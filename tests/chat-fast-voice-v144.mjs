import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('chat-voice.js','utf8');
const chat=fs.readFileSync('chat.php','utf8');
const voiceApi=fs.readFileSync('api/agent-voice-v117.php','utf8');
const chatStream=fs.readFileSync('api/chat-stream-v121.php','utf8');
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
let finishLocalSpeech=false;
const localMusicCalls=[];
let musicActive=false;
let musicGuardAllowed=true;
function makePremium(){return {
  warm:async()=>premiumReady,
  createStream(callbacks={}){
    premiumCallbacks=callbacks;let started=false;let resolveStarted,rejectStarted;
    const startedPromise=new Promise((resolve,reject)=>{resolveStarted=resolve;rejectStarted=reject;});
    return {started:startedPromise,push(text){premiumPushes.push(String(text));if(!started){started=true;premiumStartedBeforeDone=!streamDoneEmitted;callbacks.onStart?.();resolveStarted(true);}},end(){callbacks.onEnd?.();},stop(){premiumStops+=1;rejectStarted?.(new Error('stopped'));}};
  },
  speak:async(text,callbacks={})=>{callbacks.onStart?.();if(finishLocalSpeech)queueMicrotask(()=>callbacks.onEnd?.());return true;},
  stop(){premiumStops+=1;}
};}

const encoder=new TextEncoder();
const fetchCalls=[];
let submittedTranscript={transcript_session_id:21,transcript_source_selected:true};
const nativeFetch=async(inputArg,init={})=>{
  const url=String(typeof inputArg==='string'?inputArg:inputArg?.url||'');
  let payload={};try{payload=JSON.parse(String(init.body||'{}'));}catch(error){}
  fetchCalls.push({url,payload});
  if(url.includes('chat-stream-v121.php')){
    if(payload.message==='show one music fixture song'){
      const rows=[{type:'start',conversation_id:42,user_message_id:75,assistant_message_id:76},
        {type:'done',data:{ok:true,conversation_id:42,user_message_id:75,assistant_message_id:76,
          input_mode:'voice',answer:'Here is one song.',music_chat_v1:{protocol:'vp3_music_chat_v1',
            result_id:'a'.repeat(32),items:[{source:'vp3',source_id:'41'}],requested_count:1}}}];
      return new Response(rows.map(row=>JSON.stringify(row)).join('\n')+'\n',
        {status:200,headers:{'Content-Type':'application/x-ndjson'}});
    }

    if(payload.message==='search Gmail for project notes'){
      const rows=[{type:'start',conversation_id:42,user_message_id:73,assistant_message_id:74},
        {type:'done',data:{ok:true,conversation_id:42,user_message_id:73,assistant_message_id:74,
          input_mode:'voice',private_email_read:true,answer:'Private project notes',actions:[{type:'gmail_message',label:'Open email'}]}}];
      return new Response(rows.map(row=>JSON.stringify(row)).join('\n')+'\n',
        {status:200,headers:{'Content-Type':'application/x-ndjson'}});
    }
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
  STONEFELLOW_CHAT_CONTINUITY:{runMusicLocalTurn:async(message,action)=>{localMusicCalls.push({message,action});return 'Music '+action+'.';}},
  VP3_MUSIC_CHAT_V1:{command:message=>musicActive?({'pause':'pause','stop':'stop','next song':'next','resume music':'resume'}[message]||null):null},

  SpeechRecognition:Recognition,webkitSpeechRecognition:null,StonefellowAgentContext:{refresh:async()=>({surface:'chat',transcript_session_id:22,transcript_source_selected:true}),snapshot:()=>({surface:'chat',...submittedTranscript}),setConversationId(){}},StonefellowPremiumVoiceV122:makePremium,
  fetch:nativeFetch,addEventListener(){},removeEventListener(){},dispatchEvent(){return true;},setTimeout,clearTimeout,setInterval,clearInterval,
  speechSynthesis:{cancel(){},speak(){systemSpeakCalls+=1;}},SpeechSynthesisUtterance:class{constructor(text){this.text=text;this.onstart=null;this.onend=null;this.onerror=null;}}
};
const navigator={userActivation:{isActive:true}};
const location={href:'https://stonefellow.com/chat.php',origin:'https://stonefellow.com',pathname:'/chat.php',search:''};
const sandbox={window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder,console,setTimeout,clearTimeout,setInterval,clearInterval,queueMicrotask};
Object.assign(window,{window,document,navigator,localStorage,location,CustomEvent,Event,URL,URLSearchParams,Response,AbortController,ReadableStream,TextEncoder,TextDecoder});
let lastResponse;
elements.chatForm.requestSubmit=()=>{lastResponse=window.fetch('/api/chat.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',conversation_id:0,message:elements.chatInput.value,input_mode:'text',csrf_token:'csrf',music_request_id:'music-request-voice-fixture',agent_context:submittedTranscript})});};

vm.runInNewContext(source,sandbox,{filename:'chat-voice.js'});

assert(source.includes("const BUILD='chat-voice-proactive-v244-20260922-speech-recovery-20261031-mic-startup-20261101-quiet-abort-20261102'"),'fast voice behavior remains active inside the v2.44 proactive lifecycle');
assert(source.includes('TURN_END_PAUSE_MS=1800'),'fast voice path honors the v148 natural pause window');
assert(source.includes("new URL('chat-stream-v121.php',chatUrl)"),'voice turn derives the existing NDJSON Chat stream endpoint');
assert(source.includes('premium.createStream({'),'voice turn feeds incremental AI text into the premium stream');
assert(source.includes('FAST_PREMIUM_PRESTART_ERROR')&&source.includes('FAST_PREMIUM_FULL_RETRY'),'v146 recovers pre-audio premium failure through an ElevenLabs-only retry');
assert(source.includes("startSystemVoiceFallback(message,epoch,'elevenlabs-not-configured')"),'system voice remains reserved for ElevenLabs-not-configured state');
assert(chat.includes("$voiceAssetBuild = 'chat-voice-proactive-v244-20260922'")&&chat.includes("$voiceCacheBuild = 'chat-voice-proactive-v244-20260922-response-relevance-20261023-composer-r3'"),'Chat cache-busts the v2.44 proactive voice runtime');
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
const selectedContext=fetchCalls.find(call=>call.url.includes('chat-stream-v121.php'))?.payload.agent_context;
assert(selectedContext?.transcript_session_id===21&&selectedContext.transcript_source_selected===true,'the voice stream keeps the exact transcription selected when the turn was sent');
assert(premiumPushes[0]==='I can hear you. ','first streamed AI sentence is pushed directly into ElevenLabs');
assert(premiumStartedBeforeDone===true,'ElevenLabs begins before the AI done event/full answer');
assert(systemSpeakCalls===0,'system/browser TTS does not play when ElevenLabs is ready');
await sleep(45);
assert(premiumPushes.join('')==='I can hear you. This second sentence arrives later.','all streamed deltas reach the same ElevenLabs response');
assert(window.STONEFELLOW_CHAT_VOICE.fastStreamTurns===1,'runtime records the fast streamed voice turn');
assert(window.STONEFELLOW_CHAT_VOICE.fastStreamDeltas===2,'runtime records both streamed text deltas');
assert(window.STONEFELLOW_CHAT_VOICE.premiumReady===true,'runtime records ElevenLabs as configured/ready');
assert(window.STONEFELLOW_CHAT_VOICE.premiumAudibleStarts===1,'runtime separately records proof of first audible ElevenLabs playback');

await sleep(500);
const audioBeforeGmail=premiumPushes.length;
submittedTranscript={transcript_session_id:0,transcript_source_cleared:true};
recognizers.at(-1).onresult({resultIndex:0,results:[{0:{transcript:'search Gmail for project notes'},isFinal:true}]});
await sleep(1900);
const privateResult=await (await lastResponse).json();
const clearedContext=fetchCalls.find(call=>call.payload.message==='search Gmail for project notes')?.payload.agent_context;
assert(clearedContext?.transcript_session_id===0&&clearedContext.transcript_source_cleared===true&&clearedContext.transcript_source_selected===undefined,'the voice stream retains a source clear even when background context still names a transcription');
assert(privateResult.answer==='Private project notes'&&privateResult.actions[0].type==='gmail_message',
  'voice Gmail delivers the private text and its action to Chat');
assert(premiumPushes.slice(audioBeforeGmail).join('')==='I put the private Gmail result in Chat.',
  'voice fallback reads the confirmation without sending the private result to speech');
assert(/\$spoken=vp3_agent_chat_voice_text_v2160\(\$done\);\s*if\(\$spoken!==''\)chat_v121_emit\(\['type'=>'delta','delta'=>\$spoken\]\)/.test(chatStream)
  &&/\$spoken=vp3_agent_chat_voice_text_v2160\(\$replay\);\s*if\(trim\(\$spoken\)!==''\)chat_v121_emit\(\['type'=>'delta','delta'=>\$spoken,'replayed'=>true\]\)/.test(chatStream),
  'the stream and its replay separate private Gmail display from speech');

premiumCallbacks?.onError?.(new Error('simulated late ElevenLabs playback failure'));
await sleep(5);
assert(systemSpeakCalls===0,'late ElevenLabs playback failure still does not trigger duplicate system voice');


// The canonical microphone path must preserve the Music Chat result and replay
// key through NDJSON, then use guarded local transport without a phantom API turn.
finishLocalSpeech=true;
await sleep(500);
recognizers.at(-1).onresult({resultIndex:0,results:[{0:{transcript:'show one music fixture song'},isFinal:true}]});
await sleep(1950);
const musicResult=await (await lastResponse).json();
const musicFetch=fetchCalls.find(call=>call.payload.message==='show one music fixture song');
assert(musicFetch?.url.includes('chat-stream-v121.php')&&musicFetch.payload.music_request_id==='music-request-voice-fixture',
  'spoken music request preserves its idempotency key on the real voice transport');
assert(musicResult.music_chat_v1?.requested_count===1&&musicResult.music_chat_v1.items[0].source_id==='41',
  'full Music Chat card result survives the canonical voice stream for the shared Chat renderer');
musicActive=true;
window.VP3VoiceGuard={check:async()=>({allowed:musicGuardAllowed}),isEnabled:()=>true,isKnownOnly:()=>true,reset(){},attach(){},detach(){},takeContext(){return null;},wasVoiceTurn(){return false;}};
const beforeMusicFetches=fetchCalls.length;
for(const [message,action] of [['pause','pause'],['resume music','resume'],['stop','stop']]){
  await sleep(400);
  recognizers.at(-1).onresult({resultIndex:0,results:[{0:{transcript:message},isFinal:true}]});
  await sleep(1950);
  assert(localMusicCalls.at(-1)?.message===message&&localMusicCalls.at(-1)?.action===action,
    'spoken '+message+' controls the active shared music player');
}
assert(fetchCalls.length===beforeMusicFetches,'local music controls do not submit a search or leave a pending voice API turn');
musicGuardAllowed=false;
const beforeDeniedMusic=localMusicCalls.length;
await sleep(400);
recognizers.at(-1).onresult({resultIndex:0,results:[{0:{transcript:'next song'},isFinal:true}]});
await sleep(1950);
assert(localMusicCalls.length===beforeDeniedMusic&&fetchCalls.length===beforeMusicFetches,
  'speaker guard denial blocks local music controls as well as API music requests');
assert(!window.STONEFELLOW_CHAT_CONTINUITY.pendingVoiceSubmit?.(),'music control completion leaves no phantom pending voice submission');
window.VP3ConversationControls.stop();

console.log('CHAT_FAST_VOICE_V157=PASS');
