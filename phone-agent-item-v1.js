(() => {
  'use strict';
  const data = document.getElementById('phoneAgentItemData');
  const root = document.getElementById('phoneAgentItem');
  const status = document.getElementById('phoneAgentItemStatus');
  const content = document.getElementById('phoneAgentItemContent');
  const thread = document.getElementById('chatThread');
  if (!data || !root || !status || !content || !thread) return;
  let cfg;
  try {cfg = JSON.parse(data.textContent);} catch {return;}
  data.remove();
  if (/^[a-f0-9]{32}$/.test(cfg.channel || '') && typeof cfg.error === 'string') {
    status.textContent = cfg.error;
    window.parent.postMessage({channel:cfg.channel,type:'ready'},location.origin);
    window.addEventListener('message',event => {
      if (event.source === window.parent && event.origin === location.origin && event.data?.channel === cfg.channel
        && event.data.type === 'request_close' && Number.isSafeInteger(event.data.id)) {
        window.parent.postMessage({channel:cfg.channel,type:'close_result',id:event.data.id,allowed:true},location.origin);
      }
    });
    return;
  }
  if (!/^[a-f0-9]{32}$/.test(cfg.channel || '') || !/^[a-f0-9]{48}$/.test(cfg.nonce || '')
    || !/^[a-f0-9]{64}$/.test(cfg.binding || '') || !Number.isSafeInteger(cfg.agentId) || cfg.agentId < 0
    || !Number.isSafeInteger(cfg.conversationId) || cfg.conversationId < 1) return;
  const target = cfg.target;
  const life = new AbortController();
  let closed = false,canvas = null,saving = false;
  const post = (type,extra = {}) => {if (!closed && window.parent !== window) window.parent.postMessage({channel:cfg.channel,type,...extra},location.origin);};
  const node = (tag,text = '') => {const element = document.createElement(tag);element.textContent = text;return element;};
  const local = value => {
    try {const url = new URL(value,location.href);return url.origin === location.origin && !url.username && !url.password ? url.href : null;}
    catch {return null;}
  };
  function report(message) {status.textContent = message;status.hidden = !message;}
  function clearPrivate() {
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    content.replaceChildren();thread.replaceChildren();canvas = null;
  }
  window.addEventListener('vp3:phone-agent-access-changed',() => {
    clearPrivate();report('Your private workspace access changed. Return to Agent and sign in again.');post('changed');
  });
  async function api(endpoint,action,fields = {}) {
    const url = local(endpoint);if (!url || closed) throw new Error('This private view is unavailable.');
    const response = await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:life.signal,
      headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,
        ...(endpoint === cfg.imageEndpoint ? {owner_key:cfg.ownerKey} : {agent_id:cfg.agentId,conversation_id:cfg.conversationId}),
        ...(endpoint === cfg.emailEndpoint ? {owner_key:cfg.ownerKey} : {}),...fields})});
    const result = await response.json().catch(() => null);
    if (closed) throw new DOMException('This view closed.','AbortError');
    if (!response.ok || result?.ok !== true) {
      if ([401,403,419].includes(response.status) || cfg.kind !== 'gmail_draft' && [404,409].includes(response.status)) {
        clearPrivate();post('changed');
      }
      throw new Error(result?.error || 'The saved item could not be verified. Return to Agent and reopen it.');
    }
    return result;
  }
  window.STONEFELLOW_CHAT = {userId:cfg.ownerKey,agentId:cfg.agentId,workingAttentionBinding:cfg.binding};
  window.STONEFELLOW_CHAT_CONTINUITY_V87 = Object.freeze({
    agentId:() => cfg.agentId,conversationId:() => cfg.conversationId,isReady:() => !closed,isRestoring:() => false,
    showEmailCanvasView() {thread.dataset.view = 'email';},showChatView() {post('back');}
  });
  window.VP3_CHAT_EMAIL_V1 = {endpoint:cfg.emailEndpoint,csrf:cfg.csrf,ownerKey:cfg.ownerKey,agentId:cfg.agentId,
    fixedDraftId:cfg.kind === 'gmail_draft' ? target.draft_id : undefined,mediaLibraryEndpoint:cfg.mediaEndpoint,
    imageEndpoint:cfg.imageEndpoint,connectionsUrl:cfg.connectionsUrl,callReviewEnabled:cfg.kind === 'gmail_draft' && Boolean(cfg.callSettingsEndpoint)};
  function changed() {post('changed');queueMicrotask(() => post('dirty',{value:Boolean(window.VP3EmailComposerV1?.isDirty?.())}));}
  for (const name of ['vp3:chat-email-drafts-changed','vp3:chat-email-watch-changed','vp3:email-followthrough-changed']) window.addEventListener(name,changed);
  for (const name of ['input','change']) document.addEventListener(name,() => queueMicrotask(() => post('dirty',{value:Boolean(window.VP3EmailComposerV1?.isDirty?.())})));
  window.addEventListener('vp3:email-preferences-dirty',() => post('dirty',{value:Boolean(window.VP3EmailComposerV1?.isDirty?.())}));
  window.addEventListener('message',async event => {
    if (closed || event.source !== window.parent || event.origin !== location.origin || event.data?.channel !== cfg.channel
      || event.data.type !== 'request_close' || !Number.isSafeInteger(event.data.id)) return;
    const allowed = !saving && (cfg.kind !== 'gmail_draft' || await window.VP3EmailComposerV1?.requestClose?.() === true);
    post('close_result',{id:event.data.id,allowed});
  });
  window.addEventListener('pagehide',() => {closed = true;life.abort();clearPrivate();},{once:true});

  // Private call approval belongs to this trusted native host, never to generated
  // Canvas code or the carrier. A claimed intent gets one normal Gmail confirm.
  function mountCallReview() {
    if (cfg.kind !== 'gmail_draft' || !local(cfg.callSettingsEndpoint) || !/^[a-f0-9]{32}$/.test(cfg.deviceId || '')
      || !/^[a-f0-9]{32}$/.test(cfg.itemRef || '') || !Number.isSafeInteger(cfg.enrollmentRevision)) return;
    const box = node('section');box.className = 'phone-agent-call-review';box.hidden = true;
    const heading = node('h2','Call approval');
    const description = node('p','You are on an Agent call about this email. Review the recipient, message and attachments below. Enable a one-time phrase only when this saved version is ready to send, and keep this review open. Saving changes ends voice eligibility for this call; review the updated email and use Send.');
    const message = node('p');message.setAttribute('role','status');message.setAttribute('aria-live','polite');
    const phrase = node('strong');phrase.className = 'phone-agent-call-phrase';phrase.hidden = true;
    const enable = node('button','Enable voice approval');enable.type = 'button';
    const cancel = node('button','Cancel voice approval');cancel.type = 'button';cancel.hidden = true;
    const actions = node('div');actions.className = 'phone-agent-call-actions';actions.append(enable,cancel);
    box.append(heading,description,message,phrase,actions);root.insertBefore(box,thread);
    let call = null,review = null,snapshot = null,arming = null,epoch = 0,timer = null,expiryTimer = null,busy = false,terminal = '';
    const bridge = () => window.VP3EmailComposerV1?.voiceReview;
    const scopeMatches = value => value?.device_id === cfg.deviceId && value?.enrollment_revision === cfg.enrollmentRevision && value?.scope_revision === cfg.binding;
    async function callApi(action,fields = {},keepalive = false) {
      const request = new AbortController();let timedOut = false;
      const abort = () => request.abort();
      if (!keepalive) {if (life.signal.aborted) abort();else life.signal.addEventListener('abort',abort,{once:true});}
      const timeout = setTimeout(() => {timedOut = true;request.abort();},15000);
      try {
        const response = await fetch(local(cfg.callSettingsEndpoint),{method:'POST',credentials:'same-origin',cache:'no-store',
          signal:request.signal,...(keepalive ? {keepalive:true} : {}),headers:{'Content-Type':'application/json','X-VP3-Phone':'1'},
          body:JSON.stringify({action,device_id:cfg.deviceId,enrollment_revision:cfg.enrollmentRevision,scope_revision:cfg.binding,
            agent_id:cfg.agentId,conversation_id:cfg.conversationId,csrf_token:cfg.csrf,...fields})});
        const result = await response.json().catch(() => null);
        if (timedOut) throw new DOMException('Call approval timed out.','AbortError');
        if (!response.ok || result?.ok !== true || !scopeMatches(result.scope)) {
          if ([401,403,419].includes(response.status)) window.dispatchEvent(new Event('vp3:phone-agent-access-changed'));
          throw new Error(result?.message || 'Call approval changed. Review this email again before continuing.');
        }
        return result;
      } catch(error) {
        if (timedOut) throw new Error('Call approval did not respond in time. Review the email before enabling voice approval again.');
        throw error;
      } finally {clearTimeout(timeout);if (!keepalive) life.signal.removeEventListener('abort',abort);}
    }
    function checkedReview(value) {
      if (!/^[a-f0-9]{32}$/.test(value?.id || '') || !Number.isSafeInteger(value.revision) || value.revision < 1
        || !['armed','requested','claimed','completed','cancelled','expired'].includes(value.state)
        || typeof value.expires_at !== 'string' || !Number.isFinite(Date.parse(value.expires_at))) throw new Error('This voice approval could not be verified.');
      return value;
    }
    function paint() {
      box.hidden = !call && !review && !terminal;
      enable.hidden = Boolean(review || arming);cancel.hidden = !(review || arming);
      enable.disabled = busy || !call || !bridge()?.snapshot?.();cancel.disabled = false;
      if (arming && !terminal) message.textContent = 'Enabling voice approval for this exact saved email…';
      else if (!review && !terminal) message.textContent = call ? enable.disabled
        ? 'Save any changes and finish attachment work before enabling voice approval.' : 'Voice approval is off. Send remains available in the email editor.' : '';
      if (terminal) message.textContent = terminal;
    }
    function stop(text = '',notify = true) {
      clearTimeout(expiryTimer);expiryTimer = null;
      const old = review;epoch++;review = null;snapshot = null;arming = null;phrase.hidden = true;phrase.textContent = '';terminal = text;
      if (notify && old && ['armed','requested'].includes(old.state)) void callApi('voice_cancel',{review_id:old.id},true).catch(() => {});
      paint();
    }
    function watchExpiry(value) {
      clearTimeout(expiryTimer);
      expiryTimer = setTimeout(() => {
        if (review?.id === value.id) stop('Voice approval expired. Review the email before enabling it again.');
      },Math.max(0,Date.parse(value.expires_at)-Date.now()));
    }
    async function refresh() {
      if (closed || document.hidden || busy) return;
      const generation = epoch;busy = true;
      try {
        if (review) {
          if (!bridge()?.matches?.(snapshot) || Date.now() >= Date.parse(review.expires_at)) {stop('Voice approval expired or the email changed. Review the current saved version.');return;}
          const result = await callApi('voice_poll',{review_id:review.id});
          if (generation !== epoch || closed) return;
          const next = checkedReview(result.review);
          if (next.id !== review.id || next.revision < review.revision) throw new Error('This voice approval changed.');
          review = next;
          if (['armed','requested'].includes(next.state)) {
            if (Date.now() >= Date.parse(next.expires_at)) {stop('Voice approval expired. Review the email before enabling it again.');return;}
            watchExpiry(next);
          }
          if (next.state === 'requested') {
            if (!bridge()?.matches?.(snapshot) || document.hidden) {stop('The email changed before confirmation. Review it again.');return;}
            const exact = snapshot;const id = next.id;
            const claimed = await callApi('voice_claim',{review_id:id,review_token:exact.review_token});
            if (generation !== epoch || closed) return;
            const claim = checkedReview(claimed.review);
            if (claim.id !== id || claim.state !== 'claimed') throw new Error('This voice confirmation was already handled.');
            // Clear before dispatch. Polling, reconnecting or another callback cannot retry it.
            clearTimeout(expiryTimer);expiryTimer = null;review = null;snapshot = null;phrase.hidden = true;phrase.textContent = '';epoch++;
            if (!bridge()?.matches?.(exact) || document.hidden) {terminal = 'The review changed before sending. Review the email and use Send when ready.';return;}
            message.textContent = 'Sending the exact email confirmed during your call…';
            const sent = await bridge().send(exact,id);
            terminal = sent ? 'Gmail confirmed your reviewed email was sent.' : 'Check send status in the email editor. Voice approval will not retry this send.';
          } else if (['cancelled','expired'].includes(next.state)) stop('Voice approval is no longer active. Review the email to enable it again.',false);
          else if (['claimed','completed'].includes(next.state)) stop(next.state === 'completed'
            ? 'Gmail confirmed your reviewed email was sent.' : 'This confirmation was already handled. Check send status in the email editor.',false);
        } else {
          const result = await callApi('statuses',{item_refs:[cfg.itemRef]});
          if (generation !== epoch || closed) return;
          const candidate = result.active_call;
          call = candidate?.item_ref === cfg.itemRef && candidate.source_kind === 'gmail_draft'
            && candidate.state === 'submitted' && /^[a-f0-9]{32}$/.test(candidate.request_id || '')
            && /^[a-f0-9]{32}$/.test(candidate.call_id || '') ? candidate : null;
        }
      } catch(error) {
        if (error.name !== 'AbortError' && generation === epoch) {call = null;stop(error.message);}
      } finally {busy = false;paint();}
    }
    enable.addEventListener('click',async () => {
      if (busy || !call || document.hidden) return;
      const exact = bridge()?.snapshot?.();if (!exact) {paint();return;}
      const generation = ++epoch;arming = exact;busy = true;terminal = '';paint();
      try {
        const result = await callApi('voice_arm',{request_id:call.request_id,draft_id:exact.draft_id,
          review_token:exact.review_token,watch_replies:exact.watch_replies,confirmed:true});
        const armed = checkedReview(result.review);
        if (generation !== epoch || closed || document.hidden || !bridge()?.matches?.(exact)) {
          void callApi('voice_cancel',{review_id:armed.id},true).catch(() => {});return;
        }
        if (armed.state !== 'armed' || !/^send email [0-9](?: [0-9]){5}$/.test(result.challenge || '')) throw new Error('The confirmation phrase could not be verified.');
        if (Date.now() >= Date.parse(armed.expires_at)) {
          void callApi('voice_cancel',{review_id:armed.id},true).catch(() => {});
          throw new Error('Voice approval expired before it arrived. Review the email before enabling it again.');
        }
        snapshot = exact;review = armed;phrase.textContent = 'Say: “'+result.challenge+'”';phrase.hidden = false;
        watchExpiry(armed);
        message.textContent = 'This phrase applies only to this saved email and expires in two minutes or when the call ends.';
      } catch(error) {if (generation === epoch && error.name !== 'AbortError') stop(error.message);}
      finally {arming = null;busy = false;paint();}
    });
    cancel.addEventListener('click',() => stop('Voice approval cancelled. You can keep editing or use Send.'));
    for (const event of ['input','change','vp3:chat-email-drafts-changed','vp3:chat-email-watch-changed']) window.addEventListener(event,() => {
      queueMicrotask(() => {if (review && !bridge()?.matches?.(snapshot) || arming && !bridge()?.matches?.(arming)) stop('The email changed. Voice approval was cancelled.');paint();});
    },{signal:life.signal});
    window.addEventListener('vp3:email-call-review-invalidated',() => {
      if (review || arming) stop('The email changed. Voice approval was cancelled.');
      queueMicrotask(paint);
    },{signal:life.signal});
    document.addEventListener('visibilitychange',() => {
      if (document.hidden) stop('Voice approval paused when this review left the screen. Review it again to continue.');
      else {if (!review) {terminal = '';void refresh();}}
    },{signal:life.signal});
    window.addEventListener('vp3:phone-agent-access-changed',() => {stop('',false);call = null;box.hidden = true;},{signal:life.signal});
    window.addEventListener('pagehide',() => {clearTimeout(timer);stop('',false);box.remove();},{once:true});
    const tick = async () => {await refresh();if (!closed) timer = setTimeout(tick,3000);};void tick();
  }

  async function openAttention() {
    const result = await api(cfg.emailEndpoint,'attention_open',{item_ref:target.item_ref,generation:target.generation});
    const message = result.message;
    if (!message || typeof message.subject !== 'string' || typeof message.body !== 'string') throw new Error('This email is no longer available in the current Gmail review.');
    thread.hidden = true;content.hidden = false;
    content.append(node('p','Gmail · Important email'),node('h1',message.subject || 'Email'),node('p',message.from || ''));
    if (message.answered_in_gmail) content.append(node('p','This email has already been answered in Gmail.'));
    const reader = node('div');content.append(reader);
    const mounted = window.VP3GmailEmailDisplayV1?.mount?.(reader,message);
    if (!mounted) {const body = node('p',message.body);body.className = 'phone-agent-email-text';reader.append(body);}
    content.append(node('p','Use “Discuss with your Agent” to continue in your private Chat.'));
    report('');
  }

  async function openImage() {
    const result = await api(cfg.imageEndpoint,'get',{generation_id:target.generation_id});
    const view = result.generation || result.image || result.job;
    if (!view || view.id !== target.generation_id || view.status !== 'completed' || !Array.isArray(view.assets)) throw new Error('This saved image is unavailable.');
    thread.hidden = true;content.hidden = false;
    content.append(node('h1',view.title || cfg.title));
    const images = node('div');images.className = 'phone-agent-images';
    for (const asset of view.assets) {
      const url = local(asset?.url);
      if (!url || asset.available !== true || !['image/jpeg','image/png','image/webp'].includes(asset.mime_type)) continue;
      const figure = node('figure');const image = node('img');
      image.src = url;image.alt = asset.title || view.title || 'Generated image';image.decoding = 'async';
      const caption = node('figcaption',asset.title || 'Generated image');
      const download = node('a','Download image');download.href = url;download.download = '';download.rel = 'noreferrer';
      figure.append(image,caption,download);images.append(figure);
    }
    if (!images.children.length) throw new Error('The saved image file is currently unavailable.');
    content.append(images);report('');
  }

  async function openCanvas() {
    const runtime = window.VP3CanvasRuntimeV1;
    if (!runtime) throw new Error('The private Canvas runtime is unavailable.');
    const input = {canvas_id:target.canvas_id,revision:target.revision};
    const result = await api(cfg.canvasEndpoint,'open',input);
    if (result.canvas?.id !== target.canvas_id || result.revision?.id !== target.revision || !result.revision.artifact || !result.state) {
      throw new Error('This Canvas version changed. Return to Agent and reopen it.');
    }
    const commands = Array.isArray(result.revision.commands) ? result.revision.commands : [];
    const active = result.canvas.active_revision === target.revision;
    const grants = result.command_grants;
    const sources = {},links = new Map();
    let gmail = commands.includes('gmail') ? runtime.unavailableSource : null;
    let email = result.email_source ? runtime.unavailableSource : null;
    const reads = commands.filter(command => active && (command === 'gmail' ? grants?.gmail?.app_access === true
      : Object.hasOwn(runtime.sourceKeys,command) && (runtime.publicSourceKeys.includes(runtime.sourceKeys[command]) || grants?.sources?.[command] === true)));
    await Promise.all(reads.map(async command => {
      const reply = await api(cfg.canvasEndpoint,'command_data',{...input,command});
      if (command === 'gmail') gmail = reply.source;
      else if (runtime.sourceKeys[command]) {
        const normalized = runtime.normalizeSource(command,reply.source,cfg.basePath);
        sources[runtime.sourceKeys[command]] = normalized.payload;
        if (runtime.publicSourceKeys.includes(runtime.sourceKeys[command])) links.set(runtime.sourceKeys[command],new Map(normalized.links.map(row => [row.id,row.url])));
      }
    }));
    if (result.email_source && active) {
      const reply = await api(cfg.canvasEndpoint,'email_data',input);
      // Source receipts remain in the trusted owner host, never in generated app code.
      email = reply.email ? {...reply.email,rows:(reply.email.rows || []).map(({source_ref,...row}) => row)} : runtime.unavailableSource;
    }
    if (closed) return;
    const appData = grants?.app_data?.app_access === true;
    const canSave = appData && !result.email_source && !(commands.includes('gmail') && active && grants?.gmail?.app_access === true);
    thread.hidden = true;content.hidden = false;document.body.classList.add('phone-agent-canvas');
    const heading = node('header');heading.append(node('h1',result.canvas.title || cfg.title));
    if (!active) heading.append(node('p','Private preview · saved app data is unchanged'));
    const frame = node('iframe');frame.className = 'phone-agent-canvas-frame';frame.title = result.canvas.title || 'Private canvas';
    frame.setAttribute('sandbox','allow-scripts');frame.referrerPolicy = 'no-referrer';
    const key = cfg.nonce;
    frame.srcdoc = runtime.createDocument({channel:runtime.channel,nonce:key,parentOrigin:location.origin,
      artifact:result.revision.artifact,state:appData ? result.state.value : {},workerKey:runtime.token(),
      maxText:runtime.maxText,stateLimit:runtime.stateLimit,canSaveState:canSave,email,gmail,sources,
      libraryImage:result.library_image?.image,fullPage:true,
      openableSources:Object.fromEntries([...links].map(([kind,records]) => [kind,[...records.keys()]]))});
    canvas = {frame,key,ready:false,stateRevision:result.state.revision};
    const timeout = setTimeout(() => {if (canvas?.frame === frame && !canvas.ready) {frame.remove();report('This Canvas did not finish loading. Reopen it from Agent.');}},15000);
    const receive = async event => {
      if (closed || canvas?.frame !== frame || event.source !== frame.contentWindow || event.origin !== 'null'
        || event.data?.channel !== runtime.channel || event.data.nonce !== key) return;
      const eventData = event.data;
      if (eventData.kind === 'ready') {canvas.ready = true;clearTimeout(timeout);report('');}
      if (eventData.kind === 'error') {clearTimeout(timeout);frame.remove();report('This Canvas could not run. Open its original Chat to review it.');}
      if (eventData.kind === 'open_email') report('Open the original Chat to review this Canvas’s email source.');
      if (eventData.kind === 'open_source' && canvas.ready && document.hasFocus() && !document.hidden) {
        const url = local(links.get(eventData.command)?.get(eventData.id));
        if (url) window.open(url,'_blank','noopener,noreferrer');
      }
      if (eventData.kind !== 'save') return;
      const respond = (value,error) => {if (canvas?.frame === frame) frame.contentWindow?.postMessage({channel:runtime.channel,nonce:key,kind:'saved',id:eventData.id,value,error},'*');};
      if (!canvas.ready || !canSave || saving || !Number.isSafeInteger(eventData.id)
        || typeof eventData.json !== 'string' || eventData.json.length > runtime.stateLimit) {respond(null,'This Canvas cannot save data right now.');return;}
      saving = true;
      try {
        const value = JSON.parse(eventData.json);
        if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Canvas data must be a JSON object.');
        if (!active) {respond(value);return;}
        const saved = await api(cfg.canvasEndpoint,'save_state',{...input,state_revision:canvas.stateRevision,state:value});
        if (canvas?.frame !== frame) return;
        canvas.stateRevision = saved.state.revision;respond(saved.state.value);changed();
      } catch(error) {respond(null,error.message);report(error.message);}
      finally {saving = false;}
    };
    window.addEventListener('message',receive,{signal:life.signal});
    content.replaceChildren(heading,frame);
  }
  document.addEventListener('DOMContentLoaded',async () => {
    try {
      if (cfg.kind === 'gmail_draft') {
        if (!window.VP3EmailComposerV1) throw new Error('The Gmail review view is unavailable.');
        report('');
        await window.VP3EmailComposerV1.openDraft(target,{surface:'canvas'});
        mountCallReview();
      } else if (cfg.kind === 'gmail_attention') await openAttention();
      else if (cfg.kind === 'image') await openImage();
      else if (cfg.kind === 'canvas') await openCanvas();
      else throw new Error('This item type is unavailable.');
    } catch(error) {if (error.name !== 'AbortError') report(error.message || 'This private item could not open.');}
    finally {post('ready');}
  },{once:true});
})();
