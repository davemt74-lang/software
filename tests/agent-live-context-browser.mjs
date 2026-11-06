import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const files=['agent-live-context-v1.js','agent-screen-share.js','agent-activity-v94.js'];
const sources=new Map(files.map(file=>['/'+file,fs.readFileSync(file,'utf8')]));
// This fixture records the real client transport. Server admission and DB
// authority are independently exercised by the live-context PHP/API suites.
const requests=[];
const server=http.createServer(async(req,res)=>{
 const pathname=new URL(req.url,'http://fixture').pathname;
 if(sources.has(pathname)){res.setHeader('Content-Type','text/javascript');res.end(sources.get(pathname));return;}
 if(pathname==='/activity'){
  let body='';for await(const chunk of req)body+=chunk;
  requests.push({headers:req.headers,body:JSON.parse(body)});
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,owner_user_id:1,server_time_ms:Date.now(),activity:{state:'working'}}));return;
 }
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>.vp3-eyes-content{max-width:500px}img{max-width:100%}</style><textarea id="chatInput"></textarea><div class="vp3-eyes-content"></div><script>
 window.proofErrors=[];window.addEventListener('error',event=>proofErrors.push(event.message));window.proof={owner:1,agent:17,cid:42,ready:true,restoring:false,busy:false,hidden:false};
 proof.ready=!new URLSearchParams(location.search).has('unbound');window.STONEFELLOW_CHAT={userId:1,agentId:17,workingAttentionBinding:'live-owner-1-page'};
 window.STONEFELLOW_ACTIVITY={endpoint:'/activity',csrf:'fixture',surface:'chat'};
 window.STONEFELLOW_CHAT_CONTINUITY_V87={isReady:()=>proof.ready,isRestoring:()=>proof.restoring,isBusy:()=>proof.busy,conversationId:()=>proof.cid,agentId:()=>proof.agent};
 window.STONEFELLOW_CHAT_CONTINUITY=STONEFELLOW_CHAT_CONTINUITY_V87;
 Object.defineProperty(document,'hidden',{configurable:true,get:()=>proof.hidden});
 window.scene='A useful product draft';window.displayCalls=0;window.cameraCalls=0;window.analysisCalls=0;window.audioUploads=0;window.tracks=[];window.beacons=[];
 Object.defineProperty(navigator,'sendBeacon',{configurable:true,value:(endpoint,body)=>{beacons.push({endpoint,entries:[...body.entries()]});return true;}});
 const sessions=[],captures=new Map();
 const observation=(label)=>({contract:'vp3.screen-observation.v1',owner_key:'1',summary:label+' is visible',visible_text:label,site_hint:'Owner workspace',page_title:label,observations:[{kind:'project',label,detail:'A visible draft is open.',confidence:'high'}]});
 const analyze=async row=>{analysisCalls++;const result={...row,body:{...row.body,observation:observation(row.label)}};if(window.deferAnalysis)return new Promise(resolve=>window.releaseAnalysis=()=>resolve({capture:result}));return {capture:result};};
 window.VP3_ORB_ADAPTER={ownerKey:'1',conversationRef:()=>proof.ready&&!proof.restoring?{id:proof.cid,agent:proof.agent}:null,
  screenRequest:async()=>({observation:observation(scene)}),
  screenAudio:async(blob,body)=>{if(!blob.size)throw Error('Expected real shared-tab audio bytes');audioUploads++;return {capture:{capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'tab_audio',review:{state:'active'},body:{transcript:'',duration_seconds:body.duration_seconds,audio_events:[{label:'music',start_seconds:0,end_seconds:Math.min(1,body.duration_seconds),provider:'elevenlabs',model:'scribe_v2',source:'shared_tab_audio',classification:'provider_label'}]}}};},
  screenHistory:async body=>{
   if(body.action==='initiative_status')return {initiative:{available:false,enabled:false,revision:'',reason:'initiative_off'}};
   if(body.action==='sessions')return {sessions};
   if(body.action==='start'){const session={session_id:body.session_id,title:body.title,created_at:new Date().toISOString(),knowledge_url:'#',folder_name:'Shared'};sessions.push(session);return {session};}
   if(body.action==='history'||body.action==='timeline')return {session:sessions.find(row=>row.session_id===body.session_id),captures:[],has_more:false,next_before:null};
   if(body.action==='capture'){const row={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'screen',label:scene,body:{image:body.image,surface:body.surface},review:{state:'active'}};captures.set(row.capture_id,row);return {capture:row};}
   if(body.action==='analyze')return analyze(captures.get(body.capture_id));
   return {};
  }};
 Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
  getUserMedia:async()=>{cameraCalls++;throw Error('No camera or microphone capture from this coordinator');},
  getDisplayMedia:async options=>{displayCalls++;const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;const paint=()=>{const ctx=canvas.getContext('2d');ctx.fillStyle='#eef';ctx.fillRect(0,0,640,360);ctx.fillStyle='#234';ctx.font='28px sans-serif';ctx.fillText(scene,15,80);};paint();setInterval(paint,100);const stream=canvas.captureStream(10),track=stream.getVideoTracks()[0],settings=track.getSettings.bind(track);track.getSettings=()=>({...settings(),displaySurface:'browser'});tracks.push(track);if(options.audio){const context=new AudioContext(),oscillator=context.createOscillator(),destination=context.createMediaStreamDestination();oscillator.connect(destination);oscillator.start();await context.resume();const audio=destination.stream.getAudioTracks()[0];tracks.push(audio);return new MediaStream([track,audio]);}return stream;}
 }});
 let sensorySequence=0;
 window.presence=(extra={})=>window.dispatchEvent(new CustomEvent('vp3:participant-presence',{detail:{active:true,context_allowed:true,observed_at:new Date().toISOString(),sequence:++sensorySequence,context:{owner_user_id:1,agent_id:17,conversation_id:proof.cid},greetings_allowed:false,last_event:'arrival',last_event_at:new Date().toISOString(),rows:[{track_id:'local-track',participant_id:'known-local',display_name:'PRIVATE LOCAL NAME',embedding:[99]}],...extra}}));
 window.mic=(extra={})=>window.dispatchEvent(new CustomEvent('vp3:microphone-activity',{detail:{active:true,context_allowed:true,observed_at:new Date().toISOString(),sequence:++sensorySequence,context:{owner_user_id:1,agent_id:17,conversation_id:proof.cid},activity:'quiet',quiet_duration_ms:6000,observed_window_ms:10000,...extra}}));
 </script><script src="/agent-live-context-v1.js"></script><script src="/agent-activity-v94.js"></script><script src="/agent-screen-share.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const url=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let checks=0;const errors=[];
const check=async(label,work)=>{await work();checks++;console.log('PASS '+label);};
const open=async(skew=0,query='')=>{const page=await browser.newPage();page.on('pageerror',error=>errors.push(error.message));await page.clock.install({time:new Date(Date.now()+skew)});await page.goto(url+query);await page.clock.runFor(500);return page;};
try{
 const page=await open();
 await check('normal saved Chat binds the existing activity transport without starting any sensor',async()=>{
  assert.equal(await page.evaluate(()=>displayCalls+cameraCalls+analysisCalls),0);assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot()),null);
  const row=requests.at(-1);assert.equal(row.body.context.owner_user_id,1);assert.equal(row.body.context.agent_id,17);assert.equal(row.body.context.live_context,null);assert.equal(row.headers['x-vp3-working-attention'],'live-owner-1-page');
 });
 await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>VP3LiveContext.snapshot()?.screens.length===1);await page.clock.runFor(2500);
 await check('an explicitly shared current scene reaches heartbeat context with exact owner Agent Chat and source authority',async()=>{
  const value=await page.evaluate(()=>VP3LiveContext.snapshot());assert.equal(value.contract,'vp3.live-context.v1');assert.equal(value.owner_user_id,1);assert.equal(value.agent_id,17);assert.equal(value.conversation_id,42);
  assert.match(value.activity_tab,/^[a-f0-9]{32}$/);assert.match(value.session_id,/^[a-f0-9-]{36}$/);assert.equal(value.screens[0].active,true);assert.equal(value.screens[0].context_allowed,true);assert.equal(value.screens[0].source_id,'share-1');assert.match(value.screens[0].summary,/useful product draft/);assert.equal(value.screens[0].observations[0].kind,'project');
  assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context().summary),value.screens[0].summary);
  assert.ok(requests.some(row=>row.body.context.live_context?.screens[0]?.summary===value.screens[0].summary));assert.ok(!JSON.stringify(value).includes('data:image'));assert.ok(!JSON.stringify(value).includes('fingerprint'));
 });
 await page.evaluate(()=>{presence();mic();});await page.clock.runFor(2500);
 await check('Eyes stays provisional and microphone stays coarse without leaking local identity or raw media',async()=>{
  const value=await page.evaluate(()=>VP3LiveContext.snapshot());assert.equal(value.eyes.known_count,1);assert.equal(value.eyes.unknown_count,0);assert.equal(value.eyes.identity_provisional,true);assert.equal(value.eyes.authentication_authority,false);assert.equal(value.eyes.greetings_allowed,false);assert.equal(value.microphone.activity,'quiet');assert.equal(value.microphone.authentication_authority,false);
  assert.ok(!JSON.stringify(value).includes('PRIVATE LOCAL NAME'));assert.ok(!JSON.stringify(value).includes('embedding'));
 });
 await page.evaluate(()=>presence({observed_at:new Date(Date.parse(VP3LiveContext.snapshot().eyes.observed_at)-1000).toISOString(),sequence:999999,rows:[]}));
 await check('an older same-context presence result cannot replace a newer observation even with a larger sequence',async()=>assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot().eyes.known_count),1));
 await page.evaluate(()=>{presence({last_event:'steady'});});await page.clock.runFor(2500);const beforeRenewal=requests.length;
 await page.evaluate(()=>{presence({last_event:'steady'});mic({quiet_duration_ms:6500});window.dispatchEvent(new Event('vp3:screen-live-context'));});await page.clock.runFor(6000);
 await check('timestamp renewals and unchanged live frames add no heartbeat or independent thinking loop',async()=>{assert.equal(requests.length,beforeRenewal);});
 await page.evaluate(()=>mic({activity:'energy',quiet_duration_ms:0}));await page.clock.runFor(900);
 await check('sustained microphone energy defers proactive voice only and quiet clears that deferral',async()=>{
  assert.equal(await page.evaluate(()=>VP3LiveContext.voiceDeferral().defer),true);await page.evaluate(()=>mic());assert.equal(await page.evaluate(()=>VP3LiveContext.voiceDeferral().defer),false);
 });
 await page.locator('[data-screen-chat-context]').uncheck();await page.clock.runFor(2500);
 await check('the actual per-share context checkbox withdraws live scene evidence while preserving the share',async()=>{
  assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot().screens.length),0);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),true);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()),null);
  assert.match(await page.locator('[data-screen-chat-context]').getAttribute('aria-describedby'),/screen-live-use/);
 });
 await page.locator('[data-screen-chat-context]').check();await page.clock.runFor(500);
 await page.evaluate(()=>{proof.hidden=true;document.dispatchEvent(new Event('visibilitychange'));});await page.clock.runFor(500);
 await check('hidden clears current context and pending prior observations rather than replaying them',async()=>{
  assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot()),null);assert.ok((await page.evaluate(()=>VP3LiveContext.pendingEvents())).every(row=>row.kind==='cleared'));
 });
 await page.evaluate(()=>{proof.hidden=false;document.dispatchEvent(new Event('visibilitychange'));});
 await check('returning visible does not revive analysis captured before the privacy reset',async()=>{assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot()),null);});
 await page.getByRole('button',{name:'Stop sharing',exact:true}).click();await page.evaluate(()=>{window.deferAnalysis=true;});
 await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>typeof releaseAnalysis==='function');
 await page.getByRole('button',{name:'Stop sharing',exact:true}).click();await page.evaluate(()=>releaseAnalysis());await page.clock.runFor(100);
 await check('a delayed analysis completing after Stop cannot restore any live scene',async()=>{assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot()),null);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),false);});
 await page.evaluate(()=>{delete window.releaseAnalysis;});await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>typeof releaseAnalysis==='function');
 await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));proof.cid=43;window.dispatchEvent(new Event('vp3:chat-context-ready'));releaseAnalysis();});await page.clock.runFor(500);
 await check('saved Chat switching fences an old in-flight share and its source binding',async()=>{assert.equal(await page.evaluate(()=>VP3LiveContext.snapshot()),null);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),false);assert.equal(await page.evaluate(()=>tracks.at(-1).readyState),'ended');});
 await page.close();
 const many=await open();await many.getByRole('button',{name:'Share screen',exact:true}).click();await many.waitForFunction(()=>VP3LiveContext.snapshot()?.screens.length===1);
 for(let index=1;index<3;index++){
  await many.getByRole('button',{name:'Add tab',exact:true}).click();const panel=many.locator('#vp3-screen-source-panel-'+index);
  await many.evaluate(i=>{scene='Distinct page '+i;},index);await panel.getByRole('button',{name:'Share tab',exact:true}).click();await panel.getByRole('button',{name:'Capture and analyze',exact:true}).click();
  await many.waitForFunction(i=>VP3LiveContext.snapshot()?.screens.some(row=>row.source_id==='share-'+(i+1)),index);
 }
 await check('three active shares retain separate source and session bindings without borrowing selected-tab content',async()=>{
  const values=await many.evaluate(()=>VP3LiveContext.snapshot().screens);assert.equal(values.length,3);assert.equal(new Set(values.map(row=>row.session_id)).size,3);assert.deepEqual(values.map(row=>row.source_id),['share-1','share-2','share-3']);assert.equal(values.filter(row=>row.selected).length,1);assert.match(values[1].summary,/Distinct page 1/);assert.match(values[2].summary,/Distinct page 2/);
  await many.locator('#vp3-screen-source-panel-2 [data-screen-chat-context]').uncheck();assert.deepEqual(await many.evaluate(()=>VP3LiveContext.snapshot().screens.map(row=>row.source_id)),['share-1','share-2']);
 });await many.close();
 const held=await open();await held.evaluate(()=>window.deferAnalysis=true);await held.getByRole('button',{name:'Share screen',exact:true}).click();await held.waitForFunction(()=>typeof releaseAnalysis==='function');
 await held.locator('[data-screen-chat-context]').uncheck();await held.evaluate(()=>releaseAnalysis());await held.clock.runFor(200);
 await check('turning the context checkbox off during model analysis prevents the late result from reauthorizing itself',async()=>{assert.equal(await held.evaluate(()=>VP3LiveContext.snapshot()),null);assert.equal(await held.evaluate(()=>VP3AgentScreenShare.isActive()),true);});await held.close();
 const routed=await open();await routed.evaluate(()=>window.deferAnalysis=true);await routed.getByRole('button',{name:'Share screen',exact:true}).click();await routed.waitForFunction(()=>typeof releaseAnalysis==='function');
 await routed.evaluate(()=>{history.pushState({},'', '/missions.php');releaseAnalysis();});await routed.clock.runFor(500);
 await check('a route change fences a delayed scene even when a stale canonical Chat object still exists',async()=>assert.equal(await routed.evaluate(()=>VP3LiveContext.snapshot()),null));await routed.close();
 const audio=await open();await audio.locator('[data-screen-transcribe]').check();await audio.getByRole('button',{name:'Share screen',exact:true}).click();
 await audio.waitForFunction(()=>document.querySelector('[data-screen-audio-meter]').value>0);await audio.clock.runFor(16000);await audio.waitForFunction(()=>audioUploads>0&&VP3LiveContext.snapshot()?.screens[0]?.tab_audio?.audio_events?.length);
 await check('provider-labelled event-only tab audio reaches live context without fabricating speech or opening a microphone',async()=>{
  const value=await audio.evaluate(()=>VP3LiveContext.snapshot());assert.equal(value.screens[0].tab_audio.text,'');assert.equal(value.screens[0].tab_audio.audio_events[0].label,'music');assert.equal(value.screens[0].tab_audio.audio_events[0].classification,'provider_label');assert.equal(await audio.evaluate(()=>cameraCalls),0);assert.ok(!JSON.stringify(value).includes('data:audio'));
  await audio.locator('[data-screen-transcribe]').uncheck();assert.equal(await audio.evaluate(()=>VP3LiveContext.snapshot().screens[0].tab_audio),undefined);
 });await audio.close();
 const stale=await open();await stale.getByRole('button',{name:'Share screen',exact:true}).click();await stale.waitForFunction(()=>VP3LiveContext.snapshot());await stale.getByRole('button',{name:'Add tab',exact:true}).click();await stale.clock.runFor(16000);
 await check('a still-open paused preview expires from live context without being relabeled as a current scene',async()=>{assert.equal(await stale.evaluate(()=>VP3AgentScreenShare.isActive()),true);assert.equal(await stale.evaluate(()=>VP3LiveContext.snapshot()),null);});
 await stale.evaluate(()=>{proof.owner=2;STONEFELLOW_CHAT.userId=2;presence({context:{owner_user_id:1,agent_id:17,conversation_id:42}});});await stale.clock.runFor(500);
 await check('an account switch cannot export or republish the previous account sensor context',async()=>{assert.equal(await stale.evaluate(()=>VP3LiveContext.snapshot()),null);});
 await stale.evaluate(()=>{window.dispatchEvent(new Event('pagehide'));});
 await check('pagehide sends a bound null clear and stops the original live stream',async()=>{
  const values=await stale.evaluate(()=>({beacons,ended:tracks.every(track=>track.readyState==='ended')}));assert.equal(values.ended,true);
  // This account-switched page is no longer authorized to beacon the old owner.
  assert.equal(values.beacons.length,0);
 });await stale.close();
 const departing=await open();await departing.evaluate(()=>window.dispatchEvent(new Event('pagehide')));
 await check('an authorized pagehide beacon retains the exact page binding and explicit null context',async()=>{
  const entries=await departing.evaluate(()=>Object.fromEntries(beacons.at(-1).entries));assert.equal(entries.working_attention_binding,'live-owner-1-page');const value=JSON.parse(entries.context);assert.equal(value.live_context,null);assert.equal(value.owner_user_id,1);assert.equal(value.agent_id,17);assert.equal(value.visible,false);
 });await departing.close();
 const skewed=await open(300000);await skewed.evaluate(()=>presence({last_event:'steady'}));await skewed.clock.runFor(2500);
 await check('the existing server clock anchor corrects original sensory timestamps without renewing their age',async()=>{
  const value=await skewed.evaluate(()=>VP3LiveContext.snapshot());assert.ok(Math.abs(Date.parse(value.observed_at)-Date.now())<5000);assert.ok(Math.abs(Date.parse(value.eyes.observed_at)-Date.now())<5000);assert.ok(requests.some(row=>row.body.context.live_context?.session_id===value.session_id));
 });await skewed.close();
 const start=requests.length,unbound=await open(0,'?unbound=1');await unbound.evaluate(()=>presence());await unbound.clock.runFor(2500);
 await check('unready and legacy activity omit the live-context write contract until a saved owner Chat is ready',async()=>{
  assert.equal(await unbound.evaluate(()=>VP3LiveContext.snapshot()),null);assert.ok(requests.length>start);for(const row of requests.slice(start))assert.equal(Object.hasOwn(row.body.context,'live_context'),false);
 });await unbound.close();
 await check('the coordinator and existing active-share UI produce no browser exceptions',async()=>assert.deepEqual(errors,[]));
 console.log('AGENT_LIVE_CONTEXT_BROWSER=PASS ('+checks+' cases)');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
