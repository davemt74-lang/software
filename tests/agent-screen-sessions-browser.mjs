import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const script=fs.readFileSync('agent-screen-share.js','utf8'),css=fs.readFileSync('agent-orb.css','utf8');
const server=http.createServer((req,res)=>{
 if(req.url==='/agent-screen-share.js'){res.setHeader('Content-Type','text/javascript; charset=utf-8');res.end(script);return;}
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}body{margin:0;background:#152434;color:#fff}.vp3-eyes-content{width:100%;max-width:360px;box-sizing:border-box;padding:8px}</style><div class="vp3-eyes-content"></div><textarea id="chatInput"></textarea><script>
 window.calls=[];window.sessions=[];window.captures=[];window.audioCalls=[];window.cameraCalls=0;window.displayCalls=[];window.displayStreams=[];window.recordedTrackIds=[];window.recorders=[];window.deleted=[];
 const multi=new URLSearchParams(location.search).has('multi');
 if(multi)window.MediaRecorder=class extends MediaRecorder {
  start(...args){recordedTrackIds.push(this.stream.getAudioTracks()[0].id);recorders.push(this);return super.start(...args);}
 };
 const audioResponse=new Promise(resolve=>window.releaseAudioResponse=resolve);
 const obs=()=>({contract:'vp3.screen-observation.v1',owner_key:'1',summary:'Song <img> at 1:20',visible_text:'Song, artist, 1:20 / 4:00',site_hint:'Player',page_title:'Song',observations:[{kind:'music',label:'Position',detail:'1:20 of 4:00 displayed',confidence:'high'}]});
 const realTimeout=window.setTimeout.bind(window);window.setTimeout=(fn,ms,...args)=>realTimeout(fn,ms===15000?(window.fixtureAudioInterval||800):ms,...args);
 window.VP3_ORB_ADAPTER={ownerKey:'1',conversationRef:()=>({id:7}),screenRequest:async()=>({observation:obs()}),screenToChat:text=>{document.querySelector('#chatInput').value=text;return true;},screenHistory:async(body,{signal}={})=>{
 calls.push({...body});if(body.action==='list')return {sessions:sessions.filter(s=>!deleted.includes(s.session_id))};
 if(body.action==='start'){const s={session_id:body.session_id,title:body.title,status:'active',created_at:new Date().toISOString(),folder_id:12,folder_name:'Music research',knowledge_url:'/knowledge.php?folder=12&screen_session='+body.session_id};sessions.push(s);return {session:s};}
 if(body.action==='end'){const s=sessions.find(s=>s.session_id===body.session_id);if(s)s.status='ended';return {ended:true};}
 if(body.action==='delete_session'){deleted.push(body.session_id);captures=captures.filter(c=>c.session_id!==body.session_id);return {deleted:true};}
 if(body.action==='history')return {session:sessions.find(s=>s.session_id===body.session_id),captures:captures.filter(c=>c.session_id===body.session_id).slice().reverse()};
 if(body.action==='timeline')return {session:sessions.find(s=>s.session_id===body.session_id),captures:captures.filter(c=>c.session_id===body.session_id&&(c.kind==='tab_audio'||c.body.annotation_logging===true&&c.body.observation?.on_screen_translation)).slice().reverse(),next_before:null};
 if(body.action==='capture'&&body.image){const c={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'screen',memory_id:0,body:{image:body.image,note:body.note,annotation_logging:body.annotation_logging}};captures.push(c);return {capture:c};}
 const c=captures.find(c=>c.capture_id===body.capture_id);if(!c)throw Error('Capture unavailable');
 if(body.action==='analyze'){if(window.deferAnalysis)await new Promise(resolve=>window.releaseAnalysis=resolve);if(deleted.includes(c.session_id))throw Error('Session deleted');c.body.observation=obs();if(multi)c.body.observation.summary=sessions.find(s=>s.session_id===c.session_id).title+' scene';if(c.body.annotation_logging===true&&audioCalls.length)c.body.observation.on_screen_translation='Translation visible on the screen';return {capture:c};}
 if(body.action==='brain'){c.memory_id=44;return {capture:c};}return {capture:c};
 },screenAudio:async(blob,body)=>{
 audioCalls.push({bytes:blob.size,type:blob.type,...body});
 if(multi){
  if(window.deferNextMultiAudio){window.deferNextMultiAudio=false;await new Promise(resolve=>window.releasePendingMultiAudio=resolve);}
  const title=sessions.find(s=>s.session_id===body.session_id).title;
  const c={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'tab_audio',memory_id:0,body:{transcript:title+' audio',duration_seconds:body.duration_seconds}};
  captures.push(c);return {capture:c};
 }
 if(audioCalls.length>1)return {capture:null};
 await audioResponse;
 const cues=[{start_seconds:.125,end_seconds:.375,text:'A useful spoken idea from the tab'},{start_seconds:.45,end_seconds:.675,text:'An example <img>'}];
 const c={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'tab_audio',memory_id:0,body:{transcript:cues.map(cue=>cue.text).join(' '),duration_seconds:body.duration_seconds,cues}};
 captures.push(c);return {capture:c};
 }};
 function fixtureCanvas(label,color){
  const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;
  const paint=()=>{const ctx=canvas.getContext('2d');ctx.fillStyle=color;ctx.fillRect(0,0,640,360);ctx.fillStyle='#000';ctx.font='30px sans-serif';ctx.fillText(label,20,100);};
  paint();setInterval(paint,100);return canvas;
 }
 const canvas=multi?null:fixtureCanvas('Song progress 1:20 / 4:00','#abc');
 Object.defineProperty(navigator,'mediaDevices',{value:{
  getUserMedia:async()=>{cameraCalls++;throw Error('No microphone');},
  getDisplayMedia:async options=>{
   displayCalls.push({...options,gesture:navigator.userActivation.isActive});
   const index=displayCalls.length-1;
   const source=canvas||fixtureCanvas('Fixture tab '+(index+1),['#9bf','#fbd','#bfe'][index%3]);
   const s=source.captureStream(10),t=s.getVideoTracks()[0];window.displayTrack=t;
   const get=t.getSettings.bind(t);t.getSettings=()=>({...get(),displaySurface:'browser'});
   if(options.audio){const ctx=new AudioContext();await ctx.resume();const osc=ctx.createOscillator(),dest=ctx.createMediaStreamDestination();osc.connect(dest);osc.start();s.addTrack(dest.stream.getAudioTracks()[0]);window.sourceContext=ctx;}
   displayStreams.push(s);return s;
  }
 }});
 </script><script src="/agent-screen-share.js"></script>`);
});await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});let checks=0;const check=async(name,fn)=>{await fn();console.log('PASS '+name);checks++;};
try{
 const page=await browser.newPage({viewport:{width:390,height:844}}),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:'+server.address().port);await page.waitForFunction(()=>calls.length>0);
 await check('loading history does not create folders or request devices',async()=>{assert.deepEqual(await page.evaluate(()=>[sessions.length,displayCalls.length,cameraCalls]),[0,0,0]);});
 await page.locator('[data-screen-session-title]').fill('Music research');await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>VP3AgentScreenShare.context());
 await check('sharing creates one Knowledge session and saves the analyzed capture',async()=>{assert.equal(await page.evaluate(()=>sessions.length),1);assert.equal(await page.evaluate(()=>captures.length),1);assert.equal(await page.evaluate(()=>calls.filter(c=>c.action==='brain').length),0);assert.equal(await page.evaluate(()=>displayCalls[0].audio),false);assert.equal(await page.evaluate(()=>cameraCalls),0);});
 await page.locator('[data-screen-history-panel]').evaluate(e=>e.open=true);await page.locator('[data-screen-folder]').waitFor({state:'visible'});
 await check('folder link targets existing My Knowledge and observations are safely rendered',async()=>{assert.match(await page.locator('[data-screen-folder]').getAttribute('href'),/^\/knowledge.php\?folder=12/);assert.equal(await page.locator('[data-screen-history] img').count(),0);assert.match(await page.locator('[data-screen-history]').innerText(),/1:20 of 4:00 displayed/);assert.match(await page.locator('[data-screen-history]').innerText(),/Song <img>/);});
 await page.locator('[data-screen-auto]').uncheck();await page.locator('[data-screen-note]').fill('Compare this song');await page.getByRole('button',{name:'Capture',exact:true}).click();await page.waitForFunction(()=>captures.length===2);
 await check('manual capture stores user intent without starting annotation logging',async()=>{assert.equal(await page.evaluate(()=>captures[1].body.note),'Compare this song');assert.equal(await page.evaluate(()=>captures[1].body.observation),undefined);assert.equal(await page.evaluate(()=>calls.some(c=>c.annotation_logging===true)),false);});
 await page.getByRole('button',{name:'Capture and analyze',exact:true}).click();await page.waitForFunction(()=>captures.length===3&&captures[2].body.observation);
 await page.locator('[data-screen-history] [data-capture-action="brain"]').first().click();await page.waitForFunction(()=>captures.some(c=>c.memory_id===44));
 await check('Brain save and Send to Chat are explicit actions',async()=>{await page.locator('[data-screen-history] [data-capture-action="chat"]').first().click();assert.match(await page.locator('#chatInput').inputValue(),/Captured: /);assert.match(await page.locator('#chatInput').inputValue(),/Session: /);});
 await page.getByRole('button',{name:'Stop sharing',exact:true}).click();await page.waitForFunction(()=>sessions[0].status==='ended');
 await check('stop releases the display and keeps session history',async()=>{assert.equal(await page.evaluate(()=>displayTrack.readyState),'ended');assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()),null);assert.equal(await page.evaluate(()=>captures.length),3);});
 await page.getByRole('checkbox',{name:/Agent Ears/}).check();
 await page.locator('[data-screen-annotation-log]').check();
 await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>audioCalls.length>0);
 const transcriptLog=page.locator('[data-screen-transcript-log]');
 const transcriptEntries=transcriptLog.locator('[data-screen-transcript-entry]');
 const annotationEntries=transcriptLog.locator('[data-screen-annotation-entry]');
 await check('transcription log waits for a saved audio result',async()=>{assert.equal(await transcriptEntries.count(),0);});
 await page.getByRole('button',{name:'Capture and analyze',exact:true}).click();
 await check('enabled annotation logging saves a visible translation with its screen timestamp',async()=>{
  const entry=annotationEntries.filter({hasText:'Translation visible on the screen'});
  await entry.waitFor({state:'visible',timeout:4000});
  const request=await page.evaluate(()=>{
   const screenshot=captures.find(c=>c.body.observation?.on_screen_translation);
   return calls.find(call=>call.action==='capture'&&call.capture_id===screenshot.capture_id);
  });
  assert.equal(request.annotation_logging,true);
  assert.equal(request.session_id,await page.evaluate(()=>sessions[1].session_id));
  assert.equal(await entry.locator('time[datetime]').first().getAttribute('datetime'),new Date(request.captured_at).toISOString());
  await page.waitForFunction(at=>{
   const context=VP3AgentScreenShare.context();
   return context?.captured_at===at&&context.on_screen_translation==='Translation visible on the screen';
  },request.captured_at,{timeout:4000});
 });
 await page.evaluate(()=>window.releaseAudioResponse());
 await page.waitForFunction(()=>VP3AgentScreenShare.context()?.tab_audio);
 await check('shared-tab audio uses real MediaRecorder bytes without opening a microphone',async()=>{const data=await page.evaluate(()=>({audio:displayCalls[1].audio,call:audioCalls[0],mic:cameraCalls}));assert.equal(data.audio,true);assert.ok(data.call.bytes>32);assert.match(data.call.type,/audio\//);assert.equal(data.mic,0);assert.match((await page.evaluate(()=>VP3AgentScreenShare.context())).tab_audio.text,/spoken idea/);});
 await check('inline transcript timing follows captured audio through a delayed result',async()=>{
  const first=transcriptEntries.filter({hasText:'A useful spoken idea from the tab'});
  const second=transcriptEntries.filter({hasText:'An example <img>'});
  await first.waitFor({state:'visible',timeout:4000});
  await second.waitFor({state:'visible',timeout:4000});
  const {capturedAt,createdAt}=await page.evaluate(()=>({capturedAt:audioCalls[0].captured_at,createdAt:sessions[1].created_at}));
  assert.equal(await first.locator('time[datetime]').first().getAttribute('datetime'),new Date(Date.parse(capturedAt)+125).toISOString());
  assert.equal(await second.locator('time[datetime]').first().getAttribute('datetime'),new Date(Date.parse(capturedAt)+450).toISOString());
  const rangeText=await first.locator('time[datetime]').first().innerText();
  const range=rangeText.match(/(\d+):(\d{2}\.\d)\s*[–—-]\s*(\d+):(\d{2}\.\d)/);
  assert.ok(range,'Transcript cue shows its elapsed start and end: '+rangeText);
  const elapsed=(Date.parse(capturedAt)-Date.parse(createdAt))/1000;
  assert.ok(Math.abs(Number(range[1])*60+Number(range[2])-elapsed-.125)<=.101);
  assert.ok(Math.abs(Number(range[3])*60+Number(range[4])-elapsed-.375)<=.101);
  assert.equal(await transcriptEntries.count(),2);
  assert.equal(await transcriptLog.locator('img').count(),0);
  const ordered=await transcriptLog.locator('[data-screen-transcript-entry], [data-screen-annotation-entry]').allTextContents();
  assert.match(ordered[0],/A useful spoken idea/);
  assert.match(ordered[1],/An example <img>/);
  assert.match(ordered[2],/Translation visible on the screen/);
 });
 await page.locator('[data-screen-transcribe]').uncheck();assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
 await check('disabling transcription clears current audio context while preserving the saved log',async()=>{await page.locator('[data-screen-audio-status]').getByText(/(?:Agent Ears|Tab transcription) is off/).waitFor();assert.equal(await transcriptEntries.count(),2);assert.equal(await annotationEntries.count(),1);});
 await page.locator('[data-screen-history-panel]').evaluate(e=>e.open=true);await page.waitForFunction(()=>document.querySelector('[data-screen-sessions]').value===sessions[1].session_id);
 await check('saved cues and legacy segment timing return with their own session history',async()=>{
  const {older,current,legacyAt}=await page.evaluate(()=>{
   const screenshot=captures.find(c=>c.session_id===sessions[1].session_id&&c.body.observation?.on_screen_translation);
   const captured_at=new Date(Date.parse(screenshot.captured_at)-250).toISOString();
   captures.push({capture_id:crypto.randomUUID(),session_id:sessions[1].session_id,captured_at,kind:'tab_audio',memory_id:0,body:{transcript:'Legacy saved segment',duration_seconds:15}});
   return {older:sessions[0].session_id,current:sessions[1].session_id,legacyAt:captured_at};
  });
  const sessions=page.locator('[data-screen-sessions]');
  await sessions.selectOption(older);
  await transcriptEntries.first().waitFor({state:'detached',timeout:4000});
  assert.equal(await annotationEntries.count(),0);
  await sessions.selectOption(current);
  const legacy=transcriptEntries.filter({hasText:'Legacy saved segment'});
  await legacy.waitFor({state:'visible',timeout:4000});
  assert.equal(await legacy.locator('time[datetime]').first().getAttribute('datetime'),legacyAt);
  assert.equal(await transcriptEntries.count(),3);
  assert.equal(await annotationEntries.count(),1);
  await annotationEntries.getByText(/^Audio segment /).waitFor({state:'visible',timeout:4000});
 });
 page.on('dialog',d=>d.accept());await page.locator('[data-screen-delete]').click();await page.waitForFunction(()=>deleted.length===1);
 await check('delete session clears inline entries, removes selected history and sends the Brain removal choice',async()=>{await transcriptEntries.first().waitFor({state:'detached',timeout:4000});assert.equal(await annotationEntries.count(),0);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),false);assert.equal(await page.evaluate(()=>captures.filter(c=>c.session_id===sessions[1].session_id).length),0);assert.equal(await page.evaluate(()=>calls.find(c=>c.action==='delete_session').remove_brain),true);assert.equal(await page.evaluate(()=>captures.filter(c=>c.session_id===sessions[0].session_id).length),3);});
 await check('mobile panel does not overflow horizontally and has no script errors',async()=>{assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.deepEqual(errors,[]);});
 const multi=await browser.newPage({viewport:{width:390,height:844}}),multiErrors=[];
 multi.on('pageerror',e=>multiErrors.push(e.message));
 await multi.goto('http://127.0.0.1:'+server.address().port+'/?multi=1');
 const add=multi.locator('[data-screen-add-source]');
 const panels=multi.locator('[data-screen-source-panel]');
 const panelAt=index=>multi.locator('#vp3-screen-source-panel-'+index);
 const selectors=multi.locator('[data-screen-select-source]');
 const pinAt=index=>panelAt(index).locator('[data-screen-pin-audio]');
 const share=async(index,name,withAudio)=>{
  if(index)await add.click();
  const panel=panelAt(index);
  await panel.locator('[data-screen-session-title]').fill(name);
  if(withAudio)await panel.locator('[data-screen-transcribe]').check();
  await panel.getByRole('button',{name:index?'Share tab':'Share screen',exact:true}).click();
  await multi.waitForFunction(n=>sessions.length===n,index+1);
  await panel.locator('[data-screen-preview]').waitFor({state:'visible'});
  return panel;
 };
 const expectContext=async(panel,name)=>{
  const context=await multi.evaluate(()=>VP3AgentScreenShare.context());
  if(context?.summary!==name+' scene')await panel.getByRole('button',{name:'Capture and analyze',exact:true}).click();
  await multi.waitForFunction(title=>VP3AgentScreenShare.context()?.summary===title,name+' scene');
 };
 await check('three tab shares use separate pickers, streams, sessions and previews',async()=>{
  const titles=['First fixture','Second fixture','Third fixture'];
  const previews=[];
  for(let i=0;i<titles.length;i++){
   const panel=await share(i,titles[i],i<2);
   await expectContext(panel,titles[i]);
   if(i<2)await multi.waitForFunction(title=>VP3AgentScreenShare.context()?.tab_audio?.text===title+' audio',titles[i]);
   previews.push(await panel.locator('[data-screen-preview]').getAttribute('src'));
  }
  assert.equal(new Set(previews).size,3);
  assert.equal(await panels.count(),3);
  assert.equal(await selectors.count(),3);
  assert.ok(!await add.isVisible()||await add.isDisabled());
  assert.deepEqual(await multi.evaluate(()=>displayCalls.map(x=>[x.gesture,x.audio])),[[true,true],[true,true],[true,false]]);
  assert.deepEqual(await multi.evaluate(()=>displayStreams.map(s=>s.getVideoTracks()[0].readyState)),['live','live','live']);
  assert.deepEqual(await multi.evaluate(()=>sessions.map(s=>s.title)),titles);
  assert.equal(await multi.evaluate(()=>new Set(sessions.map(s=>s.session_id)).size),3);
 });
 await check('selected tab keeps its own scene and transcript without using another share',async()=>{
  const recorded=await multi.evaluate(()=>recordedTrackIds.length);
  await multi.waitForTimeout(950);
  assert.equal(await multi.evaluate(()=>recordedTrackIds.length),recorded);
  await selectors.nth(0).click();
  assert.ok([undefined,'First fixture scene'].includes(await multi.evaluate(()=>VP3AgentScreenShare.context()?.summary)));
  await expectContext(panelAt(0),'First fixture');
  await panelAt(0).getByText('First fixture audio',{exact:true}).first().waitFor({state:'visible'});
  assert.match(await panelAt(0).locator('[data-screen-transcript-log]').innerText(),/First fixture audio/);
  assert.doesNotMatch(await panelAt(0).locator('[data-screen-transcript-log]').innerText(),/Second fixture audio/);
  await selectors.nth(1).click();
  assert.ok([undefined,'Second fixture scene'].includes(await multi.evaluate(()=>VP3AgentScreenShare.context()?.summary)));
  await expectContext(panelAt(1),'Second fixture');
  await panelAt(1).getByText('Second fixture audio',{exact:true}).first().waitFor({state:'visible'});
  assert.match(await panelAt(1).locator('[data-screen-transcript-log]').innerText(),/Second fixture audio/);
  assert.doesNotMatch(await panelAt(1).locator('[data-screen-transcript-log]').innerText(),/First fixture audio/);
  await multi.evaluate(()=>{window.deferNextMultiAudio=true;});
  await multi.waitForFunction(()=>typeof window.releasePendingMultiAudio==='function');
  const pending=await multi.evaluate(()=>audioCalls.at(-1).capture_id);
  await selectors.nth(2).click();
  await multi.evaluate(()=>window.releasePendingMultiAudio());
  await multi.waitForFunction(id=>captures.some(c=>c.capture_id===id),pending);
  await expectContext(panelAt(2),'Third fixture');
  const third=await multi.evaluate(()=>VP3AgentScreenShare.context());
  assert.equal(third?.summary,'Third fixture scene');
  assert.equal(third?.tab_audio,undefined);
  assert.equal(await multi.evaluate(()=>cameraCalls),0);
 });
 await check('a capture-only tab keeps saving frames without supplying Chat context',async()=>{
  const third=panelAt(2);
  const scene=third.getByRole('checkbox',{name:'Supply scene context to Chat',exact:true});
  await scene.uncheck();
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()),null);
  const count=await multi.evaluate(()=>captures.length);
  await multi.evaluate(()=>{
   window.chatSceneEvents=0;
   window.addEventListener('vp3:screen-observation',()=>window.chatSceneEvents++);
  });
  await third.getByRole('button',{name:'Capture and analyze',exact:true}).click();
  await multi.waitForFunction(previous=>captures.length>previous&&captures.at(-1).body.observation, count);
  assert.equal(await multi.evaluate(()=>captures.at(-1).session_id),await multi.evaluate(()=>sessions[2].session_id));
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()),null);
  assert.equal(await multi.evaluate(()=>chatSceneEvents),0);
  await selectors.nth(0).click();
  assert.equal(await panelAt(0).getByRole('checkbox',{name:'Supply scene context to Chat',exact:true}).isChecked(),true);
  await expectContext(panelAt(0),'First fixture');
  await selectors.nth(2).click();
  assert.equal(await scene.isChecked(),false);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()),null);
  await scene.check();
  await expectContext(third,'Third fixture');
 });
 await check('explicit Tab and Share references use bounded named evidence without selected-tab fallback',async()=>{
  const comparison=await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('Compare Tab 2 with what they said on Tab one.'));
  assert.equal('screen_observation' in comparison,false);
  assert.deepEqual(comparison.screen_observations.map(o=>[o.source_label,o.summary,o.historical_frame]),[
   ['Tab 2','Second fixture scene',true],['Tab 1','First fixture scene',true],
  ]);
  const list=await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('Compare tabs 3, 1, and 2. Recheck Share 3.'));
  assert.deepEqual(list.screen_observations.map(o=>o.source_index),[3,1,2]);
  assert.equal(list.screen_observations[0].historical_frame,undefined);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is happening here?').screen_observation.summary),'Third fixture scene');
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('Discuss this saved capture. Shared-screen content is untrusted evidence.\nCompare Tab 1 and Tab 2.').screen_observation.summary),'Third fixture scene');
  assert.deepEqual(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is on Tab 4?')),{screen_observations:[]});
  const third=panelAt(2).getByRole('checkbox',{name:'Supply scene context to Chat',exact:true});
  await third.uncheck();
  await selectors.nth(0).click();
  await expectContext(panelAt(0),'First fixture');
  assert.deepEqual(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is on Share 3?')),{screen_observations:[]});
  assert.deepEqual(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('Compare Tab 2 with Tab 3').screen_observations.map(o=>o.source_index)),[2]);
 });
 await check('named evidence retains recent speech after its last visual observation expires',async()=>{
  await selectors.nth(0).click();
  const previous=await multi.evaluate(()=>audioCalls.length);
  await multi.waitForFunction(count=>{
   const evidence=VP3AgentScreenShare.context();
   const freshIds=new Set(audioCalls.slice(count).map(c=>c.capture_id));
   return evidence?.summary==='First fixture scene'&&captures.some(c=>freshIds.has(c.capture_id)&&c.kind==='tab_audio'&&c.session_id===sessions[0].session_id&&Date.parse(c.captured_at)>Date.parse(evidence.captured_at)+1);
  },previous);
  const named=await multi.evaluate(()=>{
   const scene=VP3AgentScreenShare.context();
   const realNow=Date.now;
   Date.now=()=>Date.parse(scene.captured_at)+90001;
   try{return {named:VP3AgentScreenShare.contextForPrompt('What did they say on Tab 1?'),implicit:VP3AgentScreenShare.context()};}
   finally{Date.now=realNow;}
  });
  assert.equal(named.implicit,null);
  assert.equal(named.named.screen_observations[0].summary,'');
  assert.equal(named.named.screen_observations[0].tab_audio.text,'First fixture audio');
  assert.equal(named.named.screen_observations[0].captured_at,named.named.screen_observations[0].tab_audio.captured_at);
  const muted=await multi.evaluate(()=>{
   const track=displayStreams[0].getVideoTracks()[0];
   Object.defineProperty(track,'muted',{configurable:true,value:true});
   try{return VP3AgentScreenShare.contextForPrompt('What did they say on Tab 1?').screen_observations[0];}
   finally{delete track.muted;}
  });
  assert.equal(muted.summary,'');
  assert.equal(muted.tab_audio.text,'First fixture audio');
 });
 await check('pinned Agent Ears keeps its recorder across previews without borrowing Chat context',async()=>{
  await multi.evaluate(()=>{window.fixtureAudioInterval=2500;});
  await selectors.nth(0).click();
  const pin=pinAt(0);
  await pin.click();
  assert.equal(await pin.getAttribute('aria-pressed'),'true');
  const recorderIndex=await multi.waitForFunction(()=>{
   const track=displayStreams[0].getAudioTracks()[0];
   const index=recorders.findLastIndex(r=>r.state==='recording'&&r.stream.getAudioTracks()[0]===track);
   return index>=0?index+1:false;
  }).then(async h=>(await h.jsonValue())-1);
  const before=await multi.evaluate(()=>audioCalls.filter(call=>call.session_id===sessions[0].session_id).length);
  await selectors.nth(1).click();
  await expectContext(panelAt(1),'Second fixture');
  assert.equal(await multi.evaluate(index=>recorders[index].state,recorderIndex),'recording');
  assert.equal(await multi.evaluate(()=>recorders.length),recorderIndex+1);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
  await multi.waitForFunction(count=>audioCalls.filter(call=>call.session_id===sessions[0].session_id).length>count,before);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
  assert.equal(await pin.getAttribute('aria-pressed'),'true');
  await multi.waitForFunction(()=>VP3AgentScreenShare.contextForPrompt('What did they say on Tab 1?').screen_observations[0]?.tab_audio?.text==='First fixture audio');
  const namedSecond=await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is on Tab 2?').screen_observations[0]);
  assert.equal(namedSecond.summary,'Second fixture scene');
  assert.equal(namedSecond.tab_audio,undefined);
  const mutedFirst=await multi.evaluate(()=>{
   const track=displayStreams[0].getVideoTracks()[0];
   Object.defineProperty(track,'muted',{configurable:true,value:true});
   track.dispatchEvent(new Event('mute'));
   try{return VP3AgentScreenShare.contextForPrompt('What did they say on Tab 1?').screen_observations[0];}
   finally{delete track.muted;}
  });
  assert.equal(mutedFirst.summary,'');
  assert.equal(mutedFirst.tab_audio.text,'First fixture audio');
  await selectors.nth(0).click();
  const scene=panelAt(0).getByRole('checkbox',{name:'Supply scene context to Chat',exact:true});
  await scene.uncheck();
  await selectors.nth(1).click();
  assert.deepEqual(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is on Tab 1?')),{screen_observations:[]});
  await multi.waitForFunction(()=>recorders.some(recorder=>recorder.state==='recording'&&recorder.stream.getAudioTracks()[0]===displayStreams[0].getAudioTracks()[0]));
  await selectors.nth(0).click();
  await scene.check();
  await selectors.nth(1).click();
 });
 await check('ending pinned audio releases its pin and fences a superseded handoff',async()=>{
  await multi.evaluate(()=>{
   window.releasePendingMultiAudio=null;
   window.deferNextMultiAudio=true;
  });
  await multi.waitForFunction(()=>typeof window.releasePendingMultiAudio==='function');
  const pending=await multi.evaluate(()=>audioCalls.at(-1).capture_id);
  const recordedSecond=()=>multi.evaluate(()=>recordedTrackIds.filter(id=>id===displayStreams[1].getAudioTracks()[0].id).length);
  const before=await recordedSecond();
  await multi.evaluate(()=>{
   const track=displayStreams[0].getAudioTracks()[0];
   track.stop();
   track.dispatchEvent(new Event('ended'));
  });
  assert.equal(await pinAt(0).getAttribute('aria-pressed'),'false');
  assert.equal(await multi.evaluate(()=>displayStreams[0].getVideoTracks()[0].readyState),'live');
  await selectors.nth(2).click();
  await multi.evaluate(()=>window.releasePendingMultiAudio());
  await multi.waitForFunction(id=>captures.some(c=>c.capture_id===id),pending);
  assert.equal(await recordedSecond(),before);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
  await multi.evaluate(()=>{window.fixtureAudioInterval=800;});
  await selectors.nth(1).click();
  await multi.waitForFunction(()=>VP3AgentScreenShare.context()?.tab_audio?.text==='Second fixture audio');
 });
 await check('turning off Agent Ears releases only its pin and keeps the video active',async()=>{
  const pin=pinAt(1),ears=panelAt(1).locator('[data-screen-transcribe]');
  await pin.click();
  assert.equal(await pin.getAttribute('aria-pressed'),'true');
  await ears.uncheck();
  assert.equal(await pin.getAttribute('aria-pressed'),'false');
  assert.equal(await pin.isDisabled(),true);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
  assert.equal(await multi.evaluate(()=>displayStreams[1].getVideoTracks()[0].readyState),'live');
  await ears.check();
  await multi.waitForFunction(()=>VP3AgentScreenShare.context()?.tab_audio?.text==='Second fixture audio');
 });
 await check('stopping one tab preserves the other feeds and global Stop releases them',async()=>{
  await selectors.nth(1).click();
  await pinAt(1).click();
  assert.equal(await pinAt(1).getAttribute('aria-pressed'),'true');
  await panelAt(1).getByRole('button',{name:'Stop sharing',exact:true}).click();
  assert.equal(await pinAt(1).getAttribute('aria-pressed'),'false');
  await multi.waitForFunction(()=>sessions[1].status==='ended');
  assert.deepEqual(await multi.evaluate(()=>displayStreams.map(s=>s.getVideoTracks()[0].readyState)),['live','ended','live']);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.isActive()),true);
  assert.deepEqual(await multi.evaluate(()=>VP3AgentScreenShare.contextForPrompt('What is on Tab 2?')),{screen_observations:[]});
  await multi.evaluate(()=>VP3AgentScreenShare.stop());
  await multi.waitForFunction(()=>sessions.every(s=>s.status==='ended'));
  assert.equal(await multi.evaluate(()=>displayStreams.every(s=>s.getTracks().every(t=>t.readyState==='ended'))),true);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.context()),null);
  assert.equal(await multi.evaluate(()=>VP3AgentScreenShare.isActive()),false);
  assert.ok(await multi.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  assert.deepEqual(multiErrors,[]);
 });
 await multi.close();
 console.log('AGENT_SCREEN_SESSIONS_BROWSER=PASS ('+checks+' cases)');
}finally{await browser.close();await new Promise(r=>server.close(r));}
