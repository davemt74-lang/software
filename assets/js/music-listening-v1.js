/* Native listening measurement only. Player controls, queues and history remain
 * owned by their existing consumers. Pending receipts live in this page only. */
(() => {
  'use strict';
  const protocol = 'native_listening_v1';
  const attached = new WeakMap();
  const retryDelays = [500, 1500, 4000];
  const heartbeatMs = 10000;
  const maxSampleMs = 20000;
  const maxPendingEvents = 64;
  const maxPendingPlays = 8;
  const maxPendingAgeMs = 120000;

  const integer = value => Math.max(0, Math.min(Number.MAX_SAFE_INTEGER, Math.round(Number(value) || 0)));
  const position = audio => integer(audio.currentTime * 1000);
  const rate = audio => Number.isFinite(audio.playbackRate) && audio.playbackRate > 0 ? audio.playbackRate : 1;
  const muted = audio => !!audio.muted || Number(audio.volume) <= 0.001;
  const uuid = () => {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = bytes[6] & 15 | 64;
    bytes[8] = bytes[8] & 63 | 128;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  };

  function attach(audio, options = {}) {
    if (attached.has(audio)) return attached.get(audio);
    const trackId = Number(options.trackId), listenerId = Number(options.listenerUserId);
    if (!Number.isSafeInteger(trackId) || trackId < 1 || !Number.isSafeInteger(listenerId) || listenerId < 0
      || !options.endpoint || !options.csrf || audio.hasAttribute('data-connected-site-playback')) return null;
    let endpoint;
    try {
      endpoint = new URL(options.endpoint, location.href);
      if (endpoint.origin !== location.origin) return null;
    } catch (_) { return null; }
    const csrf = String(options.csrf);
    const plays = new Set();
    const listeners = [];
    let active = null, detached = false, playingDuringInitialSeek = false;
    const current = () => {try {return !options.isCurrent || options.isCurrent() === true;} catch (_) {return false;}};
    const notify = (name, value) => {try {options[name]?.(value);} catch (_) {}};
    const elapsed = play => integer(performance.now() - play.startedAt);
    const serialize = payload => JSON.stringify({...payload, csrf_token: csrf});

    function fail(play, code) {
      if (play.failed) return;
      play.failed = true;
      clearTimeout(play.retryTimer);
      clearInterval(play.heartbeat);
      play.controller?.abort();
      play.queue.length = 0;
      plays.delete(play);
      notify('onError', {code, coverage: 'partial', play_id: play.id});
    }

    function valid(play) {
      if (play.failed) return false;
      if (!current()) {fail(play, 'listener_changed'); return false;}
      return true;
    }

    function sample(play, accrue = true) {
      const now = performance.now(), nextPosition = position(audio);
      const wall = Math.max(0, now - play.lastTick);
      const advancement = nextPosition - play.lastPosition;
      if (accrue && play.phase === 'playing' && advancement > 0
        && advancement <= wall * play.rate + 500) {
        // A seek, stalled clock or suspended callback cannot manufacture time.
        // Playback rate changes content advancement, not elapsed listening time.
        play.playedMs += Math.min(wall, advancement / play.rate, maxSampleMs);
        if (wall > maxSampleMs && advancement / play.rate > maxSampleMs) play.partial = true;
      } else if (accrue && play.phase === 'playing' && advancement !== 0) {
        play.partial = true;
      }
      play.lastTick = now;
      play.lastPosition = nextPosition;
    }

    function packet(play, action, overrides = {}) {
      return {
        payload: {protocol, action, listener_user_id: listenerId, track_id: trackId, play_id: play.id,
          event_id: uuid(), seq: play.nextSeq++, cumulative_played_ms: integer(play.playedMs),
          elapsed_ms: elapsed(play), position_ms: position(audio), playback_rate: play.rate,
          state: play.phase, muted: muted(audio), loop: !!audio.loop, observation_partial: play.partial, ...overrides},
        body: '', attempts: 0, createdAt: performance.now()
      };
    }

    function enqueue(play, action, overrides = {}) {
      if (!valid(play)) return;
      if (play.queue.length >= maxPendingEvents) {fail(play, 'client_queue_limit'); return;}
      play.queue.push(packet(play, action, overrides));
      void pump(play);
    }

    function accepted(data, play, outgoing, start) {
      return data?.ok === true && data.protocol === protocol && data.play_id === play.id && data.user_id === listenerId
        && Number.isSafeInteger(data.session_id) && data.session_id > 0
        && typeof data.session_token === 'string' && /^[a-f0-9]{64}$/.test(data.session_token)
        && (start || data.session_token === play.token)
        && data.accepted_seq === (start ? 0 : outgoing.payload.seq)
        && data.next_seq === (start ? 1 : outgoing.payload.seq + 1)
        && Number.isSafeInteger(data.accepted_played_ms) && data.accepted_played_ms >= 0;
    }

    async function post(play, outgoing) {
      const controller = new AbortController();
      play.controller = controller;
      const timeout = setTimeout(() => controller.abort(), 10000);
      try {
        const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json'}, keepalive: true, signal: controller.signal, body: outgoing.body});
        const data = await response.json().catch(() => null);
        return {data, status: response.status, retry: response.status >= 500, ok: response.ok, code: data?.code || `http_${response.status}`};
      } catch (_) {
        return {retry: true, ok: false, code: 'transport_unavailable'};
      } finally {
        clearTimeout(timeout);
        if (play.controller === controller) play.controller = null;
      }
    }

    async function pump(play) {
      if (!valid(play) || play.busy || play.retryTimer) return;
      const start = !play.token;
      const outgoing = start ? play.start : play.queue[0];
      if (!outgoing) {
        if (play.terminal) plays.delete(play);
        return;
      }
      if (performance.now() - outgoing.createdAt > maxPendingAgeMs) {fail(play, 'client_receipt_expired'); return;}
      if (!outgoing.body) outgoing.body = serialize({...outgoing.payload, session_token: play.token});
      play.busy = true;
      const result = await post(play, outgoing);
      play.busy = false;
      if (!valid(play)) return;
      if (result.ok && accepted(result.data, play, outgoing, start)) {
        if (start) {
          play.token = result.data.session_token;
          play.sessionId = result.data.session_id;
          if (active === play && !play.terminal && !detached) notify('onStart', result.data);
        } else {
          play.queue.shift();
          notify('onReceipt', result.data);
        }
        void pump(play);
        return;
      }
      // No play is allocated until a listener cookie has survived a round trip.
      // A lost first Set-Cookie response can safely repeat this bounded handshake.
      if (start && result.status === 428 && result.code === 'listener_initialization_required' && result.data?.retryable === true) {
        if ((outgoing.initializations || 0) < 2) {
          outgoing.initializations = (outgoing.initializations || 0) + 1;
          play.retryTimer = setTimeout(() => {play.retryTimer = 0; void pump(play);}, 500);
        } else fail(play, result.code);
        return;
      }
      // A protocol conflict or changed account is never a reason to allocate a
      // fresh play. Retry only the frozen transport/server-failure request.
      const retryable = result.retry || (result.ok && !result.data);
      if (retryable) outgoing.attempts++;
      if (retryable && outgoing.attempts <= retryDelays.length) {
        play.retryTimer = setTimeout(() => {play.retryTimer = 0; void pump(play);}, retryDelays[outgoing.attempts - 1]);
      } else fail(play, result.code || 'invalid_receipt');
    }

    function createPlay() {
      if (!current()) return null;
      if (plays.size >= maxPendingPlays) fail(plays.values().next().value, 'client_pending_play_limit');
      let id;
      try {id = uuid();} catch (_) {notify('onError', {code: 'client_identity_unavailable', coverage: 'unmeasured'}); return null;}
      const now = performance.now();
      let context;
      try {context = options.startContext?.() || {};} catch (_) {return null;}
      const payload = {protocol, action: 'start', listener_user_id: listenerId, track_id: trackId, play_id: id,
        position_ms: position(audio), playback_rate: rate(audio), muted: muted(audio), loop: !!audio.loop,
        source: ['player', 'agent_chat', 'agent_player'].includes(context.source) ? context.source : 'agent_chat'};
      if (typeof context.source_url === 'string') payload.source_url = context.source_url;
      if (context.media_offer_playback && typeof context.media_offer_playback === 'object') payload.media_offer_playback = context.media_offer_playback;
      const play = {id, token: '', startedAt: now, lastTick: now, lastPosition: position(audio), rate: rate(audio),
        phase: 'playing', playedMs: 0, partial: false, nextSeq: 1, queue: [], start: {body: serialize(payload), attempts: 0, createdAt: now},
        busy: false, failed: false, terminal: false, retryTimer: 0, heartbeat: 0, controller: null};
      active = play;
      plays.add(play);
      // Allocation itself is not heard playback. Preserve the initial observed
      // playing transition even when start acknowledgement is delayed or lost.
      enqueue(play, 'resume', {cumulative_played_ms: 0, elapsed_ms: 0});
      play.heartbeat = setInterval(() => {
        if (!valid(play) || play.terminal || active !== play) return;
        if (play.phase === 'playing' && !audio.paused && !audio.ended && !audio.seeking) {
          sample(play);
          enqueue(play, 'heartbeat');
        }
      }, heartbeatMs);
      return play;
    }

    function playing() {
      if (detached || !audio.isConnected || audio.paused || audio.ended || !current()) {
        playingDuringInitialSeek = false;
        return;
      }
      if (audio.seeking) {
        // An actual playing event can precede the initial seek completion.
        // Keep that observation, but start at the stable position and clock.
        if (!active || active.terminal) playingDuringInitialSeek = true;
        return;
      }
      playingDuringInitialSeek = false;
      if (!active || active.terminal) {createPlay(); return;}
      if (!valid(active) || active.phase === 'playing') return;
      sample(active, false);
      active.phase = 'playing';
      active.rate = rate(audio);
      enqueue(active, 'resume');
    }

    function pause() {
      playingDuringInitialSeek = false;
      const play = active;
      if (!play || play.terminal || !valid(play)) return;
      sample(play, !audio.seeking);
      if (audio.ended) return; // ended carries this final interval exactly once.
      play.phase = 'paused';
      enqueue(play, 'pause');
    }

    function waiting() {
      // Buffering can precede seeked without another playing event. Retain the
      // initial observation; seeked rechecks authority and starts a fresh clock.
      const play = active;
      if (!play || play.terminal || !valid(play) || ['buffering', 'seeking', 'paused'].includes(play.phase)) return;
      sample(play, !audio.seeking);
      play.phase = 'buffering';
      enqueue(play, 'waiting');
    }

    function seeking() {
      const play = active;
      if (!play || play.terminal || !valid(play)) return;
      // currentTime is already the destination when seeking fires. Flush only
      // the previously observed position; do not turn the jump into evidence.
      if (play.phase !== 'seeking') enqueue(play, 'heartbeat', {position_ms: play.lastPosition});
      sample(play, false);
      play.phase = 'seeking';
      enqueue(play, 'seek');
    }

    function seeked() {
      if (playingDuringInitialSeek && (!active || active.terminal)) {
        playingDuringInitialSeek = false;
        playing();
        return;
      }
      const play = active;
      if (!play || play.terminal || !valid(play)) return;
      sample(play, false);
      play.phase = audio.paused ? 'paused' : 'playing';
      play.rate = rate(audio);
      enqueue(play, audio.paused ? 'pause' : 'resume');
    }

    function ratechange() {
      const play = active;
      if (!play || play.terminal || !valid(play)) return;
      if (audio.seeking || play.phase === 'seeking') return; // seeked reports the new rate with the next stable state.
      sample(play, !audio.seeking);
      enqueue(play, 'heartbeat');
      play.rate = rate(audio);
      enqueue(play, 'ratechange');
    }

    function finish(action = 'stop', beacon = false) {
      playingDuringInitialSeek = false;
      const play = active;
      if (play && !play.terminal && valid(play)) {
        sample(play, !audio.seeking);
        play.phase = action === 'ended' ? 'ended' : 'stopped';
        play.terminal = true;
        clearInterval(play.heartbeat);
        enqueue(play, action);
      }
      active = null;
      if (beacon) flushBeacons();
    }

    function flushBeacons() {
      for (const play of plays) {
        if (!valid(play)) continue;
        const outgoing = play.token ? play.queue[0] : play.start;
        if (!outgoing) continue;
        if (performance.now() - outgoing.createdAt > maxPendingAgeMs) {fail(play, 'client_receipt_expired'); continue;}
        if (!outgoing.body) outgoing.body = serialize({...outgoing.payload, session_token: play.token});
        // Queueing a beacon is not acknowledgement. A later normal retry uses
        // the identical event/sequence/body; unconfirmed tails remain partial.
        try {navigator.sendBeacon?.(endpoint.href, new Blob([outgoing.body], {type: 'application/json'}));} catch (_) {}
      }
    }

    function flush(beacon = false) {
      if (active && valid(active) && active.phase === 'playing' && !audio.seeking) {
        sample(active);
        enqueue(active, 'heartbeat');
      }
      if (beacon) flushBeacons();
      else for (const play of plays) void pump(play);
    }

    function listen(event, handler) {audio.addEventListener(event, handler); listeners.push([event, handler]);}
    listen('playing', playing);
    listen('pause', pause);
    listen('waiting', waiting);
    listen('stalled', waiting);
    listen('seeking', seeking);
    listen('seeked', seeked);
    listen('ratechange', ratechange);
    listen('timeupdate', () => {if (active && valid(active) && !audio.seeking) sample(active);});
    listen('ended', () => finish('ended'));
    listen('error', () => finish('stop'));
    listen('emptied', () => finish('stop'));
    const api = Object.freeze({
      flush,
      // Opaque history address only. The server still rechecks the owner,
      // current source and exact selected song; no bearer token leaves here.
      receipt: () => active && !detached && !active.failed && !active.terminal && current()
        && Number.isSafeInteger(active.sessionId) && active.sessionId > 0
        ? {kind:'n', id:String(active.sessionId)} : null,
      stop: (beacon = false) => finish('stop', beacon),
      resetClock: () => {
        if (!active) return;
        if (active.phase === 'playing' && position(audio) > active.lastPosition) active.partial = true;
        sample(active, false);
      },
      detach: (beacon = false) => {finish('stop', beacon); detached = true; for (const [event, handler] of listeners) audio.removeEventListener(event, handler);}
    });
    attached.set(audio, api);
    return api;
  }

  window.VP3MusicListeningV1 = Object.freeze({attach, protocol});
})();
