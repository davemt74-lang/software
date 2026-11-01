import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {chromium} from 'playwright';
let consent=false;const requests=[];
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture');
 if(url.pathname==='/participants'||url.pathname==='/eyes'){
  let body='';req.on('data',b=>body+=b);req.on('end',()=>{if(body){const data=JSON.parse(body);requests.push(data);if(data.action==='allow_local_visual_recognition')consent=true;}res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,profiles:[{id:7,display_name:'David',recognition_consent:consent}]}));});return;
 }
 if(url.pathname.endsWith('.js')||url.pathname.endsWith('.css')){res.setHeader('Content-Type',url.pathname.endsWith('.js')?'text/javascript':'text/css');res.end(fs.readFileSync(path.basename(url.pathname)));return;}
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/agent-orb.css"><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form><script>
 window.VP3_ORB_CONFIG={ownerKey:'${url.searchParams.get('owner')||'1'}',participantsEndpoint:'/participants',eyesEndpoint:'/eyes',chatUrl:'/chat.php',csrf:'test'};
 window.StonefellowPremiumVoiceV122={};window.STONEFELLOW_NOTIFICATION_CENTER={announceWhen:async(text,current)=>{if(current()){greetings.push(text);return true;}return false;}};
 window.greetings=[];window.submissions=[];window.faces=[];window.blockCamera=false;
 document.getElementById('chatForm').addEventListener('submit',e=>{e.preventDefault();submissions.push(document.getElementById('chatInput').value);});
 Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>{const canvas=document.createElement('canvas');canvas.width=640;canvas.height=480;const c=canvas.getContext('2d');c.fillStyle='#a86';c.fillRect(0,0,640,480);const stream=canvas.captureStream(2);window.cameraStream=stream;return stream;}}});
 window.localRecord=async(update)=>{const db=await new Promise((resolve,reject)=>{const r=indexedDB.open('vp3-eyes-local-'+VP3_ORB_CONFIG.ownerKey,1);r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});try{return await new Promise((resolve,reject)=>{const t=db.transaction('samples',update?'readwrite':'readonly'),s=t.objectStore('samples'),r=s.get('7');let result;r.onsuccess=()=>{result=r.result;if(update){result={...result,...update};s.put(result);}};t.oncomplete=()=>resolve(result);t.onerror=()=>reject(t.error);});}finally{db.close();}};
 </script><script src="/agent-eyes-core.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script><script src="/agent-eyes.js"></script><script src="/agent-voice-guard.js"></script>`);
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`http://127.0.0.1:${server.address().port}`,browser=await chromium.launch({headless:true,args:['--no-sandbox']});let checks=0;
const test=async(name,fn)=>{await fn();console.log('PASS '+name);checks++;};
const v=Array.from({length:32},(_,i)=>i===0?1:0),face={box:[128,144,128,144],embedding:v};
try{
 const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await context.route('https://cdn.jsdelivr.net/**',route=>route.fulfill({contentType:'text/javascript',body:'export class Human {async load(){} async detect(){if(window.waitDetect)await new Promise(r=>window.releaseDetect=r);return {face:structuredClone(window.faces)}}}'}));
 const open=async(p=page)=>{await p.goto(base);await p.waitForFunction(()=>window.VP3AgentEyes&&window.VP3VoiceGuard);await p.evaluate(face=>{faces=[face];return VP3AgentEyes.start();},face);};
 const age=async(p=page)=>p.evaluate(async()=>{const old=Date.now()-8*24*60*60*1000;await localRecord({portraitUpdatedAt:old,portraits:(await localRecord()).portraits.map(p=>({...p,captured_at:new Date(old).toISOString()}))});});
 const enroll=async(p=page)=>p.evaluate(async v=>{const c=document.createElement('canvas');c.width=c.height=160;c.getContext('2d').fillRect(0,0,160,160);return VP3_ORB_ADAPTER.enrollEyes({id:'7',embeddings:[v,v,v],portraits:[{image:c.toDataURL('image/jpeg'),captured_at:new Date().toISOString()}],portraitRefreshEnabled:true});},v);
 await open();await page.waitForFunction(()=>greetings.some(t=>t.includes('What’s your name')));
 await test('plain yes after the visible photo offer captures and commits three real canvas JPEGs',async()=>{
  assert.equal(await page.evaluate(()=>VP3AgentEyes.hear('My name is David')),true);
  const decision=await page.evaluate(()=>VP3VoiceGuard.check('yes'));assert.equal(decision.allowed,true);assert.equal(decision.reason,'invited-introduction');
  await page.waitForFunction(()=>VP3AgentEyes.acceptsPhotoReply('yes'));
  await page.evaluate(()=>{document.getElementById('chatInput').value='Yes please';document.getElementById('chatForm').requestSubmit();});
  await page.waitForFunction(async()=>((await VP3_ORB_ADAPTER.loadEyesProfiles())[0].portraits.length===3));
  assert.deepEqual(await page.evaluate(()=>submissions),[],'Consent must not be sent to the general Chat model');
  assert.equal((await page.evaluate(()=>localRecord())).portraitRefreshEnabled,true);
  assert.match(await page.locator('[data-eyes-enrollment-status]').innerText(),/Three photos saved/);
  assert.equal(await page.locator('.vp3-eyes-portraits img').count(),3);
  assert.equal(await page.locator('.vp3-eyes-portraits img').evaluateAll(nodes=>nodes.every(n=>n.complete&&n.naturalWidth===160)),true);
 });
 await test('gallery survives reload with timestamps and ordinary yes remains a Chat message',async()=>{
  await page.reload();await page.waitForFunction(()=>window.VP3AgentEyes);await page.evaluate(()=>VP3AgentEyes.toggle());await page.locator('.vp3-eyes-content details').first().locator('summary').click();await page.locator('[data-eyes-profile]').selectOption('7');
  assert.equal(await page.locator('.vp3-eyes-portraits img').count(),3);assert.match(await page.locator('[data-eyes-photo-status]').innerText(),/3 saved photos.*Last updated/);
  await page.evaluate(()=>{document.getElementById('chatInput').value='yes';document.getElementById('chatForm').requestSubmit();});assert.deepEqual(await page.evaluate(()=>submissions),['yes']);
 });
 await test('a second account has no gallery or periodic capture permission',async()=>{const p=await context.newPage();await p.goto(base+'?owner=2');await p.waitForFunction(()=>window.VP3_ORB_ADAPTER);const rows=await p.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles());assert.equal(rows[0].portraits.length,0);assert.equal(rows[0].portraitRefreshEnabled,false);await p.close();});
 await test('a fresh gallery is not updated on each recognition',async()=>{await page.evaluate(face=>{faces=[face];return VP3AgentEyes.start();},face);await page.waitForTimeout(7500);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,3);});
 await test('an opted-in week-old gallery gets one new photo without training itself',async()=>{const original=(await page.evaluate(()=>localRecord())).embeddings;await age();await page.waitForFunction(async()=>(await localRecord()).portraits.length===4,{},{timeout:15000});assert.deepEqual((await page.evaluate(()=>localRecord())).embeddings,original);await page.waitForTimeout(2500);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,4);});
 await test('concurrent tabs cannot both append a weekly photo',async()=>{await page.evaluate(()=>VP3AgentEyes.stop());await age();const second=await context.newPage();await second.goto(base);await second.waitForFunction(()=>window.VP3_ORB_ADAPTER);const data=await page.evaluate(async()=>({id:'7',portrait:(await localRecord()).portraits[0]}));data.portrait.captured_at=new Date().toISOString();const results=await Promise.all([page.evaluate(d=>VP3_ORB_ADAPTER.refreshEyesPortrait(d),data),second.evaluate(d=>VP3_ORB_ADAPTER.refreshEyesPortrait(d),data)]);assert.equal(results.filter(r=>r.saved).length,1);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,5);await second.close();});
 await test('disabling weekly refresh survives reload and prevents automatic writes',async()=>{await page.evaluate(()=>VP3_ORB_ADAPTER.setEyesPhotoRefresh({id:'7',enabled:false}));await age();await open();await page.waitForTimeout(7500);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,5);assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0].portraitRefreshEnabled,false);});
 await test('multiple faces cannot refresh a participant gallery',async()=>{await page.evaluate(()=>VP3AgentEyes.stop());await page.evaluate(()=>VP3_ORB_ADAPTER.setEyesPhotoRefresh({id:'7',enabled:true}));await age();await page.evaluate(face=>{faces=[face,{...face,box:[350,144,128,144]}];return VP3AgentEyes.start();},face);await page.waitForTimeout(7500);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,5);});
 await test('camera Stop during a pending detection cannot save later',async()=>{await page.evaluate(()=>VP3AgentEyes.stop());await page.evaluate(face=>{faces=[face];waitDetect=true;void VP3AgentEyes.start();},face);await page.waitForFunction(()=>typeof releaseDetect==='function');await page.evaluate(()=>{VP3AgentEyes.stop();waitDetect=false;releaseDetect();});await page.waitForTimeout(500);assert.equal((await page.evaluate(()=>localRecord())).portraits.length,5);assert.equal(await page.evaluate(()=>cameraStream.getTracks().every(t=>t.readyState==='ended')),true);});
 await test('revocation cannot be overwritten by an automatic refresh',async()=>{consent=false;const data=await page.evaluate(async()=>({id:'7',portrait:(await localRecord()).portraits[0]}));assert.equal((await page.evaluate(d=>VP3_ORB_ADAPTER.refreshEyesPortrait(d),data)).saved,false);assert.equal((await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0].portraits.length,0);consent=true;});
 await test('failed IndexedDB commit reports an error instead of saved photos',async()=>{await page.evaluate(()=>{window.originalPut=IDBObjectStore.prototype.put;IDBObjectStore.prototype.put=function(){throw new DOMException('Photo storage is full','QuotaExceededError');};});assert.equal(await page.evaluate(async v=>{try{await VP3_ORB_ADAPTER.enrollEyes({id:'7',embeddings:[v,v,v],portraits:[{image:'data:image/jpeg;base64,/9j/2Q==',captured_at:new Date().toISOString()}]});return false;}catch(e){return /storage is full/.test(e.message);}},v),true);await page.evaluate(()=>{IDBObjectStore.prototype.put=originalPut;});assert.equal((await page.evaluate(()=>localRecord())).portraits.length,5);});
 await test('forget deletes gallery, recognition samples and periodic opt-in together',async()=>{await page.evaluate(()=>VP3_ORB_ADAPTER.forgetEyes('7'));const p=(await page.evaluate(()=>VP3_ORB_ADAPTER.loadEyesProfiles()))[0];assert.equal(p.portraits.length,0);assert.equal(p.embeddings.length,0);assert.equal(p.portraitRefreshEnabled,false);});
 await test('no photo, geometry or recognition embedding is uploaded in participant or Brain requests',async()=>{assert.ok(requests.length>0);assert.equal(requests.some(r=>/data:image|"portraits"|"embeddings"|"box"/.test(JSON.stringify(r))),false);});
 assert.deepEqual(errors,[]);await context.close();console.log(`AGENT_EYES_PORTRAITS_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
