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
  const positive = value => Number.isSafeInteger(value) && value > 0;
  const imageTypes = ['image/jpeg','image/png','image/webp'];
  const attachmentLimit = 10 * 1024 * 1024;
  const retained = new Map();
  let active = null, opening = false;
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
  function scope(allowEmpty = false) {
    const live = window.STONEFELLOW_CHAT;
    const chat = runtime();
    const cid = Number(chat?.conversationId?.() || 0);
    if (String(live?.userId) !== owner || Number(live?.agentId || 0) !== agent
      || Number(chat?.agentId?.() ?? agent) !== agent || String(live?.workingAttentionBinding || '') !== binding
      || window.VP3ParticipantContinuity?.selectedContext?.() || chat?.isRestoring?.()
      || chat?.isReady?.() === false || !Number.isSafeInteger(cid) || cid < (allowEmpty ? 0 : 1)) return null;
    return {agent_id:agent,conversation_id:cid};
  }
  const scopeKey = value => value ? owner+':'+value.agent_id+':'+value.conversation_id : '';
  const current = state => active === state && scopeKey(state.scope) === scopeKey(scope()) && state.shell.isConnected;
  const valuesKey = values => JSON.stringify([values.to,values.subject,values.body,values.attachments.map(asset => asset.asset_id)]);
  const dirty = state => state.draft && valuesKey(state.values) !== state.saved;
  const memoryKey = state => scopeKey(state.scope)+':'+state.draft?.id;
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
    state.send.disabled = Boolean(busy || locked || !complete || state.sendUncertain || state.imageWorking || state.imageReview);
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
    state.watch.disabled = Boolean(busy || locked || !draft?.watch_review_token);
    state.watchLabel.hidden = !draft?.watch_review_token;
    state.refresh.hidden = !draft;
    state.refresh.disabled = Boolean(busy);
    state.discard.disabled = Boolean(busy || !draft || !['prepared','write_failed','write_queued','stale','source_check','answered','failed','expired'].includes(draft.status));
    state.retry.hidden = draft?.status !== 'write_failed';
    state.latest.hidden = draft?.can_review_latest !== true;
    state.checkSend.hidden = !state.sendUncertain && draft?.status !== 'sending';
    state.retry.disabled = state.latest.disabled = state.checkSend.disabled = Boolean(state.busy);
    state.newButton.disabled = state.listButton.disabled = state.closeButton.disabled = Boolean(busy);
    state.imageToggle.disabled = state.upload.disabled = state.libraryToggle.disabled = Boolean(busy || locked || state.values?.attachments.length >= 5);
    state.shell.setAttribute('aria-busy',String(Boolean(state.busy)));
  }
  function valuesFromDraft(draft) {
    return {to:draft.to,subject:draft.subject,body:draft.body,attachments:[...draft.attachments]};
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
  function adopt(state,value,{message = ''} = {}) {
    const draft = checkedDraft(value,state.draft?.id || '');
    state.snapshotSeq = (state.snapshotSeq || 0)+1;
    state.conflicted = false;state.conflict.hidden = true;
    state.draft = draft;
    state.values = valuesFromDraft(draft);
    state.saved = valuesKey(state.values);
    state.to.value = draft.to;state.subject.value = draft.subject;state.body.value = draft.body;
    state.from.textContent = draft.from || 'Connected Gmail account';
    state.title.textContent = draft.is_reply ? 'Reply email' : 'Compose email';
    state.editor.hidden = false;state.list.hidden = true;
    state.watch.checked = Boolean(draft.watch_requested);
    const messages = {
      prepared:'',write_queued:'The Agent is preparing your draft…',writing:'The Agent is writing your email…',
      write_failed:'The Agent could not finish writing. Your saved text is kept; edit it or retry.',
      stale:'A newer message arrived. Review the latest message before sending this reply.',
      source_check:'The current Gmail thread needs to be checked before this reply can be sent.',
      answered:'You already replied to this message in Gmail.',
      sending:'Gmail has not confirmed the outcome yet. Check send status before trying anything else.',
      sent:'Gmail confirmed your email was sent.',failed:'Gmail rejected this email. Your saved draft remains available.',
      discarded:'Draft discarded.',expired:'This draft review expired. Start a new email.'
    };
    state.notice.textContent = draft.connection_changed ? 'Gmail access changed. Check Connections before editing or sending.'
      : message || messages[draft.status] || '';
    state.latestMessage.replaceChildren();state.latestMessage.hidden = !draft.latest_reply?.excerpt;
    if (draft.latest_reply?.excerpt) state.latestMessage.append(node('summary','Latest Gmail message'),
      node('strong',draft.latest_reply.subject || ''),node('p',draft.latest_reply.excerpt));
    renderAttachments(state);resizeBody(state.body);controls(state);
  }
  async function api(state,action,input = {},{media = false,signal} = {}) {
    if (!current(state)) throw new DOMException('The Chat context changed.','AbortError');
    const endpoint = localUrl(media ? cfg.imageEndpoint : cfg.endpoint);
    if (!endpoint) throw new Error(media ? 'Image generation is unavailable.' : 'The Gmail composer is unavailable.');
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
    const fields = {to:state.values.to,subject:state.values.subject,body:state.values.body,attachment_ids:state.values.attachments.map(item => item.asset_id)};
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
      result = await save();
    }
    adopt(state,result.draft,{message:'Draft saved in this Chat.'});
    retained.delete(memoryKey(state));
    window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
    return result.draft;
  }
  async function save(state,after) {
    if (!current(state) || state.busy || state.imageWorking || !state.draft?.review_token) return;
    state.busy = true;controls(state);setNotice(state,'Saving draft…');
    try { await saveSnapshot(state);if (current(state) && after) after(); }
    catch (error) { failure(state,error); }
    finally { state.busy = false;controls(state); }
  }
  async function send(state) {
    if (!current(state) || state.send.disabled || state.busy) return;
    state.busy = true;const watch = state.watch.checked;controls(state);
    let dispatched = false;
    try {
      setNotice(state,'Saving the exact email you reviewed…');
      await saveSnapshot(state);
      const token = watch ? state.draft.watch_review_token : state.draft.review_token;
      if (!token || state.draft.can_send === false) throw new Error('Complete the recipient, subject and message before sending.');
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
        const watch = Boolean(state.draft.watch_requested);
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
    if (!current(state) || state.busy || state.imageWorking || !state.draft?.review_token || !retry && !state.instruction.value.trim()) return;
    state.busy = true;controls(state);
    try {
      if (!retry) await saveSnapshot(state);
      const result = await api(state,retry ? 'retry_write' : 'compose_generate',{draft_id:state.draft.id,
        review_token:state.draft.review_token,...(!retry ? {instruction:state.instruction.value.trim()} : {})});
      adopt(state,result.draft);watchWrite(state);
    } catch (error) { failure(state,error); }
    finally { state.busy = false;controls(state); }
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
    rememberEdits ||= state.keepEditsOnRelease === true;
    if (rememberEdits || state.imageJob || state.imageReview) remember(state,{savedOnly:!rememberEdits});
    else if (state.draft) retained.delete(memoryKey(state));
    active = null;
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
    state.values.attachments.push(item);renderAttachments(state);controls(state);
  }
  async function upload(state,file) {
    if (!file || !current(state) || state.busy || state.uploading) return;
    if (!imageTypes.includes(file.type) || file.size < 1 || file.size+state.values.attachments.reduce((sum,value) => sum+value.size,0) > attachmentLimit) {
      setNotice(state,'Choose a JPG, PNG or WebP image. All attachments together must fit within 10 MB.');return;
    }
    const endpoint = localUrl(cfg.mediaLibraryEndpoint);
    if (!endpoint) { setNotice(state,'Image uploads are unavailable.');return; }
    state.uploading = true;controls(state);
    const post = async (action,fields = {}) => {
      const form = new FormData();form.set('action',action);form.set('csrf_token',cfg.csrf);
      for (const [key,value] of Object.entries(fields)) form.set(key,value);
      const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:state.controller.signal,
        headers:{'X-VP3-Media-Owner':owner},body:form});
      const data = await response.json().catch(() => null);
      if (!current(state)) throw new DOMException('The Chat context changed.','AbortError');
      if (!response.ok || data?.ok !== true) throw new Error(data?.error || 'The image upload failed. Your email is kept.');
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
      if (!response.ok || result?.ok !== true || !Array.isArray(result.assets)) throw new Error(result?.error || 'Saved images could not be loaded.');
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
    state.imageWorking = true;controls(state);
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
      draft:null,values:{to:'',subject:'',body:'',attachments:[]},saved:'',scrollTop:thread.scrollTop,returnFocus:document.activeElement};
    const shell = state.shell = node('section','','vp3-email-compose');
    shell.setAttribute('aria-labelledby','vp3EmailComposeTitle');
    const header = node('header','','vp3-email-compose-header');
    const heading = node('div');heading.append(node('small','Gmail'));
    state.title = node('h2','Compose email');state.title.id = 'vp3EmailComposeTitle';state.title.tabIndex = -1;heading.append(state.title);
    const toolbar = node('div','','vp3-email-compose-toolbar');
    state.listButton = button('Drafts',() => navigate(state,() => void openDrafts({surface:state.surface,replace:true})));
    state.newButton = button('+ New',() => navigate(state,() => void openCompose({surface:state.surface,replace:true})));
    state.newButton.setAttribute('aria-label','Compose another new email');
    state.surfaceButton = button(surface === 'canvas' ? 'Pop out' : 'Open in canvas',() => changeSurface(state,state.surface === 'canvas' ? 'popup' : 'canvas'));
    state.closeButton = button('Close',() => navigate(state,() => release(state,{rememberEdits:false})));
    toolbar.append(state.listButton,state.newButton,state.surfaceButton,state.closeButton);
    header.append(heading,toolbar);
    state.notice = node('p','Opening Gmail…','vp3-email-compose-notice');state.notice.setAttribute('role','status');state.notice.setAttribute('aria-live','polite');
    state.leave = node('section','','vp3-email-compose-leave');state.leave.hidden = true;state.leave.setAttribute('role','alert');
    state.conflict = node('section','','vp3-email-compose-leave');state.conflict.hidden = true;state.conflict.setAttribute('role','alert');
    const editor = state.editor = node('div','','vp3-email-compose-editor');editor.hidden = true;
    const from = node('p','','vp3-email-compose-from');from.append(node('strong','From '),state.from = node('span'));
    state.to = node('input');state.to.type = 'email';state.to.maxLength = 254;state.to.autocomplete = 'email';state.to.placeholder = 'name@example.com';
    state.subject = node('input');state.subject.type = 'text';state.subject.maxLength = 200;state.subject.placeholder = 'Email subject';
    state.body = node('textarea');state.body.rows = 8;state.body.maxLength = 6000;state.body.placeholder = 'Write your message, or ask the Agent to draft it…';
    state.latestMessage = node('details','','vp3-email-compose-source');state.latestMessage.hidden = true;state.latestMessage.open = true;
    editor.append(from,state.latestMessage,field('To',state.to),field('Subject',state.subject),field('Message',state.body));
    for (const [name,input] of [['to',state.to],['subject',state.subject],['body',state.body]]) input.addEventListener('input',() => {
      if (!current(state)) return;state.values[name] = input.value;if (name === 'body') resizeBody(input);controls(state);
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
    state.watch = node('input');state.watch.type = 'checkbox';
    state.watchLabel = node('label','','vp3-email-compose-watch');state.watchLabel.append(state.watch,node('span','Watch for the first reply for 30 days'));editor.append(state.watchLabel);
    const actions = node('footer','','vp3-email-compose-actions');
    state.changed = node('small');
    state.save = button('Save draft',() => void save(state));state.save.dataset.emailSave = '';
    state.send = button('Send',() => void send(state),'vp3-email-compose-primary');state.send.dataset.emailSend = '';
    state.refresh = button('Reload saved draft',() => navigate(state,() => void refreshDraft(state)));
    state.checkSend = button('Check send status',() => void checkSend(state));
    state.retry = button('Retry Agent draft',() => void write(state,true));
    state.latest = button('Review latest Gmail message',() => navigate(state,() => void refreshDraft(state,'review_latest')));
    state.discard = button('Discard draft',() => navigate(state,async () => {
      if (!current(state) || state.busy) return;state.busy = true;controls(state);
      try { await api(state,'discard',{draft_id:state.draft.id});await openDrafts({surface:state.surface,replace:true}); }
      catch (error) { failure(state,error); }
      finally { state.busy = false;controls(state); }
    }));
    const secondary = node('div','','vp3-email-compose-secondary');secondary.append(state.retry,state.latest,state.checkSend,state.refresh,state.discard);
    actions.append(state.changed,state.save,state.send);editor.append(secondary,actions);
    state.list = node('section','','vp3-email-compose-list');state.list.hidden = true;
    shell.append(header,state.notice,state.leave,state.conflict,editor,state.list);
    return state;
  }
  async function start(options = {}) {
    if (opening) return null;
    if (active && (active.busy || active.uploading || dirty(active)) && !options.replace) {
      setNotice(active,'Finish or save the email you are editing before opening another draft.');return null;
    }
    opening = true;
    try {
      let currentScope = scope(true);
      if (!currentScope) return null;
      const original = scopeKey(currentScope);
      if (!currentScope.conversation_id) {
        const ensured = Number(await runtime()?.ensureConversation?.());currentScope = scope();
        if (!currentScope || !positive(ensured) || ensured !== currentScope.conversation_id || original !== owner+':'+agent+':0') return null;
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
  async function openDrafts(options = {}) {
    const state = await start(options);if (!state) return false;
    state.title.textContent = 'Email drafts';state.list.hidden = false;
    state.busy = true;controls(state);
    try {
      const result = await api(state,'compose_list');
      if (!Array.isArray(result.drafts)) throw new Error('Saved drafts could not be loaded.');
      state.notice.textContent = 'Drafts are saved privately in this Chat. They are sent only when you choose Send.';
      for (const item of result.drafts) {
        if (!draftId.test(item?.id || '')) continue;
        const card = node('article','','vp3-email-compose-draft-card');
        card.append(node('strong',item.subject || '(No subject)'),node('p',item.to || 'Recipient not added'),
          node('small',[item.is_reply ? 'Reply' : 'New email',String(item.status || 'draft').replaceAll('_',' ')].join(' · ')));
        const resume = button('Open draft',() => void openDraft({...state.scope,draft_id:item.id},{surface:state.surface,replace:true}));
        card.append(resume);state.list.append(card);
      }
      if (!state.list.childElementCount) state.list.append(node('p','No saved drafts in this Chat yet. Select + New to compose an email.'));
      return true;
    } catch (error) { failure(state,error);return false; }
    finally { state.busy = false;controls(state); }
  }
  popup.addEventListener('cancel',event => { event.preventDefault();if (active) navigate(active,() => release(active,{rememberEdits:false})); });
  popup.addEventListener('close',() => { if (!popup.open && active?.surface === 'popup') release(active); });
  window.addEventListener('beforeunload',event => { if (active && dirty(active)) { event.preventDefault();event.returnValue = ''; } });
  for (const name of ['stonefellow:chat-context-changing','vp3:participant-selection-changed']) window.addEventListener(name,() => {
    if (active) release(active,{returnToChat:false});
  });
  window.addEventListener('vp3:chat-conversation-changed',() => { if (active && !current(active)) release(active,{returnToChat:false}); });
  window.addEventListener('vp3:chat-canvas-view-changed',() => {
    canvas.hidden = thread.dataset.view !== 'email';
    if (active?.surface === 'canvas' && thread.dataset.view !== 'email') release(active,{returnToChat:false});
  });
  window.addEventListener('resize',() => { if (active) resizeBody(active.body); });
  window.VP3EmailComposerV1 = Object.freeze({openCompose,openDraft,openDrafts});
})();
