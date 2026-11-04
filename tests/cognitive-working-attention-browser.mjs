import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const script = await fs.readFile(new URL('../chat-cognitive-presentation-v510.js', import.meta.url), 'utf8');
const browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ?
  {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const context = await browser.newContext();
const requests = [];
const writes = [];
const errors = [];
let nextReply = null;
let sessionBinding = 'owner-a-session';
let item = {title:'<img src=x onerror="window.unsafe=true"> Review blocked Mission',
  summary:'A required approval is waiting.',href:'/missions.php?mission=12',action_label:'Missions'};
let urgent = false;
let presentation = {};

await context.route('http://working.test/**', async route => {
  const request = route.request();
  const url = new URL(request.url());
  if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`
    <button id="chatAgentBriefButton">Agent Brief <em data-agent-attention-badge hidden></em></button>
    <aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div>
      <footer><button type="button" data-agent-brief-brain>Agent Brain</button></footer></aside>
    <div id="chatThread"></div><form id="chatForm"><textarea id="chatInput"></textarea></form>`});
  if (url.pathname !== '/api/cognitive-presentation-v510.php') return route.fulfill({status:404});
  const binding = request.headers()['x-vp3-working-attention'] || '';
  const body = request.method() === 'POST' ? request.postDataJSON() : null;
  const working = url.searchParams.get('include_working_attention');
  const preview = url.searchParams.get('greeting_preview');
  requests.push(body ? {binding,action:body.action} : {binding,agent:url.searchParams.get('agent_id'),working,preview});
  if (!binding || binding !== sessionBinding) return route.fulfill({status:403,json:{ok:false,error:'account_changed'}});
  if (body) {
    writes.push(body.action);
    return route.fulfill({json:{ok:true}});
  }
  const brief = {agent_name:'VP3 Agent',status_label:'Active',current_state_presentation:{
    type:urgent?'attention':'status',title:urgent?'Existing urgent state':'Current state'}};
  if (working === '1') brief.working_attention = item;
  if (working === '1' && preview === '1') brief.greeting_preview = item
    ? {decision:'would_mention',reason:'linked_meeting_followup'}
    : {decision:'quiet',reason:'recent_interaction'};
  const state = {...presentation,brief:{...brief,...presentation.brief}};
  const wait = nextReply;
  nextReply = null;
  if (wait) {
    wait.arrive();
    if (await wait.reply === 'offline') return route.abort('internetdisconnected');
  }
  return route.fulfill({json:{ok:true,state}});
});

function delayReply() {
  let finish, arrive;
  const reply = new Promise(resolve => { finish = resolve; });
  const arrived = new Promise(resolve => { arrive = resolve; });
  nextReply = {reply,arrive};
  return {finish,arrived};
}

async function openChat({agentId=17,greetingPreview=false}={}) {
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('http://working.test/');
  await page.evaluate(({agentId,greetingPreview}) => {
    window.VP3_COGNITIVE_PRESENTATION_V510 = {agentId,workingAttentionBinding:'owner-a-session',greetingPreview};
    window.speechCount = 0;
    window.cancelSpeechCount = 0;
    window.brainOpenCount = 0;
    window.STONEFELLOW_NOTIFICATION_CENTER = {
      announceWhen: (text,canSpeak) => new Promise(resolve => { window.pendingSpeech={text,canSpeak,resolve}; }),
      cancelSpeech: () => { window.cancelSpeechCount++; window.pendingSpeech?.resolve(false); },
      openBrain: () => { window.brainOpenCount++; }
    };
  },{agentId,greetingPreview});
  await page.addScriptTag({content:script});
  return page;
}

try {
  const initial = delayReply();
  const page = await openChat({greetingPreview:true});
  await initial.arrived;
  await page.getByRole('button',{name:'Agent Brief'}).click();
  initial.finish();
  const card = page.locator('[data-agent-brief-working-attention]');
  await card.waitFor();
  assert.deepEqual(requests,[
    {binding:'owner-a-session',agent:'17',working:'0',preview:null},
    {binding:'owner-a-session',agent:'17',working:'1',preview:'1'}
  ]);
  assert.equal(await card.locator('strong').textContent(),item.title);
  assert.equal(await card.locator('img').count(),0);
  assert.equal(await card.getByRole('link',{name:'Missions'}).getAttribute('href'),item.href);
  assert.equal(await page.getByText('Current state',{exact:true}).count(),0);
  assert.match(await page.locator('[data-agent-greeting-preview]').textContent(),/update may be worth mentioning/i);
  assert.match(await page.locator('[data-agent-greeting-preview]').textContent(),/upcoming meeting has an open follow-up/i);
  assert.equal(await page.locator('#chatThread').textContent(),'');
  console.log('PASS opening while background state loads fetches one work item and does not add a Chat message');

  urgent = true;
  item = {title:'Stale call follow-up',href:'/\\external.test',action_label:'Call activity'};
  const stale = delayReply();
  const refreshing = page.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  await stale.arrived;
  await page.getByRole('button',{name:/VP3 Agent.*Active/}).click();
  assert.equal(await card.count(),0);
  assert.equal(await page.locator('[data-agent-greeting-preview]').count(),0);
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
  assert.equal(await card.getByRole('button',{name:/Catch me up/}).count(),0);
  console.log('PASS network interruption clears the previous item and retry restores the optional working preview');

  item = null;
  await page.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  assert.match(await page.locator('[data-agent-greeting-preview]').textContent(),/stay quiet for now/i);
  assert.match(await page.locator('[data-agent-greeting-preview]').textContent(),/you were here recently/i);
  assert.equal(await page.evaluate(() => window.pendingSpeech),undefined);
  console.log('PASS the opened experiment reports a dry decision only');

  presentation = {
    brief:{agent_name:'Owner A Agent',attention_count:3},
    digest:{id:'owner-a-digest',summary:'Owner A digest',items:[{title:'Owner A task'}]},
    homeserver_presence:{id:14,body:'Owner A HomeServer'},
    voice_candidate:{through_id:14,message:'Owner A announcement'}
  };
  item = {title:'Owner A working context'};
  const closedTab = await openChat({agentId:0});
  await closedTab.getByText('Owner A digest',{exact:true}).waitFor();
  assert.equal(await closedTab.locator('[data-agent-brief-working-attention]').count(),0);
  await closedTab.waitForFunction(() => window.pendingSpeech?.canSpeak());
  assert.equal(await closedTab.getByText('Owner A HomeServer',{exact:true}).count(),1);
  await closedTab.locator('#chatForm').dispatchEvent('submit');
  await closedTab.waitForFunction(() => !document.hidden);
  for(let n=0;n<20&&!writes.includes('interaction');n++)await closedTab.waitForTimeout(25);
  assert.ok(writes.includes('interaction'));
  assert.ok(requests.some(record => record.binding==='owner-a-session' && record.action==='interaction'));

  const errorText = 'Your session changed. Reload Chat to see your current brief.';
  sessionBinding = 'owner-b-session';
  presentation = {
    brief:{agent_name:'Owner B Agent',attention_count:9},
    digest:{id:'owner-b-digest',summary:'Owner B digest',items:[]},
    homeserver_presence:{id:19,body:'Owner B HomeServer'},
    voice_candidate:{through_id:19,message:'Owner B announcement'}
  };
  await closedTab.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  assert.equal(await closedTab.getByText('Owner A digest',{exact:true}).count(),0);
  assert.equal(await closedTab.getByText('Owner A HomeServer',{exact:true}).count(),0);
  assert.equal(await closedTab.locator('[data-agent-attention-badge]').textContent(),'');
  await closedTab.getByRole('button',{name:/Agent.*status unavailable/}).click();
  await closedTab.getByText(errorText).waitFor();
  assert.equal(await closedTab.getByText(/Owner B/).count(),0);
  assert.deepEqual(await closedTab.evaluate(() => ({speech:window.pendingSpeech?.text,canSpeak:window.pendingSpeech?.canSpeak(),cancels:window.cancelSpeechCount})),
    {speech:'Owner A announcement',canSpeak:false,cancels:1});
  console.log('PASS cross-account closed System Agent poll clears digest, HomeServer, badge, and queued speech');

  sessionBinding = 'owner-a-session';
  presentation = {
    brief:{agent_name:'Owner A Agent',attention_count:3},
    digest:{id:'owner-a-digest',summary:'Owner A digest',items:[{title:'Owner A task'}]},
    homeserver_presence:{id:14,body:'Owner A HomeServer'},
    voice_candidate:{through_id:14,message:'Owner A announcement'}
  };
  const openTab = await openChat({agentId:0,greetingPreview:true});
  await openTab.getByRole('button',{name:/Owner A Agent.*Active/}).click();
  await openTab.locator('[data-agent-greeting-preview]').waitFor();
  const late = delayReply();
  const pending = openTab.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  await late.arrived;
  sessionBinding = 'owner-a-rotated-session';
  await openTab.getByRole('button',{name:'Dismiss'}).click();
  await openTab.getByRole('button',{name:/Agent.*status unavailable/}).waitFor();
  if (await openTab.locator('#chatAgentBriefPopover').isHidden()) {
    await openTab.getByRole('button',{name:/Agent.*status unavailable/}).click();
  }
  await openTab.getByText(errorText).waitFor();
  late.finish();
  await pending;
  assert.equal(await openTab.getByText(/Owner A/).count(),0);
  assert.equal(await openTab.locator('[data-agent-brief-working-attention],[data-agent-greeting-preview],[data-vp3-return-digest]').count(),0);
  assert.equal(await openTab.evaluate(() => window.pendingSpeech?.canSpeak()),false);
  assert.equal(await openTab.getByRole('button',{name:'Retry'}).count(),0);
  await openTab.locator('[data-agent-brief-brain]').dispatchEvent('click');
  assert.equal(await openTab.evaluate(() => window.brainOpenCount),0);
  assert.ok(requests.some(record => record.binding==='owner-a-session' && record.action==='digest_ack'));
  assert.ok(!writes.includes('digest_ack'));
  console.log('PASS a rotated session rejects stale writes, aborts in-flight data, and disables stale Brief actions');

  sessionBinding = 'owner-a-session';
  const crossAccountOpenTab = await openChat({agentId:0,greetingPreview:true});
  await crossAccountOpenTab.getByRole('button',{name:/Owner A Agent.*Active/}).click();
  await crossAccountOpenTab.locator('[data-agent-greeting-preview]').waitFor();
  sessionBinding = 'owner-b-session';
  await crossAccountOpenTab.evaluate(() => VP3_COGNITIVE_PRESENTATION.refresh());
  await crossAccountOpenTab.getByText(errorText).waitFor();
  assert.equal(await crossAccountOpenTab.locator('[data-agent-brief-working-attention],[data-agent-greeting-preview],[data-vp3-return-digest],[data-homeserver-presence-id]').count(),0);
  assert.equal(await crossAccountOpenTab.evaluate(() => window.pendingSpeech?.canSpeak()),false);
  assert.equal(await page.evaluate(() => window.speechCount),0);
  assert.equal(await closedTab.evaluate(() => window.speechCount),0);
  assert.equal(await openTab.evaluate(() => window.speechCount),0);
  assert.deepEqual(errors,[]);
  console.log('PASS cross-account poll in an opened Brief removes its working and greeting previews');
} finally {
  await browser.close();
}
