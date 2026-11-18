import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source = file => fs.readFile(new URL('../'+file,import.meta.url),'utf8');
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const errors = [],calls = [];
const waiting = new Map();
const waitForCall = action => new Promise(resolve => waiting.set(action,resolve));
page.on('pageerror',error => errors.push(error.message));
let checks = 0,mode = '',revision = 'a'.repeat(64),brainstorm = null,historyGate = null,historyRelease,brainstormGate = null,brainstormRelease;
const id = '11111111-1111-4111-8111-111111111111';
const prompt = 'A vintage album cover with a gold sun, coastal silhouettes and warm paper grain.';
const sourceRows = [
  {ref:'prompt:'+id,revision:'b'.repeat(64),kind:'image_prompt',specialist:'image-generator',title:'Vacation Brain'},
  {ref:'report:media:track:41',revision:'c'.repeat(64),kind:'specialist_report',specialist:'media',title:'Coastal music direction'},
];
const asset = number => ({id:number,title:['','Vacation Brain','Coastal study','Original base image'][number],
  available:true,media_type:'photo',mime_type:'image/png',source:number===3?'upload':'openai_image',
  url:'/media-library-file-v86.php?id='+number});
const generation = () => ({id,job_id:63,conversation_id:14,chat_label:'Chat 14',title:'Vacation Brain',prompt,
  count:2,completed_count:2,status:'completed',created_at:'2026-11-17 10:00:00',asset:asset(1),assets:[asset(1),asset(2)],
  reference_asset_id:3,reference_asset:asset(3),request_revision:'d'.repeat(64)});
const ideas = [{title:'Amber coast',prompt:'Create a vintage cover with an amber sun above a quiet coastline and subtle print texture.',
  rationale:'The warm album-cover palette connects with the music report’s coastal mood.',source_refs:sourceRows.map(row => row.ref),sources:sourceRows}];
function state(cid) {
  return {contract:'vp3.image-studio.v1',owner_user_id:mode==='foreign-owner'?2:1,agent_id:7,conversation_id:cid,
    working_attention_binding:'fixture-binding',source_revision:revision,sources:sourceRows,
    source_coverage:{media:'ready'},brainstorm,generations:[generation()],next_before:null};
}
const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
  body{margin:0;background:#fff;color:#172033;font-family:Arial,sans-serif}main{max-width:850px;margin:70px auto;padding:24px}
  #chatInput{box-sizing:border-box;width:100%;min-height:70px;padding:12px;font:inherit;border:1px solid #d6deeb;border-radius:10px}
  #launchImageStudio{position:fixed;right:16px;bottom:20px;padding:10px 14px}
  #chatThread{min-height:420px}#chatThread [role=region]{padding:24px;border:1px solid #dde2ec;border-radius:12px}
  </style></head><body><main><h1>Agent Chat</h1><section id="chatThread"></section><textarea id="chatInput" aria-label="Message your Agent"></textarea></main>
  <button id="launchImageStudio">Image Generator</button></body></html>`;
await page.route('https://studio.test/',route => route.fulfill({contentType:'text/html',body:html}));
const fixtureImages = await page.evaluate(() => [
  ['#eee1bc','#e0a54e','#526c64'],['#d9e3d3','#f1cf7e','#688987'],['#e4cad1','#f4d4ae','#78738b'],
].map(([sky,sun,coast],index) => {
  const canvas=document.createElement('canvas');canvas.width=canvas.height=512;const context=canvas.getContext('2d');
  context.fillStyle=sky;context.fillRect(0,0,512,512);context.fillStyle=sun;context.beginPath();
  context.arc(340-index*35,195,74,0,Math.PI*2);context.fill();context.fillStyle=coast;context.beginPath();
  context.moveTo(0,360);context.bezierCurveTo(150,260,250,455,512,330);context.lineTo(512,512);context.lineTo(0,512);context.fill();
  context.fillStyle=coast;context.font='bold 40px Arial';context.fillText(index===1?'COASTAL STUDY':'VACATION BRAIN',28,66);
  return canvas.toDataURL('image/png').split(',')[1];
}));
await page.route('**/media-library-file-v86.php?**',route => {
  const index=(Number(new URL(route.request().url()).searchParams.get('id'))-1)%fixtureImages.length;
  return route.fulfill({contentType:'image/png',body:Buffer.from(fixtureImages[index]||fixtureImages[0],'base64')});
});
await page.route('**/api/**',async route => {
  const body = route.request().postDataJSON() || {};
  const path = new URL(route.request().url()).pathname;
  calls.push({path,...body});
  if (waiting.has(body.action)) { const resolve=waiting.get(body.action);waiting.delete(body.action);resolve(); }
  if (path !== '/api/chat-image-studio-v1.php') return route.fulfill({status:500,json:{ok:false,error:'Unexpected provider or generic activity request.'}});
  if (body.action==='history' && historyGate) await historyGate;
  if (body.action==='brainstorm') {
    if (brainstormGate) await brainstormGate;
    brainstorm = {request_id:body.request_id,state:'ready',brief:body.brief,created_at:'2026-11-17 12:00:00',completed_at:'2026-11-17 12:00:03',ideas};
  }
  if (mode==='denied') return route.fulfill({status:403,json:{ok:false,error:'Your Agent or Chat access changed.'}});
  return route.fulfill({json:{ok:true,state:state(body.conversation_id)}}).catch(() => {});
});
async function boot({cid=11,ensure='ready'}={}) {
  await page.goto('https://studio.test/');
  await page.evaluate(({cid,ensure}) => {
    window.fixtureCid = cid;window.fixtureEnsure = ensure;window.ensureCalls = 0;window.staged = [];window.viewerCalls = [];
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.STONEFELLOW_CHAT_CONTINUITY_V87 = {
      conversationId:() => window.fixtureCid,agentId:() => 7,isReady:() => true,isRestoring:() => false,
      ensureConversation:async () => {
        window.ensureCalls++;
        if (window.fixtureEnsure!=='ready') return 0;
        await Promise.resolve();window.fixtureCid=42;window.dispatchEvent(new Event('vp3:chat-context-ready'));return 42;
      },
    };
    window.VP3_ORB_CONFIG = {activityEndpoint:'/api/activity',ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',csrf:'fixture'};
    window.VP3_IMAGE_STUDIO_V1 = {endpoint:'/api/chat-image-studio-v1.php',csrf:'fixture',ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding'};
    window.VP3ImageComposer = {stagePrompt:async (prompt,options) => {
      const input=document.getElementById('chatInput');
      if (input.value.trim() || options.conversationId!==window.fixtureCid) return false;
      window.staged.push({prompt,...options});input.value=prompt;input.focus();return true;
    }};
    window.VP3CanvasMedia = {openImages:async (ids,options) => {
      window.viewerCalls.push({ids,index:options.index,focusReturn:options.focusReturn?.id});
      const viewer=document.createElement('section');viewer.setAttribute('role','region');viewer.setAttribute('aria-label','Image viewer');
      viewer.textContent='Saved image '+ids[options.index];document.getElementById('chatThread').append(viewer);return true;
    }};
  },{cid,ensure});
  for (const file of ['agent-activity-hub-v1.css','chat-image-studio-v1.css']) await page.addStyleTag({content:await source(file)});
  for (const file of ['chat-image-studio-v1.js','agent-activity-hub-v1.js']) await page.addScriptTag({content:await source(file)});
  await page.evaluate(() => document.getElementById('launchImageStudio').addEventListener('click',event =>
    window.VP3AgentActivityHub.openSpecialist('image-generator',event.currentTarget,{label:'Image Generator'})));
}
const panel = page.getByRole('dialog',{name:'Image Generator',exact:true});
const thumbnails = panel.locator('[data-image-studio-thumbnail]');
const brainRequests = () => calls.filter(call => call.action==='brainstorm').length;
const historyRequests = () => calls.filter(call => call.action==='history').length;
async function open() { await page.getByRole('button',{name:'Image Generator',exact:true}).click();await thumbnails.first().waitFor(); }
async function check(label,test) { await test();checks++;console.log('PASS '+label); }
async function capture(name) {
  const directory=process.env.VP3_IMAGE_UI_SCREENSHOTS;
  if (directory) { await fs.mkdir(directory,{recursive:true});await page.screenshot({path:directory+'/'+name+'.png',fullPage:true}); }
}
try {
  await boot();await open();
  await check('Image specialist opens directly to scoped Images, Prompts and Brainstorming tabs without a provider call',async () => {
    assert.equal(await thumbnails.count(),2);
    assert.deepEqual(await panel.getByRole('tab').allTextContents(),['Images','Prompts','Brainstorming']);
    assert.equal(await panel.getByRole('button',{name:/Try an image|Open workspace|settings/i}).count(),0);
    assert.equal(brainRequests(),0);
    assert.equal(calls.some(call => call.path!=='/api/chat-image-studio-v1.php'),false);
    assert.ok(await panel.getByText('Chat 14',{exact:false}).count()>0);
  });
  await check('keyboard arrows select the accessible history tabs',async () => {
    const images=panel.getByRole('tab',{name:'Image History'});await images.focus();await images.press('ArrowRight');
    assert.equal(await panel.getByRole('tab',{name:'Prompt History'}).getAttribute('aria-selected'),'true');
    await page.keyboard.press('Home');assert.equal(await images.getAttribute('aria-selected'),'true');
  });
  await capture('image-studio-images-desktop');
  await check('history thumbnail hands the exact ordered image IDs to the canvas viewer and closes the specialist popup',async () => {
    await thumbnails.nth(1).click();await panel.waitFor({state:'detached'});
    assert.deepEqual(await page.evaluate(() => window.viewerCalls),[{ids:[1,2],index:1,focusReturn:'chatInput'}]);
    assert.equal(await page.locator('#chatThread').getByRole('region',{name:'Image viewer'}).count(),1);
    assert.equal(brainRequests(),0);
  });
  await open();await panel.getByRole('tab',{name:'Prompt History'}).click();
  await check('reusing a prompt preserves the current draft and restores batch and base-image metadata only after acceptance',async () => {
    await page.locator('#chatInput').fill('My unfinished Chat draft');
    await panel.getByRole('button',{name:'Use prompt',exact:true}).click();
    await panel.getByRole('status').filter({hasText:'Your current draft or image could not be replaced.'}).waitFor();
    assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished Chat draft');
    assert.equal(await page.evaluate(() => window.staged.length),0);
    await page.locator('#chatInput').fill('');await panel.getByRole('button',{name:'Use prompt',exact:true}).click();
    await panel.waitFor({state:'detached'});
    assert.deepEqual(await page.evaluate(() => window.staged.at(-1)),{prompt,count:2,referenceAssetId:3,conversationId:11});
    assert.equal(brainRequests(),0);
  });
  await page.locator('#chatInput').fill('');await open();await panel.getByRole('tab',{name:'Brainstorming'}).click();
  await check('viewing Brainstorming only reads saved sources and the explicit button performs one text request',async () => {
    assert.equal(brainRequests(),0);
    await panel.getByLabel('A direction to explore (optional)').fill('Develop a coastal music theme');
    brainstormGate=new Promise(resolve => {brainstormRelease=resolve;});
    const started=waitForCall('brainstorm');
    await panel.getByRole('button',{name:'Brainstorm ideas',exact:true}).click();
    await started;
    await panel.getByRole('status').filter({hasText:'Connecting your prompts'}).waitFor();
    assert.equal(brainRequests(),1);
    const request=calls.find(call => call.action==='brainstorm');
    assert.equal(request.source_revision,revision);assert.equal(request.brief,'Develop a coastal music theme');
    assert.match(request.request_id,/^[a-f0-9]{32}$/);
    brainstormRelease();brainstormGate=null;
    await panel.getByRole('heading',{name:'Amber coast'}).waitFor();
    assert.equal(await panel.getByText('Inspired by: Vacation Brain · Coastal music direction',{exact:true}).count(),1);
  });
  await capture('image-studio-brainstorm-desktop');
  await check('an idea is reauthorized against current sources and staged for a later user Send',async () => {
    const before=historyRequests();await panel.getByRole('button',{name:'Use prompt',exact:true}).click();
    await panel.waitFor({state:'detached'});
    assert.equal(historyRequests(),before+1);
    assert.deepEqual(await page.evaluate(() => window.staged.at(-1)),{prompt:ideas[0].prompt,count:1,referenceAssetId:0,conversationId:11});
    assert.equal(brainRequests(),1);
    assert.equal(calls.some(call => ['tick','generate','send','upload'].includes(call.action)),false);
  });
  await page.locator('#chatInput').fill('');await open();await panel.getByRole('tab',{name:'Brainstorming'}).click();
  await check('withdrawn inspiration clears saved ideas before a reuse can overwrite the composer',async () => {
    const before=await page.evaluate(() => window.staged.length);
    revision='e'.repeat(64);brainstorm={...brainstorm,state:'stale',ideas:[]};
    await panel.getByRole('button',{name:'Use prompt',exact:true}).click();
    await panel.getByRole('status').filter({hasText:'Your inspiration sources changed or could not be verified.'}).waitFor();
    assert.equal(await panel.getByRole('heading',{name:'Amber coast'}).count(),0);
    assert.equal(await page.locator('#chatInput').inputValue(),'');
    assert.equal(await page.evaluate(() => window.staged.length),before);
  });
  await panel.getByRole('button',{name:'Close',exact:true}).click();mode='foreign-owner';
  await page.getByRole('button',{name:'Image Generator',exact:true}).click();
  await check('a response for another owner cannot render prompts, images or ideas',async () => {
    await panel.getByRole('status').filter({hasText:'could not be verified for the current Chat'}).waitFor();
    assert.equal(await thumbnails.count(),0);assert.equal(await panel.locator('[data-image-studio-prompt]').count(),0);
    assert.equal(await panel.getByRole('heading',{name:'Amber coast'}).count(),0);
  });
  await panel.getByRole('button',{name:'Close',exact:true}).click();mode='';
  await check('a late history response after a Chat switch cannot reopen the old specialist',async () => {
    historyGate=new Promise(resolve => {historyRelease=resolve;});
    const before=historyRequests(),started=waitForCall('history');await page.getByRole('button',{name:'Image Generator',exact:true}).click();
    await started;
    assert.equal(historyRequests(),before+1);
    await page.evaluate(() => {window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.fixtureCid=12;});
    historyRelease();historyGate=null;await panel.waitFor({state:'detached'});
    assert.equal(await panel.count(),0);
  });
  await boot({cid:0});await page.locator('#chatInput').fill('Keep this unsent new-Chat draft');
  await check('explicit opening initializes an empty Chat through canonical continuity while preserving its draft',async () => {
    assert.equal(await page.evaluate(() => window.ensureCalls),0);
    await open();assert.equal(await page.evaluate(() => window.ensureCalls),1);
    assert.equal(await page.locator('#chatInput').inputValue(),'Keep this unsent new-Chat draft');
    assert.equal(calls.at(-1).conversation_id,42);
    assert.equal(calls.some(call => call.path!=='/api/chat-image-studio-v1.php'),false);
    await panel.press('Escape');await panel.waitFor({state:'detached'});
    assert.equal(await page.getByRole('button',{name:'Image Generator',exact:true}).evaluate(element => document.activeElement===element),true);
  });
  await boot({cid:0,ensure:'unavailable'});
  await check('a Chat that is not ready offers a retry without sending a provider request',async () => {
    const before=historyRequests();await page.getByRole('button',{name:'Image Generator',exact:true}).click();
    await panel.getByRole('status').filter({hasText:'Your draft is kept'}).waitFor();
    assert.equal(historyRequests(),before);
    await page.evaluate(() => {window.fixtureEnsure='ready';});await panel.getByRole('button',{name:'Try again',exact:true}).click();
    await thumbnails.first().waitFor();assert.equal(await page.evaluate(() => window.ensureCalls),2);
  });
  await check('verified image completion refreshes this Chat history without responding to another Chat',async () => {
    const before=historyRequests();
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:image-generation-updated',{detail:{agentId:7,conversationId:999}})));
    assert.equal(historyRequests(),before);
    const refreshed=page.waitForResponse(response => response.url().endsWith('/api/chat-image-studio-v1.php'));
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:image-generation-updated',{detail:{agentId:7,conversationId:42}})));
    await refreshed;
    assert.equal(historyRequests(),before+1);
  });
  await page.setViewportSize({width:375,height:812});
  await check('background history refresh preserves the active brainstorm direction, caret and scroll position',async () => {
    await panel.getByRole('tab',{name:'Brainstorming'}).click();
    const direction = panel.getByLabel('A direction to explore (optional)');
    const draft = 'Explore a warm coastal album cover with amber sunlight, gentle waves and a textured paper finish. '.repeat(4);
    await direction.fill(draft);
    const editing = await direction.evaluate(element => {
      element.focus();element.setSelectionRange(150,160,'forward');element.scrollTop=45;
      const host=element.closest('.vp3-chat-specialist-activity');host.scrollTop=70;
      return {start:element.selectionStart,end:element.selectionEnd,scrollTop:element.scrollTop,hostScrollTop:host.scrollTop};
    });
    historyGate = new Promise(resolve => { historyRelease=resolve; });
    const started = waitForCall('history');
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:image-generation-updated',{detail:{agentId:7,conversationId:42}})));
    await started;
    const snapshot = () => direction.evaluate(element => ({
      focused:document.activeElement===element,start:element.selectionStart,end:element.selectionEnd,
      scrollTop:element.scrollTop,hostScrollTop:element.closest('.vp3-chat-specialist-activity').scrollTop
    }));
    assert.deepEqual(await snapshot(),{focused:true,...editing});
    historyRelease();historyGate=null;
    await page.waitForFunction(() => document.querySelector('.vp3-image-studio-feedback')?.textContent==='');
    assert.deepEqual(await snapshot(),{focused:true,...editing});
    await page.keyboard.type('sunlit');
    assert.equal(await direction.inputValue(),draft.slice(0,150)+'sunlit'+draft.slice(160));
    await panel.getByRole('tab',{name:'Image History'}).click();
    assert.equal(await panel.getByRole('tab',{name:'Image History'}).evaluate(element => document.activeElement===element),true);
  });
  await check('the narrow specialist layout fits the viewport with one outer scrolling surface',async () => {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth<=innerWidth+1));
    assert.equal(await panel.locator('.vp3-image-studio-content').evaluate(element => getComputedStyle(element).overflowY),'visible');
    assert.equal(await panel.evaluate(element => getComputedStyle(element).backgroundColor),'rgb(255, 255, 255)');
    const refresh = panel.getByRole('button',{name:'Refresh image history'});
    assert.equal(await refresh.evaluate(element => {
      const text = document.createRange();text.selectNodeContents(element);
      const lines = [...text.getClientRects()];
      return lines.length === 1 && text.getBoundingClientRect().width <= element.clientWidth;
    }),true,'The Refresh label remains on one line without clipping on mobile.');
  });
  await capture('image-studio-images-mobile');
  await panel.getByRole('tab',{name:'Brainstorming'}).click();await capture('image-studio-brainstorm-mobile');
  assert.deepEqual(errors,[]);
  console.log(`IMAGE_STUDIO_BROWSER=PASS (${checks} production specialist UI regressions)`);
} finally {
  historyRelease?.();brainstormRelease?.();await browser.close();
}
