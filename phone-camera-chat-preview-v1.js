(() => {
  'use strict';
  const endpoint = document.currentScript?.dataset.cameraEndpoint;
  const thread = document.getElementById('chatThread');
  const preview = window.VP3PhoneCameraPreview;
  if (!thread || !endpoint || !preview) return;
  const pageScope = () => window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.();
  let current = null;
  let released = Promise.resolve();

  function authorized(view) {
    const scope = pageScope(), cfg = window.STONEFELLOW_CHAT;
    return current === view && !document.hidden && view.panel.isConnected && view.button.isConnected
      && Number(view.post.dataset.chatConversationId) === view.scope.conversation_id
      && scope?.owner === view.scope.owner_user_id && scope?.agent === view.scope.agent_id && scope?.conversation === view.scope.conversation_id
      && cfg?.csrf === view.csrf && cfg?.workingAttentionBinding === view.binding && navigator.onLine !== false;
  }

  function release(view) {
    if (view.released) return;
    view.released = true;
    clearTimeout(view.firstFrameTimer);
    view.recordingCleanup?.();
    view.recordingCleanup = null;
    // If Hide preceded the open response, release that same viewer before a later Show can claim the slot.
    released = view.opening.catch(() => {}).then(() => view.sent && view.request('close', {}, undefined, true)).catch(() => {});
  }

  function close() {
    const view = current;
    if (!view) return;
    current = null;
    view.observer.disconnect();
    view.receiver?.finish('stopped','Camera preview ended.');
    release(view);
    view.panel.remove();
    view.button.textContent = 'Show live stream';
    view.button.setAttribute('aria-expanded','false');
  }

  function display(view,state,text) {
    if (state === 'live') clearTimeout(view.firstFrameTimer);
    view.panel.dataset.state = state;
    if (view.status.textContent !== text) view.status.textContent = text;
  }

  async function initialGrant(view, waitForRelease) {
    await waitForRelease;
    while (authorized(view) && !view.released) {
      view.sent = true;
      const result = await view.request('open');
      // The holder's start announcement can arrive just before the first captured frame.
      if (!result.camera_starting || result.camera) return result;
      await new Promise(resolve => setTimeout(resolve,800));
    }
  }

  function open(button,post,scope) {
    close();
    const cfg = window.STONEFELLOW_CHAT;
    const panel = document.createElement('section');
    panel.className = 'phone-chat-live-preview';
    panel.setAttribute('aria-label','Private phone live stream');
    panel.innerHTML = '<div class="phone-chat-live-media"><video muted autoplay playsinline disablepictureinpicture aria-label="Private phone camera"></video><p role="status"></p></div><div class="phone-chat-live-recording"></div>';
    const video = panel.querySelector('video');
    video.muted = true;
    const view = {button,post,panel,scope,csrf:cfg.csrf,binding:cfg.workingAttentionBinding,status:panel.querySelector('[role="status"]')};
    const viewer = crypto.randomUUID().replaceAll('-','');
    const headers = {'Content-Type':'application/json','X-CSRF-Token':view.csrf,'X-VP3-Working-Attention':view.binding};
    view.request = async (action, fields = {}, signal, keepalive) => {
      if (action !== 'close' && !authorized(view)) return {camera:null};
      const {agent_id,conversation_id,device_id,session_id} = scope;
      const result = await preview.post(endpoint,headers,{agent_id,conversation_id,device_id,session_id,viewer_id:viewer,
        action:'camera_chat_'+action,...fields},signal,keepalive);
      if (result.camera && (result.camera.viewer_id !== viewer || result.camera.device_id !== device_id
          || result.camera.session_id !== session_id)) throw Error('Camera preview changed.');
      return result;
    };
    current = view;
    post.querySelector('.message-body').append(panel);
    button.textContent = 'Hide live stream';
    button.setAttribute('aria-expanded','true');
    display(view,'connecting','Connecting to the phone…');
    view.observer = new MutationObserver(() => { if (current === view && !authorized(view)) close(); });
    view.observer.observe(thread,{childList:true,subtree:true});
    view.opening = initialGrant(view,released);
    view.opening.then(initial => {
      if (!authorized(view) || view.released) return;
      if (initial?.camera) {
        view.recordingCleanup = window.VP3PhoneRecordingOwner?.bind(panel,{scope,endpoint,csrf:view.csrf,binding:view.binding});
      }
      view.firstFrameTimer = setTimeout(() => {
        view.receiver?.finish('unavailable','No live video arrived from this phone. Hide and reopen to retry.');
      },45000);
      view.receiver = preview.receive({video,sessionId:scope.session_id,deviceId:scope.device_id,request:view.request,initial,
        display:(state,text) => display(view,state,text),onEnd:() => release(view)});
    }).catch(error => {
      if (current !== view || view.released) return;
      display(view,'unavailable',error.status === 409 ? 'This stream is open in another Chat preview.' : 'Camera preview unavailable.');
      release(view);
    });
  }

  thread.addEventListener('click',event => {
    const button = event.target.closest?.('[data-phone-live-stream]');
    const post = button?.closest('.message.assistant[data-chat-message-id]');
    if (!post || !thread.contains(post)) return;
    if (button === current?.button) { close(); return; }
    const scope = pageScope(), agent = Number(button.dataset.phoneLiveAgent), cid = Number(button.dataset.phoneLiveConversation);
    if (!scope || scope.agent !== agent || scope.conversation !== cid || Number(post.dataset.chatConversationId) !== cid
        || !/^[a-f0-9]{32}$/.test(button.dataset.phoneLiveStream) || !/^[a-f0-9]{32}$/.test(button.dataset.phoneLiveDevice)) return;
    open(button,post,{owner_user_id:scope.owner,agent_id:agent,conversation_id:cid,device_id:button.dataset.phoneLiveDevice,
      session_id:button.dataset.phoneLiveStream});
  });
  for (const event of ['stonefellow:chat-context-changing','pagehide','offline']) window.addEventListener(event,close);
  function checkScope() { if (current && !authorized(current)) close(); }
  for (const event of ['vp3:chat-context-ready','vp3:chat-conversation-bound','vp3:participant-selection-changed','vp3:chat-canvas-view-changed']) {
    window.addEventListener(event,checkScope);
  }
  document.addEventListener('visibilitychange',checkScope);
})();
