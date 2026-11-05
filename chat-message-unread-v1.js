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
  const requests = new Set();
  const unread = new Set();
  const visibleSince = new Map();
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
    visibleSince.clear();
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
          else { unread.delete(messageId); visibleSince.delete(messageId); }
        }
        paint();
      }
    } finally {
      loading = false;
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

  let acknowledging = false;
  let acknowledgeRetryAt = 0;
  async function acknowledgeVisible() {
    if (acknowledging || loading || !conversationId || Date.now() < acknowledgeRetryAt) return;
    if (!configurationMatches()) { reset(true); return; }
    if (currentConversation() !== conversationId) { reset(); return; }
    const now = Date.now();
    const ids = [];
    for (const messageId of unread) {
      if (!isVisible(messages.get(messageId))) { visibleSince.delete(messageId); continue; }
      if (!visibleSince.has(messageId)) visibleSince.set(messageId,now);
      if (now - visibleSince.get(messageId) >= 1000) ids.push(messageId);
    }
    if (!ids.length) return;
    acknowledging = true;
    const id = conversationId;
    const token = epoch;
    try {
      const batch = ids.slice(0,100);
      const result = await request('POST',batch,token,id);
      if (!scoped(token,id)) return;
      if (!result) { acknowledgeRetryAt = Date.now() + 5000; return; }
      acknowledgeRetryAt = 0;
      const pending = new Set(result.unread_message_ids);
      for (const messageId of batch) {
        if (!pending.has(messageId)) { unread.delete(messageId); visibleSince.delete(messageId); }
      }
      paint();
      try { localStorage.setItem('vp3:chat-read:v1:' + ownerId + ':' + agentId + ':' + id,String(Date.now())); } catch (_error) {}
    } finally {
      acknowledging = false;
      if (queued) { queued = false; scheduleRefresh(); }
    }
  }

  window.addEventListener('stonefellow:chat-context-changing',() => reset());
  window.addEventListener('vp3:chat-context-ready',scheduleRefresh);
  window.addEventListener('vp3:chat-canvas-view-changed',() => { visibleSince.clear(); scheduleRefresh(); });
  window.addEventListener('vp3:chat-message-persisted',scheduleRefresh);
  window.addEventListener('focus',() => { visibleSince.clear(); scheduleRefresh(); });
  window.addEventListener('blur',() => visibleSince.clear());
  document.addEventListener('visibilitychange',() => { visibleSince.clear(); if (!document.hidden) scheduleRefresh(); });
  window.addEventListener('storage',event => {
    if (event.key === 'vp3:chat-read:v1:' + ownerId + ':' + agentId + ':' + conversationId) scheduleRefresh();
  });
  window.addEventListener('pagehide',() => { suspended = true; reset(); });
  window.addEventListener('pageshow',() => { suspended = false; scheduleRefresh(); });
  setInterval(() => void acknowledgeVisible(),250);
  setInterval(() => void refresh(),15000);
  window.VP3_CHAT_MESSAGE_UNREAD = {refresh};
  scheduleRefresh();
})();
