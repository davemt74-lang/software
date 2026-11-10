(() => {
  'use strict';
  if (window.VP3AgentActivityHub) return;
  const cfg = window.VP3_ORB_CONFIG;
  if (!cfg?.activityEndpoint) return;
  const initialOwner = Number(window.STONEFELLOW_CHAT?.userId || cfg.ownerKey);
  const initialBinding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || cfg.workingAttentionBinding || '');
  const groups = [
    {title:'Needs attention',ids:['reminders','tasks','lists','calendar']},
    {title:'Continue',ids:['history','youtube','songs']},
    {title:'Agent work',ids:['missions','canvases','suggestions','gmail_outcomes']}
  ];
  const kinds = new Set(['url','chat','task_notes','mission','canvas','youtube','song']);
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  let host = null;
  let root = null;
  let panel = null;
  let status = null;
  let verified = null;
  let contextLabel = null;
  const groupOpen = new Map();
  const detailOpen = new Map();
  let sections = null;
  let specialistsHost = null;
  let listening = null;
  let opened = false;
  let generation = 0;
  let controller = null;
  let timer = 0;
  let snapshot = null;
  let participantActor = 0;
  let returnFocus = null;
  let selectedSpecialist = '';
  let disposed = false;

  const participant = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  const localUrl = value => {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '';
    try { const url = new URL(value,location.origin); return url.origin === location.origin ? url.pathname + url.search + url.hash : ''; }
    catch { return ''; }
  };
  const positive = value => Number.isSafeInteger(value) && value > 0;
  function scope() {
    const chat = window.STONEFELLOW_CHAT;
    const context = window.VP3AgentOrb?.activityContext?.();
    const owner = Number(chat?.userId || cfg.ownerKey);
    const agent = Number(chat?.agentId ?? context?.agent ?? cfg.agentId ?? 0);
    const cid = Number(chat ? continuity()?.conversationId?.() ?? 0 : context?.id ?? 0);
    const binding = String(chat?.workingAttentionBinding || cfg.workingAttentionBinding || '');
    if (disposed || participant() || owner !== initialOwner || binding !== initialBinding || !owner || !binding ||
      !Number.isSafeInteger(agent) || agent < 0 || !Number.isSafeInteger(cid) || cid < 0 ||
      chat && (continuity()?.agentId?.() !== agent || continuity()?.isReady?.() !== true || continuity()?.isRestoring?.())) return null;
    return {owner,agent,cid,binding};
  }
  const signature = value => value ? JSON.stringify([value.owner,value.agent,value.cid,value.binding]) : '';
  const visible = () => opened && !document.hidden && host?.isConnected && !panel?.hidden;
  const node = (tag,text = '',className = '') => {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const button = (label,handler) => {
    const element = node('button',label);
    element.type = 'button';
    element.addEventListener('click',handler);
    return element;
  };
  function itemDetail(detail,key,title) {
    if (detail.length <= 80 && !detail.includes('\n')) return node('small',detail);
    const more = node('details','','vp3-agent-activity-detail');
    const summary = node('summary',detail.slice(0,64).trimEnd() + '…');
    summary.title = 'Show full details';
    summary.setAttribute('aria-label','Details for ' + title);
    more.open = detailOpen.get(key) === true;
    more.addEventListener('toggle',() => detailOpen.set(key,more.open));
    more.append(summary,node('p',detail));
    return more;
  }
  function itemCopy(title,detail,key) {
    const copy = node('div','','vp3-agent-activity-copy');
    const label = node('strong',title);
    label.title = title;
    copy.append(label);
    if (detail) copy.append(itemDetail(detail,key,title));
    return copy;
  }
  function stop() {
    generation++;
    clearTimeout(timer);
    timer = 0;
    controller?.abort();
    controller = null;
  }
  function clear() {
    stop();
    snapshot = null;
    detailOpen.clear();
    sections?.replaceChildren();
    specialistsHost?.replaceChildren();
    if (status) status.textContent = '';
    if (verified) verified.textContent = '';
    if (contextLabel) contextLabel.textContent = '';
  }
  function close(restore = true) {
    opened = false;
    clear();
    if (root) {
      delete root.dataset.activityOpen;
      root.dataset.initiativeDismissed = 'true';
      root.querySelector('[data-orb-activity]')?.setAttribute('aria-expanded','false');
    }
    if (host) host.hidden = true;
    const brief = document.getElementById('chatAgentBriefButton');
    brief?.setAttribute('aria-expanded','false');
    brief?.setAttribute('aria-controls','chatAgentBriefPopover');
    if (restore) returnFocus?.focus?.();
    window.dispatchEvent(new CustomEvent('vp3:agent-activity-view',{detail:{open:false}}));
  }
  function mount() {
    const next = window.VP3AgentOrb?.activityHost?.();
    if (!next || next === host) return Boolean(host);
    host = next;
    panel = host.closest('[data-agent-initiative-status]');
    root = host.closest('.vp3-orb-root');
    const heading = node('h2','Top of Mind');
    heading.id = 'vp3AgentActivityTitle';
    heading.tabIndex = -1;
    const header = node('header','','vp3-agent-activity-header');
    const details = button('Details',() => {
      close(false);
      if (window.VP3_COGNITIVE_PRESENTATION?.openDetails) window.VP3_COGNITIVE_PRESENTATION.openDetails();
      else if (!window.VP3AgentOrb?.showActivityDetails?.()) window.STONEFELLOW_NOTIFICATION_CENTER?.openBrain?.();
    });
    contextLabel = node('p','','vp3-agent-activity-context');
    status = node('p','','vp3-agent-activity-status');
    status.setAttribute('role','status');
    const refresh = button('Refresh',() => { stop(); void read(); });
    refresh.className = 'vp3-agent-activity-refresh';
    header.append(heading,refresh,details,button('Close',() => close()));
    specialistsHost = node('section','','vp3-agent-activity-specialists');
    sections = node('div','','vp3-agent-activity-sections');
    listening = node('section','','vp3-agent-activity-listening');
    listening.dataset.agentActivityListening = '';
    listening.setAttribute('aria-label','Resume playing');
    verified = node('small');
    verified.dataset.agentActivityVerified = '';
    panel.querySelector('[data-agent-worker-details]')?.append(verified);
    host.setAttribute('aria-labelledby',heading.id);
    host.replaceChildren(header,contextLabel,status,listening,specialistsHost,sections);
    host.hidden = true;
    window.dispatchEvent(new CustomEvent('vp3:agent-activity-listening-slot',{detail:{host:listening}}));
    window.dispatchEvent(new Event('vp3:agent-activity-ready'));
    return true;
  }
  function specialistSnapshot() {
    const entries = window.VP3AgentOrb?.activitySpecialists?.() || window.VP3ChatSpecialists?.snapshot?.() || [];
    return Array.isArray(entries) ? entries.slice(0,12).filter(item => typeof item?.key === 'string' &&
      /^[a-z][a-z0-9-]{0,63}$/.test(item.key) && ['label','status','title','detail','viewLabel'].every(key =>
        item[key] === undefined || typeof item[key] === 'string' && item[key].length <= 1200)) : [];
  }
  function renderSpecialists() {
    const current = scope();
    if (!specialistsHost || !opened || !current) return;
    const entries = specialistSnapshot();
    // The live rail supplies the same Gmail review in Chat. Elsewhere the
    // owner-scoped activity read provides a link, with no Gmail headers.
    const inbox = !entries.some(item => item.key === 'gmail-inbox-review')
      && snapshot?.sections.find(section => section.id === 'gmail_inbox')?.items[0];
    specialistsHost.replaceChildren();
    if (!entries.length && !inbox) return;
    specialistsHost.append(node('h3','Specialists'));
    for (const item of entries) {
      const article = node('article','','vp3-agent-activity-item');
      article.dataset.activitySpecialist = item.key;
      article.classList.toggle('selected',item.key === selectedSpecialist);
      const copy = itemCopy([item.label || 'Specialist',item.title].filter(Boolean).join(' · '),item.status || '', 'specialist:' + item.key + ':status');
      if (item.detail) copy.append(itemDetail(item.detail,'specialist:' + item.key + ':detail',item.label || 'Specialist'));
      article.append(copy);
      const actions = node('div','','vp3-agent-activity-actions');
      if (item.canView === true) actions.append(button(item.viewLabel || 'View in Chat',event => {
        if (!event.isTrusted || !scope()) return;
        const viewed = window.VP3AgentOrb?.viewActivitySpecialist?.(item.key) ?? window.VP3ChatSpecialists?.view?.(item.key);
        if (viewed !== false) close(false);
      }));
      article.append(actions);
      specialistsHost.append(article);
    }
    if (inbox) {
      const article = node('article','','vp3-agent-activity-item');
      article.dataset.activitySpecialist = 'gmail-inbox-review';
      article.append(itemCopy(inbox.title,inbox.detail,inbox.id));
      const actions = node('div','','vp3-agent-activity-actions');
      for (const action of inbox.actions) actions.append(button(action.label,event => void act(action,current,event)));
      article.append(actions);
      specialistsHost.append(article);
    }
  }
  function validState(data,current) {
    return data?.contract === 'vp3.agent.activity.v1' && data.owner_user_id === current.owner && data.agent_id === current.agent &&
      data.conversation_id === current.cid && data.working_attention_binding === current.binding &&
      typeof data.observed_at === 'string' && Number.isFinite(Date.parse(data.observed_at)) && Array.isArray(data.sections) &&
      data.sections.length <= 12 && data.sections.every(section => typeof section.id === 'string' && typeof section.label === 'string' &&
        Array.isArray(section.items) && section.items.length <= 12 && section.items.every(item => typeof item.id === 'string' &&
          typeof item.title === 'string' && item.title.length <= 500 && (item.detail === undefined || typeof item.detail === 'string' && item.detail.length <= 1600) &&
          Array.isArray(item.actions) && item.actions.length <= 4 && item.actions.every(action => kinds.has(action?.kind) &&
            typeof action.label === 'string' && action.label.length <= 120)));
  }
  async function act(action,current,event) {
    if (!event.isTrusted || !visible() || signature(current) !== signature(scope()) || !snapshot) return;
    const observed = Date.parse(snapshot.observed_at);
    if (Date.now() - observed > 60000) { status.textContent = 'Refreshing this view before continuing…'; stop(); void read(); return; }
    const intent = generation;
    const fallback = localUrl(action.href);
    const chat = continuity();
    try {
      if (action.kind === 'task_notes' && window.VP3TaskNotes?.open) {
        if (positive(action.list_id)) window.VP3TaskNotes.open({kind:'task_list',id:action.list_id});
        else window.VP3TaskNotes.open({kind:'reminders',view:['open','today','overdue','all'].includes(action.view) ? action.view : 'open'});
        return;
      }
      if (action.kind === 'canvas' && typeof action.canvas_id === 'string' && window.VP3CodingCanvasV1?.open) {
        const accepted = await window.VP3CodingCanvasV1.open(action.canvas_id,positive(action.revision) ? action.revision : undefined);
        if (accepted !== false && intent === generation && signature(current) === signature(scope())) close(false);
        return;
      }
      if (action.kind === 'mission' && typeof action.mission_id === 'string' && window.VP3AgentMissionsV1?.open) {
        const accepted = await window.VP3AgentMissionsV1.open(action.mission_id);
        if (accepted !== false && intent === generation && signature(current) === signature(scope())) close(false);
        return;
      }
      if (action.kind === 'song' && positive(action.track_id) && chat?.playActivityTrack) {
        if (await chat.playActivityTrack(action.track_id) !== false) return;
      }
      if (['chat','youtube'].includes(action.kind) && positive(action.conversation_id) && window.STONEFELLOW_CHAT && chat?.openConversation) {
        close(false);
        const accepted = await chat.openConversation(action.conversation_id,positive(action.message_id) ? {messageId:action.message_id} : {});
        const now = scope();
        if (!now || now.owner !== current.owner || now.agent !== current.agent) return;
        const row = positive(action.message_id) ? document.querySelector('#chatThread [data-chat-message-id="' + action.message_id + '"][data-chat-conversation-id="' + action.conversation_id + '"]') : null;
        if (accepted !== false && (!positive(action.message_id) || row)) row?.scrollIntoView({block:'center'});
        else if (fallback) location.assign(fallback);
        return;
      }
      if (!visible() || intent !== generation || signature(current) !== signature(scope())) return;
      if (action.kind !== 'url' && !window.STONEFELLOW_CHAT && window.VP3AgentOrb?.activityAction) {
        close(false);
        const handoff = generation;
        const accepted = await window.VP3AgentOrb.activityAction(action);
        if (accepted || handoff !== generation || signature(current) !== signature(scope())) return;
        return;
      }
      if (fallback) location.assign(fallback);
      else status.textContent = 'Open private Agent Chat to continue with this item.';
    } catch (error) {
      if (signature(current) === signature(scope()) && opened) status.textContent = error.message || 'This item could not be opened.';
    }
  }
  function render() {
    if (!snapshot || !scope() || !sections) return;
    const current = scope();
    const nodes = [];
    for (const previous of sections.querySelectorAll('[data-activity-group]')) groupOpen.set(previous.dataset.activityGroup,previous.open);
    for (const group of groups) {
      const found = snapshot.sections.filter(section => group.ids.includes(section.id) && section.items.length);
      const section = node('details','','vp3-agent-activity-group');
      const count = found.reduce((total,item) => total + item.items.length,0);
      section.dataset.activityGroup = group.title;
      section.append(node('summary',group.title + ' (' + count + ')'));
      section.open = groupOpen.has(group.title) ? groupOpen.get(group.title) : nodes.length === 0;
      if (found.length) groupOpen.set(group.title,section.open);
      section.addEventListener('toggle',() => groupOpen.set(group.title,section.open));
      for (const source of found) {
        const subsection = node('section','','vp3-agent-activity-source');
        subsection.append(node('h4',source.label));
        for (const item of source.items) {
          const article = node('article','','vp3-agent-activity-item');
          article.append(itemCopy(item.title,item.detail,source.id + ':' + item.id));
          const actions = node('div','','vp3-agent-activity-actions');
          for (const action of item.actions) actions.append(button(action.label,event => void act(action,current,event)));
          article.append(actions);
          subsection.append(article);
        }
        section.append(subsection);
      }
      if (found.length) nodes.push(section);
    }
    if (!nodes.length && !specialistSnapshot().length && !listening?.childElementCount) nodes.push(node('p','No current items to show.'));
    sections.replaceChildren(...nodes);
    contextLabel.textContent = [snapshot.agent_name,snapshot.current_chat?.title].filter(value => typeof value === 'string' && value.trim()).join(' · ');
    const checked = new Date(snapshot.observed_at).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'});
    status.textContent = '';
    verified.textContent = 'Items updated ' + checked;
    panel.querySelector('[data-agent-worker-details]').hidden = false;
    if (snapshot.coverage && Object.values(snapshot.coverage).some(value => value === 'limited' || value === 'unavailable')) {
      status.textContent = 'Some sources are unavailable.';
    }
    renderSpecialists();
    window.VP3AgentOrb?.positionActivity?.();
  }
  async function read() {
    if (!visible() || controller) return;
    const current = scope();
    if (!current) { close(false); return; }
    const mine = generation;
    const request = new AbortController();
    controller = request;
    const timeout = setTimeout(() => request.abort(),10000);
    try {
      const url = new URL(cfg.activityEndpoint,location.href);
      url.searchParams.set('agent_id',String(current.agent));
      url.searchParams.set('conversation_id',String(current.cid));
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-VP3-Working-Attention':current.binding},signal:request.signal});
      const data = await response.json();
      if (!visible() || mine !== generation || signature(current) !== signature(scope()) || request.signal.aborted) return;
      if ([401,403,419].includes(response.status)) { close(false); return; }
      if (!response.ok || data?.ok !== true || !validState(data.state,current)) throw new Error('Top of Mind could not be refreshed.');
      snapshot = data.state;
      render();
    } catch (error) {
      if (visible() && mine === generation && signature(current) === signature(scope())) {
        snapshot = null;
        sections.replaceChildren();
        renderSpecialists();
        verified.textContent = '';
        status.textContent = 'The latest items are unavailable. Use Refresh to try again.';
      }
    } finally {
      clearTimeout(timeout);
      if (controller === request) controller = null;
      if (visible() && mine === generation) timer = setTimeout(() => void read(),12000);
    }
  }
  function open(options = {}) {
    if (new URLSearchParams(location.search).get('orb_embed') === '1' && parent !== window) {
      if (!scope()) return false;
      return window.VP3AgentOrb?.openActivity?.(options) ?? false;
    }
    if (!scope() || !mount()) return false;
    if (!opened) {
      window.VP3AgentOrb?.prepareActivity?.();
      returnFocus = document.activeElement;
      opened = true;
      host.hidden = false;
      panel.hidden = false;
      root.dataset.activityOpen = 'true';
      delete root.dataset.initiativeDismissed;
      root.querySelector('[data-orb-activity]')?.setAttribute('aria-expanded','true');
      const brief = document.getElementById('chatAgentBriefButton');
      brief?.setAttribute('aria-expanded','true');
      brief?.setAttribute('aria-controls',host.id);
      status.textContent = 'Loading current items…';
      if (options.focus !== false) host.querySelector('h2').focus();
      window.dispatchEvent(new CustomEvent('vp3:agent-activity-view',{detail:{open:true}}));
    }
    selectedSpecialist = typeof options.specialist === 'string' ? options.specialist : '';
    renderSpecialists();
    window.VP3AgentOrb?.positionActivity?.();
    if (options.focus !== false && selectedSpecialist) specialistsHost.querySelector('.selected')?.scrollIntoView({block:'nearest'});
    void read();
    return true;
  }
  window.VP3AgentActivityHub = Object.freeze({open,close,refresh:() => { stop(); void read(); }});
  window.dispatchEvent(new Event('vp3:agent-activity-ready'));
  window.addEventListener('vp3:chat-context-ready',() => window.dispatchEvent(new Event('vp3:agent-activity-ready')));
  window.addEventListener('vp3:agent-orb-ready',mount);
  window.addEventListener('vp3:agent-activity-open',event => open(event.detail || {}));
  window.addEventListener('vp3:chat-specialists-changed',renderSpecialists);
  window.addEventListener('stonefellow:chat-context-changing',() => close(false));
  window.addEventListener('vp3:orb-conversation-changed',() => { if (!window.STONEFELLOW_CHAT) close(false); });
  window.addEventListener('vp3:participant-selection-changed',() => close(false));
  window.addEventListener('vp3:participant-task-context',event => { participantActor = Number(event.detail?.participant_id) || 0; close(false); });
  for (const name of ['vp3:playback-history-changed','vp3:youtube-history-changed','vp3:chat-history-updated','vp3-coding-usage-change']) {
    window.addEventListener(name,() => { if (visible()) { stop(); void read(); } });
  }
  document.addEventListener('visibilitychange',() => { stop(); if (visible()) void read(); });
  document.addEventListener('keydown',event => { if (event.key === 'Escape' && opened) { event.preventDefault(); close(); } });
  window.addEventListener('pagehide',() => { close(false); disposed = true; });
  window.addEventListener('pageshow',() => { disposed = false; });
  mount();
})();
