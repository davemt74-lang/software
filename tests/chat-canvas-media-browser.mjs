import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const source=p=>fs.readFile(new URL('../'+p,import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,args:['--no-sandbox']}),page=await browser.newPage({viewport:{width:390,height:844}}),errors=[],calls=[];
page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(10000);
let id='11111111-1111-4111-8111-111111111111',status='queued',denied=false,tickRelease,tickGate=new Promise(r=>tickRelease=r),mode='generation';
const asset={id:1,title:'Desert <img src=x onerror=alert(1)>',media_type:'photo',mime_type:'image/png',source:'openai_image',available:true,url:'/media-library-file-v86.php?id=1',editor_url:'/video-editor.php?asset=1'};
const generation=()=>({id,title:'Desert artwork',prompt:'Cinematic desert <img src=x>',model:'gpt-image-2.5-flare',size:'1024x1024',quality:'medium',background:'opaque',status,message:status==='failed'?'The provider result was not confirmed. No automatic retry.':'',provider_started:status!=='queued',asset:status==='completed'?asset:null});
const html='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside><main class="chat-main"><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div></main></div></body></html>';
await page.route('https://images.test/',r=>r.fulfill({contentType:'text/html',body:html}));
await page.route('**/media-library-file-v86.php?**',r=>r.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')}));
await page.route('**/api/**',async r=>{
 const p=r.request().postDataJSON()||{};const path=new URL(r.request().url()).pathname;calls.push({path,...p});
 const ok=x=>r.fulfill({json:{ok:true,...x}});
 if(path==='/api/chat-canvas-media-v1.php'){
  if(denied)return r.fulfill({status:403,json:{ok:false,error:'Media access changed.'}});
  if(p.action==='assets')return ok({assets:mode==='hostile'?[{...asset,url:'https://foreign.test/secret.png'}]:[{...asset,available:mode!=='missing'}]});
  if(p.action==='tick'){if(status==='queued')status='generating';await tickGate;return ok({generation:generation()});}
  if(p.action==='cancel')status='cancelled';
  return ok({generation:generation()});
 }
 if(p.action==='load')return ok({messages:[{id:10,role:'assistant',message:'Your requested media.',context_json:JSON.stringify({canvas_media:mode==='generation'?{asset_ids:[],generation_ids:[id]}:{asset_ids:[1],generation_ids:[]}})}]});
 if(p.action==='send')return ok({conversation_id:42,answer:'Another ordinary Chat reply.',assistant_message_id:12});
 if(p.action==='task.route')return ok({route:'chat'});
 return ok({conversations:[],items:[],tracks:[],updates:[]});
});
await page.route('**/*.js?**',r=>r.fulfill({contentType:'text/javascript',body:''}));
async function boot(){await page.goto('https://images.test/');await page.evaluate(()=>{window.STONEFELLOW_CHAT={userId:1,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};window.VP3_CANVAS_MEDIA_V1={endpoint:'/api/chat-canvas-media-v1.php',csrf:'fixture',agentId:7};});await page.addStyleTag({content:await source('chat.css')});await page.addStyleTag({content:await source('chat-canvas-media-v1.css')});await page.addScriptTag({content:await source('chat-canvas-media-v1.js')});await page.addScriptTag({content:await source('chat.js')});}
let checks=0;async function check(label,fn){await fn();checks++;console.log('PASS '+label);}
try{
 await boot();await page.waitForFunction(()=>document.querySelector('.vp3-canvas-media-card[data-state=generating]'));
 await check('real Chat history mounts the image job and starts exactly one worker request',async()=>{assert.equal(calls.filter(c=>c.action==='tick').length,1);assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(await page.locator('#chatInput').isDisabled(),false);});
 await page.locator('#chatInput').fill('Continue chatting while that image runs');await page.locator('#chatForm').evaluate(f=>f.requestSubmit());await page.getByText('Another ordinary Chat reply.',{exact:true}).waitFor();
 await check('the composer and conversation stay usable while generation runs',async()=>{assert.equal(await page.locator('#chatInput').isDisabled(),false);assert.equal(calls.filter(c=>c.action==='tick').length,1);});
 status='completed';tickRelease();await page.locator('.vp3-canvas-media-card img').waitFor();
 await check('confirmed images display on the canvas with private download and editor links',async()=>{assert.match(await page.locator('.vp3-canvas-media').innerText(),/Desert <img/);assert.equal(await page.locator('.vp3-canvas-media img').count(),1);assert.equal(await page.getByRole('link',{name:'Download',exact:true}).getAttribute('href'),'https://images.test/media-library-file-v86.php?id=1');assert.equal(await page.getByRole('link',{name:'Open in editor',exact:true}).count(),1);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 await boot();await page.locator('.vp3-canvas-media-card img').waitFor();await check('a full reload restores the saved image without generating again',async()=>{assert.equal(calls.filter(c=>c.action==='tick').length,1);});
 id='22222222-2222-4222-8222-222222222222';status='queued';tickGate=new Promise(r=>tickRelease=r);await boot();await page.getByRole('button',{name:'Cancel image',exact:true}).waitFor();await page.getByRole('button',{name:'Cancel image',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-canvas-media-card[data-state=cancelled]'));tickRelease();
 await check('cancellation keeps late results from reappearing as completed images',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(calls.filter(c=>c.action==='cancel').at(-1).confirmed,true);});
 status='failed';await boot();await page.getByText('The provider result was not confirmed. No automatic retry.',{exact:true}).waitFor();await check('a failed provider request has no fabricated image or automatic retry',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(calls.filter(c=>c.action==='tick').length,2);});
 denied=true;await boot();await page.getByText('Media access changed.',{exact:true}).waitFor();await check('revoked media access clears displayed private content',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);});denied=false;
 for(const next of ['missing','hostile']){mode=next;await boot();await page.getByText('Saved media file is unavailable.',{exact:true}).waitFor();assert.equal(await page.locator('.vp3-canvas-media img').count(),0);}
 await check('missing files and foreign-origin media URLs cannot become image previews',async()=>{assert.deepEqual(errors,[]);});
 console.log(`CHAT_CANVAS_MEDIA_BROWSER=PASS (${checks} cases; real Chat renderer and history, mobile)`);
}finally{await browser.close();}
