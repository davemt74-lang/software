const VP3_DEFAULT_BASE = 'https://vp3.me';
const VP3_CONTRACT_VERSION = '1';
const VP3_EXTENSION_VERSION = '22.1.0';
const VP3_MEDIA_CLIP_MAX_SECONDS = 90;

const storage = {
  async get(keys = null) { return chrome.storage.local.get(keys); },
  async set(values) { return chrome.storage.local.set(values); },
  async remove(keys) { return chrome.storage.local.remove(keys); }
};

function cleanBaseUrl(value) {
  const raw = String(value || VP3_DEFAULT_BASE).trim().replace(/\/+$/, '');
  let parsed;
  try { parsed = new URL(raw); } catch { throw new Error('Enter a valid VP3 URL.'); }
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol !== 'https:' && !(local && parsed.protocol === 'http:')) {
    throw new Error('VP3 must use HTTPS. HTTP is allowed only for localhost development.');
  }
  if (parsed.username || parsed.password || parsed.search || parsed.hash) throw new Error('VP3 URL must contain only the site origin.');
  return parsed.origin;
}

function apiUrl(base, path) { return `${cleanBaseUrl(base)}${path}`; }
function uuid() { return crypto.randomUUID(); }
function utf8Limit(value, maxBytes) {
  const text = String(value || '');
  const encoder = new TextEncoder();
  if (encoder.encode(text).length <= maxBytes) return text;
  let bytes = 0;
  let result = '';
  for (const character of text) {
    const size = encoder.encode(character).length;
    if (bytes + size > maxBytes) break;
    bytes += size;
    result += character;
  }
  return result;
}

function secondsLabel(value) {
  const seconds = Math.max(0, Math.floor(Number(value || 0)));
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

function base64UrlJson(value) {
  const bytes = new TextEncoder().encode(JSON.stringify(value || {}));
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function dataUrlBytes(dataUrl) {
  const match = /^data:([^;,]+);base64,(.+)$/i.exec(String(dataUrl || ''));
  if (!match) throw new Error('Captured media is invalid.');
  const binary = atob(match[2]);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
  return { mime: match[1].toLowerCase(), bytes };
}

async function ensureInstallation() {
  const state = await storage.get(['installation_id']);
  if (state.installation_id) return state.installation_id;
  const installation_id = uuid();
  await storage.set({ installation_id });
  return installation_id;
}

async function config() {
  const state = await storage.get(['base_url']);
  return { base_url: cleanBaseUrl(state.base_url || VP3_DEFAULT_BASE) };
}

async function ensureOriginPermission(baseUrl) {
  const origin = `${new URL(cleanBaseUrl(baseUrl)).origin}/*`;
  if (origin === 'https://vp3.me/*') return true;
  const has = await chrome.permissions.contains({ origins: [origin] });
  if (has) return true;
  return chrome.permissions.request({ origins: [origin] });
}

async function fetchJson(path, options = {}) {
  const { base_url } = await config();
  const allowed = await ensureOriginPermission(base_url);
  if (!allowed) throw new Error('Permission to connect to this VP3 installation was not granted.');
  const headers = new Headers(options.headers || {});
  headers.set('X-VP3-Extension-Version', VP3_EXTENSION_VERSION);
  headers.set('X-VP3-Contract-Version', VP3_CONTRACT_VERSION);
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    options.body = JSON.stringify(options.json);
  }
  const response = await fetch(apiUrl(base_url, path), { ...options, headers, cache: 'no-store' });
  let payload = null;
  try { payload = await response.json(); } catch { payload = null; }
  if (!response.ok || !payload?.ok) {
    const message = payload?.error?.message || payload?.message || `VP3 request failed (${response.status}).`;
    const error = new Error(message);
    error.status = response.status;
    error.code = payload?.error?.code || payload?.error || 'request_failed';
    throw error;
  }
  return payload;
}

async function clearRevokedConnection() {
  await storage.remove([
    'device_token',
    // Remove v20.x connection state during upgrade/disconnect.
    'device_id','device_credential','connected_user','approved_capabilities','pending_connection','session',
    'last_share', 'pending_capture'
  ]);
}

async function deviceToken() {
  const state = await storage.get(['device_token']);
  const token = String(state.device_token || '').trim();
  if (!/^[a-f0-9]{64}$/i.test(token)) throw new Error('Connect this browser to VP3 first.');
  return token.toLowerCase();
}

async function authorizedFetch(path, options = {}, capability = '') {
  const token = await deviceToken();
  try {
    return await fetchJson(path, {
      ...options,
      headers: { ...(options.headers || {}), Authorization: `Bearer ${token}` }
    });
  } catch (error) {
    if (error.status !== 401) throw error;
    await clearRevokedConnection();
    const revoked = new Error('This browser connection was revoked. Reconnect to VP3.');
    revoked.status = 401;
    revoked.code = 'reconnect_required';
    throw revoked;
  }
}

async function authorizedMediaDataUrl(path) {
  const token = await deviceToken();
  const { base_url } = await config();
  const response = await fetch(apiUrl(base_url, path), {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
      'X-VP3-Extension-Version': VP3_EXTENSION_VERSION,
      'X-VP3-Contract-Version': VP3_CONTRACT_VERSION
    },
    cache: 'no-store',
    credentials: 'omit'
  });
  if (response.status === 401) {
    await clearRevokedConnection();
    const revoked = new Error('This browser connection was revoked. Reconnect to VP3.');
    revoked.status = 401;
    revoked.code = 'reconnect_required';
    throw revoked;
  }
  if (!response.ok) {
    const error = new Error('Browser Share media could not be loaded.');
    error.status = response.status;
    throw error;
  }
  const blob = await response.blob();
  if (blob.size > 16 * 1024 * 1024) throw new Error('Browser Share media is too large to preview.');
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
  }
  return `data:${blob.type || 'application/octet-stream'};base64,${btoa(binary)}`;
}

async function currentAccount() {
  return authorizedFetch('/api/extension-me.php', { method: 'GET' });
}

async function browserMemoryActionV2170(action, payload = {}) {
  const normalizedAction=String(action || 'list');
  const request={ ...payload };
  if(['candidates','approve'].includes(normalizedAction)&&request.context){
    request.context=browserContextPayload(request.context);
  }
  return authorizedFetch('/api/extension-memory-v2170.php', {
    method:'POST',
    json:{ action:normalizedAction, ...request }
  }, 'agent.message');
}

async function browserExecutionActionV2180(action, payload = {}) {
  const normalizedAction=String(action || 'list');
  const request={ ...payload };
  if(['candidates','propose','execute'].includes(normalizedAction)&&request.context){
    request.context=browserContextPayload(request.context);
  }
  return authorizedFetch('/api/extension-execution-v2180.php', {
    method:'POST',
    json:{ action:normalizedAction, ...request }
  }, 'agent.message');
}

async function browserWebInteractionApiV2210(action, payload = {}) {
  return authorizedFetch('/api/extension-web-interaction-v2210.php', {
    method:'POST',
    json:{ action:String(action || 'actions'), ...payload }
  }, 'agent.message');
}

async function browserWebPageIdentityV2210(tabId) {
  const injected=await chrome.scripting.executeScript({
    target:{tabId},
    func:async()=>{
      const sha=async value=>{
        const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(String(value||'')));
        return [...new Uint8Array(digest)].map(byte=>byte.toString(16).padStart(2,'0')).join('');
      };
      return {
        domain:String(location.hostname||'').toLowerCase(),
        page_fingerprint:await sha(String(location.href||''))
      };
    }
  });
  const identity=injected?.[0]?.result;
  if(!identity?.domain||!/^[a-f0-9]{64}$/.test(String(identity.page_fingerprint||'')))throw new Error('The active page could not be identified for controlled interaction.');
  return identity;
}

async function browserWebInteractionObserveV2210(payload = {}) {
  const [tab]=await chrome.tabs.query({active:true,currentWindow:true});
  if(!tab?.id||!/^https?:/i.test(String(tab.url||'')))throw new Error('Open a normal web page before scanning controls.');
  const injected=await chrome.scripting.executeScript({
    target:{tabId:tab.id},
    func:async()=>{
      const sha=async value=>{
        const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(String(value||'')));
        return [...new Uint8Array(digest)].map(byte=>byte.toString(16).padStart(2,'0')).join('');
      };
      const clip=(value,max=180)=>String(value||'').replace(/\s+/g,' ').trim().slice(0,max);
      const visible=el=>{
        if(!(el instanceof Element))return false;
        const style=getComputedStyle(el);
        if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
        const rect=el.getBoundingClientRect();
        return rect.width>1&&rect.height>1&&rect.bottom>=0&&rect.right>=0&&rect.top<=innerHeight&&rect.left<=innerWidth;
      };
      const labelFor=el=>{
        const aria=clip(el.getAttribute('aria-label'));
        if(aria)return aria;
        if(el.id){
          try{
            const label=document.querySelector('label[for="'+CSS.escape(el.id)+'"]');
            if(label)return clip(label.innerText||label.textContent);
          }catch(_error){}
        }
        const parent=el.closest('label');
        if(parent)return clip(parent.innerText||parent.textContent);
        return clip(el.innerText||el.textContent||el.getAttribute('placeholder')||el.getAttribute('title')||el.getAttribute('name'));
      };
      const state=globalThis.__vp3WebRuntimeV2210||(globalThis.__vp3WebRuntimeV2210={epoch:0,keys:new WeakMap(),observer:null});
      if(!state.observer){
        state.observer=new MutationObserver(()=>{state.epoch+=1;});
        state.observer.observe(document.documentElement,{subtree:true,childList:true,attributes:true,characterData:true});
      }
      const selector='a[href],button,input,textarea,select,[role="button"],[role="link"],[role="checkbox"],[role="radio"],[contenteditable="true"],[tabindex]';
      const nodes=[...document.querySelectorAll(selector)].filter(visible).slice(0,240);
      const elements=[];
      const fingerprints=[];
      for(const el of nodes){
        if(elements.length>=80)break;
        const tag=String(el.tagName||'').toLowerCase();
        const inputType=tag==='input'?String(el.getAttribute('type')||'text').toLowerCase():'';
        const role=String(el.getAttribute('role')||'').toLowerCase();
        let kind='control';
        if(tag==='a'&&el.href)kind='link';
        else if(tag==='button'||role==='button'||['submit','button','reset'].includes(inputType))kind='button';
        else if(inputType==='checkbox'||role==='checkbox')kind='checkbox';
        else if(inputType==='radio'||role==='radio')kind='radio';
        else if(tag==='select')kind='select';
        else if(tag==='textarea')kind='textarea';
        else if(tag==='input')kind='input';
        const label=labelFor(el);
        const name=clip(el.getAttribute('name'),120);
        const placeholder=clip(el.getAttribute('placeholder'),160);
        const ariaLabel=clip(el.getAttribute('aria-label'),160);
        const autocomplete=clip(el.getAttribute('autocomplete'),80).toLowerCase();
        let targetHost='';
        if(tag==='a'&&el.href){try{targetHost=new URL(el.href,location.href).hostname.toLowerCase();}catch(_error){}}
        const semantic=[tag,inputType,role,name,autocomplete,label,placeholder,ariaLabel,targetHost].join('|').toLowerCase();
        const fingerprint=await sha(semantic);
        let key=state.keys.get(el);
        if(!key){key=crypto.randomUUID();state.keys.set(el,key);}
        const sensitive=inputType==='password'
          ||/(?:current-password|new-password|one-time-code|cc-(?:number|csc|exp|name)|transaction-|webauthn)/i.test(autocomplete)
          ||/\b(?:password|passcode|pin|security code|verification code|one[- ]time|otp|2fa|mfa|credit card|card number|cvv|cvc|social security|ssn|access token|api key|secret key|private key|medical|diagnosis|prescription|insurance member|bank account|routing number)\b/i.test(semantic);
        const submitLike=(tag==='button'&&String(el.getAttribute('type')||'submit').toLowerCase()==='submit')||inputType==='submit';
        const dangerous=submitLike||/\b(?:submit|send|publish|post|delete|remove|destroy|purchase|buy|order|checkout|pay|book|reserve|confirm|transfer|wire|sign|accept|agree|save changes|update account|create account|close account|cancel subscription|unsubscribe|invite|share)\b/i.test(semantic);
        const options=kind==='select'?[...el.options].slice(0,30).map(option=>({value:clip(option.value,160),label:clip(option.textContent,160)})):[];
        elements.push({
          element_key:key,element_fingerprint:fingerprint,kind,tag,input_type:inputType,role,
          label,name,placeholder,aria_label:ariaLabel,autocomplete,target_host:targetHost,
          disabled:Boolean(el.disabled)||el.getAttribute('aria-disabled')==='true',
          checked:'checked'in el?Boolean(el.checked):null,
          sensitive,submit_like:submitLike,dangerous,options
        });
        fingerprints.push(fingerprint);
      }
      return {
        domain:String(location.hostname||'').toLowerCase(),
        page_fingerprint:await sha(String(location.href||'')),
        dom_fingerprint:await sha(fingerprints.sort().join('|')),
        mutation_epoch:Number(state.epoch||0),
        elements
      };
    }
  });
  const observation=injected?.[0]?.result;
  if(!observation?.domain||!Array.isArray(observation.elements))throw new Error('Chrome could not inspect interactive controls on this page.');
  const authority=await browserWebInteractionApiV2210('observe',{
    runtime_id:String(payload.runtime_id||''),
    agent_id:Number(payload.agent_id||0),
    domain:String(observation.domain||''),
    page_fingerprint:String(observation.page_fingerprint||''),
    dom_fingerprint:String(observation.dom_fingerprint||''),
    mutation_epoch:Number(observation.mutation_epoch||0),
    element_count:observation.elements.length
  });
  return {
    tab_id:tab.id,title:String(tab.title||'').slice(0,180),
    domain:observation.domain,page_fingerprint:observation.page_fingerprint,
    dom_fingerprint:observation.dom_fingerprint,mutation_epoch:observation.mutation_epoch,
    elements:observation.elements,authority:authority?.observation||null,
    actions:authority?.actions||null
  };
}

async function browserWebWaitForTabV2210(tabId, beforeUrl, timeoutMs=6000) {
  return new Promise(resolve=>{
    let settled=false;
    const finish=async()=>{
      if(settled)return;settled=true;chrome.tabs.onUpdated.removeListener(onUpdated);clearTimeout(timer);
      const tab=await chrome.tabs.get(tabId).catch(()=>null);
      resolve(tab);
    };
    const onUpdated=(id,changeInfo)=>{
      if(id!==tabId)return;
      if(changeInfo.url&&changeInfo.url!==beforeUrl)return finish();
      if(changeInfo.status==='complete')return finish();
    };
    const timer=setTimeout(finish,timeoutMs);
    chrome.tabs.onUpdated.addListener(onUpdated);
  });
}

async function browserWebInteractionExecuteV2210(payload = {}) {
  const [tab]=await chrome.tabs.query({active:true,currentWindow:true});
  if(!tab?.id||!/^https?:/i.test(String(tab.url||'')))throw new Error('Open the approved web page before running this interaction.');
  const identity=await browserWebPageIdentityV2210(tab.id);
  const claim=await browserWebInteractionApiV2210('claim',{
    runtime_id:String(payload.runtime_id||''),agent_id:Number(payload.agent_id||0),
    interaction_id:String(payload.interaction_id||''),
    domain:String(identity.domain||''),page_fingerprint:String(identity.page_fingerprint||'')
  });
  const contract=claim?.contract||null,permitToken=String(claim?.permit_token||'');
  if(!contract||!permitToken)throw new Error('The Web interaction permit could not be claimed.');
  const requestedAction=String(payload.action_key||'');
  const requestedFp=String(payload.element_fingerprint||'');
  if(requestedAction!==String(contract.action_key||'')||requestedFp!==String(contract.element_fingerprint||'')){
    throw new Error('The local interaction no longer matches the server-authorized permit.');
  }
  const rawValue=String(payload.value??'').slice(0,4000);
  if(Number(contract.value_length||0)!==rawValue.length&&['type','select','toggle'].includes(requestedAction)){
    throw new Error('The interaction value changed after preview. Preview it again.');
  }
  const beforeUrl=String(tab.url||'');
  let outcome={verified:false,result_code:'unverified'};
  try{
    const injected=await chrome.scripting.executeScript({
      target:{tabId:tab.id},
      args:[{
        element_key:String(payload.element_key||''),element_fingerprint:requestedFp,
        action_key:requestedAction,value:rawValue,
        requires_checkpoint:Boolean(contract.requires_checkpoint),target_host:String(contract.target_host||'')
      }],
      func:async args=>{
        const sha=async value=>{
          const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(String(value||'')));
          return [...new Uint8Array(digest)].map(byte=>byte.toString(16).padStart(2,'0')).join('');
        };
        const clip=(value,max=180)=>String(value||'').replace(/\s+/g,' ').trim().slice(0,max);
        const labelFor=el=>{
          const aria=clip(el.getAttribute('aria-label'));if(aria)return aria;
          if(el.id){try{const l=document.querySelector('label[for="'+CSS.escape(el.id)+'"]');if(l)return clip(l.innerText||l.textContent);}catch(_error){}}
          const parent=el.closest('label');if(parent)return clip(parent.innerText||parent.textContent);
          return clip(el.innerText||el.textContent||el.getAttribute('placeholder')||el.getAttribute('title')||el.getAttribute('name'));
        };
        const state=globalThis.__vp3WebRuntimeV2210||(globalThis.__vp3WebRuntimeV2210={epoch:0,keys:new WeakMap(),observer:null});
        if(!state.observer){state.observer=new MutationObserver(()=>{state.epoch+=1;});state.observer.observe(document.documentElement,{subtree:true,childList:true,attributes:true,characterData:true});}
        const nodes=[...document.querySelectorAll('a[href],button,input,textarea,select,[role="button"],[role="link"],[role="checkbox"],[role="radio"],[contenteditable="true"],[tabindex]')].slice(0,320);
        const fingerprint=async el=>{
          const tag=String(el.tagName||'').toLowerCase();
          const inputType=tag==='input'?String(el.getAttribute('type')||'text').toLowerCase():'';
          const role=String(el.getAttribute('role')||'').toLowerCase();
          let host='';if(tag==='a'&&el.href){try{host=new URL(el.href,location.href).hostname.toLowerCase();}catch(_error){}}
          return sha([tag,inputType,role,clip(el.getAttribute('name'),120),clip(el.getAttribute('autocomplete'),80).toLowerCase(),labelFor(el),clip(el.getAttribute('placeholder'),160),clip(el.getAttribute('aria-label'),160),host].join('|').toLowerCase());
        };
        let el=nodes.find(node=>state.keys.get(node)===args.element_key)||null;
        if(el&&(await fingerprint(el))!==args.element_fingerprint)el=null;
        let reacquired=false;
        if(!el){
          const matches=[];
          for(const node of nodes){if((await fingerprint(node))===args.element_fingerprint)matches.push(node);if(matches.length>1)break;}
          if(matches.length!==1)return {verified:false,result_code:matches.length>1?'stale_ambiguous':'stale_missing'};
          el=matches[0];state.keys.set(el,args.element_key||crypto.randomUUID());reacquired=true;
        }
        if(el.disabled||el.getAttribute('aria-disabled')==='true')return {verified:false,result_code:'control_disabled',reacquired};
        const tag=String(el.tagName||'').toLowerCase();
        const inputType=tag==='input'?String(el.getAttribute('type')||'text').toLowerCase():'';
        const autocomplete=clip(el.getAttribute('autocomplete'),80).toLowerCase();
        const semantic=[tag,inputType,String(el.getAttribute('role')||''),clip(el.getAttribute('name'),120),autocomplete,labelFor(el),clip(el.getAttribute('placeholder'),160),clip(el.getAttribute('aria-label'),160)].join(' ').toLowerCase();
        const sensitive=inputType==='password'||/(?:current-password|new-password|one-time-code|cc-(?:number|csc|exp|name)|transaction-|webauthn)/i.test(autocomplete)||/\b(?:password|passcode|pin|security code|verification code|one[- ]time|otp|2fa|mfa|credit card|card number|cvv|cvc|social security|ssn|access token|api key|secret key|private key|medical|diagnosis|prescription|insurance member|bank account|routing number)\b/i.test(semantic);
        const submitLike=(tag==='button'&&String(el.getAttribute('type')||'submit').toLowerCase()==='submit')||inputType==='submit';
        const dangerous=submitLike||/\b(?:submit|send|publish|post|delete|remove|destroy|purchase|buy|order|checkout|pay|book|reserve|confirm|transfer|wire|sign|accept|agree|save changes|update account|create account|close account|cancel subscription|unsubscribe|invite|share)\b/i.test(semantic);
        if(sensitive&&['type','clear','select','toggle','submit'].includes(args.action_key))return {verified:false,result_code:'sensitive_manual_only',reacquired};
        if(dangerous&&!args.requires_checkpoint)return {verified:false,result_code:'checkpoint_mismatch',reacquired};
        const beforeEpoch=Number(state.epoch||0),beforeExpanded=el.getAttribute('aria-expanded'),beforePressed=el.getAttribute('aria-pressed'),beforeAriaChecked=el.getAttribute('aria-checked'),beforeChecked='checked'in el?Boolean(el.checked):null;
        const setValue=value=>{
          const proto=tag==='textarea'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;
          const desc=Object.getOwnPropertyDescriptor(proto,'value');
          if(desc?.set)desc.set.call(el,value);else el.value=value;
          el.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertText',data:null}));
          el.dispatchEvent(new Event('change',{bubbles:true}));
        };
        if(args.action_key==='focus'){
          el.scrollIntoView({block:'center',inline:'nearest'});el.focus({preventScroll:true});
          return {verified:document.activeElement===el,result_code:document.activeElement===el?'focus_verified':'focus_unverified',reacquired};
        }
        if(args.action_key==='scroll'){
          el.scrollIntoView({block:'center',inline:'nearest'});await new Promise(r=>setTimeout(r,80));
          const rect=el.getBoundingClientRect(),ok=rect.bottom>=0&&rect.top<=innerHeight&&rect.right>=0&&rect.left<=innerWidth;
          return {verified:ok,result_code:ok?'viewport_verified':'viewport_unverified',reacquired};
        }
        if(args.action_key==='type'||args.action_key==='clear'){
          if(!['input','textarea'].includes(tag)||['password','file','hidden','checkbox','radio','submit','button','reset'].includes(inputType))return {verified:false,result_code:'field_not_typable',reacquired};
          const value=args.action_key==='clear'?'':String(args.value||'');setValue(value);
          const ok=String(el.value||'')===value;
          return {verified:ok,result_code:ok?(args.action_key==='clear'?'clear_verified':'value_verified'):'value_unverified',reacquired};
        }
        if(args.action_key==='select'){
          if(tag!=='select')return {verified:false,result_code:'control_not_select',reacquired};
          el.value=String(args.value||'');el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));
          const ok=String(el.value||'')===String(args.value||'');
          return {verified:ok,result_code:ok?'option_verified':'option_unverified',reacquired};
        }
        if(args.action_key==='toggle'){
          const toggleRole=String(el.getAttribute('role')||'').toLowerCase();
          const nativeToggle=('checked'in el)&&['checkbox','radio'].includes(inputType);
          const ariaToggle=['checkbox','radio'].includes(toggleRole);
          if(!nativeToggle&&!ariaToggle)return {verified:false,result_code:'control_not_toggle',reacquired};
          const desired=String(args.value||'')==='true';
          if((inputType==='radio'||toggleRole==='radio')&&!desired)return {verified:false,result_code:'radio_uncheck_unsupported',reacquired};
          const current=nativeToggle?Boolean(el.checked):el.getAttribute('aria-checked')==='true';
          if(current!==desired)el.click();
          await new Promise(r=>setTimeout(r,120));
          const after=nativeToggle?Boolean(el.checked):el.getAttribute('aria-checked')==='true';
          const ok=after===desired;
          return {verified:ok,result_code:ok?'checked_verified':'checked_unverified',reacquired};
        }
        if(args.action_key==='open_link'){
          if(tag!=='a'||!el.href)return {verified:false,result_code:'control_not_link',reacquired};
          let url;try{url=new URL(el.href,location.href);}catch(_error){return {verified:false,result_code:'link_invalid',reacquired};}
          if(!/^https?:$/.test(url.protocol))return {verified:false,result_code:'link_protocol_blocked',reacquired};
          return {verified:false,result_code:'navigation_ready',navigate_url:url.href,target_host:url.hostname.toLowerCase(),reacquired};
        }
        if(args.action_key==='submit'){
          if(!submitLike)return {verified:false,result_code:'control_not_submit',reacquired};
          if(el.form&&typeof el.form.requestSubmit==='function')el.form.requestSubmit(el);else el.click();
          await new Promise(r=>setTimeout(r,450));
          const changed=Number(state.epoch||0)>beforeEpoch;
          return {verified:changed,result_code:changed?'submission_verified':'submission_unverified',reacquired};
        }
        if(args.action_key==='click'){
          if(tag==='a'&&el.href)return {verified:false,result_code:'use_open_link',reacquired};
          el.scrollIntoView({block:'center',inline:'nearest'});el.click();await new Promise(r=>setTimeout(r,350));
          const changed=Number(state.epoch||0)>beforeEpoch||el.getAttribute('aria-expanded')!==beforeExpanded||el.getAttribute('aria-pressed')!==beforePressed||el.getAttribute('aria-checked')!==beforeAriaChecked||('checked'in el&&Boolean(el.checked)!==beforeChecked);
          return {verified:changed,result_code:changed?'click_verified':'click_unverified',reacquired};
        }
        return {verified:false,result_code:'action_unsupported',reacquired};
      }
    });
    outcome=injected?.[0]?.result||outcome;
    if(outcome?.navigate_url){
      const url=new URL(String(outcome.navigate_url));
      const currentHost=String(identity.domain||'').toLowerCase();
      if(url.hostname.toLowerCase()!==currentHost||url.hostname.toLowerCase()!==String(contract.target_host||'').toLowerCase()){
        outcome={verified:false,result_code:'navigation_domain_blocked'};
      }else{
        await chrome.tabs.update(tab.id,{url:url.href});
        const after=await browserWebWaitForTabV2210(tab.id,beforeUrl);
        outcome={
          verified:Boolean(after?.url)&&String(after.url)===url.href,
          result_code:Boolean(after?.url)&&String(after.url)===url.href?'navigation_verified':'navigation_unverified'
        };
      }
    }else if(!outcome?.verified&&['click','submit'].includes(requestedAction)){
      const after=await browserWebWaitForTabV2210(tab.id,beforeUrl,1800);
      if(after?.url&&String(after.url)!==beforeUrl)outcome={verified:true,result_code:'navigation_verified'};
    }
  }catch(error){
    if(['click','submit'].includes(requestedAction)){
      const after=await browserWebWaitForTabV2210(tab.id,beforeUrl,1800);
      if(after?.url&&String(after.url)!==beforeUrl)outcome={verified:true,result_code:'navigation_verified'};
      else outcome={verified:false,result_code:'execution_context_lost'};
    }else outcome={verified:false,result_code:'execution_error'};
  }
  let completion=null;
  try{
    completion=await browserWebInteractionApiV2210('complete',{
      runtime_id:String(payload.runtime_id||''),agent_id:Number(payload.agent_id||0),
      interaction_id:String(payload.interaction_id||''),permit_token:permitToken,
      verified:Boolean(outcome?.verified),result_code:String(outcome?.result_code||'unverified')
    });
  }catch(error){
    const wrapped=new Error('The browser interaction ran, but VP3 could not record its verification result. '+error.message);
    wrapped.code='verification_record_failed';throw wrapped;
  }
  return {outcome,proposal:completion?.proposal||null,remaining_interactions:completion?.remaining_interactions};
}

async function browserAgentRuntimeActionV2200(action, payload = {}) {
  const normalizedAction=String(action || 'list');
  const request={ ...payload };
  if(['tick','observe','verify_navigation','replan'].includes(normalizedAction)&&request.context){
    request.context=browserContextPayload(request.context);
  }
  return authorizedFetch('/api/extension-agent-runtime-v2200.php', {
    method:'POST',
    json:{ action:normalizedAction, ...request }
  }, 'agent.message');
}

async function browserAgentRuntimeNavigateV2200(rawUrl) {
  const { base_url }=await config();
  const base=new URL(cleanBaseUrl(base_url));
  const url=new URL(String(rawUrl || ''),base.origin+'/');
  if(!/^https?:$/.test(url.protocol)||url.origin!==base.origin){
    throw new Error('Browser Agent Runtime navigation is limited to the connected VP3 installation.');
  }
  return browserDelegationNavigateV2190(url.toString());
}

async function browserAgentRuntimeCloseTabsV2200(tabIds) {
  const ids=[...new Set((Array.isArray(tabIds)?tabIds:[]).map(Number).filter(id=>Number.isInteger(id)&&id>0))].slice(0,24);
  if(!ids.length)return {closed:[]};
  const closed=[];
  for(const id of ids){
    try{await chrome.tabs.remove(id);closed.push(id);}catch(_error){}
  }
  return {closed};
}

async function browserDelegationActionV2190(action, payload = {}) {
  const normalizedAction=String(action || 'list');
  const request={ ...payload };
  if(['preview','create','verify_navigation'].includes(normalizedAction)&&request.context){
    request.context=browserContextPayload(request.context);
  }
  return authorizedFetch('/api/extension-delegation-v2190.php', {
    method:'POST',
    json:{ action:normalizedAction, ...request }
  }, 'agent.message');
}

async function browserDelegationNavigateV2190(rawUrl) {
  const { base_url }=await config();
  const base=new URL(cleanBaseUrl(base_url));
  const url=new URL(String(rawUrl || ''),base.origin+'/');
  if(!/^https?:$/.test(url.protocol)||url.origin!==base.origin){
    throw new Error('Delegated navigation is limited to the authorized VP3 installation in v21.90.');
  }
  const tab=await chrome.tabs.create({url:url.toString(),active:true});
  if(!tab?.id)throw new Error('Delegated navigation could not open a VP3 tab.');
  await new Promise(resolve=>{
    let settled=false;
    const finish=()=>{if(settled)return;settled=true;chrome.tabs.onUpdated.removeListener(onUpdated);clearTimeout(timer);resolve();};
    const onUpdated=(tabId,changeInfo)=>{if(tabId===tab.id&&changeInfo.status==='complete')finish();};
    const timer=setTimeout(finish,12000);
    chrome.tabs.onUpdated.addListener(onUpdated);
  });
  const finalTab=await chrome.tabs.get(tab.id).catch(()=>tab);
  return {ok:true,tab_id:tab.id,source_url:String(finalTab?.url||url.toString()),title:String(finalTab?.title||'').slice(0,512)};
}

async function activeTabIdentity() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) return { available: false, source_url: '' };
  return {
    available: true,
    tab_id: tab.id,
    window_id: tab.windowId,
    source_url: String(tab.url || ''),
    title: String(tab.title || '').slice(0, 512)
  };
}

async function activeCapture(tabHint = null) {
  let tab = tabHint;
  if (!tab?.id) [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) return { available: false, reason: 'Open a normal web page to capture content.' };
  let result = { selected_text: '', canonical_url: '', media: null };
  try {
    const injected = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: async () => {
        const selected_text = String(window.getSelection?.() || '').trim();
        const canonical_url = document.querySelector('link[rel="canonical"]')?.href || '';
        const description = document.querySelector('meta[name="description"]')?.content || document.querySelector('meta[property="og:description"]')?.content || '';
        const author = document.querySelector('meta[name="author"]')?.content || '';
        const site_name = document.querySelector('meta[property="og:site_name"]')?.content || '';
        const language = document.documentElement?.lang || '';
        const pageText = String(document.body?.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 1000000);
        let page_text_sha256 = '';
        if (pageText) {
          const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(pageText));
          page_text_sha256 = [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
        }
        const candidate = document.querySelector('video, audio');
        let media = null;
        if (candidate) {
          const pageUrl = location.href;
          const rawSrc = candidate.currentSrc || candidate.src || '';
          const sourceUrl = /^https?:/i.test(rawSrc) ? rawSrc : pageUrl;
          const isYoutube = /(^|\.)youtube\.com$/i.test(location.hostname) || /(^|\.)youtu\.be$/i.test(location.hostname);
          const tag = candidate.tagName.toLowerCase();
          media = {
            kind: isYoutube ? 'youtube_clip' : (tag === 'audio' ? 'audio_reference' : 'video_reference'),
            source_media_url: isYoutube ? pageUrl : sourceUrl,
            source_media_title: document.title || '',
            source_media_kind: isYoutube ? 'youtube' : tag,
            current_time: Number.isFinite(candidate.currentTime) ? candidate.currentTime : 0,
            duration: Number.isFinite(candidate.duration) ? candidate.duration : 0,
            paused: Boolean(candidate.paused)
          };
        }
        return { selected_text, canonical_url, page_text_sha256, media, metadata:{ description, author, site_name, language } };
      }
    });
    result = injected?.[0]?.result || result;
  } catch {
    // Chrome blocks injection on privileged pages. Tab metadata remains usable.
  }
  return {
    available: true,
    tab_id: tab.id,
    window_id: tab.windowId,
    source_url: tab.url || '',
    canonical_url: result.canonical_url || tab.url || '',
    title: String(tab.title || '').slice(0, 512),
    selected_text: utf8Limit(String(result.selected_text || ''), 32768),
    page_text_sha256: /^[a-f0-9]{64}$/i.test(String(result.page_text_sha256 || '')) ? String(result.page_text_sha256).toLowerCase() : '',
    media: result.media || null,
    metadata: result.metadata || { description:'', author:'', site_name:'', language:'' },
    captured_at: new Date().toISOString()
  };
}

async function selectScreenshotRegion() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) throw new Error('Open a normal web page before capturing a screenshot.');
  const injected = await chrome.scripting.executeScript({
    target: { tabId: tab.id },
    func: () => new Promise((resolve) => {
      document.getElementById('vp3-region-capture-overlay')?.remove();
      const overlay = document.createElement('div');
      overlay.id = 'vp3-region-capture-overlay';
      Object.assign(overlay.style, { position:'fixed', inset:'0', zIndex:'2147483647', cursor:'crosshair', background:'rgba(0,0,0,.18)', userSelect:'none' });
      const hint = document.createElement('div');
      hint.textContent = 'Drag to capture a region · Esc to cancel';
      Object.assign(hint.style, { position:'fixed', top:'16px', left:'50%', transform:'translateX(-50%)', background:'#111', color:'#fff', padding:'8px 12px', borderRadius:'8px', font:'13px system-ui', pointerEvents:'none' });
      const box = document.createElement('div');
      Object.assign(box.style, { position:'fixed', border:'2px solid #fff', background:'rgba(255,255,255,.10)', boxShadow:'0 0 0 9999px rgba(0,0,0,.18)', display:'none', pointerEvents:'none' });
      overlay.append(hint, box);
      document.documentElement.append(overlay);
      let startX = 0, startY = 0, dragging = false;
      const cleanup = (value) => { window.removeEventListener('keydown', onKey, true); overlay.remove(); resolve(value); };
      const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault(); cleanup(null); } };
      window.addEventListener('keydown', onKey, true);
      overlay.addEventListener('pointerdown', (event) => {
        dragging = true; startX = event.clientX; startY = event.clientY; box.style.display = 'block'; overlay.setPointerCapture(event.pointerId);
      });
      overlay.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        const x = Math.min(startX, event.clientX), y = Math.min(startY, event.clientY);
        const width = Math.abs(event.clientX - startX), height = Math.abs(event.clientY - startY);
        Object.assign(box.style, { left:`${x}px`, top:`${y}px`, width:`${width}px`, height:`${height}px` });
      });
      overlay.addEventListener('pointerup', (event) => {
        if (!dragging) return;
        dragging = false;
        const x = Math.max(0, Math.min(startX, event.clientX));
        const y = Math.max(0, Math.min(startY, event.clientY));
        const width = Math.abs(event.clientX - startX);
        const height = Math.abs(event.clientY - startY);
        if (width < 8 || height < 8) return cleanup(null);
        cleanup({ x, y, width, height, device_pixel_ratio: window.devicePixelRatio || 1, viewport_width: innerWidth, viewport_height: innerHeight });
      });
    })
  });
  const rect = injected?.[0]?.result;
  if (!rect) return { cancelled: true };
  const dataUrl = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  const source = await fetch(dataUrl);
  const bitmap = await createImageBitmap(await source.blob());
  const viewportWidth = Math.max(1, Number(rect.viewport_width || 0));
  const viewportHeight = Math.max(1, Number(rect.viewport_height || 0));
  const fallbackScale = Math.max(0.5, Number(rect.device_pixel_ratio || 1));
  const scaleX = Number.isFinite(bitmap.width / viewportWidth) ? bitmap.width / viewportWidth : fallbackScale;
  const scaleY = Number.isFinite(bitmap.height / viewportHeight) ? bitmap.height / viewportHeight : fallbackScale;
  const sx = Math.min(bitmap.width - 1, Math.max(0, Math.round(rect.x * scaleX)));
  const sy = Math.min(bitmap.height - 1, Math.max(0, Math.round(rect.y * scaleY)));
  const sw = Math.min(bitmap.width - sx, Math.max(1, Math.round(rect.width * scaleX)));
  const sh = Math.min(bitmap.height - sy, Math.max(1, Math.round(rect.height * scaleY)));
  const canvas = new OffscreenCanvas(sw, sh);
  const context = canvas.getContext('2d');
  context.drawImage(bitmap, sx, sy, sw, sh, 0, 0, sw, sh);
  bitmap.close();
  const blob = await canvas.convertToBlob({ type: 'image/png' });
  if (blob.size > 8 * 1024 * 1024) throw new Error('Captured screenshot is larger than 8 MB. Select a smaller region.');
  const array = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  for (let i = 0; i < array.length; i += 0x8000) binary += String.fromCharCode(...array.subarray(i, Math.min(i + 0x8000, array.length)));
  return {
    cancelled: false,
    data_url: `data:image/png;base64,${btoa(binary)}`,
    metadata: { width: sw, height: sh, device_pixel_ratio: Number(rect.device_pixel_ratio || 1), capture_scale_x: scaleX, capture_scale_y: scaleY, x: rect.x, y: rect.y }
  };
}

async function beginConnect(deviceName) {
  const installation_id = await ensureInstallation();
  const { base_url } = await config();
  if (!(await ensureOriginPermission(base_url))) throw new Error('VP3 site permission was not granted.');

  const redirect_uri = chrome.identity.getRedirectURL('vp3-connect');
  const state = uuid();
  const authorization = new URL(apiUrl(base_url, '/extension-connect.php'));
  authorization.searchParams.set('installation_id', installation_id);
  authorization.searchParams.set('device_name', String(deviceName || 'Chrome Browser').slice(0, 120));
  authorization.searchParams.set('extension_version', VP3_EXTENSION_VERSION);
  authorization.searchParams.set('redirect_uri', redirect_uri);
  authorization.searchParams.set('state', state);

  const callback = await chrome.identity.launchWebAuthFlow({
    url: authorization.toString(),
    interactive: true
  });
  if (!callback) throw new Error('VP3 connection was cancelled.');

  const result = new URL(callback);
  if (result.searchParams.get('state') !== state) throw new Error('VP3 connection state did not match.');
  if (result.searchParams.get('error')) throw new Error('VP3 connection was cancelled.');
  const code = String(result.searchParams.get('code') || '');
  if (!/^[a-f0-9]{64}$/i.test(code)) throw new Error('VP3 did not return a valid connection code.');

  const payload = await fetchJson('/api/extension-token.php', {
    method: 'POST',
    json: { code, installation_id }
  });
  if (!/^[a-f0-9]{64}$/i.test(String(payload.device_token || ''))) {
    throw new Error('VP3 did not return a valid device token.');
  }

  await storage.set({ device_token: String(payload.device_token).toLowerCase() });
  await storage.remove(['device_id','device_credential','connected_user','approved_capabilities','pending_connection','session']);
  await ensureProactiveNotificationAlarm();
  void pollProactiveNotifications();
  return currentAccount();
}

async function pollConnect() {
  // v21 connects synchronously through chrome.identity.launchWebAuthFlow().
  // Keep this message as a no-op compatibility surface for an older side panel.
  return publicState();
}

async function destinations() {
  return authorizedFetch('/api/extension-share-destinations.php', { method: 'GET' }, 'team.destinations.read');
}

async function thisPage(capture, cursor = '') {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || ''))) {
    return { source: null, items: [], next_cursor: '', has_more: false };
  }
  const query = new URLSearchParams({
    action: 'this_page',
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || ''),
    title: String(capture.title || '').slice(0, 512),
    source_version_hash: String(capture.page_text_sha256 || ''),
    limit: '25'
  });
  if (cursor) query.set('cursor', cursor);
  return (await authorizedFetch('/api/browser-source-feed-v2050.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).feed;
}

async function followingFeed(cursor = '') {
  const query = new URLSearchParams({ action: 'following', limit: '20' });
  if (cursor) query.set('cursor', cursor);
  return (await authorizedFetch('/api/browser-source-feed-v2050.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).feed;
}

async function sourceFeedAction(action, payload = {}) {
  const capability = ['save', 'research'].includes(action)
    ? 'knowledge.write'
    : ['publish', 'share_team'].includes(action)
      ? 'team.share.create'
      : 'team.chat.read';
  return authorizedFetch('/api/browser-source-feed-v2050.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}

async function researchContext(browserShareId) {
  const query = new URLSearchParams({
    action: 'placements',
    browser_share_id: String(browserShareId || '')
  });
  return (await authorizedFetch('/api/research-projects-v2060.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).context;
}

async function researchAction(action, payload = {}) {
  return authorizedFetch('/api/research-projects-v2060.php', {
    method: 'POST',
    json: { action, ...payload }
  }, 'knowledge.write');
}

async function liveRoomsForSource(capture) {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || ''))) {
    return { source: null, rooms: [] };
  }
  const query = new URLSearchParams({
    action: 'source_rooms',
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || ''),
    title: String(capture.title || '').slice(0, 512)
  });
  return authorizedFetch('/api/live-rooms-v2070.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function liveRoomPoll(roomId, after = 0) {
  const query = new URLSearchParams({
    action: 'poll',
    room: String(roomId || ''),
    after: String(Math.max(0, Number(after || 0)))
  });
  return authorizedFetch('/api/live-rooms-v2070.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function liveRoomAction(action, payload = {}) {
  const capability = ['create', 'send', 'end'].includes(action) ? 'team.share.create' : 'team.chat.read';
  return authorizedFetch('/api/live-rooms-v2070.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}


async function browserTrustGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(params).map(([k,v]) => [k, String(v ?? '')])) });
  return authorizedFetch('/api/browser-trust-v2080.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function browserTrustAction(action, payload = {}) {
  const capability = ['claim_create', 'claim_status'].includes(action) ? 'team.share.create' : 'team.chat.read';
  return authorizedFetch('/api/browser-trust-v2080.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}

async function observeSource(capture) {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || '')) || !/^[a-f0-9]{64}$/i.test(String(capture.page_text_sha256 || ''))) {
    return { changed: false, source: null, changes: [] };
  }
  return browserTrustAction('observe_source', {
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || capture.source_url || ''),
    title: String(capture.title || '').slice(0, 512),
    source_version_hash: String(capture.page_text_sha256 || '').toLowerCase()
  });
}


async function browserSearchGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(params).filter(([,v]) => v !== undefined && v !== null && v !== '').map(([k,v]) => [k, String(v)])) });
  return authorizedFetch('/api/search-v2090.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function browserSearchAction(action, payload = {}) {
  return authorizedFetch('/api/search-v2090.php', { method: 'POST', json: { action, ...payload } }, 'team.chat.read');
}

function fallbackSelection(capture, rich = {}) {
  const selected = utf8Limit(String(capture?.selected_text || '').trim(), 32768);
  if (selected) return selected;
  const title = String(capture?.title || 'this page').trim() || 'this page';
  if (rich.screenshot?.data_url) return `Screenshot captured from ${title}.`;
  if (rich.media_reference?.kind) {
    const start = Number(rich.media_reference.metadata?.start_seconds || 0);
    const end = Number(rich.media_reference.metadata?.end_seconds || start);
    return `Media clip ${secondsLabel(start)}–${secondsLabel(end)} captured from ${title}.`;
  }
  if (rich.commentary?.data_url) return `Voice commentary captured from ${title}.`;
  return '';
}

async function createShare(input) {
  const capture = input?.capture || {};
  const rich = input?.rich_media || {};
  const selection = fallbackSelection(capture, rich);
  if (!selection) throw new Error('Highlight text or add a rich capture before sharing.');
  const destination = input?.destination || {};
  if (!['team_general', 'conversation'].includes(destination.kind) || !Number(destination.id)) throw new Error('Choose a VP3 destination.');
  const sourceUrl = String(capture.source_url || '');
  const canonicalUrl = String(capture.canonical_url || sourceUrl);
  if (!/^https?:\/\//i.test(sourceUrl) || !/^https?:\/\//i.test(canonicalUrl)) throw new Error('This page cannot be shared.');
  const idempotencyKey = input.idempotency_key || uuid();
  const payload = await authorizedFetch('/api/browser-share.php', {
    method: 'POST',
    headers: { 'X-VP3-Idempotency-Key': idempotencyKey },
    json: {
      schema_version: 1,
      share_type: 'selection',
      destination: { kind: destination.kind, id: Number(destination.id) },
      source: {
        url: sourceUrl,
        canonical_url: canonicalUrl,
        title: String(capture.title || '').slice(0, 512)
      },
      snapshot: {
        selected_text: selection,
        captured_at: capture.captured_at || new Date().toISOString()
      },
      message: { note: utf8Limit(String(input.note || ''), 4096) }
    }
  }, 'team.share.create');
  const lastShare = {
    browser_share: payload.browser_share || null,
    chat_message: payload.chat_message || null,
    idempotent_replay: Boolean(payload.idempotent_replay),
    created_at: new Date().toISOString()
  };
  await storage.set({ last_share: lastShare });
  await storage.remove('pending_capture');
  return payload;
}

async function uploadBinaryMedia(browserShareId, kind, dataUrl, metadata = {}, name = '') {
  const { mime, bytes } = dataUrlBytes(dataUrl);
  return authorizedFetch(`/api/browser-share-media-v2040.php?browser_share_id=${encodeURIComponent(browserShareId)}`, {
    method: 'POST',
    headers: {
      'Content-Type': mime,
      'X-VP3-Media-Kind': kind,
      'X-VP3-Media-Name': encodeURIComponent(String(name || '').slice(0, 180)),
      'X-VP3-Media-Metadata': base64UrlJson(metadata)
    },
    body: bytes
  }, 'team.share.create');
}

async function createMediaReference(browserShareId, reference) {
  return authorizedFetch('/api/browser-share-media-v2040.php', {
    method: 'POST',
    json: {
      browser_share_id: browserShareId,
      kind: reference.kind,
      metadata: reference.metadata || {}
    }
  }, 'team.share.create');
}

async function createRichShare(input) {
  const payload = await createShare(input);
  const browserShareId = String(payload?.browser_share?.id || '');
  if (!browserShareId) return { ...payload, media: [], media_errors: ['Browser Share media could not resolve the new share ID.'] };
  const media = [];
  const media_errors = [];
  const rich = input?.rich_media || {};
  if (rich.screenshot?.data_url) {
    try {
      const result = await uploadBinaryMedia(browserShareId, 'screenshot', rich.screenshot.data_url, rich.screenshot.metadata || {}, 'browser-share-screenshot.png');
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Screenshot: ${error.message}`); }
  }
  if (rich.media_reference?.kind) {
    try {
      const result = await createMediaReference(browserShareId, rich.media_reference);
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Media reference: ${error.message}`); }
  }
  if (rich.commentary?.data_url) {
    try {
      const extension = /ogg/i.test(rich.commentary.mime_type || '') ? 'ogg' : /mp4/i.test(rich.commentary.mime_type || '') ? 'm4a' : 'webm';
      const result = await uploadBinaryMedia(browserShareId, 'commentary_audio', rich.commentary.data_url, rich.commentary.metadata || {}, `browser-share-commentary.${extension}`);
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Commentary: ${error.message}`); }
  }
  const visibility = ['private', 'team', 'public'].includes(String(input?.visibility || ''))
    ? String(input.visibility)
    : 'private';
  const teamId = visibility === 'team' ? Number(input?.visibility_team_id || 0) : 0;
  const publication = await sourceFeedAction('publish', {
    browser_share_id: browserShareId,
    visibility,
    team_id: teamId,
    source_version_hash: String(input?.capture?.page_text_sha256 || '')
  });
  return { ...payload, media, media_errors, annotation: publication.annotation || null };
}

async function browserShareAction(action, browserShareId, folderId = 0) {
  if (!['ask_agent', 'save_knowledge', 'create_task'].includes(action)) throw new Error('Unsupported Browser Share action.');
  const capability = action === 'save_knowledge' ? 'knowledge.write' : action === 'create_task' ? 'task.propose' : 'agent.message';
  return authorizedFetch('/api/extension-browser-share-actions-v2030.php', {
    method: 'POST',
    json: { action, browser_share_id: String(browserShareId || ''), folder_id: Number(folderId || 0) }
  }, capability);
}

async function cognitiveNow() {
  const payload = await authorizedFetch('/api/extension-cognitive-now-v2120.php', { method: 'GET' }, 'agent.message');
  return payload.feed || null;
}

async function cognitiveAction(action, payload = {}) {
  return authorizedFetch('/api/extension-cognitive-now-v2120.php', {
    method: 'POST',
    json: { action, ...payload }
  }, 'agent.message');
}

function browserContextPayload(capture) {
  const x = capture && typeof capture === 'object' ? capture : {};
  return {
    source_url:String(x.source_url || ''),
    canonical_url:String(x.canonical_url || ''),
    title:String(x.title || '').slice(0,512),
    selected_text:utf8Limit(String(x.selected_text || ''),12000),
    page_text_sha256:/^[a-f0-9]{64}$/i.test(String(x.page_text_sha256 || '')) ? String(x.page_text_sha256).toLowerCase() : '',
    metadata:{
      description:String(x.metadata?.description || '').slice(0,1000),
      author:String(x.metadata?.author || '').slice(0,240),
      site_name:String(x.metadata?.site_name || '').slice(0,240),
      language:String(x.metadata?.language || '').slice(0,32)
    },
    media:x.media || null
  };
}

function browserAgentContextV2160(capture) {
  const page = browserContextPayload(capture);
  if (!page.source_url) return null;
  return {
    browser_context:{
      contract:'browser-context-v2130',
      ephemeral:true,
      page:{
        url:page.source_url,
        canonical_url:page.canonical_url,
        title:page.title,
        selected_text:page.selected_text,
        metadata:page.metadata,
        media:page.media
      },
      relationships:[],
      prompt:''
    }
  };
}

async function agentWorkspaceV2160(action, payload = {}) {
  const request = { action:String(action || 'list'), ...payload };
  if (request.use_context) {
    const capture = request.capture || await activeCapture();
    delete request.capture;
    delete request.use_context;
    const agentContext = browserAgentContextV2160(capture);
    if (agentContext) request.agent_context = agentContext;
  } else {
    delete request.capture;
    delete request.use_context;
  }
  return authorizedFetch('/api/extension-agent-chat-v2160.php', {
    method:'POST',
    json:request
  }, 'agent.message');
}

async function contextualNow(capture, prompt = '') {
  return authorizedFetch('/api/extension-cognitive-now-v2120.php', {
    method:'POST',
    json:{ action:'context_feed', context:browserContextPayload(capture), prompt:String(prompt || '').slice(0,1200) }
  }, 'agent.message');
}

async function contextHandoff(payload, prompt = '') {
  if (!payload || payload.contract !== 'browser-context-v2130' || !payload.page) throw new Error('Current page context is unavailable.');
  const safe = {
    contract:'browser-context-v2130',
    ephemeral:true,
    page:payload.page,
    relationships:Array.isArray(payload.relationships) ? payload.relationships.slice(0,15) : [],
    prompt:String(prompt || payload.prompt || '').slice(0,1200)
  };
  const encoded = base64UrlJson(safe);
  if (encoded.length > 24000) throw new Error('Current page context is too large to hand off safely.');
  const { base_url } = await config();
  return { url:`${cleanBaseUrl(base_url)}/chat.php#vp3-browser-context=${encoded}` };
}

const VP3_NOTIFICATION_ALARM_V2140 = 'vp3-proactive-notifications-v2140';
const VP3_NOTIFICATION_PREFIX_V2140 = 'vp3-notify:';
let proactivePollPromiseV2140 = null;
let proactiveVoicePromiseV2140 = null;

function notificationIdForEvent(eventKey) {
  return VP3_NOTIFICATION_PREFIX_V2140 + String(eventKey || '');
}

function eventKeyFromNotificationId(notificationId) {
  const value = String(notificationId || '');
  return value.startsWith(VP3_NOTIFICATION_PREFIX_V2140)
    ? value.slice(VP3_NOTIFICATION_PREFIX_V2140.length)
    : '';
}

async function proactiveNotificationApi(action, payload = {}) {
  return authorizedFetch('/api/extension-notifications-v2140.php', {
    method:'POST',
    json:{ action, ...payload }
  }, 'notifications.read');
}

async function proactiveNotificationContext() {
  try {
    const tab = await activeTabIdentity();
    return tab?.available ? { source_url:String(tab.source_url || ''), title:String(tab.title || '').slice(0,512) } : {};
  } catch (_error) {
    return {};
  }
}

async function ensureProactiveNotificationAlarm() {
  if (!chrome.alarms) return;
  const existing = await chrome.alarms.get(VP3_NOTIFICATION_ALARM_V2140);
  if (!existing) {
    chrome.alarms.create(VP3_NOTIFICATION_ALARM_V2140, { delayInMinutes:0.2, periodInMinutes:1 });
  }
}

async function createProactiveNotification(candidate) {
  if (!candidate?.event_key || !candidate?.claim_token) return null;
  const id = notificationIdForEvent(candidate.event_key);
  const message = String(candidate.body || '').trim() || 'Open VP3 to review this update.';
  const options = {
    type:'basic',
    iconUrl:chrome.runtime.getURL('notification-icon.png'),
    title:String(candidate.title || 'VP3 update').slice(0,190),
    message:message.slice(0,420),
    contextMessage:candidate.context_related ? 'Related to the page you are viewing' : '',
    buttons:[
      { title:String(candidate.action_label || 'Open VP3').slice(0,80) },
      { title:'Snooze 15 min' }
    ],
    priority:Number(candidate.priority || 0) >= 90 ? 2 : 1,
    requireInteraction:Number(candidate.priority || 0) >= 100
  };
  let created = false;
  try {
    await chrome.notifications.create(id, options);
    created = true;
  } catch (error) {
    try {
      await proactiveNotificationApi('release', {
        event_key:candidate.event_key,
        claim_token:candidate.claim_token
      });
    } catch (_releaseError) {}
    throw error;
  }
  if (!created) return null;
  // Once Chrome has shown the interruption, never release the server claim on
  // an acknowledgement network failure. Releasing would allow another browser
  // to display a duplicate while this one is already visible.
  const delivered = await proactiveNotificationApi('visual_delivered', {
    event_key:candidate.event_key,
    claim_token:candidate.claim_token
  });
  return delivered?.voice || null;
}

async function authorizedVoiceAudioDataUrl(eventKey) {
  const token = await deviceToken();
  const { base_url } = await config();
  if (!(await ensureOriginPermission(base_url))) throw new Error('VP3 site permission was not granted.');
  const response = await fetch(apiUrl(base_url, '/api/extension-agent-voice-v2140.php'), {
    method:'POST',
    headers:{
      Authorization:`Bearer ${token}`,
      'Content-Type':'application/json',
      'X-VP3-Extension-Version':VP3_EXTENSION_VERSION,
      'X-VP3-Contract-Version':VP3_CONTRACT_VERSION,
      Accept:'audio/mpeg,application/json'
    },
    body:JSON.stringify({ event_key:String(eventKey || '') }),
    cache:'no-store',
    credentials:'omit'
  });
  if (response.status === 401) {
    await clearRevokedConnection();
    const revoked = new Error('This browser connection was revoked. Reconnect to VP3.');
    revoked.status = 401;
    revoked.code = 'reconnect_required';
    throw revoked;
  }
  const contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
  if (!response.ok || !contentType.startsWith('audio/')) {
    let message = 'Premium Agent Voice is temporarily unavailable.';
    try {
      const data = await response.json();
      if (data?.error?.message) message = String(data.error.message);
    } catch (_error) {}
    throw new Error(message);
  }
  const blob = await response.blob();
  if (!blob.size || blob.size > 4 * 1024 * 1024) throw new Error('Agent Voice audio is invalid.');
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
  }
  return `data:${blob.type || 'audio/mpeg'};base64,${btoa(binary)}`;
}

async function ensureVoiceOffscreenDocument() {
  if (!chrome.offscreen) throw new Error('Offscreen Agent Voice playback is unavailable.');
  let exists = false;
  if (typeof chrome.runtime.getContexts === 'function') {
    const contexts = await chrome.runtime.getContexts({
      contextTypes:['OFFSCREEN_DOCUMENT'],
      documentUrls:[chrome.runtime.getURL('offscreen.html')]
    });
    exists = Array.isArray(contexts) && contexts.length > 0;
  } else if (typeof chrome.offscreen.hasDocument === 'function') {
    exists = await chrome.offscreen.hasDocument();
  }
  if (exists) return;
  try {
    await chrome.offscreen.createDocument({
      url:'offscreen.html',
      reasons:['AUDIO_PLAYBACK'],
      justification:'Play VP3 Agent Voice proactive notifications.'
    });
  } catch (error) {
    if (!/single offscreen document|already exists/i.test(String(error?.message || error || ''))) throw error;
  }
}

async function vp3AgentVoiceBusy() {
  const { base_url } = await config();
  const base = new URL(cleanBaseUrl(base_url));
  const [activeTabs,audibleTabs] = await Promise.all([
    chrome.tabs.query({ active:true }),
    chrome.tabs.query({ audible:true })
  ]);
  const isVp3Chat = tab => {
    try {
      const url = new URL(String(tab.url || ''));
      return url.origin === base.origin && /\/chat(?:\.php)?\/?$/i.test(url.pathname);
    } catch (_error) {
      return false;
    }
  };
  if (activeTabs.some(isVp3Chat)) return true;
  // Do not talk over an existing VP3 voice response even when its tab is not
  // foregrounded. Ignore unrelated audible tabs such as music/video sites.
  return audibleTabs.some(tab => {
    try { return new URL(String(tab.url || '')).origin === base.origin; }
    catch (_error) { return false; }
  });
}

async function playProactiveVoice(candidate) {
  if (!candidate?.event_key || !candidate?.text) return false;
  if (proactiveVoicePromiseV2140) return proactiveVoicePromiseV2140;
  proactiveVoicePromiseV2140 = (async () => {
    if (await vp3AgentVoiceBusy()) return false;
    try {
      const dataUrl = await authorizedVoiceAudioDataUrl(candidate.event_key);
      await ensureVoiceOffscreenDocument();
      const result = await chrome.runtime.sendMessage({ type:'vp3_voice_play_v2140', data_url:dataUrl });
      if (!result?.ok) throw new Error(result?.error || 'Agent Voice playback failed.');
      await proactiveNotificationApi('voice_delivered', { event_key:candidate.event_key });
      return true;
    } catch (error) {
      try { await proactiveNotificationApi('voice_failed', { event_key:candidate.event_key }); } catch (_error) {}
      return false;
    }
  })();
  try { return await proactiveVoicePromiseV2140; }
  finally { proactiveVoicePromiseV2140 = null; }
}

async function pollProactiveNotifications() {
  if (proactivePollPromiseV2140) return proactivePollPromiseV2140;
  proactivePollPromiseV2140 = (async () => {
    const state = await storage.get(['device_token']);
    if (!state.device_token) return { visual:false, voice:false };
    try {
      const current_context = await proactiveNotificationContext();
      const payload = await proactiveNotificationApi('poll', { current_context });
      let voice = payload?.voice || null;
      if (payload?.visual) {
        const immediateVoice = await createProactiveNotification(payload.visual);
        if (immediateVoice) voice = immediateVoice;
      }
      if (voice) await playProactiveVoice(voice);
      return { visual:Boolean(payload?.visual), voice:Boolean(voice) };
    } catch (error) {
      if (error?.code === 'reconnect_required' || error?.status === 401) return { visual:false, voice:false };
      return { visual:false, voice:false, error:String(error?.message || error || '') };
    }
  })();
  try { return await proactivePollPromiseV2140; }
  finally { proactivePollPromiseV2140 = null; }
}

async function handleProactiveNotificationAction(eventKey, action) {
  if (!eventKey) return;
  if (action === 'open') {
    const result = await proactiveNotificationApi('open', { event_key:eventKey });
    const target = String(result?.target_url || '/chat.php');
    const { base_url } = await config();
    await chrome.tabs.create({ url:new URL(target, cleanBaseUrl(base_url) + '/').toString() });
  } else if (action === 'snooze') {
    await proactiveNotificationApi('snooze', { event_key:eventKey });
  } else if (action === 'dismiss') {
    await proactiveNotificationApi('dismiss', { event_key:eventKey });
  }
  try { await chrome.notifications.clear(notificationIdForEvent(eventKey)); } catch (_error) {}
}

const VP3_QUICK_ACTIONS_V2150 = Object.freeze({
  ask_page:{ suggestion:'ask_page', title:'Ask VP3 Agent' },
  summarize:{ suggestion:'summarize', title:'Summarize with VP3' },
  compare_knowledge:{ suggestion:'compare_knowledge', title:'Compare with Knowledge' },
  prepare_meeting:{ suggestion:'prepare_meeting', title:'Prepare for related meeting' },
  add_research:{ suggestion:'add_research', title:'Draft Research note' },
  save_knowledge:{ suggestion:'save_knowledge', title:'Draft Knowledge entry' },
  create_task:{ suggestion:'create_task', title:'Propose Task' },
  share_team:{ flow:'share_team', title:'Share with Team' },
  annotate:{ flow:'annotate', title:'Annotate / Capture' }
});

function quickActionMenuIdV2150(action) {
  return 'vp3-quick-' + String(action || '');
}

async function registerQuickActionMenusV2150() {
  await new Promise(resolve => chrome.contextMenus.removeAll(() => resolve()));
  chrome.contextMenus.create({
    id:'vp3-quick-root',
    title:'VP3',
    contexts:['page','selection','link','image','video','audio']
  });
  for (const [action,config] of Object.entries(VP3_QUICK_ACTIONS_V2150)) {
    chrome.contextMenus.create({
      id:quickActionMenuIdV2150(action),
      parentId:'vp3-quick-root',
      title:config.title,
      contexts:['page','selection','link','image','video','audio']
    });
  }
}

async function quickActionCaptureV2150(info, tab) {
  const capture = await activeCapture(tab);
  if (info?.selectionText) capture.selected_text = utf8Limit(String(info.selectionText).trim(), 12000);

  const safeContextUrl = value => {
    try {
      const url = new URL(String(value || '').trim());
      return /^https?:$/.test(url.protocol) ? url.href : '';
    } catch (_error) { return ''; }
  };
  const linkUrl = safeContextUrl(info?.linkUrl);
  const srcUrl = safeContextUrl(info?.srcUrl);
  const mediaType = String(info?.mediaType || '').toLowerCase();
  if (linkUrl && !capture.selected_text) {
    capture.selected_text = utf8Limit('Link: ' + linkUrl, 12000);
  }
  if (srcUrl) {
    const label = mediaType === 'image' ? 'Image' : mediaType === 'video' ? 'Video' : mediaType === 'audio' ? 'Audio' : 'Media';
    if (!capture.selected_text) capture.selected_text = utf8Limit(label + ': ' + srcUrl, 12000);
    if (mediaType === 'video' || mediaType === 'audio') {
      capture.media = {
        kind:mediaType === 'audio' ? 'audio_reference' : 'video_reference',
        source_media_kind:mediaType,
        source_media_url:srcUrl,
        source_media_title:String(capture.title || '').slice(0,300),
        current_time:0,
        duration:0
      };
    }
    capture.quick_target_v2150 = { kind:mediaType || 'media', url:srcUrl.slice(0,2048) };
  } else if (linkUrl) {
    capture.quick_target_v2150 = { kind:'link', url:linkUrl.slice(0,2048) };
  }
  capture.captured_at = new Date().toISOString();
  return capture;
}

async function openQuickActionComposerV2150(capture, flow, tab) {
  const pendingCapture = {
    ...capture,
    selected_text:utf8Limit(String(capture?.selected_text || ''),32768),
    title:String(capture?.title || '').slice(0,512),
    captured_at:new Date().toISOString()
  };
  await chrome.storage.session.set({
    pending_quick_action_v2150:{
      action:String(flow || 'annotate'),
      capture:pendingCapture,
      created_at:Date.now()
    }
  });
  if (tab?.id) {
    try { await chrome.sidePanel.open({ tabId:tab.id }); } catch (_error) {}
  }
  return { mode:'sidepanel', action:flow };
}

async function resolveQuickActionV2150(action, capture, tab) {
  const config = VP3_QUICK_ACTIONS_V2150[action];
  if (!config) throw new Error('Unknown VP3 quick action.');
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || ''))) {
    throw new Error('Open a normal web page to use VP3 quick actions.');
  }
  // Composer-only quick actions still require a live VP3 connection and
  // the same live capability as the canonical annotation/Team publish flow.
  // This avoids leaving a transient selection stranded behind a hidden composer.
  const account = await currentAccount();
  const liveCapabilities = new Set(Array.isArray(account?.capabilities) ? account.capabilities : []);
  if (config.flow) {
    if (!liveCapabilities.has('team.share.create')) {
      const error = new Error('This VP3 account cannot publish Browser annotations or Team shares.');
      error.code = 'capability_denied';
      throw error;
    }
    return openQuickActionComposerV2150(capture, config.flow, tab);
  }

  const contextual = await contextualNow(capture);
  const suggestion = (Array.isArray(contextual?.suggestions) ? contextual.suggestions : [])
    .find(item => item && item.id === config.suggestion);
  if (!suggestion) throw new Error(config.title + ' is not available for this page or account.');

  if (suggestion.kind === 'agent_prompt') {
    const handoff = await contextHandoff(contextual.agent_payload, suggestion.prompt || '');
    await chrome.tabs.create({ url:handoff.url });
    return { mode:'agent', action, suggestion_id:suggestion.id };
  }
  if (suggestion.kind === 'open' && suggestion.url) {
    const { base_url } = await config();
    await chrome.tabs.create({ url:new URL(String(suggestion.url), cleanBaseUrl(base_url) + '/').toString() });
    return { mode:'open', action, suggestion_id:suggestion.id };
  }
  if (suggestion.kind === 'manual_flow') {
    return openQuickActionComposerV2150(capture, suggestion.target === 'this_page' ? 'share_team' : action, tab);
  }
  throw new Error(config.title + ' requires review in VP3.');
}

async function consumeQuickActionV2150() {
  const state = await chrome.storage.session.get(['pending_quick_action_v2150']);
  const pending = state.pending_quick_action_v2150;
  await chrome.storage.session.remove('pending_quick_action_v2150');
  if (!pending || typeof pending !== 'object') return null;
  const age = Date.now() - Number(pending.created_at || 0);
  if (!Number.isFinite(age) || age < 0 || age > 5 * 60 * 1000) return null;
  return pending;
}

async function quickActionFailureV2150(error) {
  const message = String(error?.message || error || 'VP3 quick action is unavailable.').slice(0,240);
  try {
    await chrome.notifications.create('vp3-quick-error', {
      type:'basic',
      iconUrl:chrome.runtime.getURL('notification-icon.png'),
      title:'VP3 quick action',
      message,
      priority:0
    });
  } catch (_error) {}
}

async function disconnect() {
  const state = await storage.get(['device_token']);
  if (state.device_token) {
    try {
      await authorizedFetch('/api/extension-device-disconnect-v2030.php', { method: 'POST', json: {} });
    } catch (error) {
      if (error.status !== 401) throw error;
    }
  }
  await clearRevokedConnection();
  return { ok: true };
}

async function publicState() {
  const state = await storage.get(['base_url','installation_id','device_token','last_share','pending_capture']);
  const base = {
    base_url: cleanBaseUrl(state.base_url || VP3_DEFAULT_BASE),
    installation_id: state.installation_id || '',
    connected: false,
    device_id: '',
    user: null,
    capabilities: [],
    pending_connection: null,
    last_share: state.last_share || null,
    pending_capture: state.pending_capture || null
  };
  if (!state.device_token) return base;

  try {
    const account = await currentAccount();
    return {
      ...base,
      connected: true,
      device_id: account.device_id || '',
      user: account.user || null,
      capabilities: Array.isArray(account.capabilities) ? account.capabilities : []
    };
  } catch (error) {
    if (error.code === 'reconnect_required' || error.status === 401) return base;
    throw error;
  }
}

chrome.runtime.onInstalled.addListener(() => {
  void registerQuickActionMenusV2150();
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
  void ensureProactiveNotificationAlarm();
});

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  const menuId = String(info?.menuItemId || '');
  if (!menuId.startsWith('vp3-quick-') || menuId === 'vp3-quick-root' || !tab?.id) return;
  const action = menuId.slice('vp3-quick-'.length);
  try {
    const capture = await quickActionCaptureV2150(info, tab);
    await resolveQuickActionV2150(action, capture, tab);
  } catch (error) {
    await quickActionFailureV2150(error);
  }
});

chrome.runtime.onStartup.addListener(() => {
  void registerQuickActionMenusV2150();
  void ensureProactiveNotificationAlarm();
  void pollProactiveNotifications();
});

chrome.alarms.onAlarm.addListener(alarm => {
  if (alarm?.name === VP3_NOTIFICATION_ALARM_V2140) void pollProactiveNotifications();
});

chrome.notifications.onClicked.addListener(notificationId => {
  const eventKey = eventKeyFromNotificationId(notificationId);
  if (eventKey) void handleProactiveNotificationAction(eventKey, 'open');
});

chrome.notifications.onButtonClicked.addListener((notificationId, buttonIndex) => {
  const eventKey = eventKeyFromNotificationId(notificationId);
  if (!eventKey) return;
  void handleProactiveNotificationAction(eventKey, buttonIndex === 1 ? 'snooze' : 'open');
});

chrome.notifications.onClosed.addListener((notificationId, byUser) => {
  if (!byUser) return;
  const eventKey = eventKeyFromNotificationId(notificationId);
  if (eventKey) void handleProactiveNotificationAction(eventKey, 'dismiss');
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const run = async () => {
    switch (message?.type) {
      case 'state': return publicState();
      case 'capture': return activeCapture();
      case 'tab_identity': return activeTabIdentity();
      case 'capture_region': return selectScreenshotRegion();
      case 'clear_pending_capture': await storage.remove('pending_capture'); return { ok: true };
      case 'connect': return beginConnect(message.device_name);
      case 'poll_connect': return pollConnect();
      case 'destinations': return destinations();
      case 'this_page': return thisPage(message.capture || await activeCapture(), message.cursor || '');
      case 'following': return followingFeed(message.cursor || '');
      case 'source_action': return sourceFeedAction(message.action, message.payload || {});
      case 'research_context': return researchContext(message.browser_share_id);
      case 'research_action': return researchAction(message.action, message.payload || {});
      case 'live_rooms': return liveRoomsForSource(message.capture || await activeCapture());
      case 'live_poll': return liveRoomPoll(message.room, message.after || 0);
      case 'live_action': return liveRoomAction(message.action, message.payload || {});
      case 'trust_observe': return observeSource(message.capture || await activeCapture());
      case 'trust_notifications': return browserTrustGet('notifications', { limit: message.limit || 50 });
      case 'trust_history': return browserTrustGet('source_history', { source_id: message.source_id || '', limit: message.limit || 25 });
      case 'trust_claims': return browserTrustGet('claims_for_source', { source_id: message.source_id || '' });
      case 'trust_action': return browserTrustAction(message.action, message.payload || {});
      case 'search_query': return browserSearchGet('search', message.params || {});
      case 'search_discover': return browserSearchGet('discover', message.params || {});
      case 'search_recent': return browserSearchGet('recent', {});
      case 'search_saved': return browserSearchGet('saved', {});
      case 'search_action': return browserSearchAction(message.action, message.payload || {});
      case 'media_data': return authorizedMediaDataUrl(String(message.path || ''));
      case 'share': return createRichShare(message);
      case 'share_action': return browserShareAction(message.action, message.browser_share_id, message.folder_id);
      case 'cognitive_now': return cognitiveNow();
      case 'cognitive_action': return cognitiveAction(message.action, message.payload || {});
      case 'context_now': return contextualNow(message.capture || await activeCapture(), message.prompt || '');
      case 'context_handoff': return contextHandoff(message.payload || null, message.prompt || '');
      case 'agent_workspace': return agentWorkspaceV2160(message.action, message.payload || {});
      case 'memory_action': return browserMemoryActionV2170(message.action, message.payload || {});
      case 'execution_action': return browserExecutionActionV2180(message.action, message.payload || {});
      case 'delegation_action': return browserDelegationActionV2190(message.action, message.payload || {});
      case 'delegation_navigate': return browserDelegationNavigateV2190(message.url || '');
      case 'runtime_action': return browserAgentRuntimeActionV2200(message.action, message.payload || {});
      case 'runtime_navigate': return browserAgentRuntimeNavigateV2200(message.url || '');
      case 'runtime_close_tabs': return browserAgentRuntimeCloseTabsV2200(message.tab_ids || []);
      case 'web_interaction_observe': return browserWebInteractionObserveV2210(message.payload || {});
      case 'web_interaction_action': return browserWebInteractionApiV2210(message.action, message.payload || {});
      case 'web_interaction_execute': return browserWebInteractionExecuteV2210(message.payload || {});
      case 'notification_poll': return pollProactiveNotifications();
      case 'quick_action_consume': return consumeQuickActionV2150();
      case 'quick_action_run': {
        const action = String(message.action || '');
        const capture = message.capture || await activeCapture();
        return resolveQuickActionV2150(action, capture, null);
      }
      case 'disconnect': return disconnect();
      case 'open_url': {
        const url = String(message.url || '');
        if (!/^https?:\/\//i.test(url)) throw new Error('Only HTTP(S) links can be opened.');
        await chrome.tabs.create({ url });
        return { ok: true };
      }
      case 'set_base_url': {
        const base_url = cleanBaseUrl(message.base_url);
        const current = await config();
        if (base_url !== current.base_url) {
          const connection = await storage.get(['device_token']);
          if (connection.device_token) {
            throw new Error('Disconnect this browser from the current VP3 site before changing the VP3 site.');
          }
        }
        if (!(await ensureOriginPermission(base_url))) throw new Error('VP3 site permission was not granted.');
        await storage.set({ base_url });
        return { base_url };
      }
      default: throw new Error('Unsupported Browser Companion request.');
    }
  };
  run().then(value => sendResponse({ ok: true, value })).catch(error => sendResponse({ ok: false, error: error.message, code: error.code || '' }));
  return true;
});