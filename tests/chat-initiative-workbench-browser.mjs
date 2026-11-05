import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const root = 'https://workbench.test';
const endpoint = '/api/initiative-workbench-v1.php';
const mediaEndpoint = '/api/chat-canvas-media-v1.php';
const owner = '12';
const agent = 7;
const conversation = 42;
const capture = '45ab3187-9db6-44fc-879c-816e58f63e27';
const otherCapture = '45ab3187-9db6-44fc-879c-816e58f63e28';
const screenSession = '45ab3187-9db6-44fc-879c-816e58f63e29';
const mission = '7971c1d8-612f-4ffa-baca-67824d43d841';
const image = '6a5f910a-f994-4b37-a671-099c5954acde';
const capturedAt = '2026-11-01T12:00:00Z';
const asset = {
  id: 92, title: 'Saved capture redraw', media_type: 'photo', mime_type: 'image/png',
  available: true, source: 'openai_image', url: '/media-library-file-v86.php?id=92'
};
const productTitle = 'Glazed serving bowl';
const unsafeTitle = '<img src=x onerror=alert(1)> Glazed bowl photo';
const sources = {
  product: [{ source_id: '31', source_title: unsafeTitle }, { source_id: '32', source_title: 'Other product photo' }],
  screen: [
    { source_id: capture, source_title: 'Saved screen capture', captured_at: capturedAt },
    { source_id: otherCapture, source_title: 'Saved screen capture', captured_at: '2026-11-01T13:00:00Z' }
  ],
  mission: [{ source_id: mission, source_title: 'Service capacity Mission' }]
};
const productDisclosure = 'Creating the draft sends the selected photo to your configured Cloud AI provider for analysis.';
const reviewDetails = {
  product: {
    effect: 'Prepare a private Commerce draft with a suggested title and description.',
    photo_use: productDisclosure,
    owner_review: 'Review the photo and wording, then set the price, fulfillment and payment provider separately.',
    image_url: '/media-library-file-v86.php?id=31'
  },
  mission: { coverage: { captured_sources: 2, read_sources: 1 } }
};
const review = (body, source, screenshot) => ({
  source_id: source.source_id,
  source_title: source.source_title,
  revision: 'a'.repeat(64),
  details: body.tool === 'screen' ? {
    mode: body.mode, captured_at: source.captured_at, provider: 'OpenAI', sends_capture: true, image_data: screenshot,
    summary: body.mode === 'cleanup' ? 'AI cleanup aims to keep the original layout and text. It can change details.'
      : 'AI redraw creates a new visual interpretation of the selected capture.'
  } : reviewDetails[body.tool]
});
const generation = status => ({
  id: image, title: asset.title, prompt: 'Redraw selected capture', model: 'gpt-image-1',
  size: '1536x1024', quality: 'medium', background: 'opaque', status, message: '',
  provider_started: status !== 'queued', asset: status === 'completed' ? asset : null
});
const outputs = {
  product: {
    kind: 'product_listing_draft', product_id: 105, title: productTitle, description: 'A glazed bowl with a wide rim.',
    source_id: '31', source_title: unsafeTitle, review_url: '/profile-commerce-products.php?workspace_product=105',
    missing_fields: ['price', 'fulfillment', 'payment_provider']
  },
  screen: { generation: generation('queued'), canvas_media: { asset_ids: [], generation_ids: [image] } },
  mission: {
    type: 'mission_decision_brief', source_id: mission, source_title: 'Service capacity Mission',
    source_revision: 'a'.repeat(64), title: 'Service capacity brief',
    findings: [{ text: 'Capacity was available on Tuesday.', source_id: 'S1', quote: 'Capacity was available on Tuesday.' }],
    recommendation: 'Review the Tuesday window.', uncertainty: 'The excerpt covers one location.',
    next_step: 'Confirm capacity with the operator.',
    sources: [{ id: 'S1', title: 'Service capacity schedule', url: 'https://evidence.test/schedule', collected_at: '2026-11-01T12:00:00Z' }],
    coverage: { captured_sources: 1, read_sources: 1 }
  }
};
const workbenchScript = fileURLToPath(new URL('../chat-initiative-workbench-v1.js', import.meta.url));
const workbenchStyle = fileURLToPath(new URL('../chat-initiative-workbench-v1.css', import.meta.url));
const script = name => fileURLToPath(new URL(`../${name}.js`, import.meta.url));
const token = body => `fixture-${body.tool}-${body.source_id}-${body.mode || ''}-${body.instruction || ''}`;
const html = `<!doctype html><html><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
  <main><section id="chatThread" data-view="chat"><p>Earlier ordinary Agent Chat</p></section>
  <div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput" aria-label="Chat message"></textarea>
  <button id="sendChatButton">Send</button></form></div></main></body></html>`;

async function fixture(page) {
  page.setDefaultTimeout(8000);
  const calls = [];
  const mediaCalls = [];
  const errors = [];
  let nextCreate;
  let imageStatus = 'queued';
  let screenshots;
  page.on('pageerror', error => errors.push(error.message));
  await page.route(`${root}/`, route => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route(`${root}/media-library-file-v86.php?**`, route => route.fulfill({
    contentType: 'image/png',
    body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
  }));
  await page.route(`${root}${mediaEndpoint}`, route => {
    const body = route.request().postDataJSON();
    mediaCalls.push(body);
    if (body.action === 'assets') return route.fulfill({ json: { ok: true, assets: body.asset_ids.includes(31)
      ? [{ ...asset, id: 31, title: unsafeTitle, url: '/media-library-file-v86.php?id=31' }] : [] } });
    if (body.generation_id !== image) return route.fulfill({ status: 404, json: { ok: false } });
    if (body.action === 'tick') imageStatus = 'completed';
    return route.fulfill({ json: { ok: true, generation: generation(imageStatus) } });
  });
  await page.route(`${root}${endpoint}`, async route => {
    const body = route.request().postDataJSON();
    calls.push(body);
    const envelope = {
      ok: true, owner_key: owner, agent_id: body.agent_id, conversation_id: body.conversation_id, tool: body.tool
    };
    const ok = data => route.fulfill({ json: { ...envelope, ...data } });
    const fail = (status, error) => route.fulfill({ status, json: { ...envelope, ok: false, error } });
    if (body.action === 'sources') return ok({ sources: sources[body.tool] || [] });
    const selected = sources[body.tool]?.find(source => source.source_id === body.source_id);
    if (!selected) return fail(404, 'That private source is unavailable.');
    if (body.action === 'preview') return ok({ preview: review(body, selected, screenshots[body.source_id]), review_token: token(body) });
    if (body.action !== 'create' || body.confirmed !== true || body.review_token !== token(body)) {
      return fail(409, 'Review the current source again.');
    }
    const handleCreate = nextCreate;
    nextCreate = undefined;
    if (handleCreate) return handleCreate({ route, body, ok, fail });
    return ok({ output: outputs[body.tool] });
  });
  await page.goto(`${root}/`);
  screenshots = await page.evaluate(({ endpoint, mediaEndpoint, owner, agent, conversation, capture, otherCapture }) => {
    window.fixtureChatId = conversation;
    window.STONEFELLOW_CHAT_CONTINUITY = { conversationId: () => window.fixtureChatId };
    window.VP3_CANVAS_MEDIA_V1 = { endpoint: mediaEndpoint, csrf: 'fixture', userId: Number(owner), agentId: agent };
    window.VP3_INITIATIVE_WORKBENCH = { endpoint, csrf: 'fixture', ownerKey: owner, agentId: agent };
    const picture = color => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 32;
      const paint = canvas.getContext('2d');
      paint.fillStyle = color;
      paint.fillRect(0, 0, canvas.width, canvas.height);
      return canvas.toDataURL('image/jpeg');
    };
    return { [capture]: picture('#f59f64'), [otherCapture]: picture('#234c9f') };
  }, { endpoint, mediaEndpoint, owner, agent, conversation, capture, otherCapture });
  await page.addStyleTag({ path: workbenchStyle });
  await page.addScriptTag({ path: script('chat-tools') });
  await page.addScriptTag({ path: script('chat-canvas-media-v1') });
  await page.addScriptTag({ path: workbenchScript });

  const dialog = page.locator('#vp3InitiativeWorkbench');
  const button = name => dialog.getByRole('button', { name, exact: true });
  const control = name => dialog.locator(`[data-workbench-${name}]`);
  const waitForAction = (action, tool) => page.waitForRequest(request => {
    if (new URL(request.url()).pathname !== endpoint) return false;
    const body = request.postDataJSON();
    return body.action === action && body.tool === tool;
  });
  const flush = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  return { calls, mediaCalls, errors, dialog, button, control, waitForAction, flush, screenshots,
    onCreate: handler => { nextCreate = handler; } };
}

let cases = 0;
function pass(label) { cases++; console.log(`PASS ${label}`); }

try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const desktop = await fixture(page);
  const { dialog, button, control, calls, flush } = desktop;
  await flush();
  assert.deepEqual(calls, [], 'loading ordinary Chat does not run or collect workbench activity');
  assert.equal(await dialog.isVisible(), false);
  await page.getByRole('button', { name: 'Chat tools', exact: true }).click();
  await page.locator('#chatToolsContent').getByRole('button', { name: 'Initiative workbench', exact: true }).click();
  await dialog.waitFor();
  pass('Workbench opens through Chat tools and stays closed without a user action');

  await button('Close').click();
  await page.evaluate(() => {
    const host = document.createElement('article');
    host.className = 'vp3-canvas-media';
    document.getElementById('chatThread').appendChild(host);
    window.VP3CanvasMedia.mount(host, { asset_ids: [31], generation_ids: [] });
  });
  await page.locator('#chatThread').getByRole('button', { name: 'Draft listing', exact: true }).click();
  await control('source').locator('option[value="31"]').waitFor({ state: 'attached' });
  assert.equal(await control('source').inputValue(), '31');
  assert.equal(calls.some(call => call.action === 'create'), false);
  await button('Review source').click();
  await button('Create private draft').waitFor();
  await control('review').getByText(productDisclosure, { exact: true }).waitFor();
  assert.equal(await control('review').locator('img[src*="media-library-file-v86.php?id=31"]').count(), 1);
  assert.equal(await dialog.locator('img[src="x"]').count(), 0);
  assert.ok(calls.some(call => call.action === 'preview' && call.source_id === '31'));
  assert.equal(calls.some(call => call.action === 'create'), false);
  await button('Create private draft').click();
  await control('result').getByText(productTitle, { exact: true }).waitFor();
  const productCreate = calls.find(call => call.action === 'create' && call.tool === 'product');
  assert.equal(productCreate.confirmed, true);
  assert.equal(productCreate.review_token, token(productCreate));
  assert.equal(productCreate.source_id, '31');
  assert.equal(productCreate.owner_key, owner);
  assert.equal(productCreate.agent_id, agent);
  assert.equal(productCreate.conversation_id, conversation);
  assert.equal(await control('result').locator('a[href*="workspace_product=105"]').count(), 1);
  pass('An exact reviewed photo creates a private listing draft with the native Commerce review link');

  await control('tool').selectOption('screen');
  await control('source').locator(`option[value="${capture}"]`).waitFor({ state: 'attached' });
  const firstLabel = await control('source').locator(`option[value="${capture}"]`).textContent();
  const secondLabel = await control('source').locator(`option[value="${otherCapture}"]`).textContent();
  assert.notEqual(firstLabel, secondLabel);
  await control('source').selectOption(capture);
  await control('mode').selectOption('cleanup');
  await button('Review source').click();
  await button('Create edited image').waitFor();
  await control('review').getByText(/AI cleanup aims to keep the original layout and text/).waitFor();
  await control('review').locator('img[src^="data:image/jpeg;base64,"]').waitFor();
  assert.equal(await control('review').locator('img').evaluate(image => image.decode().then(() => image.naturalWidth)), 32);
  await control('mode').selectOption('redraw');
  await control('instruction').fill('Use a clean diagram style.');
  assert.ok(!(await button('Create edited image').isVisible()) || await button('Create edited image').isDisabled());
  assert.equal(calls.filter(call => call.action === 'create' && call.tool === 'screen').length, 0);
  await button('Review source').click();
  await button('Create edited image').click();
  await control('result').locator('.vp3-canvas-media-card[data-state="completed"] img').waitFor();
  const screenCreate = calls.find(call => call.action === 'create' && call.tool === 'screen');
  assert.equal(screenCreate.mode, 'redraw');
  assert.equal(screenCreate.instruction, 'Use a clean diagram style.');
  assert.equal(screenCreate.source_id, capture);
  assert.equal(desktop.mediaCalls.filter(call => call.action === 'tick').length, 1);
  assert.ok(desktop.mediaCalls.filter(call => call.action !== 'assets').every(call => call.conversation_id === conversation && call.generation_id === image));
  pass('Changing screen edit options invalidates review and the approved edit uses the canonical image card');

  await control('tool').selectOption('mission');
  await control('source').locator(`option[value="${mission}"]`).waitFor({ state: 'attached' });
  await control('source').selectOption(mission);
  await button('Review source').click();
  await control('review').getByText(/Use 1 readable excerpts from 2 collected sources/).waitFor();
  await button('Create private brief').click();
  await control('result').getByText('Capacity was available on Tuesday.', { exact: true }).waitFor();
  assert.equal(await control('result').locator('a[href="https://evidence.test/schedule"]').count(), 1);
  assert.equal(await control('result').getByText('Confirm capacity with the operator.', { exact: true }).count(), 1);
  assert.equal(calls.filter(call => call.action === 'create' && call.tool === 'mission').length, 1);
  pass('The private Mission brief includes the saved evidence link and a proposed next step');

  await control('tool').selectOption('product');
  await control('source').locator('option[value="31"]').waitFor({ state: 'attached' });
  await control('source').selectOption('31');
  await button('Review source').click();
  desktop.onCreate(({ fail }) => fail(409, 'The source changed. Review it again.'));
  await button('Create private draft').click();
  await dialog.getByText('The source changed. Review it again.', { exact: true }).waitFor();
  assert.equal(await control('result').getByText(productTitle, { exact: true }).count(), 0);
  assert.ok(!(await button('Create private draft').isVisible()) || await button('Create private draft').isDisabled());
  pass('A stale review requires a new preview before any result can appear');

  await button('Review source').click();
  desktop.onCreate(({ ok }) => ok({ owner_key: '99', output: { ...outputs.product, title: 'Foreign account draft' } }));
  const foreign = page.waitForResponse(response => response.request().postDataJSON()?.action === 'create');
  await button('Create private draft').click();
  await (await foreign).finished();
  await flush();
  assert.equal(await page.getByText('Foreign account draft', { exact: true }).count(), 0);
  pass('A response carrying another account identity never renders private draft data');

  await page.evaluate(() => window.VP3InitiativeWorkbench.open('product', '31'));
  await dialog.waitFor();
  await control('source').locator('option[value="31"]').waitFor({ state: 'attached' });
  await control('source').selectOption('31');
  await button('Review source').click();
  let unblock;
  const gate = new Promise(resolve => { unblock = resolve; });
  let finish;
  const settled = new Promise(resolve => { finish = resolve; });
  desktop.onCreate(async ({ ok }) => {
    await gate;
    try { await ok({ output: { ...outputs.product, title: 'Late result from prior Chat' } }); }
    finally { finish(); }
  });
  const held = desktop.waitForAction('create', 'product');
  await button('Create private draft').click();
  await held;
  try {
    await page.evaluate(() => {
      window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
      window.fixtureChatId = 99;
    });
  } finally { unblock(); }
  await settled;
  await flush();
  assert.equal(await page.getByText('Late result from prior Chat', { exact: true }).count(), 0);
  pass('A create response that arrives after a conversation switch stays isolated to its original Chat');

  const phone = await browser.newPage({ viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true });
  const mobile = await fixture(phone);
  await phone.evaluate(({ owner, capture, otherCapture, screenSession, capturedAt }) => {
    const eyes = document.createElement('div');
    eyes.className = 'vp3-eyes-content';
    document.body.appendChild(eyes);
    const session = { session_id: screenSession, title: 'Same capture title', created_at: capturedAt,
      status: 'ended', knowledge_url: '/knowledge.php?folder=12', folder_name: 'Saved screen share' };
    const row = id => ({ capture_id: id, session_id: screenSession, kind: 'screen', captured_at: capturedAt, body: {} });
    window.VP3_ORB_ADAPTER = {
      ownerKey: owner, conversationRef: () => ({ id: window.fixtureChatId, agent: 7 }),
      screenRequest: async () => { throw new Error('No live screen capture was requested.'); },
      screenHistory: async input => {
        if (input.action === 'list') return { sessions: [session] };
        if (input.action === 'timeline') return { session, captures: [], next_before: null };
        if (input.action === 'history') return { session, captures: [row(capture), row(otherCapture)] };
        throw new Error('Unexpected screen history action');
      }
    };
  }, { owner, capture, otherCapture, screenSession, capturedAt });
  await phone.addStyleTag({ path: fileURLToPath(new URL('../agent-orb.css', import.meta.url)) });
  await phone.addScriptTag({ path: script('agent-screen-share') });
  await phone.getByText('Sharing sessions and capture history', { exact: true }).click();
  await phone.getByRole('combobox', { name: 'Sharing session' }).selectOption(screenSession);
  await phone.locator(`[data-capture-id="${otherCapture}"]`).getByRole('button', { name: 'Edit saved image', exact: true }).click();
  await mobile.control('source').locator(`option[value="${otherCapture}"]`).waitFor({ state: 'attached' });
  assert.equal(await mobile.control('source').inputValue(), otherCapture);
  await mobile.button('Review source').click();
  await mobile.control('review').locator('img[src^="data:image/jpeg;base64,"]').waitFor();
  assert.equal(mobile.calls.find(call => call.action === 'preview').source_id, otherCapture);
  assert.equal(await mobile.control('review').locator('img').getAttribute('src'), mobile.screenshots[otherCapture]);
  assert.equal(mobile.calls.some(call => call.action === 'create'), false);
  await mobile.button('Close').click();
  pass('The native Agent Eyes history action reviews the exact selected saved frame on a phone');

  await phone.evaluate(source => window.VP3InitiativeWorkbench.open('mission', source), mission);
  await mobile.dialog.waitFor();
  await mobile.control('source').locator(`option[value="${mission}"]`).waitFor({ state: 'attached' });
  assert.equal(await mobile.control('source').inputValue(), mission);
  await mobile.button('Review source').click();
  await mobile.button('Create private brief').waitFor();
  const box = await mobile.dialog.boundingBox();
  assert.ok(box.x >= 0 && box.x + box.width <= 361 && box.y >= 0 && box.y + box.height <= 741);
  assert.equal(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  pass('A native source action selects the exact Mission on a phone without a Chat rail or horizontal overflow');

  const interrupted = mobile.waitForAction('create', 'mission');
  const failed = phone.waitForEvent('requestfailed', request => new URL(request.url()).pathname === endpoint);
  mobile.onCreate(({ route }) => route.abort('internetdisconnected'));
  await mobile.button('Create private brief').click();
  await Promise.all([interrupted, failed]);
  await mobile.dialog.getByRole('status').getByText(/Connection interrupted/).waitFor();
  await mobile.flush();
  assert.equal(await phone.getByText('Service capacity brief', { exact: true }).count(), 0);
  assert.equal(mobile.calls.filter(call => call.action === 'create' && call.tool === 'mission').length, 1);
  assert.deepEqual(desktop.errors, []);
  assert.deepEqual(mobile.errors, []);
  pass('An interrupted create displays no fabricated brief or automatic replay');
  console.log(`CHAT_INITIATIVE_WORKBENCH_BROWSER=PASS (${cases} cases; real renderer and canonical image card, controlled API)`);
} finally {
  await browser.close();
}
