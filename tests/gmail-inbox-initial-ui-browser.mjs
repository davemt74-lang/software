import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const origin = 'https://gmail-review.test';
const endpoint = '/api/gmail-inbox-initial-v1.php';
const emailEndpoint = '/api/chat-email-v1.php';
const runId = 'a'.repeat(32);
const startToken = 'b'.repeat(64);
const privateSubject = 'Urgent private budget <img src=x onerror=alert(1)>';
const privateSummary = 'The sampled subjects suggest billing <b>follow-up</b>.';
const privateBody = 'Exact saved message body <img src=x onerror=alert(1)>.';
const firstRef = 'c'.repeat(64);
const latestRef = 'd'.repeat(64);
const reportRef = 'e'.repeat(64);
const legacyRef = 'f'.repeat(64);
const savedMessageId = 50;
const firstItem = {subject:privateSubject,from:'private@example.test',category:'reply',important:true,priority_signals:['urgent'],item_ref:firstRef};
const legacyItem = {subject:'Older header only',from:'older@example.test',category:'new',legacy_ref:legacyRef};
const latestItem = {subject:'Action required: Latest item',from:'later@example.test',category:'reply',important:true,priority_signals:['action required'],item_ref:latestRef};
const repeatedSender = {subject:'New message from an existing sender',from:'PRIVATE@example.test',category:'new',item_ref:'1'.repeat(64)};
const itemLabels = row => row.locator('.gmail-review-item-labels .connections-badge').allTextContents();
const legacyMatches = [
  {message_id:'gmail_candidate_first',match_token:'2'.repeat(64),from:legacyItem.from,subject:legacyItem.subject,
    received_at:'2026-11-07T09:00:00Z',snippet:'First message <img src=x onerror=alert(1)>.'},
  {message_id:'gmail_candidate_second',match_token:'3'.repeat(64),from:legacyItem.from,subject:legacyItem.subject,
    received_at:'2026-11-08T09:00:00Z',snippet:'Second message on the same subject.'},
];
const matchedCommand = (action,index) => {
  const {message_id,match_token} = legacyMatches[index];
  return `${action} to Gmail matched item ${legacyRef} ${message_id} ${match_token}`;
};
const watchKey = '4'.repeat(64);
const watchedSenders = new Set([firstItem.from]);
const composedDraft = {id:'5'.repeat(32),status:'prepared',is_reply:true,subject:'Re: '+legacyItem.subject,
  from:'owner@example.test',to:legacyItem.from,body:'Generated reply for owner review.',review_token:'fixture-review'};
const manualDraft = {...composedDraft,id:'6'.repeat(32),body:''};
const connectionsUrl = '/connections.php?app=gmail#gmail-inbox-review';
const script = 'gmail-inbox-initial-v1.js';
const chatScripts = ['chat.js','team-chat-admin-v109.js','chat-specialist-rail-v1.js','agent-eyes-core.js',
  'agent-activity-hub-v1.js','agent-orb-adapter.js','agent-orb.js','agent-orb-particles.js','chat-email-v1.js'];
const assets = new Set(['chat.css','connections-v1.css','chat-specialist-rail-v1.css','chat-email-v1.css',
  'agent-activity-hub-v1.css','agent-orb.css','agent-orb-particles.css','team-chat-v109.css','team-chat-v109.js',script,...chatScripts]);
const phpFixture = fileURLToPath(new URL('fixtures/gmail-inbox-initial-page-v1.php', import.meta.url));
const chatRuntime = execFileSync('php',[phpFixture,'--chat-review-runtime','{}'],{encoding:'utf8'});
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return {promise,resolve};
};
async function waitForGate(gate,label) {
  let timeout;
  try {
    await Promise.race([gate.promise,new Promise((_,reject) => {
      timeout = setTimeout(() => reject(new Error(`Timed out waiting for ${label}`)),15000);
    })]);
  } finally { clearTimeout(timeout); }
}
const needsStart = () => ({state:'needs_start',limit:50});
const inProgress = state => ({state,run_id:runId,days:14,scanned:5,limit:50,stage:'scan',has_report:false,
  updated_at:'2026-11-10T12:00:00Z',truncated:false,
  ...(state === 'working' ? {next_attempt_at:new Date(Date.now()+60000).toISOString()} : {})});
const summarizing = () => ({...inProgress('working'),stage:'summary',scanned:50,truncated:true});
const completed = () => ({...inProgress('ready'),stage:'summary',scanned:50,truncated:true,has_report:true,total:3,
  report:{at:'2026-11-10 12:00:00',days:14,scanned:50,limit:50,truncated:true,total:3,important:1,replies:1,filtered:2,
    summary_status:'ready',summary:'The sampled subjects suggest billing.',tokens_charged:114,
    items:[firstItem,legacyItem]},
  chat_result:{summary:privateSummary,items:[firstItem,legacyItem],
    latest:{total:2,important:1,replies:1,at:'2026-11-10T13:00:00Z',items:[latestItem,repeatedSender]}}});
let review = needsStart();
let showMonitor = false;
let initialViewToken = startToken;
let apiViewToken = startToken;
let advanceGate = null;
let statusGate = null;
let inboxBackground = null;
let checkOverdue = null;
let workerStartsReview = false;
let delivered = null;
let pendingUpdate = null;
const batchPosts = [];
const batchScope = '9'.repeat(64);
const inboxUpdates = () => ({scope_ref:batchScope,pending:Boolean(pendingUpdate),latest:batchPosts.at(-1) || null});
let newConversations = 0;
let matchResult = {matches:legacyMatches,unique_match:false};
const requests = [];
const turns = [];
const count = (action,surface) => requests.filter(row => row.action === action && (!surface || row.surface === surface)).length;
const project = (includeReport,token = apiViewToken,includeChatResult = false) => {
  const view = structuredClone(review);
  if (!includeReport) delete view.report;
  else if (token !== null) view.start_token = token;
  if (!includeChatResult) delete view.chat_result;
  else {
    if (view.state === 'ready' && (pendingUpdate || batchPosts.length)) view.inbox_updates = inboxUpdates();
    if (inboxBackground) view.inbox_background = inboxBackground;
  }
  return view;
};
async function screenshot(page,name,view) {
  if (!process.env.VP3_GMAIL_REVIEW_SCREENSHOTS) return;
  if (view) await view.scrollIntoViewIfNeeded();
  await page.screenshot({path:fileURLToPath(new URL(`../../gmail-review-${name}.png`,import.meta.url))});
}

const chatMarkup = `<!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-activity-hub-v1.css">
<link rel="stylesheet" href="/agent-orb-particles.css">
<link rel="stylesheet" href="/chat-specialist-rail-v1.css">
<link rel="stylesheet" href="/chat-email-v1.css">
<link rel="stylesheet" href="/chat.css">
<div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside>
<main class="chat-main"><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
<div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer">
<textarea id="chatInput" aria-label="Message"></textarea><button id="sendChatButton">Send</button></form></div></main></div>
<script>
window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'review-fixture',initialView:'chat',
  initialConversationId:42,endpoint:'/api/chat.php',csrf:'fixture'};
window.VP3_CHAT_EMAIL_V1={endpoint:'${emailEndpoint}',csrf:'fixture',ownerKey:'1',agentId:7};
window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'review-fixture',activityEndpoint:'/api/hub',chatUrl:'/chat.php'};
window.STONEFELLOW_NOTIFICATION_CENTER={};
window.StonefellowPremiumVoiceV122={};
window.VP3ParticipantContinuity={selectedContext:()=>window.fixtureParticipant?{participant_id:2}:null,
  clearSelection:()=>{window.fixtureParticipant=false;}};
</script>
${chatScripts.map(name => `<script src="/${name}" defer></script>`).join('')}${chatRuntime}`;

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
try {
  const context = await browser.newContext({viewport:{width:1280,height:900}});
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  const errors = [];
  page.on('pageerror', error => { errors.push(error.message); if (process.env.VP3_TEST_DEBUG) console.error(error.message); });
  await context.route(origin+'/**',async route => {
    const request = route.request();
    const url = new URL(request.url());
    const file = url.pathname.slice(1);
    if (assets.has(file)) {
      return route.fulfill({contentType:file.endsWith('.css')?'text/css':'text/javascript',
        body:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
    }
    if (url.pathname === '/connections.php') {
      return route.fulfill({contentType:'text/html; charset=utf-8',
        body:execFileSync('php',[phpFixture,url.search.slice(1),JSON.stringify(project(true,initialViewToken,true))],{encoding:'utf8'})});
    }
    if (url.pathname === '/chat.php') {
      let body = url.searchParams.has('without_review')
        ? chatMarkup.replace(chatRuntime,'') : chatMarkup;
      if (url.searchParams.has('empty_chat')) body = body.replace('initialConversationId:42','initialConversationId:0');
      return route.fulfill({contentType:'text/html; charset=utf-8',body});
    }
    if (url.pathname === '/api/chat.php') {
      const body = request.postDataJSON() || {};
      if (body.action === 'send') {
        turns.push(body.message);
        const draft = body.message === matchedCommand('Draft reply',1) ? composedDraft
          : body.message === matchedCommand('Reply',1) ? manualDraft : null;
        const actions = draft ? [{type:'email_review',id:draft.id,conversation_id:42,is_reply:true}] : [];
        return route.fulfill({json:{ok:true,conversation_id:42,user_message_id:2*turns.length,assistant_message_id:2*turns.length+1,
          answer:`Fixture Chat result ${turns.length}.`,actions}});
      }
      if (body.action === 'new') newConversations++;
      const conversation = body.conversation_id || 42;
      const messages = body.action === 'load' ? [{id:1,role:'assistant',message:'Existing conversation'}] : [];
      if (delivered?.conversation_id === conversation && ['load','messages_after'].includes(body.action)
          && (body.action === 'load' || (body.after_id || 0) < savedMessageId)) {
        const legacyChat = new URL(request.frame().url()).searchParams.has('legacy_chat');
        const reviewContext = review.state === 'ready' && !legacyChat
          ? {source:'agent_connection_gmail_first_review',gmail_inbox_review_v1:{...delivered,review:project(false,undefined,true)}} : {};
        messages.push({id:savedMessageId,role:'assistant',message:'Your first Gmail inbox review is ready.',
          created_at:'2026-11-10 12:15:00',context_json:JSON.stringify(reviewContext)});
      }
      for (const batch of batchPosts) {
        if (batch.conversation_id !== conversation || !['load','messages_after'].includes(body.action)
            || (body.action === 'messages_after' && (body.after_id || 0) >= batch.message_id)) continue;
        const context = review.state === 'ready'
          ? {source:'agent_connection_gmail_inbox_update',gmail_inbox_review_v1:batch} : {};
        messages.push({id:batch.message_id,role:'assistant',message:'Your Gmail inbox update is ready.',
          created_at:'2026-11-10 15:20:00',context_json:JSON.stringify(context)});
      }
      return route.fulfill({json:{ok:true,conversation_id:conversation,conversations:[],latest_message_id:batchPosts.at(-1)?.message_id || delivered?.message_id || 1,
        messages,updates:[]}});
    }
    if (url.pathname === '/api/team-chat-v109.php') return route.fulfill({json:{ok:true,users:[],messages:[]}});
    if (url.pathname === emailEndpoint) {
      assert.equal(request.method(),'POST');
      assert.equal(new URL(request.frame().url()).pathname,'/chat.php');
      const body = request.postDataJSON();
      if (body.action === 'specialist_status') return route.fulfill({json:{ok:true,specialist:{state:'idle'}}});
      if (body.action === 'review') {
        const draft = [composedDraft,manualDraft].find(item => item.id === body.draft_id);
        assert.ok(draft,'The editor opens the exact draft returned by the Chat action.');
        assert.deepEqual(body,{action:'review',owner_key:'1',csrf_token:'fixture',agent_id:7,conversation_id:42,draft_id:draft.id});
        return route.fulfill({json:{ok:true,draft}});
      }
      assert.deepEqual(body,{action:body.action,owner_key:'1',csrf_token:'fixture',agent_id:7,
        conversation_id:body.conversation_id,
        ...(body.action === 'sender_watch' ? {sender:body.sender,enabled:body.enabled,watchlist_key:watchKey} : {})});
      assert.ok([42,43].includes(body.conversation_id));
      if (body.action === 'sender_watch') {
        assert.equal(body.conversation_id,42);
        assert.ok([firstItem.from,legacyItem.from,latestItem.from].includes(body.sender));
        assert.equal(typeof body.enabled,'boolean');
        if (body.enabled) watchedSenders.add(body.sender);
        else watchedSenders.delete(body.sender);
      } else assert.equal(body.action,'sender_watchlist');
      requests.push({...body,surface:'/chat.php'});
      return route.fulfill({json:{ok:true,watchlist:{available:true,key:watchKey,
        senders:body.conversation_id === 42 ? [...watchedSenders] : []}}});
    }
    if (url.pathname === '/api/hub') {
      const ready = review.state === 'ready';
      const detail = ready ? 'Ready to read in Chat'
        : review.stage === 'summary' ? 'Working · Preparing the AI summary' : 'Working · Checking Gmail headers';
      const actions = ready && delivered
        ? [{kind:'chat',label:'View in Chat',conversation_id:delivered.conversation_id,message_id:delivered.message_id,
          href:`/chat.php?agent=7&conversation_id=${delivered.conversation_id}&message_id=${delivered.message_id}`}]
        : [{kind:'url',label:ready?'View in Chat':'Open Connections',href:ready?'/chat.php?agent=7':connectionsUrl}];
      const sections = review.state === 'needs_start' ? [] : [{id:'gmail_inbox',label:'Gmail Inbox Review',items:[{
        id:'gmail:first-review',kind:'gmail_inbox_review',title:'Gmail Inbox Review',detail,
        actions,
      }]}];
      if (showMonitor) sections[0].items.unshift({
        id:'gmail:inbox-monitor',kind:'gmail_inbox_monitor',title:'Gmail inbox monitoring',detail:'',
        monitor:{state:'checked',last_checked_at:new Date(Date.now()-60000).toISOString(),
          next_check_at:new Date(Date.now()+240000).toISOString(),lease_until:null,pending:true},
        actions:[{kind:'chat',label:'View latest update',conversation_id:42,message_id:101,href:'/chat.php?agent=7&conversation_id=42&message_id=101'}],
      });
      return route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,
        conversation_id:42,working_attention_binding:'review-fixture',agent_name:'Longfellow',
        observed_at:new Date().toISOString(),sections}}});
    }
    if (url.pathname !== endpoint) return route.fulfill({status:404,body:''});
    assert.equal(request.method(),'POST');
    const body = request.postDataJSON();
    const surface = new URL(request.frame().url()).pathname;
    if (body.action === 'open_item') {
      assert.deepEqual(body,{action:'open_item',owner_key:'1',csrf_token:'fixture',item_ref:body.item_ref});
      const item = [firstItem,latestItem,...review.report?.items || [],...batchPosts.flatMap(batch => batch.review.items)]
        .find(candidate => candidate.item_ref === body.item_ref);
      assert.ok(item);
      requests.push({...body,surface});
      return route.fulfill({json:{ok:true,item:{subject:item.subject,from:item.from,body:privateBody,
        auto_reply:body.item_ref===firstRef,...(body.item_ref===firstRef?{auto_reply_to:'approved@example.test'}:{})}}});
    }
    if (body.action === 'find_matches') {
      assert.deepEqual(body,{action:'find_matches',owner_key:'1',csrf_token:'fixture',legacy_ref:legacyRef});
      requests.push({...body,surface});
      return route.fulfill({json:{ok:true,...matchResult}});
    }
    if (body.action === 'open_match') {
      const match = legacyMatches.find(item => item.message_id === body.message_id);
      assert.ok(match,'Opening a candidate uses its exact Gmail message ID.');
      assert.deepEqual(body,{action:'open_match',owner_key:'1',csrf_token:'fixture',legacy_ref:legacyRef,
        message_id:match.message_id,match_token:match.match_token});
      requests.push({...body,surface});
      return route.fulfill({json:{ok:true,item:{subject:match.subject,from:match.from,body:privateBody,auto_reply:false}}});
    }
    if (body.action === 'deliver') {
      assert.equal(surface,'/chat.php');
      assert.equal(review.state,'ready');
      assert.deepEqual(body,{action:'deliver',owner_key:'1',csrf_token:'fixture',run_id:runId,
        agent_id:7,conversation_id:body.conversation_id,working_attention_binding:'review-fixture'});
      assert.ok([42,43].includes(body.conversation_id));
      requests.push({...body,surface});
      if (delivered && delivered.conversation_id !== body.conversation_id) {
        return route.fulfill({json:{ok:true,delivery:{state:'bound_elsewhere'}}});
      }
      const state = delivered ? 'existing' : 'posted';
      delivered = {run_id:runId,report_ref:reportRef,agent_id:7,conversation_id:body.conversation_id,message_id:savedMessageId};
      return route.fulfill({json:{ok:true,delivery:{state,...delivered},review:project(false,undefined,true)}});
    }
    if (body.action === 'deliver_updates') {
      assert.equal(surface,'/chat.php');
      assert.deepEqual(body,{action:'deliver_updates',owner_key:'1',csrf_token:'fixture',
        agent_id:7,conversation_id:42,working_attention_binding:'review-fixture'});
      requests.push({...body,surface});
      assert.ok(pendingUpdate,'A verified batch can be delivered only once.');
      batchPosts.push(pendingUpdate);
      pendingUpdate = null;
      return route.fulfill({json:{ok:true,inbox_updates:inboxUpdates()}});
    }
    if (body.action === 'check_overdue') {
      assert.equal(surface,'/chat.php');
      assert.equal(inboxBackground?.state,'overdue');
      assert.deepEqual(body,{action:'check_overdue',owner_key:'1',csrf_token:'fixture',
        check_ref:inboxBackground.check_ref,working_attention_binding:'review-fixture',include_chat_result:true});
      requests.push({...body,surface});
      assert.ok(checkOverdue,'Only a scheduled test check can ask for Gmail work.');
      return route.fulfill(checkOverdue());
    }
    const includeReport = surface === '/connections.php';
    const expected = {action:body.action,owner_key:'1',csrf_token:'fixture',
      ...(includeReport ? {include_report:true} : {include_chat_result:true})};
    if (body.action === 'start') Object.assign(expected,{days:14,start_token:startToken});
    if (body.action === 'advance') Object.assign(expected,{run_id:runId});
    assert.deepEqual(body,expected,`${surface} sends only its allowed review fields.`);
    requests.push({...body,surface});
    let view;
    if (body.action === 'status' && statusGate) {
      const gate = statusGate;
      statusGate = null;
      view = project(includeReport,apiViewToken,!includeReport);
      gate.started.resolve();
      await gate.release.promise;
    } else if (body.action === 'start') {
      assert.equal(review.state,'needs_start','A run is created only once.');
      review = inProgress(workerStartsReview ? 'working' : 'queued');
    } else if (body.action === 'advance') {
      const gate = advanceGate;
      assert.ok(gate,'A bounded advance may run once for this fixture step.');
      advanceGate = null;
      gate.started.resolve();
      await gate.release.promise;
      review = completed();
    } else assert.equal(body.action,'status');
    try { await route.fulfill({json:{ok:true,review:view ?? project(includeReport,apiViewToken,!includeReport)}}); }
    catch (error) { if (!view) throw error; /* Scope changes can abort a held status. */ }
  });

  await page.goto(origin+connectionsUrl);
  const autoSend = page.getByRole('checkbox',{name:/Enable Auto Send/});
  assert.equal(await autoSend.isChecked(),false,'Auto Send is off by default.');
  assert.equal(await page.getByRole('textbox',{name:'Approved email addresses'}).inputValue(),'');
  const musicResearch = page.locator('#music-research');
  assert.equal(await musicResearch.getByRole('checkbox',{name:/Use recent VP3 Music listening/}).isChecked(),false,
    'Native Music research is off by default on the shipped Connections page.');
  assert.equal(await musicResearch.locator('input[name="csrf_token"]').inputValue(),'fixture');
  const startForm = page.locator('[data-gmail-review-start]');
  const windowSelect = startForm.locator('select[name="days"]');
  const report = page.locator('[data-gmail-review-report]');
  await windowSelect.waitFor();
  assert.equal(await windowSelect.inputValue(),'7');
  assert.deepEqual(await windowSelect.locator('option').evaluateAll(options => options.map(option => option.value)),['1','3','7','14','30']);
  assert.equal(count('start'),0,'Opening Connections never creates a review.');
  assert.equal(count('advance'),0);
  await screenshot(page,'setup',startForm);
  await windowSelect.selectOption('14');
  assert.equal(count('start'),0,'Changing the lookback does not start work.');
  const connectionsAdvance = advanceGate = {started:deferred(),release:deferred()};
  await startForm.getByRole('button').first().click();
  await waitForGate(connectionsAdvance.started,'Connections advance');
  assert.equal(count('start','/connections.php'),1);
  assert.equal(count('advance','/connections.php'),1);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  const previousStatus = count('status','/connections.php');
  review = summarizing();
  await page.locator('[data-gmail-review-status]').filter({hasText:'Preparing the AI summary.'}).waitFor();
  assert.ok(count('status','/connections.php') > previousStatus,'A read-only probe updates the held advance status.');
  assert.equal(count('advance','/connections.php'),1,'A pending slice does not overlap another advance.');
  connectionsAdvance.release.resolve();
  await report.getByText(privateSubject,{exact:true}).waitFor();
  assert.equal(await report.getByText('114 VP3 tokens charged',{exact:true}).isVisible(),true);
  assert.equal(await report.getByText('This review reached the 50-header limit.',{exact:true}).isVisible(),true);
  assert.equal(await report.getByText('Replies',{exact:true}).isVisible(),true);
  const firstConnectionRow = report.getByRole('listitem').filter({hasText:privateSubject});
  assert.deepEqual(await itemLabels(firstConnectionRow),['Incoming reply','Important']);
  assert.equal(await firstConnectionRow.getByText('Priority keywords in subject · urgent',{exact:true}).isVisible(),true);
  assert.equal(await report.locator('img,script').count(),0,'Report metadata renders as text.');
  assert.equal(count('open_item'),0,'The initial report never opens a message without a click.');
  await firstConnectionRow.getByRole('button',{name:'Open',exact:true}).click();
  await report.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(await report.getByRole('button',{name:/Auto reply/}).count(),0);
  assert.equal(await report.getByRole('checkbox').count(),0,'Watching a sender belongs to its notification Chat.');
  assert.equal(count('open_item','/connections.php'),1);
  await page.reload();
  await report.getByText(privateSubject,{exact:true}).waitFor();
  const latestConnectionRow = page.locator('[data-gmail-review-latest-item]').filter({hasText:latestItem.subject});
  assert.deepEqual(await itemLabels(latestConnectionRow),['Incoming reply','Important']);
  assert.equal(await latestConnectionRow.getByText('Priority keywords in subject · action required',{exact:true}).isVisible(),true);
  await latestConnectionRow.getByRole('button',{name:'Open',exact:true}).click();
  await latestConnectionRow.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(requests.filter(row=>row.action==='open_item' && row.surface==='/connections.php').at(-1).item_ref,latestRef);
  await screenshot(page,'report',report);
  assert.equal(count('start','/connections.php'),1);
  assert.equal(count('advance','/connections.php'),1,'Refreshing a completed report does not scan again.');
  console.log('PASS actual Connections window selection, explicit start, bounded progress and escaped retained report');

  // A review completed before this Chat loaded still appears in the thread.
  await page.goto(origin+'/chat.php');
  const completion = page.locator('#chatThread [data-gmail-review-complete]');
  const savedTurn = page.locator(`#chatThread [data-chat-message-id="${savedMessageId}"][data-chat-message-role="assistant"]`);
  await completion.getByRole('status').getByText('Reviewed 3 inbox messages.',{exact:true}).waitFor();
  assert.equal(await completion.getByRole('link',{name:'Open review'}).getAttribute('href'),origin+connectionsUrl);
  await completion.getByText(privateSummary,{exact:true}).waitFor();
  await completion.getByText(/Latest inbox update.*2 new.*1 important.*1 reply/).waitFor();
  assert.equal(await completion.locator('b,img,script').count(),0,'Saved AI text is never interpreted as markup.');
  assert.equal(await page.locator('#chatThread .message').count(),2,'A previously completed review creates one real assistant turn.');
  assert.equal(await completion.locator('xpath=ancestor::article').getAttribute('data-chat-message-id'),String(savedMessageId));
  await savedTurn.getByText('Your first Gmail inbox review is ready.',{exact:true}).waitFor();
  assert.equal(count('deliver'),1,'Opening the current Chat backfills the saved report once.');
  assert.equal(await page.getByText(privateSubject,{exact:true}).isVisible(),false);
  assert.equal(count('advance','/chat.php'),0,'Showing the saved result does not restart the review.');
  assert.equal(count('open_item','/chat.php'),0);
  assert.equal(turns.length,0);
  await completion.getByText('Messages in first review (2)',{exact:true}).click();
  const reviewRows = completion.locator('.gmail-review-items > li');
  const firstRow = reviewRows.filter({hasText:privateSubject});
  assert.deepEqual(await itemLabels(firstRow),['Incoming reply','Important']);
  assert.equal(await firstRow.getByText('Priority keywords in subject · urgent',{exact:true}).isVisible(),true);
  await firstRow.getByRole('button',{name:'Open',exact:true}).click();
  await firstRow.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(await firstRow.locator('img').count(),0);
  assert.equal(await firstRow.getByRole('button',{name:'Auto reply and send (uses tokens)'}).isVisible(),true);
  await firstRow.getByRole('button',{name:'Auto Compose (uses tokens)',exact:true}).click();
  await page.getByText('Fixture Chat result 1.',{exact:true}).waitFor();
  assert.equal(await page.locator('#chatInput').inputValue(),'');
  assert.equal(await page.locator('#chatThread').getByText(new RegExp(firstRef)).count(),0,'The reference is never shown as a Chat turn.');
  assert.deepEqual(turns,[`Draft reply to Gmail review item ${firstRef}`]);
  await firstRow.getByRole('button',{name:'Auto reply and send (uses tokens)'}).click();
  await page.getByText('Fixture Chat result 2.',{exact:true}).waitFor();
  assert.deepEqual(turns,[`Draft reply to Gmail review item ${firstRef}`,`Auto reply to Gmail review item ${firstRef}`]);
  const legacyRow = reviewRows.filter({hasText:legacyItem.subject});
  const legacyActions = legacyRow.locator(':scope > .gmail-review-item-actions');
  assert.deepEqual(await legacyActions.getByRole('button').allTextContents(),['Open','Reply','Auto Compose (uses tokens)']);
  assert.equal(await completion.getByRole('button',{name:'Find matches in Chat'}).count(),0);
  assert.equal(count('find_matches'),0,'A saved header is resolved only when the owner requests it.');
  const candidates = legacyRow.locator('[data-gmail-review-match]');
  const chooseCandidate = (index, name) => candidates.filter({hasText:legacyMatches[index].snippet})
    .getByRole('button',{name,exact:true}).click();
  const openCandidates = async name => {
    await legacyActions.getByRole('button',{name,exact:true}).click();
    await candidates.last().waitFor();
    assert.equal(await candidates.count(),2,'Two same-subject messages need an exact owner selection.');
  };
  await page.locator('#chatInput').fill('Keep this draft while looking up old mail');
  await openCandidates('Open');
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft while looking up old mail');
  assert.equal(turns.length,2,'Resolving an older header never silently picks or replies to a match.');
  assert.equal(count('open_match'),0);
  assert.equal(await candidates.locator('img,script').count(),0,'Candidate excerpts render as text.');
  await chooseCandidate(1,'Open');
  await legacyRow.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(requests.filter(row => row.action === 'open_match').at(-1).message_id,legacyMatches[1].message_id);
  assert.equal(await legacyRow.getByRole('button',{name:/Auto reply and send/}).count(),0,'A resolved legacy header can only draft for review.');
  await page.locator('#chatInput').fill('');
  await openCandidates('Reply');
  assert.equal(turns.length,2);
  await chooseCandidate(0,'Reply');
  await page.getByText('Fixture Chat result 3.',{exact:true}).waitFor();
  await openCandidates('Auto Compose (uses tokens)');
  assert.equal(turns.length,3);
  await chooseCandidate(1,'Auto Compose (uses tokens)');
  await page.getByText('Fixture Chat result 4.',{exact:true}).waitFor();
  const draftDialog = page.getByRole('dialog',{name:'Review Gmail draft'});
  await draftDialog.getByRole('button',{name:'Edit manually'}).waitFor();
  assert.equal(await draftDialog.getByRole('textbox',{name:'Message'}).inputValue(),composedDraft.body);
  assert.equal(await draftDialog.getByText(legacyItem.from,{exact:true}).isVisible(),true);
  assert.equal(await draftDialog.getByRole('button',{name:'Approve and send'}).isVisible(),true);
  await draftDialog.getByRole('button',{name:'Close',exact:true}).click();
  assert.equal(await page.locator('#chatInput').inputValue(),'');
  for (const match of legacyMatches) {
    assert.equal(await page.locator('#chatThread').getByText(new RegExp(`${match.message_id}|${match.match_token}`)).count(),0,
      'Gmail IDs and match tokens are never shown in Chat.');
  }
  assert.equal(count('find_matches'),3);
  assert.equal(count('advance','/chat.php'),0,'Resolving old headers does not repeat the paid review.');
  await completion.getByText('Messages in latest update (2)',{exact:true}).click();
  const latestRow = reviewRows.filter({hasText:latestItem.subject});
  assert.deepEqual(await itemLabels(latestRow),['Incoming reply','Important']);
  assert.equal(await latestRow.getByText('Priority keywords in subject · action required',{exact:true}).isVisible(),true);
  await latestRow.getByRole('button',{name:'Open',exact:true}).click();
  await latestRow.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(await completion.getByRole('button',{name:/Auto reply/}).count(),0,'An ineligible exact recipient has no Auto reply button.');
  assert.equal(requests.filter(row=>row.action==='open_item' && row.surface==='/chat.php').map(row=>row.item_ref).join(','),firstRef+','+latestRef);
  await latestRow.getByRole('button',{name:'Reply',exact:true}).click();
  await page.getByText('Fixture Chat result 5.',{exact:true}).waitFor();
  assert.deepEqual(turns,[`Draft reply to Gmail review item ${firstRef}`,`Auto reply to Gmail review item ${firstRef}`,
    matchedCommand('Reply',0),matchedCommand('Draft reply',1),
    `Reply to Gmail review item ${latestRef}`]);

  const watch = legacyRow.getByRole('checkbox');
  await watch.waitFor();
  assert.equal(await firstRow.getByRole('checkbox').isChecked(),true,'Existing watches are reflected in the saved review.');
  assert.equal(await watch.isChecked(),false);
  assert.equal(await latestRow.getByRole('checkbox').isChecked(),false);
  assert.equal(await reviewRows.filter({hasText:repeatedSender.subject}).getByRole('checkbox').count(),0,
    'The same address gets one checkbox across both groups, even when its case differs.');
  assert.equal(await completion.getByRole('checkbox').count(),3);
  for (const enabled of [true,false,true]) {
    const savedWatch = page.waitForResponse(response => response.url().endsWith(emailEndpoint)
      && response.request().postDataJSON()?.action === 'sender_watch');
    await watch.click();
    assert.equal((await savedWatch).ok(),true);
    await page.waitForFunction(checked => {
      const box = [...document.querySelectorAll('.gmail-review-items > li')]
        .find(row => row.textContent.includes('Older header only'))?.querySelector('input[type="checkbox"]');
      return box && !box.disabled && box.checked === checked;
    },enabled);
  }
  assert.deepEqual(requests.filter(row => row.action === 'sender_watch').map(row => [row.sender,row.enabled]),
    [[legacyItem.from,true],[legacyItem.from,false],[legacyItem.from,true]],'Only the selected sender’s exact watch is changed.');
  assert.equal(turns.length,5,'Watching a sender never starts a Chat turn or sends email.');

  const sourceAction = name => legacyActions.getByRole('button',{name,exact:true});
  matchResult = {matches:[legacyMatches[1]],unique_match:false};
  await sourceAction('Reply').click();
  await candidates.first().waitFor();
  assert.equal(await candidates.count(),1,'An incomplete lookup still needs confirmation of its only candidate.');
  assert.equal(turns.length,5);

  matchResult = {...matchResult,unique_match:true};
  await page.locator('#chatInput').fill('Keep this draft while opening an exact match');
  await sourceAction('Open').click();
  await legacyRow.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft while opening an exact match');
  assert.equal(await candidates.count(),0,'A verified single match opens directly.');
  assert.equal(requests.filter(row => row.action === 'open_match').at(-1).message_id,legacyMatches[1].message_id);
  await page.locator('#chatInput').fill('');
  await sourceAction('Reply').click();
  await draftDialog.getByText('Write your reply, then review it before sending.',{exact:true}).waitFor();
  const replyBody = draftDialog.getByRole('textbox',{name:'Message'});
  assert.equal(await replyBody.isEditable(),true);
  assert.equal(await replyBody.inputValue(),'');
  assert.equal(turns.at(-1),matchedCommand('Reply',1));
  assert.equal(await candidates.count(),0,'Reply opens the editor without a chooser for a verified match.');
  await draftDialog.getByRole('button',{name:'Close',exact:true}).click();
  await sourceAction('Auto Compose (uses tokens)').click();
  await draftDialog.getByRole('button',{name:'Edit manually'}).waitFor();
  assert.equal(await replyBody.inputValue(),composedDraft.body);
  assert.equal(await draftDialog.getByRole('button',{name:'Approve and send'}).isVisible(),true);
  assert.deepEqual(turns.slice(5),[matchedCommand('Reply',1),matchedCommand('Draft reply',1)]);
  assert.equal(await candidates.count(),0,'Auto Compose opens the composed draft directly for review.');
  assert.equal(count('advance','/chat.php'),0,'A direct legacy match still reuses the saved review.');
  await draftDialog.getByRole('button',{name:'Close',exact:true}).click();
  matchResult = {matches:legacyMatches,unique_match:false};

  const reloadStatus = statusGate = {started:deferred(),release:deferred()};
  await page.reload();
  await waitForGate(reloadStatus.started,'held status after loading the durable turn');
  await completion.getByText(privateSummary,{exact:true}).waitFor();
  reloadStatus.release.resolve();
  assert.equal(await completion.count(),1,'The durable receipt hydrates once on reload.');
  assert.equal(count('deliver'),1,'A loaded review turn bypasses another delivery request.');
  await completion.getByText('Messages in first review (2)',{exact:true}).click();
  await watch.waitFor();
  assert.equal(await watch.isChecked(),true,'The watch checkbox survives Chat reload.');
  const otherDelivery = page.waitForResponse(response => response.url().endsWith(endpoint)
    && response.request().postDataJSON()?.action === 'deliver');
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(43));
  await otherDelivery;
  assert.equal(await completion.count(),0,'Another Chat cannot display the report bound to the original thread.');
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(42));
  await completion.getByText(privateSummary,{exact:true}).waitFor();
  assert.equal(await completion.count(),1);
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  await page.locator('[data-activity-specialist="gmail-inbox-review"]').getByRole('button',{name:'View in Chat'}).click();
  await savedTurn.getByText(privateSummary,{exact:true}).waitFor();
  assert.equal(await completion.count(),1,'Top of Mind opens the same saved assistant turn.');
  console.log('PASS exact and legacy Gmail message actions, scoped sender watches, and one durable review after reload');

  delivered = null;
  review = needsStart();
  const initialStatus = page.waitForResponse(response => response.url().endsWith(endpoint) && response.request().postDataJSON()?.action === 'status');
  await page.goto(origin+'/chat.php');
  await initialStatus;
  const tile = page.locator('[data-chat-specialist="gmail-inbox-review"]');
  const workerPanel = page.locator('[data-chat-specialist-panel="gmail-inbox-review"]');
  const hub = page.locator('#vp3AgentActivityHub');
  const job = hub.locator('[data-activity-specialist="gmail-inbox-review"]');
  const orb = page.locator('.vp3-orb-root');
  const gmailWork = orb.locator(`[data-orb-job-ids="gmail:${runId}"]`);
  assert.equal(await tile.count(),0,'An inbox needing its first review has no running job.');
  assert.equal(count('start','/chat.php'),0);

  review = inProgress('working');
  await page.reload();
  await page.locator('#chatInput').fill('Keep this draft');
  await tile.waitFor();
  assert.equal(await tile.evaluate(element => element.matches('button')),true);
  assert.equal(await tile.getAttribute('aria-expanded'),'false');
  assert.equal(await workerPanel.count(),0,'The worker detail opens only when requested.');
  await gmailWork.locator('[data-orb-work-tracks="1"]').waitFor();
  assert.equal(await orb.getAttribute('data-working'),'true');
  assert.equal(await orb.getAttribute('data-state'),'idle','Gmail work stays independent of the conversation state.');
  assert.equal(await hub.isVisible(),false,'Progress does not open Top of Mind automatically.');
  assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),false);
  assert.equal(await page.getByText('Existing conversation',{exact:true}).isVisible(),true);
  assert.equal(await page.locator('[data-gmail-review-state="working"]').isVisible(),false,'The desktop rail owns live progress.');
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft');
  await tile.click();
  await workerPanel.getByText('Working',{exact:true}).waitFor();
  await workerPanel.getByText('5 of up to 50 headers checked.',{exact:true}).waitFor();
  assert.equal(await tile.getAttribute('aria-expanded'),'true');
  assert.equal(await hub.isVisible(),false);
  assert.equal(await workerPanel.getByRole('button').count(),1,'Only Close appears in the live work detail.');
  await screenshot(page,'rail');
  review = {...inProgress('working'),next_attempt_at:new Date(Date.now()+1200).toISOString()};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await tile.waitFor({state:'detached',timeout:4500});
  await workerPanel.waitFor({state:'detached'});
  await gmailWork.waitFor({state:'detached'});
  review = inProgress('working');
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await tile.click();
  await workerPanel.waitFor();
  review = {state:'disabled',limit:50};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await tile.waitFor({state:'detached'});
  await workerPanel.waitFor({state:'detached'});
  await gmailWork.waitFor({state:'detached'});
  review = inProgress('working');
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await tile.waitFor();
  await page.setViewportSize({width:390,height:844});
  await tile.waitFor({state:'detached'});
  const compactWork = page.locator('#chatThread [data-gmail-review-state="working"]').getByRole('button',{name:'View work'});
  await compactWork.click();
  await workerPanel.getByText('5 of up to 50 headers checked.',{exact:true}).waitFor();
  review = {...inProgress('working'),scanned:6};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await workerPanel.getByText('6 of up to 50 headers checked.',{exact:true}).waitFor();
  await workerPanel.getByRole('button',{name:'Close',exact:true}).click();
  await workerPanel.waitFor({state:'detached'});
  assert.equal(await compactWork.evaluate(button => button === document.activeElement),true);
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  await job.getByText('Working',{exact:true}).waitFor();
  await page.evaluate(() => {
    window.fixtureParticipant = true;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await hub.waitFor({state:'hidden'});
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-working'),'false');
  assert.equal(await job.count(),0);
  await page.evaluate(() => {
    window.fixtureParticipant = false;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await gmailWork.waitFor({state:'attached'});
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  const chatAdvance = advanceGate = {started:deferred(),release:deferred()};
  review = inProgress('queued');
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await waitForGate(chatAdvance.started,'Chat advance');
  await page.locator('#chatThread [data-gmail-review-state="queued"]').waitFor();
  assert.equal(await page.evaluate(() => window.VP3ChatSpecialists.snapshot()
    .some(item => item.key === 'gmail-inbox-review')),false,'Queued Gmail work has no rail indicator.');
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-working'),'false');
  review = summarizing();
  await job.getByText('Preparing the AI summary.',{exact:true}).waitFor();
  await gmailWork.locator('[data-orb-work-tracks="1"]').waitFor();
  assert.equal(await orb.getAttribute('data-working'),'true');
  assert.equal(count('advance','/chat.php'),1,'The mobile status probe leaves the active slice alone.');
  await screenshot(page,'mobile');
  chatAdvance.release.resolve();
  await completion.waitFor();
  await completion.locator('time[datetime="2026-11-10T12:15:00.000Z"]').getByText(/^Posted /).waitFor();
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await page.evaluate(() => window.VP3ChatSpecialists.snapshot()
    .some(item => item.key === 'gmail-inbox-review')),false,'A completed review does not keep a duplicate rail entry.');
  assert.equal(await orb.getAttribute('data-working'),'false');
  assert.equal(await page.getByText(privateSubject,{exact:true}).isVisible(),false,'Chat keeps the private item list collapsed by default.');
  assert.equal(count('advance','/chat.php'),1);
  assert.equal(count('start','/chat.php'),0);
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft');
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  assert.equal(await completion.count(),1,'A refresh leaves one completion in the active thread.');
  review = {...completed(),inbox_working_until:new Date(Date.now()+60000).toISOString()};
  await page.evaluate(() => {
    document.body.dataset.stonefellowAgentState = 'listening';
    window.dispatchEvent(new Event('focus'));
  });
  const recurringWork = orb.locator('[data-orb-job-ids="gmail:inbox-updates"]');
  await recurringWork.waitFor();
  await page.waitForFunction(() => document.querySelector('.vp3-orb-root').dataset.state === 'listening');
  assert.equal(await orb.getAttribute('data-working'),'true','A verified new-mail lease is visible while the Agent listens.');
  assert.equal(await completion.count(),1);
  review = completed();
  await recurringWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-state'),'listening','Ending Gmail work preserves the foreground listening state.');
  await page.evaluate(() => { delete document.body.dataset.stonefellowAgentState; });
  await page.evaluate(() => {
    window.fixtureParticipant = true;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await completion.waitFor({state:'detached'});
  await page.evaluate(() => {
    window.fixtureParticipant = false;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await completion.waitFor();
  review = {state:'unavailable',limit:50};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await completion.waitFor({state:'detached'});
  await savedTurn.getByText('Your first Gmail inbox review is ready.',{exact:true}).waitFor();
  assert.equal(await savedTurn.getByText(privateSummary,{exact:true}).count(),0,'Revocation removes private details from the saved turn.');
  review = completed();
  console.log('PASS specialist progress, on-screen Chat completion, private report and revocation');

  await page.setViewportSize({width:1280,height:900});
  const late = statusGate = {started:deferred(),release:deferred()};
  await page.reload();
  await waitForGate(late.started,'held status');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT.userId = 2;
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
  });
  late.release.resolve();
  await page.waitForTimeout(200);
  assert.equal(await tile.count(),0,'A delayed response cannot restore the previous account’s specialist.');
  assert.equal(await completion.count(),0,'A delayed response cannot restore the previous account’s completion.');
  assert.equal(await hub.isVisible(),false);

  const beforeFallback = requests.length;
  await page.goto(origin+'/chat.php?without_review=1');
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  await job.getByText('Ready to read in Chat',{exact:true}).waitFor();
  assert.equal(await job.count(),1);
  assert.equal(await page.getByText(privateSubject,{exact:true}).count(),0);
  assert.equal(requests.length,beforeFallback,'Top of Mind can use its metadata projection alone.');
  await job.getByRole('button',{name:'View in Chat'}).click();
  await savedTurn.getByText('Your first Gmail inbox review is ready.',{exact:true}).waitFor();
  assert.equal(await page.getByText(privateSubject,{exact:true}).count(),0,'The saved turn is content-free without its owner review projection.');
  await page.goto(origin+connectionsUrl);
  await report.getByText(privateSubject,{exact:true}).waitFor();

  const startsBeforeStaleView = count('start');
  const reloadMessage = page.locator('[data-gmail-review-status]').filter({
    hasText:'Gmail or Inbox review settings changed. Reload to continue.',
  });
  apiViewToken = null;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await reloadMessage.waitFor();
  assert.equal(await report.textContent(),'','A missing refreshed token clears the previously rendered report.');

  review = needsStart();
  apiViewToken = startToken;
  await page.reload();
  await windowSelect.waitFor();
  apiViewToken = 'c'.repeat(64);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await reloadMessage.waitFor();
  await startForm.waitFor({state:'hidden'});
  await startForm.evaluate(form => form.requestSubmit());

  initialViewToken = null;
  apiViewToken = startToken;
  await page.reload();
  await reloadMessage.waitFor();
  await startForm.waitFor({state:'hidden'});
  await startForm.evaluate(form => form.requestSubmit());
  await page.waitForTimeout(100);
  assert.equal(count('start'),startsBeforeStaleView,'Only a page loaded with the matching settings token can start a review.');

  // The owner can leave Chat open in another window while starting in Connections.
  delivered = null;
  review = needsStart();
  initialViewToken = startToken;
  workerStartsReview = true;
  const beforeNewWindow = page.waitForResponse(response => response.url().endsWith(endpoint));
  await page.goto(origin+'/chat.php');
  await beforeNewWindow;
  const reviewWindow = await context.newPage();
  await reviewWindow.goto(origin+connectionsUrl);
  await reviewWindow.locator('[data-gmail-review-start] select').selectOption('14');
  await reviewWindow.locator('[data-gmail-review-start] button').click();
  await tile.waitFor({timeout:5000});
  await gmailWork.waitFor({timeout:5000});
  assert.equal(await orb.getAttribute('data-working'),'true');
  await reviewWindow.close();
  await page.reload();
  await tile.waitFor();
  await gmailWork.waitFor();
  review = completed();
  await completion.getByText(privateSummary,{exact:true}).waitFor();
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-working'),'false','The completed review has no synthetic Working state.');
  console.log('PASS real Chat boot observes a separately started review and restores live state after reload');

  delivered = null;
  const beforeWelcome = {new:newConversations,deliver:count('deliver'),advance:count('advance')};
  await page.evaluate(() => localStorage.clear());
  await page.goto(origin+'/chat.php?empty_chat=1');
  await savedTurn.getByText(privateSummary,{exact:true}).waitFor();
  assert.deepEqual({new:newConversations,deliver:count('deliver'),advance:count('advance')},
    {new:beforeWelcome.new+1,deliver:beforeWelcome.deliver+1,advance:beforeWelcome.advance},
    'A ready review uses the normal welcome Chat creation and never rescans the mailbox.');
  assert.equal(await completion.count(),1);

  // Older Chat assets preserve the stored message identity but omit the Gmail projection.
  delivered = null;
  const beforeLegacyChat = {deliver:count('deliver'),advance:count('advance')};
  await page.goto(origin+'/chat.php?legacy_chat=1');
  await savedTurn.getByText(privateSummary,{exact:true}).waitFor();
  assert.equal(await savedTurn.evaluate(row => row._vp3GmailInboxReviewV1),undefined);
  assert.equal(await completion.count(),1);
  assert.equal(count('deliver'),beforeLegacyChat.deliver+1);
  await page.reload();
  await savedTurn.getByText(privateSummary,{exact:true}).waitFor();
  assert.equal(await page.locator('#chatThread .message').count(),2);
  assert.equal(await completion.count(),1);
  assert.equal(count('deliver'),beforeLegacyChat.deliver+2,'The old asset reads the existing delivery anchor on reload.');
  assert.equal(count('advance'),beforeLegacyChat.advance,'Recovering the anchor never rescans Gmail.');
  review = {state:'disabled',limit:50};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await completion.waitFor({state:'detached'});
  await savedTurn.getByText('Your first Gmail inbox review is ready.',{exact:true}).waitFor();
  console.log('PASS a legacy Chat projection shows one verified delivery and removes it when Inbox Review is disabled');

  const fullItems = Array.from({length:10},(_,index) => ({subject:`Saved email ${index+1}`,from:'same@example.test',
    category:'new',item_ref:(1000+index).toString(16).padStart(64,'0')}));
  review = completed();
  review.total = review.report.total = 10;
  review.report.items = review.chat_result.items = fullItems;
  delete review.chat_result.latest;
  const batchPost = (id,subject,item_ref) => {
    const run_id = id.toString(16).padStart(32,'0');
    return {kind:'batch',scope_ref:batchScope,run_id,report_ref:id.toString(16).padStart(64,'0'),agent_id:7,
      conversation_id:42,message_id:id,review:{state:'ready',run_id,total:1,important:0,replies:0,
        at:'2026-11-10T15:00:00Z',items:[{subject,from:'same@example.test',category:'new',item_ref}]}};
  };
  pendingUpdate = batchPost(100,'First saved batch · Past due','7'.repeat(64));
  pendingUpdate.review.items[0].priority_signals = ['past due'];
  const beforeUpdates = {deliver:count('deliver_updates'),advance:count('advance')};
  await page.goto(origin+'/chat.php');
  const firstList = completion.getByText('Messages in first review (10)',{exact:true});
  await firstList.click();
  assert.equal(await completion.locator('.gmail-review-items > li').count(),8);
  await completion.getByRole('button',{name:'Show next 2 messages'}).click();
  assert.equal(await completion.locator('.gmail-review-items > li').count(),10);
  await completion.getByRole('listitem').filter({hasText:'Saved email 10'}).getByRole('button',{name:'Open',exact:true}).click();
  await completion.getByText(privateBody,{exact:true}).waitFor();
  assert.equal(requests.filter(row => row.action === 'open_item').at(-1).item_ref,fullItems[9].item_ref);
  const cards = page.locator('[data-gmail-inbox-update]');
  await cards.first().getByText('Messages in inbox update (1)',{exact:true}).click();
  await cards.first().locator('time[datetime="2026-11-10T15:20:00.000Z"]').getByText(/^Posted /).waitFor();
  await cards.first().getByText('First saved batch · Past due',{exact:true}).waitFor();
  assert.equal(await cards.first().getByText('1 message with priority keywords in the subject.',{exact:true}).isVisible(),true);
  assert.equal(await cards.first().getByText('Priority keywords in subject · past due',{exact:true}).isVisible(),true);
  batchPosts.push(batchPost(101,'Second saved batch','8'.repeat(64)));
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await cards.nth(1).getByText('Messages in inbox update (1)',{exact:true}).click();
  await cards.nth(1).getByText('Second saved batch',{exact:true}).waitFor();
  assert.equal(await cards.first().getByText('First saved batch · Past due',{exact:true}).count(),1);
  await page.reload();
  await cards.nth(1).waitFor();
  await cards.first().getByText('Messages in inbox update (1)',{exact:true}).click();
  await cards.first().getByRole('button',{name:'Open',exact:true}).click();
  await cards.first().getByText(privateBody,{exact:true}).waitFor();
  assert.equal(requests.filter(row => row.action === 'open_item').at(-1).item_ref,'7'.repeat(64));
  assert.deepEqual({deliver:count('deliver_updates'),advance:count('advance')},
    {deliver:beforeUpdates.deliver+1,advance:beforeUpdates.advance});
  showMonitor = true;
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  const monitor = hub.locator('[data-activity-specialist="gmail-inbox-monitor"]');
  await monitor.getByText('Last check completed.',{exact:true}).waitFor();
  assert.equal(await monitor.getByText(/^Last check /).count(),2);
  assert.equal(await monitor.getByText(/^Next check /).count(),1);
  assert.equal(await monitor.getByText('An inbox update is waiting to post to Chat.').count(),1);
  await monitor.getByRole('button',{name:'View latest update'}).click();
  await hub.waitFor({state:'hidden'});
  await page.locator('[data-chat-message-id="101"][data-chat-conversation-id="42"]').waitFor();
  showMonitor = false;

  const priorChecks = count('check_overdue');
  const priorStarts = count('start');
  const priorAdvances = count('advance');
  const firstOverdueRef = 'a'.repeat(64);
  const refreshInbox = async action => {
    const response = page.waitForResponse(reply => reply.url().endsWith(endpoint) && reply.request().postDataJSON()?.action === action);
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    return response;
  };
  const backgroundTimes = {last_checked_at:'2026-11-10T15:00:00Z',next_check_at:new Date(Date.now()-180000).toISOString()};
  inboxBackground = {state:'checked',...backgroundTimes,check_ref:firstOverdueRef};
  await refreshInbox('status');
  await page.waitForTimeout(150);
  assert.equal(count('check_overdue'),priorChecks,'A current inbox check is read only.');

  inboxBackground = {state:'overdue',...backgroundTimes,check_ref:firstOverdueRef};
  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'saved';
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
  });
  await refreshInbox('status');
  await page.waitForTimeout(150);
  assert.equal(count('check_overdue'),priorChecks,'A non-Chat view cannot recover an overdue scan.');

  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'chat';
    const live = window.STONEFELLOW_CHAT_CONTINUITY_V87;
    window.fixtureOriginalBusy = live.isBusy;
    live.isBusy = () => true;
  });
  await refreshInbox('status');
  await page.waitForTimeout(150);
  assert.equal(count('check_overdue'),priorChecks,'An active Chat request leaves overdue work alone.');

  review = inProgress('working');
  await page.evaluate(() => { window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy = window.fixtureOriginalBusy; });
  await refreshInbox('status');
  await tile.waitFor();
  assert.equal(count('check_overdue'),priorChecks,'The first review owns Gmail work while its slice is active.');

  review = completed();
  checkOverdue = () => ({status:503,json:{ok:false}});
  assert.equal((await refreshInbox('check_overdue')).status(),503);
  await completion.getByText('First inbox review',{exact:true}).waitFor();
  await completion.locator('time[datetime="2026-11-10T12:15:00.000Z"]').waitFor();
  await refreshInbox('status');
  await page.waitForTimeout(150);
  assert.equal(count('check_overdue'),priorChecks+1,'Repeated status or focus cannot immediately retry the same reference.');

  checkOverdue = () => {
    batchPosts.push(batchPost(102,'Inbox mail from the overdue check','0'.repeat(64)));
    return {json:{ok:true,review:project(false,undefined,true)}};
  };
  await page.evaluate(() => {
    window.fixtureRealNow = Date.now;
    Date.now = () => window.fixtureRealNow()+61000;
  });
  assert.equal((await refreshInbox('check_overdue')).ok(),true);
  const overdueResult = page.locator('[data-chat-message-id="102"][data-chat-conversation-id="42"] [data-gmail-inbox-update]');
  await overdueResult.getByText('Messages in inbox update (1)',{exact:true}).click();
  await overdueResult.getByText('Inbox mail from the overdue check',{exact:true}).waitFor();
  assert.equal(await completion.count(),1,'The current batch appears separately from the first import.');
  assert.equal(count('start'),priorStarts);
  assert.equal(count('advance'),priorAdvances);
  await page.evaluate(() => { Date.now = () => window.fixtureRealNow()+122000; });
  await refreshInbox('status');
  await page.waitForTimeout(150);
  assert.equal(count('check_overdue'),priorChecks+2,'Repeated attempts on a stalled reference back off.');

  await page.evaluate(() => { Date.now = () => window.fixtureRealNow()+362000; });
  checkOverdue = () => {
    inboxBackground = {state:'checked',...backgroundTimes};
    return {json:{ok:true,review:project(false,undefined,true)}};
  };
  assert.equal((await refreshInbox('check_overdue')).ok(),true,'A stalled reference can recover after the longer cooldown.');
  await page.evaluate(() => { Date.now = window.fixtureRealNow; });
  assert.equal(count('check_overdue'),priorChecks+3);
  console.log('PASS overdue Gmail check is owner-Chat scoped, bounded, and keeps yesterday’s first review');

  review = {state:'disabled',limit:50};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await cards.first().waitFor({state:'detached'});
  assert.equal(await page.getByText('Your Gmail inbox update is ready.',{exact:true}).count(),3);
  console.log('PASS all first-review rows are pageable and separate inbox updates remain actionable after reload');

  review = needsStart();
  inboxBackground = {state:'overdue',...backgroundTimes,check_ref:'b'.repeat(64)};
  const beforeEmptyCheck = {new:newConversations,start:count('start'),deliver:count('deliver'),updates:count('deliver_updates')};
  await page.evaluate(() => localStorage.clear());
  const emptyCheck = page.waitForResponse(reply => reply.url().endsWith(endpoint) && reply.request().postDataJSON()?.action === 'check_overdue');
  await page.goto(origin+'/chat.php?empty_chat=1');
  assert.equal((await emptyCheck).ok(),true);
  assert.equal(await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()),0);
  assert.deepEqual({new:newConversations,start:count('start'),deliver:count('deliver'),updates:count('deliver_updates')},beforeEmptyCheck,
    'Checking Gmail from the empty private Chat creates no conversation or review.');

  assert.deepEqual(errors,[]);
  console.log('GMAIL_INBOX_INITIAL_UI_BROWSER=PASS (actual Connections, private report, progress and stale settings)');
} finally { await browser.close(); }
