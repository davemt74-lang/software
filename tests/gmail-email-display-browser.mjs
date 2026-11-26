import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

const message = JSON.parse(execFileSync(process.env.PHP_BIN || 'php',['tests/fixtures/gmail-email-display-v1.php'],{encoding:'utf8'}));
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const page = await browser.newPage({viewport:{width:1280,height:900}});
page.setDefaultTimeout(10000);
const errors = [],network = [];
page.on('pageerror',error => errors.push(error.message));
await page.route('https://read.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:20px;background:#f3f5f8;color:rgb(17,17,17);font:16px Arial,sans-serif}main{max-width:790px;margin:auto}#outside{font-size:16px}#mail{background:white;padding:20px;border-radius:12px;box-sizing:border-box}</style>
<main><h1 id="outside">Agent Chat</h1><section id="mail"></section></main>`}));
await page.route('https://images.example.test/**',route => {
  network.push(route.request().url());
  return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+i7iQAAAAASUVORK5CYII=','base64')});
});
await page.route('https://leak.example.test/**',route => {network.push(route.request().url());return route.abort();});
await page.route('https://read.test/private-action',route => {network.push(route.request().url());return route.abort();});

try {
  await page.goto('https://read.test/');
  await page.addStyleTag({content:await fs.readFile('gmail-email-display-v1.css','utf8')});
  await page.addScriptTag({content:await fs.readFile('gmail-email-display-v1.js','utf8')});
  assert.equal(await page.evaluate(message => {
    window.EMAIL_ATTACK=false;window.emailLinks=[];
    return window.VP3GmailEmailDisplayV1.mount(document.getElementById('mail'),message,{onLink:(event,url) => {event.preventDefault();window.emailLinks.push(url);}});
  },message),true);
  const frame = page.frameLocator('.vp3-gmail-email-frame');
  await frame.locator('h1').waitFor();
  assert.equal(await frame.locator('h1').textContent(),'Friday’s project update');
  assert.equal(await frame.locator('h1').evaluate(el=>getComputedStyle(el).color),'rgb(123, 39, 124)');
  assert.equal(await frame.locator('table th').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(234, 216, 236)');
  assert.equal(await frame.locator('script,form,input,iframe').count(),0);
  assert.equal(await frame.locator('img[src]').count(),0,'Sender images stay inert until explicitly requested.');
  assert.deepEqual(network,[],'Opening formatted mail makes no network requests from email content.');
  assert.equal(await page.evaluate(()=>window.EMAIL_ATTACK),false);
  assert.equal(await page.locator('#outside').evaluate(el=>getComputedStyle(el).color),'rgb(17, 17, 17)','Email CSS stays inside its document.');
  assert.equal(await page.locator('.vp3-gmail-email-frame').getAttribute('sandbox'),'allow-same-origin allow-popups allow-popups-to-escape-sandbox');
  await frame.getByRole('link',{name:'Open project details'}).click();
  assert.deepEqual(await page.evaluate(()=>window.emailLinks),['https://project.example.test/review']);
  await page.getByRole('button',{name:'Plain text',exact:true}).click();
  assert.equal(await page.locator('.vp3-gmail-email-frame').count(),0);
  assert.match(await page.locator('.vp3-gmail-email-plain').textContent(),/plain-text version/);
  await page.getByRole('button',{name:'Formatted',exact:true}).click();
  await frame.locator('h1').waitFor();
  const imageResponse = page.waitForResponse('https://images.example.test/banner.png');
  await page.getByRole('button',{name:'Load remote images',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('.vp3-gmail-email-frame')?.contentDocument?.querySelector('img[src]'));
  await imageResponse;
  assert.deepEqual(network,['https://images.example.test/banner.png'],'Only the reviewed public image is loaded; CSS, scripts and local requests remain blocked.');
  assert.equal(await frame.locator('img[alt="Blocked local request"]').getAttribute('src'),null);
  const directory = process.env.VP3_EMAIL_DISPLAY_SCREENSHOTS;
  if (directory) {await fs.mkdir(directory,{recursive:true});await page.screenshot({path:path.join(directory,'styled-email-desktop.png'),fullPage:true});}
  await page.setViewportSize({width:390,height:844});
  await page.waitForFunction(()=>{
    const el=document.querySelector('.vp3-gmail-email-frame');
    return el?.contentDocument?.body && el.clientHeight >= el.contentDocument.body.scrollHeight;
  });
  const dimensions = await page.locator('.vp3-gmail-email-frame').evaluate(el=>({width:el.clientWidth,inner:el.contentDocument.documentElement.scrollWidth,height:el.clientHeight,innerHeight:el.contentDocument.body.scrollHeight}));
  assert.ok(dimensions.inner<=dimensions.width+1,JSON.stringify(dimensions));
  assert.ok(dimensions.innerHeight<=dimensions.height,JSON.stringify(dimensions));
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  if (directory) await page.screenshot({path:path.join(directory,'styled-email-phone.png'),fullPage:true});
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  assert.equal(await page.locator('.vp3-gmail-email-frame').count(),0,'Private formatted content is removed when Chat changes.');
  const chatSource = await fs.readFile('chat.js','utf8');
  const privateBinding = chatSource.match(/  function bindPrivateGmailDisplayV1\(element, data\) \{[\s\S]*?\n  \}/)?.[0];
  assert.ok(privateBinding,'The private Chat presentation binding is available.');
  await page.addScriptTag({content:`(() => {const conversationId=42,cfg={agentId:7};${privateBinding}\nwindow.fixturePrivateGmailBinding=bindPrivateGmailDisplayV1;})();`});
  await page.evaluate(message => {
    const reply=document.createElement('article');
    reply.id='private-chat-email';reply.innerHTML='<div class="message-text">Private result</div>';
    document.getElementById('mail').append(reply);
    window.fixturePrivateGmailBinding(reply,{private_email_read:true,conversation_id:42,agent_id:7,
      gmail_message_display_v1:{...message,answered_in_gmail:true}});
  },message);
  assert.equal(await page.locator('#private-chat-email .vp3-private-gmail-display > p').last().textContent(),
    'You already replied to this message in Gmail.','Formatted private reads preserve the Gmail already-replied notice.');
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  assert.equal(await page.locator('.vp3-gmail-email-frame').count(),0,'The private Chat binding also releases its formatted content.');
  assert.deepEqual(errors,[]);
  console.log('Styled Gmail browser: original CSS, tables, Unicode, plain fallback, sandbox, deferred images, link receipt hook, mobile fit, already-replied notice and context cleanup passed.');
} finally { await browser.close(); }
