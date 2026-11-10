import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const origin = 'https://gmail-review.test';
const endpoint = '/api/gmail-inbox-initial-v1.php';
const runId = 'a'.repeat(32);
const privateSubject = 'Private budget <img src=x onerror=alert(1)>';
const connectionsUrl = '/connections.php?app=gmail#gmail-inbox-review';
const script = 'gmail-inbox-initial-v1.js';
const assets = new Set(['chat.css','connections-v1.css','chat-specialist-rail-v1.css',
  'agent-activity-hub-v1.css','agent-orb.css','agent-orb-particles.css','chat-specialist-rail-v1.js','agent-activity-hub-v1.js',
  'agent-eyes-core.js','agent-orb.js','agent-orb-particles.js',script]);
const chatScripts = ['agent-eyes-core.js','chat-specialist-rail-v1.js','agent-activity-hub-v1.js','agent-orb.js','agent-orb-particles.js',script];
const phpFixture = fileURLToPath(new URL('fixtures/gmail-inbox-initial-page-v1.php', import.meta.url));
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
    items:[{subject:privateSubject,from:'Sender <private@example.test>',category:'reply'}]}});
let review = needsStart();
let advanceGate = null;
let statusGate = null;
const requests = [];
const count = (action,surface) => requests.filter(row => row.action === action && (!surface || row.surface === surface)).length;
const project = includeReport => {
  const view = structuredClone(review);
  if (!includeReport) delete view.report;
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
<style>#sfOnlineRailV109{position:fixed;right:4px;top:20px}</style>
<main><section id="chatThread" data-view="chat"><article>Existing conversation</article></section>
<form id="chatForm"><textarea id="chatInput" aria-label="Message"></textarea><button id="sendChatButton">Send</button></form></main>
<aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>
<script>
window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'review-fixture'};
window.STONEFELLOW_CHAT_CONTINUITY=window.STONEFELLOW_CHAT_CONTINUITY_V87={
  agentId:()=>7,conversationId:()=>42,isReady:()=>true,isRestoring:()=>false
};
window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'review-fixture',activityEndpoint:'/api/hub'};
window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',isMainChat:()=>true,state:()=>({state:'idle'}),
  conversationRef:()=>({id:42,agent:7}),focusChat:()=>document.getElementById('chatInput').focus()};
window.VP3ParticipantContinuity={selectedContext:()=>window.fixtureParticipant?{participant_id:2}:null};
window.VP3_GMAIL_INBOX_INITIAL_V1={endpoint:${JSON.stringify(endpoint)},csrf:'fixture',ownerKey:'1',agentId:7,
  connectionsUrl:${JSON.stringify(connectionsUrl)}};
</script>
${chatScripts.map(name => `<script src="/${name}" defer></script>`).join('')}`;

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
try {
  const page = await browser.newPage({viewport:{width:1280,height:900}});
  page.setDefaultTimeout(15000);
  const errors = [];
  page.on('pageerror', error => { errors.push(error.message); if (process.env.VP3_TEST_DEBUG) console.error(error.message); });
  await page.route(origin+'/**',async route => {
    const request = route.request();
    const url = new URL(request.url());
    const file = url.pathname.slice(1);
    if (assets.has(file)) {
      return route.fulfill({contentType:file.endsWith('.css')?'text/css':'text/javascript',
        body:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
    }
    if (url.pathname === '/connections.php') {
      return route.fulfill({contentType:'text/html; charset=utf-8',
        body:execFileSync('php',[phpFixture,url.search.slice(1),JSON.stringify(review)],{encoding:'utf8'})});
    }
    if (url.pathname === '/chat.php') {
      const body = url.searchParams.has('without_review')
        ? chatMarkup.replace(`<script src="/${script}" defer></script>`,'') : chatMarkup;
      return route.fulfill({contentType:'text/html; charset=utf-8',body});
    }
    if (url.pathname === '/api/hub') {
      const detail = review.state === 'ready' ? 'Ready · View the saved review in Connections'
        : review.stage === 'summary' ? 'Working · Preparing the AI summary' : 'Working · Checking Gmail headers';
      const sections = review.state === 'needs_start' ? [] : [{id:'gmail_inbox',label:'Gmail Inbox Review',items:[{
        id:'gmail:first-review',kind:'gmail_inbox_review',title:'Gmail Inbox Review',detail,
        actions:[{kind:'url',label:'Open Connections',href:connectionsUrl}],
      }]}];
      return route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,
        conversation_id:42,working_attention_binding:'review-fixture',agent_name:'Longfellow',
        observed_at:new Date().toISOString(),sections}}});
    }
    if (url.pathname !== endpoint) return route.fulfill({status:404,body:''});
    assert.equal(request.method(),'POST');
    const body = request.postDataJSON();
    const surface = new URL(request.frame().url()).pathname;
    const includeReport = surface === '/connections.php';
    const expected = {action:body.action,owner_key:'1',csrf_token:'fixture',...(includeReport ? {include_report:true} : {})};
    if (body.action === 'start') Object.assign(expected,{days:14});
    if (body.action === 'advance') Object.assign(expected,{run_id:runId});
    assert.deepEqual(body,expected,`${surface} sends only its allowed review fields.`);
    requests.push({...body,surface});
    let view;
    if (body.action === 'status' && statusGate) {
      const gate = statusGate;
      statusGate = null;
      view = project(includeReport);
      gate.started.resolve();
      await gate.release.promise;
    } else if (body.action === 'start') {
      assert.equal(review.state,'needs_start','A run is created only once.');
      review = inProgress('queued');
    } else if (body.action === 'advance') {
      const gate = advanceGate;
      assert.ok(gate,'A bounded advance may run once for this fixture step.');
      advanceGate = null;
      gate.started.resolve();
      await gate.release.promise;
      review = completed();
    } else assert.equal(body.action,'status');
    try { await route.fulfill({json:{ok:true,review:view ?? project(includeReport)}}); }
    catch (error) { if (!view) throw error; /* Scope changes can abort a held status. */ }
  });

  await page.goto(origin+connectionsUrl);
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
  assert.equal(await report.getByText('Reply',{exact:true}).isVisible(),true);
  assert.equal(await report.locator('img,script').count(),0,'Report metadata renders as text.');
  await page.reload();
  await report.getByText(privateSubject,{exact:true}).waitFor();
  await screenshot(page,'report',report);
  assert.equal(count('start','/connections.php'),1);
  assert.equal(count('advance','/connections.php'),1,'Refreshing a completed report does not scan again.');
  console.log('PASS actual Connections window selection, explicit start, bounded progress and escaped retained report');

  review = needsStart();
  const initialStatus = page.waitForResponse(response => response.url().endsWith(endpoint) && response.request().postDataJSON()?.action === 'status');
  await page.goto(origin+'/chat.php');
  await initialStatus;
  const tile = page.locator('[data-chat-specialist="gmail-inbox-review"]');
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
  await gmailWork.locator('[data-orb-work-tracks="1"]').waitFor();
  assert.equal(await orb.getAttribute('data-working'),'true');
  assert.equal(await orb.getAttribute('data-state'),'idle','Gmail work stays independent of the conversation state.');
  assert.equal(await hub.isVisible(),false,'Progress does not open Top of Mind automatically.');
  assert.equal(await page.locator('#vp3AgentChatPopup').isVisible(),false);
  assert.equal(await page.locator('#chatThread').innerText(),'Existing conversation');
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft');
  await tile.click();
  await job.getByText('Working',{exact:true}).waitFor();
  await hub.getByText('Longfellow',{exact:true}).waitFor();
  assert.equal(await job.count(),1,'The Hub projection and live specialist share one row.');
  assert.equal(await hub.getByText('Gmail Inbox Review',{exact:true}).count(),1);
  await screenshot(page,'rail');
  assert.equal(await page.locator('[data-chat-specialist-panel]').count(),0,'The rail uses the existing Top of Mind pane.');
  await hub.getByRole('button',{name:'Close',exact:true}).click();
  await page.setViewportSize({width:390,height:844});
  await tile.waitFor({state:'detached'});
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
  await job.getByText('Queued',{exact:true}).waitFor();
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-working'),'false');
  review = summarizing();
  await job.getByText('Preparing the AI summary.',{exact:true}).waitFor();
  await gmailWork.locator('[data-orb-work-tracks="1"]').waitFor();
  assert.equal(await orb.getAttribute('data-working'),'true');
  assert.equal(count('advance','/chat.php'),1,'The mobile status probe leaves the active slice alone.');
  await screenshot(page,'mobile');
  chatAdvance.release.resolve();
  await job.getByText('Ready',{exact:true}).waitFor();
  await gmailWork.waitFor({state:'detached'});
  assert.equal(await orb.getAttribute('data-working'),'false');
  assert.equal(await page.getByText(privateSubject,{exact:true}).count(),0,'Chat never renders private report metadata.');
  assert.equal(count('advance','/chat.php'),1);
  assert.equal(count('start','/chat.php'),0);
  assert.equal(await page.locator('#chatInput').inputValue(),'Keep this draft');
  console.log('PASS desktop specialist, mobile Top of Mind, no unsolicited Chat content and participant privacy');

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
  assert.equal(await hub.isVisible(),false);

  const beforeFallback = requests.length;
  await page.goto(origin+'/chat.php?without_review=1');
  await page.locator('.vp3-orb-ball').hover();
  await page.locator('[data-orb-activity]').click();
  await job.getByText(/Ready · View the saved review/).waitFor();
  assert.equal(await job.count(),1);
  assert.equal(await page.getByText(privateSubject,{exact:true}).count(),0);
  assert.equal(requests.length,beforeFallback,'Top of Mind can use its metadata projection alone.');
  await job.getByRole('button',{name:'Open Connections'}).click();
  await page.waitForURL(origin+connectionsUrl);
  await report.getByText(privateSubject,{exact:true}).waitFor();
  assert.deepEqual(errors,[]);
  console.log('GMAIL_INBOX_INITIAL_UI_BROWSER=PASS (actual Connections, summary progress, deduplicated Hub and private report)');
} finally { await browser.close(); }
