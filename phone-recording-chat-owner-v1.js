(() => {
  'use strict';

  const labels = new Map([
    ['requested', 'Waiting for the phone holder to accept.'],
    ['accepted', 'The phone is recording.'],
    ['reviewing', 'The phone holder is reviewing the take.'],
    ['declined', 'The phone holder declined the recording request.'],
    ['submitted', 'The phone holder submitted the recording.'],
    ['expired', 'The recording request expired.'],
  ]);
  const activeStates = new Set(['requested', 'accepted', 'reviewing']);

  function privateClip(recording, endpoint) {
    if (recording?.state !== 'submitted' || !Number.isSafeInteger(recording.asset_id)
        || recording.asset_id < 1 || typeof recording.file_url !== 'string') return null;
    try {
      const expected = new URL('../media-library-file-v86.php', new URL(endpoint, location.href));
      expected.searchParams.set('id', String(recording.asset_id));
      const url = new URL(recording.file_url, location.href);
      return url.origin === location.origin && url.href === expected.href ? url.href : null;
    } catch { return null; }
  }

  function bind(panel, {scope, endpoint, csrf, binding}) {
    const host = panel.querySelector('.phone-chat-live-recording');
    if (!host) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'chat-agent-action-button';
    button.dataset.phoneRecordingRequest = '';
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.dataset.phoneRecordingStatus = '';
    const link = document.createElement('a');
    link.className = 'chat-agent-action';
    link.dataset.phoneRecordingClip = '';
    link.textContent = 'Open submitted recording';
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.hidden = true;
    host.dataset.phoneRecordingOwner = '';
    host.replaceChildren(status, link, button);

    const lifetime = new AbortController();
    const {agent_id, conversation_id, device_id, session_id} = scope;
    const headers = {'Content-Type':'application/json', 'X-CSRF-Token':csrf, 'X-VP3-Working-Attention':binding};
    let latest = null;
    let pendingKey = null;
    let ready = false;
    let writing = false;
    let version = 0;
    let timer;

    function stop() {
      if (lifetime.signal.aborted) return;
      lifetime.abort();
      clearTimeout(timer);
      button.removeEventListener('click', request);
      host.replaceChildren();
      delete host.dataset.phoneRecordingOwner;
      delete host.dataset.state;
    }

    function current() {
      const page = window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.(), config = window.STONEFELLOW_CHAT;
      if (lifetime.signal.aborted) return false;
      if (panel.isConnected && !document.hidden && page?.owner === scope.owner_user_id && page?.agent === agent_id
          && page?.conversation === conversation_id && config?.csrf === csrf && config?.workingAttentionBinding === binding) return true;
      stop();
      return false;
    }

    function render(message) {
      const state = latest?.state || 'idle';
      host.dataset.state = state;
      const text = message ?? (pendingKey && !writing ? 'The request is unconfirmed. You can retry it.' : labels.get(state) || '');
      if (status.textContent !== text) status.textContent = text;
      status.hidden = !text;
      button.hidden = activeStates.has(state);
      button.disabled = !ready || writing;
      button.textContent = pendingKey && !writing ? 'Retry recording request' : latest ? 'Request another recording' : 'Request recording';
      const clip = privateClip(latest, endpoint);
      if (clip) link.href = clip;
      else link.removeAttribute('href');
      link.hidden = !clip;
    }

    function useRecording(recording) {
      if (recording !== null && (!recording || !labels.has(recording.state)
          || (recording.device_id !== undefined && recording.device_id !== device_id)
          || (recording.session_id !== undefined && recording.session_id !== session_id))) throw Error('Invalid recording status.');
      if (recording && (recording.request_id === pendingKey || activeStates.has(recording.state))) pendingKey = null;
      latest = recording;
      ready = true;
    }

    function call(action, fields) {
      return window.VP3PhoneCameraPreview.post(endpoint, headers,
        {action, agent_id, conversation_id, device_id, session_id, ...fields}, lifetime.signal);
    }

    function handleError(error, message) {
      if ([401, 403, 404, 419].includes(error.status)) stop();
      else render(message);
    }

    function pollLater() {
      if (!lifetime.signal.aborted) timer = setTimeout(refresh, 3000);
    }

    async function refresh() {
      if (!current() || writing) return;
      const at = version;
      try {
        const result = await call('camera_record_status');
        if (!current() || at !== version) return;
        useRecording(result.recording);
        render();
      } catch (error) {
        if (current() && at === version) handleError(error, 'Recording status is unavailable. Retrying…');
      } finally {
        if (at === version) pollLater();
      }
    }

    async function request() {
      if (!current() || writing || !ready || activeStates.has(latest?.state)) return;
      clearTimeout(timer);
      version++;
      writing = true;
      pendingKey ??= crypto.randomUUID().replaceAll('-', '');
      render('Asking the phone holder…');
      try {
        const result = await call('camera_record_request', {request_id:pendingKey});
        if (!current()) return;
        if (!result.recording || result.recording.request_id !== pendingKey) throw Error('Unconfirmed request.');
        useRecording(result.recording);
        writing = false;
        render();
      } catch (error) {
        if (!current()) return;
        writing = false;
        if (error.status === 409) {
          pendingKey = null;
          ready = false;
          await refresh();
          return;
        }
        handleError(error, 'The request is unconfirmed. You can retry it.');
      }
      pollLater();
    }

    button.addEventListener('click', request);
    render();
    refresh();
    return stop;
  }

  window.VP3PhoneRecordingOwner = {bind};
})();
