import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const script = readFileSync(new URL('../chat-settings-v237.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../chat-settings-v237.css', import.meta.url), 'utf8');
const browser = await chromium.launch({
  headless:true,
  args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
});
const page = await browser.newPage({viewport:{width:1280,height:800}});
page.setDefaultTimeout(5000);
const pageErrors = [];
page.on('pageerror', error => pageErrors.push(error.message));
const origin = 'https://vp3-pa3c.test';
const posts = [];
const setting = 'agent_assisted_phone_chat_enabled';
let ready = true;
let allowed = true;
let chat = {presence_mode:'online',social_chat_enabled:true,sound_enabled:true,agent_voice_enabled:true,[setting]:false};
let checks = 0;
const check = (condition, message) => {
  assert.ok(condition, message);
  checks++;
};

try {
  await page.route(`${origin}/**`, async route => {
    const request = route.request();
    const {pathname} = new URL(request.url());
    if (pathname === '/settings') {
      if (request.method() === 'POST') {
        const body = request.postDataJSON();
        posts.push(body);
        if (body.action !== 'save_chat' || body.csrf_token !== 'fixture') return route.fulfill({status:403});
        const {action,csrf_token,...settings} = body;
        chat = {...chat,...settings};
      }
      return route.fulfill({json:{ok:true,chat,agent_assisted_phone_chat_ready:ready,agent_assisted_phone_chat_allowed:allowed}});
    }
    if (pathname === '/chat-settings.js') return route.fulfill({contentType:'text/javascript',body:script});
    return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style><body><script>
      window.STONEFELLOW_CHAT_SETTINGS = {endpoint:'/settings',csrf:'fixture'};
      window.addEventListener('stonefellow:chat-settings-updated', event => window.lastSettings = event.detail);
      </script><script src="/chat-settings.js"></script></body>`});
  });
  await page.goto(origin);
  assert.deepEqual(pageErrors, [], 'Chat Settings loaded without a browser error');
  const launcher = page.getByRole('button',{name:/Chat settings ·/});
  const save = page.getByRole('button',{name:'Save settings'});
  const toggle = page.getByRole('checkbox',{name:/Agent-assisted phone chat/});
  const modal = page.getByRole('dialog',{name:'Chat Settings'});
  await launcher.click();
  await toggle.waitFor({state:'visible'});
  check(await toggle.count() === 1 && !(await toggle.isChecked()), 'the existing modal renders one opt-in defaulted off');
  await toggle.check();
  await save.click();
  await modal.waitFor({state:'hidden'});
  check(posts.at(-1)[setting] === true, 'an explicit enable submits an actual boolean');
  check(await page.evaluate(key => window.lastSettings[key], setting) === true, 'successful save publishes the new setting');

  await launcher.click();
  await toggle.waitFor({state:'visible'});
  check(await toggle.isChecked(), 'reopening the gear displays the stored preference');
  await save.click();
  await modal.waitFor({state:'hidden'});
  check(!Object.hasOwn(posts.at(-1),setting), 'unchanged settings omit the assisted call preference');

  allowed = false;
  await launcher.click();
  await page.waitForFunction(() => document.activeElement?.name === 'presence_mode');
  check(await toggle.isEnabled() && await toggle.isChecked(), 'revoked permission still allows a prior opt-in to be turned off');
  await toggle.uncheck();
  await save.click();
  await modal.waitFor({state:'hidden'});
  check(posts.at(-1)[setting] === false && chat[setting] === false, 'explicit disable is saved even with revoked permission');

  ready = false;
  allowed = true;
  await launcher.click();
  await page.waitForFunction(() => document.activeElement?.name === 'presence_mode');
  check(!(await toggle.isVisible()), 'the unupgraded control is absent from the usable modal');
  await save.click();
  await modal.waitFor({state:'hidden'});
  check(!Object.hasOwn(posts.at(-1),setting), 'an unupgraded server still receives the old save shape');
} finally {
  await browser.close();
}
console.log(`PROFILE_ASSISTED_PHONE_SETTINGS_PA3C_BROWSER=PASS (${checks} checks)`);
