import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {chromium} from 'playwright';

// SQLite and inference are fixtures. Claiming, execution, delivery, HTTP endpoints and Chat rendering are production code.
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-initiative-closed-chat-'));
const php=process.env.VP3_PHP_BINARY||'php';
fs.cpSync(path.join(root,'includes'),path.join(temp,'includes'),{recursive:true});
fs.mkdirSync(path.join(temp,'api'));
for(const file of ['chat-v236.php','agent-activity-v94.php'])fs.copyFileSync(path.join(root,'api',file),path.join(temp,'api',file));
for(const file of ['chat.js','agent-activity-v94.js'])fs.copyFileSync(path.join(root,file),path.join(temp,file));
for(const file of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(temp,'includes',file),'<?php // Fixture infrastructure.');
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php',
  'app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php','private-share-agent-v1.php','vrss-agent-v1.php',
  'analytics-agent-v1.php','radar-agent-v1.php','tracky-agent-v1.php'])fs.copyFileSync(path.join(root,'tests','fixtures',name),path.join(temp,'includes',name));
const bootstrap=fs.readFileSync(path.join(root,'tests','fixtures','capability-chat-bootstrap.php'),'utf8');
fs.writeFileSync(path.join(temp,'includes','bootstrap.php'),bootstrap.replace('declare(strict_types=1);',`declare(strict_types=1);
$_SERVER['HTTP_X_FIXTURE_BRAIN_READY']='1';
$_SERVER['HTTP_X_FIXTURE_IMAGES_READY']='1';`)+"\nrequire __DIR__.'/initiative-closed-chat-support.php';\n");
fs.copyFileSync(path.join(root,'tests','fixtures','initiative-closed-chat-support.php'),path.join(temp,'includes','initiative-closed-chat-support.php'));
fs.writeFileSync(path.join(temp,'driver.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$result=match($argv[1]){
 'seed'=>closed_chat_seed(),
 'prepare'=>closed_chat_prepare(),
 'stale'=>closed_chat_prepare('stale'),
 'pending'=>closed_chat_prepare('pending'),
 'deliver'=>['delivered'=>vp3_initiative_deliver_pending_v1(db(),['id'=>1,'is_active'=>1])],
 'withdraw'=>(function(){db()->exec("UPDATE agent_reminders_v1 SET status='cancelled' WHERE id=100");return closed_chat_state();})(),
 'state'=>closed_chat_state(),
 default=>throw new RuntimeException('Unknown test driver action.'),
};echo json_encode($result,JSON_THROW_ON_ERROR);`);
fs.writeFileSync(path.join(temp,'index.html'),`<!doctype html><meta charset="utf-8">
<aside><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside>
<section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div></section>
<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form>
<script>
const params=new URLSearchParams(location.search);
window.STONEFELLOW_CHAT={userId:Number(params.get('owner')||1),agentId:0,initialConversationId:Number(params.get('conversation_id')||42),
 endpoint:'/api/chat-v236.php',initialView:'chat',csrf:'fixture',activityPollMs:2000};
window.STONEFELLOW_ACTIVITY={endpoint:'/api/agent-activity-v94.php',csrf:'fixture',surface:'chat'};
</script><script src="/chat.js"></script><script src="/agent-activity-v94.js"></script>`);
const driver=action=>JSON.parse(execFileSync(php,[path.join(temp,'driver.php'),action],{encoding:'utf8'}));
let server;
let browser;
let serverLogs='';
try{
  driver('seed');
  const prepared=driver('prepare');
  assert.equal(prepared.reports.length,0);
  assert.equal(prepared.receipts.filter(row=>row.capability_key==='initiative.prepare'&&row.status==='completed').length,2);
  assert.match(JSON.stringify(prepared.receipts),/launch follow-up questions/);
  assert.match(JSON.stringify(prepared.receipts),/private asset review checklist/);
  assert.equal(driver('deliver').delivered,false);
  console.log('PASS with every Chat closed, the production executor prepares two independent private jobs and sends no report');

  const reserve=net.createServer();
  await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
  const port=reserve.address().port;
  await new Promise(resolve=>reserve.close(resolve));
  const origin=`http://127.0.0.1:${port}`;
  server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',temp],{stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',chunk=>{serverLogs+=chunk;});
  for(let attempt=0;attempt<60;attempt++){
    try{await fetch(origin);break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  const owner=await browser.newContext();
  const page=await owner.newPage();
  page.setDefaultTimeout(8000);
  const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  // Pause before loading application code. Freeze Date only during setup so
  // protocol latency cannot make pauseAt's target an already elapsed time.
  const clockStart=new Date();
  await page.clock.install({time:clockStart});
  await page.clock.setFixedTime(clockStart);
  await page.clock.pauseAt(clockStart);
  await page.clock.setSystemTime(clockStart);
  const heartbeat=(state,context=()=>true)=>page.waitForResponse(response=>response.url().endsWith('/api/agent-activity-v94.php')
    &&response.request().postDataJSON()?.state===state&&context(response.request().postDataJSON()?.context||{}));
  const firstHeartbeat=heartbeat('working');
  await page.goto(origin+'/?conversation_id=42');
  await page.getByText('I will review the available private work.',{exact:true}).waitFor();
  await page.clock.runFor(300);
  await firstHeartbeat;
  const drafting=heartbeat('working',context=>context.chat_busy===true&&context.chat_focused===true);
  await page.locator('#chatInput').fill('Keep this preparation private while I finish my draft.');
  await drafting;
  assert.equal(driver('deliver').delivered,false);
  assert.equal(driver('state').reports.length,0);
  console.log('PASS reopening normal Chat records real activity and keeps prepared work private while the owner has an active draft');

  const quiet=heartbeat('working',context=>context.chat_busy===false&&context.chat_focused===true);
  await page.locator('#chatInput').fill('');
  const quietState=await (await quiet).json();
  assert.equal(quietState.activity.state,'working');
  assert.equal(quietState.activity.interruptible,false);
  assert.equal(driver('deliver').delivered,true);
  assert.equal(driver('deliver').delivered,false);
  await page.clock.fastForward(3000);
  const report='I prepared launch follow-up questions from your saved reminder.';
  await page.getByText(report,{exact:true}).waitFor();
  assert.equal(await page.getByText(report,{exact:true}).count(),1);
  assert.equal(driver('state').reports.length,1);
  assert.equal(driver('state').reports[0].conversation_id,42);
  assert.equal(await page.getByText('I prepared a private asset review checklist.',{exact:true}).count(),0);
  assert.deepEqual(errors,[]);
  console.log('PASS clearing the draft permits one silent text result through real activity, messages_after and Chat while general activity remains working');

  await page.reload();
  await page.getByText(report,{exact:true}).waitFor();
  // Run the new document's 250ms initial heartbeat at a newer observation time
  // than the departing document's pagehide beacon.
  await page.clock.runFor(300);
  assert.equal(await page.getByText(report,{exact:true}).count(),1);
  assert.equal(driver('state').reports.length,1);
  assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.openConversation(44)),true);
  await page.getByText('Only this other conversation.',{exact:true}).waitFor();
  assert.equal(await page.getByText(report,{exact:true}).count(),0);

  const foreign=await browser.newContext({extraHTTPHeaders:{'X-Fixture-User':'2'}});
  const foreignPage=await foreign.newPage();
  await foreignPage.goto(origin+'/?owner=2&conversation_id=43');
  await foreignPage.getByText('Only foreign history.',{exact:true}).waitFor();
  assert.equal(await foreignPage.getByText(report,{exact:true}).count(),0);
  const rejected=await foreign.request.post(origin+'/api/chat-v236.php',{data:{action:'load',conversation_id:42,csrf_token:'fixture'}});
  assert.equal((await rejected.json()).ok,false);
  await foreign.close();
  console.log('PASS reload, a different owner Chat and a foreign account cannot duplicate or inherit the private result');

  const stale=driver('stale');
  assert.equal(stale.reports.length,1);
  assert.equal(stale.receipts.filter(row=>row.capability_key==='initiative.prepare'&&row.status==='failed').length,1);
  assert.equal(JSON.stringify(stale).includes('WITHHOLD'),false);
  assert.equal(driver('deliver').delivered,false);
  console.log('PASS changing a source during inference prevents its draft, success receipt and Chat report');

  // The clock has stayed paused except for the explicit heartbeat/poll advances
  // above. Settle HTTP before the explicit busy/return sequence below.
  await page.waitForLoadState('networkidle');
  const engaged=await owner.request.post(origin+'/api/chat-v236.php',{data:{action:'send',conversation_id:42,
    message:'Can you create reminders?',csrf_token:'fixture',knowledge_scope:'off'}});
  assert.equal((await engaged.json()).ok,true);
  await page.bringToFront();
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.openConversation(42));
  const active=heartbeat('working',context=>context.conversation_id===42&&context.chat_busy===true&&context.chat_focused===true);
  await page.locator('#chatInput').fill('I am reviewing the next follow-up.');
  const activeState=await (await active).json();
  assert.equal(activeState.ok,true);
  assert.equal(activeState.activity.state,'working');
  assert.equal(activeState.activity.interruptible,false);
  assert.equal(activeState.activity.context_key,'chat:42');
  const pending=driver('pending');
  assert.equal(pending.reports.length,1);
  assert.ok(pending.receipts.some(row=>JSON.parse(row.result_json||'{}').initiative_delivery==='pending'));
  driver('withdraw');
  const present=heartbeat('working',context=>context.conversation_id===42&&context.chat_busy===false&&context.chat_focused===true);
  await page.locator('#chatInput').fill('');
  assert.equal((await (await present).json()).ok,true);
  assert.equal(driver('deliver').delivered,false);
  const withdrawn=driver('state');
  assert.equal(withdrawn.reports.length,1);
  assert.ok(withdrawn.receipts.some(row=>JSON.parse(row.result_json||'{}').initiative_delivery==='withheld'));
  console.log('PASS a source withdrawn after successful preparation is withheld when its owner returns');
  console.log('INITIATIVE_CLOSED_CHAT_BROWSER=PASS (production worker executor, durable SQLite, real Chat and activity HTTP controllers, Chromium)');
}catch(error){
  error.message+='\n'+serverLogs.slice(-3000);
  throw error;
}finally{
  await browser?.close();
  server?.kill();
  fs.rmSync(temp,{recursive:true,force:true});
}
