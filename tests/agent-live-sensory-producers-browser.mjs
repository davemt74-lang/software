import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {chromium} from 'playwright';

const reports=[];
const scripts=new Set(['agent-eyes-core.js','agent-orb-adapter.js','agent-eyes.js','chat-voice.js']);
const server=http.createServer((req,res)=>{
 const pathname=new URL(req.url,'http://fixture').pathname.slice(1);
 if(scripts.has(pathname)){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(pathname));return;}
 if(pathname==='participants'||pathname==='eyes'){
  let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{if(body)reports.push(JSON.parse(body));res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,profiles:[]}));});return;
 }
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Listen</button></form><div id="chatVoiceStatus"></div><script>
 window.focused=true;Object.defineProperty(document,'hasFocus',{value:()=>focused});
 window.cid=42;window.agent=7;window.ready=true;window.restoring=false;window.micEnergy=.001;window.acquisitions=[];window.greetings=[];window.presence=[];window.micEvents=[];window.faces=[];window.objects=[];
 window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,csrf:'fixture',endpoint:'/api/chat.php'};
 window.STONEFELLOW_CHAT_CONTINUITY_V87={isReady:()=>ready,isRestoring:()=>restoring,conversationId:()=>cid,agentId:()=>agent};
 window.VP3_ORB_CONFIG={ownerKey:'1',agentId:99,participantsEndpoint:'/participants',eyesEndpoint:'/eyes',chatUrl:'/chat.php',csrf:'fixture'};
 window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};window.STONEFELLOW_CHAT_VOICE_BOOT={};
 window.StonefellowPremiumVoiceV122={};window.STONEFELLOW_NOTIFICATION_CENTER={announceWhen:async(text,current)=>{if(current())greetings.push(text);return true;}};
 window.addEventListener('vp3:participant-presence',event=>presence.push(event.detail));window.addEventListener('vp3:microphone-activity',event=>micEvents.push(event.detail));
 window.tvEnabled=false;window.VP3TVWatching={needsDetection:()=>tvEnabled,screenBoxes:()=>[],faceOnScreen:()=>false,observe(){},stop(){},hear:()=>false};
 window.AudioContext=class {constructor(){this.state='running';this.destination={};}createMediaStreamSource(){return {connect(){},disconnect(){}};}createAnalyser(){return {fftSize:512,getFloatTimeDomainData:data=>data.fill(micEnergy)};}close(){this.state='closed';}resume(){return Promise.resolve();}};
 window.SpeechRecognition=class {start(){queueMicrotask(()=>this.onstart?.());}stop(){queueMicrotask(()=>this.onend?.());}abort(){this.stop();}};
 Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async options=>{
  acquisitions.push(options);if(options.video){const canvas=document.createElement('canvas');canvas.width=640;canvas.height=480;canvas.getContext('2d').fillRect(0,0,640,480);const stream=canvas.captureStream(2);window.cameraStream=stream;return stream;}
  const track=new EventTarget();Object.assign(track,{readyState:'live',enabled:true,muted:false,stop(){this.readyState='ended';}});window.micTrack=track;return {getAudioTracks:()=>[track],getTracks:()=>[track]};
 }}});
 </script><script src="/agent-eyes-core.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-eyes.js"></script><script src="/chat-voice.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
let checks=0;
const pass=label=>{checks++;console.log('PASS '+label);};
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.route('https://cdn.jsdelivr.net/**',route=>route.fulfill({contentType:'text/javascript',body:'export class Human {async load(){} async detect(){if(window.waitDetect)await new Promise(resolve=>window.releaseDetect=resolve);return {face:structuredClone(window.faces),object:structuredClone(window.objects)}}}'}));
 await page.goto(`http://127.0.0.1:${server.address().port}`);await page.waitForFunction(()=>window.VP3AgentEyes&&window.VP3ConversationControls);
 assert.deepEqual(await page.evaluate(()=>acquisitions),[]);assert.deepEqual(await page.evaluate(()=>micEvents),[]);pass('loading Chat never opens a sensor');
 assert.deepEqual(await page.evaluate(()=>VP3_ORB_ADAPTER.liveSensoryContext()),{owner_user_id:1,agent_id:7,conversation_id:42});pass('the actual Chat Agent is canonical even when the global orb default is another Agent');
 await page.evaluate(()=>{faces=[{box:[100,100,100,100]}];return VP3AgentEyes.start();});
 await page.waitForFunction(()=>presence.some(event=>event.active&&event.last_event==='arrival'));
 const arrival=await page.evaluate(()=>presence.find(event=>event.active&&event.last_event==='arrival'));
 assert.deepEqual(arrival.context,{owner_user_id:1,agent_id:7,conversation_id:42});assert.equal(arrival.context_allowed,true);assert.equal(arrival.rows.length,1);assert.ok(arrival.last_event_at);assert.deepEqual(await page.evaluate(()=>greetings),[]);assert.equal(reports.some(row=>row.event_type==='greeting'),false);pass('owner Chat publishes bound arrival evidence without independently speaking a greeting');
 const observation=await page.evaluate(()=>presence.at(-1).observed_at);
 await page.evaluate(()=>document.querySelector('[data-eyes-mirror]').dispatchEvent(new Event('change')));
 assert.equal(await page.evaluate(()=>presence.at(-1).observed_at),observation);pass('a settings redraw cannot renew camera observation freshness');
 await page.evaluate(()=>{objects=[{label:'tv',score:.95},{label:'chair',score:.9},{label:'private name <script>',score:1}];});await page.waitForTimeout(1100);
 assert.deepEqual(await page.evaluate(()=>presence.at(-1).object_labels),[]);
 await page.evaluate(()=>tvEnabled=true);await page.waitForFunction(()=>presence.at(-1).object_labels.includes('tv'));
 assert.deepEqual(await page.evaluate(()=>presence.at(-1).object_labels),['chair','tv']);pass('generic object facts only reuse an already active local TV detector');
 await page.evaluate(()=>cameraStream.getVideoTracks()[0].enabled=false);await page.waitForFunction(()=>presence.at(-1).active===false);
 await page.evaluate(()=>cameraStream.getVideoTracks()[0].enabled=true);await page.waitForFunction(()=>presence.at(-1).active===true);
 pass('a disabled camera cannot renew presence from its last frame and resumes only after fresh detection');
 await page.evaluate(()=>{document.querySelector('[data-eyes-greetings]').checked=false;document.querySelector('[data-eyes-greetings]').dispatchEvent(new Event('change'));});
 assert.equal(await page.evaluate(()=>presence.at(-1).greetings_allowed),false);pass('the existing greeting preference accompanies model context');
 await page.evaluate(()=>{focused=false;window.dispatchEvent(new Event('blur'));});
 assert.equal(await page.evaluate(()=>presence.at(-1).active),false);assert.deepEqual(await page.evaluate(()=>presence.at(-1).context),arrival.context);
 await page.waitForTimeout(1100);assert.equal(await page.evaluate(()=>presence.at(-1).active),false);pass('unfocused Chat clears live Eyes context with its original binding');
 await page.evaluate(()=>{focused=true;window.dispatchEvent(new Event('focus'));});await page.waitForFunction(()=>presence.at(-1).active);
 await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));cid=43;});
 assert.equal(await page.evaluate(()=>VP3AgentEyes.isActive()),false);assert.equal(await page.evaluate(()=>cameraStream.getTracks().every(track=>track.readyState==='ended')),true);pass('conversation changes stop the camera and clear old context');
 await page.evaluate(()=>{cid=42;waitDetect=true;void VP3AgentEyes.start();});await page.waitForFunction(()=>typeof releaseDetect==='function');
 await page.evaluate(()=>{VP3AgentEyes.stop();presence=[];waitDetect=false;releaseDetect();});await page.waitForTimeout(150);
 assert.equal(await page.evaluate(()=>presence.some(event=>event.active)),false);pass('late camera inference cannot revive a stopped capture');
 await page.evaluate(()=>VP3AgentEyes.start());await page.waitForFunction(()=>presence.at(-1)?.active);
 await page.evaluate(()=>{focused=false;window.dispatchEvent(new Event('blur'));cid=43;focused=true;});
 await page.waitForFunction(()=>!VP3AgentEyes.isActive());assert.equal(await page.evaluate(()=>presence.at(-1).active),false);
 await page.evaluate(()=>cid=42);pass('a camera binding remains fenced across blur even without a navigation event');
 await page.evaluate(()=>VP3ConversationControls.listen());await page.waitForFunction(()=>micEvents.some(event=>event.active&&event.activity==='quiet'));
 let mic=await page.evaluate(()=>micEvents.at(-1));assert.deepEqual(mic.context,arrival.context);assert.equal(mic.context_allowed,true);assert.equal(Object.hasOwn(mic,'rms'),false);assert.equal(Object.hasOwn(mic,'transcript'),false);pass('explicit listening exports coarse local activity without audio or semantic sound guesses');
 await page.evaluate(()=>micEnergy=.2);await page.waitForFunction(()=>micEvents.at(-1).activity==='energy');
 await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'start',text:'An Agent reply.'}})));
 await page.waitForFunction(()=>micEvents.at(-1).activity==='uncertain');
 await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end',text:'An Agent reply.',completed:true}})));
 pass('the Agent’s own playback is uncertain audio evidence, not owner activity');
 await page.evaluate(()=>micEnergy=.001);await page.waitForFunction(()=>micEvents.at(-1).activity==='quiet');await page.waitForTimeout(5200);
 mic=await page.evaluate(()=>micEvents.at(-1));assert.ok(mic.quiet_duration_ms>=5000);assert.ok(mic.observed_window_ms<=30000);pass('quiet duration is bounded and actual energy resets it');
 await page.evaluate(()=>{micTrack.muted=true;micTrack.dispatchEvent(new Event('mute'));});assert.equal(await page.evaluate(()=>micEvents.at(-1).active),false);
 await page.evaluate(()=>micTrack.muted=false);await page.waitForFunction(()=>micEvents.at(-1).active);pass('mute clears evidence immediately and fresh samples are required to resume');
 await page.evaluate(()=>{cid=43;});await page.waitForFunction(()=>!VP3ConversationControls.isEnabled());assert.equal(await page.evaluate(()=>micEvents.at(-1).active),false);assert.deepEqual(await page.evaluate(()=>micEvents.at(-1).context),arrival.context);pass('an active microphone cannot migrate to another conversation');
 await page.evaluate(()=>{cid=42;VP3ConversationControls.listen();});await page.waitForFunction(()=>micEvents.at(-1).active);
 await page.evaluate(()=>VP3ConversationControls.stop());assert.equal(await page.evaluate(()=>micEvents.at(-1).active),false);assert.equal(await page.evaluate(()=>micTrack.readyState),'ended');pass('Stop clears local activity and releases the existing microphone');
 await page.evaluate(()=>{ready=false;micEvents=[];VP3ConversationControls.listen();});await page.waitForTimeout(200);
 await page.evaluate(()=>ready=true);await page.waitForTimeout(200);
 assert.equal(await page.evaluate(()=>micEvents.some(event=>event.active)),false);await page.evaluate(()=>VP3ConversationControls.stop());
 pass('capture started without a ready Chat cannot acquire a later conversation binding');
 await page.evaluate(()=>{micEvents=[];VP3ConversationControls.responseWindow();});await page.waitForTimeout(250);
 assert.equal(await page.evaluate(()=>micEvents.some(event=>event.active)),false);await page.evaluate(()=>VP3ConversationControls.stop());
 pass('a temporary automatic reply window does not authorize ambient activity export');
 await page.evaluate(()=>{STONEFELLOW_CHAT.userId=2;});assert.equal(await page.evaluate(()=>VP3_ORB_ADAPTER.liveSensoryContext()),null);pass('account identity changes cannot export the old owner context');
 assert.deepEqual(errors,[]);pass('actual producers run without browser errors');
 console.log(`AGENT_LIVE_SENSORY_PRODUCERS_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
