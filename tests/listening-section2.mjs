import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {fileURLToPath} from 'node:url';

const root=fileURLToPath(new URL('../',import.meta.url));
const cloud=fs.existsSync(root+'chat-voice.js');
const source=name=>fs.readFileSync(root+(cloud?'':'ui/')+name,'utf8');
const checks=[];
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
const response=data=>({ok:true,json:async()=>data});
class Element {
 constructor(id=''){this.id=id;this.value='';this.checked=false;this.dataset={};this.attributes={};this.children=[];this.childNodes=[];this.textContent='';this.hidden=false;this.listeners={};this.options=[];this.style={setProperty(){},removeProperty(){}};const classes=new Set();this.classList={add:n=>classes.add(n),remove:n=>classes.delete(n),contains:n=>classes.has(n),toggle(n,v){if(v??!classes.has(n))classes.add(n);else classes.delete(n);}};}
 addEventListener(n,f){(this.listeners[n]??=[]).push(f);}
 setAttribute(n,v){this.attributes[n]=String(v);}
 getAttribute(n){return this.attributes[n]??null;}
 removeAttribute(n){delete this.attributes[n];}
 querySelector(){return new Element();}
 querySelectorAll(){return [];}
 replaceChildren(...children){this.children=children;}
 appendChild(child){this.children.push(child);}
 insertBefore(child){this.children.push(child);}
 append(...children){this.children.push(...children);}
 insertAdjacentHTML(){}
 dispatchEvent(){return true;}
 closest(){return null;}
 remove(){}
 focus(){}
}
function browser({storage=new Map(),recognition=true}={}){
 const elements=new Map(),listeners=new Map(),events=[],timers=[],intervals=[];
 const element=id=>{if(!elements.has(id))elements.set(id,new Element(id));return elements.get(id);};
 const document={readyState:'loading',currentScript:{dataset:{voiceCaptureScope:'test-device'}},documentElement:{lang:'en-US'},body:new Element('body'),head:new Element('head'),getElementById:element,querySelector:element,querySelectorAll:()=>[],createElement:()=>new Element(),addEventListener(){},removeEventListener(){}};
 class Recognition {start(){}stop(){queueMicrotask(()=>this.onend?.({type:'end'}));}abort(){queueMicrotask(()=>this.onend?.({type:'end'}));}}
 class Recorder {static isTypeSupported(){return true;}constructor(){this.state='inactive';this.mimeType='audio/webm';this.listeners={};}addEventListener(n,f){(this.listeners[n]??=[]).push(f);}start(){this.state='recording';}requestData(){}stop(){this.state='inactive';queueMicrotask(()=>{this.onstop?.();for(const f of this.listeners.stop||[])f();});}}
 class AudioContext {constructor(){this.state='running';}createMediaStreamSource(){return {connect(){},disconnect(){}};}createAnalyser(){return {fftSize:512,frequencyBinCount:256,getByteTimeDomainData(){},getByteFrequencyData(){}};}close(){this.state='closed';return Promise.resolve();}resume(){return Promise.resolve();}}
 const window={document,SpeechRecognition:recognition?Recognition:null,MediaRecorder:Recorder,AudioContext,addEventListener:(n,f)=>{if(!listeners.has(n))listeners.set(n,[]);listeners.get(n).push(f);},removeEventListener(){},dispatchEvent:e=>{events.push(e);for(const f of listeners.get(e.type)||[])f(e);return true;},speechSynthesis:{cancel(){},speak(){}},SpeechSynthesisUtterance:class{constructor(text){this.text=text;}},fetch:async()=>response({ok:true,session:{id:41,status:'draft',segments:[]},sessions:[]})};
 const localStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k)};
 const context={window,document,localStorage,navigator:{language:'en-US',userActivation:{isActive:true},mediaDevices:{getSupportedConstraints:()=>({})}},location:{href:'https://vp3.test/chat.php',pathname:'/chat.php',search:'',origin:'https://vp3.test'},console,URL,URLSearchParams,Response,ReadableStream,TextEncoder,TextDecoder,Blob,FormData,AbortController,DOMException,Date,performance,crypto:webcrypto,Uint8Array,Float32Array,ArrayBuffer,DataView,Event,CustomEvent:class{constructor(type,init={}){this.type=type;this.detail=init.detail||{};}},queueMicrotask,setTimeout:f=>{timers.push(f);return timers.length;},clearTimeout(){},setInterval:f=>{intervals.push(f);return intervals.length;},clearInterval(){},requestAnimationFrame:()=>1,cancelAnimationFrame(){},fetch:(...args)=>window.fetch(...args)};
 Object.assign(window,{setTimeout:context.setTimeout,clearTimeout:context.clearTimeout,setInterval:context.setInterval,clearInterval:context.clearInterval});
 context.MediaRecorder=Recorder;context.SpeechSynthesisUtterance=window.SpeechSynthesisUtterance;
 return {context,window,document,element,events,timers,intervals,storage,emit:(name,event={})=>window.dispatchEvent({type:name,...event})};
}
function load(name,b,hook=''){
 let code=source(name);if(hook){const at=code.lastIndexOf('})();');assert(at>=0);code=code.slice(0,at)+hook+'\n'+code.slice(at);}
 vm.runInNewContext(code,b.context,{filename:name});
}
function stream(){const track={readyState:'live',stops:0,stop(){this.stops++;this.readyState='ended';},addEventListener(){},getSettings(){return {};},getConstraints(){return {};}};return {track,getTracks:()=>[track],getAudioTracks:()=>[track]};}
async function test(name,run){await run();checks.push(name);console.log('PASS '+name);}


const flush=async()=>{for(let i=0;i<20;i++)await Promise.resolve();};
function clock(b){
 let time=0,sequence=0;const pending=new Map();
 const set=(fn,delay=0)=>{const id=++sequence;pending.set(id,{fn,at:time+Number(delay)});return id;};
 const clear=id=>pending.delete(id);
 Object.assign(b.context,{setTimeout:set,clearTimeout:clear});Object.assign(b.window,{setTimeout:set,clearTimeout:clear});
 return {pending,async advance(ms){const end=time+ms;let count=0;while(true){let next;for(const [id,job] of pending)if(job.at<=end&&(!next||job.at<next[1].at))next=[id,job];if(!next)break;assert(++count<1000,'bounded timer execution');time=next[1].at;pending.delete(next[0]);next[1].fn();await flush();}time=end;await flush();}};
}
const result=(text,index=0)=>({resultIndex:index,results:Array.from({length:index+1},(_,i)=>({0:{transcript:i===index?text:'earlier',confidence:.9},isFinal:true}))});

if(cloud){
 function captureStartup(b,{lease=false,workspace=false}={}){
  b.window.queueMicrotask=queueMicrotask;
  b.window.STONEFELLOW_ARTIST_LISTENING_V172={endpoint:'/api/artist-listening-v172.php',csrf:'test',userId:1,schemaReady:true};
  if(lease)load('voice-lease-v122.js',b);
  load('artist-listening-recognition.js',b);
  if(workspace)load('artist-listening-workspace.js',b);
  load('artist-listening.js',b,'window.CaptureTest={state,startRecognition,stopCapture,render,seed:()=>{state.active=true;state.session={id:41,status:"active",segments:[]};state.captureStartedAt=performance.now();}};');
  const api=b.window.CaptureTest;api.seed();return api;
 }
 for(const failure of ['silent','early-end','constructor','start-throw','network'])await test('transcription bounds '+failure+' startup without claiming capture or retaining audio',async()=>{
  const b=browser(),timer=clock(b),natives=[];let starts=0,constructors=0;
  b.window.SpeechRecognition=class{
   constructor(){constructors++;if(failure==='constructor')throw Error('service unavailable');natives.push(this);}
   start(){starts++;if(failure==='start-throw')throw Error('already starting');if(failure==='early-end')queueMicrotask(()=>this.onend?.({}));if(failure==='network'){this.onstart?.({});queueMicrotask(()=>this.onerror?.({error:'network'}));}}
   abort(){this.onend?.({});}
  };
  const api=captureStartup(b);api.startRecognition();api.render();
  if(failure!=='network')assert.equal(b.element('[data-listening-state]').textContent,'CONNECTING');
  assert.equal(api.state.recordingActive,false);await flush();await timer.advance(failure==='silent'?35000:3000);
  assert.equal(constructors,3);assert.equal(starts,failure==='constructor'?0:3);assert.equal(api.state.active,false);assert.equal(api.state.recordingActive,false);
  assert.equal(api.state.recognition,null);assert.notEqual(b.element('[data-listening-state]').textContent,'TRANSCRIBING');
  assert.equal(b.window.STONEFELLOW_ARTIST_LISTENING_RECOGNITION.startFailures,3);
  for(const native of natives){native.onstart?.({});native.onresult?.(result('late words'));native.onend?.({});}
  await timer.advance(1000);assert.equal(api.state.active,false);assert.equal(constructors,3);
 });
 await test('transcription successful restart preserves repeated finals with the actual lease and workspace wrappers',async()=>{
  const b=browser(),timer=clock(b),natives=[];
  b.window.SpeechRecognition=class{constructor(){natives.push(this);}start(){this.onstart?.({});}stop(){this.onend?.({});}abort(){this.onend?.({});}};
  b.window.fetch=()=>new Promise(()=>{});const api=captureStartup(b,{lease:true,workspace:true});api.startRecognition();api.render();
  assert.equal(b.element('[data-listening-state]').textContent,'TRANSCRIBING');assert.equal(api.state.recordingActive,false);
  natives[0].onresult(result('repeat this'));natives[0].onend({});await timer.advance(35);assert.equal(natives.length,2);
  natives[1].onresult(result('repeat this'));assert.equal(api.state.pending.length,2);assert.equal(api.state.pending.map(x=>x.text).join(' '),'repeat this repeat this');
  const before=natives.length;const owner=api.state.recognition;assert(owner.pauseTranscription());await timer.advance(1000);assert.equal(natives.length,before);
  assert(owner.resumeTranscription());assert.equal(natives.length,before+1);void api.stopCapture();assert.equal(api.state.active,false);owner.abort();
 });
 await test('transcription Stop releases a silent startup and fences late events',async()=>{
  const b=browser(),timer=clock(b);let native,starts=0;
  b.window.SpeechRecognition=class{constructor(){native=this;}start(){starts++;}abort(){this.onend?.({});}};
  const api=captureStartup(b);api.startRecognition();await api.stopCapture();native.onstart?.({});native.onresult?.(result('late'));native.onend?.({});
  await timer.advance(35000);assert.equal(starts,1);assert.equal(api.state.active,false);assert.equal(api.state.pending.length,0);assert.equal(api.state.recognition,null);
 });
 await test('silent intervals remain continuous rather than exhausting transcription startup retries',async()=>{
  const b=browser(),timer=clock(b),natives=[];
  b.window.SpeechRecognition=class{constructor(){natives.push(this);}start(){this.onstart?.({});}abort(){this.onend?.({});}};
  const api=captureStartup(b);api.startRecognition();
  for(let i=0;i<8;i++){natives.at(-1).onerror({error:'no-speech'});await timer.advance(35);}
  assert.equal(api.state.active,true);assert.equal(natives.length,9);assert.equal(b.window.STONEFELLOW_ARTIST_LISTENING_RECOGNITION.startFailures,0);await api.stopCapture();
 });
 await test('ordinary Chat voice speech uses the blue header and has no retained-audio recorder',async()=>{
  const page=source('chat.php');const rule=page.match(/body\[data-stonefellow-agent-state="speaking"\][^}]+}/)?.[0];assert(rule);assert.match(rule,/#58a6ff/);assert.doesNotMatch(rule,/#ff626b/);
  assert.doesNotMatch(source('chat-voice.js'),/new\s+(?:window\.)?MediaRecorder\s*\(/);
  assert.match(source('artist-listening.css'),/record-btn\.active[^}]+rgba\(255,92,101/);
  assert.match(source('artist-listening.php'),/artist-listening-recognition\.js\?[^']+startup=20261022/);
 });

 await test('distinct repeated utterances, result replay and document isolation',async()=>{
  const b=browser();load('artist-listening-realtime.js',b);const C=b.window.STONEFELLOW_ARTIST_LISTENING_REALTIME.TranscriptContinuity;
  const doc=new C('Earlier words');assert.equal(doc.finalize('say it again',1,{runId:'a',resultIndex:0}).delta,'say it again');
  assert.equal(doc.finalize('say it again',2,{runId:'a',resultIndex:1}).delta,'say it again');
  assert(doc.finalize('changed wording on replay',3,{runId:'a',resultIndex:1}).duplicate);
  assert.equal(doc.finalize('say it again',4,{runId:'b',resultIndex:0}).delta,'say it again');
  assert.equal(new C().finalize('say it again',4,{runId:'a',resultIndex:0}).delta,'say it again');
  assert.equal(doc.finalize('say it again').delta,'say it again');
 });
 for(const text of ['你好 世界','今日は','مرحبا بالعالم','हिन्दी आवाज़','cafe\u0301 résumé','中','I','!!!'])await test('transcript text preserved: '+text,async()=>{
  const b=browser();load('artist-listening-realtime.js',b);const api=b.window.STONEFELLOW_ARTIST_LISTENING_REALTIME;const doc=new api.TranscriptContinuity();assert.equal(doc.finalize(text).delta,text);assert.equal(doc.committed,text);
 });
 await test('overlap reconciliation requires explicit cumulative provider text',async()=>{
  const b=browser();load('artist-listening-realtime.js',b);const api=b.window.STONEFELLOW_ARTIST_LISTENING_REALTIME;
  const separate=new api.TranscriptContinuity('move the release date');assert.equal(separate.finalize('the release date to Friday').delta,'the release date to Friday');
  const cumulative=new api.TranscriptContinuity('move the release date');assert.equal(cumulative.finalize('the release date to Friday',1,{cumulative:true}).delta,'to Friday');
  assert.equal(api.reconcileFinal('cafe\u0301 résumé','café résumé demain').delta,'demain');
  assert.equal(api.reconcileFinal('!!!','???').delta,'???');
 });
 await test('native restart gives index-zero finals a fresh identity',async()=>{
  const b=browser(),timer=clock(b),native=[];b.window.SpeechRecognition=class{constructor(){native.push(this);}start(){this.onstart?.({});}stop(){}abort(){}};
  load('artist-listening-recognition.js',b);load('artist-listening-realtime.js',b);const continuity=new b.window.STONEFELLOW_ARTIST_LISTENING_REALTIME.TranscriptContinuity();
  const owner=new b.window.SpeechRecognition();owner.onresult=event=>continuity.finalize(event.results[0][0].transcript,1,{runId:event.stonefellowRecognitionRunId,resultIndex:0});owner.start();
  native[0].onresult(result('repeat'));native[0].onresult(result('repeat'));native[0].onend({});await timer.advance(35);native[1].onresult(result('repeat'));native[0].onresult(result('obsolete'));
  assert.equal(continuity.committed,'repeat repeat');owner.abort();
 });
 function chat(b){
  b.window.STONEFELLOW_CHAT={userId:1,endpoint:'/api/chat.php'};b.window.STONEFELLOW_CHAT_VOICE_BOOT={button:b.element('chatVoiceButton'),intro:null};
  load('chat-voice.js',b,'window.Test={enableVoice,startListening,createRecognition,handleVoiceApiFetch,interruptResponse,disableVoice,submitVoiceTranscript,speakAnswer,queueIntro:()=>{pendingIntroSpeech="Queued greeting";},seed:()=>{voiceOn=true;},recognize:()=>{recognition=createRecognition();return recognition;},state:()=>({voiceOn,recognitionStarting,recognitionListening,recognition,processing,speaking,activeRequest,pendingFinalTranscript,lastConversationId,proof})};');return b.window.Test;
 }
 await test('Chat lease supports native receiver checks through speech Stop and restart',async()=>{
  const b=browser(),timer=clock(b),instances=[],receivers=new WeakMap(),spoken=[];
  class NativeRecognition {
   constructor(){receivers.set(this,{});instances.push(this);}
   start(){this.onstart?.({});}
   stop(){queueMicrotask(()=>this.onend?.({}));}
   abort(){this.stop();}
  }
  for(const name of ['onstart','onend','onresult','onerror','onaudiostart','onaudioend','onnomatch','onsoundstart','onsoundend','onspeechstart','onspeechend'])Object.defineProperty(NativeRecognition.prototype,name,{
   get(){if(!receivers.has(this))throw new TypeError('Illegal invocation');return receivers.get(this)[name];},
   set(value){if(!receivers.has(this))throw new TypeError('Illegal invocation');receivers.get(this)[name]=value;}
  });
  b.window.SpeechRecognition=NativeRecognition;
  load('voice-lease-v122.js',b);
  b.window.speechSynthesis.speak=u=>spoken.push(u);
  const api=chat(b);api.enableVoice();await flush();
  assert.equal(api.state().recognitionListening,true);
  api.speakAnswer('The answer continues here');spoken[0].onstart();await flush();await timer.advance(1);
  assert.equal(api.state().speaking,true);assert.equal(api.state().proof.bargeStarts,1);
  instances.at(-1).onresult(result('stop'));await flush();
  assert.equal(api.state().speaking,false);assert.equal(api.state().proof.stopControls,1);
  // Native capture must release before the post-speech listening restart.
  assert.equal(api.state().recognitionListening,false);await timer.advance(360);
  assert.equal(api.state().recognitionListening,true);
  api.disableVoice();await flush();assert.equal(api.state().voiceOn,false);
  api.enableVoice();await flush();assert.equal(api.state().recognitionListening,true);api.disableVoice();
  const transcription=browser();clock(transcription);transcription.window.SpeechRecognition=NativeRecognition;
  const capture=captureStartup(transcription,{lease:true,workspace:true});capture.startRecognition();capture.render();
  assert.equal(transcription.element('[data-listening-state]').textContent,'TRANSCRIBING');
  instances.at(-1).onresult(result('transcription works'));assert.equal(capture.state.pending[0].text,'transcription works');
  await capture.stopCapture();assert.equal(capture.state.active,false);
 });
 await test('a saved voice preference does not activate Chat capture on page load',async()=>{
  const b=browser({storage:new Map([['stonefellow:voice-mode:1','1']])}),timer=clock(b);let starts=0,microphones=0;
  b.window.SpeechRecognition=class{start(){starts++;this.onstart?.();}abort(){this.onend?.();}};
  b.context.navigator.mediaDevices.getUserMedia=async()=>{microphones++;return stream();};
  const api=chat(b);await timer.advance(1000);
  assert.equal(api.state().voiceOn,false);assert.equal(starts,0);assert.equal(microphones,0);assert.equal(b.document.body.dataset.stonefellowAgentState,'idle');
  api.enableVoice();await flush();assert.equal(starts,1);assert.equal(api.state().recognitionListening,true);api.disableVoice();
 });
 await test('microphone click stops a pending greeting and interrupts speech before restarting',async()=>{
  const b=browser(),timer=clock(b),spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);
  b.window.SpeechRecognition=class{start(){this.onstart?.();}abort(){this.onend?.();}};
  const api=chat(b),click=()=>{for(const fn of b.element('chatVoiceButton').listeners.click||[])fn({preventDefault(){},stopImmediatePropagation(){}});};
  api.enableVoice();api.queueIntro();click();await flush();assert.equal(api.state().voiceOn,false);
  api.enableVoice();api.speakAnswer('A response');spoken[0].onstart();click();await flush();
  assert.equal(api.state().speaking,false);assert.equal(api.state().voiceOn,true);assert.equal(api.state().recognitionListening,true);
  spoken[0].onend();assert.equal(api.state().recognitionListening,true);
  click();await timer.advance(1000);assert.equal(api.state().voiceOn,false);assert.equal(api.state().recognitionListening,false);
  click();await flush();assert.equal(api.state().recognitionListening,true);api.disableVoice();
 });
 await test('Conversation history timeout releases restoration and rejects late history',async()=>{
  const b=browser(),timer=clock(b),waiting=deferred();let signal,commits=0;b.document.dispatchEvent=()=>true;
  Object.assign(b.context,{chatRevision:0,restoringConversation:0,conversationId:1,contextReady:false,lastLoadedMessageId:0,
   leaveChat(){b.context.chatRevision++;},api:(_payload,ownedSignal)=>{signal=ownedSignal;return waiting.promise;},
   rememberConversation(){commits++;},restoreDraft(){},thread:b.element('chatThread'),welcome:null,closeNav(){},setChatCanvasView(){}});
  const ui=source('chat.js'),from=ui.indexOf('  async function loadConversation(id)'),to=ui.indexOf('  async function syncConversationMessagesV101',from);
  assert(from>=0&&to>from);vm.runInNewContext(ui.slice(from,to)+'\nwindow.loadHistoryForTest=loadConversation;',b.context);
  const turn=b.window.loadHistoryForTest(7).catch(error=>error);await timer.advance(10000);
  assert.match((await turn).message,/timed out/i);assert.equal(signal.aborted,true);assert.equal(b.context.restoringConversation,0);assert.equal(commits,0);
  waiting.resolve({messages:[]});await flush();assert.equal(b.context.conversationId,1);assert.equal(commits,0);
  b.context.api=async()=>({messages:[]});await b.window.loadHistoryForTest(8);
  assert.equal(b.context.conversationId,8);assert.equal(b.context.contextReady,true);assert.equal(b.context.restoringConversation,0);assert.equal(commits,1);assert.equal(timer.pending.size,0);
 });
 await test('Chat bounds recognizers ending before startup and allows an explicit retry',async()=>{
  const b=browser(),timer=clock(b);let starts=0;
  b.window.SpeechRecognition=class{start(){starts++;queueMicrotask(()=>this.onend?.());}abort(){this.onend?.();}};
  const api=chat(b);api.enableVoice();await flush();await timer.advance(5000);
  assert.equal(starts,3);assert.equal(api.state().voiceOn,false);assert.match(b.element('chatVoiceStatus').textContent,/retry/i);
  api.enableVoice();await flush();assert.equal(starts,4);api.disableVoice();
 });
 await test('Chat bounds conversation restore without opening a microphone or losing a draft',async()=>{
  const b=browser(),timer=clock(b);let starts=0;
  b.window.STONEFELLOW_CHAT_CONTINUITY={isRestoring:()=>true,isReady:()=>false};
  b.window.SpeechRecognition=class{start(){starts++;}};
  const api=chat(b);b.element('chatInput').value='keep this draft';api.enableVoice();
  assert.match(b.element('chatVoiceStatus').textContent,/restoring/i);
  await timer.advance(15000);assert.equal(api.state().voiceOn,false);assert.equal(starts,0);
  assert.equal(b.element('chatInput').value,'keep this draft');assert.match(b.element('chatVoiceStatus').textContent,/conversation/i);
 });
 await test('Chat listens with a restored draft and only submits after new speech',async()=>{
  const b=browser(),timer=clock(b),sent=[];let native;
  b.window.SpeechRecognition=class{constructor(){native=this;}start(){this.onstart?.();}stop(){this.onend?.();}abort(){this.onend?.();}};
  b.element('chatInput').value='Please check';
  b.element('chatForm').requestSubmit=()=>sent.push(b.element('chatInput').value);
  const api=chat(b);api.enableVoice();await timer.advance(3000);
  assert.equal(api.state().voiceOn,true);assert.equal(api.state().recognitionListening,true);
  assert.equal(b.element('chatInput').value,'Please check');assert.equal(sent.length,0,'A saved draft is not automatically replayed');
  native.onresult(result('my schedule'));await timer.advance(1800);
  assert.deepEqual(sent,['Please check my schedule'],'New speech continues the visible draft without discarding it');
  api.disableVoice();
 });
 await test('Editing or clearing a draft while listening updates its dictation prefix',async()=>{
  for(const draft of ['The edited draft','']){
   const b=browser(),timer=clock(b),sent=[];let native;
   b.window.SpeechRecognition=class{constructor(){native=this;}start(){this.onstart?.();}stop(){this.onend?.();}abort(){this.onend?.();}};
   b.element('chatInput').value='An older draft';b.element('chatForm').requestSubmit=()=>sent.push(b.element('chatInput').value);
   const api=chat(b);api.enableVoice();await flush();
   b.element('chatInput').value=draft;
   for(const listener of b.element('chatInput').listeners.input||[])listener({isTrusted:true});
   await timer.advance(2000);assert.equal(sent.length,0);
   native.onresult(result('continues here'));await timer.advance(1800);
   assert.deepEqual(sent,[[draft,'continues here'].filter(Boolean).join(' ')]);api.disableVoice();
  }
 });
 await test('Typed send clears the dictation prefix before listening resumes',async()=>{
  const b=browser(),timer=clock(b);
  b.window.SpeechRecognition=class{start(){this.onstart?.();}abort(){this.onend?.();}};
  b.element('chatInput').value='Existing draft';const api=chat(b);api.enableVoice();await flush();
  assert.equal(api.state().pendingFinalTranscript,'Existing draft');
  b.element('chatInput').value='';
  await b.window.fetch('/api/chat.php',{method:'POST',body:JSON.stringify({action:'send',message:'Existing draft',input_mode:'text'})});
  await timer.advance(300);assert.equal(api.state().pendingFinalTranscript,'');
  assert.equal(api.state().recognitionListening,true);api.disableVoice();
 });
 await test('Chat starts once after restoration and ignores obsolete start/end callbacks',async()=>{
  const b=browser(),timer=clock(b);let restoring=true,starts=0;
  b.window.STONEFELLOW_CHAT_CONTINUITY={isRestoring:()=>restoring,isReady:()=>true};
  b.window.SpeechRecognition=class{start(){starts++;this.onstart?.();}abort(){queueMicrotask(()=>this.onend?.());}};
  const api=chat(b);api.enableVoice();assert.equal(starts,0);restoring=false;await timer.advance(240);
  assert.equal(starts,1);assert.equal(api.state().recognitionListening,true);
  const old=api.state().recognition;api.disableVoice();api.enableVoice();old.onstart();old.onend();
  await timer.advance(2500);assert.equal(starts,2);assert.equal(api.state().recognitionListening,true);api.disableVoice();
 });
 await test('Chat allows a slow first connection and Stop invalidates pending startup',async()=>{
  const b=browser(),timer=clock(b);let starts=0,old;
  b.window.SpeechRecognition=class{start(){starts++;old=this;b.context.setTimeout(()=>this.onstart?.(),5000);}abort(){this.onend?.();}};
  const api=chat(b);api.enableVoice();await flush();await timer.advance(5000);
  assert.equal(starts,1);assert.equal(api.state().recognitionListening,true);assert.equal(api.state().proof.recognitionStartTimeouts,0);
  api.disableVoice();api.enableVoice();await flush();const cancelled=old;api.disableVoice();await timer.advance(15000);
  cancelled.onstart();cancelled.onend();assert.equal(starts,2);assert.equal(api.state().voiceOn,false);assert.equal(api.state().recognitionListening,false);assert.equal(timer.pending.size,0);
 });
 await test('Chat startup watchdog bounds silent failures and rejects late start callbacks',async()=>{
  const b=browser(),timer=clock(b);let starts=0,old;
  b.window.SpeechRecognition=class{start(){starts++;old=this;}abort(){this.onend?.();}};
  const api=chat(b);api.enableVoice();await flush();await timer.advance(35000);
  assert.equal(starts,3);assert.equal(api.state().proof.recognitionStartTimeouts,3);assert.equal(api.state().voiceOn,false);
  old.onstart();old.onend();assert.equal(api.state().recognitionListening,false);assert.match(b.element('chatVoiceStatus').textContent,/retry/i);assert.equal(timer.pending.size,0);
 });
 await test('Chat bounds recognition constructor failures instead of leaving startup active',async()=>{
  const b=browser(),timer=clock(b);b.window.SpeechRecognition=class{constructor(){throw Error('speech service unavailable');}};
  const api=chat(b);assert.doesNotThrow(()=>api.enableVoice());await timer.advance(5000);
  assert.equal(api.state().voiceOn,false);assert.equal(api.state().recognitionStarting,false);assert.match(b.element('chatVoiceStatus').textContent,/retry/i);
 });
 for(const phrase of ['stop listening','please stop listening','turn off the microphone','Home Server offline okay you can stop listening','State reconciliation required last reconciled not yet last continuity changes zero stop zero stop listening'])await test('local capture Stop consumes '+phrase+' without a Chat request',async()=>{
  const b=browser(),timer=clock(b);let sent=0;
  b.window.SpeechRecognition=class{start(){this.onstart?.();}abort(){this.onend?.();}};
  b.element('chatForm').requestSubmit=()=>sent++;
  const api=chat(b);api.enableVoice();await flush();const old=api.state().recognition;old.onresult(result(phrase));await timer.advance(2500);
  assert.equal(sent,0);assert.equal(api.state().voiceOn,false);assert.equal(api.state().recognitionListening,false);assert.equal(b.storage.get('stonefellow:voice-mode:1'),'0');
  old.onresult(result('late echo'));await timer.advance(2500);assert.equal(sent,0);
 });
 for(const phrase of ["don't stop listening",'how do I stop listening','explain the stop listening command'])await test('discussion of '+phrase+' does not turn the microphone off',async()=>{
  const b=browser();clock(b);const api=chat(b);api.seed();api.submitVoiceTranscript(phrase);
  assert.equal(api.state().voiceOn,true);assert.equal(api.state().proof.stopControls,0);api.disableVoice();
 });
 for(const phrase of ['stop','Home Server offline okay you can stop','stop listening','State reconciliation required last reconciled not yet stop listening','new question please'])await test('Chat speech interruption handles '+phrase+' once',async()=>{
  const b=browser(),timer=clock(b),natives=[],spoken=[],sent=[];
  b.window.SpeechRecognition=class{constructor(){natives.push(this);}start(){this.onstart?.({});}stop(){queueMicrotask(()=>this.onend?.({}));}abort(){queueMicrotask(()=>this.onend?.({}));}};
  b.window.speechSynthesis.speak=u=>spoken.push(u);b.element('chatForm').requestSubmit=()=>sent.push(b.element('chatInput').value);
  const api=chat(b);api.seed();api.speakAnswer('The answer continues here');spoken[0].onstart();assert.equal(natives.length,1);
  natives[0].onresult(result(phrase));await flush();
  assert.equal(api.state().speaking,false);
  if(phrase.endsWith('stop listening')){
   assert.equal(api.state().voiceOn,false);assert.equal(api.state().recognitionListening,false);assert.equal(sent.length,0);
   assert.equal(api.state().proof.stopControls,1);assert.equal(b.events.filter(e=>e.type==='stonefellow:agent-stop').length,1);
  }else if(phrase!=='new question please'){
   assert.equal(api.state().voiceOn,true);
   assert.equal(api.state().proof.stopControls,1);assert.equal(b.events.filter(e=>e.type==='stonefellow:agent-stop').length,1);assert.equal(sent.length,0);
   await timer.advance(360);assert.equal(api.state().recognition,natives[1]);assert.equal(api.state().recognitionListening,true);
  }else{assert.equal(api.state().voiceOn,true);assert.deepEqual(sent,[phrase]);assert.equal(api.state().proof.interruptions,1);}
  api.disableVoice();await timer.advance(1000);assert.equal(api.state().recognitionListening,false);
 });
 await test('Chat accepts non-Latin speech and distinct repeated native finals',async()=>{
  const b=browser();clock(b);const api=chat(b);api.seed();const a=api.recognize();a.onresult(result('你好 世界'));a.onresult(result('你好 世界'));assert.equal(api.state().pendingFinalTranscript,'你好 世界');
  const next=api.recognize();next.onresult(result('你好 世界'));assert.equal(api.state().pendingFinalTranscript,'你好 世界 你好 世界');api.disableVoice();
 });
 await test('cancelled Chat request cannot clear, speak or change a newer turn',async()=>{
  const b=browser();clock(b);const a=deferred(),next=deferred();let calls=0;b.window.fetch=()=>++calls===1?a.promise:next.promise;const api=chat(b);api.seed();
  const first=api.handleVoiceApiFetch('/chat',{},{});api.interruptResponse();const second=api.handleVoiceApiFetch('/chat',{},{});const owned=api.state().activeRequest;
  assert.equal((await first).status,499);a.resolve(new Response(JSON.stringify({ok:true,answer:'obsolete',conversation_id:900})));await flush();assert.equal(api.state().activeRequest,owned);assert(api.state().processing);assert.equal(api.state().lastConversationId,0);
  next.resolve(new Response(JSON.stringify({ok:true,answer:'',conversation_id:42})));await second;assert.equal(api.state().lastConversationId,42);assert.equal(api.state().processing,false);api.disableVoice();
 });
 await test('failed and timed-out Chat requests release processing for retry',async()=>{
  for(const timeout of [false,true]){const b=browser(),timer=clock(b);b.window.fetch=timeout?()=>new Promise(()=>{}):()=>Promise.reject(Error('offline'));const api=chat(b);api.seed();const turn=api.handleVoiceApiFetch('/chat',{},{});if(timeout)await timer.advance(120000);assert.equal((await turn).status,502);assert.equal(api.state().activeRequest,null);assert.equal(api.state().processing,false);api.disableVoice();}
 });
 await test('Chat submit exception releases waiting and stalled playback pauses exactly once',async()=>{
  const b=browser(),timer=clock(b),spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);const api=chat(b);api.seed();b.element('chatForm').requestSubmit=()=>{throw Error('form unavailable');};api.submitVoiceTranscript('مرحبا');assert.equal(api.state().processing,false);assert(timer.pending.size>0);
  api.speakAnswer('short answer');assert.equal(spoken.length,1);spoken[0].onstart();await timer.advance(15000);assert.equal(api.state().speaking,false);assert.equal(api.state().voiceOn,false);const ends=api.state().proof.speechEnds;spoken[0].onend();assert.equal(api.state().proof.speechEnds,ends);api.disableVoice();
 });
 await test('stream cancellation settles a stalled reader and ignores late deltas',async()=>{
  const b=browser();clock(b);let stream,cancelled=0;b.window.fetch=async()=>new Response(new ReadableStream({start(controller){stream=controller;},cancel(){cancelled++;}}));
  b.window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});const api=chat(b);api.seed();const turn=api.handleVoiceApiFetch('/chat',{},{});await flush();api.interruptResponse();assert.equal((await turn).status,499);assert.equal(cancelled,1);assert.equal(api.state().activeRequest,null);api.disableVoice();
 });
 await test('stream completion, provider failure and stalled deadline settle the current turn',async()=>{
  for(const kind of ['success','failure','deadline']){const b=browser(),timer=clock(b);b.window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});b.window.fetch=async()=>kind==='deadline'?new Response(new ReadableStream({start(){}})):new Response(JSON.stringify({type:'done',data:{ok:kind==='success',error:'provider offline',answer:'',conversation_id:42}})+'\n');const api=chat(b);api.seed();const turn=api.handleVoiceApiFetch('/chat',{},{});if(kind==='deadline')await timer.advance(120000);const response=await turn;assert.equal(response.status,kind==='success'?200:502);assert.equal(api.state().activeRequest,null);assert.equal(api.state().processing,false);api.disableVoice();}
 });
 await test('canonical cancellation aborts voice transport and rejects late provider completion',async()=>{
  for(const fast of [false,true]){
   const b=browser();clock(b);const waiting=deferred(),owner=new AbortController();let signal;
   b.window.fetch=(_url,init)=>{signal=init.signal;return waiting.promise;};
   if(fast)b.window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});
   const api=chat(b);api.seed();const turn=api.handleVoiceApiFetch('/chat',{signal:owner.signal},{});await flush();owner.abort();await flush();
   assert.equal(signal.aborted,true,'voice must honor its canonical chat owner');
   assert.equal((await turn).status,499);assert.equal(api.state().processing,false);
   waiting.resolve(new Response(JSON.stringify({ok:true,answer:'obsolete',conversation_id:999})));await flush();assert.equal(api.state().lastConversationId,0);api.disableVoice();
  }
 });
 await test('incomplete provider streams fail without accepting or speaking partial answers',async()=>{
  for(const body of ['',JSON.stringify({type:'delta',delta:'partial answer'})+'\n',JSON.stringify({type:'done',data:{answer:'missing success flag'}})+'\n']){
   const b=browser();clock(b);const spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);
   b.window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});b.window.fetch=async()=>new Response(body);
   const api=chat(b);api.seed();const response=await api.handleVoiceApiFetch('/chat',{},{});
   assert.equal(response.status,502,'EOF alone cannot certify provider completion');assert.equal(api.state().proof.apiSuccess,0);assert.equal(spoken.length,0);api.disableVoice();
  }
 });
 await test('canonical Stop cancels pending context preparation without a provider request',async()=>{
  const b=browser();clock(b);const owner=new AbortController(),context=deferred();let calls=0;
  b.window.fetch=async()=>{calls++;return new Response('{}');};b.window.StonefellowAgentContext={refresh:()=>context.promise};
  const api=chat(b);api.seed();b.element('chatForm').requestSubmit=()=>{b.pending=b.window.fetch('/api/chat.php',{method:'POST',signal:owner.signal,body:JSON.stringify({action:'send',message:'hello'})}).catch(error=>error);};
  api.submitVoiceTranscript('hello');owner.abort();await b.pending;assert.equal(api.state().voiceOn,false);assert.equal(api.state().processing,false);
  context.resolve({});await flush();assert.equal(calls,0);api.disableVoice();
 });
 await test('fast voice preserves authentication and permission status for the canonical owner',async()=>{
  for(const status of [401,403,429,503]){
   const b=browser();clock(b);b.window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){},stop(){}});
   b.window.fetch=async()=>new Response(JSON.stringify({type:'error',error:'Unavailable'})+'\n',{status});
   const api=chat(b);api.seed();const response=await api.handleVoiceApiFetch('/chat',{},{});assert.equal(response.status,status);assert.equal(api.state().proof.apiSuccess,0);api.disableVoice();
  }
  const endpoint=fs.readFileSync(root+'api/chat-stream-v121.php','utf8');assert.match(endpoint,/if\(!\$user\)\{http_response_code\(401\)/);assert.match(endpoint,/if\(!has_permission\('chat.access',\$user\)\)\{http_response_code\(403\)/);
 });
 function premium(b){
  b.window.location=b.context.location;
  b.context.Audio||=class{constructor(){this.src='';}load(){}pause(){}removeAttribute(){this.src='';}play(){queueMicrotask(()=>this.onplaying?.());return Promise.resolve();}};
  load('premium-voice-v117.js',b);return b.window.StonefellowPremiumVoiceV122({agentEndpoint:'/api/chat.php',csrf:'test'});
 }
 await test('premium warm timeout is bounded, shared, retryable and ignores late readiness',async()=>{
  const b=browser(),timer=clock(b),waiting=deferred();let calls=0,signal;
  b.window.fetch=(_url,init)=>{calls++;signal=init.signal;return waiting.promise;};const owner=premium(b);const ready=owner.warm();
  await timer.advance(12000);assert.equal(await ready,false);assert.equal(calls,1);assert.equal(signal.aborted,true);
  waiting.resolve(response({ready:true,verified:true}));await flush();assert.equal(b.window.STONEFELLOW_PREMIUM_VOICE_V122.verifiedReady,false);
  b.window.fetch=async()=>response({ready:true,verified:true});assert.equal(await owner.warm(),true);owner.stop();
 });
 await test('premium ticket timeout and stalled audible playback settle exactly once',async()=>{
  for(const stall of ['ticket','playback']){
   const b=browser(),timer=clock(b);let errors=0,ends=0,signal;
   b.window.fetch=async(_url,init)=>{const body=JSON.parse(init.body||'{}');if(body.action==='warm')return response({ready:true});if(body.action==='ticket'){signal=init.signal;if(stall==='ticket')return new Promise(()=>{});return response({ok:true,stream_url:'https://vp3.test/audio'});}return {ok:true,headers:{get:()=> 'audio/mpeg'},blob:async()=>new Blob(['audio'])};};
   const owner=premium(b);await owner.warm();const session=owner.createStream({onError(){errors++;},onEnd(){ends++;}});const started=session.started.catch(()=>false);session.push('Hello. ');session.end();await flush();await flush();
   await timer.advance(stall==='ticket'?12000:20000);assert.equal(await session.done,false);assert.equal(await started,stall==='playback');assert.equal(errors,stall==='playback'?1:0);assert.equal(ends,0);if(stall==='ticket')assert(signal.aborted);owner.stop();
  }
 });
 await test('audio unlock timeout rejects late success without reviving playback',async()=>{
  const b=browser(),timer=clock(b),waiting=deferred();b.window.fetch=async()=>response({ready:true});
  b.context.Audio=class{load(){}pause(){}removeAttribute(){}play(){return waiting.promise;}};
  const owner=premium(b);await owner.warm();const unlocked=owner.unlock();await timer.advance(8000);assert.equal(await unlocked,false);
  waiting.resolve();await flush();assert.equal(owner.isUnlocked(),false);assert.equal(b.window.STONEFELLOW_PREMIUM_VOICE_V122.unlockSuccesses,0);owner.stop();
 });
 await test('silent form rejection recovers; Stop during context preparation never sends',async()=>{
  const b=browser(),timer=clock(b),context=deferred();let requests=0;b.window.fetch=async()=>{requests++;return new Response('{}');};b.window.StonefellowAgentContext={refresh:()=>context.promise,snapshot:()=>({})};const api=chat(b);api.seed();b.element('chatForm').requestSubmit=()=>{};api.submitVoiceTranscript('hello again');await timer.advance(2200);assert.equal(api.state().processing,false);
  b.element('chatForm').requestSubmit=()=>{b.pendingSend=b.window.fetch('/api/chat.php',{method:'POST',body:JSON.stringify({action:'send',message:'hello'})});};api.submitVoiceTranscript('hello');api.disableVoice();context.resolve({});assert.equal((await b.pendingSend).status,499);assert.equal(requests,0);
 });
 await test('capture controller preserves repeats and Unicode while rejecting replay',async()=>{
  const b=browser();clock(b);b.window.STONEFELLOW_ARTIST_LISTENING_V172={endpoint:'/api/artist-listening-v172.php',csrf:'test',userId:1};load('artist-listening-realtime.js',b);load('artist-listening.js',b,'window.Test={state,handleFinal,seed:()=>{state.active=true;state.session={id:41,status:"active",segments:[]};state.captureStartedAt=Date.now();}};');const api=b.window.Test;api.seed();api.handleFinal('مرحبا بالعالم',.9,{runId:'a',resultIndex:0});api.handleFinal('مرحبا بالعالم',.9,{runId:'a',resultIndex:1});api.handleFinal('مرحبا بالعالم',.9,{runId:'a',resultIndex:1});assert.equal(api.state.pending.length,2);assert.equal(api.state.pending[0].text,'مرحبا بالعالم');
 });
 await test('editor accepts single-character Unicode and distinct repeated turns',async()=>{
  const b=browser(),timer=clock(b),recognizers=[],heard=[];b.window.SpeechRecognition=class{constructor(){recognizers.push(this);}start(){this.onstart?.();}stop(){}abort(){}};
  b.context.navigator.mediaDevices.getUserMedia=async()=>stream();load('conversation-voice-v122.js',b);const owner=b.window.StonefellowConversationVoiceV122.create({userId:1,onTranscript:text=>heard.push(text)});owner.setEnabled(true,{persist:false,immediate:true});
  const first=recognizers[0];first.onresult(result('中'));first.onresult(result('中'));await timer.advance(1800);assert.equal(heard[0],'中');await timer.advance(200);recognizers.at(-1).onresult(result('中'));await timer.advance(1800);assert.deepEqual(heard,['中','中']);owner.destroy();
 });
}else{
 function voice(b){load('chat-enhancements.js',b,'window.Test={autoSubmitSpeech,speakAgentReply,stopConversationMode,startBrowserListening,recognition:()=>recognition,seed:(path="browser")=>{conversationMode=true;voicePath={stt:"browser",tts:path};},context:value=>{playbackContext=value;},state:()=>({awaitingAgent,waitingRequestId,speaking,conversationMode,outputGeneration})};');return b.window.Test;}
 function brain(b){load('brain.js',b,'window.BrainTest={submitChatTurn,leaveConversation,state:()=>({pendingChatTurn,activeConversationId})};');return b.window.BrainTest;}
 const submitEvent=b=>({preventDefault(){},target:b.element('chatForm')});
 const emitTurn=(b,requestId,status,reply='')=>b.emit('homeserver:chat-turn',{detail:{requestId,status,reply}});
 function standaloneTurn(b,api,id){b.element('chatForm').requestSubmit=()=>emitTurn(b,id,'started');api.autoSubmitSpeech('new speech');}
 await test('identical replies on distinct turns speak; duplicate completion is ignored',async()=>{
  const b=browser(),timer=clock(b),spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);const api=voice(b);api.seed();
  standaloneTurn(b,api,1);emitTurn(b,1,'completed','same reply');emitTurn(b,1,'completed','same reply');assert.equal(spoken.length,1);spoken[0].onend();
  standaloneTurn(b,api,2);emitTurn(b,2,'completed','same reply');assert.equal(spoken.length,2);assert(api.state().speaking);assert.equal(api.state().awaitingAgent,false);api.stopConversationMode('');
 });
 await test('failed, empty and rejected submissions release waiting for retry',async()=>{
  const b=browser(),timer=clock(b);const api=voice(b);api.seed();for(const [id,status] of [[1,'failed'],[2,'completed'],[3,'cancelled']]){standaloneTurn(b,api,id);emitTurn(b,id,status);assert.equal(api.state().awaitingAgent,false);}
  b.element('chatForm').requestSubmit=()=>{throw Error('blocked');};api.autoSubmitSpeech('again');assert.equal(api.state().awaitingAgent,false);b.element('chatForm').requestSubmit=()=>{};api.autoSubmitSpeech('again');assert.equal(api.state().awaitingAgent,false);assert(timer.pending.size>0);api.stopConversationMode('');
 });
 await test('old completion and old speech callbacks are inert after Stop and restart',async()=>{
  const b=browser();clock(b);const spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);const api=voice(b);api.seed();standaloneTurn(b,api,1);api.stopConversationMode('');api.seed();standaloneTurn(b,api,2);emitTurn(b,1,'completed','obsolete');assert.equal(spoken.length,0);emitTurn(b,2,'completed','current');const old=spoken[0];api.stopConversationMode('');api.seed();api.speakAgentReply('fresh');old.onend();old.onerror();assert(api.state().speaking);api.stopConversationMode('');
 });
 await test('late local TTS success and failure cannot change a newer output',async()=>{
  for(const fail of [false,true]){const b=browser();clock(b);const waiting=deferred(),spoken=[];let signal;b.window.fetch=(_url,init)=>{signal=init.signal;return waiting.promise;};b.window.speechSynthesis.speak=u=>spoken.push(u);const api=voice(b);api.seed('local');api.speakAgentReply('old');api.stopConversationMode('');assert(signal.aborted);api.seed('browser');api.speakAgentReply('fresh');if(fail)waiting.reject(Error('old failure'));else waiting.resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await flush();assert.equal(spoken.length,1);assert(api.state().speaking);api.stopConversationMode('');}
 });
 await test('Strict Local blocks fallback and playback watchdog resumes listening',async()=>{
  const b=browser(),timer=clock(b),spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);const api=voice(b);api.seed();b.element('strictLocalVoice').checked=true;api.speakAgentReply('private');assert.equal(spoken.length,0);assert.equal(api.state().conversationMode,false);
  b.element('strictLocalVoice').checked=false;api.seed();api.speakAgentReply('hello');await timer.advance(15000);assert.equal(api.state().speaking,false);api.stopConversationMode('');
 });
 await test('browser recognition aggregates new final indices and stops on startup/device failure',async()=>{
  const b=browser(),timer=clock(b);const api=voice(b);api.seed();const starts=[];b.element('chatForm').requestSubmit=()=>{starts.push(b.element('chatInput').value);emitTurn(b,1,'started');};api.startBrowserListening();const listener=api.recognition();listener.onstart();listener.onresult(result('你好',0));listener.onresult(result('世界',1));await flush();await timer.advance(0);assert.equal(starts[0],'你好 世界');api.stopConversationMode('');api.seed();api.startBrowserListening();api.recognition().onerror({error:'audio-capture'});assert.equal(api.state().conversationMode,false);api.seed();api.startBrowserListening();await timer.advance(5000);assert.equal(api.state().conversationMode,false);
 });
 await test('canonical owner emits actual reply despite failed history refresh',async()=>{
  const b=browser();clock(b);const spoken=[];b.window.speechSynthesis.speak=u=>spoken.push(u);b.window.fetch=async url=>url==='/api/v1/control/chat'?response({reply:'actual answer',conversation_id:'c1',run_id:'r1'}):Promise.reject(Error('history offline'));
  const requestOwner=brain(b),api=voice(b);api.seed();b.element('chatForm').requestSubmit=()=>requestOwner.submitChatTurn(submitEvent(b));api.autoSubmitSpeech('hello');await flush();assert.equal(spoken[0].text,'actual answer');assert.equal(requestOwner.state().pendingChatTurn,null);assert.equal(api.state().awaitingAgent,false);assert.equal(b.element('chatInput').disabled,false);assert.equal(b.events.filter(e=>e.type==='homeserver:chat-turn'&&e.detail.status==='failed').length,0);api.stopConversationMode('');
 });
 await test('canonical owner rejects parallel submits and settles cancellation despite late transport',async()=>{
  const b=browser();clock(b);const a=deferred(),next=deferred();let calls=0;b.window.fetch=url=>url==='/api/v1/control/chat'?(++calls===1?a.promise:next.promise):Promise.reject(Error('history offline'));const owner=brain(b);b.element('chatInput').value='one';const first=owner.submitChatTurn(submitEvent(b));b.element('chatInput').value='duplicate';await owner.submitChatTurn(submitEvent(b));assert.equal(calls,1);b.window.HomeServerChatTurn.cancel(1);await first;b.element('chatInput').value='next';const second=owner.submitChatTurn(submitEvent(b));a.resolve(response({reply:'obsolete',conversation_id:'old'}));await flush();assert.equal(owner.state().pendingChatTurn.id,2);next.resolve(response({reply:'new',conversation_id:'new'}));await second;assert.equal(owner.state().activeConversationId,'new');assert.equal(b.events.filter(e=>e.type==='homeserver:chat-turn'&&e.detail.status==='completed').length,1);
 });
 await test('canonical owner failure and timeout enable composer and emit one failure',async()=>{
  for(const timeout of [false,true]){const b=browser(),timer=clock(b);b.window.fetch=timeout?()=>new Promise(()=>{}):()=>Promise.reject(Error('offline'));const owner=brain(b);b.element('chatInput').value='hello';const turn=owner.submitChatTurn(submitEvent(b));if(timeout)await timer.advance(120000);await turn;assert.equal(owner.state().pendingChatTurn,null);assert.equal(b.element('chatInput').disabled,false);assert.equal(b.events.filter(e=>e.type==='homeserver:chat-turn'&&e.detail.status==='failed').length,1);}
 });
 await test('older history refresh cannot overwrite a later reply',async()=>{
  const b=browser();clock(b);const history=deferred();let calls=0;b.window.fetch=async url=>{if(url==='/api/v1/control/chat')return response({reply:'reply '+(++calls),conversation_id:'c1'});if(url==='/api/v1/control/conversations/c1')return calls===1?history.promise:response({conversation:{title:'new'},messages:[{role:'assistant',content:'new history'}]});return response({items:[]});};const owner=brain(b);b.element('chatInput').value='one';await owner.submitChatTurn(submitEvent(b));b.element('chatInput').value='two';await owner.submitChatTurn(submitEvent(b));await flush();const current=b.element('chatMessages').innerHTML;assert(current.includes('new history'));history.resolve(response({conversation:{title:'old'},messages:[{role:'assistant',content:'old history'}]}));await flush();assert.equal(b.element('chatMessages').innerHTML,current);
 });
 await test('voice Stop cancels its owned turn and navigation prevents late completion',async()=>{
  const b=browser();clock(b);const waiting=deferred();let signal;b.window.fetch=(_url,init)=>{signal=init.signal;return waiting.promise;};const owner=brain(b),api=voice(b);api.seed();b.element('chatForm').requestSubmit=()=>owner.submitChatTurn(submitEvent(b));api.autoSubmitSpeech('hello');assert(api.state().awaitingAgent);api.stopConversationMode('');assert(signal.aborted);await flush();assert.equal(owner.state().pendingChatTurn,null);waiting.resolve(response({reply:'late',conversation_id:'old'}));await flush();assert.equal(owner.state().activeConversationId,null);
 });
}
console.log(`LISTENING_SECTION2=PASS (${checks.length} behavioral cases, ${cloud?'Cloud':'HomeServer'})`);
