import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
const errors = [];
const checks = [];
const messages = [];
const sender = 'private@example.test';
const watchPrompt = `Notify me when I get an email from ${sender}.`;
const stopPrompt = `Stop watching for emails from ${sender}.`;
let status = 'idle';
let delayStatus = null;
let nextId = 1;

page.on('pageerror', error => errors.push(error.message));
await page.route('https://email.test/', route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
  </main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));

await page.route('**/api/**', async route => {
  const payload = route.request().postDataJSON() || {};
  const path = new URL(route.request().url()).pathname;
  const ok = result => route.fulfill({json:{ok:true,...result}});
  if (path === '/api/chat-email-v1.php') {
    assert.deepEqual(payload, {
      agent_id:7, conversation_id:payload.conversation_id, action:'specialist_status', owner_key:'1', csrf_token:'fixture',
    });
    checks.push(payload.conversation_id);
    const result = payload.conversation_id === 42 && status !== 'idle'
      ? {state:status,origin_message_id:messages.find(row => row.message === watchPrompt).id}
      : {state:'idle',origin_message_id:null};
    if (delayStatus && payload.conversation_id === 42) {
      const gate = delayStatus;
      delayStatus = null;
      gate.started();
      await gate.release;
      try { await ok({specialist:result}); } catch (_error) {}
      return;
    }
    return ok({specialist:result});
  }
  if (payload.action === 'load') return ok({messages:payload.conversation_id === 42 ? messages : []});
  if (payload.action === 'task.route') return ok({route:'chat'});
  if (payload.action === 'send') {
    let answer = 'Email search completed.';
    let tool_receipt = {capability:'email.read',operation:'search',status:'read_complete'};
    if (payload.message === watchPrompt || payload.message === stopPrompt) {
      const watching = payload.message === watchPrompt;
      status = watching ? 'watching' : 'idle';
      answer = watching ? 'Gmail watch is active.' : 'Gmail watch stopped.';
      tool_receipt = {capability:'email.followups',operation:watching ? 'watch_sender' : 'stop_sender_watch',status:'completed'};
    }
    const user_message_id = nextId++;
    const assistant_message_id = nextId++;
    messages.push({id:user_message_id,role:'user',message:payload.message},{id:assistant_message_id,role:'assistant',message:answer});
    return ok({conversation_id:42,user_message_id,assistant_message_id,answer,tool_receipt});
  }
  return ok({conversations:[],items:[],updates:[]});
});

const source = file => fs.readFile(new URL('../' + file, import.meta.url), 'utf8');
const specialist = page.getByRole('button',{name:'Email Specialist',exact:true});
const panel = page.locator('[data-chat-specialist-panel="email"]');
const inline = page.locator('.vp3-chat-email-specialist-status');
const send = async message => {
  await page.locator('#chatInput').fill(message);
  await page.locator('#sendChatButton').click();
};
try {
  await page.goto('https://email.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42};
    window.VP3_CHAT_EMAIL_V1 = {endpoint:'/api/chat-email-v1.php',ownerKey:'1',csrf:'fixture',agentId:7,connectionsUrl:'/connections.php'};
  });
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-email-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat-email-v1.js','chat.js']) {
    await page.addScriptTag({content:await source(file)});
  }
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady());
  await send(`Show emails from ${sender}`);
  await page.getByText('Email search completed.',{exact:true}).waitFor();
  assert.equal(await specialist.count(),0);
  assert.equal(await inline.isVisible(),false);

  await send(watchPrompt);
  await specialist.waitFor();
  assert.equal(await inline.isVisible(),false);
  await specialist.click();
  await panel.getByText('Watching Gmail for new mail',{exact:true}).waitFor();
  assert.doesNotMatch(await panel.innerText(),/private@example/);
  await panel.getByRole('button',{name:'View in Chat'}).click();
  assert.equal(await page.locator(':focus').getAttribute('data-chat-message-id'),String(messages[2].id));

  await page.setViewportSize({width:390,height:844});
  await specialist.waitFor({state:'detached'});
  await inline.waitFor();
  assert.match(await inline.textContent(),/Watching Gmail/);
  assert.doesNotMatch(await inline.textContent(),/private@example/);
  await page.setViewportSize({width:1280,height:900});
  await specialist.waitFor();
  await inline.waitFor({state:'hidden'});
  await page.locator('#sfOnlineRailV109').evaluate(rail => { rail.hidden = true; });
  await inline.waitFor();
  await page.locator('#sfOnlineRailV109').evaluate(rail => { rail.hidden = false; });
  await specialist.waitFor();
  await inline.waitFor({state:'hidden'});

  status = 'paused';
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await specialist.click();
  await panel.getByText('Gmail watch paused',{exact:true}).waitFor();

  let release;
  const result = new Promise(resolve => { release = resolve; });
  let started;
  const inFlight = new Promise(resolve => { started = resolve; });
  delayStatus = {started,release:result};
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await inFlight;
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(12));
  await specialist.waitFor({state:'detached'});
  await panel.waitFor({state:'detached'});
  release();
  assert.equal(await inline.isVisible(),false);

  status = 'watching';
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await specialist.waitFor();
  await send(stopPrompt);
  await page.getByText('Gmail watch stopped.',{exact:true}).waitFor();
  await specialist.waitFor({state:'detached'});
  assert.equal(await inline.isVisible(),false);
  assert.ok(checks.includes(12) && checks.includes(42));
  assert.deepEqual(errors,[]);
  console.log('CHAT_EMAIL_SPECIALIST_BROWSER=PASS (real Chat receipt, desktop rail, compact status, privacy, pause, stale request and cancellation)');
} finally {
  await browser.close();
}
