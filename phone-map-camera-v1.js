(() => {
  'use strict';

  window.vp3PhoneMapCameraV1 = function (root, seconds, restoreFocus) {
    const dialog = document.getElementById('phone-stream-dialog');
    const panel = document.getElementById('phone-stream-preview');
    const title = document.getElementById('phone-stream-name');
    const status = document.getElementById('phone-stream-status');
    const video = document.getElementById('phone-stream-video');
    const retry = document.getElementById('phone-stream-retry');
    const headers = {'Content-Type':'application/json', 'X-CSRF-Token':root.dataset.csrf,
      'X-VP3-Working-Attention':root.dataset.binding};
    const preview = window.VP3PhoneCameraPreview;
    let current = null;
    let released = Promise.resolve();

    function liveCamera(device) {
      const camera = device?.live_camera;
      return device?.status === 'approved' && camera?.expires_at > seconds()
        && /^[a-f0-9]{32}$/.test(camera.session_id) && Number.isSafeInteger(camera.agent_id) && camera.agent_id >= 0
        && Number.isSafeInteger(camera.conversation_id) && camera.conversation_id > 0 ? camera : null;
    }

    function display(state, message) {
      panel.dataset.state = state;
      if (status.textContent !== message) status.textContent = message;
      retry.hidden = state !== 'unavailable' || !current || !liveCamera(current.device);
    }

    function release(view) {
      if (view.release) return;
      clearTimeout(view.firstFrameTimer);
      // Wait for the open POST before releasing its slot, including a close during the handshake.
      // Serialize the next open so a delayed close cannot block a quick reopen.
      const done = view.opening.catch(() => {}).then(() => view.sent && view.request('close', {}, undefined, true)).catch(() => {});
      view.release = done;
      released = done;
    }

    function finish(view) {
      if (view.receiver) view.receiver.finish('stopped', 'Camera preview ended.');
      release(view);
    }

    function close() {
      const view = current;
      if (view) {
        current = null;
        finish(view);
      }
      if (dialog.open) dialog.close();
      title.textContent = 'Phone camera';
      display('stopped','');
      if (view && !document.hidden) restoreFocus(view.deviceId, view.fromMarker);
    }
    document.getElementById('phone-stream-close').addEventListener('click', close);
    retry.addEventListener('click', () => {
      const view = current;
      if (!view || retry.hidden || liveCamera(view.device)?.session_id !== view.sessionId) return;
      close();
      open(view.device, view.fromMarker);
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => { if (!dialog.open && current) close(); });

    function open(device, fromMarker) {
      const camera = liveCamera(device);
      if (!camera || document.hidden || dialog.open) return;
      const scope = {session_id:camera.session_id, agent_id:camera.agent_id, conversation_id:camera.conversation_id,
        device_id:device.id, viewer_id:crypto.randomUUID().replaceAll('-','')};
      const view = {device, deviceId:device.id, sessionId:camera.session_id, fromMarker, sent:false, receiver:null, release:null};
      view.request = (action, fields, signal, keepalive) => preview.post(dialog.dataset.endpoint, headers,
        {...scope, action:'camera_map_'+action, ...fields}, signal, keepalive).then(result => {
          if (result.camera && result.camera.viewer_id !== scope.viewer_id) throw new Error('Camera viewer changed.');
          return result;
        });
      current = view;
      title.textContent = device.name+' · Private camera';
      display('connecting','Connecting to the phone…');
      dialog.showModal();
      view.opening = released.then(() => {
        if (current !== view || view.release) return;
        view.sent = true;
        return view.request('open', {});
      });
      view.opening.then(initial => {
        if (current !== view || view.release) return;
        // A healthy editor can keep the source available even when this viewer's offer never arrives.
        // Release the map slot if this handshake never delivers moving video.
        view.firstFrameTimer = setTimeout(() => {
          view.receiver?.finish('unavailable', 'No live video arrived from this phone.');
        }, 45000);
        view.receiver = preview.receive({video, sessionId:camera.session_id, deviceId:device.id, request:view.request,
          initial, onEnd:() => release(view), display(state, message) {
            if (state === 'live') clearTimeout(view.firstFrameTimer);
            display(state, message);
          }});
      }).catch(error => {
        if (current !== view || view.release) return;
        display('unavailable', error.status === 409 ? 'This camera preview is open on another map.' : 'Camera preview unavailable.');
        release(view);
      });
    }

    function update(devices) {
      if (!current) return;
      const device = devices.find(device => device.id === current.deviceId);
      if (!device || device.status !== 'approved') { close(); return; }
      current.device = device;
      if (liveCamera(device)?.session_id === current.sessionId) return;
      finish(current);
      display('stopped','Camera preview ended.');
    }
    return {liveCamera, open, close, update};
  };
})();
