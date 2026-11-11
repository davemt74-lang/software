(() => {
  'use strict';
  const cfg = window.STONEFELLOW_CHAT || {};
  const settings = window.VP3_CHAT_MESSAGE_UNREAD_V1 || {};
  const thread = document.getElementById('chatThread');
  const ownerId = Number(cfg.userId || 0);
  const agentId = Number(cfg.agentId || 0);
  const binding = String(cfg.workingAttentionBinding || '');
  if (!thread || ownerId < 1 || !binding || !settings.endpoint) return;

  let epoch = 0;
  let conversationId = 0;
  let stopped = false;
  let suspended = false;
  let loading = false;
  let queued = false;
  let refreshTimer = 0;
  let acknowledging = false;
  let acknowledgeRetryAt = 0;
  let touchStart = null;
  let scrollbar = null;
  const requests = new Set();
  const unread = new Set();
  const pendingReadIds = new Set();
  let messages = new Map();
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const positiveId = value => Number.isSafeInteger(Number(value)) && Number(value) > 0;

  function configurationMatches() {
    const currentCfg = window.STONEFELLOW_CHAT || {};
    return Number(currentCfg.userId) === ownerId && Number(currentCfg.agentId || 0) === agentId
      && String(currentCfg.workingAttentionBinding || '') === binding;
  }

  function currentConversation() {
    const chat = continuity();
    if (stopped || suspended || !configurationMatches() || !chat?.isReady?.() || chat.isRestoring?.()
      || Number(chat.agentId?.() ?? agentId) !== agentId) return 0;
    const id = Number(chat.conversationId?.() || 0);
    return positiveId(id) ? id : 0;
  }

  function reset(permanent = false) {
    epoch++;
    conversationId = 0;
    stopped ||= permanent;
    for (const controller of requests) controller.abort();
    requests.clear();
    unread.clear();
    clearScrollInput();
    acknowledgeRetryAt = 0;
    messages.clear();
    thread.querySelectorAll('[data-chat-message-new]').forEach(badge => badge.remove());
  }

  function scoped(token, id) {
    if (token !== epoch || id !== conversationId) return false;
    if (!configurationMatches()) { reset(true); return false; }
    if (id !== currentConversation()) { reset(); return false; }
    return true;
  }

  function collectMessages() {
    const found = new Map();
    thread.querySelectorAll('.message[data-chat-message-role="assistant"][data-chat-message-id]').forEach(element => {
      const id = Number(element.dataset.chatMessageId);
      if (positiveId(id) && Number(element.dataset.chatConversationId) === conversationId
        && element.querySelector('.message-text')?.textContent.trim()) found.set(id, element);
    });
    messages = found;
  }

  function paint() {
    for (const [id, element] of messages) {
      const badge = element.querySelector('[data-chat-message-new]');
      if (!unread.has(id)) {
        badge?.remove();
        continue;
      }
      if (badge) continue;
      const host = element.querySelector('.message-role');
      if (!host) continue;
      const next = document.createElement('span');
      next.className = 'chat-message-new';
      next.dataset.chatMessageNew = '';
      next.textContent = 'NEW';
      next.setAttribute('aria-label', 'New message');
      host.append(next);
    }
  }

  async function request(method, ids, token, id) {
    const url = new URL(settings.endpoint, location.href);
    if (url.origin !== location.origin) throw new Error('Invalid Chat read endpoint.');
    const controller = new AbortController();
    requests.add(controller);
    const timeout = setTimeout(() => controller.abort(),10000);
    const options = {method,credentials:'same-origin',signal:controller.signal,
      headers:{'X-VP3-Working-Attention':binding,'Accept':'application/json'}};
    if (method === 'GET') {
      url.searchParams.set('agent_id',String(agentId));
      url.searchParams.set('conversation_id',String(id));
      url.searchParams.set('message_ids',ids.join(','));
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({action:'acknowledge',agent_id:agentId,conversation_id:id,
        message_ids:ids,csrf_token:String(cfg.csrf || '')});
    }
    try {
      const response = await fetch(url,options);
      if (!scoped(token,id)) return null;
      if ([401,403,419].includes(response.status)) {
        reset(true);
        return null;
      }
      if (!response.ok) return null;
      const data = await response.json();
      if (!scoped(token,id) || data.ok !== true || data.agent_id !== agentId || data.conversation_id !== id
        || !Array.isArray(data.message_ids) || !Array.isArray(data.unread_message_ids)
        || data.message_ids.length !== ids.length || data.message_ids.some(value => !ids.includes(value))
        || new Set(data.message_ids).size !== ids.length
        || data.unread_message_ids.some(value => !ids.includes(value))) return null;
      return data;
    } catch (_error) {
      return null;
    } finally {
      clearTimeout(timeout);
      requests.delete(controller);
    }
  }

  async function refresh() {
    if (stopped) return;
    if (!configurationMatches()) { reset(true); return; }
    if (loading || acknowledging) { queued = true; return; }
    const id = currentConversation();
    if (id !== conversationId) { reset(); conversationId = id; }
    if (!id) return;
    collectMessages();
    const token = epoch;
    const ids = [...messages.keys()];
    loading = true;
    try {
      for (let offset = 0; offset < ids.length; offset += 100) {
        const batch = ids.slice(offset,offset + 100);
        const state = await request('GET',batch,token,id);
        if (!scoped(token,id)) return;
        if (!state) continue;
        const fresh = new Set(state.unread_message_ids);
        for (const messageId of batch) {
          if (fresh.has(messageId)) unread.add(messageId);
          else unread.delete(messageId);
        }
        paint();
      }
    } finally {
      loading = false;
      void acknowledgeScrolled();
      if (queued) { queued = false; scheduleRefresh(); }
    }
  }

  function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => void refresh(),50);
  }

  function isVisible(element) {
    if (document.hidden || !document.hasFocus() || thread.dataset.view !== 'chat' || !element?.isConnected) return false;
    const target = element.querySelector('.message-text');
    if (!target || !target.getClientRects().length) return false;
    const box = target.getBoundingClientRect();
    const bounds = thread.getBoundingClientRect();
    const left = Math.max(box.left,bounds.left,0);
    const right = Math.min(box.right,bounds.right,innerWidth);
    const top = Math.max(box.top,bounds.top,0);
    const bottom = Math.min(box.bottom,bounds.bottom,innerHeight);
    if (box.width <= 0 || box.height <= 0 || bottom - top < Math.min(24,box.height) || right - left < 24) return false;
    const x = left + Math.min(16,(right - left) / 2);
    const y = top + Math.min(12,(bottom - top) / 2);
    const hit = document.elementFromPoint(x,y);
    return hit === target || target.contains(hit);
  }

  function clearScrollInput() {
    pendingReadIds.clear();
    touchStart = null;
    scrollbar = null;
  }

  function visibleUnread() {
    return [...unread].filter(messageId => isVisible(messages.get(messageId)));
  }

  function recordScrollInput(messageIds) {
    if (!conversationId || !scoped(epoch,conversationId) || Date.now() < acknowledgeRetryAt) return;
    // Scroll events also fire for automatic positioning. Capture the exact
    // posts visible at the user's input so a later post cannot inherit it.
    for (const messageId of messageIds ?? visibleUnread()) pendingReadIds.add(messageId);
    void acknowledgeScrolled();
  }

  async function acknowledgeScrolled() {
    if (acknowledging || loading || !pendingReadIds.size) return;
    const id = conversationId;
    const token = epoch;
    if (!id || !scoped(token,id) || document.hidden || !document.hasFocus() || thread.dataset.view !== 'chat') {
      clearScrollInput();
      return;
    }
    const batch = [...pendingReadIds].filter(messageId => unread.has(messageId)).slice(0,100);
    if (!batch.length) { pendingReadIds.clear(); return; }
    batch.forEach(messageId => pendingReadIds.delete(messageId));
    acknowledging = true;
    try {
      const result = await request('POST',batch,token,id);
      if (!scoped(token,id)) return;
      if (!result) {
        pendingReadIds.clear();
        acknowledgeRetryAt = Date.now() + 5000;
        return;
      }
      acknowledgeRetryAt = 0;
      const pending = new Set(result.unread_message_ids);
      for (const messageId of batch) {
        if (!pending.has(messageId)) unread.delete(messageId);
      }
      paint();
      try { localStorage.setItem('vp3:chat-read:v1:' + ownerId + ':' + agentId + ':' + id,String(Date.now())); } catch (_error) {}
    } finally {
      acknowledging = false;
      void acknowledgeScrolled();
      if (queued) { queued = false; scheduleRefresh(); }
    }
  }

  if (!thread.hasAttribute('tabindex')) thread.tabIndex = 0;
  thread.addEventListener('wheel',event => {
    if (event.isTrusted && !event.defaultPrevented && !event.ctrlKey && Math.abs(event.deltaY) > Math.abs(event.deltaX)) recordScrollInput();
  },{passive:true});
  thread.addEventListener('touchstart',event => {
    touchStart = event.isTrusted && event.touches.length === 1 ? event.touches[0] : null;
  },{passive:true});
  thread.addEventListener('touchmove',event => {
    const touch = event.touches.length === 1 ? event.touches[0] : null;
    if (!event.isTrusted || event.defaultPrevented || !touchStart || touch?.identifier !== touchStart.identifier) return;
    const dx = Math.abs(touch.clientX - touchStart.clientX);
    const dy = Math.abs(touch.clientY - touchStart.clientY);
    if (Math.max(dx,dy) < 5) return;
    touchStart = touch;
    if (dy > dx) recordScrollInput();
  },{passive:true});
  for (const event of ['touchend','touchcancel']) thread.addEventListener(event,() => { touchStart = null; },{passive:true});
  const scrollKeys = new Set(['ArrowUp','ArrowDown','PageUp','PageDown','Home','End',' ']);
  thread.addEventListener('keydown',event => {
    if (!event.isTrusted || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || !scrollKeys.has(event.key)
      || event.target.closest('input,textarea,select,button,a[href],[contenteditable],[role="button"]')) return;
    recordScrollInput();
  });
  // A native scrollbar can move without wheel or keyboard events.
  thread.addEventListener('pointerdown',event => {
    if (!event.isTrusted || event.defaultPrevented || event.pointerType !== 'mouse' || event.button !== 0
      || event.target !== thread || thread.scrollHeight <= thread.clientHeight) return;
    const bounds = thread.getBoundingClientRect();
    const style = getComputedStyle(thread);
    const left = bounds.left + thread.clientLeft;
    const right = left + thread.clientWidth;
    const inGutter = event.clientX >= bounds.left + parseFloat(style.borderLeftWidth) && event.clientX < left
      || event.clientX >= right && event.clientX < bounds.right - parseFloat(style.borderRightWidth);
    if (inGutter) scrollbar = {pointerId:event.pointerId,position:thread.scrollTop,expiresAt:Infinity,messageIds:visibleUnread()};
  },{passive:true});
  window.addEventListener('pointerup',event => {
    if (event.isTrusted && scrollbar?.pointerId === event.pointerId) scrollbar.expiresAt = Date.now() + 300;
  },{capture:true,passive:true});
  window.addEventListener('pointercancel',event => {
    if (scrollbar?.pointerId === event.pointerId) scrollbar = null;
  },{capture:true,passive:true});
  thread.addEventListener('scroll',event => {
    if (!event.isTrusted || !scrollbar) return;
    if (Date.now() <= scrollbar.expiresAt && thread.scrollTop !== scrollbar.position) recordScrollInput(scrollbar.messageIds);
    scrollbar = null;
  },{passive:true});

  window.addEventListener('stonefellow:chat-context-changing',() => reset());
  window.addEventListener('vp3:chat-context-ready',scheduleRefresh);
  window.addEventListener('vp3:chat-canvas-view-changed',() => { clearScrollInput(); scheduleRefresh(); });
  window.addEventListener('vp3:chat-message-persisted',scheduleRefresh);
  window.addEventListener('focus',scheduleRefresh);
  window.addEventListener('blur',clearScrollInput);
  document.addEventListener('visibilitychange',() => { clearScrollInput(); if (!document.hidden) scheduleRefresh(); });
  window.addEventListener('storage',event => {
    if (event.key === 'vp3:chat-read:v1:' + ownerId + ':' + agentId + ':' + conversationId) scheduleRefresh();
  });
  window.addEventListener('pagehide',() => { suspended = true; reset(); });
  window.addEventListener('pageshow',() => { suspended = false; scheduleRefresh(); });
  setInterval(() => void refresh(),15000);
  window.VP3_CHAT_MESSAGE_UNREAD = {refresh};
  scheduleRefresh();
})();
