import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const asset = name => fs.readFile(new URL('../' + name, import.meta.url), 'utf8');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const page = await browser.newPage({viewport: {width: 320, height: 740}});
const requests = [];
const errors = [];
let mode = 'items';
let releaseRequest;
const html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  + '<link rel="stylesheet" href="/chat-catch-up-v1.css">'
  + '<style>.chat-thread{height:460px;overflow:auto}.message{min-height:58px}</style>'
  + '<main><section class="chat-thread" id="chatThread" data-view="chat">'
  + '<div class="message" id="chatWelcome">Welcome</div></section>'
  + '<form id="chatForm"><textarea id="chatInput">Draft</textarea></form></main>'
  + '<script src="/chat-catch-up-v1.js" data-endpoint="/api/agent-catch-up-v1.php" data-agent-id="7"></script>';
const records = [
  {id: 'office:1', title: 'Check your calendar <img src=x>', reason: 'Two commitments overlap.', source_label: 'Open calendar', url: '/calendar.php?event=3'},
  {id: 'store:2', title: 'Review a campaign', reason: 'Delivery needs attention.', source_label: 'Open campaign', url: '/campaigns.php?id=2'},
  {id: 'office:3', title: 'Task due', reason: 'Due today.', source_label: 'Open task', url: '/tasks.php?id=3'},
  {id: 'store:4', title: 'Fourth item', reason: 'Outside limit.', source_label: 'Open order', url: '/orders.php'}
];
page.on('pageerror', error => errors.push(error.message));
await page.route('https://catch-up.test/', route => route.fulfill({contentType: 'text/html', body: html}));
await page.route('**/chat-catch-up-v1.js', async route => route.fulfill({contentType: 'text/javascript', body: await asset('chat-catch-up-v1.js')}));
await page.route('**/chat-catch-up-v1.css', async route => route.fulfill({contentType: 'text/css', body: await asset('chat-catch-up-v1.css')}));
await page.route('**/api/agent-catch-up-v1.php?*', async route => {
  requests.push({url: route.request().url(), method: route.request().method()});
  if (mode === 'failed') return route.fulfill({status: 503, json: {ok: false, error: 'Private service error'}});
  if (mode === 'slow') await new Promise(resolve => { releaseRequest = resolve; });
  const items = mode === 'unsafe' ? [
    records[0], {...records[1], url: '//external.test/campaigns'}, {...records[2], url: 'javascript:alert(1)'}
  ] : ['items', 'slow'].includes(mode) ? records : [];
  const coverage = {office: 'ready', commerce: mode === 'empty' ? 'ready' : 'limited'};
  await route.fulfill({json: {ok: true, brief: {items, coverage, as_of: '2026-11-04T12:00:00Z'}}}).catch(() => {});
});
const card = page.locator('[data-chat-catch-up]');
const status = card.getByRole('status');
try {
  await page.goto('https://catch-up.test/');
  assert.equal(await card.count(), 1);
  assert.equal(await card.getAttribute('open'), null);
  assert.equal(requests.length, 0);
  const reachableAfterRestore = await page.evaluate(() => {
    const thread = document.getElementById('chatThread');
    for (let index = 0; index < 40; index++) {
      const message = document.createElement('div');
      message.className = 'message';
      message.textContent = 'Restored chat message ' + index;
      thread.append(message);
    }
    thread.scrollTop = thread.scrollHeight;
    const control = thread.querySelector('[data-chat-catch-up] summary');
    const {left, top, width, height} = control.getBoundingClientRect();
    return thread.scrollTop > 0 && document.elementFromPoint(left + width / 2, top + height / 2) === control;
  });
  assert.equal(reachableAfterRestore, true, 'The collapsed control remains actionable at the bottom of a restored Chat');
  assert.equal(requests.length, 0);
  await card.locator('summary').click();
  await card.getByText('Two commitments overlap.', {exact: true}).waitFor();
  assert.deepEqual(requests, [{url: 'https://catch-up.test/api/agent-catch-up-v1.php?agent_id=7', method: 'GET'}]);
  assert.equal(await card.locator('li').count(), 3);
  assert.equal(await card.locator('img').count(), 0);
  assert.equal(await card.getByRole('link').count(), 3);
  assert.equal(await card.getByRole('link', {name: 'Open calendar'}).getAttribute('href'), 'https://catch-up.test/calendar.php?event=3');
  assert.equal(await status.innerText(), 'Some sources couldn’t be checked.');
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  assert.equal(await page.locator('#chatInput').inputValue(), 'Draft');
  console.log('PASS explicit mobile brief request, bounded source-linked rows, safe content and intact Chat');

  mode = 'unsafe';
  await card.getByRole('button', {name: 'Refresh'}).click();
  await status.getByText('Some priority updates couldn’t be displayed.').waitFor();
  assert.equal(await card.locator('li').count(), 1);
  assert.equal(await card.getByRole('link').count(), 1);
  mode = 'empty';
  await card.getByRole('button', {name: 'Refresh'}).click();
  await status.getByText('Nothing needs your attention right now.').waitFor();
  assert.equal(await card.locator('li').count(), 0);
  mode = 'partial';
  await card.getByRole('button', {name: 'Refresh'}).click();
  await status.getByText('No priority updates from the sources checked. Some sources couldn’t be checked.').waitFor();
  mode = 'failed';
  await card.getByRole('button', {name: 'Refresh'}).click();
  await status.getByText('Couldn’t check for updates. Try refreshing.').waitFor();
  assert.doesNotMatch(await card.innerText(), /Private service error/);
  console.log('PASS refresh distinguishes a quiet brief, partial coverage, and unavailable data');

  mode = 'slow';
  await card.getByRole('button', {name: 'Refresh'}).click();
  await status.getByText('Checking for priority updates…').waitFor();
  for (let attempt = 0; !releaseRequest && attempt < 40; attempt++) {
    await new Promise(resolve => setTimeout(resolve, 25));
  }
  assert.equal(typeof releaseRequest, 'function');
  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'music';
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
  });
  releaseRequest();
  assert.equal(await card.isVisible(), false);
  assert.equal(await card.getAttribute('open'), null);
  assert.equal(await card.locator('li').count(), 0);
  await page.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'chat';
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
  });
  assert.equal(await card.isVisible(), true);
  assert.equal(await card.getAttribute('open'), null);
  assert.equal(requests.length, 6);
  assert.deepEqual(errors, []);
  console.log('PASS leaving Chat cancels a request and keeps the brief collapsed when returning');
} finally {
  await browser.close();
}
