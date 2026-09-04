import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const repoFile = path => fileURLToPath(new URL(`../${path}`, import.meta.url));

class FakeClassList {
  constructor() { this.set = new Set(); }
  toggle(name, force) {
    if (force === undefined) force = !this.set.has(name);
    if (force) this.set.add(name); else this.set.delete(name);
    return force;
  }
  contains(name) { return this.set.has(name); }
  add(...names) { names.forEach(n => this.set.add(n)); }
  remove(...names) { names.forEach(n => this.set.delete(n)); }
}

class FakeElement {
  constructor(tag='div', doc=null) {
    this.tagName = tag.toUpperCase();
    this.doc = doc;
    this.id = '';
    this.dataset = {};
    this.classList = new FakeClassList();
    this.attributes = new Map();
    this.listeners = new Map();
    this.children = [];
    this.hidden = false;
    this.textContent = '';
    this.value = '';
    this.innerHTML = '';
    this.parent = null;
  }
  setAttribute(k,v) { this.attributes.set(k,String(v)); if(k==='id') this.id=String(v); }
  getAttribute(k) { return this.attributes.get(k) ?? null; }
  addEventListener(type, fn) { if(!this.listeners.has(type)) this.listeners.set(type,[]); this.listeners.get(type).push(fn); }
  dispatchEvent(event) { event.target ??= this; for(const fn of this.listeners.get(event.type)||[]) fn(event); return true; }
  cloneNode() {
    const c = new FakeElement(this.tagName.toLowerCase(), this.doc);
    c.id = this.id; c.dataset = {...this.dataset}; c.hidden=this.hidden; c.textContent=this.textContent; c.value=this.value;
    c.classList.set = new Set(this.classList.set); c.attributes = new Map(this.attributes);
    return c;
  }
  replaceWith(next) { if(this.doc && this.id) this.doc.byId.set(this.id,next); next.doc=this.doc; }
  appendChild(child) { child.parent=this; this.children.push(child); if(this.doc && child.id) this.doc.byId.set(child.id,child); return child; }
  append(...children) { children.forEach(c=>this.appendChild(c)); }
  remove() {}
  querySelector() { return null; }
  querySelectorAll() { return []; }
}

class FakeDocument {
  constructor() {
    this.byId = new Map();
    this.documentElement = {lang:'en-US'};
    this.readyState = 'complete';
    this.listeners = new Map();
    this.head = new FakeElement('head',this);
    this.body = new FakeElement('body',this);
  }
  register(id, tag='div') { const el=new FakeElement(tag,this); el.id=id; this.byId.set(id,el); return el; }
  getElementById(id) { return this.byId.get(id)||null; }
  createElement(tag) { return new FakeElement(tag,this); }
  querySelector(selector) {
    if(selector.includes('data-team-chat-admin-v108')) return this.head.children.find(x=>x.dataset.teamChatAdminV108)||null;
    if(selector.includes('data-team-chat-v108-style')) return this.head.children.find(x=>x.dataset.teamChatV108Style)||null;
    if(selector.includes('data-team-chat-v108-runtime')) return this.body.children.find(x=>x.dataset.teamChatV108Runtime)||null;
    return null;
  }
  querySelectorAll(selector) {
    if(selector.startsWith('#agentNextMovesCanvas')) return [];
    return [];
  }
  addEventListener(type,fn){ if(!this.listeners.has(type))this.listeners.set(type,[]);this.listeners.get(type).push(fn); }
}

class FakeMutationObserver { constructor(fn){this.fn=fn;} observe(){} disconnect(){} }
class FakeEvent { constructor(type,opts={}){this.type=type;this.bubbles=!!opts.bubbles;this.target=null;} preventDefault(){} stopPropagation(){} stopImmediatePropagation(){} }

async function testChatRuntime() {
  const doc = new FakeDocument();
  const original = doc.register('chatVoiceButton','button');
  const status = doc.register('chatVoiceStatus');
  const form = doc.register('chatForm','form');
  form.requestSubmit = () => {};
  const input = doc.register('chatInput','textarea');
  const thread = doc.register('chatThread');
  thread.querySelectorAll = sel => sel === '.message.assistant' ? [] : [];
  let legacyClicked=false;
  original.addEventListener('click',()=>{legacyClicked=true;});

  class Recognition {
    static latest=null;
    constructor(){ Recognition.latest=this; }
    start(){ this.onstart?.(); }
    abort(){ this.onend?.(); }
    stop(){ this.onend?.(); }
  }

  const nativeSpeak=()=>{}; const nativeCancel=()=>{};
  const synth={speak:nativeSpeak,cancel:nativeCancel};
  const windowObj={
    STONEFELLOW_CHAT:{endpoint:'/api/chat.php',csrf:'x',userId:1},
    STONEFELLOW_CHAT_CONTINUITY_V87:{},
    speechSynthesis:synth,
    SpeechRecognition:Recognition,
    isSecureContext:true,
    location:{href:'https://stonefellow.test/chat.php'},
    addEventListener(){},
  };
  const navigatorObj={permissions:{query:async()=>({state:'granted'})},mediaDevices:{getUserMedia:async()=>({getTracks:()=>[]})}};
  const context={window:windowObj,document:doc,navigator:navigatorObj,MutationObserver:FakeMutationObserver,Event:FakeEvent,URL,AbortController,DOMException,Audio:class{},SpeechSynthesisUtterance:class {constructor(text){this.text=text;}},Uint8Array,Math,setTimeout,clearTimeout,setInterval,clearInterval,console,fetch:async()=>{throw new Error('not used');}};
  context.globalThis=context;
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(repoFile('chat-v100.js'),'utf8'),context);

  const replacement=doc.getElementById('chatVoiceButton');
  assert.notEqual(replacement,original,'LISTEN button should be replaced to remove the legacy listener');
  replacement.dispatchEvent(new FakeEvent('click'));
  assert.equal(legacyClicked,false,'legacy LISTEN listener must not fire');
  assert.equal(windowObj.STONEFELLOW_CHAT_RUNTIME.legacyListenerRemoved,true);
  assert.equal(windowObj.STONEFELLOW_CHAT_RUNTIME.recognitionStarts,1,'recognition should start in the trusted click');
  assert.equal(synth.speak,nativeSpeak,'ElevenLabs runtime must not monkey-patch global speechSynthesis.speak');
  assert.equal(synth.cancel,nativeCancel,'ElevenLabs runtime must not monkey-patch global speechSynthesis.cancel');

  Recognition.latest.onerror?.({error:'service-not-allowed'});
  await new Promise(r=>setTimeout(r,0));
  assert.match(status.textContent,/service is unavailable or blocked/i);
  assert.match(status.textContent,/Microphone permission is not the problem/i);
  assert.doesNotMatch(status.textContent,/permission was not granted/i);
}

async function testTeamChatBootstrap() {
  const doc=new FakeDocument();
  const windowObj={
    STONEFELLOW_TEAM_CHAT_ADMIN:{endpoint:'/api/team-chat-v103.php',csrf:'x',userId:7,pageKey:'admin_tracks',contextLabel:'Admin · Tracks'},
    location:{href:'https://stonefellow.test/admin/tracks.php'},
  };
  let fetchCalls=0;
  const context={window:windowObj,document:doc,URL,console,fetch:async()=>{fetchCalls++;throw new Error('bootstrap should not poll');}};
  context.globalThis=context;
  vm.createContext(context);
  const source=(await import('node:fs/promises')).readFile(repoFile('team-chat-admin-v108.js'),'utf8');
  vm.runInContext(await source,context);
  assert.ok(doc.getElementById('sfOnlineRail'),'Admin rail must be created before any API poll');
  assert.equal(fetchCalls,0,'bootstrap must not require a successful fetch to render the rail');
  assert.equal(windowObj.STONEFELLOW_TEAM_CHAT_RUNTIME.railCreated,true);
}

async function testStudioBusMuteGuard() {
  const listeners=[];
  const button=new FakeElement('button'); button.dataset.groupMute='vocals'; button.setAttribute('aria-pressed','false');
  const audio={muted:false};
  const row=new FakeElement('div'); row.dataset.stemId='1'; row.querySelector=sel=>sel==='audio.stem-audio'?audio:null;
  const small={textContent:'Lead Vocal'};
  const doc={
    addEventListener(type,fn,opts){ if(type==='click') listeners.push(fn); },
    querySelectorAll(sel){ return sel==='[data-stem-id]'?[row]:[]; },
    getElementById(){ return null; },
    querySelector(sel){
      if(sel==='[data-mixer-stem="1"]') return null;
      if(sel==='[data-stem-id="1"] small') return small;
      return null;
    }
  };
  const windowObj={STONEFELLOW_STUDIO_V107_GUARD:null,setTimeout,addEventListener(){}};
  const context={window:windowObj,document:doc,setTimeout,console,performance:{getEntriesByType:()=>[]}}; context.globalThis=context;
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(repoFile('admin/stem-studio-guard-v107.js'),'utf8'),context);
  const evt={target:{closest:()=>button}};
  listeners[0](evt);
  await new Promise(r=>setTimeout(r,1));
  assert.equal(button.getAttribute('aria-pressed'),'true','fallback should recover an unbound bus mute');
  assert.equal(audio.muted,true,'fallback should mute audio in the affected group');

  button.classList.remove('active'); button.setAttribute('aria-pressed','false'); audio.muted=false;
  listeners[0](evt);
  button.classList.add('active'); button.setAttribute('aria-pressed','true');
  await new Promise(r=>setTimeout(r,1));
  assert.equal(button.getAttribute('aria-pressed'),'true','guard must not double-toggle a working main handler');
}

await testChatRuntime();
await testTeamChatBootstrap();
await testStudioBusMuteGuard();
console.log('runtime-root-cause tests passed');
