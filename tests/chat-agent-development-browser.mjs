import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
const origin = 'https://development.test';
const outlineId = 'a'.repeat(32);
const buildId = 'b'.repeat(32);
const requests = [];
const normalTurns = [];
const errors = [];
let mode = 'off';
let epoch = 0;
let status = '';
let previewReady = false;
let stale = false;
let delayStatus;
let delayDraft;
const storedTurns = [
  {id:101,role:'user',message:'How many new emails did I get today?'},
  {id:102,role:'assistant',message:'The Gmail search did not return results today.'},
  {id:103,role:'user',message:'Stop playing YouTube'},
  {id:104,role:'assistant',message:'I cannot stop the YouTube player from here.'},
];
const youtubeObjective = 'Fix the Agent Chat behavior.\n\nUser request: Stop playing YouTube\nObserved reply: I cannot stop the YouTube player from here.';

let newChats = 0;

page.on('pageerror',error => errors.push(error.message));
await page.route(origin+'/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
  </main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));
await page.route(origin+'/admin/agent-development.php?*',route => route.fulfill({contentType:'text/plain',body:'Admin revision'}));
await page.route('**/api/**',async route => {
  const payload = route.request().postDataJSON() || {};
  if (new URL(route.request().url()).pathname === '/api/agent-development-chat-v1.php') {
    assert.equal(payload.owner_key,'1');
    assert.equal(payload.agent_id,7);
    assert.equal(payload.csrf_token,'fixture');
    requests.push(payload);
    const revision = () => ({id:mode === 'outline' ? outlineId : buildId, status, mode, preview_ready:previewReady});
    const base = {ok:true,mode,epoch,owner_key:'1',agent_id:7,conversation_id:payload.conversation_id};
    if (payload.action === 'draft') {
      assert.equal(payload.conversation_id,42);
      assert.equal(Object.hasOwn(payload,'request'),false);
      if (payload.assistant_message_id === 102 && !Object.hasOwn(payload,'user_message_id')) {
        return route.fulfill({json:{...base,legacy_prompts:[
          {id:101,text:'How many new emails did I get today?'},
          {id:99,text:'An earlier question'},
        ]}});
      }
      if (payload.assistant_message_id === 102) assert.equal(payload.user_message_id,101);
      const objective = payload.assistant_message_id === 104 ? youtubeObjective : 'Fix the Gmail count flow.';
      if (delayDraft) {
        const delayed = delayDraft;
        delayDraft = null;
        delayed.started();
        await delayed.release;
      }
      try { await route.fulfill({json:{...base,draft:objective}}); } catch (_error) {}
      return;
    }
    if (payload.action === 'create') {
      assert.equal(payload.expected_epoch,epoch);
      if (stale) return route.fulfill({status:409,json:{ok:false,error:'The automation level changed. Review it before trying again.'}});
      assert.ok(payload.request && payload.conversation_id > 0);
      status = mode === 'outline' ? 'outlining' : 'queued';
      return route.fulfill({json:{...base,revision:revision()}});
    }
    assert.equal(payload.action,'status');
    assert.equal(Object.hasOwn(payload,'request'),false);
    const revisions = status && [42,55].includes(payload.conversation_id) ? [revision()] : [];
    if (delayStatus && payload.conversation_id === 42) {
      const delayed = delayStatus;
      delayStatus = null;
      delayed.started();
      await delayed.release;
      try { await route.fulfill({json:{...base,revisions}}); } catch (_error) {}
      return;
    }
    return route.fulfill({json:{...base,revisions}});
  }
  if (payload.action === 'load') {
    return route.fulfill({json:{ok:true,messages:payload.conversation_id === 42 ? storedTurns : []}});
  }
  if (payload.action === 'send') {
    normalTurns.push(payload.message);
    return route.fulfill({json:{ok:true,conversation_id:42,user_message_id:1,assistant_message_id:2,answer:'Ordinary Agent Chat reply.'}});
  }
  if (payload.action === 'new') {
    newChats++;
    return route.fulfill({json:{ok:true,conversation_id:55}});
  }
  return route.fulfill({json:{ok:true,conversations:[],messages:[],updates:[]}});
});

const source = file => fs.readFile(new URL('../'+file,import.meta.url),'utf8');
const input = page.locator('#chatInput');
const button = page.locator('#sendChatButton');
const hint = page.locator('.vp3-chat-development-hint');
const compact = page.locator('.vp3-chat-development-compact');
const receipt = page.locator('.vp3-chat-development-result');
const notice = page.locator('.vp3-chat-development-notice');
const planner = page.getByRole('button',{name:'Repair Planner',exact:true});
const coder = page.getByRole('button',{name:'Coding Specialist',exact:true});
const panel = page.locator('[data-chat-specialist-panel="development"]');
const focus = () => page.evaluate(() => window.dispatchEvent(new Event('focus')));
const created = () => requests.filter(row => row.action === 'create');
const send = async message => { await input.fill(message); await button.click(); };
const open = id => page.evaluate(id => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(id),id);
const waitForText = (locator,text) => locator.getByText(text,{exact:true}).waitFor();
const expectOrbJobs = (...ids) => page.waitForFunction(ids => window.orbJobs.length===ids.length
  && ids.every(id => window.orbJobs.some(job => job.id===`development:${id}`&&job.expiresAt>Date.now()&&job.expiresAt<=Date.now()+12000)),ids);

async function boot(reload = false) {
  if (reload) await page.reload();
  else await page.goto(origin+'/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};
    window.VP3_CHAT_DEVELOPMENT_V1 = {endpoint:'/api/agent-development-chat-v1.php',revisionsUrl:'/admin/agent-development.php',ownerKey:'1',csrf:'fixture',agentId:7};
    window.orbJobs = [];
    window.VP3AgentOrb = {setJobs:(source,jobs) => {if(source==='development')window.orbJobs=jobs;}};
  });
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-agent-development-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat.js','chat-agent-development-v1.js']) {
    await page.addScriptTag({content:await source(file)});
  }
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
}

try {
  await boot();
  await send('Outline a coding fix in ordinary Chat.');
  await page.getByText('Ordinary Agent Chat reply.',{exact:true}).waitFor();
  assert.equal(created().length,0);

  await page.locator('#newChatButton').click();
  await send('/develop fix the navigation');
  await waitForText(notice,'Choose an automation level in Admin → Agent Development first.');
  assert.equal(newChats,0);
  assert.equal(await input.inputValue(),'/develop fix the navigation');
  assert.equal(created().length,0);
  await open(42);

  mode = 'outline';
  epoch = 1;
  await focus();
  await hint.getByText(/Outline and propose/).waitFor();
  const draftFor = id => page.locator(`[data-chat-message-id="${id}"][data-chat-message-role="assistant"]`)
    .getByRole('button',{name:'Draft /develop',exact:true});
  await draftFor(104).click();
  await page.waitForFunction(objective => document.getElementById('chatInput').value === '/develop '+objective,youtubeObjective);
  assert.equal(created().length,0,'Drafting never queues a revision');
  await expectOrbJobs();
  assert.equal(requests.filter(row => row.action === 'draft').at(-1).assistant_message_id,104);
  await input.fill('A different draft I am writing');
  await draftFor(102).click();
  const useSelected = page.getByRole('button',{name:'Use selected message',exact:true});
  await useSelected.waitFor();
  assert.equal(await useSelected.isDisabled(),true);
  assert.equal(created().length,0);
  await page.getByRole('radio',{name:'How many new emails did I get today?',exact:true}).check();
  await useSelected.click();
  assert.equal(requests.filter(row => row.action === 'draft').at(-1).user_message_id,101);
  await page.getByRole('button',{name:'Use in composer',exact:true}).waitFor();
  assert.equal(await input.inputValue(),'A different draft I am writing');
  await page.getByRole('button',{name:'Dismiss draft',exact:true}).click();
  await input.fill('');
  let startedDraft;
  let releaseDraft;
  const draftInFlight = new Promise(resolve => { startedDraft = resolve; });
  delayDraft = {started:startedDraft,release:new Promise(resolve => { releaseDraft = resolve; })};
  await draftFor(104).click();
  await draftInFlight;
  await open(12);
  releaseDraft();
  await page.waitForTimeout(100);
  assert.equal(await input.inputValue(),'');
  assert.equal(await page.getByRole('button',{name:'Use in composer',exact:true}).count(),0);
  await open(42);
  await draftFor(104).click();
  await page.waitForFunction(objective => document.getElementById('chatInput').value === '/develop '+objective,youtubeObjective);
  const reviewedObjective = youtubeObjective+'\nVerify the browser player closes.';
  await input.fill('/develop '+reviewedObjective);
  mode = 'code_stage';
  epoch = 2;
  await boot(true);
  await input.waitFor();
  assert.equal(await input.inputValue(),'/develop '+reviewedObjective,'The edited draft survives reload');
  await button.click();
  await notice.getByText(/Outline and propose.*Full code & stage for launch/i).waitFor();
  assert.equal(created().length,0,'The older Outline draft cannot become a code job');
  assert.equal(await input.inputValue(),'/develop '+reviewedObjective);
  mode = 'outline';
  epoch = 3;
  await focus();
  await hint.getByText(/Outline and propose/).waitFor();
  await button.click();
  await notice.getByText(/Draft again from the reply before sending/i).waitFor();
  assert.equal(created().length,0,'A mode round trip still requires fresh review');
  await input.fill('');
  await draftFor(104).click();
  await page.waitForFunction(objective => document.getElementById('chatInput').value === '/develop '+objective,youtubeObjective);
  await input.fill('/develop '+reviewedObjective);
  await button.click();
  await planner.waitFor();
  await waitForText(receipt,'Development revision saved.');
  assert.equal(await input.inputValue(),'');
  await planner.click();
  await waitForText(panel,'Preparing outline');
  await expectOrbJobs(outlineId);
  await panel.getByRole('button',{name:'Open revision'}).waitFor();
  assert.doesNotMatch(await panel.innerText(),/Stop playing YouTube/);
  assert.equal(created().length,1);
  assert.equal(created()[0].request,reviewedObjective);
  status = 'proposed';
  await focus();
  await planner.waitFor({state:'detached'});
  await waitForText(receipt,'Outline ready for review');
  await expectOrbJobs();
  assert.equal(await receipt.getByRole('link',{name:'Open revision'}).getAttribute('href'),`${origin}/admin/agent-development.php?revision=${outlineId}`);

  mode = 'code_stage';
  epoch = 4;
  status = '';
  await focus();
  await hint.getByText(/Full code & stage for launch/).waitFor();
  await send('/develop revise the navigation');
  await coder.waitFor();
  assert.equal(await compact.isVisible(),false);
  await coder.click();
  await waitForText(panel,'Waiting for coding worker');
  await expectOrbJobs();
  assert.equal(created().length,2);
  assert.equal(normalTurns.length,1);

  await page.setViewportSize({width:390,height:844});
  await coder.waitFor({state:'detached'});
  await waitForText(compact,'Coding Specialist · Waiting for coding worker');
  assert.equal(await compact.getByRole('link',{name:'Open revision'}).getAttribute('href'),`${origin}/admin/agent-development.php?revision=${buildId}`);
  await page.setViewportSize({width:1280,height:900});
  await coder.waitFor();
  await compact.waitFor({state:'hidden'});

  status = 'building';
  let release;
  let started;
  const inFlight = new Promise(resolve => { started = resolve; });
  delayStatus = {started,release:new Promise(resolve => { release = resolve; })};
  await focus();
  await inFlight;
  await open(12);
  release();
  await coder.waitFor({state:'detached'});
  await receipt.waitFor({state:'detached'});
  await expectOrbJobs();
  assert.ok(requests.some(row => row.conversation_id === 12));
  await open(42);
  await coder.waitFor();
  await coder.click();
  await waitForText(panel,'Building and testing candidate');
  await expectOrbJobs(buildId);

  status = 'staged';
  previewReady = true;
  await focus();
  await coder.waitFor({state:'detached'});
  await expectOrbJobs();
  assert.equal(await receipt.count(),0);

  stale = true;
  await send('/develop check stale mode');
  await waitForText(notice,'The automation level changed. Review it before trying again.');
  assert.equal(await input.inputValue(),'/develop check stale mode');
  assert.equal(created().length,3);
  await page.evaluate(() => { window.VP3ParticipantContinuity = {selectedContext:() => ({participant_id:3})}; });
  await send('/develop should stay private');
  await waitForText(notice,'Use the private typed Admin Chat when the Agent is ready.');
  assert.equal(created().length,3);
  await page.evaluate(() => {
    delete window.VP3ParticipantContinuity;
    window.STONEFELLOW_CHAT_CONTINUITY = {...window.STONEFELLOW_CHAT_CONTINUITY_V87,isVoice:() => true};
  });
  await send('/develop disallow voice');
  await waitForText(notice,'Use the private typed Admin Chat when the Agent is ready.');
  assert.equal(created().length,3);

  await page.evaluate(() => { window.STONEFELLOW_CHAT_CONTINUITY = window.STONEFELLOW_CHAT_CONTINUITY_V87; });
  stale = false;
  status = '';
  await page.locator('#newChatButton').click();
  await send('/develop stage an update from a new Chat');
  await waitForText(receipt,'Development revision saved.');
  assert.equal(newChats,1);
  assert.equal(created().length,4);
  assert.equal(created().at(-1).conversation_id,55);
  assert.equal(await input.inputValue(),'');
  assert.deepEqual(errors,[]);
  console.log('CHAT_AGENT_DEVELOPMENT_BROWSER=PASS (explicit typed routing, confirmed legacy pairs, reviewed mode across reload, levels, scoped rails, compact fallback, mode conflict, participant and voice boundaries)');
} finally {
  await browser.close();
}
