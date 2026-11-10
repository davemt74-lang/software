import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const requests = [];
const pageErrors = [];
const external = [];
const calls = [];
const revisionToken = n => String(n).padStart(64,'0');
const html = '<h1>Checklist</h1><label><input id="done" type="checkbox"> Ready</label><p id="security"></p><p id="network"></p><div id="dynamic"></div>';
const js = `
  CanvasUI.checked('#done', Boolean(CanvasData.get().done));
  const isolated = typeof document === 'undefined' && typeof fetch === 'undefined' &&
    typeof postMessage === 'undefined' && typeof Object.getPrototypeOf(self).postMessage === 'undefined';
  CanvasUI.text('#security', isolated ? 'Isolated' : 'Exposed');
  import('https://external.test/worker.mjs').then(
    () => CanvasUI.text('#network','Network exposed'),
    () => CanvasUI.text('#network','Network blocked')
  );
  CanvasUI.html('#dynamic','<iframe src="https://external.test/leak"></iframe><img src="https://external.test/leak"><p onclick="alert(1)">Safe content</p>');
  CanvasUI.on('#done','change',async ({checked}) => {
    await CanvasData.save({done:checked});
    CanvasUI.text('#security','Saved');
  });
`;
const artifact = {html,css:'body{color:#182025;background-image:url(https://external.test/css)}',js};
const repaired = {html:html.replace('Checklist','Workshop checklist'),css:artifact.css,js};
const infinite = {html,css:artifact.css,js:'while (true) {}'};
const failsAfterTest = {html,css:artifact.css,js:"CanvasUI.on('#done','change',() => { throw new Error('Runtime failed'); });"};
const canvas = {id:'01234567-89ab-4cde-8f01-0123456789ab',title:'Checklist',agent_id:7,conversation_id:42,folder_id:4,active_revision:null,draft_revision:1};
const revisions = [{id:1,artifact,status:'draft',test_passed:false,can_rollback:false}];
let state = {revision:revisionToken(1),value:{}};
let job = null;
let revision = 1;
let jobNumber = 0;
let deferFirstTick = true;
let testState = '';
let conversation = 42;
let expectedAgent = 7;
let activeTick;
let listGate;
const releaseTick = () => {
  activeTick?.();
  activeTick = undefined;
};

if (process.env.VP3_TEST_DEBUG) page.on('console',message => console.log(message.type(),message.text()));
page.on('pageerror',error => pageErrors.push(error.message));
page.on('dialog',dialog => { pageErrors.push('Unexpected dialog'); void dialog.dismiss(); });
await page.route('https://canvas.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><header class="chat-topbar"></header>
  <section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
  </main></div><aside class="sf-online-rail-v109" id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>`}));
await page.route('https://external.test/**',route => {
  external.push(route.request().url());
  return route.fulfill({contentType:'text/javascript',headers:{'Access-Control-Allow-Origin':'*'},body:'export default true'});
});
await page.route('https://canvas.test/api/**',async route => {
  const body = route.request().postDataJSON() || {};
  if (!route.request().url().endsWith('/coding')) {
    if (body.action === 'new') return route.fulfill({json:{ok:true,conversation_id:++conversation}});
    return route.fulfill({json:{ok:true,conversation_id:body.conversation_id,conversations:[],updates:[],messages:[]}});
  }

  assert.equal(body.csrf_token,'fixture');
  assert.equal(body.agent_id,expectedAgent);
  requests.push(body);
  const result = data => route.fulfill({json:{ok:true,...data}});
  const snapshot = id => {
    const selected = revisions.find(item => item.id === id);
    return {canvas,revision:{...selected,test_passed:testState === `${id}:${state.revision}`},state,revisions};
  };
  switch (body.action) {
    case 'resume': return result({job:['queued','running'].includes(job?.state) ? job : null});
    case 'list': {
      if (listGate) {
        const {started,released} = listGate;
        listGate = null;
        started();
        await released;
      }
      return result({canvases:job ? [canvas] : []});
    }
    case 'build':
    case 'repair': {
      calls.push(body.action);
      assert.ok(body.conversation_id > 0);
      assert.match(body.request_id,/^[a-f0-9-]{36}$/);
      if (body.action === 'repair') {
        assert.ok(revisions.some(item => item.id === body.revision));
        canvas.draft_revision = ++revision;
        let source = repaired;
        if (body.prompt === 'Fail after startup') source = failsAfterTest;
        if (body.prompt === 'Hang during startup') {
          source = infinite;
          assert.match(body.diagnostic,/preview failed/);
        }
        revisions.push({id:revision,artifact:source,status:'draft',test_passed:false,can_rollback:false});
      }
      job = {run_id:++jobNumber,canvas_id:canvas.id,state:'queued',revision:null,model:{model:'fixture-coder'}};
      return result({job});
    }
    case 'tick': {
      assert.equal(body.run_id,job.run_id);
      if (deferFirstTick) {
        deferFirstTick = false;
        return result({job});
      }
      job = {...job,state:'running'};
      await new Promise(resolve => { activeTick = resolve; });
      if (job.state === 'running') job = {...job,state:'ready',revision};
      return result({job});
    }
    case 'job': return result({job});
    case 'stop':
      job = {...job,state:'stopped'};
      return result({job});
    case 'open': return result(snapshot(body.revision || canvas.active_revision || revision));
    case 'test':
      assert.equal(body.state_revision,state.revision);
      testState = `${body.revision}:${state.revision}`;
      return result(snapshot(body.revision));
    case 'apply':
      assert.equal(body.expected_active_revision,canvas.active_revision);
      assert.equal(testState,`${body.revision}:${state.revision}`);
      for (const item of revisions) {
        if (item.status === 'active') { item.status = 'saved'; item.can_rollback = true; }
        if (item.id === body.revision) item.status = 'active';
      }
      canvas.active_revision = body.revision;
      canvas.draft_revision = null;
      return result(snapshot(body.revision));
    case 'save_state':
      assert.equal(body.canvas_id,canvas.id);
      assert.equal(body.revision,canvas.active_revision);
      assert.equal(body.state_revision,state.revision);
      state = {revision:revisionToken(Number(state.revision) + 1),value:body.state};
      return result(snapshot(body.revision));
    case 'delete':
      assert.equal(body.delete_folder,false);
      job = null;
      return result({});
    default: throw new Error('Unexpected Canvas action: ' + body.action);
  }
});

const panel = page.locator('#chatCodingCanvasV1');
const status = panel.getByRole('status');
const preview = panel.frameLocator('iframe');
const launch = page.getByRole('button',{name:'Code / App Canvas',exact:true});
const codePrompt = panel.getByRole('textbox');
const records = action => requests.filter(request => request.action === action);
const codeEvent = await fs.readFile(new URL('../chat-coding-canvas-v1.js',import.meta.url),'utf8');

async function loadChat(conversationId) {
  await page.goto('https://canvas.test/');
  await page.evaluate(({id,agent}) => {
    if (!id) localStorage.clear();
    window.STONEFELLOW_CHAT = {userId:1,agentId:agent,endpoint:'/api/chat',csrf:'fixture',initialView:'chat',initialConversationId:id};
    window.VP3_CODING_CANVAS_V1 = {endpoint:'/api/coding',csrf:'fixture',agentId:agent};
    window.usageEvents = 0;
    window.addEventListener('vp3-coding-usage-change',() => window.usageEvents++);
  },{id:conversationId,agent:expectedAgent});
  for (const file of ['chat.css','chat-specialist-rail-v1.css','chat-coding-canvas-v1.css']) {
    await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  }
  for (const file of ['chat-specialist-rail-v1.js','chat.js']) {
    await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  }
  await page.addScriptTag({content:codeEvent});
  await page.waitForFunction(() => window.STONEFELLOW_CHAT_CONTINUITY.isReady() && !window.STONEFELLOW_CHAT_CONTINUITY.isRestoring());
}

async function nextDraft() {
  await status.getByText('Working on the canvas · fixture-coder',{exact:true}).waitFor();
  releaseTick();
  await status.getByText('Draft ready to test · fixture-coder',{exact:true}).waitFor();
}

try {
  await loadChat(42);
  await page.locator('#chatInput').fill('/build Build a checklist');
  await page.locator('#sendChatButton').click();
  await nextDraft();
  assert.equal(records('tick').length,2,'A successful response which leaves the job queued is retried');
  await preview.getByText('Isolated',{exact:true}).waitFor();
  assert.equal(await panel.locator('iframe').getAttribute('sandbox'),'allow-scripts');
  await preview.getByText('Safe content',{exact:true}).waitFor();
  await preview.getByText('Network blocked',{exact:true}).waitFor();
  assert.equal(await preview.locator('iframe, img, [onclick]').count(),0);
  assert.deepEqual(external,[]);
  assert.equal(await page.locator('#chatInput').inputValue(),'');
  assert.equal(await panel.getByRole('button',{name:'Use this version'}).isEnabled(),false);
  await preview.getByRole('checkbox',{name:'Ready'}).check();
  await preview.getByText('Saved',{exact:true}).waitFor();
  assert.equal(records('save_state').length,0,'Draft interactions stay in the preview');
  await panel.getByRole('button',{name:'Test preview'}).click();
  await status.getByText('Preview loaded successfully.',{exact:false}).waitFor();
  assert.equal(records('test').length,1);
  await panel.getByRole('button',{name:'Use this version'}).click();
  await status.getByText('This version is active.',{exact:false}).waitFor();
  await preview.getByText('Isolated',{exact:true}).waitFor();
  assert.equal(await preview.getByRole('checkbox',{name:'Ready'}).isChecked(),false,'Draft state is discarded when activating');

  const saveCount = records('save_state').length;
  const nonce = await panel.locator('iframe').evaluate(frame => frame.srcdoc.match(/script nonce="([^"]+)/)[1]);
  await page.evaluate(nonce => {
    window.dispatchEvent(new MessageEvent('message',{origin:'null',source:window,data:{channel:'vp3.coding.canvas.v1',nonce,kind:'save',id:1,json:'{"forged":true}'}}));
  },nonce);
  assert.equal(records('save_state').length,saveCount,'A forged top-level message cannot save');
  await preview.getByRole('checkbox',{name:'Ready'}).dispatchEvent('change');
  assert.equal(records('save_state').length,saveCount,'A synthetic app interaction cannot save');
  await preview.getByRole('checkbox',{name:'Ready'}).check();
  await preview.getByText('Saved',{exact:true}).waitFor();
  assert.deepEqual(state.value,{done:true});
  await panel.getByRole('button',{name:'Back to Chat'}).click();
  assert.equal(await panel.locator('iframe').count(),0);
  assert.equal(await page.locator('#chatInput').isVisible(),true);
  await loadChat(42);
  await launch.click();
  assert.equal(await page.evaluate(id=>window.VP3CodingCanvasV1.open(id,1),canvas.id),true,'Activity opens the exact saved Canvas revision');

  await preview.getByText('Isolated',{exact:true}).waitFor();
  assert.equal(await preview.getByRole('checkbox',{name:'Ready'}).isChecked(),true);
  let began, release;
  const started = new Promise(resolve => { began = resolve; });
  listGate = {started:began,released:new Promise(resolve => { release = resolve; })};
  await launch.click();
  await started;
  await page.evaluate(() => {
    window.VP3ParticipantContinuity = {selectedContext:() => ({participant_id:14})};
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  assert.equal(await launch.isVisible(),false);
  assert.equal(await panel.isVisible(),false);
  assert.equal(await panel.locator('iframe').count(),0);
  const listResponse = page.waitForResponse(response => response.url().endsWith('/coding') && response.request().postDataJSON().action === 'list');
  release();
  await listResponse;
  assert.equal(await panel.locator('select option').count(),0,'Late owner Canvas data stays hidden in participant context');
  assert.equal(await panel.locator('pre').textContent(),'');
  await page.evaluate(() => {
    window.VP3ParticipantContinuity = {selectedContext:() => null};
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:14}}));
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  assert.equal(await launch.isVisible(),false);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:-1}})));
  await launch.click();
  await panel.getByLabel('Saved canvases').selectOption(canvas.id);
  await preview.getByText('Isolated',{exact:true}).waitFor();

  await page.setViewportSize({width:390,height:780});
  await codePrompt.fill('Rename the checklist');
  await panel.getByRole('button',{name:'Repair',exact:true}).click();
  await nextDraft();
  await preview.getByRole('heading',{name:'Workshop checklist'}).waitFor();
  await preview.getByText('Isolated',{exact:true}).waitFor();
  assert.equal(await preview.getByRole('checkbox',{name:'Ready'}).isChecked(),true);
  await panel.getByRole('button',{name:'Test preview'}).click();
  await status.getByText('Preview loaded successfully.',{exact:false}).waitFor();
  await panel.getByRole('button',{name:'Use this version'}).click();
  await status.getByText('This version is active.',{exact:false}).waitFor();
  assert.deepEqual(state.value,{done:true});
  assert.deepEqual(calls,['build','repair']);
  assert.ok(await page.evaluate(() => window.usageEvents >= 1));

  await codePrompt.fill('Fail after startup');
  await panel.getByRole('button',{name:'Repair',exact:true}).click();
  await nextDraft();
  await panel.getByRole('button',{name:'Test preview'}).click();
  await status.getByText('Preview loaded successfully.',{exact:false}).waitFor();
  assert.equal(await panel.getByRole('button',{name:'Use this version'}).isEnabled(),true);
  await preview.getByRole('checkbox',{name:'Ready'}).check();
  await status.getByText('Preview failed.',{exact:false}).waitFor();
  assert.equal(await panel.getByRole('button',{name:'Use this version'}).isEnabled(),false,'A runtime error invalidates the healthy preview');

  await codePrompt.fill('Hang during startup');
  await panel.getByRole('button',{name:'Repair',exact:true}).click();
  await nextDraft();
  await status.getByText('Preview failed.',{exact:false}).waitFor({timeout:10000});
  assert.equal(await panel.locator('iframe').count(),0,'Hung generated worker is removed');
  assert.equal(await panel.getByRole('button',{name:'Use this version'}).isEnabled(),false);

  await panel.getByRole('button',{name:'Delete canvas…'}).click();
  await panel.getByRole('button',{name:'Delete canvas',exact:true}).click();
  await status.getByText('Canvas deleted.',{exact:true}).waitFor();

  expectedAgent = 0;
  await loadChat(0);
  assert.equal(await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.conversationId()),0);
  await launch.click();
  await codePrompt.fill('Build another checklist');
  await panel.getByRole('button',{name:'Build new'}).click();
  await status.getByText('Working on the canvas · fixture-coder',{exact:true}).waitFor();
  const bound = await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.conversationId());
  assert.equal(bound,conversation);
  assert.equal(records('build').at(-1).conversation_id,bound);
  await loadChat(bound);
  await launch.click();
  await status.getByText('Working on the canvas · fixture-coder',{exact:true}).waitFor();
  assert.ok(records('resume').some(request => request.conversation_id === bound));
  await panel.getByRole('button',{name:'Stop',exact:true}).click();
  await status.getByText('Build stopped · fixture-coder',{exact:true}).waitFor();
  assert.equal(records('stop').at(-1).conversation_id,bound);
  releaseTick();
  assert.deepEqual(external,[]);
  assert.deepEqual(pageErrors,[]);
  console.log('Coding Canvas browser fixture passed');
} finally {
  releaseTick();
  await browser.close();
}
