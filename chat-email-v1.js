(() => {
  'use strict';
  const cfg = window.VP3_CHAT_EMAIL_V1;
  const chatConfig = window.STONEFELLOW_CHAT;
  if (!cfg?.endpoint || !cfg.csrf || String(chatConfig?.userId) !== cfg.ownerKey) return;

  const element = (tag, text) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, handler) => {
    const node = element('button', text);
    node.type = 'button';
    node.addEventListener('click', handler);
    return node;
  };
  const dialog = element('dialog');
  dialog.id = 'vp3ChatEmailReview';
  dialog.setAttribute('aria-labelledby', 'vp3ChatEmailReviewTitle');
  const header = element('header');
  const title = element('h2', 'Review Gmail draft');
  title.id = 'vp3ChatEmailReviewTitle';
  const close = button('Close', () => dialog.close());
  header.append(title, close);

  const details = element('div');
  const status = element('p');
  status.setAttribute('role', 'status');
  const actions = element('div');
  actions.className = 'vp3-chat-email-actions';
  dialog.append(header, details, status, actions);
  document.body.append(dialog);

  let selected = null;
  let pending = null;
  let specialistRequest = null;
  let specialistTimer = 0;
  let specialistExpiryTimer = 0;
  let specialistWatching = false;
  let writeReviewTimer = 0;
  const activeWrites = new Set();
  const seenWritingDrafts = new Set();
  const writingCard = '[data-chat-email-writing="1"][data-chat-email-review]';
  const thread = document.getElementById('chatThread');
  const compactStatus = button('Email Specialist · Checking Gmail', () => window.VP3ChatSpecialists?.open?.('email', compactStatus));
  compactStatus.className = 'vp3-chat-email-specialist-status';
  compactStatus.setAttribute('aria-haspopup', 'dialog');
  compactStatus.setAttribute('aria-controls', 'vp3-chat-specialist-panel-email');
  compactStatus.setAttribute('aria-expanded', 'false');
  compactStatus.hidden = true;
  document.getElementById('chatComposerShell')?.prepend(compactStatus);

  function currentScope() {
    if (window.VP3ParticipantContinuity?.selectedContext?.()) return null;
    const config = window.STONEFELLOW_CHAT;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const agent = Number(chat?.agentId?.() ?? cfg.agentId);
    const conversation = Number(chat?.conversationId?.() || 0);
    if (String(config?.userId) !== cfg.ownerKey || Number(config?.agentId || 0) !== agent || agent !== cfg.agentId
        || chat?.isRestoring?.() || chat?.isReady?.() === false || !Number.isSafeInteger(conversation) || conversation < 1) return null;
    return { agent_id: agent, conversation_id: conversation };
  }

  function scopeMatches(selection) {
    const scope = currentScope();
    return Boolean(selection) && scope?.conversation_id === selection.conversation_id
      && scope.agent_id === selection.agent_id;
  }

  function valid(selection) {
    return dialog.open && selected === selection && scopeMatches(selection);
  }

  function specialistScope() {
    return !document.hidden && thread?.dataset.view === 'chat' ? currentScope() : null;
  }

  function showSpecialist(status) {
    clearTimeout(specialistExpiryTimer);
    const expiresAt = status?.state === 'working' ? Date.parse(status.working_until) : 0;
    const remaining = expiresAt - Date.now();
    if (!(remaining > 0)) {
      compactStatus.hidden = true;
      window.VP3ChatSpecialists?.remove('email');
      return;
    }
    compactStatus.hidden = false;
    const work = status.work === 'compose' ? ['Writing Gmail draft','Preparing the exact reply for your approval.']
      : status.work === 'revise' ? ['Revising Gmail draft','Applying your instructions to the saved draft.']
        : ['Checking Gmail','Looking for new mail matching a watch from this Chat.'];
    compactStatus.textContent = `Email Specialist · ${work[0]}`;
    window.VP3ChatSpecialists?.update('email', {
      label:'Email Specialist', title:work[0], status:'Working', detail:work[1], expiresAt,
    });
    specialistExpiryTimer = setTimeout(() => showSpecialist(null), Math.min(remaining,180000));
  }

  function clearSpecialist() {
    clearTimeout(specialistTimer);
    specialistRequest?.abort();
    specialistRequest = null;
    specialistWatching = false;
    showSpecialist(null);
  }

  function refreshSpecialistSoon(delay = 0) {
    clearTimeout(specialistTimer);
    if (!specialistScope()) {
      clearSpecialist();
      return;
    }
    specialistTimer = setTimeout(() => void refreshSpecialist(), delay);
  }

  async function refreshSpecialist() {
    const scope = specialistScope();
    if (!scope) { clearSpecialist(); return; }
    const endpoint = new URL(cfg.endpoint, location.href);
    if (endpoint.origin !== location.origin) { clearSpecialist(); return; }

    specialistRequest?.abort();
    const controller = new AbortController();
    specialistRequest = controller;
    const timeout = setTimeout(() => controller.abort(), 10000);
    let retry = 0;
    const current = () => specialistRequest === controller && specialistScope()?.conversation_id === scope.conversation_id;
    try {
      const response = await fetch(endpoint, {
        method:'POST', credentials:'same-origin', cache:'no-store', signal:controller.signal,
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({...scope, action:'specialist_status', owner_key:cfg.ownerKey, csrf_token:cfg.csrf}),
      });
      const data = await response.json();
      if (!current()) return;
      if (response.ok && data.ok === true) {
        showSpecialist(data.specialist);
        const state = data.specialist?.state;
        specialistWatching = state === 'watching' || state === 'paused' || state === 'working';
        if (activeWrites.size) retry = 1000;
        else if (specialistWatching) retry = state === 'working' ? 5000 : 15000;
      } else {
        showSpecialist(null);
        if (specialistWatching && response.status >= 500) retry = 30000;
      }
    } catch (_error) {
      if (!current()) return;
      showSpecialist(null);
      if (specialistWatching) retry = 30000;
    } finally {
      clearTimeout(timeout);
      if (specialistRequest === controller) {
        specialistRequest = null;
        if (retry) refreshSpecialistSoon(retry);
      }
    }
  }

  function labelField(text, field) {
    const label = element('label');
    label.append(element('span', text), field);
    return label;
  }

  function watchWriting(selection, draft) {
    clearTimeout(writeReviewTimer);
    if (!valid(selection) || draft.connection_changed) return;
    if (draft.status === 'write_queued' && !activeWrites.has(selection.draft_id)) {
      void advanceWriting(selection);
    } else if (draft.status === 'write_queued' || draft.status === 'writing') {
      refreshSpecialistSoon();
      writeReviewTimer = setTimeout(() => { if (valid(selection)) void request('review'); },1500);
    }
  }

  async function advanceWriting(selection) {
    if (!scopeMatches(selection) || activeWrites.has(selection.draft_id)) return;
    activeWrites.add(selection.draft_id);
    const openDraft = () => !pending && selected?.draft_id === selection.draft_id && valid(selected) ? selected : null;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(),125000);
    refreshSpecialistSoon(150);
    try {
      const response = await fetch(cfg.endpoint,{
        method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json'},
        body:JSON.stringify({...selection,action:'advance_write',owner_key:cfg.ownerKey,csrf_token:cfg.csrf}),
      });
      const data = await response.json().catch(() => null);
      const review = openDraft();
      if (!review) return;
      if (!response.ok || !data?.ok || data.draft?.id !== selection.draft_id) {
        throw new Error(data?.error || 'The Gmail writing status could not be verified.');
      }
      render(data.draft);
      watchWriting(review,data.draft);
    } catch (error) {
      if (!openDraft()) return;
      status.textContent = error.name === 'AbortError' ? 'Refresh the review to check the writing status.'
        : error.message || 'The Gmail writing status could not be verified.';
      actions.replaceChildren(button('Refresh review',() => request('review')));
    } finally {
      clearTimeout(timeout);
      activeWrites.delete(selection.draft_id);
      refreshSpecialistSoon();
    }
  }

  function advanceQueuedCards(cards = thread?.querySelectorAll(writingCard) ?? []) {
    const scope = currentScope();
    if (!scope || thread?.dataset.view !== 'chat') return;
    for (const card of cards) {
      const draft_id = card.dataset.chatEmailReview;
      if (seenWritingDrafts.has(draft_id) || Number(card.dataset.chatEmailConversation) !== scope.conversation_id
          || !/^[a-f0-9]{32}$/.test(draft_id)) continue;
      seenWritingDrafts.add(draft_id);
      void advanceWriting({...scope,draft_id});
    }
  }

  async function request(action, extra = {}) {
    const selection = selected;
    if (!selection || !valid(selection) || pending) return;
    const controller = new AbortController();
    pending = controller;
    const progress = {
      review: 'Loading the exact draft…', confirm: 'Checking the reviewed send…',
      revise: 'Saving your edits…', revise_ai: 'Queuing your revision…', retry_write:'Queuing another writing attempt…',
      discard: 'Discarding the draft…'
    };
    status.textContent = progress[action];
    const fields = [...dialog.querySelectorAll('button,input,textarea')].filter(field => field !== close);
    const prior = fields.map(field => field.disabled);
    fields.forEach(field => { field.disabled = true; });
    try {
      const response = await fetch(cfg.endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ ...selection, ...extra, action, owner_key:cfg.ownerKey, csrf_token:cfg.csrf })
      });
      const data = await response.json().catch(() => null);
      if (!valid(selection) || pending !== controller) return;
      if (!response.ok || !data?.ok || data.draft?.id !== selection.draft_id) {
        throw new Error(data?.error || 'The email review could not be verified.');
      }
      render(data.draft);
      watchWriting(selection,data.draft);
      if (action === 'confirm' && data.draft.watch_registered) refreshSpecialistSoon();
      if (action === 'revise') status.textContent = 'Changes saved. Review the updated email before sending.';
    } catch (error) {
      if (!valid(selection) || error.name === 'AbortError') return;
      status.textContent = error.message || 'The email review is unavailable.';
      if (['confirm','review','revise','revise_ai','retry_write'].includes(action)) {
        actions.replaceChildren(button('Refresh review', () => request('review')));
      }
    } finally {
      if (pending === controller) {
        pending = null;
        fields.forEach((field, index) => { field.disabled = prior[index]; });
      }
    }
  }

  function render(draft) {
    details.replaceChildren();
    actions.replaceChildren();
    const states = {
      prepared: draft.is_reply && !draft.body.trim()
        ? 'Write your reply, then review it before sending.'
        : 'Review the exact email before approving the send.',
      write_queued: 'The Email Specialist is queued to write this draft.',
      writing: draft.write_kind === 'revise' ? 'The Email Specialist is revising the saved draft.' : 'The Email Specialist is writing the reply.',
      write_failed: 'AI could not finish this draft. Retry with AI or use the manual editor.',
      sending: 'Gmail’s send outcome is pending. Check the status of this reviewed email.',
      sent: draft.watch_registered
        ? 'Gmail confirmed the send. A reply watch was registered for up to 30 days.'
        : (draft.watch_requested ? 'Gmail confirmed the send. Reply watching was unavailable.' : 'Gmail confirmed the send.'),
      failed: 'Gmail rejected this send. Prepare a new email in Chat to try again.',
      discarded: 'Draft discarded.',
      expired: 'This review expired. Prepare a new email in Chat.'
    };
    if (draft.connection_changed) {
      status.textContent = draft.status === 'sending'
        ? 'Gmail access changed while this send was pending. Check Sent in Gmail for the outcome.'
        : 'Gmail access changed. Check the account in Connections, then prepare a new draft.';
    } else {
      status.textContent = states[draft.status] || 'The email review is unavailable.';
    }
    if (draft.status === 'discarded') return;

    const addresses = element('dl');
    for (const [name, value] of [['From', draft.from], ['To', draft.to]]) {
      addresses.append(element('dt', name), element('dd', value));
    }
    if (draft.message_id) addresses.append(element('dt', 'Gmail message ID'), element('dd', draft.message_id));
    const subject = element('input');
    subject.type = 'text';
    subject.maxLength = 200;
    subject.value = draft.subject;
    const body = element('textarea');
    body.maxLength = 6000;
    body.rows = 7;
    body.value = draft.body;
    const writeFailed = draft.status === 'write_failed';
    const editable = (draft.status === 'prepared' || writeFailed) && Boolean(draft.review_token);
    let editing = editable && !writeFailed && draft.is_reply === true && !draft.body.trim();
    subject.readOnly = !editing || draft.is_reply === true;
    body.readOnly = !editing;
    details.append(addresses, labelField('Subject', subject), labelField('Message', body));

    const watch = element('input');
    watch.type = 'checkbox';
    watch.checked = Boolean(draft.watch_requested);
    watch.disabled = draft.status !== 'prepared';
    if (draft.watch_review_token) {
      const label = element('label');
      label.className = 'vp3-chat-email-watch';
      label.append(watch, element('span', 'Watch for the first reply for 30 days'));
      details.append(label);
    }
    if (['prepared','write_failed','write_queued'].includes(draft.status) && !draft.connection_changed) actions.append(button('Discard draft', () => request('discard')));
    if (!draft.review_token && !draft.watch_review_token) return;
    const writeBody = body.value.trim();
    const complete = () => (draft.is_reply === true || Boolean(subject.value.trim())) && Boolean(body.value.trim());
    const confirm = button(draft.status === 'sending' ? 'Check send status' : 'Approve and send', () => {
      if (writeFailed || editing || !complete() || subject.value !== draft.subject || body.value !== draft.body) return;
      const token = watch.checked ? draft.watch_review_token : draft.review_token;
      if (token) void request('confirm', { confirmed:true, review_token:token, watch_replies:watch.checked });
    });
    confirm.className = 'vp3-chat-email-primary';
    confirm.disabled = !complete();
    if (editable) {
      const changed = () => subject.value !== draft.subject || body.value !== draft.body;
      const edit = button('Edit manually', () => {
        editing = true;
        status.textContent = 'Make your changes, then select Save edits.';
        sync();
        body.focus();
      });
      const cancel = button('Cancel edits', () => {
        subject.value = draft.subject;
        body.value = draft.body;
        editing = false;
        status.textContent = states.prepared;
        sync();
        edit.focus();
      });
      const revise = button('Save edits', () => {
        if (!editing || (!changed() && !writeFailed) || !complete()) return;
        void request('revise', { review_token:draft.review_token, subject:subject.value, body:body.value });
      });
      const aiInstruction = element('textarea');
      aiInstruction.rows = 3;
      aiInstruction.maxLength = 600;
      const aiField = labelField('Revision instructions',aiInstruction);
      aiField.hidden = true;
      details.append(aiField);
      let revising = false;
      const ai = button('Revise with AI (uses tokens)', () => {
        revising = true;
        status.textContent = 'Describe what you want the Email Specialist to change.';
        sync();
        aiInstruction.focus();
      });
      const cancelAi = button('Cancel revision', () => {
        revising = false;
        status.textContent = states[draft.status];
        sync();
        ai.focus();
      });
      const generate = button('Generate revision', () => {
        const instruction = aiInstruction.value.trim();
        if (revising && instruction) void request('revise_ai', {review_token:draft.review_token,instruction});
      });
      const retry = button('Retry AI (uses tokens)', () => request('retry_write',{review_token:draft.review_token}));
      const sync = () => {
        subject.readOnly = !editing || draft.is_reply === true;
        body.readOnly = !editing;
        watch.disabled = editing || revising || writeFailed;
        edit.hidden = editing || revising;
        cancel.hidden = !editing;
        revise.hidden = !editing;
        revise.disabled = (!changed() && !writeFailed) || !complete();
        confirm.hidden = editing || revising || writeFailed;
        confirm.disabled = editing || revising || writeFailed || !complete() || changed();
        ai.hidden = !writeBody || editing || revising;
        aiField.hidden = !revising;
        generate.hidden = !revising;
        generate.disabled = !aiInstruction.value.trim();
        cancelAi.hidden = !revising;
        retry.hidden = !writeFailed || editing || revising;
      };
      subject.addEventListener('input', sync);
      body.addEventListener('input', sync);
      aiInstruction.addEventListener('input',sync);
      sync();
      actions.append(edit,revise,cancel,ai,generate,cancelAi,retry);
    }
    actions.append(confirm);
    if (editing) body.focus();
  }

  function replyToResult(mode, target, instruction = '') {
    if (dialog.open && navigator.onLine === false) {
      status.textContent = 'Reconnect to prepare this Gmail reply.';
      return;
    }
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const verb = mode === 'compose' ? 'Draft reply' : 'Reply';
    const suffix = mode === 'compose' && instruction ? ': ' + instruction : '';
    const opened = chat?.requestGmailReviewAction?.(`${verb} to Gmail ${target}${suffix}`) === true;
    if (opened && dialog.open) dialog.close();
    if (!opened && dialog.open) status.textContent = 'Finish the current Chat message before opening the reply editor.';
  }

  async function openMessage(scope, reference, type) {
    pending?.abort();
    const controller = pending = new AbortController();
    const selection = selected = {...scope};
    const followup = type === 'follow-up';
    const target = followup ? `follow-up ${reference}` : `search item ${reference}`;
    const timeout = setTimeout(() => controller.abort(),15000);
    title.textContent = 'Gmail message';
    details.replaceChildren();
    actions.replaceChildren();
    status.textContent = 'Opening the exact Gmail message…';
    if (!dialog.open) dialog.showModal();
    try {
      const response = await fetch(cfg.endpoint,{
        method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json'},
        body:JSON.stringify({...scope,action:followup?'open_followup':'open_search',owner_key:cfg.ownerKey,csrf_token:cfg.csrf,
          ...(followup?{message_id:reference}:{item_ref:reference})}),
      });
      const data = await response.json().catch(() => null);
      if (!valid(selection) || pending !== controller) return;
      if (!response.ok || !data?.ok || !['subject','from','body'].every(field => typeof data.message?.[field] === 'string')) {
        throw new Error(data?.error || 'This Gmail message is unavailable.');
      }
      const message = data.message;
      const headers = element('dl');
      headers.append(element('dt','From'),element('dd',message.from),element('dt','Subject'),element('dd',message.subject || '(No subject)'));
      const body = element('p',message.body || 'This message has no readable text.');
      body.className = 'vp3-chat-email-message-body';
      details.append(headers,body);
      if (message.can_reply) {
        const instruction = element('input');
        instruction.type = 'text';
        instruction.maxLength = 600;
        details.append(labelField('Auto Compose instructions (optional)',instruction));
        actions.append(button('Reply',() => {
          if (valid(selection)) replyToResult('reply',target);
        }),button('Auto Compose (uses tokens)',() => {
          if (!valid(selection)) return;
          const text = instruction.value.trim();
          if (text && !/^[^\u0000-\u001f\u007f-\u009f\u2028\u2029]{1,600}$/u.test(text)) {
            status.textContent = 'Enter up to 600 characters on one line.';
            instruction.focus();
            return;
          }
          replyToResult('compose',target,text);
        }));
      }
      status.textContent = '';
    } catch (error) {
      if (valid(selection) && pending === controller) status.textContent = error.name === 'AbortError'
        ? 'Opening this Gmail message timed out. Close it and try again.' : error.message;
    } finally {
      clearTimeout(timeout);
      if (pending === controller) pending = null;
    }
  }

  document.addEventListener('click', event => {
    const target = event.target.closest?.('[data-chat-email-review],[data-chat-email-followup],[data-chat-email-search]');
    if (!target || !document.getElementById('chatThread')?.contains(target)) return;
    const scope = currentScope();
    if (!scope || Number(target.dataset.chatEmailConversation) !== scope.conversation_id) return;
    if (target.hasAttribute('data-chat-email-followup')) {
      if (!event.isTrusted) return;
      const id = Number(target.dataset.chatEmailFollowup);
      const action = target.dataset.chatEmailFollowupAction;
      if (!Number.isSafeInteger(id) || id < 1 || !['open','reply','compose'].includes(action)) return;
      if (action === 'open') void openMessage(scope,id,'follow-up');
      else replyToResult(action,`follow-up ${id}`);
      return;
    }
    if (target.hasAttribute('data-chat-email-search')) {
      const ref = target.dataset.chatEmailSearch;
      if (event.isTrusted && /^[a-f0-9]{2,1500}$/.test(ref) && ref.length % 2 === 0) void openMessage(scope,ref,'search');
      return;
    }
    const draft = target.dataset.chatEmailReview;
    if (!/^[a-f0-9]{32}$/.test(draft)) return;
    pending?.abort();
    pending = null;
    title.textContent = 'Review Gmail draft';
    selected = { ...scope, draft_id:draft };
    details.replaceChildren();
    actions.replaceChildren();
    if (!dialog.open) dialog.showModal();
    void request('review');
  });

  dialog.addEventListener('close', () => {
    pending?.abort();
    clearTimeout(writeReviewTimer);
    pending = null;
    selected = null;
    details.replaceChildren();
    actions.replaceChildren();
    status.textContent = '';
  });
  window.addEventListener('stonefellow:chat-context-changing', () => {
    if (dialog.open) dialog.close();
    clearSpecialist();
  });
  window.addEventListener('vp3:chat-conversation-changed', () => {
    if (selected && !valid(selected)) dialog.close();
  });
  window.addEventListener('vp3:participant-selection-changed', () => {
    if (dialog.open && !valid(selected)) dialog.close();
    clearSpecialist();
    refreshSpecialistSoon();
  });
  window.addEventListener('vp3:chat-context-ready', () => {
    refreshSpecialistSoon();
    advanceQueuedCards();
  });
  window.addEventListener('vp3:chat-canvas-view-changed', () => {
    refreshSpecialistSoon();
    advanceQueuedCards();
  });
  window.addEventListener('focus', () => refreshSpecialistSoon());
  document.addEventListener('visibilitychange', () => refreshSpecialistSoon());
  window.addEventListener('pagehide', clearSpecialist);
  window.addEventListener('pageshow', () => refreshSpecialistSoon());
  window.addEventListener('vp3:chat-email-watch-changed', () => refreshSpecialistSoon());
  if (thread) new MutationObserver(records => {
    const cards = [];
    for (const record of records) for (const node of record.addedNodes) {
      if (node.matches?.(writingCard)) cards.push(node);
      else if (node.querySelectorAll) cards.push(...node.querySelectorAll(writingCard));
    }
    if (cards.length) advanceQueuedCards(cards);
  }).observe(thread,{childList:true,subtree:true});
  refreshSpecialistSoon();
  advanceQueuedCards();

  function installGithubWorkflowReview(gmail) {
    if (!cfg.githubWorkflow?.endpoint) return;
    const toolsPanel = gmail.closest('#chatToolsPanel');
    const form = element('form');
    form.className = 'vp3-chat-github-workflow';
    const toggle = element('input');
    toggle.type = 'checkbox';
    toggle.setAttribute('aria-describedby', 'vp3GithubWorkflowHelp');
    const toggleLabel = element('label');
    toggleLabel.className = 'vp3-chat-github-toggle';
    toggleLabel.append(toggle, element('strong', 'GitHub Workflow Review'));
    const description = element('p', 'Review new GitHub Actions notifications in your Gmail inbox and save findings in your private Knowledge. Announcements follow your Agent Voice setting.');
    description.id = 'vp3GithubWorkflowHelp';
    const destination = element('label');
    destination.className = 'vp3-chat-github-folder';
    const folder = element('input');
    folder.type = 'text';
    folder.name = 'folder_name';
    folder.maxLength = 160;
    folder.autocomplete = 'off';
    folder.placeholder = 'e.g. Workflow reviews';
    const folders = element('datalist');
    folders.id = 'vp3GithubWorkflowFolders';
    folder.setAttribute('list', folders.id);
    destination.append(element('span', 'Private Knowledge folder name'), folder,
      element('small', 'Choose the name of a root folder. VP3 will use your existing folder or create it for you.'));
    const status = element('p');
    status.setAttribute('role', 'status');
    const save = element('button', 'Save workflow review');
    save.type = 'submit';
    const restart = button('Restart workflow review', () => void send('restart'));
    const retry = button('Retry loading settings', () => void send('state'));
    const controls = element('div');
    controls.className = 'vp3-chat-github-controls';
    controls.append(save, restart, retry);
    form.append(toggleLabel, description, destination, folders, controls, status);
    gmail.append(form);

    let state = null;
    let request = null;
    let loadFailed = false;
    const hasOwnerScope = () => String(window.STONEFELLOW_CHAT?.userId) === cfg.ownerKey
      && !window.VP3ParticipantContinuity?.selectedContext?.();
    const visible = () => hasOwnerScope() && gmail.open && !toolsPanel?.hidden && !document.hidden;
    const messages = {
      disabled:'Workflow review is off.',
      waiting:'Waiting for the next background check.',
      checked:'Workflow review is on.',
      retrying:'The last check could not finish. Workflow review will try again.',
      restart_required:'Gmail’s review position expired. Restart to review new arrivals from now on.',
      destination_unavailable:'Choose a private Knowledge folder to resume workflow review.',
      gmail_required:'Connect Gmail and enable read access to use workflow review.',
      permission_required:'Workflow review requires Gmail read access and permission to manage private Knowledge.',
      unavailable:'Workflow review is currently unavailable.',
    };

    function renderControls() {
      const available = state?.available === true;
      const canDisable = state?.can_disable === true;
      const enabled = toggle.checked;
      const folderName = folder.value.trim();
      toggle.disabled = Boolean(request) || (!available && !canDisable);
      destination.hidden = !enabled;
      folder.disabled = Boolean(request) || !enabled || !available;
      folder.required = enabled;
      save.disabled = Boolean(request) || (enabled
        ? !available || !folderName || (state?.enabled && state.folder_name === folderName && state.status !== 'destination_unavailable')
        : !canDisable);
      restart.hidden = !(available && state?.enabled && state.status === 'restart_required');
      restart.disabled = Boolean(request);
      retry.hidden = !loadFailed;
      retry.disabled = Boolean(request);
    }

    function setState(next) {
      state = next;
      toggle.checked = next?.enabled === true;
      folder.value = typeof next?.folder_name === 'string' ? next.folder_name : '';
      folders.replaceChildren();
      for (const entry of Array.isArray(next?.folders) ? next.folders : []) {
        if (typeof entry?.name !== 'string') continue;
        const option = element('option');
        option.value = entry.name;
        folders.append(option);
      }
      status.textContent = next ? messages[next.status] || '' : '';
      renderControls();
    }

    function clear() {
      request?.abort();
      request = null;
      loadFailed = false;
      setState(null);
    }

    async function send(action) {
      if (request || !visible()) return;
      const payload = {action, owner_key:cfg.ownerKey, csrf_token:cfg.csrf};
      if (action !== 'state') {
        if (!state) return;
        Object.assign(payload, {connection_id:state.connection_id, connection_revision:state.connection_revision, github_revision:state.revision});
      }
      if (action === 'enable') {
        const name = folder.value.trim();
        if (!name || Array.from(name).length > 80) {
          status.textContent = 'Enter a folder name of up to 80 characters.';
          folder.focus();
          return;
        }
        payload.folder_name = name;
      }
      const current = new AbortController();
      request = current;
      const timeout = setTimeout(() => current.abort(), 10000);
      let failure = action === 'state' ? 'Could not load workflow review settings.' : 'Could not save workflow review settings.';
      status.textContent = action === 'state' ? 'Loading workflow review settings…' : 'Saving workflow review settings…';
      renderControls();
      try {
        const response = await fetch(cfg.githubWorkflow.endpoint, {
          method:'POST', credentials:'same-origin', cache:'no-store', signal:current.signal,
          headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(payload),
        });
        const result = await response.json();
        if (request !== current || !visible()) return;
        if (!response.ok || result?.ok !== true) {
          if ([401,403,409,419].includes(response.status)) setState(null);
          if (response.status === 409) failure = 'Workflow review settings changed. Close and reopen Gmail to refresh them.';
          if (response.status === 422) failure = 'Check the Knowledge folder name and try again.';
          throw new Error(failure);
        }
        if (result.owner_key !== cfg.ownerKey || !result.state) {
          setState(null);
          throw new Error(failure);
        }
        loadFailed = false;
        setState(result.state);
      } catch (_error) {
        if (request !== current || !visible()) return;
        loadFailed = action === 'state' || !state;
        status.textContent = failure;
      } finally {
        clearTimeout(timeout);
        if (request === current) {
          request = null;
          renderControls();
        }
      }
    }

    function sync() {
      form.hidden = !hasOwnerScope();
      if (!visible()) clear();
      else if (!state && !request && !loadFailed) void send('state');
    }

    toggle.addEventListener('change', renderControls);
    folder.addEventListener('input', renderControls);
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!save.disabled) void send(toggle.checked ? 'enable' : 'disable');
    });
    gmail.addEventListener('toggle', sync);
    const observer = toolsPanel && new MutationObserver(sync);
    observer?.observe(toolsPanel, {attributeFilter:['hidden']});
    window.addEventListener('vp3:participant-selection-changed', sync);
    window.addEventListener('stonefellow:chat-context-changing', sync);
    window.addEventListener('vp3:chat-context-ready', sync);
    window.addEventListener('focus', sync);
    document.addEventListener('visibilitychange', sync);
    window.addEventListener('pagehide', clear);
    window.addEventListener('pageshow', sync);
    renderControls();
    sync();
  }

  const tools = document.getElementById('chatToolsContent');
  if (tools) {
    const help = element('details');
    const link = element('a','Open Connections');
    link.href = cfg.connectionsUrl;
    help.append(element('summary','Gmail'),
      element('p','Try “Any urgent emails today?” or “Write a new email to buyer@example.com about our launch.”'),
      element('p','To get one notification in this Chat, ask “Notify me when I get an email from buyer@example.com.” To end the watch, ask “Stop watching for emails from buyer@example.com.”'));
    tools.append(help);
    installGithubWorkflowReview(help);
    help.append(link);
  }
})();
