(() => {
  'use strict';
  const cfg = window.VP3_GMAIL_INBOX_INITIAL_V1;
  if (!cfg?.endpoint || !cfg.connectionsUrl || !cfg.csrf || typeof cfg.ownerKey !== 'string' || !/^[1-9]\d*$/.test(cfg.ownerKey)) return;
  const endpoint = new URL(cfg.endpoint, location.href);
  const destination = new URL(cfg.connectionsUrl, location.href);
  const chatUrl = new URL(cfg.chatUrl || '/chat.php',location.href);
  if (endpoint.origin !== location.origin || destination.origin !== location.origin || chatUrl.origin !== location.origin) return;

  const host = document.querySelector('[data-gmail-initial-review]');
  const thread = host ? null : document.getElementById('chatThread');
  const app = host?.closest('details.connections-app');
  const form = host?.querySelector('[data-gmail-review-start]');
  const status = host?.querySelector('[data-gmail-review-status]');
  const result = host?.querySelector('[data-gmail-review-report]');
  const latestSection = app?.querySelector('[data-gmail-review-latest-section]');
  const start = form?.querySelector('button[type="submit"]');
  // A paid start belongs to the mailbox and settings rendered with this page.
  const renderedToken = cfg.initialReview?.start_token;
  const startToken = host && typeof renderedToken === 'string' && /^[a-f0-9]{64}$/.test(renderedToken) ? renderedToken : null;
  const reloadMessage = 'Gmail or Inbox review settings changed. Reload to continue.';
  const owner = cfg.ownerKey;
  const composeKey = `vp3:gmail-review-compose:${owner}`;
  const agent = cfg.agentId;
  const binding = window.STONEFELLOW_CHAT?.workingAttentionBinding;
  const key = 'gmail-inbox-review';
  const daysAllowed = [1,3,7,14,30];
  const labels = {unavailable:'First review is unavailable.',disabled:'Enable Inbox review to begin.',needs_start:'Choose how far back to review.',queued:'Queued',working:'Working',ready:'Ready',failed:'Failed'};
  const categories = {new:'New',important:'Important',reply:'Incoming reply'};
  const states = new Set(Object.keys(labels));
  const timestamp = value => typeof value === 'string'
    ? Date.parse(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ','T') + 'Z' : value) : NaN;
  const active = value => value?.state === 'queued' || value?.state === 'working';
  const inboxWorking = value => value?.state === 'ready' && timestamp(value.inbox_working_until) > Date.now();
  const canStart = value => value?.state === 'needs_start' || value?.state === 'failed';
  const valid = value => value && states.has(value.state)
    && (!(active(value) || value.state === 'ready') || typeof value.run_id === 'string' && /^[a-f0-9]{32}$/.test(value.run_id));
  let review = host && valid(cfg.initialReview) ? cfg.initialReview : null;
  let pending = null;
  let timer = 0;
  let progressTimer = 0;
  let progressRead = null;
  let epoch = 0;
  let participant = 0;
  let stopped = false;
  let denied = false;
  let refreshPending = false;
  let pageError = '';
  let railSignature = '';
  let railTimer = 0;
  let publishedWork = false;
  let completion = null;
  let completionSignature = '';
  let delivery = null;
  const projectedRows = new WeakSet();
  let reportSignature = '';
  let reviewSignal = null;
  let openedMessage = null;
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;

  function inScope() {
    if (stopped || denied || document.hidden || window.VP3_GMAIL_INBOX_INITIAL_V1 !== cfg || cfg.ownerKey !== owner
        || participant > 0 || window.VP3ParticipantContinuity?.selectedContext?.()) return false;
    if (host) return host.isConnected && (!app || app.open);
    const chat = window.STONEFELLOW_CHAT;
    const live = continuity();
    return String(chat?.userId) === owner && Number(chat?.agentId || 0) === agent
      && chat?.workingAttentionBinding === binding && live?.agentId?.() === agent
      && live?.isReady?.() === true && !live?.isRestoring?.();
  }

  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const itemRef = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const replyCommand = value => /^(?:Reply|Draft reply|Auto reply) to Gmail review item [a-f0-9]{64}$/.test(value);
  function itemButton(label,action,handler) {
    const button = node('button',label);
    button.type = 'button';
    button.dataset.gmailReviewAction = action;
    button.addEventListener('click',event => { if (event.isTrusted && inScope()) handler(button); });
    return button;
  }

  function compose(command) {
    if (!inScope()) return false;
    if (host) {
      try { sessionStorage.setItem(composeKey,JSON.stringify({command,at:Date.now()})); }
      catch (_) { return false; }
      location.assign(chatUrl.href);
      return true;
    }
    const input = document.getElementById('chatInput');
    const chat = continuity();
    if (!input || input.value.trim() || chat?.isBusy?.()) return false;
    if (replyCommand(command)) return chat?.requestGmailReviewAction?.(command) === true;
    chat?.showChatView?.();
    input.value = command;
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.focus();
    return true;
  }

  function restoreCompose() {
    if (host || !inScope()) return;
    try {
      const saved = JSON.parse(sessionStorage.getItem(composeKey) || 'null');
      if (!saved) return;
      sessionStorage.removeItem(composeKey);
      if (Number.isSafeInteger(saved.at) && saved.at <= Date.now() && Date.now()-saved.at < 120000
        && typeof saved.command === 'string' && saved.command.length <= 500
        && /^(?:(?:Draft reply|Reply) to Gmail review item [a-f0-9]{64}$|Search Gmail for from:)/.test(saved.command)) compose(saved.command);
    } catch (_) { /* No private composer handoff is available. */ }
  }

  function closeMessage() {
    if (!openedMessage) return;
    openedMessage.controller.abort();
    openedMessage.button.setAttribute('aria-expanded','false');
    openedMessage.panel.remove();
    openedMessage = null;
  }

  async function openMessage(row,ref,button) {
    const toggle = openedMessage?.button === button;
    closeMessage();
    if (toggle) return;
    const controller = new AbortController();
    const panel = node('div','','gmail-review-open-message');
    const status = node('p','Opening this Gmail message…');
    status.setAttribute('role','status');
    panel.append(status);
    row.append(panel);
    button.setAttribute('aria-expanded','true');
    const selection = openedMessage = {controller,panel,button};
    const current = () => openedMessage === selection && panel.isConnected && inScope();
    const timeout = setTimeout(() => controller.abort(),12000);
    try {
      const response = await send({action:'open_item',item_ref:ref},controller.signal);
      const data = await response.json();
      if (!current()) return;
      const message = data?.item;
      if (!response.ok || data.ok !== true || !['subject','from','body'].every(field => typeof message?.[field] === 'string')) {
        throw Error('Gmail item unavailable');
      }
      panel.replaceChildren(node('strong',message.subject || '(No subject)'),node('small',message.from),
        node('p',message.body || 'No readable text in this message.'));
      if (thread && message.auto_reply === true && typeof message.auto_reply_to === 'string' && message.auto_reply_to) {
        panel.append(node('small','Auto reply sends to ' + message.auto_reply_to + '.'));
        panel.append(itemButton('Auto reply and send (uses tokens)','auto-reply',auto => {
          if (compose(`Auto reply to Gmail review item ${ref}`)) {
            auto.disabled = true;
            return;
          }
          status.textContent = 'Finish your current Chat draft before using Auto reply.';
          panel.append(status);
        }));
      }
    } catch (_) {
      if (current()) status.textContent = 'This Gmail message could not be opened. Check Inbox Review and try again.';
    } finally {
      clearTimeout(timeout);
    }
  }

  function attachItemActions(row,item) {
    const actions = node('div','','gmail-review-item-actions');
    const feedback = node('small');
    feedback.setAttribute('role','status');
    const request = command => {
      feedback.textContent = compose(command) ? '' : 'Finish your current Chat draft or open Agent Chat first.';
    };
    const ref = item.item_ref;
    if (itemRef(ref)) {
      const open = itemButton('Open','open',button => void openMessage(row,ref,button));
      open.setAttribute('aria-expanded','false');
      actions.append(open,itemButton('Reply','reply',() => request(`Reply to Gmail review item ${ref}`)),
        itemButton('Draft reply (uses tokens)','draft-reply',() => request(`Draft reply to Gmail review item ${ref}`)));
    } else if (/^[^\s"\\]+@[^\s"\\]+$/.test(item.from)) {
      const subject = item.subject.replace(/["\\\r\n]/g,' ').trim().slice(0,180);
      const query = `from:${item.from}` + (subject ? ` subject:"${subject}"` : '');
      actions.append(itemButton('Find matches in Chat','find-matches',() => request(`Search Gmail for ${query}`)));
    }
    if (actions.children.length) row.append(actions,feedback);
  }

  function itemList(items) {
    if (!Array.isArray(items)) return null;
    const list = node('ul','','connections-inbox-messages gmail-review-items');
    list.setAttribute('role','list');
    for (const item of items.slice(0,8)) {
      if (!item || typeof item.subject !== 'string' || typeof item.from !== 'string') continue;
      const row = node('li');
      row.append(node('strong',item.subject.slice(0,500) || '(No subject)'),
        node('span',item.from.slice(0,500),'connections-muted'),node('span',categories[item.category] || 'New','connections-badge'));
      attachItemActions(row,item);
      list.append(row);
    }
    return list.children.length ? list : null;
  }

  function appendItems(parent,label,items) {
    const list = itemList(items);
    if (!list) return;
    const details = node('details','','gmail-review-item-group');
    details.append(node('summary',`${label} (${list.children.length})`),list);
    parent.append(details);
  }
  function detail(value) {
    if (active(value) && value.stage === 'summary') return value.state === 'working' ? 'Preparing the AI summary.' : 'AI summary is queued.';
    if (active(value) && Number.isSafeInteger(value.scanned) && value.scanned > 0 && value.scanned <= 50) {
      return `${value.scanned} of up to 50 headers checked.`;
    }
    if (value?.state === 'working') return 'Checking recent Gmail headers.';
    if (value?.state === 'failed') return 'The first review could not finish.';
    return '';
  }

  function workLease() {
    if (!inScope() || pageError || !valid(review)) return null;
    const expiresAt = timestamp(review.state === 'working' ? review.next_attempt_at
      : review.state === 'ready' ? review.inbox_working_until : null);
    return expiresAt > Date.now() ? {id:'gmail:'+review.run_id,expiresAt} : null;
  }
  function renderRail() {
    const lease = workLease();
    const description = lease && (review.state === 'ready' ? 'Checking new Gmail messages.' : detail(review));
    const next = lease ? JSON.stringify([lease.id,lease.expiresAt,description]) : '';
    if (next === railSignature) return;
    railSignature = next;
    clearTimeout(railTimer);
    if (!lease) {
      window.VP3ChatSpecialists?.remove(key);
      return;
    }
    window.VP3ChatSpecialists?.update(key,{
      label:'Gmail Inbox Review',status:labels.working,detail:description,expiresAt:lease.expiresAt,
    });
    railTimer = setTimeout(() => { renderRail(); renderCompletion(); },Math.max(0,lease.expiresAt-Date.now()));
  }

  function chatTarget() {
    if (!thread || !inScope() || thread.dataset.view !== 'chat') return null;
    const id = continuity()?.conversationId?.();
    return Number.isSafeInteger(id) && id >= 0 ? id : null;
  }

  function savedReviewRow(conversationId, runId = null) {
    if (!conversationId) return null;
    const rows = thread.querySelectorAll(`[data-chat-conversation-id="${conversationId}"][data-chat-message-role="assistant"]`);
    for (const row of rows) {
      const marker = row._vp3GmailInboxReviewV1;
      if (!marker) {
        // An older Chat asset can load the stored message without projecting its Gmail metadata.
        if (runId !== null && delivery?.run_id === runId && delivery.agent_id === agent
            && delivery.conversation_id === conversationId && String(delivery.message_id) === row.dataset.chatMessageId) return row;
        continue;
      }
      if ((runId !== null && marker.run_id !== runId)
          || marker.agent_id !== agent || marker.conversation_id !== conversationId
          || !Number.isSafeInteger(marker.message_id) || marker.message_id < 1
          || String(marker.message_id) !== row.dataset.chatMessageId || !itemRef(marker.report_ref)) continue;
      if (!valid(marker.review) || marker.review.state !== 'ready' || marker.review.run_id !== marker.run_id) continue;
      if (delivery?.run_id === marker.run_id && delivery.conversation_id === conversationId
          && (marker.message_id !== delivery.message_id || marker.report_ref !== delivery.report_ref)) continue;
      return row;
    }
    return null;
  }

  function needsDelivery(conversationId) {
    return conversationId !== null && typeof binding === 'string' && binding !== '' && review?.state === 'ready'
      && review.has_report === true && !continuity()?.isBusy?.()
      && !(delivery?.run_id === review.run_id && delivery.conversation_id === conversationId)
      && !savedReviewRow(conversationId,review.run_id);
  }

  function renderCompletion() {
    if (!thread) return;
    const target = chatTarget();
    // An ordinary Chat load already checked the current grant. Reuse that
    // projection once, then require a fresh read after leaving its context.
    if (review === null) {
      const loaded = savedReviewRow(target);
      if (loaded && !projectedRows.has(loaded)) {
        const marker = loaded._vp3GmailInboxReviewV1;
        accept(marker.review,marker.run_id);
      }
    }
    const visible = target !== null && !pageError;
    const ready = visible && review?.state === 'ready' && review.has_report === true
      && Number.isSafeInteger(review.total) && review.total >= 0 && review.total <= 50;
    const liveWork = visible && review?.state === 'working' && workLease();
    const progress = visible && (liveWork || ['queued','failed'].includes(review?.state));
    const row = ready ? savedReviewRow(target,review.run_id) : null;
    if (row) projectedRows.add(row);
    const parent = progress ? thread : row?.querySelector('.message-body');
    if (!parent || (completion && completion.parentElement !== parent)) {
      if (openedMessage && completion?.contains(openedMessage.panel)) closeMessage();
      completion?.remove();
      completion = null;
      completionSignature = '';
      if (!parent) return;
    }

    const reviewed = review.total === 1 ? '1 inbox message' : `${review.total} inbox messages`;
    const headline = ready ? `Reviewed ${reviewed}.`
      : [`Gmail Inbox Review · ${labels[review.state]}`,liveWork ? '' : detail(review)].filter(Boolean).join('. ');
    const saved = ready ? review.chat_result : null;
    const summary = typeof saved?.summary === 'string' ? saved.summary.slice(0,8000).trim() : '';
    const latest = saved?.latest;
    const batchAt = timestamp(latest?.at);
    const batch = Number.isFinite(batchAt) && Number.isSafeInteger(latest.total) && latest.total > 0
      && [latest.important,latest.replies].every(count => Number.isSafeInteger(count) && count >= 0 && count <= latest.total)
      ? `Latest inbox update · ${new Date(batchAt).toLocaleString()} · ${latest.total} new · ${latest.important} important · ${latest.replies} ${latest.replies === 1 ? 'reply' : 'replies'}`
      : '';
    const signature = JSON.stringify([review.run_id,headline,summary,batch,saved?.items,latest?.items]);
    if (completion?.isConnected && completionSignature === signature) return;
    closeMessage();
    const nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 100;
    const mounted = !completion?.isConnected;
    if (mounted) {
      completion = node('div','','vp3-chat-gmail-review');
      parent.append(completion);
    }
    completionSignature = signature;
    completion.dataset.gmailReviewState = review.state;
    if (ready) {
      completion.id = 'gmail-inbox-review';
      completion.dataset.gmailReviewComplete = review.run_id;
    } else delete completion.dataset.gmailReviewComplete;
    const status = node('span',headline);
    status.setAttribute('role','status');
    const header = node('div','','vp3-chat-gmail-review-heading');
    header.append(status);
    if (ready) {
      const link = node('a','Open review');
      link.href = destination.href;
      if (new URLSearchParams(location.search).get('orb_embed') === '1' && window.parent !== window) link.target = '_top';
      header.append(link);
    } else if (liveWork) {
      const viewWork = itemButton('View work','view-work',button => {
        if (workLease()) window.VP3ChatSpecialists?.open?.(key,button);
      });
      viewWork.setAttribute('aria-haspopup','dialog');
      viewWork.setAttribute('aria-controls','vp3-chat-specialist-panel-' + key);
      viewWork.setAttribute('aria-expanded','false');
      header.append(viewWork);
    }
    completion.replaceChildren(header);
    if (summary) completion.append(node('p',summary));
    appendItems(completion,'Messages in first review',saved?.items);
    if (batch) completion.append(node('small',batch));
    if (batch) appendItems(completion,'Messages in latest update',latest?.items);
    if (ready && mounted && location.hash === '#gmail-inbox-review') completion.scrollIntoView({block:'center'});
    else if (nearBottom) thread.scrollTop = thread.scrollHeight;
  }

  function publishWork() {
    const orb = window.VP3AgentOrb;
    if (!orb?.setJobs) return;
    const lease = workLease();
    if (lease || publishedWork) orb.setJobs('gmail',lease ? [lease] : []);
    publishedWork = Boolean(lease);
  }

  function renderReport(report) {
    if (!result) return;
    const signature = report ? JSON.stringify(report) : '';
    if (signature === reportSignature) return;
    reportSignature = signature;
    if (openedMessage && result.contains(openedMessage.panel)) closeMessage();
    result.replaceChildren();
    if (!report || typeof report !== 'object' || !daysAllowed.includes(report.days)) return;
    const time = timestamp(report.at);
    const period = [report.days === 1 ? 'Past day' : `Past ${report.days} days`];
    if (Number.isFinite(time)) period.push(new Date(time).toLocaleString());
    const counts = node('dl','','connections-inbox-counts');
    for (const [label,value] of [['Headers checked',report.scanned],['Reviewed',report.total],
      ['Important',report.important],['Replies',report.replies],['Ignored',report.filtered]]) {
      if (!Number.isSafeInteger(value) || value < 0 || value > 50) continue;
      const row = node('div');
      row.append(node('dt',label),node('dd',String(value)));
      counts.append(row);
    }
    result.append(node('p',period.join(' · '),'connections-muted'),counts);
    if (report.truncated === true) result.append(node('p',report.scanned >= 50
      ? 'This review reached the 50-header limit.' : 'More matching mail may be available.','connections-muted'));
    const summary = report.summary_status === 'ready' && typeof report.summary === 'string' && report.summary.trim()
      ? report.summary.slice(0,8000) : ({no_messages:'No messages matched this review.',unavailable:'The scan is saved. AI summary was unavailable.',
        unverified:'The scan is saved. The AI summary could not be verified.'})[report.summary_status];
    if (summary) result.append(node('p',summary,'connections-inbox-review-summary'));
    result.append(node('small',Number.isSafeInteger(report.tokens_charged) && report.tokens_charged >= 0
      ? `${report.tokens_charged.toLocaleString()} VP3 tokens charged` : 'VP3 tokens charged: unknown','connections-muted'));
    const list = itemList(report.items);
    if (list) result.append(list);
  }

  function render() {
    publishWork();
    if (!host) { renderRail(); renderCompletion(); return; }
    const line = review?.state === 'ready' ? 'First review complete.'
      : review ? [labels[review.state],detail(review)].filter(Boolean).join(' · ') : '';
    if (status) status.textContent = pageError || (!startToken && canStart(review) ? reloadMessage : line);
    host.dataset.reviewState = review?.state || '';
    if (form) form.hidden = denied || !startToken || !canStart(review);
    if (start) {
      start.disabled = Boolean(pending);
      start.textContent = review?.state === 'failed' ? 'Retry first review' : 'Start first review';
    }
    if (latestSection) latestSection.hidden = denied || !review || ['unavailable','disabled'].includes(review.state);
    renderReport(startToken && review?.has_report === true ? review.report : null);
  }

  function schedule(action, delay) {
    clearTimeout(timer);
    if (!inScope()) return;
    timer = setTimeout(() => void request(action), delay);
  }
  function nextCheck() {
    if (!active(review)) {
      if (needsDelivery(chatTarget())) {
        schedule('deliver',0);
        return;
      }
      // A review can start in Connections and finish in a separate worker.
      if (thread && ['needs_start','ready','failed'].includes(review?.state)) {
        schedule('status',inboxWorking(review) ? 2000 : 15000);
      }
      return;
    }
    const nextAt = timestamp(review.next_attempt_at);
    if (review.state === 'queued' && (!Number.isFinite(nextAt) || nextAt <= Date.now())) schedule('advance',600);
    else schedule('status',review.state === 'working' ? 2000
      : Number.isFinite(nextAt) && nextAt > Date.now() ? Math.min(15000,Math.max(1500,nextAt-Date.now())) : 2500);
  }

  function send(payload,signal) {
    return fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal,
      headers:{'Content-Type':'application/json'},body:JSON.stringify({owner_key:owner,csrf_token:cfg.csrf,
        ...(['open_item','deliver'].includes(payload.action) ? {} : host ? {include_report:true} : thread ? {include_chat_result:true} : {}),...payload})});
  }
  function accept(value, expectedRunId = null) {
    if (host && (value.start_token ?? null) !== startToken) {
      closeMessage();
      denied = true;
      review = null;
      pageError = reloadMessage;
      return;
    }
    if (expectedRunId !== null && value.run_id !== expectedRunId) return;
    const {state,run_id,stage,scanned,next_attempt_at,has_report,total,chat_result,inbox_working_until} = value;
    review = host ? value : {state,run_id,stage,scanned,next_attempt_at,has_report,total,chat_result,inbox_working_until};
    pageError = '';
  }
  function stopProgress() {
    clearTimeout(progressTimer);
    progressRead?.abort();
    progressRead = null;
  }
  function watchProgress(controller,current,runId,delay=2000) {
    const applicable = () => pending?.controller === controller && current === epoch && inScope();
    if (!applicable()) return;
    progressTimer = setTimeout(async () => {
      if (!applicable()) return;
      const read = new AbortController();
      progressRead = read;
      const timeout = setTimeout(() => read.abort(),8000);
      try {
        const response = await send({action:'status'},read.signal);
        const data = await response.json();
        if (applicable() && progressRead === read && response.ok && data?.ok === true && valid(data.review)) {
          accept(data.review,runId);
          render();
        }
      } catch (_) { /* The active slice owns retries and errors. */ }
      finally {
        clearTimeout(timeout);
        if (progressRead === read) progressRead = null;
        watchProgress(controller,current,runId);
      }
    },delay);
  }

  async function request(action, days) {
    if (pending || !inScope()) return;
    const payload = {action};
    const target = action === 'deliver' ? chatTarget() : null;
    if (action === 'deliver' && !needsDelivery(target)) return;
    if (action === 'start') {
      if (!host || !startToken || !canStart(review) || !daysAllowed.includes(days)) return;
      payload.days = days;
      payload.start_token = startToken;
    } else if (action === 'advance') {
      if (review?.state !== 'queued' || !valid(review)) return;
      payload.run_id = review.run_id;
    }
    const controller = new AbortController();
    const current = epoch;
    pending = {action,controller};
    if (start) start.disabled = true;
    const timeout = setTimeout(() => controller.abort(),{status:8000,start:15000,advance:120000,deliver:18000}[action]);
    let failed = false;
    try {
      if (action === 'deliver') {
        const runId = review.run_id;
        const conversation = target || await continuity()?.ensureConversation?.();
        if (current !== epoch || !inScope()) return;
        if (!conversation || conversation !== chatTarget() || review?.run_id !== runId) {
          failed = true;
          return;
        }
        Object.assign(payload,{run_id:runId,agent_id:agent,conversation_id:conversation,working_attention_binding:binding});
      }
      if (action === 'advance') watchProgress(controller,current,payload.run_id,350);
      const response = await send(payload,controller.signal);
      const data = await response.json();
      if (current !== epoch || !inScope()) return;
      if (action === 'deliver' && response.status === 409) {
        failed = true;
        return;
      }
      if ([401,403,409,419].includes(response.status)) {
        closeMessage();
        denied = true;
        review = null;
        pageError = 'Reload to check Inbox review for your current account.';
        return;
      }
      if (action === 'deliver' && response.ok && data?.ok === true) {
        const anchor = data.delivery;
        if (payload.conversation_id !== chatTarget() || payload.run_id !== review?.run_id) return;
        if (['bound_elsewhere','removed'].includes(anchor?.state)) {
          delivery = {run_id:payload.run_id,conversation_id:payload.conversation_id};
          return;
        }
        if (!['posted','existing'].includes(anchor?.state) || anchor.run_id !== payload.run_id
            || anchor.agent_id !== agent || anchor.conversation_id !== payload.conversation_id
            || !Number.isSafeInteger(anchor.message_id) || anchor.message_id < 1 || !itemRef(anchor.report_ref)
            || !valid(data.review) || data.review.state !== 'ready' || data.review.run_id !== payload.run_id) {
          throw Error('Saved Gmail review anchor unavailable');
        }
        delivery = anchor;
        accept(data.review,payload.run_id);
        if (!savedReviewRow(payload.conversation_id,payload.run_id)) await continuity()?.syncConversation?.(payload.conversation_id);
        return;
      }
      if (!response.ok || data?.ok !== true || !valid(data.review)) throw Error('Review status unavailable');
      accept(data.review);
      if (!denied && action !== 'status') reviewSignal?.postMessage('review-changed');
    } catch (_) {
      if (current !== epoch || !inScope()) return;
      failed = true;
      if (action !== 'status' || review?.state !== 'ready' || !savedReviewRow(chatTarget(),review.run_id)) {
        pageError = 'Inbox review could not be checked. Try again shortly.';
      }
    } finally {
      clearTimeout(timeout);
      pending = null;
      stopProgress();
      if (start) start.disabled = false;
      if (current === epoch && (inScope() || denied)) render();
      if (!inScope()) return;
      if (refreshPending) { refreshPending = false; schedule('status',0); }
      else if (failed && (action !== 'status' || active(review))) schedule('status',5000);
      else nextCheck();
    }
  }

  function refresh() {
    clearTimeout(timer);
    if (!inScope()) { closeMessage(); publishWork(); renderRail(); renderCompletion(); return; }
    renderCompletion();
    restoreCompose();
    if (pending) refreshPending = true;
    else schedule('status',0);
  }
  function invalidate() {
    epoch++;
    closeMessage();
    clearTimeout(timer);
    stopProgress();
    if (['status','deliver'].includes(pending?.action)) pending.controller.abort();
    review = null;
    delivery = null;
    if (!denied) pageError = '';
    refreshPending = false;
    render();
  }

  function listenForReviewChanges() {
    if (reviewSignal || typeof BroadcastChannel !== 'function') return;
    try {
      // This signal only wakes the existing authenticated status read. It carries no Gmail data.
      reviewSignal = new BroadcastChannel(`vp3:gmail-inbox-review:${owner}`);
      if (thread) reviewSignal.addEventListener('message',event => {
        if (event.data === 'review-changed') refresh();
      });
    } catch (_) { /* The normal status checks also see saved changes. */ }
  }

  form?.addEventListener('submit',event => {
    event.preventDefault();
    if (event.isTrusted) void request('start',Number(new FormData(form).get('days')));
  });
  app?.addEventListener('toggle',refresh);
  window.addEventListener('focus',refresh);
  document.addEventListener('visibilitychange',() => { if (document.hidden) invalidate(); else refresh(); });
  window.addEventListener('vp3:chat-context-ready',refresh);
  window.addEventListener('vp3:chat-canvas-view-changed',() => { renderCompletion(); if (thread?.dataset.view === 'chat') refresh(); });
  window.addEventListener('vp3:chat-message-persisted',event => {
    const row = event.detail?.element;
    if (row?._vp3GmailInboxReviewV1 || delivery?.message_id === Number(row?.dataset.chatMessageId)) renderCompletion();
  });
  window.addEventListener('vp3:agent-orb-ready',publishWork);
  window.addEventListener('stonefellow:chat-context-changing',invalidate);
  window.addEventListener('vp3:participant-selection-changed',() => { invalidate(); refresh(); });
  window.addEventListener('vp3:participant-task-context',event => { participant = Number(event.detail?.participant_id) || 0; invalidate(); refresh(); });
  window.addEventListener('pagehide',() => { stopped = true; reviewSignal?.close(); reviewSignal = null; invalidate(); });
  window.addEventListener('pageshow',() => { stopped = false; listenForReviewChanges(); refresh(); });
  for (const row of app?.querySelectorAll('[data-gmail-review-latest-item]') || []) {
    attachItemActions(row,{item_ref:row.dataset.itemRef,from:row.querySelector('[data-gmail-review-sender]')?.textContent || '',
      subject:row.dataset.subject || ''});
  }
  listenForReviewChanges();
  render();
  refresh();
})();
