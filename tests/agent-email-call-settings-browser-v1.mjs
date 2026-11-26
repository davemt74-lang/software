import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,mkdirSync} from 'node:fs';
import path from 'node:path';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=new URL('../',import.meta.url),read=file=>readFileSync(new URL(file,root),'utf8');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(10000);
const calls=[],errors=[],device='d'.repeat(32);let denied=false,held=null;
let policy={revision:0,urgent_enabled:false,drafts_enabled:false,grace_seconds:300,device_id:null,number_id:0};
let ownerUpdates=true;
const state=()=>({ok:true,policy,devices:[{device_id:device,label:'My connected phone',agent_name:'Main Agent',ready:true,reason:'eligible',lines:[{number_id:5,label:'My private calling line'}]}],
  owner_phone:{masked_number:'••• ••• 0199',updates_requested:ownerUpdates,time_zone:'America/Phoenix',window_start:'09:00',window_end:'17:00',max_calls_per_day:1,min_gap_minutes:240,max_duration_seconds:180},
  readiness:{state:!policy.urgent_enabled&&!policy.drafts_enabled?'off':ownerUpdates?'ready':'blocked',reason:!ownerUpdates?'owner_updates_disabled':policy.urgent_enabled||policy.drafts_enabled?'eligible':'off',
    message:!ownerUpdates?'Enable Agent update calls in your account before email calls can run.':policy.urgent_enabled||policy.drafts_enabled?'Email calls are ready within your shared calling hours and limits.':'Email calls are off.'},
  decision:null,active_call:null,links:{account_phone:'/account.php#agent-phone',phone_connection:'/phone-location.php'}});
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://email-calls.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{height:100%;margin:0;font:15px system-ui;background:#f3f5f8}#gmailLauncher{position:fixed;right:12px;top:20px;padding:12px}#chatThread{padding:24px}#chatInput{width:80%}</style>
<main><section id="chatThread">Private Agent Chat</section><textarea id="chatInput" aria-label="Chat message"></textarea></main><button id="gmailLauncher">Gmail</button><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://email-calls.test/api/hub**',route=>route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:42,
  working_attention_binding:'fixture-binding',agent_name:'Main Agent',current_chat:{title:'Private Chat'},observed_at:new Date().toISOString(),coverage:{},
  sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked.'}]}}}));
await page.route('https://email-calls.test/api/agent-email-calls-v1.php',async route=>{
  const input=route.request().postDataJSON();calls.push(input);assert.equal(route.request().headers()['x-vp3-email-calls'],'1');
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.conversation_id,42);assert.equal(input.csrf_token,'fixture-csrf');
  if(denied)return route.fulfill({status:403,json:{ok:false,error:'owner_access_changed',message:'Sign in again to check email calls.'}}).catch(()=>{});
  if(input.action==='save'){
    assert.equal(input.expected_revision,policy.revision);assert.equal(input.selected_device_id,device);assert.equal(input.number_id,5);
    if(input.urgent_enabled||input.drafts_enabled)assert.equal(input.confirmed,true);
    policy={revision:policy.revision+1,urgent_enabled:input.urgent_enabled,drafts_enabled:input.drafts_enabled,grace_seconds:input.grace_seconds,device_id:input.selected_device_id,number_id:input.number_id};
  }else assert.equal(input.action,'state');
  const snapshot=structuredClone(state());
  if(held){const gate=held;held=null;gate.start();await gate.promise;await route.fulfill({json:snapshot}).catch(()=>{});gate.finish();return;}
  return route.fulfill({json:snapshot});
});
const popup=()=>page.locator('#vp3ChatSpecialistActivity'),form=()=>popup().getByRole('form',{name:'Email calls'});
const save=()=>form().getByRole('button',{name:'Save email calls',exact:true});
const consent=()=>form().getByRole('checkbox',{name:'Allow recorded and transcribed AI calls about these email items to my connected phone.',exact:true});
function hold(){let start,release,finish;return {started:new Promise(resolve=>{start=resolve;}),promise:new Promise(resolve=>{release=resolve;}),finished:new Promise(resolve=>{finish=resolve;}),start:()=>start(),release:()=>release(),finish:()=>finish()};}
async function screenshot(name){const dir=process.env.VP3_EMAIL_CALLS_SCREENSHOT_DIR;if(!dir)return;mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,name),fullPage:true});}
async function bounds(){
  const geometry=await popup().evaluate(panel=>({width:innerWidth,body:document.documentElement.scrollWidth,left:panel.getBoundingClientRect().left,right:panel.getBoundingClientRect().right,
    nested:[...panel.querySelectorAll('*')].filter(node=>{const css=getComputedStyle(node);return ['auto','scroll'].includes(css.overflowY)&&node.scrollHeight>node.clientHeight+2;}).map(node=>node.className)}));
  assert.ok(geometry.body<=geometry.width+1);assert.ok(geometry.left>=0&&geometry.right<=geometry.width+1);assert.deepEqual(geometry.nested,[],'Only the Gmail settings popup scrolls.');
}
async function open(){await page.getByRole('button',{name:'Gmail',exact:true}).click();await popup().getByRole('button',{name:'Gmail settings',exact:true}).click();await form().getByText('Email calls are off.',{exact:true}).waitFor();}
try{
  await page.goto('https://email-calls.test/');
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>42,agentId:()=>7,isReady:()=>true,isRestoring:()=>false};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub',emailCallSettingsApi:'/api/agent-email-calls-v1.php',csrf:'fixture-csrf'};
    window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',emailReport:()=>'<p>Current Gmail results.</p>'};
  });
  for(const file of ['agent-activity-hub-v1.css','agent-email-call-settings-v1.css'])await page.addStyleTag({content:read(file)});
  for(const file of ['agent-email-call-settings-v1.js','agent-activity-hub-v1.js'])await page.addScriptTag({content:read(file)});
  await page.evaluate(()=>document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'})));
  await open();assert.equal(await form().getByLabel('Urgent incoming email',{exact:true}).isChecked(),false);assert.equal(await form().getByLabel('Agent-prepared email drafts ready for review',{exact:true}).isChecked(),false);
  assert.equal(await form().getByLabel('Grace period (minutes)',{exact:true}).inputValue(),'5');assert.equal(await save().isDisabled(),true);assert.equal(calls.filter(input=>input.action==='save').length,0);
  await bounds();await screenshot('gmail-email-calls-desktop.png');
  await form().getByLabel('Urgent incoming email',{exact:true}).check();await save().click();
  await form().getByText('Confirm that you allow recorded and transcribed AI calls about these email items.',{exact:true}).waitFor();assert.equal(calls.filter(input=>input.action==='save').length,0,'A category checkbox alone does not authorize recorded calls.');
  await consent().check();await save().click();await form().getByText('Email call choices saved. New eligible work will use these choices.',{exact:true}).waitFor();
  assert.equal(policy.urgent_enabled,true);assert.equal(policy.drafts_enabled,false);assert.equal(policy.grace_seconds,300);assert.equal(await consent().isChecked(),false,'Recorded-call consent is not reused for a later policy edit.');
  await form().getByLabel('Agent-prepared email drafts ready for review',{exact:true}).check();await form().getByLabel('Grace period (minutes)',{exact:true}).fill('10');
  policy={...policy,revision:2};ownerUpdates=false;
  await form().getByRole('button',{name:'Refresh',exact:true}).click();await form().getByRole('button',{name:'Use current settings',exact:true}).waitFor();
  assert.equal(await form().getByLabel('Agent-prepared email drafts ready for review',{exact:true}).isChecked(),true);assert.equal(await form().getByLabel('Grace period (minutes)',{exact:true}).inputValue(),'10');
  assert.equal(await save().isDisabled(),true,'A conflicting policy cannot save with a fresh revision attached to old choices.');
  await form().getByRole('button',{name:'Use current settings',exact:true}).click();assert.equal(await form().getByLabel('Agent-prepared email drafts ready for review',{exact:true}).isChecked(),false);
  await form().getByLabel('Urgent incoming email',{exact:true}).uncheck();await save().click();await form().getByText('Email calls are off.',{exact:true}).first().waitFor();
  assert.equal(calls.filter(input=>input.action==='save').at(-1).confirmed,false,'Disable-all remains available while owner update calls are off.');
  ownerUpdates=true;await form().getByRole('button',{name:'Refresh',exact:true}).click();await form().getByText('Current email call settings loaded.',{exact:true}).waitFor();
  await page.setViewportSize({width:390,height:700});await form().getByLabel('Urgent incoming email',{exact:true}).check();
  await consent().scrollIntoViewIfNeeded();await bounds();await screenshot('gmail-email-calls-phone.png');
  await save().scrollIntoViewIfNeeded();assert.ok(await save().isVisible(),'Save is reachable in the single scrolling phone popup.');
  await popup().getByRole('button',{name:'Back',exact:true}).click();await form().getByRole('button',{name:'Keep editing',exact:true}).click();
  assert.equal(await form().getByLabel('Urgent incoming email',{exact:true}).isChecked(),true);
  await popup().getByRole('button',{name:'Back',exact:true}).click();await form().getByRole('button',{name:'Discard changes',exact:true}).click();await form().waitFor({state:'detached'});
  assert.equal(policy.urgent_enabled,false,'Discarding unsaved settings does not enable calls.');
  await popup().getByRole('button',{name:'Gmail settings',exact:true}).click();await form().getByText('Email calls are off.',{exact:true}).waitFor();
  held=hold();const delayed=held;await form().getByRole('button',{name:'Refresh',exact:true}).click();await delayed.started;
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));delayed.release();await delayed.finished;await popup().waitFor({state:'detached'});
  assert.equal(await page.getByRole('form',{name:'Email calls'}).count(),0,'A delayed private response cannot restore settings after a Chat change.');
  await open();denied=true;await form().getByRole('button',{name:'Refresh',exact:true}).click();await popup().waitFor({state:'detached'});
  assert.equal(await page.getByText('••• ••• 0199',{exact:true}).count(),0,'Access loss removes the prior private number and settings.');
  assert.ok(calls.every(input=>['state','save'].includes(input.action)),'Settings do not dial, send mail, push or run inference.');assert.deepEqual(errors,[]);
  console.log('Email calls browser: actual Gmail settings entry, explicit category/recording consent, shared hours, conflict preservation, disabling, dirty Back, scope rejection and single-scroll desktop/phone layouts passed.');
}catch(error){try{await screenshot('gmail-email-calls-failure.png');console.error(JSON.stringify({errors,actions:calls.map(input=>input.action)}));}catch{}throw error;}
finally{await browser.close();}
