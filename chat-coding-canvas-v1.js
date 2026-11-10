(() => {
  'use strict';
  const cfg = window.VP3_CODING_CANVAS_V1;
  const thread = document.getElementById('chatThread');
  const shell = document.getElementById('chatComposerShell');
  const chatForm = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const owner = Number(window.STONEFELLOW_CHAT?.userId);
  const agent = Number(cfg?.agentId ?? window.STONEFELLOW_CHAT?.agentId ?? 0);
  if (!cfg?.endpoint || !cfg?.csrf || !owner || !Number.isSafeInteger(agent) || agent < 0 || !thread || !shell) return;

  const channel = 'vp3.coding.canvas.v1';
  const stateLimit = 65536;
  const maxText = 32768;
  const jobLabels = {queued:'Waiting to build',running:'Working on the canvas',ready:'Draft ready to test',failed:'Coding Agent could not complete this run',stopped:'Build stopped'};
  const activeJob = job => ['queued','running'].includes(job?.state);
  const chat = () => window.STONEFELLOW_CHAT_CONTINUITY;
  const scope = () => Number(chat()?.conversationId()) || 0;
  let participantActor = 0;
  const participantContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  const matches = () => Number(chat()?.agentId()) === agent && Number(window.STONEFELLOW_CHAT?.userId) === owner && !participantContext();
  const shown = () => thread.dataset.view === 'coding';
  const token = () => Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2,'0')).join('');
  const scriptData = value => JSON.stringify(value).replaceAll('<','\\u003c');

  // This function runs in the trusted opaque iframe, with no app JavaScript on its DOM thread.
  function hostRuntime(payload, workerBootstrap) {
    'use strict';
    const {channel,nonce,parentOrigin,artifact,state,workerKey,maxText,stateLimit} = payload;
    const root = document.getElementById('app');
    root.inert = true;
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
    let monitor = 0;
    let heartbeat = Date.now();
    let windowStarted = heartbeat;
    let operations = 0;

    const send = (kind,data = {}) => parent.postMessage({channel,nonce,kind,...data},parentOrigin);
    const reply = (kind,data = {}) => worker?.postMessage({key:workerKey,kind,...data});
    const fault = () => {
      if (!healthy) return;
      healthy = false;
      clearInterval(monitor);
      worker?.terminate();
      if (appUrl) URL.revokeObjectURL(appUrl);
      root.inert = true;
      send('error');
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
      if (++operations > 200) { fault(); return; }
      if (data.kind === 'heartbeat') heartbeat = Date.now();
      if (data.kind === 'error') fault();
      if (data.kind === 'ready' && !started) {
        started = true;
        URL.revokeObjectURL(appUrl);
        setTimeout(() => {
          if (!healthy) return;
          send('ready');
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
        let contentLength = root.textContent.length;
        for (const control of root.querySelectorAll('input,select,textarea,output')) contentLength += control.value.length;
        if (contentLength > 3 * maxText || root.querySelectorAll('*').length > 1500) throw new Error('Canvas markup is too large.');
      }
      if (data.kind === 'save' && Number.isSafeInteger(data.id) && data.id > 0 && typeof data.gesture === 'string') {
        const expires = issued.get(data.gesture);
        if (expires) issued.delete(data.gesture);
        if (!expires || expires < Date.now() || pending.size || typeof data.json !== 'string' || data.json.length > stateLimit) {
          reply('saved',{id:data.id,error:'Save must follow an app interaction and fit in the canvas data limit.'});
          return;
        }
        pending.add(data.id);
        send('save',{id:data.id,json:data.json});
      }
    }

    try {
      const style = document.createElement('style');
      style.nonce = nonce;
      style.textContent = artifact.css;
      document.head.append(style);
      root.append(markup(artifact.html));

      // Load the generated source separately so it cannot change the syntax of the trusted runtime.
      appUrl = URL.createObjectURL(new Blob(['"use strict";\n',artifact.js],{type:'text/javascript'}));
      const workerSource = '(' + workerBootstrap.toString() + ')(' + JSON.stringify(workerKey) + ',' +
        JSON.stringify(JSON.stringify(state)) + ',' + JSON.stringify(appUrl) + ',' + maxText + ',' + stateLimit + ');';
      const url = URL.createObjectURL(new Blob([workerSource],{type:'text/javascript'}));
      try { worker = new Worker(url); } finally { URL.revokeObjectURL(url); }
      worker.addEventListener('message',event => {
        try { handleWorker(event); } catch (_error) { fault(); }
      });
      worker.addEventListener('error',event => { event.preventDefault(); fault(); });
      worker.addEventListener('messageerror',fault);
      monitor = setInterval(() => { if (Date.now() - heartbeat > 3500) fault(); },1000);
      root.addEventListener('submit',event => event.preventDefault());
      for (const type of events) {
        root.addEventListener(type,event => {
          if (!healthy || !started || !event.isTrusted || !(event.target instanceof Element)) return;
          for (const [id,expires] of issued) if (expires < Date.now()) issued.delete(id);
          const gesture = crypto.randomUUID?.() || Array.from(crypto.getRandomValues(new Uint32Array(4))).join('-');
          issued.set(gesture,Date.now() + 1500);
          if (issued.size > 50) issued.delete(issued.keys().next().value);
          for (const [id,entry] of listeners) {
            if (entry.event !== type) continue;
            const el = event.target.closest(entry.selector);
            if (!el || !root.contains(el)) continue;
            reply('event',{id,gesture,event:{value:String(el.value ?? '').slice(0,maxText),checked:Boolean(el.checked),id:el.id,dataset:{...el.dataset}}});
          }
        });
      }
      addEventListener('message',event => {
        if (event.source !== parent || event.origin !== parentOrigin || event.data?.channel !== channel || event.data?.nonce !== nonce) return;
        const {id,kind,value,error} = event.data;
        if (kind === 'saved' && pending.delete(id)) reply('saved',{id,value,error});
      });
    } catch (_error) {
      fault();
    }
  }

  // This function is the only code prepended to generated worker source.
  function workerRuntime(key,initialState,appUrl,maxText,stateLimit) {
    'use strict';
    let state = JSON.parse(initialState);
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
        post({key,kind:'error'});
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
    addEventListener('error',() => send('error'));
    addEventListener('unhandledrejection',() => send('error'));
    addEventListener('message',event => {
      const data = event.data;
      if (data?.key !== key) return;
      if (data.kind === 'event') {
        gesture = data.gesture;
        try {
          const result = callbacks.get(data.id)?.(data.event);
          if (result?.catch) result.catch(() => send('error'));
        } catch (_error) {
          send('error');
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
    Object.defineProperty(URL,'createObjectURL',{value:undefined});
    loadApp(appUrl);
    send('ready');
  }

  function node(tag,text = '',className = '') {
    const el = document.createElement(tag);
    el.textContent = text;
    if (className) el.className = className;
    return el;
  }
  function button(label,handler) {
    const el = node('button',label);
    el.type = 'button';
    el.addEventListener('click',handler);
    return el;
  }

  const panel = node('section','','chat-canvas-view vp3-coding-canvas');
  panel.id = 'chatCodingCanvasV1';
  panel.dataset.chatView = 'coding';
  panel.hidden = true;
  const heading = node('h2','Code / App Canvas');
  heading.tabIndex = -1;
  panel.setAttribute('aria-labelledby','chatCodingCanvasTitleV1');
  heading.id = 'chatCodingCanvasTitleV1';
  const header = node('header','','vp3-coding-canvas-header');
  const close = button('Back to Chat',() => { chat()?.showChatView(); launch.focus(); });
  header.append(heading,close);
  const promptLabel = node('label','Describe an app to build or a change to make');
  const prompt = node('textarea');
  prompt.maxLength = 8000;
  prompt.rows = 2;
  prompt.placeholder = 'Build a workshop checklist with reusable tasks…';
  promptLabel.append(prompt);
  const controls = node('div','','vp3-coding-canvas-controls');
  const build = button('Build new',() => void run('build',prompt.value));
  const repair = button('Repair',() => void run('repair',prompt.value));
  const stop = button('Stop',() => void stopJob());
  controls.append(build,repair,stop);
  const notice = node('p','','vp3-coding-canvas-status');
  notice.setAttribute('role','status');
  const picker = node('select');
  const pickerLabel = node('label','Saved canvases');
  pickerLabel.append(picker);
  const versions = node('select');
  const versionsLabel = node('label','Version');
  versionsLabel.append(versions);
  const savedControls = node('div','','vp3-coding-canvas-controls vp3-coding-canvas-pickers');
  savedControls.append(pickerLabel,versionsLabel);
  const workspace = node('section','','vp3-coding-canvas-workspace');
  const workspaceTitle = node('h3');
  const previewDescription = node('p','','vp3-coding-canvas-description');
  const frameHost = node('div','','vp3-coding-canvas-preview');
  const source = node('details','','vp3-coding-canvas-source');
  const code = node('pre');
  source.append(node('summary','View source'),code);
  const actions = node('div','','vp3-coding-canvas-controls');
  const test = button('Test preview',() => void testPreview());
  const apply = button('Use this version',() => void applyVersion());
  const remove = button('Delete canvas…',() => showDelete(true));
  actions.append(test,apply,remove);
  const deletion = node('div','','vp3-coding-canvas-controls');
  const deleteFolder = node('input');
  deleteFolder.type = 'checkbox';
  const deleteLabel = node('label',' Also delete its Knowledge Base folder and data');
  deleteLabel.prepend(deleteFolder);
  const deleteConfirm = button('Delete canvas',() => void deleteCanvas());
  deletion.append(deleteLabel,deleteConfirm,button('Cancel',() => showDelete(false)));
  deletion.hidden = true;
  workspace.append(workspaceTitle,previewDescription,actions,deletion,frameHost,source);
  workspace.hidden = true;
  panel.append(header,promptLabel,controls,notice,savedControls,workspace);
  thread.append(panel);

  const dock = node('div','','vp3-coding-canvas-dock');
  const launch = button('Code / App Canvas',() => void openPanel());
  launch.setAttribute('aria-controls',panel.id);
  launch.setAttribute('aria-expanded','false');
  const dockStatus = node('span');
  dockStatus.setAttribute('aria-live','polite');
  dock.append(launch,dockStatus);
  shell.append(dock);

  let epoch = 0;
  let load = 0;
  let current = null;
  let frame = null;
  let job = null;
  let poll = 0;
  let locked = false;
  let pollingError = false;
  let browserTest = null;
  let lastIntent = null;
  let failedPreview = null;
  let ticked = null;

  function report(text) { notice.textContent = text; }
  function showDelete(show) {
    deleteFolder.checked = false;
    deletion.hidden = !show;
    if (show) deleteConfirm.focus();
    else if (shown() && !workspace.hidden) remove.focus();
  }
  function closePreview() {
    if (!frame) return;
    clearTimeout(frame.timer);
    frame.element.remove();
    frame = null;
  }
  function validFrame(candidate) {
    return frame === candidate && candidate.epoch === epoch && candidate.scope === scope() &&
      Number(window.STONEFELLOW_CHAT?.userId) === owner && matches() && shown() && candidate.element.isConnected &&
      current?.canvas.id === candidate.canvasId && current?.revision?.id === candidate.revision;
  }

  function tested() {
    const revision = current?.revision;
    const snapshot = current?.state?.revision;
    return Boolean(revision && snapshot && !previewFailed() && (revision.test_passed === true ||
      (browserTest?.canvas === current.canvas.id && browserTest?.revision === revision.id && browserTest?.snapshot === snapshot)));
  }
  function previewFailed() {
    return failedPreview && failedPreview.canvas === current?.canvas.id && failedPreview.revision === current?.revision?.id;
  }
  function updateControls() {
    const working = activeJob(job);
    build.disabled = locked || working;
    repair.disabled = locked || working || !current?.revision;
    stop.hidden = !working;
    stop.disabled = false;
    picker.disabled = locked;
    versions.disabled = locked;
    remove.disabled = locked || working;
    test.disabled = locked || working || current?.revision?.status !== 'draft';
    apply.disabled = locked || working || !current?.revision || !frame?.ready || !validFrame(frame) ||
      (!current.revision.can_rollback && (current.revision.status !== 'draft' || !tested())) || current.revision.id === current.canvas.active_revision;
    apply.textContent = current?.revision?.can_rollback ? 'Restore this version' : 'Use this version';
  }

  async function api(action,payload = {},conversationId = scope()) {
    if (!matches()) throw new Error('Private Canvas is available in your own Chat.');
    const response = await fetch(cfg.endpoint,{
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action,csrf_token:cfg.csrf,agent_id:agent,conversation_id:conversationId,...payload})
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(typeof data?.error === 'string' ? data.error : 'Canvas request did not complete.');
    return data;
  }

  function startPreview(testing = false) {
    closePreview();
    if (!shown() || !current?.revision || !current?.state || !matches()) return;
    const element = node('iframe');
    const nonce = token();
    const artifact = current.revision.artifact;
    const state = current.state.value;
    const isActive = !testing && current.revision.id === current.canvas.active_revision;
    const candidate = {
      nonce,element,epoch,scope:scope(),canvasId:current.canvas.id,revision:current.revision.id,
      stateRevision:current.state.revision,isActive,testing,state,ready:false,saving:false,timer:0
    };
    const payload = {channel,nonce,parentOrigin:location.origin,artifact,state,workerKey:token(),maxText,stateLimit};
    element.title = current.canvas.title + (isActive ? ' app' : ' preview');
    element.setAttribute('sandbox','allow-scripts');
    element.referrerPolicy = 'no-referrer';
    const csp = "default-src 'none'; script-src 'nonce-" + nonce + "' blob:; style-src 'nonce-" + nonce +
      "'; worker-src blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'";
    element.srcdoc = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
      '<style nonce="' + nonce + '">:root{color-scheme:light;font:16px system-ui,sans-serif}body{margin:1rem}[hidden]{display:none!important}</style>' +
      '</head><body><main id="app"></main><script nonce="' + nonce + '">(' + hostRuntime.toString() + ')(' +
      scriptData(payload) + ',' + workerRuntime.toString() + ');<\/script></body></html>';
    frame = candidate;
    frameHost.replaceChildren(element);
    candidate.timer = setTimeout(() => failPreview(candidate),6000);
  }

  function failPreview(candidate) {
    if (!validFrame(candidate)) return;
    closePreview();
    failedPreview = {canvas:candidate.canvasId,revision:candidate.revision};
    frameHost.replaceChildren(node('p','This app stopped or could not load.'));
    report('Preview failed. Open the source or ask the Coding Agent to repair this version.');
    if (candidate.testing) locked = false;
    updateControls();
  }

  async function saveFromPreview(candidate,data) {
    const respond = (value,error) => {
      if (validFrame(candidate)) candidate.element.contentWindow.postMessage({channel,nonce:candidate.nonce,kind:'saved',id:data.id,value,error},'*');
    };
    if (!candidate.ready || candidate.saving || typeof data.json !== 'string' || data.json.length > stateLimit || !Number.isSafeInteger(data.id)) {
      respond(null,'Canvas data cannot be saved right now.');
      return;
    }
    let value;
    try {
      value = JSON.parse(data.json);
      if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid canvas data.');
    } catch (_error) {
      respond(null,'Canvas data must be a JSON object.');
      return;
    }
    if (!candidate.isActive) {
      candidate.state = value;
      respond(value);
      return;
    }
    candidate.saving = true;
    try {
      const saved = await api('save_state',{canvas_id:candidate.canvasId,revision:candidate.revision,state_revision:candidate.stateRevision,state:value},candidate.scope);
      if (!validFrame(candidate)) return;
      candidate.stateRevision = saved.state.revision;
      candidate.state = saved.state.value;
      current.state = saved.state;
      respond(saved.state.value);
      updateControls();
    } catch (error) {
      if (!validFrame(candidate)) return;
      respond(null,error.message);
      report('Data was not saved. ' + error.message);
    } finally {
      candidate.saving = false;
    }
  }

  window.addEventListener('message',event => {
    const candidate = frame;
    if (!candidate || !validFrame(candidate) || event.origin !== 'null' || event.source !== candidate.element.contentWindow ||
      event.data?.channel !== channel || event.data?.nonce !== candidate.nonce) return;
    if (event.data.kind === 'error') failPreview(candidate);
    if (event.data.kind === 'save') void saveFromPreview(candidate,event.data);
    if (event.data.kind === 'ready' && !candidate.ready) {
      candidate.ready = true;
      clearTimeout(candidate.timer);
      if (candidate.testing) void recordTest(candidate);
      else updateControls();
    }
  });

  function option(label,value) {
    const el = node('option',label);
    el.value = value;
    return el;
  }

  function renderCanvas(data) {
    closePreview();
    current = data;
    workspace.hidden = !data?.revision;
    deletion.hidden = true;
    picker.value = data ? String(data.canvas.id) : '';
    if (!data?.revision) {
      workspaceTitle.textContent = '';
      previewDescription.textContent = '';
      code.textContent = '';
      versions.replaceChildren();
      frameHost.replaceChildren();
      updateControls();
      return;
    }
    workspaceTitle.textContent = data.canvas.title;
    const revision = data.revision;
    const isActive = revision.id === data.canvas.active_revision;
    previewDescription.textContent = isActive ?
      'Active app. Changes are saved in your Knowledge Base folder.' :
      'Preview. App changes here are temporary. The saved app data is kept when you switch versions.';
    versions.replaceChildren(...data.revisions.map(item => option(
      'Version ' + item.id + ' · ' + item.status,item.id
    )));
    versions.value = String(revision.id);
    source.open = false;
    code.textContent = ['HTML','CSS','JavaScript'].map((label,index) =>
      label + '\n' + revision.artifact[['html','css','js'][index]]
    ).join('\n\n');
    startPreview();
    updateControls();
  }

  async function refreshList(version = epoch) {
    const data = await api('list');
    if (version !== epoch || !matches() || !shown()) return;
    picker.replaceChildren(option('Choose a saved canvas',''),...data.canvases.map(item => option(item.title,item.id)));
    if (current) picker.value = String(current.canvas.id);
  }

  async function openSaved(id,revision,announce = true) {
    const version = epoch;
    const selection = ++load;
    if (!id) {
      renderCanvas(null);
      return;
    }
    const fields = {canvas_id:id};
    if (revision) fields.revision = revision;
    try {
      const data = await api('open',fields);
      if (version !== epoch || selection !== load || !shown() || !matches()) return;
      renderCanvas(data);
      if (announce) report('');
    } catch (error) {
      if (version === epoch && selection === load && shown()) report(error.message);
    }
  }

  async function openPanel() {
    if (!matches() || !chat()?.isReady() || chat()?.isRestoring()) return;
    const wasShown = shown();
    if (!wasShown) {
      chat().showChatView();
      thread.dataset.view = 'coding';
      window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
    }
    const version = epoch;
    if (!wasShown) {
      heading.focus();
      if (current?.revision) await openSaved(current.canvas.id,current.revision.id,false);
    }
    try {
      await refreshList(version);
      if (version === epoch && !wasShown && !current && job?.state === 'ready') await openSaved(job.canvas_id,job.revision,false);
    }
    catch (error) { if (version === epoch && shown()) report(error.message); }
  }

  function renderJob(next) {
    if (job?.state === next?.state && job?.run_id === next?.run_id) return;
    job = next;
    const status = jobLabels[next?.state] || '';
    dockStatus.textContent = status;
    if (next) {
      const model = next.model?.model;
      report(status + (model ? ' · ' + model : '') + (next.reason && next.state === 'failed' ? '. ' + next.reason : ''));
      window.VP3ChatSpecialists?.update('coding',{label:'Coding Agent',status,title:model,viewLabel:'Open Code / App Canvas',onView:openPanel});
    } else window.VP3ChatSpecialists?.remove('coding');
    updateControls();
  }

  async function advance(next,version,conversation) {
    if (version !== epoch || conversation !== scope() || !matches()) return;
    if (job?.run_id === next.run_id && !activeJob(job) && activeJob(next)) return;
    const newlyTerminal = !activeJob(next) && (job?.run_id !== next.run_id || activeJob(job));
    renderJob(next);
    if (next.state === 'queued' && (ticked?.run !== next.run_id || ticked.retryAt <= Date.now())) {
      const attempt = {run:next.run_id,retryAt:Infinity};
      ticked = attempt;
      const retryLater = () => { attempt.retryAt = Date.now() + 4000; };
      void api('tick',{run_id:next.run_id},conversation).then(retryLater,retryLater);
    }
    clearTimeout(poll);
    if (activeJob(next)) {
      poll = setTimeout(() => void pollJob(next.run_id,version,conversation),900);
    } else if (newlyTerminal) {
      window.dispatchEvent(new Event('vp3-coding-usage-change'));
      if (next.state === 'ready' && shown()) {
        try {
          await refreshList(version);
          if (version === epoch && shown()) await openSaved(next.canvas_id,next.revision,false);
        } catch (error) {
          if (version === epoch && shown()) report(error.message);
        }
      }
    }
  }

  async function pollJob(runId,version,conversation) {
    if (version !== epoch || conversation !== scope() || !matches() || job?.run_id !== runId || !activeJob(job)) return;
    try {
      const data = await api('job',{run_id:runId},conversation);
      if (job?.run_id !== runId) return;
      if (pollingError && version === epoch) report(jobLabels[data.job?.state] || '');
      pollingError = false;
      await advance(data.job,version,conversation);
    } catch (error) {
      if (version !== epoch || job?.run_id !== runId || !activeJob(job)) return;
      if (!pollingError) report(error.message + ' Checking again…');
      pollingError = true;
      poll = setTimeout(() => void pollJob(runId,version,conversation),2600);
    }
  }

  async function run(action,text,command) {
    text = text.trim();
    if (!text || text.length > 8000 || locked || activeJob(job) || !matches() || !chat()?.isReady()) {
      if (shown() && !text) report('Describe what you want the Coding Agent to make or change.');
      return;
    }
    const version = epoch;
    let conversation = scope();
    const fields = {prompt:text};
    if (action === 'repair') {
      if (!current?.revision) return;
      fields.canvas_id = current.canvas.id;
      fields.revision = current.revision.id;
      if (previewFailed()) fields.diagnostic = 'The isolated browser preview failed to start or stopped responding.';
    }
    locked = true;
    updateControls();
    try {
      if (!conversation) {
        if (shown()) chat().showChatView();
        conversation = await chat().ensureConversation();
        if (version !== epoch || !matches()) return;
      }
      await openPanel();
      if (version !== epoch || !shown()) return;
      if (!conversation) throw new Error('Wait for Chat to finish loading, then try again.');
      const intent = JSON.stringify([conversation,action,fields]);
      if (lastIntent?.intent !== intent) lastIntent = {intent,id:crypto.randomUUID()};
      fields.request_id = lastIntent.id;
      const data = await api(action,fields,conversation);
      if (version !== epoch || !matches() || conversation !== scope()) return;
      lastIntent = null;
      prompt.value = '';
      if (command && input?.value === command) {
        input.value = '';
        input.dispatchEvent(new Event('input'));
      }
      await advance(data.job,version,conversation);
    } catch (error) {
      if (version === epoch) {
        if (!shown() && chat()?.isReady()) void openPanel();
        report(error.message);
      }
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function stopJob() {
    const running = job;
    const version = epoch;
    const conversation = scope();
    if (!activeJob(running)) return;
    stop.disabled = true;
    try {
      const data = await api('stop',{run_id:running.run_id},conversation);
      if (job?.run_id === running.run_id) await advance(data.job,version,conversation);
    } catch (error) {
      if (version === epoch && job?.run_id === running.run_id) report(error.message);
    } finally {
      if (version === epoch) stop.disabled = false;
    }
  }

  async function testPreview() {
    if (!current?.revision || test.disabled || !matches()) return;
    locked = true;
    updateControls();
    report('Starting an isolated test with a copy of the canvas data…');
    startPreview(true);
  }

  async function recordTest(candidate) {
    try {
      await api('test',{canvas_id:candidate.canvasId,revision:candidate.revision,state_revision:candidate.stateRevision},candidate.scope);
      if (!validFrame(candidate)) return;
      if (previewFailed()) failedPreview = null;
      browserTest = {canvas:candidate.canvasId,revision:candidate.revision,snapshot:candidate.stateRevision};
      report('Preview loaded successfully. You can try the app here before using this version.');
    } catch (error) {
      if (validFrame(candidate)) report(error.message);
    } finally {
      if (validFrame(candidate)) {
        candidate.testing = false;
        locked = false;
        updateControls();
      }
    }
  }

  async function applyVersion() {
    if (apply.disabled || !current?.revision || !frame?.ready || !validFrame(frame) || !matches()) return;
    const version = epoch;
    const {canvas,revision} = current;
    locked = true;
    updateControls();
    try {
      const data = await api('apply',{canvas_id:canvas.id,revision:revision.id,expected_active_revision:canvas.active_revision});
      if (version !== epoch || !shown()) return;
      if (job?.canvas_id === canvas.id && job.revision === revision.id) renderJob(null);
      renderCanvas(data);
      report('This version is active. Your saved app data was kept.');
    } catch (error) {
      if (version === epoch) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function deleteCanvas() {
    const version = epoch;
    if (locked || !current || activeJob(job) || !matches()) return;
    locked = true;
    updateControls();
    try {
      const id = current.canvas.id;
      await api('delete',{canvas_id:id,delete_folder:deleteFolder.checked});
      if (version !== epoch || !shown()) return;
      if (job?.canvas_id === id) renderJob(null);
      renderCanvas(null);
      await refreshList(version);
      if (version === epoch && shown()) report('Canvas deleted.');
    } catch (error) {
      if (version === epoch) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function resume() {
    const version = epoch;
    const conversation = scope();
    if (!conversation || !matches() || !chat()?.isReady() || chat()?.isRestoring() || locked || job) return;
    try {
      const data = await api('resume',{},conversation);
      if (version === epoch && conversation === scope() && !locked && !job && data.job) await advance(data.job,version,conversation);
    } catch (_error) { /* Saved canvases and explicit builds remain available. */ }
  }

  picker.addEventListener('change',() => void openSaved(picker.value));
  versions.addEventListener('change',() => void openSaved(current?.canvas.id,Number(versions.value)));
  chatForm?.addEventListener('submit',event => {
    const command = input?.value.match(/^\s*\/build\s+([\s\S]+)$/i);
    if (!command) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!matches() || !chat()?.isReady()) return;
    void run('build',command[1],input.value);
  },true);
  window.addEventListener('vp3:chat-canvas-view-changed',() => {
    panel.hidden = !shown();
    launch.setAttribute('aria-expanded',String(shown()));
    if (!shown()) {
      if (frame?.testing) locked = false;
      closePreview();
      updateControls();
    }
  });
  function resetContext() {
    epoch++;
    clearTimeout(poll);
    locked = false;
    pollingError = false;
    lastIntent = null;
    failedPreview = null;
    ticked = null;
    browserTest = null;
    prompt.value = '';
    renderCanvas(null);
    renderJob(null);
    picker.replaceChildren();
    report('');
  }
  function updateParticipantScope() {
    resetContext();
    dock.hidden = participantContext();
    if (shown()) chat()?.showChatView();
    if (!dock.hidden) void resume();
  }
  window.addEventListener('stonefellow:chat-context-changing',resetContext);
  window.addEventListener('vp3:participant-selection-changed',updateParticipantScope);
  window.addEventListener('vp3:participant-task-context',event => {
    participantActor = Number(event.detail?.participant_id) || 0;
    updateParticipantScope();
  });
  window.addEventListener('vp3:chat-context-ready',() => void resume());
  dock.hidden = participantContext();
  void resume();
  updateControls();
})();
