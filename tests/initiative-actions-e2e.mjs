import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// The actual modern Chat controller also handles load/list/messages_after.
// Only account/database/provider infrastructure is adapted by the shared API
// fixture. Source proposals and continuations use the real leased worker.
const source=fileURLToPath(new URL('../',import.meta.url));
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-action-e2e-'));
fs.cpSync(path.join(source,'includes'),path.join(temp,'includes'),{recursive:true});
fs.mkdirSync(path.join(temp,'api'));
for(const name of ['chat.php','chat-v236.php','chat-stream-v121.php','agent-top-of-mind-v1.php'])fs.copyFileSync(path.join(source,'api',name),path.join(temp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(temp,'includes',name),'<?php // Fixture infrastructure.');
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php']){
  fs.copyFileSync(path.join(source,'tests/fixtures',name),path.join(temp,'includes',name));
}
const companion=fs.readFileSync(path.join(source,'tests/fixtures/initiative-followthrough-chat.php'),'utf8')
  .replace('function ai_generate_chat_response(', 'function followthrough_fixture_ai_generate_chat_response(');
fs.writeFileSync(path.join(temp,'includes/initiative-followthrough-chat-fixture.php'),companion);
let bootstrap=fs.readFileSync(path.join(source,'tests/fixtures/capability-chat-bootstrap.php'),'utf8');
for(const name of ['chat_remote_answer','ai_generate_chat_response','calendar_schedule_awareness_snapshot_v1330','agent_runtime_v125_idempotency_claim','agent_runtime_v125_idempotency_complete'])bootstrap=bootstrap.replace('function '+name+'(', 'function capability_fixture_'+name+'(');
bootstrap=bootstrap.replace('declare(strict_types=1);','declare(strict_types=1); session_start();');
const runtimeSource=fs.readFileSync(path.join(source,'includes/agent-runtime-v125.php'),'utf8');
const primitiveStart=runtimeSource.indexOf('function agent_runtime_v125_idempotency_path('),primitiveEnd=runtimeSource.indexOf('function agent_background_v125_jobs_dir(',primitiveStart);
assert.ok(primitiveStart>=0&&primitiveEnd>primitiveStart);
bootstrap+="\nfunction agent_runtime_v125_root(): string {return dirname(__DIR__).'/runtime-fixture';}\nfunction agent_runtime_v125_dir(string $bucket): string { $dir=agent_runtime_v125_root().'/'.preg_replace('/[^a-z0-9_-]/i','',$bucket);if(!is_dir($dir))mkdir($dir,0700,true);return $dir;}\n"+runtimeSource.slice(primitiveStart,primitiveEnd);
fs.copyFileSync(path.join(source,'tests/fixtures/initiative-action-chat.php'),path.join(temp,'includes/initiative-action-chat-fixture.php'));
bootstrap=bootstrap.replace('function personal_capability_has_v242(', 'function capability_fixture_personal_capability_has_v242(');
bootstrap=bootstrap.replace("function setting($key,$default=''){return $default;}","function setting($key,$default=''){if($key==='personal_capabilities_seed_v242'&&table_exists('settings')){return db()->query(\"SELECT setting_value FROM settings WHERE setting_key='personal_capabilities_seed_v242'\")->fetchColumn()?:$default;}return $default;}");
bootstrap+="\nrequire_once __DIR__.'/initiative-action-chat-fixture.php';\n";
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(temp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){$answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);return ['ok'=>true,'answer'=>$answer,'usage'=>[]];}`);
fs.writeFileSync(path.join(temp,'followthrough-control.php'),"<?php require __DIR__.'/includes/bootstrap.php';action_fixture_control_v1();");
const preVoice=fs.readFileSync(path.join(source,'chat.php'),'utf8').match(/\$voiceBootstrap = '([^']+)';/)[1];
const router=path.join(temp,'router.php');
fs.writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  'if(str_starts_with($path,"/api/")||$path==="/followthrough-control.php")return false;',
  '$source='+JSON.stringify(source)+';',
  'if(in_array($path,["/chat.js","/chat-voice.js","/chat.css"],true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'require __DIR__."/includes/bootstrap.php";',
  '$cfg=["userId"=>1,"agentId"=>7,"agentName"=>"Longfellow","initialConversationId"=>11,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=7","csrf"=>csrf_token(),"workingAttentionBinding"=>vp3_catch_up_page_binding_v1(current_user())];',
  'echo \'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><style>body{margin:0}.chat-thread{min-height:0}.chat-topbar{padding:14px 22px;border-bottom:1px solid #e5e7eb}#chatHistory{padding:12px}</style><body class="chat-page"><div class="chat-app"><aside class="chat-sidebar"><h2>VP3</h2><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside><main class="chat-main"><header class="chat-topbar">Longfellow · Agent Chat</header><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section><div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer"><textarea id="chatInput" placeholder="Message Longfellow…"></textarea><button id="sendChatButton">↑</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div></div></main></div>\';',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($cfg).";</script>";',
  'echo '+JSON.stringify(preVoice)+';',
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script></body></html>\';'
].join('\n'));
const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
const base='http://127.0.0.1:'+port,headers={'X-Fixture-Brain-Ready':'1'};
let logs='',browser;
const providerGate=path.join(temp,'provider-gate');
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,'-t',temp,router],{detached:true,
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'2',VP3_FOLLOWTHROUGH_GATE:providerGate},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});server.on('error',error=>{logs+=error.message;});
const control=async(action='state',query={})=>{
  const response=await fetch(base+'/followthrough-control.php?'+new URLSearchParams({action,...query}),{headers});
  const text=await response.text();let data;try{data=JSON.parse(text);}catch{throw new Error(text);}
  assert.equal(response.status,200,text);assert.equal(data.ok,true,text);return data;
};
const contextOf=message=>JSON.parse(message.context_json||'{}');
const rootPost=state=>state.messages.find(message=>contextOf(message).source==='agent_initiative');
const ready=page=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
const idle=page=>page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
const choose=(page,id)=>page.locator('[data-initiative-reply="'+id+'"]').click();
const send=async(page,text)=>{await page.locator('#chatInput').fill(text);await page.locator('#chatInput').press('Enter');await idle(page);};
const actionHeaders=plan=>({...headers,'X-Fixture-Followthrough-Intent':'advance',...(plan?{'X-Fixture-Action-Plan':JSON.stringify(plan)}:{})});
const association=message=>{const f=contextOf(message).initiative_work.followthrough;return {message_id:Number(message.id),reply_ref:f.reply_ref,revision:f.revision};};
const actionRuns=state=>state.runs.filter(run=>run.capability_key==='initiative.action');
const launchTasks=state=>state.tasks.filter(task=>Number(task.user_id)===1&&Number(task.user_agent_id)===7);
const latestAck=state=>state.messages.filter(message=>contextOf(message).initiative_followthrough).at(-1);
const browserPost=async(page,endpoint,message,ref,extra={})=>page.evaluate(async({endpoint,message,ref,extra})=>{
  const response=await fetch('/api/'+endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',message,csrf_token:'fixture',knowledge_scope:'off',conversation_id:11,agent_context:{user_agent_id:7},initiative_reply:ref,...extra})});
  const body=await response.text();let events=[];try{events=body.trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));}catch{}
  return {status:response.status,body,data:endpoint.includes('stream')?(events.find(event=>event.type==='done')?.data??events.find(event=>event.type==='error')):events[0],events};
},{endpoint,message,ref,extra});
const waitFile=async file=>{const deadline=Date.now()+12000;while(!fs.existsSync(file)){assert.ok(Date.now()<deadline,'Provider barrier did not arrive: '+file);await new Promise(resolve=>setTimeout(resolve,15));}};
let checks=0;
const check=(ok,label,details='')=>{assert.ok(ok,label+(details?'\n'+details:''));checks++;console.log('PASS '+label);};
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok){await control();break;}}catch(error){if(attempt>80)throw error;}
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  for(const mobile of [false,true]){
    const seed=await control('seed'),proposal=rootPost(seed),plan={adapter:'task.create',arguments:{title:mobile?'Prepare mobile launch copy':'Prepare desktop launch copy'}};
    const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850},extraHTTPHeaders:actionHeaders(plan)}),page=await context.newPage(),errors=[],requests=[];
    page.setDefaultTimeout(12000);page.on('pageerror',error=>errors.push(error.message));
    page.on('request',request=>{if(request.method()==='POST'&&request.url().includes('/api/chat-v236.php')){const body=request.postDataJSON();if(body?.action==='send')requests.push(body);}});
    await context.addInitScript(()=>{window.speechCalls=0;window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.speechCalls++;queueMicrotask(()=>u.onend?.());}}});window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});});
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);await choose(page,proposal.id);
      await send(page,'Save a task for that next step.');
      const reviewed=await control(),reviewAck=latestAck(reviewed),review=contextOf(reviewAck).initiative_action;
      check(review?.status==='approval_pending'&&review.adapter===plan.adapter&&review.arguments.title===plan.arguments.title&&review.receipt_id===0,
        (mobile?'mobile':'desktop')+' the actual Chat controller returns and saves an exact frozen action review');
      check(launchTasks(reviewed).length===launchTasks(seed).length&&actionRuns(reviewed).length===1,
        'preparing the action records one canonical review without writing a task');
      const details=page.locator('[data-initiative-action-run="'+review.run_id+'"]').last();
      await details.getByText(plan.arguments.title,{exact:true}).waitFor();
      if(process.env.VP3_ACTION_SCREENSHOTS){fs.mkdirSync(process.env.VP3_ACTION_SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.VP3_ACTION_SCREENSHOTS,mobile?'action-review-mobile.png':'action-review-desktop.png'),fullPage:true});}
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'the exact action review fits the main Chat canvas');
      const pending=await control('pending');assert.equal(pending.list_actions.length,1);assert.equal(pending.list_actions[0].status,'prepared');
      await context.setExtraHTTPHeaders(actionHeaders());
      let loseAck=!mobile,lostResponse=null;
      if(loseAck)await page.route('**/api/chat-v236.php*',async route=>{
        const body=route.request().method()==='POST'?route.request().postDataJSON():null;
        if(loseAck&&body?.action==='send'){loseAck=false;const response=await route.fetch();lostResponse=await response.json();return route.abort('internetdisconnected');}
        return route.continue();
      });
      await details.getByRole('button',{name:'Reply',exact:true}).click();await send(page,'Yes, save those exact details.');
      if(!mobile){
        await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();
        const saved=await control(),calls=saved.calls.length,messageCount=saved.messages.length;
        check(launchTasks(saved).length===launchTasks(seed).length+1&&actionRuns(saved)[0].status==='completed','a lost acknowledgement follows a committed canonical task, action and receipt',JSON.stringify({lostResponse,runs:actionRuns(saved).map(run=>({id:run.id,status:run.status})),tasks:launchTasks(saved).map(t=>({id:t.id,subject:t.subject}))}));
        await page.locator('#sendChatButton').click();await idle(page);
        const replay=await control();
        check(replay.calls.length===calls&&replay.messages.length===messageCount&&launchTasks(replay).length===launchTasks(saved).length,
          'retrying a committed-but-lost acknowledgement returns the saved outcome without inference, duplicate task or duplicate Chat');
        assert.deepEqual(requests.at(-1).initiative_reply,requests.at(-2).initiative_reply);
      }
      const completed=await control(),completion=contextOf(latestAck(completed)).initiative_action;
      check(completion?.status==='completed'&&completion.receipt_id>0&&completion.plan_hash===review.plan_hash,
        'the main Chat completion names the exact reviewed plan and a real canonical receipt');
      const run=actionRuns(completed)[0],step=completed.actions.find(row=>Number(row.run_id)===Number(run.id)),receipt=completed.receipts.find(row=>Number(row.run_id)===Number(run.id));
      check(run.status==='completed'&&step.status==='completed'&&receipt.status==='completed'&&step.result_json===receipt.result_json,
        'workflow completion is backed by the same persisted action and receipt result');
      check(completed.list_actions[0].status==='prepared'&&completed.lists.length===0,
        'the Initiative confirmation leaves an unrelated real pending list approval untouched');
      check(completed.task.metadata_json===seed.task.metadata_json&&completed.calls.every(call=>call.kind!=='ordinary_agent_chat'),
        'creating the reviewed task leaves the source task unchanged and never routes a synthetic query through ordinary tools');
      await page.locator('[data-initiative-action-run="'+review.run_id+'"][data-initiative-action-status="completed"]').first().waitFor();
      check(await page.locator('[data-initiative-action-reply]').count()===0,'completion retires the prior review action in the current canvas');
      await page.reload({waitUntil:'domcontentloaded'});await ready(page);
      check(await page.locator('[data-initiative-action-reply]').count()===0&&await page.getByText('Completed · verified receipt #'+completion.receipt_id,{exact:true}).count()>0,
        'reload restores receipt-backed completion without reviving an approval');
      check(await page.evaluate(()=>window.speechCalls)===0,'typed action updates do not start unsolicited speech');
      assert.deepEqual(errors,[]);
    }finally{await context.close();}
  }
  // Chromium-originated requests traverse all production controllers, including
  // NDJSON streaming; actual database state, source readers and executors remain real.
  for(const endpoint of ['chat.php','chat-v236.php?agent=7','chat-stream-v121.php']){
    const seed=await control('seed'),proposal=rootPost(seed),plan={adapter:'task.create',arguments:{title:'Controller '+endpoint+' launch'}};
    const context=await browser.newContext({extraHTTPHeaders:actionHeaders(plan)}),page=await context.newPage();await page.goto(base);await ready(page);
    try{
      const reviewed=await browserPost(page,endpoint,'Save a task for that launch.',association(proposal));
      check(reviewed.data?.ok===true&&reviewed.data.initiative_action?.status==='approval_pending',endpoint+' produces a real exact action review',reviewed.body);
      const state=await control(),ack=latestAck(state),ref={message_id:Number(proposal.id),reply_ref:contextOf(ack).initiative_followthrough.reply_ref,revision:contextOf(ack).initiative_followthrough.revision};
      await context.setExtraHTTPHeaders(actionHeaders());
      const result=await browserPost(page,endpoint,'Yes, save those exact details.',ref);
      check(result.data?.ok===true&&result.data.initiative_action?.status==='completed'&&result.data.tool_receipt?.changes_applied===true,
        endpoint+' saves one actual task and returns the native tool receipt through the same Chat path',result.body);
      const after=await control();check(launchTasks(after).length===launchTasks(seed).length+1&&actionRuns(after).length===1,endpoint+' persists one exact canonical effect');
      const stale=await browserPost(page,endpoint,'Yes, save those exact details again.',ref);
      check(stale.data?.ok!==true&&launchTasks(await control()).length===launchTasks(after).length,endpoint+' rejects a stale second-tab review without another effect',stale.body);
    }finally{await context.close();}
  }
  for(const race of ['source_change','deny_brain','deactivate_agent','account','agent','new_turn']){
    const seed=await control('seed'),proposal=rootPost(seed),plan={adapter:'task.create',arguments:{title:'Never save stale '+race}};
    const context=await browser.newContext({extraHTTPHeaders:actionHeaders(plan)}),page=await context.newPage();await page.goto(base);await ready(page);
    try{
      const reviewed=await browserPost(page,'chat-v236.php?agent=7','Save a task for that launch.',association(proposal));assert.equal(reviewed.data?.ok,true,reviewed.body);
      const ref={message_id:Number(proposal.id),reply_ref:reviewed.data.initiative_followthrough.reply_ref,revision:reviewed.data.initiative_followthrough.revision};
      await context.setExtraHTTPHeaders({...actionHeaders(),...(race==='account'?{'X-Fixture-User':'2'}:{})});
      let pending;
      if(race==='new_turn'){
        const gate='new-turn-'+proposal.id;await context.setExtraHTTPHeaders({...actionHeaders(),'X-Fixture-Followthrough-Gate':gate,'X-Fixture-Followthrough-Gate-Phase':'intent'});
        pending=browserPost(page,'chat-v236.php?agent=7','Yes, save those exact details.',ref);
        await waitFile(path.join(temp,'provider-gate-'+gate+'.entered'));await control('new_turn');fs.writeFileSync(path.join(temp,'provider-gate-'+gate+'.release'),'1');
      }else{
        if(!['account','agent'].includes(race))await control(race);
        pending=browserPost(page,race==='agent'?'chat-v236.php?agent=8':'chat-v236.php?agent=7','Yes, save those exact details.',ref,race==='agent'?{agent_context:{user_agent_id:8}}:{});
      }
      const denied=await pending,after=await control();
      check(denied.data?.ok!==true&&launchTasks(after).length===launchTasks(seed).length&&actionRuns(after)[0].status==='approval_pending',
        race+' before final action denies the effect and leaves its exact review unexecuted',denied.body);
    }finally{await context.close();}
  }

  for(const behavior of ['revise','cancel','complete_source']){
    const seed=await control('seed'),proposal=rootPost(seed),original=behavior==='complete_source'
      ?{adapter:'task.set_status',arguments:{task_id:100,status:'completed'}}:{adapter:'task.create',arguments:{title:'Original reviewed launch task'}};
    const context=await browser.newContext({extraHTTPHeaders:actionHeaders(original)}),page=await context.newPage();await page.goto(base);await ready(page);
    try{
      const reviewed=await browserPost(page,'chat-v236.php?agent=7','Advance that exact launch task.',association(proposal));assert.equal(reviewed.data?.ok,true,reviewed.body);
      const initial=reviewed.data.initiative_action,ref={message_id:Number(proposal.id),reply_ref:reviewed.data.initiative_followthrough.reply_ref,revision:reviewed.data.initiative_followthrough.revision};
      if(behavior==='revise'){
        const revisedPlan={adapter:'task.create',arguments:{title:'Revised exact launch task'}};
        await context.setExtraHTTPHeaders({...actionHeaders(revisedPlan),'X-Fixture-Action-Decision':'revise'});
        const changed=await browserPost(page,'chat-v236.php?agent=7','Change that task title to the revised launch task.',ref);
        check(changed.data?.ok===true&&changed.data.initiative_action?.status==='approval_pending'&&changed.data.initiative_action.arguments.title===revisedPlan.arguments.title,
          'a requested amendment freezes a new exact action review without applying either plan',changed.body);
        const state=await control(),runs=actionRuns(state);
        check(runs.length===2&&runs.find(run=>Number(run.id)===initial.run_id).status==='cancelled'&&launchTasks(state).length===launchTasks(seed).length,
          'revising an action cancels the prior canonical review atomically and creates no domain effect');
        await page.reload();await ready(page);
        await page.locator('[data-initiative-action-run="'+changed.data.initiative_action.run_id+'"]').getByText(revisedPlan.arguments.title,{exact:true}).waitFor();
        await context.setExtraHTTPHeaders(actionHeaders());
        const stale=await browserPost(page,'chat-v236.php?agent=7','Yes, save those exact details.',ref);
        check(stale.data?.ok!==true&&launchTasks(await control()).length===launchTasks(seed).length,'a stale tab cannot approve the replaced review');
        const current=changed.data.initiative_followthrough,newRef={message_id:current.message_id,reply_ref:current.reply_ref,revision:current.revision};
        const saved=await browserPost(page,'chat-v236.php?agent=7','Yes, save those revised exact details.',newRef);
        check(saved.data?.ok===true&&saved.data.initiative_action?.status==='completed'&&saved.data.initiative_action.plan_hash!==initial.plan_hash,
          'only a later exact approval executes the revised immutable action',saved.body);
        const tasks=launchTasks(await control());check(tasks.some(task=>task.subject===revisedPlan.arguments.title)&&!tasks.some(task=>task.subject===original.arguments.title),
          'the revised title reaches the actual canonical task and the superseded task never exists');
      }else if(behavior==='cancel'){
        await context.setExtraHTTPHeaders({...actionHeaders(),'X-Fixture-Followthrough-Intent':'decline','X-Fixture-Action-Decision':'cancel'});
        const cancelled=await browserPost(page,'chat-v236.php?agent=7','Cancel that proposed action.',ref),state=await control();
        check(cancelled.data?.ok===true&&cancelled.data.initiative_action?.status==='cancelled'&&actionRuns(state)[0].status==='cancelled'
          &&launchTasks(state).length===launchTasks(seed).length,'cancelling the exact review closes its canonical action with no task mutation',cancelled.body);
      }else{
        await context.setExtraHTTPHeaders(actionHeaders());
        const saved=await browserPost(page,'chat-v236.php?agent=7','Yes, complete that exact task.',ref),state=await control();
        check(saved.data?.ok===true&&saved.data.initiative_action?.status==='completed'&&JSON.parse(state.task.metadata_json).task_status==='completed',
          'an action may complete its own exact proposal source through a verified before-to-after transition',saved.body);
        await page.reload();await ready(page);
        check(await page.getByText('Completed · verified receipt #'+saved.data.initiative_action.receipt_id,{exact:true}).count()>0,
          'the completed-source action reloads with its real receipt instead of rejecting its own authorized mutation');
        await control('source_change');
        const stale=await browserPost(page,'chat-v236.php?agent=7','Yes, complete that exact task.',ref),later=await control();
        check(stale.data?.ok!==true&&JSON.parse(later.task.metadata_json).task_status==='completed'&&later.task.memory_text==='The saved source has changed'
          &&actionRuns(later).length===1,'a later unrelated source edit invalidates replay without reopening, overwriting or repeating the completed task',stale.body);
      }
    }finally{await context.close();}
  }
  console.log(checks+' actual Initiative action browser checks passed');
}catch(error){console.error(logs.slice(-14000));throw error;}
finally{
  if(browser)await browser.close();
  for(const file of fs.readdirSync(temp).filter(name=>name.endsWith('.entered')))fs.writeFileSync(path.join(temp,file.replace(/\.entered$/,'.release')),'1');
  if(server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(resolve=>server.once('exit',resolve));}
  fs.rmSync(temp,{recursive:true,force:true});
}
