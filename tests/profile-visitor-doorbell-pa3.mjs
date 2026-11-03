import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {copy,contract,ownerCard} from './fixtures/profile-visitor-doorbell-pa3.mjs';

// Execute the production IIFE. Only the browser, clock and HTTP boundaries are
// controlled; the companion Chromium test also loads the actual Chat renderer.
class CustomEventFixture extends Event{constructor(type,init={}){super(type);this.detail=init.detail;}}
class Element extends EventTarget{
  constructor(document,tag){super();this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attrs={};this._text='';this.className='';this.value='';this.hidden=false;this.disabled=false;this.rect={top:100,left:20,width:500,height:300,right:520,bottom:400};this.scrollTop=0;this.scrollHeight=1000;this.clientHeight=700;}
  get id(){return this.attrs.id||'';}set id(value){this.attrs.id=value;}
  get isConnected(){return this===this.ownerDocument.body||!!this.parentElement?.isConnected;}
  setAttribute(key,value){this.attrs[key]=String(value);if(key.startsWith('data-'))this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);}
  getAttribute(key){return this.attrs[key]??null;}
  append(...nodes){for(const child of nodes){child.remove();child.parentElement=this;this.children.push(child);}}
  appendChild(child){this.append(child);return child;}
  remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(n=>n!==this);this.parentElement=null;}
  replaceChildren(...nodes){for(const n of this.children)n.parentElement=null;this.children=[];this.append(...nodes);}
  get textContent(){return this._text+this.children.map(n=>n.textContent).join('');}set textContent(value){this._text=String(value);this.replaceChildren();}
  matches(selector){
    const attrs=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];
    if(attrs.some(([,key,value])=>{const actual=key==='hidden'?(this.hidden?'':undefined):key==='value'?this.value:key.startsWith('data-')?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this.attrs[key];return value===undefined?actual===undefined:actual!==value;}))return false;
    const simple=selector.replace(/\[[^\]]+\]/g,'');const id=simple.match(/#([\w-]+)/)?.[1];if(id&&id!==this.id)return false;
    const tag=simple.match(/^[\w-]+/)?.[0];if(tag&&tag.toUpperCase()!==this.tagName)return false;
    return [...simple.matchAll(/\.([\w-]+)/g)].every(([,c])=>this.className.split(/\s+/).includes(c));
  }
  querySelectorAll(selector){const nodes=[];const walk=n=>{for(const child of n.children){if(selector.split(',').some(s=>child.matches(s.trim())))nodes.push(child);walk(child);}};walk(this);return nodes;}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){for(let n=this;n;n=n.parentElement)if(n.matches(selector))return n;return null;}
  contains(element){for(let n=element;n;n=n.parentElement)if(n===this)return true;return false;}
  getBoundingClientRect(){return this.hidden?{top:0,left:0,right:0,bottom:0,width:0,height:0}:this.rect;}
  focus(){this.ownerDocument.activeElement=this;}setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}
  click(){if(!this.disabled)this.dispatchEvent(new Event('click'));}
}
class Document extends EventTarget{
  constructor(){super();this.body=new Element(this,'body');this.hidden=false;this.visibilityState='visible';this.activeElement=this.body;}
  createElement(tag){return new Element(this,tag);}querySelector(selector){return this.body.querySelector(selector);}getElementById(id){return this.querySelector('#'+id);}
}
const source=fs.readFileSync(new URL('../profile-visitor-doorbell-pa3.js',import.meta.url),'utf8');
const tick=async()=>{for(let i=0;i<4;i++)await new Promise(r=>setImmediate(r));};
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function fixture({cards=[ownerCard()],cid=42,storage=new Map(),hidden=false}={}){
  const document=new Document(),window=new EventTarget(),thread=document.createElement('section'),topbar=document.createElement('div'),privateInput=document.createElement('textarea');
  thread.id='chatThread';thread.dataset.view='chat';thread.rect={top:70,left:0,right:800,bottom:850,width:800,height:780};topbar.className='chat-topbar-actions';privateInput.id='chatInput';privateInput.value='PRIVATE CHAT DRAFT';document.body.append(topbar,thread,privateInput);
  document.hidden=hidden;document.visibilityState=hidden?'hidden':'visible';
  let activeCid=cid,agent=7,clock=1800000000000,elapsed=0,availability='available',visiblePresence=false,seq=0,messageId=700,ensureCalls=0;
  const turns=new Map(cards.map(card=>[card.turn_id,copy(card)])),requests=[],pending=[],holds=[],intervals=[],frames=[],timers=[],deliveries=new Map(),errors=[];
  const response=(payload,status=200)=>({ok:status>=200&&status<300,status,json:async()=>copy(payload)});
  const transport=async(url,options)=>{
    const input=JSON.parse(options.body);requests.push({url,...copy(input)});
    let payload={ok:true};
    if(input.action==='doorbell_list')Object.assign(payload,{contract,availability,reachable:visiblePresence,server_now_ms:clock,turns:input.chat_conversation_id>0?[...turns.values()].filter(v=>!['replied','blocked','cancelled'].includes(v.state)||deliveries.has(v.turn_id+':'+input.chat_conversation_id)).map(v=>({...copy(v),server_now_ms:clock,chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,chat_message_id:0})):[]});
    else if(input.action==='doorbell_presence'){const accepted=input.presence_sequence>seq;if(accepted){seq=input.presence_sequence;visiblePresence=input.visible;if(input.availability)availability=input.availability;}Object.assign(payload,{contract,accepted,availability,reachable:visiblePresence&&availability==='available',server_now_ms:clock});}
    else if(input.action==='doorbell_delivery'){
      const card=turns.get(input.turn_id),key=input.turn_id+':'+input.chat_conversation_id;
      if(!deliveries.has(key))deliveries.set(key,{delivery_id:(++messageId).toString(16).padStart(32,'0'),chat_message_id:messageId});
      const delivery=deliveries.get(key);Object.assign(payload,{contract,...delivery,message_id:delivery.chat_message_id,chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,turn:{...copy(card),chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,chat_message_id:delivery.chat_message_id,server_now_ms:clock}});
    }else if(input.action==='doorbell_ack'){
      const card=turns.get(input.turn_id);if(card.state==='awaiting_delivery'){card.state='awaiting_owner';card.revision++;card.acknowledged_at_ms=clock;card.pickup_deadline_ms=clock+15000;}
      Object.assign(payload,{contract,duplicate:false,turn:{...copy(card),server_now_ms:clock}});
    }else if(['doorbell_claim','doorbell_reply','doorbell_delegate','doorbell_resolve'].includes(input.action)){
      const card=turns.get(input.turn_id);card.revision++;card.state=({doorbell_claim:'owner_claimed',doorbell_reply:'replied',doorbell_delegate:'agent_ready',doorbell_resolve:'cancelled'})[input.action];card.responder=input.action==='doorbell_reply'?'owner':input.action==='doorbell_claim'?'owner':input.action==='doorbell_delegate'?'agent':'none';
      if(input.action==='doorbell_reply'){card.history.push({id:9000+requests.length,sender_type:'owner',message:input.message,created_at:'2027-01-15 08:00:01'});card.can_claim=card.can_reply=card.can_delegate=card.can_resolve=true;}
      Object.assign(payload,{turn_id:input.turn_id,conversation_id:input.conversation_id,request_id:input.request_id,turn:{...copy(card),server_now_ms:clock}});
    }else throw new Error('Unexpected action '+input.action);
    const index=holds.findIndex(h=>h.action===input.action);
    if(index>=0){const hold=holds.splice(index,1)[0];return new Promise((resolve,reject)=>{const work={input,payload,resolve:(override={},status=200)=>resolve(response({...payload,...override},status)),reject};pending.push(work);hold.arrived(work);});}
    return response(payload);
  };
  function mount(delivery){
    if(delivery.chat_conversation_id!==activeCid)return null;
    let element=thread.querySelector('[data-chat-message-id="'+delivery.chat_message_id+'"]');if(element)return element;
    element=document.createElement('article');element.className='message assistant';element.dataset.chatMessageId=String(delivery.chat_message_id);element.dataset.chatConversationId=String(activeCid);element.dataset.profileHandoffTurn=String(delivery.turn.turn_id);element.dataset.profileHandoffConversation=String(delivery.turn.conversation_id);
    const body=document.createElement('div');body.className='message-body';const text=document.createElement('p');text.className='message-text';text.textContent='Profile visitor conversation.';body.append(text);element.append(body);thread.append(element);return element;
  }
  Object.assign(window,{innerHeight:900,innerWidth:900,VP3_PROFILE_DOORBELL_PA3:{endpoint:'/notes',ownerEndpoint:'/owner',csrf:'fixture',userId:1},STONEFELLOW_CHAT:{agentId:7},
    STONEFELLOW_CHAT_CONTINUITY:{conversationId:()=>activeCid,agentId:()=>agent,isReady:()=>true,isRestoring:()=>false,ensureConversation:async()=>{ensureCalls++;activeCid=99;window.dispatchEvent(new Event('vp3:chat-context-ready'));return 99;},mountProfileHandoff:mount},
    setInterval(fn,delay){intervals.push({fn,delay});return intervals.length;},clearInterval(){},setTimeout(fn,delay){const timer={fn,delay};timers.push(timer);return timer;},clearTimeout(timer){const i=timers.indexOf(timer);if(i>=0)timers.splice(i,1);},requestAnimationFrame:fn=>frames.push(fn),getComputedStyle:()=>({display:'block',visibility:'visible'})});
  const sessionStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  vm.runInNewContext(source,{window,document,sessionStorage,navigator:{onLine:true},crypto:webcrypto,Uint8Array,performance:{now:()=>elapsed},Date,JSON,Number,String,Map,Set,Event,AbortController,CustomEvent:CustomEventFixture,fetch:transport});
  const f={document,window,thread,privateInput,turns,requests,pending,storage,errors,get ensureCalls(){return ensureCalls;},get activeCid(){return activeCid;},get clock(){return clock;},cards:()=>thread.querySelectorAll('.profile-doorbell-card'),card:id=>thread.querySelector('[data-profile-doorbell-turn="'+id+'"]'),
    hold:action=>new Promise(arrived=>holds.push({action,arrived})),async settle(){await tick();while(timers.some(timer=>timer.delay===0)){const current=timers.filter(timer=>timer.delay===0);for(const timer of current){timers.splice(timers.indexOf(timer),1);timer.fn();}await tick();}},async timeout(){for(const timer of [...timers].filter(timer=>timer.delay===12000))timer.fn();await this.settle();},async frames(){for(let i=0;i<2;i++){const current=frames.splice(0);for(const fn of current)fn();await tick();}},
    async refresh(){await window.VP3_PROFILE_DOORBELL_PA3_RUNTIME.refresh();await this.settle();},
    async changeContext(nextCid,nextAgent=7){window.dispatchEvent(new Event('stonefellow:chat-context-changing'));activeCid=nextCid;agent=nextAgent;thread.replaceChildren();window.dispatchEvent(new Event('vp3:chat-context-ready'));await this.settle();},
    async visibility(value){document.hidden=!value;document.visibilityState=value?'visible':'hidden';document.dispatchEvent(new Event('visibilitychange'));await this.settle();},
    async advance(ms){clock+=ms;elapsed+=ms;for(const timer of intervals.filter(x=>x.delay===500))timer.fn();await tick();},
    async submit(id,text){const card=this.card(id),input=card.querySelector('textarea');input.value=text;input.dispatchEvent(new Event('input'));card.querySelector('form').dispatchEvent(new Event('submit'));await tick();},
    async choose(value){const select=document.getElementById('profileDoorbellAvailability');select.value=value;select.dispatchEvent(new Event('change'));await this.settle();},
    stop(){window.dispatchEvent(new Event('pagehide'));}
  };
  await f.settle();return f;
}

{
 const f=await fixture();
 check(f.cards().length===1&&f.card(101).textContent.includes('Public visitor question 101'),'actual module mounts a public visitor card in the private canvas');
 check(!f.requests.some(r=>r.action==='doorbell_ack'),'delivery and mounted DOM alone do not start the deadline');
 await f.frames();const ack=f.requests.find(r=>r.action==='doorbell_ack');
 check(ack&&ack.chat_message_id===701&&ack.chat_conversation_id===42&&ack.chat_agent_id===7&&ack.turn_id===101&&ack.revision===1&&/^[a-f0-9]{32}$/.test(ack.tab_id)&&/^[a-f0-9]{32}$/.test(ack.delivery_id),'visible painted card ACK carries exact tab, turn, revision, delivery and private message identities');
 check(f.card(101).textContent.includes('15s to pick up'),'countdown uses the acknowledged server deadline');
 await f.advance(4000);check(f.card(101).textContent.includes('11s to pick up'),'elapsed display uses server time plus monotonic local elapsed time');
 await f.refresh();await f.frames();check(f.requests.filter(r=>r.action==='doorbell_ack').length===1,'ordinary polling does not reset or repeat an acknowledged pickup');
 check(f.privateInput.value==='PRIVATE CHAT DRAFT'&&!JSON.stringify(f.requests).includes('PRIVATE CHAT DRAFT'),'private composer content is never forwarded to visitors or presence');f.stop();
}
{
 const f=await fixture();const card=f.card(101);card.rect={top:1000,bottom:1300,left:0,right:500,width:500,height:300};await f.frames();check(!f.requests.some(r=>r.action==='doorbell_ack'),'offscreen card never starts the response window');
 card.rect.top=100;card.rect.bottom=400;f.document.elementFromPoint=()=>f.document.body;f.thread.dispatchEvent(new Event('scroll'));await f.frames();check(!f.requests.some(r=>r.action==='doorbell_ack'),'an overlay covering the card prevents visible acknowledgment');f.document.elementFromPoint=()=>card;
 await f.visibility(false);f.thread.dispatchEvent(new Event('scroll'));await f.frames();check(!f.requests.some(r=>r.action==='doorbell_ack'),'hidden tab does not ACK an otherwise mounted visible card');
 await f.visibility(true);await f.frames();check(f.requests.filter(r=>r.action==='doorbell_ack').length===1,'returning to a visible current Chat acknowledges once');
 const presence=f.requests.filter(r=>r.action==='doorbell_presence');check(presence.every((p,i)=>i===0||p.presence_sequence>presence[i-1].presence_sequence)&&presence.some(p=>p.visible===false),'presence invalidation has a strictly newer sequence than old visible heartbeats');f.stop();
}
{
 const f=await fixture({cards:[ownerCard(101),ownerCard(202)]});await f.frames();
 const a=f.card(101).querySelector('textarea'),b=f.card(202).querySelector('textarea');a.value='Draft for first';a.dispatchEvent(new Event('input'));b.value='Draft for second';b.dispatchEvent(new Event('input'));b.focus();b.setSelectionRange(4,9);
 f.turns.get(202).history.push({id:9999,sender_type:'visitor',message:'Another public line',created_at:'2027-01-15 08:00:01'});await f.refresh();
 check(a.value==='Draft for first'&&b.value==='Draft for second'&&f.document.activeElement===b&&b.selectionStart===4&&f.card(202).textContent.includes('Another public line'),'polling updates public history while preserving each draft, focus and caret');
 const pending=f.hold('doorbell_reply');await f.submit(202,'Exact second visitor reply');const work=await pending;
 check(work.input.conversation_id===1202&&work.input.turn_id===202&&work.input.chat_conversation_id===42&&work.input.chat_agent_id===7,'explicit reply captures the displayed visitor and private Chat identities');
 check(f.card(202).querySelectorAll('button').every(n=>n.disabled)&&f.card(202).querySelector('textarea').disabled,'pending reply disables all controls on that card');
 work.resolve();await f.settle();check(b.value===''&&a.value==='Draft for first'&&f.privateInput.value==='PRIVATE CHAT DRAFT','verified reply clears only its exact sent draft');
 check(f.card(202).textContent.includes('Reply sent to this visitor.'),'confirmed owner reply has truthful targeted feedback');f.stop();
}
{
 const f=await fixture();await f.frames();const pending=f.hold('doorbell_reply');await f.submit(101,'Keep after uncertain response');const work=await pending;
 // Model a transport failure before any canonical receipt was recorded.
 const original=ownerCard(101,{state:'awaiting_owner',revision:2,acknowledged_at_ms:f.clock,pickup_deadline_ms:f.clock+15000});f.turns.set(101,original);work.reject(new Error('lost response'));await f.settle();
 check(f.card(101).querySelector('textarea').value==='Keep after uncertain response'&&f.card(101).textContent.includes('could not be confirmed'),'uncertain result keeps its draft and does not claim success');
 const retry=f.hold('doorbell_reply');await f.submit(101,'Keep after uncertain response');const retried=await retry;
 check(retried.input.request_id===work.input.request_id&&retried.input.revision===work.input.revision,'uncertain retry uses one stable request key for the exact prepared action');retried.resolve();await f.settle();f.stop();
}
for(const failed of [false,true]){
 const f=await fixture({cards:[ownerCard(101),ownerCard(202)]});await f.frames();const pending=f.hold('doorbell_reply');await f.submit(101,'Old context reply');const work=await pending;
 await f.changeContext(55);const current=f.card(202);current.querySelector('textarea').value='New context draft';current.querySelector('textarea').dispatchEvent(new Event('input'));
 work.resolve({turn_id:101,conversation_id:1101,request_id:work.input.request_id},failed?503:200);await f.settle();
 check(f.activeCid===55&&current.querySelector('textarea').value==='New context draft'&&!current.textContent.includes('Reply sent to this visitor.')&&!current.textContent.includes('could not be confirmed'),'late '+(failed?'failure':'success')+' cannot clear or overwrite the new Chat context');
 check(!f.requests.some(r=>r.action==='openConversation'),'background visitor presentation never opens or switches private conversations');f.stop();
}
{
 const f=await fixture();await f.frames();const pending=f.hold('doorbell_reply');await f.submit(101,'Stalled request draft');const work=await pending;
 f.turns.set(101,ownerCard(101,{state:'awaiting_owner',revision:2,acknowledged_at_ms:f.clock,pickup_deadline_ms:f.clock+15000}));await f.timeout();
 check(f.card(101).querySelector('textarea').value==='Stalled request draft'&&!f.card(101).querySelector('[data-doorbell-action="reply"]').disabled&&f.card(101).textContent.includes('could not be confirmed'),'stalled request times out without trapping the card or losing the draft');
 work.resolve();await f.settle();check(f.card(101).querySelector('textarea').value==='Stalled request draft'&&!f.card(101).textContent.includes('Reply sent to this visitor.')&&f.requests.filter(r=>r.action==='doorbell_reply').length===1,'late transport result after timeout does not rewrite state or trigger automatic retry');f.stop();
}
{
 const f=await fixture();await f.frames();const pending=f.hold('doorbell_reply');await f.submit(101,'Keep on mismatched receipt');const work=await pending;work.resolve({turn_id:202,conversation_id:1202});await f.settle();
 check(f.card(101).querySelector('textarea').value==='Keep on mismatched receipt'&&!f.card(101).textContent.includes('Reply sent to this visitor.'),'mismatched mutation identity cannot clear the draft or announce success');f.stop();
}
{
 const f=await fixture();await f.frames();await f.choose('away');const away=f.requests.find(r=>r.action==='doorbell_presence'&&r.availability==='away');
 check(away.availability==='away'&&f.document.getElementById('profileDoorbellAvailability').value==='away','Away is an explicit saved preference');await f.advance(11000);await f.refresh();
 check(!Object.hasOwn(f.requests.filter(r=>r.action==='doorbell_presence').at(-1),'availability'),'normal heartbeat never overwrites availability preference');await f.choose('dnd');check(f.document.getElementById('profileDoorbellAvailability').value==='dnd','Do not disturb is distinct from Away');f.stop();
}
{
 const f=await fixture({cid:0});check(f.ensureCalls===1&&f.activeCid===99&&f.cards().length===1,'blank current Chat initializes through canonical continuity before reachable delivery');f.stop();
 const hidden=await fixture({hidden:true});check(hidden.requests.length===0,'background page does not register reachable presence or deliver cards');hidden.stop();
}
{
 const f=await fixture();await f.frames();const deadline=f.turns.get(101).pickup_deadline_ms;f.card(101).querySelector('textarea').value='Saved visitor draft';f.card(101).querySelector('textarea').dispatchEvent(new Event('input'));const saved=copy(f.turns.get(101));const firstTab=f.requests.find(r=>r.action==='doorbell_presence').tab_id;f.stop();await tick();
 const restored=await fixture({cards:[saved],storage:f.storage});await restored.frames();check(restored.card(101).querySelector('textarea').value==='Saved visitor draft','reload restores the unsent visitor draft independently of notification read state');
 check(!restored.requests.some(r=>r.action==='doorbell_ack')&&restored.turns.get(101).pickup_deadline_ms===deadline,'reload of active acknowledged turn does not restart15seconds');
 check(restored.requests.find(r=>r.action==='doorbell_presence').tab_id===firstTab&&restored.requests.find(r=>r.action==='doorbell_presence').presence_sequence>f.requests.filter(r=>r.action==='doorbell_presence').at(-1).presence_sequence,'same-tab reload retains token and advances presence sequence');restored.stop();
}
{
 const hostile=ownerCard(101,{visitor_label:'Visitor <img onerror=alert(1)>',visitor_message:'<script>PRIVATE INSTRUCTIONS</script>'}),f=await fixture({cards:[hostile]});
 check(f.card(101).textContent.includes('<script>PRIVATE INSTRUCTIONS</script>')&&f.card(101).querySelectorAll('script,img').length===0,'visitor supplied text remains inert text');await f.frames();await f.advance(16000);
 check(f.card(101).querySelector('[data-doorbell-action="claim"]').disabled&&f.card(101).textContent.includes('Checking the current responder'),'client countdown cannot claim ownership after the server deadline');
 check(!f.requests.some(r=>r.action.includes('dispatch')||r.action.includes('tick')),'owner card never runs provider work from a read or countdown');f.stop();
}
{
 const f=await fixture();await f.frames();const first=f.card(101),input=first.querySelector('textarea');input.value='Unsent same-visitor draft';input.dispatchEvent(new Event('input'));
 f.turns.delete(101);f.turns.set(102,ownerCard(102,{conversation_id:1101,state:'awaiting_owner',revision:2,acknowledged_at_ms:f.clock,pickup_deadline_ms:f.clock+15000}));await f.refresh();
 check(first.hidden&&f.cards().filter(card=>!card.hidden).length===1&&f.card(102).querySelector('textarea').value==='Unsent same-visitor draft'&&f.card(102).textContent.includes('Review it before sending'),'new same-thread message keeps one active card and carries only an unsubmitted draft with explicit review notice');
 const held=f.hold('doorbell_reply');await f.submit(102,'Uncertain old-turn reply');const work=await held;
 f.turns.delete(102);f.turns.set(103,ownerCard(103,{conversation_id:1101,state:'owner_claimed',responder:'owner'}));await f.refresh();work.reject(new Error('lost response'));await f.settle();
 const saved=JSON.parse(f.storage.get('vp3:profile-doorbell:1:drafts'));
 check(f.card(103).querySelector('textarea').value===''&&saved[102].text==='Uncertain old-turn reply'&&saved[102].attempt.request_id===work.input.request_id,'pending or uncertain old-turn reply stays bound to its original draft and request; it is never copied to the latest turn');f.stop();
}
{
 const f=await fixture();await f.frames();const input=f.card(101).querySelector('textarea');await f.submit(101,'x'.repeat(4001));
 check(input.maxLength===4000&&!f.requests.some(r=>r.action==='doorbell_reply')&&input.value.length===4001&&f.card(101).textContent.includes('Shorten it before sending'),'pasted overlong reply stays editable and is rejected before a request under the server4000-character limit');f.stop();
 const storage=new Map([['vp3:profile-doorbell:1:drafts',JSON.stringify({101:{text:'s'.repeat(4001),at:Date.now(),attempt:null}})]]),restored=await fixture({storage});
 check(restored.card(101).textContent.includes('saved reply is longer than 4,000')&&restored.card(101).querySelector('textarea').value.length===4001,'overlong restored draft receives an explicit correction message without silently sending or truncating it');restored.stop();
}
for(const pauseField of ['execution_paused','dispatch_execution_paused']){
 const f=await fixture({cards:[ownerCard(101,{state:'agent_ready',responder:'agent',[pauseField]:true,can_delegate:false})]});
 check(f.card(101).textContent.includes('Profile Agent is paused')&&!f.card(101).textContent.includes('preparing a reply')&&f.card(101).querySelector('[data-doorbell-action="delegate"]').disabled,'canonical '+pauseField+' has truthful status and no implied automatic resume');f.stop();
}
{
 const malformed=ownerCard(101,{can_reply:'true'}),f=await fixture({cards:[malformed]});check(f.cards().length===0&&!f.requests.some(r=>r.action==='doorbell_delivery'),'malformed current authority projection cannot become a card');f.stop();
 const f2=await fixture();const denied=f2.hold('doorbell_list'),refresh=f2.refresh();const work=await denied;work.resolve({ok:false},403);await refresh;check(f2.card(101).hidden&&f2.card(101).querySelector('[data-doorbell-action="reply"]').disabled,'revoked access hides cached visitor details and disables controls');f2.stop();
}
// Run the actual Chat continuity helpers with the same mutable context as Chat.
// These tests cover adoption/cursor races that a stand-in continuity API cannot.
const chatSource=fs.readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const start=chatSource.indexOf('  function bindStoredMessagePa3('),end=chatSource.indexOf('  function formatPlaybackTime(',start);
assert.ok(start>=0&&end>start);
function chatHooks(){
 const document=new Document(),thread=document.createElement('section');thread.dataset.view='chat';document.body.append(thread);const drafts=new Map(),calls=[],events=[],timers=new Map();let resolve;
 const ctx={document,thread,AbortController,Event,CustomEvent:CustomEventFixture,Number,Promise,conversationId:0,contextReady:true,initialRestorePending:false,restoringConversation:0,busy:false,chatRevision:1,input:{value:'Unsent private draft'},cfg:{agentId:7},
  keepDraft:(text,id)=>drafts.set(id,text),rememberConversation(){},refreshHistory:async()=>{},api:async(input)=>{calls.push(input);return new Promise(r=>resolve=r);},
  window:{dispatchEvent:event=>events.push(event.type),setTimeout(fn){const id=timers.size+1;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id)},
  addMessage(){const element=document.createElement('article');element.className='message assistant';thread.append(element);thread.scrollTop=999;return element;}};
 vm.createContext(ctx);vm.runInContext(chatSource.slice(start,end)+'\nglobalThis.ensure=ensureConversationPa3;globalThis.mount=mountProfileHandoffPa3;',ctx);
 return {ctx,drafts,calls,events,timers,resolve:value=>resolve(value)};
}
{
 const f=chatHooks(),a=f.ctx.ensure(),b=f.ctx.ensure();f.ctx.input.value='Edited while initializing';f.resolve({conversation_id:77});await Promise.all([a,b]);
 check(f.calls.length===1&&f.calls[0].action==='new'&&f.ctx.conversationId===77&&f.drafts.get(77)==='Edited while initializing'&&f.drafts.get(0)==='','canonical blank initialization is single-flight and preserves the latest private draft');
 const g=chatHooks(),pending=g.ctx.ensure();g.ctx.chatRevision++;g.ctx.conversationId=55;g.ctx.input.value='Different selected draft';g.resolve({conversation_id:77});await pending;
 check(g.ctx.conversationId===55&&g.ctx.input.value==='Different selected draft'&&g.drafts.size===0,'late blank initialization never opens or overwrites another selected conversation');
 const stalled=chatHooks(),wait=stalled.ctx.ensure().catch(error=>error);[...stalled.timers.values()][0]();const error=await wait;
 check(/timed out/.test(error.message)&&stalled.ctx.conversationId===0&&stalled.ctx.input.value==='Unsent private draft','stalled empty-Chat initialization releases without losing private draft or inventing reachability');
}
{
 const f=chatHooks();f.ctx.conversationId=42;const delivery={chat_conversation_id:42,chat_message_id:701,turn:{...ownerCard(),chat_agent_id:7}};
 const first=f.ctx.mount(delivery),second=f.ctx.mount(delivery);
 check(first===second&&first.dataset.chatMessageId==='701'&&first.dataset.chatConversationId==='42'&&first.dataset.profileHandoffTurn==='101','real Chat mount deduplicates persisted message identity and exposes the exact marker');
 check(f.ctx.mount({...delivery,chat_conversation_id:55})===null&&f.ctx.mount({...delivery,turn:{...delivery.turn,chat_agent_id:8}})===null,'real Chat mount rejects foreign current conversation or selected Agent binding');
}
console.log(`PROFILE_VISITOR_DOORBELL_PA3=PASS (${checks} runtime assertions)`);
