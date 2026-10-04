// Execute the real meeting controller against Chromium DOM and injected transport races.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source=await fs.readFile(new URL('../video-meetings-v1800.js',import.meta.url),'utf8');
const hook='window.MeetingTest={renderTranscriptSegment,pollTranscript,join,leave,endMeeting,attachTrack,detachTrack,cardId,changeLocalMedia,startTranscriptPolling,stopTranscriptPolling,state:()=>({room,connected,joining,reconnecting,lastTranscriptId,pollGeneration}),seed:target=>{room=target;connected=true;joining=false;ending=false;},};';
const code=source.replace(/\}\)\(\);\s*$/,hook+'\n})();');
const agentSource=(await fs.readFile(new URL('../video-meetings-live-agent-v18110.js',import.meta.url),'utf8')).replace(/\}\)\(\);\s*$/,"window.AgentTest={render,state:()=>state};ensurePane=()=>{};renderMessages=()=>{};\n})();");
assert.notEqual(code,source);
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let passed=0;
async function test(name,run){
 const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 try{
  await page.setContent('<!doctype html><div class="video-meeting-stage"><div id="meetingLobby"><input id="meetingDisplayName"><input id="joinMic" type="checkbox" checked><input id="joinCamera" type="checkbox" checked><button id="meetingJoin">Join</button></div><div id="meetingRoomError"></div><div id="participantGrid"></div><span id="meetingParticipantCount"></span><button id="meetingMic"></button><button id="meetingCamera"></button><button id="meetingShare"></button><div id="meetingTranscriptList"></div><span id="meetingTranscriptBridgeState"></span></div><div id="meetingAgentPanel"></div>');
  await page.evaluate(()=>{
   window.defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
   window.tick=()=>new Promise(resolve=>setTimeout(resolve,0));
   window.check=(value,label)=>{if(!value)throw Error(label);};
   window.responses=data=>({ok:true,status:200,json:async()=>({ok:true,...data})});
   window.calls=[];window.events=[];window.rooms=[];
   window.fetch=async(url,init)=>{const body=new URLSearchParams(init.body);calls.push({url,action:body.get('action'),signal:init.signal});return responses(url==='/token'?{server_url:'wss://test.invalid',participant_token:'test-token'}:{status:'live'});};
   window.VP3Meeting={meeting:'test',tokenEndpoint:'/token',presenceEndpoint:'/presence',transcriptEndpoint:'/transcript',transcriptionEnabled:false,meetingsUrl:'/meetings',isOrganizer:true};
   window.confirm=()=>true;
   window.addEventListener('vp3:meeting-transcript-updated',event=>events.push(event.detail));
   window.makeTrack=(kind='video',source='camera')=>({kind,source,stops:0,attachments:[],attach(){const element=document.createElement(kind);this.attachments.push(element);return element;},detach(){const list=this.attachments;this.attachments=[];return list;},stop(){this.stops++;}});
   const local=()=>({identity:'local',name:'Owner',trackPublications:new Map(),isMicrophoneEnabled:true,isCameraEnabled:true,isScreenShareEnabled:false,async setMicrophoneEnabled(on){this.isMicrophoneEnabled=on;},async setCameraEnabled(on){this.isCameraEnabled=on;},async setScreenShareEnabled(on){this.isScreenShareEnabled=on;}});
   window.LivekitClient={Track:{Kind:{Video:'video',Audio:'audio'},Source:{ScreenShare:'screen',ScreenShareAudio:'screenAudio'}},RoomEvent:Object.fromEntries(['ParticipantConnected','ParticipantDisconnected','TrackSubscribed','TrackUnsubscribed','ActiveSpeakersChanged','LocalTrackPublished','LocalTrackUnpublished','Reconnecting','SignalReconnecting','Reconnected','Disconnected'].map(name=>[name,name])),Room:class{
    constructor(){this.listeners=new Map();this.localParticipant=local();this.remoteParticipants=new Map();this.numParticipants=1;this.disconnects=0;rooms.push(this);}
    on(event,handler){this.listeners.set(event,handler);}
    emit(event,...args){this.listeners.get(event)?.(...args);}
    async connect(){}async startAudio(){}async disconnect(){this.disconnects++;}
    getActiveDevice(){return '';}
    static async getLocalDevices(){return [];}
   }};
  });
  await page.evaluate(source=>{window.agentSource=source;},agentSource);
  await page.addScriptTag({content:code});await page.evaluate(run);assert.deepEqual(errors,[]);passed++;console.log('PASS '+name);
 }finally{await page.close();}
}
try{
 await test('disconnect during microphone permission invalidates late join and stops capture',async()=>{
  const pending=defer(),started=defer();let cameraCalls=0;const track=makeTrack('audio','microphone');
  LivekitClient.Room.prototype.connect=async function(){this.localParticipant.setMicrophoneEnabled=async()=>{started.resolve();await pending.promise;this.localParticipant.trackPublications.set('mic',{track});};this.localParticipant.setCameraEnabled=async()=>{cameraCalls++;};};
  const joining=MeetingTest.join();await started.promise;rooms[0].emit('Disconnected');await tick();pending.resolve();await joining;
  check(cameraCalls===0&&track.stops>0,'late camera/capture');check(!MeetingTest.state().connected&&!MeetingTest.state().joining,'disconnect state');check(!calls.some(call=>call.action==='join'),'late presence join');
 });
 await test('stopped polling discards delayed transcript and does not restart',async()=>{
  VP3Meeting.transcriptionEnabled=true;const pending=defer();window.fetch=async()=>pending.promise;
  MeetingTest.seed(new LivekitClient.Room());MeetingTest.startTranscriptPolling();const before=MeetingTest.state().pollGeneration;MeetingTest.stopTranscriptPolling();
  pending.resolve(responses({segments:[{id:99,transcript_text:'stale'}],state:{ready:true}}));await tick();
  check(MeetingTest.state().pollGeneration>before&&MeetingTest.state().lastTranscriptId===0,'stale cursor');check(!document.querySelector('.meeting-transcript-row')&&!events.length,'stale transcript');
 });
 await test('reconnect preserves room and resumes status polling without duplicate media',async()=>{
  await MeetingTest.join();const target=rooms[0],track=makeTrack(),remote={identity:'remote',name:'Guest',trackPublications:new Map([['camera',{track}]])};target.remoteParticipants.set('remote',remote);target.emit('TrackSubscribed',track,{},remote);
  target.emit('Reconnecting');check(MeetingTest.state().reconnecting,'missing reconnect state');let changes=0;await MeetingTest.changeLocalMedia(async()=>{changes++;});check(!changes,'media change during reconnect');
  target.emit('Reconnected');await tick();check(!MeetingTest.state().reconnecting&&MeetingTest.state().room===target,'room lost');check(document.querySelectorAll('video').length===1,'duplicate media');check(calls.filter(call=>call.action==='status').length>=2,'status polling not resumed');await MeetingTest.leave();
 });
 await test('participant card identities cannot collide after encoding',async()=>{
  const a={identity:'a/b',name:'A'},b={identity:'a_b',name:'B'};MeetingTest.attachTrack(makeTrack(),a);MeetingTest.attachTrack(makeTrack(),b);
  check(MeetingTest.cardId(a.identity)!==MeetingTest.cardId(b.identity),'colliding identities');check(document.querySelectorAll('.video-participant-card').length===2,'collapsed participants');
 });
 await test('track attachment is idempotent and screen audio survives video unsubscribe',async()=>{
  const person={identity:'p',name:'Guest'},video=makeTrack('video','screen'),audio=makeTrack('audio','screenAudio');
  MeetingTest.attachTrack(video,person);MeetingTest.attachTrack(video,person);MeetingTest.attachTrack(audio,person);
  check(document.querySelectorAll('video').length===1,'duplicate attachment');MeetingTest.detachTrack(video,person);
  check(document.getElementById(MeetingTest.cardId('p',true))?.querySelector('audio'),'screen audio removed with video');MeetingTest.detachTrack(audio,person);check(!document.getElementById(MeetingTest.cardId('p',true)),'empty screen card');
 });
 await test('meeting closure stops media even with transcription disabled',async()=>{
  await MeetingTest.join();const target=rooms[0],track=makeTrack('audio','microphone');target.localParticipant.trackPublications.set('mic',{track});window.fetch=async()=>responses({status:'ended'});
  MeetingTest.startTranscriptPolling();await tick();await tick();check(!MeetingTest.state().connected&&track.stops>0&&target.disconnects>0,'closed meeting still captures');
 });
 await test('revoked access closes capture while transient server errors preserve media',async()=>{
  await MeetingTest.join();window.fetch=async()=>({ok:false,status:503,json:async()=>({ok:false,error:'temporary'})});MeetingTest.startTranscriptPolling();await tick();check(MeetingTest.state().connected,'transient error disconnected media');
  window.fetch=async()=>({ok:false,status:403,json:async()=>({ok:false,error:'revoked'})});MeetingTest.startTranscriptPolling();await tick();await tick();check(!MeetingTest.state().connected,'revocation retained media');
 });
 await test('transcript segments sort and deduplicate before advancing cursor',async()=>{
  await MeetingTest.join();VP3Meeting.transcriptionEnabled=true;window.fetch=async()=>responses({segments:[{id:3,transcript_text:'three'},{id:1,transcript_text:'one'},{id:3,transcript_text:'duplicate'},{id:2,transcript_text:'two'}]});
  MeetingTest.startTranscriptPolling();await tick();check([...document.querySelectorAll('.meeting-transcript-row')].map(row=>row.dataset.segmentId).join(',')==='1,2,3','missing or duplicate segment');check(MeetingTest.state().lastTranscriptId===3,'cursor');await MeetingTest.leave();
 });
 await test('media controls serialize and queued changes cannot run after leave',async()=>{
  await MeetingTest.join();const pending=defer();let changes=0;const a=MeetingTest.changeLocalMedia(async()=>{changes++;await pending.promise;}),b=MeetingTest.changeLocalMedia(async()=>{changes++;});
  check(changes===1,'concurrent media operations');await MeetingTest.leave();pending.resolve();await Promise.all([a,b]);check(changes===1,'queued capture after leave');
 });
 await test('request deadline also bounds a transport that ignores AbortSignal',async()=>{
  let expire;const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>ms===15000?(expire=fn,123456):original(fn,ms,...args);window.fetch=()=>new Promise(()=>{});
  const joining=MeetingTest.join();check(Boolean(expire),'no request deadline');expire();await joining;check(!MeetingTest.state().joining&&!MeetingTest.state().connected,'timed out join stuck');check(document.getElementById('meetingRoomError').textContent.includes('timed out'),'deadline error');
 });
 await test('pagehide aborts outstanding reads and releases owned capture',async()=>{
  await MeetingTest.join();const target=rooms[0],track=makeTrack('audio','microphone');target.localParticipant.trackPublications.set('mic',{track});const pending=defer();let signal;window.fetch=async(_url,init)=>{signal=init.signal;return pending.promise;};
  MeetingTest.startTranscriptPolling();window.dispatchEvent(new Event('pagehide'));await tick();check(signal.aborted&&track.stops>0&&!MeetingTest.state().connected,'pagehide resources');pending.resolve(responses({status:'live'}));await tick();
 });
 await test('late Agent response cannot revive participation after leaving',async()=>{
  VP3Meeting.intelligenceEndpoint='/api/video-meeting-intelligence.php';const script=document.createElement('script');script.textContent=agentSource;document.head.appendChild(script);
  const pending=defer();window.fetch=async()=>pending.promise;VP3MeetingLiveAgent18110.init();window.dispatchEvent(new CustomEvent('vp3:meeting-left'));
  pending.resolve(responses({state:{active:true,available:true}}));await tick();check(AgentTest.state().active===false&&AgentTest.state().available===false,'Agent revived');
 });
 await test('hung provider disconnect cannot hold local leave indefinitely',async()=>{
  await MeetingTest.join();const target=rooms[0],track=makeTrack('audio','microphone');target.localParticipant.trackPublications.set('mic',{track});target.disconnect=()=>new Promise(()=>{});
  let expire;const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>ms===5000?(expire=fn,123456):original(fn,ms,...args);
  const leaving=MeetingTest.leave();check(track.stops>0&&!MeetingTest.state().connected,'capture not stopped before disconnect');check(Boolean(expire),'no disconnect deadline');expire();await leaving;
 });
 await test('passive device enumeration does not request capture permissions',async()=>{
  for(const id of ['meetingMicDevice','meetingCameraDevice','meetingSpeakerDevice']){const select=document.createElement('select');select.id=id;document.body.appendChild(select);}
  const permissions=[];LivekitClient.Room.getLocalDevices=async(_kind,requestPermissions)=>{permissions.push(requestPermissions);return [];};await MeetingTest.join();await tick();
  check(permissions.length===3&&permissions.every(value=>value===false),'implicit capture permission');await MeetingTest.leave();
 });
 await test('provider end before HTTP acknowledgement still signals final review once',async()=>{
  await MeetingTest.join();let endedEvents=0;window.addEventListener('vp3:meeting-ended',()=>endedEvents++);const pending=defer();window.fetch=async(_url,init)=>new URLSearchParams(init.body).get('action')==='end'?pending.promise:responses({status:'live'});
  LivekitClient.DisconnectReason={ROOM_DELETED:5};const ending=MeetingTest.endMeeting();rooms[0].emit('Disconnected',5);await ending;await tick();
  check(!MeetingTest.state().connected&&endedEvents===1&&!document.getElementById('meetingRejoin'),'provider end misreported as reconnect');pending.resolve(responses({status:'ended'}));await tick();check(endedEvents===1,'duplicate final review');
 });
 await test('review annotations render safely and organizer correction sends current revision',async()=>{
  window.prompt=()=>'<img src=x onerror=alert(1)>';const sent=[];window.fetch=async(_url,init)=>{sent.push(Object.fromEntries(new URLSearchParams(init.body)));return responses({correction_revision:3});};
  MeetingTest.renderTranscriptSegment({id:1,speaker_name:'<script>bad</script>',transcript_text:'<b>speech</b>',overlap:true,correction_revision:2,speaker_attribution:{source:'manual_correction',speaker_identity_verified:false}});
  const row=document.querySelector('.meeting-transcript-row');check(!row.querySelector('script,img,b'),'unsafe transcript HTML');check(row.textContent.includes('Overlapping speech')&&row.textContent.includes('Owner annotation'),'evidence badge');
  row.querySelector('button').click();await tick();check(sent[0].action==='correct_speaker'&&sent[0].segment_id==='1'&&sent[0].revision==='2','correction revision binding');check(sent[0].speaker_label.startsWith('<img'),'annotation changed before canonical validation');
 });
 await test('long meeting pagination preserves prefix hashes and replaces corrected rows',async()=>{
  VP3Meeting.transcriptionEnabled=true;MeetingTest.seed(new LivekitClient.Room());let turn=0;const requests=[];
  const range=(start,end,label='Original')=>Array.from({length:end-start+1},(_,i)=>({id:start+i,speaker_name:label,transcript_text:'segment '+(start+i)}));
  const replies=[{segments:range(1,100),state:{review_hash:'prefix100'}},{segments:range(101,150),state:{review_hash:'prefix150'}},{replace_segments:true,segments:range(1,100,'Corrected'),state:{review_hash:'corrected100'}},{segments:[...range(101,150),{id:151,deleted:true}],state:{review_hash:'corrected151'}}];
  window.fetch=async(_url,init)=>{requests.push(Object.fromEntries(new URLSearchParams(init.body)));return responses(replies[turn++]);};
  await MeetingTest.pollTranscript();await MeetingTest.pollTranscript();check(MeetingTest.state().lastTranscriptId===150&&document.querySelectorAll('.meeting-transcript-row').length===150,'new speech restarted pagination');
  check(requests[1].after==='100'&&requests[1].review_hash==='prefix100','prefix hash not sent with cursor');
  await MeetingTest.pollTranscript();check(document.querySelectorAll('.meeting-transcript-row').length===100&&document.querySelector('.meeting-transcript-row').textContent.includes('Corrected'),'corrected rows were not replaced');
  await MeetingTest.pollTranscript();check(requests[3].after==='100'&&requests[3].review_hash==='corrected100','replacement prefix was lost');check(MeetingTest.state().lastTranscriptId===151&&document.querySelectorAll('.meeting-transcript-row').length===150,'deleted row failed cursor advancement');MeetingTest.stopTranscriptPolling();
 });
 console.log(`MEETINGS_SECTION6=PASS (${passed} real Chromium controller cases)`);
}finally{await browser.close();}
