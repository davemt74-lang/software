import assert from 'node:assert/strict';
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const origin='https://profile-phone.test';
const assets=new Map(['profile-phone-v1.js','profile-phone-settings-v1.js','profile-phone-v1.css','profile-image-settings-v1.js','profile.css','profile-agent-portal.css'].map(name=>['/'+name,fs.readFileSync(name,'utf8')]));
const widgetSource=fs.readFileSync('profile.php','utf8').match(/<div class="profile-agent-widget-root"[\s\S]*?(?=\n<\?php endif; \?>\n<script>)/)?.[0];
assert.ok(widgetSource,'phone fixture renders the production Profile widget');
const publicWidget=execFileSync(process.env.VP3_PHP_BINARY||'php',['-r',`
 $agent=['display_name'=>'Profile Guide'];$displayName='Dave';$profileOrbShape='orbit';$agentGreeting='Welcome. How can I help?';$isOwner=$chatPreview=false;
 function e($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function url($v){return $v;}eval('?>'.stream_get_contents(STDIN));
`],{input:widgetSource,encoding:'utf8'}).replace('id="profileAgentShell" hidden','id="profileAgentShell"');
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined,args:['--no-sandbox']});
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const context=await browser.newContext({viewport:{width:390,height:844}}),page=await context.newPage();
const errors=[];page.on('pageerror',error=>errors.push(error.message));
await page.addInitScript(()=>{const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>original(fn,[10000,12000,15000].includes(ms)?400:ms,...args);});
let published=false,number='+16025550101',revision=0,hang=false,saveLost=false,publicReads=0;const posts=[];
const owner=()=>({ok:true,contract:'vp3.profile-phone.v1',owner_user_id:41,ready:true,enabled:published,active:published,number_id:published?11:0,revision,numbers:[{id:11,number,agent_name:'My Agent'}],reason:''});
await page.route(origin+'/**',async route=>{
 const request=route.request(),url=new URL(request.url());
 if(url.pathname==='/api/profile-phone-v1.php'){
  if(url.searchParams.get('action')==='public'){publicReads++;if(hang)return;return route.fulfill({json:{ok:true,contract:'vp3.profile-phone.v1',enabled:published,number:published?number:'',href:published?'tel:'+number:''}});}
  if(request.method()==='POST'){const body=request.postDataJSON();posts.push({body,owner:request.headers()['x-vp3-profile-owner']});published=body.enabled;revision++;if(saveLost)return;}
  return route.fulfill({json:owner()});
 }
 if(url.pathname==='/api/profile-image-settings-v1.php'){if(hang)return;return route.fulfill({json:{ok:true,available:true,settings:{enabled:false,revision:0},collections:[],assets:[],history:{available:true,records:[],total_completed:0}}});}
 if(assets.has(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:assets.get(url.pathname)});
 const mode=url.pathname.includes('owner')?'owner':url.pathname.includes('images')?'images':'public';
 const widget=mode==='owner'?'<main class="profile-agent-panel" id="profilePhoneSettings"></main>':mode==='images'?'<main class="profile-agent-panel" id="profileImageSettings"></main>':publicWidget;
 const stylesheet=mode==='public'?'/profile.css':'/profile-agent-portal.css';
 return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${stylesheet}"><link rel="stylesheet" href="/profile-phone-v1.css"><style>body{font-family:Arial;margin:12px;background:#f7f8fa}*{box-sizing:border-box}main{max-width:760px;margin:auto}</style></head><body>${widget}<script>window.STONEFELLOW_PROFILE_AGENT={phoneEndpoint:'/api/profile-phone-v1.php',username:'dave',ownerName:'Dave'};window.PROFILE_AGENT_PORTAL={phoneEndpoint:'/api/profile-phone-v1.php',imageSettingsEndpoint:'/api/profile-image-settings-v1.php',ownerUserId:41,csrf:'fixture-token'};</script><script src="/${mode==='owner'?'profile-phone-settings-v1.js':mode==='images'?'profile-image-settings-v1.js':'profile-phone-v1.js'}"></script></body></html>`});
});
const open=()=>page.evaluate(()=>window.dispatchEvent(new Event('vp3:profile-agent-open')));
try{
 await page.goto(origin+'/public');await open();await page.waitForResponse(r=>r.url().includes('action=public'));
 check(await page.locator('[data-profile-phone]').isHidden(),'unpublished phone number stays out of public chat');
 published=true;await open();await page.getByRole('link',{name:'Call Dave by phone'}).waitFor();
 check(await page.locator('.profile-phone-link').getAttribute('href')==='tel:+16025550101','public Call action targets the owner-approved Twilio line');
 check(await page.locator('.profile-phone').evaluate(el=>el.getBoundingClientRect().right<=innerWidth),'phone action fits a mobile chat without horizontal overflow');
 fs.mkdirSync('artifacts/profile-reliability',{recursive:true});await page.screenshot({path:'artifacts/profile-reliability/profile-phone-mobile.png'});
 published=false;await page.locator('.profile-phone-link').click();await page.waitForFunction(()=>document.querySelector('[data-profile-phone]').hidden);
 check(await page.locator('.profile-phone-link').getAttribute('href')===null,'click rechecks current approval and cannot dial a revoked number');
 published=true;await open();await page.locator('.profile-phone-link[href]').waitFor();number='+16025550202';await page.locator('.profile-phone-link').click();
 await page.getByText('The phone line changed. Select Call by phone again.').waitFor();
 check(new URL(page.url()).pathname==='/public','changed line requires a fresh click instead of dialing a different destination');
 hang=true;await open();await page.getByText('Calling could not be checked. Open the chat again to retry.').waitFor();
 check(await page.locator('.profile-phone-link').getAttribute('href')===null,'stalled phone check removes the stale dial target');
 hang=false;await page.evaluate(()=>{window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));});
 await page.waitForFunction(()=>document.querySelector('.profile-phone-link')?.getAttribute('href')==='tel:+16025550202');
 check(publicReads>=6,'history restoration refreshes current phone approval');
 number='javascript:alert(1)';await open();await page.waitForFunction(()=>!document.querySelector('.profile-phone-link').hasAttribute('href'));
 check(!await page.locator('.profile-phone-link').getAttribute('href'),'untrusted phone projection cannot create an executable URL');
 number='+16025550101';published=false;await page.goto(origin+'/owner');await page.getByRole('button',{name:'Save calling settings'}).waitFor();
 await page.getByRole('checkbox').check();await page.getByRole('combobox').selectOption('11');await page.getByRole('button',{name:'Save calling settings'}).click();
 await page.getByText('Calling settings saved.',{exact:true}).waitFor();
 check(posts.length===1&&posts[0].owner==='41'&&posts[0].body.csrf_token==='fixture-token'&&posts[0].body.revision===0,'save binds account, CSRF and exact rendered revision');
 check(Object.keys(posts[0].body).sort().join(',')==='csrf_token,enabled,number_id,revision','owner publishes an assigned line ID without accepting an arbitrary phone destination');
 await page.setViewportSize({width:1200,height:900});await page.screenshot({path:'artifacts/profile-reliability/profile-phone-settings-desktop.png'});
 saveLost=true;await page.getByRole('checkbox').uncheck();await page.getByRole('button',{name:'Save calling settings'}).click();
 await page.getByText('Saving could not be confirmed. Reload calling settings before retrying.').waitFor();
 check(await page.getByRole('button',{name:'Save calling settings'}).isDisabled(),'lost save acknowledgement cannot blindly resubmit the old revision');
 saveLost=false;await page.getByRole('button',{name:'Reload',exact:true}).click();await page.getByRole('button',{name:'Save calling settings'}).waitFor();
 check(!await page.getByRole('checkbox').isChecked(),'reload recovers the actual saved state after acknowledgement loss');
 hang=true;await page.goto(origin+'/images');await page.getByText('Image settings took too long to respond. Try again.').waitFor();
 check(await page.getByRole('button',{name:'Retry image settings'}).isVisible(),'stalled image settings expose a usable retry instead of permanent loading');
 hang=false;await page.getByRole('button',{name:'Retry image settings'}).click();await page.getByRole('button',{name:'Save photo editing',exact:true}).waitFor();
 check(await page.getByRole('button',{name:'Save photo editing',exact:true}).isEnabled(),'image settings recover after a timed-out read');
 check(errors.length===0,'public calling, owner calling settings and image recovery have no browser errors');
 console.log(`PROFILE_PHONE_BROWSER_V1=PASS ${checks} checks`);
}finally{await context.close();await browser.close();}
