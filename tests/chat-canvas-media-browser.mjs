import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const source=p=>fs.readFile(new URL('../'+p,import.meta.url),'utf8');
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})}),page=await browser.newPage({viewport:{width:390,height:844}}),errors=[],calls=[];
page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(10000);
let id='11111111-1111-4111-8111-111111111111',status='queued',denied=false,tickRelease,tickGate=new Promise(r=>tickRelease=r),mode='generation';
const specialistPrompt = 'Create a paper lighthouse at sunset';
const marketingPrefill = {level:7,prompt:'put it on a café counter'};
let specialistMessages = [];
const orbStatuses=new Map();
const asset={id:1,title:'Desert <img src=x onerror=alert(1)>',media_type:'photo',mime_type:'image/png',source:'openai_image',available:true,url:'/media-library-file-v86.php?id=1',editor_url:'/video-editor.php?asset=1'};
const generation=(jobId=id,jobStatus=status)=>({id:jobId,title:'Desert artwork',prompt:'Cinematic desert <img src=x>',model:'gpt-image-2.5-flare',size:'1024x1024',quality:'medium',background:'opaque',status:jobStatus==='expired'?'generating':jobStatus,message:jobStatus==='failed'?'The provider result was not confirmed. No automatic retry.':'',provider_started:jobStatus!=='queued',asset:jobStatus==='completed'?asset:null,active_lease_expires_at:jobStatus==='generating'?Date.now()+30000:jobStatus==='expired'?Date.now()-1000:null});
const html='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside><main class="chat-main"><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section><div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div></main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside></body></html>';
await page.route('https://images.test/',r=>r.fulfill({contentType:'text/html',body:html}));
await page.route('**/media-library-file-v86.php?**',r=>r.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')}));
await page.route('**/api/**',async r=>{
 const p=r.request().postDataJSON()||{};const path=new URL(r.request().url()).pathname;calls.push({path,...p});
 const ok=x=>r.fulfill({json:{ok:true,...x}});
 if(path==='/api/chat-canvas-media-v1.php'){
  if(denied)return r.fulfill({status:403,json:{ok:false,error:'Media access changed.'}});
  if(mode==='orb-jobs'){
   const jobStatus=orbStatuses.get(p.generation_id);
   if(jobStatus==='error')return r.fulfill({status:503,json:{ok:false,error:'Image status unavailable.'}});
   return ok({generation:generation(p.generation_id,jobStatus)});
  }
  if(p.action==='assets')return ok({assets:mode==='hostile'?[{...asset,url:'https://foreign.test/secret.png'}]:[{...asset,available:mode!=='missing'}]});
  if(p.action==='tick'){if(status==='queued'&&mode!=='specialist')status='generating';await tickGate;return ok({generation:generation()});}
  if(p.action==='cancel')status='cancelled';
  return ok({generation:generation()});
 }
 if(p.action==='load'&&mode==='orb-jobs')return ok({messages:p.conversation_id===42?Array.from(orbStatuses.keys(),(jobId,index)=>({
  id:30+index,role:'assistant',message:'Your requested media.',context_json:JSON.stringify({canvas_media:{generation_ids:[jobId]}})
 })):[]});
 if(p.action==='load')return ok({messages:mode==='specialist'
   ? (p.conversation_id===42 ? specialistMessages : [])
   : [{id:10,role:'assistant',message:'Your requested media.',context_json:JSON.stringify({canvas_media:mode==='generation'?{asset_ids:[],generation_ids:[id]}:{asset_ids:[1],generation_ids:[],...(mode==='marketing'?{marketing_prefill:marketingPrefill}:{})}})}]});
 if(p.action==='send'&&p.message===specialistPrompt){
  const answer = 'Your image request is queued.';
  const canvas_media = {asset_ids:[],generation_ids:[id]};
  specialistMessages = [{id:13,role:'assistant',message:answer,context_json:JSON.stringify({canvas_media})}];
  return ok({conversation_id:42,user_message_id:12,assistant_message_id:13,answer,canvas_media});
 }
 if(p.action==='send')return ok({conversation_id:42,answer:'Another ordinary Chat reply.',assistant_message_id:12});
 if(p.action==='task.route')return ok({route:'chat'});
 return ok({conversations:[],items:[],tracks:[],updates:[]});
});
await page.route('**/*.js?**',r=>r.fulfill({contentType:'text/javascript',body:''}));
async function boot(withOrb=true){
 await page.goto('https://images.test/');
 await page.evaluate(withOrb=>{
  window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};
  window.VP3_CANVAS_MEDIA_V1={endpoint:'/api/chat-canvas-media-v1.php',csrf:'fixture',userId:1,agentId:7};
  window.VP3InitiativeWorkbench={open:(...args)=>{window.marketingWorkbenchArgs=args;}};
  window.orbJobs=[];
  window.mountFixtureOrb=()=>{window.VP3AgentOrb={setJobs:(source,jobs)=>{if(source==='image')window.orbJobs=jobs;}};};
  if(withOrb)window.mountFixtureOrb();
 },withOrb);
 for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-canvas-media-v1.css']) {
  await page.addStyleTag({content:await source(file)});
 }
 for (const file of ['chat-specialist-rail-v1.js','chat-canvas-media-v1.js','chat.js']) {
  await page.addScriptTag({content:await source(file)});
 }
}
let checks=0;async function check(label,fn){await fn();checks++;console.log('PASS '+label);}
const specialist = page.locator('[data-chat-specialist="image-generator"]');
const panel = page.locator('[data-chat-specialist-panel="image-generator"]');
const readyMessage = page.getByText('Image Generator finished. Your image is ready.', {exact:true});
const cardFor = generationId => page.locator(`#chatThread .message.assistant .vp3-canvas-media-card[data-generation-id="${generationId}"]`);
const tickCount = generationId => calls.filter(call => call.action==='tick' && call.generation_id===generationId).length;
const expectOrbJobs = (...ids) => page.waitForFunction(ids => window.orbJobs.length===ids.length
 && ids.every(id => window.orbJobs.some(job => job.id===`image:${id}`&&job.expiresAt>Date.now()&&job.expiresAt<=Date.now()+7500)),ids);
async function assertInlineOnly(card, progress) {
 await card.getByRole('status').filter({hasText:progress}).waitFor();
 // Wait for the media-query handler to finish retiring the desktop controls.
 // CSS alone can hide them before that handler processes the viewport change.
 await specialist.waitFor({state:'detached'});
 await panel.waitFor({state:'detached'});
 assert.equal(await card.count(),1);
 assert.equal(await page.getByRole('button',{name:'Image Generator',exact:true}).count(),0);
 assert.equal(await page.locator('#sfOnlineRailV109').isVisible(),false);
}
async function startNewImage(generationId, {desktop=true} = {}) {
 mode='specialist';
 id=generationId;
 status='queued';
 specialistMessages=[];
 tickGate=new Promise(resolve => tickRelease=resolve);
 await boot();
 await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
 assert.equal(await specialist.isVisible(),false);
 assert.equal(await panel.isVisible(),false);
 await page.locator('#chatInput').fill(specialistPrompt);
 await page.locator('#sendChatButton').click();
 const card = cardFor(generationId);
 if (desktop) {
  await card.getByRole('status').filter({hasText:/queued/i}).waitFor();
  assert.equal(await specialist.count(),0);
  assert.equal(await panel.count(),0);
 } else await assertInlineOnly(card,/queued/i);
 await expectOrbJobs();
 status='generating';
 await card.getByText('Generating image…',{exact:true}).waitFor();
 if (desktop) await specialist.waitFor();
 return card;
}
try{
 await boot();await page.waitForFunction(()=>document.querySelector('.vp3-canvas-media-card[data-state=generating]'));
 await check('real Chat history mounts the image job and starts exactly one worker request',async()=>{assert.equal(calls.filter(c=>c.action==='tick').length,1);assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(await page.locator('#chatInput').isDisabled(),false);});
 await check('mobile shows a pending job restored from history in its original Chat reply only', async () => {
  await assertInlineOnly(cardFor(id),'Generating image…');
  assert.equal(tickCount(id),1);
 });
 await page.locator('#chatInput').fill('Continue chatting while that image runs');await page.locator('#chatForm').evaluate(f=>f.requestSubmit());await page.getByText('Another ordinary Chat reply.',{exact:true}).waitFor();
 await check('the composer and conversation stay usable while generation runs',async()=>{assert.equal(await page.locator('#chatInput').isDisabled(),false);assert.equal(calls.filter(c=>c.action==='tick').length,1);});
 status='completed';tickRelease();await page.locator('.vp3-canvas-media-card img').waitFor();
 await check('confirmed images display on the canvas with private download and editor links',async()=>{assert.match(await page.locator('.vp3-canvas-media').innerText(),/Desert <img/);assert.equal(await page.locator('.vp3-canvas-media img').count(),1);assert.equal(await page.getByRole('link',{name:'Download',exact:true}).getAttribute('href'),'https://images.test/media-library-file-v86.php?id=1');assert.equal(await page.getByRole('link',{name:'Open in editor',exact:true}).count(),1);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 await specialist.waitFor({state:'hidden'});
 await panel.waitFor({state:'hidden'});
 await boot();await page.locator('.vp3-canvas-media-card img').waitFor();await check('a full reload restores the saved image without generating again',async()=>{assert.equal(calls.filter(c=>c.action==='tick').length,1);});
 id='22222222-2222-4222-8222-222222222222';status='queued';tickGate=new Promise(r=>tickRelease=r);await boot();await page.getByRole('button',{name:'Cancel image',exact:true}).waitFor();await page.getByRole('button',{name:'Cancel image',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-canvas-media-card[data-state=cancelled]'));tickRelease();
 await check('cancellation keeps late results from reappearing as completed images',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(calls.filter(c=>c.action==='cancel').at(-1).confirmed,true);await specialist.waitFor({state:'hidden'});await panel.waitFor({state:'hidden'});});
 status='failed';await boot();await page.getByText('The provider result was not confirmed. No automatic retry.',{exact:true}).waitFor();await check('a failed provider request has no fabricated image or automatic retry',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);assert.equal(calls.filter(c=>c.action==='tick').length,2);assert.equal(await specialist.isVisible(),false);assert.equal(await panel.isVisible(),false);});
 denied=true;await boot();await page.getByText('Media access changed.',{exact:true}).waitFor();await check('revoked media access clears displayed private content',async()=>{assert.equal(await page.locator('.vp3-canvas-media img').count(),0);});denied=false;
 for(const next of ['missing','hostile']){mode=next;await boot();await page.getByText('Saved media file is unavailable.',{exact:true}).waitFor();assert.equal(await page.locator('.vp3-canvas-media img').count(),0);}
 await check('missing files and foreign-origin media URLs cannot become image previews',async()=>{assert.deepEqual(errors,[]);});
 await check('restored marketing intent opens the exact saved photo and private Canvas prefill without starting a job',async()=>{
  mode='marketing';
  const before=calls.filter(c=>c.action==='tick').length;
  await boot();
  const handoff=page.locator('.vp3-canvas-media').getByRole('button',{name:'Design promotion',exact:true});
  await handoff.waitFor();
  assert.equal(await page.evaluate(()=>window.marketingWorkbenchArgs),undefined);
  await handoff.click();
  assert.deepEqual(await page.evaluate(()=>window.marketingWorkbenchArgs),['marketing','1',{marketingPrefill}]);
  assert.equal(calls.filter(c=>c.action==='tick').length,before);
 });
 const mobileCard = await startNewImage('66666666-6666-4666-8666-666666666666',{desktop:false});
 await check('a fresh mobile request keeps image progress in its originating reply while ordinary Chat continues', async () => {
  await assertInlineOnly(mobileCard,'Generating image…');
  assert.equal(await mobileCard.locator('img').count(),0);
  await page.locator('#chatInput').fill('Another question while the mobile image generates');
  await page.locator('#sendChatButton').click();
  const ordinaryReply = page.locator('#chatThread .message.assistant').filter({hasText:'Another ordinary Chat reply.'});
  await ordinaryReply.waitFor();
  assert.equal(await ordinaryReply.locator('.vp3-canvas-media').count(),0);
  await assertInlineOnly(mobileCard,'Generating image…');
  assert.equal(tickCount(id),1);
 });
 status='completed';
 tickRelease();
 await check('mobile completion displays and restores the private image in the same reply', async () => {
  await assertInlineOnly(mobileCard,'Image Generator finished. Your image is ready.');
  await mobileCard.locator('img').waitFor();
  await boot();
  await assertInlineOnly(mobileCard,'Image Generator finished. Your image is ready.');
  await mobileCard.locator('img').waitFor();
  assert.equal(tickCount(id),1);
 });

 await page.setViewportSize({width:1280,height:900});
 mode='generation';
 id='55555555-5555-4555-8555-555555555555';
 status='paused';
 await boot();
 await check('desktop paused history keeps its Chat card without an active specialist or worker', async () => {
  await cardFor(id).getByRole('status').filter({hasText:'Paused'}).waitFor();
  assert.equal(await specialist.count(),0);
  assert.equal(await panel.count(),0);
  assert.equal(tickCount(id),0);
 });
 const desktopCard = await startNewImage('33333333-3333-4333-8333-333333333333');
 await check('the active Image Generator opens its current work only on click while Chat stays usable', async () => {
  assert.equal(await specialist.isVisible(),true);
  assert.equal(await panel.isVisible(),false);
  assert.equal(await page.locator('#sfOnlineRailV109').getByRole('button',{name:'Image Generator',exact:true}).count(),1);
  await specialist.click();
  await panel.waitFor();
  assert.equal(await panel.getAttribute('role'),'dialog');
  assert.match(await panel.innerText(),/generating/i);
  assert.match(await panel.innerText(),/gpt-image-2.5-flare · 1024x1024 · medium/);
  assert.equal(await page.locator('#chatInput').isEnabled(),true);
  assert.equal(await page.locator('.vp3-canvas-media img').count(),0);
  assert.equal(tickCount(id),1);
  await panel.getByRole('button',{name:'View in Chat',exact:true}).click();
  await panel.waitFor({state:'hidden'});
  await page.waitForFunction(() => document.activeElement?.matches('.vp3-canvas-media-card[data-state="generating"]'));
 });
 await check('shrinking to mobile keeps the original progress card without restarting the job', async () => {
  await specialist.click();
  await panel.waitFor();
  await page.setViewportSize({width:390,height:844});
  await specialist.waitFor({state:'detached'});
  if (await panel.isVisible()) await panel.getByRole('button',{name:'Close',exact:true}).click();
  await assertInlineOnly(desktopCard,'Generating image…');
  await page.setViewportSize({width:1280,height:900});
  await specialist.waitFor();
  assert.equal(await panel.isVisible(),false);
  assert.equal(tickCount(id),1);
 });
 await specialist.click();
 await panel.waitFor();
 status='completed';
 tickRelease();
 await readyMessage.waitFor();
 await page.waitForFunction(() => {
  const image = document.querySelector('.vp3-canvas-media img');
  return image?.complete && image.naturalWidth>0;
 });
 await check('new image completion retires the specialist and leaves one confirmed, durable Chat result', async () => {
  await panel.waitFor({state:'hidden'});
  await specialist.waitFor({state:'hidden'});
  assert.equal(await page.locator('.vp3-canvas-media img').count(),1);
  assert.equal(await page.locator('.vp3-canvas-media').getByText('Image Generator finished. Your image is ready.',{exact:true}).count(),1);
  await boot();
  await page.locator('.vp3-canvas-media-card img').waitFor();
  assert.equal(await specialist.isVisible(),false);
  assert.equal(await panel.isVisible(),false);
  assert.equal(tickCount(id),1);
 });
 await startNewImage('44444444-4444-4444-8444-444444444444');
 await check('switching conversations clears the specialist and a late result cannot enter the new Chat', async () => {
  await specialist.click();
  await panel.waitFor();
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(55));
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===55);
  await specialist.waitFor({state:'hidden'});
  await panel.waitFor({state:'hidden'});
  const late = page.waitForResponse(response => {
   const body = response.request().postDataJSON();
   return body?.action==='tick' && body.generation_id===id;
  });
  status='completed';
  tickRelease();
  await (await late).finished();
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
  assert.equal(await page.locator('.vp3-canvas-media img').count(),0);
  assert.equal(await readyMessage.count(),0);
  assert.equal(await specialist.isVisible(),false);
  assert.equal(await panel.isVisible(),false);
  await expectOrbJobs();
  assert.deepEqual(errors,[]);
 });
 await check('the orb receives a complete private image snapshot and clears expired, completed, and unverified jobs', async () => {
  mode='orb-jobs';
  const first='77777777-7777-4777-8777-777777777777', second='88888888-8888-4888-8888-888888888888';
  orbStatuses.set(first,'generating');orbStatuses.set(second,'generating');
  await boot(false);
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await cardFor(first).getByText('Generating image…',{exact:true}).waitFor();
  await cardFor(second).getByText('Generating image…',{exact:true}).waitFor();
  assert.deepEqual(await page.evaluate(()=>window.orbJobs),[]);
  await page.evaluate(()=>{window.mountFixtureOrb();window.dispatchEvent(new Event('vp3:agent-orb-ready'));});
  await expectOrbJobs(first,second);
  orbStatuses.set(second,'expired');
  await expectOrbJobs(first);
  assert.equal(await cardFor(second).getByText('Generating image…',{exact:true}).count(),1);
  orbStatuses.set(first,'completed');orbStatuses.set(second,'generating');
  await expectOrbJobs(second);
  orbStatuses.set(second,'error');
  await cardFor(second).getByText('Image status unavailable.',{exact:true}).waitFor();
  await expectOrbJobs();
  assert.equal(tickCount(first)+tickCount(second),0);
 });
 console.log(`CHAT_CANVAS_MEDIA_BROWSER=PASS (${checks} cases; real Chat renderer and specialist, desktop and mobile)`);
}finally{await browser.close();}
