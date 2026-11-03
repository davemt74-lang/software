import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {Document,Element,FixtureCustomEvent} from './profile-calls-pa3c.mjs';
import {ownerCard} from './profile-visitor-doorbell-pa3.mjs';

export const contract='vp3.profile-speech.v1';
export const speechItem=(overrides={})=>({id:81,revision:1,kind:'visitor_message',public_conversation_id:1101,turn_id:101,visitor_message_id:2101,
  visitor_label:'Visitor 101',display_text:'Public message for the owner.',voice_assignment_id:7,voice_revision:1,state:'available',can_play:true,automatic_allowed:false,reason:'',
  chat_conversation_id:0,chat_agent_id:0,chat_message_id:0,delivery_id:'',...overrides});
const read=name=>fs.readFileSync(new URL('../../'+name,import.meta.url),'utf8');
const settle=async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));};
const storage=map=>({getItem:key=>map.get(key)||null,setItem:(key,value)=>map.set(key,String(value)),removeItem:key=>map.delete(key)});
Element.prototype.closest=function(selector){for(let el=this;el;el=el.parentElement)if(el.matches(selector))return el;return null;};
Element.prototype.contains=function(child){for(let el=child;el;el=el.parentElement)if(el===this)return true;return false;};
Element.prototype.getBoundingClientRect=function(){return this.hidden?{top:0,left:0,right:0,bottom:0,width:0,height:0}:this.rect||{top:100,left:10,right:600,bottom:430,width:590,height:330};};
Element.prototype.removeAttribute=function(name){delete this.attrs[name];};
const baseMatches=Element.prototype.matches;
Element.prototype.matches=function(selector){if(selector==='[hidden]')return this.hidden;return baseMatches.call(this,selector);};
const dispatch=Element.prototype.dispatchEvent;
Element.prototype.dispatchEvent=function(event){if(!event.target)Object.defineProperty(event,'target',{value:this,configurable:true});if(event.type==='submit')this.ownerDocument.dispatchEvent(event);if(event.defaultPrevented)return false;return dispatch.call(this,event);};

// Actual IIFEs and Chat marker helpers run here. Only DOM, clocks, recognition,
// HTTP and HTMLAudioElement are simulated. Chromium exercises native event
// propagation and the full Chat renderer with the same API projection shapes.
export async function speechFixture({items=[speechItem()],privateDraft='',voice=true,widget=false,automatic=false,scopedOnly=false}={}){
  const document=new Document(),window=new EventTarget(),timers=new Map(),local=new Map(),session=new Map(),requests=[],holds=[],failures=new Map(),audio=[],urls=new Set(),recognizers=[];
  document.querySelectorAll=selector=>[...document.body.querySelectorAll(selector),...document.head.querySelectorAll(selector)];
  document.documentElement={lang:'en-US',clientWidth:900,clientHeight:900};
  let elapsed=0,clock=1800000000000,nextTimer=0,sequence=0,present=false,availability='available',callBusy=false,ready=true,autoplayBlocked=false,attemptNo=0,providerCalls=0,preferenceRevision=1,preferenceStyle='preset',preferenceKey='';
  const records=new Map(items.map(item=>[item.id,structuredClone({...item,automatic_allowed:automatic||item.automatic_allowed})])),turns=new Map([101,202].map(id=>[id,ownerCard(id,{state:'owner_claimed',responder:'owner'})])),attempts=new Map(),claimRequests=new Map(),delivered=new Map();
  const schedule=(fn,delay,repeat=false)=>{const id=++nextTimer;timers.set(id,{fn,delay:Math.max(0,delay),due:elapsed+Math.max(0,delay),repeat});return id;};
  const timerApi={setTimeout:(fn,delay=0)=>schedule(fn,delay),setInterval:(fn,delay)=>schedule(fn,delay,true),clearTimeout:id=>timers.delete(id),clearInterval:id=>timers.delete(id)};
  const thread=document.createElement('section');thread.id='chatThread';thread.dataset.view='chat';thread.rect={top:0,left:0,width:900,height:850,right:900,bottom:850};
  const topbar=document.createElement('div');topbar.className='chat-topbar-actions';
  const form=document.createElement('form');form.id='chatForm';const input=document.createElement('textarea');input.id='chatInput';input.value=privateDraft;const send=document.createElement('button');send.id='sendChatButton';form.append(input,send);
  const button=document.createElement('button');button.id='chatVoiceButtonLegacyDormant';const status=document.createElement('span');status.id='chatVoiceStatus';document.body.append(topbar,thread,form,button,status);
  const create=document.createElement.bind(document);document.createElement=tag=>{const element=create(tag);if(tag==='audio'){
    element.paused=true;element.ended=false;element.playCalls=0;element.stops=0;
    element.play=async()=>{element.playCalls++;if(autoplayBlocked){const error=new Error('Blocked');error.name='NotAllowedError';throw error;}element.paused=false;const event=new Event('play');Object.defineProperty(event,'target',{value:element});document.dispatchEvent(event);element.onplaying?.();};
    element.pause=()=>{element.paused=true;element.stops++;};element.finish=()=>{element.paused=true;element.ended=true;element.onended?.();};element.load=()=>{};audio.push(element);
  }return element;};
  const json=(data,status=200)=>({ok:status>=200&&status<300,status,headers:new Headers({'Content-Type':'application/json'}),json:async()=>structuredClone(data)});
  const normal=item=>{
    const attempt=[...attempts.values()].filter(row=>row.itemId===item.id).at(-1),playback=attempt?.playback||'none',provider=attempt?.state||(attempt?.ready?'ready':'reserved');
    let state=attempt?({reserved:'pending',sending:'pending',outcome_unverified:'outcome_unverified',rejected:'blocked',blocked:'blocked'}[provider]||'available'):item.state;
    state=({granted:'granted',playing:'playing',ended:'client_finished',interrupted:'interrupted',blocked:'blocked'})[playback]||state;
    const retry=Boolean(attempt&&['outcome_unverified','rejected'].includes(provider));
    return {...structuredClone(item),state,playback_state:playback,can_play:item.can_play&&!['pending','granted','playing','outcome_unverified','expired'].includes(state)&&!retry,can_retry:retry,
      automatic_allowed:item.automatic_allowed&&!attempt,latest_attempt_id:attempt?.id||null,latest_attempt_state:attempt?provider:null,latest_attempt_reason:attempt?'':null,server_now_ms:clock};
  };
  const preference=()=>({contract,style:preferenceStyle,preset_key:preferenceKey,revision:preferenceRevision,ready,reason:'',presets:[{preset_key:'a'.repeat(64),label:'Clear public reading voice',style:'unclassified'},{preset_key:'b'.repeat(64),label:'Public neutral reading voice',style:'neutral'}]});
  async function transport(url,options={}){
    const parsed=new URL(url,'https://fixture.test'),body=options.method==='GET'?Object.fromEntries(parsed.searchParams):JSON.parse(options.body||'{}');
    for(const key of ['conversation_id','public_conversation_id','chat_conversation_id','chat_agent_id','item_id','revision','turn_id','attempt_id','retry_of'])if(body[key]!==undefined)body[key]=Number(body[key]);
    requests.push({path:parsed.pathname,method:options.method||'GET',...structuredClone(body)});
    if(failures.has(body.action)){const error=failures.get(body.action);return json({ok:false,contract,code:error.code||'PROFILE_SPEECH_UNAVAILABLE'},error.status||403);}
    let result={ok:true},binary=false;
    if(body.action==='doorbell_list')Object.assign(result,{availability,turns:[...turns.values()].map(card=>({...structuredClone(card),server_now_ms:clock})),server_now_ms:clock});
    else if(body.action==='doorbell_presence'){if(body.presence_sequence>sequence){sequence=body.presence_sequence;present=body.visible;if(body.availability)availability=body.availability;}Object.assign(result,{accepted:true,availability,reachable:present,server_now_ms:clock});}
    else if(body.action==='doorbell_delivery'){const card=turns.get(body.turn_id);Object.assign(result,{turn:{...structuredClone(card),chat_agent_id:7},chat_message_id:body.turn_id+600,message_id:body.turn_id+600,delivery_id:'d'.repeat(32),chat_conversation_id:body.chat_conversation_id});}
    else if(body.action==='doorbell_reply'){const card=turns.get(body.turn_id);card.revision++;card.state='replied';Object.assign(result,{turn_id:body.turn_id,conversation_id:card.conversation_id,request_id:body.request_id,turn:{...structuredClone(card),server_now_ms:clock}});}
    else if(body.action==='owner_state')Object.assign(result,{contract,items:[...records.values()].filter(item=>!scopedOnly||delivered.has(item.id)||body.public_conversation_id===item.public_conversation_id).map(normal),ready,reason:'',server_now_ms:clock});
    else if(body.action==='owner_delivery'){
      const item=records.get(body.item_id);delivered.set(item.id,body);Object.assign(item,{chat_conversation_id:body.chat_conversation_id,chat_agent_id:body.chat_agent_id,chat_message_id:item.id+900,delivery_id:'e'.repeat(32)});Object.assign(result,{contract,item:normal(item),chat_conversation_id:body.chat_conversation_id,chat_agent_id:body.chat_agent_id,chat_message_id:item.chat_message_id,delivery_id:item.delivery_id,server_now_ms:clock});
    }else if(body.action==='owner_claim'||body.action==='owner_retry'){
      const item=records.get(body.item_id);let attempt=claimRequests.get(body.request_id);
      if(!attempt){const cached=body.action==='owner_retry'?null:[...attempts.values()].find(a=>a.itemId===item.id&&a.ready);attempt={id:++attemptNo,itemId:item.id,ticket:'t'.repeat(64),ready:Boolean(cached),playUntil:0};attempts.set(attempt.id,attempt);claimRequests.set(body.request_id,attempt);}
      Object.assign(result,{contract,item:normal(item),attempt_id:attempt.id,state:attempt.ready?'ready':'reserved',ticket:attempt.ticket,voice_revision:item.voice_revision,server_now_ms:clock,lease_until_ms:clock+90000,duplicate:false,retry_may_charge:body.action==='owner_retry'});
    }else if(body.action==='owner_audio'){
      const attempt=attempts.get(body.attempt_id);if(!attempt)throw new Error('Unknown exact fixture attempt.');if(!attempt.ready){providerCalls++;attempt.ready=true;}binary=true;
    }else if(body.action==='owner_play'){
      const attempt=attempts.get(body.attempt_id);if(attempt.playRequest&&attempt.playRequest!==body.request_id)return json({ok:false,contract,code:'PROFILE_SPEECH_ALREADY_PLAYED'},409);attempt.playRequest=body.request_id;attempt.playback='granted';
      Object.assign(result,{contract,attempt_id:body.attempt_id,play_token:'f'.repeat(32),server_now_ms:clock,lease_until_ms:clock+30000,granted:true});
    }else if(body.action==='owner_report'){
      const attempt=attempts.get(body.attempt_id);if(body.status==='playing'&&body.report_sequence>(attempt.sequence||0)){attempt.sequence=body.report_sequence;attempt.playUntil=clock+30000;}attempt.playback=body.status;Object.assign(result,{contract,attempt_id:attempt.id,state:body.status,client_reported:true,report_sequence:attempt.sequence||0,server_now_ms:clock,lease_until_ms:attempt.playUntil||clock+30000});
    }else if(body.action==='visitor_state'||body.action==='visitor_preference'){
      if(body.action==='visitor_preference'){preferenceStyle=body.style;preferenceKey=body.preset_key;preferenceRevision++;}Object.assign(result,preference());
    }else if(parsed.pathname==='/api/profile-agent.php'&&body.action==='state')Object.assign(result,{agent:{greeting:''},messages:[],conversation:null});
    else if(body.action==='send')Object.assign(result,{conversation_id:42,assistant_message_id:9000,answer:'PRIVATE_CHAT_REPLY'});
    else throw new Error('Unexpected fixture action '+body.action+' on '+parsed.pathname);
    const response=()=>binary?new Response(new Uint8Array([73,68,51,1,2,3]),{status:200,headers:{'Content-Type':'audio/mpeg','Content-Length':'6'}}):json(result);
    const index=holds.findIndex(held=>held.action===body.action);
    if(index>=0){const held=holds.splice(index,1)[0];return new Promise((resolve,reject)=>{held.work={body,result,resolve:(override={},status=200)=>{if(body.action==='owner_claim'&&override.state)attempts.get(result.attempt_id).state=override.state;resolve(binary&&!Object.keys(override).length?response():json({...result,...override},status));},reject};});}
    return response();
  }
  class Clock extends Date{static now(){return clock;}}
  class ObjectURL extends URL{static createObjectURL(){const value='blob:fixture-'+randomId++;urls.add(value);return value;}static revokeObjectURL(value){urls.delete(value);}}let randomId=0;
  class Recognition{constructor(){recognizers.push(this);}start(){queueMicrotask(()=>this.onstart?.());}stop(){queueMicrotask(()=>this.onend?.());}abort(){queueMicrotask(()=>this.onend?.());}}
  const location={href:'https://fixture.test/chat.php',origin:'https://fixture.test',pathname:'/chat.php',search:''};
  Object.assign(window,timerApi,{document,location,fetch:transport,innerWidth:900,innerHeight:900,requestAnimationFrame:fn=>schedule(fn,0),getComputedStyle:()=>({display:'block',visibility:'visible'}),confirm:()=>true,
    STONEFELLOW_CHAT:{userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture'},STONEFELLOW_CHAT_VOICE_BOOT:{button,intro:null,legacyDormant:true},SpeechRecognition:Recognition,
    STONEFELLOW_AGENT_IDENTITY_V236:{agentVoiceEnabled:true},speechSynthesis:{cancel(){},speak(){}},
    VP3_PROFILE_DOORBELL_PA3:{endpoint:'/api/chat-notifications-brain-v240.php',ownerEndpoint:'/api/profile-agent.php',csrf:'fixture',userId:1},
    VP3_PROFILE_SPEECH_PA4A:{endpoint:'/api/profile-speech-pa4a.php',csrf:'fixture',userId:1},VP3ProfileCallsPa3c:{speechBusy:()=>callBusy,attachOwnerThread(){}}});
  const globals={window,document,location,navigator:{onLine:true,userActivation:{isActive:true}},localStorage:storage(local),sessionStorage:storage(session),crypto:webcrypto,Uint8Array,performance:{now:()=>elapsed},Date:Clock,URL:ObjectURL,URLSearchParams,JSON,Event,CustomEvent:FixtureCustomEvent,AbortController,Response,Headers,fetch:transport,queueMicrotask,...timerApi};
  const context=vm.createContext(globals),chat=read('chat.js');
  const helpers=chat.slice(chat.indexOf('  function bindStoredMessagePa3('),chat.indexOf('  function formatPlaybackTime(')),exports=chat.slice(chat.indexOf('  const canonicalChatContinuity = {'),chat.indexOf('  function speakIntroV101('));
  vm.runInContext('let conversationId=42,contextReady=true,restoringConversation=0,initialRestorePending=false,busy=false,voiceMode=false;const thread=document.getElementById("chatThread"),cfg={agentId:7};function addMessage(role,text){const n=document.createElement("article");n.className="message assistant";const b=document.createElement("div");b.className="message-body";const t=document.createElement("p");t.className="message-text";t.textContent=text;b.append(t);n.append(b);thread.append(n);return n;}'+helpers+exports+';window.__binding=(cid,aid)=>{conversationId=cid;cfg.agentId=aid;};',context);
  vm.runInContext(read('voice-lease-v122.js'),context);
  if(voice)vm.runInContext(read('chat-voice.js'),context,{filename:'chat-voice.js'});
  form.addEventListener('submit',event=>{if(event.defaultPrevented)return;event.preventDefault();void window.fetch('/api/chat.php',{method:'POST',body:JSON.stringify({action:'send',conversation_id:42,message:input.value,input_mode:'text'})});});
  vm.runInContext(read('profile-visitor-doorbell-pa3.js'),context,{filename:'profile-visitor-doorbell-pa3.js'});await settle();
  vm.runInContext(read('profile-speech-pa4a.js'),context,{filename:'profile-speech-pa4a.js'});await settle();
  const fixture={window,document,thread,input,form,records,turns,requests,audio,recognizers,failures,urls,local,context,
    get providerCalls(){return providerCalls;},get clock(){return clock;},get elapsed(){return elapsed;},card:id=>thread.querySelector('[data-profile-doorbell-turn="'+id+'"]'),speech:id=>thread.querySelector('[data-speech-item="'+id+'"]'),
    hold(action){const held={action,work:null};holds.push(held);return held;},setAutoplayBlocked:value=>autoplayBlocked=value,setReady:value=>ready=value,
    async refresh(){await window.VP3ProfileSpeechPa4a.refresh();await settle();},async settle(){await settle();},
    async advance(ms){for(let remaining=ms;remaining>0;){const step=Math.min(100,remaining);clock+=step;elapsed+=step;remaining-=step;for(const[id,timer]of [...timers])if(timer.due<=elapsed&&timers.has(id)){if(timer.repeat)timer.due=elapsed+Math.max(timer.delay,1);else timers.delete(id);timer.fn();}await settle();}},
    async arm(id=101){this.card(id).querySelector('[data-speech-action="draft"]').click();await settle();},
    async recognize(text,{final=true,confidence=.98}={}){const recognizer=recognizers.at(-1);if(!recognizer?.onresult)throw new Error('No active canonical recognition.');const result=[{transcript:text,confidence}];result.isFinal=final;recognizer.onresult({resultIndex:0,results:[result]});await settle();},
    async type(text,event='beforeinput'){input.dispatchEvent(new Event(event,{bubbles:true}));input.value=text;input.dispatchEvent(new Event('input',{bubbles:true}));await settle();},
    async call(value){callBusy=value;window.dispatchEvent(new FixtureCustomEvent('vp3:profile-call-state',{detail:{busy:value}}));await settle();},
    async changeContext(cid=55,aid=8){window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.__binding(cid,aid);thread.replaceChildren();window.dispatchEvent(new Event('vp3:chat-context-ready'));await settle();},
    async hidden(value=true){document.hidden=value;document.visibilityState=value?'hidden':'visible';document.dispatchEvent(new Event('visibilitychange'));await settle();},
    async stop(){window.dispatchEvent(new Event('pagehide'));await settle();},
    async mountWidget(){
      const shell=document.createElement('section');shell.id='profileAgentShell';const publicThread=document.createElement('div');publicThread.dataset.profileAgentThread='';const publicForm=document.createElement('form'),text=document.createElement('textarea'),submit=document.createElement('button');submit.type='submit';publicForm.append(text,submit);const status=document.createElement('div');status.dataset.profileAgentStatus='';shell.append(publicThread,publicForm,status);document.body.append(shell);
      window.STONEFELLOW_PROFILE_AGENT={username:'owner',endpoint:'/api/profile-agent.php',speechEndpoint:'/api/profile-speech-pa4a.php',profileToken:'PROFILE_TOKEN',conversationNamespace:'c'.repeat(64)};
      vm.runInContext(read('profile-agent.js'),context,{filename:'profile-agent.js'});await settle();return shell;
    }
  };
  if(widget)await fixture.mountWidget();return fixture;
}
