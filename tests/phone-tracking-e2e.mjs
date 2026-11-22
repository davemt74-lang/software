import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { cpSync, mkdirSync, mkdtempSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

const repo = fileURLToPath(new URL('..', import.meta.url));
const require = createRequire(import.meta.url);
const { chromium, webkit, devices, request } = require(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const php = process.env.VP3_PHP_BINARY || 'php';
const temp = mkdtempSync(path.join(tmpdir(), 'vp3-phone-e2e-'));
const site = path.join(temp, 'web');
const sessions = path.join(temp, 'sessions');
const gps = { latitude: 33.44838, longitude: -112.07404, accuracy: 12 };
const phoneEndpoint = '/api/phone-location-v1.php';
const ownerEndpoint = '/api/phone-tracking-v1.php';
const capture = process.env.VP3_PHONE_SCREENSHOTS;
let browser, mobileBrowser, server;
let serverErrors = '';

async function localPort() {
  const listener = createServer();
  await new Promise((resolve, reject) => listener.once('error', reject).listen(0, '127.0.0.1', resolve));
  const { port } = listener.address();
  await new Promise(resolve => listener.close(resolve));
  return port;
}

function prepareSite() {
  mkdirSync(site);
  mkdirSync(sessions);
  for (const folder of ['includes', 'api']) cpSync(path.join(repo, folder), path.join(site, folder), { recursive: true });
  for (const folder of ['assets', 'images']) symlinkSync(path.join(repo, folder), path.join(site, folder), 'dir');
  for (const entry of readdirSync(repo, { withFileTypes: true })) {
    if (entry.isFile() && entry.name !== 'config.php' && /\.(php|css|js)$/.test(entry.name)) {
      cpSync(path.join(repo, entry.name), path.join(site, entry.name));
    }
  }
  mkdirSync(path.join(site, 'private'));
  mkdirSync(path.join(site, 'uploads'));
  writeFileSync(path.join(site, 'config.php'), "<?php return ['db'=>['dsn'=>'sqlite:'.dirname(__DIR__).'/data.sqlite'],'site'=>['name'=>'VP3 Test','base_path'=>'']];");
  writeFileSync(path.join(site, 'health.txt'), 'ready');
  const fixture = path.join(repo, 'tests/phone-tracking-e2e-fixture.php');
  return JSON.parse(execFileSync(php, ['-d', 'session.save_path=' + sessions, fixture, site], { encoding: 'utf8' }));
}

async function eventually(message, callback, ms = 20000) {
  const deadline = Date.now() + ms;
  let lastError;
  do {
    try { if (await callback()) return; } catch (error) { lastError = error; }
    await delay(200);
  } while (Date.now() < deadline);
  throw new Error(message, { cause: lastError });
}

async function read(response) {
  const status = response.status();
  const text = await response.text();
  assert.match(response.headers()['content-type'] || '', /application\/json/, 'Expected JSON, received HTTP ' + status);
  return { status, ...JSON.parse(text) };
}

async function ownerApi(context, user, action = 'state', values = {}) {
  const headers = { 'X-VP3-Working-Attention': user.binding };
  if (action === 'state') return read(await context.request.get(ownerEndpoint, { headers }));
  return read(await context.request.post(ownerEndpoint, { headers,
    data: { action, csrf_token: user.csrf, request_id: randomUUID(), ...values } }));
}

async function chatApi(context, user, action, values = {}) {
  return read(await context.request.post('/api/chat.php', {
    headers: { 'X-VP3-Working-Attention': user.binding },
    data: { csrf_token: user.csrf, action, conversation_id: 8, agent_context: { user_agent_id: 0 }, ...values }
  }));
}

async function phoneApi(context, action, values = {}) {
  return read(await context.request.post(phoneEndpoint, { headers: { 'X-VP3-Phone': '1' }, data: { action, ...values } }));
}

function check(result, label) {
  assert.equal(result.ok, true, label + ' returned ' + result.status + ' ' + (result.error || ''));
  return result;
}

async function screenshot(page, name) {
  if (!capture) return;
  mkdirSync(capture, { recursive: true });
  await page.screenshot({ path: path.join(capture, name + '.png'), animations: 'disabled' });
}

try {
  const [owner, other] = prepareSite();
  const port = await localPort();
  const baseURL = 'http://127.0.0.1:' + port;
  server = spawn(php, ['-d', 'session.save_path=' + sessions, '-d', 'display_errors=0',
    '-S', '127.0.0.1:' + port, '-t', site], { stdio: ['ignore', 'ignore', 'pipe'] });
  server.stderr.on('data', data => {
    for (const line of data.toString().split('\n')) {
      if (/Fatal error|Uncaught |Parse error/.test(line)) serverErrors += line + '\n';
    }
  });
  await eventually('PHP server did not start', async () => (await fetch(baseURL + '/health.txt')).ok);
  // These clients also exercise cookie scope and API protection independently of the UI.
  const apiOwner = { request: await request.newContext({ baseURL, storageState: { cookies: [{
    name: 'PHPSESSID', value: owner.session, domain: '127.0.0.1', path: '/', expires: -1, httpOnly: true,
    secure: false, sameSite: 'Lax' }], origins: [] } }) };
  const firstGuest = { request: await request.newContext({ baseURL }) };
  const requestId = randomUUID();
  const deniedInvite = check(await ownerApi(apiOwner, owner, 'invite', { request_id: requestId }), 'create guest invitation');
  const sameInvite = check(await ownerApi(apiOwner, owner, 'invite', { request_id: requestId }), 'retry create invitation');
  assert.equal(sameInvite.duplicate, true);
  assert.ok(!sameInvite.invite);
  const denied = check(await phoneApi(firstGuest, 'claim', { invite: deniedInvite.invite, name: 'Declined phone' }), 'guest claim').device;
  const deniedCommand = { device_id: denied.id, generation: denied.generation, request_id: randomUUID() };
  check(await ownerApi(apiOwner, owner, 'deny', deniedCommand), 'decline guest');
  assert.equal(check(await ownerApi(apiOwner, owner, 'deny', deniedCommand), 'repeat decline').duplicate, true);
  assert.equal((await phoneApi(firstGuest, 'status')).status, 403);
  assert.deepEqual(check(await ownerApi(apiOwner, owner), 'post-decline read').devices, []);
  const expiring = check(await ownerApi(apiOwner, owner, 'invite'), 'expiring invitation');
  execFileSync(php, ['-r', "(new PDO('sqlite:'.$argv[1]))->exec('UPDATE vp3_phone_invites_v1 SET expires_at=0 WHERE claimed_device_id IS NULL');", path.join(temp, 'data.sqlite')]);
  assert.equal((await phoneApi(firstGuest, 'claim', { invite: expiring.invite, name: 'Too late' })).status, 403);
  const pair = check(await ownerApi(apiOwner, owner, 'invite'), 'route invite');
  const pending = check(await phoneApi(firstGuest, 'claim', { invite: pair.invite, name: 'HTTP phone' }), 'route claim').device;
  const command = device => ({ device_id: device.id, generation: device.generation });
  const approved = check(await ownerApi(apiOwner, owner, 'approve', { ...command(pending), role: 'guest', allow_location: true }), 'approve').device;
  const chatOrigin = { agent_id: 0, conversation_id: 8 };
  const asked = check(await ownerApi(apiOwner, owner, 'request_start', { ...command(approved), chat_origin: chatOrigin }), 'ask phone from map in saved Chat').device;
  const activity = async (conversation_id = 8) => check(await chatApi(apiOwner, owner, 'activity', { phone_updates: true, conversation_id }), 'read Chat activity').phone_updates;
  assert.deepEqual(await activity(), []);
  assert.equal(asked.location_state, 'requested');
  assert.equal(asked.latitude, null);
  const waiting = check(await phoneApi(firstGuest, 'start', command(asked)), 'start on phone').device;
  assert.equal(waiting.location_state, 'waiting');
  const fix = { ...command(waiting), ...gps, fix_at: Math.floor(Date.now()/1000) };
  const live = check(await phoneApi(firstGuest, 'fix', fix), 'first fix').device;
  assert.equal(live.first_fix_at, fix.fix_at);
  assert.equal(live.location_state, 'live');
  assert.equal(live.accuracy, gps.accuracy);
  assert.deepEqual(await activity(9), []);
  const announcements = await activity();
  assert.equal(announcements.length, 1);
  assert.match(announcements[0].speech, /HTTP phone.*is now sharing GPS/i);
  assert.ok(!JSON.stringify(announcements).includes(String(gps.latitude)));
  assert.deepEqual(await activity(), []);
  const chatStop = check(await chatApi(apiOwner, owner, 'send', { message: 'Stop sharing from HTTP phone.', input_mode: 'voice', idempotency_key: randomUUID() }), 'stop by voice command');
  assert.match(chatStop.answer, /stopped/i);
  const stopped = check(await phoneApi(firstGuest, 'status'), 'read after spoken stop').device;
  assert.equal(stopped.latitude, null);
  assert.equal((await phoneApi(firstGuest, 'fix', fix)).status, 409);
  check(await ownerApi(apiOwner, owner, 'revoke', command(stopped)), 'revoke');
  assert.equal((await phoneApi(firstGuest, 'status')).status, 403);
  assert.deepEqual(check(await ownerApi(apiOwner, owner), 'final read').devices, []);
  const mapRoute = await apiOwner.request.get('/phone-tracking.php?agent=system&conversation_id=8');
  assert.equal(mapRoute.status(), 200, 'Map route must render for a signed-in account with chat access');
  assert.match(await mapRoute.text(), /id="phone-connect"/);
  const mobileRoute = await firstGuest.request.get('/phone-location.php');
  assert.equal(mobileRoute.status(), 200);
  assert.match(await mobileRoute.text(), /id="phone-claim"/);

  await apiOwner.request.dispose();
  await firstGuest.request.dispose();
  console.log('PASS Phone Tracking HTTP acceptance: production routes, QR expiry and retry, denial, Chat-linked map approval, one scoped first-fix announcement, voice stop, late callback and revocation.');
  if (!process.env.VP3_PHONE_HTTP_ONLY) await browserJourney(baseURL, owner, other);
} catch (error) {
  if (serverErrors) console.error(serverErrors.slice(-4000));
  throw error;
} finally {
  if (mobileBrowser && mobileBrowser !== browser) await mobileBrowser.close();
  if (browser) await browser.close();
  if (server && server.exitCode === null) {
    const closed = new Promise(resolve => server.once('close', resolve));
    server.kill();
    await closed;
  }
  rmSync(temp, { recursive: true, force: true });
}

async function browserJourney(baseURL, owner, other) {
  browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
  const desktop = await browser.newContext({ baseURL, viewport: { width: 1400, height: 960 } });
  const outsider = await browser.newContext({ baseURL });
  mobileBrowser = process.env.VP3_PHONE_WEBKIT ? await webkit.launch() : browser;
  const handset = await mobileBrowser.newContext({ ...devices['iPhone 13'], baseURL,
    geolocation: { ...gps, latitude: gps.latitude - 0.01 }, permissions: ['geolocation'], deviceScaleFactor: 1 });
  const newcomer = await browser.newContext({ baseURL });
  for (const [context, user] of [[desktop, owner], [outsider, other]]) {
    await context.addCookies([{ name: 'PHPSESSID', value: user.session, url: baseURL }]);
  }
  const map = await desktop.newPage();
  const phone = await handset.newPage();
  const errors = [];
  for (const page of [map, phone]) page.on('pageerror', error => errors.push(error.message));
  async function startWithFreshSensorReading(reading) {
    const started = phone.waitForResponse(response =>
      new URL(response.url()).pathname === phoneEndpoint && response.request().postDataJSON()?.action === 'start');
    await phone.locator('#phone-start').click();
    check(await read(await started), 'start from the handset');
    // A moving phone supplies a different position after the server accepts the grant.
    // WebKit may suppress repeated readings at identical coordinates.
    await handset.setGeolocation(reading);
  }
  let gpsRequests = 0;
  const observations = [];
  const apiEvents = [];
  phone.on('response', response => {
    if (new URL(response.url()).pathname !== phoneEndpoint) return;
    const action = response.request().postDataJSON()?.action;
    apiEvents.push([action, response.status()]);
    if (apiEvents.length > 25) apiEvents.shift();
  });
  await handset.exposeBinding('countLocationRequest', () => { gpsRequests++; });
  await handset.exposeBinding('observeSensor', (_, observation) => {
    observations.push(observation);
    if (observations.length > 12) observations.shift();
  });
  async function requireLiveFix(label) {
    let latest;
    try {
      await eventually(label, async () => {
        [latest] = check(await ownerApi(desktop, owner), 'live read').devices;
        return latest?.location_state === 'live';
      });
      return latest;
    } catch (error) {
      // Diagnostics contain no coordinates, tokens, cookies, or user-entered names.
      const client = await phone.evaluate(async () => ({
        secure: window.isSecureContext,
        visible: document.visibilityState,
        permission: await navigator.permissions?.query({ name:'geolocation' }).then(r => r.state).catch(() => 'unknown'),
        gps: document.querySelector('#phone-gps')?.textContent,
        status: document.querySelector('#phone-status')?.textContent
      }));
      console.error('Handset diagnostics', JSON.stringify({ client, gpsRequests, observations, apiEvents,
        state: latest?.location_state, generation: latest?.generation }));
      throw error;
    }
  }
  await handset.addInitScript(usesWebKitFixture => {
    for (const method of ['getCurrentPosition', 'watchPosition']) {
      const prototype = Object.getPrototypeOf(navigator.geolocation);
      const original = prototype[method];
      prototype[method] = function (...args) {
        window.countLocationRequest();
        const [success, failure, options] = args;
        return original.call(this, position => {
          // Playwright's WebKit driver sends epoch milliseconds to a simulator that
          // interprets them as seconds. Convert only its microsecond-sized samples.
          // Retain the captured instant so cached and future readings stay rejectable.
          const timestamp = usesWebKitFixture && position.timestamp >= 1e15
            ? position.timestamp / 1000 : position.timestamp;
          window.observeSensor({ kind: 'position', ageMs: Math.round(Date.now() - timestamp),
            valid: [position.coords.latitude, position.coords.longitude, position.coords.accuracy].every(Number.isFinite) });
          success(timestamp === position.timestamp ? position : { coords: position.coords, timestamp });
        }, error => {
          window.observeSensor({ kind: 'error', code: error.code });
          if (failure) failure(error);
        }, options);
      };
    }
  }, Boolean(process.env.VP3_PHONE_WEBKIT));
  const initial = check(await ownerApi(desktop, owner), 'owner read');
  assert.deepEqual(initial.devices, []);
  assert.equal((await newcomer.request.get(ownerEndpoint)).status(), 401);
  assert.equal((await desktop.request.get(ownerEndpoint)).status(), 403);
  assert.equal((await ownerApi(desktop, owner, 'invite', { csrf_token: 'stale-session' })).status, 419);
  assert.equal((await phoneApi(newcomer, 'status')).status, 401);
  await map.goto('/phone-tracking.php?agent=system&conversation_id=8');
  await map.locator('#phone-connect').click();
  await map.locator('#phone-qr svg').waitFor();
  const join = new URL(await map.locator('#phone-join').getAttribute('href'), baseURL);
  const invite = new URLSearchParams(join.hash.slice(1)).get('invite');
  assert.ok(invite && join.search === '' && join.origin === baseURL);
  // Decode the pixels a camera sees, including the rendered size and contrast.
  const { PNG } = require('pngjs');
  const decodeQR = require('jsqr');
  const pixels = PNG.sync.read(await map.locator('#phone-qr svg').screenshot());
  const decoded = decodeQR(new Uint8ClampedArray(pixels.data), pixels.width, pixels.height);
  assert.ok(decoded && decoded.data === join.href, 'Rendered QR must decode to the phone invitation');
  const navigations = [];
  phone.on('request', request => navigations.push([request.url(), request.headers()['referer'] || '']));
  await phone.goto(join.href);
  await phone.locator('#phone-name').fill('Field iPhone');
  const claimSent = phone.waitForRequest(r => r.url().endsWith(phoneEndpoint) && r.postDataJSON()?.action === 'claim');
  await phone.locator('#phone-claim').click();
  const claim = (await claimSent).postDataJSON();
  let device;
  await eventually('Claim never appeared in owner state', async () => {
    [device] = check(await ownerApi(desktop, owner), 'pending read').devices;
    return device?.status === 'pending';
  });
  assert.equal(device.name, 'Field iPhone');
  assert.equal(device.latitude, null);
  const deviceId = device.id;
  const recovered = check(await phoneApi(handset, 'claim', { invite, name: claim.name, claim_key: claim.claim_key }), 'repeat claim');
  assert.equal(recovered.device.id, deviceId);
  assert.equal(recovered.token, undefined);
  assert.equal((await phoneApi(newcomer, 'claim', { invite, name: 'Second phone' })).ok, false);
  assert.equal((await phoneApi(handset, 'start', { device_id: deviceId, generation: device.generation })).ok, false);
  assert.deepEqual(check(await ownerApi(outsider, other), 'other account read').devices, []);
  assert.equal((await ownerApi(outsider, other, 'approve', { device_id: deviceId, generation: device.generation })).ok, false);
  const cookie = (await handset.cookies()).find(c => c.name === 'vp3_phone_device_v1');
  assert.ok(cookie?.httpOnly && cookie.sameSite === 'Strict' && cookie.path === phoneEndpoint);
  await map.locator('#phone-pending [data-action=approve]').click();
  await map.locator('#phone-devices [data-device-id] button[data-focus]').first().click();
  await map.locator('#phone-detail [data-action=request_start]').click();
  await eventually('Phone missed start request', async () =>
    check(await phoneApi(handset, 'status'), 'phone read').device.location_state === 'requested');
  assert.equal(gpsRequests, 0, 'Pairing and owner request must leave phone geolocation idle');
  assert.equal(await map.locator('#phone-map .phone-map-marker').count(), 0);
  await phone.locator('#phone-start').waitFor();
  assert.match(await phone.locator('#phone-owner').innerText(), /Map Operator/);
  assert.ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'iPhone page must fit its viewport');
  await screenshot(phone, 'phone-before-consent');
  await startWithFreshSensorReading(gps);
  device = await requireLiveFix('No real browser geolocation fix reached the owner');
  assert.deepEqual([device.latitude, device.longitude, device.accuracy], [gps.latitude, gps.longitude, gps.accuracy]);
  assert.ok(device.first_fix_at && device.fix_at >= device.first_fix_at && Math.abs(Date.now()/1000 - device.fix_at) < 60);
  assert.ok(gpsRequests > 0);
  await map.locator('#phone-map .phone-map-marker').first().waitFor({ state: 'visible', timeout: 25000 });
  const detail = await map.locator('#phone-detail').innerText();
  assert.ok(detail.includes(gps.latitude.toFixed(5)) && detail.includes(gps.longitude.toFixed(5)));
  assert.match(detail, /±\s*12\s*m/);
  assert.match(detail, /Location updated/);
  await map.locator('#phone-detail [data-action=follow]').click();
  assert.equal(await map.locator('#phone-detail [data-action=follow]').getAttribute('aria-pressed'), 'true');
  await map.locator('.phone-tracking-main').evaluate(element => { element.scrollTop = 0; });
  await screenshot(map, 'owner-live-map');
  await screenshot(phone, 'phone-sharing');
  assert.ok(navigations.every(([url, referrer]) => !url.includes(invite) && !referrer.includes(invite)));
  await map.locator('#phone-detail [data-action=stop]').click();
  await eventually('Owner stop did not clear coordinates', async () => {
    const current = check(await ownerApi(desktop, owner), 'stopped read').devices[0];
    return current.location_state === 'off' && current.latitude === null && current.fix_at === null;
  });
  const late = await phoneApi(handset, 'fix', { device_id: deviceId, generation: device.generation, ...gps, fix_at: Math.floor(Date.now()/1000) });
  assert.equal(late.status, 409);
  await map.locator('#phone-map .phone-map-marker').first().waitFor({ state: 'hidden' });
  await phone.reload();
  await startWithFreshSensorReading({ ...gps, longitude: gps.longitude + 0.001 });
  await requireLiveFix('Phone did not resume after deliberate local restart');
  await phone.locator('#phone-stop').click();
  await eventually('Phone stop did not reach owner', async () =>
    check(await ownerApi(desktop, owner), 'phone stop read').devices[0]?.location_state === 'off');
  await map.locator('#phone-detail [data-action=revoke]').click();
  await map.locator('#phone-revoke-accept').click();
  await eventually('Revoked phone still has authority', async () => (await phoneApi(handset, 'status')).status === 403);
  assert.deepEqual(check(await ownerApi(desktop, owner), 'revocation read').devices, []);
  assert.deepEqual(errors, []);
  console.log('PASS Phone Tracking browser acceptance: QR, two accounts, device consent, first fix, both stops, revocation and replay.');
}
