import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=new URL('../',import.meta.url);
const files=['chat.js','chat.css','chat-coding-canvas-v1.js','chat-coding-canvas-v1.css','coding-canvas-runtime-v1.js',
  'coding-canvas-diff-v1.js','coding-canvas-source-editor-v1.js','assets/vendor/acorn-8.19.0.js'];
const sources=Object.fromEntries(await Promise.all(files.map(async file=>[file,await fs.readFile(new URL(file,root),'utf8')])));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(12000);
const errors=[],requests=[],saved=[{id:1,role:'assistant',message:'Original private conversation'}];let updates=[];
const id='10000000-0000-4000-8000-000000000001';
const canvas={id,title:'Private checklist',agent_id:7,conversation_id:42,active_revision:1,draft_revision:1};
const revision={id:1,status:'active',test_passed:true,can_rollback:false,source_hash:'b'.repeat(64),artifact:{html:'<h1>Private checklist</h1><input id="note" aria-label="Preview note">',css:'',js:''}};
page.on('pageerror',e=>errors.push(e.message));
await page.route('https://canvas-phone.test/**',async route=>{
  const req=route.request(),url=new URL(req.url());
  const respond=data=>route.fulfill({json:{ok:true,...data}});
  if(sources[url.pathname.slice(1)])return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:sources[url.pathname.slice(1)]});
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/chat-coding-canvas-v1.css">
    <div class="chat-app"><main class="chat-main"><header>Canvas workspace<button id="newChatButton">New Chat</button></header><div id="chatHistory" hidden></div>
    <section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section>
    <div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer"><textarea id="chatInput" aria-label="Message Agent"></textarea><button id="sendChatButton">Send</button><button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div></div></main></div>
    <script>window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,initialView:'chat',csrf:'fixture',endpoint:'/api/chat.php',workingAttentionBinding:'owner-fixture'};
    window.VP3_CODING_CANVAS_V1={endpoint:'/api/coding',csrf:'fixture',agentId:7};window.spoken=[];window.announcementsEnabled=true;
    window.VP3ConversationControls={acceptsProactivePosts:()=>announcementsEnabled};
    window.STONEFELLOW_NOTIFICATION_CENTER={announceWhen:(speech,current,options)=>{if(current())spoken.push({speech,options});}};</script>
    ${['chat.js','coding-canvas-runtime-v1.js','coding-canvas-diff-v1.js','assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js','chat-coding-canvas-v1.js'].map(file=>'<script src="/'+file+'"></script>').join('')}`});
  if(!url.pathname.startsWith('/api/'))return route.fulfill({status:404});
  const data=req.postDataJSON();requests.push({path:url.pathname,...data});
  if(url.pathname==='/api/coding'){
    assert.equal(data.agent_id,7);assert.equal(data.csrf_token,'fixture');
    if(data.action==='list')return respond({canvases:[canvas]});
    if(data.action==='resume')return respond({job:null});
    if(data.action==='open')return respond({canvas,revision,revisions:[revision],state:{revision:'a'.repeat(64),value:{}},source_editor:{ready:true,can_edit:true}});
    throw Error('Unexpected Canvas write '+data.action);
  }
  assert.equal(req.headers()['x-vp3-working-attention'],'owner-fixture');
  if(data.action==='load')return respond({messages:saved});
  if(data.action==='activity'){
    const phone=data.phone_updates===true&&data.conversation_id===42?updates.splice(0):[];
    return respond({conversation_id:Number(data.conversation_id),latest_message_id:saved.at(-1).id,latest_id:0,unread_count:0,updates:[],phone_updates:phone});
  }
  if(data.action==='messages_after'){
    const messages=structuredClone(saved.filter(row=>row.id>data.after_id));
    return respond({conversation_id:data.conversation_id,messages});
  }
  return respond({conversations:[{id:42,title:'Private Chat'}]});
});
const panel=page.locator('#chatCodingCanvasV1');
const message=n=>page.locator('[data-chat-message-id="'+n+'"]');
const pulse=()=>page.evaluate(()=>window.dispatchEvent(new Event('vp3:chat-canvas-view-changed')));
function receive(n,speech='Connected phone sent a message.',scope={}){
  saved.push({id:n,role:'assistant',message:'Saved phone post '+n});
  updates.push({owner_user_id:1,agent_id:7,conversation_id:42,message_id:n,speech,...scope});
}
try{
  await page.goto('https://canvas-phone.test/');await page.bringToFront();await message(1).waitFor();
  await page.waitForFunction(()=>STONEFELLOW_CHAT_CONTINUITY.isReady()&&window.VP3CodingCanvasV1);
  assert.equal(await page.evaluate(id=>VP3CodingCanvasV1.open(id,1),id),true);
  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  await panel.getByRole('tab',{name:'Source',exact:true}).click();
  const source=panel.locator('.vp3-coding-source-input');await source.fill('<h1>Unsaved edit kept</h1>');
  await page.locator('#chatInput').fill('Unsent Chat draft kept');
  await page.waitForTimeout(300); // Let the editor's initial shortcut/layout refresh settle before measuring an arriving post.
  const before=await page.evaluate(()=>({view:chatThread.dataset.view,scroll:chatThread.scrollTop,source:document.querySelector('.vp3-coding-source-input').value}));
  receive(2);await pulse();await message(2).waitFor({state:'attached'});
  await page.waitForFunction(()=>spoken.length===1);
  assert.equal(await message(2).isVisible(),false,'Post is already loaded behind Canvas');
  assert.equal(await panel.isVisible(),true);assert.equal(await source.inputValue(),before.source);
  assert.equal(await page.locator('#chatInput').inputValue(),'Unsent Chat draft kept');
  assert.equal(await page.locator('#chatThread').evaluate(el=>el.scrollTop),before.scroll,'Background posts never scroll the editor');
  assert.equal(await page.evaluate(()=>spoken[0].options.autoReply),false,'Phone announcements never activate microphone reply capture');
  assert.equal(await page.locator('#chatComposerShell').isVisible(),true);
  for(const width of [390,320]){
    await page.setViewportSize({width,height:850});
    const box=await page.locator('#chatComposerShell').boundingBox();
    assert.ok(box&&box.x>=-1&&box.x+box.width<=width+1&&box.y+box.height<=851,'Footer fits the phone viewport');
  }
  receive(3,'A connected phone replied to an information request.');await pulse();await message(3).waitFor({state:'attached'});await page.waitForFunction(()=>spoken.length===2);
  for(const scope of [{owner_user_id:2},{agent_id:8},{conversation_id:43}]){receive(saved.at(-1).id+1,'Wrong scope must stay silent',scope);await pulse();await message(saved.at(-1).id).waitFor({state:'attached'});}
  assert.equal(await page.evaluate(()=>spoken.length),2,'Foreign owner/Agent/conversation announcements remain blocked');
  await page.evaluate(()=>announcementsEnabled=false);receive(7,'Voice turned off');await pulse();await message(7).waitFor({state:'attached'});
  assert.equal(await page.evaluate(()=>spoken.length),2,'Muted announcements still load the saved post');
  await page.evaluate(()=>announcementsEnabled=true);await panel.getByRole('button',{name:'Back to Chat',exact:true}).click();
  await message(2).waitFor();await message(3).waitFor();await pulse();
  assert.equal(await page.evaluate(()=>spoken.length),2,'Closing Canvas never repeats consumed announcements');
  assert.equal(await message(2).count(),1);assert.equal(await message(3).count(),1);
  for(const [index,view] of ['showPlayerView','showEmailCanvasView','showMarketingCanvasView'].entries()){
    await page.evaluate(view=>STONEFELLOW_CHAT_CONTINUITY[view](),view);
    receive(8+index,'Phone update while another Agent tool is open');await pulse();await message(8+index).waitFor({state:'attached'});
    await page.waitForFunction(expected=>spoken.length===expected,3+index);
    assert.equal(await message(8+index).isVisible(),false,'Other Agent tools retain their view while Chat loads');
  }
  assert.ok(requests.filter(r=>r.path==='/api/coding').every(r=>['list','open','resume'].includes(r.action)),'Background refresh cannot save, rebuild or apply Canvas source');
  assert.deepEqual(errors,[]);
  console.log('CANVAS_PHONE_CHAT_BROWSER_V1=PASS (live background posts, announcements, unsaved editor/Chat drafts, mobile footer and exact scope)');
}catch(error){console.error(JSON.stringify({errors,requests:requests.slice(-8),body:(await page.locator('body').innerText()).slice(0,1800)}));throw error;}
finally{await browser.close();}
