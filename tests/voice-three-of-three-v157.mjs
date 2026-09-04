import fs from 'node:fs';
import vm from 'node:vm';

const premiumSource=fs.readFileSync('premium-voice-v117.js','utf8');
const chatSource=fs.readFileSync('chat-voice.js','utf8');
const controllerSource=fs.readFileSync('conversation-voice-v122.js','utf8');
const voiceApi=fs.readFileSync('api/agent-voice-v117.php','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

assert(!voiceApi.includes("curl_init('https://api.elevenlabs.io/v1/voices/")&&voiceApi.includes("'readiness_authority' => 'tts-stream'"),'ElevenLabs warm-up does not require a separate voice-metadata permission');
assert(voiceApi.includes("'verified' => false")&&voiceApi.includes("$ready = $apiKey !== ''"),'warm-up checks local configuration while real TTS owns upstream readiness');
assert(voiceApi.includes('ElevenLabs rejected the saved API key')&&voiceApi.includes('The configured ElevenLabs voice was not found'),'actual TTS credential and voice failures remain diagnostic');
assert(premiumSource.includes('fetchAudio(streamUrl,signal)')&&premiumSource.includes("contentType.startsWith('audio/')"),'premium output verifies an actual audio response before playback');
assert(premiumSource.includes('AUDIO_START_TIMEOUT_MS')&&premiumSource.includes('audioStartTimeouts'),'premium output cannot hang forever before onplaying');
assert(chatSource.includes('armBargeAfterRecognizerRelease()')&&chatSource.includes('if(speaking&&bargeArmPending)'),'barge waits for the normal recognizer to release');
assert(!chatSource.includes('current.start(track)')&&chatSource.includes('current.start()'),'LISTEN and barge avoid the dead start(track) path');
assert(chatSource.includes('semanticEcho')&&chatSource.includes('hasRecentNearFieldSpeech'),'barge combines spoken-output rejection with near-field evidence');
assert(chatSource.includes('TRANSCRIPT_FILLER_REJECTED'),'low-value noise/filler returns directly to LISTEN');
assert(controllerSource.includes('PREMIUM_START_TIMEOUT_MS')&&controllerSource.includes('premiumStartTimeouts'),'Stem and Video have an independent premium-start recovery watchdog');

let audioPlayCalls=0;
class FakeAudio{
  constructor(){this.src='';this.preload='';this.playsInline=false;this.muted=false;this.volume=1;this.onplaying=null;this.onended=null;this.onerror=null;}
  load(){}
  pause(){}
  removeAttribute(name){if(name==='src')this.src='';}
  play(){audioPlayCalls+=1;return Promise.resolve();}
}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
const performanceClock={now:()=>Date.now()};
let fetchMode='stream-error';
const fetch=async(url,init={})=>{
  const method=String(init.method||'GET').toUpperCase();
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  if(method==='POST'&&payload.action==='warm')return new Response(JSON.stringify({ok:true,ready:true,verified:false,readiness_authority:'tts-stream',credential_state:'saved'}),{status:200,headers:{'Content-Type':'application/json'}});
  if(method==='POST'&&payload.action==='ticket'){
    if(fetchMode==='delayed-ticket')await sleep(80);
    return new Response(JSON.stringify({ok:true,stream_url:'https://stonefellow.com/api/agent-voice-v117.php?token=voice'}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(method==='GET')return new Response(JSON.stringify({ok:false,error:'The configured ElevenLabs voice was not found.'}),{status:502,headers:{'Content-Type':'application/json'}});
  throw new Error(`Unexpected request ${method} ${url}`);
};
const premiumWindow={location:{href:'https://stonefellow.com/chat.php'},dispatchEvent(){return true;}};
const premiumSandbox={window:premiumWindow,fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance:performanceClock,console,setTimeout,clearTimeout,queueMicrotask};
Object.assign(premiumWindow,{fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance:performanceClock});
vm.runInNewContext(premiumSource,premiumSandbox,{filename:'premium-voice-v117.js'});

const failedPremium=premiumWindow.StonefellowPremiumVoiceV122({agentEndpoint:'/api/chat.php',csrf:'csrf'});
let premiumError='';
try{await failedPremium.speak('This request must surface the real upstream failure.');}catch(error){premiumError=String(error?.message||error||'');}
assert(premiumError==='The configured ElevenLabs voice was not found.','non-audio stream errors reach the voice controller verbatim');
assert(audioPlayCalls===0,'invalid ElevenLabs responses never reach Audio.play');
assert(premiumWindow.STONEFELLOW_PREMIUM_VOICE_V122.audioFetchFailures===1,'failed audio response is recorded');

fetchMode='delayed-ticket';
const stoppedPremium=premiumWindow.StonefellowPremiumVoiceV122({agentEndpoint:'/api/chat.php',csrf:'csrf'});
const stoppedSession=stoppedPremium.createStream();
stoppedSession.push('Stop before the delayed ticket completes.');
stoppedSession.end();
stoppedSession.stop();
const stoppedStart=await Promise.race([stoppedSession.started,new Promise(resolve=>setTimeout(()=>resolve('hung'),60))]);
assert(stoppedStart===false,'stopping before first audio settles the started promise instead of hanging THINKING');

const fastControllerSource=controllerSource.replace('const PREMIUM_START_TIMEOUT_MS=9000;','const PREMIUM_START_TIMEOUT_MS=25;');
const recognizers=[];
class Recognition{
  constructor(){recognizers.push(this);this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;}
  start(){queueMicrotask(()=>this.onstart?.());}
  stop(){queueMicrotask(()=>this.onend?.());}
  abort(){queueMicrotask(()=>this.onend?.());}
}
const pendingPremium={
  unlock:async()=>true,warm:async()=>true,stop(){},
  createStream(){return {push(){},end(){},stop(){},started:new Promise(()=>{}),done:new Promise(()=>{})};}
};
const states=[];
const store=new Map();
const localStorage={getItem:key=>store.get(key)||null,setItem:(key,value)=>store.set(key,String(value)),removeItem:key=>store.delete(key)};
const document={documentElement:{lang:'en-US'},visibilityState:'visible',addEventListener(){},removeEventListener(){}};
const controllerWindow={
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,
  StonefellowPremiumVoiceV122:()=>pendingPremium,
  speechSynthesis:{cancel(){}},
  addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},
  setTimeout,clearTimeout,setInterval,clearInterval
};
const navigator={mediaDevices:null,userActivation:{isActive:true}};
const controllerSandbox={window:controllerWindow,document,navigator,localStorage,CustomEvent,DOMException,AbortController,crypto,performance,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(controllerWindow,{window:controllerWindow,document,navigator,localStorage,CustomEvent,DOMException,AbortController,crypto,performance});
vm.runInNewContext(fastControllerSource,controllerSandbox,{filename:'conversation-voice-v122.js'});
const voice=controllerWindow.StonefellowConversationVoiceV122.create({userId:9,initialEnabled:true,agentEndpoint:'/api/chat.php',csrf:'csrf',isBusy:()=>false,onState:state=>states.push(state)});
const speech=voice.createSpeechStream();
speech.push('This simulated ElevenLabs output never fires onStart.');
speech.end();
await sleep(180);
assert(voice.proof.premiumStartTimeouts===1,'editor watchdog detects a premium stream with no playback lifecycle');
assert(voice.isPreparing()===false,'editor leaves THINKING after premium output fails to start');
assert(states.includes('listening')||voice.isListening(),'editor automatically recovers to LISTEN');
voice.destroy();

console.log('VOICE_THREE_OF_THREE_V157=PASS');
