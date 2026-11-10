import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';

const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-media-page-'));
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});
const base = 'https://media-library.test';
const mediaRoute = '/api/media-library-v86.php';
const imageRoute = '/api/media-library-image-v1.php';
const imageUrl = id => '/media-library-file-v86.php?id=' + id;
const photo = {id: 17, title: '<Blue mug> Original', media_type: 'photo', mime_type: 'image/png', available: true, source: 'upload', url: imageUrl(17)};
const generated = {...photo, id: 18, title: 'Glazed mug', source: 'openai_image', url: imageUrl(18)};
const generationId = '6a5f910a-f994-4b37-a671-099c5954acde';
const checks = [];

try {
  fs.mkdirSync(path.join(temp, 'includes'));
  // The real shared shell hashes these assets while rendering its cache keys.
  // Keep its physical fixture complete even though unrelated browser APIs are mocked.
  for (const file of ['media-library.php', 'includes/main-sidebar.php', 'includes/member-header.php',
    'coding-canvas-sidebar-v1.css', 'coding-canvas-sidebar-v1.js',
    'member-agent-voice-menu.css', 'member-agent-voice-menu.js',
    'chat-notifications-drawer-v240.css', 'chat-notifications-drawer-v240.js']) {
    fs.copyFileSync(file, path.join(temp, file));
  }
  fs.writeFileSync(path.join(temp, 'includes/bootstrap.php'), `<?php
function current_user(){return ['id'=>1,'display_name'=>'Media owner'];}
function has_permission($key,$user){return $key==='chat.access';}
function media_studio_schema_ready(){return true;}
function system_agent_name(){return 'VP3';}
function e($s){return htmlspecialchars($s, ENT_QUOTES);}
function url($s){return $s;}
function csrf_token(){return 'media-fixture';}
function user_role_labels($u){return ['Member'];}
function member_navigation_section_label($key){return 'Workspace';}
function member_navigation_menu_links($u){return [
 ['key'=>'knowledge','label'=>'My Knowledge','url'=>'/knowledge.php','group'=>'identity'],
 ['key'=>'media_library','label'=>'Media Library','url'=>'/media-library.php','group'=>'identity'],
];}
function notification_recent($u,$n){return [];}
function notification_unread_count($u){return 0;}
function member_agent_voice_enabled($u){return false;}
`);
  for (const file of ['agent-orb.php', 'member-user-menu.php']) fs.writeFileSync(path.join(temp, 'includes', file), '');
  const html = execFileSync(process.env.VP3_PHP_BINARY || 'php', [path.join(temp, 'media-library.php')], {encoding: 'utf8'});
  const page = await browser.newPage({ viewport: { width: 1200, height: 850 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('about:blank');
  const png = Buffer.from(await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 12;
    canvas.height = 8;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#143C94';
    ctx.fillRect(0, 0, 6, 8);
    return canvas.toDataURL('image/png').split(',')[1];
  }), 'base64');
  let assets = [photo];
  let uploaded;
  let generation = null;
  let previews = 0;
  let submissions = 0;
  let imagePlan;
  let lastBrowse;
  let lastChunk;
  let finishMetadata;
  await page.route(base + '/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const reply = payload => route.fulfill({json: {ok: true, ...payload}});
    if (url.pathname === '/media-library.php') return route.fulfill({contentType: 'text/html; charset=UTF-8', body: html});
    if (url.pathname === '/media-library-file-v86.php') return route.fulfill({contentType: 'image/png', body: png});
    if (url.pathname === mediaRoute) {
      assert.equal(request.headers()['x-vp3-media-owner'], '1');
      if (request.method() === 'GET') {
        assert.equal(url.searchParams.get('action'), 'browse');
        lastBrowse = url.searchParams.get('q');
        const matches = assets.filter(asset => !lastBrowse || asset.title.toLowerCase().includes(lastBrowse.toLowerCase()));
        return reply({assets: matches, next_before: null});
      }
      const form = await new Response(request.postDataBuffer(), {headers: {'Content-Type': request.headers()['content-type']}}).formData();
      assert.equal(form.get('csrf_token'), 'media-fixture');
      const action = form.get('action');
      if (action === 'begin') {
        assert.equal(form.get('media_type'), 'photo');
        assert.equal(form.get('mime_type'), 'image/png');
        uploaded = {...photo, id: 19, title: form.get('title'), source: form.get('source'), url: imageUrl(19)};
        return reply({token: 'a'.repeat(32), chunk_size: 1048576});
      }
      assert.equal(form.get('token'), 'a'.repeat(32));
      if (action === 'chunk') {
        lastChunk = Buffer.from(await form.get('chunk').arrayBuffer());
        return reply({received: 1, total: 1});
      }
      assert.equal(action, 'finish');
      finishMetadata = JSON.parse(form.get('metadata_json'));
      assets = [uploaded, ...assets];
      return reply({asset: uploaded});
    }
    if (url.pathname === imageRoute) {
      const {action, csrf_token, owner_key, ...body} = request.postDataJSON();
      assert.equal(csrf_token, 'media-fixture');
      assert.equal(owner_key, '1');
      if (action === 'status') return reply({can_generate: true, message: '', generations: generation ? [generation] : []});
      if (action === 'preview') {
        previews++;
        imagePlan = body;
        return reply({plan: imagePlan, request_key: 'b'.repeat(32), review_token: 'review-token'});
      }
      if (action === 'submit') {
        submissions++;
        const {request_key, review_token, confirmed, ...plan} = body;
        assert.equal(request_key, 'b'.repeat(32));
        assert.equal(review_token, 'review-token');
        assert.equal(confirmed, true);
        assert.deepEqual(plan, imagePlan);
        generation = {id: generationId, status: 'queued', ...imagePlan};
        return reply({generation});
      }
      assert.equal(body.generation_id, generationId);
      if (action === 'tick') {
        generation = {...generation, status: 'completed', asset: generated};
        if (!assets.some(asset => asset.id === generated.id)) assets.unshift(generated);
      } else {
        assert.equal(action, 'get');
      }
      return reply({generation});
    }
    const file = url.pathname.slice(1);
    if (/^(?:media-library[^/]*|chat|chat-header-ui|member-shell-v77)\.(?:js|css)$/.test(file) && fs.existsSync(file)) {
      return route.fulfill({path: file, contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript'});
    }
    return route.fulfill({status: 204});
  });

  await page.goto(base + '/media-library.php');
  const sidebar = page.locator('[data-agent-primary-nav]');
  assert.equal(await sidebar.locator('a[data-vp3-nav-key="media_library"]').getAttribute('aria-current'), 'page');
  assert.deepEqual(await sidebar.locator('a').allTextContents(), ['◇Knowledge', '▧Media Library']);
  const gallery = page.locator('[data-media-assets]');
  await gallery.getByRole('heading', {name: photo.title}).waitFor();
  assert.equal(await gallery.locator('img').count(), 1);
  await page.getByRole('searchbox').fill('missing');
  await page.getByRole('button', {name: 'Search', exact: true}).click();
  await page.getByText('No saved media matches this view.').waitFor();
  assert.equal(lastBrowse, 'missing');
  await page.getByRole('searchbox').clear();
  await page.getByRole('button', {name: 'Search', exact: true}).click();
  await gallery.getByRole('button', {name: 'Edit image'}).waitFor();
  checks.push('main sidebar, active page, private gallery and search');

  await gallery.getByRole('button', {name: 'Edit image'}).click();
  const editor = page.locator('[data-media-editor]');
  const canvas = editor.locator('[data-image-editor-preview]');
  await canvas.waitFor();
  await editor.locator('[data-image-edit="rotate-right"]').click();
  await page.waitForFunction(() => {
    const canvas = document.querySelector('[data-image-editor-preview]');
    return canvas.width === 8 && canvas.height === 12;
  });
  await editor.locator('[name="crop"]').selectOption('square');
  await editor.locator('[data-image-edit="flip-horizontal"]').click();
  await editor.locator('[name="title"]').fill('Mug cover crop');
  await editor.locator('[data-image-editor-save]').click();
  await editor.getByText('Edited copy saved to your Media Library.').waitFor();
  assert.equal(uploaded.source, 'image_editor');
  assert.deepEqual(finishMetadata, {source_asset_id: photo.id});
  assert.deepEqual([lastChunk.readUInt32BE(16), lastChunk.readUInt32BE(20)], [8, 8]);
  await gallery.getByRole('heading', {name: uploaded.title}).waitFor();
  await gallery.getByRole('heading', {name: photo.title}).waitFor();
  checks.push('real canvas rotate, crop, flip, PNG upload, and original retained');

  await page.locator('[data-media-view="create"]').click();
  const creator = page.locator('[data-media-generation]');
  await creator.locator('[name="prompt"]').fill('A blue ceramic mug in soft morning light');
  await creator.locator('[name="title"]').fill(generated.title);
  await creator.locator('[name="size"]').selectOption('1536x1024');
  await creator.locator('[name="background"]').selectOption('transparent');
  await creator.locator('[data-media-generation-review]').click();
  await creator.locator('[data-media-generation-submit]').waitFor();
  assert.equal(previews, 1);
  assert.equal(submissions, 0);
  await creator.locator('[data-media-generation-submit]').click();
  await creator.locator('[data-media-generation-edit]').waitFor({timeout: 20000});
  assert.equal(submissions, 1);
  await page.reload();
  await page.locator('[data-media-view="create"]').click();
  await creator.locator('[data-media-generation-edit]').click();
  await editor.getByText(generated.title, {exact: false}).first().waitFor();
  assert.equal(submissions, 1);
  assert.equal(imagePlan.background, 'transparent');
  checks.push('prompt options, explicit review, job completion, reload, and exact image editor handoff');

  await page.setViewportSize({width: 375, height: 740});
  await page.getByRole('button', {name: 'Open VP3 navigation'}).click();
  await sidebar.getByRole('link', {name: 'Media Library'}).waitFor();
  await page.getByRole('button', {name: 'Close menu'}).click();
  const widths = await page.locator('.vp3-media-content').evaluate(node => ({
    scroll: node.scrollWidth, client: node.clientWidth,
    overflow: [...node.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > node.getBoundingClientRect().right + 1)
      .slice(0, 5).map(child => child.className || child.tagName)
  }));
  assert.ok(widths.scroll <= widths.client + 1, JSON.stringify(widths));
  assert.deepEqual(errors, []);
  checks.push('mobile sidebar and single-width workspace');
  for (const check of checks) console.log('PASS ' + check);
  console.log('MEDIA_LIBRARY_WORKSPACE_BROWSER=PASS');
} finally {
  await browser.close();
  fs.rmSync(temp, {recursive: true, force: true});
}
