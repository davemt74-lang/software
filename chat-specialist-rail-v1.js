(() => {
  'use strict';
  if (window.VP3ChatSpecialists) return;

  const entries = new Map();
  const compact = window.matchMedia('(max-width: 760px)');
  const drawer = window.matchMedia('(max-width: 820px)');
  const activeStates = new Set(['queued','running','paused']);
  const assignmentStates = new Set([...activeStates,'ready','completed','failed','stopped']);
  const icons = {
    'image-generator':'M4 4h16v16H4z M4 16l5-5 4 4 2-2 5 5 M15 8h.01',
    'email':'M3 6h18v12H3z M3 7l9 6 9-6',
    'gmail-inbox-review':'M3 6h18v12H3z M3 7l9 6 9-6',
    'development':'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16',
    'coding':'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16',
    'research':'M10 3a7 7 0 1 0 0 14 7 7 0 1 0 0-14 M15 15l5 5',
    'brainstorm':'M9 18h6 M10 21h4 M8 14a6 6 0 1 1 8 0l-1 2H9z',
    'mission':'M4 6h16v14H4z M8 6V3h8v3M4 12h16M10 12v3h4v-3',
    generic:'M12 3v3 M12 18v3 M3 12h3 M18 12h3 M5.6 5.6l2.1 2.1 M16.3 16.3l2.1 2.1 M5.6 18.4l2.1-2.1 M16.3 7.7l2.1-2.1'
  };
  let opened = null;
  let suspended = false;
  let participantActor = 0;
  const privateContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  const changed = () => window.dispatchEvent(new Event('vp3:chat-specialists-changed'));

  function node(tag, text = '') {
    const element = document.createElement(tag);
    if (text) element.textContent = text;
    return element;
  }

  function makeButton(text = '') {
    const button = node('button', text);
    button.type = 'button';
    return button;
  }

  const host = node('div');
  host.className = 'vp3-chat-specialist-rail';
  host.setAttribute('role','group');
  host.setAttribute('aria-label','Active specialists');

  function makeIcon(key) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns,'svg');
    svg.setAttribute('viewBox','0 0 24 24');
    svg.setAttribute('aria-hidden','true');
    svg.setAttribute('focusable','false');
    const path = document.createElementNS(ns,'path');
    path.setAttribute('d',Object.hasOwn(icons,key) ? icons[key] : icons.generic);
    svg.append(path);
    return svg;
  }

  function dock() {
    const mobileDrawer = drawer.matches && document.querySelector('[data-chat-mobile-specialists]');
    const hasDesktopWork = [...entries.values()].some(entry => entry.desktop);
    const rail = compact.matches || mobileDrawer || !hasDesktopWork ? null : document.getElementById('sfOnlineRailV109');
    if (!rail || rail.hidden) {
      const keepPanel = opened && (compact.matches || !host.contains(opened.trigger));
      const focused = host.contains(document.activeElement) || (!keepPanel && opened?.panel.contains(document.activeElement));
      if (!keepPanel) closePanel(false);
      host.remove();
      if (focused) document.getElementById('chatInput')?.focus();
      return false;
    }
    const settings = rail.querySelector(':scope > #sfChatSettingsRailSlotV132');
    if (host.parentNode !== rail || (settings && host.nextSibling !== settings)) {
      const focused = host.contains(document.activeElement) ? document.activeElement : null;
      rail.insertBefore(host, settings);
      focused?.focus();
    }
    return true;
  }

  const observer = new MutationObserver(dock);

  function closePanel(restoreFocus) {
    if (!opened) return;
    const {entry,panel,trigger} = opened;
    opened = null;
    entry.button.setAttribute('aria-expanded','false');
    trigger?.setAttribute('aria-expanded','false');
    panel.replaceChildren();
    panel.remove();
    if (restoreFocus) (trigger?.isConnected ? trigger : document.getElementById('chatInput'))?.focus();
  }

  function renderPanel() {
    const {entry,heading,title,status,detail,view} = opened;
    heading.textContent = entry.label;
    title.textContent = entry.title;
    title.hidden = !entry.title;
    if (status.textContent !== entry.status) status.textContent = entry.status;
    detail.textContent = entry.detail;
    detail.hidden = !entry.detail;
    view.textContent = entry.viewLabel;
    view.hidden = !entry.onView;
  }

  function openPanel(entry, trigger) {
    closePanel(false);
    const panel = node('section');
    panel.className = 'vp3-chat-specialist-panel';
    panel.dataset.chatSpecialistPanel = entry.key;
    panel.setAttribute('role','dialog');
    panel.id = entry.panelId;
    panel.setAttribute('aria-labelledby', entry.panelId + '-heading');

    const heading = node('h2');
    heading.id = entry.panelId + '-heading';
    heading.tabIndex = -1;
    const close = makeButton('Close');
    close.addEventListener('click', () => closePanel(true));
    const header = node('header');
    header.append(heading,close);
    const title = node('p');
    title.className = 'vp3-chat-specialist-title';
    const status = node('p');
    status.dataset.chatSpecialistStatus = '';
    status.setAttribute('role','status');
    const detail = node('p');
    const view = makeButton('View in Chat');
    view.addEventListener('click', () => {
      const onView = entry.onView;
      closePanel(false);
      if (onView) onView();
    });
    panel.append(header,title,status,detail,view);
    opened = {entry,panel,heading,title,status,detail,view,trigger};
    renderPanel();
    document.body.append(panel);
    entry.button.setAttribute('aria-expanded','true');
    trigger?.setAttribute('aria-expanded','true');
    heading.focus();
  }

  function open(key, trigger) {
    if (suspended || privateContext()) return false;
    const entry = entries.get(key);
    if (!entry) return false;
    if (entry.expiresAt && entry.expiresAt <= Date.now()) {
      remove(key);
      return false;
    }
    if (opened?.entry === entry) closePanel(true);
    else openPanel(entry,trigger || entry.button);
    return true;
  }

  // Producers publish only verified active work and remove it on completion or scope changes.
  // A supplied lease or freshness deadline also retires the indicator and panel automatically.
  function update(key, options = {}) {
    if (suspended || privateContext() || typeof key !== 'string' || !/^[a-z][a-z0-9-]{0,47}(?::[a-z0-9][a-z0-9-]{0,63})?$/.test(key) || !document.body) return;
    const expiresAt = Number.isSafeInteger(options.expiresAt) ? options.expiresAt : 0;
    if (expiresAt && expiresAt <= Date.now()) { remove(key); return; }
    const type = Object.hasOwn(icons,options.type) ? options.type : key.split(':',1)[0];
    let entry = entries.get(key);
    if (!entry) {
      const button = makeButton();
      const panelId = 'vp3-chat-specialist-panel-' + key;
      button.dataset.chatSpecialist = key;
      button.dataset.chatSpecialistType = type;
      button.className = 'vp3-chat-worker-indicator';
      button.setAttribute('aria-controls',panelId);
      button.setAttribute('aria-expanded','false');
      button.setAttribute('aria-haspopup','dialog');
      button.append(makeIcon(type));
      entry = {key,button,panelId};
      button.addEventListener('click', () => open(key,button));
      if (!entries.size) observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
      entries.set(key,entry);
      host.append(button);
    }
    entry.state = assignmentStates.has(options.state) ? options.state : 'running';
    entry.desktop = options.desktop !== false && activeStates.has(entry.state);
    entry.inline = options.inline !== false;
    entry.button.hidden = !entry.desktop;
    if (opened?.entry === entry && !activeStates.has(entry.state)) closePanel(true);
    entry.label = String(options.label || 'Specialist');
    entry.status = String(options.status || 'Working');
    entry.title = String(options.title || '');
    entry.detail = String(options.detail || '');
    entry.viewLabel = String(options.viewLabel || 'View in Chat');
    entry.onView = typeof options.onView === 'function' ? options.onView : null;
    entry.button.setAttribute('aria-label',entry.label);
    entry.button.title = entry.label + ' · ' + entry.status;
    if (entry.expiresAt !== expiresAt) {
      clearTimeout(entry.expiryTimer);
      entry.expiresAt = expiresAt;
      if (expiresAt) entry.expiryTimer = setTimeout(() => remove(key), Math.max(0,expiresAt-Date.now()));
    }
    changed();
    dock();
    if (opened?.entry === entry) renderPanel();
  }

  function remove(key) {
    const entry = entries.get(key);
    if (!entry) return;
    clearTimeout(entry.expiryTimer);
    const hadFocus = document.activeElement === entry.button ||
      (opened?.entry === entry && opened.panel.contains(document.activeElement));
    if (opened?.entry === entry) closePanel(false);
    entry.button.remove();
    entry.title = '';
    entry.detail = '';
    entry.onView = null;
    entries.delete(key);
    changed();
    if (!entries.size) {
      observer.disconnect();
      host.remove();
    } else dock();
    if (hadFocus) ((host.isConnected && host.querySelector('button')) || document.getElementById('chatInput'))?.focus();
  }

  compact.addEventListener('change',dock);
  drawer.addEventListener('change',dock);
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || event.defaultPrevented || !opened) return;
    const focusInside = opened.panel.contains(document.activeElement);
    closePanel(focusInside);
    if (focusInside) event.preventDefault();
  });
  window.addEventListener('pagehide', () => {
    suspended = true;
    for (const key of entries.keys()) remove(key);
  });
  window.addEventListener('pageshow', () => { suspended = false; });

  function snapshot({includeResults = false} = {}) {
    if (suspended || privateContext()) return [];
    return [...entries.values()].filter(entry => includeResults || activeStates.has(entry.state)).map(entry => ({
      key:entry.key,label:entry.label,status:entry.status,title:entry.title,
      detail:entry.detail,viewLabel:entry.viewLabel,canView:Boolean(entry.onView),
      state:entry.state,inline:entry.inline
    }));
  }

  function view(key) {
    if (suspended || privateContext()) return false;
    const entry = entries.get(key);
    if (!entry?.onView) return false;
    closePanel(false);
    entry.onView();
    return true;
  }

  function participantChanged() {
    if (!privateContext()) return;
    for (const key of entries.keys()) remove(key);
  }
  window.addEventListener('vp3:participant-selection-changed',participantChanged);
  window.addEventListener('vp3:participant-task-context',event => {
    participantActor = Number(event.detail?.participant_id) || 0;
    participantChanged();
  });
  window.VP3ChatSpecialists = Object.freeze({update,remove,snapshot,view,open});
})();
