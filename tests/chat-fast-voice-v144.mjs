import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
const voiceApi=fs.readFileSync('api/agent-voice-v117.php','utf8');
const premiumSource=fs.readFileSync('premium-voice-v117.js','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

class FakeClassList{constructor(){this.values=new Set();}toggle(name,on){if(on)this.values.add(name);else this.values.delete(name);}}
class FakeElement{
  constructor(id=''){this.id=id;this.value='';this.hidden=false;this.dataset={};this.classList=new FakeClassList();this.listeners={};this.attributes={};this.children=[];this.style={};this.textContent='';}
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
const recognizers=[];
class Recognition{constructor(){recognizers.push(this);this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;this.onspeechstart=null;}start(){queueMicrotask(()=>this.onstart?.());}stop(){queueMicrotask(()=>this.onend?.());}abort(){queueMicrotask(()=>this.onend?.());}}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Event{constructor(type){this.type=type;}}
const store=new Map();
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,val)=>store.set(key,String(val))};
const document={body:new FakeElement('body'),documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new FakeElement(),addEventListener(){},removeEventListener(){}};

let premiumReady=true;
let premiumStartedBeforeDone=false;
let streamDoneEmitted=false;
let systemSpeakCalls=0;
let premiumStops=0;
let premiumPushes=[];
let premiumCallbacks=null;
function makePremium(){return {
  warm:async()=>premiumReady,
  createStream(callbacks={}){
    premiumCallbacks=callbacks;let started=false;let resolveStarted,rejectStarted;
    const startedPromise=new Promise((resolve,reject)=>{resolveStarted=resolve;rejectStarted=reject;});
    return {started:startedPromise,push(text){premiumPushes.push(String(text));if(!started){started=true;premiumStartedBeforeDone=!streamDoneEmitted;callbacks.onStart?.();resolveStarted(true);}},end(){callbacks.onEnd?.();},stop(){premiumStops+=1;rejectStarted?.(new Error('stopped'));}};
  },
  speak:async(text,callbacks={})=>{callbacks.onStart?.();return true;},
  stop(){premiumStops+=1;}
};}

const encoder=new TextEncoder();
const fetchCalls=[];
const nativeFetch=async(inputArg,init={})=>{
  const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  fetchCalls.push({url,payload});
  if(url.includes('chat-stream-v121.php')){
    streamDoneEmitted=false;
    const body=new ReadableStream({start(controller){
      controller.enqueue(encoder.encode(JSON.stringify({type:'start',conversation_id:42,user_message_id:71,assistant_message_id:72})+'\n'));
      controller.enqueue(encoder.encode(JSON.stringify({type:'delta',delta:'I can hear you. '})+'\n'));
      setTimeout(()=>{controller.enqueue(encoder.encode(JSON.stringify({type:'delta',delta:'This second sentence arrives later.'})+'\n'));streamDoneEmitted=true;controller.enqueue(encoder.encode(JSON.stringify({type:'done',data:{ok:true,conversation_id:42,user_message_id:71,assistant_message_id:72,answer:'I can hear you. This second sentence arrives later.',input_mode:'voice'}})+'\n'));controller.close();},30);
    }});
    return new Response(body,{status:200,headers:{'Content-Type':'application/x-ndjson'}});
  }
  return new Response(JSON.stringify({ok:true,conversation_id:42,user_message_id:71,assistant_message_id:72,answer:'normal fallback answer'}),{status:200,headers:{'Content-Type':'application/json'}});
};

const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButtonLegacyDormant,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat'}),snapshot:()=>({surface:'chat'}),setConversationId(){}},StonefellowPremiumVoiceV122:makePremium,
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){systemSpeakCalls+=1;}},SpeechSynthesisUtterance:class{constructor(text){this.text=text;this.onstart=null;this.onend=null;this.onerror=null;}}
};
const navigator={userActivation:{isActive:true}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder});
elements.chatForm.requestSubmit=()=>{void window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf'})});};

vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(source.includes("const BUILD='chat-voice-canonical-20260903'"),'fast voice behavior remains active inside the canonical lifecycle');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'fast voice path honors the v148 natural pause window');
assert(source.includes("new URL('chat-stream-v121.php',chatUrl)"),'voice turn derives the existing NDJSON Chat stream endpoint');
assert(source.includes('premium.createStream({'),'voice turn feeds incremental AI text into the premium stream');
assert(source.includes('FAST_PREMIUM_PRESTART_ERROR')&&source.includes('FAST_PREMIUM_FULL_RETRY'),'v146 recovers pre-audio premium failure through an ElevenLabs-only retry');
assert(source.includes("startSystemVoiceFallback(message,epoch,'elevenlabs-not-configured')"),'system voice remains reserved for ElevenLabs-not-configured state');
assert(chat.includes("$voiceAssetBuild = 'chat-voice-canonical-20260903'")&&chat.includes("$voiceCacheBuild = 'chat-voice-canonical-20260903-failover1'"),'Chat cache-busts the canonical lifecycle runtime');
assert(chat.includes('data-chat-streaming="enabled"')&&chat.includes('data-chat-processed-input="enabled"'),'Chat preserves fast voice streaming and processed input monitoring');
assert(voiceApi.includes("/stream?output_format=")&&voiceApi.includes('CURLOPT_WRITEFUNCTION'),'server proxies ElevenLabs streaming bytes rather than buffering a complete MP3');
assert(voiceApi.includes("'eleven_flash_v2_5'"),'fast ElevenLabs Flash v2.5 model remains supported/defaulted');
assert(premiumSource.includes('FIRST_CHUNK_LIMIT = 180')&&premiumSource.includes('prefetchedTickets'),'browser retains short first TTS chunk and next-ticket prefetch');

elements.chatVoiceButtonLegacyDormant.click();
await sleep(8);
const normal=recognizers[0];
normal.onresult({resultIndex:0,results:[{0:{transcript:'are you there'},isFinal:true}]});
await sleep(120);
assert(!fetchCalls.some(call=>call.url.includes('chat-stream-v121.php')),'fast voice does not start while the user may only be pausing');
await sleep(1780);
assert(fetchCalls.some(call=>call.url.includes('chat-stream-v121.php')),'ready ElevenLabs uses the streaming Chat response transport after the quiet-window');
assert(premiumPushes[0]==='I can hear you. ','first streamed AI sentence is pushed directly into ElevenLabs');
assert(premiumStartedBeforeDone===true,'ElevenLabs begins before the AI done event/full answer');
assert(systemSpeakCalls===0,'system/browser TTS does not play when ElevenLabs is ready');
await sleep(45);
assert(premiumPushes.join('')==='I can hear you. This second sentence arrives later.','all streamed deltas reach the same ElevenLabs response');
assert(window.STONEFELLOW_CHAT_VOICE.fastStreamTurns===1,'runtime records the fast streamed voice turn');
assert(window.STONEFELLOW_CHAT_VOICE.fastStreamDeltas===2,'runtime records both streamed text deltas');
assert(window.STONEFELLOW_CHAT_VOICE.premiumReady===true,'runtime records ElevenLabs as configured/ready');
assert(window.STONEFELLOW_CHAT_VOICE.premiumAudibleStarts===1,'runtime separately records proof of first audible ElevenLabs playback');

premiumCallbacks?.onError?.(new Error('simulated late ElevenLabs playback failure'));
await sleep(5);
assert(systemSpeakCalls===0,'late ElevenLabs playback failure still does not trigger duplicate system voice');

console.log('CHAT_FAST_VOICE_V157=PASS');
