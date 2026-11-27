import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {fileURLToPath} from 'node:url';

const source = fs.readFileSync(new URL('../assets/js/music-listening-v1.js', import.meta.url), 'utf8');
const protocol = 'native_listening_v1';
const turn = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return {promise, resolve}; };
const response = (data, status = 200) => ({ok: status >= 200 && status < 300, status, json: async () => data});

class Audio extends EventTarget {
  constructor() {
    super();
    Object.assign(this, {paused: true, ended: false, seeking: false, isConnected: true,
      currentTime: 0, playbackRate: 1, volume: 1, muted: false, loop: false, attributes: new Set()});
  }
  hasAttribute(name) { return this.attributes.has(name); }
  emit(name) { this.dispatchEvent(new Event(name)); }
}

// This fake transport checks frozen identities/order only. Accepted accounting
// and database isolation are tested separately against the real PHP endpoint.
function fixture({listener = 11, current = () => true, context = () => ({source: 'agent_player'})} = {}) {
  let now = 1000, timerId = 0, sessionId = 0, handler = null;
  const timers = new Map(), calls = [], beacons = [], allocations = new Map(), committed = [], errors = [], starts = [], receipts = [];
  const audio = new Audio();
  const setTimer = (fn, delay, interval = false) => {
    const id = ++timerId; timers.set(id, {fn, at: now + delay, delay, interval}); return id;
  };
  const receive = text => {
    const input = JSON.parse(text);
    let play = allocations.get(input.play_id);
    if (input.action === 'start') {
      if (play) { assert.equal(text, play.startBody, 'A retried start changed its frozen payload'); return play.startReceipt; }
      const id = ++sessionId, token = id.toString(16).padStart(64, '0');
      const receipt = {ok: true, protocol, session_id: id, session_token: token, play_id: input.play_id,
        user_id: input.listener_user_id, accepted_seq: 0, next_seq: 1, accepted_played_ms: 0, state: 'pending'};
      allocations.set(input.play_id, {startBody: text, startReceipt: receipt, events: new Map(), next: 1});
      committed.push(input); return receipt;
    }
    assert.ok(play, 'Event arrived before an allocated start');
    assert.equal(input.session_token, play.startReceipt.session_token, 'An event acquired another play’s token');
    const existing = play.events.get(input.seq);
    if (existing) { assert.equal(text, existing.body, 'A retried event changed its frozen payload'); return existing.receipt; }
    assert.equal(input.seq, play.next, 'The emitter skipped or reordered an event');
    const states = {resume: ['playing'], heartbeat: ['playing', 'paused', 'buffering'], pause: ['paused'],
      waiting: ['buffering'], seek: ['seeking', 'paused', 'playing'], ratechange: ['playing', 'paused', 'buffering'],
      ended: ['ended'], stop: ['stopped']};
    assert.ok(states[input.action]?.includes(input.state), `Invalid ${input.action}/${input.state} pair`);
    assert.ok(input.cumulative_played_ms <= input.elapsed_ms, 'Listening exceeds observed wall time');
    const receipt = {...play.startReceipt, accepted_seq: input.seq, next_seq: input.seq + 1,
      accepted_played_ms: input.cumulative_played_ms, state: input.state, coverage: {partial: input.observation_partial}};
    play.events.set(input.seq, {body: text, receipt}); play.next++;
    committed.push(input); return receipt;
  };
  const environment = {Event, URL, Blob, AbortController, Uint8Array, crypto: webcrypto,
    performance: {now: () => now}, location: {href: 'https://vp3.test/chat.php?private=value', origin: 'https://vp3.test'},
    navigator: {sendBeacon: (url, blob) => { beacons.push({url, blob}); return true; }}, window: {},
    setTimeout: (fn, ms) => setTimer(fn, ms), clearTimeout: id => timers.delete(id),
    setInterval: (fn, ms) => setTimer(fn, ms, true), clearInterval: id => timers.delete(id),
    fetch: async (url, request) => {
      const call = {url, body: request.body, input: JSON.parse(request.body)};
      calls.push(call);
      return handler ? handler(call, receive) : response(receive(call.body));
    },
  };
  vm.createContext(environment); vm.runInContext(source, environment);
  const options = {trackId: 42, listenerUserId: listener, csrf: 'csrf-current-listener', endpoint: '/api/playback.php',
    isCurrent: current, startContext: context, onStart: data => starts.push(data),
    onReceipt: data => receipts.push(data), onError: data => errors.push(data)};
  const tracker = environment.window.VP3MusicListeningV1.attach(audio, options);
  const tick = async ms => {
    const target = now + ms;
    for (;;) {
      let chosen;
      for (const [id, timer] of timers) if (timer.at <= target && (!chosen || timer.at < chosen[1].at)) chosen = [id, timer];
      if (!chosen) break;
      const [id, timer] = chosen; now = Math.max(now, timer.at);
      if (timer.interval) timer.at = now + timer.delay; else timers.delete(id);
      timer.fn(); await turn();
    }
    now = target; await turn();
  };
  const advance = async (ms, {progress = true, timeupdate = true} = {}) => {
    for (let remaining = ms; remaining > 0;) {
      const step = Math.min(remaining, 250); remaining -= step;
      if (progress) audio.currentTime += step / 1000 * audio.playbackRate;
      await tick(step);
      if (timeupdate) audio.emit('timeupdate');
      await turn();
    }
  };
  const play = async () => { audio.paused = false; audio.ended = false; audio.emit('play'); audio.emit('playing'); await turn(); };
  const pause = async () => { audio.paused = true; audio.emit('pause'); await turn(); };
  return {audio, tracker, options, environment, calls, beacons, allocations, committed, errors, starts, receipts,
    setHandler: fn => { handler = fn; }, receive, play, pause, tick, advance,
    jump: ms => { now += ms; }, events: () => committed.filter(row => row.action !== 'start')};
}

export async function runMusicListeningClientTests({silent = false} = {}) {
  let checks = 0;
  const check = (condition, message) => { assert.ok(condition, message); checks++; };

  {
    const f = fixture(), pending = deferred();
    f.setHandler(call => call.input.action === 'start' ? pending.promise : response(f.receive(call.body)));
    f.audio.emit('waiting'); f.audio.emit('play'); f.tracker.flush(); await turn();
    check(f.calls.length === 0, 'Loading, play intent and buffering before actual playing must not allocate a listen');
    await f.play(); f.audio.emit('playing'); await turn();
    check(f.calls.length === 1, 'Overlapping playing observations must coalesce to one start');
    await f.advance(5000, {timeupdate: false}); await f.pause();
    check(f.calls.length === 1, 'Events must wait for the session acknowledgement');
    pending.resolve(response(f.receive(f.calls[0].body))); await turn();
    assert.deepEqual(f.events().map(row => [row.action, row.cumulative_played_ms]), [['resume', 0], ['pause', 5000]]); checks++;
    check(f.starts.length === 1, 'A current paused session must refresh personal history after delayed start');
  }

  {
    const f = fixture(); await f.play();
    await f.advance(3000, {timeupdate: false}); f.audio.emit('waiting'); await turn();
    await f.advance(15000, {progress: false}); f.audio.emit('stalled');
    f.audio.emit('playing'); await turn();
    await f.advance(2000, {timeupdate: false}); await f.pause();
    check(f.events().at(-1).cumulative_played_ms === 5000, 'Stalls must add zero time while pause retains the final playing interval');
    check(f.events().filter(row => row.action === 'waiting').length === 1, 'Repeated stalled notifications must not grow the outbox');
    f.audio.emit('waiting'); f.audio.playbackRate = 2; f.audio.emit('ratechange'); await turn();
    check(f.events().at(-1).action === 'ratechange' && f.events().at(-1).state === 'paused', 'A paused rate change must retain paused state');
    f.audio.paused = false; f.audio.emit('playing'); await turn(); f.audio.emit('waiting'); await turn();
    f.audio.playbackRate = .5; f.audio.emit('ratechange'); await turn();
    check(f.events().at(-1).state === 'buffering', 'A buffered rate change must retain buffering state');
  }

  {
    const f = fixture();
    f.audio.currentTime = 22; f.audio.seeking = true; f.audio.emit('seeking');
    await f.play(); f.audio.emit('playing'); await turn();
    check(f.calls.length === 0, 'Actual playing during an initial seek must wait for the stable selected position');
    await f.advance(5000, {progress: false});
    f.audio.seeking = false; f.audio.emit('seeked'); await turn();
    check(f.allocations.size === 1 && f.calls[0]?.input.position_ms === 22000,
      'An observed playing event before the initial seek completes must allocate one start at the exact resumed position');
    check(f.events()[0]?.action === 'resume' && f.events()[0].elapsed_ms === 0 && f.events()[0].cumulative_played_ms === 0,
      'The initial seek wait must not become elapsed or measured playing time');
    f.audio.emit('playing'); f.audio.emit('seeked'); await turn();
    check(f.allocations.size === 1, 'Later playing and seek completion observations must not allocate a duplicate start');
    await f.advance(2000); await f.pause();
    check(f.events().at(-1).cumulative_played_ms === 2000, 'Only media progress after the initial seek may accrue listening time');
  }

  {
    const f = fixture();
    f.audio.currentTime = 22; f.audio.seeking = true; f.audio.emit('seeking');
    f.audio.paused = false; f.audio.emit('play');
    f.audio.seeking = false; f.audio.emit('seeked');
    await f.advance(1000); f.tracker.flush(); await turn();
    check(f.calls.length === 0, 'Seek completion, play intent and position updates without actual playing must not allocate a start');
    f.audio.emit('playing'); await turn();
    check(f.allocations.size === 1, 'An actual playing event after the initial seek can allocate the first start');
  }

  for (const boundary of ['pause', 'paused completion', 'waiting', 'stalled', 'stop', 'detach', 'ended', 'error', 'emptied', 'account change', 'disconnected']) {
    let authorized = true;
    const f = fixture({current: () => authorized});
    f.audio.currentTime = 22; f.audio.seeking = true; f.audio.emit('seeking'); await f.play();
    if (boundary === 'pause') await f.pause();
    else if (boundary === 'paused completion') f.audio.paused = true;
    else if (boundary === 'stop') f.tracker.stop();
    else if (boundary === 'detach') f.tracker.detach();
    else if (boundary === 'account change') authorized = false;
    else if (boundary === 'disconnected') f.audio.isConnected = false;
    else f.audio.emit(boundary);
    f.audio.seeking = false; f.audio.emit('seeked'); await turn();
    authorized = true; f.audio.isConnected = true; f.audio.paused = false;
    f.audio.emit('play'); f.audio.emit('seeked'); f.audio.emit('timeupdate'); f.tracker.flush(); await turn();
    check(f.calls.length === 0, `${boundary} must discard an initial-seek playing observation without a later actual playing event`);
  }

  {
    let sourceUrl = '/chat.php?history=original', lostStart = false;
    const f = fixture({context: () => ({source: 'agent_player', source_url: sourceUrl})});
    f.setHandler(call => {
      const receipt = f.receive(call.body);
      if (call.input.action === 'start' && !lostStart) { lostStart = true; throw new Error('Lost initial-seek start acknowledgement'); }
      return response(receipt);
    });
    f.audio.currentTime = 22; f.audio.seeking = true; f.audio.emit('seeking'); await f.play();
    f.audio.seeking = false; f.audio.emit('seeked'); await turn();
    f.audio.emit('playing'); f.audio.emit('seeked');
    sourceUrl = '/chat.php?history=later'; await f.advance(500);
    const starts = f.calls.filter(call => call.input.action === 'start');
    check(starts.length === 2 && starts[0].body === starts[1].body && starts[1].input.position_ms === 22000
      && starts[1].input.source_url === '/chat.php?history=original',
      'A lost initial-seek start acknowledgement must retry the frozen identity, position and source context');
    check(f.allocations.size === 1 && f.starts.length === 1 && f.errors.length === 0,
      'Initial-seek retries and duplicate media events must acknowledge only one allocated play');
  }

  {
    const f = fixture(); await f.play(); await f.advance(4000);
    f.audio.seeking = true; f.audio.currentTime = 20; f.audio.emit('seeking'); await turn();
    const seek = f.events().at(-1), checkpoint = f.events().at(-2);
    check(checkpoint.action === 'heartbeat' && checkpoint.position_ms === 4000, 'Pre-seek evidence must use the last observed position');
    check(seek.action === 'seek' && seek.cumulative_played_ms === 4000, 'A forward jump must add zero listening time');
    await f.advance(8000, {progress: false}); f.audio.seeking = false; f.audio.emit('seeked'); await turn();
    await f.advance(2000); await f.pause();
    check(f.events().at(-1).cumulative_played_ms === 6000, 'Time spent seeking must not count as playing');
    f.audio.seeking = true; f.audio.currentTime = 1; f.audio.emit('seeking');
    f.audio.playbackRate = 2; f.audio.emit('ratechange'); await turn();
    f.audio.seeking = false; f.audio.emit('seeked'); await turn();
    check(f.events().at(-1).state === 'paused' && f.events().at(-1).playback_rate === 2, 'Paused backward seeking must keep the listener paused and apply its new rate');
    check(f.events().at(-1).cumulative_played_ms === 6000, 'A backward seek must neither manufacture nor erase previously observed time');
    await f.play(); await f.advance(2000); await f.pause();
    check(f.events().at(-1).cumulative_played_ms === 8000, 'Resuming after a paused seek must use elapsed time at the current rate');
    check(f.errors.length === 0, 'Seek and rate transitions must remain within the server state contract');
  }

  {
    const f = fixture(); f.audio.muted = true; f.audio.loop = true; await f.play();
    f.audio.playbackRate = 2; f.audio.emit('ratechange'); await turn(); await f.advance(4000);
    f.audio.playbackRate = .5; f.audio.emit('ratechange'); await turn(); await f.advance(4000); await f.pause();
    check(f.events().at(-1).cumulative_played_ms === 8000, 'Two-times and half-speed playback must measure actual elapsed listening');
    check(f.calls[0].input.muted && f.calls[0].input.loop && f.events().at(-1).muted && f.events().at(-1).loop,
      'Mute and loop observations must remain diagnostics without changing listening policy');
  }

  {
    const f = fixture(); let lostStart = false, lostPause = false;
    f.setHandler(call => {
      const receipt = f.receive(call.body);
      if (call.input.action === 'start' && !lostStart) { lostStart = true; throw new Error('Lost start acknowledgement after commit'); }
      if (call.input.action === 'pause' && !lostPause) { lostPause = true; throw new Error('Lost pause acknowledgement after commit'); }
      return response(receipt);
    });
    await f.play(); await f.advance(3000); await f.pause(); await f.tick(500);
    const starts = f.calls.filter(row => row.input.action === 'start'), pauses = f.calls.filter(row => row.input.action === 'pause');
    check(starts.length === 2 && starts[0].body === starts[1].body, 'A lost start acknowledgement must retry the exact same request');
    check(pauses.length === 2 && pauses[0].body === pauses[1].body, 'A lost event acknowledgement must retry identical ID, sequence and payload');
    check(f.allocations.size === 1 && f.events().length === 2 && f.starts.length === 1, 'Acknowledgement loss must not duplicate starts, events or history updates');
  }

  {
    const f = fixture(); let step = 0;
    f.setHandler(call => {
      step++;
      if (step === 1) throw new Error('Lost cookie initialization response');
      if (step <= 3) return response({ok: false, code: 'listener_initialization_required', retryable: true}, 428);
      const receipt = f.receive(call.body);
      if (step === 4) throw new Error('Lost allocated start acknowledgement');
      return response(receipt);
    });
    await f.play(); await f.tick(8000);
    const requests = f.calls.filter(call => call.input.action === 'start');
    check(requests.length === 5 && new Set(requests.map(call => call.body)).size === 1,
      'Cookie initialization and network retries must preserve one frozen start with separate bounded budgets');
    check(f.allocations.size === 1 && f.starts.length === 1 && f.errors.length === 0,
      'Lost first-cookie and allocated-start acknowledgements must still yield one logical play');
  }

  {
    const f = fixture();
    f.setHandler(() => response({ok: false, code: 'listener_initialization_required', retryable: true}, 428));
    await f.play(); await f.tick(5000);
    check(f.calls.length === 3 && f.errors[0]?.code === 'listener_initialization_required',
      'A browser refusing the listener cookie must stop after two initialization retries');
    check(f.allocations.size === 0 && f.starts.length === 0, 'Cookie initialization must never fabricate an allocated session');
  }

  {
    const f = fixture(), pendingEnd = deferred(); let endedBody;
    f.setHandler(call => {
      if (call.input.action === 'ended') { endedBody = call.body; f.receive(call.body); return pendingEnd.promise; }
      return response(f.receive(call.body));
    });
    await f.play(); await f.advance(3000, {timeupdate: false});
    f.audio.ended = true; f.audio.paused = true; f.audio.emit('pause'); f.audio.emit('ended'); await turn();
    check(JSON.parse(endedBody).cumulative_played_ms === 3000, 'Natural-end pause and ended must retain the final tail exactly once');
    const old = JSON.parse(endedBody); f.audio.currentTime = 0; await f.play();
    check(f.allocations.size === 2 && f.starts.length === 2, 'A deliberate replay can begin while the previous terminal receipt is pending');
    pendingEnd.resolve(response(f.receive(endedBody))); await turn();
    await f.advance(1000); await f.pause();
    const latest = f.events().at(-1);
    check(latest.play_id !== old.play_id && latest.session_token !== old.session_token && latest.cumulative_played_ms === 1000,
      'A previous terminal acknowledgement must not overwrite the new play identity or counters');
  }

  {
    const f = fixture(), pending = deferred(); let first = true;
    f.setHandler(call => {
      if (call.input.action === 'start' && first) { first = false; return pending.promise; }
      return response(f.receive(call.body));
    });
    await f.play(); const original = f.calls[0].body; f.tracker.stop(true);
    f.audio.currentTime = 0; await f.play();
    pending.resolve(response(f.receive(original))); await turn();
    const oldId = JSON.parse(original).play_id, oldEvents = f.events().filter(row => row.play_id === oldId);
    check(oldEvents.map(row => row.action).join(',') === 'resume,stop', 'A stopped generation must still close its late allocated session');
    check(f.starts.length === 1 && f.starts[0].play_id !== oldId, 'Only the current start may refresh personal history');
    check(f.beacons.length === 1 && await f.beacons[0].blob.text() === original, 'Page-exit delivery must keep the exact pending start identity');
  }

  for (const [status, code] of [[403, 'listener_changed'], [409, 'sequence_gap'], [409, 'event_conflict'], [410, 'session_expired'], [429, 'session_limit']]) {
    const f = fixture();
    f.setHandler(call => call.input.action === 'start' ? response(f.receive(call.body)) : response({ok: false, code}, status));
    await f.play(); await f.tick(8000); f.audio.emit('playing'); await f.pause(); await turn();
    check(f.calls.length === 2 && f.allocations.size === 1 && f.errors[0]?.code === code, `${code} must stop telemetry without retry or a replacement play`);
  }

  {
    let user = 0; const f = fixture({listener: 0, current: () => user === 0});
    await f.play(); check(f.calls[0].input.listener_user_id === 0, 'Anonymous playback must retain anonymous expected identity');
    user = 11; await f.advance(1000); await f.pause();
    check(f.calls.length === 2 && f.errors[0]?.code === 'listener_changed', 'Anonymous-to-account transition must terminate the old outbox');
  }

  {
    const f = fixture(); f.setHandler(() => { throw new Error('Offline'); });
    await f.play(); await f.tick(8000); f.audio.emit('playing'); await turn();
    check(f.calls.length === 4 && new Set(f.calls.map(row => row.body)).size === 1, 'Network retry must be bounded to four identical attempts');
    check(f.errors.length === 1 && f.errors[0].coverage === 'partial', 'Exhausted transport must expose incomplete coverage without fabricating acknowledgements');
    check(f.starts.length === 0 && f.receipts.length === 0, 'Failed delivery must never be acknowledged locally');
  }

  {
    const f = fixture(), pending = deferred(); f.setHandler(() => pending.promise); await f.play();
    for (let i = 0; i < 40; i++) { await f.pause(); await f.play(); }
    check(f.calls.length === 1 && f.errors[0]?.code === 'client_queue_limit', 'A pending start must not produce an unbounded event outbox');
    pending.resolve(response(f.receive(f.calls[0].body))); await turn();
    check(f.calls.length === 1 && f.starts.length === 0, 'A discarded outbox must ignore a late acknowledgement');
  }

  {
    const f = fixture(); await f.play(); f.jump(45000); f.audio.currentTime = 45; f.tracker.flush(); await turn();
    check(f.events().at(-1).cumulative_played_ms === 20000 && f.events().at(-1).observation_partial,
      'A suspended callback gap must cap the interval and label missing coverage');
    f.jump(3000); f.audio.currentTime = 48; f.tracker.resetClock(); f.tracker.flush(); await turn();
    check(f.events().at(-1).observation_partial && f.events().at(-1).cumulative_played_ms === 20000,
      'Foreground clock reset must retain partial coverage without charging unseen elapsed time');
  }

  {
    const f = fixture(); const connected = new Audio(); connected.attributes.add('data-connected-site-playback');
    check(f.environment.window.VP3MusicListeningV1.attach(connected, f.options) === null,
      'Connected-site playback must retain its separate protocol');
    check(f.environment.window.VP3MusicListeningV1.attach(new Audio(), {...f.options, endpoint: 'https://foreign.test/api'}) === null,
      'The native tracker must not send listener credentials to another origin');
    check(f.environment.window.VP3MusicListeningV1.attach(f.audio, f.options) === f.tracker,
      'Moving or reattaching an existing player must not register a second tracker');
    check(!/localStorage|sessionStorage|indexedDB/.test(source), 'Receipt queues must remain scoped to the current page and account');
  }

  if (!silent) console.log(`MUSIC_LISTENING_CLIENT_V1=PASS checks=${checks}`);
  return checks;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === fileURLToPath(new URL('file://' + process.argv[1]))) await runMusicListeningClientTests();
