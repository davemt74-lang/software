import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// Real controllers, source adapters, canonical claimed worker/action/receipt,
// main Chat and floating Brain. Only account/SQL/model/remote-scope transport
// infrastructure uses the same disposable foundation as the Chat API suites.
const source=fileURLToPath(new URL('../',import.meta.url));
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-capabilities-browser-'));
const gate=path.join(temp,'model-gate');
fs.cpSync(path.join(source,'includes'),path.join(temp,'includes'),{recursive:true});
fs.mkdirSync(path.join(temp,'api'));
for(const name of ['chat.php','chat-v236.php','chat-stream-v121.php','agent-top-of-mind-v1.php'])fs.copyFileSync(path.join(source,'api',name),path.join(temp,'api',name));
fs.writeFileSync(path.join(temp,'includes/ai-settings.php'),'<?php // Fixture model configuration.');
// Keep actual readonly compute/privacy preference logic. Only route/transport
// infrastructure declarations already supplied by the foundation are renamed.
let routing=fs.readFileSync(path.join(source,'includes/agent-runtime-routing-v420.php'),'utf8');
for(const name of ['vp3_agent_runtime_plan_v420','vp3_agent_runtime_tool_plan_v420','vp3_agent_runtime_capability_route_v420','vp3_agent_runtime_finalize_v420','vp3_agent_runtime_public_plan_v420'])
  routing=routing.replace('function '+name+'(', 'function capability_unused_'+name+'(');
fs.writeFileSync(path.join(temp,'includes/agent-runtime-routing-v420.php'),routing);
let remote=fs.readFileSync(path.join(source,'includes/homeserver-scope-v026.php'),'utf8');
remote=remote.replace('function homeserver_scope_v026_fetch(', 'function capability_unused_homeserver_scope_v026_fetch(');
fs.writeFileSync(path.join(temp,'includes/homeserver-scope-v026.php'),remote);
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php'])
  fs.copyFileSync(path.join(source,'tests/fixtures',name),path.join(temp,'includes',name));
fs.writeFileSync(path.join(temp,'includes/initiative-followthrough-chat-fixture.php'),
  fs.readFileSync(path.join(source,'tests/fixtures/initiative-followthrough-chat.php'),'utf8').replace('function ai_generate_chat_response(', 'function followthrough_fixture_ai_generate_chat_response('));
fs.copyFileSync(path.join(source,'tests/fixtures/initiative-capabilities-chat.php'),path.join(temp,'includes/initiative-capabilities-chat-fixture.php'));
let bootstrap=fs.readFileSync(path.join(source,'tests/fixtures/capability-chat-bootstrap.php'),'utf8');
for(const name of ['chat_remote_answer','ai_generate_chat_response','calendar_schedule_awareness_snapshot_v1330','agent_runtime_v125_idempotency_claim','agent_runtime_v125_idempotency_complete'])
  bootstrap=bootstrap.replace('function '+name+'(', 'function capability_fixture_'+name+'(');
bootstrap=bootstrap.replace('declare(strict_types=1);','declare(strict_types=1); session_start();');
// Existing private-file idempotency implementation; only its storage root is
// redirected to this disposable account. No new adapter function is extracted.
const runtime=fs.readFileSync(path.join(source,'includes/agent-runtime-v125.php'),'utf8');
const start=runtime.indexOf('function agent_runtime_v125_idempotency_path('),end=runtime.indexOf('function agent_background_v125_jobs_dir(',start);
assert.ok(start>=0&&end>start);
bootstrap+="\nfunction agent_runtime_v125_root(): string {return dirname(__DIR__).'/runtime-fixture';}\nfunction agent_runtime_v125_dir(string $bucket): string {$dir=agent_runtime_v125_root().'/'.preg_replace('/[^a-z0-9_-]/i','',$bucket);if(!is_dir($dir))mkdir($dir,0700,true);return $dir;}\n"+runtime.slice(start,end);
bootstrap+="\nrequire_once __DIR__.'/initiative-followthrough-chat-fixture.php';\nrequire_once __DIR__.'/initiative-capabilities-chat-fixture.php';\n";
bootstrap+=`\nfunction ai_generate_chat_response($prompt,...$args): array {
 $options=is_array($args[4]??null)?$args[4]:[];$protocol=(string)($options['protocol']??'');
 if(str_starts_with($protocol,'vp3.initiative-')){ $result=capabilities_browser_model_v1((string)$prompt,$protocol);if(is_array($result))return $result; }
 return followthrough_fixture_ai_generate_chat_response($prompt,...$args);
}\n`;
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(temp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){$answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);return ['ok'=>true,'answer'=>$answer,'usage'=>[]];}`);
fs.writeFileSync(path.join(temp,'capabilities-control.php'),"<?php require __DIR__.'/includes/bootstrap.php';capabilities_browser_control_v1();");
const assets=['chat.js','chat-voice.js','chat.css','agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','agent-orb.js','agent-orb-adapter.js','agent-eyes-core.js','agent-orb.css','chat-notifications-drawer-v240.js','chat-notifications-drawer-v240.css'];
const voiceBootstrap=fs.readFileSync(path.join(source,'chat.php'),'utf8').match(/\$voiceBootstrap = '([^']+)';/)[1];
const router=path.join(temp,'router.php');
fs.writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);$source='+JSON.stringify(source)+';',
  'if($path==="/health"){echo "ready";return;}',
  '$assets='+JSON.stringify(assets)+';',
  'if(in_array(ltrim($path,"/"),$assets,true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'if(str_starts_with($path,"/api/")&&$path!=="/api/chat-notifications-brain-v240.php"||$path==="/capabilities-control.php")return false;',
  'require __DIR__."/includes/bootstrap.php";',
  'if($path==="/api/chat-notifications-brain-v240.php"){header("Content-Type:application/json");$aid=current_user()["id"]===1?7:9;echo json_encode(["ok"=>true,"complete"=>true,"agent_namespace"=>"agent:".$aid,"notifications"=>["items"=>[],"unread"=>0],"brain"=>["enabled"=>false],"activity_stream"=>["complete"=>true,"agent_namespace"=>"agent:".$aid,"items"=>[]]]);return;}',
  '$uid=(int)current_user()["id"];$aid=$uid===1?7:9;$cid=$uid===1?11:13;$binding=vp3_catch_up_page_binding_v1(current_user());',
  '$cfg=["userId"=>$uid,"agentId"=>$aid,"agentName"=>"Longfellow","initialConversationId"=>$cid,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=".$aid,"csrf"=>csrf_token(),"workingAttentionBinding"=>$binding];',
  'echo \'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/agent-top-of-mind-v1.css"><link rel="stylesheet" href="/chat-notifications-drawer-v240.css"><link rel="stylesheet" href="/agent-orb.css"><style>body{margin:0}.chat-thread{min-height:0}.chat-topbar{padding:14px 22px;border-bottom:1px solid #e5e7eb}#chatHistory{padding:12px}</style><body class="chat-page"><div class="chat-app"><aside class="chat-sidebar"><h2>VP3</h2><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside><main class="chat-main"><header class="chat-topbar"><div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Activity Center</button></div><div id="chatProfileMenu">Account</div></div></header><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section><div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer"><textarea id="chatInput" placeholder="Message Longfellow…"></textarea><button id="sendChatButton">↑</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div></div></main></div>\';',
  '$drawer=["endpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>csrf_token(),"userId"=>$uid,"agentId"=>$aid,"workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php"];',
  '$orb=["ownerKey"=>(string)$uid,"agentId"=>$aid,"chatUrl"=>"/chat.php","workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php","notificationsEndpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>csrf_token(),"voiceScript"=>"/deliberately-unavailable-voice.js","drawerScript"=>"/chat-notifications-drawer-v240.js"];',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($cfg).";window.STONEFELLOW_NOTIFICATION_DRAWER=".json_encode($drawer).";window.VP3_ORB_CONFIG=".json_encode($orb).";</script>";',
  'echo '+JSON.stringify(voiceBootstrap)+';',
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script><script src="/agent-eyes-core.js"></script><script src="/agent-top-of-mind-v1.js"></script><script src="/chat-notifications-drawer-v240.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script></body></html>\';'
].join('\n'));
const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
const base='http://127.0.0.1:'+port,headers={'X-Fixture-Brain-Ready':'1'};
let logs='',browser,serial=0;
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,'-t',temp,router],{detached:true,
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'2',VP3_CAPABILITY_BROWSER_GATE:gate},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});server.on('error',error=>{logs+=error.message;});
const control=async(action='state',query={})=>{
  const requestId='capability-browser-'+(++serial);
  const response=await fetch(base+'/capabilities-control.php?'+new URLSearchParams({action,...query}),{headers:{...headers,'X-Fixture-Request-ID':requestId}});
  const text=await response.text();let data;try{data=JSON.parse(text);}catch{throw new Error(text);}
  assert.equal(response.status,200,text);assert.equal(data.ok,true,text);return data;
};
const contextOf=message=>JSON.parse(message.context_json||'{}');
const ready=page=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
const idle=page=>page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
const openBrain=async page=>{await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-brain]').click();return page.locator('[data-agent-top-of-mind]');};
const closeBrain=page=>page.locator('[data-notification-drawer-close]').click();
function resultFor(state,id){
  const action=state.actions.find(row=>Number(row.run_id)===id),receipt=state.receipts.find(row=>Number(row.run_id)===id);
  assert.equal(action.status,'completed');assert.equal(receipt.status,'completed');assert.equal(action.result_json,receipt.result_json);
  const result=JSON.parse(action.result_json),proof=result.initiative_tool_result?.output;
  assert.equal(proof?.type,'initiative_capability_read');assert.equal(proof.tool,state.tool);assert.equal(proof.primary,state.ref);
  assert.equal(proof.owner_user_id,1);assert.equal(proof.agent_id,7);assert.equal(proof.conversation_id,11);
  assert.match(proof.read_fingerprint,/^[a-f0-9]{64}$/);assert.match(proof.coverage_fingerprint,/^[a-f0-9]{64}$/);
  return {result,proof};
}
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>80)throw new Error('PHP capability fixture did not start.');await new Promise(resolve=>setTimeout(resolve,50));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  for(const mobile of [false,true]){
    const seed=await control('seed',{tool:mobile?'transcripts.read':'tasks.read',meeting:mobile?'1':'0'});
    const runId=Number(seed.prepare_id);assert.ok(runId>0);
    let state;
    if(mobile){
      const prepared=await control('execute');assert.equal(prepared.execution_result.ok,true);
      const {result}=resultFor(prepared,runId);assert.equal(result.initiative_delivery,'pending');
      assert.equal(prepared.messages.some(row=>contextOf(row).initiative_run_id===runId),false);
      const beforeModels=prepared.calls.filter(row=>['capability_model','initiative_model','main_model'].includes(row.kind)).length;
      await control('present');state=await control('deliver');assert.equal(state.delivered,true);
      assert.equal(state.calls.filter(row=>['capability_model','initiative_model','main_model'].includes(row.kind)).length,beforeModels);
      const coldPolicy=state.calls.filter(row=>row.kind==='remote_scope').map(row=>JSON.parse(row.payload)).filter(row=>row.request_id===state.request_id);
      assert.ok(coldPolicy.length>0,'a separate cold HTTP delivery must refresh the actual meeting privacy guard');
      assert.ok(coldPolicy.every(row=>row.force===true&&row.readonly===true&&row.pdo_matches===true
        &&row.credential_transport==='custom_websocket'&&row.in_transaction===false));assert.equal(state.analysis_count,0);
      console.log('PASS actual meeting transcript prepares while away; separate cold HTTP delivery refreshes canonical privacy outside the transaction and posts the saved receipt without inference or new analysis');
    }
    const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850},extraHTTPHeaders:{...headers,'X-Fixture-Followthrough-Intent':mobile?'discuss':'advance'}});
    await context.addInitScript(()=>{
      window.speechCalls=0;window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
      Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.speechCalls++;queueMicrotask(()=>{u.onstart?.();u.onend?.();});}}});
      window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
      window.StonefellowKnowledgeScopeV162={value:()=>({mode:'off',folder_id:0})};
    });
    const page=await context.newPage(),errors=[],sends=[];page.setDefaultTimeout(12000);await page.clock.install();
    page.on('pageerror',error=>errors.push(error.message));
    page.on('request',request=>{if(request.method()==='POST'&&request.url().includes('/api/chat')){const body=request.postDataJSON();if(body?.action==='send')sends.push(body);}});
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);
      let feed=await openBrain(page);
      if(!mobile){
        await feed.locator('[data-top-of-mind-run="'+runId+'"][data-work-state="queued"]').waitFor();
        await control('claim');await page.clock.fastForward(3000);
        await feed.locator('[data-top-of-mind-run="'+runId+'"][data-work-state="working"]').waitFor();
        state=await control('execute');assert.equal(state.execution_result.ok,true);
        await page.clock.fastForward(3000);
      }
      await feed.locator('[data-top-of-mind-run="'+runId+'"][data-work-state="completed"]').waitFor();
      const {result,proof}=resultFor(state,runId);assert.equal(result.initiative_delivery,'delivered');
      const proposal=state.messages.find(row=>Number(row.id)===Number(result.initiative_message_id));assert.ok(proposal);
      assert.deepEqual(contextOf(proposal).initiative_output,proof);assert.ok(proposal.message.includes(seed.sentinel));
      const rawCalls=state.calls.filter(row=>row.kind==='capability_model').map(row=>JSON.parse(row.payload));
      const planning=rawCalls.find(row=>row.protocol==='vp3.initiative-plan.v1'),drafting=rawCalls.find(row=>row.protocol==='vp3.initiative-draft.v1');
      assert.equal(planning.prompt.includes(seed.sentinel),false);assert.ok(drafting.prompt.includes(seed.sentinel));
      assert.equal(rawCalls.some(row=>row.in_transaction),false);assert.equal(rawCalls.some(row=>row.prompt.includes('SECRET')),false);
      await closeBrain(page);await page.clock.fastForward(3500);
      await page.locator('[data-chat-message-id="'+proposal.id+'"]').waitFor();
      assert.equal(await page.locator('[data-chat-message-id="'+proposal.id+'"]').count(),1);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      assert.equal(await page.evaluate(()=>window.speechCalls),0);
      await page.locator('[data-initiative-reply="'+proposal.id+'"]').click();
      let loseAck=!mobile;
      if(!mobile)await page.route('**/api/chat-v236.php*',async route=>{
        const body=route.request().method()==='POST'?route.request().postDataJSON():null;
        if(loseAck&&body?.action==='send'){loseAck=false;await route.fetch();return route.abort('connectionreset');}
        return route.continue();
      });
      const ownerQuery=mobile?'Help me think through that saved recording.':
        'Prepare the next private step for that saved task. '+
        'Keep the result private and grounded in the selected saved account record. '.repeat(6)+'OWNER_BROWSER_REPLY_SENTINEL';
      await page.locator('#chatInput').fill(ownerQuery);
      await page.locator('#chatInput').press('Enter');await idle(page);
      const marker=contextOf(proposal).initiative_work.followthrough;
      assert.deepEqual(sends[0].initiative_reply,{message_id:Number(proposal.id),reply_ref:marker.reply_ref,revision:marker.revision});
      let replied=await control();
      if(!mobile){
        await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();
        const count=replied.runs.length,models=replied.calls.length,messages=replied.messages.length;
        await page.clock.fastForward(3500);
        await page.getByText('Retrying your earlier reply',{exact:true}).waitFor();
        await page.locator('#chatInput').press('Enter');await idle(page);
        replied=await control();assert.equal(replied.runs.length,count);assert.equal(replied.calls.length,models);assert.equal(replied.messages.length,messages);
        assert.deepEqual(sends[1].initiative_reply,sends[0].initiative_reply);
        console.log('PASS a lost actual owner acknowledgement for a new-tool proposal replays its exact association without another job, owner turn or model call');
      }
      const ack=replied.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);assert.ok(ack);
      const outcome=contextOf(ack).initiative_followthrough;
      assert.equal(outcome.message_id,proposal.id);assert.equal(outcome.state,mobile?'discussed':'queued');
      assert.equal(replied.calls.some(row=>row.kind==='ordinary_agent_chat'),false);
      if(!mobile){
        const childGoal=JSON.parse(replied.runs.find(row=>Number(row.id)===outcome.continuation_run_id).goal);
        assert.equal(childGoal.tool,seed.tool,'owner continuation must repeat its original declared read leaf');
        assert.ok(ownerQuery.length>300&&ownerQuery.indexOf('OWNER_BROWSER_REPLY_SENTINEL')>300);
        const finished=await control('execute',{id:String(outcome.continuation_run_id)});assert.equal(finished.execution_result.ok,true);
        const child=resultFor(finished,outcome.continuation_run_id);assert.equal(child.proof.tool,seed.tool);
        const lastRead=finished.calls.filter(row=>row.kind==='capability_model').map(row=>JSON.parse(row.payload))
          .filter(row=>row.protocol==='vp3.initiative-draft.v1').at(-1);
        const childInput=JSON.parse(lastRead.prompt);
        assert.equal(childInput.owner_continuation.owner_reply,ownerQuery);
        assert.equal(childInput.owner_continuation.proposal_text,proposal.message);
        assert.equal(childInput.owner_continuation.kind,'owner_requested_private_continuation');
        assert.equal(childInput.tool_result.tool,seed.tool);
        assert.ok(JSON.stringify(childInput.tool_result.records).includes(seed.sentinel));
        assert.ok(child.result.initiative_draft.includes('OWNER_BROWSER_REPLY_SENTINEL'));
        await page.clock.fastForward(3500);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work completed',{exact:true}).waitFor();
      }else await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Discussed',{exact:true}).waitFor();
      await page.reload({waitUntil:'domcontentloaded'});await ready(page);
      await page.locator('[data-chat-message-id="'+proposal.id+'"]').waitFor();
      await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText(mobile?'Discussed':'Private work completed',{exact:true}).waitFor();
      assert.equal((await control()).analysis_count,0);assert.deepEqual(errors,[]);
      console.log('PASS '+(mobile?'mobile transcript':'desktop task')+' source → declared model-selected new tool → leased worker/action/receipt → live Brain and exact Chat post → existing owner composer '+(mobile?'discussion':'same-read-leaf continuation with full owner request and current raw records')+' and reload');
      if(mobile){
        await context.setExtraHTTPHeaders({...headers,'X-Fixture-User':'2'});
        await page.reload({waitUntil:'domcontentloaded'});await ready(page);
        assert.equal(await page.locator('[data-chat-message-id="'+proposal.id+'"]').count(),0);
        assert.equal((await page.locator('#chatThread').innerText()).includes(seed.sentinel),false);
        const switchedRead=page.waitForResponse(response=>response.url().includes('/api/agent-top-of-mind-v1.php')&&response.request().method()==='GET');
        feed=await openBrain(page);const switched=await switchedRead;assert.equal(switched.status(),200);
        const switchedState=(await switched.json()).state;
        assert.equal(switchedState.owner_user_id,2);assert.equal(switchedState.agent_id,9);assert.equal(switchedState.conversation_id,13);
        await feed.getByText('Top of Mind',{exact:true}).waitFor();
        assert.equal(await feed.locator('[data-top-of-mind-run="'+runId+'"]').count(),0);
        assert.equal((await feed.innerText()).includes(seed.sentinel),false);
        console.log('PASS an actual authenticated account switch reloads the other canonical Chat/Brain and retains no prior-owner capability result');
      }
    }finally{await context.close();}
  }
  const negative=await control('seed',{tool:'tasks.read'});await control('claim');
  const before=await control();const held=control('execute',{hold:'1'});
  for(let i=0;!fs.existsSync(gate+'.entered');i++){
    if(i>150)throw new Error('The real selected capability worker did not reach its model transport gate.');
    await new Promise(resolve=>setTimeout(resolve,20));
  }
  assert.equal(JSON.parse(fs.readFileSync(gate+'.entered','utf8')).in_transaction,false);
  await control('source_change');fs.writeFileSync(gate+'.release','1');
  const refused=await held;assert.equal(refused.execution_result.ok,false);
  assert.equal(refused.messages.length,before.messages.length);
  assert.equal(refused.messages.some(row=>row.message.includes(negative.sentinel)),false);
  const action=refused.actions.find(row=>Number(row.run_id)===Number(negative.prepare_id));assert.equal(action.status,'failed');
  assert.equal(refused.calls.filter(row=>row.kind==='capability_model'&&JSON.parse(row.payload).protocol==='vp3.initiative-draft.v1').length,1);
  console.log('PASS a real source edit while the actual new-tool worker model is held rejects its draft before receipt completion or Chat delivery, without repeating inference');
  console.log('INITIATIVE_CAPABILITIES_BROWSER=PASS (6 groups)');
}catch(error){console.error(logs.slice(-14000));throw error;}
finally{
  if(browser)await browser.close();fs.writeFileSync(gate+'.release','1');
  if(server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(resolve=>server.once('exit',resolve));}
  if(!process.env.VP3_CAPABILITIES_KEEP_TEMP)fs.rmSync(temp,{recursive:true,force:true});else console.log('Fixture retained at '+temp);
}
