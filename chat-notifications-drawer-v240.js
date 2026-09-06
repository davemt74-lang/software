(() => {
  'use strict';

  const cfg = window.STONEFELLOW_NOTIFICATION_DRAWER || {};
  if (!cfg.endpoint || !cfg.csrf) return;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));
  const relative = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.floor(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return date.toLocaleDateString();
  };
  const stateLabel = value => ({
    working:'Working', paused:'Paused', idle:'Idle', logged_out:'Logged out'
  }[String(value || '')] || String(value || 'Activity').replaceAll('_', ' '));

  let state = null;
  let drawer = null;
  let backdrop = null;
  let button = null;
  let activeTab = 'notifications';
  let busy = false;
  let attentionCursor = 0;
  let attentionTimer = 0;
  let attentionBusy = false;
  let responseTimer = 0;
  let responseTemporaryVoice = false;
  let responseWindowActive = false;
  let speechQueue = Promise.resolve();
  let agentVoicePreference = cfg.agentVoiceEnabled !== false;

  async function request(action = 'state', payload = null, query = {}) {
    const post = payload !== null;
    let url = cfg.endpoint;
    if (!post) {
      const target = new URL(cfg.endpoint, window.location.href);
      target.searchParams.set('action', action);
      Object.entries(query || {}).forEach(([key,value]) => target.searchParams.set(key, String(value)));
      url = target.toString();
    }
    const options = post ? {
      method:'POST', credentials:'same-origin', cache:'no-store',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, csrf_token:cfg.csrf, ...payload})
    } : {credentials:'same-origin', cache:'no-store'};
    const response = await fetch(url, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'Unable to load notifications.');
    return data;
  }

  function updateUnread() {
    const count = Number(state?.notifications?.unread || 0);
    if (!button) return;
    let badge = button.querySelector(':scope > span');
    if (count > 0) {
      if (!badge) { badge = document.createElement('span'); button.appendChild(badge); }
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = false;
    } else if (badge) {
      badge.hidden = true;
      badge.textContent = '0';
    }
    button.setAttribute('aria-label', count ? `Notifications, ${count} unread` : 'Notifications');
  }

  function ownNotificationButton() {
    const menu = document.getElementById('chatNotificationMenu');
    const oldButton = document.getElementById('chatNotificationButton');
    const actions = document.querySelector('.chat-topbar-actions');
    const profile = document.getElementById('chatProfileMenu');
    if (!menu || !oldButton || !actions || !profile) return false;
    const oldDropdown = document.getElementById('chatNotificationDropdown');
    if (oldDropdown) oldDropdown.remove();
    const replacement = oldButton.cloneNode(true);
    replacement.setAttribute('aria-controls', 'chatNotificationDrawer');
    replacement.setAttribute('aria-haspopup', 'dialog');
    replacement.setAttribute('aria-expanded', 'false');
    oldButton.replaceWith(replacement);
    button = replacement;
    if (menu.nextElementSibling !== profile) actions.insertBefore(menu, profile);
    button.addEventListener('click', openDrawer);
    return true;
  }

  function ensureDrawer() {
    if (drawer) return;
    backdrop = document.createElement('div');
    backdrop.className = 'chat-notification-drawer-backdrop';
    backdrop.hidden = true;
    drawer = document.createElement('aside');
    drawer.className = 'chat-notification-drawer';
    drawer.id = 'chatNotificationDrawer';
    drawer.hidden = true;
    drawer.setAttribute('aria-label', 'Notifications and Agent Brain');
    drawer.innerHTML = `
      <header class="chat-notification-drawer-head">
        <div><small>Stonefellow</small><strong>Activity Center</strong></div>
        <button type="button" data-notification-drawer-close aria-label="Close Activity Center">×</button>
      </header>
      <nav class="chat-notification-tabs" aria-label="Activity Center sections">
        <button type="button" data-notification-tab="notifications" class="active">Notifications <em data-notification-tab-count hidden></em></button>
        <button type="button" data-notification-tab="brain">Agent Brain</button>
        <button type="button" data-notification-tab="history">History</button>
      </nav>
      <div class="chat-notification-drawer-body" data-notification-drawer-body></div>`;
    document.body.append(backdrop, drawer);
    backdrop.addEventListener('click', closeDrawer);
    drawer.querySelector('[data-notification-drawer-close]')?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', handleDrawerClick);
  }

  function notificationView() {
    const block = state?.notifications || {};
    const items = Array.isArray(block.items) ? block.items : [];
    const unread = Number(block.unread || 0);
    return `
      <section class="chat-activity-section">
        <div class="chat-activity-section-head">
          <div><strong>Notifications</strong><span>${unread ? `${unread} unread` : 'You are caught up'}</span></div>
          ${unread ? '<button type="button" data-notification-mark-all>Mark all read</button>' : ''}
        </div>
        <div class="chat-notification-list">
          ${items.length ? items.map(item => `
            <article class="chat-notification-item${Number(item.is_read || 0) ? '' : ' unread'}" data-notification-id="${Number(item.id || 0)}">
              <span class="chat-notification-dot" aria-hidden="true"></span>
              <div><strong>${esc(item.title || 'Notification')}</strong>${item.body ? `<p>${esc(item.body)}</p>` : ''}<small>${esc(relative(item.created_at))}${item.type ? ` · ${esc(String(item.type).replaceAll('_',' '))}` : ''}</small></div>
              ${item.target_url ? `<a href="${esc(item.target_url)}" data-notification-open>Open</a>` : (!Number(item.is_read || 0) ? '<button type="button" data-notification-read>Read</button>' : '')}
            </article>`).join('') : '<div class="chat-activity-empty">No notifications yet.</div>'}
        </div>
      </section>`;
  }

  function brainMetric(label, value, detail = '') {
    return `<article class="chat-brain-metric"><strong>${esc(value)}</strong><span>${esc(label)}</span>${detail ? `<small>${esc(detail)}</small>` : ''}</article>`;
  }

  function brainView() {
    const brain = state?.brain || {};
    if (brain.enabled === false) return '<section class="chat-activity-section"><div class="chat-activity-empty">Personal Agent Brain is not enabled for this account type.</div></section>';
    const activity = brain.activity || {};
    const events = Array.isArray(brain.events) ? brain.events : [];
    const recent = Array.isArray(brain.recent) ? brain.recent : [];
    const themes = Array.isArray(brain.themes) ? brain.themes : [];
    const currentState = stateLabel(activity.state || 'idle');
    return `
      <section class="chat-activity-section chat-brain-overview">
        <div class="chat-brain-status ${esc(String(activity.state || 'idle'))}"><span></span><div><small>Current Agent State</small><strong>${esc(currentState)}</strong><p>${esc(activity.task_title || 'Agent Chat')}</p></div><em>${esc(activity.surface || 'chat')}</em></div>
        <div class="chat-brain-metrics">${brainMetric('Memories', Number(brain.memory_count || 0))}${brainMetric('Archived messages', Number(brain.archive_count || 0))}${brainMetric('Activity events', events.length)}</div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Live Brain Activity</strong><span>What the agent has been doing across Stonefellow</span></div></div>
        <div class="chat-brain-timeline">${events.length ? events.slice(0,30).map(event => `<article class="chat-brain-event"><span class="chat-brain-event-state ${esc(event.activity_state || 'idle')}"></span><div><strong>${esc(event.task_title || stateLabel(event.activity_state))}</strong><p>${esc(stateLabel(event.previous_state))} → ${esc(stateLabel(event.activity_state))}${event.reason ? ` · ${esc(String(event.reason).replaceAll('_',' '))}` : ''}</p><small>${esc(event.surface || 'chat')} · ${esc(relative(event.created_at))}</small></div></article>`).join('') : '<div class="chat-activity-empty">No Agent Brain activity history yet.</div>'}</div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Recent Memory</strong><span>Structured facts and decisions the Agent Brain is retaining</span></div></div>
        <div class="chat-brain-memory-list">${recent.length ? recent.map(memory => `<article><span>${esc(memory.memory_type || 'memory')}</span><strong>${esc(memory.subject || 'Memory')}</strong><p>${esc(memory.memory_text || '')}</p><small>${Number(memory.occurrence_count || 1)} occurrence${Number(memory.occurrence_count || 1) === 1 ? '' : 's'} · ${esc(relative(memory.last_seen_at))}</small></article>`).join('') : '<div class="chat-activity-empty">No structured memory yet.</div>'}</div>
        ${themes.length ? `<div class="chat-brain-themes"><strong>Recurring themes</strong><div>${themes.slice(0,10).map(theme => `<span>${esc(theme.subject || '')}<small>${Number(theme.occurrence_count || 0)}</small></span>`).join('')}</div></div>` : ''}
      </section>`;
  }

  function historyView() {
    const rows = Array.isArray(state?.history) ? state.history : [];
    let lastDay = '';
    return `<section class="chat-activity-section"><div class="chat-activity-section-head"><div><strong>Agent History</strong><span>Chronological conversation archive used by the Agent Brain</span></div></div><div class="chat-brain-history">${rows.length ? rows.map(row => {
      const date = new Date(String(row.created_at || '').replace(' ', 'T'));
      const day = Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
      const divider = day && day !== lastDay ? `<div class="chat-brain-history-day">${esc(day)}</div>` : '';
      lastDay = day || lastDay;
      return `${divider}<article class="${esc(row.role || 'user')}"><div><strong>${row.role === 'assistant' ? 'Agent' : 'You'}</strong><span>${esc(row.input_mode || 'text')}</span><small>${esc(relative(row.created_at))}</small></div><p>${esc(row.message || '')}</p><footer>Conversation ${Number(row.conversation_id || 0)}</footer></article>`;
    }).join('') : '<div class="chat-activity-empty">No Agent Brain conversation history yet.</div>'}</div></section>`;
  }

  function render() {
    if (!drawer) return;
    drawer.querySelectorAll('[data-notification-tab]').forEach(tab => tab.classList.toggle('active', tab.dataset.notificationTab === activeTab));
    const count = drawer.querySelector('[data-notification-tab-count]');
    const unread = Number(state?.notifications?.unread || 0);
    if (count) { count.hidden = unread < 1; count.textContent = unread > 99 ? '99+' : String(unread); }
    const body = drawer.querySelector('[data-notification-drawer-body]');
    if (!body) return;
    if (!state) { body.innerHTML = '<div class="chat-activity-loading">Loading Activity Center…</div>'; return; }
    body.innerHTML = activeTab === 'brain' ? brainView() : activeTab === 'history' ? historyView() : notificationView();
    updateUnread();
  }

  async function refresh(showError = false) {
    try {
      state = await request('state');
      if (Object.prototype.hasOwnProperty.call(state, 'agent_voice_enabled')) {
        agentVoicePreference = state.agent_voice_enabled !== false;
      }
      render();
    } catch (error) {
      if (showError && drawer) {
        const body = drawer.querySelector('[data-notification-drawer-body]');
        if (body) body.innerHTML = `<div class="chat-activity-empty error">${esc(error instanceof Error ? error.message : 'Activity Center unavailable.')}</div>`;
      }
    }
  }

  function openDrawer() {
    ensureDrawer(); drawer.hidden = false; backdrop.hidden = false;
    requestAnimationFrame(() => { drawer.classList.add('open'); backdrop.classList.add('open'); });
    button?.setAttribute('aria-expanded', 'true'); document.body.classList.add('chat-notification-drawer-open'); render(); void refresh(true);
  }
  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('open'); backdrop.classList.remove('open'); button?.setAttribute('aria-expanded', 'false'); document.body.classList.remove('chat-notification-drawer-open');
    window.setTimeout(() => { if (!drawer?.classList.contains('open')) { drawer.hidden = true; backdrop.hidden = true; } }, 180);
  }

  async function mutate(action, payload = {}) {
    if (busy) return; busy = true;
    try { state = await request(action, payload); render(); }
    catch (error) { const body = drawer?.querySelector('[data-notification-drawer-body]'); if (body) body.insertAdjacentHTML('afterbegin', `<div class="chat-activity-inline-error">${esc(error instanceof Error ? error.message : 'Could not update notification.')}</div>`); }
    finally { busy = false; }
  }

  function handleDrawerClick(event) {
    const tab = event.target.closest('[data-notification-tab]');
    if (tab) { activeTab = tab.dataset.notificationTab || 'notifications'; render(); return; }
    if (event.target.closest('[data-notification-mark-all]')) { void mutate('mark_all_read'); return; }
    const item = event.target.closest('[data-notification-id]'); if (!item) return;
    const id = Number(item.dataset.notificationId || 0); if (id < 1) return;
    if (event.target.closest('[data-notification-open]')) { if (item.classList.contains('unread')) void request('mark_read', {notification_id:id}).catch(() => {}); return; }
    if (event.target.closest('[data-notification-read]') || item.classList.contains('unread')) void mutate('mark_read', {notification_id:id});
  }

  function keepBellNextToProfile() {
    const menu = document.getElementById('chatNotificationMenu'); const profile = document.getElementById('chatProfileMenu'); const actions = document.querySelector('.chat-topbar-actions');
    if (!menu || !profile || !actions) return; if (menu.nextElementSibling !== profile) actions.insertBefore(menu, profile);
  }
  function continuity() { return window.STONEFELLOW_CHAT_CONTINUITY || window.STONEFELLOW_CHAT_CONTINUITY_V87 || {}; }
  function chatCanvasAvailable() { return typeof continuity().openConversation === 'function'; }
  function activeConversationId() { return Math.max(0, Number(continuity().conversationId?.() || 0)); }
  function activeAgentId() { return Math.max(0, Number(window.STONEFELLOW_AGENT_IDENTITY_V236?.agentId || 0)); }

  async function showAttentionConversation(conversationId, message) {
    const id = Math.max(0, Number(conversationId || 0)); const chat = continuity();
    if (id < 1 || typeof chat.openConversation !== 'function') return false;
    const opened = await chat.openConversation(id); if (!opened) return false;
    const expected = String(message || '').trim(); const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
      const texts = [...document.querySelectorAll('#chatThread .message.assistant .message-text')];
      if (texts.some(node => String(node.textContent || '').trim() === expected)) return true;
      if (typeof chat.syncConversation === 'function') await chat.syncConversation(id);
      await new Promise(resolve => setTimeout(resolve, 80));
    }
    return false;
  }

  function clearResponseWindow() { if (responseTimer) window.clearTimeout(responseTimer); responseTimer = 0; responseWindowActive = false; }
  function markUserResponse() { if (!responseWindowActive) return; clearResponseWindow(); responseTemporaryVoice = false; }
  function voiceButton() { return document.getElementById('chatVoiceButton'); }
  function voiceIsOn() { return Boolean(continuity().isVoice?.()); }
  function setVoiceMode(enabled) { const control = voiceButton(); if (!control || control.disabled) return false; const current = voiceIsOn(); if (current !== Boolean(enabled)) control.click(); return voiceIsOn(); }
  function agentVoiceEnabled() { return agentVoicePreference !== false; }

  function waitForAgentIdle(timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    return new Promise(resolve => { const tick = () => { const mode = String(document.body.dataset.stonefellowAgentState || 'idle'); if (!['processing','speaking'].includes(mode) || Date.now() >= deadline) { resolve(); return; } window.setTimeout(tick, 120); }; tick(); });
  }

  function browserSpeak(text) {
    return new Promise(resolve => {
      const message = String(text || '').trim();
      if (!message || !('speechSynthesis' in window) || !window.SpeechSynthesisUtterance) { resolve(false); return; }
      const utterance = new window.SpeechSynthesisUtterance(message); let done = false;
      const finish = ok => { if (done) return; done = true; resolve(ok); };
      utterance.onend = () => finish(true); utterance.onerror = () => finish(false);
      try { window.speechSynthesis.cancel(); window.speechSynthesis.speak(utterance); } catch (_error) { finish(false); }
    });
  }

  async function speakWithExistingVoice(text) {
    if (!agentVoiceEnabled()) return;
    const message = String(text || '').trim(); if (!message) return; await waitForAgentIdle();
    const wasVoice = voiceIsOn(); if (wasVoice) setVoiceMode(false);
    let spoken = false; const PremiumVoice = window.StonefellowPremiumVoiceV122;
    if (typeof PremiumVoice === 'function') {
      try {
        const premium = PremiumVoice({agentEndpoint:String(window.STONEFELLOW_CHAT?.endpoint || '/api/chat-v236.php'), csrf:String(cfg.csrf || '')});
        spoken = await new Promise(async resolve => {
          let settled = false; const finish = ok => { if (settled) return; settled = true; resolve(ok); };
          try { await premium.speak(message, {onEnd:() => finish(true), onError:() => finish(false)}); } catch (_error) { finish(false); }
        });
      } catch (_error) { spoken = false; }
    }
    if (!spoken) await browserSpeak(message);
    const listening = setVoiceMode(true); if (!listening) return;
    responseTemporaryVoice = !wasVoice; clearResponseWindow(); responseWindowActive = true;
    responseTimer = window.setTimeout(() => { responseTimer = 0; responseWindowActive = false; if (responseTemporaryVoice) { responseTemporaryVoice = false; setVoiceMode(false); } }, 10000);
  }

  function queueSpeech(text) { speechQueue = speechQueue.then(() => speakWithExistingVoice(text)).catch(() => {}); }

  async function presentAttention(item) {
    const notificationId = Number(item?.id || 0); if (notificationId < 1) return true;
    const data = await request('present_attention', {notification_id:notificationId, conversation_id:activeConversationId(), agent_id:activeAgentId()});
    if (!data.handled) return true;
    const surfaced = await showAttentionConversation(Number(data.conversation_id || 0), String(data.message || ''));
    if (!surfaced) throw new Error('Actionable notification could not be surfaced in Agent Chat.');
    queueSpeech(String(data.message || ''));
    state = await request('mark_read', {notification_id:notificationId}); render(); return true;
  }

  async function pollAttention(bootstrap = false) {
    if (!chatCanvasAvailable()) return;
    if (attentionBusy || (document.hidden && !bootstrap)) return;
    attentionBusy = true;
    try {
      const data = await request('attention', null, {after_id:bootstrap ? 0 : attentionCursor});
      const items = Array.isArray(data.items) ? data.items : [];
      const selected = bootstrap && items.length ? [items[items.length - 1]] : items;
      for (const item of selected) await presentAttention(item);
      attentionCursor = Math.max(attentionCursor, Number(data.latest_id || 0));
    } catch (_error) {
      // Keep the cursor unchanged so a failed canvas presentation is retried.
    } finally { attentionBusy = false; }
  }

  function startAttentionPolling() {
    if (!chatCanvasAvailable()) return;
    void pollAttention(true);
    if (attentionTimer) window.clearInterval(attentionTimer);
    attentionTimer = window.setInterval(() => pollAttention(false), 5000);
  }

  if (!ownNotificationButton()) return;
  ensureDrawer(); keepBellNextToProfile();
  const actions = document.querySelector('.chat-topbar-actions');
  if (actions) new MutationObserver(keepBellNextToProfile).observe(actions, {childList:true});
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && drawer?.classList.contains('open')) closeDrawer(); });
  document.getElementById('chatForm')?.addEventListener('submit', markUserResponse, true);
  window.addEventListener('stonefellow:chat-voice', event => { if (String(event.detail?.type || '') === 'TRANSCRIPT_SUBMIT') markUserResponse(); });
  window.addEventListener('stonefellow:agent-voice', event => { agentVoicePreference = event.detail?.enabled !== false; });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && chatCanvasAvailable()) void pollAttention(false); });
  window.addEventListener('pagehide', () => { if (attentionTimer) window.clearInterval(attentionTimer); attentionTimer = 0; clearResponseWindow(); }, {once:true});

  window.STONEFELLOW_NOTIFICATION_CENTER = {open:openDrawer, close:closeDrawer, refresh, pollAttention};
  void refresh(false).finally(startAttentionPolling);
})();