import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

const {chromium} = await import('playwright').catch(error => {
  if (!process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES) throw error;
  return import(pathToFileURL(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,'playwright/index.mjs')).href);
});
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:1280,height:920},timezoneId:'America/Phoenix'});
page.setDefaultTimeout(10000);
const root = new URL('../',import.meta.url);
const requests = [],errors = [],drafts = new Map();
let sequence = 0,delaySource = null,delaySummary = null,failReview = false,malformedQueue = false,remoteImages = 0;
function makeDraft(cid,values = {}) {
  const id = (++sequence).toString(16).padStart(32,'0');
  const draft = {id,cid,owner:1,agent:7,version:1,status:'prepared',from:'owner@example.test',to:'client@example.test',
    subject:'Email '+sequence,body:'Saved reply '+sequence,is_reply:true,attachments:[],can_open_source:true,
    edit_revision:id+'1'.padStart(32,'0'),review_token:'review-'+id+'-1',created_at:'2026-12-01T15:00:00Z',
    saved_at:'2026-12-04T15:00:00Z',age_seconds:259200,persistent:true,
    source:{kind:'reply',reason:'Drafted from the original email.',priority:null,deadline:null},...values};
  drafts.set(id,draft);return draft;
}
const organic = makeDraft(43,{subject:'Re: Delivery confirmation',body:'The Agent prepared this delivery reply.',
  source:{kind:'organic_reply',reason:'The customer needs confirmation of the delivery time.',priority:'high',deadline:'2026-12-07'}});
const local = makeDraft(42,{subject:'Re: Local project',body:'A source-bound local reply.'});
const expired = makeDraft(42,{subject:'Re: Expired proposal',status:'expired',stored_status:'prepared',persistent:false,blocked_reason:'expired'});
makeDraft(42,{subject:'Re: New details',status:'stale',blocked_reason:'stale'});
makeDraft(42,{subject:'Re: Agent writing',status:'writing',blocked_reason:'writing',review_token:null});
makeDraft(42,{subject:'Re: Send receipt',status:'sending',blocked_reason:'sending'});
const unavailable = makeDraft(42,{subject:'',body:'',to:'',status:'unavailable',stored_status:'prepared',available:false,
  can_open_source:false,blocked_reason:'unavailable',source:{kind:'unavailable',reason:null,priority:null,deadline:null}});
for (let i = 0; i < 16; i++) makeDraft(42,{subject:'Saved follow-up '+(i+1),is_reply:false,can_open_source:false,
  source:{kind:'new_email',reason:'Saved in this Chat.',priority:null,deadline:null}});
makeDraft(42,{subject:'A sent email',status:'sent',can_open_source:false});
makeDraft(42,{subject:'A discarded email',status:'discarded',can_open_source:false});
makeDraft(42,{subject:'Another agent’s private draft',agent:8});
makeDraft(42,{subject:'Another owner’s private draft',owner:2});
const storedStatus = draft => draft.stored_status || draft.status;
const isPending = draft => !['sent','discarded','answered'].includes(storedStatus(draft));
function publicDraft(draft) {
  const editable = ['prepared','write_failed'].includes(draft.status) && !draft.connection_changed;
  return {...draft,body:draft.connection_changed ? '' : draft.body,to:draft.connection_changed ? '' : draft.to,
    subject:draft.connection_changed ? '' : draft.subject,can_edit:editable,can_send:editable && Boolean(draft.to && draft.subject && draft.body),
    review_token:editable || draft.status === 'sending' ? draft.review_token : null};
}
function bump(draft) {
  draft.version++;draft.edit_revision=draft.id+draft.version.toString(16).padStart(32,'0');
  draft.review_token='review-'+draft.id+'-'+draft.version;draft.saved_at=new Date().toISOString();
}
function summary(draft) {
  const {body,review_token,...value} = publicDraft(draft);
  return {...value,stored_status:storedStatus(draft),available:draft.available !== false,
    conversation_id:draft.cid,conversation_title:draft.cid === 43 ? 'Customer deliveries' : 'Project Chat',updated_at:draft.saved_at,
    target:{agent_id:draft.agent,conversation_id:draft.cid,draft_id:draft.id}};
}
const sourcePacket = draft => ({draft_id:draft.id,edit_revision:draft.edit_revision,conversation_id:draft.cid,message:{
  subject:'Original: '+draft.subject,from:'Client <client@example.test>',body:'Please confirm delivery on December 7. Source for '+draft.id,
  display:{version:1,remote_images:1,truncated:false,html:'<!doctype html><html><head><style>.source-note{padding:18px;background:#edf3fb;color:#214b75;border-radius:8px}h2{font-size:20px}</style></head><body><div class="source-note"><h2>Delivery confirmation</h2><p>Please confirm our December 7 delivery.</p><img src="https://remote.test/private-email-image.png" alt="Sender image"></div></body></html>'}}});
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://remote.test/**',route=>{remoteImages++;return route.fulfill({status:404,body:''});});
await page.route('https://review.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>
html,body{margin:0;height:100%;font:14px Arial,sans-serif;background:#f3f5f8}main{height:100vh;display:flex;flex-direction:column}
#chatThread{flex:1;min-height:0;overflow:auto;padding:20px;box-sizing:border-box}#chatComposerShell{padding:12px;background:white}
#chatInput{box-sizing:border-box;width:100%;height:40px}#gmailLauncher{position:fixed;right:12px;top:10px;z-index:1}
</style><main><section id="chatThread" data-view="chat"><article>Private Chat</article></section>
<div id="chatComposerShell"><textarea id="chatInput" aria-label="Agent Chat message"></textarea></div></main>
<button id="gmailLauncher">Gmail</button><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://review.test/api/hub**',route=>route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,
  agent_id:7,conversation_id:Number(new URL(route.request().url()).searchParams.get('conversation_id')),working_attention_binding:'fixture-binding',
  agent_name:'Agent',current_chat:{title:'Project Chat'},observed_at:new Date().toISOString(),coverage:{},
  sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked.'}]}}}));
await page.route('https://review.test/api/chat-email-v1.php',async route=>{
  const input = route.request().postDataJSON();requests.push({...input});
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.csrf_token,'fixture');
  const ok = value => route.fulfill({json:{ok:true,...value}}).catch(()=>{});
  const conflict = error => route.fulfill({status:409,json:{ok:false,error}}).catch(()=>{});
  if (input.action === 'compose_list') {
    assert.equal(input.queue_scope,'agent');assert.ok(['pending','all'].includes(input.filter));
    const all = [...drafts.values()].filter(d=>d.owner===1 && d.agent===7);
    const counts = {pending:all.filter(isPending).length,total:all.length,sent:all.filter(d=>storedStatus(d)==='sent').length,
      discarded:all.filter(d=>storedStatus(d)==='discarded').length,answered:all.filter(d=>storedStatus(d)==='answered').length};
    const rows = input.filter==='pending' ? all.filter(isPending) : all;
    const offset = input.cursor === 'signed-page-2' ? 20 : 0;
    assert.ok(input.cursor===null || input.cursor==='signed-page-2');
    const items = rows.slice(offset,offset+input.limit).map(summary);
    if (malformedQueue && input.limit>1) { malformedQueue=false;items[0]={...items[0],target:{agent_id:8,conversation_id:43,draft_id:items[0].id}}; }
    if (delaySummary && input.limit===1) { const gate=delaySummary;delaySummary=null;gate.started();await gate.promise; }
    return ok({scope:'agent',filter:input.filter,counts,from:'owner@example.test',can_send:true,drafts:items,
      next_cursor:offset+input.limit<rows.length && input.limit>1 ? 'signed-page-2' : null});
  }
  const draft = drafts.get(input.draft_id);
  assert.ok(draft);assert.equal(draft.owner,1);assert.equal(draft.agent,7);
  assert.equal(input.conversation_id,draft.cid,'Every draft mutation/read is in its original private Chat.');
  if (input.action==='compose_source') {
    assert.equal(input.edit_revision,draft.edit_revision);
    if (!draft.can_open_source || draft.connection_changed) return conflict('Gmail source access changed.');
    const packet = sourcePacket(draft);
    if (delaySource) { const gate=delaySource;delaySource=null;gate.started();await gate.promise; }
    return ok({source:packet});
  }
  if (input.action==='review') {
    if (failReview) { failReview=false;return route.fulfill({status:403,json:{ok:false,error:'Gmail source access changed.'}}); }
    return ok({draft:publicDraft(draft)});
  }
  if (input.action==='compose_save') {
    if (input.review_token!==draft.review_token) return conflict('The saved draft changed.');
    assert.equal(input.to,draft.to);assert.equal(input.subject,draft.subject);
    assert.ok(['prepared','write_failed'].includes(draft.status));
    draft.body=input.body;bump(draft);
  } else if (input.action==='compose_generate') {
    assert.equal(input.review_token,draft.review_token);assert.ok(input.instruction);
    draft.instruction=input.instruction;draft.status='write_queued';bump(draft);
  } else if (input.action==='advance_write') {
    assert.equal(draft.status,'write_queued');draft.body='Revised for the owner: '+draft.instruction;draft.status='prepared';bump(draft);
  } else if (input.action==='discard') {
    if (input.edit_revision!==draft.edit_revision) return conflict('The selected draft changed.');
    draft.status='discarded';delete draft.stored_status;draft.review_token=null;draft.body='';bump(draft);
  } else throw new Error('Unexpected Gmail action: '+input.action);
  return ok({draft:publicDraft(draft)});
});
await page.goto('https://review.test/');
await page.evaluate(()=>{
  window.currentChat=42;window.navigationAllowed=false;window.navigationTargets=[];
  window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
  const view = name=>{document.getElementById('chatThread').dataset.view=name;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
  window.STONEFELLOW_CHAT_CONTINUITY_V87={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>true,isRestoring:()=>false,
    ensureConversation:async()=>window.currentChat,showEmailCanvasView:()=>view('email'),showChatView:()=>view('chat'),
    openEmailReviewConversation:async target=>{
      if (target.owner_key!=='1' || target.agent_id!==7 || !window.navigationAllowed) return false;
      window.navigationTargets.push(target);window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
      window.currentChat=target.conversation_id;view('chat');window.dispatchEvent(new Event('vp3:chat-context-ready'));return true;
    }};
  window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7};
  window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
  window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',emailReport:()=>''};
  window.VP3ParticipantContinuity={selectedContext:()=>window.participantSelected ? {participant_id:9} : null};
});
for (const file of ['agent-activity-hub-v1.css','gmail-email-display-v1.css','chat-email-composer-v1.css'])
  await page.addStyleTag({content:await fs.readFile(new URL(file,root),'utf8')});
for (const file of ['gmail-email-display-v1.js','chat-email-composer-v1.js','agent-activity-hub-v1.js'])
  await page.addScriptTag({content:await fs.readFile(new URL(file,root),'utf8')});
await page.evaluate(()=>document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'})));
const editor = ()=>page.locator('.vp3-email-compose').filter({visible:true});
const card = draft=>editor().locator('[data-email-draft="'+draft.id+'"]');
const context = {owner_key:'1',agent_id:7,conversation_id:43};
async function instruction(text,scope=context) {
  return page.evaluate(({text,scope})=>{
    const match=window.VP3EmailComposerV1.matchInstruction(text,scope);
    return window.VP3EmailComposerV1.handleChatInstruction(text,scope,match);
  },{text,scope});
}
async function screenshot(name) {
  const directory=process.env.VP3_EMAIL_REVIEW_SCREENSHOT_DIR;if (!directory) return;
  await fs.mkdir(directory,{recursive:true});await page.screenshot({path:path.join(directory,name),fullPage:true});
}
async function bounds() {
  const value=await editor().evaluate(el=>({scroll:el.scrollWidth,width:el.clientWidth,left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right}));
  assert.ok(value.left>=-1 && value.right<=page.viewportSize().width+1,'Review surface fits the viewport.');
  assert.ok(value.scroll<=value.width+1,'Review surface does not scroll horizontally.');
  const nested=await editor().evaluate(el=>[...el.querySelectorAll('*')].filter(child=>{
    const style=getComputedStyle(child);return ['auto','scroll'].includes(style.overflowY) && child.scrollHeight>child.clientHeight+1;
  }).map(el=>el.className));
  assert.deepEqual(nested,[],'Only the surrounding popup/canvas scrolls.');
}
try {
  await page.getByRole('button',{name:'Gmail',exact:true}).click();
  await page.getByRole('button',{name:/Needs review, 23 pending drafts/}).click();
  await editor().getByText('23 pending drafts',{exact:true}).waitFor();
  assert.equal(await card(organic).getByText('Reply · Agent prepared',{exact:true}).count(),1);
  await card(organic).getByText('Created 3 days ago',{exact:true}).waitFor();
  const deadline=await card(organic).locator('.vp3-email-compose-deadline').textContent();
  assert.match(deadline,/Dec 7, 2026/,'A date-only deadline stays December 7 in America/Phoenix.');
  assert.equal(await card(unavailable).getByRole('button',{name:'Open draft',exact:true}).isDisabled(),true);
  assert.equal(await editor().getByText('Another agent’s private draft',{exact:true}).count(),0);
  assert.equal(await editor().getByText('Another owner’s private draft',{exact:true}).count(),0);
  await bounds();await screenshot('review-queue-desktop.png');
  await editor().getByRole('button',{name:'Next',exact:true}).click();
  await editor().getByText('Page 2',{exact:true}).waitFor();
  assert.equal(await editor().getByRole('button',{name:'Next',exact:true}).isDisabled(),true);
  await editor().getByRole('button',{name:'Previous',exact:true}).click();
  await editor().getByText('Page 1',{exact:true}).waitFor();
  await editor().getByLabel('Draft status filter').selectOption('all');
  await page.waitForFunction(()=>document.querySelector('.vp3-email-compose-notice')?.textContent.startsWith('Drafts from'));
  assert.ok(requests.some(input=>input.action==='compose_list' && input.filter==='all'));
  await editor().getByLabel('Draft status filter').selectOption('pending');
  await card(organic).getByRole('button',{name:'Open original Chat',exact:true}).click();
  await editor().getByText('The original Chat could not be opened. Your current Chat is unchanged.',{exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>window.currentChat),42);
  await page.evaluate(()=>{window.navigationAllowed=true;});
  await card(organic).getByRole('button',{name:'Open original Chat',exact:true}).click();
  await editor().getByLabel('Message',{exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>window.currentChat),43);
  assert.equal(await editor().getByLabel('To',{exact:true}).getAttribute('readonly'),'');
  await editor().getByRole('button',{name:'Read original email',exact:true}).click();
  await editor().locator('iframe').waitFor();
  await page.waitForFunction(()=>document.querySelector('.vp3-email-compose iframe')?.contentDocument?.querySelector('.source-note'));
  assert.equal(remoteImages,0,'Source display makes no sender-image requests by default.');
  assert.equal(await editor().locator('iframe').getAttribute('scrolling'),'no');
  await editor().getByRole('button',{name:'Plain text',exact:true}).click();
  await editor().getByText('Please confirm delivery on December 7. Source for '+organic.id,{exact:true}).waitFor();
  await editor().getByRole('button',{name:'Formatted',exact:true}).click();
  await bounds();await screenshot('review-source-desktop.png');
  const selected=await page.evaluate(()=>window.VP3EmailComposerV1.selectedDraft());
  assert.deepEqual(Object.keys(selected).sort(),['agent_id','conversation_id','draft_id','edit_revision','owner_key']);
  assert.equal(selected.draft_id,organic.id);
  await editor().getByLabel('Message',{exact:true}).fill('My latest local facts must be saved before revision.');
  assert.equal((await instruction('Make this draft more concise.')).ok,true);
  await page.waitForFunction(()=>document.querySelector('.vp3-email-compose textarea[rows="8"]')?.value.startsWith('Revised for the owner:'));
  const generateIndex=requests.findIndex(input=>input.action==='compose_generate');
  assert.equal(requests[generateIndex-1].action,'compose_save');
  assert.equal(requests[generateIndex-1].body,'My latest local facts must be saved before revision.');
  assert.equal(requests[generateIndex].instruction,'Make this draft more concise.');
  const staleMatch=await page.evaluate(scope=>window.VP3EmailComposerV1.matchInstruction('Save this draft.',scope),context);
  await editor().getByLabel('Message',{exact:true}).fill('A new exact version.');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  const beforeStale=requests.length;
  const staleResult=await page.evaluate(({scope,match})=>window.VP3EmailComposerV1.handleChatInstruction('Save this draft.',scope,match),{scope:context,match:staleMatch});
  assert.deepEqual({handled:staleResult.handled,ok:staleResult.ok},{handled:true,ok:false});
  assert.equal(requests.length,beforeStale,'A stale conversational selection cannot issue a write.');
  assert.equal(await page.evaluate(scope=>window.VP3EmailComposerV1.matchInstruction('Send it.',scope),context),null);
  for (const text of ['Add a warm closing.','Add that Friday works.','Add the calendar details to this email.']) {
    assert.equal(await page.evaluate(({text,scope})=>window.VP3EmailComposerV1.matchInstruction(text,scope)?.kind,{text,scope:context}),'revise');
  }
  for (const text of ['Add a task to call Sam.','Add a reminder for Friday.','Add a calendar event at noon.','Add Jane to my contacts.','Add a note to my calendar.']) {
    assert.equal(await page.evaluate(({text,scope})=>window.VP3EmailComposerV1.matchInstruction(text,scope),{text,scope:context}),null,
      'A selected email must not capture an ordinary task, calendar or contact instruction.');
  }
  assert.deepEqual(await instruction('Add a task to call Sam.'),{handled:false,ok:false});
  const wrongScope=await page.evaluate(({scope,match})=>window.VP3EmailComposerV1.handleChatInstruction('Save this draft.',{...scope,conversation_id:42},match),{scope:context,match:staleMatch});
  assert.equal(wrongScope.ok,false);

  await editor().getByRole('button',{name:'Read original email',exact:true}).click();
  await editor().locator('iframe').waitFor();
  organic.connection_changed=true;organic.can_open_source=false;
  await editor().getByRole('button',{name:'Reload saved draft',exact:true}).click();
  await editor().getByText('Gmail access changed. Check Connections before editing or sending.',{exact:true}).waitFor();
  assert.equal(await editor().locator('iframe').count(),0,'Revoked source access clears already rendered HTML even when the draft hash is unchanged.');
  assert.equal(await editor().getByRole('button',{name:'Read original email',exact:true}).isDisabled(),true);
  organic.connection_changed=false;organic.can_open_source=true;
  await editor().getByRole('button',{name:'Reload saved draft',exact:true}).click();
  await editor().getByRole('button',{name:'Read original email',exact:true}).click();
  await editor().locator('iframe').waitFor();failReview=true;
  await editor().getByRole('button',{name:'Reload saved draft',exact:true}).click();
  await editor().getByText('Gmail source access changed.',{exact:true}).waitFor();
  assert.equal(await editor().locator('iframe').count(),0,'A rejected source refresh clears private HTML.');
  assert.equal(await editor().getByRole('button',{name:'Refresh original email',exact:true}).isDisabled(),true);
  await editor().getByRole('button',{name:'Reload saved draft',exact:true}).click();
  await editor().getByLabel('Message',{exact:true}).fill('Unsaved edits stay unless I explicitly discard them.');
  const discardsBefore=requests.filter(input=>input.action==='discard').length;
  const discardResult=await instruction('Discard this draft.');
  assert.equal(discardResult.ok,false);
  await editor().locator('.vp3-email-compose-leave').filter({visible:true}).getByRole('button',{name:'Keep editing',exact:true}).click();
  assert.equal(await editor().getByLabel('Message',{exact:true}).inputValue(),'Unsaved edits stay unless I explicitly discard them.');
  assert.equal(requests.filter(input=>input.action==='discard').length,discardsBefore);
  await instruction('Discard this draft.');
  const confirmation=editor().locator('.vp3-email-compose-leave').filter({visible:true});
  assert.equal(await confirmation.getByRole('button',{name:'Save draft and continue',exact:true}).count(),0);
  await confirmation.getByRole('button',{name:'Discard draft',exact:true}).click();
  await editor().getByLabel('Draft status filter').waitFor();
  assert.equal(organic.status,'discarded');
  assert.ok(requests.filter(input=>input.action==='discard').every(input=>/^[a-f0-9]{64}$/.test(input.edit_revision)));

  await card(local).getByRole('button',{name:'Open original Chat',exact:true}).click();
  await editor().getByLabel('Message',{exact:true}).waitFor();
  let releaseSource,startSource;const sourceStarted=new Promise(resolve=>{startSource=resolve;});
  delaySource={promise:new Promise(resolve=>{releaseSource=resolve;}),started:startSource};
  await editor().getByRole('button',{name:'Read original email',exact:true}).click();await sourceStarted;
  await editor().getByLabel('Message',{exact:true}).fill('A revision saved while its original-message read was pending.');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();releaseSource();await page.waitForTimeout(100);
  assert.equal(await editor().locator('iframe').count(),0,'A late source read cannot paint into a different reviewed revision.');
  await editor().getByRole('button',{name:'Needs review',exact:true}).click();
  await card(expired).getByRole('button',{name:'Open draft',exact:true}).click();
  await editor().getByText('This proposal expired. You can review its original email or discard it.',{exact:true}).waitFor();
  assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isDisabled(),true);
  await editor().getByRole('button',{name:'Read original email',exact:true}).click();
  await editor().locator('iframe').waitFor();
  await page.setViewportSize({width:390,height:844});await bounds();await screenshot('review-source-phone.png');
  await editor().getByRole('button',{name:'Discard draft',exact:true}).click();
  await editor().getByLabel('Draft status filter').waitFor();
  assert.equal(expired.status,'discarded');
  await bounds();await screenshot('review-queue-phone.png');
  malformedQueue=true;
  await editor().getByRole('button',{name:'Refresh',exact:true}).click();
  await editor().getByText('A draft’s original Chat could not be verified. Refresh the review queue.',{exact:true}).waitFor();
  assert.equal(await editor().getByText('Another agent’s private draft',{exact:true}).count(),0);
  assert.equal(requests.filter(input=>input.action==='confirm').length,0,'Queue, source reads and Chat instructions never send email.');
  assert.equal(await page.evaluate(id=>window.VP3EmailComposerV1.openDraft({draft_id:id,agent_id:7,conversation_id:42},{surface:'popup'}),local.id),true);
  await editor().getByRole('button',{name:'Read original email',exact:true}).click();await editor().locator('iframe').waitFor();
  const taskContext={owner_key:'1',agent_id:7,conversation_id:42};
  const taskMatch=await page.evaluate(scope=>window.VP3EmailComposerV1.matchInstruction('Save this draft.',scope),taskContext);
  let releaseSummary,startSummary;const summaryStarted=new Promise(resolve=>{startSummary=resolve;});
  delaySummary={promise:new Promise(resolve=>{releaseSummary=resolve;}),started:startSummary};
  await page.evaluate(()=>{
    window.dispatchEvent(new Event('vp3:chat-email-drafts-changed'));
    window.reviewSummaryReady=false;
    window.VP3EmailComposerV1.queueSummary().then(value=>{window.reviewSummaryResult=value;window.reviewSummaryReady=true;});
  });
  await summaryStarted;
  const requestsBeforeTaskContext=requests.length;
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  assert.equal(await page.evaluate(()=>window.VP3ParticipantContinuity.selectedContext()),null,'This transition is independent of participant selection.');
  assert.equal(await page.locator('.vp3-email-compose').count(),0,'A participant task context closes the private source/composer.');
  assert.equal(await page.locator('.vp3-email-compose iframe').count(),0);
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.selectedDraft()),null);
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.openDrafts()),false);
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.queueSummary()),null);
  const stoppedInstruction=await page.evaluate(({scope,match})=>window.VP3EmailComposerV1.handleChatInstruction('Save this draft.',scope,match),{scope:taskContext,match:taskMatch});
  assert.equal(stoppedInstruction.ok,false);
  assert.equal(requests.length,requestsBeforeTaskContext,'No private draft APIs run while the task participant is active.');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}})));
  releaseSummary();await page.waitForFunction(()=>window.reviewSummaryReady===true);
  assert.equal(await page.evaluate(()=>window.reviewSummaryResult),null,'An old pending count cannot return after task context changed and returned.');
  assert.equal(await page.locator('.vp3-email-compose').count(),0,'Returning to the owner does not reopen private content automatically.');
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.openDrafts({surface:'popup'})),true,'The owner can manually reopen the private queue.');
  await page.evaluate(()=>{window.participantSelected=true;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
  assert.equal(await page.locator('.vp3-email-compose').count(),0);
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.selectedDraft()),null);
  assert.equal(await page.evaluate(()=>window.VP3EmailComposerV1.queueSummary()),null);
  assert.deepEqual(errors,[]);
  console.log('Gmail review queue browser: authoritative counts, private Chat handoff, cursor pages, source styling/access revocation, date-only deadlines, exact conversational revision/discard, expiry and responsive single-scroll layout passed.');
} finally { await browser.close(); }
