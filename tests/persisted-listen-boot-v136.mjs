import fs from 'node:fs';
import vm from 'node:vm';

const chat=fs.readFileSync('chat.php','utf8');
const voice=fs.readFileSync('chat-voice.js','utf8');
const assert=(ok,label)=>{console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)throw new Error(`Failed: ${label}`);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

assert(chat.includes("$controlBuild = 'chat-voice-canonical-20260903'"),'Chat keeps the canonical direct voice owner');
assert(voice.includes('const MODE_KEY=`stonefellow:voice-mode:${userId}`'),'direct owner uses the existing per-user voice mode key');
assert(voice.includes("voiceOn=localStorage.getItem(MODE_KEY)==='1'"),'direct owner restores persisted voice state');
assert(!chat.includes('premium-autoplay-v148.js?v='),'Chat does not insert a separate autoplay wrapper into the voice contract');
assert(chat.includes('data-chat-lifecycle="canonical"')&&chat.includes('chat-voice.js?v='),'lifecycle repair stays on the canonical Agent Chat voice file');

class FakeClassList{constructor(){this.values=new Set();}toggle(name,on){if(on)this.values.add(name);else this.values.delete(name);}}
class FakeElement{
  constructor(id=''){this.id=id;this.value='';this.hidden=false;this.disabled=false;this.dataset={};this.classList=new FakeClassList();this.attributes={};this.listeners={};}
  addEventListener(type,fn){(this.listeners[type]??=[]).push(fn);}
  dispatchEvent(event){for(const fn of this.listeners[event.type]||[])fn(event);return true;}
  setAttribute(name,value){this.attributes[name]=String(value);}
  querySelector(){return null;}
  closest(){return null;}
}

const form=new FakeElement('chatForm');
const input=new FakeElement('chatInput');
const send=new FakeElement('sendChatButton');
const status=new FakeElement('chatVoiceStatus');
const button=new FakeElement('chatVoiceButtonLegacyDormant');
const body=new FakeElement('body');
const elements={chatForm:form,chatInput:input,sendChatButton:send,chatVoiceStatus:status,chatVoiceButtonLegacyDormant:button};
const document={body,documentElement:{lang:'en-US'},getElementById:id=>elements[id]||null,querySelector:()=>null,querySelectorAll:()=>[],createElement:()=>new FakeElement(),addEventListener(){},removeEventListener(){}};

let starts=0;
const recognizers=[];
class Recognition{
  constructor(){recognizers.push(this);this.onstart=null;this.onresult=null;this.onerror=null;this.onend=null;this.continuous=false;this.interimResults=false;this.lang='';}
  start(){starts+=1;queueMicrotask(()=>this.onstart?.());}
  stop(){queueMicrotask(()=>this.onend?.());}
  abort(){queueMicrotask(()=>this.onend?.());}
}
class CustomEvent{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}}
class Event{constructor(type){this.type=type;}}

const store=new Map([['stonefellow:voice-mode:1','1']]);
const localStorage={getItem:key=>store.has(key)?store.get(key):null,setItem:(key,value)=>store.set(key,String(value))};
const window={
  STONEFELLOW_CHAT:{userId:1,endpoint:'/api/chat.php',csrf:'csrf',initialConversationId:0,initialView:'chat'},
  STONEFELLOW_CHAT_VOICE_BOOT:{button,intro:null,legacyDormant:true},
  SpeechRecognition:Recognition,webkitSpeechRecognition:null,
  fetch:async()=>new Response('{}',{status:200,headers:{'Content-Type':'application/json'}}),
  speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{},
  addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval
};
const navigator={userActivation:{isActive:false}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController});
form.requestSubmit=()=>{};

vm.runInNewContext(voice,sandbox,{filename:'chat-voice.js'});
await sleep(20);

assert(starts===1&&recognizers.length===1,'persisted voice starts SpeechRecognition automatically without a click');
assert(window.STONEFELLOW_CHAT_VOICE?.starts===1,'persisted boot reaches the recognizer onstart lifecycle');
assert(body.dataset.stonefellowAgentState==='listening','persisted voice boot is blue LISTEN, not idle Voice conversation on');
assert(status.dataset.state==='listening'&&status.textContent==='Listening…','visible voice status reports active Listening');
assert(button.dataset.agentState==='listening','voice button is synchronized to the listening state');
assert(store.get('stonefellow:voice-mode:1')==='1','automatic LISTEN boot does not rewrite persisted voice mode off');

assert(voice.includes('function isAutoplayBlockedError')&&voice.includes("log('INTRO_AUTOPLAY_BLOCKED'"),'browser autoplay blocking remains handled inside the existing Chat controller');
assert(voice.includes('pendingIntroSpeech&&voiceOn&&!processing&&!speaking&&!introRetryScheduled')&&voice.includes('speakAnswer(pendingIntroSpeech)'),'queued intro retries after a successful user-gesture unlock');
assert(voice.includes("window.addEventListener('storage'")&&voice.includes("enableVoice({persist:false,start:true})"),'voice mode still synchronizes across surfaces without rewriting storage');
assert(!chat.includes('conversation-voice-v122.js?v=')&&!chat.includes('voiceStreamEndpoint')&&!chat.includes('chat-voice-v140.js?v=')&&!chat.includes('chat-barge-v141.js?v='),'Agent Chat remains on one direct voice owner');

console.log('PERSISTED_LISTEN_BOOT_V157=PASS');
