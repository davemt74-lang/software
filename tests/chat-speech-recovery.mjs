import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync('chat-voice.js','utf8'),lease=fs.readFileSync('voice-lease-v122.js','utf8');
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
function fixture({pendingMonitor=false,silentRecognition=false}={}){
 let now=Date.UTC(2026,9,31),next=1,starts=0,maxActive=0,fetches=0;
 const timers=new Map(),recognizers=[],tracks=[],monitorRequests=[],listeners=new Map(),store=new Map();
 const later=(fn,ms=0,interval=0)=>{const id=next++;timers.set(id,{fn,at:now+Math.max(0,ms),interval});return id;};
 const clear=id=>timers.delete(id);
 class ClockDate extends Date {constructor(...args){super(...(args.length?args:[now]));}static now(){return now;}}
 class Element {constructor(){this.value='';this.dataset={};this.attrs={};this.style={};this.listeners={};this.hidden=false;this.classList={toggle(){}};}
 addEventListener(t,f){(this.listeners[t]??=[]).push(f);}setAttribute(k,v){this.attrs[k]=String(v);}querySelector(){return null;}querySelectorAll(){return [];}closest(){return null;}appendChild(){}dispatchEvent(e){for(const f of this.listeners[e.type]||[])f(e);return true;}}
 const elements=Object.fromEntries(['chatForm','chatInput','sendChatButton','chatVoiceStatus','chatVoiceButton'].map(id=>[id,new Element()]));
 const document={body:new Element(),documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new Element(),addEventListener(){}};
 const emit=(type,detail={})=>{for(const fn of listeners.get(type)||[])fn({type,detail});};
 class Recognition {constructor(){recognizers.push(this);}start(){check(!recognizers.some(r=>r!==this&&r.active),'at most one native recognizer starts');starts++;this.active=true;maxActive=Math.max(maxActive,recognizers.filter(r=>r.active).length);if(!silentRecognition)queueMicrotask(()=>this.onstart?.());}stop(){if(this.holdEnd)return;this.end();}abort(){this.stop();}end(){this.active=false;queueMicrotask(()=>this.onend?.());}fail(error,hold=false){this.holdEnd=hold;this.onerror?.({error});if(!hold)this.end();}}
 class Event {constructor(type,options={}){this.type=type;this.detail=options.detail||{};}}
 const localStorage={getItem:k=>store.get(k)??null,setItem:(k,v)=>store.set(k,String(v)),removeItem:k=>store.delete(k)};
 const navigator={onLine:true,userActivation:{isActive:true},mediaDevices:{getSupportedConstraints:()=>({}),getUserMedia:async()=>{if(pendingMonitor)await new Promise(resolve=>monitorRequests.push(resolve));const track={readyState:'live',stop(){this.readyState='ended';},addEventListener(type,fn){this[type]=fn;}};tracks.push(track);return {getAudioTracks:()=>[track],getTracks:()=>[track]};}}};
 const window={STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php'},STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButton},SpeechRecognition:Recognition,
 fetch:async()=>{fetches++;return new Response('{}');},speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{},
 addEventListener:(t,f)=>{if(!listeners.has(t))listeners.set(t,[]);listeners.get(t).push(f);},removeEventListener(){},dispatchEvent:e=>{emit(e.type,e.detail);return true;},
 setTimeout:(f,ms)=>later(f,ms),clearTimeout:clear,setInterval:(f,ms)=>later(f,ms,ms),clearInterval:clear};
 const globals={window,document,navigator,localStorage,location:{href:'https://vp3.test/chat.php',origin:'https://vp3.test',pathname:'/chat.php'},URL,URLSearchParams,Response,AbortController,CustomEvent:Event,Event,Date:ClockDate,performance:{now:()=>now},console,queueMicrotask,setTimeout:window.setTimeout,clearTimeout:clear,setInterval:window.setInterval,clearInterval:clear};
 Object.assign(window,globals);vm.runInNewContext(lease,globals);vm.runInNewContext(source,globals);
 const settle=async()=>{for(let i=0;i<6;i++)await Promise.resolve();};
 const advance=async ms=>{const target=now+ms;let guard=0;await settle();while(true){const entry=[...timers].filter(([,t])=>t.at<=target).sort((a,b)=>a[1].at-b[1].at)[0];if(!entry)break;if(++guard>10000)throw Error('timer loop');const [id,t]=entry;now=t.at;timers.delete(id);if(t.interval)timers.set(id,{...t,at:now+t.interval});t.fn();await settle();}now=target;await settle();};
 return {window,document,elements,navigator,recognizers,tracks,monitorRequests,emit,advance,settle,get starts(){return starts;},get fetches(){return fetches;},get maxActive(){return maxActive;},current:()=>recognizers.findLast(r=>r.active)};
}
{
 const f=fixture({pendingMonitor:true});f.window.VP3ConversationControls.listen();
 check(f.starts===1,'main recognizer starts in the enable call while the optional monitor is still pending');
 await f.advance(15000);check(f.document.body.dataset.stonefellowAgentState==='listening'&&f.starts===1,'an unresolved monitor cannot stall or restart healthy native listening');
 f.window.VP3ConversationControls.stop();f.monitorRequests[0]();await f.settle();
 check(f.tracks.length===1&&f.tracks[0].readyState==='ended'&&f.starts===1,'late monitor acquisition after Stop releases its stream without restarting recognition');
}
{
 const f=fixture({pendingMonitor:true,silentRecognition:true});f.window.VP3ConversationControls.listen();await f.advance(35000);
 check(f.starts===3&&!f.window.VP3ConversationControls.isEnabled(),'native startup watchdog remains bounded even when the optional monitor never settles');
 f.monitorRequests.forEach(resolve=>resolve());await f.settle();check(f.tracks.every(t=>t.readyState==='ended'),'watchdog shutdown fences all pending monitor streams');
}
{
 const f=fixture({pendingMonitor:true});f.window.VP3ConversationControls.listen();await f.settle();
 f.emit('stonefellow:agent-proactive-speech',{state:'start',text:'The agent is answering.'});await f.advance(0);
 check(f.window.STONEFELLOW_CHAT_VOICE.bargeStarts===0,'handoff waits for an already pending device acquisition');
 f.monitorRequests[0]();await f.settle();check(f.window.STONEFELLOW_CHAT_VOICE.bargeStarts===1,'barge starts after existing acquisition settles without overlapping native capture');
 f.window.VP3ConversationControls.stop();f.monitorRequests.forEach(resolve=>resolve());await f.settle();check(f.tracks.every(t=>t.readyState==='ended'),'barge Stop fences the late optional monitor');
}
{
 const f=fixture({pendingMonitor:true});f.window.VP3ConversationControls.listen();await f.settle();
 f.emit('stonefellow:agent-proactive-speech',{state:'start',text:'An answer.'});await f.advance(11000);
 check(!f.window.VP3ConversationControls.isEnabled()&&f.starts===1,'a stalled existing monitor handoff stops with a bounded diagnostic instead of hanging or racing the device');
 f.monitorRequests[0]();await f.settle();check(f.tracks.every(t=>t.readyState==='ended'),'timed-out handoff closes late monitor streams');
}
{
 const f=fixture();check(f.starts===0,'saved preferences and page load do not open capture');f.window.VP3ConversationControls.listen();await f.settle();
 for(let n=0;n<7;n++){
  const r=f.current();assert.ok(r);r.fail(n%2?'network':'aborted');await f.settle();const delay=f.window.STONEFELLOW_CHAT_VOICE.speechRetryDelayMs;
  check(f.window.VP3ConversationControls.isEnabled(),'transient failure '+(n+1)+' retains the explicit listening request');
  check(delay>0&&delay<=30000&&f.document.body.dataset.stonefellowAgentState==='reconnecting','failure '+(n+1)+' is rate-limited and never displayed as listening');
  check(f.tracks.every(t=>t.readyState==='ended'),'monitor capture is released while speech is disconnected');
  const before=f.starts;await f.advance(delay-1);check(f.starts===before,'onend cannot bypass reconnect backoff');await f.advance(1);check(f.starts===before+1,'a fresh recognizer reconnects after the backoff');
 }
 check(f.window.STONEFELLOW_CHAT_VOICE.lastError===''&&f.document.body.dataset.stonefellowAgentState==='listening','actual native onstart clears the stale connection error');
 check(f.fetches===0&&f.maxActive===1,'recognizer recovery never submits or replays a Chat request');f.window.VP3ConversationControls.stop();
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.current().fail('aborted',true);await f.advance(1000);check(f.starts===1,'a delayed native end never permits a second recognizer');f.current().holdEnd=false;f.current().end();await f.advance(500);check(f.starts===2,'recognition resumes after the old native session actually ends');f.window.VP3ConversationControls.stop();
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.navigator.onLine=false;f.emit('offline');await f.settle();const before=f.starts;
 await f.advance(29000);check(f.starts===before&&f.tracks.every(t=>t.readyState==='ended'),'offline backoff suspends rapid retries and releases microphone tracks');
 f.navigator.onLine=true;f.emit('online');await f.advance(120);check(f.starts===before+1,'network return expedites a service probe without a click or reload');
 f.current().fail('network');await f.settle();f.window.VP3ConversationControls.stop();f.emit('online');await f.advance(60000);check(f.starts===before+1&&!f.window.VP3ConversationControls.isEnabled(),'Stop fences pending retries and later online events');
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.emit('stonefellow:agent-proactive-speech',{state:'start',text:'The agent is finishing its answer.'});await f.advance(0);
 check(f.window.STONEFELLOW_CHAT_VOICE.bargeStarts===1,'speech output arms the actual barge recognizer');
 for(let n=0;n<4;n++){f.current().fail('network');await f.settle();check(f.window.VP3ConversationControls.isEnabled()&&f.document.body.dataset.stonefellowAgentState==='speaking','transient barge failure does not cancel the active spoken answer');await f.advance(f.window.STONEFELLOW_CHAT_VOICE.speechRetryDelayMs);}
 f.emit('stonefellow:agent-proactive-speech',{state:'end'});await f.advance(400);check(f.document.body.dataset.stonefellowAgentState==='listening','normal listening resumes after speech and barge reconnection');f.window.VP3ConversationControls.stop();
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.navigator.onLine=false;f.emit('offline');await f.advance(30000);check(f.starts===2,'an unreliable offline hint still permits a slow service probe');f.window.VP3ConversationControls.stop();
}
{
 const f=fixture();f.window.VP3ConversationControls.responseWindow();await f.settle();f.current().fail('network');await f.settle();f.navigator.onLine=false;f.emit('offline');await f.advance(16000);check(!f.window.VP3ConversationControls.isEnabled(),'transient reconnect never extends the temporary reply-listening window');const before=f.starts;f.navigator.onLine=true;f.emit('online');await f.advance(60000);check(f.starts===before,'expired reply window cannot reopen capture on network return');
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.tracks.at(-1).readyState='ended';f.tracks.at(-1).ended();await f.advance(60000);check(!f.window.VP3ConversationControls.isEnabled()&&f.starts===1,'browser or device ending microphone capture cancels reconnect intent');
}
for(const error of ['not-allowed','service-not-allowed','audio-capture']){
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.current().fail(error);await f.advance(60000);check(!f.window.VP3ConversationControls.isEnabled()&&f.starts===1,'hard '+error+' failure stops capture without an automatic permission retry');
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.current().fail('network');await f.settle();f.emit('pagehide');f.emit('online');await f.advance(60000);check(f.starts===1&&!f.window.VP3ConversationControls.isEnabled(),'page exit prevents background reconnect or bfcache microphone restart');
}
{
 const f=fixture();f.window.VP3ConversationControls.listen();await f.settle();f.current().fail('aborted');await f.settle();f.emit('stonefellow:voice-lease-lost');await f.advance(60000);check(f.starts===1&&!f.window.VP3ConversationControls.isEnabled(),'capture-lease loss cancels retry intent without taking over another tab');
}
console.log(`CHAT_SPEECH_RECOVERY=PASS (${checks} cases; actual controller and capture lease, deterministic browser speech/network fixtures)`);
