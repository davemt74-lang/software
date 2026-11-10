import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
const source = file => fs.readFile(new URL('../' + file, import.meta.url), 'utf8');
const binding = 'fixture-working-attention';
const requests = [];
const errors = [];
const subject = 'Bridge repair <img src=x onerror=alert(1)>';
const job = {run_id:27,kind:'topic',subject,state:'queued'};
let specialist = job;
let wrongBinding = false;
let delayed;
let denied = false;
page.on('pageerror', error => errors.push(error.message));

await page.route('https://research.test/', route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
  </main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));

await page.route('**/api/**', async route => {
  const request = route.request();
  const url = new URL(request.url());
  if (url.pathname === '/api/initiative-research-status-v1.php') {
    assert.equal(request.method(),'GET');
    assert.equal(request.postData(),null);
    assert.equal(request.headers()['x-vp3-working-attention'],binding);
    assert.equal(url.searchParams.get('agent_id'),'7');
    const conversationId = Number(url.searchParams.get('conversation_id'));
    assert.ok([42,43].includes(conversationId));
    requests.push(conversationId);
    const json = {ok:true,owner_user_id:1,agent_id:7,conversation_id:conversationId,
      working_attention_binding:wrongBinding?'other-session':binding,
      specialist:conversationId===42?specialist:null};
    if (denied) return route.fulfill({status:403,json:{ok:false}});
    if (delayed && conversationId===42) {
      const gate = delayed;
      delayed = null;
      gate.started();
      await gate.release;
      try { await route.fulfill({json}); } catch (_error) {}
      return;
    }
    return route.fulfill({json});
  }
  const payload = request.postDataJSON() || {};
  return route.fulfill({json:{ok:true,conversation_id:payload.conversation_id,conversations:[],updates:[],messages:[]}});
});

const button = page.getByRole('button',{name:'Research Specialist',exact:true});
const panel = page.locator('[data-chat-specialist-panel="research"]');
const inline = page.locator('.vp3-chat-research-specialist-status');
const progress = page.getByRole('button',{name:'View research progress'});
const refresh = () => page.evaluate(() => window.dispatchEvent(new Event('vp3:chat-history-updated')));
const running = (expiresAt = Date.now()+60000) => ({...job,state:'running',lease_expires_at:new Date(expiresAt).toISOString()});

try {
  await page.goto('https://research.test/');
  await page.clock.install();
  await page.evaluate(workingAttentionBinding => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'fixture',initialView:'chat',initialConversationId:42,workingAttentionBinding};
    window.VP3_CHAT_RESEARCH_SPECIALIST_V1 = {endpoint:'/api/initiative-research-status-v1.php',ownerKey:'1',agentId:7};
  }, binding);
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-research-specialist-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat-research-specialist-v1.js','chat.js']) {
    await page.addScriptTag({content:await source(file)});
  }

  await inline.getByText(/Waiting for the research worker/).waitFor();
  assert.equal(await button.count(),0,'Queued work has no active indicator');
  assert.equal(await progress.isVisible(),false);
  specialist = running();
  await refresh();
  await button.waitFor();
  assert.equal(await panel.count(),0,'Proactive work opens only when clicked');
  assert.equal(await inline.isVisible(),false);
  await button.click();
  await panel.getByText('Researching public sources',{exact:true}).waitFor();
  assert.equal(await panel.getAttribute('role'),'dialog');
  assert.equal(await panel.locator('img').count(),0);
  assert.equal(await panel.locator('.vp3-chat-specialist-title').textContent(),subject);
  await panel.getByText('Topic research',{exact:true}).waitFor();

  await page.setViewportSize({width:390,height:780});
  await inline.waitFor();
  assert.match(await inline.textContent(),/Research Specialist · Researching public sources/);
  await button.waitFor({state:'detached'});
  if (await panel.isVisible()) await panel.getByRole('button',{name:'Close',exact:true}).click();
  await panel.waitFor({state:'detached'});
  await progress.click();
  await panel.getByText('Researching public sources',{exact:true}).waitFor();
  assert.equal(await page.locator('#chatThread .message').count(),0,'Progress stays outside saved message history');

  specialist = null;
  await refresh();
  await inline.waitFor({state:'hidden'});
  await panel.waitFor({state:'detached'});
  specialist = {...job,state:'failed'};
  await page.clock.fastForward(12500);
  await inline.waitFor();
  assert.match(await inline.textContent(),/Research did not finish/);
  assert.equal(await progress.isVisible(),false);
  await page.setViewportSize({width:1280,height:900});
  assert.equal(await button.count(),0,'Failed work has no active indicator');
  specialist = running();
  await refresh();
  await button.waitFor();
  denied = true;
  await refresh();
  await button.waitFor({state:'detached'});
  assert.equal(await inline.isVisible(),false);
  denied = false;

  specialist = running();
  wrongBinding = true;
  const mismatched = page.waitForResponse(response => response.url().includes('initiative-research-status-v1'));
  await refresh();
  await mismatched;
  assert.equal(await button.count(),0,'A response bound to another session must never render');
  wrongBinding = false;

  let started, release;
  const sent = new Promise(resolve => { started = resolve; });
  delayed = {started,release:new Promise(resolve => { release = resolve; })};
  await refresh();
  await sent;
  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
  release();
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId() === 43);
  const next = page.waitForResponse(response => new URL(response.url()).searchParams.get('conversation_id') === '43');
  await refresh();
  await next;
  assert.equal(await button.count(),0,'Late work from a different Chat must remain hidden');
  assert.equal(await inline.isVisible(),false);
  assert.equal(requests.at(-1),43);

  await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
  await button.waitFor();
  const browserNow = await page.evaluate(() => Date.now());
  specialist = running(browserNow+2000);
  const updated = page.waitForResponse(response => response.url().includes('initiative-research-status-v1'));
  await refresh();
  await updated;
  await button.click();
  await panel.waitFor();
  await page.clock.fastForward(2100);
  await button.waitFor({state:'detached'});
  await panel.waitFor({state:'detached'});
  assert.equal(await inline.isVisible(),false,'Expired work clears its active UI without another server reply');
  specialist = running();
  await refresh();
  await button.waitFor();
  await page.evaluate(() => {
    window.VP3ParticipantContinuity = {selectedContext:() => ({participant_id:5})};
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await button.waitFor({state:'detached'});
  assert.deepEqual(errors,[]);
  console.log('Research specialist active rail and mobile dialog, lease expiry, completion, failure, and session/Chat isolation verified.');
} finally {
  await browser.close();
}
