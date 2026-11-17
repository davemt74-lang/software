(() => {
  'use strict';

  const cfg = window.VP3_SCREEN_INSIGHTS;
  const thread = document.getElementById('chatThread');
  if (!cfg || !thread) return;

  const owner = Number(cfg.ownerKey);
  const agent = Number(cfg.agentId || 0);
  const records = new WeakMap();
  const drafts = new Map();
  const hash = /^[a-f0-9]{64}$/;
  const uuid = /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i;
  const identifier = value => Number.isSafeInteger(value) && value > 0;
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const el = (tag,text) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function activeChat(conversationId) {
    const chat = continuity();
    return thread.dataset.view === 'chat' && chat?.isReady?.() && !chat.isRestoring?.()
      && chat.agentId?.() === agent && chat.conversationId?.() === conversationId ? chat : null;
  }

  async function api(body,read = false) {
    const request = {...body,owner_key:String(cfg.ownerKey),agent_id:agent};
    const endpoint = new URL(cfg.endpoint,location.href);
    if (read) for (const [key,value] of Object.entries(request)) endpoint.searchParams.set(key,String(value));
    const response = await fetch(endpoint,{method:read ? 'GET' : 'POST',credentials:'same-origin',cache:'no-store',
      ...(read ? {} : {headers:{'Content-Type':'application/json'},body:JSON.stringify({...request,csrf_token:cfg.csrf})})});
    const data = await response.json();
    if (!response.ok || data?.ok !== true || String(data.owner_key) !== String(cfg.ownerKey) || data.agent_id !== agent) {
      const error = new Error(data?.error || 'Sharing follow-up access changed. Refresh Agent Chat.');
      error.status = response.ok ? 409 : response.status;
      throw error;
    }
    return data;
  }

  function link(text,value,file) {
    if (typeof value !== 'string' || !value) return null;
    let url;
    try { url = new URL(value,location.href); }
    catch (_) { return null; }
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password
      || !url.pathname.endsWith('/'+file)) return null;
    const anchor = el('a',text);
    anchor.href = url.href;
    return anchor;
  }

  function button(text,action) {
    const control = el('button',text);
    control.type = 'button';
    control.dataset.insightAction = action;
    return control;
  }

  function bindCard(card) {
    const block = el('article');
    block.dataset.insightKey = card.key;
    block.append(el('h3',card.title));
    const why = el('details');
    why.append(el('summary','Why this suggestion?'),el('p',card.why));
    for (const source of card.sources.slice(0,6)) {
      if (typeof source?.title !== 'string') continue;
      const date = new Date(source.captured_at || '');
      const title = Number.isFinite(date.getTime()) ? source.title+' · '+date.toLocaleString() : source.title;
      const anchor = link(title,source.url,'knowledge.php');
      if (anchor) why.append(anchor);
    }
    const actions = el('div');
    actions.className = 'vp3-screen-insight-actions';
    if (card.kind === 'wrapup') actions.append(button('Save session wrap-up','wrapup'));
    for (const [name,action] of [['Create Research project','research'],['Prepare Mission','mission'],['Set reminder…','remind'],
      ['Later (24 hours)','later'],['Dismiss','dismiss'],['Don’t suggest again','never']]) actions.append(button(name,action));
    block.append(why,actions);
    return block;
  }

  function bindHandoff(handoff) {
    const block = el('article');
    block.dataset.handoffId = handoff.handoff_id;
    const status = {prepared:'Prepared',preparing:'Preparing',unconfirmed:'Could not verify',source_changed:'Source changed'}[handoff.status];
    const name = handoff.kind === 'research' ? 'Research project' : 'Mission';
    block.append(el('strong',[name,status].filter(Boolean).join(' · ')));
    if (!handoff.source_current) {
      block.append(el('p','The source changed or was removed. Prepare a new follow-up from the current report.'));
      return block;
    }
    const actions = el('div');
    actions.className = 'vp3-screen-insight-actions';
    if (handoff.kind === 'research') {
      const anchor = link('Open Research project',handoff.url,'research-project.php');
      if (anchor) actions.append(anchor);
    }
    if (handoff.kind === 'mission' && typeof handoff.target_id === 'string' && uuid.test(handoff.target_id)) {
      actions.append(button('Open Mission review','open_mission'));
    }
    if (typeof handoff.target_id === 'string' && handoff.target_id) {
      actions.append(button('Save latest results to session folder','sync'));
    }
    if (actions.children.length) block.append(actions);
    return block;
  }

  function render(record) {
    const {cards,handoffs,content} = record;
    const expanded = new Set(Array.from(content.querySelectorAll('[data-insight-key]')).filter(node => node.querySelector('details')?.open)
      .map(node => node.dataset.insightKey));
    const blocks = cards.map(card => {
      const block = bindCard(card);
      block.querySelector('details').open = expanded.has(card.key);
      return block;
    });
    blocks.push(...handoffs.map(bindHandoff));
    const choices = el('div');
    choices.className = 'vp3-screen-insight-actions vp3-screen-insight-choices';
    choices.append(button('Restore dismissed suggestions','reset'));
    content.replaceChildren(...blocks,choices);
  }

  function mount(element,report) {
    const body = element?.querySelector('.message-body');
    if (!body || element.dataset.chatMessageRole !== 'assistant') return false;
    const conversationId = Number(element.dataset.chatConversationId);
    const messageId = Number(element.dataset.chatMessageId);
    const prior = records.get(element);
    if (!report || report.owner_user_id !== owner || report.agent_id !== agent
      || !identifier(messageId) || report.message_id !== messageId || !identifier(conversationId) || report.conversation_id !== conversationId
      || !Array.isArray(report.cards) || !Array.isArray(report.handoffs)) {
      prior?.host.remove();
      records.delete(element);
      return false;
    }
    const cards = report.cards.slice(0,3).filter(card => hash.test(card?.key || '') && hash.test(card?.review_hash || '')
      && typeof card.title === 'string' && typeof card.why === 'string' && Array.isArray(card.sources));
    const handoffs = report.handoffs.slice(0,20).filter(item => uuid.test(item?.handoff_id || '') && ['research','mission'].includes(item.kind));
    const sessionId = uuid.test(report.session_id || '') ? report.session_id : '';
    const signature = JSON.stringify([cards,handoffs,sessionId]);
    if (prior?.signature === signature) return true;
    if (prior) {
      Object.assign(prior,{cards,handoffs,signature,sessionId});
      render(prior);
      return true;
    }
    const host = el('section');
    host.className = 'vp3-screen-insight-report';
    host.setAttribute('aria-label','Screen-sharing report');
    const content = el('div');
    const status = el('p');
    status.className = 'vp3-screen-insight-status';
    status.setAttribute('role','status');
    host.append(content,status);
    body.append(host);
    const record = {element,host,content,status,conversationId,messageId,sessionId,cards,handoffs,signature,busy:false,refreshVersion:0};
    records.set(element,record);
    render(record);
    host.addEventListener('click',event => void handleAction(record,event));
    return true;
  }

  function currentRecord(record) {
    return records.get(record.element) === record && thread.contains(record.element) && !!activeChat(record.conversationId);
  }

  async function refresh(record) {
    if (!currentRecord(record)) return;
    const version = ++record.refreshVersion;
    let result;
    try {
      result = await api({action:'chat_report',conversation_id:record.conversationId,message_id:record.messageId},true);
    } catch (error) {
      if (![401,403,409,419].includes(error.status)) throw error;
      result = {answer:'This screen-share wrap-up is unavailable.',report:null};
    }
    if (!currentRecord(record) || record.refreshVersion !== version) return;
    const body = record.element.querySelector('.message-text');
    if (body && typeof result.answer === 'string') body.textContent = result.answer;
    record.element._vp3ScreenInsightsV1 = result.report || null;
    mount(record.element,result.report);
  }

  function replyStatus(record,text,savedUrl = null) {
    if (!currentRecord(record)) return;
    record.status.textContent = text;
    const anchor = link('Open in My Knowledge',savedUrl,'knowledge.php');
    if (anchor) record.status.append(' ',anchor);
  }

  function handoffId(card,action) {
    const key = card.key+'|'+card.review_hash+'|'+action;
    let id = drafts.get(key);
    if (!id) {
      const storageKey = 'vp3-screen-handoff:'+cfg.ownerKey+':'+agent+':'+key;
      try { id = sessionStorage.getItem(storageKey); } catch (_) {}
      if (!uuid.test(id || '')) id = crypto.randomUUID();
      try { sessionStorage.setItem(storageKey,id); } catch (_) {}
      drafts.set(key,id);
    }
    return id;
  }

  function stageReminder(record,card) {
    const source = link('Open source',card.sources[0]?.url,'knowledge.php');
    if (!source) throw new Error('The reminder source is unavailable.');
    const chat = activeChat(record.conversationId);
    const draft = 'Remind me to follow up on '+card.title+'. Source: '+source.href;
    if (!chat?.stageSpecialistPrompt?.(draft,record.conversationId)) throw new Error('Finish your current Chat activity before adding a reminder.');
    const mode = document.getElementById('vp3ChatTaskMode');
    if (mode) mode.value = 'chat';
  }

  async function handleAction(record,event) {
    const pressed = event.target.closest('[data-insight-action]');
    if (!pressed || record.busy || !currentRecord(record)) return;
    const action = pressed.dataset.insightAction;
    const card = record.cards.find(item => item.key === pressed.closest('[data-insight-key]')?.dataset.insightKey);
    const handoff = record.handoffs.find(item => item.handoff_id === pressed.closest('[data-handoff-id]')?.dataset.handoffId);
    const scope = {conversation_id:record.conversationId,report_message_id:record.messageId};
    record.busy = true;
    pressed.disabled = true;
    let refreshNeeded = false;
    try {
      if (action === 'reset') {
        await api({...scope,action:'reset_suggestions'});
        replyStatus(record,'Suggestions restored.');
        refreshNeeded = true;
      } else if (action === 'sync' && handoff?.source_current) {
        const saved = await api({...scope,action:'sync_results',handoff_id:handoff.handoff_id});
        replyStatus(record,'Latest results saved to the session Knowledge folder.',saved.url);
      } else if (action === 'open_mission' && handoff?.source_current) {
        if (!window.VP3_SCREEN_OPEN_MISSION) throw new Error('Open Agent Teams in Chat to review this Mission.');
        await window.VP3_SCREEN_OPEN_MISSION(handoff.target_id);
      } else if (!card) {
        throw new Error('The suggestion could not be verified. Refresh Agent Chat.');
      } else if (['later','dismiss','never'].includes(action)) {
        await api({...scope,action:'decide',key:card.key,review_hash:card.review_hash,decision:action});
        replyStatus(record,{later:'Suggestion snoozed for 24 hours.',dismiss:'Suggestion dismissed.',never:'Suggestion turned off.'}[action]);
        refreshNeeded = true;
      } else if (action === 'wrapup') {
        const saved = await api({...scope,action:'wrapup',key:card.key,review_hash:card.review_hash});
        replyStatus(record,'Session wrap-up saved to My Knowledge.',saved.url);
      } else if (action === 'remind') {
        await refresh(record);
        if (!currentRecord(record) || !record.cards.some(item => item.key === card.key && item.review_hash === card.review_hash)) {
          throw new Error('The suggestion changed. Choose a current source before adding a reminder.');
        }
        stageReminder(record,card);
        replyStatus(record,'Reminder request added to Chat. Choose a time before saving.');
      } else if (['research','mission'].includes(action)) {
        const saved = await api({...scope,action:'handoff',key:card.key,review_hash:card.review_hash,kind:action,handoff_id:handoffId(card,action)});
        replyStatus(record,action === 'research' ? 'Research project created with draft source observations.'
          : 'Mission prepared. Review assignments in Agent Teams before starting.');
        refreshNeeded = true;
        if (saved.prepared_mission && currentRecord(record) && window.VP3_SCREEN_PREPARED_MISSION) {
          await window.VP3_SCREEN_PREPARED_MISSION(saved.prepared_mission);
        }
      }
    } catch (error) {
      replyStatus(record,error.message);
      if ([401,403,409,419].includes(error.status)) refreshNeeded = true;
    } finally {
      pressed.disabled = false;
      record.busy = false;
      if (refreshNeeded) void refresh(record).catch(error => replyStatus(record,error.message));
    }
  }

  window.VP3_CHAT_SCREEN_INSIGHTS_V1 = {mount};
  for (const element of thread.querySelectorAll('[data-chat-message-role="assistant"]')) {
    if (element._vp3ScreenInsightsV1) mount(element,element._vp3ScreenInsightsV1);
  }

  window.addEventListener('vp3:screen-report-posted',event => {
    const {conversation_id:conversationId,message_id:messageId} = event.detail || {};
    if (!identifier(conversationId) || !identifier(messageId)) return;
    const chat = activeChat(conversationId);
    if (chat) void Promise.resolve(chat.syncConversation?.(conversationId)).catch(() => {});
  });

  function refreshAffectedReports(sessionId,captureId) {
    const active = Array.from(thread.querySelectorAll('[data-chat-message-role="assistant"]'))
      .map(element => records.get(element)).filter(Boolean);
    const matched = active.filter(record => (sessionId && record.sessionId === sessionId)
      || record.cards.some(card => card.sources.some(source => (sessionId && source.session_id === sessionId) || (captureId && source.capture_id === captureId))));
    const fallback = (sessionId || captureId ? active.filter(record => !record.cards.length && (!sessionId || !record.sessionId)) : active).slice(-4);
    for (const record of new Set([...matched,...fallback])) void refresh(record).catch(error => replyStatus(record,error.message));
  }

  window.addEventListener('vp3:screen-observation-reviewed',event => {
    const {sessionId,captureId} = event.detail || {};
    refreshAffectedReports(sessionId,captureId);
  });
})();
