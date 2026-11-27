import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath ? {executablePath} : {})});
const page = await browser.newPage();
page.setDefaultTimeout(8000);
const script = await fs.readFile(new URL('../chat-coding-canvas-v1.js',import.meta.url),'utf8');
const runtime = await fs.readFile(new URL('../coding-canvas-runtime-v1.js',import.meta.url),'utf8');
const canvas = {id:'01234567-89ab-4cde-8f01-0123456789ab',title:'Deadline board',agent_id:7,conversation_id:42,active_revision:null,draft_revision:1};
const state = {revision:'a'.repeat(64),value:{}};
const id = '1'.repeat(64), source = '2'.repeat(64);
const row = {id,source_ref:source,subject:'Private verified email subject',stage:'awaiting_reply',
  due_date:'2026-11-14',due_at:null,deadline_current:true};
let snapshot = {workflow:'deadline_board',generation:9,rows:[row]};
const requests = [];
let tested = false;

const html = '<p id="status"></p><ul id="deadline-rows"></ul><button id="save">Try to save</button><p id="write"></p>';
const workingCode = `
  const data = CanvasSources.email.get();
  CanvasUI.text('#status', data.status);
  const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
  const rows = data.rows.filter(row => row.deadline_current || row.stage === 'reply_received')
    .sort((a, b) => a.due_date.localeCompare(b.due_date));
  CanvasUI.html('#deadline-rows', rows.map(row => '<li><button data-email-id="' + row.id + '">' + escape(row.subject)
    + '</button> ' + row.stage + '</li>').join(''));
  CanvasUI.on('button[data-email-id]', 'click', event => CanvasSources.email.open(event.dataset.emailId));
  CanvasUI.on('#save', 'click', () => CanvasData.save({data}).catch(() => CanvasUI.text('#write', 'Write denied')));
  if (rows.length) CanvasSources.email.open(rows[0].id);
`;
const brokenCode = `CanvasUI.text('#status', CanvasSources.email.get().rows.map(row => row.subject).join(', '));`;
const revisions = [1,2].map(id => ({id,status:'draft',test_passed:false,can_rollback:false}));
const detail = revision => ({canvas,state,revisions,email_source:true,revision:{...revisions[revision-1],test_passed:tested,
  artifact:{html,css:'',js:revision===1 ? workingCode : brokenCode}}});

await page.route('https://canvas-email.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
  <div id="chatThread" data-view="chat"></div><div id="chatComposerShell"></div>`}));
await page.route('https://canvas-email.test/api/coding',route => {
  const request = route.request().postDataJSON();
  requests.push(request);
  assert.equal(request.csrf_token,'fixture');
  assert.equal(request.agent_id,7);
  if(request.action==='list')assert.ok([0,42].includes(request.conversation_id),'Only the scoped app footer may list without a saved Chat');
  else assert.equal(request.conversation_id,42);
  const respond = fields => route.fulfill({json:{ok:true,...fields}});
  switch (request.action) {
    case 'list': return respond({canvases:[canvas],email_start_available:false});
    case 'resume': return respond({job:null});
    case 'open': return respond(detail(request.revision));
    case 'email_data':
      assert.equal(request.canvas_id,canvas.id);
      assert.ok([1,2].includes(request.revision));
      return respond({email:snapshot});
    case 'test':
      assert.equal(request.revision,1);
      assert.equal(request.state_revision,state.revision);
      assert.equal(request.email_fixture_checked,true);
      tested = true;
      return respond(detail(1));
    default: throw new Error('Unexpected Canvas request: '+request.action);
  }
});

try {
  await page.goto('https://canvas-email.test/');
  await page.evaluate(() => {
    const set = window.setTimeout.bind(window);
    window.setTimeout = (callback, delay, ...args) => set(callback,delay === 15000 ? 600 : delay,...args);
    window.STONEFELLOW_CHAT = {userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.VP3_CODING_CANVAS_V1 = {endpoint:'/api/coding',csrf:'fixture',agentId:7};
    window.STONEFELLOW_CHAT_CONTINUITY = {agentId:() => 7,conversationId:() => 42,isReady:() => true,
      isRestoring:() => false,showChatView:() => {}};
    window.opened = [];
    window.VP3GmailInboxInitialV1 = {openTriageItem: request => { window.opened.push(request); return true; }};
  });
  await page.addScriptTag({content:runtime});
  await page.addScriptTag({content:await fs.readFile(new URL('../coding-canvas-diff-v1.js',import.meta.url),'utf8')});
  for(const file of ['assets/vendor/acorn-8.19.0.js','coding-canvas-source-editor-v1.js'])await page.addScriptTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await page.addScriptTag({content:script});
  assert.equal(await page.evaluate(id => window.VP3CodingCanvasV1.open(id,1),canvas.id),true);
  const panel = page.locator('#chatCodingCanvasV1');
  const frame = panel.frameLocator('iframe');
  const original = frame.getByRole('button',{name:row.subject});
  await original.waitFor();
  assert.equal(await page.evaluate(() => window.opened.length),0,'No startup-initiated email open');
  await original.click();
  await page.waitForFunction(() => window.opened.length === 1);
  assert.deepEqual(await page.evaluate(() => window.opened),[{id:source,generation:9,binding:'fixture-binding',agent:7,conversation:42}]);
  await frame.getByRole('button',{name:'Try to save'}).click();
  await frame.getByText('Write denied',{exact:true}).waitFor();
  assert.equal(requests.some(request => request.action==='save_state'),false,'Email rows never use the saved app data path');

  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  await panel.getByRole('button',{name:'Test preview'}).click();
  await panel.getByRole('status').getByText('The email deadline test passed with sample data.').waitFor();
  await original.waitFor();
  snapshot = {...snapshot,rows:[{...row,stage:'reply_received',deadline_current:false}]};
  await frame.getByText('reply_received',{exact:false}).waitFor();
  snapshot = null;
  await frame.getByText('unavailable',{exact:true}).waitFor();
  assert.equal(await original.count(),0,'Revocation removes the original app iframe and all rendered email rows');

  assert.equal(await page.evaluate(id => window.VP3CodingCanvasV1.open(id,2),canvas.id),true);
  await panel.getByRole('button',{name:'Edit app',exact:true}).click();
  await panel.getByRole('button',{name:'Test preview'}).click();
  await panel.getByRole('status').getByText('The email deadline test failed.',{exact:false}).waitFor();
  assert.equal(requests.filter(request => request.action==='test').length,1,'Broken draft did not persist a passing test');
  console.log('Canvas Email runtime passed live update, exact source, read-only, revocation, and sample behavior checks.');
} finally {
  await browser.close();
}
