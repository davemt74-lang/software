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
page.setDefaultTimeout(10000);
const root=new URL('../',import.meta.url),requests=[],errors=[];
let hubReads=0,remoteImages=0,readAvailable=true,mailboxRevision='a'.repeat(64),accountEmail='owner@example.test';
let failSent=0,malformedSent=false,delayList=null,delayOpen=null,workspaceAvailable=true,workspaceUnavailable=false;
const rows=Array.from({length:12},(_,i)=>({item_ref:'sent-reference-'+(i+1),subject:i===0?'Delivery confirmation':'Sent project update '+(i+1),
  from:'Owner <owner@example.test>',to:'Client '+(i+1)+' <client'+(i+1)+'@example.test>',sent_at:'2026-11-25T15:00:00Z',
  snippet:i===0?'Confirming Friday’s delivery, with the details you requested.':'An update sent from the connected Gmail account.'}));
const draft={id:'d'.repeat(32),edit_revision:'b'.repeat(64),review_token:'draft-review-1',status:'prepared',from:accountEmail,to:'client@example.test',
  subject:'New message',body:'Initial draft text.',is_reply:false,attachments:[],can_edit:true,can_send:true,source:{kind:'new_email'}};
const counts={pending:1,total:1,sent:0,discarded:0,answered:0};
const scope=()=>page.evaluate(()=>({owner_key:'1',agent_id:7,conversation_id:window.currentChat}));
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://remote.test/**',route=>{remoteImages++;return route.fulfill({status:404,body:''});});
await page.route('https://sent.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>
html,body{margin:0;height:100%;font:14px Arial,sans-serif;background:#f3f5f8}main{height:100vh;display:flex;flex-direction:column}
#chatThread{flex:1;min-height:0;overflow:auto;padding:20px;box-sizing:border-box}#chatComposerShell{padding:12px;background:white}
#chatInput{box-sizing:border-box;width:100%;height:40px}#gmailLauncher{position:fixed;right:12px;top:10px;z-index:1}
</style><main><section id="chatThread" data-view="chat"><article>Private Chat</article></section>
<div id="chatComposerShell"><textarea id="chatInput" aria-label="Agent Chat message"></textarea></div></main>
<button id="gmailLauncher">Gmail</button><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://sent.test/api/hub**',async route=>{
  hubReads++;const cid=Number(new URL(route.request().url()).searchParams.get('conversation_id'));
  return route.fulfill({headers:{'x-fixture-read':String(hubReads)},json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:cid,
    working_attention_binding:'fixture-binding',agent_name:'Agent',current_chat:{title:'Project Chat'},observed_at:new Date().toISOString(),
    coverage:workspaceUnavailable?{gmail_workspace:'unavailable'}:{},
    sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked #'+hubReads}],
    gmail_workspace:workspaceAvailable?{contract:'vp3.gmail-workspace-context.v1',owner_user_id:1,agent_id:7,conversation_id:cid,
      read_available:readAvailable,mailbox_revision:readAvailable?mailboxRevision:null}:undefined}}}).catch(()=>{});
});
await page.route('https://sent.test/api/chat-email-v1.php',async route=>{
  const input=route.request().postDataJSON();requests.push({...input});
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.csrf_token,'fixture');
  const ok=value=>route.fulfill({json:{ok:true,...value}}).catch(()=>{});
  if(input.action==='compose_list')return ok({scope:'agent',filter:input.filter,counts,drafts:[],next_cursor:null});
  if(input.action==='compose_create')return ok({draft:{...draft}});
  if(input.action==='compose_save'){Object.assign(draft,{to:input.to,subject:input.subject,body:input.body});return ok({draft:{...draft}});}
  if(input.action==='sent_list'){
    assert.equal(input.limit,10);assert.ok(input.cursor===null||input.cursor==='second-page');
    const selected=input.cursor==='second-page'?rows.slice(10):rows.slice(0,10),next=input.cursor?null:'second-page';
    const result={scope:'gmail_sent',account_email:accountEmail,checked_at:'2026-11-26T00:00:00Z',mailbox_revision:mailboxRevision,
      items:malformedSent?[{...selected[0],item_ref:''}]:selected,next_cursor:next,has_more:Boolean(next)};
    const hold=delayList;delayList=null;if(hold){hold.started();await hold.promise;}
    if(failSent)return route.fulfill({status:failSent,json:{ok:false,error:'Gmail access changed.'}}).catch(()=>{});
    return ok({sent:result});
  }
  if(input.action==='sent_open'){
    const item=rows.find(row=>row.item_ref===input.item_ref);assert.ok(item);
    const message={...item,account_email:accountEmail,mailbox_revision:mailboxRevision,can_reply:false,
      body:'Confirming Friday’s delivery. The source is the exact sent Gmail message.',
      display:{version:1,remote_images:1,truncated:false,html:'<!doctype html><html><head><style>.sent-note{padding:18px;background:#edf3fb;color:#214b75;border-radius:8px}h2{font-size:20px}</style></head><body><div class="sent-note"><h2>Delivery confirmation</h2><p>Confirming Friday’s delivery.</p><img data-vp3-remote-src="https://remote.test/sent-image.png" alt="Sent image"></div></body></html>'}};
    const hold=delayOpen;delayOpen=null;if(hold){hold.started();await hold.promise;}
    if(failSent)return route.fulfill({status:failSent,json:{ok:false,error:'Gmail access changed.'}}).catch(()=>{});
    return ok({message});
  }
  throw new Error('Unexpected email operation: '+input.action);
});
await page.goto('https://sent.test/');
await page.evaluate(()=>{
  window.currentChat=42;window.chatBusy=false;window.participantSelected=false;window.ensureCalls=0;
  window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
  window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.currentChat,agentId:()=>7,isReady:()=>true,isRestoring:()=>false,isBusy:()=>window.chatBusy,
    ensureConversation:async()=>{window.ensureCalls++;window.currentChat=43;window.dispatchEvent(new Event('vp3:chat-context-ready'));return 43;},
    showEmailCanvasView:()=>{document.getElementById('chatThread').dataset.view='email';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));},
    showChatView:()=>{document.getElementById('chatThread').dataset.view='chat';window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));}};
  window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7};
  window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
  window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',
    emailReport:()=>'<h3>Inbox findings</h3><p>'+('Current inbox findings remain in the Inbox tab. ').repeat(120)+'</p>'};
  window.VP3ParticipantContinuity={selectedContext:()=>window.participantSelected?{participant_id:9}:null};
});
for(const file of ['agent-activity-hub-v1.css','gmail-email-display-v1.css','chat-email-composer-v1.css'])
  await page.addStyleTag({content:await fs.readFile(new URL(file,root),'utf8')});
for(const file of ['gmail-email-display-v1.js','chat-email-composer-v1.js','agent-activity-hub-v1.js'])
  await page.addScriptTag({content:await fs.readFile(new URL(file,root),'utf8')});
await page.evaluate(()=>document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'})));
const popup=()=>page.locator('#vp3ChatSpecialistActivity'),sent=()=>popup().getByRole('tabpanel',{name:'Sent',exact:true});
const sentRequests=()=>requests.filter(input=>['sent_list','sent_open'].includes(input.action));
async function screenshot(name){const directory=process.env.VP3_GMAIL_SENT_SCREENSHOT_DIR;if(!directory)return;await fs.mkdir(directory,{recursive:true});await page.screenshot({path:path.join(directory,name),fullPage:true});}
async function bounds(){
  const value=await popup().evaluate(el=>({scroll:el.scrollWidth,width:el.clientWidth,left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right}));
  assert.ok(value.left>=-1&&value.right<=page.viewportSize().width+1,'Gmail popup fits the viewport.');
  assert.ok(value.scroll<=value.width+1,'Gmail popup does not scroll horizontally.');
  const nested=await popup().evaluate(el=>[...el.querySelectorAll('*')].filter(child=>{
    const style=getComputedStyle(child);return ['auto','scroll'].includes(style.overflowY)&&child.scrollHeight>child.clientHeight+1;
  }).map(el=>el.className));assert.deepEqual(nested,[],'Only the surrounding Gmail popup scrolls.');
}
async function refreshHub(){
  const response=page.waitForResponse(value=>new URL(value.url()).pathname==='/api/hub');
  await page.evaluate(()=>window.VP3AgentActivityHub.refresh());const version=Number((await response).headers()['x-fixture-read']);
  await page.waitForFunction(minimum=>[...document.querySelectorAll('#vp3GmailInboxPanel .vp3-agent-activity-detail')]
    .some(element=>Number(/^Inbox checked #(\d+)$/.exec(element.textContent)?.[1])>=minimum),version);
}
async function instruction(text){return page.evaluate(({text,context})=>{
  const match=window.VP3EmailComposerV1.matchInstruction(text,context);return window.VP3EmailComposerV1.handleChatInstruction(text,context,match);
},{text,context:await scope()});}
function hold(){let release,started;const start=new Promise(resolve=>{started=resolve;});const promise=new Promise(resolve=>{release=resolve;});return {promise,started,start,release};}
try{
  await page.getByRole('button',{name:'Gmail',exact:true}).click();
  await popup().getByRole('button',{name:'View drafts, 1 pending draft',exact:true}).waitFor();
  assert.equal(await popup().getByRole('tab',{name:'Inbox',exact:true}).getAttribute('aria-selected'),'true');
  assert.equal(sentRequests().length,0,'Opening Inbox does not fetch Sent mail.');
  await bounds();await screenshot('popup-inbox-desktop.png');
  await popup().evaluate(el=>{el.scrollTop=400;});
  const top=await popup().evaluate(el=>({popup:el.getBoundingClientRect().top,button:el.querySelector('[data-gmail-review-queue]').getBoundingClientRect().top}));
  assert.ok(top.button>=top.popup,'View drafts stays visible while the popup scrolls.');
  await popup().getByRole('tab',{name:'Sent',exact:true}).click();
  await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  assert.equal(sentRequests().filter(input=>input.action==='sent_list').length,1);
  assert.equal(await sent().getByText(/Sent from owner@example.test/).count(),1);
  assert.equal(await sent().getByText(/total/i).count(),0,'The Sent view does not invent an exact mailbox total.');
  assert.equal(await sent().locator('article').count(),10);await bounds();await screenshot('sent-list-desktop.png');
  await sent().getByRole('button',{name:'Next',exact:true}).click();await sent().getByText('Page 2',{exact:true}).waitFor();
  await sent().getByText('Sent project update 12',{exact:true}).waitFor();
  assert.equal(await sent().getByRole('button',{name:'Next',exact:true}).isDisabled(),true);
  await sent().getByRole('button',{name:'Previous',exact:true}).focus();
  const beforeRefresh=sentRequests().length;await refreshHub();
  assert.equal(sentRequests().length,beforeRefresh,'Incidental inbox refresh does not read the Gmail provider.');
  assert.equal(await sent().getByText('Page 2',{exact:true}).count(),1,'Background inbox refresh preserves the current Sent page.');
  assert.equal(await page.evaluate(()=>document.activeElement?.textContent),'Previous','Background inbox refresh preserves Sent focus.');
  await popup().getByRole('tab',{name:'Inbox',exact:true}).click();await popup().getByRole('tab',{name:'Sent',exact:true}).click();
  assert.equal(sentRequests().length,beforeRefresh,'Switching back to an already loaded Sent page does not refetch it.');
  await sent().getByRole('button',{name:'Previous',exact:true}).click();await sent().getByText('Page 1',{exact:true}).waitFor();
  await sent().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();
  await sent().locator('iframe').waitFor();await page.waitForFunction(()=>document.querySelector('.vp3-gmail-sent-reader iframe')?.contentDocument?.querySelector('.sent-note'));
  assert.equal(remoteImages,0);assert.equal(await sent().locator('iframe').getAttribute('scrolling'),'no');
  assert.equal(await sent().getByRole('button',{name:'Send',exact:true}).count(),0);assert.equal(await sent().getByRole('button',{name:'Reply',exact:true}).count(),0);
  await sent().getByRole('button',{name:'Plain text',exact:true}).click();
  await sent().getByText('Confirming Friday’s delivery. The source is the exact sent Gmail message.',{exact:true}).waitFor();
  const formatted=sent().getByRole('button',{name:'Formatted',exact:true});await formatted.click();await formatted.hover();
  const contrast=await formatted.evaluate(element=>{const style=getComputedStyle(element);const luminance=color=>color.match(/[\d.]+/g).slice(0,3).map(Number).map(value=>{const c=value/255;return c<=.04045?c/12.92:((c+.055)/1.055)**2.4;}).reduce((sum,value,index)=>sum+value*[.2126,.7152,.0722][index],0);const fg=luminance(style.color),bg=luminance(style.backgroundColor);return(Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);});
  assert.ok(contrast>=4.5,'Selected formatted reader tab retains text contrast on hover.');
  await popup().evaluate(el=>{el.scrollTop=0;});await bounds();await screenshot('sent-detail-desktop.png');
  await page.setViewportSize({width:390,height:844});await bounds();await screenshot('sent-detail-phone.png');
  await sent().getByRole('button',{name:'Back to Sent',exact:true}).click();await bounds();await screenshot('sent-list-phone.png');
  await popup().getByRole('tab',{name:'Inbox',exact:true}).focus();await page.keyboard.press('End');
  assert.equal(await popup().getByRole('tab',{name:'Sent',exact:true}).getAttribute('aria-selected'),'true');
  await page.keyboard.press('Home');assert.equal(await popup().getByRole('tab',{name:'Inbox',exact:true}).getAttribute('aria-selected'),'true');
  await popup().getByRole('button',{name:'View drafts, 1 pending draft',exact:true}).click();
  await page.getByRole('heading',{name:'Drafts',exact:true}).waitFor();
  await page.locator('.vp3-email-compose').getByRole('button',{name:'Close',exact:true}).click();
  assert.equal((await instruction('Show my sent emails.')).ok,true);await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  for(const text of ['Open my sent email.','Show Gmail sent mail.','List my sent messages.'])
    assert.equal(await page.evaluate(({text,context})=>window.VP3EmailComposerV1.matchInstruction(text,context)?.kind,{text,context:await scope()}),'sent');
  for(const text of ['Send this email.','Send my draft.','Show my sent tasks.','Tell me when it was sent.'])
    assert.equal(await page.evaluate(({text,context})=>window.VP3EmailComposerV1.matchInstruction(text,context),{text,context:await scope()}),null);
  await popup().getByRole('button',{name:'Compose new email',exact:true}).click();
  const editor=page.locator('.vp3-email-compose').filter({visible:true});await editor.getByLabel('Message',{exact:true}).fill('Keep these unsaved changes.');
  const beforeDirty=sentRequests().length;const blocked=await instruction('Show my sent emails.');assert.equal(blocked.ok,false);
  await editor.getByRole('button',{name:'Keep editing',exact:true}).click();
  assert.equal(await editor.getByLabel('Message',{exact:true}).inputValue(),'Keep these unsaved changes.');assert.equal(sentRequests().length,beforeDirty);
  await instruction('Show my sent emails.');await editor.getByRole('button',{name:'Save draft and continue',exact:true}).click();
  await sent().getByText('Delivery confirmation',{exact:true}).waitFor();assert.equal(draft.body,'Keep these unsaved changes.');
  assert.equal(requests.filter(input=>['confirm','compose_generate','advance_write'].includes(input.action)).length,0,'Opening Sent never sends email or starts a model request.');
  await page.evaluate(()=>{window.chatBusy=true;});
  assert.equal(await page.evaluate(context=>window.VP3AgentActivityHub.openGmailSent(context),await scope()),true,'Current captured email navigation works under the main Chat busy lock.');
  await page.evaluate(()=>{window.chatBusy=false;});
  await sent().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();await sent().locator('iframe').waitFor();
  const beforeRevocation=sentRequests().length;readAvailable=false;await refreshHub();
  assert.equal(await sent().locator('iframe').count(),0);assert.equal(await sent().locator('article').count(),0);
  assert.equal(await sent().getByRole('button',{name:'Refresh Sent',exact:true}).isDisabled(),true);assert.equal(sentRequests().length,beforeRevocation);
  readAvailable=true;mailboxRevision='c'.repeat(64);accountEmail='new-owner@example.test';await refreshHub();
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText(/Sent from new-owner@example.test/).waitFor();
  const beforeUnavailable=sentRequests().length;workspaceUnavailable=true;await refreshHub();
  assert.equal(await sent().locator('article').count(),0,'Unavailable workspace authority removes cached private Sent content.');
  assert.equal(await sent().getByRole('button',{name:'Refresh Sent',exact:true}).isDisabled(),true);
  assert.equal(sentRequests().length,beforeUnavailable);workspaceUnavailable=false;await refreshHub();
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  mailboxRevision='e'.repeat(64);await refreshHub();
  assert.equal(await sent().locator('article').count(),0,'A changed mailbox revision clears old Sent content without another provider read.');
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  failSent=403;await sent().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();
  await sent().getByText('Gmail access or the saved message reference changed. Refresh Sent after checking Connections.',{exact:true}).waitFor();
  assert.equal(await sent().locator('article').count(),0);failSent=0;
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  failSent=404;await sent().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();
  await sent().getByText('This Chat or sent email is no longer available. Refresh Sent to load current messages.',{exact:true}).waitFor();
  assert.equal(await sent().locator('article').count(),0,'A removed Chat or message clears private Sent rows.');
  assert.equal(await sent().locator('iframe').count(),0);failSent=0;
  malformedSent=true;await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();
  await sent().getByText('A sent message could not be verified. Refresh Sent.',{exact:true}).waitFor();malformedSent=false;
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  await page.evaluate(()=>window.VP3AgentActivityHub.close(false));workspaceAvailable=false;
  const firstRevisionHold=hold();delayList=firstRevisionHold;
  await page.evaluate(context=>{window.firstRevisionDone=false;void window.VP3AgentActivityHub.openGmailSent(context).then(result=>{window.firstRevisionResult=result;window.firstRevisionDone=true;});},await scope());
  await firstRevisionHold.start;
  workspaceAvailable=true;mailboxRevision='f'.repeat(64);await refreshHub();
  firstRevisionHold.release();await page.waitForFunction(()=>window.firstRevisionDone);
  assert.equal(await page.evaluate(()=>window.firstRevisionResult),false,'A newer workspace revision wins over the first Sent page already in flight.');
  assert.equal(await sent().locator('article').count(),0);assert.equal(await sent().getByText(/Sent from /).count(),0);
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  const refreshRevisionHold=hold();delayList=refreshRevisionHold;
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await refreshRevisionHold.start;
  mailboxRevision='9'.repeat(64);await refreshHub();refreshRevisionHold.release();
  await sent().getByText('The connected Gmail account changed. Refresh Sent to load its current messages.',{exact:true}).waitFor();
  assert.equal(await sent().locator('article').count(),0,'Refresh keeps the observed mailbox revision even while the old displayed page is cleared.');
  await sent().getByRole('button',{name:'Refresh Sent',exact:true}).click();await sent().getByText('Delivery confirmation',{exact:true}).waitFor();
  const held=hold();delayOpen=held;await sent().getByRole('button',{name:'Open sent email Delivery confirmation',exact:true}).click();await held.start;
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
  assert.equal(await popup().count(),0);assert.equal(await page.evaluate(context=>window.VP3AgentActivityHub.openGmailSent(context),await scope()),false);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}})));
  held.release();await page.waitForTimeout(80);assert.equal(await popup().count(),0,'A late sent-message read cannot reopen private content after participant context returns.');
  const listHold=hold();delayList=listHold;
  await page.evaluate(context=>{window.sentOpenDone=false;void window.VP3AgentActivityHub.openGmailSent(context).then(result=>{window.sentOpenResult=result;window.sentOpenDone=true;});},await scope());
  await listHold.start;
  await page.evaluate(()=>{window.currentChat=99;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));window.currentChat=42;window.dispatchEvent(new Event('vp3:chat-context-ready'));});
  listHold.release();await page.waitForFunction(()=>window.sentOpenDone);assert.equal(await page.evaluate(()=>window.sentOpenResult),false);assert.equal(await popup().count(),0);
  await page.evaluate(()=>{window.currentChat=0;});await page.getByRole('button',{name:'Gmail',exact:true}).click();await popup().getByRole('tab',{name:'Sent',exact:true}).click();
  await sent().getByText('Delivery confirmation',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.ensureCalls),1);
  assert.equal(sentRequests().at(-1).conversation_id,43,'Manual Sent navigation initializes and binds the fresh private Chat.');
  await page.evaluate(()=>{window.participantSelected=true;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
  assert.equal(await popup().count(),0);
  assert.deepEqual(errors,[]);
  console.log('Gmail Sent browser: top draft entry, actual Sent pages, scoped formatted reader, commands/dirty edits, mailbox revocation, late reads, fresh Chat and responsive single-scroll layout passed.');
}finally{await browser.close();}
