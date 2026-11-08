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

try {
  await page.goto(origin+'/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};
    window.VP3_CHAT_DEVELOPMENT_V1 = {endpoint:'/api/agent-development-chat-v1.php',revisionsUrl:'/admin/agent-development.php',ownerKey:'1',csrf:'fixture',agentId:7};
  });
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-agent-development-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat.js','chat-agent-development-v1.js']) {
    await page.addScriptTag({content:await source(file)});
  }
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
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
  await send('/develop fix the navigation');
  await planner.waitFor();
  await waitForText(receipt,'Development revision saved.');
  assert.equal(await input.inputValue(),'');
  await planner.click();
  await waitForText(panel,'Preparing outline');
  await panel.getByRole('button',{name:'Open revision'}).waitFor();
  assert.doesNotMatch(await panel.innerText(),/fix the navigation/);
  assert.equal(created().length,1);
  assert.deepEqual(created()[0].request,'fix the navigation');
  status = 'proposed';
  await focus();
  await planner.waitFor({state:'detached'});
  await waitForText(receipt,'Outline ready for review');
  assert.equal(await receipt.getByRole('link',{name:'Open revision'}).getAttribute('href'),`${origin}/admin/agent-development.php?revision=${outlineId}`);

  mode = 'code_stage';
  epoch = 2;
  status = '';
  await focus();
  await hint.getByText(/Full code & stage for launch/).waitFor();
  await send('/develop revise the navigation');
  await coder.waitFor();
  assert.equal(await compact.isVisible(),false);
  await coder.click();
  await waitForText(panel,'Waiting for coding worker');
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
  assert.ok(requests.some(row => row.conversation_id === 12));
  await open(42);
  await coder.waitFor();
  await coder.click();
  await waitForText(panel,'Building and testing candidate');

  status = 'staged';
  previewReady = true;
  await focus();
  await coder.waitFor({state:'detached'});
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
  console.log('CHAT_AGENT_DEVELOPMENT_BROWSER=PASS (explicit typed routing, levels, scoped rails, compact fallback, mode conflict, participant and voice boundaries)');
} finally {
  await browser.close();
}
