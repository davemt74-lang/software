import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
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
const nativeFetchCalls=[];const spoken=[];const speechCallbacks=[];let premiumStops=0;let premiumUnlocks=0;
const answers=['I can hear you. What would you like to work on?','Got it. I heard your interruption.','Thanks. We are still listening normally.'];
const nativeFetch=async(inputArg,init={})=>{const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}nativeFetchCalls.push({url,init,payload});const answer=answers[Math.min(nativeFetchCalls.length-1,answers.length-1)];return new Response(JSON.stringify({ok:true,conversation_id:42,assistant_message_id:70+nativeFetchCalls.length,answer}),{status:200,headers:{'Content-Type':'application/json'}});};
const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button:elements.chatVoiceButtonLegacyDormant,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat'}),snapshot:()=>({surface:'chat'}),setConversationId(){}},
  StonefellowPremiumVoiceV122:()=>({unlock(){premiumUnlocks+=1;return Promise.resolve(true);},stop(){premiumStops+=1;},speak:async(text,options={})=>{spoken.push(String(text));speechCallbacks.push(options);options.onStart?.();return true;}}),
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{}
};
const navigator={userActivation:{isActive:true}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController});
elements.chatForm.requestSubmit=()=>{void window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf'})});};

vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(source.includes("const BUILD='chat-voice-canonical-20260903'"),'v157 keeps pause, barge and echo runtime active');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'v148 natural-pause behavior remains active');
assert(source.includes('SR_RESULT_IGNORED_BUSY'),'late normal recognizer results are rejected while busy');
assert(source.includes('BARGE_ECHO_REJECTED'),'barge recognizer has explicit assistant-echo rejection');
assert(source.includes('SR_ECHO_REJECTED'),'normal LISTEN has post-speech echo-tail rejection');
assert(source.includes('POST_SPEECH_ECHO_MS=4000'),'post-speech echo memory window covers delayed recognition finals');
assert(source.includes('const semanticEcho=isLikelyStonefellowEcho')&&source.includes('const echo=semanticEcho&&(!immediate||!nearField)'),'explicit interruption commands require near-field evidence when they resemble output');
assert(chat.includes("$voiceAssetBuild = 'chat-voice-canonical-20260903'")&&chat.includes("$voiceCacheBuild = 'chat-voice-canonical-20260903-failover1'"),'Chat cache-busts the complete v157 repair');
assert(chat.includes('data-chat-echo-guard="canonical"'),'Chat publishes the canonical echo guard');

// LISTEN -> first user turn. A browser final is only a speech segment; the
// request waits for the quiet-window before Stonefellow starts thinking.
elements.chatVoiceButtonLegacyDormant.click();
await sleep(12);
assert(recognizers.length===1&&nativeStarts===1,'LISTEN starts the normal recognizer');
assert(premiumUnlocks>=1,'LISTEN gesture explicitly unlocks delayed ElevenLabs playback');
const normal=recognizers[0];
normal.onresult({resultIndex:0,results:[{0:{transcript:'all right are you listening'},isFinal:true}]});
await sleep(120);
assert(nativeFetchCalls.length===0,'normal speaking pause does not submit the question early');
assert(document.body.dataset.stonefellowAgentState==='listening','agent stays blue/listening during the quiet-window');
await sleep(1780);
assert(nativeFetchCalls.length===1,'first real user phrase submits exactly once after the quiet-window');
assert(spoken[0]?.startsWith('I can hear you'),'first assistant answer begins speaking');
const barge=recognizers.at(-1);
assert(barge&&barge!==normal,'response starts a dedicated native barge recognizer');

barge.onresult({resultIndex:0,results:[{0:{transcript:'I can'},isFinal:false}]});
await sleep(5);
assert(premiumStops===0,'assistant interim echo does not stop its own audio');
assert(window.STONEFELLOW_CHAT_VOICE.interruptions===0,'assistant interim echo is not counted as a user interruption');
assert(window.STONEFELLOW_CHAT_VOICE.bargeEchoRejects>=1,'assistant interim echo is explicitly rejected');

barge.onresult({resultIndex:0,results:[{0:{transcript:'I can hear you what would you like to work on'},isFinal:true}]});
await sleep(10);
assert(nativeFetchCalls.length===1,'assistant final echo does not create a second Chat turn');
assert(window.STONEFELLOW_CHAT_VOICE.interruptions===0,'assistant final echo still does not interrupt');

barge.onresult({resultIndex:0,results:[{0:{transcript:'I need to change'},isFinal:false}]});
await sleep(5);
assert(premiumStops===0,'one unconfirmed natural-speech interim does not create a false interruption');
barge.onresult({resultIndex:0,results:[{0:{transcript:'I need to change that'},isFinal:false}]});
await sleep(5);
assert(premiumStops>=1,'two stable non-echo interim updates barge before the final transcript');
assert(window.STONEFELLOW_CHAT_VOICE.interruptions===1,'real barge-in is counted exactly once');
assert(window.STONEFELLOW_CHAT_VOICE.bargeFastCuts===1,'natural interim barge records one fast cut');
barge.onresult({resultIndex:0,results:[{0:{transcript:'I need to change that'},isFinal:true}]});
await sleep(35);
assert(nativeFetchCalls.length===2,'completed interruption becomes the second Chat turn');
assert(nativeFetchCalls[1].payload.message==='I need to change that','second Chat turn contains the user interruption, not assistant audio');

assert(speechCallbacks[1]?.onEnd,'second spoken answer exposes an onEnd callback');
speechCallbacks[1].onEnd();
await sleep(360);
const postSpeech=recognizers.at(-1);
assert(postSpeech&&postSpeech!==barge,'normal LISTEN restarts after the response ends');
postSpeech.onresult({resultIndex:0,results:[{0:{transcript:'Got it I heard your interruption'},isFinal:true}]});
await sleep(25);
assert(nativeFetchCalls.length===2,'speaker-tail echo after response end is not submitted');
assert(window.STONEFELLOW_CHAT_VOICE.postSpeechEchoRejects>=1,'post-speech echo rejection is recorded');

await sleep(300);
const resumed=recognizers.at(-1);
resumed.onresult({resultIndex:0,results:[{0:{transcript:'thanks that is better'},isFinal:true}]});
await sleep(120);
assert(nativeFetchCalls.length===2,'a real follow-up also remains open during its speaking-pause window');
await sleep(1780);
assert(nativeFetchCalls.length===3,'normal user speech still submits after echo rejection and the quiet-window');
assert(nativeFetchCalls[2].payload.message==='thanks that is better','normal user speech is preserved exactly');

console.log('CHAT_BARGE_ECHO_V157=PASS');
