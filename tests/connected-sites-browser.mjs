import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const browser = await chromium.launch({
  headless: true,
  ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH && {
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
  }),
});
const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
const calls = [];
const name = 'Kitchen Fox';
const origin = 'https://restaurant.example';
const id = 'f'.repeat(32);
let available = false;
let connected = false;
let remoteRevoke = true;
let duplicate = false;

await page.route('https://vp3.test/', route => route.fulfill({
  contentType: 'text/html',
  body: `<main id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></main>
    <script>window.VP3_CONNECTED_GELATO_V1 = {endpoint:'/api/connected-gelato-v1.php',csrf:'test-csrf'};</script>`,
}));
await page.route('https://vp3.test/api/connected-gelato-v1.php', async route => {
  if (route.request().method() === 'GET') {
    const site = {
      id, origin, label: 'Owner display label', agentName: name,
      scopes: ['sales.daily.read', 'tickets.open.read', 'orders.read'],
      locations: [{ id: 7, name: '<Main Kitchen>', timezone: 'America/Phoenix' }],
    };
    const connections = connected ? [site] : [];
    if (duplicate) connections.push({ ...site, id: 'd'.repeat(32), origin: 'https://other.example' });
    await route.fulfill({ json: { ok: true, available, connections } });
    return;
  }
  const body = route.request().postDataJSON();
  calls.push(body);
  if (body.action === 'connect') {
    connected = true;
    await route.fulfill({ json: { ok: true } });
  } else {
    connected = false;
    await route.fulfill({ json: { ok: true, revokedRemotely: remoteRevoke } });
  }
});

try {
  await page.goto('https://vp3.test/');
  for (const path of ['../chat-tools.js', '../chat-connected-sites-v1.js']) {
    await page.addScriptTag({ path: fileURLToPath(new URL(path, import.meta.url)) });
  }
  await page.getByRole('button', { name: 'Chat tools' }).click();
  await page.getByText('Connected Sites', { exact: true }).click();
  await page.getByText('Connected Sites requires the VP3 Cloud database upgrade.').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Connect Food Monster' }).isDisabled(), true);
  available = true;
  await page.getByRole('button', { name: 'Refresh connections' }).click();
  const connect = async () => {
    await page.getByLabel('Food Monster site HTTPS address').fill(origin);
    await page.getByLabel('One-time pairing code').fill('fixture-pairing-code');
    await page.getByRole('button', { name: 'Connect Food Monster' }).click();
    await page.getByText('Site connected.', { exact: true }).waitFor();
  };
  await connect();
  assert.deepEqual(calls[0], { action: 'connect', origin, code: 'fixture-pairing-code', csrf_token: 'test-csrf' });
  assert.equal(await page.getByLabel('One-time pairing code').inputValue(), '');
  assert.equal(await page.getByText('Owner display label', { exact: true }).count(), 0);
  assert.match(await page.locator('[data-site-row]').innerText(), /<Main Kitchen>/);
  const input = page.locator('#chatInput');
  await input.fill('Keep this existing draft');
  await page.getByRole('button', { name: 'Ask ' + name }).click();
  assert.equal(await input.inputValue(), 'Keep this existing draft');
  await input.clear();
  await page.getByRole('button', { name: 'Ask ' + name }).click();
  assert.equal(await input.inputValue(), 'Ask Kitchen Fox how we did today and what’s still open');
  assert.equal(await page.getByRole('button', { name: 'Chat tools' }).getAttribute('aria-expanded'), 'false');
  await page.getByRole('button', { name: 'Chat tools' }).click();
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  assert.equal(calls.length, 1);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  await page.getByText('Site disconnected.', { exact: true }).waitFor();
  assert.deepEqual(calls[1], { action: 'disconnect', connectionId: id, csrf_token: 'test-csrf' });
  remoteRevoke = false;
  await connect();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  await page.getByText('Disconnected from VP3. Open VP3 Connections on the site to revoke its grant.').waitFor();
  duplicate = connected = true;
  await page.getByRole('button', { name: 'Refresh connections' }).click();
  await input.clear();
  const secondSite = page.locator('[data-site-row]').filter({ hasText: 'https://other.example' });
  await secondSite.getByRole('button', { name: 'Ask ' + name }).click();
  assert.equal(await input.inputValue(), 'Ask Kitchen Fox via other.example how we did today and what’s still open');
  console.log('Connected Sites browser pairing, named ask and disconnect passed.');
} finally {
  await browser.close();
}
