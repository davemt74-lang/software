import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
const bootstrap=fs.readFileSync('includes/bootstrap.php','utf8');
const brain=fs.readFileSync('includes/agent-brain-context-v142.php','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

class FakeClassList{constructor(){this.values=new Set();}toggle(name,on){if(on)this.values.add(name);else this.values.delete(name);}}
class FakeElement{
  constructor(id=''){this.id=id;this.value='';this.hidden=false;this.dataset={};this.classList=new FakeClassList();this.listeners={};this.attributes={};this.children=[];this.style={};this.textContent='';this.scrollTop=0;this.scrollHeight=0;}
  addEventListener(type,fn,options){(this.listeners[type]??=[]).push({fn,capture:options===true||options?.capture});}
  dispatchEvent(event){for(const {fn} of this.listeners[event.type]||[])fn(event);return true;}
  click(){this.dispatchEvent({type:'click',target:this,preventDefault(){},stopImmediatePropagation(){},stopPropagation(){}});}
  setAttribute(name,value){this.attributes[name]=String(value);}
  appendChild(child){this.children.push(child);return child;}
  querySelector(){return null;}
  querySelectorAll(){return [];}
  closest(){return null;}
}
const elements={chatForm:new FakeElement('chatForm'),chatInput:new FakeElement('chatInput'),sendChatButton:new FakeElement('sendChatButton'),chatVoiceStatus:new FakeElement('chatVoiceStatus'),chatVoiceButtonLegacyDormant:new FakeElement('chatVoiceButtonLegacyDormant')};
const recognizers=[];let nativeStarts=0;
class Recognition{constructor(){recognizers.push(this);this.onstart=null;this.onspeechstart=null;this.onresult=null;this.onerror=null;this.onend=null;this.continuous=false;this.interimResults=false;this.lang='';}start(){nativeStarts+=1;queueMicrotask(()=>this.onstart?.());}stop(){queueMicrotask(()=>this.onend?.());}abort(){queueMicrotask(()=>this.onend?.());}}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Event{constructor(type){this.type=type;}}
const store=new Map();
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,val)=>store.set(key,String(val))};
const document={body:new FakeElement('body'),documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new FakeElement(),addEventListener(){},removeEventListener(){}};
const nativeFetchCalls=[];const spoken=[];let premiumStops=0;
const nativeFetch=async(inputArg,init={})=>{const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}nativeFetchCalls.push({url,init,payload});const answer=nativeFetchCalls.length===1?'I can hear you. What would you like to work on?':'Got it. I heard your interruption.';return new Response(JSON.stringify({ok:true,conversation_id:42,assistant_message_id:70+nativeFetchCalls.length,answer}),{status:200,headers:{'Content-Type':'application/json'}});};
const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButtonLegacyDormant,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat'}),snapshot:()=>({surface:'chat'}),setConversationId(){}},
  StonefellowPremiumVoiceV122:()=>({stop(){premiumStops+=1;},speak:async(text,options={})=>{spoken.push(String(text));options.onStart?.();return true;}}),
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{}
};
const navigator={userActivation:{isActive:true}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController});
elements.chatForm.requestSubmit=()=>{void window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf'})});};

vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(chat.includes("$controlBuild = 'chat-voice-canonical-20260903'"),'Chat preserves the v142 direct-control architecture marker');
assert(chat.includes('chat-voice.js?v='),'Chat loads the single direct voice owner');
assert(!chat.includes('chat-voice-v140.js?v='),'Chat no longer loads the v140 voice owner');
assert(!chat.includes('chat-barge-v141.js?v='),'Chat no longer loads the v141 Web Audio barge helper');
assert(source.includes('AudioContext')&&source.includes('getFloatTimeDomainData'),'barge uses processed near-field energy only to reject echo/noise');
assert(source.includes('getUserMedia')&&!source.includes('current.start(track)')&&source.includes('current.start()'),'v157 keeps one stable SpeechRecognition capture path plus a processed monitor');
assert(source.includes('new SpeechRecognitionCtor()')&&source.includes('BARGE_SR_STARTED'),'barge still uses native SpeechRecognition');
assert(source.includes("interruptResponse('button')"),'same AI button keeps the guaranteed manual interrupt path');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'v148 keeps a natural pause window before submitting normal speech');
assert(source.includes('START_WATCHDOG_MS=2200')&&source.includes('recognitionStartTimeouts'),'canonical voice recovers if native recognition never reaches onstart');
assert(source.includes('DUPLICATE_WINDOW_MS=2600')&&source.includes('isDuplicateTranscript'),'canonical voice rejects duplicate finals in the short recognition window');
assert(source.includes('function acceptTranscript')&&source.includes('lowConfidenceRejected'),'canonical voice integrates v122 confidence acceptance safeguards');
assert(source.includes('function transcriptWords')&&source.includes('\\p{L}')&&source.includes('\\p{N}'),'canonical transcript safeguards preserve Unicode speech instead of rejecting non-English input');
assert(source.includes("lowConfidenceRejected+=1;updateComposer(pendingFinalTranscript)")&&source.includes("duplicatesRejected+=1;updateComposer(pendingFinalTranscript)"),'rejected continuation audio preserves any already accepted pending phrase in the composer');
assert(source.includes('AgentContext?.conversationId?.()'),'canonical Chat restores the shared conversation identity when returning from an editor');
assert(!/STONEFELLOW_CHAT_VOICE_V\d+|STONEFELLOW_CHAT_CONTINUITY_V\d+/.test(source),'canonical voice exports no versioned Agent Chat ownership aliases');
assert(bootstrap.includes("agent-brain-context-v142.php")&&!bootstrap.includes("agent-brain-context-v123.php"),'bootstrap loads the deployment-safe Agent Brain pathname');
assert(brain.includes('crc32((string)$feature)')&&!brain.includes('crc32($feature)'),'Agent Brain hashing remains string-safe');
assert(window.STONEFELLOW_CHAT_VOICE?.directOwner===true,'direct Agent Chat voice ownership remains');
assert(window.STONEFELLOW_CHAT_VOICE?.speechRecognitionBarge===true,'native recognizer barge ownership remains');

// Default-mic compatibility path remains available when MediaStreamTrack input is unavailable.
elements.chatVoiceButtonLegacyDormant.click();
await sleep(12);
assert(recognizers.length===1&&nativeStarts===1,'LISTEN still starts one native recognizer on compatibility fallback');
assert(window.STONEFELLOW_CHAT_VOICE.starts===1,'normal recognizer onstart is observed');
assert(document.body.dataset.stonefellowAgentState==='listening','blue LISTEN follows real native onstart');
const normal=recognizers[0];
normal.onresult({resultIndex:0,results:[{0:{transcript:'all right are you listening'},isFinal:true}]});
await sleep(120);
assert(nativeFetchCalls.length===0,'final speech does not submit during the natural pause window');
assert(document.body.dataset.stonefellowAgentState==='listening','agent remains in LISTEN during the pause window');
await sleep(1780);
assert(nativeFetchCalls.length===1,'first spoken turn makes one Chat request after the pause window');
assert(nativeFetchCalls[0].url==='/api/chat.php'&&nativeFetchCalls[0].payload.input_mode==='voice','spoken turn uses the normal Chat API as voice');
assert(spoken[0]?.startsWith('I can hear you'),'successful Chat answer starts ElevenLabs output');
assert(document.body.dataset.stonefellowAgentState==='speaking','response enters speaking state');
assert(recognizers.length>=3,'pause continuation plus speaking response create native recognizers without losing barge-in');
assert(window.STONEFELLOW_CHAT_VOICE.bargeStarts===1,'barge recognizer reaches onstart');

const barge=recognizers.at(-1);
barge.onspeechstart?.();
barge.onresult({resultIndex:0,results:[{0:{transcript:'stop I need to change that'},isFinal:false}]});
await sleep(5);
assert(premiumStops>=1,'non-echo barge speech immediately stops Stonefellow audio');
assert(window.STONEFELLOW_CHAT_VOICE.interruptions===1,'barge speech records one real interruption');
barge.onresult({resultIndex:0,results:[{0:{transcript:'stop I need to change that'},isFinal:true}]});
await sleep(35);
assert(nativeFetchCalls.length===2,'completed interruption becomes the second Chat turn');
assert(nativeFetchCalls[1].payload.message==='stop I need to change that','second turn contains the interruption phrase');
assert(window.STONEFELLOW_CHAT_VOICE.bargeSubmits===1,'barge capture is finalized exactly once');

console.log('CHAT_VOICE_V157_COMPAT=PASS');
