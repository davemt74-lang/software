(() => {
  'use strict';

  const sidebar = document.getElementById('chatSidebar');
  const menu = document.getElementById('openChatSidebar');
  const thread = document.getElementById('chatThread');
  const panel = sidebar?.querySelector('[data-chat-mobile-specialists]');
  const registry = window.VP3ChatSpecialists;
  if (!panel || !menu || !thread || !registry) return;

  const home = sidebar.querySelector('#chatMobileHomePanel');
  const tablist = sidebar.querySelector('.vp3-chat-mobile-tabs');
  const tabs = Array.from(tablist.querySelectorAll('[data-chat-mobile-tab]'));
  const list = panel.querySelector('[data-chat-specialist-mobile-list]');
  const empty = panel.querySelector('[data-chat-specialist-mobile-empty]');
  const mobile = matchMedia('(max-width: 820px)');
  const menuLabel = menu.getAttribute('aria-label') || 'Open menu';
  const drawerIsOpen = () => document.body.classList.contains('chat-nav-open');
  const rows = new Map();
  const notices = new Map();

  const dot = document.createElement('span');
  dot.dataset.chatSpecialistDot = '';
  dot.setAttribute('aria-hidden', 'true');
  dot.hidden = true;
  menu.append(dot);

  const noticeArea = document.createElement('div');
  noticeArea.className = 'vp3-chat-specialist-notices';

  function select(tab) {
    tabs.forEach(button => {
      const selected = button === tab;
      button.setAttribute('aria-selected', String(selected));
      button.tabIndex = selected ? 0 : -1;
    });
    const showSpecialists = mobile.matches && tab.dataset.chatMobileTab === 'specialists';
    home.hidden = showSpecialists;
    panel.hidden = !showSpecialists;
  }

  function createRow(entry, isNotice) {
    const row = document.createElement(isNotice ? 'article' : 'li');
    row.setAttribute(isNotice ? 'data-chat-specialist-notice' : 'data-chat-mobile-specialist', entry.key);
    const label = document.createElement('strong');
    const title = document.createElement('span');
    const status = document.createElement('small');
    const detail = isNotice ? null : document.createElement('small');
    if (isNotice) status.setAttribute('role', 'status');
    const action = document.createElement('button');
    action.type = 'button';
    action.addEventListener('click', () => {
      const wasOpen = drawerIsOpen();
      if (wasOpen) document.getElementById('closeChatSidebar')?.click();
      if (!registry.view(entry.key) && wasOpen) menu.focus();
    });
    row.append(label, title, status);
    if (detail) row.append(detail);
    row.append(action);
    return {row, label, title, status, detail, action};
  }

  function write(element, value) {
    if (element.textContent !== value) element.textContent = value;
  }

  function updateRows(container, cache, entries, isNotice = false) {
    const current = new Set(entries.map(entry => entry.key));
    for (const [key, item] of cache) {
      if (current.has(key)) continue;
      const focused = item.row.contains(document.activeElement);
      item.row.remove();
      cache.delete(key);
      if (focused) {
        const tab = tabs.find(button => button.tabIndex === 0);
        (drawerIsOpen() ? tab : document.getElementById('chatInput'))?.focus();
      }
    }
    for (const entry of entries) {
      let item = cache.get(entry.key);
      if (!item) {
        item = createRow(entry, isNotice);
        cache.set(entry.key, item);
        container.append(item.row);
      }
      item.row.dataset.state = entry.state;
      write(item.label, entry.label);
      write(item.title, entry.title);
      write(item.status, entry.status);
      write(item.action, entry.viewLabel);
      if (item.detail) {
        write(item.detail, entry.detail);
        item.detail.hidden = !entry.detail;
      }
      item.title.hidden = !entry.title;
      item.action.hidden = !entry.canView;
    }
  }

  function render() {
    const entries = mobile.matches ? registry.snapshot({includeResults:true}) : [];
    const working = entries.some(entry => entry.state === 'running');
    dot.hidden = !working || drawerIsOpen();
    menu.setAttribute('aria-expanded', String(mobile.matches && drawerIsOpen()));
    menu.setAttribute('aria-label', working ? menuLabel + ', specialists working' : menuLabel);

    updateRows(list, rows, entries);
    empty.hidden = entries.length > 0;
    const inline = thread.dataset.view === 'chat' ? entries.filter(entry => entry.inline) : [];
    const atEnd = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 36;
    const previousCount = noticeArea.isConnected ? notices.size : 0;
    updateRows(noticeArea, notices, inline, true);
    if (inline.length && !noticeArea.isConnected) thread.append(noticeArea);
    if (!inline.length) noticeArea.remove();
    if (atEnd && inline.length > previousCount) thread.scrollTop = thread.scrollHeight;
  }

  function resize() {
    tablist.hidden = !mobile.matches;
    if (mobile.matches) {
      home.setAttribute('role', 'tabpanel');
      home.setAttribute('aria-labelledby', tabs[0].id);
      home.tabIndex = 0;
    } else {
      home.removeAttribute('role');
      home.removeAttribute('aria-labelledby');
      home.removeAttribute('tabindex');
    }
    select(tabs[0]);
    render();
  }

  tablist.addEventListener('click', event => {
    const tab = event.target.closest('[data-chat-mobile-tab]');
    if (tab) select(tab);
  });
  tablist.addEventListener('keydown', event => {
    const index = tabs.indexOf(event.target);
    if (index < 0 || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
    event.preventDefault();
    const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) : tabs[1 - index];
    select(next);
    next.focus();
  });

  new MutationObserver(render).observe(document.body, {attributes:true, attributeFilter:['class']});
  new MutationObserver(render).observe(thread, {childList:true, attributes:true, attributeFilter:['data-view']});
  window.addEventListener('vp3:chat-specialists-changed', render);
  mobile.addEventListener('change', resize);
  sidebar.dataset.chatMobileSpecialistsReady = '';
  resize();
})();
