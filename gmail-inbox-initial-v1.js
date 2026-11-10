(() => {
  'use strict';
  const cfg = window.VP3_GMAIL_INBOX_INITIAL_V1;
  if (!cfg?.endpoint || !cfg.connectionsUrl || !cfg.csrf || typeof cfg.ownerKey !== 'string' || !/^[1-9]\d*$/.test(cfg.ownerKey)) return;
  const endpoint = new URL(cfg.endpoint, location.href);
  const destination = new URL(cfg.connectionsUrl, location.href);
  if (endpoint.origin !== location.origin || destination.origin !== location.origin) return;

  const host = document.querySelector('[data-gmail-initial-review]');
  const thread = host ? null : document.getElementById('chatThread');
  const app = host?.closest('details.connections-app');
  const form = host?.querySelector('[data-gmail-review-start]');
  const status = host?.querySelector('[data-gmail-review-status]');
  const result = host?.querySelector('[data-gmail-review-report]');
  const start = form?.querySelector('button[type="submit"]');
  // A paid start belongs to the mailbox and settings rendered with this page.
  const renderedToken = cfg.initialReview?.start_token;
  const startToken = host && typeof renderedToken === 'string' && /^[a-f0-9]{64}$/.test(renderedToken) ? renderedToken : null;
  const reloadMessage = 'Gmail or Inbox review settings changed. Reload to continue.';
  const owner = cfg.ownerKey;
  const agent = cfg.agentId;
  const binding = window.STONEFELLOW_CHAT?.workingAttentionBinding;
  const key = 'gmail-inbox-review';
  const daysAllowed = [1,3,7,14,30];
  const labels = {unavailable:'First review is unavailable.',disabled:'Enable Inbox review to begin.',needs_start:'Choose how far back to review.',queued:'Queued',working:'Working',ready:'Ready',failed:'Failed'};
  const categories = {new:'New',important:'Important',reply:'Reply'};
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
  let publishedWork = false;
  let completion = null;
  let completionSignature = '';
  let reviewSignal = null;

  function inScope() {
    if (stopped || denied || document.hidden || window.VP3_GMAIL_INBOX_INITIAL_V1 !== cfg || cfg.ownerKey !== owner
        || participant > 0 || window.VP3ParticipantContinuity?.selectedContext?.()) return false;
    if (host) return host.isConnected && (!app || app.open);
    const chat = window.STONEFELLOW_CHAT;
    const live = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
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
  function detail(value) {
    if (active(value) && value.stage === 'summary') return value.state === 'working' ? 'Preparing the AI summary.' : 'AI summary is queued.';
    if (active(value) && Number.isSafeInteger(value.scanned) && value.scanned > 0 && value.scanned <= 50) {
      return `${value.scanned} of up to 50 headers checked.`;
    }
    if (value?.state === 'working') return 'Checking recent Gmail headers.';
    if (value?.state === 'ready') return 'View the saved review in Connections.';
    if (value?.state === 'failed') return 'The first review could not finish.';
    return '';
  }

  function openReview() {
    if (!inScope()) return;
    const top = new URLSearchParams(location.search).get('orb_embed') === '1' && window.parent !== window
      ? window.parent : window;
    top.location.assign(destination.href);
  }
  function renderRail() {
    const checkingInbox = inboxWorking(review);
    const state = checkingInbox ? 'working' : review?.state;
    const description = checkingInbox ? 'Checking new Gmail messages.' : detail(review);
    const next = inScope() && !pageError && ['queued','working','failed'].includes(state)
      ? JSON.stringify([state,description]) : '';
    if (next === railSignature) return;
    railSignature = next;
    if (!next) window.VP3ChatSpecialists?.remove(key);
    else window.VP3ChatSpecialists?.update(key, {
      label:'Gmail Inbox Review',status:labels[state],detail:description,
      viewLabel:'Open Connections',onView:openReview,
    });
  }

  function renderCompletion() {
    if (!thread) return;
    const visible = inScope() && thread.dataset.view === 'chat' && !pageError;
    const ready = visible && review?.state === 'ready' && review.has_report === true
      && Number.isSafeInteger(review.total) && review.total >= 0 && review.total <= 50;
    const progress = visible && (active(review) || review?.state === 'failed');
    if (!ready && !progress) {
      completion?.remove();
      completion = null;
      completionSignature = '';
      return;
    }

    const reviewed = review.total === 1 ? '1 inbox message' : `${review.total} inbox messages`;
    const headline = ready ? `First Gmail review complete. Reviewed ${reviewed}.`
      : [`Gmail Inbox Review · ${labels[review.state]}`,detail(review)].filter(Boolean).join('. ');
    const saved = ready ? review.chat_result : null;
    const summary = typeof saved?.summary === 'string' ? saved.summary.slice(0,8000).trim() : '';
    const latest = saved?.latest;
    const batchAt = timestamp(latest?.at);
    const batch = Number.isFinite(batchAt) && Number.isSafeInteger(latest.total) && latest.total > 0
      && [latest.important,latest.replies].every(count => Number.isSafeInteger(count) && count >= 0 && count <= latest.total)
      ? `Latest inbox update · ${new Date(batchAt).toLocaleString()} · ${latest.total} new · ${latest.important} important · ${latest.replies} ${latest.replies === 1 ? 'reply' : 'replies'}`
      : '';
    const signature = JSON.stringify([review.run_id,headline,summary,batch]);
    if (completion?.isConnected && completionSignature === signature) return;
    const nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 100;
    if (!completion?.isConnected) {
      completion = node('div','','vp3-chat-gmail-review');
      thread.append(completion);
    }
    completionSignature = signature;
    completion.dataset.gmailReviewState = review.state;
    if (ready) completion.dataset.gmailReviewComplete = review.run_id;
    else delete completion.dataset.gmailReviewComplete;
    const status = node('span',headline);
    status.setAttribute('role','status');
    const link = node('a',ready ? 'Open review' : 'Open Inbox Review');
    link.href = destination.href;
    if (new URLSearchParams(location.search).get('orb_embed') === '1' && window.parent !== window) link.target = '_top';
    const header = node('div','','vp3-chat-gmail-review-heading');
    header.append(status,link);
    completion.replaceChildren(header);
    if (summary) completion.append(node('p',summary));
    if (batch) completion.append(node('small',batch));
    if (nearBottom) thread.scrollTop = thread.scrollHeight;
  }

  function publishWork() {
    const orb = window.VP3AgentOrb;
    if (!orb?.setJobs) return;
    const expiresAt = timestamp(review?.state === 'working' ? review.next_attempt_at : review?.inbox_working_until);
    const working = inScope() && !pageError && valid(review) && expiresAt > Date.now()
      && (review.state === 'working' || inboxWorking(review));
    if (working || publishedWork) orb.setJobs('gmail',working ? [{id:'gmail:'+review.run_id,expiresAt}] : []);
    publishedWork = working;
  }

  function renderReport(report) {
    if (!result) return;
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
    if (Array.isArray(report.items) && report.items.length) {
      const list = node('ul','','connections-inbox-messages');
      list.setAttribute('role','list');
      for (const item of report.items.slice(0,8)) {
        if (!item || typeof item.subject !== 'string' || typeof item.from !== 'string') continue;
        const row = node('li');
        row.append(node('strong',item.subject.slice(0,500) || '(No subject)'),
          node('span',item.from.slice(0,500),'connections-muted'),node('span',categories[item.category] || 'New','connections-badge'));
        list.append(row);
      }
      if (list.children.length) result.append(list);
    }
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
    renderReport(startToken && review?.has_report === true ? review.report : null);
  }

  function schedule(action, delay) {
    clearTimeout(timer);
    if (!inScope()) return;
    timer = setTimeout(() => void request(action), delay);
  }
  function nextCheck() {
    if (!active(review)) {
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
        ...(host ? {include_report:true} : thread ? {include_chat_result:true} : {}),...payload})});
  }
  function accept(value, expectedRunId = null) {
    if (host && (value.start_token ?? null) !== startToken) {
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
    const timeout = setTimeout(() => controller.abort(),{status:8000,start:15000,advance:120000}[action]);
    let failed = false;
    try {
      if (action === 'advance') watchProgress(controller,current,payload.run_id,350);
      const response = await send(payload,controller.signal);
      const data = await response.json();
      if (current !== epoch || !inScope()) return;
      if ([401,403,409,419].includes(response.status)) {
        denied = true;
        review = null;
        pageError = 'Reload to check Inbox review for your current account.';
        return;
      }
      if (!response.ok || data?.ok !== true || !valid(data.review)) throw Error('Review status unavailable');
      accept(data.review);
      if (!denied && action !== 'status') reviewSignal?.postMessage('review-changed');
    } catch (_) {
      if (current !== epoch || !inScope()) return;
      failed = true;
      pageError = 'Inbox review could not be checked. Try again shortly.';
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

  function renderRailUnavailable() {
    railSignature = '';
    window.VP3ChatSpecialists?.remove(key);
  }
  function refresh() {
    clearTimeout(timer);
    if (!inScope()) { publishWork(); renderRailUnavailable(); renderCompletion(); return; }
    if (pending) refreshPending = true;
    else schedule('status',0);
  }
  function invalidate() {
    epoch++;
    clearTimeout(timer);
    stopProgress();
    if (pending?.action === 'status') pending.controller.abort();
    review = null;
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
  window.addEventListener('vp3:chat-canvas-view-changed',renderCompletion);
  window.addEventListener('vp3:agent-orb-ready',publishWork);
  window.addEventListener('stonefellow:chat-context-changing',invalidate);
  window.addEventListener('vp3:participant-selection-changed',() => { invalidate(); refresh(); });
  window.addEventListener('vp3:participant-task-context',event => { participant = Number(event.detail?.participant_id) || 0; invalidate(); refresh(); });
  window.addEventListener('pagehide',() => { stopped = true; reviewSignal?.close(); reviewSignal = null; invalidate(); });
  window.addEventListener('pageshow',() => { stopped = false; listenForReviewChanges(); refresh(); });
  listenForReviewChanges();
  render();
  refresh();
})();
