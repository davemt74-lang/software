(() => {
  'use strict';
  const endpoint = document.currentScript?.dataset.replyEndpoint;
  const thread = document.getElementById('chatThread');
  if (!endpoint || !thread) return;
  const drafts = new Map();
  const scope = () => window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.();

  function matches(post, action) {
    const cfg = window.STONEFELLOW_CHAT, page = scope();
    return post.isConnected && thread.contains(post) && page && cfg
      && page.owner === Number(cfg.userId) && page.agent === action.agent_id && page.conversation === action.conversation_id
      && post.dataset.chatMessageRole === 'user' && Number(post.dataset.chatMessageId) === action.message_id
      && Number(post.dataset.chatConversationId) === action.conversation_id;
  }

  function close(draft, focus = false) {
    draft.controller?.abort();
    drafts.delete(draft.button);
    draft.form.remove();
    draft.button.setAttribute('aria-expanded','false');
    if (focus && draft.button.isConnected) draft.button.focus();
  }

  function retire(button, message) {
    const draft = drafts.get(button);
    if (draft) close(draft);
    if (message) {
      const status = document.createElement('span');
      status.setAttribute('role','status');
      status.textContent = message;
      button.replaceWith(status);
    } else button.remove();
  }

  function received(post, originalId) {
    const cid = Number(post.dataset.chatConversationId);
    if (!Number.isSafeInteger(originalId) || originalId < 1 || !Number.isSafeInteger(cid) || cid < 1) return;
    const button = thread.querySelector('[data-chat-conversation-id="'+cid+'"][data-chat-message-id="'+originalId+'"] [data-phone-reply-button]');
    if (button) retire(button,'');
  }

  function open(button, post, action, name) {
    const existing = drafts.get(button);
    if (existing) { existing.input.focus(); return; }
    if (!matches(post,action)) return;
    const cfg = window.STONEFELLOW_CHAT;
    const form = document.createElement('form');
    form.dataset.phoneOwnerReply = '';
    form.innerHTML = '<label><span></span><textarea rows="2" maxlength="1000" required></textarea></label>'
      + '<div class="chat-agent-actions"><button class="chat-agent-action-button" type="submit">Send reply</button>'
      + '<button class="chat-agent-action-button" type="button">Cancel</button></div><p role="status" aria-live="polite"></p>';
    const input = form.querySelector('textarea'), send = form.querySelector('[type="submit"]');
    const cancel = form.querySelector('[type="button"]'), status = form.querySelector('[role="status"]');
    form.querySelector('label span').textContent = 'Reply to '+name;
    const draft = {form,input,button,post,action,csrf:cfg.csrf,binding:cfg.workingAttentionBinding,controller:null};
    const current = () => drafts.get(button) === draft && matches(post,action)
      && window.STONEFELLOW_CHAT?.csrf === draft.csrf && window.STONEFELLOW_CHAT?.workingAttentionBinding === draft.binding;
    drafts.set(button,draft);
    post.querySelector('.message-body').append(form);
    button.setAttribute('aria-expanded','true');
    send.disabled = true;
    input.addEventListener('input',() => { send.disabled = !input.value.trim() || !!draft.controller; });
    cancel.addEventListener('click',() => close(draft,true));
    form.addEventListener('keydown',event => {
      if (event.key === 'Escape' && !draft.controller) {
        event.preventDefault();
        event.stopPropagation();
        close(draft,true);
      }
    });
    form.addEventListener('submit',async event => {
      event.preventDefault();
      if (!current() || draft.controller) return;
      const message = input.value.trim();
      if (!message || new TextEncoder().encode(message).length > 3000) {
        status.textContent = 'Enter a shorter reply.'; return;
      }
      const controller = new AbortController();
      draft.controller = controller;
      const timeout = setTimeout(() => controller.abort(),15000);
      input.readOnly = true;
      send.disabled = cancel.disabled = true;
      status.textContent = 'Sending…';
      try {
        const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
          signal:controller.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':draft.csrf,
            'X-VP3-Working-Attention':draft.binding},
          body:JSON.stringify({action:'reply',device_id:action.device_id,request_id:action.request_id,
            agent_id:action.agent_id,conversation_id:action.conversation_id,message})});
        if (!current()) return;
        if (response.status === 409) {
          retire(button,'This message already has a reply.');
        } else if ([401,403,404,419].includes(response.status)) {
          retire(button,'Reply unavailable. Refresh Chat to check this connection.'); return;
        } else {
          if (!response.ok) throw new Error('Reply unavailable');
          const data = await response.json();
          if (!current()) return;
          if (data.ok !== true || !Number.isSafeInteger(data.reply_message_id) || data.reply_message_id < 1) throw new Error('Missing reply');
          retire(button,'Reply sent to '+name+'.');
        }
        // The saved reply appears as an owner message in this same private thread.
        if (matches(post,action)) void window.STONEFELLOW_CHAT_CONTINUITY?.syncConversation(action.conversation_id)?.catch(() => {});
      } catch {
        if (current()) status.textContent = 'Delivery could not be confirmed. You can send this reply again.';
      } finally {
        clearTimeout(timeout);
        if (drafts.get(button) === draft) {
          draft.controller = null;
          input.readOnly = false;
          cancel.disabled = false;
          send.disabled = !input.value.trim();
        }
      }
    });
    input.focus();
  }

  function mount(post, actions, sender) {
    const action = actions?.find(item => item?.type === 'phone_owner_reply');
    if (!action || !sender || post.dataset.chatMessageRole !== 'user'
        || !Number.isSafeInteger(action.message_id) || action.message_id < 1 || Number(post.dataset.chatMessageId) !== action.message_id
        || !Number.isSafeInteger(action.conversation_id) || action.conversation_id < 1 || Number(post.dataset.chatConversationId) !== action.conversation_id
        || !Number.isSafeInteger(action.agent_id) || action.agent_id < 0 || !/^[0-9a-f]{32}$/.test(action.device_id)
        || !/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(action.request_id)) return;
    const controls = post.querySelector('.chat-agent-actions');
    if (!controls || controls.querySelector('[data-phone-reply-button]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'chat-agent-action-button';
    button.dataset.phoneReplyButton = '';
    button.setAttribute('aria-expanded','false');
    button.textContent = 'Reply';
    button.addEventListener('click',() => open(button,post,action,sender));
    controls.append(button);
  }

  function checkDrafts() {
    for (const draft of drafts.values()) if (!matches(draft.post,draft.action)) close(draft);
  }
  for (const name of ['stonefellow:chat-context-changing','pagehide']) {
    window.addEventListener(name,() => { for (const draft of drafts.values()) close(draft); });
  }
  for (const name of ['vp3:chat-context-ready','vp3:chat-conversation-bound','vp3:participant-selection-changed','vp3:chat-canvas-view-changed']) {
    window.addEventListener(name,checkDrafts);
  }
  new MutationObserver(checkDrafts).observe(thread,{childList:true,subtree:true});
  window.VP3PhoneOwnerReply = {mount,received};
})();
