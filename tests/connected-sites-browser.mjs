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
const knowledgeUrl = 'https://vp3.test/vp3/knowledge.php';
const firstFolder = { id: 21, name, url: 'https://untrusted.example/redirect' };
const secondFolder = { id: 22, name: name + ' (other.example)' };
let available = false;
let connected = false;
let remoteRevoke = true;
let duplicate = false;
let canCreateKnowledgeFolder = true;
let knowledgeFolder = null;

await page.route('https://vp3.test/', route => route.fulfill({
  contentType: 'text/html',
  body: `<main id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></main>
    <script>window.VP3_CONNECTED_GELATO_V1 = {endpoint:'/api/connected-gelato-v1.php',csrf:'test-csrf',knowledgeUrl:'/vp3/knowledge.php'};</script>`,
}));
await page.route(knowledgeUrl + '?folder=22', route => route.fulfill({
  contentType: 'text/html', body: '<h1>Private Knowledge folder 22</h1>',
}));
await page.route('https://vp3.test/api/connected-gelato-v1.php', async route => {
  if (route.request().method() === 'GET') {
    const site = {
      id, origin, label: 'Owner display label', agentName: name,
      scopes: ['sales.daily.read', 'tickets.open.read', 'orders.read'],
      locations: [{ id: 7, name: '<Main Kitchen>', timezone: 'America/Phoenix' }],
      knowledgeFolder,
    };
    const connections = connected ? [site] : [];
    if (duplicate) connections.push({ ...site, id: 'd'.repeat(32), origin: 'https://other.example', knowledgeFolder: secondFolder });
    await route.fulfill({ json: { ok: true, available, connections, canCreateKnowledgeFolder } });
    return;
  }
  const body = route.request().postDataJSON();
  calls.push(body);
  if (body.action === 'connect') {
    connected = true;
    if (canCreateKnowledgeFolder) knowledgeFolder ??= firstFolder;
    await route.fulfill({ json: { ok: true } });
  } else if (body.action === 'createKnowledgeFolder') {
    assert.equal(canCreateKnowledgeFolder, true);
    knowledgeFolder = firstFolder;
    await route.fulfill({ json: { ok: true, knowledgeFolder } });
  } else if (body.action === 'disconnect') {
    connected = false;
    await route.fulfill({ json: { ok: true, revokedRemotely: remoteRevoke } });
  } else {
    assert.fail('Unexpected connection action ' + body.action);
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
  assert.equal(await page.getByRole('button', { name: 'Connect Gelato' }).isDisabled(), true);
  available = true;
  const refreshButton = page.getByRole('button', { name: 'Refresh connections' });
  const refresh = async () => {
    await refreshButton.click();
    await refreshButton.and(page.locator(':enabled')).waitFor();
  };
  await refresh();
  const connect = async () => {
    await page.getByLabel('Gelato site HTTPS address').fill(origin);
    await page.getByLabel('One-time pairing code').fill('fixture-pairing-code');
    await page.getByRole('button', { name: 'Connect Gelato' }).click();
    await page.getByText('Site connected.', { exact: true }).waitFor();
  };
  await connect();
  assert.deepEqual(calls[0], { action: 'connect', origin, code: 'fixture-pairing-code', csrf_token: 'test-csrf' });
  assert.equal(await page.getByLabel('One-time pairing code').inputValue(), '');
  assert.equal(await page.getByText('Owner display label', { exact: true }).count(), 0);
  assert.match(await page.locator('[data-site-row]').innerText(), /<Main Kitchen>/);
  assert.equal(await page.getByText('In Agent Chat, say “Ask Gelato” or type “/ask gelato” followed by your question.').count(), 1);
  const primaryFolderLink = page.getByRole('link', { name: 'Open Knowledge folder for ' + name, exact: true });
  assert.equal(await primaryFolderLink.getAttribute('href'), knowledgeUrl + '?folder=21');
  assert.equal(await page.getByRole('button', { name: 'Create Knowledge folder' }).count(), 0);
  const input = page.locator('#chatInput');
  await input.fill('Keep this existing draft');
  await page.getByRole('button', { name: 'Ask Gelato', exact: true }).click();
  assert.equal(await input.inputValue(), 'Keep this existing draft');
  await input.clear();
  await page.getByRole('button', { name: 'Ask Gelato', exact: true }).click();
  assert.equal(await input.inputValue(), 'Ask Gelato how we did today and what’s still open');
  assert.equal(await page.getByRole('button', { name: 'Chat tools' }).getAttribute('aria-expanded'), 'false');
  await page.getByRole('button', { name: 'Chat tools' }).click();
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  assert.equal(calls.length, 1);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  await page.getByText('Site disconnected.', { exact: true }).waitFor();
  assert.deepEqual(calls[1], { action: 'disconnect', connectionId: id, csrf_token: 'test-csrf' });
  assert.equal(await primaryFolderLink.count(), 0);
  remoteRevoke = false;
  await connect();
  assert.equal(await primaryFolderLink.getAttribute('href'), knowledgeUrl + '?folder=21');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Disconnect' }).click();
  await page.getByText('Disconnected from VP3. Open VP3 Connections on the site to revoke its grant.').waitFor();
  duplicate = connected = true;
  knowledgeFolder = null;
  canCreateKnowledgeFolder = false;
  await refresh();
  assert.equal(await primaryFolderLink.count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Create Knowledge folder' }).count(), 0);
  await input.clear();
  const secondSite = page.locator('[data-site-row]').filter({ hasText: 'https://other.example' });
  await secondSite.getByRole('button', { name: 'Ask Gelato', exact: true }).click();
  assert.equal(await input.inputValue(), 'Ask Gelato via other.example how we did today and what’s still open');
  await page.getByRole('button', { name: 'Chat tools' }).click();
  canCreateKnowledgeFolder = true;
  await refresh();
  const createFolder = page.getByRole('button', { name: 'Create Knowledge folder' });
  assert.equal(await createFolder.count(), 1);
  await createFolder.click();
  await page.getByText('Knowledge folder ready.', { exact: true }).waitFor();
  assert.deepEqual(calls.at(-1), { action: 'createKnowledgeFolder', connectionId: id, csrf_token: 'test-csrf' });
  assert.equal(await primaryFolderLink.getAttribute('href'), knowledgeUrl + '?folder=21');
  const secondFolderLink = secondSite.getByRole('link', { name: 'Open Knowledge folder for ' + secondFolder.name });
  assert.equal(await secondFolderLink.getAttribute('href'), knowledgeUrl + '?folder=22');
  await secondFolderLink.click();
  await page.getByRole('heading', { name: 'Private Knowledge folder 22' }).waitFor();
  console.log('Connected Sites browser pairing, named ask, disconnect and Knowledge folders passed.');
} finally {
  await browser.close();
}
