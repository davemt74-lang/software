'use strict';

// Camera authority belongs to this phone and one request. GPS has its own controls.
window.VP3PhoneCameraHolder = function (request, inScope = () => true) {
  const element = name => document.getElementById('phone-camera' + name);
  const card = element(''), status = element('-status'), video = element('-preview');
  const start = element('-start'), stop = element('-stop'), decline = element('-decline');
  let deviceId = null, owner = '', current = null, run = null, notice = '';
  let revision = 0, polling = false, pendingStop = null, stopping = false;
  const stopKey = 'vp3-phone-camera-stop:' + location.pathname;
  try { pendingStop = JSON.parse(sessionStorage.getItem(stopKey)); } catch {}
  const ready = () => deviceId && !document.hidden && navigator.onLine !== false && inScope();
  const supported = () => window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.RTCPeerConnection && window.VP3PhoneCameraICE?.gather;
  const valid = camera => camera && inScope(camera) && camera.device_id === deviceId && typeof camera.session_id === 'string'
    && Number.isFinite(camera.expires_at) && camera.expires_at * 1000 > Date.now()
    && ['requested','sharing','answered'].includes(camera.state);
  const stillCurrent = r => run === r && ready() && valid(current) && current.session_id === r.id;

  function render() {
    card.hidden = !deviceId || !inScope() || (!current && !notice);
    let message = notice;
    if (current && !message) {
      if (run) message = run.message;
      else if (current.state === 'requested') message = (owner || 'The account owner')
        + ' requested this camera for a private editor preview. Share video only while this page is open.';
      else message = 'A private camera session is open on this phone. You can stop it here.';
      if (!run && current.state === 'requested' && !supported()) message = 'Camera sharing needs a secure browser with camera and WebRTC access.';
    }
    if (status.textContent !== message) status.textContent = message;
    start.hidden = !current || current.state !== 'requested' || !!run;
    start.disabled = !ready() || !supported();
    decline.hidden = !current || current.state !== 'requested' || !!run;
    stop.hidden = !run && (!current || current.state === 'requested');
  }

  function saveStop(item) {
    pendingStop = item;
    try {
      if (item) sessionStorage.setItem(stopKey,JSON.stringify(item));
      else sessionStorage.removeItem(stopKey);
    } catch {}
  }
  async function flushStop(keepalive = false) {
    const item = pendingStop;
    if (!item || stopping || navigator.onLine === false) return;
    stopping = true;
    try {
      await request('camera_stop',{session_id:item.sessionId,...(item.requestedOnly ? {requested_only:true} : {})},keepalive);
      if (pendingStop === item) saveStop(null);
    } catch (e) {
      if ([401,403,404,409,410].includes(e.status) && pendingStop === item) saveStop(null);
    } finally { stopping = false; }
  }
  function closeMedia() {
    const old = run;
    run = null;
    if (old) {
      clearTimeout(old.connectTimer);
      old.abort.abort();
      old.pc?.close();
      for (const track of old.stream?.getTracks() || []) track.stop();
    }
    video.pause();
    video.srcObject = null;
    video.hidden = true;
  }
  function end(text, notify = true, keepalive = false) {
    const id = current?.session_id || run?.id;
    const requestedOnly = current?.state === 'requested' && !run?.granted;
    revision++;
    closeMedia();
    current = null;
    notice = text;
    if (notify && id && deviceId) {
      saveStop({deviceId,sessionId:id,requestedOnly});
      void flushStop(keepalive);
    }
    render();
  }

  async function applyAnswer(r, camera) {
    if (!stillCurrent(r) || !r.offered || r.answering || r.pc.remoteDescription || !camera.answer_sdp) return;
    r.answering = true;
    try { await r.pc.setRemoteDescription({type:'answer',sdp:camera.answer_sdp}); }
    catch { if (run === r) end('The editor could not connect. Camera sharing stopped.'); }
    finally { r.answering = false; }
  }
  async function startCamera() {
    if (!ready() || !supported() || !valid(current) || current.state !== 'requested' || run) return;
    revision++;
    const r = {id:current.session_id,abort:new AbortController(),message:'Waiting for camera permission…'};
    run = r;
    notice = '';
    render();
    try {
      const stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
      if (!stillCurrent(r)) { stream.getTracks().forEach(t => t.stop()); return; }
      r.stream = stream;
      const [track] = stream.getVideoTracks();
      if (!track || track.readyState !== 'live' || stream.getTracks().length !== 1) throw new Error('No usable camera');
      track.addEventListener('ended',() => { if (run === r) end('The camera disconnected. Sharing stopped.'); },{once:true});
      let grant;
      try { grant = await request('camera_start',{session_id:r.id}); }
      catch (error) {
        if (run === r && error.status === 409) {
          end('Another page already answered this camera request.',false);
          void poll();
          return;
        }
        throw error;
      }
      if (!stillCurrent(r)) {
        // This page stopped during authorization. Release its successful grant even if the earlier decline raced it.
        if (grant.camera?.session_id === r.id && grant.camera?.state === 'sharing') {
          void request('camera_stop',{session_id:r.id},true).catch(() => {});
        }
        return;
      }
      if (!valid(grant.camera) || grant.camera.session_id !== r.id || grant.camera.state !== 'sharing') throw new Error('Request expired');
      revision++;
      current = grant.camera;
      r.granted = true;
      const pc = new RTCPeerConnection({iceServers:grant.ice_servers || []});
      r.pc = pc;
      pc.addTransceiver(track,{direction:'sendonly',streams:[stream]});
      function connectionChanged() {
        if (run !== r) return;
        const state = pc.connectionState || pc.iceConnectionState;
        if (['failed','closed','disconnected'].includes(state)) end('The editor disconnected. Camera sharing stopped.');
        else if (state === 'connected' || state === 'completed') {
          clearTimeout(r.connectTimer);
          r.message = 'Sharing camera video with the private editor preview.';
          render();
        }
      }
      pc.addEventListener('connectionstatechange',connectionChanged);
      pc.addEventListener('iceconnectionstatechange',connectionChanged);
      video.srcObject = stream;
      video.hidden = false;
      void video.play().catch(() => {});
      r.message = 'Camera ready. Waiting for the private editor preview.';
      render();
      const offer = await pc.createOffer();
      if (!stillCurrent(r)) return;
      await pc.setLocalDescription(offer);
      const sdp = await window.VP3PhoneCameraICE.gather(pc,r.abort.signal);
      if (!stillCurrent(r)) return;
      const response = await request('camera_offer',{session_id:r.id,sdp});
      if (!stillCurrent(r)) return;
      if (!valid(response.camera) || response.camera.session_id !== r.id) throw new Error('Request ended');
      r.offered = true;
      r.connectTimer = setTimeout(() => {
        if (run === r) end('The private editor did not connect. Camera sharing stopped.');
      },60000);
      await applyAnswer(r,response.camera);
      void poll();
    } catch (error) {
      if (r === run) end(error.name === 'NotAllowedError'
        ? 'Camera permission was declined. Sharing is off.'
        : 'The camera could not start a private preview. Sharing is off.');
    }
  }

  async function poll() {
    if (run && !valid(current)) end('The camera request expired. Sharing stopped.');
    if (!ready() || polling) return;
    const stamp = revision;
    polling = true;
    try {
      if (pendingStop?.deviceId === deviceId) await flushStop();
      const {camera} = await request('camera_status',run?.granted ? {session_id:run.id,heartbeat:true} : {});
      if (stamp !== revision || !ready()) return;
      const next = valid(camera) && camera.session_id !== pendingStop?.sessionId ? camera : null;
      if (run && next?.session_id !== run.id) end('This camera session has ended.',false);
      if (next && next.session_id !== current?.session_id) notice = '';
      current = next;
      if (run && next) await applyAnswer(run,next);
    } catch {
      if (stamp !== revision) return;
      if (run) end('Connection to VP3 was lost. Camera sharing stopped.');
      else current = null;
    } finally { polling = false; render(); }
  }
  function suspend() {
    if (run) end('Camera sharing stopped when this page became inactive.',true,true);
  }
  document.addEventListener('visibilitychange',() => { if (document.hidden) suspend(); else void poll(); });
  window.addEventListener('pagehide',suspend);
  window.addEventListener('offline',() => { if (run) end('Camera sharing stopped while this phone is offline.'); });
  window.addEventListener('online',() => void poll());
  start.addEventListener('click',startCamera);
  stop.addEventListener('click',() => { if (run || valid(current)) end('Camera sharing stopped.'); });
  decline.addEventListener('click',() => { if (valid(current) && current.state === 'requested') end('Camera request declined.'); });
  setInterval(poll,2000);
  return {bind(id,name) {
    owner = name;
    if (id === deviceId) return;
    end('',!!run,true);
    deviceId = id;
    if (id && pendingStop && pendingStop.deviceId !== id) saveStop(null);
    render();
    if (id) void poll();
  }};
};
