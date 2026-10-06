import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = process.cwd();
const extension = path.join(root, 'browser-companion');
const manifest = JSON.parse(await fs.readFile(path.join(extension, 'manifest.json')));
assert.equal(manifest.version, '22.9.1');
assert.ok(manifest.permissions.includes('clipboardWrite') && manifest.permissions.includes('activeTab'));
assert.ok(!manifest.host_permissions.includes('<all_urls>'), 'Shipping extension keeps existing host scope');
const downloadSource = await fs.readFile(path.join(root, 'chrome-extension-download.php'), 'utf8');
for (const asset of ['local-screenshots.css','local-screenshots.js']) assert.ok(downloadSource.includes(`'${asset}'`), `Public ZIP includes ${asset}`);
const errors = [];
const server = http.createServer(async (req, res) => {
  const name = path.basename(new URL(req.url, 'http://localhost').pathname);
  if (name === 'homeserver') {res.end('<!doctype html><style>body{background:#eef2f6;font:24px Arial}article{background:white;padding:30px;margin:20px;border-radius:15px}</style><article><h1>HomeServer</h1><p>Agent Chat screenshot acceptance</p></article>'); return;}
  try {
    const file = await fs.readFile(path.join(extension, name || 'sidepanel.html'));
    res.setHeader('Content-Type', name.endsWith('.js') ? 'text/javascript' : name.endsWith('.css') ? 'text/css' : 'text/html');res.end(file);
  } catch (_) {res.statusCode = 404;res.end();}
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
let browser, integration;
const temp = await fs.mkdtemp(path.join(os.tmpdir(), 'vp3-local-screenshots-'));
try {
  browser = await chromium.launch({headless: true, ...(process.env.SCREENSHOT_CHROMIUM_EXECUTABLE ? {executablePath: process.env.SCREENSHOT_CHROMIUM_EXECUTABLE} : {})});
  const page = await browser.newPage({viewport: {width: 360, height: 900}});
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    window.calls = {captures: 0, saves: 0, copies: 0, regions: 0, publications: 0, revoked: 0};
    window.mode = '';
    window.queryCount = 0;
    const nativeRevoke = URL.revokeObjectURL;
    URL.revokeObjectURL = url => {window.calls.revoked++; nativeRevoke(url);};
    const png = () => {const c = document.createElement('canvas');c.width = 640;c.height = 360;const ctx = c.getContext('2d');ctx.fillStyle = '#eef2f6';ctx.fillRect(0,0,640,360);ctx.fillStyle = '#111827';ctx.font = '30px Arial';ctx.fillText('HomeServer · Agent Chat',25,70);return c.toDataURL('image/png');};
    window.chrome = {
      tabs: {query: async () => {window.queryCount++;return [{id: window.mode === 'switched' && window.queryCount % 2 === 0 ? 2 : 1, windowId: 7, url: 'http://127.0.0.1:8080/#chat'}];}, captureVisibleTab: async (id, options) => {
        window.calls.captures++;window.captureOptions = {id,options};
        if(window.mode === 'permission')throw Error('activeTab permission required');
        if(window.mode === 'delayed')await new Promise(resolve => {window.releaseCapture = resolve;});
        return window.mode === 'invalid' ? 'data:text/html,invalid' : png();
      }},
      runtime: {sendMessage: (message, callback) => {
        if(message.type === 'capture_region') {window.calls.regions++;return Promise.resolve(window.mode === 'cancelled' ? {ok:true,value:{cancelled:true}} : {ok:true,value:{data_url:png(),cancelled:false}});}
        if(message.type === 'share')window.calls.publications++;
        const result = {ok:true,value:message.type === 'state' ? {connected:false,config:{base_url:'https://vp3.me'},capabilities:[]} : null};
        if(callback){callback(result);return;}return Promise.resolve(result);
      }},
      downloads: {download: async options => {window.calls.saves++;window.saveOptions=options;if(window.mode==='save-failure')throw Error('Download cancelled');window.savedBytes=Array.from(new Uint8Array(await (await fetch(options.url)).arrayBuffer()));return 1;}}
    };
    Object.defineProperty(navigator, 'clipboard', {value:{write:async items=>{window.calls.copies++;if(window.mode==='copy-failure')throw Error('Clipboard blocked');window.copiedBytes=Array.from(new Uint8Array(await (await items[0].getType('image/png')).arrayBuffer()));}}});
  });
  await page.goto(base + '/sidepanel.html');
  assert.equal(await page.locator('#shareWorkspace').isVisible(), false, 'Cloud workspace remains gated while logged out');
  assert.equal(await page.locator('#localScreenshotPanel').isVisible(), true, 'Local screenshots work while logged out');
  assert.equal(await page.evaluate(() => window.calls.captures), 0, 'No capture on startup');
  await page.locator('#localCaptureVisible').click();
  await page.waitForFunction(() => !document.querySelector('#localScreenshotPreview').hidden);
  assert.equal(await page.locator('#localScreenshotSize').textContent(), '640 × 360 px · PNG');
  assert.deepEqual(await page.evaluate(() => window.captureOptions), {id:7,options:{format:'png'}});
  await page.locator('#localScreenshotSave').click();
  await page.waitForFunction(() => window.savedBytes);
  const saved = await page.evaluate(() => ({bytes:window.savedBytes, options:window.saveOptions}));
  assert.deepEqual(saved.bytes.slice(0,8), [137,80,78,71,13,10,26,10]);
  assert.equal(saved.options.saveAs, true);
  assert.match(saved.options.filename, /^VP3-Screenshot-.*\.png$/);
  await page.locator('#localScreenshotCopy').click();
  await page.waitForFunction(() => window.copiedBytes);
  assert.deepEqual(await page.evaluate(() => window.copiedBytes), saved.bytes, 'Copy retains exact PNG bytes');
  for (const mode of ['permission','invalid','switched']) {
    await page.evaluate(value => {window.mode=value;window.queryCount=0;}, mode);
    await page.locator('#localCaptureVisible').click();
    await page.waitForFunction(() => document.querySelector('#localScreenshotStatus').dataset.error === 'true');
    assert.equal(await page.locator('#localScreenshotPreview').isVisible(), true, 'Failure preserves the previous screenshot');
  }
  await page.evaluate(() => {window.mode='delayed';window.queryCount=0;});
  await page.locator('#localCaptureVisible').click();
  await page.waitForFunction(() => window.releaseCapture);
  const count = await page.evaluate(() => window.calls.captures);
  await page.locator('#localCaptureVisible').evaluate(node => node.click());
  assert.equal(await page.evaluate(() => window.calls.captures), count, 'Overlapping capture is blocked');
  await page.evaluate(() => {window.mode='';window.releaseCapture();});
  await page.waitForFunction(() => !document.querySelector('#localCaptureVisible').disabled);
  await page.evaluate(() => {window.mode='cancelled';});
  await page.locator('#localCaptureRegion').click();
  await page.waitForFunction(() => document.querySelector('#localScreenshotStatus').textContent.includes('cancelled'));
  await page.evaluate(() => {window.mode='';});
  await page.locator('#localCaptureRegion').click();
  await page.waitForFunction(() => document.querySelector('#localScreenshotStatus').textContent.includes('ready'));
  await page.evaluate(() => {window.mode='copy-failure';});
  await page.locator('#localScreenshotCopy').click();
  await page.waitForFunction(() => document.querySelector('#localScreenshotStatus').textContent.includes('Use Save PNG'));
  await page.evaluate(() => {window.mode='save-failure';});
  await page.locator('#localScreenshotSave').click();
  await page.waitForFunction(() => document.querySelector('#localScreenshotStatus').textContent.includes('could not be saved'));
  await page.locator('#localScreenshotClear').click();
  assert.equal(await page.locator('#localScreenshotPreview').isVisible(), false);
  assert.equal(await page.evaluate(() => window.calls.publications), 0, 'Local capture/save/copy never publishes');
  assert.ok(await page.evaluate(() => window.calls.revoked) >= 3, 'Replaced and cleared image URLs are released');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  assert.deepEqual(errors, []);
  console.log('PASS: logged-out full sidepanel; PNG preview/save/copy; no publication; cancellation; permission and image failures; tab race; capture single-flight; resource cleanup.');

  if (process.env.SCREENSHOT_EXTENSION_INTEGRATION === '1') {
    await browser.close();browser = null;
    const shippedProfile = path.join(temp, 'shipped-profile');
    const launch = ext => chromium.launchPersistentContext(ext === extension ? shippedProfile : path.join(temp,'capture-profile'), {
      channel:'chromium', headless:true, ignoreDefaultArgs:['--disable-extensions'], acceptDownloads:true,
      args:[`--disable-extensions-except=${ext}`,`--load-extension=${ext}`]
    });
    integration = await launch(extension);
    const worker = integration.serviceWorkers()[0] || await integration.waitForEvent('serviceworker');
    const shipped = await integration.newPage();
    await shipped.goto(worker.url().replace(/background.js$/, 'sidepanel.html'));
    assert.equal(await shipped.locator('#localScreenshotPanel').isVisible(), true, 'Shipping MV3 extension loads');
    await integration.close();

    // Positive native-API exercise needs a capture grant without a physical toolbar click.
    // Grant it only in a temporary test manifest; packaged host permissions remain unchanged.
    const fixture = path.join(temp, 'capture-extension');
    await fs.cp(extension, fixture, {recursive:true});
    await fs.writeFile(path.join(fixture,'manifest.json'), JSON.stringify({...manifest,host_permissions:[...manifest.host_permissions,'<all_urls>']}));
    integration = await launch(fixture);
    const nativeWorker = integration.serviceWorkers()[0] || await integration.waitForEvent('serviceworker');
    const panel = await integration.newPage();
    await panel.goto(nativeWorker.url().replace(/background.js$/, 'sidepanel.html'));
    const home = await integration.newPage();await home.goto(base+'/homeserver');await home.bringToFront();
    await panel.evaluate(() => document.querySelector('#localCaptureVisible').click());
    await panel.locator('#localScreenshotImage').waitFor({state:'visible'});
    const nativePng = await panel.evaluate(async () => Array.from(new Uint8Array(await (await fetch(document.querySelector('#localScreenshotImage').src)).arrayBuffer())));
    assert.deepEqual(nativePng.slice(0,8), [137,80,78,71,13,10,26,10]);
    const pngBuffer = Buffer.from(nativePng);
    const viewport = await home.evaluate(() => ({width:innerWidth,height:innerHeight}));
    const expectedCrop = `${Math.round(100*pngBuffer.readUInt32BE(16)/viewport.width)} × ${Math.round(80*pngBuffer.readUInt32BE(20)/viewport.height)}`;
    console.log('Native capture dimensions',pngBuffer.readUInt32BE(16),pngBuffer.readUInt32BE(20),'CSS viewport',viewport,'expected crop',expectedCrop);
    await panel.evaluate(() => {
      const download=chrome.downloads.download.bind(chrome.downloads);
      chrome.downloads.download=async options=>{const id=await download({...options,saveAs:false});window.nativeDownloadId=id;return id;};
      document.querySelector('#localScreenshotSave').click();
    });
    let complete = false;
    for (let attempt=0; attempt<100; attempt++) {
      complete = await panel.evaluate(async () => window.nativeDownloadId !== undefined && (await chrome.downloads.search({id:window.nativeDownloadId}))[0]?.state==='complete');
      if (complete) break;
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.equal(complete,true,'Native Chrome PNG download completes');
    const download = await panel.evaluate(async () => (await chrome.downloads.search({id:window.nativeDownloadId}))[0]);
    assert.deepEqual(Array.from(await fs.readFile(download.filename)), nativePng, 'Native Chrome download preserves captured PNG');
    await home.bringToFront();
    await panel.evaluate(() => document.querySelector('#localCaptureRegion').click());
    await home.locator('#vp3-region-capture-overlay').waitFor();
    await home.mouse.move(30,40);await home.mouse.down();await home.mouse.move(130,120);await home.mouse.up();
    try {
      await panel.locator('#localScreenshotSize').filter({hasText:expectedCrop}).waitFor({state:'visible'});
    } catch (error) {
      console.log('Region failure context',await panel.evaluate(() => ({status:document.querySelector('#localScreenshotStatus').textContent,size:document.querySelector('#localScreenshotSize').textContent})));
      throw error;
    }
    console.log('PASS: shipping MV3 extension loaded; real Chrome visible capture, exact PNG download and canonical region crop (temporary test-only capture grant).');
  }
} finally {
  if(browser)await browser.close();if(integration)await integration.close();
  await new Promise(resolve=>server.close(resolve));await fs.rm(temp,{recursive:true,force:true});
}
