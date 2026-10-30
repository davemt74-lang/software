import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const script=fs.readFileSync(fs.existsSync('agent-screen-share.js')?'agent-screen-share.js':'ui/agent-screen-share.js','utf8');
const server=http.createServer((req,res)=>{
 if(req.url.startsWith('/agent-screen-share.js')){res.setHeader('Content-Type','text/javascript');res.end(script);return;}
 const owner=new URL(req.url,'http://fixture').searchParams.has('foreign')?'2':'1';
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px system-ui}.vp3-eyes-content{max-width:360px;padding:12px}img{max-width:100%}</style><div class="vp3-eyes-content"></div><script>
 window.displayCalls=[];window.cameraCalls=0;window.analysisCalls=[];window.label='Product A';
 window.VP3_ORB_ADAPTER={ownerKey:${JSON.stringify(owner)},screenRequest:async(body,{signal})=>{
  analysisCalls.push({body,signal});const result=()=>({observation:{contract:'vp3.screen-observation.v1',owner_key:window.wrongOwner?'2':${JSON.stringify(owner)},summary:label+' is visible',visible_text:label,site_hint:'Example store',page_title:label}});
  if(window.deferAnalysis)return new Promise(resolve=>window.releaseAnalysis=()=>resolve(result()));
  if(window.failAnalysis)throw Error('Configured model unavailable.');return result();
 }};
 const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;
 window.paint=(color=window.paintColor||'#fff')=>{window.paintColor=color;const ctx=canvas.getContext('2d');ctx.fillStyle=color;ctx.fillRect(0,0,640,360);ctx.fillStyle='#000';ctx.font='36px sans-serif';ctx.fillText(label,30,90);window.displayTrack?.requestFrame?.();};paint();
 const acquire=()=>{const s=canvas.captureStream(10);window.displayStream=s;window.displayTrack=s.getVideoTracks()[0];const settings=displayTrack.getSettings.bind(displayTrack);displayTrack.getSettings=()=>({...settings(),displaySurface:'window'});paint();setInterval(()=>paint(),100);return s;};
 Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{cameraCalls++;throw Error('No camera should be requested');},getDisplayMedia:options=>{
   displayCalls.push({options,gesture:navigator.userActivation.isActive});
   if(window.denyPicker)return Promise.reject(new DOMException('Denied','NotAllowedError'));
   if(window.deferPicker)return new Promise(resolve=>window.releasePicker=()=>resolve(acquire()));
   return Promise.resolve(acquire());
 }}});
 </script><script src="/agent-screen-share.js"></script>`);
});await new Promise(r=>server.listen(0,'127.0.0.1',r));const url=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let checks=0;
const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(url);await page.clock.install();
 await check('loading Eyes does not request display, camera or microphone',async()=>{assert.equal(await page.evaluate(()=>displayCalls.length+cameraCalls+analysisCalls.length),0);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()),null);});
 await check('Share screen invokes the picker in the click gesture without audio',async()=>{
  await page.getByRole('button',{name:'Share screen',exact:true}).click();await page.waitForFunction(()=>VP3AgentScreenShare.context());
  const first=await page.evaluate(()=>displayCalls[0]);assert.equal(first.gesture,true);assert.equal(first.options.audio,false);assert.equal(first.options.monitorTypeSurfaces,'include');assert.equal(await page.evaluate(()=>cameraCalls),0);
  assert.match(await page.locator('[data-screen-preview]').getAttribute('src'),/^data:image\/jpeg;base64,/);
 });
 await check('Chat receives bounded description and timestamp without screenshot pixels',async()=>{
  const context=await page.evaluate(()=>VP3AgentScreenShare.context());assert.equal(context.summary,'Product A is visible');assert.ok(Date.parse(context.captured_at));assert.equal(context.surface,'window');assert.equal(JSON.stringify(context).includes('base64'),false);
  assert.equal(await page.evaluate(()=>analysisCalls[0].body.image.startsWith('data:image/jpeg;base64,')),true);
 });
 await check('unchanged frames avoid repeated model requests and hidden tabs keep sharing',async()=>{
  await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});
  await page.clock.runFor(31000);assert.equal(await page.evaluate(()=>analysisCalls.length),1);assert.equal(await page.evaluate(()=>VP3AgentScreenShare.isActive()),true);assert.ok(await page.evaluate(()=>VP3AgentScreenShare.context()));
 });
 await check('a changed visible surface replaces prior page context',async()=>{
  await page.evaluate(()=>{label='Product B';paint('#000');});await page.waitForTimeout(150);await page.clock.runFor(6000);await page.waitForFunction(()=>VP3AgentScreenShare.context()?.page_title==='Product B');
  assert.equal(await page.evaluate(()=>analysisCalls.length),2);
 });
 await check('same-account embedded Chat can read current shared context',async()=>{
  await page.evaluate(()=>{const f=document.createElement('iframe');f.src='/embed?orb_embed=1';document.body.append(f);});const frame=page.frames().find(f=>f.url().includes('orb_embed'))||page.frames()[1];await frame.waitForFunction(()=>window.VP3AgentScreenShare);assert.equal((await frame.evaluate(()=>VP3AgentScreenShare.context())).summary,'Product B is visible');
  await page.evaluate(()=>{const f=document.createElement('iframe');f.src='/embed?orb_embed=1&foreign=1';document.body.append(f);});await page.waitForTimeout(100);const foreign=page.frames().find(f=>f.url().includes('foreign'));await foreign.waitForFunction(()=>window.VP3AgentScreenShare);assert.equal(await foreign.evaluate(()=>VP3AgentScreenShare.context()),null);
 });
 await check('Stop clears context and pixels and releases the display track',async()=>{
  await page.getByRole('button',{name:'Stop sharing'}).click();assert.equal(await page.evaluate(()=>displayTrack.readyState),'ended');assert.equal(await page.evaluate(()=>VP3AgentScreenShare.context()),null);assert.equal(await page.locator('[data-screen-preview]').getAttribute('src'),null);
  const child=page.frames().find(f=>f.url().includes('orb_embed')&&!f.url().includes('foreign'));assert.equal(await child.evaluate(()=>VP3AgentScreenShare.context()),null);
 });
 await page.close();
 const pending=await browser.newPage();await pending.goto(url);await pending.evaluate(()=>window.deferPicker=true);await pending.getByRole('button',{name:'Share screen',exact:true}).click();
 await check('Stop while picker is pending disposes a late granted stream',async()=>{await pending.getByRole('button',{name:'Stop sharing'}).click();await pending.evaluate(()=>releasePicker());await pending.waitForFunction(()=>window.displayTrack?.readyState==='ended');assert.equal(await pending.evaluate(()=>analysisCalls.length),0);assert.equal(await pending.evaluate(()=>VP3AgentScreenShare.isActive()),false);});await pending.close();
 const late=await browser.newPage();await late.goto(url);await late.evaluate(()=>window.deferAnalysis=true);await late.getByRole('button',{name:'Share screen',exact:true}).click();await late.waitForFunction(()=>typeof window.releaseAnalysis==='function');
 await check('Stop fences an analysis result even if the transport ignores cancellation',async()=>{await late.getByRole('button',{name:'Stop sharing'}).click();await late.evaluate(()=>releaseAnalysis());await late.waitForTimeout(50);assert.equal(await late.evaluate(()=>VP3AgentScreenShare.context()),null);assert.equal(await late.evaluate(()=>analysisCalls[0].signal.aborted),true);});await late.close();
 const failure=await browser.newPage();await failure.goto(url);
 await check('permission denial is retryable without starting capture',async()=>{await failure.evaluate(()=>window.denyPicker=true);await failure.getByRole('button',{name:'Share screen',exact:true}).click();await failure.getByText('Screen sharing was cancelled or not allowed. Select Share screen to retry.').waitFor();assert.equal(await failure.evaluate(()=>VP3AgentScreenShare.isActive()),false);});
 await check('provider failure leaves sharing active but supplies no invented context',async()=>{await failure.evaluate(()=>{denyPicker=false;window.failAnalysis=true;});await failure.getByRole('button',{name:'Share screen',exact:true}).click();await failure.getByText('Sharing continues. Configured model unavailable.').waitFor();assert.equal(await failure.evaluate(()=>VP3AgentScreenShare.isActive()),true);assert.equal(await failure.evaluate(()=>VP3AgentScreenShare.context()),null);});
 await check('manual analysis works with automatic analysis disabled',async()=>{await failure.locator('[data-screen-auto]').uncheck();await failure.evaluate(()=>window.failAnalysis=false);await failure.getByRole('button',{name:'Capture and analyze',exact:true}).click();await failure.waitForFunction(()=>VP3AgentScreenShare.context());});
 await check('browser Stop Sharing and page unload release capture',async()=>{await failure.evaluate(()=>{displayTrack.stop();displayTrack.dispatchEvent(new Event('ended'));});assert.equal(await failure.evaluate(()=>VP3AgentScreenShare.isActive()),false);await failure.getByRole('button',{name:'Share screen',exact:true}).click();await failure.waitForFunction(()=>VP3AgentScreenShare.isActive());await failure.evaluate(()=>window.dispatchEvent(new Event('pagehide')));assert.equal(await failure.evaluate(()=>displayTrack.readyState),'ended');});
 await failure.close();assert.deepEqual(errors,[]);console.log(`AGENT_SCREEN_SHARE_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
