import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';

// The production widget runs against a controlled DOM, clock and HTTP boundary.
class Element extends EventTarget {
 constructor(document,tag='div'){super();this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attrs={};this.style={};this.disabled=false;this.checked=false;this.hidden=false;this.value='';this._text='';this.scrollHeight=100;}
 get id(){return this.attrs.id||'';}set id(v){this.attrs.id=String(v);}get type(){return this.attrs.type||'';}set type(v){this.attrs.type=String(v);}get className(){return this.attrs.class||'';}set className(v){this.attrs.class=String(v);}
 get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}set textContent(v){this._text=String(v);this.replaceChildren();}
 set innerHTML(v){assert.equal(v,'','untrusted markup cannot enter the fixture DOM');this._text='';this.replaceChildren();}
 get isConnected(){return this===this.ownerDocument.body||Boolean(this.parentElement?.isConnected);}
 replaceChildren(...children){for(const child of this.children)child.parentElement=null;this.children=[];this.append(...children);}
 append(...children){for(let child of children){if(typeof child==='string')child=this.ownerDocument.createTextNode(child);child.remove();child.parentElement=this;this.children.push(child);}}appendChild(c){this.append(c);return c;}
 remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(c=>c!==this);this.parentElement=null;}
 setAttribute(k,v){this.attrs[k]=String(v);if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v);}
 matches(selector){const attributes=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];if(attributes.some(([,key,value])=>{const actual=key.startsWith('data-')?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this.attrs[key];return value===undefined?actual===undefined:actual!==value;}))return false;const simple=selector.replace(/\[[^\]]+\]/g,''),tag=simple.match(/^[\w-]+/)?.[0],id=simple.match(/#([\w-]+)/)?.[1];return(!tag||this.tagName===tag.toUpperCase())&&(!id||this.id===id)&&[...simple.matchAll(/\.([\w-]+)/g)].every(([,n])=>this.className.split(/\s+/).includes(n));}
 querySelectorAll(s){const found=[];const walk=n=>{for(const c of n.children){if(c.matches(s))found.push(c);walk(c);}};walk(this);return found;}querySelector(s){return this.querySelectorAll(s)[0]||null;}focus(){this.ownerDocument.activeElement=this;}requestSubmit(){this.dispatchEvent(new Event('submit',{cancelable:true}));}
}
class Document extends EventTarget {constructor(){super();this.body=new Element(this,'body');this.visibilityState='visible';}createElement(t){return new Element(this,t);}createTextNode(t){const e=new Element(this,'#text');e.textContent=t;return e;}querySelector(s){return this.body.querySelector(s);}getElementById(id){return this.querySelector('#'+id);}}
const source=fs.readFileSync(new URL('../profile-agent.js',import.meta.url),'utf8');
const clone=v=>structuredClone(v),flush=async()=>{for(let i=0;i<8;i++)await new Promise(r=>setImmediate(r));};
const storage=map=>({getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,String(v)),removeItem:k=>map.delete(k)});
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
function backend({available=true,ownerName='Dave'}={}){
 let seq=100,turnSeq=500;const messages=[],records=new Map(),holds=[];
 const state={conversation:null,handoff:null,chat:chatChoice({owner_name:ownerName,live_text_available:available}),loseMessage:false,loseEnd:false,changed:false,dispatches:0};
 const reply=(data,status=200)=>({ok:status<400,status,json:async()=>clone(data)});
 const snapshot=(meta,after=0)=>({ok:true,agent:{name:'Profile Guide',greeting:''},conversation:state.conversation,messages:messages.filter(m=>m.id>Number(after)),handoff:state.handoff&&{...state.handoff,server_now_ms:meta.now},chat:state.chat});
 const transition=(status,now=1800000000000)=>{
  state.chat={...state.chat,live_status:status,mode:['waiting','accepted'].includes(status)?'live':state.chat.mode,can_end_live_chat:['waiting','accepted'].includes(status)};
  if(state.handoff){const mode=status==='accepted'?'owner_claimed':['waiting'].includes(status)?'awaiting_owner':'agent_ready';state.handoff={...state.handoff,state:mode,dispatch_state:mode,revision:state.handoff.revision+1,pickup_deadline_ms:status==='waiting'?now+15000:null};state.conversation.status=status==='accepted'?'owner_joined':'open';}
 };
 const handler=async(action,body,meta)=>{
  if(state.changed)return reply({ok:false,code:'PROFILE_SESSION_CHANGED',error:'Refresh the Profile'},409);
  let payload;
  if(action==='state'||action==='poll')payload=snapshot(meta,body.after_id||0);
  else if(action==='message'){
   let accepted=records.get(body.client_request_id);
   if(!accepted){
    const mode=body.chat_mode??'auto',before=state.chat.live_status;
    const continuation=body.continue_live===true&&mode==='live';
    const acceptedLive=continuation&&before==='accepted',waiting=mode==='live'&&(continuation?before==='waiting':state.chat.live_text_available);
    const phase=acceptedLive?'owner_claimed':waiting?'awaiting_owner':'agent_ready';
    const deadline=waiting?(continuation?state.handoff.pickup_deadline_ms:meta.now+15000):null;
    state.conversation={conversation_id:31,status:acceptedLive?'owner_joined':'open'};
    const message={id:++seq,sender_type:'visitor',message:body.message};messages.push(message);
    state.chat={...state.chat,mode:continuation&&!acceptedLive&&!waiting?'agent':mode,live_status:acceptedLive?'accepted':waiting?'waiting':continuation?'ended':mode==='live'?'unavailable':'none',can_end_live_chat:acceptedLive||waiting};
    state.handoff={turn_id:++turnSeq,revision:1,conversation_id:31,state:phase,dispatch_turn_id:turnSeq,dispatch_state:phase,server_now_ms:meta.now,pickup_deadline_ms:deadline};
    accepted={ok:true,accepted:true,conversation_id:31,visitor_message_id:message.id,client_request_id:body.client_request_id,conversation:clone(state.conversation),handoff:clone(state.handoff),chat:clone(state.chat),saved_mode:mode};records.set(body.client_request_id,accepted);
   }
   if(state.loseMessage){state.loseMessage=false;throw Error('Message response lost');}
   payload={...accepted,chat:clone(state.chat),handoff:clone(state.handoff),conversation:clone(state.conversation)};
  }else if(action==='dispatch'){
   state.dispatches++;
   if(state.chat.live_status==='accepted'&&!state.chat.live_text_available)transition('ended');
   if(['agent_ready','agent_running'].includes(state.handoff.state)||state.handoff.state==='awaiting_owner'){
    if(state.chat.live_status==='waiting')transition('expired');
    messages.push({id:++seq,sender_type:'agent',message:'Public Agent response'});state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:state.handoff.revision+1};
   }
   payload={ok:true,conversation_id:31,turn_id:body.turn_id,dispatch_checked:true};
  }else if(action==='end_live_chat'){
   if(body.conversation_id!==31||body.turn_id!==state.handoff.turn_id||body.revision!==state.handoff.revision)return reply({ok:false,error:'Changed'},409);
   transition('ended');state.chat.mode='agent';
   if(state.loseEnd){state.loseEnd=false;throw Error('End response lost');}
   payload=snapshot(meta);
  }else throw Error('Unexpected action '+action);
  const index=holds.findIndex(h=>h.action===action);
  if(index>=0){const hold=holds.splice(index,1)[0],saved=clone(payload);return new Promise(done=>hold.arrived({body:clone(body),payload:saved,release:(override={},status=200)=>done(reply({...saved,...override},status))}));}
  return reply(payload);
 };
 return {state,messages,records,reply,handler,transition,hold:action=>new Promise(arrived=>holds.push({action,arrived}))};
}
async function widget({server=backend(),local=new Map(),session=new Map(),namespace='a'.repeat(64)}={}){
 const document=new Document(),window=new EventTarget(),shell=document.createElement('section');shell.id='profileAgentShell';document.body.append(shell);
 const thread=document.createElement('div');thread.dataset.profileAgentThread='';
 const form=document.createElement('form'),textarea=document.createElement('textarea'),submit=document.createElement('button');submit.type='submit';submit.textContent='Send';form.append(textarea,submit);
 const status=document.createElement('div');status.dataset.profileAgentStatus='';
 const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.profileLiveChat='';checkbox.disabled=true;
 const availability=document.createElement('small');availability.dataset.profileLiveAvailability='';const owner=document.createElement('strong');owner.dataset.profileOwnerName='';const end=document.createElement('button');end.type='button';end.dataset.profileEndLive='';end.hidden=true;const disclosure=document.createElement('div');disclosure.dataset.profileAgentDisclosure='';shell.append(disclosure,checkbox,owner,availability,end,thread,form,status);
 const privateInput=document.createElement('textarea');privateInput.id='chatInput';privateInput.value='PRIVATE_OWNER_DRAFT';document.body.append(privateInput);
 let now=1800000000000,timerId=0;const timers=new Map(),requests=[];const schedule=(cb,delay,repeat=false)=>{const id=++timerId;timers.set(id,{cb,delay,repeat,due:now+Number(delay)});return id;};
 Object.assign(window,{location:{href:'https://profile.example.test/fixture-owner',origin:'https://profile.example.test'},setInterval:(cb,d)=>schedule(cb,d,true),setTimeout:(cb,d)=>schedule(cb,d),STONEFELLOW_PROFILE_AGENT:{username:'fixture-owner',ownerName:'Dave',agentName:'Profile Guide',endpoint:'/api/profile-agent.php',conversationNamespace:namespace,profileToken:'FIXTURE_PROFILE_TOKEN',greeting:''}});
 class Clock extends Date{static now(){return now;}}
 const fetch=async(url,options)=>{const body=options.method==='GET'?Object.fromEntries(new URL(url,'https://profile.example.test').searchParams):JSON.parse(options.body);const request={action:body.action,body,method:options.method,now};requests.push(request);return server.handler(body.action,body,request);};
 vm.runInNewContext(source,{window,document,localStorage:storage(local),sessionStorage:storage(session),fetch,URL,URLSearchParams,structuredClone,crypto:webcrypto,Uint8Array,Date:Clock,clearTimeout:id=>timers.delete(id),clearInterval:id=>timers.delete(id)});await flush();
 return {document,window,thread,form,textarea,submit,status,checkbox,availability,owner,end,disclosure,privateInput,requests,local,session,server,
  async send(message){textarea.value=message;form.requestSubmit();await flush();},async choose(value){checkbox.checked=value;checkbox.dispatchEvent(new Event('change'));await flush();},async endChat(){end.dispatchEvent(new Event('click'));await flush();},
  async advance(ms){now+=ms;const due=[...timers].filter(([,t])=>t.due<=now);for(const[id,t]of due){if(!timers.has(id))continue;if(t.repeat)t.due=now+t.delay;else timers.delete(id);t.cb();}await flush();},close(){window.dispatchEvent(new Event('pagehide'));}};
}

{
 const f=await widget();check(!f.checkbox.checked&&!f.checkbox.disabled,'available owner still defaults the visitor to Agent chat');
 await f.send('Public information please');check(f.requests.find(r=>r.action==='message').body.chat_mode==='agent','default send binds an explicit Agent-only mode');check(f.server.state.dispatches===1&&f.thread.textContent.includes('Public Agent response'),'Agent-only turn starts immediately without a pickup countdown');
 check(!f.requests.some(r=>r.action==='end_live_chat')&&f.privateInput.value==='PRIVATE_OWNER_DRAFT'&&!JSON.stringify(f.requests).includes('PRIVATE_OWNER_DRAFT'),'ordinary Agent chat neither ends human sessions nor forwards private composer text');f.close();
}
{
 const f=await widget({server:backend({available:false})});check(f.checkbox.disabled&&!f.checkbox.checked&&f.availability.textContent.includes('unavailable'),'unavailable owner disables live selection and explains Agent fallback');await f.choose(true);await f.send('Still use the Agent');check(f.requests.find(r=>r.action==='message').body.chat_mode==='agent','synthetic checkbox changes cannot request unavailable live chat');f.close();
}
{
 const f=await widget();const before=f.requests.length;await f.choose(true);check(f.requests.length===before&&f.availability.textContent.includes('next message'),'checkbox selection alone sends no live request');await f.send('Can Dave answer?');const saved=f.requests.find(r=>r.action==='message');check(saved.body.chat_mode==='live'&&!Object.hasOwn(saved.body,'continue_live')&&f.server.state.dispatches===0,'fresh live intent has no continuation marker and waits for owner pickup');check(f.checkbox.checked&&f.checkbox.disabled&&!f.end.hidden&&f.end.textContent==='Cancel live request','waiting request locks its mode and exposes an explicit cancel control');check(f.status.textContent.includes('15 seconds'),'waiting state retains the authoritative pickup deadline');await f.advance(14000);check(f.server.state.dispatches===0,'live request does not start Agent fallback before its deadline');await f.advance(1000);check(f.server.state.dispatches===1&&!f.checkbox.checked&&f.status.textContent.includes('timed out'),'timeout returns the same widget to truthful Agent handling');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Request a person');f.server.transition('accepted');f.server.messages.push({id:200,sender_type:'owner',message:'Hello from Dave'});await f.advance(1000);
 check(f.status.textContent.includes('chatting live with Dave')&&f.disclosure.textContent.includes('Profile Agent is paused'),'acceptance switches status and disclosure to direct human text');
 check(f.thread.querySelector('.visitor')?.querySelector('.profile-agent-message-speaker')?.textContent==='You','the visitor message keeps its own explicit speaker attribution');
 const ownerMessage=f.thread.querySelector('.owner');check(ownerMessage?.querySelector('.profile-agent-message-speaker')?.textContent==='Dave'&&ownerMessage.textContent.includes('Hello from Dave'),'owner replies are attributed to the public owner name');
 check(f.server.state.dispatches===0&&!f.end.hidden&&f.end.textContent.includes('End live chat'),'accepted chat exposes End without starting an Agent response');
 f.textarea.value='My unsent follow-up';const before=f.requests.length;await f.endChat();const request=f.requests.slice(before).find(r=>r.action==='end_live_chat');check(request?.body.conversation_id===31&&request.body.turn_id===f.server.state.handoff.turn_id&&request.body.revision===2,'visitor End submits the exact displayed conversation, turn and revision');
 check(f.textarea.value==='My unsent follow-up'&&f.end.hidden&&!f.checkbox.checked&&f.status.textContent.includes('ended'),'ending a live chat keeps the visitor draft and returns to Agent mode');
 check(f.thread.textContent.includes('Hello from Dave')&&f.privateInput.value==='PRIVATE_OWNER_DRAFT','end preserves the text transcript and unrelated private draft');f.close();
}
for(const outcome of ['declined','unavailable']){
 const f=await widget();await f.choose(true);await f.send('Please answer');f.server.transition(outcome);await f.advance(1000);check(!f.checkbox.checked&&f.end.hidden&&f.status.textContent.toLowerCase().includes(outcome==='declined'?'declined':'unavailable'),outcome+' remains explicit after Agent fallback instead of claiming human acceptance');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Cancel before pickup');f.textarea.value='Draft survives cancellation';await f.endChat();check(f.requests.find(r=>r.action==='end_live_chat')?.body.revision===1&&f.server.state.chat.live_status==='ended','pending live request can be cancelled through the same exact-bound end action');check(f.textarea.value==='Draft survives cancellation'&&f.end.hidden,'cancelling a pending request preserves the unsent message');f.close();
}
{
 const server=backend();server.state.loseMessage=true;const local=new Map(),session=new Map(),first=await widget({server,local,session});await first.choose(true);await first.send('Lost live request response');const original=first.requests.find(r=>r.action==='message').body;check(first.checkbox.disabled&&first.checkbox.checked&&first.submit.textContent==='Retry message','uncertain live send freezes its selected mode while offering retry');server.state.chat.live_text_available=false;first.close();const next=await widget({server,local,session});const retry=next.requests.find(r=>r.action==='message')?.body;check(retry?.client_request_id===original.client_request_id&&retry.chat_mode==='live'&&server.records.size===1,'reload and an availability change preserve the exact saved live mode and retry key');next.close();
}
{
 const server=backend(),namespace='b'.repeat(64),session=new Map(),key=`stonefellow-profile-agent:v1:fixture-owner:${namespace}:pending`;session.set(key,JSON.stringify({message:'Old pending request',conversation_id:0,client_request_id:'c'.repeat(32)}));const f=await widget({server,session,namespace});check(f.requests.find(r=>r.action==='message')?.body.chat_mode===undefined,'restoring an older pending request does not reinterpret it as the new Agent default');check(server.records.size===1&&session.size===0,'legacy pending retry clears only after exact saved receipt confirmation');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Wait for acceptance');f.server.transition('accepted');await f.advance(1000);f.server.state.chat.live_text_available=false;await f.advance(1000);check(f.requests.some(r=>r.action==='dispatch')&&f.server.state.chat.live_status==='ended'&&f.status.textContent.includes('ended'),'accepted chat losing current availability triggers canonical maintenance and Agent return');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Accept then end');f.server.transition('accepted');await f.advance(1000);const hold=f.server.hold('poll');await f.advance(1000);const old=await hold;f.textarea.value='Current unsent draft';await f.endChat();old.release();await flush();check(f.end.hidden&&!f.disclosure.textContent.includes('chatting live')&&f.textarea.value==='Current unsent draft','late accepted poll after End cannot revive human mode or overwrite the draft');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('End with a lost result');f.server.transition('accepted');await f.advance(1000);f.server.state.loseEnd=true;f.textarea.value='Unsent draft survives lost End';await f.endChat();
 check(f.status.textContent.includes('could not be confirmed')&&f.textarea.value==='Unsent draft survives lost End','lost end response preserves the draft and makes no premature success claim');
 await f.advance(1000);
 check(f.end.hidden&&!f.checkbox.checked&&f.status.textContent.includes('ended')&&!f.status.textContent.includes('could not be confirmed'),'fresh authoritative ended projection resolves an uncertain end result');f.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Switch account later');f.server.transition('accepted');await f.advance(1000);f.server.state.changed=true;await f.endChat();check(f.checkbox.disabled&&f.end.hidden&&f.textarea.disabled&&f.thread.textContent===''&&f.status.textContent.includes('session changed'),'account switch during End clears prior history and disables controls until refreshed');f.close();
}
{
 const server=backend({ownerName:'Dave <img src=x onerror=alert(1)>'}),f=await widget({server});check(f.owner.textContent==='Dave <img src=x onerror=alert(1)>'&&f.owner.children.length===0,'owner-provided display name remains literal text in the live-choice label');await f.choose(true);await f.send('Hello');server.transition('accepted');server.messages.push({id:200,sender_type:'owner',message:'<script>secret()</script>'});await f.advance(1000);check(!f.thread.querySelector('img')&&!f.thread.querySelector('script')&&f.thread.textContent.includes('<script>secret()</script>'),'owner name and direct-message content never become executable markup');f.close();
}
{
 const f=await widget();const before=f.requests.length;f.server.state.chat={...f.server.state.chat,live_text_available:'true'};await f.advance(10000);check(f.checkbox.disabled&&!f.checkbox.checked&&!f.requests.slice(before).some(r=>r.action==='message'),'malformed availability fails closed without a live request');f.close();
}
{
 const local=new Map(),sessionA=new Map(),serverA=backend();serverA.state.loseMessage=true;const a=await widget({server:serverA,local,session:sessionA});await a.choose(true);await a.send('A private pending live request');const b=await widget({server:backend(),local,session:new Map(),namespace:'d'.repeat(64)});check(!b.thread.textContent.includes('A private')&&!b.requests.some(r=>r.action==='message')&&!b.checkbox.checked,'different visitor namespace cannot inherit another visitor live request or transcript');a.close();b.close();
}
{
 const f=await widget();await f.choose(true);await f.send('Start live text');f.server.transition('accepted');await f.advance(1000);
 f.server.state.loseMessage=true;await f.send('Preserve this accepted follow-up');
 const original=f.requests.filter(r=>r.action==='message').at(-1).body;
 check(original.chat_mode==='live'&&original.continue_live===true&&original.conversation_id===31,'accepted visitor text continues the existing live conversation instead of requesting another');
 await f.send(f.textarea.value);const retry=f.requests.filter(r=>r.action==='message').at(-1).body;
 check(retry.continue_live===true&&retry.client_request_id===original.client_request_id&&f.server.records.size===2,'uncertain accepted follow-up retries the same continuation intent');
 f.server.transition('ended');await f.send('Arrived before the ended poll');const stale=f.requests.filter(r=>r.action==='message').at(-1).body;
 check(stale.continue_live===true&&f.server.state.chat.live_status==='ended'&&f.end.hidden,'cached accepted mode sends a continuation marker and displays the authoritative Agent fallback');f.close();
}
{
 const server=backend(),handle=server.handler;let failState=true;
 server.handler=async(action,body,meta)=>{if(action==='state'&&failState){failState=false;throw Error('State connection unavailable');}return handle(action,body,meta);};
 const namespace='b'.repeat(64),session=new Map(),key=`stonefellow-profile-agent:v1:fixture-owner:${namespace}:pending`,intent={message:'Restore after failed state',conversation_id:0,client_request_id:'e'.repeat(32),chat_mode:'live'};session.set(key,JSON.stringify(intent));
 const f=await widget({server,session,namespace});check(f.textarea.value===intent.message&&f.submit.textContent==='Retry message'&&!f.submit.disabled&&!f.requests.some(r=>r.action==='message'),'saved retry controls survive a failed startup state request');
 await f.send(f.textarea.value);const request=f.requests.find(r=>r.action==='message').body;
 check(request.client_request_id===intent.client_request_id&&request.chat_mode==='live'&&session.size===0,'visitor can retry the original saved intent after initial load failed');f.close();
}
{
 const f=await widget(),hold=f.server.hold('message');await f.choose(true);await f.send('Keep my timed-out live intent');const delayed=await hold;
 await f.advance(11999);check(f.submit.disabled&&f.server.records.size===1,'unconfirmed send remains pending before the response deadline');
 await f.advance(1);check(!f.submit.disabled&&f.submit.textContent==='Retry message'&&f.textarea.value==='Keep my timed-out live intent','response deadline restores the original message and retry controls');
 delayed.release();await flush();check(f.submit.textContent==='Retry message'&&!f.requests.some(r=>r.action==='dispatch'),'late response after local timeout cannot silently settle or dispatch the request');
 await f.send(f.textarea.value);const retries=f.requests.filter(r=>r.action==='message');check(retries.length===2&&retries[0].body.client_request_id===retries[1].body.client_request_id&&retries[1].body.chat_mode==='live'&&f.server.records.size===1,'retry after timeout confirms exactly one saved live intent');f.close();
}
console.log(`PROFILE_CHAT_CHOICE_PA3D=PASS ${checks} checks`);
