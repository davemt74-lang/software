import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const extension=path.join(process.cwd(),'browser-companion');
const manifest=JSON.parse(await fs.readFile(path.join(extension,'manifest.json')));
assert.equal(manifest.version,'22.9.2');assert.equal(manifest.action.default_popup,'popup.html');
assert.ok(!manifest.host_permissions.includes('<all_urls>'));
const stableId=[...crypto.createHash('sha256').update(Buffer.from(manifest.key,'base64')).digest('hex').slice(0,32)].map(n=>String.fromCharCode(97+parseInt(n,16))).join('');
const downloadSource=await fs.readFile('chrome-extension-download.php','utf8');
for(const name of ['popup.html','popup.js','popup.css','screenshot-preview.html','screenshots-store.js','screenshots-worker.js','local-screenshots.js','local-screenshots.css'])assert.ok(downloadSource.includes(`'${name}'`),'Public download includes '+name);
assert.ok(!(await fs.readFile(path.join(extension,'sidepanel.html'),'utf8')).includes('localScreenshotPanel'),'No screenshot panel in sidebar');
const server=http.createServer(async(req,res)=>{
  const name=path.basename(new URL(req.url,'http://localhost').pathname);
  res.setHeader('Content-Type',name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html');
  if(['homeserver','nested','oversize'].includes(name)) {
    const content='<div style="height:900px;background:#ee2222">Top</div><div style="height:900px;background:#2244ee">Middle</div><div style="height:900px;background:#22cc44">Bottom</div>';
    if(name==='nested')res.end('<!doctype html><style>body{margin:0;overflow:hidden;height:100vh}header,footer{height:40px;background:#222}main{height:calc(100vh - 80px);overflow:auto}</style><header>HomeServer</header><main>'+content+'</main><footer>Chat composer</footer>');
    else res.end('<!doctype html><style>body{margin:0}'+(name==='oversize'?'body{height:50000px}':'')+'</style>'+content);
    return;
  }
  if(name==='runner'){res.end('<!doctype html><script src="screenshots-store.js"></script><script src="screenshots-worker.js"></script>');return;}
  try{res.end(await fs.readFile(path.join(extension,name)));}catch(_){res.statusCode=404;res.end();}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
const temp=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-dropdown-tests-'));
let browser,integration;
try {
  browser=await chromium.launch({headless:true,...(process.env.SCREENSHOT_CHROMIUM_EXECUTABLE?{executablePath:process.env.SCREENSHOT_CHROMIUM_EXECUTABLE}:{})});
  const context=await browser.newContext({viewport:{width:900,height:700}});
  const home=await context.newPage();await home.goto(base+'/homeserver');
  const runner=await context.newPage();let frameMode='',frames=0;
  await runner.exposeFunction('inject',async(fn,args)=>home.evaluate(`(${fn})(...${JSON.stringify(args)})`));
  await runner.exposeFunction('takeFrame',async()=>{
    frames++;
    if(frameMode==='permission')throw Error('activeTab permission required');
    const png=await home.screenshot();
    if(frameMode==='race')await runner.evaluate(()=>{events.activated.forEach(fn=>fn({windowId:7,tabId:2}));events.activated.forEach(fn=>fn({windowId:7,tabId:1}));});
    if(frameMode==='dynamic')await home.evaluate(()=>{document.body.style.height='4000px';});
    return 'data:image/png;base64,'+png.toString('base64');
  });
  await runner.addInitScript(id=>{
    window.events={activated:[],updated:[],removed:[],focus:[]};
    const event=key=>({addListener:fn=>events[key].push(fn),removeListener:fn=>{events[key]=events[key].filter(item=>item!==fn);}});
    window.currentUrl='';window.activatedPreview=false;
    window.chrome={runtime:{id,getURL:name=>'chrome-extension://'+id+'/'+name},
      tabs:{query:async()=>[{id:1,windowId:7,url:currentUrl}],create:async options=>{window.previewUrl=options.url;return {id:99};},update:async()=>{window.activatedPreview=true;},captureVisibleTab:()=>window.takeFrame(),onActivated:event('activated'),onUpdated:event('updated'),onRemoved:event('removed')},
      windows:{onFocusChanged:event('focus')},scripting:{executeScript:async({func,args})=>[{result:await window.inject(func.toString(),args)}]}};
    window.selectScreenshotRegion=async()=>{const blob=await(await fetch(await window.takeFrame())).blob();const bitmap=await createImageBitmap(blob);const canvas=new OffscreenCanvas(100,80);canvas.getContext('2d').drawImage(bitmap,30,40,100,80,0,0,100,80);bitmap.close();const data=await canvas.convertToBlob({type:'image/png'});const bytes=new Uint8Array(await data.arrayBuffer());return {cancelled:false,data_url:'data:image/png;base64,'+btoa(String.fromCharCode(...bytes)),metadata:{width:100,height:80}};};
  },stableId);
  await runner.goto(base+'/runner');
  async function start(mode,page='homeserver'){
    await home.goto(base+'/'+page);await runner.evaluate(url=>{currentUrl=url;activatedPreview=false;},home.url());
    return runner.evaluate(mode=>vp3LocalScreenshotStart(mode,{id:chrome.runtime.id,url:chrome.runtime.getURL('popup.html')}),mode);
  }
  async function done(job){
    for(let i=0;i<150;i++){
      const status=await runner.evaluate(id=>vp3LocalScreenshotStatus(id),job.job_id);
      if(status.state!=='running')return status;
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    throw Error('Capture did not finish');
  }
  const visible=await start('visible');assert.equal((await done(visible)).state,'ready');
  const preview=await context.newPage();
  await preview.exposeFunction('getJobStatus',id=>runner.evaluate(id=>vp3LocalScreenshotStatus(id),id));
  await preview.addInitScript(()=>{
    window.revoked=0;const native=URL.revokeObjectURL;URL.revokeObjectURL=url=>{revoked++;native(url);};
    window.chrome={runtime:{sendMessage:async message=>({ok:true,value:await window.getJobStatus(message.job_id)})},downloads:{download:async options=>{if(window.saveFail)throw Error('Download cancelled');window.saveOptions=options;window.savedBytes=Array.from(new Uint8Array(await(await fetch(options.url)).arrayBuffer()));return 1;}}};
    Object.defineProperty(navigator,'clipboard',{value:{write:async items=>{if(window.copyFail)throw Error('Clipboard blocked');window.copiedBytes=Array.from(new Uint8Array(await(await items[0].getType('image/png')).arrayBuffer()));}}});
  });
  await preview.goto(base+'/screenshot-preview.html?capture='+visible.job_id);
  try{await preview.locator('#localScreenshotImage').waitFor({state:'visible',timeout:5000});}catch(error){console.log('Preview error',await preview.locator('#localScreenshotStatus').textContent());throw error;}
  assert.equal(await preview.locator('#localScreenshotSize').textContent(),'900 × 700 px · PNG');
  await preview.locator('#localScreenshotSave').click();await preview.waitForFunction(()=>window.savedBytes);
  await preview.locator('#localScreenshotCopy').click();await preview.waitForFunction(()=>window.copiedBytes);
  assert.deepEqual(await preview.evaluate(()=>copiedBytes),await preview.evaluate(()=>savedBytes));
  assert.deepEqual((await preview.evaluate(()=>savedBytes)).slice(0,8),[137,80,78,71,13,10,26,10]);
  assert.equal(await preview.evaluate(()=>saveOptions.saveAs),true);
  await preview.evaluate(()=>{copyFail=true;saveFail=true;});
  await preview.locator('#localScreenshotCopy').click();await preview.waitForFunction(()=>document.querySelector('#localScreenshotStatus').textContent.includes('Use Save PNG'));
  await preview.locator('#localScreenshotSave').click();await preview.waitForFunction(()=>document.querySelector('#localScreenshotStatus').textContent.includes('could not be saved'));
  await preview.locator('#localScreenshotClear').click();assert.equal(await preview.evaluate(()=>revoked),1);
  const root=await start('full');await home.evaluate(()=>scrollTo(0,173));assert.equal((await done(root)).state,'ready');
  assert.equal(await home.evaluate(()=>scrollY),173,'Root scroll restored');
  const pixels=await runner.evaluate(async id=>{const r=await VP3ScreenshotStore.take(id),bitmap=await createImageBitmap(r.blob),canvas=new OffscreenCanvas(bitmap.width,bitmap.height),ctx=canvas.getContext('2d');ctx.drawImage(bitmap,0,0);bitmap.close();return {width:r.width,height:r.height,samples:[400,1400,2400].map(y=>Array.from(ctx.getImageData(20,y,1,1).data))};},root.job_id);
  assert.deepEqual(pixels,{width:900,height:2700,samples:[[238,34,34,255],[34,68,238,255],[34,204,68,255]]},'Full-page stitch includes top, middle and bottom');
  const nested=await start('full','nested');await home.evaluate(()=>document.querySelector('main').scrollTop=233);assert.equal((await done(nested)).state,'ready');
  assert.equal(await home.evaluate(()=>document.querySelector('main').scrollTop),233,'Chat scroller restored');
  const nestedImage=await runner.evaluate(async id=>{const r=await VP3ScreenshotStore.take(id);return {width:r.width,height:r.height};},nested.job_id);
  assert.deepEqual(nestedImage,{width:900,height:2780},'Full HomeServer scroller plus header and composer captured');
  const large=await start('full','oversize');await home.evaluate(()=>scrollTo(0,173));assert.match((await done(large)).message,/too large/);assert.equal(await home.evaluate(()=>scrollY),173);
  frameMode='permission';assert.match((await done(await start('visible'))).message,/toolbar icon/);
  frameMode='race';assert.match((await done(await start('visible'))).message,/active page changed/,'Round-trip tab switch detected');
  frameMode='dynamic';assert.match((await done(await start('full'))).message,/page size changed/);
  frameMode='';const cancelled=await start('full');
  await assert.rejects(runner.evaluate(()=>vp3LocalScreenshotStart('visible',{id:chrome.runtime.id,url:chrome.runtime.getURL('popup.html')})),/already running/);
  await runner.evaluate(id=>vp3LocalScreenshotCancel(id),cancelled.job_id);assert.match((await done(cancelled)).message,/cancelled/);
  assert.deepEqual(await runner.evaluate(()=>Object.values(events).map(list=>list.length)),[0,0,0,0],'Listeners cleaned');
  await assert.rejects(runner.evaluate(()=>vp3LocalScreenshotStart('visible',{id:'other',url:'https://evil.test'})),/toolbar menu/);
  assert.equal(await home.evaluate(()=>document.querySelectorAll('[id^="vp3-screenshot-style-"]').length),0,'Temporary page CSS cleaned');
  const menu=await context.newPage();await menu.addInitScript(()=>{window.close=()=>{window.closedMenu=true;};window.chrome={runtime:{sendMessage:async message=>{window.sent=message;return {ok:true};}},tabs:{query:async()=>[{id:1}]},sidePanel:{open:async()=>{window.companionOpened=true;}}};});
  await menu.goto(base+'/popup.html');assert.deepEqual(await menu.locator('[data-capture]').allTextContents(),['Capture visible webpage','Capture entire webpage','Capture region']);
  await menu.locator('[data-capture=full]').click();assert.deepEqual(await menu.evaluate(()=>sent),{type:'local_screenshot_start',mode:'full'});
  await menu.reload();await menu.locator('#openCompanion').click();assert.equal(await menu.evaluate(()=>companionOpened),true);
  console.log('PASS: dropdown; local PNG save/copy; root and nested entire-page stitch; scroll restoration; size bounds; errors; tab race; cancellation; single-flight; cleanup.');

  if(process.env.SCREENSHOT_EXTENSION_INTEGRATION==='1') {
    await browser.close();browser=null;
    async function launch(ext,name){return chromium.launchPersistentContext(path.join(temp,name),{channel:'chromium',headless:true,viewport:null,ignoreDefaultArgs:['--disable-extensions'],acceptDownloads:true,args:['--window-size=1280,900',`--disable-extensions-except=${ext}`,`--load-extension=${ext}`]});}
    integration=await launch(extension,'shipped');let worker=integration.serviceWorkers()[0]||await integration.waitForEvent('serviceworker');
    assert.equal(new URL(worker.url()).hostname,stableId,'Native Chrome ID matches the public key');
    let nativeMenu=await integration.newPage();await nativeMenu.goto(worker.url().replace(/background.js$/,'popup.html'));assert.equal(await nativeMenu.locator('[data-capture]').count(),3);
    await integration.close();
    const fixture=path.join(temp,'grant-fixture');await fs.cp(extension,fixture,{recursive:true});
    await fs.writeFile(path.join(fixture,'manifest.json'),JSON.stringify({...manifest,host_permissions:[...manifest.host_permissions,'<all_urls>']}));
    integration=await launch(fixture,'granted');worker=integration.serviceWorkers()[0]||await integration.waitForEvent('serviceworker');assert.equal(new URL(worker.url()).hostname,stableId,'Stable ID survives another folder');
    nativeMenu=await integration.newPage();await nativeMenu.goto(worker.url().replace(/background.js$/,'popup.html'));
    const nativeHome=await integration.newPage();
    async function nativeStart(mode,name='homeserver') {
      await nativeHome.goto(base+'/'+name);await nativeHome.bringToFront();
      const created=integration.waitForEvent('page');
      const response=await nativeMenu.evaluate(mode=>chrome.runtime.sendMessage({type:'local_screenshot_start',mode}),mode);
      assert.equal(response.ok,true,response.error);
      const preview=await created;await preview.waitForURL('**/screenshot-preview.html?capture=*');
      return {preview,id:response.value.job_id};
    }
    async function ready(preview){
      try{await preview.locator('#localScreenshotImage').waitFor({state:'visible',timeout:30000});}
      catch(error){console.log('Native capture failure',await preview.locator('#localScreenshotStatus').textContent());throw error;}
    }
    const visibleNative=await nativeStart('visible');await ready(visibleNative.preview);
    const original=await visibleNative.preview.evaluate(async()=>Array.from(new Uint8Array(await(await fetch(document.querySelector('img').src)).arrayBuffer())));
    assert.deepEqual(original.slice(0,8),[137,80,78,71,13,10,26,10]);
    await visibleNative.preview.evaluate(()=>{const native=chrome.downloads.download.bind(chrome.downloads);chrome.downloads.download=async options=>{const id=await native({...options,saveAs:false});window.downloadId=id;return id;};document.querySelector('#localScreenshotSave').click();});
    let saved;
    for(let i=0;i<100;i++){saved=await visibleNative.preview.evaluate(async()=>window.downloadId!==undefined?(await chrome.downloads.search({id:window.downloadId}))[0]:null);if(saved?.state==='complete')break;await new Promise(resolve=>setTimeout(resolve,100));}
    assert.equal(saved?.state,'complete');assert.deepEqual(Array.from(await fs.readFile(saved.filename)),original);
    for(const name of ['homeserver','nested']) {
      const full=await nativeStart('full',name);await ready(full.preview);
      const dimensions=await full.preview.evaluate(()=>document.querySelector('#localScreenshotSize').textContent());
      const bitmap=await full.preview.evaluate(async()=>{const blob=await(await fetch(document.querySelector('img').src)).blob(),b=await createImageBitmap(blob),c=document.createElement('canvas');c.width=b.width;c.height=b.height;const ctx=c.getContext('2d');ctx.drawImage(b,0,0);b.close();return {width:c.width,height:c.height,samples:[400,1400,2400].map(y=>Array.from(ctx.getImageData(20,y+(location.href.includes('nested')?40:0),1,1).data))};});
      assert.equal(bitmap.height,name==='nested'?2780:2700,dimensions);
      assert.deepEqual(bitmap.samples,[[238,34,34,255],[34,68,238,255],[34,204,68,255]]);
      assert.equal(await nativeHome.evaluate(()=>scrollY),0);
    }
    const region=await nativeStart('region');await nativeHome.locator('#vp3-region-capture-overlay').waitFor();
    await nativeHome.mouse.move(30,40);await nativeHome.mouse.down();await nativeHome.mouse.move(130,120);await nativeHome.mouse.up();await ready(region.preview);
    assert.match(await region.preview.locator('#localScreenshotSize').textContent(),/^100 × 80/);
    const cancel=await nativeStart('region');await nativeHome.locator('#vp3-region-capture-overlay').waitFor();await nativeHome.keyboard.press('Escape');
    await cancel.preview.locator('#localScreenshotStatus').filter({hasText:'cancelled'}).waitFor();
    console.log('PASS: shipping popup and stable ID in two folders; real Chrome visible/entire/nested/region capture; exact PNG download; region cancellation (temporary test-only capture grant).');
  }
} finally {
  if(browser)await browser.close();if(integration)await integration.close();
  await new Promise(resolve=>server.close(resolve));await fs.rm(temp,{recursive:true,force:true});
}
