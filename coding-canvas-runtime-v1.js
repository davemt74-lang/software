(() => {
  'use strict';
  const channel = 'vp3.coding.canvas.v1';
  const stateLimit = 65536;
  const maxText = 32768;
  const sourceKeys = Object.freeze({music:'music',calendar:'calendar',products:'products',
    'public-products':'publicProducts','public-events':'publicEvents','public-booking':'publicBooking',
    'public-campaigns':'publicCampaigns','public-rewards':'publicRewards','public-media':'publicMedia'});
  const publicSourceKeys = Object.freeze(['publicProducts','publicEvents','publicBooking','publicCampaigns','publicRewards','publicMedia']);
  const unavailableSource = Object.freeze({status:'unavailable',rows:Object.freeze([])});
  const token = () => Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2,'0')).join('');
  const scriptData = value => JSON.stringify(value).replaceAll('<','\\u003c');
  function normalizeLibraryImage(image) {
    if (image?.available !== true || typeof image.title !== 'string' || image.title.length > 380 ||
      typeof image.data !== 'string' || image.data.length > 5592432
      || !/^data:image\/(?:jpeg|png|webp);base64,[a-z0-9+/]+={0,2}$/i.test(image.data)) return null;
    return {title:image.title,data:image.data};
  }

  function normalizeSource(command,value,basePath = '') {
    const unavailable = {payload:unavailableSource,links:[]};
    const publicCommand = command.startsWith('public-');
    const maxRows = command === 'calendar' ? 40 : publicCommand ? 24 : 30;
    if (!Object.hasOwn(sourceKeys,command) || !(value?.status === 'ready' || !publicCommand && value?.status === 'limited') ||
      !Array.isArray(value.rows) || value.rows.length > maxRows) {
      return unavailable;
    }
    const textFields = {
      music:['title','album','genre','visibility','played_at'],
      calendar:['title','start_at_utc','end_at_utc','timezone','recurrence'],
      products:['title','description','product_type','currency'],
      'public-products':['title','description','price_label','currency'],
      'public-events':['title','description','starts_at','location','status'],
      'public-booking':['title','description','timezone'],
      'public-campaigns':['title','description','merchant_name','cta_label','starts_at','ends_at'],
      'public-rewards':['title','description','value_label','campaign_title'],
      'public-media':['title','description','collection'],
    }[command];
    const flag = {music:'is_published',calendar:'all_day',products:'is_active'}[command];
    const quantity = {products:'price_cents','public-products':'price_cents','public-booking':'duration_minutes'}[command];
    const rows = [];
    const links = [];
    const ids = new Set();
    for (const row of value.rows) {
      const validId = command === 'products' ? Number.isSafeInteger(row?.id) && row.id > 0 :
        typeof row?.id === 'string' && /^[A-Za-z0-9_.:@-]{1,128}$/.test(row.id);
      if (!validId || ids.has(row.id)) return unavailable;
      const record = {id:row.id};
      ids.add(row.id);
      for (const key of textFields) {
        if (typeof row[key] === 'string') record[key] = row[key].slice(0,600);
      }
      if (flag && typeof row[flag] === 'boolean') record[flag] = row[flag];
      if (quantity && Number.isSafeInteger(row[quantity]) && row[quantity] >= 0) record[quantity] = row[quantity];
      if (command === 'calendar' && Number.isSafeInteger(row.event_id) && row.event_id > 0) record.event_id = row.event_id;
      if (command === 'music') {
        if (row.track_id === null || Number.isSafeInteger(row.track_id) && row.track_id > 0) record.track_id = row.track_id;
        for (const key of ['position_seconds','duration_seconds']) {
          if (Number.isFinite(row[key]) && row[key] >= 0) record[key] = row[key];
        }
      }
      const url = publicLink(command,row.url,basePath);
      if (url) links.push({id:row.id,url});
      rows.push(record);
    }
    return {payload:{status:value.status,rows},links};
  }

  function publicLink(command,href,basePath) {
    const routes = {
      'public-products':/^\/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]\/product\/[a-z0-9-]{1,80}$/,
      'public-events':/^\/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]$/,
      'public-booking':/^\/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]\/book\/[a-z0-9-]{1,80}$/,
      'public-campaigns':/^\/campaign\/[a-z0-9-]{1,80}$/,
      'public-rewards':/^\/campaign\/[a-z0-9-]{1,80}$/,
      'public-media':/^\/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]$/,
    };
    if (!Object.hasOwn(routes,command) || typeof href !== 'string' || href.length > 650 || !href.startsWith('/') ||
      href.startsWith('//') || href.includes('\\') || /[\x00-\x20\x7f]/.test(href)) return null;
    try {
      const target = new URL(href,location.origin);
      if (target.origin !== location.origin || target.username || target.password || target.search || target.hash ||
        target.pathname !== href || !target.pathname.startsWith(basePath + '/')) return null;
      return routes[command].test(target.pathname.slice(basePath.length)) ? target.pathname : null;
    } catch (_error) { return null; }
  }

  // This function runs in the trusted opaque iframe, with no app JavaScript on its DOM thread.
  function hostRuntime(payload, workerBootstrap, captureThumbnail, workflowBootstrap) {
    'use strict';
    const {channel,nonce,parentOrigin,artifact,state,workerKey,maxText,stateLimit,email,emailCheck,gmail,canSaveState,
      sources,openableSources,libraryImage} = payload;
    const clickable = new Set(['publicProducts','publicEvents','publicBooking','publicCampaigns','publicRewards','publicMedia']);
    const root = document.getElementById('app');
    root.inert = true;
    const appImage = libraryImage ? document.createElement('img') : null;
    if (appImage) {
      appImage.className = 'vp3-canvas-library-image';
      appImage.alt = libraryImage.title;
      appImage.decoding = 'async';
      appImage.src = libraryImage.data;
    }
    function placeLibraryImage() {
      if (!appImage) return;
      const slot = root.querySelector('div[data-canvas-library-image],section[data-canvas-library-image]');
      if (slot && appImage.parentNode !== slot) slot.append(appImage);
      else if (!slot && appImage.parentNode !== root) root.prepend(appImage);
    }
    const tags = new Set('h1 h2 h3 h4 h5 h6 div span p section article header footer main nav aside button input select optgroup option textarea label fieldset legend ul ol li dl dt dd table thead tbody tfoot tr td th caption details summary strong b em i small code pre kbd blockquote hr br output progress meter'.split(' '));
    const attributes = new Set('id class name value type checked disabled placeholder for min max step selected multiple hidden tabindex title role colspan rowspan open maxlength minlength rows cols readonly required autocomplete'.split(' '));
    const inputTypes = new Set('text search number date datetime-local time range color checkbox radio email url tel password month week'.split(' '));
    const events = ['click','input','change'];
    const listeners = new Map();
    const issued = new Map();
    const pending = new Set();
    let healthy = true;
    let started = false;
    let worker;
    let appUrl;
    let workerUrl;
    let monitor = 0;
    let heartbeat = Date.now();
    let windowStarted = heartbeat;
    let operations = 0;
    const send = (kind,data = {}) => parent.postMessage({channel,nonce,kind,...data},parentOrigin);
    const reply = (kind,data = {}) => worker?.postMessage({key:workerKey,kind,...data});
    const workflow=payload.workflowMode&&typeof workflowBootstrap==='function'?workflowBootstrap(root,{
      send,dispatch:dispatchControl,select,pending,healthy:()=>healthy,ready:()=>started&&!root.inert,mode:payload.workflowMode,
    }):null;
    const releaseUrls = () => {
      if (appUrl) URL.revokeObjectURL(appUrl);
      if (workerUrl) URL.revokeObjectURL(workerUrl);
      appUrl = workerUrl = null;
    };
    const fault = reason => {
      if (!healthy) return;
      healthy = false;
      clearInterval(monitor);
      worker?.terminate();
      releaseUrls();
      root.inert = true;
      send('error',{reason});
    };

    function markup(source) {
      const template = document.createElement('template');
      template.innerHTML = source;
      let count = 0;
      for (const el of template.content.querySelectorAll('*')) {
        if (++count > 1500) throw new Error('Canvas markup is too large.');
        if (!tags.has(el.localName) || el.namespaceURI !== 'http://www.w3.org/1999/xhtml') {
          el.remove();
          continue;
        }
        for (const attr of [...el.attributes]) {
          if (!attributes.has(attr.name) && !/^(?:aria|data)-[a-z][a-z0-9_-]*$/.test(attr.name)) el.removeAttribute(attr.name);
        }
        if (el.localName === 'button') el.type = 'button';
        if (el.localName === 'input' && !inputTypes.has(el.type)) el.type = 'text';
      }
      return template.content;
    }

    function select(selector) {
      if (typeof selector !== 'string' || !selector || selector.length > 200) throw new Error('Invalid canvas selector.');
      return [...root.querySelectorAll(selector)];
    }

    function handleWorker(event) {
      const data = event.data;
      if (!healthy || data?.key !== workerKey) return;
      if (Date.now() - windowStarted > 1000) { windowStarted = Date.now(); operations = 0; }
      if (++operations > 200) { fault('render'); return; }
      if (data.kind === 'heartbeat') heartbeat = Date.now();
      if (data.kind === 'error') fault(data.reason);
      if (data.kind === 'event_done') workflow?.done(data.gesture,data.id);
      if (data.kind === 'ready' && !started) {
        started = true;
        releaseUrls();
        setTimeout(() => {
          if (!healthy) return;
          if (emailCheck) {
            const rendered = root.innerText;
            const first = rendered.indexOf(emailCheck[0]);
            if (first < 0 || rendered.indexOf(emailCheck[1]) <= first || rendered.includes(emailCheck[2])) {
              send('email_test_failed');
              fault('render');
              return;
            }
          }
          if (typeof captureThumbnail === 'function') {
            send('thumbnail',{imageBase64:captureThumbnail(root)});
            clearInterval(monitor);
            worker.terminate();
            return;
          }
          send('ready',{emailWorkflowChecked:Boolean(emailCheck)});
          root.inert = false;
        },200);
      }
      if (data.kind === 'on' && events.includes(data.event) && Number.isSafeInteger(data.id) && data.id > 0 && listeners.size < 120) {
        select(data.selector);
        listeners.set(data.id,{selector:data.selector,event:data.event});
      }
      if (data.kind === 'render') {
        const nodes = select(data.selector);
        if (typeof data.value === 'string' && data.value.length * nodes.length > maxText) throw new Error('Canvas render is too large.');
        if (data.op === 'text' && typeof data.value === 'string' && data.value.length <= maxText) {
          nodes.forEach(el => { el.textContent = data.value; });
        } else if (data.op === 'html' && typeof data.value === 'string' && data.value.length <= maxText) {
          const fragment = markup(data.value);
          if (nodes.length > 1) throw new Error('Canvas HTML requires one target.');
          nodes[0]?.replaceChildren(fragment);
        } else if (data.op === 'value' && typeof data.value === 'string' && data.value.length <= maxText) {
          nodes.forEach(el => { if (['input','select','textarea','output'].includes(el.localName)) el.value = data.value; });
        } else if (data.op === 'checked' && typeof data.value === 'boolean') {
          nodes.forEach(el => { if (el.localName === 'input') el.checked = data.value; });
        } else if (data.op === 'show' && typeof data.value === 'boolean') {
          nodes.forEach(el => { el.hidden = !data.value; });
        }
        placeLibraryImage();
        workflow?.changed();
        let contentLength = root.textContent.length;
        for (const control of root.querySelectorAll('input,select,textarea,output')) contentLength += control.value.length;
        if (contentLength > 3 * maxText || root.querySelectorAll('*').length > 1500) throw new Error('Canvas markup is too large.');
      }
      if (data.kind === 'save' && Number.isSafeInteger(data.id) && data.id > 0 && typeof data.gesture === 'string') {
        const expires = issued.get(data.gesture)?.expires;
        if (expires) issued.delete(data.gesture);
        if (!canSaveState || !expires || expires < Date.now() || pending.size || typeof data.json !== 'string' || data.json.length > stateLimit) {
          reply('saved',{id:data.id,error:'Save must follow an app interaction and fit in the canvas data limit.'});
          return;
        }
        pending.add(data.id);
        send('save',{id:data.id,json:data.json});
      }
      if (data.kind === 'open_email' && email?.status === 'ready' && typeof data.gesture === 'string') {
        const expires = issued.get(data.gesture)?.expires;
        if (expires) issued.delete(data.gesture);
        if (expires >= Date.now() && email.rows.some(row => row.id === data.id)) send('open_email',{id:data.id});
      }
      if (data.kind === 'open_source' && clickable.has(data.command) && openableSources?.[data.command]?.includes(data.id) &&
        sources?.[data.command]?.status === 'ready' && typeof data.gesture === 'string') {
        const activation = issued.get(data.gesture);
        if (activation) issued.delete(data.gesture);
        if (activation?.type === 'click' && activation.expires >= Date.now() &&
          sources[data.command].rows.some(row => row.id === data.id)) send('open_source',{command:data.command,id:data.id});
      }
    }

    try {
      const style = document.createElement('style');
      style.nonce = nonce;
      style.textContent = artifact.css;
      document.head.append(style);
      root.append(markup(artifact.html));
      placeLibraryImage();

      // Load the generated source separately so it cannot change the syntax of the trusted runtime.
      appUrl = URL.createObjectURL(new Blob(['"use strict";\n',artifact.js],{type:'text/javascript'}));
      const workerSource = '(' + workerBootstrap.toString() + ')(' + JSON.stringify(workerKey) + ',' +
        JSON.stringify(JSON.stringify(state)) + ',' + JSON.stringify(appUrl) + ',' + maxText + ',' + stateLimit + ',' +
        JSON.stringify(JSON.stringify(email ?? null)) + ',' + JSON.stringify(JSON.stringify(gmail ?? null)) + ',' +
        JSON.stringify(canSaveState === true) + ',' + JSON.stringify(JSON.stringify(sources ?? {})) + ',' +
        JSON.stringify(JSON.stringify(openableSources ?? {})) + ','+JSON.stringify(Boolean(workflow))+');';
      workerUrl = URL.createObjectURL(new Blob([workerSource],{type:'text/javascript'}));
      try { worker = new Worker(workerUrl); }
      catch (_error) { fault('worker_start'); return; }
      worker.addEventListener('message',event => {
        try { handleWorker(event); } catch (_error) { fault('render'); }
      });
      worker.addEventListener('error',event => { event.preventDefault(); fault(started ? 'source_runtime' : 'worker_start'); });
      worker.addEventListener('messageerror',() => fault('render'));
      document.addEventListener('visibilitychange',() => { heartbeat = Date.now(); });
      window.addEventListener('pagehide',releaseUrls,{once:true});
      monitor = setInterval(() => { if (!document.hidden && Date.now() - heartbeat > 3500) fault('unresponsive'); },1000);
      root.addEventListener('submit',event => event.preventDefault());
      for (const type of events) {
        root.addEventListener(type,event => {
          if (!healthy || !started || !event.isTrusted || !(event.target instanceof Element)) return;
          workflow?.record(event.target,type);
          dispatchControl(event.target,type);
        });
      }
      addEventListener('message',event => {
        if (event.source !== parent || event.origin !== parentOrigin || event.data?.channel !== channel || event.data?.nonce !== nonce) return;
        const {id,kind,value,error} = event.data;
        if (kind === 'saved' && pending.delete(id)) {workflow?.saved(value,error);reply('saved',{id,value,error});}
        if (kind === 'workflow_run' && payload.workflowMode==='check') void workflow?.run(event.data);
        if (kind === 'workflow_record_stop' && payload.workflowMode==='record') send('workflow_record',{steps:workflow?.stopRecord()??[]});
      });
    } catch (_error) {
      fault('render');
    }
    function dispatchControl(target,type) {
      for(const [id,activation] of issued)if(activation.expires<Date.now())issued.delete(id);
      const gesture=crypto.randomUUID?.()||Array.from(crypto.getRandomValues(new Uint32Array(4))).join('-');
      issued.set(gesture,{expires:Date.now()+1500,type});if(issued.size>50)issued.delete(issued.keys().next().value);
      const ids=[];
      for(const [id,entry] of listeners){
        if(entry.event!==type)continue;
        const el=target.closest(entry.selector);if(!el||!root.contains(el))continue;
        ids.push(id);reply('event',{id,gesture,event:{value:String(el.value??'').slice(0,maxText),checked:Boolean(el.checked),id:el.id,dataset:{...el.dataset}}});
      }
      return {gesture,ids};
    }
  }

  // This function is the only code prepended to generated worker source.
  function workerRuntime(key,initialState,appUrl,maxText,stateLimit,emailSource,gmailSource,canSaveState,sourceData,sourceControls,workflowEnabled) {
    'use strict';
    let state = JSON.parse(initialState);
    const email = JSON.parse(emailSource);
    const gmail = JSON.parse(gmailSource);
    const records = JSON.parse(sourceData);
    const openable = JSON.parse(sourceControls);
    const post = postMessage.bind(self);
    const loadApp = importScripts.bind(self);
    const clone = structuredClone;
    const encode = JSON.stringify;
    const encoder = new TextEncoder();
    const utf8 = encoder.encode.bind(encoder);
    const events = new Set(['click','input','change']);
    const callbacks = new Map();
    const saves = new Map();
    let nextId = 0;
    let gesture = '';
    const clock = performance.now.bind(performance);
    const stopWorker = close.bind(self);
    let windowStart = clock();
    let messages = 0;
    let stopped = false;
    const send = (kind,data = {}) => {
      if (stopped) return;
      const now = clock();
      if (now - windowStart > 1000) {
        messages = 0;
        windowStart = now;
      }
      if (++messages > 180) {
        stopped = true;
        post({key,kind:'error',reason:'render'});
        stopWorker();
        return;
      }
      post({key,kind,...data});
    };

    // Restrict ambient capabilities in addition to the inherited worker CSP.
    for (const name of ['fetch','XMLHttpRequest','WebSocket','EventSource','WebTransport','Worker','SharedWorker','BroadcastChannel','importScripts','postMessage','indexedDB','caches']) {
      const disabled = {value:undefined,writable:false,configurable:false};
      for (let target = self; target; target = Object.getPrototypeOf(target)) {
        if (target !== self && !Object.hasOwn(target,name)) continue;
        try { Object.defineProperty(target,name,disabled); } catch (_error) {}
      }
    }
    addEventListener('error',() => send('error',{reason:'source_runtime'}));
    addEventListener('unhandledrejection',() => send('error',{reason:'source_runtime'}));
    addEventListener('message',event => {
      const data = event.data;
      if (data?.key !== key) return;
      if (data.kind === 'event') {
        gesture = data.gesture;
        try {
          const result = callbacks.get(data.id)?.(data.event);
          if(workflowEnabled)Promise.resolve(result).then(()=>send('event_done',{gesture:data.gesture,id:data.id}),()=>send('error',{reason:'source_runtime'}));
          else if(result?.catch)result.catch(()=>send('error',{reason:'source_runtime'}));
        } catch (_error) {
          send('error',{reason:'source_runtime'});
        } finally {
          gesture = '';
        }
      }
      if (data.kind === 'saved') {
        const pending = saves.get(data.id);
        if (!pending) return;
        saves.delete(data.id);
        if (data.error) pending.reject(new Error(data.error));
        else {
          state = data.value;
          pending.resolve(clone(state));
        }
      }
    });

    const validSelector = selector => typeof selector === 'string' && selector.length > 0 && selector.length <= 200;
    const render = (op,type) => (selector,value) => {
      if (!validSelector(selector) || typeof value !== type || (type === 'string' && value.length > maxText)) {
        throw new TypeError('Invalid canvas render.');
      }
      send('render',{op,selector,value});
    };
    const ui = Object.freeze({
      on(selector,event,callback) {
        if (!validSelector(selector) || typeof event !== 'string' || event.length > 6 || !events.has(event) ||
          typeof callback !== 'function' || callbacks.size >= 120) throw new TypeError('Invalid canvas event handler.');
        const id = ++nextId;
        callbacks.set(id,callback);
        send('on',{selector,event,id});
      },
      text:render('text','string'), html:render('html','string'), value:render('value','string'),
      checked:render('checked','boolean'), show:render('show','boolean')
    });
    const data = Object.freeze({
      get:() => clone(state),
      save(next) {
        if (!canSaveState) return Promise.reject(new Error('App data is read-only for this Canvas.'));
        if (!gesture || !next || typeof next !== 'object' || Array.isArray(next)) return Promise.reject(new Error('Use a canvas interaction to save a data object.'));
        return new Promise((resolve,reject) => {
          const id = ++nextId;
          const json = encode(next);
          if (typeof json !== 'string' || json.length > stateLimit || utf8(json).byteLength > stateLimit) {
            reject(new Error('App data is limited to 64 KiB.'));
            return;
          }
          send('save',{id,json,gesture});
          saves.set(id,{resolve,reject});
          gesture = '';
        });
      }
    });
    setInterval(() => send('heartbeat'),700);
    Object.defineProperties(self,{CanvasUI:{value:ui},CanvasData:{value:data}});
    const sources = {};
    if (email) {
      sources.email = Object.freeze({
        get:() => clone(email),
        open(id) {
          if (!gesture || typeof id !== 'string' || !email.rows.some(row => row.id === id)) return false;
          send('open_email',{id,gesture});
          gesture = '';
          return true;
        }
      });
    }
    if (gmail) sources.gmail = Object.freeze({get:() => clone(gmail)});
    for (const command of ['music','calendar','products','publicProducts','publicEvents','publicBooking','publicCampaigns','publicRewards','publicMedia']) {
      if (!Object.hasOwn(records,command)) continue;
      const source = records[command];
      const api = {get:() => clone(source)};
      if (command.startsWith('public') && Object.hasOwn(openable,command)) {
        api.open = id => {
          if (!gesture || typeof id !== 'string' || !openable[command].includes(id)) return false;
          send('open_source',{command,id,gesture});
          gesture = '';
          return true;
        };
      }
      sources[command] = Object.freeze(api);
    }
    if (Object.keys(sources).length) Object.defineProperty(self,'CanvasSources',{value:Object.freeze(sources)});
    Object.defineProperty(URL,'createObjectURL',{value:undefined});
    try { loadApp(appUrl); }
    catch (error) {
      send('error',{reason:error?.name === 'SyntaxError' ? 'source_syntax' : 'source_startup'});
      stopWorker();
      return;
    }
    send('ready');
  }

  function createDocument(payload,capture) {
    const nonce = payload.nonce;
    const libraryImage = normalizeLibraryImage(payload.libraryImage);
    const csp = "default-src 'none'; script-src 'nonce-" + nonce + "' blob:; style-src 'nonce-" + nonce +
      "'; worker-src blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'" +
      (libraryImage ? '; img-src data:' : '');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
      '<style nonce="' + nonce + '">:root{color-scheme:light;font:16px system-ui,sans-serif}body{margin:' + (payload.fullPage === true ? '0' : '1rem') + '}[hidden]{display:none!important}.vp3-canvas-library-image{display:block;max-width:100%;max-height:420px;object-fit:contain;margin:0 auto 1rem}</style>' +
      '</head><body><main id="app"></main><script nonce="' + nonce + '">(' + hostRuntime.toString() + ')(' +
      scriptData({...payload,libraryImage}) + ',' + workerRuntime.toString() + (capture ? ',' + capture.toString() : ',null') +
      (window.VP3CanvasWorkflowRuntimeV1?.createHost ? ','+window.VP3CanvasWorkflowRuntimeV1.createHost.toString() : '') +
      ');<\/script></body></html>';
  }

  window.VP3CanvasRuntimeV1 = Object.freeze({channel,stateLimit,maxText,token,createDocument,sourceKeys,
    publicSourceKeys,normalizeSource,unavailableSource});
})();
