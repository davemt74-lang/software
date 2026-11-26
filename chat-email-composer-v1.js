(() => {
  'use strict';
  const cfg = window.VP3_CHAT_EMAIL_V1;
  const thread = document.getElementById('chatThread');
  if (!cfg?.endpoint || !cfg.csrf || !thread || window.VP3EmailComposerV1) return;
  const runtime = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const owner = String(cfg.ownerKey);
  const agent = Number(cfg.agentId);
  const binding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '');
  const draftId = /^[a-f0-9]{32}$/;
  const revisionId = /^[a-f0-9]{64}$/;
  const positive = value => Number.isSafeInteger(value) && value > 0;
  const statusLabels = {prepared:'Ready for review',write_queued:'Waiting for Agent',writing:'Agent writing',write_failed:'Writing needs attention',
    stale:'New message to review',source_check:'Thread needs checking',answered:'Already answered',sending:'Checking send outcome',
    sent:'Sent',failed:'Send needs attention',discarded:'Discarded',expired:'Review expired',drafting:'Preparing reply',
    unavailable:'Unavailable',automatic:'Automatic reply'};
  const blockedLabels = {connection_changed:'Gmail access changed. Check Connections before continuing.',expired:'This proposal expired. Its original email can still be reviewed when available.',
    answered:'The original email was already answered.',stale:'A newer message needs review.',source_check:'Check the current Gmail thread before continuing.',
    failed:'Gmail did not accept this send. Review its status.',writing:'The Agent is preparing this draft.',sending:'Check this send’s outcome before starting another.',
    automatic:'Automatic replies are managed in their original review surface.',unavailable:'This draft’s saved content or original request is unavailable.'};
  const imageTypes = ['image/jpeg','image/png','image/webp'];
  const attachmentLimit = 10 * 1024 * 1024;
  const retained = new Map();
  let active = null, opening = false;
  let summaryCache = null, summaryRequest = null, summaryEpoch = 0;
  let participantActor = 0, participantEpoch = 0;
  const node = (tag, text = '', className = '') => {
    const value = document.createElement(tag);
    value.textContent = text;
    if (className) value.className = className;
    return value;
  };
  const button = (label, handler, className = '') => {
    const value = node('button',label,className);
    value.type = 'button';
    value.addEventListener('click',handler);
    return value;
  };
  const field = (label, input) => {
    const value = node('label','','vp3-email-compose-field');
    value.append(node('span',label),input);
    return value;
  };
  const nonce = () => Array.from(crypto.getRandomValues(new Uint8Array(16)),byte => byte.toString(16).padStart(2,'0')).join('');
  function localUrl(value, file = '') {
    try {
      const url = new URL(value,location.href);
      return typeof value === 'string' && value && url.origin === location.origin && !url.username && !url.password
        && (!file || url.pathname.endsWith('/'+file)) ? url.href : '';
    } catch { return ''; }
  }
  function privateAccessChanged(response) {
    if (cfg.fixedDraftId && [401,403,419].includes(response.status)) window.dispatchEvent(new Event('vp3:phone-agent-access-changed'));
  }
  function scope(allowEmpty = false) {
    const live = window.STONEFELLOW_CHAT;
    const chat = runtime();
    const cid = Number(chat?.conversationId?.() || 0);
    if (String(live?.userId) !== owner || Number(live?.agentId || 0) !== agent
      || Number(chat?.agentId?.() ?? agent) !== agent || String(live?.workingAttentionBinding || '') !== binding
      || participantActor > 0 || window.VP3ParticipantContinuity?.selectedContext?.() || chat?.isRestoring?.()
      || chat?.isReady?.() === false || !Number.isSafeInteger(cid) || cid < (allowEmpty ? 0 : 1)) return null;
    return {agent_id:agent,conversation_id:cid};
  }
  const scopeKey = value => value ? owner+':'+value.agent_id+':'+value.conversation_id : '';
  const current = state => active === state && scopeKey(state.scope) === scopeKey(scope()) && state.shell.isConnected;
  const followthrough = () => window.VP3EmailFollowthroughV1;
  const followPolicy = value => followthrough()?.policy ? followthrough().policy(value) : null;
  const valuesKey = values => JSON.stringify([values.to,values.subject,values.body,values.attachments.map(asset => asset.asset_id),values.followthrough || null]);
  const dirty = state => state.draft && valuesKey(state.values) !== state.saved;
  function invalidateVoiceReview(state) {
    if (!cfg.fixedDraftId || cfg.callReviewEnabled !== true || !current(state)) return;
    state.voiceEditSeq = (state.voiceEditSeq || 0)+1;
    window.dispatchEvent(new Event('vp3:email-call-review-invalidated'));
  }
  const memoryKey = state => scopeKey(state.scope)+':'+state.draft?.id;
  const shortText = (value,limit = 500) => typeof value === 'string' ? value.trim().slice(0,limit) : '';
  function sameScope(value,currentScope = scope()) {
    return currentScope && value && String(value.owner_key) === owner && value.agent_id === currentScope.agent_id
      && value.conversation_id === currentScope.conversation_id;
  }
  function selectedDraft() {
    const state = active;
    if (!state || !current(state) || state.editor.hidden || !draftId.test(state.draft?.id || '')
      || !revisionId.test(state.draft?.edit_revision || '') || state.draft.connection_changed) return null;
    return Object.freeze({draft_id:state.draft.id,edit_revision:state.draft.edit_revision,owner_key:owner,...state.scope});
  }
  function sameSelection(value) {
    const selected = selectedDraft();
    return selected && sameScope(value) && value.draft_id === selected.draft_id && value.edit_revision === selected.edit_revision;
  }
  function displayTime(value) {
    const dateOnly = typeof value === 'string' && /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (dateOnly) {
      const [,year,month,day] = dateOnly.map(Number);
      const date = new Date(year,month-1,day);
      return date.getFullYear() === year && date.getMonth() === month-1 && date.getDate() === day
        ? date.toLocaleDateString(undefined,{dateStyle:'medium'}) : '';
    }
    const time = typeof value === 'string' ? Date.parse(value) : NaN;
    return Number.isFinite(time) ? new Date(time).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}) : '';
  }
  function displayAge(value) {
    if (!Number.isSafeInteger(value) || value < 0) return '';
    if (value < 60) return 'Created just now';
    if (value < 3600) { const minutes = Math.floor(value/60);return `Created ${minutes} minute${minutes === 1 ? '' : 's'} ago`; }
    if (value < 86400) { const hours = Math.floor(value/3600);return `Created ${hours} hour${hours === 1 ? '' : 's'} ago`; }
    const days = Math.floor(value/86400);return `Created ${days} day${days === 1 ? '' : 's'} ago`;
  }
  function checkedCounts(value) {
    if (!value || !['pending','total','sent','discarded','answered'].every(key => Number.isSafeInteger(value[key]) && value[key] >= 0))
      throw new Error('The Gmail review counts could not be verified.');
    return Object.fromEntries(['pending','total','sent','discarded','answered'].map(key => [key,value[key]]));
  }
  function publishCounts(value) {
    const counts = checkedCounts(value);
    summaryCache = {counts,at:Date.now()};
    window.dispatchEvent(new CustomEvent('vp3:chat-email-review-count',{detail:{owner_key:owner,agent_id:agent,counts}}));
    return counts;
  }
  function asset(value) {
    const id = Number(value?.asset_id ?? value?.id);
    const mime = value?.mime ?? value?.mime_type;
    const size = Number(value?.size ?? value?.file_size);
    const url = localUrl(value?.url,'media-library-file-v86.php');
    if (value?.available === false || !positive(id) || !imageTypes.includes(mime) || !Number.isSafeInteger(size) || size < 1 || size > attachmentLimit || !url) return null;
    return {asset_id:id,name:String(value.name || value.title || 'Image').slice(0,190),mime,size,url};
  }
  function checkedDraft(value, expected = '') {
    if (!draftId.test(value?.id || '') || expected && value.id !== expected
      || !['from','to','subject','body','status'].every(key => typeof value[key] === 'string')
      || value.to.length > 320 || value.subject.length > 1000 || value.body.length > 30000) throw new Error('The saved email could not be verified.');
    const attachments = (value.attachments || []).map(asset);
    if (attachments.some(value => !value) || attachments.length > 5) throw new Error('The email attachments could not be verified.');
    if (value.followthrough !== undefined) {
      if (!followthrough()?.policy) throw new Error('Response deadline controls are unavailable. Reload Chat before editing this email.');
      followPolicy(value.followthrough);
    }
    return {...value,attachments};
  }
  function checkedGeneration(value) {
    if (!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value?.id || '')
      || !['queued','generating','paused','completed','cancelled','failed','file_unavailable'].includes(value.status))
      throw new Error('The image-generation status could not be verified.');
    return value;
  }
  const canvas = node('section','','chat-canvas-view vp3-email-canvas');
  canvas.id = 'chatEmailCanvasV1';
  canvas.dataset.chatView = 'email';
  canvas.hidden = true;
  thread.append(canvas);
  const popup = node('dialog','','vp3-email-compose-dialog');
  popup.id = 'vp3EmailComposerPopupV1';
  popup.setAttribute('aria-label','Gmail composer');
  document.body.append(popup);

  function setNotice(state,text) {
    if (current(state)) state.notice.textContent = text;
  }
  function resizeBody(input) {
    input.style.height = 'auto';
    input.style.height = Math.max(180,input.scrollHeight)+'px';
  }
  function controls(state) {
    if (!current(state)) return;
    const draft = state.draft;
    const locked = !draft || state.conflicted || draft.connection_changed || !draft.review_token
      || draft.can_edit === false || !['prepared','write_failed'].includes(draft.status);
    const busy = state.busy || state.uploading;
    for (const input of state.editor.querySelectorAll('input,textarea,select')) input.disabled = Boolean(busy || locked);
    state.to.readOnly = Boolean(draft?.is_reply);
    state.subject.readOnly = Boolean(draft?.is_reply);
    for (const control of state.editor.querySelectorAll('[data-email-edit-action]')) control.disabled = Boolean(busy || locked);
    state.save.disabled = Boolean(busy || locked || state.imageWorking);
    const complete = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.values?.to.trim() || '')
      && Boolean(state.values?.subject.trim() || draft?.is_reply) && Boolean(state.values?.body.trim());
    const verifyEditedSnapshot = draft?.status === 'prepared' && draft?.is_followup !== true && dirty(state);
    state.send.disabled = Boolean(busy || locked || !complete || draft?.can_send === false && !verifyEditedSnapshot || draft?.followthrough_unavailable
      || state.sendUncertain || state.imageWorking || state.imageReview);
    state.generate.disabled = Boolean(busy || locked || state.imageWorking || !state.instruction.value.trim());
    state.imageCreate.disabled = Boolean(busy || locked || state.imageWorking || state.imagePrompt.value.trim().length < 3);
    state.imagePrompt.disabled = Boolean(busy || locked || state.imageWorking || state.imageReview);
    state.imageSize.disabled = Boolean(busy || locked || state.imageWorking || state.imageReview);
    state.imageCreate.textContent = state.imageReview ? 'Retry image request' : 'Generate image';
    state.imageCancel.hidden = !state.imageJob || !state.imageWorking;
    state.imageCancel.disabled = Boolean(state.busy || state.cancellingImage);
    state.imageRetry.hidden = !state.imageJob || !state.imageWorking;
    state.imageRetry.disabled = Boolean(state.busy || state.checkingImage);
    state.generate.textContent = state.values?.body.trim() ? 'Revise with Agent' : 'Draft with Agent';
    state.changed.textContent = dirty(state) ? 'Unsaved changes' : draft ? 'Saved in this Chat' : '';
    const following = state.values?.followthrough?.enabled === true;
    state.watch.disabled = Boolean(busy || locked || !draft?.watch_review_token || following);
    state.watchLabel.hidden = !draft?.watch_review_token || following;
    if (state.followSettings) {
      state.followSettings.root.hidden = draft?.status === 'sent' || draft?.is_followup === true || !draft?.watch_review_token && !following;
      state.followSettings.setDisabled(busy || locked || draft?.is_followup === true || !draft?.watch_review_token);
    }
    state.refresh.hidden = !draft;
    state.refresh.disabled = Boolean(busy);
    state.discard.disabled = Boolean(busy || !draft || !['prepared','write_failed','write_queued','stale','source_check','answered','failed','expired'].includes(draft.status));
    state.retry.hidden = draft?.status !== 'write_failed';
    state.latest.hidden = draft?.can_review_latest !== true;
    state.checkSend.hidden = !state.sendUncertain && draft?.status !== 'sending';
    state.retry.disabled = state.latest.disabled = state.checkSend.disabled = Boolean(state.busy);
    state.newButton.disabled = state.listButton.disabled = state.closeButton.disabled = Boolean(busy);
    state.imageToggle.disabled = state.upload.disabled = state.libraryToggle.disabled = Boolean(busy || locked || state.values?.attachments.length >= 5);
    state.sourceRead.disabled = Boolean(busy || state.sourceLoading || state.sourceDenied || !draft?.can_open_source || !revisionId.test(draft?.edit_revision || ''));
    if (state.queueFilter) {
      state.queueFilter.disabled = state.queueReload.disabled = Boolean(busy);
      state.queuePrevious.disabled = Boolean(busy || state.queuePage < 1);
      state.queueNext.disabled = Boolean(busy || !state.queueNextCursor);
      for (const control of state.queueRows.querySelectorAll('button')) control.disabled = Boolean(busy || control.dataset.emailUnavailable === 'true');
    }
    state.shell.setAttribute('aria-busy',String(Boolean(state.busy)));
  }
  function valuesFromDraft(draft) {
    return {to:draft.to,subject:draft.subject,body:draft.body,attachments:[...draft.attachments],followthrough:followPolicy(draft.followthrough)};
  }
  function sameSavedVersion(state,draft) {
    if (!state.draft || draft.id !== state.draft.id || draft.from !== state.draft.from || draft.is_reply !== state.draft.is_reply
      || draft.connection_changed || draft.can_edit === false || !['prepared','write_failed'].includes(draft.status)) return false;
    if (typeof draft.edit_revision === 'string' && typeof state.draft.edit_revision === 'string') return draft.edit_revision === state.draft.edit_revision;
    return valuesKey(valuesFromDraft(draft)) === state.saved;
  }
  function showConflict(state,latest) {
    if (latest.connection_changed) {
      if (state.draft) retained.delete(memoryKey(state));
      adopt(state,latest);return;
    }
    state.draft = latest;
    renderSource(state,latest);
    state.conflicted = true;state.conflict.hidden = false;
    state.conflict.replaceChildren(node('strong','This draft changed elsewhere. Your edits are kept.'));
    const saved = node('details');saved.append(node('summary','Compare the saved version'),node('p',latest.to),
      node('strong',latest.subject || '(No subject)'),node('p',latest.body));state.conflict.append(saved);
    if (latest.can_edit !== false && latest.review_token && ['prepared','write_failed'].includes(latest.status)) {
      state.conflict.append(button('Use my edits',() => {
        if (!current(state)) return;
        state.draft = latest;state.saved = valuesKey(valuesFromDraft(latest));
        if (latest.is_reply) { state.values.to = latest.to;state.values.subject = latest.subject;state.to.value = latest.to;state.subject.value = latest.subject; }
        state.conflicted = false;state.conflict.hidden = true;
        setNotice(state,'Your edits are ready. Save draft to replace the saved version.');controls(state);
      }));
    }
    state.conflict.append(button('Reload saved version',() => { if (current(state)) adopt(state,latest); }));
    controls(state);
  }
  function renderAttachments(state) {
    state.attachments.replaceChildren();
    for (const item of state.values.attachments) {
      const card = node('figure','','vp3-email-compose-attachment');
      const image = node('img');image.src = item.url;image.alt = item.name;image.loading = 'lazy';
      const caption = node('figcaption');
      caption.append(node('span',item.name),node('small',Math.ceil(item.size/1024)+' KB'));
      const remove = button('Remove',() => {
        if (!current(state) || state.busy || state.uploading) return;
        invalidateVoiceReview(state);
        state.values.attachments = state.values.attachments.filter(value => value.asset_id !== item.asset_id);
        renderAttachments(state);controls(state);
      });
      remove.setAttribute('aria-label','Remove '+item.name);
      remove.dataset.emailEditAction = '';
      card.append(image,caption,remove);state.attachments.append(card);
    }
    state.attachmentCount.textContent = state.values.attachments.length
      ? state.values.attachments.length+' of 5 images · '+(state.values.attachments.reduce((sum,item) => sum+item.size,0)/1024/1024).toFixed(1)+' MB of 10 MB'
      : 'JPG, PNG or WebP · up to 5 images, 10 MB total';
  }
  function renderSource(state,draft) {
    state.originalSource.hidden = !draft.is_reply && !draft.can_open_source;
    const key = [draft.id,draft.edit_revision,draft.can_open_source === true,Boolean(draft.connection_changed)].join(':');
    if (state.sourceKey === key) return;
    state.sourceKey = key;state.sourceLoading = false;state.sourceDenied = !draft.can_open_source || Boolean(draft.connection_changed);
    state.sourceContent.replaceChildren();
    state.sourceRead.textContent = 'Read original email';
    const reason = draft.connection_changed ? '' : shortText(draft.source?.reason);
    state.sourceReason.textContent = reason || 'Review the original message before sending this reply.';
    state.sourceStatus.textContent = draft.can_open_source ? '' : 'The original email is not available with the current Gmail access.';
  }
  function clearSource(state,message) {
    if (!current(state)) return;
    state.sourceKey = null;state.sourceDenied = true;state.sourceLoading = false;
    state.sourceContent.replaceChildren();state.sourceStatus.textContent = message;
    controls(state);
  }
  async function readSource(state) {
    if (!current(state) || state.busy || state.sourceLoading || state.sourceDenied || !state.draft?.can_open_source) return false;
    const selection = selectedDraft();
    if (!selection) return false;
    const sourceKey = state.sourceKey;
    state.sourceLoading = true;state.sourceStatus.textContent = 'Reading the original email…';controls(state);
    try {
      const result = await api(state,'compose_source',{draft_id:selection.draft_id,edit_revision:selection.edit_revision});
      if (!sameSelection(selection) || state.sourceKey !== sourceKey) return false;
      const packet = result.source || result;
      const message = packet.message;
      if (packet.draft_id !== selection.draft_id || packet.edit_revision !== selection.edit_revision
        || packet.conversation_id !== selection.conversation_id || !message
        || !['subject','from','body'].every(key => typeof message[key] === 'string')
        || message.subject.length > 2000 || message.from.length > 2000 || message.body.length > 100000)
        throw new Error('The original email no longer matches this draft. Reload the saved draft to review it.');
      state.sourceContent.replaceChildren();
      const heading = node('header','','vp3-email-compose-source-heading');
      heading.append(node('strong',message.subject || '(No subject)'),node('small','From '+message.from));
      state.sourceContent.append(heading);
      const plain = parent => parent.append(node('p',message.body || 'No readable text in this email.'));
      if (!window.VP3GmailEmailDisplayV1?.mount(state.sourceContent,message,{renderPlain:plain})) plain(state.sourceContent);
      state.sourceStatus.textContent = 'Original message for this draft.';state.sourceRead.textContent = 'Refresh original email';
      return true;
    } catch (error) {
      if (current(state) && state.sourceKey === sourceKey && error.name !== 'AbortError') state.sourceStatus.textContent = error.message;
      return false;
    } finally {
      if (current(state) && state.sourceKey === sourceKey) { state.sourceLoading = false;controls(state); }
    }
  }
  function adopt(state,value,{message = ''} = {}) {
    const draft = checkedDraft(value,state.draft?.id || '');
    state.snapshotSeq = (state.snapshotSeq || 0)+1;
    state.conflicted = false;state.conflict.hidden = true;
    state.draft = draft;
    state.values = valuesFromDraft(draft);
    state.saved = valuesKey(state.values);
    state.to.value = draft.to;state.subject.value = draft.subject;state.body.value = draft.body;
    state.from.textContent = draft.from || 'Connected Gmail account';
    state.title.textContent = draft.is_followup ? 'Follow-up email' : draft.is_reply ? 'Reply email' : 'Compose email';
    state.editor.hidden = false;state.list.hidden = true;
    state.watch.checked = Boolean(draft.watch_requested);
    state.followSettings?.update(state.values.followthrough);
    const messages = {
      prepared:'',write_queued:'The Agent is preparing your draft…',writing:'The Agent is writing your email…',
      write_failed:'The Agent could not finish writing. Your saved text is kept; edit it or retry.',
      stale:'A newer message arrived. Review the latest message before sending this reply.',
      source_check:'The current Gmail thread needs to be checked before this reply can be sent.',
      answered:'You already replied to this message in Gmail.',
      sending:'Gmail has not confirmed the outcome yet. Check send status before trying anything else.',
      sent:'Gmail confirmed your email was sent.',failed:'Gmail rejected this email. Your saved draft remains available.',
      discarded:'Draft discarded.',expired:'This proposal expired. You can review its original email or discard it.'
    };
    state.notice.textContent = draft.connection_changed ? 'Gmail access changed. Check Connections before editing or sending.'
      : draft.followthrough_unavailable ? 'This follow-up changed or paused. Check its response deadline and Gmail thread before sending.'
      : message || messages[draft.status] || '';
    state.latestMessage.replaceChildren();state.latestMessage.hidden = !draft.latest_reply?.excerpt;
    if (draft.latest_reply?.excerpt) state.latestMessage.append(node('summary','Latest Gmail message'),
      node('strong',draft.latest_reply.subject || ''),node('p',draft.latest_reply.excerpt));
    state.followRecord?.dispose();state.followRecord = null;state.followRecordHost.hidden = true;
    if (draft.followthrough_record && followthrough()?.mount && !draft.connection_changed) {
      state.followRecordHost.hidden = false;
      state.followRecord = followthrough().mount(state.followRecordHost,draft.followthrough_record,{
        context:{owner_key:owner,...state.scope},isCurrent:() => current(state) && state.draft === draft,
        onChange:item => { if (current(state)) { invalidateVoiceReview(state);state.draft.followthrough_record = item; } },
        openDraft:cfg.fixedDraftId ? undefined : target => navigate(state,() => void openDraft(target,{surface:state.surface,replace:true})),
        openReply:cfg.fixedDraftId ? undefined : (target,trigger) => navigate(state,() => {
          if (!current(state) || typeof window.VP3EmailFollowup?.open !== 'function') return;
          const context = {owner_key:owner,...state.scope,...target};release(state,{rememberEdits:false});window.VP3EmailFollowup.open(context,trigger);
        })});
    }
    renderSource(state,draft);renderAttachments(state);resizeBody(state.body);controls(state);
  }
  async function api(state,action,input = {},{media = false,signal,voiceReview} = {}) {
    if (!current(state)) throw new DOMException('The Chat context changed.','AbortError');
    let endpoint = localUrl(media ? cfg.imageEndpoint : cfg.endpoint);
    if (!endpoint) throw new Error(media ? 'Image generation is unavailable.' : 'The Gmail composer is unavailable.');
    if (voiceReview !== undefined) {
      if (!cfg.fixedDraftId || cfg.callReviewEnabled !== true || media || action !== 'confirm' || !draftId.test(voiceReview))
        throw new Error('This voice review is unavailable.');
      const bound = new URL(endpoint);bound.searchParams.set('voice_review',voiceReview);endpoint = bound.href;
    }
    const parent = signal || state.controller.signal;
    const controller = new AbortController();
    const abort = () => controller.abort();
    let timedOut = false;
    if (parent.aborted) controller.abort();else parent.addEventListener('abort',abort,{once:true});
    const timer = setTimeout(() => { timedOut = true;controller.abort(); },['advance_write','tick'].includes(action) ? 125000 : 25000);
    try {
      const response = await fetch(endpoint,{
        method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'Content-Type':'application/json'},body:JSON.stringify({action,owner_key:owner,csrf_token:cfg.csrf,
          ...(!media ? state.scope : {}),...input})
      });
      const data = await response.json().catch(() => null);
      if (!current(state)) throw new DOMException('The Chat context changed.','AbortError');
      if (!response.ok || data?.ok !== true) {
        const error = new Error(data?.error || 'The request could not be confirmed. Your text is kept.');
        if (!media && (response.status === 403 || response.status === 409 && ['review','review_latest','compose_source'].includes(action)))
          clearSource(state,'Source access or the saved draft changed. Reload the saved draft before reading its original email.');
        privateAccessChanged(response);
        error.status = response.status;throw error;
      }
      return data;
    } catch (error) {
      if (timedOut && !parent.aborted) throw new Error('This request took too long. Your text is kept; check its status before trying again.');
      throw error;
    } finally { clearTimeout(timer);parent.removeEventListener('abort',abort); }
  }
  function failure(state,error,fallback = '') {
    if (current(state) && error.name !== 'AbortError') setNotice(state,error.message || fallback || 'The request failed. Your text is kept.');
  }
  async function saveSnapshot(state) {
    if (state.followSettings) state.values.followthrough = state.followSettings.validate();
    const fields = {to:state.values.to,subject:state.values.subject,body:state.values.body,attachment_ids:state.values.attachments.map(item => item.asset_id)};
    if (state.values.followthrough) fields.followthrough = state.values.followthrough;
    const save = () => api(state,'compose_save',{draft_id:state.draft.id,review_token:state.draft.review_token,...fields});
    let result;
    try { result = await save(); }
    catch (error) {
      if (error.status !== 409) throw error;
      const latest = checkedDraft((await api(state,'review',{draft_id:state.draft.id})).draft,state.draft.id);
      if (!sameSavedVersion(state,latest)) {
        showConflict(state,latest);throw new Error('The saved draft changed. Compare versions before replacing it.');
      }
      state.draft = latest;
      renderSource(state,latest);
      result = await save();
    }
    adopt(state,result.draft,{message:'Draft saved in this Chat.'});
    retained.delete(memoryKey(state));
    window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
    return result.draft;
  }
  async function save(state,after) {
    if (!current(state) || state.busy || state.imageWorking || !state.draft?.review_token) return false;
    state.busy = true;controls(state);setNotice(state,'Saving draft…');
    try { await saveSnapshot(state);if (current(state) && after) after();return current(state) || Boolean(after); }
    catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  async function send(state) {
    if (!current(state) || state.send.disabled || state.busy) return;
    state.busy = true;const watch = state.watch.checked || state.values.followthrough?.enabled === true;controls(state);
    let dispatched = false;
    try {
      setNotice(state,'Saving the exact email you reviewed…');
      await saveSnapshot(state);
      const token = watch ? state.draft.watch_review_token : state.draft.review_token;
      if (state.draft.can_send === false || state.draft.followthrough_unavailable) throw new Error('This saved email cannot be sent yet. Review its current status before sending.');
      if (!token) throw new Error('Complete the recipient, subject and message before sending.');
      setNotice(state,'Sending your reviewed email…');dispatched = true;
      const result = await api(state,'confirm',{draft_id:state.draft.id,review_token:token,confirmed:true,watch_replies:watch});
      state.sendUncertain = false;adopt(state,result.draft);
      window.dispatchEvent(new Event('vp3:chat-email-watch-changed'));
      window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
    } catch (error) {
      if (current(state) && dispatched) state.sendUncertain = true;
      failure(state,error);
      if (current(state) && dispatched) state.notice.textContent += ' Check send status to verify the outcome.';
    } finally { state.busy = false;controls(state); }
  }
  // The trusted Phone host keeps this exact review only in memory. Editing,
  // refreshing, attachment work or a watch-choice change invalidates it.
  function voiceReviewSnapshot() {
    const state = active;
    if (!cfg.fixedDraftId || cfg.callReviewEnabled !== true || !state || !current(state) || dirty(state)
      || state.busy || state.uploading || state.imageWorking || state.imageReview || state.conflicted || state.sendUncertain
      || state.draft?.id !== cfg.fixedDraftId || state.draft.status !== 'prepared' || state.draft.can_send === false
      || state.draft.connection_changed || state.draft.followthrough_unavailable || state.send.disabled) return null;
    const watch = state.watch.checked || state.values.followthrough?.enabled === true;
    const token = watch ? state.draft.watch_review_token : state.draft.review_token;
    if (!token) return null;
    return Object.freeze({draft_id:state.draft.id,review_token:token,watch_replies:watch,
      edit_revision:state.draft.edit_revision,sequence:state.snapshotSeq,edit_sequence:state.voiceEditSeq || 0});
  }
  function voiceReviewMatches(snapshot) {
    const value = voiceReviewSnapshot();
    return Boolean(value && snapshot && Object.keys(value).every(key => value[key] === snapshot[key]));
  }
  async function voiceReviewSend(snapshot,reviewId) {
    if (!draftId.test(reviewId || '') || !voiceReviewMatches(snapshot)) return false;
    const state = active;state.busy = true;controls(state);let dispatched = false;
    try {
      setNotice(state,'Sending the exact email confirmed during your call…');dispatched = true;
      const result = await api(state,'confirm',{draft_id:snapshot.draft_id,review_token:snapshot.review_token,
        confirmed:true,watch_replies:snapshot.watch_replies},{voiceReview:reviewId});
      state.sendUncertain = false;adopt(state,result.draft);
      window.dispatchEvent(new Event('vp3:chat-email-watch-changed'));
      window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
      return state.draft.status === 'sent';
    } catch (error) {
      if (current(state) && dispatched) state.sendUncertain = true;
      failure(state,error);
      if (current(state) && dispatched) state.notice.textContent += ' Check send status before trying another action.';
      return false;
    } finally {state.busy = false;controls(state);}
  }
  async function refreshDraft(state,action = 'review') {
    if (!current(state) || state.busy || !state.draft) return;
    state.busy = true;controls(state);setNotice(state,'Checking the saved email…');
    try {
      const result = await api(state,action,{draft_id:state.draft.id});
      state.sendUncertain = false;adopt(state,result.draft);watchWrite(state);
    } catch (error) { failure(state,error); }
    finally { state.busy = false;controls(state); }
  }
  async function checkSend(state) {
    if (!current(state) || state.busy || !state.draft) return;
    state.busy = true;controls(state);setNotice(state,'Verifying this email’s send outcome…');
    try {
      let result = await api(state,'review',{draft_id:state.draft.id});
      adopt(state,result.draft);
      if (state.draft.status === 'sending' && !state.draft.connection_changed) {
        const watch = Boolean(state.draft.watch_requested || state.draft.followthrough?.enabled);
        const token = watch ? state.draft.watch_review_token : state.draft.review_token;
        if (token) {
          result = await api(state,'confirm',{draft_id:state.draft.id,confirmed:true,review_token:token,watch_replies:watch});
          adopt(state,result.draft);
        }
      }
      state.sendUncertain = state.draft.status === 'sending';
    } catch (error) { failure(state,error); }
    finally { state.busy = false;controls(state); }
  }
  async function write(state,retry = false) {
    if (!current(state) || state.busy || state.imageWorking || !state.draft?.review_token || !retry && !state.instruction.value.trim()) return false;
    invalidateVoiceReview(state);state.busy = true;controls(state);
    try {
      if (!retry) await saveSnapshot(state);
      const result = await api(state,retry ? 'retry_write' : 'compose_generate',{draft_id:state.draft.id,
        review_token:state.draft.review_token,...(!retry ? {instruction:state.instruction.value.trim()} : {})});
      adopt(state,result.draft);watchWrite(state);return true;
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  async function discardDraft(state,selection) {
    if (!current(state) || state.busy || state.uploading || !sameSelection(selection)) return false;
    state.busy = true;controls(state);
    try {
      const result = await api(state,'discard',{draft_id:selection.draft_id,edit_revision:selection.edit_revision});
      if (result.draft?.status !== 'discarded') throw new Error('This draft can no longer be discarded. Reload its current status.');
      retained.delete(memoryKey(state));
      window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
      if (cfg.fixedDraftId) adopt(state,result.draft);
      else await openDrafts({surface:state.surface,replace:true});
      return true;
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  function requestDiscard(state,selection) {
    if (!current(state) || !sameSelection(selection)) return false;
    if (!dirty(state)) { void discardDraft(state,selection);return true; }
    state.leave.hidden = false;state.leave.replaceChildren(node('p','Discard this draft and its unsaved changes?'));
    state.leave.append(button('Keep editing',() => { state.leave.hidden = true;state.body.focus(); }),
      button('Discard draft',() => {
        if (!current(state) || !sameSelection(selection)) {
          setNotice(state,'The selected draft changed. Review its current version before discarding it.');return;
        }
        state.leave.hidden = true;void discardDraft(state,selection);
      }));
    state.leave.querySelector('button')?.focus();return false;
  }
  function watchWrite(state) {
    clearTimeout(state.writeTimer);
    if (!current(state) || !['write_queued','writing'].includes(state.draft?.status)) return;
    if (state.advancing) return;
    if (state.draft.status === 'write_queued' && !state.checkWritingFirst) {
      state.advancing = true;
      const sequence = state.snapshotSeq;
      setNotice(state,'The Agent is writing your email…');
      void api(state,'advance_write',{draft_id:state.draft.id}).then(result => {
        if (current(state) && state.snapshotSeq === sequence && !dirty(state) && !state.busy) adopt(state,result.draft);
      }).catch(error => failure(state,error)).finally(() => {
        state.advancing = false;state.checkWritingFirst = true;watchWrite(state);
      });
      return;
    }
    state.writeTimer = setTimeout(async () => {
      if (!current(state) || state.busy) { watchWrite(state);return; }
      const sequence = state.snapshotSeq;
      try {
        const result = await api(state,'review',{draft_id:state.draft.id});
        if (current(state) && state.snapshotSeq === sequence && !dirty(state) && !state.busy) adopt(state,result.draft);
        state.checkWritingFirst = false;watchWrite(state);
      }
      catch (error) { failure(state,error); }
    },1800);
  }
  function remember(state,{savedOnly = false} = {}) {
    if (state.draft && (dirty(state) || state.imageJob || state.imageReview)) {
      const values = savedOnly ? valuesFromDraft(state.draft) : state.values;
      retained.set(memoryKey(state),{draft:state.draft,values:structuredClone(values),saved:state.saved,
        instruction:state.instruction.value,imagePrompt:state.imagePrompt.value,imageSize:state.imageSize.value,
        imageJob:state.imageJob,imageReview:state.imageReview});
    }
  }
  function release(state,{rememberEdits = true,returnToChat = true} = {}) {
    if (active !== state) return;
    if (state.closeResolve) {state.closeResolve(false);state.closeResolve = null;}
    rememberEdits ||= state.keepEditsOnRelease === true;
    if (rememberEdits || state.imageJob || state.imageReview) remember(state,{savedOnly:!rememberEdits});
    else if (state.draft) retained.delete(memoryKey(state));
    active = null;
    state.followRecord?.dispose();state.followRecord = null;
    state.controller.abort();clearTimeout(state.writeTimer);clearTimeout(state.imageTimer);
    state.shell.remove();canvas.hidden = true;
    if (popup.open) popup.close();
    if (returnToChat && state.surface === 'canvas' && thread.dataset.view === 'email') runtime()?.showChatView?.();
    if (returnToChat && scopeKey(state.scope) === scopeKey(scope())) {
      if (state.surface === 'canvas') thread.scrollTo?.({top:state.scrollTop,behavior:'instant'});
      (state.returnFocus?.isConnected ? state.returnFocus : document.getElementById('chatInput'))?.focus({preventScroll:true});
    }
  }
  function navigate(state,action) {
    if (!current(state) || state.busy || state.uploading) return false;
    if (!dirty(state)) { action();return true; }
    state.leave.hidden = false;state.leave.replaceChildren(node('p','Save your changes before leaving this email?'));
    const preserve = state.imageWorking
      ? button('Keep edits in this tab',() => { state.keepEditsOnRelease = true;state.leave.hidden = true;action(); })
      : button('Save draft and continue',() => void save(state,() => { state.leave.hidden = true;action(); }));
    if (state.imageWorking) state.leave.firstElementChild.textContent = 'Your image is still being generated. Keep your unsaved edits in this tab, or continue editing.';
    state.leave.append(preserve,
      button('Keep editing',() => { state.leave.hidden = true;state.body.focus(); }),
      button('Discard edits',() => { state.leave.hidden = true;retained.delete(memoryKey(state));action(); }));
    state.leave.querySelector('button')?.focus();return false;
  }
  function requestClose() {
    const state = active;
    if (!state || !current(state)) return Promise.resolve(true);
    if (state.closePromise) return state.closePromise;
    if (state.busy || state.uploading || state.imageWorking) {
      setNotice(state,state.imageWorking ? 'Wait for your image or cancel its request before leaving this email.' : 'Wait for the current email action to finish.');
      return Promise.resolve(false);
    }
    if (!dirty(state)) {release(state,{rememberEdits:false,returnToChat:false});return Promise.resolve(true);}
    state.closePromise = new Promise(resolve => {state.closeResolve = resolve;});
    const finish = allowed => {
      const resolve = state.closeResolve;if (!resolve) return;
      state.closeResolve = null;state.closePromise = null;
      state.leave.hidden = true;
      if (allowed) release(state,{rememberEdits:false,returnToChat:false});
      else state.body.focus();
      resolve?.(allowed);
    };
    state.leave.hidden = false;state.leave.replaceChildren(node('p','Save your changes before leaving this email?'));
    const leaveButtons = [button('Save draft and leave',async () => {
      if (!state.closeResolve || state.busy) return;
      leaveButtons.forEach(value => {value.disabled = true;});
      const saved = await save(state);
      if (saved && current(state)) finish(true);
      else leaveButtons.forEach(value => {value.disabled = false;});
    }),button('Keep editing',() => {if (!state.busy) finish(false);}),button('Discard edits',() => {if (!state.busy) finish(true);})];
    state.leave.append(...leaveButtons);
    state.leave.querySelector('button')?.focus();
    return state.closePromise;
  }
  function changeSurface(state,surface) {
    if (!current(state)) return false;
    const next = surface === 'canvas' ? 'canvas' : 'popup';
    state.surface = next;
    state.surfaceButton.textContent = next === 'canvas' ? 'Pop out' : 'Open in canvas';
    if (next === 'canvas') {
      if (popup.open) popup.close();
      canvas.append(state.shell);canvas.hidden = false;
      if (runtime()?.showEmailCanvasView) runtime().showEmailCanvasView();
      else { thread.dataset.view = 'email';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed')); }
      thread.scrollTo?.({top:0,behavior:'instant'});
    } else {
      popup.append(state.shell);canvas.hidden = true;
      if (thread.dataset.view === 'email') runtime()?.showChatView?.();
      if (!popup.open) popup.showModal();
    }
    resizeBody(state.body);state.title.focus({preventScroll:true});return true;
  }
  function attach(state,value) {
    const item = asset(value);
    if (!current(state) || !item) throw new Error('This image could not be attached. Choose a JPG, PNG or WebP image.');
    if (!state.draft?.review_token || state.draft.can_edit === false || !['prepared','write_failed'].includes(state.draft.status))
      throw new Error('The image is saved privately. Open an editable draft to attach it.');
    if (state.values.attachments.some(value => value.asset_id === item.asset_id)) return;
    if (state.values.attachments.length >= 5 || state.values.attachments.reduce((sum,value) => sum+value.size,0)+item.size > attachmentLimit)
      throw new Error('Use up to five images with a combined size of 10 MB.');
    invalidateVoiceReview(state);state.values.attachments.push(item);renderAttachments(state);controls(state);
  }
  async function upload(state,file) {
    if (!file || !current(state) || state.busy || state.uploading) return;
    if (!imageTypes.includes(file.type) || file.size < 1 || file.size+state.values.attachments.reduce((sum,value) => sum+value.size,0) > attachmentLimit) {
      setNotice(state,'Choose a JPG, PNG or WebP image. All attachments together must fit within 10 MB.');return;
    }
    const endpoint = localUrl(cfg.mediaLibraryEndpoint);
    if (!endpoint) { setNotice(state,'Image uploads are unavailable.');return; }
    invalidateVoiceReview(state);state.uploading = true;controls(state);
    const post = async (action,fields = {}) => {
      const form = new FormData();form.set('action',action);form.set('csrf_token',cfg.csrf);
      for (const [key,value] of Object.entries(fields)) form.set(key,value);
      const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:state.controller.signal,
        headers:{'X-VP3-Media-Owner':owner},body:form});
      const data = await response.json().catch(() => null);
      if (!current(state)) throw new DOMException('The Chat context changed.','AbortError');
      if (!response.ok || data?.ok !== true) {privateAccessChanged(response);throw new Error(data?.error || 'The image upload failed. Your email is kept.');}
      return data;
    };
    try {
      setNotice(state,'Uploading '+file.name+'…');
      const start = await post('begin',{media_type:'photo',mime_type:file.type,title:file.name.slice(0,190),original_name:file.name,source:'upload'});
      if (!draftId.test(start.token || '')) throw new Error('The image upload could not be started.');
      const size = Math.min(1024*1024,positive(start.chunk_size) ? start.chunk_size : 1024*1024);
      const chunks = Math.ceil(file.size/size);
      for (let index=0;index<chunks;index++) {
        await post('chunk',{token:start.token,index:String(index),total_chunks:String(chunks),chunk:file.slice(index*size,(index+1)*size,file.type)});
        setNotice(state,'Uploading '+file.name+' · '+Math.round((index+1)/chunks*100)+'%');
      }
      const result = await post('finish',{token:start.token,metadata_json:'{}'});
      attach(state,result.asset);setNotice(state,'Image attached. Save the draft to keep your changes.');
    } catch (error) { failure(state,error); }
    finally { state.uploading = false;state.file.value = '';controls(state); }
  }
  async function library(state,before = '') {
    if (!current(state) || state.busy || state.uploading) return;
    const endpoint = localUrl(cfg.mediaLibraryEndpoint);
    if (!endpoint) return;
    state.library.hidden = false;state.library.replaceChildren(node('p','Loading your saved images…'));
    try {
      const url = new URL(endpoint);url.searchParams.set('action','browse');url.searchParams.set('type','photo');
      if (before) url.searchParams.set('before',String(before));
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:state.controller.signal,headers:{'X-VP3-Media-Owner':owner}});
      const result = await response.json().catch(() => null);
      if (!current(state)) return;
      if (!response.ok || result?.ok !== true || !Array.isArray(result.assets)) {privateAccessChanged(response);throw new Error(result?.error || 'Saved images could not be loaded.');}
      state.library.replaceChildren();
      const grid = node('div','','vp3-email-compose-image-grid');
      for (const candidate of result.assets.slice(0,40)) {
        const picture = asset(candidate);if (!picture) continue;
        const choose = button('',() => {
          try { attach(state,picture);state.library.hidden = true;setNotice(state,'Saved image attached.'); }
          catch (error) { failure(state,error); }
        });
        choose.setAttribute('aria-label','Attach '+picture.name);
        const thumb = node('img');thumb.src = picture.url;thumb.alt = '';thumb.loading = 'lazy';
        choose.append(thumb,node('span',picture.name));grid.append(choose);
      }
      state.library.append(node('h3','Saved images'),grid);
      if (!grid.childElementCount) state.library.append(node('p','No saved JPG, PNG or WebP images were found.'));
      if (positive(result.next_before)) state.library.append(button('Earlier images',() => void library(state,result.next_before)));
      state.library.append(button('Close saved images',() => { state.library.hidden = true; }));
      for (const control of grid.querySelectorAll('button')) control.dataset.emailEditAction = '';
      controls(state);
    } catch (error) { if (current(state)) state.library.textContent = error.message || 'Saved images are unavailable.'; }
  }
  async function generateImage(state) {
    if (!current(state) || state.imageCreate.disabled) return;
    invalidateVoiceReview(state);state.imageWorking = true;controls(state);
    try {
      setNotice(state,'Preparing your image…');
      if (!state.imageReview) state.imageReview = await api(state,'preview',{prompt:state.imagePrompt.value.trim(),title:'Email image',
        size:state.imageSize.value,quality:'medium',background:'opaque'},{media:true});
      const preview = state.imageReview;
      if (!draftId.test(preview.request_key || '') || typeof preview.review_token !== 'string' || !preview.plan)
        throw new Error('The image request could not be reviewed.');
      const result = await api(state,'submit',{...preview.plan,request_key:preview.request_key,review_token:preview.review_token,confirmed:true},{media:true});
      checkedGeneration(result.generation);
      state.imageReview = null;state.imageJob = result.generation;
      processImage(state,result.generation);pollImage(state);
    } catch (error) {
      state.imageWorking = false;
      if (error.status === 409 && /review expired/i.test(error.message)) state.imageReview = null;
      failure(state,error);
    }
    finally { controls(state); }
  }
  function processImage(state,job) {
    if (!current(state)) return;
    checkedGeneration(job);
    if (state.attachedGeneration === job.id || state.imageTerminal === job.id) return;
    if (state.imageJob && state.imageJob.id !== job.id) return;
    state.imageJob = job;
    const working = ['queued','generating','paused'].includes(job.status);
    state.imageWorking = working;
    if (!working && job.status !== 'completed') state.imageTerminal = job.id;
    state.imageProgress.hidden = false;
    state.imageProgress.textContent = ({queued:'Your image is queued…',generating:'Generating your image…',paused:'Image generation is paused.',
      completed:'Image generated and attached.',failed:'Image generation failed. Your email is kept.',cancelled:'Image request cancelled.',
      file_unavailable:'The generated image file is unavailable.'})[job.status] || 'Checking image generation…';
    if (job.status === 'completed' && state.attachedGeneration !== job.id) {
      try {
        attach(state,job.asset || job.assets?.[0]);state.attachedGeneration = job.id;state.imageJob = null;
        setNotice(state,'Generated image attached. Save the draft to keep your changes.');
      } catch (error) { state.imageProgress.textContent = error.message; }
    }
    controls(state);
  }
  function pollImage(state) {
    clearTimeout(state.imageTimer);
    if (!current(state) || !state.imageJob || !['queued','generating','paused'].includes(state.imageJob.status)) return;
    const id = state.imageJob.id;
    if (!state.ticking && Date.now()-(state.lastTick || 0) >= 10000 && state.imageJob.status !== 'paused') {
      state.ticking = true;state.lastTick = Date.now();
      void api(state,'tick',{generation_id:id},{media:true}).then(result => processImage(state,result.generation))
        .catch(error => { if (current(state) && error.name !== 'AbortError') state.imageProgress.textContent = error.message; })
        .finally(() => { state.ticking = false; });
    }
    state.imageTimer = setTimeout(async () => {
      if (!current(state) || state.imageJob?.id !== id) return;
      try { const result = await api(state,'get',{generation_id:id},{media:true});processImage(state,result.generation);pollImage(state); }
      catch (error) {
        failure(state,error);
        if (current(state)) state.imageProgress.textContent = 'Image status is unavailable. Check image status or cancel this request before sending.';
        controls(state);
      }
    },2600);
  }
  function buildState(currentScope,surface) {
    const state = {scope:currentScope,surface,controller:new AbortController(),busy:false,uploading:false,imageWorking:false,
      draft:null,values:{to:'',subject:'',body:'',attachments:[],followthrough:followPolicy()},saved:'',scrollTop:thread.scrollTop,returnFocus:document.activeElement};
    const shell = state.shell = node('section','','vp3-email-compose');
    shell.setAttribute('aria-labelledby','vp3EmailComposeTitle');
    const header = node('header','','vp3-email-compose-header');
    const heading = node('div');heading.append(node('small','Gmail'));
    state.title = node('h2','Compose email');state.title.id = 'vp3EmailComposeTitle';state.title.tabIndex = -1;heading.append(state.title);
    const toolbar = node('div','','vp3-email-compose-toolbar');
    state.listButton = button('View drafts',() => navigate(state,() => void openDrafts({surface:state.surface,replace:true})));
    state.newButton = button('+ New',() => navigate(state,() => void openCompose({surface:state.surface,replace:true})));
    state.newButton.setAttribute('aria-label','Compose another new email');
    state.surfaceButton = button(surface === 'canvas' ? 'Pop out' : 'Open in canvas',() => changeSurface(state,state.surface === 'canvas' ? 'popup' : 'canvas'));
    state.closeButton = button('Close',() => navigate(state,() => release(state,{rememberEdits:false})));
    toolbar.append(state.listButton,state.newButton,state.surfaceButton,state.closeButton);
    if (cfg.fixedDraftId) toolbar.hidden = true;
    header.append(heading,toolbar);
    state.notice = node('p','Opening Gmail…','vp3-email-compose-notice');state.notice.setAttribute('role','status');state.notice.setAttribute('aria-live','polite');
    state.leave = node('section','','vp3-email-compose-leave');state.leave.hidden = true;state.leave.setAttribute('role','alert');
    state.conflict = node('section','','vp3-email-compose-leave');state.conflict.hidden = true;state.conflict.setAttribute('role','alert');
    state.followRecordHost = node('div','','vp3-email-compose-followthrough');state.followRecordHost.hidden = true;
    const editor = state.editor = node('div','','vp3-email-compose-editor');editor.hidden = true;
    const from = node('p','','vp3-email-compose-from');from.append(node('strong','From '),state.from = node('span'));
    state.to = node('input');state.to.type = 'email';state.to.maxLength = 254;state.to.autocomplete = 'email';state.to.placeholder = 'name@example.com';
    state.subject = node('input');state.subject.type = 'text';state.subject.maxLength = 200;state.subject.placeholder = 'Email subject';
    state.body = node('textarea');state.body.rows = 8;state.body.maxLength = 6000;state.body.placeholder = 'Write your message, or ask the Agent to draft it…';
    state.originalSource = node('details','','vp3-email-compose-source');state.originalSource.hidden = true;state.originalSource.open = true;
    state.originalSource.append(node('summary','Original email'));
    state.sourceReason = node('p');state.sourceStatus = node('small');state.sourceStatus.setAttribute('role','status');
    state.sourceRead = button('Read original email',() => void readSource(state));
    state.sourceContent = node('div','','vp3-email-compose-source-content');
    state.originalSource.append(state.sourceReason,state.sourceRead,state.sourceStatus,state.sourceContent);
    state.latestMessage = node('details','','vp3-email-compose-source');state.latestMessage.hidden = true;state.latestMessage.open = true;
    editor.append(from,state.originalSource,state.latestMessage,field('To',state.to),field('Subject',state.subject),field('Message',state.body));
    for (const [name,input] of [['to',state.to],['subject',state.subject],['body',state.body]]) input.addEventListener('input',() => {
      if (!current(state)) return;invalidateVoiceReview(state);state.values[name] = input.value;if (name === 'body') resizeBody(input);controls(state);
    });
    const images = node('section','','vp3-email-compose-images');images.append(node('h3','Images'));
    const imageActions = node('div','','vp3-email-compose-toolbar');
    state.upload = button('Add image',() => state.file.click());
    state.file = node('input');state.file.type = 'file';state.file.accept = imageTypes.join(',');state.file.hidden = true;
    state.file.addEventListener('change',() => void upload(state,state.file.files?.[0]));
    state.libraryToggle = button('Saved images',() => void library(state));
    state.imageToggle = button('Generate image',() => { state.imageTools.hidden = !state.imageTools.hidden;if (!state.imageTools.hidden) state.imagePrompt.focus(); });
    imageActions.append(state.upload,state.file,state.libraryToggle,state.imageToggle);
    state.attachmentCount = node('small');state.attachments = node('div','','vp3-email-compose-attachments');
    state.library = node('section','','vp3-email-compose-library');state.library.hidden = true;
    state.imageTools = node('section','','vp3-email-compose-image-tools');state.imageTools.hidden = true;
    state.imagePrompt = node('textarea');state.imagePrompt.rows = 2;state.imagePrompt.maxLength = 4000;
    state.imagePrompt.placeholder = 'Describe an image to attach to this email';
    state.imageSize = node('select');
    for (const [value,label] of [['1024x1024','Square'],['1536x1024','Landscape'],['1024x1536','Portrait']]) {
      const option = node('option',label);option.value = value;state.imageSize.append(option);
    }
    state.imageCreate = button('Generate image',() => void generateImage(state));
    state.imageCreate.dataset.emailGenerateImage = '';
    state.imageCancel = button('Cancel image request',async () => {
      if (!current(state) || !state.imageJob || state.cancellingImage) return;
      state.cancellingImage = true;controls(state);
      try {
        const result = await api(state,'cancel',{generation_id:state.imageJob.id,confirmed:true},{media:true});
        processImage(state,result.generation);
      } catch (error) { failure(state,error); }
      finally { state.cancellingImage = false;controls(state); }
    });
    state.imageCancel.hidden = true;
    state.imageRetry = button('Check image status',async () => {
      if (!current(state) || !state.imageJob || state.checkingImage) return;
      state.checkingImage = true;controls(state);
      try {
        const result = await api(state,'get',{generation_id:state.imageJob.id},{media:true});
        processImage(state,result.generation);pollImage(state);
      } catch (error) { failure(state,error); }
      finally { state.checkingImage = false;controls(state); }
    });
    state.imageRetry.hidden = true;
    state.imagePrompt.addEventListener('input',() => { resizeBody(state.imagePrompt);controls(state); });
    state.imageProgress = node('p','','vp3-email-compose-image-progress');state.imageProgress.hidden = true;state.imageProgress.setAttribute('role','status');
    state.imageTools.append(field('Image description',state.imagePrompt),field('Image shape',state.imageSize),
      state.imageCreate,state.imageCancel,state.imageRetry,node('small','Uses your image-generation quota. The image is saved privately.'),state.imageProgress);
    images.append(imageActions,state.attachmentCount,state.attachments,state.library,state.imageTools);editor.append(images);
    const agentTools = node('details','','vp3-email-compose-agent');
    agentTools.append(node('summary','Ask the Agent to write or revise'));
    state.instruction = node('textarea');state.instruction.rows = 2;state.instruction.maxLength = 600;
    state.instruction.placeholder = 'What should this email say? Include any facts or tone you want.';
    state.generate = button('Draft with Agent',() => void write(state));state.generate.dataset.emailEditAction = '';
    state.instruction.addEventListener('input',() => { resizeBody(state.instruction);controls(state); });
    agentTools.append(field('Instructions',state.instruction),state.generate,node('small','The Agent saves a draft for your review. Sending remains your choice.'));
    editor.append(agentTools);
    if (followthrough()?.settings) {
      state.followSettings = followthrough().settings(undefined,{onChange:value => { if (current(state)) { invalidateVoiceReview(state);state.values.followthrough = value;controls(state); } }});
      editor.append(state.followSettings.root);
    }
    state.watch = node('input');state.watch.type = 'checkbox';
    state.watch.addEventListener('change',() => invalidateVoiceReview(state));
    state.watchLabel = node('label','','vp3-email-compose-watch');state.watchLabel.append(state.watch,node('span','Watch for the first reply for 30 days'));editor.append(state.watchLabel);
    const actions = node('footer','','vp3-email-compose-actions');
    state.changed = node('small');
    state.save = button('Save draft',() => void save(state));state.save.dataset.emailSave = '';
    state.send = button('Send',() => void send(state),'vp3-email-compose-primary');state.send.dataset.emailSend = '';
    state.refresh = button('Reload saved draft',() => navigate(state,() => void refreshDraft(state)));
    state.checkSend = button('Check send status',() => void checkSend(state));
    state.retry = button('Retry Agent draft',() => void write(state,true));
    state.latest = button('Review latest Gmail message',() => navigate(state,() => void refreshDraft(state,'review_latest')));
    state.discard = button('Discard draft',() => requestDiscard(state,selectedDraft()));
    const secondary = node('div','','vp3-email-compose-secondary');secondary.append(state.retry,state.latest,state.checkSend,state.refresh,state.discard);
    actions.append(state.changed,state.save,state.send);editor.append(secondary,actions);
    state.list = node('section','','vp3-email-compose-list');state.list.hidden = true;
    shell.append(header,state.notice,state.leave,state.conflict,state.followRecordHost,editor,state.list);
    return state;
  }
  async function start(options = {}) {
    if (opening) return null;
    if (active && (active.busy || active.uploading || dirty(active)) && !options.replace) {
      setNotice(active,'Finish or save the email you are editing before opening another draft.');return null;
    }
    opening = true;
    try {
      const actorEpoch = participantEpoch;
      let currentScope = scope(true);
      if (!currentScope) return null;
      const original = scopeKey(currentScope);
      if (!currentScope.conversation_id) {
        const ensured = Number(await runtime()?.ensureConversation?.());currentScope = scope();
        if (!currentScope || actorEpoch !== participantEpoch || !positive(ensured) || ensured !== currentScope.conversation_id || original !== owner+':'+agent+':0') return null;
      }
      if (options.agent_id !== undefined && Number(options.agent_id) !== currentScope.agent_id
        || options.conversation_id !== undefined && Number(options.conversation_id) !== currentScope.conversation_id) return null;
      if (active) release(active,{rememberEdits:!options.replace,returnToChat:false});
      const state = buildState(currentScope,options.surface === 'canvas' ? 'canvas' : 'popup');
      active = state;popup.append(state.shell);changeSurface(state,state.surface);return state;
    } catch { return null; }
    finally { opening = false; }
  }
  async function openCompose(options = {}) {
    if (cfg.fixedDraftId) return false;
    const state = await start(options);if (!state) return false;
    state.busy = true;controls(state);
    try {
      const result = await api(state,'compose_create',{request_id:nonce(),
        ...(typeof options.to === 'string' ? {to:options.to} : {}),
        ...(typeof options.subject === 'string' ? {subject:options.subject} : {}),
        ...(typeof options.body === 'string' ? {body:options.body} : {})});
      adopt(state,result.draft);state.to.focus();return true;
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  async function openDraft(selection,options = {}) {
    const input = typeof selection === 'string' ? {draft_id:selection} : selection || {};
    if (!draftId.test(input.draft_id || '')) return false;
    if (cfg.fixedDraftId && input.draft_id !== cfg.fixedDraftId) return false;
    const currentScope = scope();
    if (!currentScope || input.agent_id !== undefined && Number(input.agent_id) !== currentScope.agent_id
      || input.conversation_id !== undefined && Number(input.conversation_id) !== currentScope.conversation_id) return false;
    if (active?.draft?.id === input.draft_id && current(active)) return changeSurface(active,options.surface || 'canvas');
    const state = await start({...input,...options,surface:options.surface || 'canvas'});if (!state) return false;
    state.busy = true;controls(state);
    try {
      const result = await api(state,'review',{draft_id:input.draft_id});
      const draft = checkedDraft(result.draft,input.draft_id);adopt(state,draft);
      const saved = retained.get(memoryKey(state));
      if (draft.connection_changed) retained.delete(memoryKey(state));
      if (saved && !draft.connection_changed && draft.can_edit !== false && draft.review_token && ['prepared','write_failed'].includes(draft.status)) {
        const matches = sameSavedVersion({draft:saved.draft,saved:saved.saved},draft);
        state.values = saved.values;state.saved = saved.saved;
        state.to.value = saved.values.to;state.subject.value = saved.values.subject;state.body.value = saved.values.body;
        state.followSettings?.update(saved.values.followthrough);
        state.instruction.value = saved.instruction;state.imagePrompt.value = saved.imagePrompt;state.imageSize.value = saved.imageSize;
        state.imageJob = saved.imageJob;state.imageReview = saved.imageReview;
        state.notice.textContent = 'Your unsaved edits are restored. Save draft to keep them.';
        if (!matches) showConflict(state,draft);
        renderAttachments(state);resizeBody(state.body);
        if (state.imageJob) { state.imageTools.hidden = false;processImage(state,state.imageJob);pollImage(state); }
      } else watchWrite(state);
      return true;
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  function checkedQueueItem(item) {
    const target = item?.target;
    if (!draftId.test(item?.id || '') || !positive(item.conversation_id) || !target || target.agent_id !== agent
      || target.conversation_id !== item.conversation_id || target.draft_id !== item.id || typeof item.status !== 'string')
      throw new Error('A draft’s original Chat could not be verified. Refresh the review queue.');
    return item;
  }
  async function openQueueItem(state,item) {
    if (!current(state) || state.busy) return false;
    if (item.available === false || ['automatic','unavailable'].includes(item.status)) return false;
    const target = checkedQueueItem(item).target;
    const surface = state.surface;
    const actorEpoch = participantEpoch;
    if (target.conversation_id === state.scope.conversation_id) return openDraft(target,{surface,replace:true});
    const navigateToChat = runtime()?.openEmailReviewConversation;
    if (typeof navigateToChat !== 'function') { setNotice(state,'Open this draft’s original private Chat to review it.');return false; }
    state.busy = true;controls(state);setNotice(state,'Opening the original private Chat…');
    try {
      const opened = await navigateToChat({owner_key:owner,...target});
      const live = scope();
      if (!opened || actorEpoch !== participantEpoch || !live || live.agent_id !== target.agent_id || live.conversation_id !== target.conversation_id) {
        if (current(state)) setNotice(state,'The original Chat could not be opened. Your current Chat is unchanged.');
        return false;
      }
      return openDraft(target,{surface,replace:true});
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  function queueCard(state,item) {
    const card = node('article','','vp3-email-compose-draft-card');
    card.dataset.emailDraft = item.id;
    const content = node('div','','vp3-email-compose-draft-content');
    const flags = node('div','','vp3-email-compose-draft-flags');
    const status = node('span',statusLabels[item.status] || 'Needs checking','vp3-email-compose-status');
    status.dataset.status = item.status;flags.append(status);
    const priority = shortText(item.source?.priority,30).toLowerCase();
    if (priority && priority !== 'normal') {
      const badge = node('span',priority[0].toUpperCase()+priority.slice(1),'vp3-email-compose-priority');
      if (['urgent','high','low'].includes(priority)) badge.dataset.priority = priority;
      flags.append(badge);
    }
    const unavailable = item.available === false || ['automatic','unavailable'].includes(item.status);
    content.append(flags,node('h3',shortText(item.subject,1000) || (unavailable ? 'Unavailable draft' : '(No subject)')));
    if (!unavailable) content.append(node('p',shortText(item.to,320) || 'Recipient not added'));
    const reason = shortText(item.source?.reason);
    if (reason) content.append(node('p',reason,'vp3-email-compose-draft-reason'));
    if (shortText(item.blocked_reason)) content.append(node('p',blockedLabels[item.blocked_reason] || 'Review this draft’s status before continuing.','vp3-email-compose-draft-blocked'));
    const facts = node('div','','vp3-email-compose-draft-facts');
    const kind = ['organic_reply','outbound_followup'].includes(item.source?.kind) ? 'Agent prepared' : '';
    if (!unavailable) facts.append(node('small',[item.source?.kind === 'outbound_followup' ? 'Follow-up' : item.is_reply ? 'Reply' : 'New email',kind].filter(Boolean).join(' · ')));
    const deadline = displayTime(item.source?.deadline);
    if (deadline) facts.append(node('small','Due '+deadline,'vp3-email-compose-deadline'));
    const age = displayAge(item.age_seconds);
    const created = displayTime(item.created_at);
    if (age || created) {
      const date = node('small',age || 'Created '+created);if (created) date.title = created;facts.append(date);
    }
    const updated = displayTime(item.updated_at || item.saved_at);
    if (updated) facts.append(node('small','Updated '+updated));
    facts.append(node('small','Chat: '+(shortText(item.conversation_title,190) || 'Private Chat')));
    content.append(facts);
    const crossChat = item.conversation_id !== state.scope.conversation_id;
    const resume = button(crossChat ? 'Open original Chat' : 'Open draft',() => void openQueueItem(state,item));
    resume.dataset.emailUnavailable = String(unavailable);
    resume.disabled = unavailable;
    card.append(content,resume);return card;
  }
  async function loadQueue(state,{page = state.queuePage,filter = state.queueFilter.value} = {}) {
    if (!current(state) || state.busy || !['pending','all'].includes(filter)) return false;
    state.busy = true;controls(state);setNotice(state,'Loading private email drafts…');
    try {
      const cursor = page === 0 ? null : state.queueCursors[page];
      if (page > 0 && (typeof cursor !== 'string' || !cursor)) throw new Error('Refresh the review queue to continue browsing.');
      const result = await api(state,'compose_list',{queue_scope:'agent',filter,limit:20,cursor});
      if (!Array.isArray(result.drafts) || result.drafts.length > 50
        || result.filter !== undefined && result.filter !== filter || result.scope !== undefined && result.scope !== 'agent')
        throw new Error('The saved draft queue could not be verified.');
      const items = result.drafts.map(checkedQueueItem);
      const next = result.next_cursor ?? null;
      if (next !== null && (typeof next !== 'string' || !next || next.length > 8000)) throw new Error('The next draft page could not be verified.');
      const counts = publishCounts(result.counts);
      state.queuePage = page;state.queueNextCursor = next;
      state.queueFilter.value = filter;state.queueLoadedFilter = filter;
      state.queueTotals.replaceChildren(node('strong',`${counts.pending} pending draft${counts.pending === 1 ? '' : 's'}`),
        node('small',`${counts.total} total · ${counts.sent} sent · ${counts.discarded} discarded · ${counts.answered} answered`));
      state.queueRows.replaceChildren(...items.map(item => queueCard(state,item)));
      if (!items.length) state.queueRows.append(node('p',filter === 'pending' ? 'No pending drafts. Select + New to compose an email.' : 'No email drafts in this agent’s private Chats.'));
      state.queuePageLabel.textContent = 'Page '+(page+1);
      state.notice.textContent = 'Drafts from this agent’s private Chats. Pending includes drafts being written or needing attention. Sending remains your choice.';
      return true;
    } catch (error) {
      failure(state,error);
      if (state.queueLoadedFilter) state.queueFilter.value = state.queueLoadedFilter;
      if (!state.queueRows.childElementCount) state.queueRows.append(node('p','Use Refresh to try loading the queue again.'));
      return false;
    } finally { state.busy = false;controls(state); }
  }
  async function openDrafts(options = {}) {
    if (cfg.fixedDraftId) return false;
    const state = await start(options);if (!state) return false;
    state.title.textContent = 'Drafts';state.list.hidden = false;
    state.queuePage = 0;state.queueCursors = [null];state.queueNextCursor = null;
    const top = node('div','','vp3-email-compose-queue-top');
    state.queueTotals = node('div','','vp3-email-compose-queue-totals');state.queueTotals.setAttribute('aria-live','polite');
    state.queueFilter = node('select');state.queueFilter.setAttribute('aria-label','Draft status filter');
    for (const [value,label] of [['pending','Pending drafts'],['all','All drafts']]) {
      const option = node('option',label);option.value = value;state.queueFilter.append(option);
    }
    state.queueFilter.value = options.filter === 'all' ? 'all' : 'pending';
    state.queueFilter.addEventListener('change',() => { state.queueCursors = [null];void loadQueue(state,{page:0}); });
    state.queueReload = button('Refresh',() => { state.queueCursors = [null];void loadQueue(state,{page:0}); });
    const filters = node('div','','vp3-email-compose-queue-filters');filters.append(state.queueFilter,state.queueReload);
    top.append(state.queueTotals,filters);
    state.queueRows = node('div','','vp3-email-compose-queue-rows');state.queueRows.setAttribute('aria-label','Email draft review queue');
    const pages = node('nav','','vp3-email-compose-queue-pages');pages.setAttribute('aria-label','Draft pages');
    state.queuePrevious = button('Previous',() => void loadQueue(state,{page:state.queuePage-1}));
    state.queueNext = button('Next',() => {
      if (!state.queueNextCursor) return;state.queueCursors[state.queuePage+1] = state.queueNextCursor;
      void loadQueue(state,{page:state.queuePage+1});
    });
    state.queuePageLabel = node('span','Page 1');pages.append(state.queuePrevious,state.queuePageLabel,state.queueNext);
    state.list.append(top,state.queueRows,pages);
    return loadQueue(state);
  }
  async function queueSummary() {
    const currentScope = scope();
    if (!currentScope) return null;
    if (summaryCache && Date.now()-summaryCache.at < 10000) return {...summaryCache.counts};
    const key = scopeKey(currentScope);
    if (summaryRequest?.key === key) return summaryRequest.promise;
    const endpoint = localUrl(cfg.endpoint);
    if (!endpoint) return null;
    const controller = new AbortController();
    const epoch = summaryEpoch;
    const timer = setTimeout(() => controller.abort(),25000);
    const promise = (async () => {
      try {
        const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
          headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'compose_list',owner_key:owner,csrf_token:cfg.csrf,
            ...currentScope,queue_scope:'agent',filter:'pending',limit:1,cursor:null})});
        const result = await response.json();
        if (!response.ok || result?.ok !== true || key !== scopeKey(scope()) || epoch !== summaryEpoch) return null;
        return publishCounts(result.counts);
      } catch { return null; }
      finally { clearTimeout(timer);if (summaryRequest?.controller === controller) summaryRequest = null; }
    })();
    summaryRequest = {key,promise,controller};return promise;
  }
  function matchInstruction(text,context) {
    if (typeof text !== 'string' || !sameScope(context,scope(true))) return null;
    const requested = text.trim();
    const command = requested.replace(/^(?:(?:please\s+)|(?:(?:can|could|would) you\s+))+/iu,'').replace(/[.!?]+$/u,'').trim();
    if (/^(?:show|open|list|view|review)(?: me)?(?: my| the)?(?: gmail| email)? (?:drafts|draft queue|needs review(?: queue)?)$/iu.test(command))
      return Object.freeze({kind:'queue',requested});
    if (/^(?:show|open|list|view)(?: me)?(?: my| the)?(?: gmail| email)? follow[ -]?ups$/iu.test(command)
      || /^(?:show|open|list|view)(?: me)?(?: my| the)? overdue (?:gmail )?(?:emails?|mail|messages?)$/iu.test(command))
      return Object.freeze({kind:'sent',section:'followups',requested});
    if (/^(?:show|open|list|view)(?: me)?(?: my| the)?(?: gmail)? sent (?:emails?|mail|messages?)$/iu.test(command))
      return Object.freeze({kind:'sent',requested});
    const selection = selectedDraft();
    if (!selection) return null;
    if (/^save (?:it|(?:(?:this|the|my|current) )?(?:email )?draft)$/iu.test(command))
      return Object.freeze({kind:'save',requested,selection});
    if (/^(?:discard|delete) (?:it|(?:(?:this|the|my|current) )?(?:email )?draft)$/iu.test(command))
      return Object.freeze({kind:'discard',requested,selection});
    const explicitEmailAddition = /^add\s+\S[\s\S]*\b(?:to|in|into) (?:this|the|my|current) (?:email|draft|reply)\b/iu.test(command);
    const otherAddition = /^add\s+(?:(?:a|an|the|new|another|my)\s+)*(?:task|reminder|appointment|calendar(?: event)?|event|contact|meeting|alarm|timer|list|todo|to-do)\b/iu.test(command)
      || /\bto (?:my |the |a |an |our )?(?:tasks?|task list|to-do list|calendar|contacts?|address book|reminders?|shopping list|playlist|spreadsheet)\b/iu.test(command);
    const emailAddition = explicitEmailAddition || !otherAddition
      && /^add\s+(?:that\b|(?:(?:a|an|the)\s+)?(?:(?:warm|friendly|formal|brief|short|polite|clear)\s+)?(?:sentence|paragraph|line|greeting|closing|signature|note|detail|details|mention|point)\b)/iu.test(command);
    const revise = /^(?:revise|rewrite|shorten|expand|polish|simplify|rephrase) (?:it|(?:(?:this|the|my|current) )?(?:email|draft|reply))(?:\s|[,:]|$)/iu.test(command)
      || /^make (?:it|(?:(?:this|the|my|current) )?(?:email|draft|reply)) (?:shorter|longer|clearer|friendlier|warmer|formal|friendly|concise|professional|more (?:formal|friendly|concise|professional|detailed)|less (?:formal|wordy))(?:\s|[,:]|$)/iu.test(command)
      || emailAddition;
    return revise ? Object.freeze({kind:'revise',requested,instruction:requested,selection}) : null;
  }
  async function handleChatInstruction(text,context,matched) {
    const match = matched || matchInstruction(text,context);
    if (!match) return {handled:false,ok:false};
    const rejected = message => ({handled:true,ok:false,message});
    if (typeof text !== 'string' || match.requested !== text.trim() || !sameScope(context,scope(true)))
      return rejected('The Chat changed. Keep your instruction and select the email again.');
    const currentMatch = matchInstruction(text,context);
    if (!currentMatch || currentMatch.kind !== match.kind || currentMatch.instruction !== match.instruction)
      return rejected('Select the exact email draft before applying this instruction.');
    if (match.kind === 'queue' || match.kind === 'sent') {
      const state = active;
      if (state && current(state) && (state.busy || state.uploading)) return rejected('Wait for the current email action to finish.');
      const open = async () => {
        if (!sameScope(context,scope(true))) return false;
        if (match.kind === 'queue') return openDrafts({surface:state?.surface || 'canvas',replace:true});
        const sent = window.VP3AgentActivityHub?.openGmailSent;
        if (typeof sent !== 'function') return false;
        if (state && current(state)) release(state,{rememberEdits:false});
        return sent(context);
      };
      if (state && current(state) && dirty(state)) {
        navigate(state,() => void open());
        return rejected('Choose how to handle your unsaved email changes before opening '+(match.kind === 'sent' ? 'Sent.' : 'View drafts.'));
      }
      const ok = await open();
      return {handled:true,ok,message:match.kind === 'sent'
        ? ok ? match.section === 'followups' ? 'Opened follow-ups in Gmail Sent for this Chat.' : 'Opened Gmail Sent.'
          : 'Gmail Sent could not be opened. Your instruction is kept.'
        : ok ? 'Opened the Gmail draft review queue.' : 'The draft review queue could not be opened. Your instruction is kept.'};
    }
    if (!sameSelection(match.selection)) return rejected('The selected draft changed. Review its current version, then repeat your instruction.');
    const state = active;
    if (!state || !current(state) || state.busy || state.uploading || state.conflicted)
      return rejected('Finish the current draft action or resolve its saved-version conflict first.');
    if (match.kind === 'discard') {
      if (state.discard.disabled) return rejected('This draft cannot be discarded while its outcome is being checked.');
      if (dirty(state)) {
        requestDiscard(state,match.selection);
        return rejected('Choose how to handle your unsaved changes in the email before discarding it.');
      }
      const ok = await discardDraft(state,match.selection);
      return {handled:true,ok,message:ok ? 'Discarded the selected email draft.' : 'The draft was not discarded. Review the message in the email editor.'};
    }
    if (state.draft.can_edit === false || !state.draft.review_token || !['prepared','write_failed'].includes(state.draft.status) || state.imageWorking)
      return rejected('This email is not ready to edit. Check its status in the editor first.');
    if (match.kind === 'save') {
      const ok = await save(state);
      return {handled:true,ok,message:ok ? 'Saved the selected email draft in its original Chat.' : 'The draft could not be saved. Your edits and instruction are kept.'};
    }
    if (match.kind !== 'revise' || !match.instruction || match.instruction.length > 600)
      return rejected('Use a revision instruction of 600 characters or fewer.');
    state.instruction.value = match.instruction;controls(state);
    const ok = await write(state);
    return {handled:true,ok,message:ok ? 'The Agent is revising the selected draft. Review the result before sending.'
      : 'The revision could not be started. Your draft and instruction are kept.'};
  }
  function clearQueueSummary() {
    summaryEpoch++;summaryCache = null;summaryRequest?.controller.abort();summaryRequest = null;
  }
  popup.addEventListener('cancel',event => { event.preventDefault();if (active) navigate(active,() => release(active,{rememberEdits:false})); });
  popup.addEventListener('close',() => { if (!popup.open && active?.surface === 'popup') release(active); });
  window.addEventListener('beforeunload',event => { if (active && dirty(active)) { event.preventDefault();event.returnValue = ''; } });
  for (const name of ['stonefellow:chat-context-changing','vp3:participant-selection-changed']) window.addEventListener(name,() => {
    clearQueueSummary();
    if (active) release(active,{returnToChat:false});
  });
  window.addEventListener('vp3:participant-task-context',event => {
    participantActor = Math.max(0,Number(event.detail?.participant_id) || 0);participantEpoch++;
    clearQueueSummary();
    if (active) release(active,{returnToChat:false});
  });
  window.addEventListener('vp3:chat-email-drafts-changed',clearQueueSummary);
  window.addEventListener('vp3:chat-history-updated',clearQueueSummary);
  window.addEventListener('vp3:chat-conversation-changed',() => { if (active && !current(active)) release(active,{returnToChat:false}); });
  window.addEventListener('vp3:chat-canvas-view-changed',() => {
    canvas.hidden = thread.dataset.view !== 'email';
    if (active?.surface === 'canvas' && thread.dataset.view !== 'email') release(active,{returnToChat:false});
  });
  window.addEventListener('resize',() => { if (active) resizeBody(active.body); });
  window.VP3EmailComposerV1 = Object.freeze({openCompose,openDraft,openDrafts,queueSummary,selectedDraft,matchInstruction,handleChatInstruction,
    requestClose,isDirty:() => Boolean(active && current(active) && dirty(active)),
    ...(cfg.fixedDraftId && cfg.callReviewEnabled === true ? {voiceReview:Object.freeze({snapshot:voiceReviewSnapshot,matches:voiceReviewMatches,send:voiceReviewSend})} : {})});
})();
