import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../',import.meta.url);
const html = execFileSync(process.env.PHP_BINARY || 'php',[fileURLToPath(new URL('phone-location.php',root))],{encoding:'utf8'});
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
try {
  const page = await browser.newPage({viewport:{width:375,height:550},hasTouch:true,isMobile:true});
  page.setDefaultTimeout(5000);
  const device = {id:'d'.repeat(32),name:'Field phone',status:'pending',generation:1,allow_location:false,holder_can_save_places:false};
  const turns = [], sent = [], errors = [];
  page.on('pageerror',error => errors.push(error.message));
  await page.route('https://phone.test/**',async route => {
    const {pathname} = new URL(route.request().url());
    if (pathname === '/phone-location.php') return route.fulfill({contentType:'text/html',body:html});
    if (/^\/phone-[a-z0-9-]+\.(js|css)$/.test(pathname)) {
      return route.fulfill({contentType:pathname.endsWith('.js') ? 'text/javascript' : 'text/css',body:readFileSync(new URL(pathname.slice(1),root))});
    }
    if (pathname !== '/api/phone-location-v1.php') return route.fulfill({status:404});
    const q = route.request().postDataJSON();
    switch (q.action) {
      case 'status': return route.fulfill({json:{ok:true,device,owner_name:'Studio'}});
      case 'camera_status': return route.fulfill({json:{ok:true,camera:null}});
      case 'chat_status':
        assert.equal(q.device_id,device.id);
        return route.fulfill({json:{ok:true,turns}});
      case 'chat_send':
        assert.equal(q.device_id,device.id);
        sent.push(q);
        if (sent.length === 1) return route.abort(); // Lost before the server committed.
        turns.push({request_id:q.request_id,message:q.message,state:'replied',reply:'The camera is in Phone apps.'});
        return route.fulfill({json:{ok:true,turns}});
      default: return route.fulfill({status:400,json:{ok:false}});
    }
  });
  await page.goto('https://phone.test/phone-location.php');
  await page.locator('#phone-label').filter({hasText:'Field phone'}).waitFor();
  assert.equal(await page.locator('#phone-dock').isVisible(),false);
  device.status = 'approved';
  device.allow_location = true;
  const wake = () => page.evaluate(() => window.dispatchEvent(new Event('online')));
  await wake();
  const apps = page.getByRole('button',{name:'Phone apps'});
  await apps.click();
  assert.equal(await page.locator('#phone-app-save-place').isVisible(),false);
  await page.locator('#phone-app-camera').click();
  await page.locator('#phone-camera-close').click();
  assert.equal(await apps.getAttribute('aria-expanded'),'false');
  const input = page.getByRole('textbox',{name:'Message the connected agent'});
  await input.fill('Where is the <camera>?');
  await input.press('Enter');
  await page.getByRole('button',{name:'Retry delivery'}).click();
  await page.locator('#phone-chat-turns').getByText('The camera is in Phone apps.').waitFor();
  assert.equal(sent.length,2);
  assert.equal(sent[0].request_id,sent[1].request_id);
  assert.equal(sent[0].message,sent[1].message);
  assert.equal(await page.locator('.phone-bubble.mine').count(),1);
  await page.locator('#phone-location').evaluate(el => { el.scrollTop = 0; });
  const footer = await page.locator('#phone-dock').boundingBox();
  assert.ok(footer && Math.abs(footer.y + footer.height - 550) <= 2,'composer must stay at viewport bottom');
  device.status = 'revoked';
  await wake();
  await page.locator('#phone-dock').waitFor({state:'hidden'});
  assert.equal(await page.locator('.phone-bubble').count(),0);
  assert.deepEqual(errors,[]);
  console.log('PASS Paired phone apps, sticky composer, idempotent retry, scoped reply, and revocation.');
} finally {
  await browser.close();
}
