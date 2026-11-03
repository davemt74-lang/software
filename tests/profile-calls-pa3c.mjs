import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {contract,copy,callCard,installFakeMedia,Document,FixtureCustomEvent} from './fixtures/profile-calls-pa3c.mjs';

const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const runtime=read('profile-calls-pa3c.js'),leaseSource=read('voice-lease-v122.js'),publicSource=read('profile-agent.js'),chatSource=read('chat.js');
const settle=async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));};
let checks=0;function check(value,label){assert.ok(value,label);checks++;console.log('PASS '+label);}
const storage=map=>({getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,String(v)),removeItem:k=>map.delete(k)});

async function fixture({role='visitor',initial=callCard(role),widget=false,cid=31,sdk=true}={}){
  const document=new Document(),window=new EventTarget(),host=document.createElement('div'),privateInput=document.createElement('textarea');
  privateInput.id='chatInput';privateInput.value='PRIVATE_DRAFT_DO_NOT_RELAY';document.body.append(host,privateInput);
  let now=1800000000000,elapsed=0,nextTimer=0,chatCid=42,chatAgent=7,publicCid=cid,contextEpoch=0,visible=true;
  const timers=new Map(),requests=[],holds=[],failures=new Map(),operations=new Map(),cards=new Map(initial?[[initial.call_id,copy(initial)]]:[]),local=new Map(),session=new Map();
  const schedule=(fn,delay,repeat=false)=>{const id=++nextTimer;timers.set(id,{fn,delay,due:elapsed+delay,repeat});return id;};
  const timerApi={setTimeout:(fn,delay=0)=>schedule(fn,delay),setInterval:(fn,delay)=>schedule(fn,delay,true),clearTimeout:id=>timers.delete(id),clearInterval:id=>timers.delete(id)};
  Object.assign(window,timerApi,{document,location:{href:'https://fixture.test/profile',origin:'https://fixture.test',pathname:'/profile'}});
  let stats=installFakeMedia(window);if(!sdk)delete window.LivekitClient;
  const response=(data,status=200)=>({ok:status<400,status,json:async()=>copy(data)});
  const normalized=card=>({...copy(card),role,server_now_ms:now,...(role==='owner'?{chat_conversation_id:chatCid,chat_agent_id:chatAgent}: {})});
  function setState(card,state){Object.assign(card,{state,revision:card.revision+1,can_accept:false,can_decline:false,can_cancel:false,can_end:['preparing','ready','active'].includes(state),can_join:['ready','active'].includes(state),cleanup_pending:state==='ending',can_return_to_agent:state==='ending'||state==='ended',dispatch_due:state==='preparing'});}
  const transport=async(url,options={})=>{
    const parsed=new URL(url,'https://fixture.test'),body=options.method==='GET'?Object.fromEntries(parsed.searchParams):JSON.parse(options.body||'{}');
    for(const key of ['call_id','revision','conversation_id','chat_conversation_id','chat_agent_id'])if(body[key]!==undefined)body[key]=Number(body[key]);
    requests.push({path:parsed.pathname,method:options.method,...copy(body)});
    if(failures.has(body.action)){const failure=failures.get(body.action);return response({ok:false,code:failure.code},failure.status);}
    let result={ok:true};const action=body.action;
    if(parsed.pathname==='/api/profile-agent.php'){
      if(action==='state'||action==='poll')Object.assign(result,{messages:[],conversation:publicCid?{id:publicCid,status:'open'}:null,agent:{greeting:''}});
      else if(action==='message'){publicCid=publicCid||31;Object.assign(result,{conversation_id:publicCid,visitor_message_id:500,client_request_id:body.client_request_id,conversation:{id:publicCid,status:'owner_joined'},handoff:null});}
      else throw new Error('Unexpected Profile action '+action);
    }else{
      const operation=action.replace(/^(owner|visitor)_/,'');
      if(!['state','delivery','heartbeat'].includes(operation)&&! /^[a-f0-9]{32}$/.test(body.request_id||''))return response({ok:false,code:'PROFILE_CALL_INVALID'},422);
      if(operation==='state')Object.assign(result,{contract,calls:(body.conversation_id===0&&role==='visitor')?[]:[...cards.values()].map(normalized),can_offer:cards.size===0&&(role!=='owner'||body.conversation_id>0),reason:'',server_now_ms:now,conversation_id:body.conversation_id||0});
      else if(operation==='offer'){
        publicCid=body.conversation_id||31;const card=callCard(role,{call_id:41,conversation_id:publicCid,mode:body.mode,offered_by:role,can_accept:false,can_decline:false,can_cancel:true});cards.set(41,card);
        Object.assign(result,{contract,call:normalized(card),call_id:41,revision:card.revision,conversation_id:publicCid,request_id:body.request_id,duplicate:false});
      }else{
        const card=cards.get(body.call_id);if(!card)throw new Error('Unexpected fixture call');
        if(operation==='accept')setState(card,'preparing');
        else if(operation==='dispatch'){if(card.state==='preparing')setState(card,'ready');}
        else if(operation==='end')setState(card,'ending');
        else if(operation==='return_to_agent'){card.revision++;card.can_return_to_agent=false;}
        else if(operation==='join')card.authority_valid_until_ms=now+30000;
        else if(operation==='heartbeat')card.authority_valid_until_ms=now+30000;
        else if(!['delivery','cancel','decline'].includes(operation))throw new Error('Unexpected call action '+action);
        Object.assign(result,{contract,call:normalized(card),call_id:card.call_id,revision:card.revision,conversation_id:card.conversation_id,request_id:body.request_id||'',duplicate:false});
        if(operation==='delivery')Object.assign(result,{delivery_id:'d'.repeat(32),chat_conversation_id:body.chat_conversation_id,chat_agent_id:body.chat_agent_id,chat_message_id:701,message_id:701});
        if(operation==='join')Object.assign(result,{role,client_instance_id:body.client_instance_id,join_intent_id:'b'.repeat(32),server_url:'wss://fixture.livekit.cloud',participant_token:'FIXTURE_'+role.toUpperCase()+'_TOKEN_'+'x'.repeat(40),participant_identity:role+'-41',peer_identity:(role==='owner'?'visitor':'owner')+'-41',expires_at_ms:now+60000,microphone:true,camera:body.camera});
      }
    }
    const at=holds.findIndex(h=>h.action===action);
    if(at>=0){const held=holds.splice(at,1)[0];return new Promise((resolve,reject)=>{held.work={body,result,resolve:(overrides={},status=200)=>resolve(response({...result,...overrides},status)),reject};});}
    return response(result);
  };
  class Clock extends Date{static now(){return now;}}
  const globals={window,document,location:window.location,navigator:{},localStorage:storage(local),sessionStorage:storage(session),crypto:webcrypto,Uint8Array,URL,URLSearchParams,JSON,Date:Clock,performance:{now:()=>elapsed},Event,CustomEvent:FixtureCustomEvent,structuredClone,fetch:transport,...timerApi};
  const context=vm.createContext(globals);vm.runInContext(leaseSource,context);
  let surface,shell,form,textarea;
  if(role==='owner'){
    host.id='chatThread';host.dataset.view='chat';
    const helpers=chatSource.slice(chatSource.indexOf('  function bindStoredMessagePa3('),chatSource.indexOf('  function formatPlaybackTime('));
    vm.runInContext('let conversationId=42,contextReady=true,restoringConversation=0;const thread=document.getElementById("chatThread"),cfg={agentId:7};function addMessage(role,text){const n=document.createElement("article");n.className="message assistant";const b=document.createElement("div");b.className="message-body";const t=document.createElement("p");t.className="message-text";t.textContent=text;b.append(t);n.append(b);thread.append(n);return n;}'+helpers+';window.__realMount=mountProfileCallPa3c;window.__binding=(cid,aid)=>{conversationId=cid;cfg.agentId=aid;};',context);
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>chatCid,agentId:()=>chatAgent,isReady:()=>true,isRestoring:()=>false,mountProfileCall:delivery=>window.__realMount(delivery)};
    window.VP3_PROFILE_CALLS_PA3C={role:'owner',endpoint:'/api/profile-calls-pa3c.php',csrf:'FIXTURE_CSRF'};
  }
  vm.runInContext(runtime,context);
  if(widget){
    shell=document.createElement('section');shell.id='profileAgentShell';const thread=document.createElement('div');thread.dataset.profileAgentThread='';form=document.createElement('form');textarea=document.createElement('textarea');const submit=document.createElement('button');submit.type='submit';submit.textContent='Send';form.append(textarea,submit);const status=document.createElement('div');status.dataset.profileAgentStatus='';shell.append(thread,form,status);document.body.append(shell);
    window.STONEFELLOW_PROFILE_AGENT={username:'fixture-owner',endpoint:'/api/profile-agent.php',callEndpoint:'/api/profile-calls-pa3c.php',callOwnerName:'Human Owner',conversationNamespace:'a'.repeat(64),profileToken:'FIXTURE_PROFILE_TOKEN',greeting:''};
    if(cid)local.set('stonefellow-profile-agent:v1:fixture-owner:'+'a'.repeat(64),String(cid));
    vm.runInContext(publicSource,context);
  }else if(role==='visitor'){
    surface=window.VP3ProfileCallsPa3c.mountVisitor({host,peerLabel:'Human Owner',context:()=>({key:publicCid+':'+contextEpoch,binding:{conversation_id:publicCid},active:visible}),request:async(action,payload,method)=>{const url=new URL('/api/profile-calls-pa3c.php',window.location.href);const opts={method};if(method==='GET')Object.entries({action,...payload}).forEach(([k,v])=>url.searchParams.set(k,String(v)));else opts.body=JSON.stringify({action,...payload});const result=await transport(url,opts),data=await result.json();if(!result.ok||!data.ok){const error=new Error('fixture');error.status=result.status;error.code=data.code;throw error;}return data;},adopt:r=>publicCid=r.conversation_id});
  }
  const f={window,document,host,privateInput,stats,requests,failures,local,session,cards,context,get surface(){return surface;},get shell(){return shell;},get form(){return form;},get textarea(){return textarea;},get now(){return now;},card:()=>document.querySelector('.profile-call-card'),
    hold(action){const held={action,work:null};holds.push(held);return held;},
    async refresh(){if(surface)await surface.refresh();else if(role==='owner')await window.VP3ProfileCallsPa3c.refresh();else window.dispatchEvent(new FixtureCustomEvent('vp3:profile-agent-visibility',{detail:{open:true}}));await settle();},
    async click(action){this.card().querySelector('[data-call-action="'+action+'"]').click();await settle();},
    async consent(camera=false){const inputs=this.card().querySelectorAll('input');inputs[0].checked=true;inputs[0].dispatchEvent(new Event('change'));inputs[1].checked=camera;inputs[1].dispatchEvent(new Event('change'));await settle();},
    async ready(){const c=cards.get(41);setState(c,'ready');await this.refresh();},
    async advance(ms){for(let remain=ms;remain>0;){const step=Math.min(1000,remain);now+=step;elapsed+=step;remain-=step;for(const[id,timer]of [...timers])if(timer.due<=elapsed&&timers.has(id)){if(timer.repeat)timer.due=elapsed+timer.delay;else timers.delete(id);timer.fn();}await settle();}},
    async changeContext(){if(role==='owner'){window.dispatchEvent(new Event('stonefellow:chat-context-changing'));chatCid=55;chatAgent=8;window.__binding(chatCid,chatAgent);host.replaceChildren();window.dispatchEvent(new Event('vp3:chat-context-ready'));}else{surface?.invalidate('Visitor changed.');contextEpoch++;publicCid=99;host.replaceChildren();}await settle();},
    async closeWidget(){shell.hidden=true;window.dispatchEvent(new FixtureCustomEvent('vp3:profile-agent-visibility',{detail:{open:false}}));await settle();},
    async stop(){window.dispatchEvent(new Event('pagehide'));await settle();}
  };
  await settle();return f;
}

{
  const f=await fixture();check(f.card()?.textContent.includes('Human Owner'),'actual runtime mounts human call controls with an inert text label');
  check(f.stats.rooms.length===0&&!f.requests.some(r=>r.action==='visitor_join'),'read and incoming offer do not request token, microphone or camera');
  await f.click('accept');check(f.cards.get(41).state==='ready'&&f.requests.some(r=>r.action==='visitor_dispatch'),'acceptance uses separate explicit provider dispatch to reach readiness');
  check(f.stats.microphones===0&&f.stats.cameras===0&&f.card().querySelector('[data-call-action=join]').disabled,'accept does not capture and Join remains disabled without microphone consent');
  check(f.card().querySelectorAll('input').every(input=>input.checked===false),'microphone and optional camera are unchecked by default');
  await f.consent();await f.click('join');check(f.stats.connections===1&&f.stats.microphones===1&&f.stats.cameras===0,'explicit audio consent connects one microphone while the video camera stays off');
  check(f.card().textContent.includes('waiting for the other person'),'locally connected status does not claim the remote person joined');
  f.stats.rooms[0].addPeer();await settle();check(f.card().querySelector('audio')&&f.card().textContent.includes('speaking directly'),'actual remote audio publication mounts and reports direct human conversation');
  check(!JSON.stringify(f.requests).includes('PRIVATE_DRAFT')&&f.privateInput.value==='PRIVATE_DRAFT_DO_NOT_RELAY','call traffic never reads, forwards or modifies the private Chat composer');
  check(!JSON.stringify([...f.local,...f.session]).includes('TOKEN_')&&!f.card().textContent.includes('TOKEN_'),'provider credentials stay out of browser storage and rendered content');
  const held=f.hold('visitor_end');await f.click('end');check(f.stats.tracks.filter(t=>t.local&&t.source==='microphone').every(t=>t.stopped),'explicit hang-up stops local microphone before its server response returns');
  check(f.card().textContent.includes('microphone and camera are off'),'local shutdown is reported while the remote cleanup result remains pending');held.work.resolve();await settle();
  check(f.card().textContent.includes('Service cleanup is pending'),'ending never claims verified provider cleanup');
  await f.click('return_to_agent');check(f.requests.some(r=>r.action==='visitor_return_to_agent')&&f.card().textContent.includes('next message'),'explicit Return to Agent uses its own server action and shows verified next-message state');await f.stop();
}
{
  const f=await fixture();await f.ready();await f.consent(true);const held=f.hold('visitor_join');f.card().querySelector('[data-call-action=join]').click();f.card().querySelector('[data-call-action=join]').click();await settle();
  check(f.requests.filter(r=>r.action==='visitor_join').length===1&&f.stats.connections===0,'double Join has one pending request and does not capture before its authorization');held.work.resolve();await settle();
  check(f.stats.microphones===1&&f.stats.cameras===1,'explicit optional-camera consent publishes video once');await f.stop();check(f.stats.tracks.every(t=>t.stopped),'page teardown stops every captured media track');
}
for(const code of ['PROFILE_CALL_IN_USE','PROFILE_CALL_WORK_PENDING']){
  const f=await fixture();await f.ready();await f.consent();const held=f.hold('visitor_join');await f.click('join');
  // Join itself does not change the canonical revision. A separate provider
  // observation can advance ready -> active while this token attempt waits.
  if(code==='PROFILE_CALL_WORK_PENDING'){const card=f.cards.get(41);Object.assign(card,{state:'active',revision:card.revision+1,connection_verified:true,owner_connected:true,visitor_connected:true});}
  held.work.resolve({ok:false,code},409);await settle();
  check(f.stats.rooms.length===0&&f.stats.microphones===0&&!f.requests.some(r=>r.action==='visitor_end'),code+' rejection neither captures nor hangs up the first participant');
  if(code==='PROFILE_CALL_WORK_PENDING'){const first=held.work.body;await f.click('join');const attempts=f.requests.filter(r=>r.action==='visitor_join');check(attempts.length===2&&attempts[1].request_id===first.request_id&&attempts[1].client_instance_id===first.client_instance_id&&attempts[1].revision>first.revision&&f.stats.connections===1,'simultaneous-party provider conflict retries the same intent at the refreshed revision');}
  await f.stop();
}
{
  const f=await fixture();await f.ready();await f.consent();const held=f.hold('visitor_join');await f.click('join');held.work.reject(new Error('lost reply'));await settle();
  check(!f.requests.some(r=>r.action==='visitor_end')&&f.stats.rooms.length===0,'uncertain Join response does not cancel a possibly established peer call');
  await f.click('join');check(f.requests.filter(r=>r.action==='visitor_join')[1].request_id===held.work.body.request_id,'explicit retry after lost Join reply retains the same operation identity');await f.stop();
}
{
  const f=await fixture();await f.ready();await f.consent();const prior=f.window.StonefellowVoiceLeaseV122.acquireCapture('chat-voice');check(Boolean(prior),'fixture holds the actual shared capture lease');await f.click('join');
  check(f.stats.connections===0&&f.card().textContent.includes('Another voice surface'),'busy shared capture owner requires an explicit switch without opening media');
  f.card().querySelectorAll('button').find(b=>b.textContent==='Switch microphone to this call').click();await settle();
  check(f.stats.connections===1&&f.stats.microphones===1&&!f.requests.some(r=>r.action==='visitor_end')&&!prior.isCurrent(),'intentional yield handles real synchronous lease-lost event without ending its own new call');
  f.window.StonefellowVoiceLeaseV122.yield();await settle();check(f.stats.tracks.every(t=>t.stopped),'later genuine capture ownership loss stops call tracks immediately');await f.stop();
}
for(const kind of ['connect','microphone','camera'])for(const reject of [false,true]){
  const f=await fixture();await f.ready();await f.consent(kind==='camera');const held=f.stats.hold(kind);await f.click('join');check(Boolean(held.pending),'fixture reached pending '+kind+' operation');
  await f.click('end');check(f.requests.some(r=>r.action==='visitor_end'),'End remains usable during pending '+kind+' permission/connection');
  if(reject)held.pending.reject();else held.pending.resolve();await settle();
  check(f.stats.tracks.every(t=>t.stopped)&&f.stats.disconnects>=1&&!f.card().textContent.includes('Connected ·'),'late '+kind+' '+(reject?'rejection':'resolution')+' after hang-up cannot retain tracks or revive the call');await f.stop();
}
{
  const f=await fixture({sdk:false});await f.ready();await f.consent();await f.click('join');const script=f.document.head.querySelector('script');check(Boolean(script)&&f.stats.rooms.length===0,'SDK loads only after explicit authorized Join');await f.click('end');installFakeMedia(f.window);script.onload();await settle();check(f.window.__callMediaFixture.rooms.length===0,'late SDK load after hang-up never creates a Room');await f.stop();
}
{
  const f=await fixture({role:'owner'});check(f.host.querySelector('[data-chat-message-id="701"]')&&f.card(),'real Chat helper binds the call card to its actual private message');
  check(f.host.querySelector('[data-chat-message-id="701"]').dataset.profileCallConversation==='31','stored call marker has its exact public conversation without a fake text turn');
  await f.refresh();check(f.host.querySelectorAll('.message').length===1,'repeated delivery deduplicates the persisted main Chat message');
  await f.ready();await f.consent();const held=f.stats.hold('connect');await f.click('join');await f.changeContext();held.pending.resolve();await settle();
  check(f.stats.tracks.every(t=>t.stopped)&&f.stats.disconnects>0&&!f.card()?.textContent.includes('Connected ·'),'late connection after private Chat/Agent switch cannot capture or revive old context');
  const own=f.requests.find(r=>r.action==='owner_join');check(own.chat_conversation_id===42&&own.chat_agent_id===7&&own.delivery_id==='d'.repeat(32)&&own.conversation_id===31&&own.csrf_token==='FIXTURE_CSRF','owner join carries captured public call plus exact private Chat, Agent, card and CSRF proof');await f.stop();
}
{
  const f=await fixture();await f.ready();await f.consent();await f.click('join');
  const until=f.cards.get(41).authority_valid_until_ms;
  for(let i=0;i<3;i++){const held=f.hold('visitor_heartbeat');await f.advance(10000);if(held.work)held.work.resolve({ok:false,code:'CALL_PROVIDER_UNAVAILABLE'},503);await settle();}
  check(f.now>=until&&f.stats.tracks.every(t=>t.stopped)&&!f.card().textContent.includes('Connected ·'),'failed heartbeats never extend the confirmed deadline; local media expires on time');await f.stop();
}
{
  const f=await fixture({widget:true,initial:null,cid:0});const offer=f.document.querySelector('[data-call-offer=audio]');check(offer&&!offer.disabled,'actual native Profile bridge exposes an available call before any message');offer.click();await settle();
  const req=f.requests.find(r=>r.action==='visitor_offer');check(req.path==='/api/profile-calls-pa3c.php'&&req.profile_token==='FIXTURE_PROFILE_TOKEN'&&req.conversation_namespace==='a'.repeat(64)&&req.conversation_id===0,'call-only request reuses the captured native session/token and namespace at the real new endpoint');
  check(f.local.get('stonefellow-profile-agent:v1:fixture-owner:'+'a'.repeat(64))==='31'&&!f.requests.some(r=>r.action==='message'),'canonical call-only CID is adopted without fabricating a public message');
  await f.ready();await f.consent();await f.click('join');f.textarea.value='Can we discuss the public product?';f.form.requestSubmit();await settle();check(f.stats.microphones===1&&!f.stats.tracks[0].stopped,'typing a real public message does not invalidate the live call namespace');
  const text=f.requests.find(r=>r.action==='message');check(text.conversation_id===31&&text.message==='Can we discuss the public product?'&&!text.message.includes('PRIVATE'),'public text remains on its canonical thread while main Chat stays private');
  await f.closeWidget();check(f.stats.tracks.every(t=>t.stopped)&&f.requests.some(r=>r.action==='visitor_end'),'closing the actual Profile widget stops media and requests exact call cleanup');await f.stop();
}
{
  const f=await fixture({widget:true});await f.ready();await f.consent();await f.click('join');
  f.window.STONEFELLOW_PROFILE_AGENT.conversationNamespace='e'.repeat(64);await f.advance(3000);check(f.stats.tracks.every(t=>t.stopped),'visitor namespace change tears down active media before further capture');await f.stop();
}
for(const delayed of ['join','heartbeat']){
  const f=await fixture();await f.ready();await f.consent();const held=f.hold('visitor_'+delayed);await f.click('join');
  check(Boolean(held.work),'fixture holds delayed '+delayed+' authority response');
  await f.advance(11000);f.failures.set('visitor_heartbeat',{code:'CALL_PROVIDER_UNAVAILABLE',status:503});held.work.resolve();await settle();
  check(f.stats.microphones===1,'still-current delayed '+delayed+' response can use only its remaining lease');
  await f.advance(20000);check(f.stats.tracks.every(t=>t.stopped),'delayed '+delayed+' response cannot add its network delay to the server lease');await f.stop();
}
{
  const f=await fixture({widget:true});await f.ready();await f.consent();const held=f.stats.hold('microphone');await f.click('join');held.pending.reject();await settle();
  check(f.stats.tracks.every(t=>t.stopped)&&f.requests.some(r=>r.action==='visitor_end'),'capture failure cleans any partial microphone and asks for canonical cleanup');
  check(!f.textarea.disabled&&f.card().textContent.includes('keep messaging'),'hardware failure leaves actual visitor text entry usable');await f.stop();
}
console.log('PROFILE_CALLS_PA3C=PASS ('+checks+' runtime assertions)');
