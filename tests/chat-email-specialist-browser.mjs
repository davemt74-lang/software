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
const workflowCalls = [];
const workflowFolder = 'Member workflow reviews';
let workflowState = {
  available:true, enabled:false, can_disable:false, status:'disabled',
  connection_id:9, connection_revision:'a'.repeat(64), revision:'',
  folder_id:0, folder_name:'', folders:[], last_checked_at:null,
};

page.on('pageerror', error => errors.push(error.message));
await page.route('https://email.test/', route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><header class="chat-topbar"></header><section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
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
await page.route('https://email.test/connection-gmail-github.php', async route => {
  assert.equal(route.request().method(),'POST');
  const payload = route.request().postDataJSON();
  workflowCalls.push(payload);
  assert.equal(payload.owner_key,'1');
  assert.equal(payload.csrf_token,'fixture');
  if (payload.action !== 'state') {
    assert.equal(payload.connection_id,workflowState.connection_id);
    assert.equal(payload.connection_revision,workflowState.connection_revision);
    assert.equal(payload.github_revision,workflowState.revision);
    if (payload.action === 'enable') {
      assert.equal(payload.folder_name,workflowFolder);
      workflowState = {...workflowState,enabled:true,can_disable:true,status:'waiting',revision:'b'.repeat(32),
        folder_id:18,folder_name:workflowFolder,folders:[{id:18,name:workflowFolder}]};
    } else {
      assert.equal(payload.action,'disable');
      assert.equal(Object.hasOwn(payload,'folder_name'),false);
      workflowState = {...workflowState,enabled:false,can_disable:false,status:'disabled',revision:'c'.repeat(32)};
    }
  }
  await route.fulfill({json:{ok:true,owner_key:'1',state:workflowState}});
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
    window.VP3_CHAT_EMAIL_V1 = {endpoint:'/api/chat-email-v1.php',ownerKey:'1',csrf:'fixture',agentId:7,connectionsUrl:'/connections.php',
      githubWorkflow:{endpoint:'/connection-gmail-github.php'}};
  });
  for (const file of ['chat.css','team-chat-v109.css','chat-specialist-rail-v1.css','chat-tools.css','chat-email-v1.css']) {
    await page.addStyleTag({content:await source(file)});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat-tools.js','chat-email-v1.js','chat.js']) {
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

  await page.setViewportSize({width:390,height:844});
  const tools = page.getByRole('dialog',{name:'Chat tools'});
  const gmail = tools.getByText('Gmail',{exact:true});
  const workflow = tools.locator('.vp3-chat-github-workflow');
  const workflowToggle = workflow.getByRole('checkbox',{name:'GitHub Workflow Review'});
  const folder = workflow.getByLabel(/Private Knowledge folder name/);
  const saveWorkflow = workflow.getByRole('button',{name:'Save workflow review'});
  await page.getByRole('button',{name:'Chat tools'}).click();
  assert.equal(workflowCalls.length,0,'opening Chat tools alone never requests private Gmail settings');
  await gmail.click();
  await workflow.getByText('Workflow review is off.',{exact:true}).waitFor();
  assert.equal(await workflowToggle.isChecked(),false);
  assert.equal(await folder.isVisible(),false);
  assert.match(await workflow.innerText(),/Announcements follow your Agent Voice setting/);
  assert.deepEqual(workflowCalls,[{action:'state',owner_key:'1',csrf_token:'fixture'}]);
  await workflowToggle.check();
  assert.equal(await folder.inputValue(),'');
  assert.equal(await saveWorkflow.isDisabled(),true);
  assert.equal(workflowCalls.length,1,'checking the option requires an explicit folder and save');
  await folder.fill(workflowFolder);
  await saveWorkflow.click();
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  assert.equal(workflowCalls.at(-1).action,'enable');
  assert.equal(await folder.inputValue(),workflowFolder);

  await page.evaluate(() => {
    window.workflowParticipantSelected = true;
    window.VP3ParticipantContinuity = {selectedContext:() => window.workflowParticipantSelected ? {participant_id:'2'} : null};
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await workflow.waitFor({state:'hidden'});
  assert.equal(await workflow.locator('input[name="folder_name"]').inputValue(),'');
  assert.equal(await workflow.locator('datalist option').count(),0);
  await page.evaluate(() => {
    window.workflowParticipantSelected = false;
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  assert.equal(await folder.inputValue(),workflowFolder);
  assert.equal(workflowCalls.at(-1).action,'state');

  await page.evaluate(() => {
    window.STONEFELLOW_CHAT.userId = 2;
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
  });
  await workflow.waitFor({state:'hidden'});
  assert.equal(await workflow.locator('input[name="folder_name"]').inputValue(),'');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT.userId = 1;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
  });
  await workflow.getByText('Waiting for the next background check.',{exact:true}).waitFor();
  await workflowToggle.uncheck();
  await saveWorkflow.click();
  await workflow.getByText('Workflow review is off.',{exact:true}).waitFor();
  assert.equal(workflowCalls.at(-1).action,'disable');
  assert.equal(await folder.isVisible(),false);
  assert.deepEqual(errors,[]);
  console.log('CHAT_EMAIL_SPECIALIST_BROWSER=PASS (Chat receipt, specialist rail, Gmail workflow preferences, participant privacy and cancellation)');
} finally {
  await browser.close();
}
