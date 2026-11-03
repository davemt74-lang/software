import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const script=fs.readFileSync('agent-screen-share.js','utf8'),css=fs.readFileSync('agent-orb.css','utf8');
const server=http.createServer((req,res)=>{
 if(req.url==='/agent-screen-share.js'){res.setHeader('Content-Type','text/javascript');res.end(script);return;}
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}body{margin:0;background:#152434;color:#fff}.vp3-eyes-content{width:100%;max-width:360px;box-sizing:border-box;padding:8px}</style><div class="vp3-eyes-content"></div><textarea id="chatInput"></textarea><script>
 window.calls=[];window.sessions=[];window.captures=[];window.audioCalls=[];window.cameraCalls=0;window.displayCalls=[];window.deleted=[];
 const obs=()=>({contract:'vp3.screen-observation.v1',owner_key:'1',summary:'Song <img> at 1:20',visible_text:'Song, artist, 1:20 / 4:00',site_hint:'Player',page_title:'Song',observations:[{kind:'music',label:'Position',detail:'1:20 of 4:00 displayed',confidence:'high'}]});
 const realTimeout=window.setTimeout.bind(window);window.setTimeout=(fn,ms,...args)=>realTimeout(fn,ms===15000?800:ms,...args);
 window.VP3_ORB_ADAPTER={ownerKey:'1',conversationRef:()=>({id:7}),screenRequest:async()=>({observation:obs()}),screenToChat:text=>{document.querySelector('#chatInput').value=text;return true;},screenHistory:async(body,{signal}={})=>{
 calls.push({...body});if(body.action==='list')return {sessions:sessions.filter(s=>!deleted.includes(s.session_id))};
 if(body.action==='start'){const s={session_id:body.session_id,title:body.title,status:'active',created_at:new Date().toISOString(),folder_id:12,folder_name:'Music research',knowledge_url:'/knowledge.php?folder=12&screen_session='+body.session_id};sessions.push(s);return {session:s};}
 if(body.action==='end'){const s=sessions.find(s=>s.session_id===body.session_id);if(s)s.status='ended';return {ended:true};}
 if(body.action==='delete_session'){deleted.push(body.session_id);captures=captures.filter(c=>c.session_id!==body.session_id);return {deleted:true};}
 if(body.action==='history')return {session:sessions.find(s=>s.session_id===body.session_id),captures:captures.filter(c=>c.session_id===body.session_id).slice().reverse()};
 if(body.action==='capture'&&body.image){const c={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'screen',memory_id:0,body:{image:body.image,note:body.note}};captures.push(c);return {capture:c};}
 const c=captures.find(c=>c.capture_id===body.capture_id);if(!c)throw Error('Capture unavailable');
 if(body.action==='analyze'){if(window.deferAnalysis)await new Promise(resolve=>window.releaseAnalysis=resolve);if(deleted.includes(c.session_id))throw Error('Session deleted');c.body.observation=obs();return {capture:c};}
 if(body.action==='brain'){c.memory_id=44;return {capture:c};}return {capture:c};
 },screenAudio:async(blob,body)=>{audioCalls.push({bytes:blob.size,type:blob.type,...body});const c={capture_id:body.capture_id,session_id:body.session_id,captured_at:body.captured_at,kind:'tab_audio',memory_id:0,body:{transcript:'A useful spoken idea from the tab'}};captures.push(c);return {capture:c};}};
 const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;const paint=()=>{const ctx=canvas.getContext('2d');ctx.fillStyle='#abc';ctx.fillRect(0,0,640,360);ctx.fillStyle='#000';ctx.font='30px sans-serif';ctx.fillText('Song progress 1:20 / 4:00',20,100);};paint();setInterval(paint,100);
 Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{cameraCalls++;throw Error('No microphone');},getDisplayMedia:async options=>{displayCalls.push(options);const s=canvas.captureStream(10),t=s.getVideoTracks()[0];window.displayTrack=t;const get=t.getSettings.bind(t);t.getSettings=()=>({...get(),displaySurface:'browser'});if(options.audio){const ctx=new AudioContext();await ctx.resume();const osc=ctx.createOscillator(),dest=ctx.createMediaStreamDestination();osc.connect(dest);osc.start();s.addTrack(dest.stream.getAudioTracks()[0]);window.sourceContext=ctx;}return s;}}});
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
 await check('manual capture stores user intent without inventing analysis',async()=>{assert.equal(await page.evaluate(()=>captures[1].body.note),'Compare this song');assert.equal(await page.evaluate(()=>captures[1].body.observation),undefined);});
 await page.getByRole('button',{name:'Capture and analyze',exact:true}).click();await page.waitForFunction(()=>captures.length===3&&captures[2].body.observation);
 await page.locator('[data-screen-history] [data-capture-action="brain"]').first().click();await page.waitForFunction(()=>captures.some(c=>c.memory_id===44));
 await check('Brain save and Send to Chat are explicit actions',async()=>{await page.locator('[data-screen-history] [data-capture-action="chat"]').first().click();assert.match(await page.locator('#chatInput').inputValue(),/Captured: /);assert.match(await page.locator('#chatInput').inputValue(),/Session: /);});
 await page.getByRole('button',{name:'Stop sharing',exact:true}).click();await page.waitForFunction(()=>sessions[0].status==='ended');
 await check('stop releases the display and keeps session history',async()=>{assert.equal(await page.evaluate(()=>displayTrack.readyState),'ended');assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()),null);assert.equal(await page.evaluate(()=>captures.length),3);});
 await page.locator('[data-screen-transcribe]').check();await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>audioCalls.length>0);await page.waitForFunction(()=>VP3AgentScreenShare.context()?.tab_audio);
 await check('shared-tab audio uses real MediaRecorder bytes without opening a microphone',async()=>{const data=await page.evaluate(()=>({audio:displayCalls[1].audio,call:audioCalls[0],mic:cameraCalls}));assert.equal(data.audio,true);assert.ok(data.call.bytes>32);assert.match(data.call.type,/audio\//);assert.equal(data.mic,0);assert.match((await page.evaluate(()=>VP3AgentScreenShare.context())).tab_audio.text,/spoken idea/);});
 await page.locator('[data-screen-transcribe]').uncheck();assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()?.tab_audio),undefined);
 await check('disabling transcription clears current audio context',async()=>{await page.locator('[data-screen-audio-status]').getByText('Tab transcription is off.',{exact:true}).waitFor();});
 await page.locator('[data-screen-history-panel]').evaluate(e=>e.open=true);await page.waitForFunction(()=>document.querySelector('[data-screen-sessions]').value===sessions[1].session_id);
 page.on('dialog',d=>d.accept());await page.locator('[data-screen-delete]').click();await page.waitForFunction(()=>deleted.length===1);
 await check('delete session removes selected history and sends the Brain removal choice',async()=>{assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),false);assert.equal(await page.evaluate(()=>captures.filter(c=>c.session_id===sessions[1].session_id).length),0);assert.equal(await page.evaluate(()=>calls.find(c=>c.action==='delete_session').remove_brain),true);assert.equal(await page.evaluate(()=>captures.filter(c=>c.session_id===sessions[0].session_id).length),3);});
 await check('mobile panel does not overflow horizontally and has no script errors',async()=>{assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));assert.deepEqual(errors,[]);});
 console.log('AGENT_SCREEN_SESSIONS_BROWSER=PASS ('+checks+' cases)');
}finally{await browser.close();await new Promise(r=>server.close(r));}
