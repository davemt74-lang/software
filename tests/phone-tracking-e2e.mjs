import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { mkdirSync, mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { createRequire } from 'node:module';
import { localPort, preparePhoneSite } from './fixtures/phone-site-v1.mjs';
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
  if (['state', 'history', 'places','information_list'].includes(action)) {
    return read(await context.request.get(ownerEndpoint, { headers, params: { action, ...values } }));
  }
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
  {
  const dialerWindow={};
  runInNewContext(readFileSync(path.join(repo,'phone-remote-dialer-v1.js'),'utf8'),{window:dialerWindow,performance:{now:()=>1000}});
  const {canAuto,live,numberOK}=dialerWindow.VP3RemoteDialerV1;
  const d={id:'a'.repeat(32),status:'approved',generation:2,dialer:{revision:1,server_epoch:100,enabled:true,auto_enabled:true,auto_number:'+16025550138',request:{id:'b'.repeat(32),state:'pending',expires_epoch:220,auto_allowed:true,auto_attempted:false,destination:'+16025550138'}}};
  const active={visible:true,focused:true,mobile:true,clock:1000};
  assert.equal(canAuto(d,1000,active),true);
  for(const gate of ['visible','focused','mobile'])assert.equal(canAuto(d,1000,{...active,[gate]:false}),false);
  assert.equal(canAuto(d,1000,{...active,clock:11000}),false);
  assert.equal(canAuto({...d,dialer:{...d.dialer,request:{...d.dialer.request,auto_attempted:true}}},1000,active),false);
  assert.equal(canAuto({...d,dialer:{...d.dialer,auto_number:'+16025550137'}},1000,active),false);
  assert.equal(live({...d,dialer:{...d.dialer,request:{...d.dialer.request,expires_epoch:100}}},1000,1000),false);
  assert.equal(numberOK('+16025550138;123'),false);
  }

  const [owner, other] = preparePhoneSite(repo, site, sessions, php);
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
  assert.equal((await ownerApi(apiOwner, owner, 'request_start', command(live))).status, 409);
  check(await ownerApi(apiOwner, owner, 'revoke', command(live)), 'revoke the same pairing after its location generation changes');
  assert.equal((await phoneApi(firstGuest, 'status')).status, 403);
  assert.deepEqual(check(await ownerApi(apiOwner, owner), 'final read').devices, []);
  const mapRoute = await apiOwner.request.get('/phone-tracking.php?agent=system&conversation_id=8');
  assert.equal(mapRoute.status(), 200, 'Map route must render for a signed-in account with chat access');
  const mapHtml = await mapRoute.text();
  assert.match(mapHtml, /id="phone-connect"/);
  assert.match(mapHtml, /<a[^>]*data-vp3-nav-key="phone_tracking"[^>]*aria-current="page"/);
  const mobileRoute = await firstGuest.request.get('/phone-location.php');
  assert.equal(mobileRoute.status(), 200);
  const phoneHTML=await mobileRoute.text();
  assert.match(phoneHTML, /id="phone-claim"/);
  assert.match(phoneHTML,/rel="manifest" href="phone-app.webmanifest"/);
  assert.match(mobileRoute.headers()['content-security-policy'],/manifest-src 'self'; worker-src 'self'/);
  const manifestResponse=await firstGuest.request.get('/phone-app.webmanifest');
  assert.equal(manifestResponse.status(),200);
  const manifest=JSON.parse(await manifestResponse.text());
  assert.equal(manifest.scope,'./phone-location.php');assert.equal(manifest.start_url,'./phone-location.php');assert.equal(manifest.display,'standalone');
  for(const icon of manifest.icons){const response=await firstGuest.request.get('/'+icon.src);assert.equal(response.status(),200);const bytes=await response.body();assert.equal(bytes.readUInt32BE(16),Number(icon.sizes.split('x')[0]));assert.equal(bytes.readUInt32BE(20),Number(icon.sizes.split('x')[1]));}
  const sw=await firstGuest.request.get('/phone-service-worker-v1.js');assert.equal(sw.status(),200);const workerText=await sw.text();assert.ok(!/addEventListener\(['"]fetch|caches\.open|PushManager|pushManager\.subscribe/.test(workerText),'installation worker must not cache or subscribe');

  await verifyTwoPhoneRoutes(baseURL, apiOwner, owner, other);
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

async function verifyTwoPhoneRoutes(baseURL, apiOwner, owner, other) {
  const ownerPhone = { request: await request.newContext({ baseURL }) };
  const guestPhone = { request: await request.newContext({ baseURL }) };
  const outsider = { request: await request.newContext({ baseURL, extraHTTPHeaders: { Cookie: 'PHPSESSID=' + other.session } }) };
  async function share(context, name, role, point) {
    const invitation = check(await ownerApi(apiOwner, owner, 'invite'), 'invite ' + name);
    const device = check(await phoneApi(context, 'claim', { name, invite: invitation.invite }), 'claim ' + name).device;
    const approved = check(await ownerApi(apiOwner, owner, 'approve', { device_id: device.id,
      generation: device.generation, role, allow_location: true }), 'approve ' + name).device;
    const grant = check(await phoneApi(context, 'start', { device_id: device.id, generation: approved.generation }), 'grant ' + name).device;
    return check(await phoneApi(context, 'fix', { device_id: device.id, generation: grant.generation,
      ...point, fix_at: Math.floor(Date.now()/1000) }), name + ' fix').device;
  }
  try {
    const [first, second] = await Promise.all([
      share(ownerPhone, 'Studio iPhone', 'owner', gps),
      share(guestPhone, 'Crew Android', 'guest', { ...gps, longitude: gps.longitude + 0.5 })
    ]);
    await verifyTrailRoutes({ apiOwner, owner, outsider, other, ownerPhone, guestPhone, first, second });
    const firstGrant = { device_id: first.id, generation: first.generation };
    const edit = { ...firstGrant, preferences_version: first.preferences_version, update_interval_seconds: 30, accuracy_mode: 'balanced' };
    assert.equal((await ownerApi(outsider, other, 'set_preferences', edit)).ok, false);
    assert.deepEqual(check(await ownerApi(outsider, other), 'unrelated owner list').devices, []);
    check(await ownerApi(apiOwner, owner, 'set_preferences', edit), 'save first settings');
    const status = async context => check(await phoneApi(context, 'status'), 'isolated handset settings').device;
    const profile = d => [d.update_interval_seconds, d.accuracy_mode, d.generation, d.location_state];
    assert.deepEqual(profile(await status(ownerPhone)), [30, 'balanced', first.generation, 'live']);
    assert.deepEqual(profile(await status(guestPhone)), [5, 'high', second.generation, 'live']);
    assert.equal((await phoneApi(guestPhone, 'stop', firstGrant)).status, 403);
    const typed = { message: 'Stop sharing from Studio iPhone.', input_mode: 'text', idempotency_key: randomUUID() };
    const stop = check(await chatApi(apiOwner, owner, 'send', typed), 'chat stops one phone');
    assert.match(stop.answer, /stopped.*Studio iPhone/i);
    assert.deepEqual(stop.phone_updates.map(x => x.speech), [stop.answer]);
    const replay = check(await chatApi(apiOwner, owner, 'send', typed), 'repeat named stop');
    assert.equal(replay.assistant_message_id, stop.assistant_message_id);
    assert.deepEqual(replay.phone_updates || [], []);
    assert.equal((await phoneApi(ownerPhone, 'fix', { ...firstGrant, ...gps, fix_at: Math.floor(Date.now()/1000) })).status, 409);
    assert.equal((await status(ownerPhone)).latitude, null);
    assert.deepEqual(profile(await status(guestPhone)), [5, 'high', second.generation, 'live']);
    for (const phone of [ownerPhone, guestPhone]) {
      const d = await status(phone);
      check(await ownerApi(apiOwner, owner, 'revoke', { device_id: d.id, generation: d.generation }), 'release test pairing');
    }
    console.log('PASS Two-phone HTTP acceptance: concurrent pairing and fixes, isolated device preferences, one typed Chat stop announcement, retry, rejected late fix, and second phone still live.');
  } finally {
    await Promise.all([ownerPhone.request.dispose(), guestPhone.request.dispose(), outsider.request.dispose()]);
  }
}

async function startWithFreshSensorReading(page, context, reading) {
  const started = page.waitForResponse(response => new URL(response.url()).pathname === phoneEndpoint &&
    response.request().postDataJSON()?.action === 'start');
  await page.locator('#phone-start').click();
  check(await read(await started), 'start from the handset');
  // An OS can deliver the initial position during the tap. Give the subsequent movement
  // a distinct GPS second so the server can distinguish it from that first sample.
  const grantSecond = Math.floor(Date.now()/1000);
  await eventually('GPS clock did not advance', () => Math.floor(Date.now()/1000) > grantSecond);
  await context.setGeolocation(reading);
}

async function browserJourney(baseURL, owner, other) {
  browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
  const desktop = await browser.newContext({ baseURL, viewport: { width: 1400, height: 960 } });
  const outsider = await browser.newContext({ baseURL });
  mobileBrowser = process.env.VP3_PHONE_WEBKIT ? await webkit.launch() : browser;
  const handset = await mobileBrowser.newContext({ ...devices['iPhone 11'], baseURL,
    geolocation: { ...gps, latitude: gps.latitude - 0.01 }, permissions: ['geolocation'], deviceScaleFactor: 1 });
  const newcomer = await browser.newContext({ baseURL });
  for (const [context, user] of [[desktop, owner], [outsider, other]]) {
    await context.addCookies([{ name: 'PHPSESSID', value: user.session, url: baseURL }]);
  }
  await verifyManagedReviewUI(desktop);
  await verifyInstalledPairing(baseURL,desktop,owner);
  const map = await desktop.newPage();
  const phone = await handset.newPage();
  const errors = [];
  for (const page of [map, phone]) page.on('pageerror', error => errors.push(error.message));
  let gpsRequests = 0;
  let highAccuracyWatch;
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
    if (observation.kind === 'watch') highAccuracyWatch = observation.highAccuracy;
    observations.push(observation);
    if (observations.length > 12) observations.shift();
  });
  async function requireLiveFix(label, expected) {
    let latest;
    try {
      await eventually(label, async () => {
        [latest] = check(await ownerApi(desktop, owner), 'live read').devices;
        return latest?.location_state === 'live' && latest.latitude === expected.latitude && latest.longitude === expected.longitude;
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
        if (method === 'watchPosition') window.observeSensor({ kind: 'watch', highAccuracy: options?.enableHighAccuracy });
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
  const trackingLink = map.locator('[data-vp3-nav-key="phone_tracking"]');
  await trackingLink.waitFor({ state: 'visible' });
  assert.equal(await trackingLink.getAttribute('aria-current'), 'page');
  assert.equal(await trackingLink.innerText().then(text => text.includes('Phone Tracking')), true);
  assert.equal(new URL(await trackingLink.getAttribute('href'), baseURL).pathname, '/phone-tracking.php');
  await trackingLink.scrollIntoViewIfNeeded();
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
  await map.locator('#phone-pending select[name=role]').selectOption('owner');
  await map.locator('#phone-pending [data-action=approve]').click();
  await map.locator('#phone-devices [data-device-id] button[data-focus]').first().click();
  const communication=map.locator('#phone-communication-profile');
  await communication.getByRole('button',{name:'Permissions',exact:true}).click();
  await communication.getByLabel('Allow telephone calls',{exact:true}).check();
  await communication.getByLabel('Allow information requests',{exact:true}).check();
  await communication.getByRole('button',{name:'Save permissions',exact:true}).click();
  await eventually('Map grants did not save',async()=>{
    const d=check(await ownerApi(desktop,owner),'communication grant').devices.find(d=>d.id===deviceId);
    return d?.communication?.owner_calls&&d.communication.owner_requests;
  });
  const holderForm=phone.locator('#phone-communication-form');
  await holderForm.getByLabel('My personal phone number',{exact:true}).fill('+16025550129');
  await holderForm.getByRole('button',{name:'Save communication preferences',exact:true}).click();
  await eventually('Holder number did not save',async()=>{
    const d=check(await phoneApi(handset,'status'),'holder communication read').device;
    return d.communication?.phone_e164==='+16025550129';
  });
  await eventually('Holder call consent did not become available',async()=>await holderForm.getByLabel('Allow telephone calls from this Agent',{exact:true}).isEnabled());
  await holderForm.getByLabel('Allow telephone calls from this Agent',{exact:true}).check();
  await holderForm.getByLabel('Allow proactive check-ins',{exact:true}).check();
  await holderForm.getByLabel('Allow information requests',{exact:true}).check();
  await holderForm.getByRole('button',{name:'Save communication preferences',exact:true}).click();
  await eventually('Holder consent did not save',async()=>{
    const d=check(await ownerApi(desktop,owner),'communication consent').devices.find(d=>d.id===deviceId);
    return d?.communication?.call_permission_saved&&d.communication.proactive_permission_saved&&d.communication.request_permission_saved;
  });
  await communication.getByRole('button',{name:'Communication',exact:true}).click();
  await eventually('Map did not show masked number and both consents',async()=>{
    const detail=await communication.innerText();return detail.includes('Ending in 0129')&&detail.includes('Both permissions saved');
  });
  assert.ok(!(await communication.innerText()).includes('+16025550129'),'map does not expose full phone number');
  await communication.getByRole('button',{name:'Activity',exact:true}).click();
  await communication.getByRole('button',{name:'Location history',exact:true}).waitFor();
  await communication.getByRole('button',{name:'Saved places',exact:true}).waitFor();
  await communication.getByRole('button',{name:'Communication',exact:true}).click();
  await screenshot(map,'owner-connected-user-communication');
  // PS5D: choose a different synthetic destination than the holder's approved automatic number.
  // Never invoke tel: or a carrier call during automated browser coverage.
  const dialerSettings=phone.locator('#phone-dialer-settings-host');
  await dialerSettings.getByLabel('Allow remote call requests',{exact:true}).check();
  await dialerSettings.getByLabel('Automatically try to open the dialer for my approved number',{exact:true}).check();
  await dialerSettings.getByLabel('Approved automatic destination (international number)',{exact:true}).fill('+16025550138');
  await dialerSettings.getByRole('button',{name:'Save dialer permissions',exact:true}).click();
  await eventually('Dialer opt-in was not saved',async()=>{
    const d=check(await phoneApi(handset,'status'),'dialer permission').device;
    return d.dialer?.enabled&&d.dialer.auto_number==='+16025550138';
  });
  // PS5E.3: actual map mounts all three modes even without a Twilio assignment.
  const modes=map.locator('#phone-dialer-owner-host');
  await modes.getByRole('button',{name:'Twilio managed callback',exact:true}).click();
  await modes.getByText('Managed callback review',{exact:true}).waitFor();
  assert.equal(await modes.getByRole('button',{name:'Request managed callback approval',exact:true}).isVisible(),false);
  const noManaged=check(await ownerApi(desktop,owner),'managed review status without carrier').devices.find(d=>d.id===deviceId);
  assert.equal(noManaged.managed_callback.dispatch_enabled,false);
  assert.equal(noManaged.managed_callback.available,false);
  assert.equal((await ownerApi(desktop,owner,'managed_dispatch',{device_id:deviceId,generation:noManaged.generation})).status,422,'no live managed dispatch action exposed');
  await modes.getByRole('button',{name:'Browser call',exact:true}).click();
  await modes.getByText('Browser call to connected user',{exact:true}).waitFor();
  await modes.getByRole('button',{name:'Personal cellular',exact:true}).click();
  const dialerMap=map.locator('#phone-dialer-owner-host');
  await dialerMap.getByLabel('Destination number (international format)',{exact:true}).fill('+16025550137');
  await dialerMap.getByLabel('Ask this connected phone to open its own dialer',{exact:true}).check();
  await dialerMap.getByRole('button',{name:'Send call request',exact:true}).click();
  const dialerAlert=phone.locator('#phone-dialer-alert-host');
  await dialerAlert.getByText('+16025550137',{exact:true}).waitFor();
  await dialerAlert.getByRole('button',{name:'Call now',exact:true}).waitFor();
  const pendingDialer=check(await phoneApi(handset,'status'),'dialer pending').device.dialer.request;
  assert.equal(pendingDialer.auto_allowed,false);
  assert.equal(pendingDialer.auto_attempted,false);
  const mapDialer=check(await ownerApi(desktop,owner),'dialer owner status').devices.find(d=>d.id===deviceId).dialer;
  assert.ok(mapDialer.request?.state==='pending'&&!('destination' in mapDialer.request)&&!('auto_number' in mapDialer));
  await dialerAlert.getByRole('button',{name:'Decline',exact:true}).click();
  await eventually('Dialer decline was not recorded',async()=>check(await phoneApi(handset,'status'),'dialer declined').device.dialer.request?.state==='declined');
  await dialerSettings.getByLabel('Allow remote call requests',{exact:true}).uncheck();
  await dialerSettings.getByRole('button',{name:'Save dialer permissions',exact:true}).click();
  await eventually('Dialer opt-out was not saved',async()=>check(await phoneApi(handset,'status'),'dialer off').device.dialer.enabled===false);

  await communication.getByRole('button',{name:'Information',exact:true}).click();
  const panel=communication.locator('.phone-information-panel');
  await panel.getByLabel('Information type',{exact:true}).selectOption('question');
  await panel.getByLabel('Information message',{exact:true}).fill('What time is best for the private fixture review?');
  await panel.getByRole('button',{name:'Send to phone',exact:true}).click();
  await panel.getByText('What time is best for the private fixture review?',{exact:true}).waitFor();
  const holderInbox=phone.locator('#phone-information');
  await eventually('Phone did not receive open request count',async()=> (await holderInbox.locator('summary').innerText()).includes('(1 open)'));
  await holderInbox.locator('summary').click();
  await holderInbox.getByText('What time is best for the private fixture review?',{exact:true}).waitFor();
  await holderInbox.getByRole('button',{name:'Accept request',exact:true}).click();
  await holderInbox.getByLabel('Your response',{exact:true}).fill('Before noon <private fixture>');
  await holderInbox.getByRole('button',{name:'Send response',exact:true}).click();
  await holderInbox.getByText('Response: Before noon <private fixture>',{exact:true}).waitFor();
  await panel.getByRole('button',{name:'Refresh exchange',exact:true}).click();
  await panel.getByText('Response: Before noon <private fixture>',{exact:true}).waitFor();
  assert.equal((await ownerApi(outsider,other,'information_list',{device_id:deviceId})).status,404);
  const ownState=check(await ownerApi(desktop,owner),'count-only map state');
  assert.ok(!JSON.stringify(ownState).includes('Before noon'),'ordinary map polls do not reveal request body or response');
  const updates=check(await chatApi(desktop,owner,'activity',{phone_updates:true,conversation_id:8}),'information Chat receipt').phone_updates;
  const receipt=updates.find(u=>u.speech.includes('responded to an information request'));
  assert.ok(receipt&&!JSON.stringify(updates).includes('Before noon'),'Chat receives only a scoped generic receipt, not the private response');
  assert.ok(!check(await chatApi(desktop,owner,'activity',{phone_updates:true,conversation_id:8}),'receipt replay').phone_updates.some(u=>u.speech.includes('responded to an information request')));
  await screenshot(map,'owner-connected-information');
  // Real browser requests through both existing authenticated endpoints.
  await panel.getByLabel('Information type',{exact:true}).selectOption('photo_request');
  await panel.getByLabel('Information message',{exact:true}).fill('Please share the synthetic review photo.');
  await panel.getByRole('button',{name:'Send to phone',exact:true}).click();
  await panel.getByText('Please share the synthetic review photo.',{exact:true}).waitFor();
  const photo=holderInbox.locator('.phone-information-item').filter({hasText:'Please share the synthetic review photo.'});
  await photo.getByRole('button',{name:'Accept request',exact:true}).click();
  const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN5sAAAAASUVORK5CYII=','base64');
  await photo.getByLabel('Choose attachment',{exact:true}).setInputFiles({name:'fixture.png',mimeType:'image/png',buffer:image});
  await photo.getByLabel('Share this selected file with the other party.',{exact:true}).check();
  await photo.getByRole('button',{name:'Share selected file',exact:true}).click();
  await photo.getByRole('button',{name:'Download file',exact:true}).waitFor();
  const photoOwner=panel.locator('.phone-information-item').filter({hasText:'Please share the synthetic review photo.'});
  await photoOwner.getByRole('button',{name:'Download file',exact:true}).waitFor({timeout:25000});
  const photoDownload=map.waitForEvent('download');
  await photoOwner.getByRole('button',{name:'Download file',exact:true}).click();
  const photoResult=await photoDownload;
  assert.match(photoResult.suggestedFilename(),/^vp3-[a-f0-9]{12}\.png$/);
  const photoParts=[];for await(const part of await photoResult.createReadStream())photoParts.push(part);
  assert.deepEqual(Buffer.concat(photoParts),image,'private browser photo download preserves exact bytes');
  await panel.getByLabel('Information type',{exact:true}).selectOption('file_share');
  await panel.getByLabel('Information message',{exact:true}).fill('Here is the synthetic document.');
  const textFile=Buffer.from('Private synthetic document.\n','utf8');
  await panel.getByLabel('Choose attachment',{exact:true}).setInputFiles({name:'fixture.txt',mimeType:'text/plain',buffer:textFile});
  await panel.getByLabel('Share this selected file with the other party.',{exact:true}).check();
  await panel.getByRole('button',{name:'Send to phone',exact:true}).click();
  const fileHolder=holderInbox.locator('.phone-information-item').filter({hasText:'Here is the synthetic document.'});
  await fileHolder.getByRole('button',{name:'Download file',exact:true}).waitFor({timeout:25000});
  const fileDownload=phone.waitForEvent('download');
  await fileHolder.getByRole('button',{name:'Download file',exact:true}).click();
  const fileResult=await fileDownload;
  assert.match(fileResult.suggestedFilename(),/^vp3-[a-f0-9]{12}\.txt$/);
  const fileParts=[];for await(const part of await fileResult.createReadStream())fileParts.push(part);
  assert.deepEqual(Buffer.concat(fileParts),textFile,'holder receives exact selected document');
  await fileHolder.getByRole('button',{name:'Acknowledge file',exact:true}).click();
  await fileHolder.getByText('Send a file · Acknowledged',{exact:true}).waitFor();
  // The file journey produces its own two private receipts. Verify and drain them
  // here so the later two-phone location test sees only its location announcements.
  const fileReceipts=check(await chatApi(desktop,owner,'activity',{phone_updates:true,conversation_id:8}),'file exchange Chat receipts').phone_updates;
  assert.equal(fileReceipts.filter(u=>u.speech.includes('responded to an information request')).length,1,'photo completion has one scoped receipt');
  assert.equal(fileReceipts.filter(u=>u.speech.includes('acknowledged an update')).length,1,'file acknowledgement has one scoped receipt');
  assert.equal(fileReceipts.length,2,'only the two file exchange receipts are outstanding');
  assert.ok(!JSON.stringify(fileReceipts).includes('synthetic review photo')&&!JSON.stringify(fileReceipts).includes('synthetic document'),'file receipts contain no private request bodies or document content');
  assert.deepEqual(check(await chatApi(desktop,owner,'activity',{phone_updates:true,conversation_id:8}),'file receipt replay').phone_updates,[]);
  await screenshot(map,'owner-connected-files');
  await holderInbox.locator('summary').click();
  await communication.getByRole('button',{name:'Communication',exact:true}).click();
  await map.locator('#phone-detail [data-action=request_start]').click();
  await eventually('Phone missed start request', async () =>
    check(await phoneApi(handset, 'status'), 'phone read').device.location_state === 'requested');
  assert.equal(gpsRequests, 0, 'Pairing and owner request must leave phone geolocation idle');
  assert.equal(await map.locator('#phone-map .phone-map-marker').count(), 0);
  await phone.locator('#phone-start').waitFor();
  assert.match(await phone.locator('#phone-owner').innerText(), /Map Operator/);
  assert.ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'iPhone page must fit its viewport');
  await verifyPhoneTabs(phone);
  await screenshot(phone, 'phone-before-consent');
  // The owner request advances the GPS generation; wait for the actual phone UI,
  // not just the independent HTTP test client, to observe that request.
  await phone.getByText('The owner requested your location. Tap Start sharing to allow it.',{exact:true}).waitFor();
  await startWithFreshSensorReading(phone, handset, gps);
  device = await requireLiveFix('No real browser geolocation fix reached the owner', gps);
  assert.deepEqual([device.latitude, device.longitude, device.accuracy], [gps.latitude, gps.longitude, gps.accuracy]);
  assert.ok(device.first_fix_at && device.fix_at >= device.first_fix_at && Math.abs(Date.now()/1000 - device.fix_at) < 60);
  assert.ok(gpsRequests > 0);
  await map.locator('#phone-map .phone-map-marker').first().waitFor({ state: 'visible', timeout: 25000 });
  assert.doesNotMatch(await map.locator('#phone-status').innerText(), /waiting for the phone|sharing requested/i);
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
  const restartPoint = { ...gps, longitude: -112.07304 };
  await startWithFreshSensorReading(phone, handset, restartPoint);
  await requireLiveFix('Phone did not resume after deliberate local restart', restartPoint);
  await map.locator('#phone-map .phone-map-marker').first().waitFor({ state: 'visible' });
  await map.locator('#phone-detail [data-action=stop]').waitFor();
  await phone.locator('#phone-stop').click();
  await eventually('Phone stop did not reach owner', async () =>
    check(await ownerApi(desktop, owner), 'phone stop read').devices[0]?.location_state === 'off');
  await multiPhoneJourney({ baseURL, desktop, outsider, owner, other, map, handset, phone, deviceId,
    errors, currentWatchAccuracy: () => highAccuracyWatch });
  // Disconnect the pairing immediately, even if the map last showed its previous GPS generation.
  await map.locator('#phone-detail [data-action=revoke]').click();
  const revoked = map.waitForResponse(response => new URL(response.url()).pathname === ownerEndpoint &&
    response.request().method() === 'POST' && response.request().postDataJSON()?.action === 'revoke');
  await map.locator('#phone-revoke-accept').click();
  check(await read(await revoked), 'revoke from the map');
  await eventually('Revoked phone still has authority', async () => (await phoneApi(handset, 'status')).status === 403);
  assert.deepEqual(check(await ownerApi(desktop, owner), 'revocation read').devices, []);
  assert.deepEqual(errors, []);
  console.log('PASS Phone Tracking browser acceptance: QR, two accounts, device consent, first fix, both stops, revocation and replay.');
}

async function verifyPhoneTabs(phone) {
  const connection = phone.getByRole('tab', {name:'Connection', exact:true});
  const chat = phone.getByRole('tab', {name:'Chat', exact:true});
  const input = phone.locator('#phone-chat-input');
  async function verifyDock() {
    assert.equal(await input.isVisible(), true);
    assert.ok(await phone.locator('#phone-apps-toggle').evaluate(button => {
      const rect = button.getBoundingClientRect();
      const height = window.visualViewport?.height || innerHeight;
      return rect.top > height / 2 && rect.bottom <= height + 1 &&
        button.contains(document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2));
    }), 'The shared app launcher is reachable at the bottom of the visible phone viewport');
  }
  async function verifyConnection() {
    assert.equal(await connection.getAttribute('aria-selected'), 'true');
    await verifyDock();
    const position = await phone.locator('#phone-label').evaluate(label => {
      const bounds = label.getBoundingClientRect();
      return {top:bounds.top, bottom:bounds.bottom, height:window.visualViewport?.height || innerHeight};
    });
    assert.ok(position.bottom > 0 && position.top < position.height * .35,
      'The connected phone name should appear near the top of the iPhone viewport: ' + JSON.stringify(position));
  }
  // The new preference journey deliberately scrolls to controls below the fold.
  // Reselect Connection and verify that it restores the overview.
  await connection.click();
  await verifyConnection();
  await screenshot(phone, 'phone-connection-tab');
  await chat.click();
  assert.equal(await chat.getAttribute('aria-selected'), 'true');
  assert.equal(await phone.locator('#phone-controls').isVisible(), false);
  await input.fill('Meet me at the studio');
  assert.equal(await phone.locator('#phone-chat-send').isEnabled(), true);
  await input.blur();
  await verifyDock();
  await screenshot(phone, 'phone-chat-tab');
  await connection.click();
  await verifyConnection();
  await chat.click();
  assert.equal(await input.inputValue(), 'Meet me at the studio');
  await input.fill('');
  await connection.click();
}

async function multiPhoneJourney({ baseURL, desktop, outsider, owner, other, map, handset, phone, deviceId,
    errors, currentWatchAccuracy }) {
  const pixelPoint = { latitude: gps.latitude + 0.18, longitude: gps.longitude - 0.09, accuracy: 18 };
  const android = await browser.newContext({ ...devices['Pixel 7'], baseURL, permissions: ['geolocation'],
    geolocation: { ...pixelPoint, latitude: pixelPoint.latitude - 0.01 }, deviceScaleFactor: 1 });
  const pixel = await android.newPage();
  pixel.on('pageerror', error => errors.push(error.message));
  const state = async () => check(await ownerApi(desktop, owner), 'two-phone map state').devices;
  const fromChat = async (message, idempotency_key = randomUUID()) =>
    check(await chatApi(desktop, owner, 'send', { message, input_mode: 'voice', idempotency_key }), message);
  const choosePhone = id => map.locator('#phone-devices [data-focus="' + id + '"]').click();
  const preferences = map.locator('#phone-preferences');
  const interval = preferences.locator('[name=update_interval_seconds]');
  const accuracy = preferences.locator('[name=accuracy_mode]');
  const markers = map.locator('#phone-map .phone-map-marker');
  const invite = check(await ownerApi(desktop, owner, 'invite'), 'second phone invite').invite;
  await pixel.goto('/phone-location.php#invite=' + invite);
  await pixel.locator('#phone-name').fill('Crew Pixel');
  const claimed = pixel.waitForResponse(response => new URL(response.url()).pathname === phoneEndpoint &&
    response.request().postDataJSON()?.action === 'claim');
  await pixel.locator('#phone-claim').click();
  const second = check(await read(await claimed), 'second phone claim').device;
  check(await ownerApi(desktop, owner, 'approve', { device_id: second.id, generation: second.generation,
    allow_location: true, role: 'guest' }), 'second phone approval');

  for (const name of ['Field iPhone', 'Crew Pixel']) {
    assert.match((await fromChat('Start sharing from ' + name + '.')).answer, /requested/i);
  }
  for (const page of [phone, pixel]) {
    await eventually('Holder missed its named start request', async () =>
      /requested your location/i.test(await page.locator('#phone-status').innerText()));
  }
  const firstPoint = { ...gps, latitude: 33.44938 };
  await startWithFreshSensorReading(phone, handset, firstPoint);
  await startWithFreshSensorReading(pixel, android, pixelPoint);
  await eventually('Both independently granted phones must be live', async () => {
    const devices = await state();
    return devices.length === 2 && devices.every(d => d.location_state === 'live') &&
      devices.some(d => d.id === deviceId && d.role === 'owner' && d.latitude === firstPoint.latitude) &&
      devices.some(d => d.id === second.id && d.role === 'guest' && d.latitude === pixelPoint.latitude);
  });
  await eventually('Two live markers should be visible together', async () => await markers.count() === 2);
  await map.locator('#phone-fit').click();
  assert.deepEqual(check(await ownerApi(outsider, other), 'other account sees no shared phones').devices, []);
  await historyAndPlacesJourney({ desktop, owner, map, handset, phone, android, deviceId, guestId: second.id });
  const announcements = async () => check(await chatApi(desktop, owner, 'activity', { phone_updates: true }),
    'two-phone announcements').phone_updates;
  const updates = await announcements();
  assert.equal(updates.length, 2);
  for (const name of ['Field iPhone', 'Crew Pixel']) {
    assert.equal(updates.filter(update => update.speech.includes(name)).length, 1);
  }
  assert.deepEqual(await announcements(), []);
  const [studio] = check(await ownerApi(desktop, owner, 'places'), 'saved place for arrival').places.filter(p => p.name === 'Studio');
  assert.ok(studio);
  await verifyArrivalJourney({ ownerContext:desktop, owner, otherContext:outsider, other,
    selectedPhone:handset, guestPhone:android, selectedId:deviceId, selectedName:'Field iPhone', place:studio,
    handsetPage:phone });
  const first = (await state()).find(d => d.id === deviceId);
  assert.equal(currentWatchAccuracy(), true, 'iPhone should initially request high accuracy');
  const proposed = { device_id: deviceId, generation: first.generation, preferences_version: first.preferences_version,
    update_interval_seconds: 15, accuracy_mode: 'balanced' };
  assert.equal((await ownerApi(outsider, other, 'set_preferences', proposed)).ok, false);
  await choosePhone(deviceId);
  assert.match(await map.locator('#phone-detail-summary').innerText(), /Accepted on phone/);
  await interval.selectOption('15');
  await accuracy.selectOption('balanced');
  await accuracy.focus();
  // A GPS poll may arrive while the user is editing the selected phone.
  const mapPoll = await map.waitForResponse(response => new URL(response.url()).pathname === ownerEndpoint &&
    response.request().method() === 'GET');
  await mapPoll.finished();
  assert.equal(await accuracy.evaluate(select => select === document.activeElement), true);
  assert.equal(await interval.inputValue(), '15');
  assert.equal(await accuracy.inputValue(), 'balanced');
  const saved = map.waitForResponse(response => new URL(response.url()).pathname === ownerEndpoint &&
    response.request().method() === 'POST' && response.request().postDataJSON()?.action === 'set_preferences');
  await preferences.getByRole('button', { name: 'Save settings' }).click();
  const edited = check(await read(await saved), 'owner edits active phone settings').device;
  assert.equal(edited.preferences_version, first.preferences_version + 1);
  assert.equal(edited.generation, first.generation, 'Editing preferences must preserve holder consent');
  assert.equal(edited.location_state, 'live');
  await eventually('iPhone did not display its saved GPS settings', async () =>
    /15 seconds.*balanced/i.test(await phone.locator('#phone-preferences').innerText()));
  await eventually('Live iPhone sensor kept its old accuracy request', () => currentWatchAccuracy() === false);
  await choosePhone(second.id);
  assert.equal(await interval.inputValue(), '5');
  assert.equal(await accuracy.inputValue(), 'high');
  assert.match(await pixel.locator('#phone-preferences').innerText(), /5 seconds.*high/i);
  await choosePhone(deviceId);
  assert.equal(await interval.inputValue(), '15');
  assert.equal(await accuracy.inputValue(), 'balanced');
  await screenshot(map, 'owner-two-live-phones');

  assert.match((await fromChat('Stop sharing my location.')).answer, /which phone/i);
  assert.ok((await state()).every(d => d.location_state === 'live'));
  const key = randomUUID();
  const stop = await fromChat('Stop sharing from Field iPhone.', key);
  assert.match(stop.answer, /stopped.*Field iPhone/i);
  assert.equal((await fromChat('Stop sharing from Field iPhone.', key)).assistant_message_id,
    stop.assistant_message_id, 'Retry must reuse the same private Chat confirmation');
  assert.equal((await phoneApi(handset, 'fix', { device_id: first.id, generation: first.generation,
    ...gps, fix_at: Math.floor(Date.now()/1000) })).status, 409);
  const stopped = (await state()).find(d => d.id === deviceId);
  assert.equal(stopped.status, 'approved');
  assert.equal(stopped.latitude, null);
  assert.equal(stopped.location_state, 'off');
  await eventually('Only the selected phone should disappear', async () => await markers.count() === 1 &&
    (await markers.first().getAttribute('title')).startsWith('Crew Pixel'));
  const beforeMove = (await state()).find(d => d.id === second.id);
  assert.equal(beforeMove.location_state, 'live');
  // Wait for the next reporting interval, then supply an actual browser position.
  await eventually('Second phone reporting window did not open', () =>
    Math.floor(Date.now()/1000) >= beforeMove.fix_at + beforeMove.update_interval_seconds);
  const moved = { ...pixelPoint, longitude: pixelPoint.longitude + 0.02 };
  await android.setGeolocation(moved);
  await eventually('Stopping the iPhone interrupted the second phone’s live updates', async () =>
    (await state()).some(d => d.id === second.id && d.longitude === moved.longitude && d.fix_at > beforeMove.fix_at));

  await android.setOffline(true);
  await eventually('Android did not go offline', () => pixel.evaluate(() => navigator.onLine === false));
  // Age the test database's one reading to simulate a prolonged sensor suspension without a minute-long sleep.
  const ageOneFix = "[$file,$id]=array_slice($argv,1);$db=new PDO('sqlite:'.$file);" +
    "$q=$db->prepare('UPDATE vp3_phone_devices_v1 SET started_at=?,first_fix_at=?,fix_at=?,last_seen_at=? WHERE id=?');" +
    "$old=time()-90;$q->execute([$old,$old,$old,$old,$id]);";
  execFileSync(php, ['-r', ageOneFix, path.join(temp, 'data.sqlite'), second.id]);
  await map.locator('#phone-map .phone-map-marker.is-stale').waitFor();
  await pixel.locator('#phone-stop').click();
  await eventually('Offline stop was not applied on the phone', async () =>
    /stopped/i.test(await pixel.locator('#phone-gps').innerText()));
  const reconnectStop = pixel.waitForResponse(response => new URL(response.url()).pathname === phoneEndpoint &&
    response.request().postDataJSON()?.action === 'stop');
  await android.setOffline(false);
  check(await read(await reconnectStop), 'deliver the Android stop after reconnect');
  await eventually('Reconnected Android did not deliver its pending stop', async () =>
    (await state()).every(d => d.status === 'approved' && d.location_state === 'off' && d.latitude === null));
  await eventually('Both stopped markers should clear', async () => await markers.count() === 0);
  await pixel.locator('#phone-start').waitFor({ state:'visible' });
  assert.equal((await phoneApi(android, 'fix', { device_id: second.id, generation: beforeMove.generation,
    ...moved, fix_at: Math.floor(Date.now()/1000) })).status, 409);
  const secondOff = (await state()).find(d => d.id === second.id);
  const historyLink = (await fromChat('Show location history for Crew Pixel.')).actions[0].url;
  await verifyLinkedPhoneHistory(desktop, historyLink, second.id, deviceId, async () =>
    check(await ownerApi(desktop, owner, 'revoke', { device_id: second.id, generation: secondOff.generation }),
      'remove the second test pairing'));
  await choosePhone(deviceId);
  // The owner enables a single paired phone to create a place while its live GPS remains off.
  const grantButton = map.locator('#phone-detail [data-action=set_holder_place_access]');
  await phone.getByRole('tab',{name:'Chat',exact:true}).click();
  await phone.locator('#phone-apps-toggle').click();
  assert.equal(await phone.locator('#phone-app-save-place').isVisible(),false);
  await grantButton.click();
  await phone.locator('#phone-app-save-place').click();
  await phone.locator('#phone-save-place-name').fill('Side door');
  const freshSpot = {...gps, longitude:gps.longitude + 0.03};
  await handset.setGeolocation(freshSpot);
  const placeReceipt = phone.waitForResponse(response => new URL(response.url()).pathname === phoneEndpoint
    && response.request().postDataJSON()?.action === 'holder_save_place');
  await phone.locator('#phone-save-place-submit').click();
  await handset.setGeolocation(freshSpot);
  assert.equal(check(await read(await placeReceipt),'holder saves named location').saved_place.name,'Side door');
  await phone.getByRole('button',{name:'Done',exact:true}).click();
  assert.ok(check(await ownerApi(desktop,owner,'places'),'owner reads holder-created location').places.some(p => p.name === 'Side door'));
  const afterSave = (await state()).find(d => d.id === deviceId);
  assert.equal(afterSave.location_state,'off');
  assert.equal(afterSave.latitude,null);
  await grantButton.click();
  await phone.locator('#phone-apps-toggle').click();
  await phone.locator('#phone-app-save-place').waitFor({state:'hidden'});
  await phone.locator('#phone-apps-toggle').click();
  console.log('PASS Owner-enabled one-time named location from the phone apps menu with live sharing off.');
  await android.close();
  console.log('PASS Multiple phones: independent live GPS, isolated per-phone settings, named and ambiguous voice commands, both first-fix announcements, stale map, offline stop and Android reconnect.');
}

async function verifyTrailRoutes({ apiOwner, owner, outsider, other, ownerPhone, guestPhone, first, second }) {
  const firstId = { device_id: first.id, generation: first.generation };
  const secondId = { device_id: second.id, generation: second.generation };
  const trail = async id => check(await ownerApi(apiOwner, owner, 'history', { device_id: id }), 'history read').points;
  const places = async () => check(await ownerApi(apiOwner, owner, 'places'), 'saved places').places;
  const input = { ...firstId, history_version:first.history_version, retention_days:7 };
  assert.deepEqual(await trail(first.id), []);
  assert.deepEqual(await trail(second.id), []);
  assert.equal((await ownerApi(outsider, other, 'request_history', input)).ok, false);
  assert.equal((await ownerApi(outsider, other, 'history', { device_id: first.id })).ok, false);
  const waiting = check(await ownerApi(apiOwner, owner, 'request_history', input), 'request seven-day history').device;
  assert.equal(waiting.history_state, 'requested');
  assert.equal((await phoneApi(guestPhone, 'accept_history', { ...firstId, history_version: waiting.history_version })).ok, false);
  const active = check(await phoneApi(ownerPhone, 'accept_history', { ...firstId, history_version: waiting.history_version }),
    'holder approves history').device;
  assert.equal(active.history_state, 'active');
  assert.deepEqual(await trail(first.id), [], 'prior live fixes must never become trail points');
  const permission = { ...firstId, place_permission_version: active.place_permission_version, allow_saved_places: true };
  check(await phoneApi(ownerPhone, 'set_place_permission', permission), 'independent holder place consent');
  assert.equal((await ownerApi(apiOwner, owner, 'save_place', { ...secondId, name:'Guest place' })).ok, false);
  const send = async (message, idempotency_key = randomUUID()) => check(await chatApi(apiOwner, owner, 'send',
    { message, idempotency_key }), 'private Chat place choice');
  const askForPhone = async () => {
    const question = await send('Save this location as Route office.');
    assert.ok(question.answer.includes(first.name) && question.answer.includes(second.name), question.answer);
  };
  await askForPhone();
  await send(second.name);
  assert.deepEqual(await places(), [], 'Choosing a live-only guest must never save the other phone’s location');
  await askForPhone();
  const saveKey = randomUUID();
  const saved = await send(first.name, saveKey);
  assert.match(saved.answer, /Route office/i);
  assert.deepEqual(saved.phone_updates.map(u => u.speech), [saved.answer]);
  const repeated = await send(first.name, saveKey);
  assert.equal(repeated.assistant_message_id, saved.assistant_message_id);
  assert.deepEqual(repeated.phone_updates || [], []);
  const [place] = await places();
  assert.ok(place && place.name === 'Route office' && place.longitude === first.longitude);
  assert.deepEqual(check(await ownerApi(outsider, other, 'places'), 'other account places').places, []);
  assert.equal((await ownerApi(outsider, other, 'delete_place', { place_id: place.id })).ok, false);
  const consentSecond = Math.floor(Date.now()/1000);
  await eventually('Need a new post-consent sensor second', () => Math.floor(Date.now()/1000) > consentSecond);
  const now = Math.floor(Date.now()/1000);
  check(await phoneApi(ownerPhone, 'fix', { ...firstId, ...gps, fix_at:now, history_version: active.history_version }), 'authorized trail sample');
  check(await phoneApi(guestPhone, 'fix', { ...secondId, ...gps, longitude: gps.longitude + 0.5, fix_at:now }), 'guest live-only sample');
  const [point] = await trail(first.id);
  assert.equal(point.fix_at, now);
  assert.equal(point.expires_at, point.fix_at + 7 * 86400);
  assert.deepEqual(await trail(second.id), []);
  check(await ownerApi(apiOwner, owner, 'delete_history', { ...firstId, history_version:active.history_version }), 'delete trail');
  assert.deepEqual(await trail(first.id), []);
  const current = check(await phoneApi(ownerPhone, 'status'), 'after deleting trail').device;
  assert.equal(current.location_state, 'live');
  assert.equal(current.history_state, 'off');
  assert.ok((await places()).some(p => p.id === place.id && p.name === 'Route office'));
  await verifyArrivalJourney({ ownerContext:apiOwner, owner, otherContext:outsider, other,
    selectedPhone:ownerPhone, guestPhone, selectedId:first.id, selectedName:first.name, place });
  check(await ownerApi(apiOwner, owner, 'delete_place', { place_id:place.id }), 'remove HTTP test place');
  console.log('PASS History and places HTTP: distinct holder consent, no backfill, live-only guest, isolated accounts, seven-day retention and deletion preserves the live session and saved place.');
}

async function historyAndPlacesJourney({ desktop, owner, map, handset, phone, android, deviceId, guestId }) {
  const trail = async id => check(await ownerApi(desktop, owner, 'history', { device_id: id }), 'read retained samples').points;
  const places = async () => check(await ownerApi(desktop, owner, 'places'), 'read private places').places;
  const send = async message => check(await chatApi(desktop, owner, 'send', { message, idempotency_key: randomUUID() }), message);
  const openHistory = async () => {
    await map.locator('#phone-tab-history').click();
    await map.locator('#phone-history-device').selectOption(deviceId);
  };
  await openHistory();
  await map.locator('#phone-history-retention').selectOption('7');
  await map.locator('#phone-history-request').click();
  await phone.locator('#phone-history-accept').waitFor({state:'visible'});
  await phone.locator('#phone-history-accept').scrollIntoViewIfNeeded();
  await screenshot(phone, 'phone-history-consent');
  await phone.locator('#phone-history-accept').click();
  await phone.locator('#phone-history-stop').waitFor({state:'visible'});
  assert.deepEqual(await trail(guestId), []);
  const consentSecond = Math.floor(Date.now()/1000);
  await eventually('First retained fix must be captured after consent', () => Math.floor(Date.now()/1000) > consentSecond);
  const retainedFixes = [];
  for (const latitude of [33.45012, 33.45317]) {
    await handset.setGeolocation({ ...gps, latitude });
    await eventually('iPhone movement never appeared in its opted-in trail', async () => {
      const point = (await trail(deviceId)).find(p => Math.abs(p.latitude - latitude) < 0.000001);
      if (point) retainedFixes.push(point);
      return !!point;
    });
    await eventually('Need a distinct sensor second for the next map point', () => Math.floor(Date.now()/1000) > retainedFixes.at(-1).fix_at);
  }
  await eventually('The trail should draw a continuous line and its endpoint on the map', async () =>
    await map.locator('#phone-map svg .phone-history-trail').count() >= 2);
  await map.locator('#phone-history-points > li[data-fix-at="' + retainedFixes[1].fix_at + '"]').waitFor();
  await screenshot(map, 'owner-location-history');

  // Keep the guest live-only. An ambiguous request needs a named source even if only one phone can save a place.
  await phone.locator('#phone-places-allow').click();
  await phone.locator('#phone-places-stop').waitFor({state:'visible'});
  await phone.locator('#phone-places-stop').scrollIntoViewIfNeeded();
  await screenshot(phone, 'phone-history-and-places-sharing');
  const draftPlaces = async () => (await places()).filter(p => p.name === 'Studio');
  const ambiguity = await send('Save this location as Studio.');
  assert.ok(ambiguity.answer.includes('Field iPhone') && ambiguity.answer.includes('Crew Pixel'), ambiguity.answer);
  assert.deepEqual(await draftPlaces(), []);
  const named = await send('Field iPhone');
  assert.match(named.answer, /Studio/i);
  const [studio] = await draftPlaces();
  assert.ok(studio && Math.abs(studio.latitude - retainedFixes[1].latitude) < 0.000001,
    'Studio must reference the named phone’s current fix');
  await map.locator('#phone-tab-places').click();
  const savedRow = map.locator('#phone-places-list > li[data-place-id="' + studio.id + '"]');
  await savedRow.waitFor({state:'visible'});
  assert.match(await savedRow.innerText(), /Studio/);

  // Stop retention while keeping sharing live, then simulate expiry of one recorded sample.
  await phone.locator('#phone-history-stop').click();
  await eventually('Holder did not stop retention independently', async () => {
    const d = check(await phoneApi(handset, 'status'), 'current retention state').device;
    return d.history_state === 'off' && d.location_state === 'live';
  });
  const [oldest, newer] = retainedFixes;
  const expireOne = "[$file,$id,$stamp]=array_slice($argv,1);$db=new PDO('sqlite:'.$file);" +
    "$q=$db->prepare('UPDATE vp3_phone_history_points_v1 SET expires_at=? WHERE device_id=? AND fix_at=?');" +
    "$q->execute([time()-1,$id,$stamp]);if($q->rowCount()!==1)exit(1);";
  execFileSync(php, ['-r', expireOne, path.join(temp, 'data.sqlite'), deviceId, String(oldest.fix_at)]);
  await map.reload();
  await openHistory();
  await map.locator('#phone-history-points > li[data-fix-at="' + newer.fix_at + '"]').waitFor();
  assert.equal(await map.locator('#phone-history-points > li[data-fix-at="' + oldest.fix_at + '"]').count(), 0);
  assert.ok(!(await trail(deviceId)).some(p => p.fix_at === oldest.fix_at));
  await map.locator('#phone-history-delete').click();
  const deleted = map.waitForResponse(r => new URL(r.url()).pathname === ownerEndpoint &&
    r.request().method() === 'POST' && r.request().postDataJSON()?.action === 'delete_history');
  await map.locator('#phone-archive-confirm').click();
  check(await read(await deleted), 'confirmed map trail deletion');
  await map.reload();
  await openHistory();
  assert.deepEqual(await trail(deviceId), []);
  assert.deepEqual(await trail(guestId), []);
  await eventually('Deleted history should clear its map/list', async () =>
    (await map.locator('#phone-history-count').innerText()).trim() === '0 points' &&
    await map.locator('#phone-history-points > li[data-fix-at]').count() === 0 &&
    await map.locator('.phone-history-trail').count() === 0);
  await map.locator('#phone-tab-places').click();
  await savedRow.waitFor({state:'visible'});
  assert.equal((await draftPlaces()).length, 1);
  await savedRow.scrollIntoViewIfNeeded();
  // Keep the verified row and its controls above the fixed Chat composer in the viewport capture.
  await savedRow.evaluate(row => row.scrollIntoView({block:'center', behavior:'instant'}));
  await screenshot(map, 'owner-saved-studio');
  await savedRow.getByRole('button', { name:'Delete Studio' }).click();
  const dialog = map.getByRole('dialog', { name:/delete.*studio/i });
  await dialog.waitFor({state:'visible'});
  await dialog.getByRole('button', { name:'Cancel' }).click();
  await dialog.waitFor({state:'hidden'});
  assert.match(await savedRow.innerText(), /Studio/);
  assert.ok((await places()).some(p => p.id === studio.id), 'Cancelling deletion should preserve Studio');
  await map.locator('#phone-tab-devices').click();
  // Send fresh positions on both emulated sensors after exercising the archive panels.
  await handset.setGeolocation({ ...gps, latitude:33.46721 });
  await android.setGeolocation({ ...gps, latitude:33.62838, longitude:-112.15991 });
  await eventually('Both live phones must continue after trail deletion', async () => {
    const devices = check(await ownerApi(desktop, owner), 'live state after history deletion').devices;
    return devices.some(d => d.id === deviceId && d.location_state === 'live' && d.latitude === 33.46721) &&
      devices.some(d => d.id === guestId && d.location_state === 'live' && d.longitude === -112.15991);
  });
  assert.deepEqual(await trail(deviceId), []);
  console.log('PASS Emulated iPhone and Android: opt-in trail, live-only guest, two-phone Chat clarification, saved Studio persists, expired/deleted points gone after refresh, both sensors remain live.');
}


async function verifyArrivalJourney({ ownerContext, owner, otherContext, other, selectedPhone, guestPhone,
  selectedId, selectedName, place, handsetPage }) {
  const status = async phone => check(await phoneApi(phone, 'status'), 'phone arrival status').device;
  const send = async (message, key = randomUUID()) => check(await chatApi(ownerContext, owner, 'send',
    { message, idempotency_key:key }), message);
  const updates = async (context = ownerContext, user = owner, conversation_id = 8) =>
    check(await chatApi(context, user, 'activity', { phone_updates:true, conversation_id }), 'private arrival poll').phone_updates;
  const openTask = 'Recording: mic check';
  const outside = { latitude:place.latitude + 0.011, longitude:place.longitude, accuracy:5 };
  const inside = { latitude:place.latitude, longitude:place.longitude, accuracy:5 };
  async function move(phone, point) {
    const before = await status(phone);
    const previousFix = before.fix_at || 0;
    await eventually('Need a new captured GPS second', () => Math.floor(Date.now()/1000) > previousFix);
    if (handsetPage) {
      await phone.setGeolocation(point);
    } else {
      const grants = Object.fromEntries(before.arrival_rules.filter(rule => rule.state === 'active')
        .map(rule => [rule.id,rule.version]));
      check(await phoneApi(phone,'fix',{ device_id:before.id, generation:before.generation, ...point,
        fix_at:Math.floor(Date.now()/1000), arrival_grants:grants }), 'deliver fresh phone reading');
    }
    await eventually('Phone did not publish the new reading', async () => {
      const d = await status(phone);
      return d.fix_at > previousFix && d.latitude === point.latitude && d.longitude === point.longitude &&
        d.accuracy === point.accuracy;
    });
  }
  async function arrival() {
    assert.deepEqual(await updates(ownerContext,owner,9), [], 'Other conversation must not claim the arrival');
    assert.deepEqual(await updates(otherContext,other,10), [], 'Other account must not claim the arrival');
    const messages = await updates();
    assert.equal(messages.length, 1, 'Expected one private arrival announcement');
    const speech = messages[0].speech;
    assert.ok(speech.includes(place.name) && speech.includes(openTask), 'Arrival must name the saved place and actual open task: ' + speech);
    assert.doesNotMatch(speech, /archived mix|shelved session|groceries|other agent session|other owner session/i);
    assert.ok(!speech.includes(String(place.longitude)), 'Arrival speech must not include raw coordinates');
    assert.deepEqual(await updates(), [], 'Polling the same visit must not repeat speech');
    return Number(messages[0].message_id);
  }
  const start = await status(selectedPhone);
  assert.equal(start.history_state, 'off');
  const requested = 'When my iPhone arrives at ' + place.name + ', show me my open recording tasks.';
  const proposed = await send(requested);
  assert.ok(proposed.answer.includes(selectedName) && proposed.answer.includes(place.name), proposed.answer);
  assert.match(proposed.answer, /confirm/i);
  assert.deepEqual((await status(selectedPhone)).arrival_rules, [], 'Chat proposal alone must not request holder consent');
  const confirmKey = randomUUID();
  const confirmed = await send('Confirm arrival alert', confirmKey);
  assert.equal((await send('Confirm arrival alert',confirmKey)).assistant_message_id, confirmed.assistant_message_id);
  const [rule] = (await status(selectedPhone)).arrival_rules;
  assert.ok(rule && rule.place_name === place.name && rule.state === 'requested');
  assert.deepEqual((await status(guestPhone)).arrival_rules, []);
  const consent = { device_id:selectedId, generation:start.generation, rule_id:rule.id, rule_version:rule.version };
  assert.equal((await phoneApi(guestPhone, 'accept_arrival', consent)).ok, false);
  if (handsetPage) {
    const row = handsetPage.locator('#phone-arrivals [data-rule-id="' + rule.id + '"]');
    const accept = row.locator('[data-arrival-action="accept_arrival"]');
    await accept.waitFor({state:'visible'});
    assert.ok((await row.innerText()).includes(place.name));
    assert.match(await row.innerText(), /Map Operator/);
    await accept.scrollIntoViewIfNeeded();
    await screenshot(handsetPage, 'phone-arrival-consent');
    await accept.click();
    await row.locator('[data-arrival-action="stop_arrival"]').waitFor({state:'visible'});
  } else {
    check(await phoneApi(selectedPhone, 'accept_arrival', consent), 'holder accepts arrival');
  }
  const [active] = (await status(selectedPhone)).arrival_rules;
  assert.equal(active.state, 'active');
  const consentSecond = Math.floor(Date.now()/1000);
  await eventually('Need a captured second after consent', () => Math.floor(Date.now()/1000) > consentSecond);

  await move(selectedPhone, outside);
  await move(guestPhone, inside);
  assert.deepEqual(await updates(), [], 'Guest arriving first must stay silent');
  const stale = await phoneApi(selectedPhone, 'fix', { device_id:selectedId, generation:start.generation,
    ...inside, fix_at:Math.floor(Date.now()/1000)-120, arrival_grants:{[active.id]:active.version} });
  assert.equal(stale.status,422);
  await move(selectedPhone, { ...inside, latitude:place.latitude + 0.00002, accuracy:450 });
  assert.deepEqual(await updates(), [], 'Stale and imprecise readings must stay silent');
  await move(selectedPhone, inside);
  const firstId = await arrival();
  await move(selectedPhone, { ...inside, latitude:place.latitude + 0.00003 });
  assert.deepEqual(await updates(), [], 'Another good fix inside the same visit must stay silent');
  await move(selectedPhone, outside);
  await move(selectedPhone, inside);
  const secondId = await arrival();
  assert.ok(Number(firstId) > 0 && Number(secondId) > Number(firstId));
  const reloadChat = async () => check(await chatApi(ownerContext, owner, 'load'), 'reopen the private conversation').messages;
  const thisVisit = m => m.role === 'assistant' && Number(m.id) >= firstId && m.message.includes(openTask);
  const visits = (await reloadChat()).filter(thisVisit);
  assert.deepEqual(visits.map(m => Number(m.id)), [Number(firstId), Number(secondId)]);
  assert.deepEqual(await updates(), [], 'Reopening Chat cannot re-announce either visit');
  assert.deepEqual(check(await ownerApi(ownerContext,owner,'history',{device_id:selectedId}), 'live-only arrivals').points, []);
  if (handsetPage) {
    await handsetPage.reload();
    await eventually('Reload must relinquish the interrupted live session', async () => (await status(selectedPhone)).location_state === 'off');
    await startWithFreshSensorReading(handsetPage, selectedPhone, { ...inside, latitude:place.latitude + 0.00004 });
    await eventually('Explicitly restarted handset did not resume live sharing', async () => (await status(selectedPhone)).location_state === 'live');
    assert.deepEqual(await updates(), [], 'Reopening the phone at the same place must never manufacture another visit');
    const returned = (await reloadChat()).filter(thisVisit);
    assert.equal(returned.length, 2);
  }
  await verifyLocalResearchJourney({ownerContext,owner,otherContext,other,selectedPhone,guestPhone,
    selectedId,selectedName,place,handsetPage,previousRuleId:active.id,move,status,send,updates,reloadChat});
  console.log('PASS Location Specialist: separate holder consent, selected phone only, accurate outside-to-inside arrivals, exact private open tasks, silent reload, re-entry and live-only GPS.');
}


async function verifyLocalResearchJourney({ ownerContext, owner, otherContext, other, selectedPhone, guestPhone,
  selectedId, selectedName, place, handsetPage, previousRuleId, move, status, send, updates, reloadChat }) {
  const runWorker = () => JSON.parse(execFileSync(php, ['-d','session.save_path='+sessions,
    path.join(repo,'tests/phone-tracking-local-research-fixture.php'),site,owner.session,owner.binding], {encoding:'utf8'}));
  const changeTask = state => {
    const sql = "UPDATE agent_memory_items SET metadata_json=? WHERE user_id=1 AND subject=?";
    const run = "$db=new PDO('sqlite:'.$argv[1]);$q=$db->prepare($argv[2]);" +
      "$q->execute([json_encode(['task_status'=>$argv[3]]),$argv[4]]);if($q->rowCount()!==1)exit(1);";
    execFileSync(php,['-r',run,path.join(temp,'data.sqlite'),sql,state,'Recording: buy XLR cables for confidential Juniper rehearsal']);
  };
  changeTask('open');
  const current = await status(selectedPhone);
  const prior = current.arrival_rules.find(rule=>rule.id===previousRuleId);
  if (prior) {
    check(await phoneApi(selectedPhone,'stop_arrival',{device_id:selectedId,generation:current.generation,
      rule_id:prior.id,rule_version:prior.version}), 'withdraw the plain task alert before requesting a different one');
  }
  const setup = 'When my iPhone arrives at '+place.name+', check my open recording tasks. If I still need XLR cables, suggest nearby music stores open today.';
  const summary = await send(setup);
  assert.ok(summary.answer.includes(selectedName) && summary.answer.includes(place.name),summary.answer);
  assert.match(summary.answer,/XLR cables/i);
  assert.match(summary.answer,/music stores/i);
  await send('Confirm arrival alert');
  const [rule] = (await status(selectedPhone)).arrival_rules;
  assert.ok(rule && rule.place_name===place.name && rule.state==='requested');
  assert.deepEqual((await status(guestPhone)).arrival_rules,[]);
  if (handsetPage) {
    const row = handsetPage.locator('#phone-arrivals [data-rule-id="'+rule.id+'"]');
    await row.locator('[data-arrival-action="accept_arrival"]').click();
    await row.locator('[data-arrival-action="stop_arrival"]').waitFor({state:'visible'});
  } else {
    check(await phoneApi(selectedPhone,'accept_arrival',{device_id:selectedId,generation:current.generation,
      rule_id:rule.id,rule_version:rule.version}), 'phone approves the conditional alert');
  }
  const acceptedAt = Math.floor(Date.now()/1000);
  await eventually('Wait for a sensor reading taken after conditional consent',()=>Math.floor(Date.now()/1000)>acceptedAt);
  const outside = {latitude:place.latitude+0.014,longitude:place.longitude,accuracy:8};
  const inside = {latitude:place.latitude+0.00002,longitude:place.longitude,accuracy:8};
  await move(selectedPhone,outside);
  await move(guestPhone,outside);
  await move(guestPhone,inside);
  assert.deepEqual(await updates(),[], 'Guest phone cannot offer local research');
  assert.deepEqual(runWorker().queries,[], 'Setup and guest proximity cannot issue an external query');
  await move(selectedPhone,inside);
  assert.deepEqual(await updates(otherContext,other,10),[]);
  assert.deepEqual(await updates(ownerContext,owner,9),[]);
  const offered = await updates();
  assert.equal(offered.length,1);
  const offerId = Number(offered[0].message_id);
  assert.ok(Number.isSafeInteger(offerId) && offerId>0);
  assert.match(offered[0].speech,/XLR cables/i);
  assert.match(offered[0].speech,/music stores/i);
  assert.deepEqual(await updates(),[],'One eligible visit may offer one search');
  assert.deepEqual(runWorker().queries,[], 'An eligible arrival may only offer research');
  const preview = await send('Search area for offer #'+offerId+': Phoenix, Arizona');
  const approval = preview.answer.match(/Approve local search ([A-F0-9]{12})/i)?.[0];
  assert.ok(approval && /Phoenix/i.test(preview.answer) && /music stores/i.test(preview.answer)
    && /XLR cables/i.test(preview.answer),preview.answer);
  assert.deepEqual(runWorker().queries,[], 'Selecting a public area still requires explicit approval');
  const approveKey = randomUUID();
  const approved = await send(approval,approveKey);
  assert.equal((await send(approval,approveKey)).assistant_message_id,approved.assistant_message_id);
  const work = runWorker();
  assert.equal(work.queries.length,1,JSON.stringify(work));
  const [query] = work.queries;
  assert.ok(preview.answer.includes(query),'The provider must receive the exact query shown for approval: '+query);
  assert.match(query,/Phoenix/);
  assert.match(query,/XLR cables/i);
  assert.match(query,/music stores/i);
  for (const secret of [place.name, selectedName, 'Juniper', 'Recording:', 'Map Operator', String(place.longitude), String(place.latitude)]) {
    assert.ok(!query.toLowerCase().includes(secret.toLowerCase()),'External search leaked a private fixture field');
  }
  assert.deepEqual(new Set(work.pages),new Set([
    'https://west-sound.example.org/phoenix','https://desert-strings.example.org/phoenix'
  ]));
  assert.deepEqual(runWorker().queries,[],'A second worker must not repeat the approved external search');
  assert.deepEqual(await updates(ownerContext,owner,9),[],'Another private conversation cannot claim results');
  const [result] = await updates();
  assert.ok(result?.speech.includes('West Sound Music') && result.speech.includes('Desert Strings'),JSON.stringify(result));
  assert.match(result.speech,/10:00|10\s*(?:am|a\.m\.)/i);
  assert.match(result.speech,/in stock/i);
  const desert = result.speech.split('Desert Strings')[1].split('West Sound Music')[0];
  assert.match(desert,/hours[^\n]{0,110}(?:unverified|unknown|not verified|not confirmed)/i);
  assert.match(desert,/(?:stock|availability)[^\n]{0,110}(?:unverified|unknown|not verified|not confirmed)/i);
  assert.doesNotMatch(desert,/always in stock|open 24 hours/i, 'A discovery snippet cannot verify opening hours or stock');
  assert.deepEqual(await updates(),[],'A completed search must be announced only once');
  const saved = (await reloadChat()).find(row=>Number(row.id)===Number(result.message_id));
  assert.ok(saved?.message.includes('West Sound Music') && saved.message.includes('Desert Strings'));
  const context = typeof saved.context_json==='string' ? JSON.parse(saved.context_json) : saved.context_json;
  assert.ok(Array.isArray(context?.sources) && context.sources.length===2,JSON.stringify(context));
  assert.deepEqual(new Set(context.sources.map(s=>s.url)),new Set(work.pages));
  for (const source of context.sources) {
    assert.equal(source.coverage,'page_excerpt','A store fact requires evidence from the fetched page');
    assert.equal(source.source,'location-web:'+createHash('sha256').update(source.url).digest('hex'));
  }
  changeTask('completed');
  await move(selectedPhone,outside);
  await move(selectedPhone,inside);
  assert.deepEqual(await updates(),[],'Completing the relevant task prevents research offers on the next visit');
  assert.deepEqual(runWorker().queries,[]);
  assert.deepEqual(check(await ownerApi(ownerContext,owner,'history',{device_id:selectedId}), 'research with history off').points,[]);
  console.log('PASS Location research: two phones, one eligible offer, exact public area approval, sourced stores, private task completion suppresses re-entry.');
}

async function verifyLinkedPhoneHistory(desktop, link, linkedId, remainingId, revoke) {
  const page = await desktop.newPage();
  const historyReads = [];
  page.on('request', req => {
    const url = new URL(req.url());
    if (url.pathname === ownerEndpoint && url.searchParams.get('action') === 'history') {
      historyReads.push(url.searchParams.get('device_id'));
    }
  });
  const select = page.locator('#phone-history-device');
  const assertUnavailable = async () => {
    await eventually('Unavailable phone links must leave the trail unselected', async () =>
      await select.inputValue() === '' && /linked phone is unavailable/i.test(await page.locator('#phone-history-status').innerText()));
    assert.equal(await page.locator('#phone-history-points > li[data-fix-at], .phone-history-trail').count(), 0);
  };
  try {
    await page.goto(link);
    await eventually('The Chat link must open history for the named phone', async () =>
      await select.isVisible() && await select.inputValue() === linkedId && historyReads.includes(linkedId));
    await revoke();
    await assertUnavailable();
    assert.ok(historyReads.every(id => id === linkedId), 'Revoking a linked phone must not read another phone’s history');

    const malformed = new URL(page.url());
    malformed.searchParams.set('device_id', 'invalid');
    historyReads.length = 0;
    await page.goto(malformed.href);
    await assertUnavailable();
    assert.deepEqual(historyReads, []);
    await select.selectOption(remainingId);
    await eventually('Choosing an available phone must load its own trail', () => historyReads.includes(remainingId));
  } finally {
    await page.close();
  }
}

/** Production DOM component with synthetic API responses; no carrier or real phone number. */
async function verifyManagedReviewUI(context){
  const page=await context.newPage();
  try{
    await page.setViewportSize({width:390,height:844});
    await page.setContent('<main id="owner"></main><main id="holder"></main>');
    await page.addScriptTag({content:readFileSync(path.join(repo,'phone-managed-call-ui-v1.js'),'utf8')});
    await page.addStyleTag({content:readFileSync(path.join(repo,'phone-managed-call-ui-v1.css'),'utf8')});
    await page.evaluate(()=>{
      const epoch=Math.floor(Date.now()/1000),device='a'.repeat(32),profile='b'.repeat(32);
      const p={available:true,dispatch_enabled:false,server_epoch:epoch,reason:'review_only',request:null,lines:[{id:2,phone_e164:'+16025550102',agent_name:'Fixture Agent'}]};
      const d={id:device,status:'approved',generation:1,communication:{profile_id:profile,revision:1,phone_revision:1,call_permission_saved:true},agent_chat:{conversation_id:21,agent_id:0},managed_callback:p};
      const holderDevice={...d,managed_callback:{...p,lines:[]}};
      const calls=[];let saved=null;
      function refresh(){
        const redacted=saved?{request_id:saved.request_id,state:saved.state,expires_epoch:epoch+120,...(saved.transport==='twilio_native'?{transport:'twilio_native',native:{state:saved.issued?'issued':'review',enabled:true,can_issue_pin:false}}:{})}:null;
        d.managed_callback={...p,request:redacted};
        holderDevice.managed_callback={...p,lines:[],request:redacted?{...redacted,...(saved.state==='pending'?{destination_e164:saved.destination_e164}:{}),...(redacted.native?{native:{...redacted.native,can_issue_pin:saved.state==='approved'&&!saved.issued}}:{})}:null};
        ownerUI.bind(d,true);holderUI.bind(holderDevice,true);
      }
      const ownerUI=window.VP3ManagedCallUIV1.owner({directRoot:document.createElement('div'),openBrowser:()=>{},request:async(action,body)=>{
        calls.push({action,body});
        if(action==='managed_prepare')saved={request_id:body.managed_request_id,destination_e164:body.destination_e164,state:'pending',transport:body.transport||'twilio_callback'};
        if(action==='managed_cancel')saved.state='canceled';
        refresh();return {managed_callback:d.managed_callback};
      }});
      const holderUI=window.VP3ManagedCallUIV1.holder({request:async(action,body)=>{
        calls.push({action,body});
        if(action==='native_dialthrough_issue'){saved.issued=true;refresh();return {device:holderDevice,native_dialthrough_ticket:{request_id:saved.request_id,line_e164:'+16025550102',pin:'00412567',server_epoch:epoch,expires_epoch:epoch+120}};}
        saved.state=body.decision==='approve'?'approved':'declined';refresh();return {device:holderDevice};
      }});
      document.getElementById('owner').append(ownerUI.root);
      document.getElementById('holder').append(holderUI.root);
      window.managedFixture={calls,ownerUI,holderUI,d,holderDevice,refresh};
      refresh();
    });
    const own=page.locator('#owner'),hold=page.locator('#holder');
    await own.getByRole('button',{name:'Twilio managed callback',exact:true}).click();
    const send=own.getByRole('button',{name:'Request managed callback approval',exact:true});
    await own.getByLabel('Managed destination number (international format)').fill('+16025550148');
    assert.equal(await send.isEnabled(),false,'confirmation required before preparing');
    await own.getByRole('checkbox').check();await send.click();
    await hold.getByText('+16025550148',{exact:true}).waitFor();
    assert.equal(await hold.getByRole('button',{name:'Approve managed request'}).isEnabled(),false);
    await hold.getByRole('checkbox').check();await hold.getByRole('button',{name:'Approve managed request'}).click();
    await own.getByText('Approved — no call has been placed',{exact:true}).waitFor();
    assert.equal(await hold.getByText('+16025550148',{exact:true}).isVisible(),false,'pending destination removed after approval');
    await own.getByRole('button',{name:'Cancel managed request'}).click();
    await own.getByText('Canceled',{exact:true}).waitFor();
    const sent=await page.evaluate(()=>window.managedFixture.calls);
    assert.deepEqual(sent.map(x=>x.action),['managed_prepare','managed_respond','managed_cancel']);
    assert.equal(sent[0].body.device_id,'a'.repeat(32));assert.equal(sent[1].body.destination_e164,'+16025550148');
    assert.equal(sent[0].body.managed_request_id,sent[1].body.managed_request_id);
    assert.ok(!JSON.stringify(sent).includes('recording_enabled'));
    await own.getByLabel('Managed destination number (international format)').fill('+16025550149');
    await page.evaluate(()=>{const {ownerUI,d,holderUI}=window.managedFixture;ownerUI.bind({...d,id:'c'.repeat(32)},true);holderUI.invalidate();});
    await own.getByRole('button',{name:'Twilio managed callback',exact:true}).click();
    assert.equal(await own.getByLabel('Managed destination number (international format)').inputValue(),'','switching devices clears managed destination draft');
    // The empty parent may have zero layout height. Assert the sensitive child is actually hidden.
    assert.equal(await hold.locator('.phone-managed-holder').isVisible(),false);
    assert.equal(await hold.locator('.phone-managed-destination').textContent(),'');
    // Mount a synthetic existing managed call; controls must not initiate a new call.
    const mountManagedCall=()=>page.evaluate(()=>{
      const {ownerUI,d}=window.managedFixture;
      d.managed_callback={...d.managed_callback,request:{request_id:'d'.repeat(32),state:'dispatch_claimed',expires_epoch:Math.floor(Date.now()/1000)+120,
        call:{public_id:'e'.repeat(32),state:'ringing',dispatch_state:'bridging',duration_seconds:0,managed_control:{can_reconcile:true,can_end:true,end_state:'none',read_state:'none'}}}};
      ownerUI.bind(d,true);ownerUI.setMode('managed');
    });
    await mountManagedCall();
    await own.getByRole('button',{name:'Check carrier status',exact:true}).click();
    await page.waitForFunction(()=>window.managedFixture.calls.some(x=>x.action==='managed_reconcile'));
    await mountManagedCall();
    page.once('dialog',dialog=>dialog.accept());
    await own.getByRole('button',{name:'End managed call',exact:true}).click();
    await page.waitForFunction(()=>window.managedFixture.calls.some(x=>x.action==='managed_end'));
    const controlCalls=await page.evaluate(()=>window.managedFixture.calls.slice(3));
    assert.deepEqual(controlCalls.map(x=>x.action),['managed_reconcile','managed_end']);
    assert.equal(controlCalls[1].body.call_id,'e'.repeat(32));
    assert.equal(controlCalls[1].body.confirmed,true);
    // Native mode uses the same approval form but never auto-opens the OS dialer.
    await page.evaluate(()=>{const {d,ownerUI,refresh}=window.managedFixture;d.managed_callback={...d.managed_callback,request:null};ownerUI.bind(d,true);ownerUI.setMode('native');});
    await own.getByLabel('Managed destination number (international format)').fill('+16025550159');
    await own.getByRole('checkbox').check();await own.getByRole('button',{name:'Request dial-through approval',exact:true}).click();
    await hold.getByText('+16025550159',{exact:true}).waitFor();
    await hold.getByRole('checkbox').check();await hold.getByRole('button',{name:'Approve managed request',exact:true}).click();
    await hold.getByRole('button',{name:'Get one-time dialing code',exact:true}).click();
    await hold.getByText('00412567',{exact:true}).waitFor();
    await hold.getByRole('button',{name:'Open native phone dialer',exact:true}).waitFor();
    assert.equal(await own.getByText('00412567',{exact:true}).count(),0,'PIN never appears in owner view');
    assert.equal(await hold.getByRole('button',{name:'Get one-time dialing code',exact:true}).isVisible(),false);
    await page.evaluate(()=>window.managedFixture.holderUI.invalidate());
    assert.equal(await hold.getByText('00412567',{exact:true}).count(),0,'invalidating holder view removes native PIN');
    const nativeCalls=await page.evaluate(()=>window.managedFixture.calls.slice(5));
    assert.deepEqual(nativeCalls.map(x=>x.action),['managed_prepare','managed_respond','native_dialthrough_issue']);
    assert.equal(nativeCalls[0].body.transport,'twilio_native');assert.equal(nativeCalls[2].body.confirmed,true);
    // A simulated administrator-approved pilot exposes one explicit billable action only.
    await page.evaluate(()=>{
      const {d,ownerUI}=window.managedFixture;
      d.managed_callback={...d.managed_callback,dispatch_enabled:false,server_epoch:Math.floor(Date.now()/1000),
        request:{request_id:'f'.repeat(32),state:'approved',transport:'twilio_callback',expires_epoch:Math.floor(Date.now()/1000)+120}};
      ownerUI.bind(d,true);ownerUI.setMode('managed');
    });
    const pilot=own.getByRole('button',{name:'Start unrecorded pilot call',exact:true});
    assert.equal(await pilot.isVisible(),false);
    await page.evaluate(()=>{
      const {d,ownerUI}=window.managedFixture;
      d.managed_callback={...d.managed_callback,dispatch_enabled:true};ownerUI.bind(d,true);
    });
    await pilot.waitFor();
    page.once('dialog',dialog=>dialog.dismiss());
    await pilot.click();
    assert.equal(await page.evaluate(()=>window.managedFixture.calls.some(x=>x.action==='managed_pilot_dispatch')),false,'canceling confirmation sends no paid request');
    page.once('dialog',dialog=>dialog.accept());
    await pilot.click();
    await page.waitForFunction(()=>window.managedFixture.calls.some(x=>x.action==='managed_pilot_dispatch'));
    const pilotBody=await page.evaluate(()=>window.managedFixture.calls.find(x=>x.action==='managed_pilot_dispatch').body);
    assert.equal(pilotBody.managed_request_id,'f'.repeat(32));assert.equal(pilotBody.confirmed,true);
    assert.equal(Object.hasOwn(pilotBody,'destination_e164'),false,'the UI cannot substitute the approved destination');
    await page.evaluate(()=>{
      const {d,ownerUI}=window.managedFixture;
      d.managed_callback={...d.managed_callback,dispatch_enabled:true,request:{request_id:'e'.repeat(32),state:'approved',transport:'twilio_callback',expires_epoch:Math.floor(Date.now()/1000)+120,
        recording:{requested:true,state:'requested',revision:1}}};
      ownerUI.bind(d,true);
    });
    assert.equal(await pilot.isVisible(),false,'recorded requests cannot use unrecorded pilot');
    page.once('dialog',dialog=>dialog.accept());
    await own.getByRole('button',{name:'Withdraw recording consent',exact:true}).click();
    await page.waitForFunction(()=>window.managedFixture.calls.some(x=>x.action==='managed_recording_withdraw'));
    console.log('PASS Managed call review, native code and explicit pilot controls without actual carrier traffic.');
  }finally{await page.close();}
}


/** Real server pairing in a new, isolated installation context. Not physical OS installation. */
async function verifyInstalledPairing(baseURL,desktop,owner){
  const installed=await browser.newContext({baseURL,viewport:{width:390,height:844},serviceWorkers:'allow'});
  const guest=await installed.newPage(),map=await desktop.newPage(),errors=[];
  guest.on('pageerror',e=>errors.push(e.message));
  await guest.addInitScript(()=>Object.defineProperty(navigator,'standalone',{configurable:true,value:true}));
  try{
    await guest.goto('/phone-location.php');
    await guest.locator('#phone-setup-guide').click();
    await guest.getByText('Opened from your installed app.',{exact:true}).waitFor();
    await guest.locator('#phone-info-back').click();
    await guest.locator('#phone-pair-entry').waitFor();
    await guest.waitForFunction(()=>document.getElementById('phone-install-panel').dataset.workerState==='registered');
    const regs=await guest.evaluate(async()=>Array.from(await navigator.serviceWorker.getRegistrations(),r=>r.scope));
    assert.deepEqual(regs,[baseURL+'/phone-location.php'],'worker is not installed for main Chat or the whole origin');
    await map.goto('/phone-tracking.php?agent=system&conversation_id=8');
    await map.locator('#phone-connect').click();
    await map.locator('#phone-qr svg').waitFor();
    await map.locator('#phone-install-code-box summary').click();
    const code=await map.locator('#phone-install-code').inputValue();
    assert.match(code,/^[A-Za-z0-9_-]{43}$/);
    const requests=[];guest.on('request',req=>{requests.push(req.url());});
    await guest.locator('#phone-pair-code').fill(code);
    await guest.locator('#phone-pair-continue').click();
    await guest.locator('#phone-name').fill('Installed phone fixture');
    await guest.locator('#phone-claim').click();
    await eventually('manual code must create a pending device',async()=>check(await ownerApi(desktop,owner),'manual pairing').devices.some(d=>d.name==='Installed phone fixture'&&d.status==='pending'));
    const d=check(await ownerApi(desktop,owner),'manual pending read').devices.find(d=>d.name==='Installed phone fixture');
    assert.equal(d.location_state,'off');assert.ok(!requests.some(u=>u.includes(code)));
    assert.equal(await guest.locator('#phone-pair-code').inputValue(),'');
    assert.equal(await guest.evaluate(secret=>Object.values(sessionStorage).some(v=>v.includes(secret))||Object.values(localStorage).some(v=>v.includes(secret)),code),false,'manual invite never persists in browser storage');
    const approved=check(await ownerApi(desktop,owner,'approve',{device_id:d.id,generation:d.generation,role:'guest',allow_location:false}),'installed device approval').device;
    await eventually('installed connection must become approved',async()=>(await phoneApi(installed,'status')).device?.status==='approved');
    await guest.reload();
    await guest.waitForFunction(()=>document.getElementById('phone-badge').textContent==='Connected phone');
    assert.equal((await phoneApi(installed,'status')).device.id,d.id,'same installation keeps the existing authenticated cookie');
    const reused=await browser.newContext({baseURL});
    try{assert.equal((await phoneApi(reused,'claim',{invite:code,name:'Replay attempt'})).status,403);}finally{await reused.close();}
    // Transfer the existing device into an independently authenticated app.
    const moved=await browser.newContext({baseURL,viewport:{width:390,height:844},serviceWorkers:'allow'});
    const fresh=await moved.newPage();
    fresh.on('pageerror',e=>errors.push(e.message));
    try{
      const messageId=randomUUID();
      check(await phoneApi(installed,'chat_send',{device_id:d.id,request_id:messageId,message:'Private handoff fixture'}),'source chat');
      await guest.getByRole('tab',{name:'Info',exact:true}).click();
      await guest.locator('#phone-handoff-panel summary').click();
      await guest.locator('#phone-handoff-offer').waitFor();
      guest.once('dialog',dialog=>dialog.accept());
      await guest.locator('#phone-handoff-offer').click();
      await guest.locator('#phone-handoff-ticket').waitFor();
      const moveCode=await guest.locator('#phone-handoff-code').inputValue();
      assert.match(moveCode,/^[A-Za-z0-9_-]{43}$/);
      await fresh.goto('/phone-location.php');
      await fresh.locator('#phone-setup-guide').click();
      await fresh.locator('#phone-handoff-panel summary').click();
      await fresh.locator('#phone-handoff-entry').fill(moveCode);
      await fresh.locator('#phone-handoff-claim').click();
      await fresh.waitForFunction(()=>/^[0-9A-F]{8}$/.test(document.getElementById('phone-handoff-compare').textContent));
      const comparison=await fresh.locator('#phone-handoff-compare').textContent();
      assert.equal(await fresh.locator('#phone-handoff-finish').isVisible(),false);
      assert.equal((await phoneApi(installed,'status')).device.id,d.id,'source remains authorized during review');
      await map.locator('#phone-review').waitFor();
      if(!await map.locator('#phone-pairing').isVisible())await map.locator('#phone-review').click();
      const review=map.locator('#phone-handoff-pending [data-handoff-id]');
      await review.waitFor();
      assert.match(await review.innerText(),new RegExp(comparison));
      map.once('dialog',dialog=>dialog.accept());
      await review.getByRole('button',{name:'Approve move',exact:true}).click();
      await eventually('owner must approve exact target',async()=>check(await ownerApi(desktop,owner),'transfer approval').devices.find(v=>v.id===d.id)?.handoff?.state==='approved');
      await fresh.locator('#phone-handoff-check').click();
      await fresh.locator('#phone-handoff-finish').waitFor();
      assert.equal((await phoneApi(installed,'status')).device.id,d.id,'approval alone leaves old session valid');
      fresh.once('dialog',dialog=>dialog.accept());
      await fresh.locator('#phone-handoff-finish').click();
      await fresh.waitForFunction(()=>document.getElementById('phone-badge').textContent==='Connected phone');
      const newDevice=check(await phoneApi(moved,'status'),'moved device').device;
      assert.equal(newDevice.id,d.id);assert.ok(newDevice.generation>approved.generation);
      assert.equal(newDevice.location_state,'off');
      assert.equal((await phoneApi(installed,'status')).status,403);
      const history=check(await phoneApi(moved,'chat_status',{device_id:d.id}),'preserved private conversation').turns;
      assert.ok(history.some(turn=>turn.request_id===messageId&&turn.message==='Private handoff fixture'));
      assert.equal(await fresh.evaluate(secret=>Object.values(sessionStorage).some(v=>v.includes(secret))||Object.values(localStorage).some(v=>v.includes(secret)),moveCode),false);
      check(await ownerApi(desktop,owner,'revoke',{device_id:d.id,generation:newDevice.generation}),'revoke moved fixture');
      assert.equal((await phoneApi(moved,'status')).status,403);
    }finally{await moved.close();}
    if(await map.locator('#phone-pair-close').isVisible())await map.locator('#phone-pair-close').click();
    await eventually('closed invite must be cleared',async()=>await map.locator('#phone-install-code').inputValue()==='');
    assert.deepEqual(errors,[]);
  }finally{await installed.close();await map.close();}
}
