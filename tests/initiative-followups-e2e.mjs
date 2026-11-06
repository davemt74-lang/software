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
fs.writeFileSync(path.join(temp,'includes/initiative-action-chat-fixture.php'),fs.readFileSync(path.join(source,'tests/fixtures/initiative-action-chat.php'),'utf8').replace('function ai_generate_chat_response(', 'function action_fixture_ai_generate_chat_response('));
fs.copyFileSync(path.join(source,'tests/fixtures/initiative-followups-chat.php'),path.join(temp,'includes/initiative-followups-chat-fixture.php'));
bootstrap=bootstrap.replace('function personal_capability_has_v242(', 'function capability_fixture_personal_capability_has_v242(');
bootstrap=bootstrap.replace("function setting($key,$default=''){return $default;}","function setting($key,$default=''){if($key==='personal_capabilities_seed_v242'&&table_exists('settings')){return db()->query(\"SELECT setting_value FROM settings WHERE setting_key='personal_capabilities_seed_v242'\")->fetchColumn()?:$default;}return $default;}");
bootstrap+="\nrequire_once __DIR__.'/initiative-followups-chat-fixture.php';\n";
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(temp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){$answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);return ['ok'=>true,'answer'=>$answer,'usage'=>[]];}`);
fs.writeFileSync(path.join(temp,'followthrough-control.php'),"<?php require __DIR__.'/includes/bootstrap.php';followup_fixture_control();");
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
    const seed=await control('seed'),proposal=rootPost(seed),when=new Date(Date.now()+3*86400000).toISOString().slice(0,10)+'T15:00:00';
    const plan={adapter:'proposal.revisit.create',arguments:{focus:'Revisit the launch assets',start_local:when,timezone:'America/Phoenix'}};
    const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850},extraHTTPHeaders:{...headers,'X-Fixture-Followup-Clarification':'What time and timezone should I use for that revisit?'}});
    const page=await context.newPage();page.setDefaultTimeout(15000);
    try{
      await page.goto(base);await ready(page);await choose(page,proposal.id);await send(page,'Revisit this on Tuesday.');
      let state=await control();check(state.reminders.length===0,'missing timing details cannot create or promise a schedule');
      check(contextOf(latestAck(state)).initiative_followup_clarification===true,'a timing question is saved with the original bound proposal');
      await context.setExtraHTTPHeaders(actionHeaders(plan));await send(page,'Three PM, Phoenix time.');
      state=await control();const review=contextOf(latestAck(state)).initiative_action;
      check(review?.adapter===plan.adapter&&review.arguments.timezone==='America/Phoenix'&&state.reminders.length===0,'a natural answer completes an exact revisit review without another Reply click or write');
      const decision=state.calls.filter(call=>call.kind==='initiative_model').at(-1),prompt=JSON.parse(JSON.parse(decision.payload).prompt);
      check(prompt.prior_owner_replies.some(row=>row.text==='Revisit this on Tuesday.'),'only actual earlier bound owner turns supply the missing time context');
      const card=page.locator('[data-initiative-action-run="'+review.run_id+'"]');await card.waitFor();
      check((await card.textContent()).includes(when)&&(await card.textContent()).includes('America/Phoenix'),'main Chat shows the exact normalized local time and timezone before approval');
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'revisit review fits desktop and mobile Chat');
      await context.setExtraHTTPHeaders(actionHeaders());await card.getByRole('button',{name:'Reply',exact:true}).click();await send(page,'Yes, schedule those exact details.');
      state=await control();const reminder=state.reminders[0],done=contextOf(latestAck(state)).initiative_action;
      check(state.reminders.length===1&&Number(reminder.followup_action_id)>0&&done.status==='completed'&&done.receipt_id>0,'approval saves one real reminder linked to its canonical action and receipt');
      await page.reload();await ready(page);check(await page.locator('[data-initiative-action-reply]').count()===0,'reload does not revive the completed schedule approval');
      const before=state.messages.length;state=await control('tick',{id:reminder.id});check(state.messages.length===before&&state.occurrences.length===1,'time wake uses the existing worker queue without a generic reminder post');
      state=await control('run',{id:reminder.id});const occurrence=state.occurrences[0];
      check(Number(occurrence.message_id)>0&&occurrence.notified_at&&occurrence.presented_at,'worker records exactly one actual natural Chat delivery for the due occurrence');
      await page.reload();await ready(page);await page.getByText('The current launch task is still open. We can review the launch assets next.',{exact:true}).waitFor();
      check(state.calls.filter(call=>call.kind==='followup_model').length===1,'the same main Chat receives one fresh model reassessment');
    }finally{await context.close();}
  }
  console.log(checks+' real scheduled follow-up browser checks passed');
}catch(error){console.error(logs.slice(-14000));throw error;}
finally{
  if(browser)await browser.close();
  for(const file of fs.readdirSync(temp).filter(name=>name.endsWith('.entered')))fs.writeFileSync(path.join(temp,file.replace(/\.entered$/,'.release')),'1');
  if(server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(resolve=>server.once('exit',resolve));}
  fs.rmSync(temp,{recursive:true,force:true});
}
