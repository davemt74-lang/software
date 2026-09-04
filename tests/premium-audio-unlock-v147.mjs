import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('premium-voice-v117.js','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

let userGesture=false;
let audioBlessed=false;
let silentUnlockPlays=0;
let delayedVoicePlays=0;
let ticketRequests=0;

class FakeAudio{
  constructor(){this.src='';this.preload='';this.playsInline=false;this.muted=false;this.volume=1;this.onplaying=null;this.onended=null;this.onerror=null;this.error=null;}
  load(){}
  pause(){}
  removeAttribute(name){if(name==='src')this.src='';}
  play(){
    if(this.src.startsWith('data:audio/wav')){
      silentUnlockPlays+=1;
      if(!userGesture)return Promise.reject(new DOMException('Playback requires a user gesture.','NotAllowedError'));
      audioBlessed=true;
      return Promise.resolve();
    }
    delayedVoicePlays+=1;
    if(!audioBlessed)return Promise.reject(new DOMException('Autoplay blocked.','NotAllowedError'));
    queueMicrotask(()=>{
      this.onplaying?.();
      setTimeout(()=>this.onended?.(),2);
    });
    return Promise.resolve();
  }
}

const fetch=async(url,init={})=>{
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  if(payload.action==='warm')return new Response(JSON.stringify({ok:true,ready:true,verified:true,credential_state:'saved',model_id:'eleven_flash_v2_5',output_format:'mp3_22050_32'}),{status:200,headers:{'Content-Type':'application/json'}});
  if(payload.action==='ticket'){
    ticketRequests+=1;
    await sleep(8);
    return new Response(JSON.stringify({ok:true,stream_url:`https://stonefellow.com/api/agent-voice-v117.php?token=${ticketRequests}`,model_id:'eleven_flash_v2_5',output_format:'mp3_22050_32'}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(String(init.method||'GET').toUpperCase()==='GET'&&String(url).includes('agent-voice-v117.php?token=')){
    return new Response(new Blob(['fake-mp3-bytes'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
  }
  throw new Error(`Unexpected request: ${url}`);
};

class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
const window={location:{href:'https://stonefellow.com/chat.php'},dispatchEvent(){return true;}};
const sandbox={window,fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance,console,setTimeout,clearTimeout,queueMicrotask};
Object.assign(window,{fetch,Audio:FakeAudio,CustomEvent,DOMException,AbortController,Response,URL,performance});
vm.runInNewContext(source,sandbox,{filename:'premium-voice-v117.js'});

const premium=window.StonefellowPremiumVoiceV122({agentEndpoint:'/api/chat.php',csrf:'csrf'});
userGesture=true;
const unlocked=await premium.unlock();
userGesture=false;

assert(unlocked===true,'LISTEN user gesture explicitly unlocks premium audio');
assert(silentUnlockPlays===1,'unlock uses one silent same-element playback');
assert(premium.isUnlocked()===true,'premium runtime retains the unlocked state');

let audibleStarts=0;
let speechEnds=0;
const started=await premium.speak('This ElevenLabs response begins after asynchronous model and ticket work.',{
  onStart(){audibleStarts+=1;},
  onEnd(){speechEnds+=1;}
});
await sleep(15);

assert(started===true,'delayed ElevenLabs playback starts after user activation has expired');
assert(ticketRequests===1,'delayed voice still uses the server ticket path');
assert(delayedVoicePlays===1,'the unlocked audio element performs real response playback');
assert(audibleStarts===1&&speechEnds===1,'delayed response emits one audible lifecycle');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.audioUnlocked===true,'runtime proof records unlocked audio');
assert(window.STONEFELLOW_PREMIUM_VOICE_V122.unlockSuccesses===1,'runtime proof records one successful unlock');

console.log('PREMIUM_AUDIO_UNLOCK_V147=PASS');
