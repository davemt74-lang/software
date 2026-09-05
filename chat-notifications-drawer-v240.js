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

  async function request(action = 'state', payload = null) {
    const post = payload !== null;
    const url = post ? cfg.endpoint : `${cfg.endpoint}?action=${encodeURIComponent(action)}`;
    const options = post ? {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
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
      if (!badge) {
        badge = document.createElement('span');
        button.appendChild(badge);
      }
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

    if (menu.nextElementSibling !== profile) {
      actions.insertBefore(menu, profile);
    }
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
              <div>
                <strong>${esc(item.title || 'Notification')}</strong>
                ${item.body ? `<p>${esc(item.body)}</p>` : ''}
                <small>${esc(relative(item.created_at))}${item.type ? ` · ${esc(String(item.type).replaceAll('_',' '))}` : ''}</small>
              </div>
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
    const activity = brain.activity || {};
    const events = Array.isArray(brain.events) ? brain.events : [];
    const recent = Array.isArray(brain.recent) ? brain.recent : [];
    const themes = Array.isArray(brain.themes) ? brain.themes : [];
    const currentState = stateLabel(activity.state || 'idle');
    return `
      <section class="chat-activity-section chat-brain-overview">
        <div class="chat-brain-status ${esc(String(activity.state || 'idle'))}">
          <span></span>
          <div><small>Current Agent State</small><strong>${esc(currentState)}</strong><p>${esc(activity.task_title || 'Agent Chat')}</p></div>
          <em>${esc(activity.surface || 'chat')}</em>
        </div>
        <div class="chat-brain-metrics">
          ${brainMetric('Memories', Number(brain.memory_count || 0))}
          ${brainMetric('Archived messages', Number(brain.archive_count || 0))}
          ${brainMetric('Activity events', events.length)}
        </div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Live Brain Activity</strong><span>What the agent has been doing across Stonefellow</span></div></div>
        <div class="chat-brain-timeline">
          ${events.length ? events.slice(0,30).map(event => `
            <article class="chat-brain-event">
              <span class="chat-brain-event-state ${esc(event.activity_state || 'idle')}"></span>
              <div>
                <strong>${esc(event.task_title || stateLabel(event.activity_state))}</strong>
                <p>${esc(stateLabel(event.previous_state))} → ${esc(stateLabel(event.activity_state))}${event.reason ? ` · ${esc(String(event.reason).replaceAll('_',' '))}` : ''}</p>
                <small>${esc(event.surface || 'chat')} · ${esc(relative(event.created_at))}</small>
              </div>
            </article>`).join('') : '<div class="chat-activity-empty">No Agent Brain activity history yet.</div>'}
        </div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Recent Memory</strong><span>Structured facts and decisions the Agent Brain is retaining</span></div></div>
        <div class="chat-brain-memory-list">
          ${recent.length ? recent.map(memory => `
            <article><span>${esc(memory.memory_type || 'memory')}</span><strong>${esc(memory.subject || 'Memory')}</strong><p>${esc(memory.memory_text || '')}</p><small>${Number(memory.occurrence_count || 1)} occurrence${Number(memory.occurrence_count || 1) === 1 ? '' : 's'} · ${esc(relative(memory.last_seen_at))}</small></article>`).join('') : '<div class="chat-activity-empty">No structured memory yet.</div>'}
        </div>
        ${themes.length ? `<div class="chat-brain-themes"><strong>Recurring themes</strong><div>${themes.slice(0,10).map(theme => `<span>${esc(theme.subject || '')}<small>${Number(theme.occurrence_count || 0)}</small></span>`).join('')}</div></div>` : ''}
      </section>`;
  }

  function historyView() {
    const rows = Array.isArray(state?.history) ? state.history : [];
    let lastDay = '';
    return `
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Agent History</strong><span>Chronological conversation archive used by the Agent Brain</span></div></div>
        <div class="chat-brain-history">
          ${rows.length ? rows.map(row => {
            const date = new Date(String(row.created_at || '').replace(' ', 'T'));
            const day = Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
            const divider = day && day !== lastDay ? `<div class="chat-brain-history-day">${esc(day)}</div>` : '';
            lastDay = day || lastDay;
            return `${divider}<article class="${esc(row.role || 'user')}">
              <div><strong>${row.role === 'assistant' ? 'Agent' : 'You'}</strong><span>${esc(row.input_mode || 'text')}</span><small>${esc(relative(row.created_at))}</small></div>
              <p>${esc(row.message || '')}</p>
              <footer>Conversation ${Number(row.conversation_id || 0)}</footer>
            </article>`;
          }).join('') : '<div class="chat-activity-empty">No Agent Brain conversation history yet.</div>'}
        </div>
      </section>`;
  }

  function render() {
    if (!drawer) return;
    drawer.querySelectorAll('[data-notification-tab]').forEach(tab => {
      tab.classList.toggle('active', tab.dataset.notificationTab === activeTab);
    });
    const count = drawer.querySelector('[data-notification-tab-count]');
    const unread = Number(state?.notifications?.unread || 0);
    if (count) {
      count.hidden = unread < 1;
      count.textContent = unread > 99 ? '99+' : String(unread);
    }
    const body = drawer.querySelector('[data-notification-drawer-body]');
    if (!body) return;
    if (!state) {
      body.innerHTML = '<div class="chat-activity-loading">Loading Activity Center…</div>';
      return;
    }
    body.innerHTML = activeTab === 'brain' ? brainView() : activeTab === 'history' ? historyView() : notificationView();
    updateUnread();
  }

  async function refresh(showError = false) {
    try {
      state = await request('state');
      render();
    } catch (error) {
      if (showError && drawer) {
        const body = drawer.querySelector('[data-notification-drawer-body]');
        if (body) body.innerHTML = `<div class="chat-activity-empty error">${esc(error instanceof Error ? error.message : 'Activity Center unavailable.')}</div>`;
      }
    }
  }

  function openDrawer() {
    ensureDrawer();
    drawer.hidden = false;
    backdrop.hidden = false;
    requestAnimationFrame(() => {
      drawer.classList.add('open');
      backdrop.classList.add('open');
    });
    button?.setAttribute('aria-expanded', 'true');
    document.body.classList.add('chat-notification-drawer-open');
    render();
    void refresh(true);
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('open');
    backdrop.classList.remove('open');
    button?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('chat-notification-drawer-open');
    window.setTimeout(() => {
      if (!drawer?.classList.contains('open')) {
        drawer.hidden = true;
        backdrop.hidden = true;
      }
    }, 180);
  }

  async function mutate(action, payload = {}) {
    if (busy) return;
    busy = true;
    try {
      state = await request(action, payload);
      render();
    } catch (error) {
      const body = drawer?.querySelector('[data-notification-drawer-body]');
      if (body) body.insertAdjacentHTML('afterbegin', `<div class="chat-activity-inline-error">${esc(error instanceof Error ? error.message : 'Could not update notification.')}</div>`);
    } finally {
      busy = false;
    }
  }

  function handleDrawerClick(event) {
    const tab = event.target.closest('[data-notification-tab]');
    if (tab) {
      activeTab = tab.dataset.notificationTab || 'notifications';
      render();
      return;
    }
    if (event.target.closest('[data-notification-mark-all]')) {
      void mutate('mark_all_read');
      return;
    }
    const item = event.target.closest('[data-notification-id]');
    if (!item) return;
    const id = Number(item.dataset.notificationId || 0);
    if (id < 1) return;
    if (event.target.closest('[data-notification-open]')) {
      if (item.classList.contains('unread')) void request('mark_read', {notification_id:id}).catch(() => {});
      return;
    }
    if (event.target.closest('[data-notification-read]') || item.classList.contains('unread')) {
      void mutate('mark_read', {notification_id:id});
    }
  }

  function keepBellNextToProfile() {
    const menu = document.getElementById('chatNotificationMenu');
    const profile = document.getElementById('chatProfileMenu');
    const actions = document.querySelector('.chat-topbar-actions');
    if (!menu || !profile || !actions) return;
    if (menu.nextElementSibling !== profile) actions.insertBefore(menu, profile);
  }

  if (!ownNotificationButton()) return;
  ensureDrawer();
  keepBellNextToProfile();
  const actions = document.querySelector('.chat-topbar-actions');
  if (actions) new MutationObserver(keepBellNextToProfile).observe(actions, {childList:true});
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && drawer?.classList.contains('open')) closeDrawer();
  });
  window.STONEFELLOW_NOTIFICATION_CENTER = {open:openDrawer, close:closeDrawer, refresh};
  void refresh(false);
})();
