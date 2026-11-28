import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';

const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const root = 'https://workbench.test';
const endpoint = '/api/initiative-workbench-v1.php';
const mediaEndpoint = '/api/chat-canvas-media-v1.php';
const uploadEndpoint = '/api/media-library-v86.php';
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
const draftId = 'b'.repeat(64);
const productUrl = '/dave/product/glazed-serving-bowl';
const originalUrl = '/media-library-file-v86.php?id=31';
const graphicUrl = '/media-library-file-v86.php?id=93';
const unsafeTitle = '<img src=x onerror=alert(1)> Glazed bowl photo';
const oldGeneratedId = 7;
const oldUploadedId = 8;
const libraryImages = [
  ...Array.from({length:60}, (_, index) => ({id:160-index,title:`Saved product photo ${160-index}`,source:'upload'})),
  {id:32,title:'Other product photo',source:'upload'},
  {id:31,title:unsafeTitle,source:'upload'},
  {id:oldUploadedId,title:'Original studio photograph',source:'upload'},
  {id:oldGeneratedId,title:'Generated bowl in a garden',source:'openai_image'}
].map(item => ({...item,media_type:'photo',mime_type:'image/png',file_size:4096,available:true,
  created_at:'2026-10-01T12:00:00Z',url:`/media-library-file-v86.php?id=${item.id}`,collection_ids:[2]}));
const sources = {
  product: [{ source_id: 'description', source_title: 'Describe a product' }, { source_id: '31', source_title: unsafeTitle }, { source_id: '32', source_title: 'Other product photo' }],
  marketing: [{ source_id: '31', source_title: unsafeTitle }],
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
  marketing: { image_url:originalUrl, summary:'Product: Glazed serving bowl', linked_product_image:true,
    drafts:[{draft_id:draftId,product_id:105,asset_id:31,title:'A bowl for the table'}] },
  mission: { coverage: { captured_sources: 2, read_sources: 1 } }
};
const review = (body, source, screenshot) => ({
  source_id: source.source_id,
  source_title: source.source_title,
  revision: body.tool === 'screen' && (body.mode !== 'cleanup' || body.instruction) ? 'c'.repeat(64) : 'a'.repeat(64),
  ...(body.tool === 'screen' ? {reference_revision:'a'.repeat(64)} : {}),
  details: body.tool === 'screen' ? {
    mode: body.mode, captured_at: source.captured_at, provider: 'OpenAI', sends_capture: true, image_data: screenshot,
    summary: body.mode === 'cleanup' ? 'AI cleanup aims to keep the original layout and text. It can change details.'
      : 'AI redraw creates a new visual interpretation of the selected capture.'
  } : body.source_id === 'description' ? {summary: body.product_description}
    : ['product','marketing'].includes(body.tool) ? {...reviewDetails[body.tool],image_url:`/media-library-file-v86.php?id=${body.source_id}`}
      : reviewDetails[body.tool]
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
  marketing: {kind:'product_marketing_draft', draft_id:draftId, product_id:105, asset_id:31, title:'A bowl for the table',
    description:'Glazed serving bowl with a wide rim.', campaign_idea:'Product spotlight.',
    image_url:'/media-library-file-v86.php?id=31', review_url:'/profile-commerce-products.php?workspace_product=105#product-marketing-drafts'},
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
const token = body => `fixture-${body.tool}-${body.source_id}-${body.mode || ''}-${body.instruction || ''}-${body.product_description || ''}-${body.product_id || ''}`;
const html = `<!doctype html><html><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
  <main><section id="chatThread" data-view="chat"><p>Earlier ordinary Agent Chat</p></section>
  <div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput" aria-label="Chat message"></textarea>
  <button id="sendChatButton">Send</button></form></div></main></body></html>`;

async function fixture(page, {chatConfig = true, query = '', activeAgent = agent, ready = true,
  marketingDrafts = reviewDetails.marketing.drafts, linkedProductImage = true, canvasAvailable = true,
  pickerAvailable = true, largeLibrary = false, products = [{id:105,title:productTitle},{id:106,title:'Matching side plate'}]} = {}) {
  page.setDefaultTimeout(8000);
  const calls = [];
  const mediaCalls = [];
  const uploads = [];
  const browseCalls = [];
  const designs = new Map();
  const privateDrafts = new Map([[draftId, outputs.marketing]]);
  const errors = [];
  let nextCreate;
  let nextPreview;
  let nextCreativeOpen;
  let imageStatus = 'queued';
  let promotion;
  let originalPng;
  let uploadedPng;
  const parts = [];
  let screenshots;
  page.on('pageerror', error => errors.push(error.message));
  await page.route(url => url.origin === root && url.pathname === '/', route => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route(`${root}/media-library-file-v86.php?**`, route => route.fulfill({
    contentType: 'image/png',
    body: (route.request().url().endsWith('id=93') ? uploadedPng : originalPng)
      || Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
  }));
  await page.route(url => url.origin === root && url.pathname === uploadEndpoint, async route => {
    const request = route.request();
    if (request.method() === 'GET') {
      const query = new URL(request.url()).searchParams;
      assert.equal(request.headers()['x-vp3-media-owner'],owner);
      assert.equal(query.get('action'),'browse');
      assert.equal(query.get('type'),'photo');
      browseCalls.push(Object.fromEntries(query));
      const before = Number(query.get('before')) || Infinity;
      const q = (query.get('q') || '').toLowerCase();
      const source = query.get('source') || '';
      const images = libraryImages.filter(item => item.id < before && item.title.toLowerCase().includes(q)
        && (source !== 'generated' || item.source === 'openai_image') && (source !== 'uploaded' || item.source === 'upload'));
      return route.fulfill({json:{ok:true,assets:images.slice(0,24),next_before:images.length > 24 ? images[23].id : null,
        collections_available:true,collections:[{id:2,name:'Product photography',asset_count:libraryImages.length}]}});
    }
    const payload = await new Response(request.postDataBuffer(), {headers:{'content-type':request.headers()['content-type']}}).formData();
    assert.equal(request.headers()['x-vp3-media-owner'], owner);
    assert.equal(payload.get('csrf_token'), 'fixture');
    const action = payload.get('action');
    uploads.push(action);
    let output;
    if (action === 'begin') {
      assert.equal(payload.get('mime_type'), 'image/png');
      assert.equal(payload.get('source'), 'promotion_design');
      output = {token:'fixture-media-upload',chunk_size:1048576};
    } else if (action === 'chunk') {
      parts.push(Buffer.from(await payload.get('chunk').arrayBuffer()));
      output = {};
    } else if (action === 'finish') {
      const metadata = JSON.parse(payload.get('metadata_json'));
      assert.equal(designs.get(metadata.creative_id)?.status, 'ready');
      uploadedPng = Buffer.concat(parts);
      assert.equal(uploadedPng.subarray(0,8).toString('hex'),'89504e470d0a1a0a');
      assert.equal(uploadedPng.readUInt32BE(16),1080);
      output = {asset:{id:93}};
    }
    return route.fulfill({json:{ok:true,...output}});
  });
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
    if (body.action === 'creative_open') {
      const draft = privateDrafts.get(body.draft_id);
      assert.ok(draft);
      assert.equal(body.product_id,draft.product_id);
      assert.equal(body.source_id,String(draft.asset_id));
      const variants = [...designs.values()].reverse().filter(item => item.draft_id === body.draft_id);
      const creative = nextCreativeOpen ? nextCreativeOpen(variants) : body.creative_id ? designs.get(body.creative_id) : variants[0];
      nextCreativeOpen = undefined;
      return ok({draft:{...draft,product_url:productUrl,cta:'Shop the bowl'},creative:creative || null,variants,promotion,
        campaigns:[{id:17,name:'Summer table',merchant_name:'Shop'},{id:23,name:'Autumn table',merchant_name:'Shop'}]});
    }
    if (body.action === 'creative_prepare' || body.action === 'creative_generate') {
      const draft = privateDrafts.get(body.draft_id);
      assert.ok(draft);
      assert.equal(body.product_id,draft.product_id);
      assert.equal(body.source_id,String(draft.asset_id));
      assert.match(body.nonce,/^[a-f0-9]{32}$/);
      const {level,headline,copy,prompt,style,remove_background,tolerance} = body;
      const creative = {id:body.nonce,draft_id:body.draft_id,product_id:body.product_id,level,headline,copy,prompt,style,remove_background,tolerance,
        status:body.action==='creative_prepare'?'review':level<5?'ready':'queued',
        source_image_url:draft.image_url,product_url:productUrl,cta:'Shop the bowl'};
      if (body.action === 'creative_prepare') return ok({creative,review_token:'review-'+body.nonce});
      assert.equal(body.review_token,'review-'+body.nonce);
      assert.equal(body.confirmed,true);
      designs.set(creative.id,creative);
      return ok({creative});
    }
    if (body.action.startsWith('creative_')) {
      const creative = designs.get(body.creative_id);
      assert.ok(creative);
      if (body.action === 'creative_tick') {
        creative.status = 'ready';
        creative.generated_url = '/media-library-file-v86.php?id=92';
      } else if (body.action === 'creative_save') {
        assert.equal(body.asset_id,93);
        creative.status = 'saved';
        creative.image_url = graphicUrl;
      } else if (body.action === 'creative_publish_preview') {
        const campaign = body.campaign_id === 17 ? {id:17,name:'Summer table'} : body.campaign_id === 23 ? {id:23,name:'Autumn table'} : null;
        return ok({creative,campaign,review_token:`publish-${creative.id}-${body.campaign_id ?? 0}`});
      } else if (body.action === 'creative_publish') {
        assert.equal(body.confirmed,true);
        assert.equal(body.review_token,`publish-${creative.id}-${body.campaign_id ?? 0}`);
        creative.status = 'published';
        creative.campaign_id = body.campaign_id ?? null;
        creative.public_url = '/dave#product-promotion-fixture';
        promotion = creative;
      } else if (body.action === 'creative_pause') {
        assert.equal(body.confirmed,true);
        creative.status = 'paused';
        creative.public_url = '';
        promotion = null;
      } else {
        assert.equal(body.action,'creative_status');
      }
      return ok({creative});
    }
    if (body.action === 'sources') {
      const available = largeLibrary && ['product','marketing'].includes(body.tool)
        ? [...(body.tool === 'product' ? [sources.product[0]] : []),
          ...libraryImages.slice(0,50).map(item => ({source_id:String(item.id),source_title:item.title}))] : sources[body.tool] || [];
      return ok({sources:available,...(body.tool === 'marketing' ? {products} : {})});
    }
    const librarySource = ['product','marketing'].includes(body.tool) && libraryImages.find(item => String(item.id) === body.source_id);
    const selected = sources[body.tool]?.find(source => source.source_id === body.source_id)
      || (librarySource && {source_id:String(librarySource.id),source_title:librarySource.title});
    if (!selected) return fail(404, 'That private source is unavailable.');
    if (body.action === 'preview') {
      const handlePreview = nextPreview;
      nextPreview = undefined;
      const preview = review(body, selected, screenshots[body.source_id]);
      if (body.tool === 'marketing') preview.details = {...preview.details, drafts:body.source_id === '31' ? marketingDrafts
        : marketingDrafts.filter(item => String(item.asset_id) === body.source_id && item.product_id === body.product_id),
        linked_product_image:linkedProductImage};
      if (handlePreview) return handlePreview({route,body,ok,fail,preview});
      return ok({ preview, review_token: token(body) });
    }
    if (body.action !== 'create' || body.confirmed !== true || body.review_token !== token(body)) {
      return fail(409, 'Review the current source again.');
    }
    const handleCreate = nextCreate;
    nextCreate = undefined;
    if (handleCreate) return handleCreate({ route, body, ok, fail });
    if (body.tool === 'marketing' && (body.source_id !== '31' || body.product_id !== 105)) {
      const draft = {...outputs.marketing,draft_id:body.product_id.toString(16).padStart(8,'0') + Number(body.source_id).toString(16).padStart(56,'0'),
        product_id:body.product_id,asset_id:Number(body.source_id),image_url:`/media-library-file-v86.php?id=${body.source_id}`};
      privateDrafts.set(draft.draft_id,draft);
      return ok({output:draft});
    }
    return ok({ output: outputs[body.tool] });
  });
  await page.goto(`${root}/${query}`);
  screenshots = await page.evaluate(({ endpoint, mediaEndpoint, uploadEndpoint, owner, agent, conversation, capture, otherCapture, chatConfig, ready }) => {
    window.fixtureChatId = conversation;
    window.fixtureChatReady = ready;
    window.STONEFELLOW_CHAT_CONTINUITY = {
      conversationId: () => window.fixtureChatId,
      isReady: () => window.fixtureChatReady,
      showChatView: () => { document.getElementById('chatThread').dataset.view = 'chat'; }
    };
    window.VP3_CANVAS_MEDIA_V1 = { endpoint: mediaEndpoint, csrf: 'fixture', userId: Number(owner), agentId: agent };
    window.VP3_INITIATIVE_WORKBENCH = { endpoint, mediaEndpoint:uploadEndpoint, csrf: 'fixture', ownerKey: owner, agentId: agent };
    if (chatConfig) window.STONEFELLOW_CHAT = {userId:Number(owner),agentId:agent,workingAttentionBinding:'fixture-session'};
    const picture = color => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 32;
      const paint = canvas.getContext('2d');
      paint.fillStyle = color;
      paint.fillRect(0, 0, canvas.width, canvas.height);
      return canvas.toDataURL('image/jpeg');
    };
    const canvas = document.createElement('canvas');
    canvas.width = 96;
    canvas.height = 64;
    const paint = canvas.getContext('2d');
    paint.fillStyle = '#2868a0';
    paint.fillRect(30, 10, 36, 44);
    return { [capture]: picture('#f59f64'), [otherCapture]: picture('#234c9f'), png:canvas.toDataURL('image/png').split(',')[1] };
  }, { endpoint, mediaEndpoint, uploadEndpoint, owner, agent:activeAgent, conversation, capture, otherCapture, chatConfig, ready });
  originalPng = Buffer.from(screenshots.png,'base64');
  await page.addStyleTag({ path: workbenchStyle });
  if (pickerAvailable) await page.addStyleTag({ path:fileURLToPath(new URL('../media-library-picker-v1.css', import.meta.url)) });
  await page.addScriptTag({ path: script('chat-tools') });
  await page.addScriptTag({ path: script('chat-canvas-media-v1') });
  await page.addScriptTag({ path: script('chat-marketing-creative-render-v1') });
  if (pickerAvailable) await page.addScriptTag({ path: script('media-library-picker-v1') });
  if (canvasAvailable) await page.addScriptTag({ path: script('chat-marketing-canvas-v1') });
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
  return { calls, mediaCalls, browseCalls, uploads, errors, dialog, button, control, waitForAction, flush, screenshots,
    onPreview: handler => { nextPreview = handler; },
    onCreate: handler => { nextCreate = handler; },
    onCreativeOpen: handler => { nextCreativeOpen = handler; } };
}

let cases = 0;
function pass(label) { cases++; console.log(`PASS ${label}`); }
async function pickerScreenshot(target, filename) {
  const folder = process.env.VP3_MEDIA_PICKER_SCREENSHOTS;
  if (!folder) return;
  await mkdir(folder,{recursive:true});
  await target.screenshot({path:join(folder,filename)});
}
const picker = host => host.locator('[data-media-picker]');
async function searchImage(host, title, id) {
  const selection = picker(host);
  await selection.locator('[data-media-picker-search]').fill(title);
  await selection.locator(`[data-media-picker-asset="${id}"]`).click();
  assert.equal(await selection.locator('[data-media-picker-preview]').getAttribute('data-media-picker-candidate'),String(id));
  return selection;
}
const canvasSettings = canvas => canvas.evaluate(panel => ({
  headline:panel.querySelector('[data-marketing-headline]').value,
  copy:panel.querySelector('[data-marketing-copy]').value,
  level:panel.querySelector('[data-marketing-level]').value,
  prompt:panel.querySelector('[data-marketing-prompt]').value,
  style:panel.querySelector('fieldset select').value,
  removeBackground:panel.querySelector('fieldset input[type=checkbox]').checked,
  tolerance:panel.querySelector('#vp3MarketingCutoutTolerance').value,
  campaign:panel.querySelectorAll('.vp3-marketing-controls select')[0].value
}));

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

  await control('source').selectOption('description');
  const productDescription = control('product-description');
  await productDescription.fill('A serving bowl with a wide rim.');
  await button('Review source').click();
  await control('review').getByText('A serving bowl with a wide rim.', {exact:true}).waitFor();
  await productDescription.fill('A glazed serving bowl with a wide rim.');
  assert.equal(await button('Create private draft').isVisible(), false);
  await button('Review source').click();
  await button('Create private draft').click();
  await control('result').getByText(productTitle, {exact:true}).waitFor();
  const textCreate = calls.find(call => call.action === 'create' && call.source_id === 'description');
  assert.equal(textCreate.product_description, 'A glazed serving bowl with a wide rim.');
  assert.equal(textCreate.review_token, token(textCreate));
  pass('Editing a product description requires a new review before the approved text can be sent');


  await button('Close').click();
  await page.locator('#chatThread').getByRole('button', {name:'Draft marketing',exact:true}).click();
  await control('product').locator('option[value="105"]').waitFor({state:'attached'});
  assert.equal(await control('source').inputValue(),'31');
  await control('product').selectOption('105');
  await button('Review source').click();
  await control('review').locator('img[src*="id=31"]').waitFor();
  await button('Save private marketing draft').click();
  await control('result').getByText('A bowl for the table',{exact:true}).waitFor();
  const marketing = calls.find(call => call.action==='create' && call.tool==='marketing');
  assert.equal(marketing.product_id,105);
  assert.equal(marketing.source_id,'31');
  assert.equal(marketing.review_token,token(marketing));
  pass('A Chat image card opens a reviewed marketing draft for the exact asset and chosen product');

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
  const revisionPage = await browser.newPage();
  const revisionFixture = await fixture(revisionPage);
  await revisionPage.evaluate(() => window.VP3InitiativeWorkbench.open('product','31','b'.repeat(64)));
  await revisionFixture.control('source').waitFor();
  await revisionPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value === '31');
  await revisionFixture.button('Review source').click();
  await revisionFixture.dialog.getByText('This source changed after the Agent prepared its suggestion. Reload the source to review its current version.',{exact:true}).waitFor();
  assert.equal(await revisionFixture.dialog.locator('.vp3-workbench-actions button').last().isVisible(),false);
  assert.equal(revisionFixture.calls.some(call => call.action==='create'),false);
  pass('An old Agent review proposal cannot become approval for a changed source');
  await revisionFixture.button('Reload sources').click();
  await revisionPage.waitForFunction(() => !document.querySelector('[data-workbench-source]')?.disabled);
  await revisionFixture.button('Review source').click();
  await revisionFixture.dialog.getByText('Source ready for your review.',{exact:true}).waitFor();
  assert.equal(revisionFixture.calls.some(call => call.action==='create'),false);
  pass('Explicitly reloading a source permits a fresh review while creation still requires confirmation');

  const treatmentPage = await browser.newPage();
  const treatmentFixture = await fixture(treatmentPage);
  await treatmentPage.evaluate(capture => window.VP3InitiativeWorkbench.open('screen',capture,'a'.repeat(64)),capture);
  await treatmentPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value !== '');
  await treatmentFixture.control('mode').selectOption('redraw');
  await treatmentFixture.control('instruction').fill('Use line art.');
  await treatmentFixture.button('Review source').click();
  await treatmentFixture.button('Create edited image').waitFor();
  assert.equal(treatmentFixture.calls.filter(call=>call.action==='preview').at(-1).instruction,'Use line art.');
  assert.equal(treatmentFixture.calls.some(call=>call.action==='create'),false);
  pass('Owner-selected redraw and directions retain the Agent capture reference but require a fresh treatment review');
  await treatmentFixture.control('instruction').fill('Use ink.');
  treatmentFixture.onPreview(({body,ok,preview}) => ok({preview:{...preview,reference_revision:'b'.repeat(64)},review_token:token(body)}));
  await treatmentFixture.button('Review source').click();
  await treatmentFixture.dialog.getByText('This source changed after the Agent prepared its suggestion. Reload the source to review its current version.',{exact:true}).waitFor();
  assert.equal(await treatmentFixture.button('Create edited image').isVisible(),false);
  assert.equal(treatmentFixture.calls.some(call=>call.action==='create'),false);
  assert.deepEqual(treatmentFixture.errors,[]);
  pass('Changing edit directions cannot bypass a changed saved-capture reference');

  const accountPage = await browser.newPage();
  const accountFixture = await fixture(accountPage);
  await accountPage.evaluate(() => window.VP3InitiativeWorkbench.open('product','31'));
  await accountPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value === '31');
  let releasePreview;
  const previewGate = new Promise(resolve => { releasePreview = resolve; });
  let finishPreview;
  const previewSettled = new Promise(resolve => { finishPreview = resolve; });
  accountFixture.onPreview(async ({body,ok,preview}) => {
    await previewGate;
    try { await ok({preview,review_token:token(body)}); } catch {} finally { finishPreview(); }
  });
  const pendingPreview = accountFixture.waitForAction('preview','product');
  await accountFixture.button('Review source').click();
  await pendingPreview;
  await accountPage.evaluate(() => {
    window.originalChatConfig = window.STONEFELLOW_CHAT;
    window.STONEFELLOW_CHAT = {...window.STONEFELLOW_CHAT,userId:99};
  });
  releasePreview();await previewSettled;
  await accountFixture.dialog.waitFor({state:'hidden'});
  assert.equal(await accountFixture.control('review').textContent(),'');
  assert.equal(accountFixture.calls.some(call => call.action==='create'),false);
  pass('A pending preview clears on account replacement without relying on a Chat event');

  await accountPage.evaluate(() => {
    window.STONEFELLOW_CHAT=window.originalChatConfig;
    window.VP3InitiativeWorkbench.open('product','31');
  });
  await accountPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value === '31');
  await accountFixture.button('Review source').click();
  await accountFixture.button('Create private draft').waitFor();
  const beforeStaleCreate = accountFixture.calls.filter(call => call.action==='create').length;
  await accountPage.evaluate(() => {
    window.STONEFELLOW_CHAT = {...window.STONEFELLOW_CHAT,workingAttentionBinding:'rotated-session'};
    document.querySelector('#vp3InitiativeWorkbench .vp3-workbench-actions button:last-child').click();
  });
  await accountFixture.dialog.waitFor({state:'hidden'});
  assert.equal(accountFixture.calls.filter(call => call.action==='create').length,beforeStaleCreate);
  assert.equal(await accountFixture.control('review').textContent(),'');
  pass('A stale session cannot send an already-reviewed Create action');

  await accountPage.evaluate(() => {
    window.STONEFELLOW_CHAT=window.originalChatConfig;
    window.VP3InitiativeWorkbench.open('product','31');
  });
  await accountPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value === '31');
  await accountFixture.button('Review source').click();
  await accountFixture.button('Create private draft').waitFor();
  let releaseCreate;
  const createGate = new Promise(resolve => { releaseCreate = resolve; });
  let finishCreate;
  const createSettled = new Promise(resolve => { finishCreate = resolve; });
  accountFixture.onCreate(async ({ok}) => {
    await createGate;
    try { await ok({output:{...outputs.product,title:'OLD ACCOUNT PRIVATE RESULT'}}); } catch {} finally { finishCreate(); }
  });
  const pendingCreate = accountFixture.waitForAction('create','product');
  await accountFixture.button('Create private draft').click();await pendingCreate;
  await accountPage.evaluate(() => { delete window.STONEFELLOW_CHAT; });
  releaseCreate();await createSettled;
  await accountFixture.dialog.waitFor({state:'hidden'});
  assert.equal(await accountPage.getByText('OLD ACCOUNT PRIVATE RESULT',{exact:true}).count(),0);
  assert.equal(await accountFixture.control('result').textContent(),'');
  pass('A late Create response clears private data after logout configuration disappears');

  const legacyPage = await browser.newPage();
  const legacyFixture = await fixture(legacyPage,{chatConfig:false});
  await legacyPage.evaluate(() => window.VP3InitiativeWorkbench.open('product','31'));
  await legacyPage.waitForFunction(() => document.querySelector('[data-workbench-source]')?.value === '31');
  await legacyFixture.button('Review source').click();
  await legacyFixture.button('Create private draft').waitFor();
  assert.equal(legacyFixture.calls.some(call=>call.action==='create'),false);
  assert.deepEqual(accountFixture.errors,[]);assert.deepEqual(legacyFixture.errors,[]);
  pass('A controlled standalone Workbench without Chat configuration retains explicit source review');

  const promotionPage = await browser.newPage({viewport:{width:390,height:844}});
  const promotionFixture = await fixture(promotionPage);
  const canvas = promotionPage.locator('#chatThread > [data-marketing-canvas]');
  const canvasButton = name => canvas.getByRole('button',{name,exact:true});
  await promotionPage.evaluate(() => {
    document.getElementById('chatThread').dataset.view = 'player';
    window.VP3InitiativeWorkbench.open('marketing','105-31');
  });
  await promotionFixture.button('Review source').click();
  await promotionFixture.button('Save private marketing draft').click();
  await promotionFixture.button('Design promotion in Canvas').click();
  await canvas.getByRole('heading',{name:'Product promotion'}).waitFor();
  assert.equal(await promotionPage.locator('#chatThread').getAttribute('data-view'),'chat');
  await promotionFixture.dialog.waitFor({state:'hidden'});
  assert.equal(await promotionPage.locator('#chatForm textarea').isEnabled(),true);
  const slider = canvas.getByRole('slider',{name:'Use original image'});
  assert.equal(await slider.inputValue(),'1');
  await canvas.locator('[data-marketing-headline]').fill('Serve your favorite meal');
  await canvas.locator('[data-marketing-copy]').fill('A wide rim for sharing.');
  await canvasButton('Review graphic settings').click();
  await canvasButton('Create private graphic').waitFor();
  assert.equal(promotionFixture.calls.some(call => call.action === 'creative_generate'),false);
  await canvasButton('Create private graphic').click();
  await canvasButton('Save to Media Library').waitFor();
  assert.equal(await canvas.getByRole('img',{name:'Original product photo'}).getAttribute('src'),root+originalUrl);
  assert.match(await canvas.locator('[data-marketing-visitor] img').getAttribute('src'),/^blob:/);
  assert.equal(await canvas.getByRole('link',{name:'Shop the bowl'}).getAttribute('href'),root+productUrl);
  assert.equal(promotionFixture.calls.some(call => call.action === 'creative_tick'),false);
  assert.equal(await canvas.evaluate(element => element.scrollWidth <= element.clientWidth),true);
  const selectedDesign = promotionFixture.calls.find(call => call.action==='creative_generate');
  assert.equal(selectedDesign.level,1);
  assert.equal(selectedDesign.headline,'Serve your favorite meal');
  assert.equal(selectedDesign.copy,'A wide rim for sharing.');
  pass('Level 1 opens in the real Chat Canvas, preserves the exact source, and composes a private graphic with the visitor CTA');

  await canvasButton('Save to Media Library').click();
  await canvasButton('Review publication').waitFor();
  assert.deepEqual(promotionFixture.uploads,['begin','chunk','finish']);
  assert.equal(await canvas.locator('[data-marketing-visitor] img').getAttribute('src'),root+graphicUrl);
  const campaignChoice = canvas.getByLabel('Campaign (optional)');
  await campaignChoice.selectOption('17');
  await canvasButton('Review publication').click();
  await canvasButton('Approve and publish').waitFor();
  assert.match(await canvas.locator('[data-marketing-confirmation]').innerText(),/credited to Summer table/);
  assert.equal(promotionFixture.calls.some(call => call.action==='creative_publish'),false);
  const approvedDesignSettings = await canvasSettings(canvas);
  const approvedDesignCalls = promotionFixture.calls.length;
  const approvedDesignCopy = await canvas.locator('[data-marketing-confirmation]').innerText();
  await canvasButton('Choose from Media Library').click();
  await picker(canvas).locator('[data-media-picker-asset="160"]').click();
  await picker(canvas).locator('[data-media-picker-cancel]').click();
  assert.deepEqual(await canvasSettings(canvas),approvedDesignSettings);
  assert.equal(await canvas.locator('[data-marketing-confirmation]').innerText(),approvedDesignCopy);
  assert.equal(await canvasButton('Approve and publish').isEnabled(),true);
  assert.equal(await canvas.locator('[data-marketing-visitor] img').getAttribute('src'),root+graphicUrl);
  assert.equal(promotionFixture.calls.length,approvedDesignCalls);
  pass('Opening and cancelling the shared picker preserves the approved graphic, saved draft, publication review, and selected campaign');
  await canvas.locator('[data-marketing-copy]').fill('A different, unreviewed caption');
  assert.equal(await canvasButton('Approve and publish').isVisible(),false);
  await canvas.getByLabel('Recent designs').selectOption(selectedDesign.nonce);
  await campaignChoice.selectOption('17');
  await canvasButton('Review publication').click();
  await canvasButton('Approve and publish').click();
  const publicPromotion = canvas.getByRole('link',{name:'View public promotion'});
  await publicPromotion.waitFor();
  assert.equal(await publicPromotion.getAttribute('href'),root+'/dave#product-promotion-fixture');
  assert.equal(promotionFixture.calls.find(call => call.action==='creative_publish').creative_id,selectedDesign.nonce);
  assert.equal(promotionFixture.calls.find(call => call.action==='creative_publish').campaign_id,17);
  assert.equal(await campaignChoice.inputValue(),'17');
  assert.equal(await canvasButton('Review publication').isVisible(),false);
  await campaignChoice.selectOption('23');
  await canvasButton('Review publication').click();
  await canvasButton('Approve and publish').waitFor();
  assert.match(await canvas.locator('[data-marketing-confirmation]').innerText(),/credited to Autumn table/);
  assert.equal(promotionFixture.calls.filter(call => call.action==='creative_publish').length,1);
  await canvasButton('Approve and publish').click();
  await canvasButton('Approve and publish').waitFor({state:'hidden'});
  assert.equal(promotionFixture.calls.filter(call => call.action==='creative_publish').at(-1).campaign_id,23);
  assert.equal(await campaignChoice.inputValue(),'23');
  await canvasButton('Pause promotion').click();
  await publicPromotion.waitFor({state:'hidden'});
  assert.equal(promotionFixture.calls.find(call => call.action==='creative_pause').creative_id,selectedDesign.nonce);
  pass('Publication freezes the exact PNG and optional campaign, and a live campaign change requires a fresh owner review');

  await canvasButton('Close promotion').click();
  await promotionPage.evaluate(() => window.VP3InitiativeWorkbench.open('marketing','105-31',{
    marketingPrefill:{level:7,prompt:'Place the bowl on a café counter.'}
  }));
  await promotionFixture.button('Review source').click();
  await promotionFixture.button('Design promotion · A bowl for the table').click();
  await canvas.getByLabel('Scene directions').waitFor();
  assert.equal(await slider.inputValue(),'7');
  assert.equal(await canvas.getByLabel('Scene directions').inputValue(),'Place the bowl on a café counter.');
  await canvasButton('Review graphic settings').click();
  await canvasButton('Generate with OpenAI').waitFor();
  assert.equal(promotionFixture.calls.filter(call => call.action==='creative_generate').length,1);
  await canvasButton('Generate with OpenAI').click();
  await canvasButton('Save to Media Library').waitFor();
  assert.equal(promotionFixture.calls.filter(call => call.action==='creative_tick').length,1);
  const sceneDesign = promotionFixture.calls.filter(call => call.action==='creative_generate').at(-1);
  assert.equal(sceneDesign.level,7);
  assert.equal(sceneDesign.prompt,'Place the bowl on a café counter.');
  pass('A level 7 Chat hint survives the saved-draft handoff, requires provider confirmation, and uses the exact completed scene');

  await canvasButton('Close promotion').click();
  const launch = {owner_user_id:Number(owner),agent_id:agent,conversation_id:conversation,product_id:105,source_id:'31',
    draft_id:draftId,creative_id:selectedDesign.nonce,publication_id:'1'.repeat(32),stage:'promotion_outcomes'};
  const beforeResume = promotionFixture.calls.length;
  assert.equal(await promotionPage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({...marker,conversation_id:99}),launch),false);
  assert.equal(await promotionPage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({...marker,owner_user_id:99}),launch),false);
  assert.equal(await promotionPage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch(marker),launch),true);
  await canvasButton('Review publication').waitFor();
  assert.equal(await canvas.getByLabel('Recent designs').inputValue(),selectedDesign.nonce);
  assert.equal(await canvas.locator('[data-marketing-headline]').inputValue(),selectedDesign.headline);
  assert.equal(await canvasButton('Approve and publish').isVisible(),false);
  assert.deepEqual(promotionFixture.calls.slice(beforeResume).map(call => [call.action,call.creative_id]),[['creative_open',selectedDesign.nonce]]);
  pass('Promotion results reopen the exact older creative despite a newer scene and leave publication to explicit review');

  await canvasButton('Close promotion').click();
  promotionFixture.onCreativeOpen(variants => ({...variants.find(item => item.id === selectedDesign.nonce),status:'starting'}));
  const beforeStartingResume = promotionFixture.calls.length;
  assert.equal(await promotionPage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({...marker,stage:'image_starting'}),launch),true);
  await canvasButton('Refresh image status').waitFor();
  assert.equal(await canvas.getByLabel('Recent designs').inputValue(),selectedDesign.nonce);
  assert.deepEqual(promotionFixture.calls.slice(beforeStartingResume).map(call => [call.action,call.creative_id]),[['creative_open',selectedDesign.nonce]]);
  pass('A marketing plan at image_starting resumes its exact private creative without implicitly retrying generation');

  await canvasButton('Close promotion').click();
  promotionFixture.onCreativeOpen(variants => variants[0]);
  assert.equal(await promotionPage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch(marker),launch),true);
  await canvas.getByText('The selected promotion design could not be verified.',{exact:true}).waitFor();
  assert.equal(await canvasButton('Review publication').isVisible(),false);
  assert.equal(await canvasButton('Review graphic settings').isVisible(),false);
  await canvasButton('Retry opening draft').click();
  await canvasButton('Review publication').waitFor();
  assert.equal(await canvas.getByLabel('Recent designs').inputValue(),selectedDesign.nonce);
  pass('A stale or mismatched Canvas response cannot display a replacement creative or offer approval');

  await promotionPage.evaluate(() => {
    window.fixtureChatId = 999;
    window.dispatchEvent(new Event('vp3:chat-conversation-changed'));
  });
  await canvas.waitFor({state:'detached'});
  assert.deepEqual(promotionFixture.errors,[]);
  pass('A different Chat removes the private promotion Canvas and its image');

  const photoGatePage = await browser.newPage();
  const photoGateFixture = await fixture(photoGatePage,{marketingDrafts:[],linkedProductImage:false});
  const photoNeeded = {owner_user_id:Number(owner),agent_id:agent,conversation_id:conversation,product_id:105,stage:'photo_needed'};
  assert.equal(await photoGatePage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch(marker),photoNeeded),true);
  await photoGateFixture.control('source').locator('option[value="31"]').waitFor({state:'attached'});
  assert.equal(await photoGateFixture.control('product').inputValue(),'105');
  assert.equal(await photoGateFixture.control('source').inputValue(),'');
  assert.equal(await photoGateFixture.button('Review source').isDisabled(),true);
  assert.deepEqual(photoGateFixture.calls.map(call => call.action),['sources']);
  await photoGateFixture.control('source').selectOption('31');
  assert.deepEqual(photoGateFixture.calls.map(call => call.action),['sources']);
  await photoGateFixture.button('Review source').click();
  await photoGateFixture.button('Save private marketing draft').waitFor();
  assert.deepEqual(photoGateFixture.calls.map(call => call.action),['sources','preview']);
  assert.equal(photoGateFixture.calls.at(-1).product_id,105);
  assert.equal(photoGateFixture.calls.at(-1).source_id,'31');
  pass('A listing without an original photo opens marketing for the exact product and requires choosing and reviewing a photo');

  const launchGatePage = await browser.newPage();
  const launchGateFixture = await fixture(launchGatePage,{marketingDrafts:[]});
  assert.equal(await launchGatePage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({
    ...marker,stage:'marketing_review',draft_id:undefined,creative_id:undefined
  }),launch),true);
  await launchGateFixture.button('Save private marketing draft').waitFor();
  assert.deepEqual(launchGateFixture.calls.map(call => call.action),['sources','preview']);
  assert.equal(launchGateFixture.calls.at(-1).source_id,'31');
  assert.equal(launchGateFixture.calls.at(-1).product_id,105);
  await launchGateFixture.button('Close').click();
  assert.equal(await launchGatePage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({
    ...marker,stage:'design_review',creative_id:undefined
  }),launch),true);
  await launchGatePage.locator('[data-marketing-canvas]').getByRole('button',{name:'Review graphic settings'}).waitFor();
  assert.deepEqual(launchGateFixture.calls.map(call => call.action),['sources','preview','creative_open']);
  assert.equal(launchGateFixture.calls.at(-1).draft_id,draftId);
  await launchGatePage.route(`${root}/profile-commerce-products.php?workspace_product=105`,route => route.fulfill({body:'Commerce review'}));
  await launchGatePage.evaluate(marker => window.VP3InitiativeWorkbench.openProductLaunch({
    ...marker,stage:'listing_review',source_id:undefined,draft_id:undefined,creative_id:undefined
  }),launch);
  await launchGatePage.waitForURL(`${root}/profile-commerce-products.php?workspace_product=105`);
  pass('Launch gates open the exact product listing, linked-photo review or saved marketing draft with no implicit approval');

  const resumePage = await browser.newPage();
  const resumeQuery = `?conversation_id=${conversation}&agent=${agent}&marketing_product=105&marketing_source=31&marketing_draft=${draftId}`;
  const resumeFixture = await fixture(resumePage,{query:resumeQuery});
  const resumed = resumePage.locator('#chatThread > [data-marketing-canvas]');
  await resumed.getByRole('button',{name:'Review graphic settings'}).waitFor();
  assert.equal(resumeFixture.calls.find(call => call.action==='creative_open').draft_id,draftId);
  assert.equal(await resumeFixture.dialog.isVisible(),false);
  assert.deepEqual(resumeFixture.errors,[]);
  const foreignResumePage = await browser.newPage();
  const foreignResumeFixture = await fixture(foreignResumePage,{query:resumeQuery.replace('agent='+agent,'agent=99')});
  await foreignResumePage.evaluate(() => window.dispatchEvent(new Event('vp3:chat-context-ready')));
  await foreignResumeFixture.flush();
  assert.equal(foreignResumeFixture.calls.some(call => call.action==='creative_open'),false);
  pass('A Commerce resume link opens the same saved draft only after its owning Agent and Chat match');

  const listingQuery = `?conversation_id=${conversation}&agent=${agent}&marketing_product=105&marketing_source=31`;
  const listingResumePage = await browser.newPage();
  const listingResume = await fixture(listingResumePage,{query:listingQuery,ready:false,
    marketingDrafts:[{draft_id:'c'.repeat(64),product_id:105,asset_id:32,title:'Different image'},
      ...reviewDetails.marketing.drafts]});
  await listingResume.flush();
  assert.deepEqual(listingResume.calls,[]);
  await listingResumePage.evaluate(() => {
    window.fixtureChatReady = true;
    window.dispatchEvent(new Event('vp3:chat-context-ready'));
  });
  await listingResumePage.locator('[data-marketing-canvas]').getByRole('button',{name:'Review graphic settings'}).waitFor();
  assert.deepEqual(listingResume.calls.map(call => call.action),['sources','preview','creative_open']);
  assert.equal(listingResume.calls.at(-1).draft_id,draftId);
  assert.equal(listingResume.calls.at(-1).source_id,'31');
  assert.equal(listingResume.calls.at(-1).product_id,105);
  pass('An approved listing reopens its exact existing marketing draft after Chat restores, without creating another draft');

  const unavailableCanvasPage = await browser.newPage();
  const unavailableCanvas = await fixture(unavailableCanvasPage,{query:listingQuery,canvasAvailable:false});
  await unavailableCanvas.dialog.getByRole('status').getByText('Your marketing draft is saved.',{exact:false}).waitFor();
  assert.deepEqual(unavailableCanvas.calls.map(call => call.action),['sources','preview']);
  assert.equal(await unavailableCanvas.button('Save private marketing draft').isVisible(),false);
  pass('A saved marketing draft does not become a new draft if Canvas is unavailable');

  const newListingPage = await browser.newPage();
  const newListing = await fixture(newListingPage,{query:listingQuery.replace('agent='+agent,'agent=system'),
    activeAgent:0,marketingDrafts:[]});
  await newListing.button('Save private marketing draft').waitFor();
  assert.equal(await newListing.control('product').inputValue(),'105');
  assert.equal(await newListing.control('source').inputValue(),'31');
  assert.deepEqual(newListing.calls.map(call => call.action),['sources','preview']);
  await newListing.button('Save private marketing draft').click();
  await newListingPage.locator('[data-marketing-canvas]').getByRole('button',{name:'Review graphic settings'}).waitFor();
  assert.deepEqual(newListing.calls.map(call => call.action),['sources','preview','create','creative_open']);
  assert.ok(newListing.calls.every(call => call.agent_id===0 && call.conversation_id===conversation));
  assert.equal(newListing.calls.at(-2).confirmed,true);
  assert.equal(newListing.calls.at(-1).source_id,'31');
  assert.equal(newListing.calls.at(-1).product_id,105);
  pass('A listing from system Agent Chat preselects the exact product and photo, then opens Canvas only after draft approval');

  const changedListingPage = await browser.newPage();
  const changedListing = await fixture(changedListingPage,{query:listingQuery,linkedProductImage:false});
  await changedListing.dialog.getByRole('status').getByText('The linked listing photo changed.',{exact:false}).waitFor();
  assert.deepEqual(changedListing.calls.map(call => call.action),['sources','preview']);
  assert.equal(await changedListing.button('Save private marketing draft').isVisible(),false);
  const wrongListingPage = await browser.newPage();
  const wrongListing = await fixture(wrongListingPage,{query:listingQuery.replace('conversation_id='+conversation,'conversation_id=99')});
  await wrongListing.flush();
  assert.deepEqual(wrongListing.calls,[]);
  pass('A changed listing photo or wrong conversation cannot continue into the promotion');

  const pickerPage = await browser.newPage({viewport:{width:1280,height:900}});
  const productPicker = await fixture(pickerPage,{largeLibrary:true});
  const typedDescription = '  A glazed serving bowl for sharing.\nKeep this wording while I compare photographs.  ';
  await pickerPage.evaluate(() => window.VP3InitiativeWorkbench.open('product','description'));
  await productPicker.control('source').locator('option[value="description"]').waitFor({state:'attached'});
  await productPicker.control('product-description').fill(typedDescription);
  assert.equal(await productPicker.control('source').locator('option').count(),52);
  assert.equal(await productPicker.control('source').locator(`option[value="${oldGeneratedId}"]`).count(),0);
  const originalProductCalls = productPicker.calls.length;
  await productPicker.button('Choose from Media Library').click();
  await picker(productPicker.dialog).locator('[data-media-picker-asset="160"]').click();
  assert.equal(productPicker.calls.length,originalProductCalls);
  await picker(productPicker.dialog).locator('[data-media-picker-cancel]').click();
  assert.equal(await productPicker.control('source').inputValue(),'description');
  assert.equal(await productPicker.control('product-description').inputValue(),typedDescription);
  assert.equal(productPicker.calls.length,originalProductCalls);
  pass('Product picker browsing and Cancel preserve the typed description without requesting a preview or creating anything');

  await productPicker.button('Choose from Media Library').click();
  const productSelection = picker(productPicker.dialog);
  await productSelection.locator('[data-media-picker-asset="160"]').waitFor();
  await productSelection.locator('[data-media-picker-more]').click();
  await productSelection.locator('[data-media-picker-asset="113"]').waitFor();
  await productSelection.locator('[data-media-picker-more]').click();
  await productSelection.locator(`[data-media-picker-asset="${oldGeneratedId}"]`).click();
  assert.equal(await productPicker.control('source').inputValue(),'description');
  await productSelection.locator('[data-media-picker-use]').scrollIntoViewIfNeeded();
  await pickerScreenshot(productPicker.dialog,'product-picker-desktop.png');
  await productSelection.locator('[data-media-picker-use]').click();
  await productSelection.waitFor({state:'hidden'});
  assert.equal(await productPicker.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await productPicker.control('product-description').inputValue(),typedDescription);
  assert.equal(await productPicker.control('selected-image').locator('img').getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  assert.equal(productPicker.calls.at(-1).source_id,String(oldGeneratedId));
  assert.equal(productPicker.calls.at(-1).action,'preview');
  assert.equal(productPicker.calls.at(-1).tool,'product');
  assert.equal(await productPicker.button('Create private draft').isVisible(),false);
  assert.ok(productPicker.browseCalls.some(call => call.before === '137'));
  assert.ok(productPicker.browseCalls.some(call => call.before === '113'));
  await productPicker.button('Use a description').click();
  assert.equal(await productPicker.control('source').inputValue(),'description');
  assert.equal(await productPicker.control('product-description').inputValue(),typedDescription);
  await productPicker.control('source').selectOption(String(oldGeneratedId));
  await productPicker.button('Review source').click();
  await productPicker.button('Create private draft').waitFor();
  assert.equal(productPicker.calls.at(-1).source_id,String(oldGeneratedId));
  assert.equal(await productPicker.control('review').locator('img').getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  assert.ok(productPicker.calls.every(call => ['sources','preview'].includes(call.action)));
  assert.deepEqual(productPicker.uploads,[]);
  pass('An image older than the latest 50 is chosen through library pagination, retains its exact ID and preview, and still requires source review');

  const reviewedProduct = await productPicker.control('review').innerText();
  await productPicker.button('Choose from Media Library').click();
  await searchImage(productPicker.dialog,'Original studio photograph',oldUploadedId);
  productPicker.onPreview(({fail}) => fail(404,'The selected photo is no longer available.'));
  await picker(productPicker.dialog).locator('[data-media-picker-use]').click();
  await picker(productPicker.dialog).getByText('The selected photo is no longer available.',{exact:true}).waitFor();
  assert.equal(await productPicker.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await productPicker.control('product-description').inputValue(),typedDescription);
  assert.equal(await productPicker.control('review').innerText(),reviewedProduct);
  await picker(productPicker.dialog).locator('[data-media-picker-cancel]').click();
  assert.equal(await productPicker.button('Create private draft').isEnabled(),true);
  pass('A failed exact-image validation leaves the Product source, description, and previous review intact');

  await productPicker.button('Choose from Media Library').click();
  await searchImage(productPicker.dialog,'Original studio photograph',oldUploadedId);
  productPicker.onPreview(({body,ok,preview}) => ok({owner_key:'99',
    preview:{...preview,source_title:'Another account private image'},review_token:token(body)}));
  await picker(productPicker.dialog).locator('[data-media-picker-use]').click();
  await picker(productPicker.dialog).getByText('The selected image could not be verified for this account and Chat.',{exact:true}).waitFor();
  assert.equal(await productPicker.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await productPicker.control('review').innerText(),reviewedProduct);
  assert.equal(await pickerPage.getByText('Another account private image',{exact:true}).count(),0);
  await picker(productPicker.dialog).locator('[data-media-picker-cancel]').click();
  pass('A foreign-account exact preview cannot commit or render its private image metadata in the Product form');

  await productPicker.button('Choose from Media Library').click();
  await picker(productPicker.dialog).locator('[data-media-picker-asset="160"]').click();
  let releaseImageSelection;
  const imageSelectionGate = new Promise(resolve => { releaseImageSelection = resolve; });
  let finishImageSelection;
  const imageSelectionSettled = new Promise(resolve => { finishImageSelection = resolve; });
  productPicker.onPreview(async ({body,ok,preview}) => {
    await imageSelectionGate;
    try { await ok({preview,review_token:token(body)}); } catch {} finally { finishImageSelection(); }
  });
  const pendingImageSelection = productPicker.waitForAction('preview','product');
  await picker(productPicker.dialog).locator('[data-media-picker-use]').click();
  await pendingImageSelection;
  await picker(productPicker.dialog).locator('[data-media-picker-asset="159"]').click();
  releaseImageSelection();
  await imageSelectionSettled;
  await productPicker.flush();
  assert.equal(await productPicker.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await picker(productPicker.dialog).locator('[data-media-picker-preview]').getAttribute('data-media-picker-candidate'),'159');
  await picker(productPicker.dialog).locator('[data-media-picker-use]').click();
  await picker(productPicker.dialog).waitFor({state:'hidden'});
  assert.equal(await productPicker.control('source').inputValue(),'159');
  assert.equal(await productPicker.control('product-description').inputValue(),typedDescription);
  assert.ok(productPicker.calls.every(call => ['sources','preview'].includes(call.action)));
  assert.deepEqual(productPicker.uploads,[]);
  pass('Replacing a candidate while validation is in flight discards the old response and only Use image commits the replacement');

  const immediateReset = await pickerPage.evaluate(() => {
    const dialog = document.getElementById('vp3InitiativeWorkbench');
    const panel = dialog.querySelector('[data-workbench-selected-image]');
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    const cleared = {open:dialog.open,hidden:panel.hidden,src:panel.querySelector('img').getAttribute('src'),
      alt:panel.querySelector('img').getAttribute('alt'),caption:panel.querySelector('figcaption').textContent};
    const reopened = window.VP3InitiativeWorkbench.openMarketingImage({id:160,title:'New selection after context reset',
      available:true,media_type:'photo',mime_type:'image/png',url:'/media-library-file-v86.php?id=160'},
    {ownerKey:'12',agentId:7,conversationId:42,isCurrent:() => true});
    return {cleared,reopened};
  });
  assert.deepEqual(immediateReset,{cleared:{open:false,hidden:true,src:null,alt:null,caption:''},reopened:true});
  await productPicker.flush();
  assert.equal(await productPicker.dialog.evaluate(node => node.open),true);
  assert.equal(await productPicker.control('selected-image').locator('img').getAttribute('src'),root + '/media-library-file-v86.php?id=160');
  assert.equal(await productPicker.control('selected-image').locator('figcaption').textContent(),'New selection after context reset');
  pass('Context invalidation clears private image metadata synchronously and a queued close event preserves an immediately reopened selection');

  for (const contextChange of ['account','agent','conversation','participant','historical participant','binding']) {
    await pickerPage.evaluate(() => {
      window.STONEFELLOW_CHAT = {userId:12,agentId:7,workingAttentionBinding:'fixture-session'};
      window.fixtureChatId = 42;
      delete window.VP3ParticipantContinuity;
      window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:0}}));
      window.VP3InitiativeWorkbench.open('product','159');
    });
    await productPicker.control('source').locator('option[value="159"]').waitFor({state:'attached'});
    await productPicker.button('Review source').click();
    await productPicker.button('Create private draft').waitFor();
    assert.equal(await productPicker.control('selected-image').locator('img').getAttribute('src'),root + '/media-library-file-v86.php?id=159');
    await productPicker.button('Choose from Media Library').click();
    await picker(productPicker.dialog).locator('[data-media-picker-asset="160"]').click();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let finish;
    const settled = new Promise(resolve => { finish = resolve; });
    productPicker.onPreview(async ({body,ok,preview}) => {
      await gate;
      try { await ok({preview:{...preview,source_title:'Late private source'},review_token:token(body)}); }
      catch {} finally { finish(); }
    });
    const pendingSelection = productPicker.waitForAction('preview','product');
    await picker(productPicker.dialog).locator('[data-media-picker-use]').click();
    await pendingSelection;
    await pickerPage.evaluate(change => {
      if (change === 'account') window.STONEFELLOW_CHAT.userId = 99;
      if (change === 'agent') window.STONEFELLOW_CHAT.agentId = 8;
      if (change === 'conversation') window.fixtureChatId = 43;
      if (change === 'participant') window.VP3ParticipantContinuity = {selectedContext:() => ({participant_id:9})};
      if (change === 'historical participant') window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}}));
      if (change === 'binding') window.STONEFELLOW_CHAT.workingAttentionBinding = 'replaced-session';
    },contextChange);
    release();
    await settled;
    await productPicker.dialog.waitFor({state:'hidden'});
    assert.equal(await pickerPage.getByText('Late private source',{exact:true}).count(),0);
    assert.equal(await productPicker.control('selected-image').locator('img').getAttribute('src'),null);
    assert.equal(await productPicker.control('selected-image').locator('img').getAttribute('alt'),null);
    assert.equal(await productPicker.control('selected-image').locator('figcaption').textContent(),'');
    assert.equal(productPicker.calls.some(call => call.action === 'create'),false);
  }
  assert.deepEqual(productPicker.errors,[]);
  pass('Account, Agent, Chat, selected or historical participant, and session changes invalidate pending image use before any destination mutation');

  const marketingPickerPage = await browser.newPage({viewport:{width:390,height:844}});
  const marketingPicker = await fixture(marketingPickerPage,{largeLibrary:true,marketingDrafts:[]});
  await marketingPickerPage.evaluate(() => window.VP3InitiativeWorkbench.open('marketing','',{productId:106}));
  await marketingPicker.control('source').locator('option[value="160"]').waitFor({state:'attached'});
  assert.equal(await marketingPicker.control('product').inputValue(),'106');
  await marketingPicker.button('Choose from Media Library').click();
  await searchImage(marketingPicker.dialog,'Generated bowl in a garden',oldGeneratedId);
  await picker(marketingPicker.dialog).locator('[data-media-picker-use]').click();
  await picker(marketingPicker.dialog).waitFor({state:'hidden'});
  assert.equal(await marketingPicker.control('product').inputValue(),'106');
  assert.equal(await marketingPicker.control('source').inputValue(),String(oldGeneratedId));
  assert.deepEqual(marketingPicker.calls.map(call => call.action),['sources','preview']);
  assert.equal(marketingPicker.calls.at(-1).product_id,106);
  assert.equal(marketingPicker.calls.at(-1).source_id,String(oldGeneratedId));
  assert.deepEqual(marketingPicker.uploads,[]);
  await marketingPicker.button('Review source').click();
  await marketingPicker.button('Save private marketing draft').waitFor();
  await marketingPicker.button('Save private marketing draft').click();
  await marketingPicker.button('Design promotion in Canvas').click();
  const changingCanvas = marketingPickerPage.locator('[data-marketing-canvas]');
  const changingButton = name => changingCanvas.getByRole('button',{name,exact:true});
  await changingButton('Review graphic settings').waitFor();
  const initialMarketingDraft = marketingPicker.calls.find(call => call.action === 'creative_open').draft_id;
  assert.equal(marketingPicker.calls.find(call => call.action === 'create').source_id,String(oldGeneratedId));
  assert.equal(marketingPicker.calls.find(call => call.action === 'create').product_id,106);
  pass('Marketing selection keeps the chosen product, and only explicit source review and draft creation open the exact saved image in Canvas');

  await changingCanvas.locator('[data-marketing-headline]').fill('  Garden collection  ');
  await changingCanvas.locator('[data-marketing-copy]').fill('A setting for summer.\nKeep this exact copy.');
  await changingCanvas.locator('[data-marketing-level]').evaluate(input => { input.value = '6'; input.dispatchEvent(new Event('input',{bubbles:true})); });
  await changingCanvas.locator('[data-marketing-prompt]').fill('  Set the bowl on a quiet garden table.  ');
  const layoutStyle = changingCanvas.getByRole('combobox',{name:'Layout style'});
  await layoutStyle.selectOption('warm');
  assert.equal(await layoutStyle.inputValue(),'warm');
  await changingCanvas.getByRole('checkbox',{name:'Remove a solid background',exact:true}).check();
  await changingCanvas.locator('#vp3MarketingCutoutTolerance').evaluate(input => { input.value = '37'; input.dispatchEvent(new Event('input',{bubbles:true})); });
  const preservedSettings = await canvasSettings(changingCanvas);
  const canvasCalls = marketingPicker.calls.length;
  await changingButton('Choose from Media Library').click();
  await searchImage(changingCanvas,'Original studio photograph',oldUploadedId);
  await picker(changingCanvas).locator('[data-media-picker-cancel]').click();
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  assert.equal(marketingPicker.calls.length,canvasCalls);
  assert.equal(await changingCanvas.getByRole('img',{name:'Original product photo',exact:true}).getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  await changingButton('Choose from Media Library').click();
  await searchImage(changingCanvas,'Original studio photograph',oldUploadedId);
  marketingPicker.onPreview(({fail}) => fail(404,'The selected photo is no longer available.'));
  await picker(changingCanvas).locator('[data-media-picker-use]').click();
  await picker(changingCanvas).getByText('The selected photo is no longer available.',{exact:true}).waitFor();
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  assert.equal(await changingCanvas.locator('[data-marketing-source-choice]').isVisible(),false);
  await picker(changingCanvas).locator('[data-media-picker-cancel]').click();
  pass('Cancelling or failing a promotion image selection preserves the exact original photo, current draft, copy, layout, and scene settings');

  await changingButton('Choose from Media Library').click();
  await searchImage(changingCanvas,'Original studio photograph',oldUploadedId);
  let releaseCanvasSelection;
  const canvasSelectionGate = new Promise(resolve => { releaseCanvasSelection = resolve; });
  let finishCanvasSelection;
  const canvasSelectionSettled = new Promise(resolve => { finishCanvasSelection = resolve; });
  marketingPicker.onPreview(async ({body,ok,preview}) => {
    await canvasSelectionGate;
    try { await ok({preview,review_token:token(body)}); } catch {} finally { finishCanvasSelection(); }
  });
  const pendingCanvasSelection = marketingPicker.waitForAction('preview','marketing');
  await picker(changingCanvas).locator('[data-media-picker-use]').click();
  await pendingCanvasSelection;
  await picker(changingCanvas).locator('[data-media-picker-cancel]').click();
  releaseCanvasSelection();
  await canvasSelectionSettled;
  await marketingPicker.flush();
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  assert.equal(await changingCanvas.locator('[data-marketing-source-choice]').isVisible(),false);
  assert.equal(await changingCanvas.getByRole('img',{name:'Original product photo',exact:true}).getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  pass('Cancelling an in-flight promotion selection discards its late validation response and keeps the current design unchanged');

  await changingButton('Choose from Media Library').click();
  await searchImage(changingCanvas,'Original studio photograph',oldUploadedId);
  await picker(changingCanvas).locator('[data-media-picker-use]').click();
  await picker(changingCanvas).waitFor({state:'hidden'});
  await changingCanvas.locator('[data-marketing-source-choice]').waitFor();
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  assert.equal(marketingPicker.calls.at(-1).action,'preview');
  assert.equal(marketingPicker.calls.at(-1).product_id,106);
  assert.equal(marketingPicker.calls.at(-1).source_id,String(oldUploadedId));
  assert.equal(marketingPicker.calls.filter(call => call.action === 'create').length,1);
  assert.equal(marketingPicker.calls.filter(call => call.action === 'creative_open').at(-1).draft_id,initialMarketingDraft);
  assert.equal(await changingCanvas.getByRole('img',{name:'Original product photo',exact:true}).getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  assert.equal(await changingCanvas.locator('[data-marketing-source-choice] img').getAttribute('src'),root+`/media-library-file-v86.php?id=${oldUploadedId}`);
  assert.equal(marketingPicker.calls.some(call => ['creative_prepare','creative_generate','creative_publish'].includes(call.action)),false);
  await pickerScreenshot(changingCanvas,'marketing-staged-image-mobile.png');
  await changingButton('Review selected image').click();
  await marketingPicker.control('source').locator(`option[value="${oldUploadedId}"]`).waitFor({state:'attached'});
  assert.equal(await marketingPicker.control('product').inputValue(),'106');
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  await marketingPicker.button('Close').click();
  await marketingPicker.dialog.waitFor({state:'hidden'});
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  assert.equal(marketingPicker.calls.filter(call => call.action === 'creative_open').at(-1).draft_id,initialMarketingDraft);
  assert.equal(await changingCanvas.locator('[data-marketing-source-choice]').isVisible(),true);
  pass('Use image stages a replacement for review while the old promotion stays intact, including when the Workbench review is cancelled');

  await changingButton('Review selected image').click();
  await marketingPicker.control('source').locator(`option[value="${oldUploadedId}"]`).waitFor({state:'attached'});
  marketingPicker.onPreview(({body,ok,preview}) => ok({preview:{...preview,revision:'d'.repeat(64)},review_token:token(body)}));
  await marketingPicker.button('Review source').click();
  await marketingPicker.dialog.getByText('This source changed after you selected it. Reload the source to review its current version.',{exact:true}).waitFor();
  assert.equal(await marketingPicker.button('Save private marketing draft').isVisible(),false);
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  await marketingPicker.button('Close').click();
  pass('A staged image retains its validated revision, so a changed photo cannot silently become a newly approved source');

  await changingButton('Review selected image').click();
  await marketingPicker.control('source').locator(`option[value="${oldUploadedId}"]`).waitFor({state:'attached'});
  await marketingPicker.button('Review source').click();
  await marketingPicker.button('Save private marketing draft').waitFor();
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  await marketingPicker.button('Save private marketing draft').click();
  await marketingPicker.dialog.waitFor({state:'hidden'});
  await changingButton('Review graphic settings').waitFor();
  await marketingPickerPage.waitForFunction(id => document.querySelector('[data-marketing-canvas] img[alt="Original product photo"]')?.src.endsWith('id='+id),oldUploadedId);
  assert.deepEqual(await canvasSettings(changingCanvas),preservedSettings);
  const replacedDraft = marketingPicker.calls.filter(call => call.action === 'creative_open').at(-1);
  assert.notEqual(replacedDraft.draft_id,initialMarketingDraft);
  assert.equal(replacedDraft.product_id,106);
  assert.equal(replacedDraft.source_id,String(oldUploadedId));
  const replacementCreate = marketingPicker.calls.filter(call => call.action === 'create').at(-1);
  assert.equal(replacementCreate.source_id,String(oldUploadedId));
  assert.equal(replacementCreate.product_id,106);
  assert.equal(replacementCreate.confirmed,true);
  assert.equal(replacementCreate.review_token,token(replacementCreate));
  assert.equal(marketingPicker.calls.some(call => ['creative_prepare','creative_generate','creative_publish'].includes(call.action)),false);
  assert.deepEqual(marketingPicker.uploads,[]);
  assert.deepEqual(marketingPicker.errors,[]);
  assert.equal(await marketingPickerPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
  pass('Explicit reviewed creation switches to a separate exact-source private draft and carries every entered design field without generating, uploading, or publishing');

  const imageHandoffPage = await browser.newPage();
  const imageHandoff = await fixture(imageHandoffPage,{largeLibrary:true});
  const savedMarketingImage = libraryImages.find(item => item.id === oldGeneratedId);
  const handoffChecks = await imageHandoffPage.evaluate(asset => {
    const open = (image = asset, changes = {}) => window.VP3InitiativeWorkbench.openMarketingImage(image,
      {ownerKey:'12',agentId:7,conversationId:42,isCurrent:() => true,...changes});
    const rejected = [open(asset,{ownerKey:'99'}),open(asset,{agentId:8}),open(asset,{conversationId:43}),
      open(asset,{isCurrent:() => false}),open({...asset,id:8}),open({...asset,available:false}),
      open({...asset,file_size:4*1024*1024+1}),open({...asset,url:'https://foreign.test/media-library-file-v86.php?id=7'}),
      open({...asset,url:'/unexpected/media-library-file-v86.php?id=7'})];
    window.VP3ParticipantContinuity = {selectedContext:() => ({participant_id:9})};
    rejected.push(open());
    delete window.VP3ParticipantContinuity;
    return {rejected,opened:open()};
  },savedMarketingImage);
  assert.deepEqual(handoffChecks.rejected,Array(10).fill(false));
  assert.equal(handoffChecks.opened,true);
  await imageHandoff.control('source').locator(`option[value="${oldGeneratedId}"]`).waitFor({state:'attached'});
  assert.equal(await imageHandoff.control('tool').inputValue(),'marketing');
  assert.equal(await imageHandoff.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await imageHandoff.control('product').inputValue(),'');
  assert.equal(await imageHandoff.button('Review source').isDisabled(),true);
  assert.deepEqual(imageHandoff.calls.map(call => call.action),['sources']);
  assert.deepEqual(imageHandoff.uploads,[]);
  await imageHandoff.control('product').selectOption('106');
  await imageHandoff.button('Review source').click();
  await imageHandoff.button('Save private marketing draft').waitFor();
  assert.equal(imageHandoff.calls.at(-1).source_id,String(oldGeneratedId));
  assert.equal(imageHandoff.calls.at(-1).product_id,106);
  assert.equal(imageHandoff.calls.some(call => call.action === 'create'),false);
  pass('Use in Marketing opens the exact saved image only for a current private account/Agent/Chat and requires a product plus source review');

  const firstProductPage = await browser.newPage();
  const firstProduct = await fixture(firstProductPage,{largeLibrary:true,products:[]});
  assert.equal(await firstProductPage.evaluate(asset => window.VP3InitiativeWorkbench.openMarketingImage(asset,
    {ownerKey:'12',agentId:7,conversationId:42,isCurrent:() => true}),savedMarketingImage),true);
  await firstProduct.control('source').locator(`option[value="${oldGeneratedId}"]`).waitFor({state:'attached'});
  assert.equal(await firstProduct.control('product').locator('option').count(),1);
  await firstProduct.dialog.getByText('Create a product listing first, then continue to its promotion from the saved listing.',{exact:true}).waitFor();
  assert.equal(await firstProduct.button('Create product draft from this image').isEnabled(),true);
  assert.equal(await firstProduct.button('Review source').isDisabled(),true);
  assert.deepEqual(firstProduct.calls.map(call => call.action),['sources']);
  await firstProduct.button('Create product draft from this image').click();
  await firstProduct.control('source').locator(`option[value="${oldGeneratedId}"]`).waitFor({state:'attached'});
  assert.equal(await firstProduct.control('tool').inputValue(),'product');
  assert.equal(await firstProduct.control('source').inputValue(),String(oldGeneratedId));
  assert.equal(await firstProduct.control('selected-image').locator('img').getAttribute('src'),root+`/media-library-file-v86.php?id=${oldGeneratedId}`);
  assert.deepEqual(firstProduct.calls.map(call => call.action),['sources','sources']);
  await firstProduct.button('Review source').click();
  await firstProduct.button('Create private draft').waitFor();
  assert.equal(firstProduct.calls.at(-1).tool,'product');
  assert.equal(firstProduct.calls.at(-1).source_id,String(oldGeneratedId));
  assert.equal(firstProduct.calls.some(call => call.action === 'create'),false);
  assert.deepEqual(firstProduct.uploads,[]);
  pass('Use in Marketing with no products offers the existing Product draft flow and retains the exact chosen image through its separate source review');

  await firstProduct.button('Close').click();
  await firstProductPage.evaluate(() => window.VP3InitiativeWorkbench.open('marketing'));
  await firstProduct.control('source').locator('option[value="160"]').waitFor({state:'attached'});
  assert.equal(await firstProduct.button('Choose from Media Library').isEnabled(),true);
  await firstProduct.button('Choose from Media Library').click();
  await searchImage(firstProduct.dialog,'Original studio photograph',oldUploadedId);
  await picker(firstProduct.dialog).locator('[data-media-picker-use]').click();
  await picker(firstProduct.dialog).waitFor({state:'hidden'});
  assert.equal(firstProduct.calls.at(-1).action,'preview');
  assert.equal(firstProduct.calls.at(-1).tool,'product');
  assert.equal(firstProduct.calls.at(-1).source_id,String(oldUploadedId));
  assert.equal(await firstProduct.control('source').inputValue(),String(oldUploadedId));
  assert.equal(await firstProduct.button('Create product draft from this image').isEnabled(),true);
  assert.equal(await firstProduct.button('Review source').isDisabled(),true);
  assert.equal(firstProduct.calls.some(call => call.action === 'create'),false);
  assert.deepEqual(firstProduct.errors,[]);
  pass('An empty product catalog still permits visual image selection through read-only Product validation and an explicit product-draft handoff');

  const withoutPickerPage = await browser.newPage();
  const withoutPicker = await fixture(withoutPickerPage,{pickerAvailable:false});
  await withoutPickerPage.evaluate(() => window.VP3InitiativeWorkbench.open('product','31'));
  await withoutPicker.control('source').locator('option[value="31"]').waitFor({state:'attached'});
  assert.equal(await withoutPicker.button('Choose from Media Library').isDisabled(),true);
  await withoutPicker.button('Review source').click();
  await withoutPicker.button('Create private draft').waitFor();
  assert.deepEqual(withoutPicker.calls.map(call => call.action),['sources','preview']);
  assert.deepEqual(withoutPicker.errors,[]);
  pass('The original Product source dropdown and review remain usable if the shared picker runtime is unavailable');

  console.log(`CHAT_INITIATIVE_WORKBENCH_BROWSER=PASS (${cases} cases; real renderer and canonical image card, controlled API)`);
} finally {
  await browser.close();
}
