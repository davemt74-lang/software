import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const source=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-initiative-http-'));
const php=process.env.VP3_PHP_BINARY||'php';
const probe=net.createServer();
await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;
await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
for(const name of ['cognitive-presentation-v510.php','agent-chat-read-v1.php'])copyFileSync(join(source,'api',name),join(temp,'api',name));
writeFileSync(join(temp,'includes','bootstrap.php'),'<?php session_start(); require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';');
for(const name of ['agent-initiative-runtime-v1.php','agent-chat-read-v1.php']){
  writeFileSync(join(temp,'includes',name),'<?php require_once '+JSON.stringify(join(source,'includes',name))+';');
}
const router=join(temp,'router.php');
writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  '$source='+JSON.stringify(source)+';',
  'if(in_array($path,["/chat.js","/chat-voice.js","/chat-cognitive-presentation-v510.js","/chat-message-unread-v1.js","/chat-cognitive-presentation-v510.css"],true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'if(in_array($path,["/api/cognitive-presentation-v510.php","/api/agent-chat-read-v1.php"],true)){require __DIR__.$path;return;}',
  'require __DIR__."/includes/bootstrap.php";',
  'if($path==="/api/chat-v236.php")initiative_http_chat_v1();',
  'if($path==="/fixture-state")initiative_http_state_v1();',
  '$binding=vp3_catch_up_page_binding_v1(current_user());',
  '$chat=["userId"=>1,"agentId"=>7,"initialConversationId"=>11,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=7","csrf"=>csrf_token(),"workingAttentionBinding"=>$binding];',
  '$presentation=["agentId"=>7,"csrf"=>csrf_token(),"workingAttentionBinding"=>$binding,"liveContext"=>true,"initiativeRuntime"=>true];',
  'echo \'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat-cognitive-presentation-v510.css"><style>[hidden]{display:none!important}#chatThread{height:500px;overflow:auto}.message{min-height:40px}</style>\';',
  'echo \'<button id="newChatButton">New Chat</button><div id="chatHistory"></div><button id="chatAgentBriefButton">Agent Brief</button><aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside><section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section></section><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div>\';',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($chat).";window.VP3_COGNITIVE_PRESENTATION_V510=".json_encode($presentation).";window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:\\"/api/agent-chat-read-v1.php\\"};</script>";',
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script><script src="/chat-cognitive-presentation-v510.js"></script><script src="/chat-message-unread-v1.js"></script>\';'
].join('\n'));
let logs='';
const server=spawn(php,['-S','127.0.0.1:'+port,'-t',temp,router],{
  env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'working.sqlite')},stdio:['ignore','pipe','pipe']
});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});
server.on('error',error=>{logs+=error.message;});
let browser;
try{
  for(let attempt=0;;attempt++){
    try{const ready=await fetch(base+'/health');if(ready.ok)break;}catch{}
    if(attempt>50)throw new Error('PHP HTTP fixture did not start: '+logs);
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  page.setDefaultTimeout(10000);
  const errors=[],writes=[],responses=[];
  page.on('pageerror',error=>errors.push(error.message));
  page.on('request',request=>{
    if(request.method()==='POST'&&request.url().includes('/api/cognitive-presentation-v510.php'))writes.push(request.postDataJSON());
  });
  page.on('response',async response=>{
    if(response.url().includes('/api/cognitive-presentation-v510.php')&&response.request().method()==='POST'){
      responses.push({status:response.status(),body:await response.json().catch(()=>({}))});
    }
  });
  await page.addInitScript(()=>{
    window.speechCalls=0;window.announcementCalls=0;
    window.SpeechRecognition=undefined;window.webkitSpeechRecognition=undefined;
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(){window.speechCalls++;}}});
    window.SpeechSynthesisUtterance=class {constructor(text){this.text=text;}};
    window.STONEFELLOW_NOTIFICATION_CENTER={cancelSpeech(){},announceWhen(){window.announcementCalls++;return false;}};
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
    window.StonefellowKnowledgeScopeV162={value:()=>({mode:'off',folder_id:0})};
  });
  await page.clock.install();
  await page.goto(base,{waitUntil:'domcontentloaded'});
  const status=page.locator('[data-agent-initiative-status]');
  await status.getByText('Background preparation is off for this Agent.',{exact:true}).waitFor();
  let state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.runs.length,0);assert.equal(state.model_calls.length,0);
  assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
  const first=page.waitForResponse(response=>response.request().method()==='POST'
    &&response.url().includes('/api/cognitive-presentation-v510.php')
    &&response.request().postDataJSON().action==='initiative_tick');
  await status.getByRole('button',{name:'Enable',exact:true}).click();
  const planned=await first;
  assert.equal(planned.status(),200);
  const plannedText=await planned.text();
  let plannedData;
  try{plannedData=JSON.parse(plannedText);}catch{throw new Error('Invalid canonical API response: '+plannedText);}
  assert.equal(plannedData.reason,'completed',JSON.stringify({responses,state:await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()))}));
  await page.clock.fastForward(16000);
  const report=page.locator('.message').filter({hasText:'Verified background Brain analysis.'});
  await report.waitFor();
  await report.locator('[data-chat-message-new]').waitFor();
  assert.equal(await report.count(),1);
  assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.model_calls.length,2);
  assert.deepEqual(state.model_calls.map(row=>row.protocol),['vp3.initiative-plan.v1','vp3.initiative-draft.v1']);
  assert.equal(state.runs.length,2);assert.ok(state.runs.every(row=>row.agent_id===7&&row.status==='completed'));
  assert.equal(state.receipts.length,2);assert.ok(state.receipts.every(row=>row.status==='completed'));
  assert.equal(state.reports.length,1);
  const stored=state.reports[0],context=JSON.parse(stored.context_json);
  assert.equal(stored.conversation_id,11);assert.ok(stored.message.length>490);
  assert.deepEqual(context.initiative_work.agent_id,7);
  assert.equal(context.tool_receipt.capability,'brain.read');
  assert.equal(context.tool_receipt.count,1);
  assert.deepEqual(Object.keys(context.initiative_output.source_revisions),['brain:100']);
  assert.equal(await report.getAttribute('data-chat-message-id'),String(stored.id));
  assert.ok(!JSON.stringify(state.model_calls).includes('SYSTEM SECRET')&&!JSON.stringify(state.model_calls).includes('FOREIGN SECRET'));
  await page.clock.fastForward(16000);
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.model_calls.length,2);assert.equal(state.reports.length,1);
  const oldRevision=state.policy.revision;
  await status.getByRole('button',{name:'Pause',exact:true}).click();
  await status.getByText('Background preparation is off for this Agent.',{exact:true}).waitFor();
  const stale=await page.evaluate(async revision=>{
    const cfg=window.VP3_COGNITIVE_PRESENTATION_V510;
    const response=await fetch('/api/cognitive-presentation-v510.php',{method:'POST',
      headers:{'Content-Type':'application/json','X-VP3-Working-Attention':cfg.workingAttentionBinding},
      body:JSON.stringify({action:'initiative_setting',agent_id:7,conversation_id:11,csrf_token:cfg.csrf,
        enabled:true,knowledge_scope:{mode:'off',folder_id:0},policy_revision:revision})});
    return {status:response.status,data:await response.json()};
  },oldRevision);
  assert.equal(stale.status,409);assert.equal(stale.data.error,'initiative_settings_changed');
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.policy.enabled,false);assert.notEqual(state.policy.revision,oldRevision);
  assert.equal(state.model_calls.length,2);assert.equal(state.reports.length,1);
  assert.deepEqual(errors,[]);
  console.log('PASS real Chromium → actual presentation API → scoped opt-in → actual leased worker/planner → real named-Agent Brain read → verified receipt → one saved Chat report over 490 characters → main Chat NEW tag, Brief closed and no speech');
  console.log('PASS actual GET health performs no inference; unchanged situations reuse completed canonical work, exact Brain scope excludes System/foreign records, mobile status fits viewport');
  console.log('PASS the actual settings API pauses the exact Agent and rejects a stale tab revision without re-enabling work or spending another model call');
}catch(error){
  console.error(logs.slice(-9000));throw error;
}finally{
  if(browser)await browser.close();
  if(server.exitCode===null&&server.signalCode===null){
    server.kill();
    await new Promise(resolve=>server.once('exit',resolve));
  }
  rmSync(temp,{recursive:true,force:true});
}
