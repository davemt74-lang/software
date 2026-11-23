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
  const scope = {agent_id: config.agentId, conversation_id: config.conversationId, session_id: config.sessionId};
  const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf, 'X-VP3-WORKING-ATTENTION': config.binding};
  let stopUnconfirmed = false;

  function display(state, text) {
    panel.dataset.state = state;
    if (status.textContent !== text) status.textContent = text;
  }

  function request(action, fields, signal, keepalive) {
    return window.VP3PhoneCameraPreview.post(config.endpoint, headers, {...scope, action:'camera_'+action, ...fields}, signal, keepalive);
  }
  const receiver = window.VP3PhoneCameraPreview.receive({
    video, sessionId:config.sessionId, request, display,
    onName(deviceName) { name.textContent = deviceName + ' · Private preview'; },
    onEnd() { stopButton.hidden = true; dismiss.hidden = false; }
  });

  async function sendStop() {
    try {
      const data = await request('stop', {}, undefined, true);
      return !data.camera || (data.camera.session_id === config.sessionId && data.camera.state === 'stopped');
    } catch { return false; }
  }
  stopButton.addEventListener('click', async () => {
    if ((receiver.ended && !stopUnconfirmed) || stopButton.disabled) return;
    receiver.finish('stopped', 'Preview closed · confirming stop with the phone…');
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
    const shouldStop = (!receiver.ended && receiver.answered) || stopUnconfirmed;
    receiver.finish('stopped', 'Camera preview closed.');
    if (shouldStop) void sendStop();
  });
})();
