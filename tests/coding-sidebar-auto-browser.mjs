import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:390,height:800}});
const errors = [];
const writes = [];
page.on('pageerror', error => errors.push(error.message));
const enabledByAgent = {17:false,18:false};
let canEnable = true;
let available = true;
let revision = 'a'.repeat(32);
let failedSave = false;
let holdRead = null;
const states = agent => ({owner_user_id:1,agent_id:agent,enabled:available && enabledByAgent[agent],
  initiative_on:true,available,can_enable:canEnable,revision,reason:available ? '' : 'Enable Initiative in a saved Chat first.'});
await page.route('https://coding.test/', route => route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}aside{width:280px;box-sizing:border-box;padding:8px}</style>
<aside id="chatSidebar" data-csrf="fixture"><section class="coding-sidebar-control" data-coding-sidebar="/api/models" data-coding-owner="1" data-coding-auto-endpoint="/api/auto">
<label for="model">Coding Agent model</label><select id="model"></select>
<label class="coding-sidebar-auto"><input type="checkbox" data-coding-auto-drafts><span>Auto Build drafts</span></label>
<small class="coding-sidebar-auto-help">Uses your coding model and tokens. Saves drafts for review.</small>
<p data-coding-auto-status role="status" class="coding-sidebar-status" hidden></p>
<strong data-coding-funding></strong><span data-coding-balance-label></span><strong data-coding-balance></strong><meter data-coding-token-meter></meter>
<div><span data-coding-credits></span></div><div><span data-coding-reserved></span></div><div><span data-coding-period-label></span><time data-coding-period></time></div>
<p id="vp3CodingSidebarStatus" role="status" hidden></p></section></aside>`}));
await page.route('https://coding.test/api/models', route => route.fulfill({json:{ok:true,state:{funding:'system',selected:null,
  default:{provider:'openai',model:'fixture',label:'Fixture coder'},options:[{provider:'openai',model:'fixture',label:'Fixture coder'}],unavailable:''},
  balance:{unlimited:false,package_allowance:10000,package_remaining:7000,credits_remaining:500,reserved:0,period_end:null}}}));
await page.route('https://coding.test/api/auto**', async route => {
  const request = route.request();
  const agent = request.method() === 'POST' ? request.postDataJSON().agent_id : Number(new URL(request.url()).searchParams.get('agent_id'));
  assert.ok([17,18].includes(agent));
  if (request.method() === 'POST') {
    const body = request.postDataJSON();
    assert.deepEqual(Object.keys(body).sort(),['agent_id','csrf_token','enabled','revision']);
    assert.equal(body.csrf_token,'fixture');
    assert.equal(typeof body.enabled,'boolean');
    writes.push(body);
    if (failedSave) return route.fulfill({status:503,json:{ok:false,error:'Please try again.'}});
    assert.equal(body.revision, revision);
    enabledByAgent[agent] = body.enabled;
    revision = body.enabled ? 'b'.repeat(32) : 'c'.repeat(32);
  } else if (holdRead) {
    const held = holdRead;
    holdRead = null;
    held.started();
    await held.release;
  }
  try { await route.fulfill({json:{ok:true,state:states(agent)}}); } catch {}
});
try {
  await page.goto('https://coding.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT = {userId:1,agentId:17};
    window.VP3ParticipantContinuity = {selectedContext:() => window.participant ? {participant_id:8} : null};
  });
  await page.addStyleTag({content:await fs.readFile(new URL('../coding-canvas-sidebar-v1.css',import.meta.url),'utf8')});
  await page.addScriptTag({content:await fs.readFile(new URL('../coding-canvas-sidebar-v1.js',import.meta.url),'utf8')});
  assert.equal(await page.getByRole('checkbox',{name:'Auto Build drafts'}).count(),1);
  const toggle = page.locator('[data-coding-auto-drafts]');
  const autoStatus = page.locator('[data-coding-auto-status]');
  await page.waitForFunction(() => !document.querySelector('[data-coding-auto-drafts]').disabled);
  assert.equal(await toggle.isChecked(),false,'Auto Build starts off.');
  assert.equal(await page.locator('[data-coding-balance]').textContent(),'7,500','The one token figure includes both plan tokens and extra credits.');
  await toggle.check();
  await autoStatus.getByText('Saved.',{exact:true}).waitFor();
  assert.equal(await toggle.isChecked(),true);
  assert.equal(writes.length,1);
  assert.equal(writes[0].agent_id,17);
  assert.equal(await page.locator('[data-coding-token-meter]').evaluate(node => node.value),7000,'Toggling leaves model and token controls intact.');

  failedSave = true;
  await toggle.click();
  await autoStatus.getByText('Please try again.',{exact:true}).waitFor();
  assert.equal(await toggle.isChecked(),true,'A failed save restores the persisted preference.');
  failedSave = false;
  canEnable = false;
  const unavailableRefresh = page.waitForResponse(response =>
    response.url() === 'https://coding.test/api/auto?agent_id=17' && response.request().method() === 'GET');
  await page.evaluate(() => dispatchEvent(new Event('vp3-coding-usage-change')));
  await unavailableRefresh;
  // Refresh debounces for 350ms and disables the control until its response is applied.
  await page.waitForFunction(() => {
    const toggle = document.querySelector('[data-coding-auto-drafts]');
    return !toggle.disabled && toggle.checked;
  });
  assert.equal(await toggle.isEnabled(),true,'An existing opt-in remains switchable off when AI access is unavailable.');
  await toggle.uncheck();
  await page.waitForFunction(() => document.querySelector('[data-coding-auto-drafts]').disabled);
  assert.equal(await toggle.isChecked(),false);

  await page.evaluate(() => { window.participant=true; dispatchEvent(new Event('vp3:participant-selection-changed')); });
  assert.equal(await page.locator('[data-coding-sidebar]').isHidden(),true);
  assert.equal(await toggle.isChecked(),false);
  const count = writes.length;
  await page.evaluate(() => document.querySelector('[data-coding-auto-drafts]').dispatchEvent(new Event('change')));
  assert.equal(writes.length,count,'A selected participant cannot save the owner preference.');
  canEnable = true;
  await page.evaluate(() => { window.participant=false; dispatchEvent(new Event('vp3:participant-selection-changed')); });
  await page.waitForFunction(() => !document.querySelector('[data-coding-auto-drafts]').disabled);

  const writesBeforeRestore = writes.length;
  await page.evaluate(() => {
    STONEFELLOW_CHAT.agentId=18;
    const toggle=document.querySelector('[data-coding-auto-drafts]');
    toggle.checked=true;
    toggle.dispatchEvent(new Event('change'));
  });
  assert.equal(writes.length,writesBeforeRestore,'A configuration change before the ready event cannot POST the old Agent’s preference.');
  await page.waitForFunction(() => !document.querySelector('[data-coding-auto-drafts]').disabled);
  await page.evaluate(() => { STONEFELLOW_CHAT.agentId=17; dispatchEvent(new Event('vp3:chat-context-ready')); });
  await page.waitForFunction(() => !document.querySelector('[data-coding-auto-drafts]').disabled);

  let arrived, release;
  const started = new Promise(resolve => { arrived=resolve; });
  const waiting = new Promise(resolve => { release=resolve; });
  holdRead = {started:arrived,release:waiting};
  await page.evaluate(() => dispatchEvent(new Event('vp3-coding-usage-change')));
  await started;
  await page.evaluate(() => { STONEFELLOW_CHAT.agentId=18; dispatchEvent(new Event('vp3:chat-context-ready')); });
  release();
  await page.waitForFunction(() => !document.querySelector('[data-coding-auto-drafts]').disabled);
  await toggle.check();
  await autoStatus.getByText('Saved.',{exact:true}).waitFor();
  assert.equal(writes.at(-1).agent_id,18,'A late former-Agent read cannot retarget a later save.');
  assert.equal(await toggle.isChecked(),true,'The response reflects the saved preference for Agent18.');
  assert.equal(enabledByAgent[17],false,'Saving another Agent does not change the former Agent’s preference.');

  available = false;
  await page.evaluate(() => dispatchEvent(new Event('vp3-coding-usage-change')));
  await autoStatus.getByText('Enable Initiative in a saved Chat first.',{exact:true}).waitFor();
  assert.equal(await toggle.isEnabled(),false);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,'The control fits a mobile sidebar.');
  assert.deepEqual(errors,[]);
  console.log('CODING_SIDEBAR_AUTO_BROWSER=PASS opt-in, exact Agent, rollback, opt-out, participant fence, stale reads and mobile fit');
} finally { await browser.close(); }
