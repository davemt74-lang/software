import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const script = await fs.readFile(new URL('../chat-cognitive-presentation-v510.js', import.meta.url), 'utf8');
const browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ?
  {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const context = await browser.newContext();
const requests = [];
const errors = [];
let nextReply = null;
let sessionBinding = 'owner-a-session';
let item = {title:'<img src=x onerror="window.unsafe=true"> Review blocked Mission',
  summary:'A required approval is waiting.',href:'/missions.php?mission=12',action_label:'Missions'};
let urgent = false;

await context.route('http://working.test/**', async route => {
  const request = route.request();
  const url = new URL(request.url());
  if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`
    <button id="chatAgentBriefButton">Agent Brief</button>
    <aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div></aside>
    <div id="chatThread"></div><form id="chatForm"><textarea id="chatInput"></textarea></form>`});
  if (url.pathname !== '/api/cognitive-presentation-v510.php') return route.fulfill({status:404});
  if (request.method() === 'POST') return route.fulfill({json:{ok:true}});
  const binding = request.headers()['x-vp3-working-attention'] || '';
  requests.push({binding,agent:url.searchParams.get('agent_id')});
  const brief = {agent_name:'VP3 Agent',status_label:'Active',current_state_presentation:{
    type:urgent?'attention':'status',title:urgent?'Existing urgent state':'Current state'}};
  if (binding && binding !== sessionBinding) return route.fulfill({status:403,json:{ok:false,error:'account_changed'}});
  if (binding) brief.working_attention = item;
  const wait = nextReply;
  nextReply = null;
  if (wait) {
    wait.arrive();
    if (await wait.reply === 'offline') return route.abort('internetdisconnected');
  }
  return route.fulfill({json:{ok:true,state:{brief}}});
});

function delayReply() {
  let finish, arrive;
  const reply = new Promise(resolve => { finish = resolve; });
  const arrived = new Promise(resolve => { arrive = resolve; });
  nextReply = {reply,arrive};
  return {finish,arrived};
}

async function openChat() {
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('http://working.test/');
  await page.evaluate(() => {
    window.VP3_COGNITIVE_PRESENTATION_V510 = {agentId:17,workingAttentionBinding:'owner-a-session'};
    window.speechCount = 0;
    window.STONEFELLOW_NOTIFICATION_CENTER = {announce:() => { window.speechCount += 1; return true; }};
    window.submitted = '';
    document.querySelector('#chatForm').addEventListener('submit', event => {
      event.preventDefault();
      window.submitted = document.querySelector('#chatInput').value;
    });
  });
  await page.addScriptTag({content:script});
  return page;
}

try {
  const initial = delayReply();
  const page = await openChat();
  await initial.arrived;
  await page.getByRole('button',{name:'Agent Brief'}).click();
  initial.finish();
  const card = page.locator('[data-agent-brief-working-attention]');
  await card.waitFor();
  assert.deepEqual(requests,[{binding:'',agent:'17'},{binding:'owner-a-session',agent:'17'}]);
  assert.equal(await card.locator('strong').textContent(),item.title);
  assert.equal(await card.locator('img').count(),0);
  assert.equal(await card.getByRole('link',{name:'Missions'}).getAttribute('href'),item.href);
  assert.equal(await page.getByText('Current state',{exact:true}).count(),0);
  assert.equal(await page.locator('#chatThread').textContent(),'');
  console.log('PASS opening while background state loads fetches one work item and does not add a Chat message');

  urgent = true;
  item = {title:'Stale call follow-up',href:'/\\external.test',action_label:'Call activity'};
  const stale = delayReply();
  const refreshing = page.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  await stale.arrived;
  await page.getByRole('button',{name:/VP3 Agent.*Active/}).click();
  assert.equal(await card.count(),0);
  await page.getByRole('button',{name:/VP3 Agent.*Active/}).click();
  item = {title:'Prepare for the upcoming meeting',summary:'Starts in 15 minutes.',href:'/\\external.test',action_label:'Calendar'};
  stale.finish();
  await refreshing;
  await card.getByText(item.title,{exact:true}).waitFor();
  assert.equal(await page.getByText('Stale call follow-up',{exact:true}).count(),0);
  assert.equal(await page.getByText('Existing urgent state',{exact:true}).count(),1);
  assert.equal(await card.getByRole('link').count(),0);
  console.log('PASS a stale response cannot fill a reopened brief; the existing urgent state stays visible');

  const offline = delayReply();
  const goingOffline = page.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  await offline.arrived;
  offline.finish('offline');
  await goingOffline;
  assert.equal(await card.count(),0);
  await page.getByRole('button',{name:'Retry'}).click();
  await card.getByText(item.title,{exact:true}).waitFor();
  await card.getByRole('button',{name:'Catch me up on work'}).click();
  assert.equal(await page.evaluate(() => window.submitted),'Catch me up on work');
  assert.equal(await card.count(),0);
  console.log('PASS network interruption clears the previous item; retry and typed Catch me up use existing controls');

  const otherTab = await openChat();
  await otherTab.getByRole('button',{name:/VP3 Agent.*Active/}).waitFor();
  assert.equal(await otherTab.locator('[data-agent-brief-working-attention]').count(),0);
  sessionBinding = 'owner-b-session';
  await otherTab.getByRole('button',{name:/VP3 Agent.*Active/}).click();
  await otherTab.getByText('Your session changed. Reload Chat to see your current brief.').waitFor();
  assert.equal(await otherTab.locator('[data-agent-brief-working-attention]').count(),0);
  assert.equal(await page.evaluate(() => window.speechCount),0);
  assert.equal(await otherTab.evaluate(() => window.speechCount),0);
  assert.deepEqual(errors,[]);
  console.log('PASS another tab performs no working read until opened and a changed session receives no item');
} finally {
  await browser.close();
}
