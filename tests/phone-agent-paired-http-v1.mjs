import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import {mkdtempSync,rmSync,readFileSync,mkdirSync} from 'node:fs';
import {createRequire} from 'node:module';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';
import {localPort,preparePhoneSite} from './fixtures/phone-site-v1.mjs';

const {chromium,request}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const repo=fileURLToPath(new URL('..',import.meta.url)),temp=mkdtempSync(path.join(tmpdir(),'vp3-phone-paired-'));
const site=path.join(temp,'web'),sessions=path.join(temp,'sessions'),php=process.env.VP3_PHP_BINARY||'php';
const endpoint='/api/phone-location-v1.php',tracking='/api/phone-tracking-v1.php';
let browser,server,logs='';
try{
  const [owner,other]=preparePhoneSite(repo,site,sessions,php);
  const canvas=JSON.parse(execFileSync(php,['-d','session.save_path='+sessions,path.join(repo,'tests/fixtures/phone-agent-paired-http-v1.php'),site],{encoding:'utf8'}));
  const port=await localPort(),baseURL='http://127.0.0.1:'+port;
  server=spawn(php,['-d','session.save_path='+sessions,'-d','display_errors=0','-S','127.0.0.1:'+port,'-t',site],{stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',b=>{logs+=b;});
  const ready=await request.newContext({baseURL});
  for(let i=0;i<60;i++){try{if((await ready.get('/health.txt')).ok())break;}catch{}await delay(100);}
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  const main=await browser.newContext({baseURL}),phone=await browser.newContext({baseURL,isMobile:true,hasTouch:true,viewport:{width:390,height:740}});
  await main.addCookies([{name:'PHPSESSID',value:owner.session,url:baseURL}]);
  const mainHeaders={'X-VP3-Working-Attention':owner.binding};
  async function post(api,url,data,headers={}){const r=await api.post(url,{headers,data});const j=await r.json();return {status:r.status(),...j};}
  async function mainAction(action,data={}){return post(main.request,tracking,{action,csrf_token:owner.csrf,...data},mainHeaders);}
  async function pairedAction(data){return post(phone.request,endpoint+'?workspace=1',data,{'X-VP3-Phone':'1'});}
  function ok(r){assert.equal(r.ok,true,JSON.stringify(r));return r;}
  const invite=ok(await mainAction('invite',{request_id:randomUUID()}));
  const claim=ok(await post(phone.request,endpoint,{action:'claim',invite:new URL(invite.phone_url,baseURL).hash.slice(8),name:'No-login iPhone'},{'X-VP3-Phone':'1'}));
  let d=claim.device;d=ok(await mainAction('approve',{device_id:d.id,generation:d.generation,allow_location:false,request_id:randomUUID()})).device;
  const page=await phone.newPage(),errors=[];page.setDefaultTimeout(12000);page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/phone-location.php');await page.locator('#phone-tab-agent').click();
  await page.locator('.phone-agent-approval').getByText(/No login is needed/).waitFor();
  assert.equal((await pairedAction({action:'status',device_id:d.id})).state,'approval_required');
  assert.equal((await pairedAction({action:'enroll',device_id:d.id,confirmed:true,expected_revision:0,csrf_token:owner.csrf})).status,401);
  const browsed={...canvas,conversation_id:9}; // Open an existing Canvas while a different owned Chat is selected.
  const targets=ok(await post(main.request,'/api/coding-canvas-v1.php',{action:'phone_targets',csrf_token:owner.csrf,agent_id:0,...browsed}));
  assert.deepEqual(targets.phones.map(p=>p.id),[d.id]);assert.equal(targets.phones[0].agent_access.enabled,false);
  const fromNewChat=ok(await post(main.request,'/api/coding-canvas-v1.php',{action:'phone_targets',csrf_token:owner.csrf,agent_id:0,...canvas,conversation_id:0}));
  assert.deepEqual(fromNewChat.phones.map(p=>p.id),[d.id],'a saved Canvas also lists phones when opened from a new unsaved Chat');
  const foreignChat=await post(main.request,'/api/coding-canvas-v1.php',{action:'phone_targets',csrf_token:owner.csrf,agent_id:0,...canvas,conversation_id:10});
  assert.equal(foreignChat.status,403,'a foreign browsing conversation never becomes authority over another owner');
  const staleVersion=await post(main.request,'/api/coding-canvas-v1.php',{action:'phone_targets',csrf_token:owner.csrf,agent_id:0,...browsed,revision:2});
  assert.equal(staleVersion.status,409);assert.match(staleVersion.error,/Reopen the Canvas/);
  const editor=await main.newPage();editor.setDefaultTimeout(12000);
  await editor.goto('/chat.php?agent=system&conversation_id=9&canvas_id='+canvas.canvas_id);
  try{await editor.locator('#chatCodingCanvasV1 .vp3-coding-canvas-edit-app').click();}catch(error){console.error('EDITOR',JSON.stringify(await editor.evaluate(()=>({url:location.href,title:document.title,text:document.body.innerText.slice(0,1800),cfg:typeof VP3_CODING_CANVAS_V1,chat:typeof STONEFELLOW_CHAT,thread:!!document.getElementById('chatThread')}))));throw error;}
  await editor.locator('#chatCodingCanvasV1').getByRole('button',{name:'Send Canvas to Connected Phone',exact:true}).click();
  const sendDialog=editor.getByRole('dialog',{name:'Send Canvas to Connected Phone',exact:true});
  const phoneSelect=sendDialog.getByRole('combobox',{name:'Connected phone',exact:true});
  await phoneSelect.locator('option').filter({hasText:'No-login iPhone'}).waitFor({state:'attached'});
  assert.equal(await phoneSelect.inputValue(),d.id,'the real editor lists this connected phone from a different Chat');
  if(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR){
    mkdirSync(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR,{recursive:true});
    await editor.screenshot({path:path.join(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR,'canvas-phone-dropdown-fixed.png')});
  }

  await sendDialog.getByRole('checkbox').check();
  const sentResponse=editor.waitForResponse(r=>new URL(r.url()).pathname==='/api/coding-canvas-v1.php'&&r.request().postDataJSON()?.action==='phone_send');
  await sendDialog.getByRole('button',{name:'Send Canvas',exact:true}).click();
  const response=await sentResponse;const sent=ok({status:response.status(),...await response.json()});
  await sendDialog.waitFor({state:'hidden'});

  await page.locator('.phone-agent-approval').getByRole('button',{name:'Check for approval'}).click();
  const card=page.locator('[data-item="'+sent.phone_delivery.item_ref+'"]');await card.getByText('Phone checklist',{exact:true}).waitFor();
  const s=ok(await pairedAction({action:'status',device_id:d.id}));assert.equal(s.authorization_mode,'paired_device');
  const detail=ok(await pairedAction({action:'detail',device_id:d.id,item_ref:sent.phone_delivery.item_ref,
    csrf_token:s.csrf_token,enrollment_revision:s.enrollment_revision,scope_revision:s.scope_revision}));
  assert.equal(detail.item.conversation_id,canvas.conversation_id,'delivery keeps the Canvas original conversation rather than relabeling it as the browsing Chat');
  await card.getByRole('button',{name:'Open canvas'}).click();
  const native=page.frameLocator('.phone-agent-native-frame');await native.getByRole('heading',{name:'Phone checklist'}).waitFor();
  await native.frameLocator('iframe').getByText('Private saved canvas arrived.',{exact:true}).waitFor();
  // The phone cookie is accepted only at the paired endpoint, never as a main-app login.
  const direct=await post(phone.request,'/api/coding-canvas-v1.php',{action:'open',csrf_token:s.csrf_token,agent_id:0,...canvas});assert.equal(direct.status,401);
  const phoneCookies=await phone.cookies();
  const anonymousId=phoneCookies.find(c=>c.name==='PHPSESSID')?.value;assert.ok(anonymousId);assert.notEqual(anonymousId,owner.session);
  const sessionText=readFileSync(path.join(sessions,'sess_'+anonymousId),'utf8');assert.doesNotMatch(sessionText,/(?:^|;)user_id\||auth_password_fingerprint\|/);
  assert.deepEqual(errors,[]);
  ok(await mainAction('agent_access',{device_id:d.id,generation:d.generation,expected_revision:s.revision,enabled:false}));
  const stale=await pairedAction({action:'feed',device_id:d.id,csrf_token:s.csrf_token,enrollment_revision:s.enrollment_revision,scope_revision:s.scope_revision});assert.ok([401,403,409].includes(stale.status));
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));await page.locator('.phone-agent-approval').waitFor();
  assert.equal(await page.locator('.phone-agent-native-frame').count(),0);assert.equal(await page.locator('#phone-tab-agent').isVisible(),true);
  assert.equal((await pairedAction({action:'status',device_id:d.id})).state,'approval_required');
  assert.equal(await page.getByRole('link',{name:'Sign in to your account'}).count(),0);
  const mainPage=await main.newPage();mainPage.setDefaultTimeout(12000);
  await mainPage.goto('/phone-tracking.php');
  await mainPage.locator('[data-device-id="'+d.id+'"] .phone-device').click();
  await mainPage.getByRole('button',{name:'Permissions',exact:true}).click();
  const access=mainPage.getByLabel('Allow AGENT on this phone without signing in',{exact:true});
  assert.equal(await access.isChecked(),false);
  await access.check();
  await mainPage.getByRole('button',{name:'Save AGENT access',exact:true}).click();
  await page.locator('.phone-agent-approval').getByRole('button',{name:'Check for approval'}).click();
  await page.locator('.phone-agent-item').first().waitFor();
  assert.equal((await pairedAction({action:'status',device_id:d.id})).authorization_mode,'paired_device');
  if(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR){
    mkdirSync(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR,{recursive:true});
    await page.screenshot({path:path.join(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR,'paired-agent-phone.png')});
    await mainPage.screenshot({path:path.join(process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR,'paired-agent-main-permissions.png')});
  }
  await ready.dispose();
  assert.doesNotMatch(logs,/PHP (?:Fatal error|Warning|Parse error)/);
  console.log('Phone paired HTTP: cross-chat/new-chat phone discovery and delivery, preserved Canvas origin, stale/foreign context denial, native viewer without login and revocation passed.');
}catch(e){console.error(logs.slice(-5500));throw e;}finally{await browser?.close();server?.kill('SIGTERM');rmSync(temp,{recursive:true,force:true});}
