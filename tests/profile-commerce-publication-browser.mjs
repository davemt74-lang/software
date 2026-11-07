import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {spawn, spawnSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../', import.meta.url));
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-listing-publication-'));
const php = process.env.VP3_PHP_BINARY || 'php';
const fixture = path.join(root, 'tests/fixtures/profile-commerce-publication-browser.php');
const env = {...process.env, VP3_PUBLICATION_REPOSITORY:root, VP3_PUBLICATION_WEB_ROOT:tmp};
const productPath = '/fixture-seller/product/handmade-blue-mug';

const invoke = action => {
  const result = spawnSync(php, [fixture, action], {env, encoding:'utf8'});
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
};
const formValues = form => form.evaluate(element => Object.fromEntries(new FormData(element)));
const actionForm = (container, action) => container.locator(`form:has(input[name="action"][value="${action}"])`);
const fill = (form, name, value) => form.locator(`[name="${name}"]`).fill(value);
const select = (form, name, value) => form.locator(`[name="${name}"]`).selectOption(value);
const submit = form => form.locator('button[type="submit"]').click();
let server, browser, logs = '';
try {
  for (const dir of ['includes', 'sessions']) fs.mkdirSync(path.join(tmp, dir));
  for (const file of fs.readdirSync(path.join(root, 'includes'))) {
    if (!['bootstrap.php','workspace-sidebar-v82.php','member-header.php'].includes(file)) {
      fs.symlinkSync(path.join(root, 'includes', file), path.join(tmp, 'includes', file));
    }
  }
  fs.symlinkSync(fixture, path.join(tmp, 'includes/bootstrap.php'));
  fs.writeFileSync(path.join(tmp, 'includes/workspace-sidebar-v82.php'),
    '<link rel="stylesheet" href="/profile-commerce-products-shell-v1310.css"><aside class="chat-sidebar">Fixture workspace</aside>');
  fs.writeFileSync(path.join(tmp, 'includes/member-header.php'), '<header class="chat-header">Profile Commerce</header>');
  for (const file of ['profile-commerce-products.php','profile-commerce-product.php','profile-product-photo.php',
    'media-library-file-v86.php','chat.css','profile-commerce-products-shell-v1310.css']) {
    fs.copyFileSync(path.join(root, file), path.join(tmp, file));
  }
  fs.writeFileSync(path.join(tmp, 'router.php'), `<?php
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/(?:private/|sessions/|publication\\.sqlite)~', $route)) { http_response_code(404); return; }
if ($route === '/fixture-seller/product/handmade-blue-mug') {
    $_GET['username'] = 'fixture-seller';
    $_GET['product'] = 'handmade-blue-mug';
    require __DIR__.'/profile-commerce-product.php';
    return;
}
return false;
`);
  const seed = invoke('init');
  const ownerPath = `/profile-commerce-products.php?workspace_product=${seed.product_id}`;
  const originalPath = '/media-library-file-v86.php?id=51';
  const listingPhotoPath = `/profile-product-photo.php?username=fixture-seller&product=handmade-blue-mug&listing=${seed.product_id}`;
  const initial = invoke('state');
  assert.equal(initial.products[0].is_active, 0);
  assert.equal(initial.products[0].price_cents, 0);
  assert.equal(JSON.parse(initial.products[0].metadata_json).source_content_sha256, seed.source_hash);

  const reservation = net.createServer();
  await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  server = spawn(php, ['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp,path.join(tmp,'router.php')], {
    env, stdio:['ignore','ignore','pipe'],
  });
  server.stderr.on('data', data => logs += data);
  let ready = false;
  for (let i=0; i<80; i++) {
    try {
      const response = await fetch(base + ownerPath, {headers:{'X-Fixture-Owner':'1'}});
      if (response.ok) { ready = true; break; }
    } catch {}
    await new Promise(resolve => setTimeout(resolve,30));
  }
  assert.ok(ready, logs);
  assert.equal((await fetch(base + ownerPath)).status, 403);
  assert.equal((await fetch(base + productPath)).status, 404);
  assert.equal((await fetch(base + listingPhotoPath)).status, 404);
  assert.equal((await fetch(base + originalPath)).status, 403);

  browser = await chromium.launch({
    headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
  });
  const owner = await browser.newContext({viewport:{width:390,height:740},isMobile:true,hasTouch:true,extraHTTPHeaders:{'X-Fixture-Owner':'1'}});
  const page = await owner.newPage();
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const ownerResponse = await page.goto(base + ownerPath);
  assert.match(ownerResponse?.headers()['cache-control'] ?? '', /no-store/);
  const draft = page.locator('#product-listing-draft');
  const previewForm = actionForm(draft, 'preview_initiative_publication');
  await previewForm.waitFor();
  const source = draft.locator(`img[src="${originalPath}"]`);
  await source.waitFor();
  await page.waitForFunction(selector => document.querySelector(selector)?.naturalWidth === 32, `img[src="${originalPath}"]`);
  assert.equal(await draft.locator('[name="title"]').inputValue(), 'Handmade blue mug');
  assert.equal(await previewForm.locator('[name="price"]').inputValue(), '');
  assert.equal(await previewForm.locator('[name="show_listing_photo"]').isChecked(), false);
  const other = await browser.newContext({extraHTTPHeaders:{'X-Fixture-Owner':'2'}});
  const otherPage = await other.newPage();
  assert.equal((await otherPage.goto(base + ownerPath))?.status(), 200);
  assert.equal(await otherPage.locator('#product-listing-draft').count(), 0);
  assert.equal(await otherPage.getByText('Handmade blue mug',{exact:true}).count(), 0);
  assert.equal((await other.request.get(base + originalPath)).status(), 404);
  console.log('PASS the mobile owner sees the exact private draft and photo while another owner and visitors cannot');

  await fill(previewForm,'price','42.50');
  await previewForm.locator('[name="currency"]').selectOption('USD');
  await select(previewForm,'product_type','physical');
  await select(previewForm,'fulfillment_type','local_pickup');
  await select(previewForm,'provider_choice',String(seed.connection_id));
  await fill(previewForm,'profile_slug','handmade-blue-mug');
  await fill(previewForm,'profile_cta','Buy the mug');
  await fill(previewForm,'hold_minutes','45');
  await fill(previewForm,'cancellation_policy','Please contact the seller about returns.');
  await previewForm.locator('[name="show_listing_photo"]').check();
  const previewFields = await formValues(previewForm);
  assert.equal(previewFields.product_id,String(seed.product_id));
  assert.equal(previewFields.owner_key,'1');
  assert.ok(previewFields.csrf_token);
  const rejectedCsrf = await owner.request.post(base + ownerPath, {form:{...previewFields,csrf_token:'incorrect'}});
  assert.doesNotMatch(await rejectedCsrf.text(), /id="product-listing-publication-review"/);
  assert.deepEqual(invoke('state'), initial);

  await submit(previewForm);
  const review = page.locator('#product-listing-publication-review');
  const confirmForm = actionForm(review, 'confirm_initiative_publication');
  await confirmForm.waitFor();
  await review.locator(`img[src="${originalPath}"]`).waitFor();
  const reviewText = await review.innerText();
  assert.match(reviewText,/Handmade blue mug/);
  assert.match(reviewText,/42\.50/);
  assert.match(reviewText,/Local pickup/i);
  assert.match(reviewText,/Stripe/i);
  assert.match(reviewText,/Please contact the seller about returns/);
  assert.equal(await confirmForm.locator('[name="confirm_publish"]').isChecked(),false);
  const approval = await formValues(confirmForm);
  assert.equal(approval.product_id,String(seed.product_id));
  assert.equal(approval.provider_mode,'fixed');
  assert.equal(approval.fixed_connection_id,String(seed.connection_id));
  assert.equal(approval.show_listing_photo,'1');
  assert.ok(approval.review_token);
  assert.deepEqual(invoke('state'), initial);
  assert.equal((await fetch(base + productPath)).status,404);
  assert.equal((await fetch(base + listingPhotoPath)).status,404);
  console.log('PASS signed owner review previews the terms, payment provider and source without activating a product');

  for (const fields of [
    approval,
    {...approval, confirm_publish:'1',price:'1.00'},
    {...approval, confirm_publish:'1',provider_mode:'guest_choice',fixed_connection_id:'0'},
    {...approval, confirm_publish:'1',show_listing_photo:'0'},
    {...approval, confirm_publish:'1',csrf_token:'incorrect'},
  ]) {
    const response = await owner.request.post(base + ownerPath,{form:fields,maxRedirects:0});
    assert.doesNotMatch(await response.text(), /id="product-listing-published"/);
    assert.deepEqual(invoke('state'), initial);
  }
  const otherToken = await otherPage.locator('input[name="csrf_token"]').first().inputValue();
  const forbidden = await other.request.post(base + ownerPath,{
    form:{...approval,csrf_token:otherToken,owner_key:'2',confirm_publish:'1'},maxRedirects:0,
  });
  assert.equal(forbidden.status(), 200);
  const rejectedText = await forbidden.text();
  assert.doesNotMatch(rejectedText,/id="product-listing-published"|Handmade blue mug/);
  assert.deepEqual(invoke('state'),initial);
  const freshSession = await browser.newContext({extraHTTPHeaders:{'X-Fixture-Owner':'1'}});
  const freshPage = await freshSession.newPage();
  await freshPage.goto(base + ownerPath);
  const freshToken = await actionForm(freshPage.locator('#product-listing-draft'), 'preview_initiative_publication')
    .locator('input[name="csrf_token"]').inputValue();
  assert.notEqual(freshToken,approval.csrf_token);
  const wrongSession = await freshSession.request.post(base + ownerPath,{
    form:{...approval,csrf_token:freshToken,confirm_publish:'1'},maxRedirects:0,
  });
  assert.doesNotMatch(await wrongSession.text(), /id="product-listing-published"/);
  assert.deepEqual(invoke('state'),initial);
  console.log('PASS missing approval, tampered terms, invalid CSRF, and cross-account or cross-session replay leave the draft private');

  await confirmForm.locator('[name="confirm_publish"]').check();
  await submit(confirmForm);
  const published = page.locator('#product-listing-published');
  await published.waitFor();
  assert.equal(await published.getByRole('link',{name:/open.*listing|view.*listing/i}).getAttribute('href'),productPath);
  const canvas = await published.getByRole('link',{name:/design promotion/i}).getAttribute('href');
  const params = new URL(canvas,base).searchParams;
  assert.equal(params.get('agent'),'7');
  assert.equal(params.get('conversation_id'),'11');
  assert.equal(params.get('marketing_product'),String(seed.product_id));
  assert.equal(params.get('marketing_source'),'51');
  const final = invoke('state');
  assert.equal(final.products.length,1);
  const item = final.products[0];
  const metadata = JSON.parse(item.metadata_json);
  assert.equal(item.id,seed.product_id);
  assert.equal(item.is_active,1);
  assert.equal(item.title,'Handmade blue mug');
  assert.equal(item.price_cents,4250);
  assert.equal(item.fulfillment_type,'local_pickup');
  assert.equal(item.fixed_connection_id,seed.connection_id);
  assert.equal(metadata.profile_visibility,'public');
  assert.equal(metadata.public_listing_photo,true);
  assert.equal(String(metadata.source_asset_id),'51');
  assert.equal(metadata.source_content_sha256,seed.source_hash);
  assert.equal(final.orders,0);
  assert.equal(final.promotions,0);
  const retried = await owner.request.post(base + ownerPath,{form:{...approval,confirm_publish:'1'}});
  assert.match(await retried.text(), /id="product-listing-published"/);
  assert.deepEqual(invoke('state'),final);
  console.log('PASS approval publishes the same product and photo once and carries the exact product, photo, Agent and Chat into Canvas');

  const visitor = await browser.newPage({viewport:{width:390,height:740},isMobile:true});
  const publicResponse = await visitor.goto(base + productPath);
  assert.equal(publicResponse?.status(),200);
  await visitor.getByRole('heading',{name:'Handmade blue mug'}).waitFor();
  assert.ok(await visitor.getByRole('button',{name:'Buy the mug',exact:true}).isVisible());
  assert.match(await visitor.locator('.pcp-price').innerText(),/42\.50/);
  assert.equal(await visitor.getByRole('img',{name:'Handmade blue mug'}).getAttribute('src'),listingPhotoPath);
  const photo = await fetch(base + listingPhotoPath);
  assert.equal(photo.status,200);
  assert.equal(createHash('sha256').update(Buffer.from(await photo.arrayBuffer())).digest('hex'),seed.source_hash);
  assert.ok(!(await visitor.content()).includes(originalPath));
  assert.deepEqual(errors,[]);
  console.log('PASS the anonymous visitor sees the approved listing and existing checkout for the same product');
  console.log('PROFILE_COMMERCE_PUBLICATION_BROWSER=PASS');
} catch (error) {
  if (logs) console.error(logs.slice(-6000));
  throw error;
} finally {
  if (browser) await browser.close();
  if (server) {
    server.kill();
    await new Promise(resolve => server.exitCode !== null || server.signalCode !== null ? resolve() : server.once('exit',resolve));
  }
  fs.rmSync(tmp,{recursive:true,force:true});
}
