import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {artistOrigin, providerOrigin, providerRoute, manageRoute, protocol, musicProviderFixture, beginInput, eventInput} from './music-provider-fixture-v1.mjs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = await musicProviderFixture();
const traffic = [];
let browser;
let proxy;
let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); checks++;};
const equal = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++;};
const artifactDirectory = path.resolve('artifacts/music-provider-v1');
fs.mkdirSync(artifactDirectory, {recursive: true});

/**
 * Real cross-origin HTTPS requests and real PHP responses. The ephemeral TLS
 * certificate is trusted only in this isolated test context. Host resolver
 * mapping changes the network socket destination, not Host/Origin/URL values;
 * CORS, mixed-content and cookie rules remain enabled. Domain verification is
 * separately exercised through the service transport fixture, not certified as
 * an external DNS/TLS deployment by this browser test.
 */
async function httpsFixture() {
  const key = path.join(fixture.root, 'fixture-key.pem');
  const cert = path.join(fixture.root, 'fixture-cert.pem');
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1',
    '-subj', '/CN=provider.example.test', '-addext', 'subjectAltName=DNS:provider.example.test,DNS:artist.example.test,DNS:other.example.test',
    '-keyout', key, '-out', cert], {stdio: 'ignore'});
  const target = new URL(fixture.base);
  proxy = https.createServer({key: fs.readFileSync(key), cert: fs.readFileSync(cert)}, (request, response) => {
    const hostname = (request.headers.host || '').split(':')[0];
    if (hostname === 'artist.example.test' || hostname === 'other.example.test') {
      response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
      response.end('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Artist playback fixture</title></head><body><main><h1>Artist music</h1><audio id="recording" controls crossorigin="anonymous"></audio><button id="play">Play selected recording</button><p id="status" role="status">Waiting for provider</p></main><script>document.getElementById("play").onclick=()=>document.getElementById("recording").play();</script></body></html>');
      return;
    }
    if (hostname !== 'provider.example.test') {response.writeHead(404); response.end(); return;}
    traffic.push({method: request.method, path: new URL(request.url, providerOrigin).pathname,
      origin: request.headers.origin || '', cookie: Boolean(request.headers.cookie), authorization: Boolean(request.headers.authorization)});
    const upstream = http.request({hostname: target.hostname, port: target.port,
      method: request.method, path: request.url, headers: request.headers}, incoming => {
      response.writeHead(incoming.statusCode, incoming.headers);
      incoming.pipe(response);
    });
    upstream.on('error', () => {if (!response.headersSent) response.writeHead(502); response.end('Fixture upstream unavailable.');});
    request.pipe(upstream);
  });
  await new Promise((resolve, reject) => {proxy.once('error', reject); proxy.listen(0, '127.0.0.1', resolve);});
  return proxy.address().port;
}

async function browserPost(page, fields, playbackKey = '') {
  return page.evaluate(async ({fields, playbackKey, providerOrigin, providerRoute, protocol}) => {
    const response = await fetch(providerOrigin + providerRoute + '?site_id=' + encodeURIComponent(fields.site_id), {
      method: 'POST', credentials: 'omit', headers: {'Content-Type': 'application/json',
        ...(playbackKey ? {Authorization: 'Bearer ' + playbackKey} : {})},
      body: JSON.stringify({protocol, ...fields}),
    });
    return {status: response.status, data: await response.json()};
  }, {fields, playbackKey, providerOrigin, providerRoute, protocol});
}

try {
  const {site} = fixture.createSite();
  const identity = fixture.identities.find(item => Number(item.catalog_track_id) === 10);
  const selection = fixture.ownerService({action: 'selection.update', workspace_id: 1, site_id: site.site_id,
    request_id: crypto.randomUUID(), expected_selection_revision: site.selection_revision, recording_ids: [identity.recording_id]});
  const tlsPort = await httpsFixture();
  browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
    args: ['--no-sandbox', '--no-proxy-server',
      `--host-resolver-rules=MAP provider.example.test 127.0.0.1:${tlsPort}, MAP artist.example.test 127.0.0.1:${tlsPort}, MAP other.example.test 127.0.0.1:${tlsPort}`]});
  const visitor = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}});
  // Existing unrelated cookies must not become a requirement or leak into
  // cookie-free playback API or crossorigin=anonymous audio requests.
  await visitor.addCookies([{name: 'private_owner_fixture', value: 'unused', url: providerOrigin},
    {name: 'private_artist_fixture', value: 'unused', url: artistOrigin}]);
  const page = await visitor.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(artistOrigin + '/music', {waitUntil: 'domcontentloaded'});
  equal(new URL(page.url()).origin, artistOrigin, 'Visitor page retains actual external HTTPS origin');
  const input = beginInput(site.site_id, identity.recording_id, {source_url: artistOrigin + '/music?token=PRIVATE-PAGE#PRIVATE-FRAGMENT'});
  const begun = await browserPost(page, input);
  equal(begun.status, 200, 'Cross-origin browser begin passes real CORS');
  equal(begun.data.state, 'pending', 'Browser allocation is not a heard start');
  equal(begun.data.source.url, artistOrigin + '/music', 'Browser page source is safely retained');
  check(traffic.some(item => item.method === 'OPTIONS' && item.origin === artistOrigin), 'Real browser made provider preflight');
  const bytes = await page.evaluate(async url => {
    const response = await fetch(url, {credentials: 'omit', headers: {Range: 'bytes=0-9'}});
    return {status: response.status, etag: response.headers.get('etag'), range: response.headers.get('content-range'),
      length: response.headers.get('content-length'), bytes: [...new Uint8Array(await response.arrayBuffer())]};
  }, begun.data.media.url);
  equal(bytes.status, 206, 'Browser receives authorized cross-origin range');
  equal(bytes.bytes, [...fs.readFileSync(path.join(fixture.root, 'uploads/artist-music/1/legacy.wav')).subarray(0, 10)], 'Browser range has exact bytes');
  check(Boolean(bytes.etag) && bytes.range.startsWith('bytes 0-9/'), 'Browser can read exposed version/range headers');
  equal(bytes.length, '10', 'Browser range Content-Length');
  await page.evaluate(url => new Promise((resolve, reject) => {
    const audio = document.getElementById('recording');
    audio.addEventListener('loadedmetadata', () => resolve(), {once: true});
    audio.addEventListener('error', () => reject(new Error('Real audio element could not load provider media')), {once: true});
    audio.src = url;
  }), begun.data.media.url);
  await page.getByRole('button', {name: 'Play selected recording'}).click();
  await page.waitForFunction(() => document.getElementById('recording').currentTime > 0.2);
  await page.evaluate(() => {document.getElementById('recording').pause(); document.getElementById('status').textContent = 'Verified VP3 media is playing through the artist site';});
  const beforeEvidence = fixture.readDb('SELECT started_ms,accepted_played_ms FROM music_provider_sessions_v1 WHERE session_id=?', [begun.data.session_id])[0];
  equal(beforeEvidence, {started_ms: null, accepted_played_ms: 0}, 'Actual media playback without telemetry is not fabricated as observed listening');
  const resume = eventInput(begun.data.session_id, {site_id: site.site_id});
  const resumed = await browserPost(page, resume, input.playback_key);
  equal(resumed.status, 200, 'Cross-origin session-scoped telemetry is accepted');
  check(resumed.data.started && resumed.data.accepted_seq === 1, 'Telemetry records one actual playing boundary');
  const retry = await browserPost(page, resume, input.playback_key);
  equal(retry.data.accepted_seq, 1, 'Browser event retry keeps one receipt');
  equal(retry.data.accepted_played_ms, 0, 'Browser retry manufactures no duration');
  const renewed = await browserPost(page, {action: 'playback.renew', site_id: site.site_id,
    session_id: begun.data.session_id, request_id: crypto.randomUUID()}, input.playback_key);
  equal(renewed.status, 200, 'Browser renewal passes real authorization preflight');
  equal(renewed.data.session_id, begun.data.session_id, 'Browser renewal preserves logical playback');
  equal(renewed.data.asset_id, begun.data.asset_id, 'Browser renewal preserves delivered asset');
  equal(renewed.data.accepted_seq, 1, 'Browser renewal preserves event sequence');
  const matchingRange = await page.evaluate(async ({url, etag}) => {
    const response = await fetch(url, {credentials: 'omit', headers: {Range: 'bytes=0-9', 'If-Range': etag}});
    return {status: response.status, bytes: (await response.arrayBuffer()).byteLength};
  }, {url: renewed.data.media.url, etag: bytes.etag});
  equal(matchingRange, {status: 206, bytes: 10}, 'If-Range preflight and version-aware range work in browser');
  check(traffic.filter(item => item.path.startsWith('/api/music-provider')).every(item => !item.cookie), 'Browser transferred unrelated cookies into visitor authority');
  equal(fixture.readDb('SELECT COUNT(*) AS count FROM track_play_sessions')[0].count, 1, 'Browser provider playback changed private VP3 history');
  await page.screenshot({path: path.join(artifactDirectory, 'external-player-mobile.png'), fullPage: true});
  const attacker = await visitor.newPage();
  await attacker.goto('https://other.example.test/music', {waitUntil: 'domcontentloaded'});
  const blocked = await browserPost(attacker, beginInput(site.site_id, identity.recording_id)).then(() => false, () => true);
  check(blocked, 'Browser CORS did not block an unregistered origin');
  fixture.ownerService({action: 'site.revoke', workspace_id: 1, site_id: site.site_id,
    expected_revision: selection.site.revision, request_id: crypto.randomUUID()});
  const revoked = await page.evaluate(async url => {
    try {const response = await fetch(url, {credentials: 'omit'}); return response.ok;} catch {return false;}
  }, renewed.data.media.url);
  equal(revoked, false, 'Existing browser media ticket survives site revocation');
  equal(errors, [], 'External player browser had uncaught runtime errors');
  await visitor.close();

  const owner = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1280, height: 960},
    extraHTTPHeaders: {'X-User': '11'}});
  const editor = await owner.newPage();
  const editorErrors = [];
  editor.on('pageerror', error => editorErrors.push(error.message));
  editor.on('dialog', dialog => dialog.accept());
  editor.setDefaultTimeout(10000);
  let createResponse;
  let dropCreateResponse = true;
  const creates = [];
  await editor.route('**' + manageRoute, async route => {
    const request = route.request();
    const body = request.postDataJSON();
    if (body.action === 'site.create') {
      creates.push(request.postData());
      if (dropCreateResponse) {
        dropCreateResponse = false;
        // Commit the real operation, then lose only its HTTP acknowledgement.
        // Local routing is explicit because the test transport itself does not
        // use Chromium's fixture DNS socket mapping.
        const result = await route.fetch({url: fixture.base + manageRoute, headers: await request.allHeaders()});
        equal(result.status(), 200, 'Lost-response setup executed actual owner create');
        createResponse = await result.json();
        return route.abort('failed');
      }
    }
    return route.continue();
  });
  const waitIdle = async () => editor.waitForFunction(() => !document.getElementById('musicProviderRefresh').disabled);
  await editor.goto(providerOrigin + '/music-sites.php?workspace=1', {waitUntil: 'domcontentloaded'});
  await waitIdle();
  equal(await editor.getByRole('heading', {name: 'Sites & streaming', exact: true}).count(), 1, 'Actual Sites & API page rendered');
  const form = editor.locator('#musicProviderCreate');
  const displayName = 'Artist <img src=x onerror=window.injected=true>';
  await form.getByLabel('Site name', {exact: true}).fill(displayName);
  await form.getByLabel('Site address', {exact: false}).fill('https://owner.example.test');
  await form.getByRole('button', {name: 'Register site', exact: true}).click();
  await editor.locator('#musicProviderRetry').waitFor({state: 'visible'});
  equal(fixture.readDb('SELECT COUNT(*) AS count FROM music_provider_sites_v1 WHERE origin=?', ['https://owner.example.test'])[0].count, 1, 'Lost browser acknowledgement duplicated site creation');
  check((await editor.locator('#musicProviderStatus').textContent()).includes('Retry'), 'UI explains interrupted response recovery');
  await form.getByLabel('Site name', {exact: true}).fill('A competing unsaved change');
  await form.getByRole('button', {name: 'Register site', exact: true}).click();
  equal(creates.length, 1, 'Uncertain mutation allowed a different create request');
  await editor.locator('#musicProviderRetry').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('Site registered'));
  equal(creates.length, 2, 'Retry did not resend the lost create request');
  equal(creates[0], creates[1], 'Retry changed the original mutation body, key or credential');
  const ownerSiteId = createResponse.site.site_id;
  equal(fixture.readDb('SELECT site_id FROM music_provider_sites_v1 WHERE origin=?', ['https://owner.example.test']), [{site_id: ownerSiteId}], 'Retry returned a different registered site');
  check(await editor.locator('#musicProviderVerification').isVisible(), 'Pending owner site shows exact verification instructions');
  equal(await editor.locator('#musicProviderVerificationUrl').textContent(), 'https://owner.example.test/api/vp3-music-site.php', 'UI verification endpoint is the fixed safe path');
  const firstCredential = await editor.locator('#musicProviderCredential').inputValue();
  check(/^vmp1_[a-f0-9]{64}$/.test(firstCredential), 'Owner receives generated server credential after acknowledged save');
  equal(await editor.evaluate(() => window.injected || false), false, 'Site label executed HTML');
  equal(await editor.locator('#musicProviderSiteList img').count(), 0, 'Site label became injected markup');
  equal(await editor.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]).includes('vmp1_')), false, 'Site credential persisted in browser storage');
  await editor.locator('#musicProviderHideCredential').click();
  equal(await editor.locator('#musicProviderCredential').inputValue(), '', 'Hide credential did not clear the field');
  await editor.screenshot({path: path.join(artifactDirectory, 'sites-desktop-verification.png'), fullPage: true});

  fixture.verifySite(createResponse.site);
  await editor.locator('#musicProviderRefresh').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderDetail').hidden);
  const card = editor.locator('.music-sites-card').filter({hasText: displayName});
  check((await card.textContent()).includes('Verified'), 'External verification refresh did not show current state');
  await card.getByRole('button', {name: 'Manage site & songs'}).click();
  await waitIdle();
  check(!(await editor.locator('#musicProviderVerification').isVisible()), 'Verified site still displays stale verification challenge');
  const firstCheckbox = editor.locator('#musicProviderCatalog input[value="' + identity.recording_id + '"]');
  await firstCheckbox.check();
  await editor.locator('#musicProviderSaveSelection').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('Song selection saved'));
  equal(fixture.readDb('SELECT recording_id FROM music_provider_selections_v1 WHERE site_id=?', [ownerSiteId]), [{recording_id: identity.recording_id}], 'Owner UI did not save the selected canonical recording');
  const ownerSite = fixture.ownerService({action: 'site.list', workspace_id: 1}).sites.find(item => item.site_id === ownerSiteId);
  const conflict = fixture.ownerService({action: 'selection.update', workspace_id: 1, site_id: ownerSiteId,
    expected_selection_revision: ownerSite.selection_revision, request_id: crypto.randomUUID(), recording_ids: []});
  await editor.locator('#musicProviderSaveSelection').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').classList.contains('error'));
  check((await editor.locator('#musicProviderStatus').textContent()).includes('changed'), 'Concurrent selection conflict is understandable');
  check(await firstCheckbox.isChecked(), 'Selection conflict discarded the owner’s draft selection');
  await editor.locator('#musicProviderRefresh').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderDetail').hidden);
  await editor.locator('.music-sites-card').filter({hasText: displayName}).getByRole('button', {name: 'Manage site & songs'}).click();
  await waitIdle();
  check(!(await firstCheckbox.isChecked()), 'Refresh did not load current server selection');
  await firstCheckbox.check();
  const csrf = await editor.locator('#musicProviderSites').getAttribute('data-csrf');
  await editor.locator('#musicProviderSites').evaluate(node => {node.dataset.csrf = 'expired-fixture-token';});
  await editor.locator('#musicProviderSaveSelection').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('session changed'));
  check(await firstCheckbox.isChecked(), 'CSRF refusal discarded owner selection');
  equal(fixture.readDb('SELECT COUNT(*) AS count FROM music_provider_selections_v1 WHERE site_id=?', [ownerSiteId])[0].count, 0, 'CSRF refusal saved songs');
  await editor.locator('#musicProviderSites').evaluate((node, token) => {node.dataset.csrf = token;}, csrf);
  await editor.locator('#musicProviderSaveSelection').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('Song selection saved'));
  check(!await editor.locator('#musicProviderRetry').isVisible(), 'Successful save left a stale retry control');
  await editor.setViewportSize({width: 390, height: 844});
  equal(await editor.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Phone layout scrolls horizontally');
  equal(await editor.evaluate(() => [...document.querySelectorAll('.music-sites-main,.music-sites-wrap,.music-sites-panel,.music-sites-catalog')]
    .filter(node => /auto|scroll/.test(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight + 1).length), 0, 'Music workflow has nested scrolling containers');
  equal(await editor.locator('#musicProviderSaveSelection').evaluate(node => node.getBoundingClientRect().height >= 40), true, 'Phone save control has an undersized target');
  await editor.locator('#musicProviderSaveSelection').focus();
  await editor.keyboard.press('Tab');
  check(await editor.evaluate(() => document.activeElement !== document.body), 'Keyboard focus was lost in page controls');
  await editor.screenshot({path: path.join(artifactDirectory, 'sites-mobile-selection.png'), fullPage: true});
  await editor.locator('.music-sites-advanced summary').click();
  await editor.locator('#musicProviderRotate').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('Connection updated'));
  const nextCredential = await editor.locator('#musicProviderCredential').inputValue();
  check(nextCredential !== firstCredential && /^vmp1_[a-f0-9]{64}$/.test(nextCredential), 'Reconnect did not issue a distinct backend credential');
  check(await editor.locator('#musicProviderVerification').isVisible(), 'Reconnect did not require fresh site verification');
  equal(fixture.readDb('SELECT COUNT(*) AS count FROM music_provider_selections_v1 WHERE site_id=?', [ownerSiteId])[0].count, 1, 'Reconnect lost selected recording references');
  await editor.locator('#musicProviderHideCredential').click();
  await editor.locator('#musicProviderRevoke').click();
  await editor.waitForFunction(() => document.getElementById('musicProviderStatus').textContent.includes('Site access revoked'));
  equal(fixture.readDb('SELECT status FROM music_provider_sites_v1 WHERE site_id=?', [ownerSiteId]), [{status: 'revoked'}], 'Owner revoke control did not stop site access');
  equal(editorErrors, [], 'Sites & API controller produced uncaught browser errors');
  const producer = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, extraHTTPHeaders: {'X-User': '13'}});
  const readOnly = await producer.newPage();
  await readOnly.goto(providerOrigin + '/music-sites.php?workspace=1');
  equal(await readOnly.locator('#musicProviderCreate').count(), 0, 'Producer received site-creation controls');
  check((await readOnly.getByRole('status').textContent()).includes('owner or manager'), 'Read-only workspace role has no explanation');
  const foreignPage = await editor.goto(providerOrigin + '/music-sites.php?workspace=2');
  equal(foreignPage.status(), 403, 'Music site page exposed a foreign workspace');
  await producer.close();
  await owner.close();
  console.log(`MUSIC_PROVIDER_BROWSER_V1=PASS ${checks} actual owner UI desktop/390px, lost-response retry, revision/CSRF/role controls, HTTPS cross-origin PHP/CORS/audio, cookie-free ranges, renewal, receipts and revocation checks`);
} finally {
  await browser?.close();
  if (proxy) {
    proxy.closeAllConnections?.();
    await new Promise(resolve => proxy.close(resolve));
  }
  fixture.close();
}
