'use strict';

// Camera authority belongs to this phone and one request. GPS has its own controls.
window.VP3PhoneCameraHolder = function (request, inScope = () => true) {
  const element = name => document.getElementById('phone-camera' + name);
  const card = element(''), status = element('-status'), video = element('-preview');
  const start = element('-start'), stop = element('-stop'), decline = element('-decline');
  // Signed-in Agent Chat uses the existing inline request controls. The paired page also hosts the Camera app.
  const app = card?.tagName === 'DIALOG' ? card : null;
  const local = element('-local'), lens = element('-lens'), back = element('-close'), mode = element('-mode');
  let expanded = false, preview = null, opening = null, preparing = null, facing = 'environment';
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
    const visible = !!deviceId && inScope() && (expanded || current || notice);
    card.hidden = !visible;
    if (app) {
      if (visible && !app.open) {
        if (expanded) app.showModal();
        else app.show();
      } else if (!visible && app.open) app.close();
    }
    let message = notice;
    if (current && !message) {
      if (run) message = run.message;
      else if (current.state === 'requested') message = 'Share video with ' + (owner || 'the account owner')
        + ' in the private editor preview while this page is open.';
      else message = 'A private camera session is open on this phone. You can stop it here.';
      if (!run && current.state === 'requested' && !supported()) message = 'Camera sharing needs a secure browser with camera and WebRTC access.';
    }
    if (app && expanded && !message) message = preview ? 'Preview is visible only on this phone.' :
      'Preview the camera here or share video with the private editor.';
    if (app && expanded && opening) message = 'Waiting for camera permission…';
    if (app && expanded && preparing) message = 'Connecting to the private editor…';
    if (app && expanded && !supported()) message = 'Open Camera in a secure browser with camera and WebRTC access.';
    if (app) {
      back.hidden = !expanded;
      lens.hidden = !expanded || !!run || !!opening || !!preparing || (!!current && current.state !== 'requested');
      lens.textContent = facing === 'environment' ? 'Rear camera' : 'Front camera';
      lens.setAttribute('aria-label',lens.textContent + '. Switch camera');
      lens.disabled = !ready() || !supported();
      local.hidden = !expanded || !!preview || !!run || !!preparing || (!!current && current.state !== 'requested');
      local.disabled = !ready() || !supported() || !!opening;
      mode.hidden = !expanded || video.hidden;
      mode.dataset.sharing = String(!!run?.granted);
      mode.textContent = run?.granted ? 'Private editor' : 'Local preview';
    }
    if (status.textContent !== message) status.textContent = message;
    start.hidden = (!current && !expanded) || (!!current && current.state !== 'requested') || !!run;
    start.disabled = !ready() || !supported() || !!preparing || !!opening || !!pendingStop;
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
  function stopPreview() {
    opening = null;
    preparing = null;
    if (preview) preview.getTracks().forEach(track => track.stop());
    preview = null;
    if (!run) { video.srcObject = null; video.hidden = true; }
  }
  function showStream(stream) {
    video.srcObject = stream;
    video.hidden = false;
    void video.play().catch(() => {});
  }
  function open() {
    if (!app || !ready()) return;
    if (!expanded) {
      expanded = true;
      if (app.open) app.close();
      render();
      back.focus();
    }
  }
  function leave() {
    if (!app || !expanded) return;
    if (run) end('Camera sharing stopped.');
    stopPreview();
    expanded = false;
    notice = '';
    if (app.open) app.close();
    render();
    const launcher = document.getElementById('phone-app-camera');
    (launcher?.getClientRects().length ? launcher : document.getElementById('phone-apps-toggle'))?.focus();
  }
  async function localPreview() {
    if (!expanded || !ready() || run || opening || preparing || (!!current && current.state !== 'requested') || !supported()) return null;
    stopPreview();
    const attempt = {};
    opening = attempt;
    notice = '';
    render();
    try {
      const stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facing}},audio:false});
      if (opening !== attempt || !ready() || !expanded) { stream.getTracks().forEach(t => t.stop()); return null; }
      const [track] = stream.getVideoTracks();
      if (!track || track.readyState !== 'live') { stream.getTracks().forEach(t => t.stop()); throw Error(); }
      preview = stream;
      track.addEventListener('ended',() => {
        if (preview === stream) { stopPreview(); notice = 'Camera disconnected. Tap Preview to try again.'; render(); }
      },{once:true});
      showStream(stream);
      return stream;
    } catch (error) {
      if (opening === attempt) notice = error.name === 'NotAllowedError' ?
        'Camera permission was declined. Sharing is off.' : 'Camera unavailable. Tap Preview to try again.';
      return null;
    } finally { if (opening === attempt) opening = null; render(); }
  }
  function closeMedia() {
    stopPreview();
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
    if (!ready() || !supported() || run || opening || preparing || pendingStop) return;
    if (app && (expanded || valid(current))) open();
    if (!current && expanded) {
      // Capture only after a holder tap, and disclose the destination before transmitting video.
      const stream = preview || await localPreview();
      if (!stream || stream !== preview || !ready() || !expanded || preparing) return;
      const attempt = {};
      preparing = attempt;
      revision++;
      notice = '';
      render();
      try {
        const {camera} = await request('camera_prepare',{device_id:deviceId});
        if (preparing !== attempt || !ready() || !expanded || preview !== stream) return;
        if (!valid(camera) || camera.state !== 'requested') throw Error();
        current = camera;
      } catch (error) {
        if (preparing === attempt) notice = error.status === 409 ?
          'Another camera request is open. Finish it before sharing.' : 'The private editor is unavailable. Your preview stays on this phone.';
        return;
      } finally { if (preparing === attempt) preparing = null; render(); }
    }
    if (!valid(current) || current.state !== 'requested') return;
    revision++;
    const r = {id:current.session_id,abort:new AbortController(),message:'Waiting for camera permission…'};
    run = r;
    notice = '';
    render();
    try {
      const stream = preview || await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facing}},audio:false});
      if (preview === stream) preview = null;
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
      showStream(stream);
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
    if (!ready() || polling || preparing) return;
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
      else { current = null; stopPreview(); }
    } finally { polling = false; render(); }
  }
  function suspend() {
    if (run) end('Camera sharing stopped when this page became inactive.',true,true);
    else if (opening || preview || preparing) { stopPreview(); notice = 'Camera paused. Tap Preview to resume.'; render(); }
  }
  document.addEventListener('visibilitychange',() => { if (document.hidden) suspend(); else void poll(); });
  window.addEventListener('pagehide',suspend);
  window.addEventListener('offline',() => { if (run) end('Camera sharing stopped while this phone is offline.'); else suspend(); });
  window.addEventListener('online',() => void poll());
  if (app) {
    back.addEventListener('click',leave);
    app.addEventListener('cancel',event => { event.preventDefault(); leave(); });
    local.addEventListener('click',localPreview);
    lens.addEventListener('click',() => {
      if (!ready() || run || opening || preparing) return;
      facing = facing === 'environment' ? 'user' : 'environment';
      if (preview) void localPreview();
      else render();
    });
  }
  start.addEventListener('click',startCamera);
  stop.addEventListener('click',() => { if (run || valid(current)) end('Camera sharing stopped.'); });
  decline.addEventListener('click',() => { if (valid(current) && current.state === 'requested') end('Camera request declined.'); });
  setInterval(poll,2000);
  return {open,bind(id,name) {
    owner = name;
    if (id === deviceId) return;
    expanded = false;
    if (app?.open) app.close();
    end('',!!run,true);
    deviceId = id;
    if (id && pendingStop && pendingStop.deviceId !== id) saveStop(null);
    render();
    if (id) void poll();
  }};
};
