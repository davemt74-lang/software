import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';

// Run the production widget; only browser DOM, clock and HTTP are controlled.
class Element extends EventTarget {
  constructor(document,tag='div'){super();this.ownerDocument=document;this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attrs={};this.style={};this.disabled=false;this.value='';this._text='';this.scrollHeight=100;}
  get id(){return this.attrs.id||'';}set id(value){this.attrs.id=String(value);}
  get type(){return this.attrs.type||'';}set type(value){this.attrs.type=String(value);}
  get className(){return this.attrs.class||'';}set className(value){this.attrs.class=String(value);}
  get textContent(){return this._text+this.children.map(child=>child.textContent).join('');}set textContent(value){this._text=String(value);this.replaceChildren();}
  set innerHTML(value){assert.equal(value,'');this._text='';this.replaceChildren();}
  get isConnected(){return this===this.ownerDocument.body||Boolean(this.parentElement?.isConnected);}
  replaceChildren(...children){for(const child of this.children)child.parentElement=null;this.children=[];this.append(...children);}
  append(...children){for(let child of children){if(typeof child==='string')child=this.ownerDocument.createTextNode(child);child.remove();child.parentElement=this;this.children.push(child);}}
  appendChild(child){this.append(child);return child;}
  remove(){if(this.parentElement)this.parentElement.children=this.parentElement.children.filter(child=>child!==this);this.parentElement=null;}
  setAttribute(key,value){this.attrs[key]=String(value);if(key.startsWith('data-'))this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);}
  matches(selector){
    const attributes=[...selector.matchAll(/\[([^\]=]+)(?:=['"]?([^'"\]]*)['"]?)?\]/g)];
    if(attributes.some(([,key,value])=>{const actual=key.startsWith('data-')?this.dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this.attrs[key];return value===undefined?actual===undefined:actual!==value;}))return false;
    const simple=selector.replace(/\[[^\]]+\]/g,'');const tag=simple.match(/^[\w-]+/)?.[0],id=simple.match(/#([\w-]+)/)?.[1];
    return (!tag||this.tagName===tag.toUpperCase())&&(!id||this.id===id)&&[...simple.matchAll(/\.([\w-]+)/g)].every(([,name])=>this.className.split(/\s+/).includes(name));
  }
  querySelectorAll(selector){const found=[];const walk=node=>{for(const child of node.children){if(child.matches(selector))found.push(child);walk(child);}};walk(this);return found;}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  focus(){this.ownerDocument.activeElement=this;}
  requestSubmit(){this.dispatchEvent(new Event('submit',{cancelable:true}));}
}
class Document extends EventTarget {
  constructor(){super();this.body=new Element(this,'body');this.visibilityState='visible';}
  createElement(tag){return new Element(this,tag);}
  createTextNode(text){const element=new Element(this,'#text');element.textContent=text;return element;}
  querySelector(selector){return this.body.querySelector(selector);}
  getElementById(id){return this.querySelector('#'+id);}
}
const source=fs.readFileSync(new URL('../profile-agent.js',import.meta.url),'utf8');
const flush=async()=>{for(let index=0;index<6;index++)await new Promise(resolve=>setImmediate(resolve));};
const storage=map=>({getItem:key=>map.get(key)||null,setItem:(key,value)=>map.set(key,String(value)),removeItem:key=>map.delete(key)});
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
function backend(initialState='agent_ready'){
  let sequence=100,turnSequence=500;const records=new Map(),messages=[];
  const state={conversation:null,handoff:null,firstLost:false,namespaceChanged:false,hold:null,dispatchCount:0};
  const reply=(data,status=200)=>({ok:status<400,status,json:async()=>structuredClone(data)});
  const handler=async(action,body,meta)=>{
    if(state.namespaceChanged)return reply({ok:false,code:'PROFILE_SESSION_CHANGED',error:'Refresh this profile.'},409);
    if(action==='state'||action==='poll')return reply({ok:true,agent:{name:'Fixture Guide',greeting:''},conversation:state.conversation,messages:messages.filter(message=>message.id>Number(body.after_id||0)),handoff:state.handoff&&{...state.handoff,server_now_ms:meta.now}});
    if(action==='message'){
      let accepted=records.get(body.client_request_id);
      if(!accepted){
        state.conversation={conversation_id:31,status:initialState==='owner_claimed'?'owner_joined':'open'};
        const message={id:++sequence,sender_type:'visitor',message:body.message};messages.push(message);
        state.handoff={turn_id:++turnSequence,revision:1,conversation_id:31,state:initialState,server_now_ms:meta.now,pickup_deadline_ms:initialState==='awaiting_owner'?meta.now+15000:null,dispatch_turn_id:turnSequence,dispatch_state:initialState};
        accepted={ok:true,accepted:true,conversation_id:31,visitor_message_id:message.id,client_request_id:body.client_request_id,conversation:state.conversation,handoff:state.handoff};records.set(body.client_request_id,accepted);
      }
      if(state.firstLost){state.firstLost=false;throw new Error('fixture lost response');}
      if(state.hold)return await state.hold(accepted);
      return reply(accepted);
    }
    if(action==='dispatch'){
      state.dispatchCount++;state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:state.handoff.revision+1};
      messages.push({id:++sequence,sender_type:'agent',message:'Fixture saved public answer'});
      return reply({ok:true,conversation_id:31,turn_id:body.turn_id,dispatch_checked:true});
    }
    throw new Error('Unexpected fixture action '+action);
  };
  return {state,records,messages,handler,reply};
}
async function widget({backend:server=backend(),local=new Map(),session=new Map(),namespace='a'.repeat(64)}={}){
  const document=new Document(),window=new EventTarget(),shell=document.createElement('section');shell.id='profileAgentShell';document.body.append(shell);
  const thread=document.createElement('div');thread.dataset.profileAgentThread='';
  const form=document.createElement('form'),textarea=document.createElement('textarea'),submit=document.createElement('button');submit.type='submit';submit.textContent='Send';form.append(textarea,submit);
  const status=document.createElement('div');status.dataset.profileAgentStatus='';shell.append(thread,form,status);
  const privateInput=document.createElement('textarea');privateInput.id='chatInput';privateInput.value='PRIVATE_CHAT_DRAFT_MUST_STAY_PRIVATE';document.body.append(privateInput);
  let now=1800000000000,timerId=0;const timers=new Map(),requests=[];
  const schedule=(callback,delay,repeat=false)=>{const id=++timerId;timers.set(id,{callback,delay,repeat,due:now+Number(delay)});return id;};
  Object.assign(window,{location:{href:'https://profile.example.test/fixture-owner',origin:'https://profile.example.test'},setInterval:(cb,delay)=>schedule(cb,delay,true),setTimeout:(cb,delay)=>schedule(cb,delay),STONEFELLOW_PROFILE_AGENT:{username:'fixture-owner',endpoint:'/api/profile-agent.php',conversationNamespace:namespace,profileToken:'FIXTURE_PROFILE_TOKEN',greeting:''}});
  class Clock extends Date{static now(){return now;}}
  const fetch=async(url,options)=>{
    const body=options.method==='GET'?Object.fromEntries(new URL(url,'https://profile.example.test').searchParams):JSON.parse(options.body);
    const request={action:body.action,body,method:options.method,now};requests.push(request);
    return server.handler(body.action,body,request);
  };
  vm.runInNewContext(source,{window,document,localStorage:storage(local),sessionStorage:storage(session),fetch,URL,URLSearchParams,structuredClone,crypto:webcrypto,Uint8Array,Date:Clock,clearTimeout:id=>timers.delete(id),clearInterval:id=>timers.delete(id)});
  await flush();
  return {document,window,thread,form,textarea,submit,status,privateInput,requests,local,session,server,
    async send(message){textarea.value=message;form.requestSubmit();await flush();},
    async advance(milliseconds){now+=milliseconds;const due=[...timers].filter(([,timer])=>timer.due<=now);for(const[id,timer]of due){if(!timers.has(id))continue;if(timer.repeat)timer.due=now+timer.delay;else timers.delete(id);timer.callback();}await flush();},
    close(){window.dispatchEvent(new Event('pagehide'));},
  };
}

{
  const server=backend();server.state.firstLost=true;const f=await widget({backend:server});await f.send('What are your public hours?');
  const first=f.requests.find(request=>request.action==='message');
  check(server.records.size===1&&f.submit.textContent==='Retry message','lost response keeps one saved intent and offers an exact retry');
  check(f.textarea.value==='What are your public hours?'&&f.status.textContent.includes('may already be saved'),'uncertainty preserves the submitted message and explains its state');
  await f.send(f.textarea.value);const sends=f.requests.filter(request=>request.action==='message');
  check(sends.length===2&&sends[1].body.client_request_id===first.body.client_request_id&&sends[1].body.message===first.body.message,'retry preserves the original request ID and message');
  check(server.records.size===1&&f.thread.querySelectorAll('.visitor').length===1,'retry recovery renders the canonical visitor message once');
  check(server.state.dispatchCount===1&&f.thread.textContent.includes('Fixture saved public answer'),'saved native turn is dispatched and its canonical answer arrives by poll');
  check(f.requests.filter(request=>request.action==='poll').every(request=>request.method==='GET')&&f.requests.filter(request=>request.action==='dispatch').every(request=>request.method==='POST'),'poll reads use GET while execution assistance uses POST');
  check(f.privateInput.value==='PRIVATE_CHAT_DRAFT_MUST_STAY_PRIVATE'&&!JSON.stringify(f.requests).includes('PRIVATE_CHAT_DRAFT_MUST_STAY_PRIVATE'),'visitor workflow neither changes nor transmits the private Chat draft');
  await f.send('A different public question');const next=f.requests.filter(request=>request.action==='message').at(-1);
  check(next.body.client_request_id!==first.body.client_request_id&&next.body.conversation_id===31,'a second explicit message gets a new request ID in the saved conversation');f.close();
}
{
  const server=backend();server.state.firstLost=true;const local=new Map(),session=new Map();const first=await widget({backend:server,local,session});await first.send('Recover after reload');const requestId=first.requests.find(request=>request.action==='message').body.client_request_id;first.close();
  const resumed=await widget({backend:server,local,session});
  check(resumed.requests.find(request=>request.action==='message')?.body.client_request_id===requestId&&server.records.size===1,'reload retries the previously authorized saved request without another intent');
  check(resumed.thread.querySelectorAll('.visitor').length===1&&session.size===0,'reload removes the uncertain retry only after a verified saved message receipt');resumed.close();
}
{
  const server=backend('awaiting_owner'),f=await widget({backend:server});await f.send('Wait for the owner');
  check(server.state.dispatchCount===0&&f.status.textContent.includes('15 seconds'),'the visible server deadline is shown without starting Agent execution early');
  await f.advance(14000);check(server.state.dispatchCount===0,'browser assistance stays idle before the server deadline');
  await f.advance(1000);check(server.state.dispatchCount===1,'browser assists the exact saved turn once its reported deadline is due');f.close();
}
{
  const server=backend('owner_claimed'),f=await widget({backend:server});await f.send('Please ask the owner');await f.advance(20000);
  check(server.state.dispatchCount===0&&f.status.textContent.includes('owner has joined'),'owner takeover suppresses automatic Agent dispatch across polling');
  server.messages.push({id:200,sender_type:'owner',message:'Fixture direct owner response'});await f.advance(10000);
  check(f.thread.textContent.includes('Fixture direct owner response'),'direct owner replies appear through the same canonical message poll');f.close();
}
{
  const server=backend(),normal=server.handler;
  server.handler=async(action,body,meta)=>{const response=await normal(action,body,meta);if(action==='message')Object.assign(server.state.handoff,{execution_paused:true,dispatch_execution_paused:true});return response;};
  const f=await widget({backend:server});await f.send('Wait for an explicit owner resume');
  check(server.state.dispatchCount===0&&f.status.textContent.includes('is paused')&&!f.status.textContent.includes('preparing'),'a manually paused Agent is shown as waiting without automatic dispatch');
  const polls=f.requests.filter(request=>request.action==='poll').length;await f.advance(1000);await f.advance(1000);
  check(server.state.dispatchCount===0&&f.requests.filter(request=>request.action==='poll').length>polls,'paused work keeps read-only polling without a repeated execution request');
  Object.assign(server.state.handoff,{execution_paused:false,dispatch_execution_paused:false});await f.advance(1000);
  check(server.state.dispatchCount===1&&f.thread.textContent.includes('Fixture saved public answer'),'polling notices explicit canonical resume and assists the saved turn once');f.close();
}
{
  const server=backend(),normal=server.handler;
  server.handler=async(action,body,meta)=>{const response=await normal(action,body,meta);if(action==='message')Object.assign(server.state.handoff,{turn_id:server.state.handoff.turn_id+1,execution_paused:false,dispatch_execution_paused:true});return response;};
  const f=await widget({backend:server});await f.send('Wait behind an earlier paused turn');await f.advance(2000);
  check(server.state.dispatchCount===0&&f.status.textContent.includes('is paused'),'an earlier dispatch turn uses its own pause state instead of the latest displayed turn');
  Object.assign(server.state.handoff,{execution_paused:true,dispatch_execution_paused:false});await f.advance(1000);
  check(server.state.dispatchCount===1,'a later paused display turn does not block an earlier eligible dispatch turn');f.close();
}
{
  const server=backend();server.state.firstLost=true;const f=await widget({backend:server});await f.send('Only visitor A may send this');server.state.namespaceChanged=true;
  await f.send(f.textarea.value);
  check(f.submit.disabled&&f.textarea.disabled&&f.status.textContent.includes('session changed'),'account switch requires a fresh profile instead of resending under the new principal');
  check(f.thread.textContent===''&&f.session.size===0,'principal change clears prior visitor history and pending retry material');
  check(f.requests.every(request=>request.body.conversation_namespace==='a'.repeat(64)),'all state, message and dispatch requests carry the captured visitor namespace');f.close();
}
{
  const server=backend();let resolve;server.state.hold=accepted=>new Promise(done=>{resolve=()=>done(server.reply(accepted));});const f=await widget({backend:server});await f.send('Delayed old page request');
  const prior=f.thread.textContent;f.close();resolve();await flush();
  check(server.state.dispatchCount===0&&f.thread.textContent===prior,'late admission response after page teardown cannot publish or dispatch work');
}
{
  const local=new Map(),serverA=backend(),serverB=backend();serverA.state.firstLost=true;
  const a=await widget({backend:serverA,local,session:new Map(),namespace:'a'.repeat(64)});await a.send('Visitor A pending text');
  const b=await widget({backend:serverB,local,session:new Map(),namespace:'b'.repeat(64)});
  check(!b.requests.some(request=>request.action==='message')&&!b.thread.textContent.includes('Visitor A'),'another visitor namespace does not inherit the first visitor pending send or transcript');
  await b.send('Visitor B public question');
  check(serverB.records.size===1&&[...serverB.records.values()][0].client_request_id!==[...serverA.records.values()][0].client_request_id,'separate visitors retain independent explicit request identities');a.close();b.close();
}
{
  const server=backend(),normal=server.handler;let resolveState;
  server.handler=async(action,body,meta)=>{
    if(action==='state'&&!resolveState)return new Promise(resolve=>{resolveState=()=>resolve(server.reply({ok:true,agent:{greeting:''},messages:[],conversation:null,handoff:null}));});
    return normal(action,body,meta);
  };
  const f=await widget({backend:server});await f.send('Send before initial restoration finishes');const before=f.thread.textContent;
  resolveState();await flush();
  check(f.thread.textContent===before&&f.thread.textContent.includes('Fixture saved public answer'),'late initial state cannot erase a newer accepted visitor turn or answer');
  check(server.records.size===1&&server.state.dispatchCount===1,'restoration race creates no extra turn or automatic response');f.close();
}
console.log(`PROFILE_VISITOR_HANDOFF_PA3=PASS ${checks} checks`);
