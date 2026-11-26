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
    imageEndpoint:cfg.imageEndpoint,connectionsUrl:cfg.connectionsUrl};
  function changed() {post('changed');queueMicrotask(() => post('dirty',{value:Boolean(window.VP3EmailComposerV1?.isDirty?.())}));}
  for (const name of ['vp3:chat-email-drafts-changed','vp3:chat-email-watch-changed','vp3:email-followthrough-changed']) window.addEventListener(name,changed);
  for (const name of ['input','change']) document.addEventListener(name,() => queueMicrotask(() => post('dirty',{value:Boolean(window.VP3EmailComposerV1?.isDirty?.())})));
  window.addEventListener('message',async event => {
    if (closed || event.source !== window.parent || event.origin !== location.origin || event.data?.channel !== cfg.channel
      || event.data.type !== 'request_close' || !Number.isSafeInteger(event.data.id)) return;
    const allowed = !saving && (cfg.kind !== 'gmail_draft' || await window.VP3EmailComposerV1?.requestClose?.() === true);
    post('close_result',{id:event.data.id,allowed});
  });
  window.addEventListener('pagehide',() => {closed = true;life.abort();clearPrivate();},{once:true});

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
      } else if (cfg.kind === 'gmail_attention') await openAttention();
      else if (cfg.kind === 'image') await openImage();
      else if (cfg.kind === 'canvas') await openCanvas();
      else throw new Error('This item type is unavailable.');
    } catch(error) {if (error.name !== 'AbortError') report(error.message || 'This private item could not open.');}
    finally {post('ready');}
  },{once:true});
})();
