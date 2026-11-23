(() => {
  'use strict';

  async function post(endpoint, headers, payload, signal, keepalive = false) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    if (signal?.aborted) throw new DOMException('Session closed', 'AbortError');
    signal?.addEventListener('abort', abort, {once: true});
    const timeout = setTimeout(abort, 5000);
    try {
      const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', keepalive,
        referrerPolicy: 'no-referrer', headers, signal: controller.signal, body: JSON.stringify(payload)});
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
      signal?.removeEventListener('abort', abort);
    }
  }

  function receive({video, sessionId, deviceId, request, display, onName, onEnd, initial}) {
    const lifetime = new AbortController();
    let peer = null;
    let ended = false;
    let answering = false;
    let answerAccepted = false;
    let authorizedAt = performance.now();
    let frameTime = 0;
    let frameCount = null;
    let framesAdvanced = false;
    let frameRequest = null;
    let healthTimer = null;
    let pollTimer = null;

    function matches(camera) {
      return camera?.session_id === sessionId && (!deviceId || camera.device_id === deviceId);
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
      display(state, text);
      onEnd?.();
    }

    function noteFrame(count) {
      if (Number.isFinite(count) && (frameCount === null || count > frameCount)) {
        framesAdvanced = frameCount !== null;
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
      const fresh = framesAdvanced && performance.now() - frameTime < 4000;
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
        const data = await request('answer', {sdp}, lifetime.signal);
        if (ended) return;
        if (!matches(data.camera) || data.camera.state !== 'answered') {
          finish('stopped', 'Camera preview ended.');
          return;
        }
        answerAccepted = true;
        frameTime = performance.now();
        authorizedAt = frameTime;
        healthTimer = setInterval(() => { void health(); }, 500);
      } catch {
        if (!ended) finish('unavailable', 'Could not connect to this camera preview.');
      } finally {
        answering = false;
      }
    }

    async function poll(initial) {
      try {
        const data = initial || await request('status', {}, lifetime.signal);
        if (ended) return;
        const camera = data.camera;
        if (!matches(camera) || camera.state === 'stopped') {
          finish('stopped', 'Camera preview ended.');
          return;
        }
        authorizedAt = performance.now();
        if (camera.device_name) onName?.(camera.device_name);
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
          finish('unavailable', 'Camera preview unavailable.');
        } else {
          display('stalled', 'Reconnecting to the private preview…');
        }
      } finally {
        if (!ended) pollTimer = setTimeout(() => void poll(), 1500);
      }
    }

    if (typeof RTCPeerConnection !== 'function' || !window.VP3PhoneCameraICE) {
      finish('unavailable', 'This browser cannot open a live camera preview.');
    } else {
      void poll(initial);
    }
    return {finish, get answered() { return answerAccepted; }, get ended() { return ended; }};
  }

  window.VP3PhoneCameraPreview = {post, receive};
})();
