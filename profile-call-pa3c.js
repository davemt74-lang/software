(() => {
  'use strict';
  const contract = 'vp3.profile-call.pa3c';
  const sdkUrl = 'https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js';
  const activeStatuses = new Set(['ringing', 'accepted', 'live']);
  const terminalStatuses = new Set(['ended', 'declined', 'expired']);
  const validId = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const positive = value => Number.isSafeInteger(value) && value > 0;
  const supported = () => window.isSecureContext && typeof navigator.mediaDevices?.getUserMedia === 'function';
  let sdkPromise;

  function randomId() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
  }
  function loadSdk() {
    if (window.LivekitClient?.Room) return Promise.resolve(window.LivekitClient);
    if (!sdkPromise) sdkPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = sdkUrl;
      script.crossOrigin = 'anonymous';
      script.referrerPolicy = 'no-referrer';
      const timer = setTimeout(fail, 10000);
      function fail() {
        clearTimeout(timer);
        script.remove();
        sdkPromise = null;
        reject(new Error('Voice call media could not load.'));
      }
      script.onerror = fail;
      script.onload = () => {
        if (!window.LivekitClient?.Room) return fail();
        clearTimeout(timer);
        resolve(window.LivekitClient);
      };
      document.head.append(script);
    });
    return sdkPromise;
  }
  function normalizeCall(value) {
    if (!value || !validId(value.id) || !positive(value.conversation_id) || !positive(value.revision)
      || !['owner', 'visitor'].includes(value.initiator) || typeof value.assisted !== 'boolean'
      || !(activeStatuses.has(value.status) || terminalStatuses.has(value.status))) return null;
    return {
      id: value.id, conversation_id: value.conversation_id, revision: value.revision, status: value.status,
      initiator: value.initiator, assisted: value.assisted, agent_name: String(value.agent_name || '').slice(0, 240),
      peer_name: String(value.peer_name || '').slice(0, 240),
    };
  }
  function make(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text) element.textContent = text;
    return element;
  }
  function button(label, action) {
    const control = make('button', '', label);
    control.type = 'button';
    control.addEventListener('click', action);
    return control;
  }
  function setText(element, value) {
    if (element.textContent !== value) element.textContent = value;
  }
  function stopTracks(stream) {
    for (const track of stream?.getTracks?.() || []) track.stop();
  }

  function mount(host, {side, endpoint, credentials, visible = () => true, onActivity}) {
    if (!host || !endpoint || !['owner', 'visitor'].includes(side)) return null;
    const clientId = randomId();
    const owner = side === 'owner';
    const mediaHost = make('div', 'profile-call-media');
    mediaHost.hidden = true;
    document.body.append(mediaHost);
    const status = make('p', 'profile-call-status');
    status.setAttribute('role', 'status');
    const notice = make('p', 'profile-call-notice');
    const controls = make('div', 'profile-call-controls');
    const offerButton = button('Voice call', () => void offer(conversationId));
    const acceptButton = button('Accept voice call', () => void accept());
    const declineButton = button('Decline', () => void operate('decline'));
    const joinButton = button('Join voice call', () => { if (call) { joinIntent = call.id; void join(); } });
    const muteButton = button('Mute', () => void mute());
    const audioButton = button('Play call audio', () => void playAudio());
    const endButton = button('End call', () => void operate('end'));
    controls.append(offerButton, acceptButton, declineButton, joinButton, muteButton, audioButton, endButton);
    host.append(notice, status, controls);
    const cards = new Map();
    const remoteAudio = new Map();
    let stopped = false, conversationId = 0, epoch = 0, timer = 0, requestSequence = 0;
    let available = false, transportReady = false, assisted = false, assistedEnabled = false, agentName = '';
    let call = null, terminal = null, terminalAt = 0, busy = false, errorText = '', errorUntil = 0, offerAttempt = null;
    let room = null, stream = null, publication = null, captureTicket = null;
    let localPhase = '', joinIntent = '', mediaEpoch = 0, muted = false, mediaBusy = false, playbackBlocked = false;
    let lastHeartbeat = 0, authorityAt = 0, pendingPoll = false, lastEvent = '', lastActivity = '';

    const active = () => call && activeStatuses.has(call.status);
    function notify(value) {
      if (!owner || !value) return;
      const phase = value.status === 'ringing' ? 'ringing' : value.status === 'accepted' ? 'connecting'
        : value.status === 'live' ? 'active' : 'ended';
      const detail = {id: value.id, conversationId: value.conversation_id, status: phase,
        connection: localPhase || 'disconnected', assisted: value.assisted, agent: {name: value.agent_name || 'Profile Agent'}};
      const key = JSON.stringify(detail);
      if (key !== lastEvent) {
        lastEvent = key;
        window.VP3_PROFILE_CALL_OWNER_STATE = detail;
        window.dispatchEvent(new CustomEvent('vp3:profile-call-state', {detail}));
      }
    }
    function setError(message = '') {
      errorText = message;
      errorUntil = Date.now() + 12000;
    }
    function render() {
      const inCall = Boolean(active());
      const incoming = inCall && call.status === 'ringing' && call.initiator !== side;
      const connecting = inCall && call.status !== 'ringing';
      const label = call?.agent_name || agentName || 'the Profile Agent';
      const reveal = inCall ? call.assisted : assistedEnabled;
      const subject = call?.peer_name || (owner ? 'Profile visitor' : 'the profile owner');
      notice.hidden = !reveal || (owner && !inCall);
      setText(notice, inCall
        ? `${label} can listen and transcribe this call.`
        : `${label} can listen and transcribe Agent-assisted calls.`);
      offerButton.hidden = owner || inCall || !available || !supported();
      setText(offerButton, assisted ? 'Call with Agent' : 'Voice call');
      acceptButton.hidden = !incoming || !supported();
      setText(acceptButton, call?.assisted ? 'Accept Agent-assisted call' : 'Accept voice call');
      declineButton.hidden = !incoming;
      joinButton.hidden = !connecting || !!localPhase || !supported();
      muteButton.hidden = localPhase !== 'connected';
      setText(muteButton, muted ? 'Unmute' : 'Mute');
      muteButton.setAttribute('aria-pressed', String(muted));
      audioButton.hidden = localPhase !== 'connected' || !playbackBlocked;
      endButton.hidden = !inCall || incoming;
      setText(endButton, call?.status === 'ringing' ? 'Cancel call' : 'End call');
      for (const control of [offerButton, acceptButton, declineButton, joinButton, endButton]) control.disabled = busy;
      muteButton.disabled = mediaBusy;
      const phaseText = !inCall ? '' : incoming ? `${subject} is calling.` : call.status === 'ringing' ? `Calling ${subject}…`
        : localPhase === 'reconnecting' ? 'Call interrupted. Reconnecting…' : localPhase === 'joining' ? 'Connecting audio…'
        : localPhase === 'connected' ? (call.status === 'live' ? `Voice call with ${subject}` : `Waiting for ${subject} to join audio…`)
        : supported() ? 'Voice call accepted. Join audio to continue.' : 'Voice calls require a browser with microphone access.';
      const outcome = terminal && Date.now() - terminalAt < 12000
        ? ({ended: 'Voice call ended. You can continue in text.', declined: 'Voice call declined. You can continue in text.', expired: 'Voice call timed out. You can continue in text.'})[terminal.status] : '';
      setText(status, (Date.now() < errorUntil ? errorText : '') || phaseText || outcome);
      status.hidden = !status.textContent;
      host.hidden = notice.hidden && status.hidden && [...controls.children].every(control => control.hidden);
      for (const [container, entry] of cards) {
        if (!container.isConnected) { cards.delete(container); continue; }
        container.hidden = !entry.enabled || !transportReady || !supported() || inCall;
        entry.button.disabled = busy;
        setText(entry.button, assisted ? 'Call with Agent' : 'Voice call');
        entry.disclosure.hidden = !assisted;
        setText(entry.disclosure, `${agentName || 'Your Profile Agent'} can listen and transcribe this call.`);
      }
      if (inCall) notify(call);
      const activity = inCall ? (call.assisted ? 'assisted' : 'call') : '';
      if (activity !== lastActivity) {
        lastActivity = activity;
        onActivity?.(inCall, activity === 'assisted');
      }
    }
    function closeLocal() {
      mediaEpoch++;
      const oldRoom = room;
      room = null;
      localPhase = '';
      stopTracks(stream);
      stream = null;
      captureTicket?.release();
      captureTicket = null;
      publication = null;
      muted = false;
      mediaBusy = false;
      playbackBlocked = false;
      for (const [track, element] of remoteAudio) detach(track, element);
      if (oldRoom) void Promise.resolve().then(() => oldRoom.disconnect()).catch(() => {});
    }
    function markTerminal(previous, next) {
      joinIntent = '';
      closeLocal();
      terminal = next || {...previous, status: 'ended'};
      terminalAt = Date.now();
      notify(terminal);
    }
    async function post(action, cid = 0, fields = {}) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 6500);
      try {
        const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
          signal: controller.signal, headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({...credentials(), action, side, conversation_id: cid, client_id: clientId, ...fields})});
        const data = await response.json();
        if (!response.ok || data?.ok !== true) {
          const error = new Error('Voice call request could not be confirmed.');
          error.status = response.status;
          error.code = typeof data?.code === 'string' ? data.code : '';
          throw error;
        }
        if (data.contract !== contract) throw new Error('Unexpected Profile call response.');
        return data;
      } finally { clearTimeout(timeout); }
    }
    function apply(data) {
      const next = normalizeCall(data.call);
      if (data.call && !next) throw new Error('Invalid call state.');
      if (next && !owner && next.conversation_id !== conversationId) throw new Error('Wrong voice call.');
      if (next && call?.id === next.id && next.revision < call.revision) return;
      if (call && activeStatuses.has(call.status) && (!next || next.id !== call.id || terminalStatuses.has(next.status))) {
        markTerminal(call, next?.id === call.id ? next : null);
      }
      if (next && terminalStatuses.has(next.status) && terminal?.id !== next.id) markTerminal(next, next);
      available = data.available === true;
      transportReady = data.voice_ready === true;
      assisted = data.assisted === true;
      assistedEnabled = data.agent_assisted_phone_chat_enabled === true || assisted;
      agentName = String(data.agent_name || '').slice(0, 240);
      if (next && activeStatuses.has(next.status) && terminal?.id !== next.id) authorityAt = Date.now();
      call = next && activeStatuses.has(next.status) && terminal?.id !== next.id ? next : null;
      if (call) offerAttempt = null;
      render();
      if (call && call.status !== 'ringing' && joinIntent === call.id && !localPhase && !busy) void join();
    }
    function schedule(delay = active() ? 2500 : (transportReady ? 4000 : 12000)) {
      clearTimeout(timer);
      if (!stopped) timer = setTimeout(() => void poll(), delay);
    }
    async function poll() {
      if (stopped || pendingPoll || busy) return schedule();
      if (!active() && !visible()) return schedule();
      const generation = epoch;
      const sequence = ++requestSequence;
      const previous = call;
      pendingPoll = true;
      try {
        const heartbeat = previous && localPhase === 'connected' && Date.now() - lastHeartbeat >= 5000;
        const data = heartbeat
          ? await post('heartbeat', previous.conversation_id, {call_id: previous.id})
          : await post('state', previous?.conversation_id ?? (owner ? 0 : conversationId), previous ? {call_id: previous.id} : {});
        if (stopped || generation !== epoch || sequence !== requestSequence) return;
        if (heartbeat) lastHeartbeat = Date.now();
        apply(data);
      } catch (error) {
        if (stopped || generation !== epoch || sequence !== requestSequence) return;
        if ([401, 403, 404, 419].includes(error.status) || error.code === 'PROFILE_SESSION_CHANGED') {
          if (active()) markTerminal(call, null);
          call = null;
          available = transportReady = assistedEnabled = false;
          setError();
        } else if (active() && ([409, 422].includes(error.status) || Date.now() - authorityAt > 11000)) {
          joinIntent = '';
          closeLocal();
          setError('Audio connection could not be verified. Rejoin when the connection returns.');
        }
        render();
      } finally { pendingPoll = false; schedule(); }
    }
    async function offer(cid) {
      if (!positive(cid) || busy || active() || !supported()) return;
      if (owner && ![...cards.values()].some(entry => entry.cid === cid && entry.enabled)) return;
      if (!owner && (!available || cid !== conversationId)) return;
      const generation = epoch;
      const disclosed = assisted;
      if (!offerAttempt || offerAttempt.cid !== cid || offerAttempt.disclosed !== disclosed) {
        offerAttempt = {cid, disclosed, id: randomId()};
      }
      const attempt = offerAttempt;
      ++requestSequence;
      busy = true;
      setError();
      render();
      try {
        const result = await post('offer', cid, {request_id: attempt.id, disclosed});
        if (stopped || generation !== epoch) return;
        const next = normalizeCall(result.call);
        if (!next || next.conversation_id !== cid || next.initiator !== side || next.assisted !== disclosed) throw new Error('Unexpected voice offer.');
        joinIntent = activeStatuses.has(next.status) ? next.id : '';
        apply(result);
      } catch (error) {
        if (stopped || generation !== epoch) return;
        if (error.status && error.status < 500) offerAttempt = null;
        setError('Could not confirm the voice call. Check the current status and try again.');
      } finally {
        if (!stopped && generation === epoch) {
          busy = false;
          render();
          schedule(0);
        }
      }
    }
    async function accept() {
      if (!active() || call.status !== 'ringing' || call.initiator === side || busy || !supported()) return;
      await operate('accept');
    }
    async function operate(action) {
      if (!active() || busy) return;
      const previous = call;
      const generation = epoch;
      ++requestSequence;
      busy = true;
      setError();
      if (action === 'end') { joinIntent = ''; closeLocal(); }
      render();
      try {
        const data = await post(action, previous.conversation_id, {call_id: previous.id, revision: previous.revision, disclosed: previous.assisted});
        if (stopped || generation !== epoch) return;
        const next = normalizeCall(data.call);
        if (action === 'accept' && next?.id === previous.id && next.assisted === previous.assisted && activeStatuses.has(next.status)) joinIntent = previous.id;
        apply(data);
      } catch (_error) {
        if (!stopped && generation === epoch) setError('Could not confirm the call update. Checking the current status.');
      } finally {
        if (!stopped && generation === epoch) {
          busy = false;
          render();
          if (action === 'accept') void join();
          schedule(0);
        }
      }
    }
    function detach(track, element) {
      try { track.detach(element); } catch (_) {}
      element.remove();
      remoteAudio.delete(track);
    }
    function attach(track, LK) {
      if (!track || track.kind !== LK.Track.Kind.Audio || remoteAudio.has(track)) return;
      const element = track.attach();
      element.autoplay = true;
      element.playsInline = true;
      mediaHost.append(element);
      remoteAudio.set(track, element);
    }
    async function playAudio() {
      const target = room;
      if (!target) return;
      try {
        await target.startAudio();
        if (room === target) playbackBlocked = target.canPlaybackAudio === false;
      } catch (_) { if (room === target) playbackBlocked = true; }
      if (room === target) render();
    }
    function setRoomEvents(target, LK) {
      const on = (name, callback) => {
        const event = LK.RoomEvent[name];
        if (event) target.on(event, (...args) => { if (room === target) callback(...args); });
      };
      on('TrackSubscribed', track => attach(track, LK));
      on('TrackUnsubscribed', track => { const element = remoteAudio.get(track); if (element) detach(track, element); });
      on('AudioPlaybackStatusChanged', () => { playbackBlocked = target.canPlaybackAudio === false; render(); });
      on('Reconnecting', () => { localPhase = 'reconnecting'; render(); });
      on('SignalReconnecting', () => { localPhase = 'reconnecting'; render(); });
      on('Reconnected', () => { localPhase = publication ? 'connected' : 'joining'; lastHeartbeat = 0; render(); schedule(0); });
      on('Disconnected', reason => {
        const previous = call;
        joinIntent = '';
        closeLocal();
        if (Number.isInteger(reason) && reason === LK.DisconnectReason?.ROOM_DELETED && previous) {
          markTerminal(previous, null);
          call = null;
        } else if (reason === LK.DisconnectReason?.DUPLICATE_IDENTITY && reason !== undefined) {
          setError('This call is connected in another tab.');
        } else {
          setError('Audio disconnected. You can rejoin while the call is open.');
        }
        render();
        schedule(0);
      });
    }
    async function join() {
      if (!active() || call.status === 'ringing' || localPhase || busy || joinIntent !== call.id || !supported()) return;
      const previous = call;
      const generation = ++mediaEpoch;
      const current = () => !stopped && mediaEpoch === generation && call?.id === previous.id && activeStatuses.has(call.status)
        && (!captureTicket || captureTicket.isCurrent());
      localPhase = 'joining';
      setError();
      render();
      let target;
      let granted = false;
      try {
        const chatVoice = owner ? window.VP3ConversationControls : null;
        if (chatVoice?.isEnabled?.() || chatVoice?.state?.()?.state === 'speaking') {
          throw new Error('Stop Agent Chat voice before joining this call.');
        }
        const lease = window.StonefellowVoiceLeaseV122;
        if (lease) {
          captureTicket = lease.acquireCapture('profile-call');
          if (!captureTicket) throw new Error('Stop voice capture in the other Chat or tab, then join the call.');
        }
        const LK = await loadSdk();
        if (!current()) return;
        const grant = await post('token', previous.conversation_id, {call_id: previous.id, revision: call.revision});
        if (!current()) return;
        const grantCall = normalizeCall(grant.call);
        const url = new URL(grant.url);
        const safeTransport = url.protocol === 'wss:' || (url.protocol === 'ws:' && ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname));
        if (grantCall?.id !== previous.id || grantCall.conversation_id !== previous.conversation_id || grantCall.assisted !== previous.assisted
          || !['accepted', 'live'].includes(grantCall.status) || !safeTransport || url.username || url.password || url.search || url.hash
          || typeof grant.token !== 'string' || !grant.token || grant.identity !== `vp3-profile-${side}-${previous.id}`) throw new Error('Invalid call access.');
        granted = true;
        target = new LK.Room({disconnectOnPageLeave: true});
        room = target;
        setRoomEvents(target, LK);
        await target.connect(url.href, grant.token, {autoSubscribe: true});
        if (!current() || room !== target) return;
        if (target.localParticipant.identity !== grant.identity) throw new Error('The voice call identity could not be verified.');
        const input = await navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}, video: false});
        if (!current() || room !== target) { stopTracks(input); return; }
        stream = input;
        const microphone = input.getAudioTracks()[0];
        if (!microphone || (captureTicket && !captureTicket.ownStream(input))) throw new Error('Microphone unavailable.');
        microphone.addEventListener('ended', () => {
          if (room !== target) return;
          setError('Microphone disconnected. You can continue in text.');
          void operate('end');
        }, {once: true});
        const published = await target.localParticipant.publishTrack(microphone, {source: LK.Track.Source.Microphone});
        if (!current() || room !== target) return;
        publication = published;
        localPhase = 'connected';
        lastHeartbeat = 0;
        render();
        schedule(0);
        void playAudio();
      } catch (error) {
        if (!current()) return;
        joinIntent = '';
        closeLocal();
        setError(error.name === 'NotAllowedError' ? 'Allow microphone access to join. You can continue in text.'
          : granted ? 'Could not connect call audio. You can continue in text.' : (error.status ? 'Could not claim this call. Check the other tab or try joining again.' : error.message));
        render();
        if (granted) {
          ++requestSequence;
          void post('end', previous.conversation_id, {call_id: previous.id, revision: previous.revision}).catch(() => {}).finally(() => schedule(0));
        }
      } finally {
        if (target && room !== target) await Promise.resolve(target.disconnect()).catch(() => {});
      }
    }
    async function mute() {
      if (!publication || localPhase !== 'connected' || mediaBusy) return;
      const target = publication;
      mediaBusy = true;
      render();
      try { if (muted) await target.unmute(); else await target.mute(); if (publication === target) muted = !muted; }
      catch (_) { if (publication === target) setError('Could not change microphone mute.'); }
      finally { if (publication === target) { mediaBusy = false; render(); } }
    }
    function setConversation(cid) {
      if (owner || stopped) return;
      cid = positive(cid) ? cid : 0;
      if (conversationId === cid) return;
      epoch++;
      conversationId = cid;
      joinIntent = '';
      closeLocal();
      call = terminal = offerAttempt = null;
      available = false;
      busy = false;
      setError();
      render();
      schedule(0);
    }
    function bindCard(container, cid, enabled) {
      if (!owner || !container?.isConnected || !positive(cid)) return;
      let entry = cards.get(container);
      if (!entry) {
        const control = button('Voice call', () => void offer(cid));
        const disclosure = make('small', 'profile-call-notice');
        container.append(control, disclosure);
        entry = {cid, enabled, button: control, disclosure};
        cards.set(container, entry);
      } else {
        if (entry.enabled === enabled) return;
        entry.enabled = enabled;
      }
      render();
    }
    function stop() {
      if (stopped) return;
      stopped = true;
      epoch++;
      clearTimeout(timer);
      closeLocal();
      onActivity?.(false, false);
      mediaHost.remove();
      host.hidden = true;
    }
    function loseConnection() {
      if (!localPhase) return;
      joinIntent = '';
      closeLocal();
      setError('Audio disconnected. Rejoin when the connection returns.');
      render();
    }
    window.addEventListener('stonefellow:voice-lease-lost', loseConnection);
    window.addEventListener('offline', loseConnection);
    window.addEventListener('online', () => schedule(0));
    window.addEventListener('pagehide', stop, {once: true});
    document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(0); });
    render();
    schedule(0);
    return {setConversation, bindCard, hasActiveCall: cid => Boolean(active() && call.conversation_id === cid), refresh: () => schedule(0), stop};
  }
  window.VP3ProfileCallPA3C = {mount};
})();
