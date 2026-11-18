import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {Element,Document,FixtureCustomEvent} from './fixtures/profile-calls-pa3c.mjs';

// DOM primitives for the existing trusted static template. Public data still
// reaches only textContent; this parser never executes markup or scripts.
class ActivityElement extends Element {
  get firstElementChild(){return this.children.find(n=>n.tagName!=='#TEXT')||null;}
  get lastElementChild(){return this.children.filter(n=>n.tagName!=='#TEXT').at(-1)||null;}
  get childElementCount(){return this.children.filter(n=>n.tagName!=='#TEXT').length;}
  get previousElementSibling(){const rows=this.parentElement?.children.filter(n=>n.tagName!=='#TEXT')||[];return rows[rows.indexOf(this)-1]||null;}
  setAttribute(key,value){super.setAttribute(key,value);if(key==='hidden')this.hidden=true;}
  removeAttribute(key){delete this.attrs[key];if(key==='hidden')this.hidden=false;}
  hasAttribute(key){return this.getAttribute(key)!==null;}
  prepend(...items){for(const item of [...items].reverse())this.insertBefore(item,this.children[0]);}
  after(node){const rows=this.parentElement.children;this.parentElement.insertBefore(node,rows[rows.indexOf(this)+1]);}
  replaceWith(node){this.parentElement.insertBefore(node,this);this.remove();}
  closest(selector){for(let node=this;node;node=node.parentElement)if(selector.split(',').some(s=>node.matches(s)))return node;return null;}
  reset(){for(const input of this.querySelectorAll('input')){input.value='';input.checked=false;}}
  scrollIntoView(){this.scrolled=true;}
  show(){this.open=true;}
  showModal(){this.show();}
  close(){this.open=false;this.dispatchEvent(new Event('close'));}
  set innerHTML(markup){
    this._text='';this.replaceChildren();const stack=[this];
    for(const token of String(markup).match(/<[^>]*>|[^<]+/g)||[]){
      if(token.startsWith('</')){stack.pop();continue;}
      if(token.startsWith('<')){
        const [,tag,attributes]=/^<([a-z][a-z0-9-]*)([^>]*)>$/i.exec(token)||[];
        if(!tag)throw new Error('Unsupported static template token');
        const node=this.ownerDocument.createElement(tag);
        for(const [,key,a,b] of attributes.matchAll(/([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'))?/g))node.setAttribute(key,a??b??'');
        stack.at(-1).append(node);if(!['input','img','br','hr'].includes(tag))stack.push(node);
      }else stack.at(-1).append(this.ownerDocument.createTextNode(token));
    }
  }
}
class ActivityDocument extends Document {
  constructor(){super();this.baseURI='https://fixture.test/vp3/chat.php';}
  createElement(tag){return new ActivityElement(this,tag);}
}
const source=fs.readFileSync(new URL('../profile-call-activity-pa3c.js',import.meta.url),'utf8');
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const settle=async()=>{for(let i=0;i<10;i++)await new Promise(resolve=>setImmediate(resolve));};
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const original={ownerUserId:1,chatAgentId:7,chatConversationId:11,workingAttentionBinding:'current-private-binding',publicConversationId:31,callId:41};
const activity=(changes={})=>({call_id:'a'.repeat(32),state:'listening',agent:{name:'Longfellow'},assistance_enabled:true,
  assistance_toggle_available:true,assistance_revision:1,research_available:true,segments:[{id:1,speaker:'Visitor',text:'Exact <script>visitor text</script>',start_ms:100}],
  research:[],cursor:1,has_more:false,transcript_url:'/vp3/artist-listening.php?session=71',...changes});

function fixture({legacy=false}={}){
  const document=new ActivityDocument(),window=new EventTarget(),timers=new Map(),reads=[],mutations=[],errors=[];
  let clock=0,timerId=0,scope={...original},response=activity(),readHook=null,mutateHook=null,fetches=0;
  const setTimeout=(fn,delay=0)=>{const id=++timerId;timers.set(id,{fn,due:clock+delay});return id;};
  const clearTimeout=id=>timers.delete(id);
  const media=new EventTarget();media.matches=false;
  const location={origin:'https://fixture.test',href:document.baseURI};window.location=location;
  if(legacy){const host=document.createElement('div');host.id='profileCallActivityPa3c';host.dataset.api='/activity';host.dataset.csrf='fixture-csrf';host.hidden=true;document.body.append(host);}
  const globals={window,document,location,URL,AbortController,Event,CustomEvent:FixtureCustomEvent,Element:ActivityElement,
    crypto:webcrypto,Uint8Array,setTimeout,clearTimeout,matchMedia:()=>media,
    MutationObserver:class{observe(){}disconnect(){}},fetch:async()=>{fetches++;return{ok:true,status:200,json:async()=>({ok:true,...response})};}};
  const context=vm.createContext(globals);vm.runInContext(source,context,{filename:'profile-call-activity-pa3c.js'});
  const envelope=(captured,value=response)=>({call_id:captured.callId,public_conversation_id:captured.publicConversationId,activity:structuredClone(value)});
  const options={context:()=>scope,
    read:async value=>{reads.push(value);return readHook?readHook(value):envelope(value.context);},
    mutate:async value=>{mutations.push(value);return mutateHook?mutateHook(value):{call_id:value.context.callId,public_conversation_id:value.context.publicConversationId,
      operation:value.operation,result:value.operation==='research'?{research_status:'complete'}:{assistance_enabled:value.input.enabled,assistance_revision:value.input.assistance_revision+1}};},
    onUnavailable:error=>errors.push(error)};
  const f={window,document,reads,mutations,errors,options,envelope,get fetches(){return fetches;},get scope(){return scope;},get response(){return response;},
    set scope(value){scope=value;},set response(value){response=value;},set readHook(value){readHook=value;},set mutateHook(value){mutateHook=value;},
    mount(){const host=document.createElement('div');document.body.append(host);const controller=window.VP3ProfileCallActivityPa3c.mount(host,options);return{host,controller,
      query:()=>host.querySelector('form').querySelector('input'),form:()=>host.querySelector('form'),button:()=>host.querySelector('form').querySelector('button'),toggle:()=>host.querySelector('[data-call-toggle]').querySelector('input')};},
    async advance(ms=0){clock+=ms;for(let round=0;round<10;round++){const due=[...timers].filter(([,t])=>t.due<=clock);if(!due.length)break;for(const[id,t]of due)if(timers.delete(id))t.fn();await settle();}},
  };
  return f;
}

{
  const f=fixture();check(typeof f.window.VP3ProfileCallActivityPa3c.mount==='function','activity renderer exports without a legacy launcher or media surface');
  const v=f.mount();await f.advance();
  check(f.reads.length===1&&f.mutations.length===0&&f.fetches===0,'embedding and selecting activity performs only its scoped read callback');
  check(Object.isFrozen(f.reads[0].context)&&f.reads[0].context.callId===41&&f.reads[0].context.chatConversationId===11,'the reader receives an immutable exact private and public selection');
  check(v.host.textContent.includes('Exact <script>visitor text</script>')&&!v.host.querySelector('script'),'public transcript text remains inert text in the reusable renderer');
  check(!v.host.querySelector('dialog')&&!v.host.querySelector('.vp3-call-launch')&&v.host.querySelector('.vp3-call-activity--embedded'),'workspace activity embeds a section without a second launcher or nested dialog');
  check(!v.form().hidden&&!v.toggle().disabled,'existing explicit research and assistance controls remain available after a verified read');
  v.controller.dispose();const count=f.reads.length;await f.advance(10000);
  check(!v.host.childElementCount&&f.reads.length===count,'disposing the activity view removes controls and its pending read timer');
}
{
  const f=fixture();let first=true;f.mutateHook=async request=>{if(first){first=false;throw new Error('Response lost');}return{call_id:41,public_conversation_id:31,operation:'research',result:{research_status:'complete'}};};
  let v=f.mount();await f.advance();v.query().value='Find public opening hours';v.form().requestSubmit();await settle();
  const attempt=f.mutations[0];check(attempt.operation==='research'&&/^[a-f0-9]{32}$/.test(attempt.input.request_id),'explicit research creates one stable canonical request identity');
  check(v.query().disabled&&v.button().textContent==='Retry research','an uncertain research response preserves its exact question for retry');
  v.controller.dispose();v=f.mount();await f.advance();
  check(v.query().value==='Find public opening hours'&&f.mutations.length===1,'dispose and reopen recover the question without submitting another operation');
  v.form().requestSubmit();await settle();
  check(f.mutations.length===2&&JSON.stringify(f.mutations[1].input)===JSON.stringify(attempt.input),'explicit retry after reopening sends the same request ID and body');
  check(v.query().value==='','a confirmed research response clears the recovered draft');v.controller.dispose();
}
{
  const f=fixture();f.mutateHook=async()=>{throw new Error('Unconfirmed response');};let v=f.mount();await f.advance();
  v.query().value='Check the public source';v.form().requestSubmit();await settle();const attempt=f.mutations[0];v.controller.dispose();
  f.response=activity({research:[{id:attempt.input.request_id,query:attempt.input.query,status:'complete',summary:'Stored exact result',sources:[]}]});
  v=f.mount();await f.advance();
  check(v.host.textContent.includes('Stored exact result')&&v.query().value===''&&f.mutations.length===1,'a saved canonical result reconciles an uncertain request without replay');v.controller.dispose();
}
{
  const f=fixture(),pending=deferred();f.mutateHook=()=>pending.promise;let v=f.mount();await f.advance();
  v.query().value='One in-flight question';v.form().requestSubmit();await settle();v.controller.dispose();v=f.mount();await f.advance();
  check(v.button().disabled&&f.mutations.length===1,'reopening an in-flight research request cannot submit a concurrent duplicate');
  pending.reject(new Error('Unconfirmed'));await settle();await f.advance(2400);
  check(!v.button().disabled&&v.button().textContent==='Retry research','the reopened view recovers a request that became uncertain after the earlier view closed');v.controller.dispose();
}
{
  const f=fixture();let v=f.mount();await f.advance();const held=deferred();f.readHook=()=>held.promise;await f.advance(2400);
  f.scope={...original,chatAgentId:8,chatConversationId:12,callId:42,publicConversationId:32};
  f.window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
  held.resolve(f.envelope(original,activity({segments:[{id:2,speaker:'Old',text:'STALE_PRIVATE_TRANSCRIPT',start_ms:2}]})));await settle();
  check(!v.host.textContent.includes('STALE_PRIVATE_TRANSCRIPT')&&!v.host.textContent.includes('Exact <script>'),'a true private scope change clears visible evidence and fences a late response');
  check(f.mutations.length===0,'scope invalidation starts no assistance or media action');v.controller.dispose();
}
{
  const f=fixture();const v=f.mount();await f.advance();const held=deferred();f.readHook=()=>held.promise;await f.advance(2400);
  f.scope={...original,callId:42,publicConversationId:32};v.controller.select(42);f.readHook=null;
  held.resolve(f.envelope(original,activity({segments:[{id:2,speaker:'Old',text:'WRONG_SELECTED_CALL',start_ms:2}]})));await settle();await f.advance();
  check(!v.host.textContent.includes('WRONG_SELECTED_CALL')&&f.reads.at(-1).context.callId===42,'exact call selection invalidates an older call response without any delivery');
  check(v.controller.select(999)===false&&f.errors.length===1,'a missing exact selection becomes unavailable instead of selecting another call');v.controller.dispose();
}
{
  const f=fixture(),v=f.mount();await f.advance();f.readHook=async()=>{throw Object.assign(new Error('Revoked'),{status:403});};await f.advance(2400);
  check(f.errors.length===1&&!v.host.textContent.includes('Exact <script>')&&!v.host.querySelector('[data-call-transcript-link]').hasAttribute('href'),
    'revoked current authority clears transcript, research and recording links');v.controller.dispose();
}
{
  const f=fixture(),v=f.mount();await f.advance();const held=deferred();f.readHook=()=>held.promise;await f.advance(2400);
  f.scope={...original,workingAttentionBinding:'replaced-private-binding'};
  held.resolve(f.envelope(original));await settle();
  check(!v.host.textContent.includes('Exact <script>')&&f.errors.length===1,'a changed captured binding clears earlier evidence even before a context-change event arrives');v.controller.dispose();
}
{
  const f=fixture(),v=f.mount();await f.advance();f.readHook=async()=>f.envelope(original,activity({call_id:'b'.repeat(32)}));await f.advance(2400);
  check(!v.host.textContent.includes('Exact <script>')&&f.errors.length===1,'an inconsistent exact activity identity clears the old selection instead of retrying stale evidence');v.controller.dispose();
}
{
  const f=fixture();f.response=activity({state:'ended',assistance_enabled:false,assistance_toggle_available:false,research_available:false});const v=f.mount();await f.advance();
  check(v.form().hidden&&v.host.querySelector('[data-call-toggle]').hidden&&v.host.textContent.includes('Exact <script>'),
    'ended selected activity remains readable while live-only controls are absent');const count=f.reads.length;await f.advance(5000);
  check(f.reads.length===count,'a completed activity view does not keep polling a finished call');v.controller.dispose();
}
{
  const f=fixture(),v=f.mount();await f.advance();v.toggle().checked=false;v.toggle().dispatchEvent(new Event('change'));await settle();
  check(f.mutations.length===1&&f.mutations[0].operation==='set_assistance'&&f.mutations[0].input.enabled===false
    &&f.mutations[0].input.assistance_revision===1,'only the explicit assistance switch sends its captured canonical revision and desired state');v.controller.dispose();
}
{
  const f=fixture({legacy:true});f.window.dispatchEvent(new FixtureCustomEvent('vp3:profile-call-assistance',{detail:{id:'a'.repeat(32),callId:41,conversationId:31,status:'active',assisted:true,agent:{name:'Longfellow'}}}));
  await f.advance();const baseline=f.fetches;const v=f.mount();await f.advance();await f.advance(2400);
  check(baseline===1&&f.fetches===baseline&&f.reads.length===2,'embedding suspends the legacy reader and leaves only one active activity poller');
  v.controller.dispose();await f.advance();check(f.fetches===baseline+1,'disposing the embedded view resumes only the existing legacy activity reader');
  let opened=null;f.window.VP3AgentActivityHub={openProfile(selection){opened=selection;return true;}};
  f.document.getElementById('profileCallActivityPa3c').querySelector('button').click();
  check(opened.callId===41&&opened.publicConversationId===31&&opened.tab==='calls'
    &&!f.document.getElementById('vp3CallActivityDrawer').open,'the legacy launcher delegates the exact call into the single Profile workspace');
  f.window.dispatchEvent(new Event('pagehide'));
}
console.log(`PROFILE_CALL_WORKSPACE_V1=PASS (${checks} checks)`);
