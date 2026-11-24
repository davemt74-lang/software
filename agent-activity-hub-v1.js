(() => {
  'use strict';
  if (window.VP3AgentActivityHub) return;
  const cfg = window.VP3_ORB_CONFIG;
  if (!cfg?.activityEndpoint) return;
  const initialOwner = Number(window.STONEFELLOW_CHAT?.userId || cfg.ownerKey);
  const initialBinding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || cfg.workingAttentionBinding || '');
  const groups = [
    {title:'Needs attention',ids:['reminders','tasks','lists','calendar']},
    {title:'Continue',ids:['recording','history','youtube','songs']},
    {title:'Agent work',ids:['missions','canvases','suggestions','gmail_outcomes']}
  ];
  const workspaceSources = {planning:['calendar','appointments'],coding:['canvas_suggestions','canvases'],mission:['missions'],brainstorm:['suggestions'],marketing:['marketing']};
  const canvasSuggestionStates = {considered:'Idea',queued:'Proposal queued',working:'Preparing a proposal',pending:'Ready to post in Chat',
    proposal_ready:'Awaiting your review',discussed:'Discussed in Chat',draft_queued:'Private draft requested',
    draft_ready:'Private draft ready',draft_failed:'Draft generation failed'};
  const officeCollections = {
    reminders:{label:'Reminders',empty:'No saved reminders.'},
    tasks:{label:'Tasks',empty:'No saved tasks.'},
    lists:{label:'Task lists',empty:'No saved task lists.'},
    list_items:{label:'List contents',empty:'This list is empty.'},
  };
  const officeReportStates = {queued:'Analysis queued.',running:'Analyzing this item.',ready:'Analysis ready.',
    failed:'The analysis could not be verified.',stale:'The analysis is no longer current.'};
  const officeAgentTaskStates = {queued:'Queued',running:'In progress',completed:'Completed',failed:'Failed',stale:'No longer current'};
  const findingEngagementStates = {
    open:'Awaiting your reply in Chat',discussed:'Reply recorded',queued:'Follow-up queued',
    deferred:'Follow-up deferred',declined:'Declined',owner_resolved:'Marked resolved by you',
    completed:'Follow-up completed',stale:'Follow-up needs review',
  };
  const mediaEngagementStates = {
    open:'Awaiting your reply in Chat',accepted:'Accepted suggestion',play_requested:'Play requested',played:'Played',
    declined:'Skipped',deferred:'Deferred',stale:'No longer available',
  };
  const kinds = new Set(['url','chat','task_notes','list_inventory','mission','canvas','youtube','song']);
  const gmailMonitorLabels = {
    scheduled:'Background check scheduled.',
    checked:'Last check completed.',
    working:'Checking Gmail for new messages.',
    overdue:'A scheduled Gmail check is due. It can run in open Chat.',
    retrying:'The last check failed. A retry is scheduled.',
    restart_required:'Restart inbox monitoring in Gmail settings.',
    unavailable:'Gmail monitoring status is unavailable.'
  };
  const specialistPriority = ['working','thinking','waiting_specialist','waiting_agent','waiting_owner','waiting_reply',
    'queued','ready_to_report','ready','interrupted','blocked','failed','waiting_retry','paused','completed','assessed','scheduled','checked','idle'];
  const specialistRank = state => {
    const rank = specialistPriority.indexOf(state);
    return rank === -1 ? specialistPriority.length : rank;
  };
  const specialistKey = /^[a-z][a-z0-9-]{0,47}(?::[a-z0-9][a-z0-9-]{0,63})?$/;
  const activeSpecialist = state => state === 'thinking' || state === 'working';
  const family = key => ['email','gmail-inbox-review','gmail-inbox-monitor'].includes(key) ? 'gmail' : key.split(':',1)[0];
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const ruleActionLabels = {watch:'Keep in Top of Mind',prepare:'Prepare privately',ask:'Ask in Chat',report:'Report in Chat'};
  const ruleKey = /^[a-z][a-z0-9_-]{0,47}$/;
  const ruleSpecialists = new Set(['gmail','tasks','planning','media','curiosity','connected-sites','transcripts','marketing','profile']);
  const connectionSpecialists = {gmail:'gmail',research:'research',youtube:'youtube',music:'music',media:'music',
    transcripts:'transcripts',transcription:'transcripts'};
  const specialistExamples = {
    gmail:{actions:[['Check urgent email','Any urgent emails today?']]},
    tasks:{actions:[['Review saved tasks','List saved Brain tasks']]},
    planning:{actions:[['Review my calendar','Do I have any events on my calendar?']]},
    location:{actions:[
      ['Set up an arrival alert','When my iPhone arrives at Studio, show me my open recording tasks.'],
      ['Set up local research','When my iPhone arrives at Studio, check my open recording tasks. If I still need XLR cables, suggest nearby music stores open today.'],
      ['List arrival alerts','List my arrival alerts']
    ]},
    media:{question:'Would you like to listen to music or watch a video?',actions:[
      ['Listen to music','Play some music'],['Find a video','Search YouTube for a short live acoustic performance']]},
    research:{actions:[['Try a research question','Research topic speech-to-text accuracy benchmarks']]},
    'image-generator':{actions:[['Try an image','Generate a square image of a small desert observatory under a star-filled sky, with warm light in its windows and no text.']]},
    coding:{actions:[['Try a demo canvas','/build Build a private launch checklist with three sample tasks, a progress counter, and checkbox state that persists in Canvas. Use sample data only.']]},
    mission:{actions:[['Try a Mission plan','Compare three podcast microphones under $150 using public specifications. Summarize cost, connection type, and the trade-offs for recording interviews.']]},
    development:{question:'Outline a proposed app change for review.',settings:'Agent Development settings',
      actions:[['Draft an example request','/develop Outline a clearer empty state for the Missions panel.']]},
    brainstorm:{actions:[['Explore an idea','Brainstorm three ways to make my weekly planning easier.']]}
  };
  const starterWorkspaces = new Set(['media','canvas','connected-sites']);
  let host = null;
  let root = null;
  let panel = null;
  let status = null;
  let verified = null;
  let contextLabel = null;
  let summaryBody = null;
  let renderedSummary = '';
  let coordinationHost = null;
  const groupOpen = new Map();
  const detailOpen = new Map();
  const engagementPages = new Map();
  const marketingCanvasSelections = new Map();
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
  let operatorTab = 'brain';
  let operatorContact = '';
  let operatorRendered = '';
  let revealSpecialist = false;
  let disposed = false;
  let orbSurface = null;
  let specialistTrigger = null;
  let selectedLabel = '';
  let specialistScope = '';
  let workspaceButton = null;
  let backButton = null;
  let settingsButton = null;
  let specialistContent = null;
  let specialistSettings = null;
  let connectedSitesView = null;
  let imageStudioView = null;
  let profileView = null;
  let specialistMode = false;
  let workspace = false;
  let officeAllLoaded = false;
  let officeList = null;
  const officePages = new Map();
  const officeRequests = new Map();
  const officeRefreshes = new WeakSet();
  const officeErrors = new Map();
  let analysisRequest = null;
  let analysisFeedback = '';
  let accessRejected = false;

  const participant = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  const localUrl = value => {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '';
    try { const url = new URL(value,location.origin); return url.origin === location.origin ? url.pathname + url.search + url.hash : ''; }
    catch { return ''; }
  };
  const positive = value => Number.isSafeInteger(value) && value > 0;
  function reminderZone(value) {
    if (typeof value !== 'string' || value.length > 80) return '';
    try { new Intl.DateTimeFormat('en',{timeZone:value}); return value; }
    catch { return ''; }
  }
  const leaseCurrent = value => Date.parse(value) > Date.now();
  const coordinationInReview = () => ['brainstorming','planning','analyzing'].includes(snapshot?.coordination?.phase)
    && (!snapshot.coordination.lease_expires_at || leaseCurrent(snapshot.coordination.lease_expires_at));
  function scope(allowPending = false) {
    const chat = window.STONEFELLOW_CHAT;
    const context = window.VP3AgentOrb?.activityContext?.();
    const owner = Number(chat?.userId || cfg.ownerKey);
    const agent = Number(chat?.agentId ?? context?.agent ?? cfg.agentId ?? 0);
    const cid = Number(chat ? continuity()?.conversationId?.() ?? 0 : context?.id ?? 0);
    const binding = String(chat?.workingAttentionBinding || cfg.workingAttentionBinding || '');
    if (disposed || participant() || owner !== initialOwner || binding !== initialBinding || !owner || !binding ||
      !Number.isSafeInteger(agent) || agent < 0 || !Number.isSafeInteger(cid) || cid < 0 ||
      !allowPending && chat && (continuity()?.agentId?.() !== agent || continuity()?.isReady?.() !== true || continuity()?.isRestoring?.())) return null;
    return {owner,agent,cid,binding,hasChat:Boolean(chat)};
  }
  const signature = value => value ? JSON.stringify([value.owner,value.agent,value.cid,value.binding]) : '';
  const visible = () => opened && !document.hidden && host?.isConnected && !panel?.hidden;
  const officeWorkspace = () => specialistMode && workspace && family(selectedSpecialist) === 'tasks';
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
  function itemCopy(title,detail,key,expanded = false) {
    const copy = node('div','','vp3-agent-activity-copy');
    if (title) {
      const label = node('strong',title);
      label.title = title;
      copy.append(label);
    }
    if (detail) copy.append(expanded ? node('p',detail,'vp3-agent-activity-detail') : itemDetail(detail,key,title));
    return copy;
  }
  function gmailMonitorCopy(item) {
    const monitor = item.monitor;
    const expired = (monitor.state === 'working' && !leaseCurrent(monitor.lease_until)) || monitor.overdue_reason === 'claim_expired';
    const detail = expired ? 'The inbox check was interrupted. It can resume in open Chat.'
      : monitor.overdue_reason === 'retry_due' ? 'The last inbox check failed. Its retry is due.' : gmailMonitorLabels[monitor.state];
    const copy = itemCopy('',detail,item.id);
    if (monitor.pending === true) copy.append(node('small','An inbox update is waiting to post to Chat.'));
    return copy;
  }
  function stop() {
    generation++;
    clearTimeout(timer);
    timer = 0;
    controller?.abort();
    controller = null;
  }
  function abortOfficeRequests() {
    for (const request of officeRequests.values()) request.abort();
    officeRequests.clear();
  }
  function clearEngagementPages() {
    for (const page of engagementPages.values()) page.controller?.abort();
    engagementPages.clear();
  }
  function clear() {
    stop();
    connectedSitesView?.dispose();
    connectedSitesView = null;
    imageStudioView?.dispose();
    imageStudioView = null;
    profileView?.dispose();
    profileView = null;
    abortOfficeRequests();
    orbSurface?.rules?.reset();
    officeAllLoaded = false;
    officeList = null;
    officePages.clear();
    officeErrors.clear();
    analysisRequest?.abort();
    analysisRequest = null;
    analysisFeedback = '';
    snapshot = null;
    operatorTab = 'brain';
    operatorContact = '';
    operatorRendered = '';
    revealSpecialist = false;
    detailOpen.clear();
    marketingCanvasSelections.clear();
    clearEngagementPages();
    sections?.replaceChildren();
    specialistsHost?.replaceChildren();
    coordinationHost?.replaceChildren();
    if (!specialistMode) orbSurface?.opportunities.replaceChildren();
    renderSummary();
    if (specialistMode && family(selectedSpecialist) === 'tasks') workspaceButton.textContent = 'Open workspace';
    if (status) status.textContent = '';
    if (verified) verified.textContent = '';
    if (contextLabel) contextLabel.textContent = '';
  }
  function close(restore = true) {
    if (!opened) return;
    if(specialistMode&&family(selectedSpecialist)==='profile')window.VP3ProfileWorkspaceV1?.cancelPendingAnalyticsDestination?.();
    opened = false;
    accessRejected = false;
    clear();
    const focus = returnFocus;
    returnFocus = null;
    if (specialistMode) {
      disposeSpecialistSettings();
      specialistTrigger?.setAttribute('aria-expanded','false');
      host.remove();
      specialistTrigger = workspaceButton = backButton = settingsButton = specialistContent = null;
      specialistMode = workspace = false;
      if (restore) (focus?.isConnected ? focus : document.getElementById('chatInput'))?.focus();
      return;
    }
    if (root) {
      delete root.dataset.activityOpen;
      root.dataset.initiativeDismissed = 'true';
    }
    if (host) host.hidden = true;
    panel?.removeAttribute('role');
    panel?.removeAttribute('aria-labelledby');
    panel?.setAttribute('aria-label','Agent background work');
    orbSurface?.presenceObserver?.disconnect();
    const brief = document.getElementById('chatAgentBriefButton');
    brief?.setAttribute('aria-expanded','false');
    brief?.setAttribute('aria-controls','chatAgentBriefPopover');
    if (restore) focus?.focus?.();
    window.dispatchEvent(new CustomEvent('vp3:agent-activity-view',{detail:{open:false}}));
  }
  function renderPresence() {
    if (!visible() || specialistMode || !orbSurface?.presence) return;
    const voice = window.VP3AgentOrb?.state?.()?.state || root?.dataset.state;
    const eyes = window.VP3AgentEyes?.isActive?.() === true || root?.dataset.eyesActive === 'true';
    const chat = continuity();
    const availability = !window.STONEFELLOW_CHAT ? 'Agent Chat closed'
      : !chat?.isReady?.() || chat?.isRestoring?.() ? 'Chat connecting'
      : document.getElementById('chatInput')?.value.trim() ? 'Drafting in Chat'
      : chat?.isBusy?.() || ['thinking','processing','speaking'].includes(voice) ? 'Chat in progress'
      : document.hasFocus() ? 'Chat available' : 'Chat out of focus';
    const labels = [voice === 'listening' ? 'Listening' : 'Listening off',eyes ? 'Agent Eyes on' : 'Agent Eyes off',availability];
    orbSurface.presence.forEach((chip,index) => {
      if (chip.textContent !== labels[index]) chip.textContent = labels[index];
    });
  }
  function createActivityTabs(overview,opportunities) {
    const list = node('div','','vp3-agent-activity-tabs');
    list.setAttribute('role','tablist');
    list.setAttribute('aria-label','Top of Mind views');
    const panels = [overview,opportunities];
    const tabs = ['Overview','Opportunities'].map((label,index) => {
      const tab = button(label,() => select(index));
      tab.id = 'vp3AgentActivityTab' + index;
      tab.setAttribute('role','tab');
      tab.setAttribute('aria-controls',panels[index].id);
      panels[index].setAttribute('role','tabpanel');
      panels[index].setAttribute('aria-labelledby',tab.id);
      panels[index].tabIndex = 0;
      return tab;
    });
    function select(index,focus = false) {
      tabs.forEach((tab,i) => {
        tab.tabIndex = i === index ? 0 : -1;
        tab.setAttribute('aria-selected',String(i === index));
        panels[i].hidden = i !== index;
      });
      if (focus) tabs[index].focus();
    }
    list.addEventListener('keydown',event => {
      const active = tabs.indexOf(event.target);
      if (active < 0 || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault();
      select(event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (active + 1) % tabs.length,true);
    });
    list.append(...tabs);
    select(0);
    return {list,select};
  }
  function mount() {
    const next = window.VP3AgentOrb?.activityHost?.();
    if (!next || opened && specialistMode) return false;
    if (orbSurface?.host === next) {
      ({host,root,panel,status,verified,contextLabel,summaryBody,coordinationHost,sections,specialistsHost,listening} = orbSurface);
      return true;
    }
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
    const summary = node('section','','vp3-agent-activity-summary');
    const summaryHeading = node('h3','Summary');
    summaryHeading.id = 'vp3AgentActivitySummaryTitle';
    summary.setAttribute('aria-labelledby',summaryHeading.id);
    summary.hidden = true;
    summaryBody = node('div');
    renderedSummary = '';
    summaryBody.setAttribute('aria-live','polite');
    summary.append(summaryHeading,summaryBody);
    const endpoint = localUrl(cfg.coordinationRulesEndpoint);
    const rules = endpoint && typeof cfg.csrf === 'string' && cfg.csrf ? createRulesEditor(endpoint) : null;
    const connections = localUrl(cfg.specialistSettings?.connections);
    const checkins = connections ? settingsLink('Casual check-in settings',connections+'#casual-checkins') : null;
    if (checkins) checkins.className = 'vp3-agent-activity-checkin-settings';
    coordinationHost = node('section','','vp3-agent-activity-coordination');
    coordinationHost.setAttribute('aria-label','Agent coordination');
    specialistsHost = node('section','','vp3-agent-activity-specialists');
    sections = node('div','','vp3-agent-activity-sections');
    listening = node('section','','vp3-agent-activity-listening');
    listening.dataset.agentActivityListening = '';
    listening.setAttribute('aria-label','Resume playing');
    const presenceBar = node('div','','vp3-agent-activity-presence');
    presenceBar.setAttribute('role','group');
    presenceBar.setAttribute('aria-label','Current presence');
    const presence = Array.from({length:3},() => node('span'));
    presenceBar.append(...presence);
    const overview = node('div','','vp3-agent-activity-tabpanel');
    overview.id = 'vp3AgentActivityOverview';
    const opportunities = node('div','','vp3-agent-activity-tabpanel');
    opportunities.id = 'vp3AgentActivityOpportunities';
    const tabs = createActivityTabs(overview,opportunities);
    overview.append(summary,coordinationHost,sections,specialistsHost,listening,...(rules ? [rules.host] : []),
      ...(checkins ? [checkins] : []));
    verified = node('small');
    verified.dataset.agentActivityVerified = '';
    panel.querySelector('[data-agent-worker-details]')?.append(verified);
    host.setAttribute('aria-labelledby',heading.id);
    host.replaceChildren(header,contextLabel,presenceBar,tabs.list,status,overview,opportunities);
    host.hidden = true;
    const presenceObserver = new MutationObserver(renderPresence);
    orbSurface = {host,root,panel,status,verified,contextLabel,summaryBody,coordinationHost,sections,specialistsHost,listening,rules,
      opportunities,tabs,presence,presenceObserver};
    window.dispatchEvent(new CustomEvent('vp3:agent-activity-listening-slot',{detail:{host:listening}}));
    window.dispatchEvent(new Event('vp3:agent-activity-ready'));
    return true;
  }
  function rulesState(data,current) {
    const state = data?.state;
    if (data?.ok !== true || state?.owner_user_id !== current.owner || state?.agent_id !== current.agent ||
      state?.conversation_id !== current.cid || typeof state.revision !== 'string' || !/^[a-f0-9]{64}$/.test(state.revision)) return null;
    const validLabel = label => typeof label === 'string' && label.trim() !== '' && label.length <= 120;
    const actions = Object.entries(state.catalog?.actions || {});
    const specialists = Object.entries(state.catalog?.specialists || {});
    if (actions.length !== Object.keys(ruleActionLabels).length || actions.some(([id,label]) => !Object.hasOwn(ruleActionLabels,id) || !validLabel(label)) ||
      !specialists.length || specialists.length > 64) return null;
    const catalog = new Map();
    const rules = {};
    for (const [id,definition] of specialists) {
      const triggers = Object.entries(definition?.triggers || {});
      if (!ruleKey.test(id) || !validLabel(definition?.label) || !triggers.length || triggers.length > 32 ||
        triggers.some(([trigger,label]) => !ruleKey.test(trigger) || !validLabel(label) || !Object.hasOwn(ruleActionLabels,state.rules?.[id]?.[trigger]))) return null;
      const triggerMap = new Map(triggers);
      const limits = definition.allowed_actions;
      if (limits !== undefined && (!limits || Array.isArray(limits) || typeof limits !== 'object')) return null;
      const allowed = new Map();
      for (const [trigger,keys] of Object.entries(limits || {})) {
        if (!triggerMap.has(trigger) || !Array.isArray(keys) || !keys.length || keys.length > actions.length ||
          keys.some(key => !Object.hasOwn(ruleActionLabels,key)) || new Set(keys).size !== keys.length ||
          !keys.includes(state.rules[id][trigger])) return null;
        allowed.set(trigger,new Set(keys));
      }
      catalog.set(id,{label:definition.label,triggers:triggerMap,allowed});
      rules[id] = Object.fromEntries(triggers.map(([trigger]) => [trigger,state.rules[id][trigger]]));
    }
    return {revision:state.revision,rules,catalog,actions};
  }
  function createRulesEditor(endpoint, fixedSpecialist = '') {
    const details = node('details','','vp3-agent-activity-rules');
    const feedback = node('p','','vp3-agent-activity-status');
    feedback.setAttribute('role','status');
    const form = node('form');
    const fields = node('fieldset');
    const rows = node('div');
    const picker = node('select');
    const specialistLabel = node('label');
    specialistLabel.append(node('span','Specialist'),picker);
    specialistLabel.hidden = Boolean(fixedSpecialist);
    fields.append(node('p','These rules apply to this Agent across saved Chats.','vp3-agent-activity-status'),specialistLabel,rows);
    const controls = node('div','','vp3-agent-activity-rule-controls');
    const save = node('button','Save rules');
    save.type = 'submit';
    const reload = button('Reload rules',() => void requestRules());
    controls.append(save,reload);
    form.append(fields,controls,feedback);
    details.append(node('summary','Chat rules'),form);
    let saved = null;
    let draft = null;
    let request = null;
    let stale = false;
    let retry = false;
    const editorActive = () => visible() && details.isConnected && (fixedSpecialist
      ? specialistSettings?.body.contains(details) === true
      : !specialistMode);
    function updateControls() {
      fields.hidden = !saved;
      fields.disabled = Boolean(request) || stale;
      save.hidden = !saved;
      save.disabled = Boolean(request) || stale || JSON.stringify(saved?.rules) === JSON.stringify(draft);
      reload.hidden = !retry;
      reload.disabled = Boolean(request);
    }
    function ruleRows() {
      const specialist = picker.value;
      const definition = saved?.catalog.get(specialist);
      const content = [];
      for (const [trigger,name] of definition?.triggers || []) {
        const select = node('select');
        const allowed = definition.allowed.get(trigger);
        for (const [id,label] of saved.actions) if (!allowed || allowed.has(id)) select.append(new Option(label,id));
        select.value = draft[specialist][trigger];
        select.addEventListener('change',() => {
          if (!Object.hasOwn(ruleActionLabels,select.value) || (allowed && !allowed.has(select.value))) return;
          draft[specialist][trigger] = select.value;
          feedback.textContent = '';
          updateControls();
        });
        const label = node('label');
        label.append(node('span',name),select);
        content.push(label);
      }
      rows.replaceChildren(...content);
    }
    async function requestRules(changes = null) {
      const current = scope();
      if (request || !editorActive() || !positive(current?.cid) || !details.open) return;
      const active = new AbortController();
      request = active;
      retry = false;
      feedback.textContent = changes ? 'Saving Chat rules…' : 'Loading Chat rules…';
      updateControls();
      const timeout = setTimeout(() => active.abort(),10000);
      const isCurrent = () => request === active && editorActive() && signature(current) === signature(scope());
      try {
        const url = new URL(endpoint,location.origin);
        const ids = {agent_id:current.agent,conversation_id:current.cid};
        const headers = {'X-VP3-Working-Attention':current.binding};
        let body;
        if (changes) {
          headers['Content-Type'] = 'application/json';
          body = JSON.stringify({...ids,csrf_token:cfg.csrf,revision:saved.revision,rules:changes});
        } else {
          for (const [id,value] of Object.entries(ids)) url.searchParams.set(id,String(value));
        }
        const response = await fetch(url,{method:changes ? 'POST' : 'GET',credentials:'same-origin',cache:'no-store',headers,body,signal:active.signal});
        if (!isCurrent()) return;
        if (response.status === 409) {
          stale = retry = true;
          feedback.textContent = 'Rules changed in another session. Reload rules to continue.';
          return;
        }
        if ([401,403,419].includes(response.status)) {
          saved = draft = null;
          rows.replaceChildren();
          picker.replaceChildren();
          retry = true;
          feedback.textContent = 'This Chat’s access could not be verified.';
          return;
        }
        const next = rulesState(await response.json(),current);
        if (!isCurrent()) return;
        if (!response.ok || !next) throw new Error('Invalid rule settings');
        if (fixedSpecialist && !next.catalog.has(fixedSpecialist)) throw new Error('This specialist has no Chat rules.');
        const previous = picker.value;
        saved = next;
        draft = Object.fromEntries(Object.entries(next.rules).map(([id,values]) => [id,{...values}]));
        picker.replaceChildren(...[...next.catalog].filter(([id]) => !fixedSpecialist || id === fixedSpecialist)
          .map(([id,{label}]) => new Option(label,id)));
        if (fixedSpecialist || next.catalog.has(previous)) picker.value = fixedSpecialist || previous;
        ruleRows();
        stale = false;
        feedback.textContent = changes ? 'Chat rules saved.' : '';
        if (changes) { stop(); void read(); }
      } catch {
        if (!isCurrent()) return;
        retry = !saved || stale;
        feedback.textContent = changes ? 'Chat rules could not be saved. Try again.' : 'Chat rules could not be loaded.';
      } finally {
        clearTimeout(timeout);
        if (request === active) {
          request = null;
          updateControls();
          window.VP3AgentOrb?.positionActivity?.();
        }
      }
    }
    picker.addEventListener('change',ruleRows);
    details.addEventListener('toggle',() => {
      if (details.open && !saved && !request && !retry) void requestRules();
      window.VP3AgentOrb?.positionActivity?.();
    });
    form.addEventListener('submit',event => {
      event.preventDefault();
      if (event.isTrusted && !save.disabled && draft) void requestRules(fixedSpecialist ? {[fixedSpecialist]:draft[fixedSpecialist]} : draft);
    });
    updateControls();
    return {host:details,open() { details.open = true; void requestRules(); },label:(specialist,trigger) => saved?.catalog.get(specialist)?.triggers.get(trigger),reset() {
      request?.abort();
      request = saved = draft = null;
      stale = retry = false;
      details.open = false;
      feedback.textContent = '';
      picker.replaceChildren();
      rows.replaceChildren();
      updateControls();
    }};
  }
  function positionSpecialist() {
    if (!opened || !specialistMode || workspace || specialistSettings || innerWidth <= 820) return;
    const anchor = specialistTrigger?.getBoundingClientRect();
    if (!anchor?.width || !anchor.height) return;
    const right = anchor.left > 385 ? innerWidth - anchor.left + 10 : 12;
    const top = Math.max(12,Math.min(anchor.top - 10,innerHeight - host.offsetHeight - 12));
    host.style.setProperty('--specialist-right',`${right}px`);
    host.style.setProperty('--specialist-top',`${top}px`);
  }
  function showWorkspace(expanded) {
    workspace = expanded;
    if (expanded) host.dataset.workspace = 'true';
    else delete host.dataset.workspace;
    workspaceButton.hidden = expanded;
    settingsButton.hidden = expanded || ['curiosity','profile'].includes(family(selectedSpecialist)) && !localUrl(cfg.coordinationRulesEndpoint);
    backButton.hidden = !expanded;
    if (family(selectedSpecialist) === 'tasks') {
      if (!expanded) {
        abortOfficeRequests();
        officeList = null;
        officePages.delete('list_items');
        officeErrors.delete('list_items');
      }
      stop();
      void read();
    } else if (family(selectedSpecialist) === 'planning') {
      stop();
      void read();
    }
    if (snapshot) render();
    else renderSpecialists();
    positionSpecialist();
    host.scrollTop = 0;
    (expanded ? backButton : workspaceButton).focus();
  }

  function settingsLink(label,href) {
    const target = localUrl(href);
    if (!target) return null;
    const link = node('a',label);
    link.href = target;
    link.target = '_blank';
    link.rel = 'noopener';
    return link;
  }

  function mountSettingsFrame(view,current,endpoint,params,label,fallbackHref) {
    const address = localUrl(endpoint);
    if (!address) return false;
    const src = new URL(address,location.origin);
    for (const [key,value] of Object.entries({view:'specialist-settings',owner_user_id:current.owner,...params})) {
      src.searchParams.set(key,String(value));
    }
    const expected = params.app || 'planning';
    const section = node('section','','vp3-agent-activity-native-settings');
    const feedback = node('p','Loading '+label.toLowerCase()+'…','vp3-agent-activity-status');
    feedback.setAttribute('role','status');
    const frame = node('iframe');
    frame.title = label;
    frame.hidden = true;
    const fallback = settingsLink('Open '+label.toLowerCase(),fallbackHref);
    if (fallback) fallback.hidden = true;
    section.append(feedback,frame,...(fallback ? [fallback] : []));
    view.body.append(section);
    let observer = null;
    let frameDocument = null;
    const keydown = event => {
      if (event.key === 'Escape' && !event.defaultPrevented && specialistSettings === view) {
        event.preventDefault();
        closeSpecialistSettings();
      }
    };
    const releaseDocument = () => {
      observer?.disconnect();
      observer = null;
      frameDocument?.removeEventListener('keydown',keydown);
      frameDocument = null;
    };
    const unavailable = () => {
      frame.hidden = true;
      feedback.textContent = label+' could not be verified here.';
      if (fallback) fallback.hidden = false;
    };
    const timeout = setTimeout(unavailable,10000);
    frame.addEventListener('load',() => {
      releaseDocument();
      if (specialistSettings !== view || signature(scope()) !== signature(current)) return;
      clearTimeout(timeout);
      const document = frame.contentDocument;
      const identity = document?.body?.dataset;
      if (!identity || identity.vp3SpecialistSettings !== expected || Number(identity.ownerUserId) !== current.owner
        || expected === 'planning' && (Number(identity.agentId) !== current.agent || Number(identity.conversationId) !== current.cid)) {
        unavailable();
        return;
      }
      frameDocument = document;
      feedback.textContent = '';
      if (fallback) fallback.hidden = true;
      frame.hidden = false;
      const content = document.querySelector('main') || document.body;
      const resize = () => {
        if (specialistSettings !== view || frameDocument !== document) return;
        const height = Math.ceil(content.getBoundingClientRect().bottom - document.body.getBoundingClientRect().top);
        frame.style.height = `${Math.max(48,height + 2)}px`;
      };
      resize();
      observer = new ResizeObserver(resize);
      observer.observe(content);
      document.addEventListener('keydown',keydown);
    });
    view.cleanups.push(() => { clearTimeout(timeout); releaseDocument(); });
    frame.src = src.href;
    return true;
  }

  function populateSpecialistSettings(view,current,key) {
    const settings = cfg.specialistSettings || {};
    const rulesEndpoint = localUrl(cfg.coordinationRulesEndpoint);
    if (key === 'profile') view.body.append(node('p',
      'Visitor, call and reviewed task notifications continue in their existing interfaces. Current Profile reports remain available here.'));
    if (ruleSpecialists.has(key) && rulesEndpoint) {
      if (positive(current.cid)) {
        const rules = createRulesEditor(rulesEndpoint,key);
        view.body.append(rules.host);
        view.cleanups.push(rules.reset);
        rules.open();
      } else view.body.append(node('p','Open a saved Chat to edit this specialist’s Chat rules.'));
    }
    const app = Object.hasOwn(connectionSpecialists,key) ? connectionSpecialists[key] : '';
    if (app) {
      const label = {gmail:'Gmail connection settings',youtube:'YouTube settings',music:'Music and YouTube preferences',
        research:'Research preferences',transcripts:'Transcript preferences'}[app];
      const url = localUrl(settings.connections);
      const fallback = {gmail:'?app=gmail',youtube:'?app=youtube',music:'#music-research',
        research:'#transcript-research',transcripts:'#transcript-research'}[app];
      if (url) mountSettingsFrame(view,current,url,{app},label,url+fallback);
      if (key === 'media' && url) view.body.append(settingsLink('Casual check-ins',url+'#casual-checkins'));
    }
    if (key === 'planning') {
      const url = localUrl(settings.planning);
      if (url && positive(current.cid)) mountSettingsFrame(view,current,url,
        {tab:'settings',agent:current.agent,conversation_id:current.cid},'Scheduling settings',`${url}?tab=settings`);
    }
    if (key === 'coding' || key === 'brainstorm' || key === 'mission') {
      const content = node('section','','vp3-agent-activity-native-controls');
      view.body.append(content);
      const cleanup = key === 'coding' ? window.VP3CodingAgentSettings?.mount?.(content)
        : key === 'brainstorm' ? window.VP3_COGNITIVE_PRESENTATION?.mountInitiativeSettings?.(content)
          : window.VP3AgentMissionsV1?.mountSettings?.(content);
      if (typeof cleanup === 'function') view.cleanups.push(cleanup);
      else content.remove();
    }
    if (key === 'development' || key === 'image-generator') {
      const link = key === 'development' ? settingsLink('Agent Development settings',settings.developmentAdmin)
        : settingsLink('Image model settings',settings.imagesAdmin);
      if (link) view.body.append(link);
      if (key === 'image-generator') view.body.append(node('p','Choose image size, quality, and background for each generation request.'));
    }
    if (!view.body.childElementCount) view.body.append(node('p','This specialist has no saved settings for this Chat.'));
  }

  function disposeSpecialistSettings() {
    if (!specialistSettings) return;
    const view = specialistSettings;
    specialistSettings = null;
    for (const cleanup of view.cleanups) cleanup();
    view.body.remove();
  }

  function closeSpecialistSettings() {
    if (!specialistSettings) return;
    disposeSpecialistSettings();
    delete host.dataset.settings;
    specialistContent.hidden = false;
    host.querySelector('h2').textContent = selectedLabel;
    workspaceButton.hidden = Boolean(connectedSitesView || profileView);
    backButton.hidden = true;
    settingsButton.setAttribute('aria-expanded','false');
    host.scrollTop = 0;
    positionSpecialist();
    settingsButton.focus({preventScroll:true});
    void read();
  }

  function openSpecialistSettings() {
    if (specialistSettings) { closeSpecialistSettings(); return; }
    const current = scope();
    if (!current || accessRejected) return;
    stop();
    abortOfficeRequests();
    const body = node('div','','vp3-agent-activity-settings');
    body.id = 'vp3ChatSpecialistSettings';
    specialistSettings = {body,scopeKey:signature(current),cleanups:[]};
    host.dataset.settings = 'true';
    specialistContent.hidden = true;
    host.append(body);
    const heading = host.querySelector('h2');
    heading.textContent = selectedLabel+' settings';
    workspaceButton.hidden = true;
    backButton.hidden = false;
    settingsButton.setAttribute('aria-expanded','true');
    populateSpecialistSettings(specialistSettings,current,family(selectedSpecialist));
    host.scrollTop = 0;
    heading.focus({preventScroll:true});
  }

  function specialistSettingsIcon() {
    const ns = 'http://www.w3.org/2000/svg';
    const icon = document.createElementNS(ns,'svg');
    icon.setAttribute('viewBox','0 0 24 24');
    icon.setAttribute('aria-hidden','true');
    icon.setAttribute('focusable','false');
    const path = document.createElementNS(ns,'path');
    path.setAttribute('d','M10.3 3h3.4l.6 2.3 1.5.8 2.3-.6 2.4 2.4-.6 2.3.8 1.5 2.3.6v3.4l-2.3.6-.8 1.5.6 2.3-2.4 2.4-2.3-.6-1.5.8-.6 2.3h-3.4l-.6-2.3-1.5-.8-2.3.6-2.4-2.4.6-2.3-.8-1.5-2.3-.6v-3.4l2.3-.6.8-1.5-.6-2.3 2.4-2.4 2.3.6 1.5-.8z');
    path.setAttribute('transform','translate(1.8 1.8) scale(.85)');
    const center = document.createElementNS(ns,'circle');
    center.setAttribute('cx','12');center.setAttribute('cy','12');center.setAttribute('r','3');
    icon.append(path,center);
    return icon;
  }

  function openSavedTranscription(current,event,sessionId=0) {
    const canvas = window.STONEFELLOW_TRANSCRIPTION_CANVAS;
    if (!canvas?.open || !event.isTrusted || !current?.hasChat || accessRejected
      || signature(current) !== signature(scope())) return;
    close(false);
    void canvas.open(sessionId ? {sessionId} : {});
  }

  function openSpecialist(key,trigger,{label,focusReturn,fullReport = false,selection} = {}) {
    if (!scope(true)?.hasChat || typeof key !== 'string' || !specialistKey.test(key)) return false;
    if (opened && specialistMode && selectedSpecialist === key) {
      if (profileView) { if (selection) profileView.select(selection);return true; }
      close(); return true;
    }
    if (opened) close(false);
    specialistMode = true;
    selectedSpecialist = key;
    specialistScope = signature(scope(true));
    specialistTrigger = trigger;
    returnFocus = focusReturn || trigger;
    selectedLabel = String(label || window.VP3TopOfMindFeedV1?.specialistNames?.[family(key)] || 'Specialist').slice(0,120);
    host = node('section','','vp3-chat-specialist-activity');
    host.id = 'vp3ChatSpecialistActivity';
    host.setAttribute('role','dialog');
    host.setAttribute('aria-labelledby','vp3ChatSpecialistActivityTitle');
    panel = host;
    root = contextLabel = summaryBody = listening = null;
    const heading = node('h2',selectedLabel);
    heading.id = 'vp3ChatSpecialistActivityTitle';
    heading.tabIndex = -1;
    const header = node('header','','vp3-agent-activity-header');
    const id = family(key);
    const connectedSites = id === 'connected-sites' && window.VP3ConnectedSites?.mountWorkspace;
    const imageStudio = id === 'image-generator' && window.VP3ImageStudio?.mountWorkspace;
    const profileWorkspace = id === 'profile' && window.VP3_PROFILE_WORKSPACE_V1?.endpoint && window.VP3ProfileWorkspaceV1?.mountWorkspace;
    const transcriptCanvas = id === 'transcripts' && typeof window.STONEFELLOW_TRANSCRIPTION_CANVAS?.open === 'function';
    workspaceButton = button(transcriptCanvas ? 'Open Transcription Intelligence'
      : ['gmail','media','curiosity','coding','marketing','profile'].includes(id) ? 'Full report' : 'Open workspace',event => {
      if (!transcriptCanvas) { showWorkspace(true); return; }
      openSavedTranscription(scope(),event);
    });
    if (id === 'location') {
      const base = localUrl(window.VP3_CHAT_SPECIALIST_PRESENCE?.phoneTrackingUrl);
      workspaceButton.hidden = !base;
      if (base) {
        const current = scope(true);
        const map = new URL(base,location.origin);
        map.searchParams.set('agent',String(current.agent || 'system'));
        if (positive(current.cid)) map.searchParams.set('conversation_id',String(current.cid));
        workspaceButton = node('a','Open Phone Tracking');
        workspaceButton.href = map.href;
      }
    }
    if (id === 'operator') {
      workspaceButton = button('Open Phone',event => {
        if (!event.isTrusted || specialistScope !== signature(scope(true)) ||
          typeof window.VP3PhoneWorkspaceV1?.open !== 'function') return;
        const trigger = specialistTrigger;
        close(false);
        window.VP3PhoneWorkspaceV1.open(trigger);
      });
      workspaceButton.hidden = typeof window.VP3PhoneWorkspaceV1?.open !== 'function';
    }
    settingsButton = button('',openSpecialistSettings);
    settingsButton.className = 'vp3-specialist-settings-button';
    settingsButton.setAttribute('aria-label',selectedLabel+' settings');
    settingsButton.setAttribute('title',selectedLabel+' settings');
    settingsButton.setAttribute('aria-controls','vp3ChatSpecialistSettings');
    settingsButton.setAttribute('aria-expanded','false');
    settingsButton.append(specialistSettingsIcon());
    settingsButton.hidden = ['location','operator'].includes(id) || ['curiosity','connected-sites','profile'].includes(id) && !localUrl(cfg.coordinationRulesEndpoint);
    backButton = button('Back',() => specialistSettings ? closeSpecialistSettings() : showWorkspace(false));
    backButton.hidden = true;
    header.append(heading,workspaceButton,backButton,settingsButton,button('Close',() => close()));
    status = node('p','Loading activity…','vp3-agent-activity-status');
    status.setAttribute('role','status');
    verified = node('small');
    coordinationHost = node('section','','vp3-agent-activity-coordination');
    coordinationHost.setAttribute('aria-label','Current assessment');
    specialistsHost = node('div','','vp3-agent-activity-specialists');
    sections = node('div','','vp3-agent-activity-sections');
    specialistContent = node('div');
    specialistContent.append(status,coordinationHost,specialistsHost,sections);
    host.append(header,specialistContent);
    document.body.append(host);
    specialistTrigger?.setAttribute('aria-controls',host.id);
    specialistTrigger?.setAttribute('aria-haspopup','dialog');
    specialistTrigger?.setAttribute('aria-expanded','true');
    opened = true;
    if (profileWorkspace) {
      workspace = true;
      host.dataset.workspace = 'true';
      host.dataset.profileWorkspace = 'true';
      workspaceButton.hidden = true;
      status.hidden = true;
      profileView = profileWorkspace(sections,{scope,readReports:readProfileReport,renderReport:(target,value,options={}) => {
        const current=scope();
        if (current) renderProfileWorkspace(current,{host:target,value:{profile_report:value.report,observed_at:value.observedAt},
          full:options.full!==false,onSelect:options.onSelect,isCurrent:()=>target.isConnected&&signature(current)===signature(scope())});
      },onClose:restore=>close(restore),selection:selection||{tab:'overview'}});
      heading.focus({preventScroll:true});
    } else if (imageStudio) {
      workspace = true;
      host.dataset.workspace = 'true';
      host.dataset.imageStudio = 'true';
      workspaceButton.hidden = settingsButton.hidden = true;
      status.hidden = true;
      imageStudioView = imageStudio(sections,scope,() => close(false));
      heading.focus({preventScroll:true});
    } else if (connectedSites) {
      workspace = true;
      host.dataset.workspace = 'true';
      workspaceButton.hidden = true;
      status.hidden = true;
      connectedSitesView = connectedSites(sections, scope, () => close(false));
      heading.focus({preventScroll:true});
    } else {
      renderSpecialists();
      positionSpecialist();
      if (fullReport) showWorkspace(true);
      else heading.focus({preventScroll:true});
    }
    if (!profileView) void read();
    return true;
  }
  function specialistSnapshot() {
    const entries = window.VP3AgentOrb?.activitySpecialists?.({includeResults:true}) || window.VP3ChatSpecialists?.snapshot?.({includeResults:true}) || [];
    return Array.isArray(entries) ? entries.slice(0,12).filter(item => typeof item?.key === 'string' &&
      specialistKey.test(item.key) && (!item.expiresAt || item.expiresAt > Date.now()) &&
      ['label','status','title','detail','viewLabel'].every(key =>
        item[key] === undefined || typeof item[key] === 'string' && item[key].length <= 1200)) : [];
  }
  function appendSpecialistItem(host,copy,actions,key) {
    const article = node('article','','vp3-agent-activity-item');
    if (key) article.dataset.activitySpecialist = key;
    article.append(copy,actions);
    host.append(article);
    return article;
  }
  function stageSpecialistExample(id,index,current) {
    if (!current?.hasChat || !Object.hasOwn(specialistExamples,id)) return false;
    const prompt = specialistExamples[id].actions[index]?.[1];
    if (!prompt) return false;
    return id === 'mission' ? window.VP3AgentMissionsV1?.prepareExample?.(prompt,current.cid) === true
      : continuity()?.stageSpecialistPrompt?.(prompt,current.cid) === true;
  }

  async function moreOpenEngagements(id,current,before) {
    const endpoint = localUrl(cfg.topOfMindEndpoint);
    if (!endpoint || !positive(before) || !positive(current.cid) || signature(current) !== signature(scope())) return;
    const prior = engagementPages.get(id);
    if (prior?.controller) return;
    const controller = new AbortController();
    const page = {items:prior?.items || [],next:before,controller};
    engagementPages.set(id,page);
    renderSpecialists();
    const timeout = setTimeout(() => controller.abort(),8000);
    try {
      const url = new URL(endpoint,location.origin);
      for (const [key,value] of Object.entries({view:'engagements',scope:'chat',agent_id:current.agent,conversation_id:current.cid,specialist:id,before})) {
        url.searchParams.set(key,String(value));
      }
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'X-VP3-Working-Attention':current.binding}});
      const result = (await response.json()).state;
      if (!response.ok || result?.contract !== 'vp3.agent.engagements.v1' || result.owner_user_id !== current.owner
        || result.agent_id !== current.agent || result.conversation_id !== current.cid || result.specialist !== id
        || result.working_attention_binding !== current.binding || !Array.isArray(result.open_engagements)
        || result.open_engagements.length > 30
        || result.open_engagements.some(item => !positive(item?.message_id) || item.message_id >= before)
        || !['ready','unavailable'].includes(result.open_engagements_status)) throw Error('Engagement page unavailable');
      if (engagementPages.get(id) !== page || signature(current) !== signature(scope())) return;
      page.items = page.items.concat(result.open_engagements);
      if (result.open_engagements_status === 'unavailable') {
        page.error = 'Some open questions could not be verified. Try again.';
        return;
      }
      page.next = positive(result.open_engagements_next_before) && result.open_engagements_next_before < before
        ? result.open_engagements_next_before : null;
    } catch (_) {
      if (engagementPages.get(id) === page) page.error = 'More open questions could not be loaded.';
    } finally {
      clearTimeout(timeout);
      if (engagementPages.get(id) === page && signature(current) === signature(scope()) && visible()) {
        page.controller = null;
        renderSpecialists();
      }
    }
  }

  function appendOpenEngagements(section,id,row,current) {
    const first = Array.isArray(row?.open_engagements) ? row.open_engagements : [];
    const page = engagementPages.get(id);
    const next = page ? page.next : row?.open_engagements_next_before;
    const items = new Map();
    for (const item of [...first,...(page?.items || [])]) {
      if (!positive(item?.message_id) || item.conversation_id !== current.cid || typeof item.question !== 'string' || !item.question.trim()) continue;
      const action = specialistResult(item,current);
      if (action) items.set(item.message_id,{...item,action});
    }
    if (row?.open_engagements_status === 'unavailable' && !items.size && !positive(next)) {
      section.append(node('p','Open questions could not be verified.','vp3-agent-activity-detail'));
      return;
    }
    if (!items.size && !positive(next)) return;
    const key = 'open-engagements:' + id;
    const details = node('details','','vp3-agent-open-engagements');
    details.open = detailOpen.get(key) === true;
    const summary = node('summary',items.size ? `Awaiting your reply (${items.size}${positive(next) ? '+' : ''})` : 'Check older questions');
    summary.dataset.specialistFocus = key;
    details.append(summary);
    details.addEventListener('toggle',() => { if (details.isConnected) detailOpen.set(key,details.open); });
    const list = node('ul');
    for (const item of items.values()) {
      const li = node('li');
      if (typeof item.title === 'string' && item.title.trim() && item.title.trim() !== item.question.trim()) {
        li.append(node('strong',item.title.slice(0,190)));
      }
      li.append(node('p',item.question.slice(0,1000)));
      const view = button('View in Chat',event => void act(item.action,current,event));
      view.dataset.specialistFocus = key + ':' + item.message_id;
      li.append(view);
      list.append(li);
    }
    details.append(list);
    if (row?.open_engagements_status === 'unavailable') details.append(node('small','Some open questions could not be verified.'));
    if (page?.error) details.append(node('small',page.error));
    if (positive(next) && localUrl(cfg.topOfMindEndpoint)) {
      const more = button(page?.controller ? 'Loading…' : 'Show more',event => {
        if (event.isTrusted && visible()) void moreOpenEngagements(id,current,next);
      });
      more.disabled = Boolean(page?.controller);
      more.dataset.specialistFocus = key + ':more';
      details.append(more);
    }
    section.append(details);
  }
  function appendSpecialistExample(section,id,row,current) {
    if (!snapshot || !current.hasChat || specialistSettings || !row || !Object.hasOwn(specialistExamples,id)) return;
    if (id === 'coding' && snapshot.sections.some(source => source.id === 'canvas_suggestions' && source.items.length)) return;
    const pausedDevelopment = id === 'development' && row.state === 'paused';
    const arrivalControls = id === 'location' && ['armed','awaiting_consent'].includes(row.state);
    if (!arrivalControls && !pausedDevelopment && !['idle','checked','scheduled'].includes(row.state)) return;
    const example = specialistExamples[id];
    const article = node('div','','vp3-agent-activity-example');
    if (example.question) article.append(node('p',example.question));
    const actions = node('div','','vp3-agent-activity-actions');
    article.append(actions);
    if (pausedDevelopment) {
      const href = localUrl(cfg.specialistSettings?.developmentAdmin);
      if (!href) return;
      const link = node('a',example.settings);
      link.href = href;
      link.dataset.specialistExample = 'development-settings';
      actions.append(link);
    } else {
      const stagePrompt = id === 'mission' ? window.VP3AgentMissionsV1?.prepareExample : continuity()?.stageSpecialistPrompt;
      if (typeof stagePrompt !== 'function') return;
      const feedback = node('small');
      feedback.setAttribute('role','status');
      example.actions.forEach(([label],index) => {
        const control = button(label,event => {
          if (!event.isTrusted || !visible() || signature(current) !== signature(scope())) return;
          if (stageSpecialistExample(id,index,current)) close(false);
          else feedback.textContent = id === 'mission' ? 'Finish the current Mission draft or Chat turn, then try again.'
            : 'Finish your current draft or Chat turn, then try again.';
        });
        control.dataset.specialistExample = id + '-' + index;
        actions.append(control);
      });
      article.append(feedback);
    }
    section.append(article);
  }
  function specialistResult(row,current) {
    const href = localUrl(row.result_url);
    if (!href || !positive(row.conversation_id)) return null;
    const url = new URL(href,location.origin);
    const cid = url.searchParams.get('conversation_id');
    const agent = url.searchParams.get('agent');
    const message = url.searchParams.get('message_id');
    if (!url.pathname.endsWith('/chat.php') || cid !== String(row.conversation_id) ||
        agent !== String(current.agent) && !(current.agent === 0 && agent === 'system') ||
        message !== null && message !== String(row.message_id)) return null;
    if (message === null && positive(row.message_id)) url.searchParams.set('message_id',String(row.message_id));
    return {kind:'chat',label:'View in Chat',href:url.pathname+url.search+url.hash,
      conversation_id:row.conversation_id,message_id:row.message_id};
  }
  function openGmailSource(event,current,monitor) {
    const source = event.target.closest('[data-gmail-report-item]');
    if (!source || !event.isTrusted || !visible() || signature(current) !== signature(scope())) return;
    if (Date.now() - Date.parse(snapshot?.observed_at) > 60000) {
      status.textContent = 'Refreshing this view before continuing…';
      stop(); void read(); return;
    }
    if (window.VP3TopOfMindFeedV1.openEmailReportItem(current,monitor,source.dataset.gmailReportItem)) close(false);
    else status.textContent = 'Open Agent Chat and try again to read this email.';
  }
  async function analyzeRecentInbox(event,current,endpoint,csrf) {
    if (!event.isTrusted || !visible() || analysisRequest || !snapshot || signature(current) !== signature(scope())) return;
    const request = new AbortController();
    analysisRequest = request;
    analysisFeedback = '';
    renderSpecialists();
    const timeout = setTimeout(() => request.abort(),120000);
    const stillCurrent = () => analysisRequest === request && visible() && signature(current) === signature(scope());
    try {
      const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:request.signal,
        headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'analyze_recent',owner_key:String(current.owner),
          csrf_token:csrf,working_attention_binding:current.binding,agent_id:current.agent,conversation_id:current.cid})});
      const data = await response.json();
      if (!response.ok || data?.ok !== true) throw new Error('Inbox analysis was not accepted.');
      if (!stillCurrent()) return;
      stop();
      void read();
      if (typeof continuity()?.syncConversation === 'function') {
        Promise.resolve(continuity().syncConversation(current.cid)).catch(() => {});
      }
    } catch {
      if (stillCurrent()) analysisFeedback = 'Inbox analysis could not be started. Try again or check Gmail settings.';
    } finally {
      clearTimeout(timeout);
      if (stillCurrent()) {
        analysisRequest = null;
        renderSpecialists();
      } else if (analysisRequest === request) analysisRequest = null;
    }
  }
  function gmailMonitoringDetails(item,firstReview,current) {
    const key = 'gmail-inbox-monitor';
    const details = node('details','','vp3-email-monitor-details');
    details.open = detailOpen.get(key) === true;
    details.addEventListener('toggle',() => detailOpen.set(key,details.open));
    details.append(node('summary','Inbox monitoring details'));
    const actions = node('div','','vp3-agent-activity-actions');
    for (const action of item.actions) actions.append(button(action.label,event => void act(action,current,event)));
    for (const action of firstReview?.actions || []) actions.append(button('View first inbox review',event => void act(action,current,event)));
    appendSpecialistItem(details,gmailMonitorCopy(item),actions,key);
    return details;
  }
  function gmailArrivals(current,monitor) {
    const arrived = monitor?.latest_arrivals;
    const observed = Date.parse(arrived?.at);
    const count = value => Number.isSafeInteger(value) && value >= 0 ? value : 0;
    const total = count(arrived?.total);
    const filtered = count(arrived?.filtered);
    if (!Number.isFinite(observed) || total + filtered === 0) return null;

    const section = node('section','','vp3-email-report vp3-email-arrivals');
    section.setAttribute('aria-label','Latest inbox arrivals');
    section.append(node('h4','Latest inbox arrivals'));
    const details = [
      total ? total + (total === 1 ? ' new message' : ' new messages') : '',
      count(arrived.important) ? arrived.important + ' marked important' : '',
      count(arrived.replies) ? arrived.replies + (arrived.replies === 1 ? ' reply' : ' replies') : '',
      filtered ? filtered + ' filtered by your rules' : '',
    ].filter(Boolean);
    section.append(node('p',details.join(' · ')));
    const age = node('time',new Date(observed).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}));
    age.dateTime = new Date(observed).toISOString();
    age.title = 'When this group of messages was reviewed';
    section.append(age);

    const messages = node('ul');
    for (const item of (Array.isArray(arrived.items) ? arrived.items : []).slice(0,workspace ? 12 : 3)) {
      if (typeof item?.subject !== 'string') continue;
      const subject = item.subject.trim().slice(0,190) || '(No subject)';
      const row = node('li');
      row.append(node('strong',subject));
      if (typeof item.from === 'string' && item.from.trim()) row.append(node('small',item.from.trim().slice(0,190)));
      const open = window.VP3GmailInboxInitialV1?.openArrivedItem;
      if (Number.isSafeInteger(current.cid) && current.cid >= 0 && typeof open === 'function'
        && /^[a-f0-9]{64}$/.test(item.item_ref || '')) {
        const action = button('Open email',event => {
          if (event.isTrusted && visible() && signature(current) === signature(scope()) && open(item.item_ref,current.cid)){
            close(false);
          }
        });
        action.setAttribute('aria-label','Open email '+subject);
        row.append(action);
      }
      messages.append(row);
    }
    if (messages.childElementCount) section.append(messages);
    return section;
  }
  function renderGmailSpecialist(current,inbox,presentation,work) {
    if (!snapshot) return;
    const item = inbox.find(entry => entry.monitor);
    const monitor = item?.monitor;
    const section = node('section','','vp3-agent-activity-specialist');
    section.dataset.activitySpecialist = 'gmail';
    if (work && ['working','queued','checked','ready_to_report','waiting_reply','waiting_retry','interrupted','completed'].includes(work.state)) {
      section.insertAdjacentHTML('beforeend',presentation.specialistHeading('Gmail',work.state,work.progress));
      if (work.detail) section.append(node('p',work.detail,'vp3-agent-activity-detail'));
      const result = specialistResult(work,current);
      if (result) section.append(button(result.label,event => void act(result,current,event)));
    }
    const arrivals = gmailArrivals(current,monitor);
    if (arrivals) section.append(arrivals);
    const reportMarkup = presentation.emailReport?.(current,monitor,workspace);
    if (reportMarkup) {
      section.insertAdjacentHTML('beforeend',reportMarkup);
      if (monitor) section.lastElementChild.addEventListener('click',event => openGmailSource(event,current,monitor));
    }
    const gmailCfg = window.VP3_GMAIL_INBOX_INITIAL_V1;
    const endpoint = localUrl(gmailCfg?.endpoint);
    const authorized = endpoint && gmailCfg.ownerKey === String(current.owner) && gmailCfg.agentId === current.agent && gmailCfg.csrf;
    const settings = item?.actions.find(action => action.kind === 'url' && localUrl(action.href))?.href
      || localUrl(gmailCfg?.connectionsUrl);
    const controls = node('div','','vp3-email-report-controls');
    const mayAnalyze = monitor && !['disabled','setup_required'].includes(monitor.analysis_state)
      && !['unavailable','restart_required'].includes(monitor.state)
      && (monitor.analysis_state || monitor.report?.running_report);
    if (mayAnalyze && authorized && current.cid > 0) {
      const analyze = button(analysisRequest ? 'Analyzing inbox…' : 'Analyze recent inbox',
        event => void analyzeRecentInbox(event,current,endpoint,gmailCfg.csrf));
      analyze.disabled = Boolean(analysisRequest);
      controls.append(analyze);
    } else if (mayAnalyze && authorized && current.cid === 0) {
      controls.append(node('p','Open a saved Agent Chat to analyze recent inbox email.'));
    } else if (settings) {
      const link = node('a','Open Gmail settings');
      link.href = settings;
      controls.append(link);
    }
    if (typeof window.VP3_COGNITIVE_PRESENTATION?.openDiagnostics === 'function') {
      controls.append(button('Diagnostics',event => {
        if (!event.isTrusted || signature(current) !== signature(scope()) || !visible()) return;
        if (window.VP3_COGNITIVE_PRESENTATION.openDiagnostics()) close(false);
      }));
    } else if (settings && !controls.querySelector('a')) {
      const link = node('a','Gmail settings');
      link.href = settings;
      controls.append(link);
    }
    if (analysisFeedback) {
      const feedback = node('p',analysisFeedback);
      feedback.setAttribute('role','status');
      controls.append(feedback);
    }
    if (controls.childElementCount) section.append(controls);
    appendOpenEngagements(section,'gmail',work,current);
    if (monitor && ['checked','scheduled'].includes(monitor.state)) {
      appendSpecialistExample(section,'gmail',work,current);
    }
    specialistsHost.append(section);
  }
  function specialistRows(inbox,{specialistNames,specialistLabels}) {
    const rows = new Map();
    for (const row of Array.isArray(snapshot?.specialists) ? snapshot.specialists.slice(0,24) : []) {
      if (!Object.hasOwn(specialistNames,row?.id) || !Object.hasOwn(specialistLabels,row?.state)) continue;
      const expired = activeSpecialist(row.state) && row.lease_expires_at && !leaseCurrent(row.lease_expires_at);
      rows.set(row.id,{...row,state:expired ? 'interrupted' : row.state,progress:expired ? null : row.progress,entries:[]});
    }
    const monitor = inbox.find(item => item.id === 'gmail:inbox-monitor')?.monitor;
    const review = inbox.find(item => item.id === 'gmail:first-review');
    const recurring = monitor?.state === 'working' && leaseCurrent(monitor.lease_until);
    const reviewing = review?.review_state === 'working' && (!review.review_lease_until || leaseCurrent(review.review_lease_until));
    const analysis = monitor?.analysis_work;
    const analyzing = analysis?.state === 'working' && leaseCurrent(analysis.lease_until);
    if (inbox.length) {
      const overdue = monitor?.overdue_reason === 'claim_expired' ? 'interrupted'
        : monitor?.overdue_reason === 'retry_due' ? 'waiting_retry' : 'queued';
      const states = {scheduled:'scheduled',checked:'idle',working:recurring ? 'working' : 'interrupted',overdue,
        retrying:'waiting_retry',restart_required:'blocked',unavailable:'unavailable'};
      const state = reviewing || recurring || analyzing ? 'working' : analysis?.state === 'queued' ? 'queued'
        : analysis?.state === 'ready' ? 'ready' : states[monitor?.state] ||
        ({needs_start:'waiting_owner',queued:'queued',ready:'ready',failed:'failed'})[review?.review_state] || 'idle';
      const row = rows.get('gmail');
      if (!row || specialistRank(state) < specialistRank(row.state)) {
        rows.set('gmail',{...row,id:'gmail',state,progress:null,entries:[]});
      }
    }
    for (const entry of specialistSnapshot()) {
      const id = family(entry.key);
      if (entry.key === 'gmail-inbox-review' && recurring && !reviewing) continue;
      const state = entry.state === 'running' || !entry.state ? 'working' : entry.state;
      if (!Object.hasOwn(specialistLabels,state)) continue;
      const row = rows.get(id);
      // Arrival state belongs to the exact open Chat. Its fresh read outranks an earlier rail poll.
      if (['location','operator'].includes(id) && row) continue;
      if (!row) rows.set(id,{id,state,progress:entry.progress,entries:entry.presence ? [] : [entry],label:entry.label,
        detail:entry.presence ? entry.detail : ''});
      else {
        if (!entry.presence) row.entries.push(entry);
        if (specialistRank(state) < specialistRank(row.state)) {
          row.state = state;
          row.progress = entry.progress;
          if (entry.presence) row.detail = entry.detail;
        }
      }
    }
    if (!specialistMode && !rows.has('media') && (['ready','unavailable'].includes(snapshot?.youtube_activity?.status)
      || recentListeningItems(1).length)) {
      rows.set('media',{id:'media',state:'checked',progress:null,entries:[]});
    }
    if (coordinationInReview()) rows.delete('brainstorm');
    return rows;
  }
  function renderSpecialists() {
    const current = scope();
    const presentation = window.VP3TopOfMindFeedV1;
    if (!specialistsHost || !opened || connectedSitesView || imageStudioView || profileView || accessRejected || !current || !presentation?.specialistHeading) return;
    const inbox = snapshot?.sections.find(section => section.id === 'gmail_inbox')?.items || [];
    const rows = specialistRows(inbox,presentation);
    const {specialistNames,specialistHeading} = presentation;
    const selected = family(selectedSpecialist);
    const selectedControl = specialistsHost.contains(document.activeElement) ? document.activeElement.dataset : null;
    const reveal = () => {
      if (selectedControl && document.activeElement === document.body) {
        const [attribute,value] = selectedControl.specialistFocus ? ['specialist-focus',selectedControl.specialistFocus]
          : ['specialist-example',selectedControl.specialistExample];
        if (value) specialistsHost.querySelector(`[data-${attribute}="${CSS.escape(value)}"]`)?.focus({preventScroll:true});
      }
      if (!revealSpecialist) return;
      const target = selected === 'brainstorm' && coordinationInReview() ? coordinationHost : specialistsHost.querySelector('.selected');
      if (target) {
        revealSpecialist = false;
        target.scrollIntoView({block:'nearest'});
      }
    };
    specialistsHost.replaceChildren();
    if (specialistMode && selected === 'gmail') {
      renderGmailSpecialist(current,inbox,presentation,rows.get('gmail'));
      reveal();
      return;
    }
    if (!rows.size) { reveal(); return; }
    if (!specialistMode) specialistsHost.append(node('h3','Specialists'));
    const ids = specialistMode ? [selected] : new Set([...Object.keys(specialistNames),...rows.keys()]);
    for (const id of ids) {
      const row = rows.get(id);
      if (!row) continue;
      const name = Object.hasOwn(specialistNames,id) ? specialistNames[id] : row.label || 'Specialist';
      const section = node('section','','vp3-agent-activity-specialist');
      section.dataset.activitySpecialist = id;
      section.classList.toggle('selected',!specialistMode && id === selected);
      section.setAttribute('aria-label',name);
      section.insertAdjacentHTML('afterbegin',specialistHeading(name,row.state,row.progress));
      const reportLabels = {profile:'Open Profile report',curiosity:'Open Curiosity report',media:'Open Media report',
        'connected-sites':'Open Connected Sites report',marketing:'Open Marketing report'};
      if (Object.hasOwn(reportLabels,id) && !specialistMode && current.hasChat) {
        const actions = node('div','','vp3-agent-activity-actions');
        const launch = button(reportLabels[id],event => {
          if (!event.isTrusted || signature(current) !== signature(scope())) return;
          openSpecialist(id,launch,{label:name,focusReturn:document.getElementById('chatInput'),fullReport:true,
            ...(id==='profile'?{selection:{tab:'reports'}}:{})});
        });
        launch.dataset.specialistFocus = id + ':report';
        actions.append(launch);
        section.append(actions);
      }
      if (id === 'media' && !specialistMode) {
        const queue = searchHistoryPlaylist(current,4);
        if (queue) section.append(queue);
      }
      const result = specialistResult(row,current);
      const resultKey = officeChatKey(result);
      const reportItems = specialistMode && id === 'tasks' && !officeList ? officeReviewItems() : [];
      const resultInReport = resultKey && (reportItems.some(item => item.actions.some(action => officeChatKey(action) === resultKey))
        || id === 'tasks' && attachedOfficeChatKeys(current).has(resultKey));
      if (id !== 'gmail' || !inbox.length) {
        const detail = typeof row.detail === 'string' ? row.detail.slice(0,1200) : '';
        const handoff = presentation.handoffLabel?.(row.handoff);
        if (detail || handoff || (result && !resultInReport)) {
          const copy = node('div','','vp3-agent-activity-copy');
          if (detail) copy.append(itemDetail(detail,'specialist:'+id+':status',name));
          if (handoff) copy.append(node('small',handoff,'vp3-agent-activity-detail'));
          const actions = node('div','','vp3-agent-activity-actions');
          if (result && !resultInReport) actions.append(button(result.label,event => void act(result,current,event)));
          appendSpecialistItem(section,copy,actions);
        }
      }
      if (id === 'transcripts') {
        for (const report of presentation.transcriptReportRows(row)) {
          const copy = itemCopy(report.title,report.summary,'transcript:'+report.id,true);
          for (const [label,value] of [['Next step ',report.nextStep],['Question for you ',report.question]]) {
            if (!value) continue;
            const line = node('p');
            line.append(node('b',label),value);
            copy.append(line);
          }
          const source = node('a','Open source finding');
          source.href = report.href;
          source.dataset.specialistFocus = 'transcript:'+report.id;
          const actions = node('div','','vp3-agent-activity-actions');
          if (current.hasChat && typeof window.STONEFELLOW_TRANSCRIPTION_CANVAS?.open === 'function') {
            actions.append(button('Open saved report',event => openSavedTranscription(current,event,report.id)));
          }
          actions.append(source);
          appendSpecialistItem(section,copy,actions);
        }
      }
      const monitor = id === 'gmail' ? inbox.find(item => item.monitor) : null;
      const firstReview = monitor ? inbox.find(item => item.id === 'gmail:first-review' && item.review_state === 'ready') : null;
      for (const item of id === 'gmail' ? inbox : []) {
        const key = item.monitor ? 'gmail-inbox-monitor' : 'gmail-inbox-review';
        if (item === firstReview || (!item.monitor && row.entries.some(entry => entry.key === key))) continue;
        if (item.monitor) {
          section.append(gmailMonitoringDetails(item,firstReview,current));
          continue;
        }
        const actions = node('div','','vp3-agent-activity-actions');
        for (const action of item.actions) actions.append(button(action.label,event => void act(action,current,event)));
        const expired = item.review_state === 'working' && item.review_lease_until && !leaseCurrent(item.review_lease_until);
        const detail = expired ? 'Waiting for the next verified update.' : item.detail;
        appendSpecialistItem(section,itemCopy(item.title,detail,item.id),actions,key);
      }
      const entries = specialistMode && !workspace
        ? [row.entries.find(entry => entry.key === selectedSpecialist) || row.entries[0]].filter(Boolean) : row.entries;
      for (const entry of entries) {
        const title = [entry.label === name || specialistMode && entry.label === selectedLabel ? '' : entry.label,entry.title].filter(Boolean).join(' · ');
        const entryStatus = specialistMode && entry.status === presentation.specialistLabels[row.state] ? '' : entry.status || '';
        const copy = itemCopy(title,entryStatus,'specialist:'+entry.key+':status');
        if (entry.detail) copy.append(itemDetail(entry.detail,'specialist:'+entry.key+':detail',entry.label || name));
        const actions = node('div','','vp3-agent-activity-actions');
        if (entry.canView === true && entry.key !== 'brainstorm' && (specialistMode || entry.key !== 'connected-sites')) actions.append(button(entry.viewLabel || 'View in Chat',event => {
          if (!event.isTrusted || signature(current) !== signature(scope())) return;
          const viewed = specialistMode ? window.VP3ChatSpecialists?.view?.(entry.key)
            : window.VP3AgentOrb?.viewActivitySpecialist?.(entry.key) ?? window.VP3ChatSpecialists?.view?.(entry.key);
          if (viewed !== false) close(false);
        }));
        appendSpecialistItem(section,copy,actions,entry.key === id ? '' : entry.key);
      }
      if (id !== 'curiosity' || !specialistMode) appendOpenEngagements(section,id,row,current);
      if (id === 'media' && !specialistMode) {
        const media = mediaHistory(current,3);
        if (media.length) {
          const details = node('details','','vp3-agent-activity-media-history');
          const detailKey = 'media:youtube-history';
          details.open = detailOpen.get(detailKey) ?? true;
          details.addEventListener('toggle',() => detailOpen.set(detailKey,details.open));
          details.append(node('summary','Recent media activity'),...media);
          section.append(details);
        }
      }
      appendSpecialistExample(section,id,row,current);
      specialistsHost.append(section);
    }
    reveal();
  }
  function renderCoordination() {
    coordinationHost.replaceChildren();
    if (specialistMode && family(selectedSpecialist) !== 'brainstorm') return;
    coordinationHost.classList.toggle('selected',family(selectedSpecialist) === 'brainstorm' && coordinationInReview());
    const coordination = snapshot?.coordination;
    const coordinationLabels = window.VP3TopOfMindFeedV1?.coordinationLabels;
    if (!coordinationLabels || !Object.hasOwn(coordinationLabels,coordination?.phase)) return;
    const expired = coordination.lease_expires_at && !leaseCurrent(coordination.lease_expires_at);
    const phase = expired ? 'waiting' : coordination.phase;
    const detail = expired ? 'Waiting for the next verified update.' : coordination.detail;
    coordinationHost.dataset.phase = phase;
    coordinationHost.append(node('strong',specialistMode ? 'Current assessment' : 'ob1 coordination'),node('span',coordinationLabels[phase]));
    if (typeof detail === 'string' && detail) coordinationHost.append(node('small',detail.slice(0,400)));
    if (!expired && (specialistMode || !snapshot.summary?.overview) && typeof coordination.finding === 'string' && coordination.finding) {
      coordinationHost.append(node('small',coordination.finding.slice(0,500)));
    }
  }
  function routingDescription(route,withSpecialist = false) {
    const names = window.VP3TopOfMindFeedV1?.specialistNames || {};
    if (!Object.hasOwn(names,route?.specialist) || !Object.hasOwn(ruleActionLabels,route.action) ||
      typeof route.trigger !== 'string' || !ruleKey.test(route.trigger)) return '';
    const fallback = route.trigger[0].toUpperCase() + route.trigger.slice(1).replace(/[_-]/g,' ');
    const trigger = typeof route.trigger_label === 'string' && route.trigger_label.trim() && route.trigger_label.length <= 120
      ? route.trigger_label : orbSurface?.rules?.label(route.specialist,route.trigger) || fallback;
    return (withSpecialist ? names[route.specialist] + ' · ' : '') + trigger + ' → ' + ruleActionLabels[route.action];
  }
  function renderSummary() {
    if (!summaryBody) return;
    summaryBody.parentElement.hidden = !snapshot;
    const summary = snapshot?.summary;
    const overview = typeof summary?.overview === 'string' ? summary.overview.trim().slice(0,600) : '';
    const findings = Array.isArray(snapshot?.coordination_findings) ? snapshot.coordination_findings : [];
    const primary = findings.filter(item => item?.routing?.specialist !== 'casual');
    const finding = ['needs_owner','agent_work','resolved','watching'].map(state =>
      (primary.length ? primary : findings).find(item => item?.kind === 'coordination_finding' && item.state === state)).find(Boolean);
    const focus = typeof finding?.title === 'string' ? finding.title.trim().slice(0,190) : '';
    const next = typeof finding?.next_step === 'string' ? finding.next_step.trim().slice(0,320) : '';
    const names = window.VP3TopOfMindFeedV1?.specialistNames || {};
    const updates = new Map();
    for (const row of Array.isArray(summary?.updates) ? summary.updates.slice(0,10) : []) {
      const id = row?.specialist_id;
      if (typeof id !== 'string' || !Object.hasOwn(names,id) || updates.has(id) || typeof row.title !== 'string' || !row.title.trim()) continue;
      const title = row.title.trim().slice(0,250);
      const detail = typeof row.detail === 'string' ? row.detail.trim().slice(0,400) : '';
      const nextStep = typeof row.next_step === 'string' ? row.next_step.trim().slice(0,240) : '';
      const routing = row.routing?.specialist === id ? routingDescription(row.routing) : '';
      updates.set(id,{title,detail:detail !== title && detail !== overview ? detail : '',
        nextStep:nextStep !== next && nextStep !== detail && nextStep !== title ? nextStep : '',routing});
    }
    const checks = new Map();
    const labels = window.VP3TopOfMindFeedV1?.specialistLabels || {};
    for (const row of Array.isArray(summary?.checks) ? summary.checks.slice(0,13) : []) {
      const id = row?.specialist_id;
      if (!Object.hasOwn(names,id) || updates.has(id) || checks.has(id) || !Object.hasOwn(labels,row?.state)) continue;
      checks.set(id,{state:labels[row.state],detail:typeof row.detail === 'string' ? row.detail.slice(0,240) : ''});
    }
    const briefings = Array.isArray(summary?.briefings) ? summary.briefings.filter(report =>
      ['profile','media','marketing'].includes(report?.specialist_id) &&
      typeof report.title === 'string' && report.title.length <= 160 &&
      report.sections && typeof report.sections === 'object' &&
      typeof report.sections.next_step === 'string').slice(0,3) : [];
    const version = JSON.stringify([focus,overview,next,[...updates],[...checks],briefings,Boolean(snapshot)]);
    if (version === renderedSummary) return;
    renderedSummary = version;
    const content = [];
    if (focus) content.push(node('h4',focus,'vp3-agent-activity-focus'));
    if (overview) content.push(node('p',overview,'vp3-agent-activity-overview'));
    if (next) {
      const line = node('p','','vp3-agent-activity-summary-next');
      line.append(node('b','Next step '),next);
      content.push(line);
    }
    if (updates.size) {
      const list = node('ul','','vp3-agent-activity-summary-updates');
      list.setAttribute('role','list');
      list.setAttribute('aria-label','Specialist updates');
      for (const [id,{title,detail,nextStep,routing}] of updates) {
        const item = node('li');
        item.append(node('span',names[id],'vp3-agent-activity-summary-source'),node('strong',title));
        if (detail) item.append(node('p',detail));
        if (nextStep) {
          const line = node('p');
          line.append(node('b','Next step '),nextStep);
          item.append(line);
        }
        if (routing) item.append(node('small',routing,'vp3-agent-activity-rule-match'));
        list.append(item);
      }
      content.push(list);
    }
    if (briefings.length) {
      const details = node('details','','vp3-agent-specialist-briefings');
      const key = 'ob1-specialist-briefings';
      details.open = detailOpen.get(key) === true;
      details.addEventListener('toggle',() => detailOpen.set(key,details.open));
      details.append(node('summary','Verified specialist briefings (' + briefings.length + ')'));
      const sections = {key_findings:'Key findings',actions_followups:'Actions & follow-ups',
        open_questions:'Open questions',next_step:'Next step',questions_for_you:'Questions for you'};
      for (const report of briefings) {
        const article = node('article','','vp3-agent-specialist-briefing');
        const title = node('strong',(names[report.specialist_id] || report.specialist_id) + ' · ' + report.title);
        article.append(title);
        if (report.source_coverage === 'limited') article.append(node('small','Some sources could not be verified.'));
        if (report.presentation_owner === 'profile_native') article.append(node('small','Existing Profile notifications own this visitor update.'));
        // A report is only a proposal until its exact Chat/reply/executor
        // receipts have been checked. This surface never executes approval.
        const lifecycleLabels = {
          posted:'Report posted in Chat',
          awaiting_owner:'Waiting for your reply in Chat',
          owner_replied:'You replied · follow-through is not yet verified',
          preparation_queued:'Private preparation queued · completion not verified',
          preparation_verified:'Private preparation verified · not sent or published',
          approved_action_verified:'Approved private action completed with receipt',
          preparation_failed:'Private preparation failed · review in Chat',
          deferred:'Deferred at your request',
          declined:'You declined this suggestion',
          owner_marked_resolved:'You marked this resolved · not an executor completion'
        };
        const receipt = report.engagement;
        const activeScope = scope();
        const receiptHref = typeof receipt?.chat_url === 'string' && Object.hasOwn(lifecycleLabels,receipt?.state)
          && Number.isSafeInteger(receipt?.message_id) && receipt.message_id > 0
          && Number.isSafeInteger(receipt?.conversation_id) && receipt.conversation_id === activeScope?.cid
          && Number.isSafeInteger(receipt?.run_id) && receipt.run_id > 0
          ? localUrl(receipt.chat_url) : null;
        if (receiptHref) {
          const status = node('div','','vp3-agent-specialist-engagement');
          status.append(node('small',lifecycleLabels[receipt.state]));
          const link = node('a',receipt.state === 'awaiting_owner'?'Reply in Chat':'View Chat and outcome');
          link.href = receiptHref;
          link.setAttribute('data-vp3-specialist-message-id',String(receipt.message_id));
          status.append(link);
          article.append(status);
        }
        for (const [field,label] of Object.entries(sections)) {
          const raw = report.sections[field];
          const lines = (Array.isArray(raw) ? raw : typeof raw === 'string' ? [raw] : [])
            .filter(text => typeof text === 'string' && text.trim()).slice(0,4);
          if (!lines.length) continue;
          const group = node('div','','vp3-agent-specialist-briefing-section');
          group.append(node('b',label));
          for (const line of lines) group.append(node('p',line.slice(0,500)));
          article.append(group);
        }
        const href = localUrl(report.source_url);
        if (href) { const a = node('a','Review verified source'); a.href = href; article.append(a); }
        details.append(article);
      }
      content.push(details);
    }
    if (!content.length && snapshot) content.push(node('p','No new specialist findings to summarize.','vp3-agent-activity-summary-empty'));
    if (checks.size) {
      const details = node('details','','vp3-agent-activity-detail');
      const key = 'ob1-specialist-checks';
      details.open = detailOpen.get(key) === true;
      details.addEventListener('toggle',() => detailOpen.set(key,details.open));
      const heading = node('summary','Other specialist checks');
      const list = node('ul','','vp3-agent-activity-summary-updates');
      list.setAttribute('role','list');
      for (const [id,{state,detail}] of checks) {
        const item = node('li');
        item.append(node('span',names[id],'vp3-agent-activity-summary-source'),node('strong',state));
        if (detail) item.append(node('p',detail));
        list.append(item);
      }
      details.append(heading,list);
      content.push(details);
    }
    summaryBody.replaceChildren(...content);
  }
  function validState(data,current) {
    return data?.contract === 'vp3.agent.activity.v1' && data.owner_user_id === current.owner && data.agent_id === current.agent &&
      data.conversation_id === current.cid && data.working_attention_binding === current.binding &&
      typeof data.observed_at === 'string' && Number.isFinite(Date.parse(data.observed_at)) && Array.isArray(data.sections) &&
      data.sections.length <= 13 && data.sections.every(section => typeof section.id === 'string' && typeof section.label === 'string' &&
        Array.isArray(section.items) && section.items.length <= 12 && section.items.every(item => typeof item.id === 'string' &&
          typeof item.title === 'string' && item.title.length <= 500 && (item.detail === undefined || typeof item.detail === 'string' && item.detail.length <= 1600) &&
          (!['office_reviews','marketing'].includes(section.id) || ['next_step','followup_question'].every(field => item[field] === undefined ||
            typeof item[field] === 'string' && item[field].length <= 1000)) &&
          (item.monitor === undefined || section.id === 'gmail_inbox' && item.id === 'gmail:inbox-monitor' && Object.hasOwn(gmailMonitorLabels,item.monitor?.state)) &&
          Array.isArray(item.actions) && item.actions.length <= 4 && item.actions.every(action => kinds.has(action?.kind) &&
            typeof action.label === 'string' && action.label.length <= 120 && (action.kind !== 'list_inventory' || positive(action.list_id)))));
  }
  async function act(action,current,event) {
    if (!event.isTrusted || !visible() || signature(current) !== signature(scope()) || !snapshot) return;
    const observed = Date.parse(officeList?.data?.observed_at || snapshot.observed_at);
    if (Date.now() - observed > 60000) { status.textContent = 'Refreshing this view before continuing…'; stop(); void read(); return; }
    const intent = generation;
    const fallback = localUrl(action.href);
    const chat = continuity();
    try {
      if (action.kind === 'stoneward_history') {
        const source = stonewardHistoryUrl(action.href);
        if (source) window.open(source,'_blank','noopener,noreferrer');
        return;
      }
      if (['chat','youtube'].includes(action.kind) && Number.isSafeInteger(action.agent_id)
        && action.agent_id >= 0 && action.agent_id !== current.agent) {
        if (fallback) location.assign(fallback);
        return;
      }
      if (action.kind === 'list_inventory' && officeWorkspace()) {
        selectOfficeList(action.list_id);
        return;
      }
      if (action.kind === 'task_notes' && window.VP3TaskNotes?.open) {
        if (specialistMode) close(false);
        if (positive(action.list_id)) window.VP3TaskNotes.open({kind:'task_list',id:action.list_id});
        else {
          const timezone = reminderZone(action.timezone);
          window.VP3TaskNotes.open({kind:'reminders',view:['open','today','overdue','all'].includes(action.view) ? action.view : 'open',
            ...(timezone ? {timezone} : {})});
        }
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
  function officeChatKey(action) {
    return action?.kind === 'chat' && positive(action.conversation_id) && positive(action.message_id)
      ? `${action.conversation_id}:${action.message_id}` : '';
  }
  function officeEntryChatAction(report,current,engagement = officeEntryEngagement(report,current)) {
    if (engagement?.action) return engagement.action;
    const action = report?.chat_action;
    if (!officeChatKey(action) || typeof action.href !== 'string') return null;
    return specialistResult({...action,result_url:action.href},current);
  }
  function officeEntryEngagement(report,current) {
    return report?.state === 'ready' ? findingEngagement(report.engagement,current) : null;
  }
  function findingEngagement(entry,current,states = findingEngagementStates) {
    if (!Object.hasOwn(states,entry?.state)
      || !positive(entry.message_id)) return null;
    if (entry.href === null && entry.state !== 'open') return {state:entry.state,action:null};
    if (typeof entry.href !== 'string') return null;
    const action = specialistResult({conversation_id:current.cid,message_id:entry.message_id,result_url:entry.href},current);
    return action ? {state:entry.state,action} : null;
  }
  function appendOfficeEntryReport(copy,item,current) {
    const report = item.office_report;
    if (!Object.hasOwn(officeReportStates,report?.state)) return;
    const engagement = officeEntryEngagement(report,current);
    const body = node('section','','vp3-office-entry-report');
    body.setAttribute('aria-label','Report for ' + item.title);
    body.append(node('h5','Analysis'));
    const summary = typeof report.summary === 'string' && report.state === 'ready' ? report.summary.slice(0,1600) : '';
    body.append(node('p',summary || officeReportStates[report.state]));
    if (report.state === 'ready') {
      if (report.limited === true) body.append(node('small','Analysis uses selected saved details.'));
      const questionLabel = engagement && engagement.state !== 'open' ? 'Original question' : 'Question for you';
      for (const [field,label] of [['next_step','Next step'],['followup_question',questionLabel]]) {
        if (typeof report[field] !== 'string' || !report[field]) continue;
        const line = node('p');
        line.append(node('strong',label + ': '),report[field].slice(0,1000));
        body.append(line);
      }
      if (engagement) body.append(node('p',findingEngagementStates[engagement.state]));
      const plan = Array.isArray(report.action_plan) ? report.action_plan.filter(step => typeof step === 'string' && step).slice(0,12) : [];
      if (plan.length) {
        const steps = node('ol');
        for (const step of plan) steps.append(node('li',step.slice(0,1000)));
        body.append(node('h5','Action plan'),steps);
      }
    }
    const task = report.agent_task;
    if (typeof task?.title === 'string' && task.title && Object.hasOwn(officeAgentTaskStates,task.state)) {
      body.append(node('h5','Agent Task'));
      const taskLine = node('p');
      taskLine.append(node('strong',task.title.slice(0,500)),' · ' + officeAgentTaskStates[task.state]);
      body.append(taskLine);
      if (typeof task.result === 'string' && task.result) body.append(node('p',task.result.slice(0,1600)));
    }
    const action = officeEntryChatAction(report,current,engagement);
    if (action) body.append(button(engagement?.action ? 'View question in Chat' : action.label,event => void act(action,current,event)));
    copy.append(body);
  }
  function renderSource(current,source,expanded = false) {
    const subsection = node('section','','vp3-agent-activity-source');
    if (source.label) subsection.append(node('h4',source.label));
    const inventory = officeWorkspace() && Object.hasOwn(officeCollections,source.id);
    const planning = family(selectedSpecialist) === 'planning' && workspaceSources.planning.includes(source.id);
    const media = source.id === 'media_report' || source.id === 'media_offers';
    const canvasSuggestions = source.id === 'canvas_suggestions';
    const states = media ? mediaEngagementStates : findingEngagementStates;
    for (const item of source.items) {
      const copy = itemCopy(item.title,item.detail,source.id + ':' + item.id,expanded);
      const handoff = window.VP3TopOfMindFeedV1?.handoffLabel?.(item.handoff);
      if (handoff) copy.append(node('small',handoff,'vp3-agent-activity-detail'));
      if (canvasSuggestions) {
        const names = window.VP3TopOfMindFeedV1?.specialistNames || {};
        const titles = (Array.isArray(item.sources) ? item.sources : []).slice(0,3).flatMap(source => {
          if (typeof source?.title !== 'string' || !source.title.trim()) return [];
          const familyName = typeof source.specialist === 'string' && Object.hasOwn(names,source.specialist) ? names[source.specialist] : '';
          return [(familyName ? familyName + ' · ' : '') + source.title.slice(0,190)];
        });
        if (Object.hasOwn(canvasSuggestionStates,item.state)) copy.append(node('small',canvasSuggestionStates[item.state],'vp3-agent-activity-detail'));
        if (titles.length) copy.append(node('small',titles.join('  |  '),'vp3-agent-activity-detail'));
      }
      const engagement = planning || media ? findingEngagement(item.engagement,current,states) : null;
      const replied = engagement && engagement.state !== 'open';
      if (planning || media || ['office_reviews','appointments','marketing'].includes(source.id)) {
        const questionLabel = replied ? 'Original question' : source.id === 'office_reviews' ? 'Suggested question' : 'Question for you';
        for (const [field,label] of [['next_step',replied ? 'Suggested next step' : 'Next step'],['followup_question',questionLabel]]) {
          if (!item[field]) continue;
          const line = node('p','','vp3-agent-activity-detail');
          line.append(node('strong',label + ': '),item[field]);
          copy.append(line);
        }
        if (engagement) copy.append(node('p',states[engagement.state],'vp3-agent-activity-detail'));
      } else if (inventory) {
        appendOfficeEntryReport(copy,item,current);
      }
      const actions = node('div','','vp3-agent-activity-actions');
      for (const action of item.actions) actions.append(button(action.label,event => void act(action,current,event)));
      if (engagement?.action) actions.append(button(media ? 'View offer in Chat' : 'View question in Chat',event => void act(engagement.action,current,event)));
      const row = appendSpecialistItem(subsection,copy,actions);
      if (inventory) row.dataset.officeItemId = item.id;
    }
    if (expanded && ['calendar','appointments'].includes(source.id) && source.items.length === 0) {
      const label = source.id === 'calendar' ? 'Upcoming events' : 'Appointments';
      const message = ['unavailable','forbidden'].includes(snapshot?.coverage?.[source.id])
        ? label + ' could not be loaded.' : 'No ' + label.toLowerCase() + ' to show.';
      subsection.append(node('p',message));
    }
    if (source.id === 'marketing' && source.items.length === 0) {
      const coverage = snapshot?.coverage?.marketing;
      subsection.append(node('p',coverage === 'unconfigured' ? 'No authorized campaign sources are configured.'
        : ['unavailable','forbidden'].includes(coverage) ? 'Campaign recommendations could not be verified.'
        : 'No current campaign recommendations.'));
    }
    if (canvasSuggestions && source.items.length === 0) {
      const coverage = snapshot?.coverage?.[source.id];
      subsection.append(node('p',coverage === 'disabled' ? 'App Suggestions are available in the Chat where Initiative is on.'
        : ['unavailable','forbidden','limited'].includes(coverage) ? 'Current suggestions could not be verified.' : 'No current app suggestions.'));
    }
    return subsection;
  }
  function stonewardHistoryUrl(value) {
    if (typeof value !== 'string' || !/^https:\/\/[a-z0-9.-]{1,253}\/\?view=history$/i.test(value)) return null;
    try { return new URL(value).href; } catch { return null; }
  }
  function mediaReportItems() {
    const findings = snapshot?.media_report?.findings;
    if (!Array.isArray(findings)) return [];
    const shortText = (value,limit) => typeof value === 'string' ? value.trim().slice(0,limit) : '';
    return findings.slice(0,12).flatMap(item => {
      const title = shortText(item?.title,190);
      const summary = shortText(item?.summary,600);
      if (typeof item?.id !== 'string' || !item.id || !title || !summary) return [];
      const actions = Array.isArray(item.actions) ? item.actions.slice(0,4).filter(action => {
        if (['stoneward_track','stoneward_resume','stoneward_played'].includes(item.kind) && action?.kind === 'stoneward_history') {
          return Boolean(shortText(action.label,120) && action.label.length <= 120 && stonewardHistoryUrl(action.href));
        }
        if (!['url','chat','youtube','song'].includes(action?.kind) ||
          !shortText(action.label,120) || action.label.length > 120 || !localUrl(action.href)) return false;
        if (action.kind === 'song') return positive(action.track_id);
        return action.kind === 'url' || positive(action.conversation_id) && positive(action.message_id);
      }) : [];
      return [{id:item.id.slice(0,190),title,detail:summary,next_step:shortText(item.next_step,320),
        followup_question:shortText(item.question,320),engagement:item.engagement,actions}];
    });
  }
  function recentMediaOfferItems(current,limit) {
    const offers = snapshot?.media_report?.recent_offers;
    if (!Array.isArray(offers)) return [];
    return offers.slice(0,limit).flatMap(offer => {
      const title = typeof offer?.title === 'string' ? offer.title.trim().slice(0,190) : '';
      if (!title || !findingEngagement(offer,current,mediaEngagementStates)) return [];
      return [{id:'media-offer:'+offer.message_id,title,detail:'',engagement:offer,actions:[]}];
    });
  }
  function recentListeningItems(limit) {
    const listening = snapshot?.media_report?.recent_listening ?? snapshot?.recent_listening;
    if (!Array.isArray(listening)) return [];
    return listening.slice(0,limit).flatMap(item => {
      if (typeof item?.id !== 'string' || typeof item.title !== 'string' || !item.title || item.title.length > 200
        || typeof item.detail !== 'string' || item.detail.length > 420 || !Array.isArray(item.actions)) return [];
      const actions = item.actions.slice(0,2).filter(action => {
        if (typeof action?.label !== 'string' || !action.label || action.label.length > 120) return false;
        if (action.kind === 'stoneward_history') return Boolean(stonewardHistoryUrl(action.href));
        return ['url','song'].includes(action.kind) && localUrl(action.href) && (action.kind !== 'song' || positive(action.track_id));
      });
      return [{id:item.id.slice(0,190),title:item.title,detail:item.detail,actions}];
    });
  }
  function youtubeSourceAction(action) {
    return action?.kind === 'chat' && typeof action.label === 'string' && action.label.length <= 120
      && Number.isSafeInteger(action.agent_id) && action.agent_id >= 0
      && positive(action.conversation_id) && positive(action.message_id) && localUrl(action.href);
  }
  function youtubeActivitySources(limit,searchLimit = limit) {
    const history = snapshot?.youtube_activity;
    if (history?.status !== 'ready') return [];
    return [['searches','Recent YouTube searches'],['played','Played videos']].map(([id,label]) => ({
      id:'youtube-'+id,label,
      items:(Array.isArray(history[id]) ? history[id] : []).filter(item =>
        typeof item?.id === 'string' && typeof item.title === 'string' && item.title.length <= 200
        && typeof item.detail === 'string' && item.detail.length <= 200 && Array.isArray(item.actions)
        && item.actions.length === 1 && youtubeSourceAction(item.actions[0])).slice(0,id === 'searches' ? searchLimit : limit)
    }));
  }
  function searchHistoryPlaylist(current,limit) {
    const activity = snapshot?.youtube_activity;
    if (!['ready','unavailable'].includes(activity?.status)) return null;
    const title = 'YouTube search history playlist';
    const section = node('section','','vp3-agent-activity-source vp3-youtube-history-playlist');
    section.setAttribute('aria-label',title);
    section.append(node('h4',title));
    const entries = window.VP3TopOfMindFeedV1?.youtubePlaylistEntries?.(activity) || [];
    if (!entries.length) {
      const explanation=window.VP3TopOfMindFeedV1?.youtubePlaylistEmptyText?.(activity);
      if(explanation) section.append(node('p',explanation));
      return section;
    }
    const list = node('ol');
    for (const entry of entries.slice(0,limit)) {
      const row = node('li');
      const play = node('a','Play on YouTube');
      play.href = entry.watch_url;
      play.target = '_blank';
      play.rel = 'noopener noreferrer';
      play.dataset.specialistFocus = 'media:watch:'+entry.video_id;
      const canOpen = event => {
        if (!event.isTrusted || !visible() || signature(current) !== signature(scope())
          || entry.expires_at <= Date.now()/1000) {
          event.preventDefault();
          return false;
        }
        return true;
      };
      play.addEventListener('click',canOpen);
      const open = button('Open in Chat',event => {
        if (canOpen(event)) void act(entry.action,current,event);
      });
      open.dataset.specialistFocus = 'media:source:'+entry.video_id;
      const actions = node('div','','vp3-agent-activity-actions');
      actions.append(open,play);
      row.append(node('strong',entry.title));
      if (entry.channel) row.append(node('small',entry.channel));
      row.append(actions);
      list.append(row);
    }
    section.append(list);
    return section;
  }
  function mediaHistory(current,limit,searchLimit = limit) {
    const output = [];
    for (const source of youtubeActivitySources(limit,searchLimit)) {
      if (source.items.length) output.push(renderSource(current,source,true));
    }
    const listening = recentListeningItems(limit);
    if (listening.length) output.push(renderSource(current,{id:'recent_listening',label:'Recent listening',items:listening},true));
    return output;
  }
  function appendMediaDelivery(report,current,shownItems) {
    const delivery=snapshot?.media_delivery;
    if (!delivery) return;
    const handoff=delivery.handoff;
    const reasons={
      no_chat:'An open saved Chat is needed for proactive posts.', starting:'Connecting this Chat to the Agent loop.',
      linked_elsewhere:'The Agent is following another Chat.', initiative_paused:'Initiative is paused.',
      checking:'Waiting for the first Media check in this Chat.', disabled:'Media check-ins are off in settings.',
      quiet_chat_required:'Waiting for quiet Chat.', playing:'Waiting for playback to finish.',
      idle_spacing:'Waiting for the current quiet period.', new_activity_required:'Waiting for new activity after the previous offer.',
      owner_turn_pending:'Waiting for the current Chat turn to finish.', recent_proactive_post:'Waiting after a recent Agent post.',
      no_playable_sources:'No playable pick is available right now.', handoff_pending:'A Media offer is in the Chat queue.',
      handoff_stopped:'The last offer stopped. The next eligible check can try again.',
      ready:'A Media offer is eligible for Chat.', cooldown:'Waiting after a recent Media offer.',
      already_offered:'The current pick has already been offered.', report_only:'These findings are available in the Media report.',
      result_unavailable:'The saved offer could not be verified.', verification_unavailable:'Media delivery could not be verified.',
      assignment_failed:'The last Media offer failed before delivery.', assignment_cancelled:'The last Media offer was canceled.',
    };
    const posted=handoff?.state==='posted';
    if (posted && shownItems.some(item => item.engagement?.message_id === handoff.message_id &&
      findingEngagement(item.engagement,current,mediaEngagementStates))) return;
    const queued=['queued','working'].includes(handoff?.state);
    const gate=handoff?.state==='waiting' ? handoff.reason : delivery.gate;
    const message=posted?'The latest offer was posted in this Chat.':queued?'A Media offer is in the Chat queue.':
      Object.hasOwn(reasons,gate)?reasons[gate]:'';
    if (message) report.append(node('p',message));
    if (posted) {
      const action=handoff.action;
      if (action?.kind==='chat'&&action.agent_id===current.agent&&action.conversation_id===current.cid&&
        positive(action.message_id)&&localUrl(action.href)) {
        report.append(button('View latest offer in Chat',event=>void act(action,current,event)));
      }
    }
  }
  function renderMediaWorkspace(current) {
    if (!snapshot) { sections.replaceChildren(); return; }
    const items = mediaReportItems().slice(0,workspace ? 12 : 1);
    const offers = recentMediaOfferItems(current,workspace ? 6 : 1);
    const report = renderSource(current,{id:'media_report',label:'Listening & viewing report',items},true);
    const coverage = snapshot.coverage?.media_report || snapshot.media_report?.coverage;
    if (!items.length && ['unavailable','forbidden'].includes(coverage)) {
      report.append(node('p','The media report could not be loaded.'));
    } else if (!items.length && !offers.length && !snapshot.media_delivery) {
      report.append(node('p','No media suggestions yet. Recent plays and YouTube searches can inform this report.'));
    }
    appendMediaDelivery(report,current,[...items,...offers]);
    const queue = searchHistoryPlaylist(current,workspace ? 18 : 4);
    const nodes = queue ? [queue,report] : [report];
    if (offers.length) nodes.push(renderSource(current,{id:'media_offers',label:'Recent offers',items:offers},true));
    nodes.push(...(workspace ? mediaHistory(current,8,12) : mediaHistory(current,1)));
    if (workspace) {
      const controls = node('div','','vp3-agent-activity-actions vp3-agent-media-controls');
      controls.append(button('Open Player',event => {
        if (!event.isTrusted || !visible() || signature(current) !== signature(scope())) return;
        const chat = continuity();
        if (typeof chat?.showPlayerView !== 'function') return;
        close(false);
        chat.showPlayerView();
      }));
      if (typeof continuity()?.showPlayerView === 'function') nodes.push(controls);
      for (const [id,label] of [['songs','Recent listening'],['youtube','YouTube searches & playback']]) {
        if (id === 'youtube' && snapshot.youtube_activity) continue;
        if (id === 'songs' && Array.isArray(snapshot.media_report?.recent_listening)) continue;
        const source = snapshot.sections.find(section => section.id === id);
        if (source?.items.length) nodes.push(renderSource(current,{...source,label},true));
      }
    }
    sections.replaceChildren(...nodes);
  }
  const curiosityText = (value,limit) => typeof value === 'string' ? value.trim().slice(0,limit) : '';
  const curiosityStates = {open:'Open',answered:'Answered',skipped:'Skipped',deferred:'Deferred'};
  function curiositySources(rows,current,focusKey) {
    const actions = node('div','','vp3-agent-activity-actions');
    const seen = new Set();
    for (const row of Array.isArray(rows) ? rows.slice(0,4) : []) {
      if (!positive(row?.conversation_id) || !positive(row?.message_id)) continue;
      const action = specialistResult({...row,result_url:row.href},current);
      const key = officeChatKey(action);
      if (!key || seen.has(key)) continue;
      seen.add(key);
      const link = button(curiosityText(row.label,120) || 'View in Chat',event => void act(action,current,event));
      link.dataset.curiosityFocus = focusKey + ':' + key;
      actions.append(link);
    }
    return actions;
  }
  function curiositySection(title) {
    const section = node('section','','vp3-agent-activity-source vp3-curiosity-section');
    section.setAttribute('aria-label',title);
    section.append(node('h4',title));
    return section;
  }
  function curiosityQuestionLinks(question,current,focusKey) {
    const {conversation_id,message_id,href} = question;
    return curiositySources([
      {conversation_id,message_id,href,label:'View question in Chat'},
      {conversation_id,message_id:question.reply_message_id,href:question.reply_href,
        label:question.state === 'answered' ? 'View answer in Chat' : 'View response in Chat'}
    ],current,focusKey);
  }
  function curiosityDiagnostics(report) {
    const view = node('details','','vp3-curiosity-diagnostics');
    const key = 'curiosity:diagnostics';
    view.open = detailOpen.get(key) === true;
    view.addEventListener('toggle',() => detailOpen.set(key,view.open));
    const summary = node('summary','Review diagnostics');
    summary.dataset.curiosityFocus = key;
    view.append(summary,node('p',report?.source_coverage === 'ready' ? 'Source review complete.' : 'Sources could not be verified.'));
    const checked = new Date(report?.review?.at || '');
    if (Number.isFinite(checked.getTime())) {
      view.append(node('p','Top of Mind last checked ' + checked.toLocaleString()));
      const reasons = {
        ready:'A question was eligible for Chat.', quiet_chat_required:'Waiting for quiet Chat.',
        playing:'Waiting for playback to end.', owner_turn_pending:'Waiting for the current turn.',
        recent_proactive_post:'Waiting after a recent proactive update.', awaiting_reply:'An earlier question is open. Curiosity continues to review new activity.',
        no_question:'No question was ready.', higher_priority:'Another specialist had work ready.',
        report_only:'Questions are kept in this report.', already_offered:'The question already appeared in Chat.'
      };
      const reason = report.review.queued ? 'A question entered the shared Chat queue.' :
        (Object.hasOwn(reasons,report.review.gate) ? reasons[report.review.gate] : '');
      if (reason) view.append(node('p',reason));
    }
    return view;
  }
  const profileReportSections = [
    ['key_findings','Key findings','No current findings in the checked Profile sources.'],
    ['actions_followups','Actions & follow-ups','No saved actions or follow-ups in the checked sources.'],
    ['open_questions','Open questions','No open questions in the checked sources.'],
    ['next_step','Next step','No next step recorded in the checked sources.'],
    ['questions_for_you','Questions for you','No questions for you in the checked sources.']
  ];
  const profileReportCoverage = {
    ready:'Current Profile sources checked.',
    limited:'Some Profile sources could be checked. This report may be incomplete.',
    unavailable:'The Profile report could not be verified. Try again shortly.',
    unconfigured:'Profile activity is not configured or available in this Chat.'
  };
  const profileReportText = (value,limit=320) => typeof value === 'string' && Array.from(value).length <= limit
    && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/.test(value) ? value.trim() : null;
  const analyticsZoneFormatters=new Map(),analyticsDayStarts=new Map();
  function analyticsCalendarParts(stamp,zone) {
    let formatter=analyticsZoneFormatters.get(zone);
    if(!formatter){formatter=new Intl.DateTimeFormat('en-GB',{timeZone:zone,hourCycle:'h23',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});analyticsZoneFormatters.set(zone,formatter);}
    const values={};for(const part of formatter.formatToParts(new Date(stamp*1000)))if(part.type!=='literal')values[part.type]=part.value;
    return {date:values.year+'-'+values.month+'-'+values.day,time:values.hour+':'+values.minute+':'+values.second,
      clock:Date.UTC(Number(values.year),Number(values.month)-1,Number(values.day),Number(values.hour),Number(values.minute),Number(values.second))/1000};
  }
  function analyticsDayStart(date,zone) {
    const key=zone+'|'+date;if(analyticsDayStarts.has(key))return analyticsDayStarts.get(key);
    const nominal=Date.parse(date+'T00:00:00Z')/1000;if(!Number.isSafeInteger(nominal))return null;
    // Prefer the first exact midnight when a timezone repeats it. Sampling
    // offsets also handles a brief midnight followed by a previous-date fold.
    const candidates=[];
    for(let offset=-36*3600;offset<=36*3600;offset+=6*3600){
      const stamp=nominal+offset,parts=analyticsCalendarParts(stamp,zone),candidate=nominal-(parts.clock-stamp),actual=analyticsCalendarParts(candidate,zone);
      if(actual.date===date&&actual.time==='00:00:00')candidates.push(candidate);
    }
    let first;
    if(candidates.length)first=Math.min(...candidates);
    else {
      // A skipped midnight starts at the first available instant of that day.
      let low=nominal-36*3600,high=nominal+36*3600;
      while(low<high){const middle=Math.floor((low+high)/2);if(analyticsCalendarParts(middle,zone).date<date)low=middle+1;else high=middle;}
      first=analyticsCalendarParts(low,zone).date===date?low:null;
    }
    if(analyticsDayStarts.size>256)analyticsDayStarts.clear();analyticsDayStarts.set(key,first);return first;
  }
  function profileAnalyticsRef(value) {
    if(typeof value!=='string'||value.length>180||value.slice(8).length>155)return null;
    const match=/^profile:analytics:v1:(1|7|30):([1-9][0-9]{8,10}):(e|s|u):([A-Za-z0-9_-]{1,100})$/.exec(value);
    if(!match)return null;
    const cutoff=Number(match[2]);
    if(!positive(cutoff)||cutoff<946684800||cutoff>=4133980800||cutoff>Math.floor(Date.now()/1000))return null;
    let zone;
    try {
      zone=atob(match[4].replace(/-/g,'+').replace(/_/g,'/'));
      if(!zone||zone.length>75||!/^[A-Za-z0-9_+./-]+$/.test(zone)
        ||btoa(zone).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')!==match[4]
        ||match[3]==='u'&&zone!=='UTC')return null;
      const local=analyticsCalendarParts(cutoff,zone);
      if(analyticsDayStart(local.date,zone)!==cutoff)return null;
    }catch{return null;}
    return {ref:value,period:Number(match[1]),cutoff,timezone:zone,timezone_source:{e:'explicit',s:'schedule',u:'utc_fallback'}[match[3]]};
  }
  function profileAnalyticsSource(source,chatUrl) {
    const raw=source?.href||source?.url;
    const ref=profileAnalyticsRef(source?.ref),href=localUrl(raw),base=localUrl(chatUrl);
    if(!ref||!href||!base||typeof raw!=='string'||/[\\\u0000-\u0020\u007f]/.test(raw))return null;
    const url=new URL(href,location.origin);
    if(url.hash||url.username||url.password||url.pathname!==new URL(base,location.origin).pathname||[...url.searchParams].length!==3
      ||!['agent','conversation_id','profile_analytics'].every(key=>url.searchParams.getAll(key).length===1)
      ||url.searchParams.get('profile_analytics')!==ref.ref)return null;
    const agentText=url.searchParams.get('agent'),conversationText=url.searchParams.get('conversation_id');
    const id=value=>/^[1-9][0-9]{0,15}$/.test(value||'')&&positive(Number(value))?Number(value):null;
    const agent=agentText==='system'?0:id(agentText),conversation=id(conversationText);
    return agent===null||conversation===null?null:{...ref,chatAgentId:agent,chatConversationId:conversation,href};
  }
  function productMarketingSource(source) {
    const raw=source?.url||source?.href,ref=source?.ref;
    if(typeof raw!=='string'||!raw.startsWith('/')||raw.startsWith('//')||/[\\\u0000-\u0020\u007f]/.test(raw)||typeof ref!=='string')return null;
    const plan=/^marketing:product-plan:([1-9][0-9]{0,15})$/.exec(ref),period=/^marketing:ft:v1:([a-f0-9]{32}):(.+)$/.exec(ref);
    const fixed=period&&profileAnalyticsRef('profile:analytics:v1:'+period[2]);
    if(plan?!positive(Number(plan[1])):!fixed)return null;
    const url=new URL(raw,location.origin),base=new URL(cfg.activityEndpoint,location.origin).pathname.replace(/\/api\/[^/]+$/,'');
    const key=plan?'marketing_plan':'marketing_followthrough';
    if(url.pathname!==base+'/chat.php'||url.hash||url.username||url.password||[...url.searchParams].length!==3
      ||!['agent','conversation_id',key].every(name=>url.searchParams.getAll(name).length===1)
      ||url.searchParams.get(key)!==(plan?plan[1]:ref))return null;
    const id=value=>/^[1-9][0-9]{0,15}$/.test(value||'')&&positive(Number(value))?Number(value):null;
    const agent=url.searchParams.get('agent')==='system'?0:id(url.searchParams.get('agent')),chat=id(url.searchParams.get('conversation_id'));
    return agent===null||chat===null?null:{ref,kind:plan?'plan':'followthrough',chatAgentId:agent,chatConversationId:chat,
      href:url.pathname+url.search,...(plan?{productId:Number(plan[1])}:{publicationId:period[1],period:fixed})};
  }
  function productMarketingModel(report,current) {
    if(report?.contract!=='vp3.product-marketing-summary.v1'||!Object.hasOwn(profileReportCoverage,report.coverage)
      ||!Array.isArray(report.items)||report.items.length>18)return null;
    if(['unavailable','unconfigured'].includes(report.coverage))return report.items.length?null:{coverage:report.coverage,items:[]};
    const items=[],refs=new Set();
    for(const source of report.items){const target=productMarketingSource(source);
      if(!target||target.kind!==source.kind||target.chatAgentId!==current.agent||target.chatConversationId!==current.cid
        ||refs.has(target.ref)||!/^[a-f0-9]{64}$/.test(source.revision||''))return null;
      const row={...target,revision:source.revision};
      for(const [key,limit] of [['title',190],['summary',1200],['next_step',320],['status',40]]){
        row[key]=profileReportText(source[key],limit);if(row[key]===null||['title','summary'].includes(key)&&!row[key])return null;
      }
      refs.add(target.ref);items.push(row);
    }
    return {coverage:report.coverage,items};
  }
  function openProductMarketingSource(source,current,event) {
    if(event?.ctrlKey||event?.metaKey||event?.shiftKey||event?.altKey||source.kind!=='followthrough'
      ||source.chatAgentId!==current.agent||source.chatConversationId!==current.cid)return false;
    if(window.VP3MarketingFollowthroughV1?.openSource?.(source)!==true)return false;
    close(false);return true;
  }
  function profileSourceUrl(source) {
    if(source?.ref?.startsWith('profile:ft:')){
      return productMarketingSource({...source,ref:'marketing:'+source.ref.slice(8)})?.href||'';
    }
    if(source?.ref?.startsWith('profile:analytics:')){
      const base=new URL(cfg.activityEndpoint,location.origin).pathname.replace(/\/api\/[^/]+$/,'');
      return profileAnalyticsSource(source,base+'/chat.php')?.href||'';
    }
    const ref = /^profile:(conversation|event|call|followup):([1-9][0-9]{0,18})$/.exec(source?.ref || '');
    const href = localUrl(source?.url);
    if (!ref || !positive(Number(ref[2])) || !href || /[\u0000-\u0020\u007f]/.test(source.url)) return '';
    const url = new URL(href,location.origin);
    const base = new URL(cfg.activityEndpoint,location.origin).pathname.replace(/\/api\/[^/]+$/,'');
    if (url.hash || url.username || url.password) return '';
    const exactId = key => {
      const values = url.searchParams.getAll(key);
      return values.length === 1 && /^[1-9][0-9]{0,15}$/.test(values[0]) && positive(Number(values[0]));
    };
    if (ref[1] === 'followup') {
      return url.pathname === base + '/artist-listening.php' && [...url.searchParams].length === 1
        && exactId('session') ? href : '';
    }
    if (url.pathname !== base + '/profile-agent.php' || [...url.searchParams].length !== 2
      || url.searchParams.getAll('tab').length !== 1) return '';
    const tab = url.searchParams.get('tab');
    if (['conversation','call'].includes(ref[1]) && tab === 'inbox' && exactId('conversation')) {
      if (ref[1] === 'conversation' && url.searchParams.get('conversation') !== ref[2]) return '';
      return href;
    }
    return ref[1] === 'event' && tab === 'visitors' && exactId('session') ? href : '';
  }
  function profileReportModel(report) {
    if (report?.contract !== 'vp3.profile-report.v1' || !Object.hasOwn(profileReportCoverage,report.coverage)
      || !Array.isArray(report.findings) || report.findings.length > 24) return null;
    const result = {coverage:report.coverage,findings:[]};
    for (const [key] of profileReportSections) {
      if (key === 'next_step') {
        result[key] = profileReportText(report[key]);
        if (result[key] === null) return null;
      } else {
        if (!Array.isArray(report[key]) || report[key].length > 6) return null;
        result[key] = report[key].map(value => profileReportText(value));
        if (result[key].some(value => value === null || !value)) return null;
      }
    }
    // An error or missing configuration cannot retain facts from an earlier read.
    if (['unavailable','unconfigured'].includes(report.coverage)) {
      for (const [key] of profileReportSections) result[key] = key === 'next_step' ? '' : [];
      return result;
    }
    const refs = new Set();
    for (const source of report.findings) {
      const href = profileSourceUrl(source);
      const title = profileReportText(source?.title,190);
      if (!href || !title || !/^[a-f0-9]{64}$/.test(source.revision || '') || refs.has(source.ref)) return null;
      refs.add(source.ref);
      const finding={ref:source.ref,revision:source.revision,title,href};
      for(const [key,limit] of [['status',40],['priority',20],['trigger',40],['disposition',30]]){
        if(source[key]===undefined)continue;
        const value=profileReportText(source[key],limit);if(value===null)return null;finding[key]=value;
      }
      if(source.profile_source_kind!==undefined){
        if(source.profile_source_kind!==(source.ref.startsWith('profile:ft:')?'commerce':source.ref.split(':')[1]))return null;
        finding.profile_source_kind=source.profile_source_kind;
      }
      for(const key of ['profile_presentation_owned','legacy_delivered']){
        if(source[key]===undefined)continue;if(typeof source[key]!=='boolean')return null;finding[key]=source[key];
      }
      if(source.presentation_owner!==undefined){
        if(!['','profile_attention_pa4b','profile_handoff_pa3','profile_speech_pa4a','profile_calls_pa3c','office_tasks','profile_briefing_v1','product_marketing_briefing_v1'].includes(source.presentation_owner)
          || source.profile_presentation_owned!==undefined&&source.profile_presentation_owned!==(source.presentation_owner!==''))return null;
        finding.presentation_owner=source.presentation_owner;
      }
      if(source.profile_event_type!==undefined){
        if(!source.ref.startsWith('profile:event:')||typeof source.profile_event_type!=='string'||!/^[a-z][a-z0-9_]{0,63}$/.test(source.profile_event_type))return null;
        finding.profile_event_type=source.profile_event_type;
      }
      if(source.related_product_marketing!==undefined||source.related_product_marketing_coverage!==undefined){
        if(!/^profile:conversation:[1-9][0-9]*$/.test(source.ref)||!Array.isArray(source.related_product_marketing)
          ||source.related_product_marketing.length>4||!Object.hasOwn(profileReportCoverage,source.related_product_marketing_coverage))return null;
        if(['unavailable','unconfigured'].includes(source.related_product_marketing_coverage)&&source.related_product_marketing.length)return null;
        finding.related_product_marketing=[];finding.related_product_marketing_coverage=source.related_product_marketing_coverage;
        const relatedPublications=new Set(),conversation=Number(source.ref.split(':')[2]);
        for(const related of source.related_product_marketing){const target=productMarketingSource(related),title=profileReportText(related?.title,240),question=profileReportText(related?.question,600);
          if(!target||!title||question===null||relatedPublications.has(related.publication_id)||!/^[a-f0-9]{64}$/.test(related.revision||'')
            ||related.relation!=='saved_promotion_reply'||related.public_conversation_id!==conversation
            ||!['product_id','turn_id','visitor_message_id','answer_message_id','linked_at_utc_ms'].every(key=>positive(related[key]))
            ||related.answer_message_id<=related.visitor_message_id||!/^[a-f0-9]{32}$/.test(related.publication_id||'')
            ||target.kind==='plan'&&target.productId!==related.product_id||target.kind==='followthrough'&&target.publicationId!==related.publication_id)return null;
          relatedPublications.add(related.publication_id);finding.related_product_marketing.push({...target,title,revision:related.revision});
        }
      }
      result.findings.push(finding);
    }
    return result;
  }
  function renderProfileWorkspace(current,options={}) {
    const target=options.host||sections,value=options.value||snapshot,expanded=options.full??workspace;
    if (!value) { target.replaceChildren(); return; }
    const report = value.profile_report;
    const model = profileReportModel(report);
    const body = node('section','','vp3-agent-activity-source');
    body.dataset.profileReport = model?.coverage || 'unavailable';
    body.setAttribute('aria-label','Profile report');
    const coverage = model?.coverage || 'unavailable';
    body.append(node('p',coverage === 'unconfigured' && current.cid === 0
      ? 'Save this Chat to read Profile activity.' : profileReportCoverage[coverage],'vp3-agent-activity-status'));
    const observed = Date.parse(value.observed_at);
    if (Number.isFinite(observed)) body.append(node('small','Checked ' + new Date(observed).toLocaleString()));
    const readable = model && ['ready','limited'].includes(coverage);
    for (const [key,title,empty] of profileReportSections) {
      const section = node('section','','vp3-agent-activity-source');
      section.dataset.profileReportSection = key;
      section.append(node('h4',title));
      const values = readable ? (key === 'next_step' ? [model[key]].filter(Boolean) : model[key]) : [];
      if (values.length) {
        const list = node('ul','','vp3-curiosity-questions');
        for (const value of values.slice(0,expanded ? 6 : 2)) list.append(node('li',value));
        section.append(list);
        if (!expanded && values.length > 2) section.append(node('small','More in the full report.'));
      } else section.append(node('p',readable ? empty : 'Available after the Profile sources can be checked.'));
      body.append(section);
    }
    if (readable && model.findings.length) {
      const sources = node('section','','vp3-agent-activity-source');
      sources.setAttribute('aria-label','Profile report sources');
      sources.append(node('h4','Sources'));
      const routine=source=>source.profile_source_kind==='event'&&['profile_view','return_visitor'].includes(source.profile_event_type)
        && typeof source.presentation_owner==='string'&&source.trigger==='activity'&&source.priority==='low';
      const visits=model.findings.filter(routine),other=model.findings.filter(source=>!routine(source));
      const sourceItem=source=>{
        const item = node('li');
        const link = node('a',source.title);
        link.href = source.href;
        link.dataset.profileSource = source.ref;
        link.dataset.profileSourceRevision=source.revision;
        if(source.disposition!==undefined)link.dataset.profileSourceDisposition=source.disposition;
        if(source.presentation_owner!==undefined)link.dataset.profilePresentationOwner=source.presentation_owner;
        link.addEventListener('click',event => {
          if (signature(current) !== signature(scope()) || (options.isCurrent ? !options.isCurrent() : snapshot?.profile_report !== report)) {
            event.preventDefault();
            close(false);
          } else if (source.ref.startsWith('profile:ft:')&&window.VP3MarketingFollowthroughV1?.openSource?.(source)===true) {
            event.preventDefault();close(false);
          } else if (options.onSelect?.(source)===true) {
            event.preventDefault();
          }
        });
        item.append(link);
        if(source.related_product_marketing?.length){
          const related=node('div','','vp3-profile-related-marketing');related.dataset.profileRelatedMarketing=source.ref;
          related.append(node('small','Related Product Marketing'));
          for(const destination of source.related_product_marketing){const action=node('a',destination.title);action.href=destination.href;
            action.dataset.productMarketingSource=destination.ref;action.dataset.productMarketingRevision=destination.revision;
            action.addEventListener('click',event=>{
              if(signature(current)!==signature(scope())||(options.isCurrent?!options.isCurrent():snapshot?.profile_report!==report)){
                event.preventDefault();close(false);return;
              }
              if(openProductMarketingSource(destination,current,event))event.preventDefault();
            });related.append(action);
          }
          item.append(related);
        }
        if(source.related_product_marketing_coverage&&source.related_product_marketing_coverage!=='ready')
          item.append(node('small','Related marketing evidence is '+(source.related_product_marketing_coverage==='limited'?'partial.':'unavailable.')));
        if(routine(source)){
          const disposition={pending:'Pending',handled:'Handled',ignored:'Dismissed',snoozed:'Snoozed',delivered:'Delivered'}[source.disposition]||'';
          const owner={profile_attention_pa4b:'Visitor notification',profile_handoff_pa3:'Conversation handoff',profile_speech_pa4a:'Visitor announcement',profile_calls_pa3c:'Call notification',office_tasks:'Reviewed task notification',profile_briefing_v1:'Morning briefing'}[source.presentation_owner]||'';
          const note=[disposition,owner].filter(Boolean).join(' · ');if(note)item.append(node('small',note));
        }
        return item;
      };
      if(other.length){const list=node('ul','','vp3-curiosity-questions');for(const source of other.slice(0,expanded?24:3))list.append(sourceItem(source));sources.append(list);}
      if(visits.length){
        const group=node('details','','vp3-profile-visit-group');group.dataset.profileVisitGroup='';
        group.append(node('summary',visits.length+' visit '+(visits.length===1?'record':'records')+' in this checked view'));
        const list=node('ul','','vp3-curiosity-questions');for(const source of visits)list.append(sourceItem(source));group.append(list);sources.append(group);
      }
      if (!expanded && other.length > 3) sources.append(node('small','More sources in the full report.'));
      body.append(sources);
    }
    target.replaceChildren(body);
  }

  async function readProfileReport({signal}={}) {
    const current=scope();
    if (!current) throw Object.assign(new Error('This Chat’s access could not be verified.'),{status:403});
    const request=new AbortController(),relay=()=>request.abort();
    if (signal?.aborted) request.abort();else signal?.addEventListener('abort',relay,{once:true});
    const timeout=setTimeout(()=>request.abort(),15000);
    try {
      const response=await requestActivity(activityUrl(current,'profile'),current,request);
      const data=await response.json().catch(()=>null);
      if (request.signal.aborted || signature(current)!==signature(scope())) throw Object.assign(new Error('Profile context changed.'),{name:'AbortError'});
      if (!response.ok) throw Object.assign(new Error('The Profile report could not be verified.'),{status:response.status});
      if (data?.ok!==true||!validState(data.state,current)||!profileReportModel(data.state.profile_report)
        || data.state.coverage?.profile_report!==data.state.profile_report.coverage) throw new Error('The Profile report could not be verified.');
      return {report:data.state.profile_report,observedAt:data.state.observed_at};
    } finally {clearTimeout(timeout);signal?.removeEventListener('abort',relay);}
  }

  function renderCuriosityWorkspace(current) {
    if (!snapshot) { sections.replaceChildren(); return; }
    const active = sections.contains(document.activeElement) ? document.activeElement.dataset.curiosityFocus : '';
    const report = snapshot.curiosity_report;
    const ready = report?.source_coverage === 'ready';
    const findings = curiositySection('Key findings');
    const body = [findings];
    if (!ready) {
      findings.append(node('p','The Curiosity report could not be verified.'));
    } else {
      const limit = workspace ? 12 : 1;
      const themes = Array.isArray(report.themes) ? report.themes.slice(0,limit) : [];
      for (const theme of themes) {
        const title = curiosityText(theme?.title,190);
        if (!title) continue;
        const article = node('article','','vp3-curiosity-item');
        article.append(node('h5',title));
        const summary = curiosityText(theme.summary,600);
        if (summary) article.append(node('p',summary));
        if (workspace && theme.state === 'confirmed') article.append(node('small','Confirmed theme'));
        if (workspace) {
          const links = curiositySources(theme.sources,current,'theme:' + curiosityText(theme.id,120));
          if (links.childElementCount) article.append(links);
        }
        findings.append(article);
      }
      if (findings.childElementCount === 1) findings.append(node('p','No Curiosity findings yet.'));

      const threads = Array.isArray(report.threads) ? report.threads.slice(0,12) : [];
      const next = report.next_question;
      const nextText = next?.conversation_id === current.cid && next.state === 'open' ? curiosityText(next.question,600) : '';
      const pending = report.pending_question;
      const pendingText = report.attention === 'awaiting_reply' && pending?.state === 'open'
        && positive(pending.conversation_id) && positive(pending.message_id) ? curiosityText(pending.question,600) : '';
      if (nextText) {
        const section = curiositySection('Next step');
        section.append(node('p',nextText));
        const links = curiositySources(next.sources,current,'next:' + curiosityText(next.id,120));
        if (links.childElementCount) section.append(links);
        body.push(section);
      }
      if (pendingText) {
        const awaiting = curiositySection('Earlier question in Chat');
        awaiting.append(node('p',pendingText));
        const links = curiosityQuestionLinks(pending,current,'awaiting:' + curiosityText(pending.id,120));
        if (links.childElementCount) awaiting.append(links);
        body.push(awaiting);
      }
      if (workspace) {
        const followups = curiositySection('Actions & follow-ups');
        const openQuestions = curiositySection('Open questions');
        const openList = node('ul','','vp3-curiosity-questions');
        for (const thread of threads) {
          const title = curiosityText(thread?.title,190);
          if (!title || !Object.hasOwn(curiosityStates,thread.state)) continue;
          const threadKey = 'thread:' + curiosityText(thread.id,120);
          const card = node('article','','vp3-curiosity-item');
          const heading = node('h5',title);
          card.append(heading,node('small',curiosityStates[thread.state]));
          const {answered,total} = thread.progress || {};
          if (Number.isSafeInteger(answered) && positive(total) && answered >= 0 && answered <= total) {
            const label = `${answered} of ${total} answered`;
            const progress = node('progress');
            progress.max = total;
            progress.value = answered;
            progress.setAttribute('aria-label',title + ', ' + label);
            const row = node('div','','vp3-curiosity-progress');
            row.append(progress,node('small',label));
            card.append(row);
          }
          const responses = node('ul','','vp3-curiosity-questions');
          const questions = Array.isArray(thread.questions) ? thread.questions.slice(0,10) : [];
          for (const question of questions) {
            const questionText = curiosityText(question?.question,600);
            if (!questionText || !Object.hasOwn(curiosityStates,question.state)) continue;
            if (question.state === 'open' && (
              nextText && question.id === next.id && thread.id === next.thread_id
              || pendingText && question.id === pending.id && thread.id === pending.thread_id
            )) continue;
            const item = node('li');
            item.append(node('p',questionText));
            if (question.state !== 'open') {
              item.append(node('small',curiosityStates[question.state]));
              const answer = curiosityText(question.answer,700);
              if (question.state === 'answered' && answer) item.append(node('p',answer));
            }
            const links = curiosityQuestionLinks(question,current,threadKey + ':' + curiosityText(question.id,120));
            if (links.childElementCount) item.append(links);
            (question.state === 'open' ? openList : responses).append(item);
          }
          if (responses.childElementCount) card.append(responses);
          followups.append(card);
        }
        if (followups.childElementCount === 1) followups.append(node('p','No saved follow-ups.'));
        openQuestions.append(openList.childElementCount ? openList
          : node('p',nextText || pendingText ? 'No other open questions.' : 'No open questions.'));
        body.push(followups,openQuestions);
      }
    }
    if (workspace) body.push(curiosityDiagnostics(report));
    sections.replaceChildren(...body);
    if (active && document.activeElement === document.body) {
      sections.querySelector(`[data-curiosity-focus="${CSS.escape(active)}"]`)?.focus({preventScroll:true});
    }
  }
  function coordinationCards(current,group) {
    if (!['Needs attention','Agent work'].includes(group) || !Array.isArray(snapshot?.coordination_findings)) return [];
    const items = [];
    const seen = new Set();
    const open = new Set((Array.isArray(snapshot.specialists) ? snapshot.specialists : []).flatMap(row =>
      (Array.isArray(row?.open_engagements) ? row.open_engagements : []).map(item => item?.message_id)));
    for (const item of snapshot.coordination_findings.slice(0,4)) {
      if (item?.kind !== 'coordination_finding' || typeof item.id !== 'string' || !/^[a-f0-9]{64}$/.test(item.id)
        || seen.has(item.id) || typeof item.title !== 'string' || typeof item.detail !== 'string') continue;
      const belongs = group === 'Needs attention' ? item.state === 'needs_owner' :
        group === 'Agent work' && ['agent_work','resolved','watching'].includes(item.state);
      if (!belongs) continue;
      if (item.state === 'needs_owner' && item.delivery === 'delivered' && Array.isArray(item.actions)
        && item.actions.some(action => open.has(action?.message_id))) continue;
      seen.add(item.id);
      const article = node('article','','vp3-agent-activity-item vp3-agent-activity-finding');
      article.dataset.coordinationFinding = item.id;
      const copy = itemCopy(item.title.slice(0,190),item.detail.slice(0,600),'coordination:' + item.id,true);
      const route = routingDescription(item.routing,true);
      if (route) copy.append(node('small',route,'vp3-agent-activity-rule-match'));
      const deliveryLabels = {pending:'Waiting to post to Chat',delivered:'Posted to Chat',withheld:'Chat delivery withheld'};
      const states = {needs_owner:'Waiting on you',agent_work:'Agent work',resolved:'Resolved',watching:'Watching'};
      copy.append(node('span',states[item.state],'vp3-agent-activity-finding-state'));
      const delivery = Object.hasOwn(deliveryLabels,item.delivery) ? deliveryLabels[item.delivery] : '';
      if (delivery) copy.append(node('small',delivery,'vp3-agent-activity-detail'));
      const workLabels = {queued:'Queued',running:'In progress',failed:'Work could not be verified',completed:'Completed'};
      if (Object.hasOwn(workLabels,item.work_status)) copy.append(node('small',workLabels[item.work_status],'vp3-agent-activity-detail'));
      for (const [field,label,limit] of [['next_step','Next step',320],['question','Question for you',320],['result','Result',600]]) {
        if (typeof item[field] !== 'string' || !item[field]) continue;
        const line = node('p','','vp3-agent-activity-detail');
        line.append(node('strong',label + ': '),item[field].slice(0,limit));
        copy.append(line);
      }
      const sources = node('ul','','vp3-agent-activity-finding-sources');
      sources.setAttribute('aria-label','Sources');
      const labels = window.VP3TopOfMindFeedV1?.specialistNames || {};
      for (const source of Array.isArray(item.sources) ? item.sources.slice(0,5) : []) {
        if (!Object.hasOwn(labels,source?.specialist) || typeof source.title !== 'string' || !source.title) continue;
        const label = labels[source.specialist] + ' · ' + source.title.slice(0,120);
        const entry = node('li');
        const gmail = source.specialist === 'gmail' ? source.source_action : null;
        if (gmail?.type === 'gmail_triage' && /^[a-f0-9]{64}$/.test(gmail.item_ref) && positive(gmail.generation)) {
          entry.append(button(label,event => {
            if (!event.isTrusted || !visible() || signature(current) !== signature(scope()) || !snapshot) return;
            if (Date.now() - Date.parse(snapshot.observed_at) > 60000) {
              status.textContent = 'Refreshing this view before continuing…';
              stop(); void read(); return;
            }
            if (window.VP3TopOfMindFeedV1?.openExactGmailMessage(current,gmail.item_ref,gmail.generation)) close(false);
            else status.textContent = 'Open Agent Chat and try again to read this email.';
          }));
        } else {
          const href = localUrl(source.url);
          const link = href ? node('a',label) : node('span',label);
          if (href) link.href = href;
          entry.append(link);
        }
        sources.append(entry);
      }
      if (sources.childElementCount) copy.append(sources);
      const actions = node('div','','vp3-agent-activity-actions');
      const chat = Array.isArray(item.actions) ? item.actions.find(action => action?.kind === 'chat' &&
        action.conversation_id === current.cid && positive(action.message_id)) : null;
      const action = chat && specialistResult({...chat,result_url:chat.href},current);
      if (action) actions.append(button(action.label,event => void act(action,current,event)));
      article.append(copy,actions);
      items.push(article);
    }
    return items;
  }
  function officeReviewItems() {
    const items = snapshot?.sections.find(section => section.id === 'office_reviews')?.items || [];
    return workspace ? items : items.slice(0,1);
  }
  function activityUrl(current,view = '',collection = '',cursor = '',listId = 0) {
    const url = new URL(cfg.activityEndpoint,location.href);
    url.searchParams.set('agent_id',String(current.agent));
    url.searchParams.set('conversation_id',String(current.cid));
    if (view) url.searchParams.set('view',view);
    if (collection) url.searchParams.set('collection',collection);
    if (cursor) url.searchParams.set('cursor',cursor);
    if (listId) url.searchParams.set('list_id',String(listId));
    return url;
  }
  function requestActivity(url,current,request) {
    return fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-VP3-Working-Attention':current.binding},signal:request.signal});
  }
  function officeCursor(data,id) {
    const value = data?.pages?.[id]?.next_cursor;
    return typeof value === 'string' && value.length > 0 && value.length <= 1024 ? value : null;
  }
  function hasOfficePage(data,id) {
    return data?.pages?.[id]?.next_cursor === null || officeCursor(data,id) !== null;
  }
  function resetOfficePage(id) {
    officePages.delete(id);
    officeErrors.delete(id);
    officeRequests.get(id)?.abort();
    officeRequests.delete(id);
  }
  function checkOfficePage(data,id) {
    const page = officePages.get(id);
    if (page && (page.firstCursor !== officeCursor(data,id) || ['forbidden','unavailable'].includes(data.coverage?.[id]))) {
      resetOfficePage(id);
    }
  }
  function selectOfficeList(id) {
    abortOfficeRequests();
    resetOfficePage('list_items');
    officeList = id ? {id,data:null} : null;
    stop();
    if (snapshot) render();
    host.scrollTop = 0;
    if (officeList) sections.querySelector('[data-office-inventory-back]')?.focus();
    else sections.querySelector('[data-office-collection="lists"] h4')?.focus();
    void read();
  }
  function officeCollectionSource(data,id) {
    return data?.sections?.find(section => section.id === id);
  }
  function officeCollectionItems(source) {
    const items = new Map(source.items.map(item => [item.id,item]));
    const pages = officePages.get(source.id)?.batches;
    if (pages) for (const batch of pages.values()) {
      for (const item of batch.items) if (!items.has(item.id)) items.set(item.id,item);
    }
    return [...items.values()];
  }
  function attachedOfficeChatKeys(current) {
    const keys = new Set();
    if (!officeWorkspace()) return keys;
    const data = officeList ? officeList.data : snapshot;
    const ids = officeList ? ['list_items'] : ['reminders','tasks','lists'];
    for (const id of ids) {
      const source = officeCollectionSource(data,id);
      if (!source) continue;
      for (const item of officeCollectionItems(source)) {
        if (!Object.hasOwn(officeReportStates,item.office_report?.state)) continue;
        const key = officeChatKey(officeEntryChatAction(item.office_report,current));
        if (key) keys.add(key);
      }
    }
    return keys;
  }
  function renderOfficeCollection(current,data,source) {
    const id = source.id;
    const extra = officePages.get(id);
    const items = officeCollectionItems(source);
    const section = renderSource(current,{...source,items});
    section.dataset.officeCollection = id;
    section.firstElementChild.tabIndex = -1;
    const coverage = data.coverage?.[id];
    const nextCursor = extra ? extra.nextCursor : officeCursor(data,id);
    if (!items.length) section.append(node('p',coverage === 'forbidden' ? 'This collection is no longer available.' :
      coverage === 'unavailable' ? 'This collection could not be loaded.' :
        nextCursor ? 'No visible items on this page.' : officeCollections[id].empty));
    const controls = node('div','','vp3-agent-activity-actions');
    if (nextCursor) {
      const load = button('Load more',event => void loadOfficePage(id,current,event));
      const name = id === 'list_items' ? 'list items' : officeCollections[id].label.toLowerCase();
      load.setAttribute('aria-label','Load more ' + name);
      load.dataset.officeLoad = id;
      const pending = officeRequests.get(id);
      load.disabled = Boolean(pending && !officeRefreshes.has(pending));
      controls.append(load);
    }
    const error = officeErrors.get(id);
    if (error) {
      const feedback = node('small',error);
      feedback.setAttribute('role','status');
      controls.append(feedback);
    }
    if (controls.childElementCount) section.append(controls);
    return section;
  }
  function refreshVisibleOfficePage(current) {
    if (!officeWorkspace() || officeRequests.size || !officePages.size) return;
    const viewport = host.getBoundingClientRect();
    const now = Date.now();
    let next = null;
    for (const section of sections.querySelectorAll('[data-office-collection]')) {
      const id = section.dataset.officeCollection;
      const batches = officePages.get(id)?.batches;
      if (!batches?.size) continue;
      const visibleIds = new Set();
      for (const row of section.querySelectorAll('[data-office-item-id]')) {
        const bounds = row.getBoundingClientRect();
        if (bounds.top < viewport.bottom && bounds.bottom > viewport.top) visibleIds.add(row.dataset.officeItemId);
      }
      for (const [cursor,batch] of batches) {
        if (now - batch.checkedAt < 10000 || (next && next.checkedAt <= batch.checkedAt)) continue;
        if (batch.items.some(item => visibleIds.has(item.id))) next = {id,cursor,checkedAt:batch.checkedAt};
      }
    }
    if (next) void loadOfficePage(next.id,current,null,next.cursor);
  }
  async function loadOfficePage(id,current,event,refreshCursor = '') {
    if ((!refreshCursor && !event?.isTrusted) || !officeWorkspace() || !visible()
      || signature(current) !== signature(scope())) return;
    const pending = officeRequests.get(id);
    if (pending && (refreshCursor || !officeRefreshes.has(pending))) return;
    const list = officeList;
    const from = id === 'list_items' ? list?.data : snapshot;
    const firstCursor = officeCursor(from,id);
    const previous = officePages.get(id);
    const cursor = refreshCursor || (previous ? previous.nextCursor : firstCursor);
    if (!cursor || (id === 'list_items') !== Boolean(list)) return;
    pending?.abort();
    const request = new AbortController();
    if (refreshCursor) officeRefreshes.add(request);
    officeRequests.set(id,request);
    const trigger = event?.currentTarget;
    const restoreFocus = trigger && document.activeElement === trigger;
    if (trigger) {
      officeErrors.delete(id);
      trigger.disabled = true;
    }
    let changed = false;
    const timeout = setTimeout(() => request.abort(),10000);
    const isCurrent = () => visible() && officeWorkspace() && officeList === list && officeRequests.get(id) === request
      && signature(current) === signature(scope()) && officeCursor(list?.data || snapshot,id) === firstCursor;
    try {
      const response = await requestActivity(activityUrl(current,'tasks',id,cursor,list?.id),current,request);
      if (!isCurrent()) return;
      if (list && response.status === 403) {
        list.data = null;
        list.error = 'This list is no longer available.';
        resetOfficePage(id);
        render();
        return;
      }
      if ([401,403,419].includes(response.status) && !list) {
        resetOfficePage(id);
        stop();
        void read();
        return;
      }
      const data = await response.json();
      if (!isCurrent() || request.signal.aborted) return;
      const state = data?.state;
      const source = officeCollectionSource(state,id);
      const nextCursor = officeCursor(state,id);
      if (!response.ok || data?.ok !== true || !validState(state,current) || state.sections.length !== 1 || !source
        || !hasOfficePage(state,id) || (list && state.list_id !== list.id)
        || nextCursor === cursor || (!refreshCursor && nextCursor && previous?.batches.has(nextCursor))
        || (Number.isSafeInteger(Number(nextCursor)) && nextCursor && Number.isSafeInteger(Number(cursor))
          && Number(nextCursor) >= Number(cursor))) throw new Error('Invalid collection page');
      if (['forbidden','unavailable'].includes(state.coverage?.[id])) throw new Error('Collection unavailable');
      const batches = new Map(previous?.batches);
      const old = batches.get(cursor);
      batches.set(cursor,{items:source.items,checkedAt:Date.now()});
      const continuation = [...batches.keys()].at(-1) === cursor ? nextCursor : previous.nextCursor;
      changed = !refreshCursor || JSON.stringify(old?.items) !== JSON.stringify(source.items) || continuation !== previous.nextCursor;
      officePages.set(id,{firstCursor,nextCursor:continuation,batches});
    } catch {
      if (isCurrent() && !refreshCursor) officeErrors.set(id,'Could not load more items. Try again.');
    } finally {
      clearTimeout(timeout);
      if (officeRequests.get(id) !== request) return;
      officeRequests.delete(id);
      if (!visible() || !officeWorkspace() || officeList !== list || signature(current) !== signature(scope())) return;
      if (refreshCursor && !changed) return;
      const scrollTop = host.scrollTop;
      renderSpecialists();
      renderWorkspaceSources(current);
      if (refreshCursor) host.scrollTop = scrollTop;
      if (restoreFocus && (document.activeElement === trigger || document.activeElement === document.body)) {
        (sections.querySelector(`[data-office-load="${id}"]`) || sections.querySelector(`[data-office-collection="${id}"] h4`))?.focus();
      }
    }
  }
  function renderMarketingCanvasLink(current,campaign,review) {
    const endpoint = localUrl(review.link_endpoint);
    if (!endpoint || campaign.can_link_canvas !== true || !Number.isSafeInteger(campaign.version) || campaign.version < 0) return null;
    const choices = (Array.isArray(review.canvas_choices) ? review.canvas_choices : []).slice(0,24).filter(choice =>
      typeof choice?.id === 'string' && /^[a-f0-9-]{36}$/.test(choice.id) && positive(choice.version) &&
      typeof choice.title === 'string' && /^[a-f0-9]{64}$/.test(choice.proof));
    if (!choices.length) return null;
    const form = node('form','','vp3-agent-activity-actions');
    const label = node('label','Tested private Canvas ');
    const select = node('select');
    const placeholder = node('option','Choose a Canvas version');
    placeholder.value = '';
    select.append(placeholder);
    const byValue = new Map();
    for (const choice of choices) {
      const option = node('option',`${choice.title.slice(0,130)} · V${choice.version}${choice.attached === true ? ' · Image attached' : ''}`);
      option.value = `${choice.id}:${choice.version}`;
      byValue.set(option.value,choice);
      select.append(option);
    }
    select.value = byValue.has(marketingCanvasSelections.get(campaign.id)) ? marketingCanvasSelections.get(campaign.id) : '';
    label.append(select);
    const submit = node('button','Link private Canvas');
    submit.type = 'submit';
    submit.disabled = !select.value;
    select.addEventListener('change',() => {
      marketingCanvasSelections.set(campaign.id,select.value);
      submit.disabled = !select.value;
    });
    const feedback = node('p','','vp3-agent-activity-status');
    feedback.setAttribute('role','status');
    form.append(label,submit,feedback);
    form.addEventListener('submit',async event => {
      event.preventDefault();
      const choice = byValue.get(select.value);
      if (!event.isTrusted || submit.disabled || !choice || !visible() || signature(scope()) !== signature(current)) return;
      select.disabled = submit.disabled = true;
      feedback.textContent = 'Saving the private Canvas link…';
      try {
        const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{
          'Content-Type':'application/json','X-VP3-Working-Attention':current.binding},body:JSON.stringify({
            csrf_token:cfg.csrf,agent_id:current.agent,conversation_id:current.cid,campaign_id:campaign.id,
            campaign_version:campaign.version,canvas_id:choice.id,canvas_version:choice.version,canvas_proof:choice.proof,
          })});
        const data = await response.json();
        if (!response.ok || data?.ok !== true || data.state !== 'linked') throw new Error(data?.error || 'The private Canvas link could not be saved.');
        if (!visible() || signature(scope()) !== signature(current)) return;
        marketingCanvasSelections.delete(campaign.id);
        stop();
        void read();
      } catch (error) {
        feedback.textContent = error.message;
        select.disabled = submit.disabled = false;
      }
    });
    return form;
  }

  function renderProductMarketingReview(current) {
    const raw=snapshot?.product_marketing,report=productMarketingModel(raw,current),section=node('section','','vp3-agent-activity-source vp3-product-marketing-summary');
    section.dataset.productMarketingSummary=report?.coverage||'unavailable';
    section.append(node('h4','Product Marketing'),node('p','Existing plans and completed promotion periods in this private Chat.'));
    const state=report?.coverage||'unavailable';
    if(['unavailable','unconfigured'].includes(state)){
      section.append(node('p',state==='unconfigured'?(current.cid?'No readable Product Marketing sources are configured for this Chat.':'Save this Chat to read Product Marketing.'):'Product Marketing could not be verified. Refresh this report to check again.'));
      return section;
    }
    if(state==='limited')section.append(node('p','Some sources could not be fully verified. This summary may be incomplete.','vp3-agent-activity-status'));
    for(const [kind,label] of [['plan','Current plans'],['followthrough','Completed promotion periods']]){
      const rows=report.items.filter(row=>row.kind===kind);if(!rows.length)continue;
      const group=node('section');group.append(node('h5',label));
      for(const item of rows){const card=node('article');card.dataset.productMarketingSource=item.ref;
        card.append(node('h5',item.title));
        if(item.period){const date=new Intl.DateTimeFormat('en-GB',{year:'numeric',month:'short',day:'numeric',timeZone:item.period.timezone}).format(new Date(item.period.cutoff*1000-1));
          card.append(node('small',item.period.period===1?'Completed day: '+date+' · '+item.period.timezone:'Completed '+item.period.period+' days ending '+date+' · '+item.period.timezone));}
        card.append(node('p',item.summary));if(item.next_step)card.append(node('p',item.next_step));
        const link=node('a',kind==='plan'?'Open current plan':'View follow-through');link.href=item.href;
        link.dataset.productMarketingLink=item.ref;link.dataset.productMarketingRevision=item.revision;
        link.addEventListener('click',event=>{
          if(signature(current)!==signature(scope())||snapshot?.product_marketing!==raw){event.preventDefault();close(false);return;}
          if(openProductMarketingSource(item,current,event))event.preventDefault();
        });card.append(link);group.append(card);
      }
      section.append(group);
    }
    if(!report.items.length)section.append(node('p','No current plans or attributable promotion activity in the checked sources.'));
    return section;
  }
  function renderMarketingReview(current) {
    const review = snapshot?.marketing;
    const section = node('section','','vp3-agent-activity-source');
    section.append(node('h4','Campaign follow-through'));
    const rows = Array.isArray(review?.campaigns) ? review.campaigns.slice(0,5) : [];
    const draftLinks = new Set([review?.draft_href,...rows.map(campaign => campaign?.draft_href)].map(localUrl).filter(Boolean));
    for (const href of draftLinks) {
      const business = rows.find(campaign => localUrl(campaign?.draft_href) === href)?.business;
      const link = node('a',draftLinks.size > 1 && typeof business === 'string' ? `Prepare an offer for ${business.slice(0,100)}` : 'Prepare an offer draft');
      link.href = href;
      section.append(link);
    }
    const readinessLabels = {ready:'Ready',missing:'Needs attention',limited:'Could not verify',not_applicable:'Not applicable'};
    const campaignLabels = {draft:'Draft',scheduled:'Scheduled',active:'Active',paused:'Paused',completed:'Completed',archived:'Archived'};
    const checkLabels = {location:'Location',claim_code:'Merchant claim code',reward:'Reward',audience:'Audience'};
    const observationLabels = {claims:'Recorded reward claims',redemptions:'Campaign optimization sample',promotion:'Tracked promotion activity'};
    const metrics = {reward_redemptions:'Reward redemptions',decisions_sampled:'Decisions observed',clicks:'Clicks',
      checkout_starts:'Checkouts started',paid_orders:'Paid orders',refunded_orders:'Refunded orders'};
    for (const campaign of rows) {
      if (!positive(campaign?.id) || typeof campaign.name !== 'string' || typeof campaign.business !== 'string') continue;
      const key = 'marketing:campaign:' + campaign.id;
      const card = node('details','','vp3-agent-activity-group');
      card.open = detailOpen.get(key) ?? false;
      card.addEventListener('toggle',() => detailOpen.set(key,card.open));
      card.append(node('summary',`${campaign.business.slice(0,140)} · ${campaign.name.slice(0,160)}`));
      const status = Object.hasOwn(campaignLabels,campaign.status) ? campaignLabels[campaign.status] : '';
      const location = typeof campaign.location === 'string' ? campaign.location.slice(0,120) : '';
      const version = positive(campaign.version) ? `Version ${campaign.version}` : '';
      if (status || location || version) card.append(node('p',[status,location,version].filter(Boolean).join(' · ')));
      const readiness = campaign.readiness;
      if (readiness?.checks && typeof readiness.checks === 'object') {
        card.append(node('h5','Offer readiness'));
        for (const [id,check] of Object.entries(readiness.checks)) {
          if (!Object.hasOwn(checkLabels,id) || !Object.hasOwn(readinessLabels,check?.state) || typeof check.detail !== 'string') continue;
          const line = node('p');
          line.append(node('strong',`${checkLabels[id]} · ${readinessLabels[check.state]} `),check.detail.slice(0,320));
          card.append(line);
          if (typeof check.next_step === 'string' && check.next_step) card.append(node('p',check.next_step.slice(0,320)));
        }
      }
      const decisions = (Array.isArray(campaign.outcomes?.decisions) ? campaign.outcomes.decisions : [])
        .slice(0,6).filter(decision => ['accepted','dismissed','proposed'].includes(decision?.status) &&
          typeof decision.type === 'string' && /^[a-z-]{1,40}$/.test(decision.type));
      if (decisions.length) {
        card.append(node('h5','Recommendation decisions'));
        for (const decision of decisions) card.append(node('p',`${decision.type.replaceAll('-',' ')} · ${decision.status}`));
      }
      for (const [id,label] of Object.entries(observationLabels)) {
        const observation = campaign.outcomes?.observations?.[id];
        if (!observation) continue;
        card.append(node('h5',label));
        if (observation.state !== 'observed') {
          card.append(node('p',observation.state === 'unavailable' ? 'Observation could not be verified.' : 'No verified observation yet.'));
          continue;
        }
        const dates = [observation.window_start,observation.window_end].map(value => typeof value === 'string' ? value.match(/^\d{4}-\d{2}-\d{2}/)?.[0] : '').filter(Boolean);
        if (dates.length) card.append(node('small',dates.length === 2 ? dates.join(' through ') : `From ${dates[0]}`));
        for (const [metric,value] of Object.entries(observation.metrics || {})) {
          if (Object.hasOwn(metrics,metric) && Number.isSafeInteger(value) && value >= 0) card.append(node('p',`${metrics[metric]} · ${value}`));
        }
      }
      for (const artifact of (Array.isArray(campaign.artifacts) ? campaign.artifacts : []).slice(0,4)) {
        if (typeof artifact?.label !== 'string' || !['saved','linked','unavailable'].includes(artifact.state)) continue;
        const destination = localUrl(artifact.href);
        const label = artifact.label.slice(0,180) + (artifact.state === 'unavailable' ? ' · Review link no longer current' : '');
        const entry = node(destination ? 'a' : 'span',label);
        if (destination) entry.href = destination;
        const line = node('p');
        line.append(entry);
        card.append(line);
      }
      const href = localUrl(campaign.href);
      if (href) {
        const link = node('a','Review campaign');
        link.href = href;
        card.append(link);
      }
      const linking = renderMarketingCanvasLink(current,campaign,review);
      if (linking) card.append(linking);
      section.append(card);
    }
    if (!rows.length) section.append(node('p',review?.coverage === 'unavailable' ? 'Campaign follow-through could not be verified.'
      : review?.coverage === 'unconfigured' ? 'No authorized campaign workspace is available.' : 'No campaigns to review yet.'));
    return section;
  }

  const operatorCallStates = {completed:'Completed',busy:'Busy',no_answer:'No answer',failed:'Failed',canceled:'Canceled',voicemail_ready:'Voicemail saved'};
  const operatorRecordingStates = {none:'No linked recording receipt',unavailable:'Recording status unavailable',received:'Recording receipt saved',queued:'Recording queued',processing:'Processing recording',ready:'Recording job completed',failed:'Processing needs review'};
  function operatorHistoryModel(raw) {
    if (!raw || !['ready','unavailable','no_chat'].includes(raw.coverage) || !['ready','limited','unavailable'].includes(raw.recording_coverage)
      || !Array.isArray(raw.calls) || raw.calls.length>50) return null;
    if (raw.coverage!=='ready' && raw.calls.length) return null;
    const seen=new Set();
    for (const call of raw.calls) {
      if (!call || typeof call.call_id!=='string' || !/^(?:[a-f0-9]{32}|legacy-[1-9][0-9]{0,17})$/.test(call.call_id)
        || seen.has(call.call_id) || !Number.isSafeInteger(call.contact_id) || call.contact_id<0
        || typeof call.contact_name!=='string' || call.contact_name.length>240
        || typeof call.peer_e164!=='string' || (call.peer_e164!=='' && !/^\+[1-9][0-9]{7,14}$/.test(call.peer_e164))
        || (Object.hasOwn(call,'connected_profile_id')&&(!/^[a-f0-9]{32}$/.test(call.connected_profile_id)||call.peer_e164!==''||call.contact_id!==0||!/^Ending in [0-9]{4}$/.test(call.peer_masked)))
        || !['inbound','outbound'].includes(call.direction) || !Object.hasOwn(operatorCallStates,call.state)
        || typeof call.created_at!=='string' || (call.created_at!=='' && !Number.isFinite(Date.parse(call.created_at)))
        || !Number.isSafeInteger(call.duration_seconds) || call.duration_seconds<0 || call.duration_seconds>86400
        || !Object.hasOwn(operatorRecordingStates,call.recording?.state)
        || typeof call.recording.transcript_saved!=='boolean' || typeof call.recording.report_saved!=='boolean') return null;
      seen.add(call.call_id);
    }
    return raw;
  }
  function operatorPhoneAction(current,method,event) {
    const phone=window.VP3PhoneWorkspaceV1;
    if (!event.isTrusted || signature(current)!==signature(scope()) || typeof phone?.[method]!=='function') return;
    const trigger=specialistTrigger;
    close(false);
    phone[method](trigger);
  }
  function operatorFollowupModel(raw) {
    if (!raw || !['ready','limited','unavailable','unconfigured'].includes(raw.coverage) ||
      !Array.isArray(raw.reports) || raw.reports.length>8 ||
      (['unavailable','unconfigured'].includes(raw.coverage) && raw.reports.length)) return null;
    const refs=new Set();
    for (const report of raw.reports) {
      if (!report || typeof report.ref!=='string' || !/^followup:[a-f0-9]{32}$/.test(report.ref) ||
        refs.has(report.ref) || typeof report.call_id!=='string' || !/^(?:[a-f0-9]{32}|legacy-[1-9][0-9]{0,17})$/.test(report.call_id) ||
        typeof report.created_at!=='string' || !Number.isFinite(Date.parse(report.created_at)) ||
        !Array.isArray(report.items) || !report.items.length || report.items.length>3) return null;
      refs.add(report.ref);
      const keys=new Set();
      for (const item of report.items) {
        if (!item || typeof item.key!=='string' || !/^[a-f0-9]{64}$/.test(item.key) || keys.has(item.key) ||
          !positive(item.session_id) || typeof item.text!=='string' || !item.text.trim() || item.text.length>600 ||
          typeof item.next_step!=='string' || item.next_step.length>560 || !['accepted','unreviewed'].includes(item.review_state) ||
          typeof item.revision!=='string' || !/^[a-f0-9]{64}$/.test(item.revision) || !localUrl(item.source_url)) return null;
        const source=new URL(item.source_url,location.origin);
        if (!source.pathname.endsWith('/artist-listening-evidence.php') || source.hash ||
          source.searchParams.get('session')!==String(item.session_id) ||
          !/^[a-z0-9_-]{1,64}$/.test(source.searchParams.get('plugin')||'') ||
          !/^[a-z0-9_-]{1,64}$/.test(source.searchParams.get('section')||'') ||
          !/^[A-Za-z0-9_.:-]{1,190}$/.test(source.searchParams.get('item')||'') ||
          [...source.searchParams.keys()].some(key=>!['session','plugin','section','item','page'].includes(key)) ||
          [...source.searchParams.keys()].length!==new Set(source.searchParams.keys()).size ||
          (source.searchParams.has('page')&&!/^[1-9][0-9]{0,5}$/.test(source.searchParams.get('page')))) return null;
        keys.add(item.key);
      }
    }
    return raw;
  }
  function renderOperatorFollowups(current,panel,review) {
    panel.append(node('h4','Conversation follow-ups'));
    if (!review || ['unavailable','unconfigured'].includes(review.coverage)) {
      panel.append(node('p',review?.coverage==='unconfigured'?'Follow-up review requires a saved authorized Chat and transcript access.':'Call follow-ups could not be verified.'));
      return;
    }
    panel.append(node('small','Recent call analysis delivered in this Chat. AI suggestions are not confirmed commitments; no calls, tasks or calendar entries are created here.'));
    if (!review.reports.length) panel.append(node('p','No current open follow-ups found in the recent call reports checked.'));
    for (const report of review.reports) {
      for (const item of report.items) {
        const copy=itemCopy(item.text,item.next_step,'operator-followup:'+item.key,true);
        copy.append(node('small',item.review_state==='accepted'?'Reviewed · not yet tracked':'AI suggestion · needs review'));
        const actions=node('div','','vp3-agent-activity-actions');
        const source=node('a','Review source evidence');source.href=localUrl(item.source_url);
        source.dataset.operatorFocus='source:'+item.key;
        source.addEventListener('click',event=>{if(!event.isTrusted||signature(current)!==signature(scope()))event.preventDefault();});
        actions.append(source);
        if (typeof window.STONEFELLOW_TRANSCRIPTION_CANVAS?.open==='function') {
          const control=button('Open saved transcript',event=>openSavedTranscription(current,event,item.session_id));
          control.dataset.operatorFocus='transcript:'+item.key;actions.append(control);
        }
        appendSpecialistItem(panel,copy,actions);
      }
    }
    if (review.coverage==='limited') panel.append(node('small','Review is limited to the most recent eight eligible reports; some sources could not be checked.'));
  }
  function operatorCalendarModel(raw) {
    if (!raw || raw.execution_enabled!==false || !['ready','limited','unavailable'].includes(raw.coverage)
      || !Array.isArray(raw.appointments) || raw.appointments.length>10 || raw.coverage==='unavailable'&&raw.appointments.length) return null;
    const seen=new Set();
    for (const appointment of raw.appointments) {
      if (!appointment || !positive(appointment.event_id) || seen.has(appointment.event_id) || appointment.execution_enabled!==false
        || typeof appointment.title!=='string' || appointment.title.length>380 || typeof appointment.label!=='string' || appointment.label.length>160
        || !Number.isFinite(Date.parse(appointment.start_at)) || !Number.isFinite(Date.parse(appointment.end_at))
        || Date.parse(appointment.end_at)<=Date.parse(appointment.start_at) || !localUrl(appointment.url)) return null;
      const href=new URL(appointment.url,location.origin);
      if (!href.pathname.endsWith('/calendar-event.php')||href.hash||href.searchParams.get('id')!==String(appointment.event_id)
        || [...href.searchParams.keys()].length!==1) return null;
      seen.add(appointment.event_id);
    }
    return raw;
  }
  function renderOperatorCalendar(current,panel,calendar) {
    panel.append(node('h4','Planned calls'));
    panel.append(node('small','Upcoming owner-reviewed Calendar appointments. These are reminders, not automated calls.'));
    if (!calendar || calendar.coverage==='unavailable') {
      panel.append(node('p','Planned calls could not be verified.'));return;
    }
    if (!calendar.appointments.length) panel.append(node('p','No upcoming phone appointments found.'));
    for (const appointment of calendar.appointments) {
      const copy=itemCopy(appointment.title,appointment.label,'operator-calendar:'+appointment.event_id,true);
      const actions=node('div','','vp3-agent-activity-actions'),link=node('a','Open Calendar appointment');
      link.href=localUrl(appointment.url);link.dataset.operatorFocus='calendar:'+appointment.event_id;
      link.addEventListener('click',event=>{if(!event.isTrusted||signature(current)!==signature(scope()))event.preventDefault();});
      actions.append(link);appendSpecialistItem(panel,copy,actions);
    }
    if(calendar.coverage==='limited')panel.append(node('small','Some appointments may be outside this recent view.'));
  }
  const operatorWorkerLabels={disabled:'Reporting disabled',not_granted:'Worker not granted',revoked:'Worker credential revoked',
    pairing_unavailable:'Pairing unavailable',awaiting_registration:'Awaiting signed registration',reporting:'Signed HomeServer report verified',
    stale:'Worker report stale',retired:'Worker retired',unavailable:'Worker status unavailable'};
  function operatorWorkerModel(current) {
    const row=Array.isArray(snapshot?.specialists)?snapshot.specialists.find(r=>r?.id==='operator'&&r.conversation_id===current.cid):null;
    const raw=row?.operator_worker;
    if(!raw||!Object.hasOwn(operatorWorkerLabels,raw.state)||raw.execution_enabled!==false||raw.live_certified!==false
      ||!Number.isSafeInteger(raw.checked_at)||raw.checked_at<1||raw.checked_at>4102444710
      ||!Number.isSafeInteger(raw.fresh_until)||raw.fresh_until<0
      ||(raw.last_verified_epoch!==null&&(!Number.isSafeInteger(raw.last_verified_epoch)||raw.last_verified_epoch<1||raw.last_verified_epoch>raw.checked_at))
      ||(raw.state==='reporting'&&(raw.last_verified_epoch===null||raw.fresh_until<=raw.checked_at||raw.fresh_until>raw.last_verified_epoch+25))
      ||(raw.state!=='reporting'&&raw.fresh_until!==0))return null;
    return {state:raw.state,last_verified_epoch:raw.last_verified_epoch,checked_at:raw.checked_at,fresh_until:raw.fresh_until};
  }
  function renderOperatorWorker(panel,worker) {
    if(!worker)return; // Legacy/disabled deployments retain the existing Brain.
    const block=node('section','','vp3-agent-activity-specialist');block.dataset.operatorWorkerStatus='true';
    block.append(node('h4','HomeServer worker'),node('p',operatorWorkerLabels[worker.state]));
    if(worker.last_verified_epoch!==null)
      block.append(node('small','Last verified report: '+new Date(worker.last_verified_epoch*1000).toLocaleString()));
    block.append(node('p','Registration reporting only. Autonomous calls are off; live call readiness is not verified.'));
    panel.append(block);
  }
  function renderOperatorHistory(current) {
    const history=operatorHistoryModel(snapshot?.operator_history);
    if (!history) {sections.replaceChildren();operatorRendered='';return;}
    const contactKey=call=>call.connected_profile_id?'connected:'+call.connected_profile_id:call.contact_id>0?'contact:'+call.contact_id:call.peer_e164?'phone:'+call.peer_e164:'unknown:'+call.call_id;
    const label=call=>call.connected_profile_id?'Connected phone · '+call.peer_masked:call.contact_name||call.peer_e164||'Unknown caller';
    const contacts=new Map();
    for (const call of history.calls) if (!contacts.has(contactKey(call))) contacts.set(contactKey(call),label(call));
    if (operatorContact && !contacts.has(operatorContact)) operatorContact='';
    const followups=operatorFollowupModel(snapshot?.operator_followups);
    const calendar=operatorCalendarModel(snapshot?.operator_calendar);
    const worker=operatorWorkerModel(current);
    const key=JSON.stringify([history,followups,calendar,worker,operatorTab,operatorContact]);
    if (key===operatorRendered && sections.querySelector('[data-operator-view]')) return;
    operatorRendered=key;
    const focus=sections.contains(document.activeElement)?document.activeElement.dataset.operatorFocus:'';
    const panel=node('section','','vp3-agent-activity-specialist');
    panel.dataset.operatorView=operatorTab;
    const nav=node('nav','','vp3-agent-activity-actions');nav.setAttribute('aria-label','Operator views');
    for (const [id,title] of [['brain','Brain'],['history','History']]) {
      const tab=button(title,event=>{
        if (!event.isTrusted || signature(current)!==signature(scope())) return;
        operatorTab=id;renderOperatorHistory(current);
        sections.querySelector('[data-operator-focus="'+id+'"]')?.focus({preventScroll:true});
      });
      tab.dataset.operatorFocus=id;tab.setAttribute('aria-pressed',String(operatorTab===id));nav.append(tab);
    }
    panel.append(nav);
    if(operatorTab==='brain')renderOperatorWorker(panel,worker);
    if (history.coverage!=='ready') {
      panel.append(node('p',history.coverage==='no_chat'?'Open a saved private Chat to review phone history.':'Phone conversation history could not be verified.'));
    } else {
      panel.append(node('small','Up to 50 recent finished call receipts for this '+(current.agent>0?'assigned Agent.':'account.')));
      if (operatorTab==='brain') {
        const calls=history.calls;
        const count=predicate=>calls.filter(predicate).length;
        panel.append(node('h4','Conversation overview'));
        panel.append(node('p',calls.length+' recent calls · '+contacts.size+' caller groups'));
        panel.append(node('p',count(c=>['busy','no_answer','failed'].includes(c.state))+' busy, unanswered or failed calls'));
        panel.append(node('p',count(c=>['queued','processing'].includes(c.recording.state))+' calls awaiting recording processing'));
        panel.append(node('p',count(c=>c.recording.state==='failed')+' calls with processing failures to review'));
        panel.append(node('p',count(c=>c.recording.transcript_saved)+' saved transcripts · '+count(c=>c.recording.report_saved)+' saved reports'));
        panel.append(node('small','Recording status describes the latest linked receipt. Saved reports are historical and require review; this overview does not infer commitments or send follow-ups.'));
        renderOperatorFollowups(current,panel,followups);
        renderOperatorCalendar(current,panel,calendar);
      } else {
        const filter=node('select');filter.dataset.operatorFocus='contact-filter';filter.setAttribute('aria-label','Filter call history by caller');
        for (const [id,title] of [['','All callers'],...contacts]) {
          const option=node('option',title);option.value=id;option.selected=id===operatorContact;filter.append(option);
        }
        filter.addEventListener('change',event=>{
          if (signature(current)!==signature(scope())) return;
          operatorContact=contacts.has(filter.value)?filter.value:'';renderOperatorHistory(current);
        });
        panel.append(filter);
        const calls=history.calls.filter(c=>!operatorContact||contactKey(c)===operatorContact);
        if (!calls.length) panel.append(node('p','No finished call receipts in this view.'));
        for (const call of calls) {
          const when=call.created_at?new Date(call.created_at).toLocaleString():'Date unavailable';
          const duration=Math.floor(call.duration_seconds/60)+'m '+(call.duration_seconds%60)+'s';
          const copy=itemCopy(label(call),[call.contact_name?call.peer_e164:'',call.direction==='inbound'?'Incoming':'Outgoing',operatorCallStates[call.state],when,duration].filter(Boolean).join(' · '),'operator:'+call.call_id);
          copy.append(node('small',[operatorRecordingStates[call.recording.state],call.recording.transcript_saved?'Saved transcript':'',call.recording.report_saved?'Saved report':''].filter(Boolean).join(' · ')));
          appendSpecialistItem(panel,copy,node('div','','vp3-agent-activity-actions'));
        }
      }
      if (history.recording_coverage!=='ready') panel.append(node('p','Some recording or transcript status could not be verified.'));
      const actions=node('div','','vp3-agent-activity-actions');
      for (const [method,title] of [['openHistory','Open call history'],['openRecordings','Open recordings and reports']]) {
        if (typeof window.VP3PhoneWorkspaceV1?.[method]!=='function') continue;
        const control=button(title,event=>operatorPhoneAction(current,method,event));control.dataset.operatorFocus=method;actions.append(control);
      }
      if (actions.childElementCount) panel.append(actions);
    }
    sections.replaceChildren(panel);
    if (focus) sections.querySelector('[data-operator-focus="'+CSS.escape(focus)+'"]')?.focus({preventScroll:true});
  }
  function renderWorkspaceSources(current) {
    const specialist = family(selectedSpecialist);
    if (specialist === 'operator') {renderOperatorHistory(current);return;}
    if (specialist === 'profile') {
      renderProfileWorkspace(current);
      return;
    }
    if (specialist === 'media') {
      renderMediaWorkspace(current);
      return;
    }
    if (specialist === 'curiosity') {
      renderCuriosityWorkspace(current);
      return;
    }
    if (officeWorkspace()) {
      if (officeList) {
        const restoreBack = sections.querySelector('[data-office-inventory-back]') === document.activeElement;
        const back = button('Back to inventory',() => selectOfficeList(0));
        back.dataset.officeInventoryBack = '';
        const source = officeCollectionSource(officeList.data,'list_items');
        if (source) sections.replaceChildren(back,renderOfficeCollection(current,officeList.data,source));
        else {
          const message = node('p',officeList.error || 'Loading list contents…');
          message.setAttribute('role','status');
          sections.replaceChildren(back,message);
          if (officeList.error) sections.append(button('Retry list',() => { stop(); void read(); }));
        }
        if (restoreBack) back.focus({preventScroll:true});
        return;
      }
      if (!officeAllLoaded || !snapshot) { sections.replaceChildren(); return; }
      const nodes = [];
      const reviews = officeCollectionSource(snapshot,'office_reviews');
      const sources = ['reminders','tasks','lists'].map(id => officeCollectionSource(snapshot,id)
        || {id,label:officeCollections[id].label,items:[]});
      const navigation = node('nav','','vp3-agent-activity-actions');
      navigation.setAttribute('aria-label','Office collections');
      for (const id of ['tasks','lists','reminders']) {
        navigation.append(button(officeCollections[id].label,() => {
          const heading = sections.querySelector(`[data-office-collection="${id}"] h4`);
          heading?.focus({preventScroll:true});
          heading?.scrollIntoView({block:'start'});
        }));
      }
      nodes.push(navigation);
      if (reviews?.items.length) {
        const attached = attachedOfficeChatKeys(current);
        const items = reviews.items.filter(item => !item.actions.some(action => attached.has(officeChatKey(action))));
        if (items.length) nodes.push(renderSource(current,{...reviews,items},true));
      }
      for (const source of sources) nodes.push(renderOfficeCollection(current,snapshot,source));
      sections.replaceChildren(...nodes);
      return;
    }
    const ids = workspace && Object.hasOwn(workspaceSources,specialist) ? workspaceSources[specialist]
      : specialist === 'tasks' ? ['office_reviews'] : specialist === 'coding' ? ['canvas_suggestions'] : [];
    const sources = ids.map(id => snapshot?.sections.find(source => source.id === id &&
      (source.items.length || specialist === 'planning' || ['canvas_suggestions','marketing'].includes(id)))).filter(Boolean);
    if (specialist === 'tasks') workspaceButton.textContent = officeReviewItems().length ? 'Full report' : 'Open workspace';
    const reviews = specialist === 'marketing' && workspace ? [renderProductMarketingReview(current),renderMarketingReview(current)] : [];
    sections.replaceChildren(...reviews,...sources.map(source => source.id === 'office_reviews'
      ? renderSource(current,{...source,items:officeReviewItems()},workspace)
      : renderSource(current,source,specialist === 'planning' || workspace && ['coding','marketing'].includes(specialist))));
  }
  function render() {
    if (!snapshot || !scope() || !sections) return;
    const current = scope();
    if (specialistMode) {
      renderCoordination();
      renderSpecialists();
      renderWorkspaceSources(current);
      const hasActivity = specialistsHost.childElementCount || coordinationHost.childElementCount || sections.childElementCount;
      status.textContent = hasActivity ? '' : 'No current activity to show.';
      if (officeWorkspace() && !officeAllLoaded && !officeList) status.textContent = 'Loading saved tasks, lists and reminders…';
      positionSpecialist();
      return;
    }
    const nodes = [];
    for (const previous of sections.querySelectorAll('[data-activity-group]')) groupOpen.set(previous.dataset.activityGroup,previous.open);
    for (const group of groups) {
      const found = snapshot.sections.filter(section => group.ids.includes(section.id) &&
        (section.id !== 'youtube' || !snapshot.youtube_activity) && (section.id !== 'songs' || !recentListeningItems(1).length))
        .map(section => section.id === 'suggestions' ? {...section,items:section.items.filter(item => !item.id.startsWith('opportunity:'))} : section)
        .filter(section => section.items.length);
      const section = node('details','','vp3-agent-activity-group');
      const count = found.reduce((total,item) => total + item.items.length,0);
      section.dataset.activityGroup = group.title;
      section.append(node('summary',group.title + ' (' + count + ')'));
      section.open = groupOpen.has(group.title) ? groupOpen.get(group.title) : nodes.length === 0;
      if (count) groupOpen.set(group.title,section.open);
      section.addEventListener('toggle',() => groupOpen.set(group.title,section.open));
      section.append(...found.map(source => renderSource(current,source)));
      if (count) nodes.push(section);
    }
    sections.replaceChildren(...nodes);
    const findings = ['Needs attention','Agent work'].flatMap(group => {
      const cards = coordinationCards(current,group);
      if (!cards.length) return [];
      const section = node('section','','vp3-agent-activity-opportunities-group');
      section.setAttribute('aria-label',group);
      section.dataset.opportunityGroup = group;
      section.append(node('h3',group),...cards);
      return [section];
    });
    const suggestions = snapshot.sections.find(section => section.id === 'suggestions');
    const ideas = suggestions?.items.filter(item => item.id.startsWith('opportunity:')).map(item => {
      const elsewhere = item.actions.some(action => action?.kind === 'chat' && action.conversation_id !== current.cid);
      return elsewhere ? {...item,detail:'From another saved Chat. ' + (item.detail || '')} : item;
    }) || [];
    if (ideas.length) {
      const ideasSection = node('section','','vp3-agent-activity-opportunities-group');
      ideasSection.setAttribute('aria-label','Ideas from Initiative');
      ideasSection.append(node('h3','Ideas from Initiative'),renderSource(current,{...suggestions,label:'',items:ideas}));
      findings.push(ideasSection);
    }
    const canvasSuggestions = snapshot.sections.find(section => section.id === 'canvas_suggestions');
    if (canvasSuggestions?.items.length) {
      const appSection = node('section','','vp3-agent-activity-opportunities-group');
      appSection.setAttribute('aria-label','App Suggestions');
      appSection.append(node('h3','App Suggestions'),renderSource(current,{...canvasSuggestions,label:''},true));
      findings.push(appSection);
    }
    const offer = snapshot.media_offer;
    if (offer?.kind === 'youtube_offer' && typeof offer.id === 'string' &&
      typeof offer.title === 'string' && typeof offer.detail === 'string' &&
      Array.isArray(offer.actions) && offer.actions.length === 1 &&
      offer.actions[0]?.kind === 'chat' && offer.actions[0].conversation_id === current.cid &&
      positive(offer.actions[0].message_id) && localUrl(offer.actions[0].href)) {
      const card = {id:offer.id,title:offer.title.slice(0,190),detail:offer.detail.slice(0,600),
        actions:[{...offer.actions[0],label:'View in Chat'}]};
      const source = renderSource(current,{id:'youtube-offer',label:'',items:[card]},true);
      const media = node('section','','vp3-agent-activity-opportunities-group');
      media.setAttribute('aria-label','YouTube pick');
      media.append(node('h3','YouTube pick'),source);
      findings.push(media);
    }
    orbSurface.opportunities.replaceChildren(...(findings.length ? findings :
      [node('p','No current opportunities or decisions to review.','vp3-agent-activity-status')]));
    contextLabel.textContent = [snapshot.agent_name,snapshot.current_chat?.title].filter(value => typeof value === 'string' && value.trim()).join(' · ');
    const checked = new Date(snapshot.observed_at).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'});
    status.textContent = '';
    verified.textContent = 'Items updated ' + checked;
    panel.querySelector('[data-agent-worker-details]').hidden = false;
    if (snapshot.coverage && Object.values(snapshot.coverage).some(value => value === 'limited' || value === 'unavailable')) {
      status.textContent = 'Some sources are unavailable.';
    }
    renderSummary();
    renderCoordination();
    renderSpecialists();
    window.VP3AgentOrb?.positionActivity?.();
  }
  async function read() {
    if (!visible() || controller || specialistSettings) return;
    if (imageStudioView || specialistMode && host?.dataset.imageStudio === 'true') { void imageStudioView?.refresh(); return; }
    if (connectedSitesView) { void connectedSitesView.refresh(); return; }
    if (profileView) { void profileView.refresh(); return; }
    const current = scope();
    if (!current) {
      if (!specialistMode || !scope(true)?.hasChat) close(false);
      else {
        clear();
        status.textContent = 'Waiting for Agent Chat to finish connecting…';
      }
      return;
    }
    const mine = generation;
    const request = new AbortController();
    controller = request;
    const timeout = setTimeout(() => request.abort(),10000);
    const selected = family(selectedSpecialist);
    const view = specialistMode && (['gmail','tasks','media','curiosity','transcripts','coding','marketing','profile','location','operator'].includes(selected) || selected === 'planning' && workspace) ? selected : '';
    const list = officeWorkspace() ? officeList : null;
    const fullInventory = officeWorkspace() && !list;
    try {
      const collection = list ? 'list_items' : fullInventory ? 'all' : view === 'tasks' ? 'reviews' : '';
      const url = activityUrl(current,view,collection,'',list?.id);
      const response = await requestActivity(url,current,request);
      const isCurrent = () => visible() && mine === generation && signature(current) === signature(scope()) && !request.signal.aborted;
      if (!isCurrent()) return;
      if (list && response.status === 403) {
        list.data = null;
        list.error = 'This list is no longer available.';
        resetOfficePage('list_items');
        render();
        return;
      }
      if ([401,403,419].includes(response.status)) {
        if (!specialistMode) { close(false); return; }
        accessRejected = true;
        clear();
        status.textContent = 'This Chat’s access could not be verified. ';
        status.append(button('Reload Chat',() => location.reload()));
        return;
      }
      const data = await response.json();
      if (!isCurrent()) return;
      if (!response.ok || data?.ok !== true || !validState(data.state,current)) throw new Error('Top of Mind could not be refreshed.');
      if (view === 'operator' && (!operatorHistoryModel(data.state.operator_history) || !operatorFollowupModel(data.state.operator_followups) || !operatorCalendarModel(data.state.operator_calendar))) throw new Error('The Operator history or follow-ups could not be verified.');
      if (view === 'profile' && (!profileReportModel(data.state.profile_report)
        || data.state.coverage?.profile_report !== data.state.profile_report.coverage)) throw new Error('The Profile report could not be verified.');
      accessRejected = false;
      if (list) {
        if (data.state.list_id !== list.id || data.state.sections.length !== 1 || !officeCollectionSource(data.state,'list_items')
          || !hasOfficePage(data.state,'list_items')) {
          throw new Error('List contents could not be verified.');
        }
        checkOfficePage(data.state,'list_items');
        list.data = data.state;
        list.error = '';
        render();
        refreshVisibleOfficePage(current);
        return;
      }
      if (fullInventory && ['reminders','tasks','lists'].some(id => !hasOfficePage(data.state,id) || !officeCollectionSource(data.state,id))) {
        throw new Error('The full Office inventory could not be verified.');
      }
      officeAllLoaded = fullInventory;
      if (fullInventory) for (const id of ['reminders','tasks','lists']) checkOfficePage(data.state,id);
      snapshot = data.state;
      render();
      if (fullInventory) refreshVisibleOfficePage(current);
    } catch (error) {
      if (visible() && mine === generation && signature(current) === signature(scope())) {
        if (list) {
          list.data = null;
          list.error = 'List contents could not be loaded.';
          resetOfficePage('list_items');
          render();
          return;
        }
        officeAllLoaded = false;
        snapshot = null;
        if (specialistMode) renderWorkspaceSources(current);
        else sections.replaceChildren();
        renderSummary();
        renderCoordination();
        renderSpecialists();
        verified.textContent = '';
        status.textContent = specialistMode && family(selectedSpecialist) === 'gmail'
          ? 'Inbox analysis could not be loaded. Retrying shortly.'
          : view === 'media' ? 'Media analysis could not be loaded. Retrying shortly.'
          : view === 'curiosity' ? 'The Curiosity report could not be loaded. Retrying shortly.'
          : view === 'profile' ? 'The Profile report could not be loaded. Retrying shortly.'
          : fullInventory ? 'Saved tasks, lists and reminders could not be loaded. Retrying shortly.'
          : specialistMode ? 'Activity could not be refreshed. Retrying shortly.'
            : 'The latest items are unavailable. Use Refresh to try again.';
        if (specialistMode) status.append(' ',button('Retry now',() => { stop(); void read(); }));
      }
    } finally {
      clearTimeout(timeout);
      if (controller === request) controller = null;
      if (visible() && mine === generation) {
        const inbox = snapshot?.sections.find(section => section.id === 'gmail_inbox')?.items || [];
        const monitor = inbox.find(item => item.id === 'gmail:inbox-monitor')?.monitor;
        const review = inbox.find(item => item.id === 'gmail:first-review');
        const checking = monitor?.state === 'working' && leaseCurrent(monitor.lease_until)
          || monitor?.analysis_work?.state === 'working' && leaseCurrent(monitor.analysis_work.lease_until)
          || review?.review_state === 'working' && leaseCurrent(review.review_lease_until);
        timer = setTimeout(() => void read(),checking ? 2500 : 12000);
      }
    }
  }
  function open(options = {}) {
    if (new URLSearchParams(location.search).get('orb_embed') === '1' && parent !== window) {
      if (!scope()) return false;
      return window.VP3AgentOrb?.openActivity?.(options) ?? false;
    }
    if (!scope()) return false;
    if (opened && specialistMode) close(false);
    if (!mount()) return false;
    if (orbSurface.rules) orbSurface.rules.host.hidden = !positive(scope()?.cid);
    if (!opened) {
      window.VP3AgentOrb?.prepareActivity?.();
      returnFocus = document.activeElement;
      opened = true;
      host.hidden = false;
      panel.hidden = false;
      panel.setAttribute('role','dialog');
      panel.setAttribute('aria-labelledby','vp3AgentActivityTitle');
      panel.removeAttribute('aria-label');
      root.dataset.activityOpen = 'true';
      delete root.dataset.initiativeDismissed;
      const brief = document.getElementById('chatAgentBriefButton');
      brief?.setAttribute('aria-expanded','true');
      brief?.setAttribute('aria-controls',host.id);
      status.textContent = 'Loading current items…';
      orbSurface.tabs.select(0);
      orbSurface.presenceObserver.observe(root,{attributes:true,attributeFilter:['data-state','data-eyes-active']});
      renderPresence();
      if (options.focus !== false) host.querySelector('h2').focus();
      window.dispatchEvent(new CustomEvent('vp3:agent-activity-view',{detail:{open:true}}));
    }
    selectedSpecialist = typeof options.specialist === 'string' && specialistKey.test(options.specialist) ? options.specialist : '';
    revealSpecialist = options.focus !== false && !!selectedSpecialist;
    renderCoordination();
    renderSpecialists();
    window.VP3AgentOrb?.positionActivity?.();
    void read();
    return true;
  }
  window.VP3AgentActivityHub = Object.freeze({open,openSpecialist,close,
    openProfile:(selection={},trigger=null) => {
      if (!window.VP3ProfileWorkspaceV1?.mountWorkspace || !window.VP3_PROFILE_WORKSPACE_V1?.endpoint) return false;
      return openSpecialist('profile',trigger,{label:'Profile',focusReturn:trigger||document.getElementById('chatInput'),fullReport:true,selection});
    },
    refreshProfile:() => { if (profileView) void profileView.refresh(); },
    stageExample:(id,cid) => {
      const current = scope();
      if (!current?.hasChat || current.cid !== cid) return false;
      if (!starterWorkspaces.has(id)) return stageSpecialistExample(id,0,current);
      if (opened && specialistMode && selectedSpecialist === id) {
        if (!workspace) showWorkspace(true);
        return true;
      }
      const trigger = document.querySelector(`[data-chat-specialist="${id}"]`);
      return openSpecialist(id,trigger,{focusReturn:document.getElementById('chatInput'),fullReport:true});
    },
    refresh:() => { stop(); void read(); }});
  window.dispatchEvent(new Event('vp3:agent-activity-ready'));
  window.addEventListener('vp3:chat-context-ready',() => {
    window.dispatchEvent(new Event('vp3:agent-activity-ready'));
    if (specialistMode && ['profile','location','operator'].includes(family(selectedSpecialist)) && specialistScope !== signature(scope(true))) {
      close(false);
      return;
    }
    if (specialistSettings && specialistSettings.scopeKey !== signature(scope())) { close(false); return; }
    if (specialistMode && visible() && !accessRejected) void read();
    renderPresence();
  });
  for (const name of ['focus','blur','stonefellow:chat-voice','vp3:agent-eyes-started','vp3:agent-eyes-stopped','vp3:chat-history-updated']) {
    window.addEventListener(name,renderPresence);
  }
  document.addEventListener('input',event => { if (event.target?.id === 'chatInput') renderPresence(); });
  window.addEventListener('vp3:agent-orb-ready',mount);
  window.addEventListener('vp3:agent-activity-open',event => open(event.detail || {}));
  window.addEventListener('vp3:chat-specialists-changed',renderSpecialists);
  window.addEventListener('stonefellow:chat-context-changing',() => close(false));
  window.addEventListener('vp3:orb-conversation-changed',() => { if (!window.STONEFELLOW_CHAT) close(false); });
  window.addEventListener('vp3:participant-selection-changed',() => close(false));
  window.addEventListener('vp3:participant-task-context',event => { participantActor = Number(event.detail?.participant_id) || 0; close(false); });
  for (const name of ['vp3:playback-history-changed','vp3:youtube-history-changed','vp3:chat-history-updated','vp3-coding-usage-change']) {
    window.addEventListener(name,() => {
      if (name === 'vp3:chat-history-updated') clearEngagementPages();
      if (visible()) { stop(); void read(); }
    });
  }
  window.addEventListener('vp3:initiative-reply-updated',() => {
    clearEngagementPages();
    if (visible()) { stop(); void read(); }
  });
  document.addEventListener('visibilitychange',() => {
    stop();
    if (visible() && specialistSettings && specialistSettings.scopeKey !== signature(scope())) close(false);
    else if (visible()) void read();
    else abortOfficeRequests();
  });
  window.addEventListener('resize',positionSpecialist);
  document.addEventListener('pointerdown',event => {
    if (opened && specialistMode && !workspace && !specialistSettings && !host.contains(event.target) && !specialistTrigger?.contains(event.target)) close(false);
  });
  document.addEventListener('keydown',event => {
    if (event.key !== 'Escape' || event.defaultPrevented || !opened) return;
    event.preventDefault();
    if (specialistSettings) closeSpecialistSettings();
    else if (specialistMode && workspace && !connectedSitesView && !imageStudioView && !profileView) showWorkspace(false);
    else close();
  });
  window.addEventListener('pagehide',() => { close(false); disposed = true; });
  window.addEventListener('pageshow',() => { disposed = false; });
  mount();
})();
