import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

const {chromium}=await import('playwright').catch(error=>{
  if(!process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES)throw error;
  return import(pathToFileURL(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,'playwright/index.mjs')).href);
});
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:920},timezoneId:'America/Phoenix'});
page.setDefaultTimeout(12000);
const root=new URL('../',import.meta.url),requests=[],errors=[];
const originalId='d'.repeat(32),followupId='f'.repeat(32),reference='a'.repeat(32);
const future=new Date(Date.now()+7*86400000).toISOString().slice(0,10),later=new Date(Date.now()+8*86400000).toISOString().slice(0,10);
const deadline=future+'T23:00:00Z',past=new Date(Date.now()-86400000).toISOString().replace(/\.\d+Z$/,'Z');
let mailboxRevision='b'.repeat(64),followAvailable=true,tracked=false,version=1,hubReads=0,failUpdate=0,delayUpdate=null,delayList=null,delayGet=null;
let listItemsOverride=null,exactItem=null,failGet=0;
let expectWatch=true,denySavedSend=false;
let nextCheck='unavailable';
const disabled={enabled:false,deadline_at:null,time_zone:'UTC',prepare_if_overdue:false,priority:'normal'};
const drafts=new Map([
  [originalId,{id:originalId,edit_revision:'1'.repeat(64),review_token:'review-1',watch_review_token:'watch-1',status:'prepared',from:'owner@example.test',
    to:'client@example.test',subject:'Delivery confirmation',body:'Please confirm the delivery date.',is_reply:false,attachments:[],can_edit:true,can_send:true,followthrough:{...disabled},source:{kind:'new_email'}}],
  [followupId,{id:followupId,edit_revision:'f'.repeat(64),review_token:'followup-review',watch_review_token:'followup-watch',status:'prepared',from:'owner@example.test',
    to:'client@example.test',subject:'Re: Delivery confirmation',body:'Following up on the delivery date. Could you confirm?',is_reply:true,is_followup:true,
    attachments:[],can_edit:true,can_send:false,followthrough_unavailable:true,followthrough:{...disabled},source:{kind:'outbound_followup',reason:'A verified response deadline passed.'}}]
]);
const ordinaryDraft=structuredClone(drafts.get(originalId));
let item={followthrough_ref:reference,revision:1,state_version:1,mailbox_revision:mailboxRevision,subject:'Delivery confirmation',recipient:'client@example.test',agent_id:7,conversation_id:42,
  outcome:'waiting',paused:false,deadline_at:deadline,time_zone:'America/Phoenix',prepare_if_overdue:true,priority:'high',
  check:{state:'never_checked',result:null,checked_at:null},draft_target:null,reply_target:null,
  capabilities:{change_deadline:true,pause:true,resume:false,resolve:true,check:true}};
function capabilities(){const active=item.outcome!=='resolved';item.capabilities={change_deadline:active,pause:active&&!item.paused,resume:active&&item.paused,resolve:active,check:active&&!item.paused};}
const snapshot=()=>structuredClone({...item,mailbox_revision:mailboxRevision});
const context={owner_key:'1',agent_id:7,conversation_id:42};
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://follow.test/**',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;height:100%;font:14px Arial,sans-serif;background:#f3f5f8}main{height:100vh;display:flex;flex-direction:column}
#chatThread{flex:1;min-height:0;overflow:auto;padding:20px;box-sizing:border-box}#chatComposerShell{padding:12px;background:white}#chatInput{box-sizing:border-box;width:100%;height:40px}
#gmailLauncher{position:fixed;right:12px;top:10px;z-index:1}</style>
<main><section id="chatThread" data-view="chat"><article>Private Chat</article></section><div id="chatComposerShell"><textarea id="chatInput"></textarea></div></main>
<button id="gmailLauncher">Gmail</button><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://follow.test/api/hub**',route=>{
  hubReads++;
  return route.fulfill({headers:{'x-fixture-read':String(hubReads)},json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:42,
    working_attention_binding:'fixture-binding',agent_name:'Agent',observed_at:new Date().toISOString(),coverage:{},sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],
    specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked #'+hubReads}],
    gmail_workspace:{contract:'vp3.gmail-workspace-context.v1',owner_user_id:1,agent_id:7,conversation_id:42,read_available:true,mailbox_revision:mailboxRevision,
      capabilities:{followups:{available:followAvailable}}}}}}).catch(()=>{});
});
await page.route('https://follow.test/api/chat-email-v1.php',async route=>{
  const input=route.request().postDataJSON();requests.push(structuredClone(input));
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.conversation_id,42);assert.equal(input.csrf_token,'fixture');
  const ok=value=>route.fulfill({json:{ok:true,...value}}).catch(()=>{});
  if(input.action==='specialist_status')return ok({specialist:{state:'idle',origin_message_id:null}});
  if(input.action==='compose_list')return ok({scope:'agent',filter:input.filter,counts:{pending:1,total:1,sent:0,discarded:0,answered:0},drafts:[],next_cursor:null});
  if(input.action==='compose_create')return ok({draft:structuredClone(drafts.get(originalId))});
  if(input.action==='review')return ok({draft:structuredClone(drafts.get(input.draft_id))});
  if(input.action==='compose_save'){
    assert.equal(typeof input.followthrough.enabled,'boolean');
    if(!input.followthrough.enabled)assert.equal(input.followthrough.prepare_if_overdue,false,'Disabled follow-through never requests preparation.');
    const draft=drafts.get(input.draft_id);assert.ok(draft);version++;
    Object.assign(draft,{to:input.to,subject:input.subject,body:input.body,followthrough:structuredClone(input.followthrough),edit_revision:version.toString(16).padStart(64,'0'),
      review_token:'review-'+version,watch_review_token:'watch-'+version,
      can_send:!denySavedSend&&!draft.followthrough_unavailable&&Boolean(input.to&&input.subject&&input.body)});return ok({draft:structuredClone(draft)});
  }
  if(input.action==='confirm'){
    assert.equal(input.draft_id,originalId);assert.equal(input.watch_replies,expectWatch,'A chosen deadline also registers the canonical reply watch.');
    assert.equal(input.review_token,input.watch_replies?drafts.get(originalId).watch_review_token:drafts.get(originalId).review_token);tracked=input.watch_replies;
    const draft=drafts.get(originalId);Object.assign(item,draft.followthrough);delete item.enabled;
    Object.assign(draft,{status:'sent',can_send:false,can_edit:false,watch_requested:input.watch_replies,watch_registered:input.watch_replies,
      followthrough_record:input.watch_replies?snapshot():null});
    delete draft.review_token;delete draft.watch_review_token;return ok({draft:structuredClone(draft)});
  }
  if(input.action==='followthrough_list'){
    assert.equal(input.limit,20);assert.ok(['open','all'].includes(input.filter));
    const all=(listItemsOverride|| (tracked?[snapshot()]:[])).map(value=>value.followthrough_ref===reference?snapshot():structuredClone(value));
    const filtered=all.filter(value=>input.filter==='all'||value.outcome!=='resolved');
    assert.ok(input.cursor===undefined||/^page-[0-9]+$/.test(input.cursor));
    const offset=input.cursor?Number(input.cursor.slice(5)):0,items=filtered.slice(offset,offset+20),hasMore=offset+20<filtered.length;
    const result={state:followAvailable?'ready':'unavailable',items,counts:{waiting:all.filter(value=>value.outcome==='waiting').length,overdue:all.filter(value=>value.outcome==='overdue').length,
      replied:all.filter(value=>value.outcome==='replied').length,resolved:all.filter(value=>value.outcome==='resolved').length,paused:all.filter(value=>value.paused).length},
      has_more:hasMore,next_cursor:hasMore?'page-'+(offset+20):null,mailbox_revision:mailboxRevision,coverage:'tracked_sends_in_this_private_chat'};
    const held=delayList;delayList=null;if(held){held.started();await held.promise;}return ok(result);
  }
  if(input.action==='followthrough_get'){
    const target=exactItem?.followthrough_ref===input.followthrough_ref?structuredClone(exactItem):input.followthrough_ref===reference?snapshot():null;
    const held=delayGet;delayGet=null;if(held){held.started();await held.promise;}
    if(failGet||!target)return route.fulfill({status:failGet||404,json:{ok:false,error:'This follow-up is unavailable.'}}).catch(()=>{});
    return ok({item:target});
  }
  if(['followthrough_update','followthrough_check'].includes(input.action)){
    assert.equal(input.followthrough_ref,reference);
    const held=delayUpdate;delayUpdate=null;if(held){held.started();await held.promise;}
    if(failUpdate||input.expected_revision!==item.revision)return route.fulfill({status:failUpdate||409,json:{ok:false,error:'The follow-up changed. Refresh it before continuing.'}}).catch(()=>{});
    if(input.action==='followthrough_update'){
      assert.ok(['deadline','pause','resume','resolve'].includes(input.operation));
      if(input.operation==='deadline'){
        assert.ok(!Object.hasOwn(input,'settings'),'Deadline settings use the approved flat action contract.');
        Object.assign(item,{deadline_at:input.deadline_at,time_zone:input.time_zone,prepare_if_overdue:input.prepare_if_overdue,priority:input.priority,outcome:'waiting',awaiting_new_deadline:false,
          check:{state:'never_checked',result:null,checked_at:null}});
      }else if(input.operation==='pause')item.paused=true;
      else if(input.operation==='resume')item.paused=false;
      else item.outcome='resolved';
    }else{
      item.deadline_at=past;
      if(nextCheck==='unavailable')Object.assign(item,{outcome:'waiting',check:{state:'unavailable',result:null,checked_at:null}});
      if(nextCheck==='overdue')Object.assign(item,{outcome:'overdue',check:{state:'verified',result:'no_reply',checked_at:new Date().toISOString()},draft_target:{agent_id:7,conversation_id:42,draft_id:followupId}});
      if(nextCheck==='replied')Object.assign(item,{outcome:'replied',check:{state:'verified',result:'reply',checked_at:new Date().toISOString()},reply_target:{agent_id:7,conversation_id:42,message_id:800},draft_target:null});
    }
    if(input.action==='followthrough_update')item.revision++;
    item.state_version++;capabilities();return ok({item:snapshot()});
  }
  if(input.action==='sent_list')return ok({sent:{scope:'gmail_sent',account_email:'owner@example.test',checked_at:new Date().toISOString(),mailbox_revision:mailboxRevision,
    items:[{item_ref:'exact-sent-reference',subject:'Delivery confirmation',from:'owner@example.test',to:'client@example.test',sent_at:new Date().toISOString(),snippet:'Please confirm the delivery date.'}],next_cursor:null,has_more:false}});
  if(input.action==='sent_open')return ok({message:{subject:'Delivery confirmation',from:'owner@example.test',to:'client@example.test',sent_at:new Date().toISOString(),account_email:'owner@example.test',
    mailbox_revision:mailboxRevision,can_reply:false,body:'Please confirm the delivery date.',followthrough_record:snapshot()}});
  if(input.action==='open_followup'){assert.equal(input.message_id,800);return ok({message:{subject:'Re: Delivery confirmation',from:'client@example.test',body:'The delivery is confirmed.',can_reply:false}});}
  throw new Error('Unexpected email action: '+input.action);
});
async function installUI(query='',ready=true){
  await page.goto('https://follow.test/'+query);
  await page.evaluate(ready=>{
    window.currentChat=42;window.participantSelected=false;window.chatReady=ready;
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.currentChat,agentId:()=>7,isReady:()=>window.chatReady,isRestoring:()=>false,isBusy:()=>false,
      showEmailCanvasView:()=>{document.getElementById('chatThread').dataset.view='email';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
      showChatView:()=>{document.getElementById('chatThread').dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));}};
    window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
    window.VP3ParticipantContinuity={selectedContext:()=>window.participantSelected?{participant_id:9}:null};
    window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',emailReport:()=>'<p>Inbox is up to date.</p>'};
  },ready);
  for(const file of ['agent-activity-hub-v1.css','chat-email-v1.css','chat-email-composer-v1.css','gmail-email-display-v1.css','chat-email-followthrough-v1.css'])
    await page.addStyleTag({content:await fs.readFile(new URL(file,root),'utf8')});
  for(const file of ['gmail-email-display-v1.js','chat-email-followthrough-v1.js','chat-email-composer-v1.js','chat-email-v1.js','agent-activity-hub-v1.js'])
    await page.addScriptTag({content:await fs.readFile(new URL(file,root),'utf8')});
  await page.evaluate(()=>document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'})));
}
await installUI();
const editor=()=>page.locator('.vp3-email-compose').filter({visible:true}),popup=()=>page.locator('#vp3ChatSpecialistActivity');
const followCard=()=>popup().locator('.vp3-email-followthrough-card').filter({visible:true});
const followRequests=()=>requests.filter(input=>input.action.startsWith('followthrough_'));
async function openSent(){assert.equal(await page.evaluate(context=>window.VP3AgentActivityHub.openGmailSent(context),context),true);await followCard().getByRole('heading',{name:'Delivery confirmation',exact:true}).waitFor();}
async function manage(){await followCard().getByText('Manage follow-up',{exact:true}).click();}
async function screenshot(name){const directory=process.env.VP3_GMAIL_FOLLOWTHROUGH_SCREENSHOT_DIR;if(!directory)return;await fs.mkdir(directory,{recursive:true});await page.screenshot({path:path.join(directory,name),fullPage:true});}
async function bounds(locator){
  const result=await locator.evaluate(el=>({left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right,width:el.clientWidth,scroll:el.scrollWidth,
    nested:[...el.querySelectorAll('*')].filter(child=>['auto','scroll'].includes(getComputedStyle(child).overflowY)&&child.scrollHeight>child.clientHeight+1).map(child=>child.className)}));
  assert.ok(result.left>=-1&&result.right<=page.viewportSize().width+1,'Follow-through fits the viewport.');assert.ok(result.scroll<=result.width+1,'No horizontal overflow.');
  assert.deepEqual(result.nested,[],'The existing outer surface remains the only scrolling area.');
}
async function refreshHub(){const response=page.waitForResponse(value=>new URL(value.url()).pathname==='/api/hub');await page.evaluate(()=>window.VP3AgentActivityHub.refresh());
  const minimum=Number((await response).headers()['x-fixture-read']);await page.waitForFunction(minimum=>[...document.querySelectorAll('#vp3GmailInboxPanel .vp3-agent-activity-detail')]
    .some(el=>Number(/^Inbox checked #(\d+)$/.exec(el.textContent)?.[1])>=minimum),minimum);}
function hold(){let release,started;const promise=new Promise(resolve=>{release=resolve;}),start=new Promise(resolve=>{started=resolve;});return{promise,start,release,started};}
try{
  await page.getByRole('button',{name:'Gmail',exact:true}).click();await popup().getByRole('button',{name:'Compose new email',exact:true}).click();
  await editor().getByLabel('Watch for the first reply for 30 days',{exact:true}).waitFor();
  await editor().getByLabel('Set response deadline',{exact:true}).check();
  assert.equal(await editor().getByLabel('Time zone',{exact:true}).inputValue(),'America/Phoenix','New deadlines visibly use the browser’s local time zone.');
  await editor().getByLabel('Reply expected by',{exact:true}).fill('2000-01-01T12:00');
  const saves=()=>requests.filter(input=>input.action==='compose_save').length;
  const beforeInvalid=saves();await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await editor().getByText('Choose a future response deadline within 30 days.',{exact:true}).waitFor();assert.equal(saves(),beforeInvalid);
  await editor().getByLabel('Reply expected by',{exact:true}).fill(new Date(Date.now()+40*86400000).toISOString().slice(0,16));
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await editor().getByText('Choose a future response deadline within 30 days.',{exact:true}).waitFor();assert.equal(saves(),beforeInvalid);
  await editor().getByLabel('Time zone',{exact:true}).selectOption('America/New_York');
  await editor().getByLabel('Reply expected by',{exact:true}).fill('2027-03-14T02:30');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await editor().getByText('Choose a valid response date and time in the selected time zone.',{exact:true}).waitFor();
  await editor().getByLabel('Reply expected by',{exact:true}).fill('2027-11-07T01:30');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await editor().getByText('This local time occurs twice. Choose another time or use UTC.',{exact:true}).waitFor();assert.equal(saves(),beforeInvalid);
  await editor().getByLabel('Time zone',{exact:true}).selectOption('America/Phoenix');
  await editor().getByLabel('Reply expected by',{exact:true}).fill(future+'T16:00');
  await editor().getByLabel('Prepare a follow-up draft if overdue',{exact:true}).check();await editor().getByLabel('Follow-up priority',{exact:true}).selectOption('high');
  await editor().getByLabel('Reply expected by',{exact:true}).scrollIntoViewIfNeeded();await bounds(editor());await screenshot('composer-deadline-desktop.png');
  await page.setViewportSize({width:390,height:844});await bounds(editor());await screenshot('composer-deadline-phone.png');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await editor().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.deepEqual(drafts.get(originalId).followthrough,{enabled:true,deadline_at:deadline,time_zone:'America/Phoenix',prepare_if_overdue:true,priority:'high'});
  await editor().getByRole('button',{name:'Close',exact:true}).click();
  await page.evaluate(({id})=>window.VP3EmailComposerV1.openDraft({agent_id:7,conversation_id:42,draft_id:id},{surface:'popup'}),{id:originalId});
  assert.equal(await editor().getByLabel('Set response deadline',{exact:true}).isChecked(),true);assert.match(await editor().getByLabel('Reply expected by',{exact:true}).inputValue(),new RegExp('^'+future+'T16:00'));
  await editor().getByLabel('Follow-up priority',{exact:true}).selectOption('low');await editor().getByRole('button',{name:'Close',exact:true}).click();
  await editor().getByRole('button',{name:'Keep editing',exact:true}).click();assert.equal(await editor().getByLabel('Follow-up priority',{exact:true}).inputValue(),'low');
  await editor().getByLabel('Follow-up priority',{exact:true}).selectOption('high');await editor().getByRole('button',{name:'Send',exact:true}).click();
  await editor().getByText('Gmail confirmed your email was sent.',{exact:true}).waitFor();await editor().getByText('Waiting for reply',{exact:true}).waitFor();
  assert.equal(requests.filter(input=>input.action==='confirm').length,1);
  await editor().getByRole('button',{name:'Close',exact:true}).click();
  listItemsOverride=[snapshot(),...Array.from({length:20},(_,index)=>({...snapshot(),followthrough_ref:(index+1).toString(16).padStart(32,'0'),subject:'Earlier tracked email '+(index+1)}))];
  await page.setViewportSize({width:1280,height:920});await openSent();
  await popup().getByText('Showing 20 saved follow-ups. Load more to see older follow-ups.',{exact:true}).waitFor();
  await bounds(popup());await screenshot('sent-followups-desktop.png');
  await popup().getByRole('button',{name:'Load more follow-ups',exact:true}).click();
  await popup().getByRole('heading',{name:'Earlier tracked email 20',exact:true}).waitFor();
  assert.equal(await followCard().count(),21,'Load more reaches and retains the twenty-first tracked email.');
  assert.equal(await popup().getByRole('button',{name:'Load more follow-ups',exact:true}).isVisible(),false);
  listItemsOverride=null;await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('#vp3ChatSpecialistActivity .vp3-email-followthrough-card').length===1);
  const beforeInbox=followRequests().length;await refreshHub();assert.equal(followRequests().length,beforeInbox,'Inbox refresh does not recheck Gmail or start follow-up work.');
  await manage();await followCard().getByRole('button',{name:'Pause follow-up',exact:true}).click();await followCard().getByText('Paused · Waiting for reply',{exact:true}).waitFor();
  await manage();await followCard().getByRole('button',{name:'Resume follow-up',exact:true}).click();await followCard().getByText('Waiting for reply',{exact:true}).waitFor();
  await manage();await followCard().getByRole('button',{name:'Change deadline',exact:true}).click();await followCard().getByLabel('Reply expected by',{exact:true}).fill(later+'T11:30');
  const beforeEditRefresh=followRequests().length;await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).click();
  assert.equal(followRequests().length,beforeEditRefresh);assert.match(await followCard().getByLabel('Reply expected by',{exact:true}).inputValue(),new RegExp('^'+later+'T11:30'));
  await followCard().getByRole('button',{name:'Save deadline',exact:true}).click();await followCard().getByText('Waiting for reply',{exact:true}).waitFor();
  assert.equal(item.deadline_at,later+'T18:30:00Z');
  await manage();await followCard().getByRole('button',{name:'Check Gmail now',exact:true}).click();
  await followCard().getByText('The current reply outcome could not be verified. Check Gmail again before following up.',{exact:true}).waitFor();
  assert.equal(await popup().locator('[data-outcome="overdue"]').count(),0,'A past deadline and unavailable Gmail check never imply overdue.');
  nextCheck='overdue';await manage();await followCard().getByRole('button',{name:'Check Gmail now',exact:true}).click();await followCard().getByText('Overdue',{exact:true}).waitFor();
  await popup().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();await popup().getByRole('button',{name:'Back to Sent',exact:true}).waitFor();
  assert.equal(await followCard().count(),1,'Exact Sent detail shows one verified follow-up control block.');
  await page.setViewportSize({width:390,height:844});await bounds(popup());await screenshot('sent-followup-detail-phone.png');
  await followCard().getByRole('button',{name:'Review follow-up draft',exact:true}).click();await editor().getByRole('heading',{name:'Follow-up email',exact:true}).waitFor();
  assert.equal(await editor().getByLabel('To',{exact:true}).inputValue(),'client@example.test');assert.equal(await editor().getByLabel('To',{exact:true}).getAttribute('readonly'),'');
  assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isDisabled(),true);assert.equal(await editor().getByLabel('Set response deadline',{exact:true}).isVisible(),false);
  await bounds(editor());await screenshot('followup-draft-phone.png');
  for(const text of ['Show my Gmail follow-ups.','Open my email followups.','Show overdue emails.']){
    const matched=await page.evaluate(({text,context})=>window.VP3EmailComposerV1.matchInstruction(text,context),{text,context});assert.equal(matched.kind,'sent');assert.equal(matched.section,'followups');
  }
  assert.equal(await page.evaluate(context=>window.VP3EmailComposerV1.matchInstruction('Change the deadline to Friday.',context),context),null);
  const command=await page.evaluate(context=>{const text='Show my Gmail follow-ups.';return window.VP3EmailComposerV1.handleChatInstruction(text,context,window.VP3EmailComposerV1.matchInstruction(text,context));},context);
  assert.equal(command.ok,true);assert.equal(command.message,'Opened follow-ups in Gmail Sent for this Chat.');await followCard().getByText('Overdue',{exact:true}).waitFor();
  const oldList=hold();delayList=oldList;await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).click();await oldList.start;
  const priorControlRevision=item.revision,priorStateVersion=item.state_version;
  nextCheck='replied';await manage();await followCard().getByRole('button',{name:'Check Gmail now',exact:true}).click();await followCard().getByText('Reply received',{exact:true}).waitFor();
  assert.equal(item.revision,priorControlRevision,'A background reply check preserves the owner-control revision.');
  assert.equal(item.state_version,priorStateVersion+1,'A verified reply advances the public state version.');
  await page.evaluate(()=>{
    window.replyRegressions=[];const host=document.querySelector('#vp3ChatSpecialistActivity');
    window.followObserver=new MutationObserver(()=>{
      for(const card of host.querySelectorAll('.vp3-email-followthrough-card'))if(card.dataset.outcome==='overdue')window.replyRegressions.push(card.textContent);
    });window.followObserver.observe(host,{subtree:true,childList:true,attributes:true,attributeFilter:['data-outcome']});
  });
  oldList.release();await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.querySelector('.vp3-email-followthrough-top button')?.disabled);
  assert.equal(await followCard().getByText('Reply received',{exact:true}).count(),1,'An earlier list cannot replace a just-checked reply at the same control revision.');
  assert.deepEqual(await page.evaluate(()=>{window.followObserver.disconnect();return window.replyRegressions;}),[],'The old list never briefly repaints the obsolete overdue outcome.');
  await followCard().getByRole('button',{name:'Read reply',exact:true}).click();await page.locator('#vp3ChatEmailReview').getByText('The delivery is confirmed.',{exact:true}).waitFor();
  assert.equal(requests.filter(input=>input.action==='open_followup').length,1);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  assert.equal(await page.locator('#vp3ChatEmailReview').evaluate(el=>el.open),false,'The exact reply reader closes on participant task context.');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}})));await openSent();
  await manage();await followCard().getByRole('button',{name:'Mark resolved',exact:true}).click();
  await popup().getByLabel('Follow-up filter',{exact:true}).selectOption('all');await followCard().getByText('Marked resolved by you.',{exact:true}).waitFor();
  item={...item,outcome:'waiting',awaiting_new_deadline:true,paused:false,revision:item.revision+1,state_version:item.state_version+1,draft_target:null,reply_target:null,check:{state:'never_checked',result:null,checked_at:null}};capabilities();
  await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).click();await followCard().getByText('Follow-up sent · waiting for reply',{exact:true}).waitFor();
  await followCard().getByText('Choose a new response deadline to schedule another follow-up.',{exact:true}).waitFor();
  await manage();await followCard().getByRole('button',{name:'Change deadline',exact:true}).click();await followCard().getByLabel('Reply expected by',{exact:true}).fill(later+'T12:15');
  failUpdate=409;await followCard().getByRole('button',{name:'Save deadline',exact:true}).click();await followCard().getByText('The follow-up changed. Refresh it before continuing.',{exact:true}).waitFor();
  assert.equal(await followCard().getByRole('heading').count(),0,'A stale mutation clears the previous private record before retry.');failUpdate=0;
  await popup().getByRole('button',{name:'Refresh follow-ups',exact:true}).click();await followCard().getByRole('heading',{name:'Delivery confirmation',exact:true}).waitFor();
  followAvailable=false;await refreshHub();assert.equal(await popup().locator('.vp3-email-followthrough-card').count(),0,'Revoked follow-up permission clears controls while read-only Sent remains available.');
  followAvailable=true;await refreshHub();await followCard().getByRole('heading',{name:'Delivery confirmation',exact:true}).waitFor();
  const versionChecks=await page.evaluate(({record,context})=>{
    const host=document.createElement('div');document.body.append(host);
    const view=window.VP3EmailFollowthroughV1.mount(host,record,{context,isCurrent:()=>true,mailboxRevision:record.mailbox_revision,openDraft:()=>{}});
    const sameRevision=view.update({...record,state_version:record.state_version-1});
    const higherRevision=view.update({...record,revision:record.revision+1,state_version:record.state_version-1});
    const missingVersion={...record};delete missingVersion.state_version;const legacyDowngrade=view.update(missingVersion);
    let invalidRejected=false;try{view.update({...record,state_version:0});}catch{invalidRejected=true;}
    const explanations=[];
    for(const [index,reason]of['initiative_paused','dispatch_uncertain','draft_discarded'].entries()){
      view.update({...record,state_version:record.state_version+index+1,awaiting_new_deadline:false,
        draft_target:{agent_id:7,conversation_id:42,draft_id:'f'.repeat(32)},preparation:{state:'unavailable',reason}});
      explanations.push({text:host.querySelector('.vp3-email-followthrough-preparation')?.textContent,buttons:[...host.querySelectorAll('button')].map(button=>button.textContent)});
    }
    view.dispose();host.remove();return{sameRevision,higherRevision,legacyDowngrade,invalidRejected,explanations};
  },{record:snapshot(),context});
  assert.deepEqual([versionChecks.sameRevision,versionChecks.higherRevision,versionChecks.legacyDowngrade],[false,false,false],'Older state is rejected even at a higher control revision or when a known state version is omitted.');
  assert.equal(versionChecks.invalidRejected,true);
  assert.match(versionChecks.explanations[0].text,/Initiative is paused/);assert.match(versionChecks.explanations[1].text,/not be retried automatically/);
  assert.match(versionChecks.explanations[2].text,/draft was discarded/);assert.ok(versionChecks.explanations.every(value=>!value.buttons.includes('Review follow-up draft')));
  const held=hold();delayUpdate=held;await manage();await followCard().getByRole('button',{name:'Pause follow-up',exact:true}).click();await held.start;
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}})));held.release();
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.equal(await popup().count(),0);assert.equal(await page.locator('.vp3-email-followthrough-card').count(),0,'A late mutation cannot repaint after leaving and returning to private scope.');
  const noticeQuery=ref=>'?agent=7&conversation_id=42&gmail_followthrough='+ref;
  const navigationReads=()=>requests.filter(input=>['followthrough_list','followthrough_get','sent_list'].includes(input.action)).length;
  const ready=()=>page.evaluate(()=>{window.chatReady=true;window.dispatchEvent(new Event('vp3:chat-context-ready'));
    return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
  const beforeReady=navigationReads();await installUI(noticeQuery(reference),false);
  assert.equal(navigationReads(),beforeReady,'A notification waits for the private Chat to finish restoring before reading Gmail.');
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:chat-context-changing',{detail:{initialRestore:true}})));await ready();
  await page.waitForFunction(ref=>document.activeElement?.closest('.vp3-email-followthrough-card')?.dataset.followthrough===ref,reference);
  assert.equal(new URL(page.url()).searchParams.has('gmail_followthrough'),false,'The exact notice target is consumed only after scoped focus.');
  assert.equal(await popup().getByLabel('Follow-up filter',{exact:true}).inputValue(),'all','A resolved notification target can still be opened.');
  exactItem={...snapshot(),followthrough_ref:'e'.repeat(32),subject:'Notification target beyond page one',paused:false};
  const beforeExact=requests.length;await installUI(noticeQuery(exactItem.followthrough_ref));
  await page.waitForFunction(ref=>document.activeElement?.closest('.vp3-email-followthrough-card')?.dataset.followthrough===ref,exactItem.followthrough_ref);
  await popup().getByText('Opened from this notification',{exact:true}).waitFor();
  assert.equal(await popup().getByRole('heading',{name:'Delivery confirmation',exact:true}).count(),1,'An exact notice target is pinned without replacing the saved list.');
  assert.equal(requests.slice(beforeExact).filter(input=>input.action==='followthrough_get').length,1);
  assert.ok(requests.slice(beforeExact).every(input=>!['followthrough_update','followthrough_check','confirm','compose_save','compose_generate'].includes(input.action)),'Opening the exact notification only reads saved state.');
  await installUI(noticeQuery('9'.repeat(32)));
  await popup().getByText('This follow-up is unavailable in the current view. Showing saved follow-ups for this Chat.',{exact:true}).waitFor();
  assert.equal(await popup().getByRole('heading',{name:'Delivery confirmation',exact:true}).count(),1,'An unavailable exact target leaves the current scoped list usable.');
  const beforeWrongChat=navigationReads();await installUI('?agent=7&conversation_id=43&gmail_followthrough='+reference);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.equal(navigationReads(),beforeWrongChat,'A notice for a different private Chat cannot load this Chat’s Gmail.');
  const beforeActor=navigationReads();await installUI(noticeQuery(reference),false);
  await page.evaluate(()=>{
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}}));
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}}));
  });await ready();
  assert.equal(navigationReads(),beforeActor,'Entering and leaving participant context cancels a pending private notice.');
  const beforeChangedChat=navigationReads();await installUI(noticeQuery(reference),false);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:chat-context-changing',{detail:{initialRestore:false}})));await ready();
  assert.equal(navigationReads(),beforeChangedChat,'A later Chat transition cannot revive an old notice.');
  const lateExact=hold();delayGet=lateExact;await installUI(noticeQuery(exactItem.followthrough_ref));await lateExact.start;
  await page.evaluate(()=>{
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}}));
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}}));
  });lateExact.release();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.equal(await page.locator('.vp3-email-followthrough-card').count(),0,'A late exact-target lookup cannot repaint or focus after participant scope changed.');
  expectWatch=false;
  for(const denied of [false,true]){
    denySavedSend=denied;drafts.set(originalId,{...structuredClone(ordinaryDraft),to:'',subject:'',body:'',can_send:false});
    await installUI();await page.evaluate(()=>window.VP3EmailComposerV1.openCompose({surface:'popup'}));
    assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isDisabled(),true);
    await editor().getByLabel('To',{exact:true}).fill('client@example.test');await editor().getByLabel('Subject',{exact:true}).fill('A newly composed email');
    await editor().getByLabel('Message',{exact:true}).fill('This exact message is saved and verified before sending.');
    assert.equal(await editor().getByRole('button',{name:'Send',exact:true}).isEnabled(),true,'A completed ordinary draft can save and verify directly from Send.');
    const beforeSend=requests.length;await editor().getByRole('button',{name:'Send',exact:true}).click();
    await editor().getByText(denied?'This saved email cannot be sent yet. Review its current status before sending.':'Gmail confirmed your email was sent.',{exact:true}).waitFor();
    const changes=requests.slice(beforeSend).filter(input=>['compose_save','confirm'].includes(input.action));
    assert.deepEqual(changes.map(input=>input.action),denied?['compose_save']:['compose_save','confirm'],'Only a saved snapshot authorized by the server can reach confirm.');
    assert.equal(changes[0].body,'This exact message is saved and verified before sending.');
  }
  assert.equal(requests.filter(input=>['compose_generate','advance_write'].includes(input.action)).length,0,'Follow-through navigation never starts model work.');
  assert.deepEqual(errors,[]);
  console.log('Gmail follow-through browser: saved deadline/timezone/priority, dirty edits, exact reviewed send, scoped Sent controls, verified outcomes, monotonic late-response protection, paginated records, exact private notification navigation, preparation explanations, reply reader, privacy and mobile single-scroll layout passed.');
}finally{await browser.close();}
