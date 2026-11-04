import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const scripts=['agent-screen-watch-zone.js','agent-screen-share.js'];
const server=http.createServer((req,res)=>{
  const script=req.url.slice(1);
  if(scripts.includes(script)){
    res.setHeader('Content-Type','text/javascript; charset=utf-8');
    res.end(fs.readFileSync(script));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');
  res.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>${fs.readFileSync('agent-orb.css','utf8')}body{margin:0;background:#152434;color:#fff}.vp3-eyes-content{max-width:430px;padding:8px}</style>
    <div class="vp3-eyes-content"></div>
    <script>
      window.streams=[];window.calls=[];window.sessions=[];window.captures=[];window.colors=[{outside:'#aaccee',inside:'#f2b400'},{outside:'#eeeeee',inside:'#ef2266'}];
      Object.defineProperty(navigator,'mediaDevices',{value:{getDisplayMedia:async()=>{
        const palette=colors[sessions.length]||colors[0];
        const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;
        const ctx=canvas.getContext('2d');
        const paint=()=>{
          ctx.fillStyle=palette.outside;ctx.fillRect(0,0,640,360);
          ctx.fillStyle=palette.inside;ctx.fillRect(80,60,245,160);
        };
        paint();const timer=setInterval(paint,80);
        const stream=canvas.captureStream(12),track=stream.getVideoTracks()[0],get=track.getSettings.bind(track);
        track.getSettings=()=>({...get(),displaySurface:'browser'});
        const stop=track.stop.bind(track);track.stop=()=>{clearInterval(timer);stop();};
        streams.push(stream);return stream;
      }}});
      window.VP3_ORB_ADAPTER={ownerKey:'1',screenRequest:async()=>({}),screenHistory:async body=>{
        calls.push(body);
        if(body.action==='list')return {sessions};
        if(body.action==='start'){
          const session={session_id:body.session_id,title:body.title,created_at:new Date().toISOString(),status:'active'};
          sessions.push(session);return {session};
        }
        if(body.action==='end')return {ended:true};
        const session=sessions.find(s=>s.session_id===body.session_id);
        if(body.action==='timeline')return {session,captures:[]};
        if(body.action==='history')return {session,captures:captures.filter(c=>c.session_id===body.session_id).slice().reverse()};
        if(body.action==='capture'&&body.image){
          const existing=captures.find(c=>c.capture_id===body.capture_id);
          if(existing)return {capture:existing};
          const capture={session_id:body.session_id,capture_id:body.capture_id,captured_at:body.captured_at,kind:'screen',body:{watch_region:body.watch_region}};
          captures.push(capture);
          if(body.watch_region&&window.dropCropResponse){window.dropCropResponse=false;throw new TypeError('Connection lost after saving');}
          return {capture};
        }
        const capture=captures.find(c=>c.capture_id===body.capture_id);
        if(body.action==='analyze')capture.body.observation={contract:'vp3.screen-observation.v1',owner_key:'1',summary:'Full frame analyzed'};
        return {capture};
      }};
    </script>${scripts.map(script=>'<script src="/'+script+'"></script>').join('')}`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
let browser;
try{
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
  const page=await browser.newPage({viewport:{width:460,height:860}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:'+server.address().port);
  const panel=index=>page.locator('#vp3-screen-source-panel-'+index);
  const watchCalls=()=>page.evaluate(()=>calls.filter(call=>call.watch_region));
  const draw=async(source)=>{
    await source.locator('[data-screen-watch-draw]').click();
    const preview=source.locator('[data-screen-preview]');
    await preview.scrollIntoViewIfNeeded();
    const {x,y,width,height}=await preview.boundingBox();
    await page.mouse.move(x+width*.18,y+height*.24);
    await page.mouse.down();await page.mouse.move(x+width*.43,y+height*.54,{steps:5});await page.mouse.up();
    await source.locator('.vp3-screen-watch-box').waitFor({state:'visible'});
  };

  await panel(0).getByRole('button',{name:'Share screen',exact:true}).click();
  await panel(0).locator('[data-screen-preview]').waitFor({state:'visible'});
  await page.waitForFunction(()=>calls.some(c=>c.action==='analyze'));
  await panel(0).getByRole('checkbox',{name:'Analyze shared frames automatically'}).uncheck();
  const tabStatus=page.locator('[data-screen-source-status]').first();
  await tabStatus.getByText(/Previewing/).waitFor();
  assert.deepEqual(await watchCalls(),[]);
  await draw(panel(0));
  assert.match(await tabStatus.innerText(),/Capturing/);
  await page.evaluate(()=>{colors[0].outside='#7722ee';});
  await page.waitForTimeout(1250);
  assert.deepEqual(await watchCalls(),[]);
  console.log('PASS a watch zone is opt-in and ignores changes outside the box');

  await page.evaluate(()=>{colors[0].inside='#16cc48';});
  await page.waitForFunction(()=>calls.some(c=>c.watch_region));
  const result=await page.evaluate(async()=>{
    const request=calls.find(c=>c.watch_region);
    const img=new Image();img.src=request.image;await img.decode();
    const canvas=document.createElement('canvas');canvas.width=img.width;canvas.height=img.height;
    const ctx=canvas.getContext('2d');ctx.drawImage(img,0,0);
    return {region:request.watch_region,session:request.session_id,expected:sessions[0].session_id,
      width:img.width,height:img.height,pixel:[...ctx.getImageData(img.width/2,img.height/2,1,1).data]};
  });
  assert.equal(result.session,result.expected);
  for(const [key,expected] of Object.entries({x:.18,y:.24,width:.25,height:.30}))assert.ok(Math.abs(result.region[key]-expected)<.01,key);
  assert.ok(result.width>=160&&result.width<640&&result.height>=90&&result.height<360);
  assert.ok(result.pixel[1]>180&&result.pixel[0]<45&&result.pixel[2]<110,'Expected pixels from the selected green zone');
  await panel(0).locator('[data-screen-history-panel]').evaluate(el=>{el.open=true;});
  await panel(0).getByText('Watched zone capture',{exact:false}).first().waitFor();
  assert.equal(await page.evaluate(()=>calls.filter(c=>c.action==='analyze').length),1);
  console.log('PASS stable changes save actual cropped pixels in the current session history without a model call');

  await page.evaluate(()=>{colors[0].inside='#0846e7';});
  await page.waitForTimeout(1350);
  assert.equal((await watchCalls()).length,1);
  await page.locator('[data-screen-add-source]').click();
  await panel(1).getByRole('button',{name:'Share tab',exact:true}).click();
  await panel(1).locator('[data-screen-preview]').waitFor({state:'visible'});
  assert.equal(await panel(1).locator('.vp3-screen-watch-box').isVisible(),false);
  await page.evaluate(()=>{colors[0].inside='#ffffff';});
  await page.waitForTimeout(5100);
  assert.equal((await watchCalls()).length,1);
  await page.locator('[data-screen-select-source]').first().click();
  await panel(0).locator('.vp3-screen-watch-box').waitFor({state:'visible'});
  await page.waitForTimeout(650);
  assert.equal((await watchCalls()).length,1);
  await page.evaluate(()=>{colors[0].inside='#f08080';});
  await page.waitForFunction(()=>calls.filter(c=>c.watch_region).length===2);
  await panel(0).locator('[data-screen-watch-clear]').click();
  assert.equal(await panel(0).locator('.vp3-screen-watch-box').isVisible(),false);
  assert.match(await tabStatus.innerText(),/Previewing/);
  await page.evaluate(()=>{colors[0].inside='#000000';});
  await page.waitForTimeout(1150);
  assert.equal((await watchCalls()).length,2);
  console.log('PASS rate limit, per-tab isolation, pause, re-baseline, and clear');

  await draw(panel(0));
  await page.evaluate(()=>{window.dropCropResponse=true;colors[0].inside='#9c34ee';});
  const status=panel(0).locator('[data-screen-watch-status]');
  await status.getByText(/Retrying once/).waitFor();
  await status.getByText(/saved to My Knowledge/).waitFor();
  const retry=(await watchCalls()).slice(2);
  assert.equal(retry.length,2);
  assert.deepEqual(retry[0],retry[1]);
  assert.equal(await page.evaluate(()=>captures.filter(c=>c.body.watch_region).length),3);
  assert.deepEqual(errors,[]);
  console.log('PASS a lost save response retries the same crop once without a duplicate');
  await page.evaluate(()=>{
    const track=streams[0].getVideoTracks()[0];
    Object.defineProperty(track,'muted',{configurable:true,value:true});
    track.dispatchEvent(new Event('mute'));
  });
  assert.match(await status.innerText(),/paused/);
  assert.equal(await panel(0).locator('[data-screen-preview]').isVisible(),false);
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true})));
  assert.equal(await page.evaluate(()=>streams.every(s=>s.getTracks().every(t=>t.readyState==='ended'))),true);
  assert.equal(await panel(0).locator('.vp3-screen-watch-box').isVisible(),false);
  console.log('PASS native display mute pauses the zone and pagehide clears it');
  await panel(0).getByRole('button',{name:'Share screen',exact:true}).click();
  await panel(0).locator('[data-screen-preview]').waitFor({state:'visible'});
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true})));
  assert.equal(await page.evaluate(()=>streams.every(s=>s.getTracks().every(t=>t.readyState==='ended'))),true);
  console.log('PASS repeated pagehide cleans up another share after restoration');
}finally{
  await browser?.close();
  await new Promise(resolve=>server.close(resolve));
}
