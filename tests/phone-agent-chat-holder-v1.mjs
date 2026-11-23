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
  let chatReads = 0;
  let camera = null;
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
      case 'camera_status': return route.fulfill({json:{ok:true,camera}});
      case 'camera_stop':
        assert.equal(q.session_id,camera.session_id);
        assert.equal(q.requested_only,true);
        camera = null;
        return route.fulfill({json:{ok:true,camera:null}});
      case 'chat_status':
        chatReads++;
        assert.equal(q.device_id,device.id);
        return route.fulfill({json:{ok:true,turns}});
      case 'chat_send':
        assert.equal(q.device_id,device.id);
        sent.push(q);
        if (sent.length === 1) return route.abort(); // Lost before the server committed.
        const previous = turns.findIndex(turn => turn.request_id === q.request_id);
        const turn = {request_id:q.request_id,message:q.message,state:'replied',reply:'The camera is in Phone apps.'};
        if (sent.length === 3) Object.assign(turn,{state:'failed',reply:null,failure_message:'The agent is busy. Wait a moment, then retry this message.'});
        if (previous < 0) turns.push(turn);
        else turns[previous] = turn;
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
  const connection = page.getByRole('tab',{name:'Connection',exact:true});
  const chat = page.getByRole('tab',{name:'Chat',exact:true});
  const invitation = page.locator('#phone-camera');
  await wake();
  await connection.waitFor();
  assert.equal(await connection.getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false);
  assert.equal(chatReads,0,'the Connection tab does not request the private transcript');
  // Safari pans its visual viewport independently while focusing and closing the keyboard.
  // Offsetting the page by this value used to leave the top of the phone screen blank.
  await page.evaluate(() => {
    Object.defineProperty(window.visualViewport,'offsetTop',{configurable:true,get:()=>210});
    window.visualViewport.dispatchEvent(new Event('scroll'));
  });
  const header = await page.locator('.phone-header').boundingBox();
  assert.ok(header && header.y >= 0 && header.y < 10,'Connection starts at the visible top after viewport pan');
  await page.evaluate(() => { delete window.visualViewport.offsetTop; });
  camera = {device_id:device.id,session_id:'b'.repeat(32),state:'requested',expires_at:Math.floor(Date.now()/1000)+60};
  await wake();
  await invitation.getByRole('button',{name:'Decline'}).click();
  await page.getByRole('button',{name:'Dismiss camera message'}).click();
  await invitation.waitFor({state:'hidden'});
  assert.equal(await connection.getAttribute('aria-selected'),'true','incoming requests work on Connection');
  await connection.focus();
  await connection.press('ArrowRight');
  assert.equal(await chat.getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-controls').isVisible(),false);
  await page.locator('#phone-chat-empty').waitFor();
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
  await input.fill('Can I find it in Chat?');
  await input.press('Enter');
  await page.getByText('The agent is busy. Wait a moment, then retry this message.').waitFor();
  await page.getByRole('button',{name:'Retry reply'}).click();
  await page.waitForFunction(() => document.querySelectorAll('.phone-chat-retry').length === 0
    && document.querySelectorAll('.phone-bubble:not(.mine) small')[1]?.textContent === 'Agent');
  assert.equal(sent[2].request_id,sent[3].request_id);
  assert.equal(await page.locator('.phone-bubble.mine').count(),2);
  const footer = await page.locator('#phone-dock').boundingBox();
  assert.ok(footer && Math.abs(footer.y + footer.height - 550) <= 2,'composer must stay at viewport bottom');
  await input.fill('Unsent chat draft');
  await page.setViewportSize({width:375,height:335});
  await page.waitForFunction(() => Math.abs(document.querySelector('#phone-dock').getBoundingClientRect().bottom - 335) <= 2);
  await page.setViewportSize({width:375,height:550});
  await chat.focus();
  await chat.press('Home');
  assert.equal(await connection.getAttribute('aria-selected'),'true');
  assert.equal(await input.isVisible(),false);
  await connection.press('End');
  assert.equal(await chat.getAttribute('aria-selected'),'true');
  assert.equal(await input.inputValue(),'Unsent chat draft');
  await input.focus();
  camera = {device_id:device.id,session_id:'a'.repeat(32),state:'requested',expires_at:Math.floor(Date.now()/1000)+60};
  await wake();
  await invitation.getByText(/Studio requested this phone’s camera/).waitFor();
  assert.ok(await input.evaluate(el => el === document.activeElement),'incoming request preserves keyboard focus');
  assert.equal(await input.inputValue(),'Unsent chat draft');
  const accept = await invitation.locator('#phone-camera-start').boundingBox();
  assert.ok(accept && accept.y >= 0 && accept.y + accept.height < 550,'the accept action is visible without scrolling');
  await invitation.getByRole('button',{name:'Decline'}).click();
  await invitation.getByText('Camera request declined.').waitFor();
  await invitation.getByRole('button',{name:'Dismiss camera message'}).click();
  await invitation.waitFor({state:'hidden'});
  device.status = 'revoked';
  await wake();
  await page.locator('#phone-dock').waitFor({state:'hidden'});
  assert.equal(await page.locator('.phone-bubble').count(),0);
  assert.equal(await page.locator('#phone-chat-input').inputValue(),'');
  assert.equal(await chat.isVisible(),false);
  await page.locator('#phone-connection').waitFor();
  assert.deepEqual(errors,[]);
  console.log('PASS Connection and Chat tabs, mobile keyboard layout, incoming consent on both tabs, paired phone apps, draft recovery, scoped reply, and revocation.');
} finally {
  await browser.close();
}
