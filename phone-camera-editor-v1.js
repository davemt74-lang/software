(() => {
  'use strict';
  const panel = document.getElementById('phoneCameraPreview');
  if (!panel) return;

  const config = JSON.parse(panel.dataset.config);
  const video = document.getElementById('phoneCameraPreviewVideo');
  const name = document.getElementById('phoneCameraPreviewName');
  const status = document.getElementById('phoneCameraPreviewStatus');
  const stopButton = document.getElementById('phoneCameraPreviewStop');
  const dismiss = document.getElementById('phoneCameraPreviewDismiss');
  const lifetime = new AbortController();
  const scope = {agent_id: config.agentId, conversation_id: config.conversationId, session_id: config.sessionId};
  const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf, 'X-VP3-WORKING-ATTENTION': config.binding};
  let peer = null;
  let ended = false;
  let answering = false;
  let answerAccepted = false;
  let authorizedAt = performance.now();
  let frameTime = 0;
  let frameCount = 0;
  let frameRequest = null;
  let healthTimer = null;
  let pollTimer = null;
  let stopUnconfirmed = false;

  function display(state, text) {
    panel.dataset.state = state;
    if (status.textContent !== text) status.textContent = text;
  }

  function finish(state, text) {
    if (ended) return;
    ended = true;
    lifetime.abort();
    clearTimeout(pollTimer);
    clearInterval(healthTimer);
    if (frameRequest !== null) video.cancelVideoFrameCallback?.(frameRequest);
    video.pause();
    video.srcObject = null;
    if (peer) {
      peer.ontrack = null;
      peer.onconnectionstatechange = null;
      peer.oniceconnectionstatechange = null;
      peer.close();
      peer = null;
    }
    stopButton.hidden = true;
    dismiss.hidden = false;
    display(state, text);
  }

  // Close the local media immediately. Confirm separately that the phone should release its camera.
  async function sendStop() {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch(config.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers,
        referrerPolicy: 'no-referrer', keepalive: true, signal: controller.signal,
        body: JSON.stringify({...scope, action: 'camera_stop'})});
      if (!response.ok) return false;
      const data = await response.json();
      return data.ok === true && (!data.camera || (data.camera.session_id === config.sessionId && data.camera.state === 'stopped'));
    } catch {
      return false;
    } finally {
      clearTimeout(timeout);
    }
  }
  stopButton.addEventListener('click', async () => {
    if ((ended && !stopUnconfirmed) || stopButton.disabled) return;
    finish('stopped', 'Preview closed · confirming stop with the phone…');
    stopButton.disabled = true;
    dismiss.hidden = true;
    stopUnconfirmed = true;
    if (await sendStop()) {
      stopUnconfirmed = false;
      display('stopped', 'Camera preview stopped.');
      stopButton.hidden = true;
      dismiss.hidden = false;
    } else {
      display('unavailable', 'Preview closed. Could not confirm that the phone stopped sharing.');
      stopButton.hidden = false;
      stopButton.textContent = 'Retry stop';
      dismiss.hidden = false;
    }
    stopButton.disabled = false;
  });
  dismiss.addEventListener('click', () => {
    panel.hidden = true;
    document.getElementById('phoneCameraControls').hidden = true;
  });
  window.addEventListener('pagehide', () => {
    const shouldStop = (!ended && answerAccepted) || stopUnconfirmed;
    finish('stopped', 'Camera preview closed.');
    if (shouldStop) void sendStop();
  });

  async function request(action, extra = {}) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    if (lifetime.signal.aborted) throw new DOMException('Session closed', 'AbortError');
    lifetime.signal.addEventListener('abort', abort, {once: true});
    const timeout = setTimeout(abort, 5000);
    try {
      const response = await fetch(config.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
        referrerPolicy: 'no-referrer', headers, signal: controller.signal, body: JSON.stringify({...scope, action, ...extra})});
      if (!response.ok) {
        const error = new Error('Camera request failed.');
        error.status = response.status;
        throw error;
      }
      const data = await response.json();
      if (data.ok !== true) throw new Error('Invalid camera response.');
      return data;
    } finally {
      clearTimeout(timeout);
      lifetime.signal.removeEventListener('abort', abort);
    }
  }

  function noteFrame(count) {
    if (Number.isFinite(count) && count > frameCount) {
      frameCount = count;
      frameTime = performance.now();
    }
  }

  function onFrame(time, info) {
    if (ended) return;
    noteFrame(info.presentedFrames);
    frameRequest = video.requestVideoFrameCallback(onFrame);
  }

  async function health() {
    if (!answerAccepted || ended) return;
    // Older engines expose decoded-frame counters through the element or the receiver stats.
    if (!video.requestVideoFrameCallback) {
      let frames = video.getVideoPlaybackQuality?.().totalVideoFrames ?? video.webkitDecodedFrameCount;
      if (!Number.isFinite(frames) && peer) {
        try {
          const report = await peer.getStats();
          report.forEach(row => {
            if (row.type === 'inbound-rtp' && (row.kind === 'video' || row.mediaType === 'video')) frames = row.framesDecoded;
          });
        } catch { /* A lost connection is surfaced by the frame deadline. */ }
      }
      if (ended) return;
      noteFrame(frames);
    }
    const transportDown = ['disconnected','failed','closed'].includes(peer?.connectionState || peer?.iceConnectionState);
    const fresh = frameCount >= 2 && performance.now() - frameTime < 4000;
    const playable = !video.paused && video.readyState >= 2 && video.srcObject?.getVideoTracks().some(track => track.readyState === 'live' && !track.muted);
    if (!transportDown && fresh && playable && performance.now() - authorizedAt < 7000) {
      display('live', 'Receiving video · Off air');
    } else if (transportDown || performance.now() - authorizedAt >= 7000 || performance.now() - frameTime >= 4000 || !playable && frameCount > 0) {
      display('stalled', 'Camera stalled · waiting for fresh frames');
    }
  }

  async function answer(offer, iceServers) {
    answering = true;
    display('connecting', 'Connecting to the phone…');
    try {
      peer = new RTCPeerConnection({iceServers: iceServers || []});
      peer.ontrack = event => {
        if (ended || event.track.kind !== 'video') return;
        video.srcObject = new MediaStream([event.track]);
        video.play().catch(() => {});
        if (video.requestVideoFrameCallback && frameRequest === null) frameRequest = video.requestVideoFrameCallback(onFrame);
        event.track.addEventListener('ended', () => {
          if (!ended) display('stalled', 'Camera stalled · waiting for fresh frames');
        }, {once: true});
      };
      peer.onconnectionstatechange = () => {
        if (!ended && ['failed', 'disconnected'].includes(peer?.connectionState || peer?.iceConnectionState)) {
          display('stalled', 'Camera connection interrupted');
        }
      };
      peer.oniceconnectionstatechange = peer.onconnectionstatechange;
      await peer.setRemoteDescription({type: 'offer', sdp: offer});
      if (ended) return;
      const transceivers = peer.getTransceivers();
      if (transceivers.length !== 1 || transceivers[0].receiver.track.kind !== 'video') throw new Error('Invalid camera stream.');
      transceivers[0].direction = 'recvonly';
      const description = await peer.createAnswer();
      if (ended) return;
      await peer.setLocalDescription(description);
      if (ended) return;
      const sdp = await window.VP3PhoneCameraICE.gather(peer, lifetime.signal);
      if (ended) return;
      const data = await request('camera_answer', {sdp});
      if (ended) return;
      if (data.camera?.session_id !== config.sessionId || data.camera.state !== 'answered') {
        finish('stopped', 'Camera preview ended.');
        return;
      }
      answerAccepted = true;
      frameTime = performance.now();
      authorizedAt = frameTime;
      healthTimer = setInterval(() => { void health(); }, 500);
    } catch {
      if (!ended) finish('unavailable', 'Could not connect. Request the phone again in Agent Chat.');
    } finally {
      answering = false;
    }
  }

  async function poll() {
    try {
      const data = await request('camera_status');
      if (ended) return;
      const camera = data.camera;
      if (!camera || camera.session_id !== config.sessionId || camera.state === 'stopped') {
        finish('stopped', 'Camera preview ended.');
        return;
      }
      authorizedAt = performance.now();
      if (camera.device_name) name.textContent = camera.device_name + ' · Private preview';
      switch (camera.state) {
        case 'requested':
          display('waiting', 'Waiting for the phone holder to start sharing…');
          break;
        case 'sharing':
          if (!peer && !answering && typeof camera.offer_sdp === 'string' && camera.offer_sdp) {
            void answer(camera.offer_sdp, data.ice_servers);
          } else if (!peer) {
            display('connecting', 'Waiting for the camera…');
          }
          break;
        case 'answered':
          if (!peer && !answering) finish('unavailable', 'This camera is already open in another preview.');
          break;
        default:
          finish('unavailable', 'Camera preview unavailable.');
      }
    } catch (error) {
      if (ended) return;
      if ([400,401,403,404,409,410,419,422].includes(error.status) || performance.now() - authorizedAt > 12000) {
        finish('unavailable', 'Camera preview unavailable. Open the request in private Agent Chat.');
      } else {
        display('stalled', 'Reconnecting to the private preview…');
      }
    } finally {
      if (!ended) pollTimer = setTimeout(poll, 1500);
    }
  }

  if (typeof RTCPeerConnection !== 'function' || !window.VP3PhoneCameraICE) {
    finish('unavailable', 'This browser cannot open a live camera preview.');
  } else {
    void poll();
  }
})();
