(() => {
  'use strict';
  if (window.VP3ChatSpecialists) return;

  const entries = new Map();
  const compact = window.matchMedia('(max-width: 760px)');
  const drawer = window.matchMedia('(max-width: 820px)');
  const activeStates = new Set(['queued','running','paused']);
  const workingStates = new Set(['running','thinking','working']);
  const assignmentStates = new Set([...activeStates,'ready','completed','failed','stopped']);
  const presenceStates = new Set([...assignmentStates,'thinking','working','waiting_specialist','waiting_owner','waiting_reply',
    'ready_to_report','checked','idle','blocked','unavailable','armed','awaiting_consent']);
  const icons = {
    'image-generator':'M4 4h16v16H4z M4 16l5-5 4 4 2-2 5 5 M15 8h.01',
    'email':'M3 6h18v12H3z M3 7l9 6 9-6',
    'gmail':'M3 6h18v12H3z M3 7l9 6 9-6',
    location:'M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0z M14.5 10a2.5 2.5 0 1 1-5 0 2.5 2.5 0 1 1 5 0z',
    'gmail-inbox-review':'M3 6h18v12H3z M3 7l9 6 9-6',
    'development':'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16',
    'coding':'M8 7l-5 5 5 5 M16 7l5 5-5 5 M14 4l-4 16',
    'research':'M10 3a7 7 0 1 0 0 14 7 7 0 1 0 0-14 M15 15l5 5',
    'planning':'M4 6h16v14H4z M4 10h16 M8 4v4 M16 4v4 M8 14h3 M8 17h6',
    'tasks':'M4 6l2 2 3-4 M12 6h8 M4 13l2 2 3-4 M12 13h8 M4 20h4 M12 20h8',
    'profile':'M8 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M2 20v-2a6 6 0 0 1 12 0v2 M15 5h7v8h-3l-3 3v-3h-1',
    'media':'M3 14v-2a9 9 0 0 1 18 0v2 M3 14a2 2 0 0 1 2-2h2v8H5a2 2 0 0 1-2-2z M21 14a2 2 0 0 0-2-2h-2v8h2a2 2 0 0 0 2-2z',
    'transcripts':'M6 3h8l4 4v14H6z M14 3v5h4 M9 12h6 M9 16h6',
    'brainstorm':'M9 18h6 M10 21h4 M8 14a6 6 0 1 1 8 0l-1 2H9z',
    'curiosity':'M12 2.8a9.2 9.2 0 1 0 0 18.4 9.2 9.2 0 0 0 0-18.4z M15.8 8.2l-2.1 5.5-5.5 2.1 2.1-5.5z',
    'connected-sites':'M10 13.5l4-4 M8 15.5l-1 1a3.5 3.5 0 0 1-5-5l4.5-4.5a3.5 3.5 0 0 1 5 0 M16 8.5l1-1a3.5 3.5 0 0 1 5 5l-4.5 4.5a3.5 3.5 0 0 1-5 0',
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
  host.setAttribute('aria-label','Specialists');

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
    let hasDesktopWork = false;
    for (const entry of entries.values()) {
      const shown = entry.desktop && visible(entry,other => other.desktop);
      if (entry.button.hidden === shown) entry.button.hidden = !shown;
      hasDesktopWork ||= shown;
    }
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

  function fresh(entry) {
    return !entry.expiresAt || entry.expiresAt > Date.now();
  }

  function expire(entry) {
    if (!entry.keepLauncher) { remove(entry.key); return; }
    update(entry.key, {
      label:entry.label,fallbackFor:entry.fallbackFor,state:'unavailable',status:'Status unavailable',
      presence:true,inline:false,keepLauncher:true,viewLabel:entry.viewLabel,onView:entry.onView
    });
  }

  function visible(entry, eligible) {
    return fresh(entry) && !entry.fallbackFor.some(type => [...entries.values()].some(other => other !== entry &&
      other.key.split(':',1)[0] === type && fresh(other) && eligible(other)));
  }

  const observer = new MutationObserver(dock);

  function setButtonPanel(entry, expanded = false) {
    const button = entry.button;
    if (!expanded && button.getAttribute('aria-controls') === 'vp3ChatSpecialistActivity'
        && button.getAttribute('aria-expanded') === 'true') return;
    button.setAttribute('aria-controls',!expanded && window.VP3AgentActivityHub?.openSpecialist
      ? 'vp3ChatSpecialistActivity' : entry.panelId);
    button.setAttribute('aria-expanded',String(expanded));
    button.setAttribute('aria-haspopup','dialog');
  }

  function closePanel(restoreFocus) {
    if (!opened) return;
    const {entry,panel,trigger,focusReturn} = opened;
    opened = null;
    setButtonPanel(entry);
    if (trigger !== entry.button) trigger?.setAttribute('aria-expanded','false');
    panel.replaceChildren();
    panel.remove();
    const focus = focusReturn || trigger;
    if (restoreFocus) (focus?.isConnected ? focus : document.getElementById('chatInput'))?.focus();
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

  function openPanel(entry, trigger, focusReturn) {
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
    opened = {entry,panel,heading,title,status,detail,view,trigger,focusReturn};
    renderPanel();
    document.body.append(panel);
    setButtonPanel(entry,true);
    if (trigger !== entry.button) {
      trigger?.setAttribute('aria-expanded','true');
      trigger?.setAttribute('aria-controls',panel.id);
    }
    heading.focus();
  }

  function open(key, trigger, focusReturn) {
    if (suspended || privateContext()) return false;
    const entry = entries.get(key);
    if (!entry) return false;
    if (!fresh(entry)) expire(entry);
    if (!entries.has(key)) return false;
    if (opened?.entry === entry) closePanel(true);
    else openPanel(entry,trigger || entry.button,focusReturn);
    return true;
  }

  function openActivity(key, trigger, focusReturn) {
    if (suspended || privateContext()) return false;
    const entry = entries.get(key);
    if (!entry) return false;
    if (!fresh(entry)) expire(entry);
    if (!entries.has(key)) return false;
    const launcher = trigger || entry.button;
    const hub = window.VP3AgentActivityHub;
    if (hub?.openSpecialist) {
      closePanel(false);
      if (hub.openSpecialist(key,launcher,{label:entry.label,focusReturn})) return true;
    }
    return open(key,launcher,focusReturn);
  }

  // Producers publish verified work. Persistent specialists also show their observed idle or waiting status.
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
      button.append(makeIcon(type));
      entry = {key,button,panelId};
      button.addEventListener('click', () => openActivity(key,button));
      if (!entries.size) observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
      entries.set(key,entry);
      host.append(button);
    }
    entry.presence = options.presence === true;
    entry.keepLauncher = entry.presence && options.keepLauncher === true;
    entry.fallbackFor = entry.presence && Array.isArray(options.fallbackFor) ? options.fallbackFor : [];
    entry.state = (entry.presence ? presenceStates : assignmentStates).has(options.state) ? options.state : 'running';
    entry.progress = Number.isFinite(options.progress) && options.progress >= 0 && options.progress <= 100 ? options.progress : null;
    const desktopResult = entry.state === 'completed' && options.desktopResult === true && expiresAt > Date.now()
      && typeof options.onView === 'function';
    entry.desktop = options.desktop !== false && (entry.presence || activeStates.has(entry.state) || desktopResult);
    entry.inline = options.inline !== false;
    entry.button.dataset.chatSpecialistState = entry.state;
    entry.button.toggleAttribute('data-chat-specialist-presence',entry.presence);
    if (opened?.entry === entry && !entry.presence && !activeStates.has(entry.state) && !desktopResult) closePanel(true);
    entry.label = String(options.label || 'Specialist');
    entry.status = String(options.status || 'Working');
    entry.title = String(options.title || '');
    entry.detail = String(options.detail || '');
    entry.viewLabel = String(options.viewLabel || 'View in Chat');
    entry.onView = typeof options.onView === 'function' ? options.onView : null;
    entry.button.title = entry.label + ' · ' + entry.status;
    entry.button.setAttribute('aria-label',entry.presence ? entry.button.title : entry.label);
    setButtonPanel(entry,opened?.entry === entry);
    if (entry.expiresAt !== expiresAt) {
      clearTimeout(entry.expiryTimer);
      entry.expiresAt = expiresAt;
      if (expiresAt) entry.expiryTimer = setTimeout(() => expire(entry), Math.max(0,expiresAt-Date.now()));
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
    const included = entry => includeResults || (entry.presence ? workingStates.has(entry.state) : activeStates.has(entry.state));
    return [...entries.values()].filter(entry => included(entry) && visible(entry,included)).map(entry => ({
      key:entry.key,label:entry.label,status:entry.status,title:entry.title,
      detail:entry.detail,viewLabel:entry.viewLabel,canView:Boolean(entry.onView),
      state:entry.state,inline:entry.inline,
      ...(entry.presence ? {presence:true,progress:entry.progress,expiresAt:entry.expiresAt} : {})
    }));
  }

  function view(key) {
    if (suspended || privateContext()) return false;
    const entry = entries.get(key);
    if (!entry?.onView || entry.expiresAt && entry.expiresAt <= Date.now()) return false;
    closePanel(false);
    const accepted = entry.onView();
    return !entry.presence || accepted !== false;
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
  window.VP3ChatSpecialists = Object.freeze({update,remove,snapshot,view,open,openActivity});
})();
