import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
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

let trackStops=0;
const track={
  kind:'audio',readyState:'live',label:'Processed Microphone',contentHint:'',
  getSettings(){return {echoCancellation:true,noiseSuppression:true,autoGainControl:true,voiceIsolation:true};},
  getConstraints(){return {echoCancellation:true,noiseSuppression:true,autoGainControl:true,voiceIsolation:true};},
  addEventListener(){},stop(){trackStops+=1;this.readyState='ended';}
};
const stream={getAudioTracks:()=>[track],getTracks:()=>[track]};
let gumCalls=0;
let gumConstraints=null;
const mediaDevices={
  getSupportedConstraints:()=>({echoCancellation:true,noiseSuppression:true,autoGainControl:true,voiceIsolation:true,channelCount:true}),
  async getUserMedia(constraints){gumCalls+=1;gumConstraints=constraints;return stream;}
};

const recognizers=[];
const recognitionTrackArgs=[];
class Recognition{
  constructor(){recognizers.push(this);this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;this.onspeechstart=null;}
  start(audioTrack){recognitionTrackArgs.push(audioTrack??null);queueMicrotask(()=>this.onstart?.());}
  stop(){queueMicrotask(()=>this.onend?.());}
  abort(){queueMicrotask(()=>this.onend?.());}
}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Event{constructor(type){this.type=type;}}

const store=new Map();
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,val)=>store.set(key,String(val))};
const document={body:new FakeElement('body'),documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new FakeElement(),addEventListener(){},removeEventListener(){}};
let premiumStops=0;
const Premium=()=>({
  warm:async()=>true,
  speak:async(text,callbacks={})=>{callbacks.onStart?.();return true;},
  stop(){premiumStops+=1;}
});
const fetchCalls=[];
const nativeFetch=async(inputArg,init={})=>{
  const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  fetchCalls.push({url,payload});
  return new Response(JSON.stringify({ok:true,conversation_id:42,user_message_id:1,assistant_message_id:2,answer:'I can hear you clearly.'}),{status:200,headers:{'Content-Type':'application/json'}});
};

const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButtonLegacyDormant,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat'}),snapshot:()=>({surface:'chat'}),setConversationId(){}},StonefellowPremiumVoiceV122:Premium,
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{}
};
const navigator={userActivation:{isActive:true},mediaDevices};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController});

elements.chatForm.requestSubmit=()=>{void window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf'})});};

vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(source.includes("const BUILD='chat-voice-canonical-20260903'"),'v157 keeps processed input as the echo/noise monitor');
assert(chat.includes("$voiceAssetBuild = 'chat-voice-canonical-20260903'")&&chat.includes("$voiceCacheBuild = 'chat-voice-canonical-20260903-failover1'"),'Chat cache-busts the complete v157 voice repair');
assert(!source.includes('current.start(track)')&&source.includes('current.start()'),'SpeechRecognition uses the stable no-argument browser path');
assert(source.includes("echoCancellation:'all'")&&source.includes('audio.echoCancellation=true')&&source.includes('noiseSuppression:true')&&source.includes('autoGainControl:true'),'processed input requests all-system-audio cancellation with a compatibility fallback, noise suppression, and AGC');
assert(source.includes('voiceIsolation')&&source.includes("track.contentHint='speech-recognition'"),'processed input uses voice isolation when supported and marks speech-recognition content');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'processed mic voice flow retains the natural pause window');

elements.chatVoiceButtonLegacyDormant.click();
await sleep(12);
assert(gumCalls===1,'LISTEN acquires one managed microphone stream');
assert(gumConstraints?.audio?.echoCancellation==='all','managed mic requests all-system-audio echo cancellation');
assert(window.STONEFELLOW_CHAT_VOICE.echoCancellationMode==='all','runtime records all-system-audio echo cancellation mode');
assert(source.includes('MIC_ECHO_MODE_FALLBACK'),'runtime includes a compatibility fallback for browsers without all-mode AEC');
assert(gumConstraints?.audio?.noiseSuppression===true,'managed mic requests noise suppression');
assert(gumConstraints?.audio?.autoGainControl===true,'managed mic requests automatic gain control');
assert(gumConstraints?.audio?.voiceIsolation===true,'managed mic requests voice isolation when Chrome reports support');
assert(track.contentHint==='speech-recognition','managed microphone track is optimized for speech recognition');
assert(recognizers.length===1&&recognitionTrackArgs[0]===null,'normal LISTEN starts synchronously without the dead start(track) path');
assert(window.STONEFELLOW_CHAT_VOICE.processedTrackStarts===0,'runtime never reports an obsolete processed-track recognizer start');
assert(window.STONEFELLOW_CHAT_VOICE.defaultMicStarts===1,'browser SpeechRecognition owns capture while the processed stream monitors near-field speech');

const normal=recognizers[0];
normal.onresult({resultIndex:0,results:[{0:{transcript:'can you hear me',confidence:.96},isFinal:true}]});
await sleep(120);
assert(fetchCalls.length===0,'processed speech is not submitted before the natural pause window closes');
await sleep(1780);
assert(fetchCalls.length===1&&fetchCalls[0].payload.input_mode==='voice','voice transcript reaches the normal Chat API after the pause window');
assert(document.body.dataset.stonefellowAgentState==='speaking','premium response reaches speaking state');
assert(recognizers.length>=3,'response creates a dedicated barge recognizer after pause-window continuation');
assert(recognitionTrackArgs.at(-1)===null,'barge uses the stable no-argument SpeechRecognition start');
assert(window.STONEFELLOW_CHAT_VOICE.processedTrackStarts===0,'LISTEN continuation and barge never reintroduce start(track)');
assert(window.STONEFELLOW_CHAT_VOICE.defaultMicStarts>=3,'normal LISTEN and barge both reach native start');

console.log('CHAT_PROCESSED_MIC_V157=PASS');
