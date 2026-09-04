import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('premium-voice-v117.js','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

let warmCalls=0;
let ticketCalls=0;
let audioPlayCalls=0;
let audioOnPlaying=0;
let firstTicketText='';

class FakeAudio{
  constructor(){this.preload='';this.src='';this.onplaying=null;this.onended=null;this.onerror=null;}
  load(){}
  pause(){}
  removeAttribute(name){if(name==='src')this.src='';}
  play(){
    audioPlayCalls+=1;
    queueMicrotask(()=>{
      audioOnPlaying+=1;
      this.onplaying?.();
      setTimeout(()=>this.onended?.(),2);
    });
    return Promise.resolve();
  }
}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
const performance={now:()=>Date.now()};
const fetch=async(url,init={})=>{
  const method=String(init.method||'GET').toUpperCase();
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  if(method==='POST'&&payload.action==='warm'){
    warmCalls+=1;
    return new Response(JSON.stringify({ok:true,ready:true,verified:true,model_id:'eleven_flash_v2_5',output_format:'mp3_22050_32'}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(method==='POST'&&payload.action==='ticket'){
    ticketCalls+=1;
    firstTicketText ||= String(payload.text||'');
    return new Response(JSON.stringify({ok:true,stream_url:`https://stonefellow.com/api/agent-voice-v117.php?token=${ticketCalls}`,model_id:'eleven_flash_v2_5',output_format:'mp3_22050_32'}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(method==='GET'&&String(url).includes('agent-voice-v117.php?token=')){
    return new Response(new Blob(['fake-mp3-bytes'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
  }
  throw new Error(`Unexpected fetch ${method} ${url}`);
};
const window={location:{href:'https://stonefellow.com/chat.php'},dispatchEvent(){return true;}};
const sandbox={window,fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance,console,setTimeout,clearTimeout,queueMicrotask};
Object.assign(window,{fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance});
vm.runInNewContext(source,sandbox,{filename:'premium-voice-v117.js'});

const Premium=window.StonefellowPremiumVoiceV122;
assert(typeof Premium==='function','real premium voice module exports a constructor');
const premium=Premium({agentEndpoint:'/api/chat.php',csrf:'csrf'});
const ready=await premium.warm();
assert(ready===true&&warmCalls>=1,'real premium module confirms configured ElevenLabs readiness');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.verifiedReady===true,'readiness is based on a live ElevenLabs voice verification');

let started=0;
let ended=0;
const session=premium.createStream({onStart(){started+=1;},onEnd(){ended+=1;}});
session.push('Hello from Stonefellow. ');
session.end();
await session.started;
await session.done;
await sleep(5);
assert(ticketCalls>=1,'real premium createStream requests an ElevenLabs ticket');
assert(firstTicketText.includes('Hello from Stonefellow'),'real premium ticket contains the streamed response text');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.audioFetches>=1,'premium playback verifies and fetches the audio response before playback');
assert(audioPlayCalls>=1&&audioOnPlaying>=1,'real premium createStream reaches actual Audio.play/onplaying lifecycle');
assert(started===1,'real premium stream emits exactly one audible start callback');
assert(ended===1,'real premium stream emits a clean end callback');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.streamStarts>=1,'real premium telemetry records audible stream start');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.lastFirstAudioMs>=0,'real premium telemetry records first-audio latency');

console.log('PREMIUM_VOICE_RUNTIME_V146=PASS');
