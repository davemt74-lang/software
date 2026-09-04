import fs from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';

const source=fs.readFileSync('conversation-voice-v122.js','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Recognition{
  constructor(){recognizers.push(this);this.continuous=false;this.interimResults=false;this.lang='';this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;}
  start(){queueMicrotask(()=>this.onstart?.());}
  stop(){queueMicrotask(()=>this.onend?.());}
  abort(){queueMicrotask(()=>this.onend?.());}
}

const recognizers=[];
const storage=new Map();
const localStorage={getItem:key=>storage.has(key)?storage.get(key):null,setItem:(key,value)=>storage.set(key,String(value))};
const document={documentElement:{lang:'en-US'},visibilityState:'visible',addEventListener(){},removeEventListener(){}};
let unlocks=0;
let premiumStops=0;
let premiumCallbacks=null;
let bargeOptions=null;
const premium={
  unlock(){unlocks+=1;return Promise.resolve(true);},
  warm(){return Promise.resolve(true);},
  createStream(callbacks={}){premiumCallbacks=callbacks;return {push(){},end(){},stop(){premiumStops+=1;},started:Promise.resolve(true),done:Promise.resolve(true)};},
  stop(){premiumStops+=1;}
};
const window={
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,
  StonefellowPremiumVoiceV122:()=>premium,
  StonefellowEditorVoiceBarge:options=>{bargeOptions=options;return {ensure:()=>Promise.resolve(),start(){},stop(){},release(){}};},
  STONEFELLOW_ACTIVITY:null,
  addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},
  setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{}
};
const navigator={userActivation:{isActive:true},mediaDevices:null,sendBeacon:null};
const location={pathname:'/video-editor.php'};
const sandbox={window,document,navigator,location,localStorage,CustomEvent,Blob,crypto:webcrypto,performance,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask,Promise,Math,Date};
Object.assign(window,{window,document,navigator,location,localStorage,CustomEvent,Blob,crypto:webcrypto,performance});
vm.runInNewContext(source,sandbox,{filename:'conversation-voice-v122.js'});

const transcripts=[];
let interruptions=0;
const voice=window.StonefellowConversationVoiceV122.create({
  userId:1,source:'video',agentEndpoint:'/api/video-agent.php',csrf:'csrf',initialEnabled:false,
  isBusy:()=>false,onTranscript:text=>transcripts.push(String(text)),onInterrupt:()=>{interruptions+=1;}
});

voice.toggle();
await sleep(12);
assert(unlocks>=1,'editor LISTEN toggle unlocks delayed ElevenLabs playback');
assert(recognizers.length===1,'editor LISTEN starts one normal recognizer');

const speech=voice.createSpeechStream();
speech.push('Stonefellow can help with this edit and keep the conversation moving.');
premiumCallbacks.onStart();
await sleep(130);
assert(recognizers.length>=2,'editor response starts a native barge recognizer');
const barge=recognizers.at(-1);

bargeOptions.interrupt();
await sleep(5);
assert(premiumStops===0&&interruptions===0,'raw speaker-level echo cannot stop editor output by itself');

barge.onresult({results:[{0:{transcript:'Stonefellow can help with this edit'},isFinal:false}]});
await sleep(5);
assert(premiumStops===0&&voice.proof.echoCandidatesRejected>=1,'assistant speech recognized by the mic is rejected as self-echo');

barge.onresult({results:[{0:{transcript:'change the opening'},isFinal:false}]});
await sleep(5);
assert(premiumStops===0,'one natural interim remains an unconfirmed barge candidate');
barge.onresult({results:[{0:{transcript:'change the opening scene'},isFinal:false}]});
await sleep(5);
assert(premiumStops>=1&&interruptions===1,'two stable non-echo interims interrupt editor output before final speech');
barge.onresult({results:[{0:{transcript:'change the opening scene'},isFinal:true}]});
await sleep(15);
assert(transcripts.length===1&&transcripts[0]==='change the opening scene','editor preserves and submits the complete interruption utterance');
assert(voice.proof.bargeFastCuts===1,'editor runtime records the natural fast barge');

voice.destroy();
console.log('EDITOR_VOICE_V147=PASS');
