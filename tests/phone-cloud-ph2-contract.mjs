import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';

const read=path=>fs.readFileSync(path,'utf8');
let checks=0;
function check(name,condition){assert.ok(condition,name);checks++;console.log(`PASS ${name}`);}

const catalog=read('includes/subscription-schema.php');
const packages=read('admin/packages.php');
const domain=read('includes/phone-cloud-v100.php');
const member=read('api/phone-cloud-v100.php');
const page=read('phone.php');
const admin=read('admin/phone.php');
const menu=read('includes/member-user-menu.php');
const upgrade=read('upgrade.php');
check('subscription package editor inherits phone count, voice and SMS',
  ['phone.numbers','phone.voice','phone.sms'].every(key=>catalog.includes(`'${key}'`))
  &&packages.includes('subscription_capability_catalog()')
  &&packages.includes("$key==='phone.numbers'")
  &&packages.includes('monthly_price_cents'));
check('phone registries are separate from provider credentials',
  domain.includes('phone_cloud_numbers_v100')&&domain.includes('phone_cloud_requests_v100')&&admin.includes('ai_encrypt_secret'));
check('member phone assignment and requests enforce account-owned agent and package quotas',
  domain.includes('vp3_phone_agent_owned_v100')&&domain.includes('vp3_phone_package_v100')&&domain.includes('vp3_phone_quota_v100')
  &&domain.includes('owner_user_id=?')&&member.includes('can_register'));
check('carrier search is read-only and rate-limited',
  member.includes("$action==='search'")&&member.includes('count($attempts)>=6')&&domain.includes('AvailablePhoneNumbers/US/Local.json'));
check('purchase requires exact explicit approval, never retries uncertain sends',
  domain.includes("'PURCHASE '.$number")&&domain.includes('review_required')
  &&domain.includes("status=\\'purchasing\\'")&&domain.includes("status=\\'fulfilled\\' WHERE id=? AND status=\\'purchasing\\'"));
check('admin is privileged and cannot auto-enable calling or SMS',
  admin.includes("require_permission('users.manage')")&&domain.includes('sms_enabled=0,voice_enabled=0')
  &&admin.includes('No live sending is enabled'));
check('menu and standalone page use existing shared voice UI, without extra voice toggle',
  menu.includes('data-vp3-agent-phone-card')&&page.includes('includes/member-header.php')
  &&!page.includes('data-vp3-global-agent-voice'));
check('database upgrade includes phone schema',
  upgrade.includes('vp3_phone_schema_ready_v100')&&upgrade.includes('vp3_phone_ensure_schema_v100'));
check('phone quota counts all owned numbers, not the 20-row menu preview',
  domain.includes("SELECT COUNT(*) FROM phone_cloud_numbers_v100 WHERE owner_user_id=?")
  &&domain.includes('SELECT id FROM users WHERE id=? FOR UPDATE'));

// Set PHONE_BROWSER_TEST=1 after installing playwright + Chromium to run the
// live dropdown scenarios. The architecture contract runs without npm deps.
if (process.env.PHONE_BROWSER_TEST === '1') {
const {chromium}=await import('playwright');

let phone={number:{phone_e164:'+16025550111',agent_name:'Test Agent',status:'unconfigured'},package:{included:true},request:null};
const server=http.createServer(async(req,res)=>{
  const path=new URL(req.url,'http://local').pathname;
  res.setHeader('Cache-Control','no-store');
  if(path==='/member-agent-voice-menu.js'){
    res.setHeader('Content-Type','text/javascript');res.end(read('member-agent-voice-menu.js'));return;
  }
  if(path.startsWith('/api/')){
    res.setHeader('Content-Type','application/json');
    let result={ok:true};
    if(path.includes('phone-cloud')) result={ok:true,state:phone,provider_connected:true};
    else if(path.includes('user-agent-system'))result={ok:true,state:{agents:[{id:7,display_name:'Test Agent',is_active:1,is_default:1,voice_enabled:0}],system_voice:{}}};
    else if(path.includes('studio-voice-profile'))result={ok:true,state:{voice:{has_clone_binding:false,clone_enabled:false}}};
    else if(path.includes('chat-settings'))result={ok:true,agent_voice_allowed:true,chat:{agent_voice_enabled:true}};
    else if(path.includes('agent-voice-v117'))result={ok:true,ready:true,verified:true,voice_name:'Default'};
    else if(path.includes('agent-voice-settings'))result={ok:true,selected:{},voices:[]};
    res.end(JSON.stringify(result));return;
  }
  res.setHeader('Content-Type','text/html');
  res.end(`<!doctype html><html><body><div id="chatProfileMenu" data-agent-voice-menu-config data-user-id="1" data-csrf="test" data-phone-endpoint="/api/phone-cloud-v100.php" data-phone-register-url="/phone.php" data-pricing-url="/pricing.php"><button id="chatProfileButton">Profile</button><div id="chatProfileDropdown"><div class="chat-profile-summary"><label><input type="checkbox" data-agent-voice-toggle data-vp3-global-agent-voice></label></div></div></div><script src="/member-agent-voice-menu.js"></script></body></html>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  async function visit(state){phone=state;await page.goto(base);await page.waitForFunction(()=>!document.querySelector('[data-vp3-agent-phone-label]')?.textContent.includes('Checking'));}
  await visit({number:{phone_e164:'+16025550111',agent_name:'Test Agent',status:'unconfigured'},package:{included:true},request:null});
  check('assigned phone displays in the Agent + Voice dropdown',
    (await page.locator('[data-vp3-agent-phone-label]').innerText())==='+16025550111');
  check('assigned number link opens number management',
    (await page.locator('[data-vp3-agent-phone-link]').getAttribute('href'))==='/phone.php'
    &&(await page.locator('[data-vp3-agent-phone-link]').innerText())==='Manage number');
  check('phone card does not duplicate global Agent Voice toggle',
    (await page.locator('[data-vp3-global-agent-voice]').count())===1);
  await visit({number:null,package:{included:true},request:{phone_e164:'+16025550222'}});
  check('pending number request is visible',
    (await page.locator('[data-vp3-agent-phone-label]').innerText()).includes('+16025550222'));
  await visit({number:null,package:{included:true},request:null});
  check('eligible user sees Register number',
    (await page.locator('[data-vp3-agent-phone-link]').innerText())==='Register number');
  await visit({number:null,package:{included:false},request:null});
  check('ineligible user sees package upgrade, not register',
    (await page.locator('[data-vp3-agent-phone-link]').innerText())==='View plans'
    &&(await page.locator('[data-vp3-agent-phone-link]').getAttribute('href'))==='/pricing.php');
  assert.deepEqual(errors,[]);await page.close();
}finally{
  await browser.close();await new Promise(resolve=>server.close(resolve));
}
} else {
  console.log('INFO Browser behavior scenarios require PHONE_BROWSER_TEST=1 and Playwright.');
}
console.log(`PHONE_CLOUD_PH2_CONTRACT=PASS (${checks} cases)`);
