import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

const {chromium} = await import('playwright').catch(error => {
  if (!process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES) throw error;
  return import(pathToFileURL(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,'playwright/index.mjs')).href);
});
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const errors = [],requests = [],drafts = new Map();
let count = 0,loseConfirmation = false,delayedSave = null,imageDone = false,expireSave = false,conflictSave = false,delayedWrite = null;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+i7iQAAAAASUVORK5CYII=','base64');
const assets = [1,2,3].map(id => ({id,asset_id:id,title:'Fixture image '+id,name:'Fixture image '+id,media_type:'photo',
  mime:'image/png',mime_type:'image/png',size:png.length,file_size:png.length,available:true,url:'/media-library-file-v86.php?id='+id}));
const imageId = '11111111-1111-4111-8111-111111111111';
const generation = () => ({id:imageId,status:imageDone ? 'completed' : 'queued',asset:imageDone ? assets[1] : null});
function makeDraft(cid,{reply = false,...values} = {}) {
  const id = (++count).toString(16).padStart(32,'0');
  const draft = {id,cid,status:'prepared',from:'owner@example.test',to:'',subject:'',body:'',attachments:[],
    is_reply:reply,can_edit:true,can_send:false,review_token:'token-'+id+'-1',edit_revision:id+'0'.repeat(32),saved_at:new Date().toISOString(),...values};
  drafts.set(id,draft);return draft;
}
const publicDraft = draft => ({...draft,can_send:!!draft.to && !!draft.body && (!!draft.subject || draft.is_reply) && draft.status === 'prepared'});
const nextToken = draft => { draft.review_token += '-next';draft.edit_revision=draft.id+draft.review_token.length.toString(16).padStart(32,'0');draft.saved_at = new Date().toISOString(); };
const originalReply = makeDraft(42,{reply:true,to:'sender@example.test',subject:'Re: Project details',body:'The Agent’s source-bound reply.'});
page.on('pageerror',error => errors.push(error.message));
await page.route('https://compose.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>
html,body{margin:0;height:100%;font:14px Arial,sans-serif;background:#f3f5f8}main{height:100vh;display:flex;flex-direction:column}
#chatThread{flex:1;min-height:0;overflow:auto;padding:20px;box-sizing:border-box}#chatComposerShell{padding:12px;background:white}
#chatInput{box-sizing:border-box;width:100%;height:40px}#gmailLauncher{position:fixed;right:12px;top:10px;z-index:1}
</style><main><section id="chatThread" data-view="chat"><article class="message">Existing Chat message</article></section>
<div id="chatComposerShell"><textarea id="chatInput" aria-label="Agent Chat message"></textarea></div></main>
<button id="gmailLauncher">Gmail</button><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://compose.test/media-library-file-v86.php**',route => route.fulfill({contentType:'image/png',body:png}));
await page.route('https://compose.test/api/hub**',route => {
  const cid = Number(new URL(route.request().url()).searchParams.get('conversation_id'));
  return route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:cid,
    working_attention_binding:'fixture-binding',agent_name:'Agent',current_chat:{title:'Email Chat'},observed_at:new Date().toISOString(),
    coverage:{},sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked.'}]}}});
});
await page.route('https://compose.test/api/chat-email-v1.php',async route => {
  const input = route.request().postDataJSON();requests.push({...input});
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.csrf_token,'fixture');
  const ok = result => route.fulfill({json:{ok:true,...result}}).catch(() => {});
  if (input.action === 'compose_create') {
    assert.match(input.request_id,/^[a-f0-9]{32}$/);
    return ok({draft:publicDraft(makeDraft(input.conversation_id,{to:input.to || '',subject:input.subject || '',body:input.body || ''}))});
  }
  if (input.action === 'compose_list') {
    const all = [...drafts.values()].filter(d => input.queue_scope === 'agent' || d.cid === input.conversation_id);
    const pending = all.filter(d => !['sent','discarded','answered'].includes(d.status));
    const rows = input.filter === 'all' ? all : pending;
    const counts = {pending:pending.length,total:all.length,sent:all.filter(d=>d.status==='sent').length,
      discarded:all.filter(d=>d.status==='discarded').length,answered:all.filter(d=>d.status==='answered').length};
    return ok({drafts:rows.slice(0,input.limit || 20).map(d=>({...publicDraft(d),conversation_id:d.cid,conversation_title:'Email Chat',
      target:{agent_id:7,conversation_id:d.cid,draft_id:d.id},source:{kind:'manual',reason:'',priority:'normal',deadline:null}})),
      counts,scope:input.queue_scope || 'chat',filter:input.filter || 'pending',next_cursor:null,from:'owner@example.test',can_send:true});
  }
  const draft = drafts.get(input.draft_id);
  assert.ok(draft);assert.equal(draft.cid,input.conversation_id,'Draft reads and writes stay in their original Chat.');
  if (input.action === 'compose_save') {
    if (expireSave) {
      expireSave=false;draft.review_token+='-renewed';
      return route.fulfill({status:409,json:{ok:false,error:'The source or proposal changed, or the review expired. Review it again.'}});
    }
    if (conflictSave) {
      conflictSave=false;draft.body='A different edit saved in another browser.';nextToken(draft);
      return route.fulfill({status:409,json:{ok:false,error:'This email draft changed. Open the latest saved version.'}});
    }
    assert.equal(input.review_token,draft.review_token,'Edits bind to the currently reviewed version.');
    if (draft.is_reply) { assert.equal(input.to,draft.to);assert.equal(input.subject,draft.subject); }
    if (delayedSave) { const gate=delayedSave;delayedSave=null;gate.started();await gate.promise; }
    Object.assign(draft,{to:input.to,subject:input.subject,body:input.body,attachments:input.attachment_ids.map(id=>assets.find(a=>a.asset_id===id))});
    nextToken(draft);
  } else if (input.action === 'compose_generate') {
    assert.equal(input.review_token,draft.review_token);
    assert.ok(input.instruction);draft.status='write_queued';draft.review_token=null;draft.instruction=input.instruction;
  } else if (input.action === 'advance_write') {
    draft.body='Agent-generated draft using: '+draft.instruction;
    if (!draft.is_reply) draft.subject='Agent-generated subject';
    draft.status='prepared';draft.review_token='written-'+draft.id;
    if (delayedWrite) { const gate=delayedWrite;delayedWrite=null;gate.started();await gate.promise; }
  } else if (input.action === 'confirm') {
    assert.equal(input.review_token,draft.review_token);assert.equal(input.confirmed,true);
    assert.ok(draft.body);assert.ok(draft.to);draft.status='sent';draft.can_edit=false;draft.review_token=null;
    if (loseConfirmation) { loseConfirmation=false;return route.fulfill({status:503,json:{ok:false,error:'The connection ended before send confirmation.'}}); }
  } else if (input.action === 'discard') { draft.status='discarded';draft.review_token=null; }
  else assert.equal(input.action,'review');
  return ok({draft:publicDraft(draft)});
});
await page.route('https://compose.test/api/media-library-v86.php**',route => {
  assert.equal(route.request().headers()['x-vp3-media-owner'],'1');
  if (route.request().method() === 'GET') return route.fulfill({json:{ok:true,assets:[assets[2]],next_before:null}});
  const body = route.request().postDataBuffer().toString('latin1');
  const action = body.match(/name="action"\r\n\r\n([^\r]+)/)?.[1];
  if (action === 'begin') return route.fulfill({json:{ok:true,token:'a'.repeat(32),chunk_size:1024*1024}});
  if (action === 'chunk') return route.fulfill({json:{ok:true}});
  assert.equal(action,'finish');return route.fulfill({json:{ok:true,asset:assets[0]}});
});
await page.route('https://compose.test/api/media-library-image-v1.php',async route => {
  const input = route.request().postDataJSON();assert.equal(input.owner_key,'1');assert.equal(input.csrf_token,'fixture');
  if (input.action === 'preview') return route.fulfill({json:{ok:true,plan:{prompt:input.prompt,title:input.title,size:input.size,quality:input.quality,background:input.background},request_key:'b'.repeat(32),review_token:'image-review'}});
  if (input.action === 'submit') { assert.equal(input.review_token,'image-review');assert.equal(input.confirmed,true); }
  else { assert.ok(['get','tick','cancel'].includes(input.action));assert.equal(input.generation_id,imageId); }
  if (input.action === 'tick') { imageDone=true; }
  return route.fulfill({json:{ok:true,generation:generation()}});
});
const root = new URL('../',import.meta.url);
async function bootstrap() {
  await page.goto('https://compose.test/');
  await page.evaluate(() => {
    window.currentChat=42;
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    const view = name => { document.getElementById('chatThread').dataset.view=name;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed')); };
    window.STONEFELLOW_CHAT_CONTINUITY_V87={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>true,isRestoring:()=>false,
      ensureConversation:async()=>window.currentChat,showEmailCanvasView:()=>view('email'),showChatView:()=>view('chat')};
    window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7,
      mediaLibraryEndpoint:'/api/media-library-v86.php',imageEndpoint:'/api/media-library-image-v1.php'};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
    window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',emailReport:()=>''};
    window.VP3ParticipantContinuity={selectedContext:()=>window.participantSelected ? {participant_id:9} : null};
  });
  for (const file of ['agent-activity-hub-v1.css','chat-email-composer-v1.css']) await page.addStyleTag({content:await fs.readFile(new URL(file,root),'utf8')});
  for (const file of ['chat-email-composer-v1.js','agent-activity-hub-v1.js']) await page.addScriptTag({content:await fs.readFile(new URL(file,root),'utf8')});
  await page.evaluate(() => document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'})));
}
const editor = () => page.locator('.vp3-email-compose').filter({visible:true});
async function openNew(surface='popup') {
  assert.equal(await page.evaluate(surface=>window.VP3EmailComposerV1.openCompose({surface}),surface),true);
  await editor().getByLabel('To',{exact:true}).waitFor();
}
async function bounds(locator) {
  const result = await locator.evaluate(el=>{const box=el.getBoundingClientRect();return {left:box.left,right:box.right,top:box.top,bottom:box.bottom,scroll:el.scrollWidth,width:el.clientWidth};});
  const viewport=page.viewportSize();assert.ok(result.left>=-1 && result.right<=viewport.width+1,'Composer fits viewport horizontally');
  assert.ok(result.scroll<=result.width+1,'Composer has no horizontal scrolling');
}
try {
  await bootstrap();
  await page.getByRole('button',{name:'Gmail',exact:true}).click();
  await page.getByRole('button',{name:'Compose new email',exact:true}).click();
  await editor().getByLabel('To',{exact:true}).waitFor();
  const first = [...drafts.values()].at(-1);
  await editor().getByLabel('Subject',{exact:true}).fill('A partial draft');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.equal(first.subject,'A partial draft');assert.equal(first.to,'');
  assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isDisabled(),true);
  await editor().getByLabel('To',{exact:true}).fill('recipient@example.test');
  await editor().getByLabel('Message',{exact:true}).fill('A message written manually.');
  expireSave=true;
  const savesBeforeExpiry=requests.filter(r=>r.action==='compose_save').length;
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.equal(requests.filter(r=>r.action==='compose_save').length,savesBeforeExpiry+2,'An expired token refreshes only an unchanged saved revision, then retries once.');
  await editor().getByLabel('Message',{exact:true}).fill('My local edit survives a conflict.');
  conflictSave=true;
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByRole('button',{name:'Use my edits',exact:true}).waitFor();
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'My local edit survives a conflict.');
  assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isDisabled(),true);
  await editor().getByRole('button',{name:'Use my edits',exact:true}).click();
  await editor().getByLabel('Message',{exact:true}).fill('A message written manually.');
  await page.evaluate(()=>window.sameEditor=document.querySelector('.vp3-email-compose textarea[rows="8"]'));
  await editor().getByRole('button',{name:'Open in canvas',exact:true}).click();
  assert.equal(await page.locator('#chatThread').getAttribute('data-view'),'email');
  assert.equal(await page.evaluate(()=>window.sameEditor===document.querySelector('#chatEmailCanvasV1 textarea[rows="8"]')),true,'Popup and canvas move the same form.');
  await editor().getByRole('button',{name:'Pop out',exact:true}).click();
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'A message written manually.');
  await bounds(page.locator('#vp3EmailComposerPopupV1'));
  await editor().locator('input[type=file]').setInputFiles({name:'attachment.png',mimeType:'image/png',buffer:png});
  await editor().getByRole('button',{name:'Remove Fixture image 1'}).waitFor();
  await editor().getByRole('button',{name:'Saved images',exact:true}).click();
  await editor().getByRole('button',{name:'Attach Fixture image 3'}).click();
  await editor().getByRole('button',{name:'Remove Fixture image 3'}).click();
  await editor().getByRole('button',{name:'Generate image',exact:true}).first().click();
  await editor().getByLabel('Image description',{exact:true}).fill('A calm desert sunset for my email');
  await editor().locator('[data-email-generate-image]').click();
  await editor().getByRole('button',{name:'Remove Fixture image 2'}).waitFor();
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.deepEqual(first.attachments.map(a=>a.asset_id),[1,2]);
  await editor().getByRole('button',{name:'Needs review',exact:true}).click();
  await editor().getByText('A partial draft',{exact:true}).waitFor();
  await editor().locator('article').filter({hasText:'A partial draft'}).getByRole('button',{name:'Open draft'}).click();
  await editor().getByRole('button',{name:'Remove Fixture image 2'}).waitFor();
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'A message written manually.');
  await editor().locator('summary').filter({hasText:'Ask the Agent'}).click();
  await editor().getByLabel('Instructions',{exact:true}).fill('Make it welcoming and concise.');
  let releaseWrite,startWrite;const writingStarted=new Promise(resolve=>{startWrite=resolve;});
  delayedWrite={promise:new Promise(resolve=>{releaseWrite=resolve;}),started:startWrite};
  const reviewsBeforeWrite=requests.filter(r=>r.action==='review' && r.draft_id===first.id).length;
  await editor().getByRole('button',{name:'Revise with Agent',exact:true}).click();
  await writingStarted;
  await page.waitForTimeout(2100);
  assert.equal(requests.filter(r=>r.action==='review' && r.draft_id===first.id).length,reviewsBeforeWrite,'Writing does not race its own poller.');
  await editor().getByRole('button',{name:'Reload saved draft',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.vp3-email-compose textarea[rows="8"]').value.startsWith('Agent-generated draft'));
  await editor().getByLabel('Message',{exact:true}).fill('Owner edits after the newer review.');
  releaseWrite();await page.waitForTimeout(100);
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'Owner edits after the newer review.','A delayed model response cannot overwrite edits from a newer review.');
  assert.deepEqual(first.attachments.map(a=>a.asset_id),[1,2],'AI revision preserves selected attachments.');
  await editor().getByLabel('Message',{exact:true}).fill('My exact final edited message.');
  loseConfirmation=true;
  await editor().getByRole('button',{name:'Send',exact:true}).evaluate(el=>{el.click();el.click();});
  await editor().getByRole('button',{name:'Check send status',exact:true}).waitFor();
  assert.equal(requests.filter(r=>r.action==='confirm' && r.draft_id===first.id).length,1,'Repeated clicks do not submit twice.');
  assert.equal(first.body,'My exact final edited message.');
  await editor().getByRole('button',{name:'Check send status',exact:true}).click();
  await editor().getByText('Gmail confirmed your email was sent.',{exact:true}).waitFor();
  assert.equal(requests.filter(r=>r.action==='confirm' && r.draft_id===first.id).length,1,'Uncertain outcome is checked before any retry.');
  await editor().getByRole('button',{name:'Close',exact:true}).click();

  await openNew('canvas');
  const recovery=[...drafts.values()].at(-1);
  await editor().getByLabel('Message',{exact:true}).fill('Keep this unsaved text in its original Chat.');
  assert.equal(await page.evaluate(id=>window.VP3EmailComposerV1.openDraft({draft_id:id,agent_id:7,conversation_id:42}),originalReply.id),false,'Automatic draft opening does not replace dirty owner edits.');
  await editor().getByRole('button',{name:'Close',exact:true}).click();
  await editor().getByRole('button',{name:'Keep editing',exact:true}).click();
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'Keep this unsaved text in its original Chat.');
  await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.currentChat=43;window.STONEFELLOW_CHAT_CONTINUITY_V87.showChatView();});
  assert.equal(await page.locator('.vp3-email-compose').count(),0);
  assert.equal(await page.evaluate(id=>window.VP3EmailComposerV1.openDraft({draft_id:id,agent_id:7,conversation_id:42}),recovery.id),false);
  await page.evaluate(()=>{window.currentChat=42;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  assert.equal(await page.evaluate(id=>window.VP3EmailComposerV1.openDraft({draft_id:id,agent_id:7,conversation_id:42}),recovery.id),true);
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'Keep this unsaved text in its original Chat.');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  await editor().getByRole('button',{name:'Close',exact:true}).click();

  assert.equal(await page.evaluate(id=>window.VP3EmailComposerV1.openDraft({draft_id:id,agent_id:7,conversation_id:42}),originalReply.id),true);
  assert.equal(await editor().getByLabel('To',{exact:true}).getAttribute('readonly'),'');
  assert.equal(await editor().getByLabel('Subject',{exact:true}).getAttribute('readonly'),'');
  await editor().getByLabel('Message',{exact:true}).fill('My reviewed reply preserves its source.');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.equal(originalReply.to,'sender@example.test');assert.equal(originalReply.subject,'Re: Project details');
  await page.setViewportSize({width:390,height:844});
  await bounds(page.locator('#chatEmailCanvasV1'));
  await editor().getByRole('button',{name:'Pop out',exact:true}).click();
  await bounds(page.locator('#vp3EmailComposerPopupV1'));
  const shot = process.env.VP3_EMAIL_COMPOSER_SCREENSHOT;
  if (shot) { await fs.mkdir(path.dirname(shot),{recursive:true});await page.screenshot({path:shot,fullPage:true}); }
  await editor().getByRole('button',{name:'Close',exact:true}).click();

  await openNew();
  await editor().getByLabel('Subject',{exact:true}).fill('Late response test');
  let release,started;const waiting=new Promise(resolve=>{started=resolve;});
  delayedSave={promise:new Promise(resolve=>{release=resolve;}),started};
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await waiting;
  await page.evaluate(()=>{window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.currentChat=43;});
  release();
  await openNew();
  assert.equal(await editor().getByLabel('Subject',{exact:true}).inputValue(),'','A late save from another Chat cannot paint into the current composer.');
  await page.evaluate(()=>{window.participantSelected=true;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
  assert.equal(await page.locator('.vp3-email-compose').count(),0,'Participant context closes the private composer.');
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.openCompose()),false);
  assert.deepEqual(errors,[]);
  console.log('Gmail composer browser: popup/canvas, partial drafts, edits, scoped recovery, AI drafts, images, send reconciliation, mobile and context isolation passed.');
} finally { await browser.close(); }
