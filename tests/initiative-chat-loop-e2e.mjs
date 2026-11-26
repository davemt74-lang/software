import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync,cpSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium,request} from 'playwright';

// Identity/provider/calendar IO and the concurrent main-Agent visual state are
// controlled fixture inputs. Activity, policy, jobs, tools, result validation,
// Chat delivery and the ordinary owner's typed-message client are production.

const source=fileURLToPath(new URL('../',import.meta.url));
const transcriptScenario=process.argv.includes('--transcript');
const gmailWithoutAnchor=process.argv.includes('--gmail-no-anchor');
const freshGmail=process.argv.includes('--gmail-fresh');
const gmailBacklog=process.argv.includes('--gmail-backlog');
const gmailScenario=freshGmail||gmailWithoutAnchor||gmailBacklog||process.argv.includes('--gmail');
const officeScenario=process.argv.find(arg=>arg.startsWith('--office='))?.slice('--office='.length)??'';
assert.ok(['','task','list','reminder'].includes(officeScenario),'Choose one Office fixture source.');
const temp=mkdtempSync(join(tmpdir(),'vp3-initiative-http-'));
const php=process.env.VP3_PHP_BINARY||'php';
const probe=net.createServer();
await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;
await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
const apiFiles=['cognitive-presentation-v510.php','agent-chat-read-v1.php','agent-activity-v94.php'];
const chatAssets=['chat.js','chat-voice.js','agent-activity-v94.js','agent-eyes-core.js','agent-orb.js',
  'chat-cognitive-presentation-v510.js','chat-message-unread-v1.js','chat-cognitive-presentation-v510.css'];
if(transcriptScenario){
  apiFiles.push('artist-listening.php','artist-listening-v172.php','artist-listening-intelligence-v300.php',
    'artist-recordings-v198.php','initiative-research-status-v1.php');
  chatAssets.push('chat-transcription-canvas.js','chat-transcription-canvas.css','artist-listening-recordings.js');
}
if(gmailScenario){
  apiFiles.push('gmail-inbox-initial-v1.php');
  chatAssets.push('gmail-inbox-initial-v1.js');
}
if(officeScenario||freshGmail){
  apiFiles.push('agent-activity-hub-v1.php');
  chatAssets.push('agent-activity-hub-v1.js','agent-activity-hub-v1.css');
}
if(officeScenario){
  apiFiles.push('agent-top-of-mind-v1.php');
  chatAssets.push('chat-specialist-rail-v1.js','chat-specialist-rail-v1.css','agent-top-of-mind-v1.js');
}
if(freshGmail){
  apiFiles.push('chat-email-v1.php','agent-top-of-mind-v1.php');
  chatAssets.push('agent-orb.css','agent-top-of-mind-v1.js','chat-email-v1.js','chat-email-v1.css');
}
for(const name of apiFiles)copyFileSync(join(source,'api',name),join(temp,'api',name));
const fixtureFiles=['initiative-chat-loop-http.php','image-generation-v1.php','workflow-agent-schema-v1.php','initiative-gmail-chat-http.php',
  'initiative-office-chat-http.php','initiative-transcript-chat-http.php'];
if(gmailBacklog)fixtureFiles.push('initiative-media-chat-http.php');
// Every production include resolves in the same disposable source tree, with
// the same provider/compute adapters as the Chat bootstrap on every cold request.
cpSync(join(source,'includes'),join(temp,'includes'),{recursive:true});
mkdirSync(join(temp,'tests','fixtures'),{recursive:true});
for(const name of fixtureFiles)copyFileSync(join(source,'tests','fixtures',name),join(temp,'tests','fixtures',name));
writeFileSync(join(temp,'includes','ai-settings.php'),`<?php
function ai_encrypt_secret(string $value):string{return 'fixture:'.base64_encode($value);}
function ai_decrypt_secret(string $value):string{return str_starts_with($value,'fixture:')?(string)base64_decode(substr($value,8),true):'';}
function ai_master_key():string{return str_repeat('f',32);}
`);
writeFileSync(join(temp,'includes','agent-runtime-routing-v420.php'),`<?php
function vp3_agent_runtime_preference_readonly_v420(PDO $pdo,int $owner,int $agent):array{return ['effective_preference'=>'vp3_cloud'];}
`);
if(gmailScenario){
  if(freshGmail){
    // The fixture already supplies the presentation projection. The unrelated
    // browser-extension bootstrap loads a second copy via the Chat runtime.
    writeFileSync(join(temp,'includes','cognitive-presentation-v510.php'),'<?php');
  }
  const connector=join(temp,'includes','agent-connection-gmail-v1.php');
  const original=readFileSync(connector,'utf8');
  const blocked=original.replace(/function agent_connection_gmail_http_v1\([\s\S]*?(?=\nfunction agent_connection_gmail_request_v1)/,`function agent_connection_gmail_http_v1(string $method,string $url,array $headers,?array $body=null,bool $form=false):array {
    if (getenv('VP3_INITIATIVE_HTTP_GMAIL_FRESH')==='1') return initiative_http_gmail_wire_v1($method,$url,$headers,$body,$form);
    db()->prepare('INSERT INTO fixture_gmail_provider_calls VALUES(?)')->execute([$method]);
    throw new LogicException('An existing saved Gmail result must not contact the provider.');
}
`);
  assert.notEqual(blocked,original,'the Gmail wire adapter must be controlled before the test starts');
  writeFileSync(connector,blocked);
}else{
  writeFileSync(join(temp,'includes','agent-connection-gmail-inbox-status-v1.php'),`<?php
function agent_connection_gmail_diagnostics_v1(PDO $pdo,array $user,int $agentId,int $conversationId): array {
    return ['state'=>'disabled','next_step'=>'Connect Gmail in Connections.',
        'stages'=>array_map(static fn($label)=>['label'=>$label,'state'=>'unavailable','detail'=>'Gmail is not connected.'],
            ['Gmail setup','Inbox scan','Classification','Content analysis','Chat delivery'])];
}`);
}
const backlogFixture=gmailBacklog?`define('STONEFELLOW_ROOT', dirname(__DIR__));
require __DIR__.'/../tests/fixtures/initiative-media-chat-http.php';`:'';
const assertColdTick=gmailBacklog?`if(($_SERVER['REQUEST_METHOD']??'')==='POST'
    &&parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/api/cognitive-presentation-v510.php'
    &&(json_decode((string)file_get_contents('php://input'),true)['action']??null)==='initiative_tick'){
    initiative_http_gmail_assert_cold_tick_v1();
}`:'';
writeFileSync(join(temp,'includes','bootstrap.php'),`<?php session_start();
${backlogFixture}
require ${JSON.stringify(join(temp,'tests/fixtures/initiative-chat-loop-http.php'))};
${assertColdTick}`);
if(gmailBacklog){
  writeFileSync(join(temp,'native.wav'),Buffer.from('524946462600000057415645666d74201000000001000100401f0000803e00000200100064617461020000000000','hex'));
}
const phpPaths=paths=>'['+paths.map(path=>JSON.stringify(path)).join(',')+']';
writeFileSync(join(temp,'VP3_RELEASE.json'),JSON.stringify({commit:'a'.repeat(40)}));
const router=join(temp,'router.php');
writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  '$source='+JSON.stringify(source)+';',
  `if(in_array($path,${phpPaths(chatAssets.map(name=>'/'+name))},true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}`,
  `if(in_array($path,${phpPaths(apiFiles.map(name=>'/api/'+name))},true)){require __DIR__.$path;return;}`,
  'require __DIR__."/includes/bootstrap.php";',
  'if($path==="/api/chat-v236.php")initiative_http_chat_v1();',
  'if($path==="/fixture-state")initiative_http_state_v1();',
  'if($path==="/fixture-checkin")initiative_http_checkin_scenario_v1();',
  ...(freshGmail?['if($path==="/fixture-gmail-arrival")initiative_http_gmail_arm_v1();']:[]),
  ...(gmailBacklog?['if($path==="/fixture-gmail-backlog")initiative_http_gmail_backlog_batch_v1();']:[]),
  ...(officeScenario?['if($path==="/fixture-office-rescan")initiative_http_office_rescan_v1();']:[]),
  '$binding=vp3_catch_up_page_binding_v1(current_user());',
  '$chat=["userId"=>1,"agentId"=>7,"initialConversationId"=>11,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=7","csrf"=>csrf_token(),"workingAttentionBinding"=>$binding];',
  '$presentation=["agentId"=>7,"csrf"=>csrf_token(),"workingAttentionBinding"=>$binding,"liveContext"=>true,"initiativeRuntime"=>true];',
  'echo \'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat-cognitive-presentation-v510.css"><style>[hidden]{display:none!important}#chatThread{height:500px;overflow:auto}.message{min-height:40px}</style>\';',
  'echo \'<button id="newChatButton">New Chat</button><div id="chatHistory"></div><button id="chatAgentBriefButton">Agent Brief</button><aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside><section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section></section><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div>\';',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($chat).";window.VP3_COGNITIVE_PRESENTATION_V510=".json_encode($presentation).";window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:\\"/api/agent-chat-read-v1.php\\"};window.STONEFELLOW_ACTIVITY={endpoint:\\"/api/agent-activity-v94.php\\",csrf:\\"fixture\\",surface:\\"chat\\",conversationId:11};</script>";',
  'if(isset($_GET["busy"]))echo \'<script>sessionStorage.setItem("vp3:chat:drafts:1:7",JSON.stringify({"11":{text:"My unfinished thought",at:Date.now()}}));</script>\';',
  ...(officeScenario?[
    `echo '<aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside><link rel="stylesheet" href="/chat-specialist-rail-v1.css"><script>window.VP3_ORB_CONFIG={ownerKey:"1",agentId:7,topOfMindEndpoint:"/api/agent-top-of-mind-v1.php",activityEndpoint:"/api/agent-activity-hub-v1.php",workingAttentionBinding:window.STONEFELLOW_CHAT.workingAttentionBinding};window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:1,agent_id:7,states:{tasks:"unavailable"}};</script><script src="/chat-specialist-rail-v1.js"></script>';`,
  ]:[]),
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script>\';',
  'if(isset($_GET["busy"]))echo \'<script>document.body.dataset.stonefellowAgentState="processing";</script>\';',
  'echo \'<script src="/agent-activity-v94.js"></script><script src="/agent-eyes-core.js"></script><script src="/agent-orb.js"></script><script src="/chat-cognitive-presentation-v510.js"></script><script src="/chat-message-unread-v1.js"></script>\';',
  ...(transcriptScenario?[
    `echo '<div class="chat-topbar-actions"></div><link rel="stylesheet" href="/chat-transcription-canvas.css"><script>window.STONEFELLOW_RECORDINGS_V198_CONFIG={csrf:"fixture",transcriptFollowupsEndpoint:"/api/initiative-research-status-v1.php"};</script><script src="/artist-listening-recordings.js"></script><script src="/chat-transcription-canvas.js"></script>';`,
  ]:[]),
  ...(gmailScenario?[
    `echo '<script>window.VP3_GMAIL_INBOX_INITIAL_V1={endpoint:"/api/gmail-inbox-initial-v1.php",csrf:"fixture",ownerKey:"1",agentId:7,connectionsUrl:"/connections.php"};</script><script src="/gmail-inbox-initial-v1.js"></script>';`,
  ]:[]),
  ...(freshGmail?[
    `echo '<link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-activity-hub-v1.css"><script>window.VP3_ORB_CONFIG={ownerKey:"1",agentId:7,activityEndpoint:"/api/agent-activity-hub-v1.php",chatUrl:"/chat.php",workingAttentionBinding:window.STONEFELLOW_CHAT.workingAttentionBinding};</script><script src="/agent-top-of-mind-v1.js"></script><script src="/agent-activity-hub-v1.js"></script>';`,
    `echo '<link rel="stylesheet" href="/chat-email-v1.css"><script>window.VP3_CHAT_EMAIL_V1={ownerKey:"1",agentId:7,csrf:"fixture",endpoint:"/api/chat-email-v1.php",connectionsUrl:"/connections.php"};</script><script src="/chat-email-v1.js"></script>';`,
  ]:[]),
  ...(officeScenario?[
    `echo '<link rel="stylesheet" href="/agent-activity-hub-v1.css"><script src="/agent-top-of-mind-v1.js"></script><script src="/agent-activity-hub-v1.js"></script>';`,
  ]:[]),
].join('\n'));
let logs='';
const transportOptions=transcriptScenario?['-d','disable_functions=dns_get_record,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close']:[];
const server=spawn(php,[...transportOptions,'-S','127.0.0.1:'+port,'-t',temp,router],{
  env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'working.sqlite'),
    VP3_INITIATIVE_HTTP_BROWSER:'1',
    VP3_INITIATIVE_HTTP_TRANSCRIPT:transcriptScenario?'1':'0',VP3_INITIATIVE_HTTP_GMAIL:gmailScenario?'1':'0',
    VP3_INITIATIVE_HTTP_OFFICE:officeScenario,
    ...(gmailScenario?{VP3_GMAIL_CLIENT_ID:'initiative-fixture.apps.googleusercontent.com',VP3_GMAIL_CLIENT_SECRET:'fixture-only',
      VP3_INITIATIVE_HTTP_GMAIL_LEGACY_ANCHOR:gmailWithoutAnchor||freshGmail?'0':'1',
      VP3_INITIATIVE_HTTP_GMAIL_FRESH:freshGmail?'1':'0',VP3_INITIATIVE_HTTP_GMAIL_BACKLOG:gmailBacklog?'1':'0'}:{})},
  stdio:['ignore','pipe','pipe']
});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});
server.on('error',error=>{logs+=error.message;});
let browser;

function initiativeTick(page){
  return page.waitForResponse(response=>response.request().method()==='POST'
    &&response.url().includes('/api/cognitive-presentation-v510.php')
    &&response.request().postDataJSON().action==='initiative_tick');
}
async function fixtureJson(response){
  const body=await response.text();
  assert.equal(response.status(),200,body);
  assert.match(body,/^\{/,body);
  return JSON.parse(body);
}

async function readInitiativeDebug(){
  const client=await request.newContext();
  try{
    const markup=await (await client.get(base)).text();
    const config=JSON.parse(markup.match(/window\.STONEFELLOW_CHAT=(.*?);window\.VP3_COGNITIVE_PRESENTATION_V510=/)[1]);
    const debug=await client.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=11',{
      headers:{'X-VP3-Working-Attention':config.workingAttentionBinding},
    });
    return (await fixtureJson(debug)).diagnostics;
  }finally{await client.dispose();}
}

async function verifyOffice(page,writes,sourceKind){
  await page.setViewportSize({width:1280,height:850});
  const fixtures={
    task:{tool:'tasks.read',ref:'task:100',receipt:'tasks.read',summary:'The launch follow-up still needs a reviewed promotion plan.',
      nextStep:'Identify who can review the promotion plan.',question:'What is blocking the plan review?'},
    list:{tool:'tasks.lists.read',ref:'task-list:601',receipt:'tasks.lists',summary:'A task on Coordinated launch is waiting on a reviewed promotion plan.',
      nextStep:'Check who owns the plan review.',question:'Who can unblock the review?'},
    reminder:{tool:'reminders.read',ref:'reminder:201',receipt:'reminders.manage',summary:'The reminder to call the venue about the jukebox is overdue.',
      nextStep:'Confirm whether the call happened.',question:'Did you reach the venue, or is there a better time to call?'},
  };
  const fixture=fixtures[sourceKind];
  const state=async()=>fixtureJson(await page.request.get(base+'/fixture-state'));
  const readTaskStatus=async()=>{
    const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
    const endpoint=base+'/api/agent-top-of-mind-v1.php?view=work&scope=chat&agent_id=7&conversation_id=11';
    const {state:work}=await fixtureJson(await page.request.get(endpoint,{headers:{'X-VP3-Working-Attention':binding}}));
    assert.equal(work.agent_id,7);
    assert.equal(work.conversation_id,11);
    return work.specialists.find(row=>row.id==='tasks');
  };
  const readTaskQuestions=async()=>{
    const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
    const endpoint=base+'/api/agent-top-of-mind-v1.php?view=engagements&scope=chat&agent_id=7&conversation_id=11&specialist=tasks';
    const {state:questions}=await fixtureJson(await page.request.get(endpoint,{headers:{'X-VP3-Working-Attention':binding}}));
    assert.equal(questions.open_engagements_status,'ready',JSON.stringify(questions));
    return questions.open_engagements;
  };
  const before=await state();
  assert.equal(before.reports.length,0);
  assert.equal(before.runs.length,0);
  assert.equal(before.model_calls.length,0);
  const first=initiativeTick(page);
  await page.goto(base,{waitUntil:'domcontentloaded'});
  const planned=await fixtureJson(await first);
  assert.equal(planned.reason,'completed',JSON.stringify(await state()));
  const tick=async(interval=16000)=>{
    await fixtureJson(await page.request.post(base+'/fixture-office-rescan',{data:{csrf_token:'fixture'}}));
    const next=initiativeTick(page);
    await page.clock.fastForward(interval);
    await fixtureJson(await next);
    return state();
  };
  const nativeRun=current=>current.runs.find(run=>{
    const goal=JSON.parse(run.goal);
    return goal.tool===fixture.tool&&goal.office_entry===true&&goal.primary===fixture.ref&&run.status==='completed';
  });
  let after=await state();
  for(let attempt=0;attempt<6&&!nativeRun(after);attempt++)after=await tick();
  const native=nativeRun(after);
  assert.ok(native,'the normal scan and worker verify the exact private Office record');
  const nativeResult=JSON.parse(after.receipts.find(row=>row.run_id===native.id&&row.status==='completed').result_json);
  assert.equal(nativeResult.initiative_delivery,'private');
  assert.equal(nativeResult.initiative_tool_result.tool_receipt.capability,fixture.receipt);
  assert.equal(nativeResult.initiative_tool_result.tool_receipt.changes_applied,false);
  assert.equal(nativeResult.initiative_tool_result.output.record_count,1);
  assert.equal(nativeResult.initiative_tool_result.output.followup_question,fixture.question);
  assert.equal(after.reports.length,0,'a specialist receipt never posts directly to Chat');
  assert.deepEqual(await readTaskQuestions(),[],'the private report alone does not prove a Chat post');
  const modelCallsBeforeDelivery=after.model_calls;
  if(sourceKind==='task'){
    assert.equal(nativeResult.initiative_tool_result.output.owner_attention,false);
    const renewed=page.waitForResponse(response=>response.url().includes('/api/agent-activity-v94.php')
      &&response.request().method()==='POST'&&response.request().postDataJSON()?.context?.chat_busy===false);
    await fixtureJson(await page.request.post(base+'/fixture-office-rescan',{data:{csrf_token:'fixture',saved_only:true}}));
    await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
    const {agent_core_context:context}=await fixtureJson(await renewed);
    assert.equal(context.health.execution_ready,false);
    assert.equal(context.health.pump_available,true);
  }

  const coordinationRef='office:'+fixture.ref;
  const decisionRun=current=>current.runs.find(run=>{
    const goal=JSON.parse(run.goal);
    return goal.tool==='coordination.review'&&goal.primary===coordinationRef&&run.status==='completed';
  });
  for(let attempt=0;attempt<6&&(!decisionRun(after)||!after.reports.length);attempt++)after=await tick(sourceKind==='task'?31000:16000);
  const decision=decisionRun(after);
  assert.ok(decision,'the shared worker applies the Office rule in an open quiet Chat');
  const goal=JSON.parse(decision.goal);
  assert.deepEqual(goal.coordination_route,{specialist:'tasks',trigger:'waiting_owner',action:'ask'});
  assert.equal(goal.binding.conversation_id,11);
  if(sourceKind==='task'){
    assert.equal(goal.coordination_saved,true);
    assert.deepEqual(after.model_calls,modelCallsBeforeDelivery);
  }
  const report=page.locator('#chatThread .message').filter({hasText:fixture.question});
  await report.waitFor();
  const chatText=await report.innerText();
  assert.ok(chatText.includes('Next step: '+fixture.nextStep));
  assert.ok(chatText.includes('Question for you: '+fixture.question));
  const receipt=after.receipts.find(row=>row.run_id===decision.id&&row.status==='completed');
  const result=JSON.parse(receipt.result_json);
  const verified=result.initiative_tool_result;
  assert.equal(verified.output.type,'initiative_coordination');
  assert.equal(verified.output.state,'needs_owner');
  assert.deepEqual(verified.output.evidence_revisions,{[coordinationRef]:goal.sources[coordinationRef]});
  assert.equal(verified.output.next_step,fixture.nextStep);
  assert.equal(verified.output.question,fixture.question);
  assert.equal(after.reports.length,1);
  const saved=after.reports[0];
  assert.equal(saved.conversation_id,11);
  assert.equal(saved.message,verified.draft);
  assert.equal(Number(result.initiative_message_id),Number(saved.id));
  assert.equal(await report.getAttribute('data-chat-message-id'),String(saved.id));
  assert.deepEqual(after.office,before.office,'the source task, list membership or reminder occurrence stays untouched');

  assert.notEqual((await readTaskStatus())?.state,'waiting_reply');
  const [posted]=await readTaskQuestions();
  assert.equal(posted?.message_id,Number(saved.id));
  assert.equal(new URL(posted.result_url,base).searchParams.get('message_id'),String(saved.id));
  await fixtureJson(await page.request.post(base+'/fixture-office-rescan',{data:{csrf_token:'fixture',age_delivery:true}}));
  assert.deepEqual(await readTaskQuestions(),[posted],'the original open question stays actionable after its completion leaves the recent-work window');

  await page.clock.setSystemTime(Date.now());
  await page.reload({waitUntil:'domcontentloaded'});
  await report.waitFor();
  assert.equal(await report.count(),1);
  assert.equal(await report.getAttribute('data-chat-message-id'),String(saved.id));
  const tasksLauncher=page.locator('[data-chat-specialist="tasks"]');
  await tasksLauncher.waitFor();
  const [activityResponse]=await Promise.all([page.waitForResponse(response=>{
    const request=new URL(response.url());
    return request.pathname==='/api/agent-activity-hub-v1.php'&&request.searchParams.get('view')==='tasks';
  }),tasksLauncher.click()]);
  const activity=await fixtureJson(activityResponse);
  assert.equal(activity.state.agent_id,7);
  assert.equal(activity.state.conversation_id,11);
  assert.deepEqual(activity.state.specialists.find(row=>row.id==='tasks')?.open_engagements,[posted]);
  const reviews=activity.state.sections.find(section=>section.id==='office_reviews');
  assert.ok(reviews.items.some(item=>item.detail===fixture.summary),
    'Tasks still shows its own verified private report');
  assert.ok(reviews.items.every(item=>item.actions.every(action=>action.kind!=='chat')),
    'Private Tasks reports do not claim to be the shared Chat handoff');
  const workspace=page.getByRole('dialog',{name:'Tasks & Lists'});
  await workspace.getByText('Awaiting your reply (1)',{exact:true}).click();
  await workspace.getByRole('button',{name:'View in Chat',exact:true}).waitFor();
  const [inventoryResponse]=await Promise.all([page.waitForResponse(response=>{
    const request=new URL(response.url());
    return request.pathname==='/api/agent-activity-hub-v1.php'&&request.searchParams.get('view')==='tasks'
      &&request.searchParams.get('collection')==='all';
  }),workspace.getByRole('button',{name:'Full report',exact:true}).click()]);
  const inventory=(await fixtureJson(inventoryResponse)).state;
  const itemId=sourceKind==='list'?'list:601':fixture.ref;
  const item=inventory.sections.flatMap(section=>section.items).find(item=>item.id===itemId);
  assert.equal(item.office_report.summary,fixture.summary);
  assert.equal(item.office_report.followup_question,fixture.question);
  assert.equal(item.office_report.chat_action,undefined);
  const privateReport=workspace.getByRole('region',{name:'Report for '+item.title});
  await privateReport.getByText(fixture.summary,{exact:true}).waitFor();
  await privateReport.getByText('Next step: '+fixture.nextStep,{exact:true}).waitFor();
  await privateReport.getByText('Question for you: '+fixture.question,{exact:true}).waitFor();
  const finished=await state();
  assert.deepEqual(finished.reports,after.reports);
  assert.equal(finished.runs.filter(run=>JSON.parse(run.goal).primary===coordinationRef).length,1);
  if(sourceKind==='task')assert.deepEqual(finished.model_calls,modelCallsBeforeDelivery);
  assert.equal(writes.some(write=>write.action==='initiative_setting'),false);
  console.log(`PASS saved ${sourceKind} → verified private Office report → shared Ask in Chat rule → one Chat question, open engagement and private inventory after reload`);
}

async function verifyGmail(page,writes){
  const state=async()=>fixtureJson(await page.request.get(base+'/fixture-state'));
  const activeReports=state=>state.reports.filter(row=>Number(row.conversation_id)===11);
  const summary='A venue wants to host a jukebox session.';
  const nextStep='Check whether the jukebox is available in March.';
  const question='Which March dates can you offer the venue?';
  const unopened=await state();
  assert.equal(activeReports(unopened).length,0);
  assert.equal(unopened.model_calls.length,0);
  assert.notEqual(unopened.policy.enabled,true);
  assert.deepEqual(unopened.gmail.handoffs.needs_attention.map(finding=>[finding.summary,finding.next_step,finding.followup_question]),
    [[summary,nextStep,question]],JSON.stringify(unopened.gmail));
  assert.deepEqual(Object.values(unopened.gmail.source_triggers),gmailWithoutAnchor?[]:['needs_attention'],JSON.stringify(unopened.gmail));
  assert.equal(unopened.gmail.delivery_stage,'waiting');
  const debugBefore=(await readInitiativeDebug()).gmail.stages;
  assert.deepEqual(debugBefore.map(stage=>stage.state),['complete','complete','complete','complete','waiting'],JSON.stringify(debugBefore));
  assert.equal(debugBefore[4].code,'gmail_chat_not_linked');
  const busyHeartbeat=page.waitForResponse(async response=>response.url().includes('/api/agent-activity-v94.php')
    && response.request().method()==='POST' && response.request().postDataJSON()?.context?.chat_busy===true
    && (await response.json().catch(()=>null))?.agent_core_context?.conversation_id===11);
  const first=initiativeTick(page);
  await page.goto(base+'/?busy=1',{waitUntil:'domcontentloaded'});
  await page.clock.fastForward(350);
  await busyHeartbeat;
  await fixtureJson(await first);
  // A timestamped saved Chat result can arrive while its owner drafts; only
  // proactive voice uses the browser's current interruption availability.
  for(let attempt=0;attempt<4 && activeReports(await state()).length===0;attempt++){
    const tick=initiativeTick(page);
    await page.clock.fastForward(16000);
    await fixtureJson(await tick);
  }
  const routed=await state();
  assert.equal(activeReports(routed).length,1,JSON.stringify({runs:routed.runs,receipts:routed.receipts,gmail:routed.gmail}));
  assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished thought');
  assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
  await page.evaluate(()=>{document.body.dataset.stonefellowAgentState='idle';});
  await page.locator('#chatInput').fill('');
  const post=page.locator('#chatThread .message').filter({hasText:summary});
  await post.waitFor();
  assert.match(await post.innerText(),/Check whether the jukebox is available in March\./);
  assert.match(await post.innerText(),/Which March dates can you offer the venue\?/);
  const persisted=await state();
  assert.equal(activeReports(persisted).length,1);
  const [saved]=activeReports(persisted);
  assert.equal(saved.conversation_id,11);
  assert.equal(await post.getAttribute('data-chat-message-id'),String(saved.id));
  const context=JSON.parse(saved.context_json),run=persisted.runs.find(row=>Number(row.id)===context.initiative_run_id);
  assert.equal(context.source,'agent_initiative');
  assert.equal(context.initiative_output.type,'initiative_coordination');
  assert.equal(context.initiative_output.state,'needs_owner');
  assert.equal(context.initiative_output.question,question);
  assert.ok(run && JSON.parse(run.goal).tool==='coordination.review');
  assert.deepEqual(Object.keys(context.initiative_output.evidence_revisions),[JSON.parse(run.goal).primary]);
  assert.ok(JSON.parse(run.goal).primary.startsWith('gmail:'));
  const receipt=persisted.receipts.find(row=>Number(row.run_id)===Number(run.id)&&row.status==='completed');
  assert.equal(Number(JSON.parse(receipt.result_json).initiative_message_id),Number(saved.id));
  assert.ok(!JSON.stringify(saved).includes('fixture_venue_thread')&&!JSON.stringify(saved).includes('fixture_venue_message'),
    'Top of Mind stores a scoped evidence reference, without exposing provider identifiers');
  const replay=initiativeTick(page);
  await page.goto(base,{waitUntil:'domcontentloaded'});
  await fixtureJson(await replay);
  await post.waitFor();
  assert.equal(await post.count(),1);
  assert.equal(await post.getAttribute('data-chat-message-id'),String(saved.id));
  const finished=await state();
  assert.deepEqual(finished.reports,persisted.reports);
  assert.equal(finished.gmail.finding_revision,unopened.gmail.finding_revision);
  assert.equal(finished.gmail.provider_calls,0);
  assert.equal(finished.gmail.delivery_stage,'complete',finished.gmail.delivery_detail);
  const debugAfter=await readInitiativeDebug();
  assert.deepEqual(debugAfter.gmail.stages.map(stage=>stage.state),['complete','complete','complete','complete','complete']);
  const lastPost=debugAfter.engagement.last_post;
  assert.deepEqual([lastPost.message_id,lastPost.run_id,lastPost.source,lastPost.specialist,lastPost.result,lastPost.includes_gmail,lastPost.proof],
    [Number(saved.id),Number(run.id),'initiative','gmail','question',true,'receipt']);
  assert.equal(JSON.stringify(lastPost).includes(question),false,'the receipt-backed diagnostic omits mailbox and Chat text');
  assert.ok(finished.model_calls.every(call=>call.protocol==='vp3.initiative-plan.v1'),
    'routing the existing specialist question does not repeat Gmail analysis or generate another question');
  assert.equal(writes.some(write=>write.action==='initiative_setting'),false);
  console.log(`PASS saved Gmail needs-attention finding (${gmailWithoutAnchor?'no legacy inbox review':'existing inbox destination'}) → Top of Mind rule → one verified result in the open Chat → same result after reload without reanalysis`);
}

async function verifyGmailBacklog(page){
  const state=async()=>fixtureJson(await page.request.get(base+'/fixture-state'));
  const tick=async()=>{
    const next=initiativeTick(page);
    await page.clock.fastForward(16000);
    return fixtureJson(await next);
  };
  const setup=await page.request.get(base);
  assert.match(await setup.text(),/window\.STONEFELLOW_CHAT=/);
  const first=initiativeTick(page);
  const loaded=await page.goto(base,{waitUntil:'domcontentloaded'});
  assert.match(await loaded.text(),/window\.STONEFELLOW_CHAT=/);
  await fixtureJson(await first);
  let debug=await readInitiativeDebug();
  for(let attempt=0;attempt<4&&debug.engagement.last_post?.specialist!=='media';attempt++){
    await tick();
    debug=await readInitiativeDebug();
  }
  const mediaPost=debug.engagement.last_post;
  assert.equal(mediaPost?.specialist,'media',JSON.stringify(debug.engagement));
  assert.equal(mediaPost.proof,'receipt');
  assert.equal(debug.engagement.presence,'quiet');
  await fixtureJson(await page.request.post(base+'/fixture-gmail-backlog',{data:{csrf_token:'fixture'}}));
  debug=await readInitiativeDebug();
  assert.equal(debug.engagement.presence,'quiet');
  assert.deepEqual(debug.engagement.last_post,mediaPost);
  assert.equal(debug.gmail.stages[1].code,'gmail_scan_empty');
  assert.match(debug.gmail.stages[2].detail,/221 inbox messages/);
  assert.equal(debug.gmail.stages[3].code,'gmail_content_stopped');
  assert.match(debug.gmail.stages[3].detail,/20 analyzed, 0 pending, 8 stopped/);
  assert.equal(debug.gmail.stages[4].code,'gmail_handoff_unassigned');
  assert.match(debug.gmail.stages[4].detail,/10 current Gmail findings/);

  const result=await tick();
  debug=await readInitiativeDebug();
  assert.equal(result.coordination,'queued_chat_handoff',JSON.stringify({tick:debug.engagement.latest_tick,gmail:debug.gmail.stages[4]}));
  assert.equal(debug.engagement.last_post?.specialist,'gmail',JSON.stringify(debug.engagement));
  assert.equal(debug.engagement.last_post?.proof,'receipt');
  assert.ok(debug.engagement.last_post.message_id>mediaPost.message_id);
  const current=await state();
  const gmailPosts=current.reports.filter(row=>Object.keys(JSON.parse(row.context_json).initiative_output?.evidence_revisions??{})
    .some(ref=>ref.startsWith('gmail:')));
  assert.equal(gmailPosts.length,1);
  const gmailPost=gmailPosts[0];
  const canvas=page.locator(`#chatThread [data-chat-message-id="${gmailPost.id}"]`);
  await canvas.waitFor();
  assert.match(await canvas.innerText(),/Should I hold stage \d+ for the rehearsal\?/);
  assert.match(await canvas.innerText(),/Check whether stage \d+ is still needed\./);
  const receipt=current.receipts.find(row=>Number(row.run_id)===Number(result.run_id)&&row.status==='completed');
  assert.equal(Number(JSON.parse(receipt.result_json).initiative_message_id),Number(gmailPost.id));
  assert.equal(current.gmail.provider_calls,0);
  assert.ok(current.model_calls.every(call=>call.protocol==='vp3.initiative-plan.v1'));
  console.log('PASS cold open-Chat tick with ten Gmail handoffs, eight stopped analyses and a confirmed Media post → verified Gmail question and Next step in Chat');
}

async function verifyFreshGmail(page,writes){
  const state=async()=>fixtureJson(await page.request.get(base+'/fixture-state'));
  const openGmailQuestions=async()=>{
    const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
    const endpoint=base+'/api/agent-top-of-mind-v1.php?view=engagements&scope=chat&agent_id=7&conversation_id=11&specialist=gmail';
    const {state:questions}=await fixtureJson(await page.request.get(endpoint,{headers:{'X-VP3-Working-Attention':binding}}));
    assert.equal(questions.open_engagements_status,'ready');
    return questions.open_engagements;
  };
  const decisions=current=>current.reports.filter(row=>{
    const output=JSON.parse(row.context_json).initiative_output;
    return Number(row.conversation_id)===11&&output?.type==='initiative_coordination'
      &&Object.keys(output.evidence_revisions||{}).some(ref=>ref.startsWith('gmail:'));
  });
  const unopened=await state();
  assert.equal(unopened.gmail.provider_calls,0);
  assert.equal(unopened.gmail.handoffs?.needs_attention?.length||0,0);
  assert.equal(decisions(unopened).length,0);
  const first=initiativeTick(page);
  await page.goto(base,{waitUntil:'domcontentloaded'});
  await fixtureJson(await first);
  const slideout=page.locator('#vp3AgentActivityHub');
  assert.equal(await slideout.isVisible(),false);
  const tick=async()=>{
    const next=initiativeTick(page);
    await page.clock.fastForward(16000);
    await fixtureJson(await next);
    return state();
  };
  const officeReady=current=>current.runs.some(run=>{
    const goal=JSON.parse(run.goal);
    return run.status==='completed'&&goal.office_entry===true&&goal.primary==='task:100';
  });
  let after=await state();
  for(let attempt=0;attempt<5&&!officeReady(after);attempt++)after=await tick();
  assert.ok(officeReady(after),'the ordinary Chat worker completes the equipment report before the email arrives');
  const scan=page.waitForResponse(response=>response.url().endsWith('/api/gmail-inbox-initial-v1.php')
    &&response.request().postDataJSON()?.action==='check_overdue');
  await fixtureJson(await page.request.post(base+'/fixture-gmail-arrival',{data:{csrf_token:'fixture'}}));
  await page.clock.fastForward(16000);
  await fixtureJson(await scan);
  after=await state();
  assert.ok(after.gmail.provider_calls>0);
  assert.equal(after.gmail.handoffs.needs_attention.length,1,'a new message becomes one specialist finding');
  assert.equal(after.gmail.history_id,'1235');
  assert.equal(after.gmail.finding_count,1);
  assert.deepEqual(after.gmail.triage.map(row=>[row.subject,row.attention_key,row.reason_key]),[
    ['Juniper jukebox rehearsal','review','body_request'],['Weekly sale','read_later','gmail_promotions'],
  ]);
  for(let attempt=0;attempt<8&&!decisions(after).length;attempt++)after=await tick();
  const [question]=decisions(after);
  assert.ok(question,JSON.stringify({gmail:after.gmail,runs:after.runs,receipts:after.receipts}));
  const recentMedia=after.reports.find(row=>row.message==='Want to listen to a song?');
  assert.ok(recentMedia&&Number(recentMedia.id)<Number(question.id),
    'the real Gmail question reaches Chat after a just-posted media suggestion');
  assert.ok((Date.parse(question.created_at.replace(' ','T')+'Z')-Date.parse(recentMedia.created_at.replace(' ','T')+'Z'))<15000,
    'Gmail does not wait for the passive suggestion cooldown');
  assert.equal(await slideout.isVisible(),false);
  const questionContext=JSON.parse(question.context_json);
  assert.equal(questionContext.initiative_output.state,'needs_owner');
  const refs=Object.keys(questionContext.initiative_output.evidence_revisions);
  assert.ok(refs.some(ref=>ref.startsWith('gmail:'))&&refs.some(ref=>ref.startsWith('planning:'))&&
    refs.some(ref=>ref.startsWith('office:')),JSON.stringify(questionContext.initiative_output));
  assert.match(question.message,/Friday/);
  assert.match(question.message,/Saturday/);
  assert.match(question.message,/equipment/i);
  const questionRow=page.locator(`#chatThread [data-chat-message-id="${question.id}"]`);
  await questionRow.waitFor();
  assert.deepEqual((await openGmailQuestions()).map(item=>item.message_id),[Number(question.id)]);
  const input=page.locator('#chatInput');
  await input.fill('Saturday');
  await page.locator('#sendChatButton').click();
  for(let attempt=0;attempt<8&&decisions(after).length<2;attempt++)after=await tick();
  const [,reply]=decisions(after);
  assert.ok(reply,JSON.stringify({runs:after.runs,receipts:after.receipts,models:after.model_calls}));
  assert.deepEqual(await openGmailQuestions(),[]);
  const replyContext=JSON.parse(reply.context_json);
  assert.equal(replyContext.initiative_output.situation_id,questionContext.initiative_output.situation_id);
  assert.equal(replyContext.initiative_output.state,'resolved');
  assert.match(reply.message,/draft/i);
  const draft=replyContext.actions?.find(action=>action.type==='email_review');
  assert.match(draft?.id||'',/^[a-f0-9]{32}$/);
  assert.equal(after.gmail.drafts.length,1);
  assert.equal(after.gmail.drafts[0].id,draft.id);
  assert.equal(after.gmail.drafts[0].recipient,'venue@example.test');
  assert.equal(after.gmail.drafts[0].payload.body,'Saturday works for the Juniper jukebox rehearsal. The equipment is ready.');
  assert.equal(after.gmail.sent_count,0);
  assert.ok(after.gmail.wire.every(call=>call.method==='GET'));
  const resultRow=page.locator(`#chatThread [data-chat-message-id="${reply.id}"]`);
  await resultRow.getByRole('button',{name:'Review reply',exact:true}).click();
  const review=page.getByRole('dialog',{name:'Review Gmail draft'});
  await review.getByText('venue@example.test',{exact:true}).waitFor();
  assert.equal(await review.getByRole('textbox',{name:'Message'}).inputValue(),
    'Saturday works for the Juniper jukebox rehearsal. The equipment is ready.');
  await review.getByRole('button',{name:'Close',exact:true}).click();
  await page.locator('.vp3-orb-ball').press('Shift+Enter');
  await slideout.getByRole('tab',{name:'Opportunities',exact:true}).click();
  const finding=slideout.locator(`[data-coordination-finding="${replyContext.initiative_output.situation_id}"]`);
  await finding.getByText('Resolved',{exact:true}).waitFor();
  const reloadTick=initiativeTick(page);
  await page.reload({waitUntil:'domcontentloaded'});
  await questionRow.waitFor();
  await resultRow.waitFor();
  assert.equal(await slideout.isVisible(),false);
  await fixtureJson(await reloadTick);
  const reloaded=await state();
  assert.deepEqual(decisions(reloaded),decisions(after),'reload preserves the same question and result');
  assert.equal(reloaded.gmail.sent_count,0);
  assert.deepEqual(reloaded.gmail.drafts,after.gmail.drafts);
  assert.equal(reloaded.gmail.wire.filter(call=>call.path.endsWith('/history')).length,1);
  assert.equal(writes.some(write=>write.action==='initiative_setting'),false);
  console.log('PASS fresh Gmail arrival after a Media suggestion → authorized Planning and Tasks evidence → unsolicited Chat question → typed Saturday → verified draft and resolved Top of Mind item → no duplicate after reload');
}

async function verifyTranscript(page,writes){
  const state=()=>page.request.get(base+'/fixture-state').then(response=>response.json());
  const unopened=await state();
  assert.equal(unopened.runs.length,0);
  assert.equal(unopened.model_calls.length,0);
  assert.notEqual(unopened.policy.enabled,true);
  const accepted=page.waitForResponse(async response=>response.url()===base+'/api/agent-activity-v94.php'
    &&(await response.json().catch(()=>null))?.agent_core_context?.agent_id===7);
  const first=initiativeTick(page);
  await page.goto(base,{waitUntil:'domcontentloaded'});
  const heartbeat=(await fixtureJson(await accepted)).agent_core_context;
  assert.equal(heartbeat.conversation_id,11);
  const assessment=await fixtureJson(await first);
  assert.equal(assessment.reason,'completed',JSON.stringify(await state()));
  const staged=await state();
  assert.deepEqual(staged.model_calls.map(row=>row.protocol),['vp3.initiative-plan.v1']);
  const queued=staged.runs.find(row=>JSON.parse(row.goal).tool==='topic.research');
  assert.ok(queued,'the automatic assessment queued the existing report question');
  assert.equal(staged.reports.length,0);

  const afterReload=initiativeTick(page);
  await page.reload({waitUntil:'domcontentloaded'});
  const execution=await fixtureJson(await afterReload);
  assert.equal(execution.reason,'completed',JSON.stringify(await state()));
  await page.clock.fastForward(16000);
  const quote='Ventilation design depends on the appliance and installation conditions.';
  const report=page.locator('#chatThread .message').filter({hasText:quote});
  await report.waitFor();
  const finished=await state();
  assert.deepEqual(finished.model_calls.map(row=>row.protocol),[
    'vp3.initiative-plan.v1','vp3.transcript-research-plan.v1','vp3.topic-research.v1',
  ]);
  const goal=JSON.parse(queued.goal);
  // An independent starter invitation may also be saved. Bind this result to
  // the exact transcript source so even a duplicate from another run fails.
  const researchReports=finished.reports.filter(row=>
    JSON.parse(row.context_json).initiative_output?.source_ref===goal.primary);
  assert.equal(researchReports.length,1,JSON.stringify({reports:finished.reports,runs:finished.runs,
    receipts:finished.receipts,model_calls:finished.model_calls.map(row=>row.protocol)}));
  assert.equal(Number(researchReports[0].conversation_id),11);
  assert.equal(JSON.parse(researchReports[0].context_json).initiative_run_id,Number(queued.id));
  const messageId=Number(await report.getAttribute('data-chat-message-id'));
  assert.equal(messageId,Number(researchReports[0].id));
  const receipt=finished.receipts.find(row=>row.run_id===queued.id&&row.status==='completed');
  const result=JSON.parse(receipt.result_json);
  assert.equal(Number(result.initiative_message_id),messageId);
  assert.equal(result.initiative_tool_result.output.source_ref,goal.primary);
  assert.equal(result.initiative_tool_result.output.source_revision,goal.sources[goal.primary]);
  assert.equal(result.initiative_tool_result.sources[0].coverage,'page_excerpt');
  assert.equal(result.initiative_tool_result.sources[0].url,'https://fixture.invalid/oven-ventilation');
  await page.reload({waitUntil:'domcontentloaded'});
  await report.waitFor();
  const projectionUrl=base+'/api/initiative-research-status-v1.php?view=transcript&agent_id=7&conversation_id=11&session_id=940201';
  const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
  const beforeStatus=await fixtureJson(await page.request.get(projectionUrl,{headers:{'X-VP3-Working-Attention':binding}}));
  assert.deepEqual(beforeStatus.followups.map(row=>[row.plugin_id,row.section_key,row.item_id]),
    [['qa','unanswered','public-guidance']],JSON.stringify(beforeStatus));
  const projected=page.waitForResponse(response=>response.url()===projectionUrl);
  await page.getByRole('button',{name:'Open Transcription Intelligence'}).click();
  const projection=await fixtureJson(await projected);
  assert.deepEqual(projection.followups,beforeStatus.followups,'opening the report preserves the saved result and evidence');
  const canvas=page.getByRole('complementary',{name:'Transcription Intelligence'});
  const openQuestions=canvas.locator('.intel-home-card').filter({has:page.getByText('Open questions',{exact:true})});
  const question=openQuestions.getByRole('listitem').filter({hasText:'How should we evaluate oven ventilation for the private Supplier Delta quote?'});
  await question.waitFor();
  assert.equal(new URL(await question.getByRole('link',{name:/Page 1/}).getAttribute('href'),base).searchParams.get('page'),'1');
  const followup=question.locator('[data-intel-followup]').filter({hasText:quote});
  await followup.waitFor();
  const link=followup.getByRole('link',{name:'View in Chat'});
  const chatLink=new URL(await link.getAttribute('href'),base);
  assert.equal(chatLink.searchParams.get('agent'),'7');
  assert.equal(chatLink.searchParams.get('conversation_id'),'11');
  assert.equal(chatLink.searchParams.get('message_id'),String(messageId));
  assert.equal(await report.count(),1);
  const transcriptCalls=(await state()).model_calls.filter(row=>JSON.parse(row.prompt).response_kind!=='office_entry_review');
  assert.equal(transcriptCalls.length,3,'reading the report and reopening Chat reuse the completed result');
  assert.equal(writes.some(write=>write.action==='initiative_setting'),false);
  console.log('PASS saved Transcription question → automatic signed-in Chat heartbeat and assessment → reload → leased Research worker and verified citation → saved Chat message and exact Transcription report item');
}

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
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',isMainChat:()=>true,state:()=>({state:document.body.dataset.stonefellowAgentState||'idle'})};
  });
  await page.clock.install();
  if(transcriptScenario){
    await verifyTranscript(page,writes);
    assert.deepEqual(errors,[]);
  }else if(freshGmail){
    await verifyFreshGmail(page,writes);
    assert.deepEqual(errors,[]);
  }else if(gmailBacklog){
    await verifyGmailBacklog(page);
    assert.deepEqual(errors,[]);
  }else if(gmailScenario){
    await verifyGmail(page,writes);
    assert.deepEqual(errors,[]);
  }else if(officeScenario){
    await verifyOffice(page,writes,officeScenario);
    assert.deepEqual(errors,[]);
  }else await verifyBrainLoop(page,writes,responses,errors);
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

async function verifyBrainLoop(page,writes,responses,errors){
  const brainCalls = state => state.model_calls.filter(row=>{
    const input=JSON.parse(row.prompt);
    return input.response_kind!=='office_entry_review'&&input.role!=='brainstorm';
  });
  const unopened=await page.request.get(base),markup=await unopened.text();
  const rendered=JSON.parse(markup.match(/window\.STONEFELLOW_CHAT=(.*?);window\.VP3_COGNITIVE_PRESENTATION_V510=/)[1]);
  const pureHealth=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=state&agent_id=7&conversation_id=11&include_working_attention=0&include_live_context=1&include_initiative_runtime=1',{
    headers:{'X-VP3-Working-Attention':rendered.workingAttentionBinding}
  });
  assert.equal(pureHealth.status(),200);
  assert.equal((await pureHealth.json()).state.live_context.initiative.runtime.enabled,false);
  const diagnosticURL=base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=11';
  const diagnosticHeaders={'X-VP3-Working-Attention':rendered.workingAttentionBinding};
  const diagnosticResponse=await page.request.get(diagnosticURL,{headers:diagnosticHeaders});
  assert.equal(diagnosticResponse.status(),200,await diagnosticResponse.text());
  const diagnosticBody=await diagnosticResponse.text();
  assert.match(diagnosticBody,/^\{/,diagnosticBody);
  const diagnostic=JSON.parse(diagnosticBody).diagnostics;
  assert.equal(diagnostic.agent_id,7);
  assert.equal(diagnostic.conversation_id,11);
  assert.equal(diagnostic.release,'a'.repeat(12));
  assert.equal(diagnostic.initiative.enabled,false);
  assert.equal(diagnostic.gmail.stages.length,5);
  assert.equal((await page.request.get(diagnosticURL,{headers:{'X-VP3-Working-Attention':'wrong-binding'}})).status(),403);
  assert.equal((await page.request.get(diagnosticURL.replace('conversation_id=11','conversation_id=99'),{headers:diagnosticHeaders})).status(),404);
  let state=await page.request.get(base+'/fixture-state').then(r=>r.json());
  assert.equal(state.runs.length,0);assert.equal(state.model_calls.length,0);
  assert.equal(Object.keys(state.policy).length,0,'pure GET does not manufacture an enabled policy');
  const first=page.waitForResponse(response=>response.request().method()==='POST'
    &&response.url().includes('/api/cognitive-presentation-v510.php')
    &&response.request().postDataJSON().action==='initiative_tick');
  await page.goto(base+'/?busy=1',{waitUntil:'domcontentloaded'});
  const status=page.locator('[data-agent-initiative-status]');
  await status.locator('[data-agent-worker-details]').evaluate(details=>{details.open=true;});
  assert.equal(await page.locator('#chatThread [data-agent-initiative-status]').count(),0);
  assert.equal(await page.locator('.vp3-orb-root [data-agent-initiative-status]').count(),1);
  assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
  assert.equal(await status.getByRole('button',{name:'Enable',exact:true}).count(),0);
  await page.clock.fastForward(300);
  const planned=await first;
  assert.equal(planned.status(),200);
  const plannedText=await planned.text();
  let plannedData;
  try{plannedData=JSON.parse(plannedText);}catch{throw new Error('Invalid canonical API response: '+plannedText);}
  assert.equal(plannedData.reason,'completed',JSON.stringify({responses,state:await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()))}));
  const brainPrepared=initiativeTick(page);
  await page.clock.fastForward(16000);
  assert.equal((await fixtureJson(await brainPrepared)).reason,'completed');
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished thought');
  assert.equal(brainCalls(state).length,2,'the real private planner and preparation run despite the owner draft');
  assert.equal(state.reports.length,1,'a verified timestamped result reaches its saved Chat while the owner draft stays intact');
  assert.ok(state.actions.some(row=>JSON.parse(row.result_json||'{}').initiative_delivery==='delivered'));
  assert.equal(JSON.parse(state.model_calls[0].prompt).owner_context.text_delivery_available,true);
  await page.evaluate(()=>document.body.dataset.stonefellowAgentState='processing');
  await page.locator('#chatInput').fill('');
  await page.clock.fastForward(16000);
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.reports.length,1);
  await page.evaluate(()=>document.body.dataset.stonefellowAgentState='speaking');
  await page.clock.fastForward(16000);
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(state.reports.length,1);
  assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
  await page.evaluate(()=>document.body.dataset.stonefellowAgentState='idle');
  await page.clock.fastForward(17000);
  const report=page.locator('.message').filter({hasText:'Verified background Brain analysis.'});
  await report.waitFor();
  await report.locator('[data-chat-message-new]').waitFor();
  assert.equal(await report.count(),1);
  assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(brainCalls(state).length,2);
  assert.equal(writes.some(write=>write.action==='initiative_setting'),false,'core activation uses accepted activity, not an Enable mutation');
  assert.deepEqual(state.policy.knowledge_scope,{mode:'off',folder_id:0});
  assert.deepEqual(brainCalls(state).map(row=>row.protocol),['vp3.initiative-plan.v1','vp3.initiative-draft.v1']);
  const brainRuns=state.runs.filter(row=>{
    const goal=JSON.parse(row.goal);return goal.office_entry!==true&&goal.tool!=='brainstorm.explore';
  });
  const brainReceipts=state.receipts.filter(row=>brainRuns.some(run=>run.id===row.run_id));
  assert.equal(brainRuns.length,2);assert.ok(brainRuns.every(row=>row.agent_id===7&&row.status==='completed'));
  assert.equal(brainReceipts.length,2);assert.ok(brainReceipts.every(row=>row.status==='completed'));
  const creativeRuns=state.runs.filter(row=>JSON.parse(row.goal).tool==='brainstorm.explore');
  assert.ok(creativeRuns.length<=1,'Independent creative work shares canonical unchanged-context dedupe.');
  for(const run of creativeRuns.filter(row=>row.status==='completed')){
    const receipt=state.receipts.find(row=>row.run_id===run.id&&row.status==='completed');
    assert.ok(receipt,'A completed creative check retains its canonical receipt.');
    const result=JSON.parse(receipt.result_json);
    assert.equal(result.initiative_delivery,'private');
    assert.equal(result.initiative_tool_result.output.type,'initiative_brainstorm_report');
    assert.deepEqual(result.initiative_tool_result.output.ideas,[]);
  }
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
  assert.equal(brainCalls(state).length,2);assert.equal(state.reports.length,1);
  const oldRevision=state.policy.revision;
  await status.getByRole('button',{name:'Pause',exact:true}).click();
  await status.getByText('Agent background work is paused.',{exact:true}).waitFor();
  await page.clock.fastForward(35000);
  await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
  assert.equal(writes.filter(write=>write.action==='initiative_setting').length,1);
  assert.equal(Object.hasOwn(writes.find(write=>write.action==='initiative_setting'),'knowledge_scope'),false);
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
  assert.equal(brainCalls(state).length,2);assert.equal(state.reports.length,1);
  // Explicitly defer a second canonical job through the real API's private-only
  // worker mode. A subsequently saved normal turn must invalidate its tail.
  let deferCheckinDelivery=true;
  await page.route('**/api/cognitive-presentation-v510.php*',async route=>{
    const request=route.request();
    const body=request.method()==='POST'?request.postDataJSON():null;
    if(deferCheckinDelivery&&body?.action==='initiative_tick')
      return route.continue({postData:JSON.stringify({...body,private_only:true})});
    return route.continue();
  });
  await page.clock.setSystemTime(new Date());
  const selected=await page.evaluate(async()=>fetch('/fixture-checkin',{method:'POST',
    headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:window.STONEFELLOW_CHAT.csrf})}).then(r=>r.json()));
  assert.equal(selected.ok,true);
  await page.locator('#chatInput').fill('The launch direction has changed. Please use the new plan.');
  const checkinPlan=page.waitForResponse(response=>response.request().method()==='POST'
    &&response.url().includes('/api/cognitive-presentation-v510.php')
    &&response.request().postDataJSON().action==='initiative_tick');
  await status.getByRole('button',{name:'Resume',exact:true}).click();
  assert.equal((await (await checkinPlan).json()).reason,'completed');
  // Fast-forwarding browser timers does not await the actual PHP request.
  // Observe the preparation receipt before reading the fixture database; this
  // retains the exact four-call assertion on slower hosted runners.
  const checkinPrepared=initiativeTick(page);
  await page.clock.fastForward(16000);
  assert.equal((await fixtureJson(await checkinPrepared)).reason,'completed');
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  assert.equal(brainCalls(state).length,4);
  const pendingCheckinRow=state.actions.find(row=>JSON.parse(row.result_json||'{}').initiative_tool_result?.output?.type==='initiative_chat_check_in');
  assert.ok(pendingCheckinRow,'the real check-in tool prepared a revision-bound result');
  const pendingCheckin=JSON.parse(pendingCheckinRow.result_json);
  assert.equal(pendingCheckin.initiative_delivery,'pending');
  assert.equal(state.reports.length,1,'the explicitly deferred check-in remains private during the owner draft');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await page.getByText('I have saved your new direction.',{exact:true}).waitFor();
  deferCheckinDelivery=false;
  await fixtureJson(await page.request.post(base+'/fixture-checkin',{data:{csrf_token:'fixture',age_delivery_run_id:pendingCheckinRow.run_id}}));
  const revalidatedCheckin=initiativeTick(page);
  await page.clock.fastForward(17000);
  await fixtureJson(await revalidatedCheckin);
  state=await page.evaluate(async()=>fetch('/fixture-state').then(r=>r.json()));
  const withheldCheckin=state.actions.map(row=>JSON.parse(row.result_json||'{}'))
    .find(result=>result.initiative_tool_result?.output?.type==='initiative_chat_check_in');
  assert.equal(withheldCheckin.initiative_delivery,'withheld','a saved owner turn invalidates the old conversational check-in');
  assert.equal(state.reports.length,1);assert.equal(brainCalls(state).length,4);
  assert.equal(await page.getByText('A check-in based on the earlier owner conversation.',{exact:true}).count(),0);
  assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
  assert.deepEqual(errors,[]);
  console.log('PASS real Chromium → actual accepted activity API → automatic named-Agent core policy → actual leased worker/planner → real Brain read → verified receipt → one canonical Chat report over 490 characters → main Chat NEW tag, Brief closed and no speech');
  console.log('PASS actual GET health performs no inference; unchanged situations reuse completed canonical work, exact Brain scope excludes System/foreign records, mobile status fits viewport');
  console.log('PASS explicit Pause survives automatic activity heartbeats; the actual settings API rejects a stale tab revision without re-enabling work or spending another model call');
  console.log('PASS actual preparation saves one silent text result while the owner drafts, main Agent processes and speech is active; an intervening saved owner turn withholds an explicitly deferred stale check-in');
}
