import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const scripts=['agent-screen-watch-zone.js','agent-screen-share.js'];
const server=http.createServer((req,res)=>{
  if(req.url.startsWith('/fixture-target?')){
    res.setHeader('Content-Type','text/html; charset=utf-8');
    res.end('<!doctype html><title>Fixture source page</title><main>Saved source target</main>');
    return;
  }
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
      const owner=new URLSearchParams(location.search).get('owner')||'1';
      window.streams=[];window.calls=[];window.sessions=[];window.captures=[];window.displayRequests=[];window.colors=[{outside:'#aaccee',inside:'#f2b400'},{outside:'#eeeeee',inside:'#ef2266'}];
      Object.defineProperty(navigator,'mediaDevices',{value:{getDisplayMedia:async options=>{
        displayRequests.push({audio:options.audio,gesture:navigator.userActivation.isActive});
        if(window.cancelDisplayRequest){window.cancelDisplayRequest=false;throw new DOMException('Picker cancelled','NotAllowedError');}
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
      window.VP3_ORB_ADAPTER={ownerKey:owner,screenRequest:async()=>({}),screenAudio:async()=>({}),screenHistory:async body=>{
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
        if(body.action==='analyze')capture.body.observation={contract:'vp3.screen-observation.v1',owner_key:owner,summary:'Full frame analyzed'};
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
  const address='http://127.0.0.1:'+server.address().port;
  await page.goto(address);
  const panel=index=>page.locator('#vp3-screen-source-panel-'+index);
  const watchCalls=()=>page.evaluate(()=>calls.filter(call=>call.watch_region));
  const draw=async(source,onPage=page,region={x:.18,y:.24,width:.25,height:.30})=>{
    await source.locator('[data-screen-watch-draw]').click();
    const preview=source.locator('[data-screen-preview]');
    await preview.scrollIntoViewIfNeeded();
    const {x,y,width,height}=await preview.boundingBox();
    await onPage.mouse.move(x+width*region.x,y+height*region.y);
    await onPage.mouse.down();
    await onPage.mouse.move(x+width*(region.x+region.width),y+height*(region.y+region.height),{steps:5});
    await onPage.mouse.up();
    await source.locator('.vp3-screen-watch-box').waitFor({state:'visible'});
  };

  await panel(0).getByRole('button',{name:'Share screen',exact:true}).click();
  await panel(0).locator('[data-screen-preview]').waitFor({state:'visible'});
  await page.waitForFunction(()=>calls.some(c=>c.action==='analyze'));
  await panel(0).getByRole('checkbox',{name:'Analyze shared frames automatically'}).uncheck();
  const tabStatus=page.locator('[data-screen-source-status]').first();
  await page.waitForFunction(()=>document.querySelector('[data-screen-source-status]').textContent.includes('Previewing'));
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

  const setupContext=await browser.newContext({viewport:{width:320,height:900}});
  const setupPage=await setupContext.newPage();
  setupPage.on('pageerror',error=>errors.push(error.message));
  await setupPage.goto(address);
  const setupPanel=index=>setupPage.locator('#vp3-screen-source-panel-'+index);
  const setupTabs=setupPage.getByRole('tab',{name:/^(Share|Tab) \d/});
  const saveSetup=setupPage.locator('[data-screen-layout-save]');
  const loadSetup=setupPage.locator('[data-screen-layout-load]');
  const setupName=setupPage.locator('[data-screen-layout-name]');
  const setupPicker=setupPage.locator('[data-screen-layout-select]');
  const updateSetup=setupPage.locator('[data-screen-layout-update]');
  const loadCommand=target=>setupPage.evaluate(value=>window.VP3AgentScreenShare.loadSetupCommand(value),target);
  const primary=setupPanel(0),secondary=setupPanel(1),third=setupPanel(2);
  const primaryUrl=address+'/fixture-target?tab=primary&context='+'a'.repeat(1950)+'#start';
  const secondaryUrl=address+'/fixture-target?tab=secondary&context='+'b'.repeat(1950);
  const targetRequests=[];
  setupContext.on('request',request=>{
    if(request.url().startsWith(address+'/fixture-target?'))targetRequests.push(request.url().split('#')[0]);
  });
  const sourceUrl=source=>source.locator('[data-screen-source-url]');
  const openPage=source=>source.locator('[data-screen-open-url]');
  const firstRegion={x:.18,y:.24,width:.25,height:.30};
  const secondRegion={x:.24,y:.31,width:.18,height:.22};
  const preview=source=>source.locator('[data-screen-preview]');
  const watchBox=source=>source.locator('.vp3-screen-watch-box');
  const resumeWatch=source=>source.locator('[data-screen-watch-resume]');
  async function nameSlot(source,name,title){
    await source.locator('[data-screen-tab-name]').fill(name);
    await source.locator('[data-screen-session-title]').fill(title);
  }
  async function expectRegion(source,expected){
    const region=await watchBox(source).evaluate(box=>Object.fromEntries(
      Object.entries({x:'left',y:'top',width:'width',height:'height'}).map(([key,style])=>[key,parseFloat(box.style[style])/100])
    ));
    for(const [key,value] of Object.entries(expected))assert.ok(Math.abs(region[key]-value)<.015,key);
  }

  assert.equal(await loadSetup.isDisabled(),true);
  await nameSlot(primary,'Primary with a longer descriptive name','Main review session');
  await sourceUrl(primary).fill(primaryUrl);
  assert.equal(await openPage(primary).isVisible(),true);
  await primary.locator('[data-screen-auto]').uncheck();
  await primary.locator('[data-screen-chat-context]').uncheck();
  await primary.locator('[data-screen-transcribe]').check();
  await primary.getByRole('button',{name:'Share screen',exact:true}).click();
  await preview(primary).waitFor({state:'visible'});
  await draw(primary,setupPage,firstRegion);
  await setupPage.locator('[data-screen-add-source]').click();
  await nameSlot(secondary,'Reference','Second review session');
  await sourceUrl(secondary).fill(secondaryUrl);
  await secondary.locator('[data-screen-auto]').uncheck();
  await secondary.getByRole('button',{name:'Share tab',exact:true}).click();
  await preview(secondary).waitFor({state:'visible'});
  await draw(secondary,setupPage,secondRegion);
  await setupPage.locator('[data-screen-add-source]').click();
  await nameSlot(third,'No zone','Third review session');
  assert.equal(await setupTabs.count(),3);
  const geometry=await setupTabs.evaluateAll(tabs=>tabs.map(tab=>{
    const {x,y,right}=tab.getBoundingClientRect();
    return {x,y,right,status:document.getElementById(tab.getAttribute('aria-describedby'))?.textContent,
      icons:[...tab.querySelectorAll('[data-screen-source-icon]')].map(icon=>icon.dataset.screenSourceIcon)};
  }));
  assert.ok(geometry.every(tab=>Math.abs(tab.y-geometry[0].y)<1),'All three tabs share a single mobile row');
  assert.ok(geometry.every((tab,i)=>!i||tab.x>=geometry[i-1].right),'Neighboring tabs do not overlap');
  assert.ok(geometry.every(tab=>tab.right<=320&&tab.status.includes('Chat context')));
  for(const tab of geometry)assert.deepEqual(tab.icons,['visual','ears','chat']);
  assert.ok(await setupPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await setupTabs.first().focus();
  await setupPage.keyboard.press('End');
  assert.equal(await setupTabs.last().getAttribute('aria-selected'),'true');
  assert.equal(await setupTabs.last().evaluate(tab=>tab===document.activeElement),true);
  const lastTooltip=setupTabs.last().getByRole('tooltip');
  assert.equal(await lastTooltip.evaluate(tip=>getComputedStyle(tip).clipPath),'none');
  await setupPage.keyboard.press('Escape');
  assert.notEqual(await lastTooltip.evaluate(tip=>getComputedStyle(tip).clipPath),'none');
  console.log('PASS three tabs fit one mobile row with keyboard navigation and accessible status');

  assert.deepEqual(await setupPage.evaluate(()=>calls.filter(c=>c.watch_region)),[]);
  await setupName.fill('Morning Review');
  await saveSetup.click();
  assert.equal(await loadSetup.isDisabled(),true,'Loading cannot replace a live share');
  assert.equal(await setupPicker.isVisible(),false,'One saved setup does not need a dropdown');
  const storedPreset=await setupPage.evaluate(()=>Object.entries(localStorage));
  assert.ok(storedPreset.some(([,value])=>value.length>4096),'Valid long URLs fit alongside the other preset fields');
  await setupName.fill('Unsafe layout');
  for(const invalid of ['javascript:alert(1)',address.replace('://','://user:password@')+'/fixture-target?tab=unsafe']){
    await sourceUrl(third).fill(invalid);
    assert.equal(await openPage(third).isVisible(),false);
    await saveSetup.click();
    assert.deepEqual(await setupPage.evaluate(()=>Object.entries(localStorage)),storedPreset);
  }
  await sourceUrl(third).fill('');
  console.log('PASS unsafe URL schemes and credentialed URLs cannot be opened or saved');

  const ownerPage=await setupContext.newPage();
  await ownerPage.goto(address+'/?owner=2');
  assert.equal(await ownerPage.locator('[data-screen-layout-load]').isDisabled(),true,'Another account cannot load this preset');
  await ownerPage.close();

  await setupPage.reload();
  assert.equal(await setupTabs.count(),1,'A saved setup requires an explicit Load action');
  await loadSetup.click();
  assert.equal(await setupTabs.count(),3);
  assert.deepEqual(await setupPage.evaluate(()=>[displayRequests.length,calls.filter(c=>c.action==='start').length]),[0,0]);
  assert.equal(setupContext.pages().length,1);
  assert.deepEqual(targetRequests,[]);
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'Primary with a longer descriptive name');
  assert.equal(await primary.locator('[data-screen-session-title]').inputValue(),'Main review session');
  assert.equal(await sourceUrl(primary).inputValue(),primaryUrl);
  assert.equal(await primary.locator('[data-screen-chat-context]').isChecked(),false);
  assert.equal(await primary.locator('[data-screen-transcribe]').isChecked(),true);
  assert.equal(await secondary.locator('[data-screen-tab-name]').inputValue(),'Reference');
  assert.equal(await secondary.locator('[data-screen-session-title]').inputValue(),'Second review session');
  assert.equal(await sourceUrl(secondary).inputValue(),secondaryUrl);
  assert.equal(await secondary.locator('[data-screen-chat-context]').isChecked(),true);
  assert.equal(await secondary.locator('[data-screen-transcribe]').isChecked(),false);
  assert.equal(await third.locator('[data-screen-tab-name]').inputValue(),'No zone');
  assert.equal(await sourceUrl(third).inputValue(),'');
  console.log('PASS an account-scoped saved setup restores three idle slots without starting capture');

  await setupTabs.first().click();
  assert.equal(await openPage(primary).getAttribute('href'),primaryUrl);
  const [opened]=await Promise.all([setupContext.waitForEvent('page'),openPage(primary).click()]);
  await opened.waitForURL(primaryUrl);
  assert.equal(await opened.title(),'Fixture source page');
  assert.deepEqual(await opened.evaluate(()=>[window.opener,document.referrer]),[null,'']);
  assert.equal(setupPage.url(),address+'/');
  assert.deepEqual(targetRequests,[primaryUrl.split('#')[0]]);
  assert.deepEqual(await setupPage.evaluate(()=>[displayRequests.length,calls.filter(c=>c.action==='start').length]),[0,0]);
  await opened.close();
  console.log('PASS restored URLs stay idle until Open page opens a separate tab with no opener or picker');

  await setupPage.evaluate(()=>{window.cancelDisplayRequest=true;});
  const reconnect=primary.getByRole('button',{name:'Share screen',exact:true});
  await reconnect.click();
  await primary.locator('[data-screen-status]').getByText(/cancelled/).waitFor();
  assert.deepEqual(await setupPage.evaluate(()=>[displayRequests,calls.filter(c=>c.action==='start').length]),[[{audio:true,gesture:true}],0]);
  assert.equal(await preview(primary).isVisible(),false);
  await reconnect.click();
  await preview(primary).waitFor({state:'visible'});
  await watchBox(primary).waitFor({state:'visible'});
  await expectRegion(primary,firstRegion);
  assert.equal(await resumeWatch(primary).isEnabled(),true);
  assert.equal(await setupPage.evaluate(()=>sessions[0].title),'Main review session');
  await setupPage.evaluate(()=>{colors[0].inside='#ee67ac';});
  await setupPage.waitForTimeout(1250);
  assert.deepEqual(await setupPage.evaluate(()=>calls.filter(c=>c.watch_region)),[]);
  await resumeWatch(primary).click();
  await setupPage.evaluate(()=>{colors[0].inside='#33c100';});
  await setupPage.waitForFunction(()=>calls.some(c=>c.watch_region));
  assert.equal(await setupPage.evaluate(()=>calls.find(c=>c.watch_region).session_id===sessions[0].session_id),true);

  await setupTabs.nth(1).click();
  await secondary.getByRole('button',{name:'Share tab',exact:true}).click();
  await preview(secondary).waitFor({state:'visible'});
  await watchBox(secondary).waitFor({state:'visible'});
  await expectRegion(secondary,secondRegion);
  assert.equal(await resumeWatch(secondary).isEnabled(),true);
  assert.equal(await setupPage.evaluate(()=>sessions[1].title),'Second review session');
  assert.deepEqual(await setupPage.evaluate(()=>displayRequests),[
    {audio:true,gesture:true},{audio:true,gesture:true},{audio:false,gesture:true}
  ]);
  assert.deepEqual(errors,[]);
  console.log('PASS a canceled picker preserves the saved zone and each reconnected slot waits for explicit resume');

  assert.equal((await loadCommand(1)).ok,false,'Voice load cannot replace live shares');
  assert.equal(await preview(secondary).isVisible(),true);
  assert.equal(await setupPage.evaluate(()=>displayRequests.length),3);
  await setupPage.reload();
  assert.equal(await setupTabs.count(),1);
  await nameSlot(primary,'Evening dashboard','Evening session');
  await setupName.fill('Evening Focus');
  await saveSetup.click();
  assert.equal(await setupPicker.isVisible(),true,'Multiple saved setups show a dropdown');
  const options=await setupPicker.locator('option').evaluateAll(items=>items.map(item=>({value:item.value,label:item.textContent})));
  const morning=options.find(option=>option.label.includes('Morning Review'));
  const evening=options.find(option=>option.label.includes('Evening Focus'));
  assert.ok(morning?.value&&evening?.value&&morning.value!==evening.value);

  await setupPicker.selectOption(evening.value);
  await loadSetup.click();
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'Evening dashboard');
  await nameSlot(primary,'Updated dashboard','Evening session updated');
  await setupName.fill('evening focus');
  const beforeDuplicate=await setupPage.evaluate(()=>Object.entries(localStorage));
  await saveSetup.click();
  assert.deepEqual(await setupPage.evaluate(()=>Object.entries(localStorage)),beforeDuplicate,'Save setup cannot overwrite a name by accident');
  await updateSetup.click();
  await setupPicker.selectOption(morning.value);
  assert.equal(await updateSetup.isDisabled(),true,'Selecting another setup cannot overwrite it before loading');
  await loadSetup.click();
  assert.equal(await setupTabs.count(),3);
  assert.equal(await sourceUrl(primary).inputValue(),primaryUrl,'Updating the evening setup preserves the morning setup');
  assert.equal(await setupPicker.locator('option').count(),options.length);
  console.log('PASS named setups coexist, the dropdown loads a selection, and explicit update preserves the other setup');

  const unknownName=await loadCommand('Focus');
  assert.equal(unknownName.ok,false,'A partial name cannot fall back to another setup');
  assert.equal((await loadCommand(999)).ok,false,'An unknown number cannot fall back to another setup');
  assert.equal(await setupTabs.count(),3);
  assert.equal((await loadCommand('eVenING fOcUS')).ok,true,'Voice names match without case sensitivity');
  assert.equal(await setupTabs.count(),1);
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'Updated dashboard');
  assert.equal(await primary.locator('[data-screen-session-title]').inputValue(),'Evening session updated');
  assert.equal((await loadCommand(1)).ok,true);
  assert.equal(await setupTabs.count(),3);
  assert.equal(await primary.locator('[data-screen-session-title]').inputValue(),'Main review session');
  assert.deepEqual(await setupPage.evaluate(()=>[displayRequests.length,calls.filter(c=>c.action==='start').length]),[0,0]);
  assert.equal(setupContext.pages().length,1);
  assert.deepEqual(targetRequests,[primaryUrl.split('#')[0]]);
  console.log('PASS exact numbered and named voice loads restore idle tabs and reject unknown or active requests');

  const legacyOwner='legacy';
  await setupPage.evaluate(({owner,url})=>localStorage.setItem('vp3:agent-eyes:setup:v1:'+owner,JSON.stringify({
    version:1,selected:0,tabs:[{name:'Earlier tab',sessionTitle:'Earlier session',url,chatContext:false,zone:{x:.1,y:.2,width:.3,height:.4}}]
  })),{owner:legacyOwner,url:secondaryUrl});
  await setupPage.goto(address+'/?owner='+legacyOwner);
  assert.equal(await setupPicker.isVisible(),false);
  assert.equal((await loadCommand('Setup 1')).ok,true,'The old setup is available under its migration name');
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'Earlier tab');
  assert.equal(await sourceUrl(primary).inputValue(),secondaryUrl);
  assert.equal(await primary.locator('[data-screen-chat-context]').isChecked(),false);
  await nameSlot(primary,'New tab','New session');
  await setupName.fill('New setup');
  await saveSetup.click();
  await setupPage.reload();
  assert.equal(await setupPicker.isVisible(),true);
  assert.equal((await loadCommand(1)).ok,true,'The migrated setup keeps its number after saving a second setup');
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'Earlier tab');
  assert.equal((await loadCommand(2)).ok,true);
  assert.equal(await primary.locator('[data-screen-tab-name]').inputValue(),'New tab');
  assert.deepEqual(await setupPage.evaluate(()=>[displayRequests.length,calls.filter(c=>c.action==='start').length]),[0,0]);
  assert.deepEqual(errors,[]);
  console.log('PASS legacy single-setup data is available as Setup 1 and preserved when adding a second setup');
  await setupContext.close();
}finally{
  await browser?.close();
  await new Promise(resolve=>server.close(resolve));
}
