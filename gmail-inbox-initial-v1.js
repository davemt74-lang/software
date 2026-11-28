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
  const triageOpenKey = `vp3:gmail-triage-open:${owner}`;
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
  const inboxWorking = value => timestamp(value?.inbox_working_until) > Date.now();
  const canStart = value => value?.state === 'needs_start' || value?.state === 'failed';
  const valid = value => value && states.has(value.state)
    && (!(active(value) || value.state === 'ready') || typeof value.run_id === 'string' && /^[a-f0-9]{32}$/.test(value.run_id));
  let review = host && valid(cfg.initialReview) ? cfg.initialReview : null;
  // Undefined means no status response yet. Null means the response could not authorize updates.
  let updateAccess;
  let freshRoutineRows = new WeakSet();
  let checkedRoutineRows = new WeakSet();
  let routineRead = null;
  let routineStatusNeedsProof = false;
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
  const updateCards = new Map();
  let reportSignature = '';
  let reviewSignal = null;
  let openedMessage = null;
  let watchlist = null;
  let watchRequest = null;
  let watchReadAfter = 0;
  let lastCheckAttempt = null;
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
  function resultTime(createdAt, completedAt) {
    const postedAt = timestamp(createdAt);
    const savedAt = Number.isFinite(postedAt) ? postedAt : timestamp(completedAt);
    if (!Number.isFinite(savedAt)) return null;
    const date = new Date(savedAt);
    const label = Number.isFinite(postedAt) ? 'Posted' : 'Review completed';
    const time = node('time',label+' '+date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}),'vp3-chat-gmail-result-time');
    time.dateTime = date.toISOString();
    return time;
  }
  const itemRef = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const messageId = value => typeof value === 'string' && /^[a-zA-Z0-9_-]{1,190}$/.test(value);
  const reviewReply = /^(?:Reply|Draft reply|Auto reply) to Gmail review item [a-f0-9]{64}$/;
  const selectedReply = /^(?:Reply|Draft reply) to Gmail matched item [a-f0-9]{64} [a-zA-Z0-9_-]{1,190} [a-f0-9]{64}$/;
  function httpLink(value) {
    if (typeof value !== 'string' || value.length > 2048 || !/^https?:\/\/[^/?#:]/i.test(value)
        || /[\s\p{C}\\<>"'`]/u.test(value)) return null;
    try {
      const url = new URL(value);
      return ['http:','https:'].includes(url.protocol) && url.hostname && !url.username && !url.password ? url : null;
    } catch (_) { return null; }
  }

  function trimmedLink(value) {
    if (value.endsWith('...') || value.endsWith('…')) return '';
    let target = value.replace(/[.!,;]+$/u,'');
    for (const [open,close] of [['(',')'],['[',']'],['{','}']]) {
      let balance = 0;
      for (const char of target) balance += (char === open) - (char === close);
      while (balance < 0 && target.endsWith(close)) {
        target = target.slice(0,-1).replace(/[.!,;]+$/u,'');
        balance++;
      }
    }
    return target;
  }

  function linkScope(marker,ref) {
    return itemRef(binding) && Number.isSafeInteger(marker?.conversation_id) && marker.conversation_id > 0
      && Number.isSafeInteger(marker.message_id) && marker.message_id > 0
      ? {agent_id:agent,conversation_id:marker.conversation_id,chat_message_id:marker.message_id,
          working_attention_binding:binding,...(itemRef(ref) ? {item_ref:ref} : {})} : null;
  }

  function recordableLink(proof,target) {
    if (!proof || !target?.isConnected || !inScope()) return false;
    return proof.mailbox === true ? Boolean(openedMessage?.panel?.contains(target))
      : Boolean(thread?.contains(target) && chatTarget() === proof.conversation_id);
  }

  function linkObservation(proof,url,receipt) {
    const {mailbox,...scope} = proof;
    return {action:mailbox === true ? 'record_mailbox_link_open' : 'record_link_open',...scope,url,
      ...(receipt?.token ? {link_token:receipt.token,link_label:receipt.label} : {})};
  }

  function followEmailLink(event,proof,receipt) {
    if(!receipt?.token||!proof)return;
    try{window.VP3AgentScreenShare?.activateFromEmailLink?.(event,{ownerKey:owner});}catch{}
  }

  function gmailLink(value,label,proof = null,receipt = null) {
    const anchor = node('a',label,'vp3-gmail-text-link');
    anchor.href = value;
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';
    if (proof) {
      const record = event => {
        if (!event.isTrusted || event.defaultPrevented || event.button !== (event.type === 'auxclick' ? 1 : 0)
            || !recordableLink(proof,anchor)
            || anchor.getAttribute('href') !== value) return;
        followEmailLink(event,proof,receipt);
        void send(linkObservation(proof,value,receipt),undefined,true)
          .catch(() => { /* Opening the anchor is independent of recording the click. */ });
      };
      anchor.addEventListener('click',record);
      anchor.addEventListener('auxclick',record);
    }
    return anchor;
  }

  function appendTextLinks(element,text,proof = null,approved = new Map(),onlyReceipts = false) {
    const visible = new Set();
    let cursor = 0, linked = 0;
    for (const match of text.matchAll(/https?:\/\/[^\s\p{C}<>"'`“”‘’]+/giu)) {
      if (linked >= 30) break;
      if (/[\p{L}\p{N}_./:%@=?&#-]$/u.test(text.slice(Math.max(0,match.index-2),match.index))) continue;
      const exact = httpLink(match[0]);
      const value = exact && approved.has(exact.href) ? match[0] : trimmedLink(match[0]);
      const url = httpLink(value);
      if (!url) continue;
      const receipt = approved.get(url.href);
      element.append(text.slice(cursor,match.index),
        gmailLink(receipt?.url ?? value,value,onlyReceipts && !receipt?.token ? null : proof,receipt));
      visible.add(url.href);
      linked++;
      cursor = match.index+value.length;
    }
    element.append(text.slice(cursor));
    return visible;
  }

  function renderOpenedEmail(panel,text,links,proof,bodyClass = '',display = null,subject = '',documents = null,captureSource = null,sender = '') {
    const approved = new Map();
    for (const entry of Array.isArray(links) ? links.slice(0,30) : []) {
      const url = httpLink(entry?.url);
      if (!url || approved.has(url.href)) continue;
      const label = typeof entry.label === 'string' && [...entry.label].length <= 160 ? entry.label : '';
      approved.set(url.href,{url:entry.url,label,token:label && itemRef(entry.token) ? entry.token : null});
    }
    const renderPlain = host => {
      const body = node('p','',bodyClass);
      const visible = appendTextLinks(body,text,proof,approved,true);
      host.append(body);
      const extras = [...approved].filter(([url]) => !visible.has(url));
      if (extras.length) {
        const list = node('ul','','gmail-review-source-links');
        list.setAttribute('aria-label','Links in this email');
        for (const [,entry] of extras) {
          const row = node('li');
          row.append(gmailLink(entry.url,entry.label || entry.url,entry.token ? proof : null,entry));
          list.append(row);
        }
        host.append(list);
      }
    };
    const formatted = window.VP3GmailEmailDisplayV1?.mount(panel,{body:text,display,subject,from:sender,capture_source:captureSource},{renderPlain,
      onLink:(event,value) => {
        const receipt = approved.get(value);
        if (!event.isTrusted || !receipt?.token || !recordableLink(proof,panel)) return;
        followEmailLink(event,proof,receipt);
        void send(linkObservation(proof,receipt.url,receipt),undefined,true)
          .catch(() => {});
      }});
    if (!formatted) renderPlain(panel);
    window.VP3GmailDocumentsV1?.mount(panel,documents);
  }

  function renderGmailPostLinks(row) {
    const target = chatTarget();
    const id = Number(row?.dataset.chatMessageId);
    if (!row || !target || row.dataset.gmailContentPost !== 'true'
        || String(target) !== row.dataset.chatConversationId || !Number.isSafeInteger(id) || id < 1) return;
    const body = row.querySelector('.message-text');
    if (!body || body.querySelector('.vp3-gmail-text-link')) return;
    const text = body.textContent;
    if (!text || !/https?:\/\//i.test(text)) return;
    body.replaceChildren();
    appendTextLinks(body,text,linkScope({conversation_id:target,message_id:id}));
  }

  function senderAddress(value) {
    const address = (value.match(/^[^<>]*<([^<>]+)>$/)?.[1] || value).trim().toLowerCase();
    return address.length <= 254 && /^[^\s"\\<>@]+@[^\s"\\<>@]+$/.test(address) ? address : '';
  }

  function watchScope() {
    const email = window.VP3_CHAT_EMAIL_V1;
    const conversation = chatTarget();
    if (!conversation || email?.ownerKey !== owner || !email.csrf || !email.endpoint) return null;
    let url;
    try { url = new URL(email.endpoint,location.href); } catch (_) { return null; }
    if (url.origin !== location.origin) return null;
    return {url:url.href,owner_key:owner,agent_id:agent,conversation_id:conversation,csrf_token:email.csrf};
  }

  function syncWatchControls() {
    for (const control of thread?.querySelectorAll('[data-gmail-review-watch-sender]') || []) {
      control.parentElement.hidden = !watchlist?.available;
      control.checked = watchlist?.senders.has(control.dataset.gmailReviewWatchSender) === true;
      control.disabled = Boolean(watchRequest);
    }
  }

  async function readWatchlist(control = null) {
    const scope = watchScope();
    if (!scope || watchRequest || !thread?.querySelector('[data-gmail-review-watch-sender]')) return;
    if (!control && Date.now() < watchReadAfter) return;
    if (control && !watchlist?.key) { syncWatchControls(); return; }
    const controller = watchRequest = new AbortController();
    const {url,...requestScope} = scope;
    const action = control ? {action:'sender_watch',sender:control.dataset.gmailReviewWatchSender,enabled:control.checked,watchlist_key:watchlist.key}
      : {action:'sender_watchlist'};
    const feedback = control?.closest('li')?.querySelector('[data-gmail-review-feedback]');
    if (feedback) feedback.textContent = '';
    const current = () => watchRequest === controller && watchScope()?.conversation_id === scope.conversation_id;
    const timeout = setTimeout(() => controller.abort(),10000);
    let failure = 'Could not save the sender watch. Try again.';
    syncWatchControls();
    try {
      const response = await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'Content-Type':'application/json'},body:JSON.stringify({...requestScope,...action})});
      const data = await response.json();
      if (!current()) return;
      const state = data?.watchlist;
      if (!response.ok || data?.ok !== true || typeof state?.available !== 'boolean' || !Array.isArray(state.senders)
          || (state.available && !itemRef(state.key))) {
        if ([409,422].includes(response.status) && typeof data?.error === 'string') failure = data.error.slice(0,200);
        throw Error('Sender watch unavailable');
      }
      watchlist = {available:state.available,senders:new Set(state.senders),key:state.key};
      if (control) window.dispatchEvent(new Event('vp3:chat-email-watch-changed'));
    } catch (_) {
      if (!current()) return;
      if (feedback) feedback.textContent = failure;
      else watchlist = null;
    } finally {
      clearTimeout(timeout);
      if (watchRequest === controller) {
        watchRequest = null;
        watchReadAfter = Date.now()+15000;
        syncWatchControls();
      }
    }
  }

  function watchControl(address) {
    const label = node('label','','gmail-review-watch');
    label.hidden = true;
    const control = node('input');
    control.type = 'checkbox';
    control.dataset.gmailReviewWatchSender = address;
    control.setAttribute('aria-label',`Watch sender ${address}`);
    label.title = 'Notify me about the next email from this sender within 30 days';
    label.append(control,node('span','Watch sender'));
    control.addEventListener('change',event => { if (event.isTrusted && inScope()) void readWatchlist(control); });
    return label;
  }
  function itemButton(label,action,handler) {
    const button = node('button',label);
    button.type = 'button';
    button.dataset.gmailReviewAction = action;
    button.addEventListener('click',event => { if (event.isTrusted && inScope()) handler(button); });
    return button;
  }

  function compose(command) {
    if (!inScope() || !(reviewReply.test(command) || selectedReply.test(command))) return false;
    if (host) {
      try { sessionStorage.setItem(composeKey,JSON.stringify({command,at:Date.now()})); }
      catch (_) { return false; }
      location.assign(chatUrl.href);
      return true;
    }
    const input = document.getElementById('chatInput');
    const chat = continuity();
    if (!input || input.value.trim() || chat?.isBusy?.()) return false;
    return chat?.requestGmailReviewAction?.(command) === true;
  }

  function restoreCompose() {
    if (host || !inScope()) return;
    try {
      const saved = JSON.parse(sessionStorage.getItem(composeKey) || 'null');
      if (!saved) return;
      sessionStorage.removeItem(composeKey);
      if (Number.isSafeInteger(saved.at) && saved.at <= Date.now() && Date.now()-saved.at < 120000
        && typeof saved.command === 'string' && !saved.command.startsWith('Auto reply ')) compose(saved.command);
    } catch (_) { /* No private composer handoff is available. */ }
  }

  function closeMessage() {
    if (!openedMessage) return;
    const {controller,button,panel,dialog} = openedMessage;
    openedMessage = null;
    controller.abort();
    button?.setAttribute('aria-expanded','false');
    panel.remove();
    if (dialog?.open) dialog.close();
    dialog?.remove();
  }

  function openPanel(row,button) {
    const toggle = openedMessage?.button === button;
    closeMessage();
    if (toggle) return null;
    const controller = new AbortController();
    const panel = node('div','','gmail-review-open-message');
    row.append(panel);
    button.setAttribute('aria-expanded','true');
    return openedMessage = {controller,panel,button};
  }

  async function panelRead(selection,payload,progress,failed,renderResult) {
    if (!selection) return;
    const {controller,panel} = selection;
    const status = node('p',progress);
    status.setAttribute('role','status');
    panel.replaceChildren(status);
    const current = () => openedMessage === selection && panel.isConnected && inScope();
    const timeout = setTimeout(() => controller.abort(),12000);
    try {
      const response = await send(payload,controller.signal);
      const data = await response.json();
      if (!current()) return;
      if (!response.ok || data?.ok !== true) throw Error('Gmail item unavailable');
      renderResult(data,panel,status);
    } catch (_) {
      if (current()) status.textContent = failed;
    } finally {
      clearTimeout(timeout);
    }
  }

  function readMessage(selection,payload,request = null,recordPriority = null) {
    return panelRead(selection,payload,'Opening this Gmail message…','This Gmail message could not be opened. Check Inbox Review and try again.',(data,panel,status) => {
      const message = data.item;
      if (!['subject','from','body'].every(field => typeof message?.[field] === 'string')) throw Error('Gmail item unavailable');
      recordPriority?.(data.priority);
      delete panel.dataset.gmailReviewMatches;
      panel.replaceChildren(node('strong',message.subject || '(No subject)'),node('small',message.from));
      const ref = itemRef(payload.item_ref) ? payload.item_ref : itemRef(message.item_ref) ? message.item_ref : null;
      const proof = ref ? linkScope({conversation_id:payload.conversation_id,message_id:payload.chat_message_id},ref)
        || {mailbox:true,item_ref:ref} : null;
      renderOpenedEmail(panel,message.body || 'No readable text in this message.',message.links,proof,'',message.display,message.subject,message.documents,message.capture_source,message.from);
      if (message.answered_in_gmail === true) {
        panel.append(node('small','Already replied in Gmail.'));
        return;
      }
      if (request) {
        const actions = node('div','','gmail-review-item-actions');
        actions.append(...replyButtons(ref ? `review item ${ref}` : matchTarget(payload),request));
        panel.append(actions);
      }
      if (payload.action === 'open_item' && !selection.dialog && ref && thread && message.auto_reply === true
          && typeof message.auto_reply_to === 'string' && message.auto_reply_to) {
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
    });
  }

  function openTriageItem(item) {
    if (!thread || !inScope() || !itemRef(item?.id) || item.agent !== agent || item.binding !== binding
        || !Number.isSafeInteger(item.generation) || item.generation < 1
        || !Number.isSafeInteger(item.conversation) || item.conversation < 0) return false;
    return openChatMessage(item.conversation,{action:'triage_open',item_ref:item.id,generation:item.generation,
      working_attention_binding:binding,agent_id:agent,conversation_id:item.conversation});
  }

  function openArrivedItem(ref, conversationId) {
    if (!thread || !inScope() || !itemRef(ref) || !Number.isSafeInteger(conversationId) || conversationId < 0) return false;
    return openChatMessage(conversationId,{action:'open_item',item_ref:ref});
  }

  function openReportedItem(id,conversationId) {
    if (!thread || chatTarget() !== conversationId) return false;
    const proof = linkScope({conversation_id:conversationId,message_id:id});
    if (!proof || !thread.querySelector(`[data-chat-message-role="assistant"][data-chat-conversation-id="${conversationId}"][data-chat-message-id="${id}"][data-gmail-content-post="true"]`)) return false;
    return openChatMessage(conversationId,{action:'open_item',...proof});
  }

  function openChatMessage(conversationId,payload) {
    const live = continuity();
    if (conversationId !== live.conversationId?.()) return false;
    live.showChatView?.();
    if (chatTarget() !== conversationId) return false;
    closeMessage();

    const dialog = node('dialog','','gmail-review-triage-dialog');
    const header = node('header');
    const close = node('button','Close');
    close.type = 'button';
    close.autofocus = true;
    header.append(node('strong','Gmail message'),close);
    const panel = node('div','','gmail-review-open-message');
    dialog.setAttribute('aria-label','Gmail message');
    dialog.append(header,panel);
    thread.append(dialog);
    const selection = openedMessage = {controller:new AbortController(),panel,dialog};
    const dismiss = () => { if (openedMessage === selection) closeMessage(); };
    close.addEventListener('click',dismiss);
    dialog.addEventListener('close',dismiss);
    try { dialog.showModal(); }
    catch (_) { dismiss(); return false; }
    const feedback = node('small');
    feedback.setAttribute('role','status');
    void readMessage(selection,payload,command => {
      if (compose(command)) dismiss();
      else {
        feedback.textContent = 'Finish your current Chat draft before replying.';
        panel.append(feedback);
      }
    });
    return true;
  }

  function restoreTriageItem() {
    if (!thread || !inScope()) return;
    try {
      const saved = JSON.parse(sessionStorage.getItem(triageOpenKey) || 'null');
      if (!saved) return;
      sessionStorage.removeItem(triageOpenKey);
      if (Number.isSafeInteger(saved.at) && saved.at <= Date.now() && Date.now()-saved.at < 120000
          && saved.conversation === 0) openTriageItem({...saved,conversation:continuity()?.conversationId?.()});
    } catch (_) { /* The originating page can be used to try the exact message again. */ }
  }

  function replyButtons(target,request,autoLabel = 'Auto Compose (uses tokens)') {
    return [itemButton('Reply','reply',() => request(`Reply to Gmail ${target}`)),
      itemButton(autoLabel,'draft-reply',() => request(`Draft reply to Gmail ${target}`))];
  }

  function matchTarget({legacy_ref,message_id,match_token}) {
    return `matched item ${legacy_ref} ${message_id} ${match_token}`;
  }

  function findMatches(row,ref,button,action,request) {
    const selection = openPanel(row,button);
    return panelRead(selection,{action:'find_matches',legacy_ref:ref},'Looking up this Gmail message…','Could not look up this saved Gmail message. Try again.',(data,panel) => {
      if (!Array.isArray(data.matches) || data.matches.length > 10) throw Error('Gmail matches unavailable');
      const matches = data.matches.filter(match => messageId(match?.message_id) && itemRef(match.match_token)
        && ['from','subject','received_at','snippet'].every(field => typeof match[field] === 'string'));
      if (data.unique_match === true && data.matches.length === 1 && matches.length === 1) {
        const match = matches[0];
        const payload = {action:'open_match',legacy_ref:ref,message_id:match.message_id,match_token:match.match_token};
        if (action === 'open' || match.answered_in_gmail === true) {
          void readMessage(selection,payload,request);
          return;
        }
        const verb = action === 'reply' ? 'Reply' : 'Draft reply';
        request(`${verb} to Gmail ${matchTarget(payload)}`);
        closeMessage();
        return;
      }
      panel.dataset.gmailReviewMatches = '';
      const status = node('p',matches.length ? 'Choose the message you want to open or reply to.' : 'No exact sender and subject matches were found in the messages checked.');
      status.setAttribute('role','status');
      panel.replaceChildren(status);
      if (!matches.length) return;
      const list = node('ul','','gmail-review-match-list');
      for (const match of matches) {
        const candidate = node('li');
        candidate.dataset.gmailReviewMatch = '';
        const at = timestamp(match.received_at);
        const actions = node('div','','gmail-review-item-actions');
        const payload = {action:'open_match',legacy_ref:ref,message_id:match.message_id,match_token:match.match_token};
        actions.append(itemButton('Open','open-match',() => void readMessage(selection,payload,request)));
        if (match.answered_in_gmail === true) actions.append(node('small','Already replied in Gmail.'));
        else actions.append(...replyButtons(matchTarget(payload),request));
        candidate.append(node('strong',match.subject || '(No subject)'),node('small',match.from),
          node('small',Number.isFinite(at) ? new Date(at).toLocaleString() : ''),node('p',match.snippet),actions);
        list.append(candidate);
      }
      panel.append(list);
    });
  }

  function attachItemActions(row,item,seenSenders = null,marker = null) {
    const actions = node('div','','gmail-review-item-actions');
    const feedback = node('small');
    feedback.dataset.gmailReviewFeedback = '';
    feedback.setAttribute('role','status');
    const ref = item.item_ref;
    const sourceTarget = itemRef(ref) ? linkScope(marker,ref) : null;
    const digestTarget = marker?.routine_digest === true ? sourceTarget : null;
    const priority = digestTarget ? itemButton('Prioritize','prioritize',() => void prioritize()) : null;

    function recordPriority(state) {
      if (!priority || !['high','already_high'].includes(state)) return;
      item.engagement_priority = 'high';
      priority.textContent = 'High priority';
      priority.disabled = true;
    }

    async function prioritize() {
      if (!priority || priority.disabled || !inScope() || chatTarget() !== digestTarget.conversation_id) return;
      const started = epoch;
      const current = () => started === epoch && row.isConnected && inScope() && chatTarget() === digestTarget.conversation_id;
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(),10000);
      priority.disabled = true;
      try {
        const response = await send({action:'prioritize_item',...digestTarget},controller.signal);
        const data = await response.json();
        if (!current()) return;
        if (!response.ok || data?.ok !== true || !['high','already_high'].includes(data.priority)) throw Error('Priority unavailable');
        recordPriority(data.priority);
        feedback.textContent = 'Added to priority review.';
      } catch (_) {
        if (current() && item.engagement_priority !== 'high') feedback.textContent = 'Could not add this email to priority review.';
      } finally {
        clearTimeout(timeout);
        if (current()) priority.disabled = item.engagement_priority === 'high';
      }
    }

    const request = command => {
      const submitted = compose(command);
      feedback.textContent = submitted ? '' : 'Finish your current Chat draft or open Agent Chat first.';
      if (submitted) void prioritize();
    };
    if (itemRef(ref)) {
      const open = itemButton('Open','open',button => {
        if (digestTarget) {
          void readMessage(openPanel(row,button),{action:'routine_open',...digestTarget},null,recordPriority);
        } else {
          void readMessage(openPanel(row,button),{action:'open_item',item_ref:ref,...sourceTarget});
        }
      });
      open.setAttribute('aria-expanded','false');
      actions.append(open);
      const answered = item.answered_in_gmail === true;
      if (answered) actions.append(node('small','Already replied in Gmail.'));
      if (!answered || digestTarget) {
        const controls = replyButtons(`review item ${ref}`,request,digestTarget ? 'Auto response (costs tokens)' : undefined);
        if (answered) for (const control of controls) control.disabled = true;
        actions.append(...controls);
      }
      if (priority) {
        recordPriority(item.engagement_priority);
        actions.append(priority);
      }
    } else if (itemRef(item.legacy_ref)) {
      for (const [label,action] of [['Open','open'],['Reply','reply'],['Auto Compose (uses tokens)','draft-reply']]) {
        const control = itemButton(label,action,button => void findMatches(row,item.legacy_ref,button,action,request));
        control.setAttribute('aria-expanded','false');
        actions.append(control);
      }
    }
    const sender = seenSenders ? senderAddress(item.from) : '';
    if (sender && !seenSenders.has(sender)) {
      seenSenders.add(sender);
      actions.append(watchControl(sender));
    }
    if (actions.children.length) row.append(actions,feedback);
  }

  function priorityKeywordSummary(items) {
    if (!Array.isArray(items)) return '';
    const count = items.filter(item => Array.isArray(item?.priority_signals) && item.priority_signals.length).length;
    return count ? `${count} ${count === 1 ? 'message' : 'messages'} with priority keywords in the subject.` : '';
  }

  function itemList(items,seenSenders = null,marker = null,includePreview = false) {
    if (!Array.isArray(items)) return null;
    const entries = items.slice(0,50).filter(item => item && typeof item.subject === 'string' && typeof item.from === 'string');
    if (!entries.length) return null;
    const list = node('ul','','connections-inbox-messages gmail-review-items');
    list.setAttribute('role','list');
    const routine = marker?.routine_digest === true;
    const pageSize = 8;
    const addPage = () => {
      const offset = list.children.length;
      for (const item of entries.slice(offset,offset+pageSize)) {
        const row = node('li');
        row.append(node('strong',item.subject.slice(0,500) || '(No subject)'));
        if (routine && includePreview) {
          const preview = typeof item.preview === 'string' ? item.preview.trim().slice(0,320) : '';
          if (preview) {
            const text = node('p','','gmail-review-item-preview');
            appendTextLinks(text,preview,itemRef(item.item_ref) ? linkScope(marker,item.item_ref) : null);
            row.append(text);
          }
        }
        if (!routine) {
          const labels = node('div','','gmail-review-item-labels');
          labels.append(node('span',categories[item.category] || 'New','connections-badge'));
          if (item.category === 'reply' && item.important === true) {
            labels.append(node('span',categories.important,'connections-badge'));
          }
          row.append(node('span',item.from.slice(0,500),'connections-muted'),labels);
          if (Array.isArray(item.priority_matches) && item.priority_matches.length) {
            row.append(node('small','High priority · ' + item.priority_matches.join(' · '),'gmail-review-item-priority'));
          }
          if (Array.isArray(item.priority_signals) && item.priority_signals.length) {
            row.append(node('small','Priority keywords in subject · ' + item.priority_signals.join(', '),'gmail-review-item-priority'));
          }
          if (typeof item.triage?.reason === 'string' && item.triage.reason) {
            const time = typeof item.triage.due_at === 'string' ? new Date(item.triage.due_at) : null;
            const due = time && !Number.isNaN(time.getTime())
              ? new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short'}).format(time)
              : item.triage.due_date;
            row.append(node('small',item.triage.reason + (due ? ' Deadline ' + due + '.' : ''),'gmail-review-item-priority'));
          }
        }
        attachItemActions(row,item,seenSenders,marker);
        list.append(row);
      }
    };
    addPage();
    const more = entries.length > pageSize ? itemButton('','more-items',button => {
      const offset = list.children.length;
      addPage();
      syncWatchControls();
      void readWatchlist();
      updateMore(button);
      const first = list.children[offset];
      if (first) {
        first.tabIndex = -1;
        first.focus();
      }
    }) : null;
    function updateMore(button) {
      const remaining = entries.length-list.children.length;
      button.hidden = remaining < 1;
      const count = Math.min(pageSize,remaining);
      button.textContent = count ? `Show next ${count} ${count === 1 ? 'message' : 'messages'}` : '';
    }
    if (more) {
      more.className = 'connections-button connections-secondary';
      updateMore(more);
    }
    return {list,more,total:entries.length};
  }

  function appendItems(parent,label,items,seenSenders,marker = null) {
    const page = itemList(items,seenSenders,marker);
    if (!page) return;
    const details = node('details','','gmail-review-item-group');
    details.append(node('summary',`${label} (${page.total})`),page.list);
    if (page.more) details.append(page.more);
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
    const initial = review.state === 'working';
    if (initial) {
      const expiresAt = timestamp(review.next_attempt_at);
      return expiresAt > Date.now() ? {id:'gmail:'+review.run_id,expiresAt} : null;
    }
    const analysis = review.inbox_background?.analysis_work;
    const scanExpiry = timestamp(review.inbox_working_until);
    const analysisExpiry = analysis?.state === 'working' ? timestamp(analysis.lease_until) : NaN;
    if (scanExpiry > Date.now()) return {id:'gmail:inbox-updates',expiresAt:scanExpiry};
    if (analysisExpiry > Date.now()) return {id:'gmail:content-analysis',expiresAt:analysisExpiry};
    return null;
  }
  function renderRail() {
    const lease = workLease();
    const description = lease && (review.state === 'working' ? detail(review)
      : lease.id === 'gmail:content-analysis' ? 'Analyzing Gmail message content.' : 'Checking new Gmail messages.');
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
      if (marker?.kind === 'batch') continue;
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

  function needsUpdateDelivery(conversationId) {
    return conversationId > 0 && typeof binding === 'string' && binding !== '' && review?.inbox_updates?.pending === true
      && !continuity()?.isBusy?.();
  }

  function overdueCheckRef(conversationId) {
    const monitor = review?.inbox_background;
    if (conversationId === null || typeof binding !== 'string' || binding === '' || pending || review?.state === 'working'
        || !monitor || continuity()?.isBusy?.()) return null;
    const ref = monitor?.state === 'overdue' && itemRef(monitor.check_ref) ? monitor.check_ref
      : conversationId > 0 && itemRef(monitor?.content_check_ref) ? monitor.content_check_ref : null;
    if (ref === null) return null;
    // The worker owns the lease. Slow repeated attempts on the same reference
    // so a temporarily stalled check can recover without repeated requests.
    const delay = lastCheckAttempt?.ref === ref && lastCheckAttempt.count >= 2 ? 300000 : 60000;
    if (lastCheckAttempt && Date.now() - lastCheckAttempt.at < delay) return null;
    return ref;
  }

  async function syncUpdates() {
    const latest = review?.inbox_updates?.latest;
    const target = chatTarget();
    if (!target || updateAccess === null || continuity()?.isBusy?.()) return;
    if (latest?.kind === 'batch' && latest.agent_id === agent && latest.conversation_id === target
        && Number.isSafeInteger(latest.message_id) && latest.message_id > 0 && itemRef(latest.report_ref)) {
      const row = thread.querySelector(`[data-chat-conversation-id="${target}"][data-chat-message-id="${latest.message_id}"]`);
      try {
        if (!row) await continuity()?.syncConversation?.(target);
        else if (row.dataset.gmailRoutinePost !== 'true' && !row._vp3GmailInboxReviewV1) {
          await continuity()?.refreshGmailUpdate?.(target,latest.message_id);
        }
      } catch (_) { /* Retry on the next status check. */ }
    }
    await recoverRoutineRows();
  }

  async function recoverRoutineRows() {
    const target = chatTarget();
    const live = continuity();
    if (!target || updateAccess === null || routineRead || live?.isBusy?.() || !live?.refreshGmailUpdates) return;
    const scopeRef = updateAccess?.scope_ref;
    const checked = checkedRoutineRows;
    const rows = [...thread.querySelectorAll(`[data-chat-conversation-id="${target}"][data-chat-message-role="assistant"][data-gmail-routine-post="true"]`)]
      .reverse().filter(row => {
        const marker = row._vp3GmailInboxReviewV1;
        return !checked.has(row) && (!marker || !freshRoutineRows.has(row) || (scopeRef && marker.scope_ref !== scopeRef));
      }).slice(0,24);
    if (!rows.length) return;
    for (const row of rows) checked.add(row);
    const controller = routineRead = new AbortController();
    const timer = setTimeout(() => controller.abort(),15000);
    try {
      if (!await live.refreshGmailUpdates(target,rows.map(row => Number(row.dataset.chatMessageId)),controller.signal)) {
        for (const row of rows) checked.delete(row);
      }
    } catch (_) { /* The next valid lifecycle or mailbox-scope change allows another exact read. */ }
    finally {
      clearTimeout(timer);
      if (routineRead === controller) {
        routineRead = null;
        renderUpdates();
      }
    }
  }

  function renderUpdates() {
    if (!thread) return;
    const target = chatTarget();
    const wanted = new Set();
    let routineChanged = false;
    const scopeRef = updateAccess?.scope_ref;
    if (target > 0 && updateAccess !== null) {
      for (const row of thread.querySelectorAll(`[data-chat-conversation-id="${target}"][data-chat-message-role="assistant"]`)) {
        const marker = row._vp3GmailInboxReviewV1;
        const value = marker?.review;
        const at = timestamp(value?.at);
        const routine = marker?.routine_digest === true;
        if (marker?.kind !== 'batch' || !itemRef(marker.scope_ref) || (scopeRef && marker.scope_ref !== scopeRef)
            || marker.agent_id !== agent || marker.conversation_id !== target
            || (routine && !freshRoutineRows.has(row))
            || (!routine && (!scopeRef || pageError || review?.state === 'unavailable'))
            || String(marker.message_id) !== row.dataset.chatMessageId || !itemRef(marker.report_ref)
            || !valid(value) || value.state !== 'ready' || value.run_id !== marker.run_id
            || !Number.isSafeInteger(value.total) || value.total < 1 || value.total > 50 || !Number.isFinite(at)) continue;
        // Chat projected the approved preview with this exact message. A newer status can withdraw preview access.
        const includePreview = updateAccess === undefined || updateAccess.preview_enabled === true;
        const parent = row.querySelector('.message-body');
        if (!parent) continue;
        wanted.add(row);
        const previous = updateCards.get(row);
        if (previous?.isConnected) {
          if (previous._vp3GmailProjection === marker
              && (!routine || previous.dataset.gmailRoutinePreview === String(includePreview))) continue;
          if (openedMessage && previous.contains(openedMessage.panel)) closeMessage();
          if (previous.dataset.gmailRoutineDigest === 'true') routineChanged = true;
          previous.remove();
        }
        const panel = node('div','','vp3-chat-gmail-review');
        panel.dataset.gmailInboxUpdate = marker.run_id;
        const headline = marker.priority_item === true ? 'High-priority Gmail message'
          : `${value.total} new Gmail ${value.total === 1 ? 'message' : 'messages'}`;
        panel.append(node('strong',headline));
        const time = resultTime(row.dataset.chatCreatedAt,value.at);
        if (time) panel.append(time);
        if (!routine
            && [value.important,value.replies].every(count => Number.isSafeInteger(count) && count >= 0 && count <= value.total)) {
          panel.append(node('small',`${value.important} important · ${value.replies} ${value.replies === 1 ? 'reply' : 'replies'}`));
        }
        if (routine) {
          routineChanged = true;
          panel.dataset.gmailRoutineDigest = 'true';
          panel.dataset.gmailRoutinePreview = String(includePreview);
          const page = itemList(value.items,null,marker,includePreview);
          if (page) {
            panel.append(page.list);
            if (page.more) panel.append(page.more);
          }
        } else {
          const priority = priorityKeywordSummary(value.items);
          if (priority) panel.append(node('small',priority));
          if (marker.priority_item === true) {
            const page = itemList(value.items,new Set(),marker);
            if (page) panel.append(page.list);
          } else appendItems(panel,'Messages in inbox update',value.items,new Set(),marker);
        }
        parent.append(panel);
        panel._vp3GmailProjection = marker;
        updateCards.set(row,panel);
      }
    }
    for (const [row,panel] of updateCards) {
      if (wanted.has(row)) continue;
      if (openedMessage && panel.contains(openedMessage.panel)) closeMessage();
      if (panel.dataset.gmailRoutineDigest === 'true') routineChanged = true;
      panel.remove();
      updateCards.delete(row);
    }
    syncWatchControls();
    if (wanted.size) void readWatchlist();
    if (routineChanged) window.dispatchEvent(new Event('vp3:gmail-digest-render-changed'));
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
        accept(marker.review,marker.run_id,false);
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
    const time = ready ? resultTime(row?.dataset.chatCreatedAt,review.updated_at) : null;
    const summary = typeof saved?.summary === 'string' ? saved.summary.slice(0,8000).trim() : '';
    const latest = saved?.latest;
    const batchAt = timestamp(latest?.at);
    const batch = Number.isFinite(batchAt) && Number.isSafeInteger(latest.total) && latest.total > 0
      && [latest.important,latest.replies].every(count => Number.isSafeInteger(count) && count >= 0 && count <= latest.total)
      ? `Latest inbox update · ${new Date(batchAt).toLocaleString()} · ${latest.total} new · ${latest.important} important · ${latest.replies} ${latest.replies === 1 ? 'reply' : 'replies'}`
      : '';
    const signature = JSON.stringify([review.run_id,headline,time?.dateTime,time?.textContent,summary,batch,saved?.items,latest?.items]);
    if (completion?.isConnected && completionSignature === signature) {
      void readWatchlist();
      return;
    }
    if (openedMessage && completion?.contains(openedMessage.panel)) closeMessage();
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
    if (ready) completion.append(node('small','First inbox review'));
    if (time) completion.append(time);
    const firstPriority = priorityKeywordSummary(saved?.items);
    if (firstPriority) completion.append(node('small',firstPriority));
    if (summary) completion.append(node('p',summary));
    if (Array.isArray(saved?.items) && saved.items.length < review.total) {
      completion.append(node('small',`Saved details are available for ${saved.items.length} of ${review.total} reviewed messages.`));
    }
    const seenSenders = new Set();
    appendItems(completion,'Messages in first review',saved?.items,seenSenders);
    if (batch) {
      completion.append(node('small',batch));
      const latestPriority = priorityKeywordSummary(latest.items);
      if (latestPriority) completion.append(node('small',latestPriority));
      appendItems(completion,'Messages in latest update',latest.items,seenSenders);
    }
    syncWatchControls();
    void readWatchlist();
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
    const priority = priorityKeywordSummary(report.items);
    if (priority) result.append(node('p',priority,'connections-muted'));
    if (report.truncated === true) result.append(node('p',report.scanned >= 50
      ? 'This review reached the 50-header limit.' : 'More matching mail may be available.','connections-muted'));
    const summary = report.summary_status === 'ready' && typeof report.summary === 'string' && report.summary.trim()
      ? report.summary.slice(0,8000) : ({no_messages:'No messages matched this review.',unavailable:'The scan is saved. AI summary was unavailable.',
        unverified:'The scan is saved. The AI summary could not be verified.'})[report.summary_status];
    if (summary) result.append(node('p',summary,'connections-inbox-review-summary'));
    result.append(node('small',Number.isSafeInteger(report.tokens_charged) && report.tokens_charged >= 0
      ? `${report.tokens_charged.toLocaleString()} VP3 tokens charged` : 'VP3 tokens charged: unknown','connections-muted'));
    const page = itemList(report.items);
    if (page) {
      if (page.total < report.total) result.append(node('small',`Saved details are available for ${page.total} of ${report.total} reviewed messages.`));
      result.append(page.list);
      if (page.more) result.append(page.more);
    }
  }

  function render() {
    publishWork();
    if (!host) { renderRail(); renderCompletion(); renderUpdates(); return; }
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
    const target = chatTarget();
    // Keep recurring checks moving even if a first review or an older Chat delivery is waiting to retry.
    if (overdueCheckRef(target)) {
      schedule('check_overdue',0);
      return;
    }
    if (!active(review)) {
      if (needsDelivery(target)) {
        schedule('deliver',0);
        return;
      }
      if (needsUpdateDelivery(target)) {
        schedule('deliver_updates',0);
        return;
      }
      // A review can start in Connections and finish in a separate worker.
      // A status snapshot may also be unavailable during a mailbox change.
      const retryCurrentChat = target > 0 && review?.state === 'unavailable';
      if (thread && (retryCurrentChat || review?.inbox_background || ['needs_start','ready','failed'].includes(review?.state))) {
        schedule('status',inboxWorking(review) ? 2000 : 15000);
      }
      return;
    }
    const nextAt = timestamp(review.next_attempt_at);
    if (review.state === 'queued' && (!Number.isFinite(nextAt) || nextAt <= Date.now())) schedule('advance',600);
    else schedule('status',review.state === 'working' ? 2000
      : Number.isFinite(nextAt) && nextAt > Date.now() ? Math.min(15000,Math.max(1500,nextAt-Date.now())) : 2500);
  }

  function send(payload,signal,keepalive = false) {
    return fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal,keepalive,
      headers:{'Content-Type':'application/json'},body:JSON.stringify({owner_key:owner,csrf_token:cfg.csrf,
        ...(['status','start','advance'].includes(payload.action) ? host ? {include_report:true} : {include_chat_result:true} : {}),...payload})});
  }
  function acceptUpdateAccess(value) {
    if (itemRef(value?.scope_ref) && value.scope_ref !== updateAccess?.scope_ref) checkedRoutineRows = new WeakSet();
    routineStatusNeedsProof = false;
    updateAccess = itemRef(value?.scope_ref) ? value : null;
    renderUpdates();
  }
  function accept(value, expectedRunId = null, fromStatus = true) {
    if (host && (value.start_token ?? null) !== startToken) {
      closeMessage();
      denied = true;
      review = null;
      pageError = reloadMessage;
      return;
    }
    if (expectedRunId !== null && value.run_id !== expectedRunId) return;
    const {state,run_id,stage,scanned,updated_at,next_attempt_at,has_report,total,chat_result,inbox_working_until,inbox_updates,inbox_background} = value;
    if (thread && fromStatus) {
      if (state === 'unavailable' && !itemRef(inbox_updates?.scope_ref) && updateAccess !== null) {
        // This status is also used for transient errors. Require exact Chat proof before using cached rows.
        if (!routineStatusNeedsProof) {
          updateAccess = undefined;
          freshRoutineRows = new WeakSet();
          checkedRoutineRows = new WeakSet();
          routineStatusNeedsProof = true;
          renderUpdates();
        }
      } else acceptUpdateAccess(state === 'disabled' ? null : inbox_updates);
    }
    review = host ? value : {state,run_id,stage,scanned,updated_at,next_attempt_at,has_report,total,chat_result,inbox_working_until,inbox_updates,inbox_background};
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
    const target = ['deliver','deliver_updates','check_overdue'].includes(action) ? chatTarget() : null;
    if ((action === 'deliver' && !needsDelivery(target)) ||
        (action === 'deliver_updates' && !needsUpdateDelivery(target))) {
      nextCheck();
      return;
    }
    if (action === 'check_overdue') {
      const ref = overdueCheckRef(target);
      if (!ref) { nextCheck(); return; }
      lastCheckAttempt = {ref,at:Date.now(),count:lastCheckAttempt?.ref === ref ? lastCheckAttempt.count+1 : 1};
      Object.assign(payload,{check_ref:ref,working_attention_binding:binding,include_chat_result:true});
      if (target > 0) Object.assign(payload,{agent_id:agent,conversation_id:target});
    }
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
    const timeout = setTimeout(() => controller.abort(),{status:8000,start:15000,advance:120000,deliver:18000,deliver_updates:18000,check_overdue:120000}[action]);
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
      if (action === 'check_overdue') watchProgress(controller,current,null,350);
      if (action === 'deliver_updates') {
        Object.assign(payload,{agent_id:agent,conversation_id:target,working_attention_binding:binding});
      }
      const response = await send(payload,controller.signal);
      const data = await response.json();
      if (current !== epoch || !inScope()) return;
      if (action === 'check_overdue' && target !== chatTarget()) return;
      if (['deliver','deliver_updates','check_overdue'].includes(action) && response.status === 409) {
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
      if (action === 'deliver_updates' && response.ok && data?.ok === true) {
        if (target !== chatTarget()) return;
        review.inbox_updates = data.inbox_updates;
        acceptUpdateAccess(data.inbox_updates);
        await syncUpdates();
        return;
      }
      if (!response.ok || data?.ok !== true || !valid(data.review)) throw Error('Review status unavailable');
      accept(data.review);
      if (action === 'check_overdue' && target > 0) await continuity()?.syncConversation?.(target);
      if (!host) await syncUpdates();
      if (!denied && action !== 'status') reviewSignal?.postMessage('review-changed');
    } catch (_) {
      if (current !== epoch || !inScope()) return;
      failed = true;
      if (action === 'check_overdue') return;
      if (action === 'status' && thread) void recoverRoutineRows();
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
      else if (failed && (thread || action !== 'status' || active(review))) {
        schedule('status',action === 'status' && !active(review) ? 15000 : 5000);
      }
      else nextCheck();
    }
  }

  function refresh() {
    clearTimeout(timer);
    if (!inScope()) { closeMessage(); publishWork(); renderRail(); renderCompletion(); renderUpdates(); return; }
    renderCompletion();
    renderUpdates();
    restoreCompose();
    restoreTriageItem();
    if (pending) refreshPending = true;
    else schedule('status',0);
  }
  function invalidate() {
    epoch++;
    closeMessage();
    watchRequest?.abort();
    watchRequest = null;
    watchlist = null;
    watchReadAfter = 0;
    clearTimeout(timer);
    stopProgress();
    if (['status','deliver','deliver_updates','check_overdue'].includes(pending?.action)) pending.controller.abort();
    review = null;
    updateAccess = undefined;
    freshRoutineRows = new WeakSet();
    checkedRoutineRows = new WeakSet();
    routineRead?.abort();
    routineRead = null;
    routineStatusNeedsProof = false;
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
  window.addEventListener('vp3:chat-context-ready',() => {
    for (const row of thread?.querySelectorAll('[data-gmail-content-post="true"]') || []) renderGmailPostLinks(row);
    refresh();
  });
  window.addEventListener('vp3:chat-canvas-view-changed',() => {
    renderCompletion();
    if (thread?.dataset.view === 'chat') refresh();
    else if (openedMessage?.dialog) closeMessage();
  });
  window.addEventListener('vp3:chat-message-persisted',event => {
    const row = event.detail?.element;
    renderGmailPostLinks(row);
    if (row?._vp3GmailInboxReviewV1?.kind === 'batch') {
      if (row._vp3GmailInboxReviewV1.routine_digest === true) freshRoutineRows.add(row);
      renderUpdates();
    }
    else if (row?._vp3GmailInboxReviewV1 || delivery?.message_id === Number(row?.dataset.chatMessageId)) renderCompletion();
  });
  window.addEventListener('vp3:chat-gmail-projection-updated',event => {
    const row = event.detail?.element;
    if (row?._vp3GmailInboxReviewV1?.routine_digest === true) freshRoutineRows.add(row);
    renderUpdates();
  });
  window.addEventListener('vp3:agent-orb-ready',publishWork);
  window.addEventListener('vp3:chat-email-watch-changed',() => {
    if (watchRequest) return;
    watchReadAfter = 0;
    void readWatchlist();
  });
  window.addEventListener('stonefellow:chat-context-changing',invalidate);
  window.addEventListener('vp3:participant-selection-changed',() => { invalidate(); refresh(); });
  window.addEventListener('vp3:participant-task-context',event => { participant = Number(event.detail?.participant_id) || 0; invalidate(); refresh(); });
  window.addEventListener('pagehide',() => { stopped = true; reviewSignal?.close(); reviewSignal = null; invalidate(); });
  window.addEventListener('pageshow',() => { stopped = false; listenForReviewChanges(); refresh(); });
  for (const row of app?.querySelectorAll('[data-gmail-review-latest-item]') || []) {
    attachItemActions(row,{item_ref:row.dataset.itemRef,legacy_ref:row.dataset.legacyRef});
  }
  if (thread) {
    // The canonical Chat restore can finish before this deferred script attaches its listeners.
    for (const row of thread.querySelectorAll('[data-chat-message-role="assistant"][data-gmail-routine-post="true"]')) {
      if (row._vp3GmailInboxReviewV1?.routine_digest === true) freshRoutineRows.add(row);
    }
    for (const row of thread.querySelectorAll('[data-gmail-content-post="true"]')) renderGmailPostLinks(row);
    window.VP3GmailInboxInitialV1 = Object.freeze({openTriageItem,openArrivedItem,openReportedItem,
      renderMessageBody:(panel,text,links,bodyClass,display,subject,documents,captureSource,sender) => renderOpenedEmail(panel,text,links,null,bodyClass,display,subject,documents,captureSource,sender)});
  }
  listenForReviewChanges();
  render();
  refresh();
})();
