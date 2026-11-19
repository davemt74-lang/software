(() => {
  'use strict';
  const host = document.getElementById('publicCanvas');
  const status = document.getElementById('publicCanvasStatus');
  const data = document.getElementById('publicCanvasData');
  const nonce = document.currentScript?.nonce;
  const runtime = window.VP3CanvasRuntimeV1;
  if (!host || !status || !data) return;

  function message(text) {
    status.textContent = text;
    status.hidden = !text;
  }

  let payload;
  try { payload = JSON.parse(data.textContent); }
  catch (_error) { message('This Canvas app could not start.'); return; }
  data.remove();
  if (!runtime || !/^[a-f0-9]{48}$/.test(nonce ?? '') || !payload?.artifact) {
    message('This Canvas app could not start.');
    return;
  }

  const {channel,token,maxText,stateLimit,sourceKeys,publicSourceKeys,normalizeSource,createDocument} = runtime;
  const sources = {};
  const links = new Map();
  for (const [command,value] of Object.entries(payload.sources ?? {})) {
    const key = sourceKeys[command];
    if (!publicSourceKeys.includes(key)) continue;
    const source = normalizeSource(command,value,typeof payload.basePath === 'string' ? payload.basePath : '');
    sources[key] = source.payload;
    links.set(key,new Map(source.links.map(row => [row.id,row.url])));
  }

  const frame = document.createElement('iframe');
  frame.title = document.title;
  frame.className = 'public-canvas-frame';
  frame.setAttribute('sandbox','allow-scripts');
  frame.referrerPolicy = 'no-referrer';
  frame.srcdoc = createDocument({channel,nonce,parentOrigin:location.origin,artifact:payload.artifact,
    state:{},workerKey:token(),maxText,stateLimit,canSaveState:false,sources,fullPage:payload.fullPage === true,
    openableSources:Object.fromEntries([...links].map(([key,records]) => [key,[...records.keys()]]))});

  let ready = false;
  function fail() {
    clearTimeout(timer);
    window.removeEventListener('message',receive);
    frame.remove();
    message('This Canvas app could not load. Refresh to try again.');
  }
  function receive(event) {
    if (event.source !== frame.contentWindow || event.origin !== 'null' || event.data?.channel !== channel ||
      event.data?.nonce !== nonce) return;
    if (event.data.kind === 'error') fail();
    if (event.data.kind === 'ready' && !ready) {
      ready = true;
      clearTimeout(timer);
      message('');
    }
    if (event.data.kind === 'open_source' && ready && !document.hidden && document.hasFocus()) {
      const url = links.get(event.data.command)?.get(event.data.id);
      if (url) location.assign(url);
    }
  }

  message('Loading app…');
  const timer = setTimeout(fail,10000);
  window.addEventListener('message',receive);
  host.append(frame);
})();
