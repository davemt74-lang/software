import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
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
class Recognition{
  constructor(){recognizers.push(this);this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;this.onspeechstart=null;}
  start(){queueMicrotask(()=>this.onstart?.());}
  stop(){queueMicrotask(()=>this.onend?.());}
  abort(){queueMicrotask(()=>this.onend?.());}
}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Event{constructor(type){this.type=type;}}
const store=new Map();
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,val)=>store.set(key,String(val))};
const document={body:new FakeElement('body'),documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new FakeElement(),addEventListener(){},removeEventListener(){}};

let createStreamCalls=0;
let retrySpeakCalls=0;
let retryStarted=0;
let systemSpeakCalls=0;
const pushed=[];
function Premium(){
  return {
    warm:async()=>true,
    createStream(){
      createStreamCalls+=1;
      let rejectStarted;
      const started=new Promise((resolve,reject)=>{rejectStarted=reject;});
      return {
        started,
        push(text){pushed.push(String(text));},
        end(){queueMicrotask(()=>rejectStarted(new Error('simulated incremental ticket/audio start failure')));},
        stop(){}
      };
    },
    async speak(text,callbacks={}){
      retrySpeakCalls+=1;
      retryStarted+=1;
      callbacks.onStart?.();
      return true;
    },
    stop(){}
  };
}

const encoder=new TextEncoder();
const nativeFetch=async(inputArg,init={})=>{
  const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');
  if(url.includes('chat-stream-v121.php')){
    const body=new ReadableStream({start(controller){
      controller.enqueue(encoder.encode(JSON.stringify({type:'start',conversation_id:42,user_message_id:1,assistant_message_id:2})+'\n'));
      controller.enqueue(encoder.encode(JSON.stringify({type:'delta',delta:'ElevenLabs should retry this answer. '})+'\n'));
      controller.enqueue(encoder.encode(JSON.stringify({type:'done',data:{ok:true,conversation_id:42,user_message_id:1,assistant_message_id:2,answer:'ElevenLabs should retry this answer.',input_mode:'voice'}})+'\n'));
      controller.close();
    }});
    return new Response(body,{status:200,headers:{'Content-Type':'application/x-ndjson'}});
  }
  return new Response(JSON.stringify({ok:true,conversation_id:42,answer:'fallback'}),{status:200,headers:{'Content-Type':'application/json'}});
};
const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButtonLegacyDormant,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat'}),snapshot:()=>({surface:'chat'}),setConversationId(){}},StonefellowPremiumVoiceV122:Premium,
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){systemSpeakCalls+=1;}},SpeechSynthesisUtterance:class{constructor(text){this.text=text;}}
};
const navigator={userActivation:{isActive:true}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder});

elements.chatForm.requestSubmit=()=>{void window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf'})});};
vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(source.includes('FAST_PREMIUM_FULL_RETRY'),'v146 contains an explicit full-answer ElevenLabs retry');
assert(source.includes('premiumPrestartFailures')&&source.includes('premiumAudibleStarts'),'v146 distinguishes configured readiness from real first audio');
assert(source.includes("setAgentState('error','ElevenLabs voice could not start.')"),'v146 surfaces total ElevenLabs failure instead of silent LISTEN');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'v148 keeps the retry path behind the same natural speaking-pause window');

// Start LISTEN and finish one speech segment. The incremental stream must not
// start until the v148 quiet-window closes; then a pre-audio failure retries
// the complete answer through ElevenLabs exactly once.
elements.chatVoiceButtonLegacyDormant.click();
await sleep(8);
const normal=recognizers[0];
normal.onresult({resultIndex:0,results:[{0:{transcript:'say something',confidence:.99},isFinal:true}]});
await sleep(120);
assert(createStreamCalls===0,'fast incremental ElevenLabs stream does not start during a normal speaking pause');
await sleep(1780);
await sleep(45);

assert(createStreamCalls===1,'fast incremental ElevenLabs stream is attempted once after the quiet-window');
assert(pushed.join('').includes('ElevenLabs should retry this answer.'),'AI deltas are still fed to the fast ElevenLabs stream');
assert(retrySpeakCalls===1,'pre-audio fast failure retries the complete answer through ElevenLabs speak() exactly once');
assert(retryStarted===1,'ElevenLabs retry reaches an audible start callback');
assert(window.STONEFELLOW_CHAT_VOICE.premiumFullRetries===1,'runtime telemetry records one full-answer ElevenLabs retry');
assert(window.STONEFELLOW_CHAT_VOICE.premiumAudibleStarts===1,'runtime telemetry records the retry as audible');
assert(systemSpeakCalls===0,'configured ElevenLabs failure never falls through to system/browser TTS');
assert(document.body.dataset.stonefellowAgentState==='speaking','successful ElevenLabs retry restores responding state');

console.log('CHAT_ELEVENLABS_RETRY_V148=PASS');
