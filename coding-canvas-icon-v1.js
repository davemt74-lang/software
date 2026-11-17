(() => {
  'use strict';

  const canvasConfig = window.VP3_CODING_CANVAS_V1;
  if (!canvasConfig?.csrf || window.VP3CanvasIconV1) return;

  function localUrl(input) {
    if (typeof input !== 'string' || !input) return null;
    try {
      const target = new URL(input, location.href);
      return target.origin === location.origin && !target.username && !target.password ? target : null;
    } catch { return null; }
  }

  const iconEndpoint = localUrl(canvasConfig.iconEndpoint);
  const artworkEndpoint = localUrl(canvasConfig.artworkEndpoint);
  if (!iconEndpoint || !artworkEndpoint) return;

  const pending = new Map();
  const processed = new Set();
  const continuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 ?? window.STONEFELLOW_CHAT_CONTINUITY;
  const current = context => {
    const session = window.STONEFELLOW_CHAT;
    return context.ownerId === Number(session?.userId) && context.agentId === Number(session?.agentId ?? 0) &&
      context.agentId === Number(continuity()?.agentId?.()) && context.conversationId === Number(continuity()?.conversationId?.()) &&
      context.binding === String(session?.workingAttentionBinding ?? '') && !window.VP3ParticipantContinuity?.selectedContext?.();
  };

  async function send(endpoint, action, context, extra = {}) {
    const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', cache:'no-store',
      headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action, csrf_token:canvasConfig.csrf, canvas_id:context.canvasId,
        ...(endpoint === iconEndpoint ? {owner_id:context.ownerId} : {}),
        revision:context.revision, conversation_id:context.conversationId, agent_id:context.agentId, ...extra,
      }),
    });
    const body = await response.json().catch(() => null);
    if (!response.ok || body?.ok !== true) throw new Error(body?.error || 'The app icon could not be generated.');
    return body;
  }

  async function squarePng(asset) {
    const src = localUrl(asset.image_url);
    if (!src || !src.pathname.endsWith('/media-library-file-v86.php') ||
        !Number.isSafeInteger(asset.asset_id) || asset.asset_id < 1 ||
        src.searchParams.get('id') !== String(asset.asset_id)) {
      throw new Error('The generated icon file could not be verified.');
    }
    const response = await fetch(src, {credentials:'same-origin', cache:'no-store', redirect:'error'});
    if (!response.ok || response.headers.get('content-type')?.split(';')[0] !== 'image/png') throw new Error('The icon image is unavailable.');
    const png = await response.blob();
    if (png.size < 24 || png.size > 25 * 1024 * 1024) throw new Error('The icon image has an invalid size.');
    const assetUrl = URL.createObjectURL(png);
    try {
      const image = new Image();
      image.src = assetUrl;
      await image.decode();
      if (image.naturalWidth !== 1024 || image.naturalHeight !== 1024) throw new Error('The icon image must be square.');
      const output = document.createElement('canvas');
      output.width = output.height = 128;
      const context = output.getContext('2d');
      if (!context) throw new Error('The browser could not prepare the icon.');
      context.drawImage(image, 0, 0, 128, 128);
      const data = output.toDataURL('image/png');
      if (!data.startsWith('data:image/png;base64,')) throw new Error('The browser could not prepare the icon.');
      return data.slice('data:image/png;base64,'.length);
    } finally {
      URL.revokeObjectURL(assetUrl);
    }
  }

  const wait = milliseconds => new Promise(resolve => setTimeout(resolve,milliseconds));
  const exact = (state,scope) => state?.owner_id === scope.ownerId && state.agent_id === scope.agentId &&
    state.conversation_id === scope.conversationId && state.canvas_id === scope.canvasId && state.revision === scope.revision;

  async function create(input,replaceHash = '') {
    if (!input || typeof input.canvasId !== 'string' ||
        !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(input.canvasId) ||
        !Number.isSafeInteger(input.revision) || input.revision < 1 || !Number.isSafeInteger(input.conversationId) ||
        input.conversationId < 1 || !Number.isSafeInteger(input.ownerId) || input.ownerId < 1 ||
        !Number.isSafeInteger(input.agentId) || input.agentId < 0 || (replaceHash && !/^[a-f0-9]{64}$/.test(replaceHash))) return false;
    const scope = {...input,binding:String(window.STONEFELLOW_CHAT?.workingAttentionBinding ?? '')};
    if (!current(scope)) return false;
    const key = [scope.ownerId,scope.agentId,scope.canvasId,replaceHash].join(':');
    if (pending.has(key)) return pending.get(key);
    if (!replaceHash && processed.has(key)) return false;
    const replace = replaceHash ? {replace_hash:replaceHash} : {};
    const request = (async () => {
      try {
        let state = await send(iconEndpoint,'run',scope,replace);
        const started = Date.now();
        while (current(scope) && exact(state,scope) && ['queued','generating'].includes(state.status) && Date.now() - started < 135000) {
          await wait(3000);
          if (!current(scope)) return false;
          state = await send(iconEndpoint,'status',scope,replace);
        }
        if (!current(scope) || !exact(state,scope)) return false;
        if (state.status === 'saved') {
          processed.add(key);
          return true;
        }
        if (state.status !== 'completed') {
          if (state.status === 'unavailable') processed.add(key);
          return false;
        }
        const image = await squarePng(state);
        if (!current(scope)) return false;
        const saved = await send(artworkEndpoint,'save_artwork',scope,{kind:'icon',image_base64:image,...replace});
        if (!current(scope) || saved.canvas?.id !== scope.canvasId || typeof saved.canvas.icon_url !== 'string') return false;
        processed.add(key);
        window.dispatchEvent(new CustomEvent('vp3:canvas-artwork-updated',{detail:{
          canvasId:scope.canvasId,revision:scope.revision,agentId:scope.agentId,ownerId:scope.ownerId,
          conversationId:scope.conversationId,binding:scope.binding,artwork:saved.canvas,
        }}));
        return true;
      } catch {
        return false;
      } finally {
        pending.delete(key);
      }
    })();
    pending.set(key,request);
    return request;
  }

  window.addEventListener('vp3:canvas-preview-tested', event => { void create(event.detail); });
  window.VP3CanvasIconV1 = Object.freeze({ regenerate:(context,hash) => hash ? create(context,hash) : Promise.resolve(false) });
})();
