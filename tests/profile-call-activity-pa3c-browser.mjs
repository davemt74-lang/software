import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const asset = name => fs.readFileSync(new URL(`../${name}`, import.meta.url), 'utf8');
const assets = {
  '/profile-call-activity-pa3c.js': ['text/javascript', asset('profile-call-activity-pa3c.js')],
  '/profile-call-activity-pa3c.css': ['text/css', asset('profile-call-activity-pa3c.css')],
};
const server = http.createServer((request, response) => {
  const file = assets[request.url];
  response.writeHead(200, {'Content-Type': file?.[0] || 'text/html'});
  response.end(file?.[1] || `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/profile-call-activity-pa3c.css"><button id="other">Other chat action</button>
    <div id="sfChatSettingsRailSlotV132"><button id="settings">Settings</button></div>
    <div id="profileCallActivityPa3c" data-api="/activity" data-csrf="test-token" hidden></div>
    <script src="/profile-call-activity-pa3c.js"></script>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}`;
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS ' + label); }

async function fixture(browser, width, reducedMotion = 'no-preference', persistLostResearch = false) {
  const context = await browser.newContext({viewport: {width, height: 680}, reducedMotion});
  const page = await context.newPage();
  const posts = [];
  const reads = [];
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const payload = {
    state: 'listening', assistance_enabled: true, assistance_revision: 1, assistance_toggle_available: true, research_available: true,
    agent: {name: 'Longfellow', avatar_url: 'javascript:alert(1)'}, research: [],
    transcript_url: '/transcriptions.php?session=12',
  };
  let denied = false;
  let loseToggleResponse = false;
  let pendingResearch = null;
  let firstLost = true;
  await page.route('**/activity*', async route => {
    const request = route.request();
    const reply = (data, status = 200) => route.fulfill({status, contentType: 'application/json', body: JSON.stringify({ok: status === 200, ...data})});
    if (request.method() === 'POST') {
      const body = request.postDataJSON();
      posts.push({body, header: request.headers()['x-csrf-token']});
      if (body.action === 'set_assistance') {
        if (body.assistance_revision !== payload.assistance_revision) return reply({error: 'Assistance changed.'}, 409);
        payload.assistance_enabled = body.enabled;
        payload.state = body.enabled ? 'listening' : 'paused';
        payload.assistance_revision++;
        if (loseToggleResponse) { loseToggleResponse = false; return route.abort('failed'); }
        return reply({assistance_enabled: body.enabled, assistance_revision: payload.assistance_revision});
      }
      if (firstLost) {
        firstLost = false;
        if (persistLostResearch) payload.research = [{id: body.request_id, query: body.query, status: 'complete', summary: 'Recovered saved result'}];
        return route.abort('failed');
      }
      const row = {id: body.request_id, query: body.query, status: 'running'};
      payload.research = [row];
      payload.state = 'researching';
      await new Promise(resolve => { pendingResearch = resolve; });
      Object.assign(row, {status: 'complete', summary: 'City found <script>bad()</script>', sources: [
        {title: 'City archive', url: 'https://example.com/archive'},
        {title: 'Unsafe', url: 'javascript:alert(1)'},
      ]});
      payload.state = 'listening';
      return reply({});
    }
    const params = new URL(request.url()).searchParams;
    const id = params.get('call_id');
    const after = params.get('after');
    reads.push([id, after]);
    if (denied) return reply({}, 403);
    if (id === 'new-call' || !payload.transcript_url) return reply({...payload, call_id: id, segments: [], research: [], cursor: '0'});
    const segments = after === '0'
      ? [{id: 1, speaker: 'Visitor <img src=x>', text: 'Final <b>words</b>', start_ms: 12000}]
      : [{id: 1, speaker: 'Duplicate', text: 'Duplicate row', start_ms: 12000}, {id: 2, speaker: 'Owner', text: 'Earlier in call', start_ms: 9000}];
    return reply({...payload, call_id: id, segments, cursor: after === '0' ? 1 : 2, has_more: after === '0'});
  });
  await page.goto(url);
  const emit = (id, status, assisted = true) => page.evaluate(detail => {
    window.dispatchEvent(new CustomEvent('vp3:profile-call-assistance', {detail}));
  }, {id, status, assisted, agent: {name: 'Longfellow'}});
  const host = page.locator('#profileCallActivityPa3c');
  const launch = host.getByRole('button');
  const drawer = page.getByRole('dialog');
  const state = value => page.waitForFunction(expected => document.querySelector('#profileCallActivityPa3c').dataset.state === expected, value);
  const animation = () => launch.evaluate(button => getComputedStyle(button, '::after').animationName);
  return {page, context, host, launch, drawer, emit, state, animation, posts, reads,
    deny() { denied = true; }, discard() { payload.transcript_url = ''; }, finishResearch() { pendingResearch?.(); },
    advanceToggleRevision() { payload.assistance_revision += 2; }, loseToggleResponse() { loseToggleResponse = true; },
    async close() { check(!errors.length, `no uncaught browser errors ${errors.join(', ')}`); await context.close(); },
  };
}

let browser;
try {
  browser = await chromium.launch({headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
  const desktop = await fixture(browser, 1200);
  check(!await desktop.host.isVisible(), 'no icon before a call');
  await desktop.emit('plain-call', 'active', false);
  check(!await desktop.host.isVisible() && desktop.reads.length === 0, 'ordinary calls do not request activity');
  await desktop.emit('first-call', 'connecting');
  await desktop.state('listening');
  check(await desktop.host.evaluate(node => node.parentElement.id) === 'sfChatSettingsRailSlotV132', 'icon docks inside the actual desktop rail slot');
  check((await desktop.launch.getAttribute('aria-label')).includes('Longfellow') && await desktop.launch.locator('img').count() === 0, 'named icon uses the safe initial fallback');
  check(await desktop.animation() === 'vp3-call-activity-pulse', 'verified listening state pulses');
  await desktop.launch.click();
  check(await desktop.drawer.isVisible() && await desktop.launch.getAttribute('aria-expanded') === 'true', 'launch opens the labeled slide-out');
  await desktop.drawer.getByText('Earlier in call').waitFor();
  const rows = desktop.drawer.locator('[data-call-segments] > li');
  check(await rows.count() === 2 && (await rows.first().textContent()).includes('0:09') && (await rows.last().textContent()).includes('0:12'), 'cursor pagination orders timestamps and deduplicates overlapping segments');
  check((await rows.last().textContent()).includes('Visitor <img src=x>') && (await rows.last().textContent()).includes('Final <b>words</b>') && await rows.locator('img,b').count() === 0, 'transcript and speaker labels are inert text');
  check((await desktop.drawer.getByRole('link', {name: 'Open in Transcriptions'}).getAttribute('href')).endsWith('/transcriptions.php?session=12'), 'canonical transcript opens through a same-origin link');
  const toggle = desktop.drawer.getByRole('switch', {name: 'Agent assistance'});
  await toggle.uncheck();
  await desktop.state('paused');
  check(desktop.posts[0].body.call_id === 'first-call' && desktop.posts[0].body.enabled === false && desktop.posts[0].body.assistance_revision === 1 && desktop.posts[0].body.csrf === 'test-token' && desktop.posts[0].header === 'test-token', 'switch posts the exact call, current revision, and request token');
  check(await desktop.animation() === 'none' && await rows.count() === 2, 'authoritative pause stops the pulse and preserves the transcript');
  desktop.advanceToggleRevision();
  await toggle.click();
  await desktop.state('paused');
  check(desktop.posts[1].body.assistance_revision === 2 && !await toggle.isChecked(), 'a stale tab refetches the newer paused state without resuming assistance');
  desktop.loseToggleResponse();
  await toggle.click();
  await desktop.state('listening');
  check(desktop.posts[2].body.assistance_revision === 4 && await toggle.isChecked(), 'fresh revision resumes assistance and a lost reply reconciles through the activity feed');
  const research = desktop.drawer.getByRole('textbox', {name: 'Research a question'});
  const submit = desktop.drawer.getByRole('button', {name: 'Research', exact: true}).last();
  await research.fill('Find the city archive');
  await submit.click();
  await desktop.drawer.getByText(/request could not be confirmed/).waitFor();
  check(await research.inputValue() === 'Find the city archive', 'unconfirmed request keeps the draft');
  await submit.click();
  await desktop.state('researching');
  check(desktop.posts[3].body.request_id === desktop.posts[4].body.request_id && desktop.posts[4].body.query === 'Find the city archive', 'retry preserves request identity and sends only the typed query');
  check(await desktop.animation() === 'vp3-call-activity-pulse' && await submit.isDisabled(), 'verified research state pulses while duplicate submission is disabled');
  desktop.finishResearch();
  await desktop.drawer.getByText('City found <script>bad()</script>', {exact: true}).waitFor();
  const link = desktop.drawer.getByRole('link', {name: 'City archive'});
  check(await link.getAttribute('href') === 'https://example.com/archive' && (await link.getAttribute('rel')).includes('noreferrer') && await desktop.drawer.getByRole('link', {name: 'Unsafe'}).count() === 0, 'research safely renders verified source links and inert summary');
  await desktop.emit('first-call', 'ended');
  await desktop.state('ended');
  check(await desktop.animation() === 'none' && await rows.count() === 2 && !await toggle.isVisible(), 'ended call is static and remains readable');
  await desktop.page.keyboard.press('Escape');
  await desktop.page.waitForFunction(() => document.activeElement.classList.contains('vp3-call-launch'));
  check(!await desktop.drawer.isVisible(), 'Escape dismisses the panel and restores focus');
  desktop.discard();
  await desktop.launch.click();
  await desktop.drawer.getByText('No transcript was captured for this call.').waitFor();
  check(await rows.count() === 0 && await desktop.drawer.getByRole('link', {name: 'Open in Transcriptions'}).count() === 0, 'a removed canonical transcript is cleared on refresh');
  await desktop.emit('new-call', 'active');
  await desktop.state('listening');
  await desktop.launch.click();
  check(await desktop.drawer.locator('[data-call-segments] > li').count() === 0 && await desktop.drawer.getByRole('link', {name: 'City archive'}).count() === 0, 'another call clears previous transcript and research');
  await desktop.emit('first-call', 'active');
  check(await desktop.drawer.isVisible() && await desktop.drawer.locator('[data-call-segments] > li').count() === 0, 'late event for a replaced call cannot restore its contents');
  desktop.deny();
  await desktop.page.waitForFunction(() => document.querySelector('#profileCallActivityPa3c').hidden);
  check(!await desktop.drawer.isVisible() && !await desktop.host.isVisible(), 'authorization loss removes the drawer and launcher');
  await desktop.close();

  const mobile = await fixture(browser, 390, 'reduce', true);
  await mobile.emit('mobile-call', 'active');
  await mobile.state('listening');
  check(await mobile.host.evaluate(node => node.parentElement === document.body) && await mobile.animation() === 'none', 'mobile launcher remains visible outside the hidden rail and respects reduced motion');
  await mobile.launch.click();
  const bounds = await mobile.drawer.boundingBox();
  check(bounds.width === 390 && bounds.x === 0 && await mobile.drawer.evaluate(node => node.matches(':modal')), 'mobile drawer fills the viewport and traps focus natively');
  check(await mobile.drawer.locator('.vp3-call-scroll').evaluate(node => getComputedStyle(node).overflowY) === 'auto' && await mobile.drawer.evaluate(node => getComputedStyle(node).overflowY) === 'hidden', 'drawer has one vertical scrolling surface');
  const mobileQuery = mobile.drawer.getByRole('textbox', {name: 'Research a question'});
  await mobileQuery.fill('Recover a committed search');
  await mobile.drawer.getByRole('button', {name: 'Research', exact: true}).last().click();
  await mobile.drawer.getByText('Recovered saved result', {exact: true}).waitFor();
  check(await mobileQuery.inputValue() === '' && mobile.posts.length === 1 && !await mobile.drawer.getByText(/request could not be confirmed/).isVisible(), 'a committed search with a lost response reconciles from its exact receipt');
  await mobile.drawer.getByRole('button', {name: 'Close call activity'}).click();
  check(!await mobile.drawer.isVisible(), 'mobile close is operable');
  await mobile.close();
  console.log(`PROFILE_CALL_ACTIVITY_PA3C_BROWSER=PASS ${checks} checks`);
} finally {
  await browser?.close();
  await new Promise(resolve => server.close(resolve));
}
