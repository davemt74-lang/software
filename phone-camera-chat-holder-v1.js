(() => {
  'use strict';
  const thread = document.getElementById('chatThread');
  if (!thread || !window.VP3PhoneCameraHolder) return;
  const pageScope = () => window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.();
  const editorUrl = new URL('video-editor.php',location.href);
  const endpoint = new URL('api/phone-location-v1.php',location.href);
  let invitation = null, panel = null, holder = null;
  let probe = null;

  function inScope(camera) {
    const scope = pageScope();
    if (!invitation || !invitation.post.isConnected || !scope ||
        scope.owner !== invitation.owner || scope.agent !== invitation.agent ||
        scope.conversation !== invitation.conversation) return false;
    return !camera || (camera.session_id === invitation.session &&
      camera.owner_user_id === scope.owner && camera.agent_id === scope.agent && camera.conversation_id === scope.conversation);
  }

  function request(action,data = {},keepalive = false) {
    // Even on signed-in Chat, the holder API authorizes this phone's HttpOnly pairing cookie.
    // A stop for the original session can still complete after its Chat card has closed.
    if (action !== 'camera_stop' && (!inScope() || data.session_id && data.session_id !== invitation.session)) {
      throw new Error('Camera request changed.');
    }
    return post({action,session_id:data.session_id || invitation.session,...data},keepalive);
  }

  async function post(payload,keepalive = false) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(),8000);
    try {
      const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
        redirect:'error',referrerPolicy:'no-referrer',keepalive,signal:controller.signal,
        headers:{'Content-Type':'application/json','X-VP3-Phone':'1'},
        body:JSON.stringify(payload)});
      const result = await response.json();
      if (!response.ok || result.ok !== true) {
        const error = new Error('Camera unavailable.');
        error.status = response.status;
        throw error;
      }
      return result;
    } finally { clearTimeout(timer); }
  }

  function close() {
    holder?.bind(null,'');
    panel?.remove();
    invitation = null;
    probe = null;
  }

  function mount() {
    if (!panel) {
      panel = document.createElement('section');
      panel.id = 'phone-camera';
      panel.hidden = true;
      panel.setAttribute('aria-label','Private phone camera');
      panel.innerHTML = '<p role="status" aria-live="polite" id="phone-camera-status"></p>' +
        '<video aria-label="Your camera preview" id="phone-camera-preview" width="200" muted autoplay playsinline hidden></video>' +
        '<div class="chat-agent-actions">' +
        '<button class="chat-agent-action-button" type="button" id="phone-camera-start" hidden>Start Sharing</button>' +
        '<button class="chat-agent-action-button" type="button" id="phone-camera-decline" hidden>Decline</button>' +
        '<button class="chat-agent-action-button" type="button" id="phone-camera-stop" hidden>Stop Sharing</button></div>';
    }
    invitation.post.querySelector('.message-body').append(panel);
    holder ??= window.VP3PhoneCameraHolder(request,inScope);
  }

  function detect() {
    const scope = pageScope();
    if (!scope) { close(); return; }
    const selector = '.message.assistant[data-chat-conversation-id="' + scope.conversation + '"] .chat-agent-actions a[href*="camera_session="]';
    const actions = [...thread.querySelectorAll(selector)].reverse();
    for (const action of actions) {
      const url = new URL(action.href);
      const id = url.searchParams.get('camera_session');
      if (url.origin !== editorUrl.origin || url.pathname !== editorUrl.pathname || !/^[a-f0-9]{32}$/.test(id || '')
          || url.searchParams.get('conversation_id') !== String(scope.conversation)
          || url.searchParams.get('agent') !== String(scope.agent || 'system')) continue;
      const post = action.closest('.message.assistant');
      if (invitation?.session === id && invitation.conversation === scope.conversation &&
          invitation.owner === scope.owner && invitation.agent === scope.agent) {
        invitation.post = post;
        if (panel?.parentNode && !post.contains(panel)) post.querySelector('.message-body').append(panel);
      } else {
        close();
        invitation = {...scope,post,session:id};
      }
      if (probe === invitation) return;
      const expected = invitation;
      probe = expected;
      request('camera_status').then(({camera}) => {
        if (invitation !== expected || !camera || !inScope(camera) || !['requested','sharing','answered'].includes(camera.state)) return;
        mount();
        holder.bind(camera.device_id,'Your Agent');
      }).catch(error => {
        // A desktop browser without this phone's cookie will never display holder controls.
        if (probe === expected && ![401,403,404].includes(error.status)) probe = null;
      });
      return;
    }
    close();
  }

  // The owner may send the request from another browser while the paired phone already has Chat open.
  // Only a paired phone keeps checking. Restore the real announcement before showing its controls.
  let discovering = false, paired = true, requestedSync = null;
  async function discover() {
    const scope = pageScope();
    if (!scope || document.hidden || !paired || discovering || navigator.onLine === false) return;
    discovering = true;
    try {
      const {camera} = await post({action:'camera_status'});
      const live = pageScope();
      if (!camera || !live || live.owner !== scope.owner || live.agent !== scope.agent || live.conversation !== scope.conversation
          || camera.owner_user_id !== live.owner || camera.agent_id !== live.agent || camera.conversation_id !== live.conversation
          || !/^[a-f0-9]{32}$/.test(camera.session_id) || camera.session_id === invitation?.session) return;
      if (requestedSync?.session === camera.session_id && Date.now() - requestedSync.time < 10000) return;
      requestedSync = {session:camera.session_id,time:Date.now()};
      await window.STONEFELLOW_CHAT_CONTINUITY.syncConversation(scope.conversation);
      schedule();
    } catch (error) {
      if ([401,403].includes(error.status)) paired = false;
    } finally { discovering = false; }
  }
  setInterval(discover,6000);
  window.addEventListener('vp3:chat-context-ready',discover);
  window.addEventListener('pageshow',() => { paired = true; void discover(); });

  let queued = false;
  function schedule() {
    if (queued) return;
    queued = true;
    queueMicrotask(() => { queued = false; detect(); });
  }
  window.addEventListener('stonefellow:chat-context-changing',close);
  for (const event of ['vp3:chat-context-ready','vp3:chat-message-persisted','vp3:chat-conversation-bound',
    'vp3:participant-selection-changed','vp3:chat-canvas-view-changed','online','pageshow']) {
    window.addEventListener(event,schedule);
  }
  schedule();
})();
