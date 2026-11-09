(() => {
  'use strict';
  const cfg = window.VP3_CHAT_DEVELOPMENT_V1;
  const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87;
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const send = document.getElementById('sendChatButton');
  const thread = document.getElementById('chatThread');
  const composer = document.getElementById('chatComposerShell');
  if (!cfg?.csrf || !chat || !form || !input || !send || !thread || !composer) return;

  const endpoint = new URL(cfg.endpoint,location.href);
  const revisionsUrl = new URL(cfg.revisionsUrl,location.href);
  if ([endpoint,revisionsUrl].some(url => url.origin !== location.origin || url.username || url.password)) return;

  const modeLabels = {outline:'Outline and propose',code_stage:'Full code & stage for launch'};
  const statusLabels = {
    outlining:'Preparing outline', proposed:'Outline ready for review', queued:'Waiting for coding worker',
    building:'Building and testing candidate', staged:'Preview staged for review', approved:'Staged candidate approved',
    cancelled:'Development request cancelled', failed:'Development request needs attention',
  };
  const active = new Set(['outlining','queued','building']);
  const key = 'development';
  const node = (tag,cls = '') => Object.assign(document.createElement(tag),{className:cls});
  const hint = node('div','vp3-chat-development-hint');
  const hintLink = node('a');
  hintLink.href = revisionsUrl.href;
  const compact = node('div','vp3-chat-development-compact');
  const compactText = node('span');
  const compactLink = node('a');
  compactLink.textContent = 'Open revision';
  compactText.setAttribute('role','status');
  compact.append(compactText,compactLink);
  const notice = node('div','vp3-chat-development-notice');
  notice.setAttribute('role','status');
  const draftReview = node('section','vp3-chat-development-draft-review');
  draftReview.hidden = true;
  const draftLabel = node('label');
  draftLabel.textContent = 'Review development draft';
  const draftInput = node('textarea');
  draftInput.rows = 4;
  draftInput.spellcheck = false;
  draftLabel.append(draftInput);
  const draftHelp = node('p');
  draftHelp.textContent = 'Your Chat text is kept. When the composer is empty, move this draft into it and review before sending.';
  const draftActions = node('div','vp3-chat-development-draft-actions');
  const useDraft = node('button');
  useDraft.type = 'button';
  useDraft.textContent = 'Use in composer';
  const dismissDraft = node('button');
  dismissDraft.type = 'button';
  dismissDraft.textContent = 'Dismiss draft';
  draftActions.append(useDraft,dismissDraft);
  draftReview.append(draftLabel,draftHelp,draftActions);
  const result = node('section','vp3-chat-development-result');
  const resultText = node('span');
  const resultLink = node('a');
  resultText.setAttribute('role','status');
  resultLink.textContent = 'Open revision';
  result.append(resultText,resultLink);
  hint.append(hintLink);
  composer.prepend(hint,compact,notice,draftReview);

  let generation = 0;
  let control = null;
  let shownId = '';
  let poll = null;
  let timer = 0;
  let submission = null;
  let tracking = false;
  let draftRequest = null;
  let reviewSource = null;
  let composerEdits = 0;

  function scope() {
    const context = window.STONEFELLOW_CHAT;
    if (document.hidden || thread.dataset.view !== 'chat' || !chat.isReady() || chat.isRestoring()
        || String(context?.userId) !== cfg.ownerKey || Number(context?.agentId || 0) !== cfg.agentId
        || chat.agentId() !== cfg.agentId || window.VP3ParticipantContinuity?.selectedContext?.()) return null;
    const conversation = chat.conversationId();
    if (!Number.isSafeInteger(conversation) || conversation < 0) return null;
    return {agent_id:cfg.agentId, conversation_id:conversation};
  }

  function validScope(current,version) {
    return generation === version && scope()?.conversation_id === current.conversation_id;
  }

  function revisionUrl(id) {
    const url = new URL(revisionsUrl);
    url.searchParams.set('revision',id);
    return url.href;
  }

  function label(row) {
    if (['staged','approved'].includes(row.status) && row.preview_ready !== true) return 'Staged revision · preview unavailable';
    return statusLabels[row.status];
  }

  function validRevision(row) {
    return typeof row?.id === 'string' && /^[a-f0-9]{32}$/.test(row.id) && Object.hasOwn(statusLabels,row.status)
      && Object.hasOwn(modeLabels,row.mode);
  }

  function showNotice(text = '') {
    notice.textContent = text;
    notice.hidden = !text;
  }

  function showControl(data) {
    const previous = control;
    control = data && Number.isSafeInteger(data.epoch) && data.epoch >= 0
      && (data.mode === 'off' || Object.hasOwn(modeLabels,data.mode)) ? {mode:data.mode,epoch:data.epoch} : null;
    if (previous?.epoch !== control?.epoch || previous?.mode !== control?.mode) {
      cancelDraftRequest();
      clearDraftReview();
    }
    hint.hidden = !control || control.mode === 'off';
    if (!hint.hidden) hintLink.textContent = `Agent Development · ${modeLabels[control.mode]} · Draft from a reply or type /develop`;
    syncDraftActions();
  }

  const savedReply = '.message[data-chat-message-role="assistant"][data-chat-message-id]';

  function eligibleReply(row,current) {
    if (!current?.conversation_id || !row?.matches(savedReply) || !thread.contains(row)
        || Number(row.dataset.chatConversationId) !== current.conversation_id
        || !row.querySelector('.message-text')?.textContent.trim()
        || row.matches('[data-profile-handoff-turn],[data-profile-call-id],[data-profile-speech-item],[data-profile-attention-delivery]')
        || row.querySelector('[data-initiative-followthrough-state]')) return null;
    const assistantId = Number(row.dataset.chatMessageId);
    return Number.isSafeInteger(assistantId) && assistantId > 0 ? {assistantId} : null;
  }

  function syncDraftActions() {
    const current = control && control.mode !== 'off' ? scope() : null;
    for (const row of thread.querySelectorAll(savedReply)) {
      const existing = row.querySelector('[data-vp3-development-draft]');
      if (!eligibleReply(row,current)) {
        existing?.remove();
        continue;
      }
      if (existing) continue;
      const host = row.querySelector('.message-body');
      if (!host) continue;
      const button = node('button','vp3-chat-development-draft-trigger');
      button.type = 'button';
      button.textContent = 'Draft /develop';
      button.dataset.vp3DevelopmentDraft = '';
      button.addEventListener('click',() => void prepareDraft(row,button));
      host.append(button);
    }
  }

  function clearDraftReview() {
    reviewSource = null;
    draftInput.value = '';
    draftReview.hidden = true;
  }

  function cancelDraftRequest() {
    if (!draftRequest) return;
    draftRequest.controller.abort();
    draftRequest.button.disabled = false;
    draftRequest = null;
  }

  function validDraftSource(source) {
    if (!source || !validScope(source.current,source.version)
        || control?.epoch !== source.epoch || control?.mode !== source.mode) return false;
    const reply = eligibleReply(source.row,source.current);
    return reply?.assistantId === source.assistantId;
  }

  function typedComposerReady() {
    const live = window.STONEFELLOW_CHAT_CONTINUITY;
    return !submission && !chat.isBusy() && !live?.isBusy?.() && !live?.isVoice?.()
      && !live?.pendingVoiceSubmit?.() && !input.disabled && !send.disabled;
  }

  function validObjective(objective) {
    return typeof objective === 'string' && objective.trim() !== '' && [...objective].length <= 1500;
  }

  function fillDraft(objective) {
    clearDraftReview();
    input.value = '/develop ' + objective.trim();
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.focus();
    showNotice('Draft ready. Review it and send when you want to start development.');
  }

  async function prepareDraft(row,button) {
    const current = scope();
    const reply = eligibleReply(row,current);
    if (!reply || !control || control.mode === 'off' || !typedComposerReady()) {
      showNotice('Use the private typed Admin Chat when the Agent is ready.');
      return;
    }
    cancelDraftRequest();
    clearDraftReview();
    const request = draftRequest = {
      ...reply,row,button,current,version:generation,epoch:control.epoch,mode:control.mode,
      controller:new AbortController(),composerEdits,composerWasEmpty:input.value === '',
    };
    const deadline = setTimeout(() => request.controller.abort(),10000);
    button.disabled = true;
    showNotice('Preparing development draft…');
    try {
      const data = await call('draft',current,request.controller,{assistant_message_id:reply.assistantId});
      if (draftRequest !== request || !validScope(current,request.version)) return;
      if (!validDraftSource(request)) {
        showNotice('This exchange changed. Choose a saved reply again.');
        return;
      }
      if (data.mode !== request.mode || data.epoch !== request.epoch) {
        schedule();
        throw new Error('The automation level changed. Choose the reply again to prepare a draft.');
      }
      if (!validObjective(data.draft)) throw new Error('This exchange did not produce a usable development draft.');
      if (request.composerWasEmpty && input.value === '' && composerEdits === request.composerEdits && typedComposerReady()) {
        fillDraft(data.draft);
      } else {
        reviewSource = request;
        draftInput.value = data.draft;
        draftReview.hidden = false;
        showNotice('Development draft ready for review.');
      }
    } catch (error) {
      if (draftRequest !== request || !validScope(current,request.version)) return;
      showNotice(error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError
        ? 'The draft could not be prepared. Choose the reply again to retry.' : error.message);
    } finally {
      clearTimeout(deadline);
      if (draftRequest === request) {
        draftRequest = null;
        button.disabled = false;
      }
    }
  }

  useDraft.addEventListener('click',() => {
    if (!validDraftSource(reviewSource)) {
      clearDraftReview();
      showNotice('This exchange is no longer available. Choose a saved reply again.');
    } else if (input.value !== '' || !typedComposerReady()) {
      showNotice('Your current Chat text is kept. Use the draft when the typed composer is empty.');
    } else if (!validObjective(draftInput.value)) {
      showNotice('Enter a development request of up to 1,500 characters.');
    } else {
      fillDraft(draftInput.value);
    }
  });

  dismissDraft.addEventListener('click',() => {
    const trigger = reviewSource?.button;
    clearDraftReview();
    showNotice();
    if (trigger?.isConnected) trigger.focus();
  });

  function showSpecialist(row, current = null, version = generation) {
    compact.hidden = !row;
    if (!row) {
      compactText.textContent = '';
      compactLink.removeAttribute('href');
      window.VP3ChatSpecialists?.remove(key);
      return;
    }
    const url = revisionUrl(row.id);
    const name = row.mode === 'outline' ? 'Repair Planner' : 'Coding Specialist';
    const text = `${name} · ${label(row)}`;
    if (compactText.textContent !== text) compactText.textContent = text;
    compactLink.href = url;
    window.VP3ChatSpecialists?.update(key,{
      label:name, title:modeLabels[row.mode], status:label(row), viewLabel:'Open revision',
      onView:() => { if (current && validScope(current,version)) location.assign(url); },
    });
  }

  function showReceipt(row) {
    const text = active.has(row.status) ? 'Development revision saved.' : label(row);
    if (resultText.textContent !== text) resultText.textContent = text;
    resultLink.href = revisionUrl(row.id);
    if (!result.isConnected) {
      thread.insertBefore(result,thread.querySelector('.chat-listening-continuation'));
      thread.scrollTop = thread.scrollHeight;
    }
  }

  function unlock(request) {
    if (submission !== request) return;
    submission = null;
    input.disabled = false;
    send.disabled = false;
  }

  function reset() {
    generation++;
    clearTimeout(timer);
    poll?.abort();
    poll = null;
    cancelDraftRequest();
    clearDraftReview();
    if (submission) {
      submission.controller.abort();
      unlock(submission);
    }
    shownId = '';
    tracking = false;
    showControl(null);
    showSpecialist(null);
    showNotice();
    result.remove();
  }

  async function call(action,current,controller,fields = {}) {
    const response = await fetch(endpoint.href,{
      method:'POST', credentials:'same-origin', cache:'no-store', signal:controller.signal,
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...current, action, owner_key:cfg.ownerKey, csrf_token:cfg.csrf, ...fields}),
    });
    const data = await response.json();
    if (!response.ok || data.ok !== true) {
      const error = new Error(typeof data.error === 'string' ? data.error.slice(0,240) : 'Agent Development is unavailable.');
      error.status = response.status;
      throw error;
    }
    if (data.owner_key !== cfg.ownerKey || data.agent_id !== current.agent_id || data.conversation_id !== current.conversation_id) {
      throw new Error('The development result could not be matched to this Chat.');
    }
    return data;
  }

  async function refresh() {
    const current = scope();
    if (!current) return;
    const version = generation;
    poll?.abort();
    const controller = poll = new AbortController();
    const timeout = setTimeout(() => controller.abort(),10000);
    let next = 0;
    try {
      const data = await call('status',current,controller);
      if (poll !== controller || !validScope(current,version)) return;
      showControl(data);
      const rows = Array.isArray(data.revisions) ? data.revisions.filter(validRevision) : [];
      const pending = control && control.mode !== 'off' ? rows.find(row => row.mode === control.mode && active.has(row.status)) : null;
      tracking = Boolean(pending);
      showSpecialist(pending,current,version);
      const receipt = rows.find(row => row.id === shownId);
      if (receipt) showReceipt(receipt);
      if (pending || submission) next = 5000;
    } catch (error) {
      if (poll !== controller || !validScope(current,version)) return;
      showControl(null);
      showSpecialist(null);
      if (error.status === 403 || error.status === 404) {
        shownId = '';
        tracking = false;
        result.remove();
      }
      if (submission || tracking) next = 10000;
    } finally {
      clearTimeout(timeout);
      if (poll === controller) {
        poll = null;
        if (next) schedule(next);
      }
    }
  }

  function schedule(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => void refresh(),delay);
  }

  async function submit(objective) {
    if (submission) return;
    const initial = scope();
    const live = window.STONEFELLOW_CHAT_CONTINUITY;
    if (!initial || live?.isBusy?.() || live?.isVoice?.() || live?.pendingVoiceSubmit?.() || input.disabled || send.disabled) {
      showNotice('Use the private typed Admin Chat when the Agent is ready.');
      return;
    }
    if (!objective || [...objective].length > 1500) {
      showNotice('Type /develop followed by a request of up to 1,500 characters.');
      return;
    }
    const version = generation;
    const original = input.value;
    cancelDraftRequest();
    clearDraftReview();
    const request = submission = {controller:new AbortController()};
    const deadline = setTimeout(() => request.controller.abort(),90000);
    input.disabled = true;
    send.disabled = true;
    showNotice();
    try {
      const currentControl = await call('status',initial,request.controller);
      if (submission !== request || !validScope(initial,version)) return;
      showControl(currentControl);
      if (!control || control.mode === 'off') throw new Error('Choose an automation level in Admin → Agent Development first.');
      const expectedEpoch = control.epoch;
      const conversation = initial.conversation_id || await chat.ensureConversation();
      if (!conversation && validScope(initial,version)) throw new Error('Open a private Chat before starting development work.');
      const current = {agent_id:initial.agent_id,conversation_id:conversation};
      if (submission !== request || !validScope(current,version)) return;
      schedule(1500);
      const data = await call('create',current,request.controller,{request:objective, expected_epoch:expectedEpoch});
      if (submission !== request || !validScope(current,version)) return;
      if (!validRevision(data.revision)) throw new Error('The revision could not be verified. Check Admin Revisions before trying again.');
      showControl(data);
      shownId = data.revision.id;
      showReceipt(data.revision);
      if (active.has(data.revision.status) && control?.mode === data.revision.mode) showSpecialist(data.revision,current,version);
      if (input.value === original) {
        input.value = '';
        input.dispatchEvent(new Event('input',{bubbles:true}));
      }
    } catch (error) {
      if (generation !== version || submission !== request) return;
      showNotice(error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError
        ? 'The request could not be verified. Check Admin Revisions before trying again.' : error.message);
    } finally {
      clearTimeout(deadline);
      if (submission === request) {
        unlock(request);
        if (scope()) input.focus();
        schedule();
      }
    }
  }

  document.addEventListener('submit',event => {
    if (event.target !== form) return;
    const match = /^\/develop(?:\s+([\s\S]*))?$/i.exec(input.value.trim());
    if (!match) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void submit((match[1] || '').trim());
  },true);

  window.addEventListener('stonefellow:chat-context-changing',reset);
  window.addEventListener('vp3:participant-task-context',() => { reset(); schedule(); });
  window.addEventListener('vp3:chat-context-ready',() => schedule());
  window.addEventListener('vp3:chat-message-persisted',() => queueMicrotask(syncDraftActions));
  window.addEventListener('vp3:chat-canvas-view-changed',() => { if (thread.dataset.view !== 'chat') reset(); else schedule(); });
  window.addEventListener('focus',() => schedule());
  window.addEventListener('pagehide',reset);
  window.addEventListener('pageshow',() => schedule());
  document.addEventListener('visibilitychange',() => { if (document.hidden) reset(); else schedule(); });
  input.addEventListener('input',() => { composerEdits++; showNotice(); });
  reset();
  schedule();
})();
