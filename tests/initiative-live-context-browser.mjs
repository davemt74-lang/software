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
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-live-context-browser-'));
const gate=path.join(temp,'model-gate');
fs.cpSync(path.join(source,'includes'),path.join(temp,'includes'),{recursive:true});
fs.mkdirSync(path.join(temp,'api'));
for(const name of ['chat.php','chat-v236.php','chat-stream-v121.php','agent-top-of-mind-v1.php','agent-activity-v94.php','cognitive-presentation-v510.php','chat-settings-v237.php'])fs.copyFileSync(path.join(source,'api',name),path.join(temp,'api',name));
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
// Observe the original exception before the production Chat boundary sanitizes
// it to503. This adds telemetry only to the copied fixture, not a catch/retry.
let chatRuntime=fs.readFileSync(path.join(source,'includes/agent-chat-runtime-v2160.php'),'utf8');
const errorBoundary=/function vp3_agent_chat_followthrough_error_v2160\(Throwable \$error\): VP3InitiativeReplyExceptionV2160\s*\{/;
assert.match(chatRuntime,errorBoundary);
chatRuntime=chatRuntime.replace(errorBoundary,match=>match+'\n    live_browser_exception_v1($error);');
fs.writeFileSync(path.join(temp,'includes/agent-chat-runtime-v2160.php'),chatRuntime);
// The optional dashboard projection is controlled HTTP transport, while its
// actual Initiative work status projection, mutations and voice UI stay real.
let presentation=fs.readFileSync(path.join(source,'includes/cognitive-presentation-v510.php'),'utf8');
for(const name of ['vp3_cognitive_presentation_schema_ready_v510','vp3_cognitive_presentation_state_v510',
  'vp3_cognitive_presentation_touch_v510','vp3_cognitive_presentation_voice_delivered_v510','vp3_cognitive_presentation_voice_suppressed_v510'])
  presentation=presentation.replace('function '+name+'(', 'function live_fixture_unused_'+name+'(');
fs.writeFileSync(path.join(temp,'includes/cognitive-presentation-v510.php'),presentation);
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php'])
  fs.copyFileSync(path.join(source,'tests/fixtures',name),path.join(temp,'includes',name));
fs.writeFileSync(path.join(temp,'includes/initiative-followthrough-chat-fixture.php'),
  fs.readFileSync(path.join(source,'tests/fixtures/initiative-followthrough-chat.php'),'utf8').replace('function ai_generate_chat_response(', 'function followthrough_fixture_ai_generate_chat_response('));
fs.copyFileSync(path.join(source,'tests/fixtures/initiative-capabilities-chat.php'),path.join(temp,'includes/initiative-capabilities-chat-fixture.php'));
fs.copyFileSync(path.join(source,'tests/fixtures/initiative-live-context-chat.php'),path.join(temp,'includes/initiative-live-context-chat-fixture.php'));
let bootstrap=fs.readFileSync(path.join(source,'tests/fixtures/capability-chat-bootstrap.php'),'utf8');
// SQLite has no SELECT FOR UPDATE owner locks. Its transport takes an immediate
// database write lock for the same actual transaction boundaries, preventing a
// deferred read-to-write upgrade from manufacturing SQLITE_BUSY_SNAPSHOT when
// real activity/beacon requests overlap a disposable test-account reset.
bootstrap=bootstrap.replace('class TranscriptFixturePDO extends PDO {',`class TranscriptFixturePDO extends PDO {
 private bool $fixtureTransaction=false;
 public function beginTransaction():bool {parent::exec('BEGIN IMMEDIATE');$this->fixtureTransaction=true;return true;}
 public function inTransaction():bool {return $this->fixtureTransaction;}
 public function commit():bool {parent::exec('COMMIT');$this->fixtureTransaction=false;return true;}
 public function rollBack():bool {parent::exec('ROLLBACK');$this->fixtureTransaction=false;return true;}
`);
bootstrap=bootstrap.replace("$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);", "$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout=10000');");
for(const name of ['chat_remote_answer','ai_generate_chat_response','calendar_schedule_awareness_snapshot_v1330','agent_runtime_v125_idempotency_claim','agent_runtime_v125_idempotency_complete','personal_capability_has_v242'])
  bootstrap=bootstrap.replace('function '+name+'(', 'function capability_fixture_'+name+'(');
bootstrap=bootstrap.replace('declare(strict_types=1);','declare(strict_types=1); session_start();');
// Existing private-file idempotency implementation; only its storage root is
// redirected to this disposable account. No new adapter function is extracted.
const runtime=fs.readFileSync(path.join(source,'includes/agent-runtime-v125.php'),'utf8');
const start=runtime.indexOf('function agent_runtime_v125_idempotency_path('),end=runtime.indexOf('function agent_background_v125_jobs_dir(',start);
assert.ok(start>=0&&end>start);
bootstrap+="\nfunction agent_runtime_v125_root(): string {return dirname(__DIR__).'/runtime-fixture';}\nfunction agent_runtime_v125_dir(string $bucket): string {$dir=agent_runtime_v125_root().'/'.preg_replace('/[^a-z0-9_-]/i','',$bucket);if(!is_dir($dir))mkdir($dir,0700,true);return $dir;}\n"+runtime.slice(start,end);
bootstrap+="\nrequire_once __DIR__.'/initiative-followthrough-chat-fixture.php';\nrequire_once __DIR__.'/initiative-capabilities-chat-fixture.php';\nrequire_once __DIR__.'/initiative-live-context-chat-fixture.php';\n";
bootstrap+=`\nfunction personal_capability_has_v242($key,...$args){return $key==='voice_profile.access'&&isset($_SERVER['HTTP_X_FIXTURE_VOICE_READY'])||capability_fixture_personal_capability_has_v242($key,...$args);}
function ai_generate_chat_response($prompt,...$args): array {
 $options=is_array($args[4]??null)?$args[4]:[];$protocol=(string)($options['protocol']??'');
 if(str_starts_with($protocol,'vp3.initiative-')){ $result=live_browser_model_v1((string)$prompt,$protocol);if(is_array($result))return $result;$result=capabilities_browser_model_v1((string)$prompt,$protocol);if(is_array($result))return $result; }
 return followthrough_fixture_ai_generate_chat_response($prompt,...$args);
}\n`;
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(temp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){$answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);return ['ok'=>true,'answer'=>$answer,'usage'=>[]];}`);
fs.writeFileSync(path.join(temp,'live-control.php'),"<?php require __DIR__.'/includes/bootstrap.php';live_browser_control_v1();");
const assets=['chat.js','chat-voice.js','chat.css','agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','agent-orb.js','agent-orb-adapter.js','agent-eyes-core.js','agent-orb.css','chat-notifications-drawer-v240.js','chat-notifications-drawer-v240.css','agent-screen-share.js','agent-live-context-v1.js','agent-activity-v94.js','chat-cognitive-presentation-v510.js','chat-initiative-voice-v1.js'];
const voiceBootstrap=fs.readFileSync(path.join(source,'chat.php'),'utf8').match(/\$voiceBootstrap = '([^']+)';/)[1];
const router=path.join(temp,'router.php');
fs.writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);$source='+JSON.stringify(source)+';',
  'if($path==="/health"){echo "ready";return;}',
  '$assets='+JSON.stringify(assets)+';',
  'if(in_array(ltrim($path,"/"),$assets,true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'if(str_starts_with($path,"/api/")&&$path!=="/api/chat-notifications-brain-v240.php"||$path==="/live-control.php")return false;',
  'require __DIR__."/includes/bootstrap.php";',
  'if(str_starts_with($path,"/fixture-screen-")){live_browser_media_transport_v1($path);return;}',
  'if($path==="/api/chat-notifications-brain-v240.php"){header("Content-Type:application/json");$aid=current_user()["id"]===1?7:9;echo json_encode(["ok"=>true,"complete"=>true,"agent_namespace"=>"agent:".$aid,"notifications"=>["items"=>[],"unread"=>0],"brain"=>["enabled"=>false],"activity_stream"=>["complete"=>true,"agent_namespace"=>"agent:".$aid,"items"=>[]]]);return;}',
  '$uid=(int)current_user()["id"];$aid=$uid===1?((int)($_GET["agent"]??7)===8?8:7):9;$cid=$uid===1?($aid===8?12:11):13;$binding=vp3_catch_up_page_binding_v1(current_user());',
  '$cfg=["userId"=>$uid,"agentId"=>$aid,"agentName"=>"Longfellow","initialConversationId"=>$cid,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=".$aid,"csrf"=>csrf_token(),"workingAttentionBinding"=>$binding,"agentVoiceEnabled"=>chat_settings_agent_voice_enabled_v237(db(),current_user())];',
  'echo \'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/agent-top-of-mind-v1.css"><link rel="stylesheet" href="/chat-notifications-drawer-v240.css"><link rel="stylesheet" href="/agent-orb.css"><style>body{margin:0}.chat-thread{min-height:0}.chat-topbar{padding:14px 22px;border-bottom:1px solid #e5e7eb}#chatHistory{padding:12px}#fixtureMedia{position:fixed;z-index:10000;right:5px;bottom:5px;width:130px;max-height:80px;overflow:auto;background:white}.vp3-eyes-content{width:100%}.vp3-screen-share video{max-width:100%}</style><body class="chat-page"><div class="chat-app"><aside class="chat-sidebar"><h2>VP3</h2><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside><main class="chat-main"><header class="chat-topbar"><div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Activity Center</button></div><div id="chatProfileMenu">Account</div></div><button id="chatAgentBriefButton"><i data-agent-status-dot></i><span data-agent-attention-badge></span>Agent</button><div id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></div></header><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatLiveContext" hidden></div><div id="chatWelcome" hidden></div></section><div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer"><textarea id="chatInput" placeholder="Message Longfellow…"></textarea><button id="sendChatButton">↑</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div></div></main></div><details id="fixtureMedia"><summary>Controlled media</summary><div class="vp3-eyes-content"></div></details>\';',
  '$drawer=["endpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>csrf_token(),"userId"=>$uid,"agentId"=>$aid,"workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php"];',
  '$orb=["ownerKey"=>(string)$uid,"agentId"=>$aid,"chatUrl"=>"/chat.php","workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php","notificationsEndpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>csrf_token(),"voiceScript"=>"/deliberately-unavailable-voice.js","drawerScript"=>"/chat-notifications-drawer-v240.js","screenEndpoint"=>"/fixture-screen-vision","screenSessionsEndpoint"=>"/fixture-screen-sessions","screenAudioEndpoint"=>"/fixture-screen-audio"];',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($cfg).";window.STONEFELLOW_NOTIFICATION_DRAWER=".json_encode($drawer).";window.VP3_ORB_CONFIG=".json_encode($orb).";window.STONEFELLOW_ACTIVITY=".json_encode(["endpoint"=>"/api/agent-activity-v94.php","csrf"=>csrf_token(),"surface"=>"chat","conversationId"=>$cid]).";window.VP3_COGNITIVE_PRESENTATION_V510=".json_encode(["agentId"=>$aid,"csrf"=>csrf_token(),"workingAttentionBinding"=>$binding,"liveContext"=>true,"initiativeRuntime"=>false]).";</script>";',
  'echo '+JSON.stringify(voiceBootstrap)+';',
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script><script src="/agent-eyes-core.js"></script><script src="/agent-top-of-mind-v1.js"></script><script src="/chat-notifications-drawer-v240.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script><script src="/agent-screen-share.js"></script><script src="/agent-live-context-v1.js"></script><script src="/agent-activity-v94.js"></script><script src="/chat-initiative-voice-v1.js"></script><script src="/chat-cognitive-presentation-v510.js"></script></body></html>\';'
].join('\n'));
const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
const base='http://127.0.0.1:'+port,headers={'X-Fixture-Brain-Ready':'1','X-Fixture-Voice-Ready':'1'};
let logs='',browser,serial=0,groups=0;
const onlyVoice=process.env.VP3_LIVE_BROWSER_ONLY_VOICE==='1';
fs.mkdirSync(path.join(temp,'sessions'));
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+path.join(temp,'sessions'),'-S','127.0.0.1:'+port,'-t',temp,router],{detached:true,
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'4',VP3_LIVE_BROWSER_GATE:gate},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});server.on('error',error=>{logs+=error.message;});
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const control=async(action='state',query={})=>{
  const response=await fetch(base+'/live-control.php?'+new URLSearchParams({action,...query}),{headers:{...headers,'X-Fixture-Request-ID':'live-browser-'+(++serial)}});
  const raw=await response.text();let data;try{data=JSON.parse(raw);}catch{throw new Error(raw);}
  assert.equal(response.status,200,raw);assert.equal(data.ok,true,raw);return data;
};
const until=async(fn,message='Timed out waiting for actual live state.')=>{for(let i=0;i<150;i++){const result=await fn();if(result)return result;await delay(60);}throw new Error(message);};
const ready=page=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
const idle=page=>page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
const contextOf=message=>JSON.parse(message.context_json||'{}');
const currentPacket=state=>{const row=state.activity_rows.find(row=>Number(row.user_id)===1);return row?JSON.parse(row.details_json||'{}').live_context:null;};
const modelCalls=state=>state.calls.filter(row=>row.kind==='live_model').map(row=>JSON.parse(row.payload));
const pass=text=>{groups++;console.log('PASS '+text);};
function verifiedResult(state,id,tool,cid=11){
  const action=state.actions.find(row=>Number(row.run_id)===id),receipt=state.receipts.find(row=>Number(row.run_id)===id);
  assert.equal(action.status,'completed');assert.equal(receipt.status,'completed');assert.equal(action.result_json,receipt.result_json);
  const result=JSON.parse(action.result_json),proof=result.initiative_tool_result?.output;
  assert.equal(proof?.type,'initiative_capability_read');assert.equal(proof.tool,tool);
  assert.equal(proof.owner_user_id,1);assert.equal(proof.agent_id,7);assert.equal(proof.conversation_id,cid);
  assert.match(proof.read_fingerprint,/^[a-f0-9]{64}$/);assert.match(proof.coverage_fingerprint,/^[a-f0-9]{64}$/);
  const post=state.messages.find(row=>Number(row.id)===Number(result.initiative_message_id));
  assert.ok(post,'actual completed work must reach its exact canonical main Chat');assert.deepEqual(contextOf(post).initiative_output,proof);
  return {result,proof,post};
}
async function makeContext(mobile=false){
  const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850},extraHTTPHeaders:headers});
  await context.addInitScript(()=>{
    window.mediaAcquisitions=0;window.speechCalls=0;window.nativeRecordings=0;window.pendingUtterances=[];window.proactiveStarts=0;
    window.addEventListener('stonefellow:agent-proactive-speech',event=>{if(event.detail?.state==='start')window.proactiveStarts++;});
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.speechCalls++;if(window.holdSpeech){window.pendingUtterances.push(u);return;}queueMicrotask(()=>{u.onstart?.();u.onend?.();});}}});
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
    window.StonefellowKnowledgeScopeV162={value:()=>({mode:'off',folder_id:0})};
    // Only native media acquisition and provider transport are controlled. The
    // actual screen producer captures this moving browser canvas and records a
    // real AudioContext stream using Chromium's actual MediaRecorder.
    Object.defineProperty(navigator.mediaDevices,'getDisplayMedia',{value:async options=>{
      window.mediaAcquisitions++;
      const canvas=document.createElement('canvas');canvas.width=640;canvas.height=360;
      const ctx=canvas.getContext('2d');ctx.fillStyle='#dde5ed';ctx.fillRect(0,0,640,360);
      ctx.fillStyle='#173048';ctx.font='26px sans-serif';ctx.fillText('Supplier comparison',30,60);
      const timer=setInterval(()=>{ctx.fillStyle='#173048';ctx.fillRect(600,330,20,20);},60);
      const stream=canvas.captureStream(10),track=stream.getVideoTracks()[0];
      const settings=track.getSettings.bind(track);track.getSettings=()=>({...settings(),displaySurface:'browser'});
      track.addEventListener('ended',()=>clearInterval(timer));
      if(options.audio){const ac=new AudioContext(),oscillator=ac.createOscillator(),gain=ac.createGain(),target=ac.createMediaStreamDestination();
        gain.gain.value=.12;oscillator.connect(gain);gain.connect(target);oscillator.start();await ac.resume();
        const audio=target.stream.getAudioTracks()[0];stream.addTrack(audio);audio.addEventListener('ended',()=>{oscillator.stop();void ac.close();});}
      return stream;
    }});
    const Recorder=window.MediaRecorder;
    window.MediaRecorder=class extends Recorder{start(...args){window.nativeRecordings++;super.start(...args);
      if(window.nativeRecordings===1)setTimeout(()=>{if(this.state==='recording')this.stop();},1200);}};
  });
  return context;
}
async function openPage(context){
  const page=await context.newPage();page.setDefaultTimeout(12000);page.errors=[];page.activityRequests=[];page.sends=[];
  page.on('pageerror',error=>page.errors.push(error.message));
  page.on('request',request=>{
    if(request.method()!=='POST')return;
    if(request.url().includes('/api/agent-activity-v94.php')&&request.headers()['content-type']?.includes('application/json'))page.activityRequests.push({body:request.postDataJSON(),headers:request.headers()});
    if(request.url().includes('/api/chat')&&request.postDataJSON()?.action==='send')page.sends.push(request.postDataJSON());
  });
  await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);await page.bringToFront();
  await page.waitForFunction(()=>window.VP3LiveContext&&window.StonefellowAgentActivity?.coreContext());
  assert.equal(await page.evaluate(()=>window.mediaAcquisitions),0,'opening Chat must not acquire sensors');
  return page;
}
async function startShare(page,audio=false){
  await page.locator('#fixtureMedia').evaluate(node=>node.open=true);
  if(audio)await page.locator('[data-screen-transcribe]').first().check();
  await page.locator('[data-screen-start]').first().click();
  await page.waitForFunction(()=>window.VP3AgentScreenShare?.liveContext()?.some(row=>row.summary.includes('LIVE_SCREEN_CONTENT_SENTINEL')));
  await page.locator('#fixtureMedia').evaluate(node=>node.open=false);
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  return until(async()=>{const state=await control();const packet=currentPacket(state);return packet?.screens?.length?{state,packet}:false;},'The actual activity endpoint never saved the actual current screen packet.');
}
async function emitCue(page,activity,sequence=1){
  await page.evaluate(({activity,sequence})=>{
    const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87;
    window.dispatchEvent(new CustomEvent('vp3:microphone-activity',{detail:{context:{owner_user_id:Number(window.STONEFELLOW_CHAT.userId),agent_id:Number(chat.agentId()),conversation_id:Number(chat.conversationId())},
      observed_at:new Date().toISOString(),sequence,active:true,context_allowed:true,activity,
      quiet_duration_ms:activity==='quiet'?1500:0,observed_window_ms:1200}}));
  },{activity,sequence});
}
async function plan(){const state=await control('plan');assert.equal(state.new_plan,true,JSON.stringify(state));assert.ok(Number(state.prepare_id)>0);return state;}
async function execute(){const state=await control('execute');assert.equal(state.execution_result.ok,true,JSON.stringify(state.execution_result));return state;}
function noRawDurable(state){
  // Test-only media/provider call logs are intentionally omitted. The original
  // activity row may contain current scene details; no history or work record
  // may retain the raw packet, visual sentinel or unclassified microphone data.
  const durable={events:state.activity_events,memory:state.memory_rows,archive:state.archive_rows,runs:state.runs,
    actions:state.actions,receipts:state.receipts,workflow_events:state.events,messages:state.messages};
  const text=JSON.stringify(durable);assert.equal(text.includes('LIVE_SCREEN_CONTENT_SENTINEL'),false);
  assert.equal(text.includes('data:image/'),false);assert.equal(text.includes('provider_label'),false);
}
try{
  for(let attempt=0;;attempt++){try{if((await fetch(base+'/health')).ok)break;}catch{}if(attempt>80)throw new Error('PHP live fixture failed to start.');await delay(50);}
  const transport=(await control('buffer_contract')).transport;
  assert.equal(transport.mode,'wal');assert.equal(transport.original,'"original"');assert.equal(transport.current,'"changed"');assert.equal(transport.transaction,true);
  assert.deepEqual(transport.assoc,{id:1,optional:null,label:'value'});assert.deepEqual(transport.numeric,[2,0,'next']);
  assert.equal(transport.exhausted,false);assert.equal(transport.null,null);assert.deepEqual(transport.remaining,[2]);
  assert.deepEqual(transport.iteration,[[1,null,'value'],[2,0,'next']]);assert.equal(transport.both.optional,null);assert.equal(transport.both[2],'value');
  assert.deepEqual(transport.object,{id:2,optional:0,label:'next'});assert.deepEqual(transport.columns,['value','next']);
  assert.equal(transport.closed,false);assert.deepEqual(transport.pairs,{'1':'value','2':'next'});
  assert.equal(transport.native_modes_match,true,JSON.stringify(transport));
  console.log('PASS actual SQLite buffering preserves fetch modes, nulls, iteration and re-execution; another WAL connection commits before the original connection begins its real owner transaction');
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined,args:['--autoplay-policy=no-user-gesture-required']});
  if(!onlyVoice){
  const repeatMobile=Math.max(1,Math.min(20,Number(process.env.VP3_LIVE_BROWSER_REPEAT_MOBILE)||1));
  for(const mobile of [false,...Array(repeatMobile).fill(true)]){
    await control('seed',{mode:mobile?'list':'live'});const context=await makeContext(mobile);const page=await openPage(context);
    try{
      const {packet}=await startShare(page);
      if(!mobile){await page.evaluate(()=>{
        window.dispatchEvent(new CustomEvent('vp3:participant-presence',{detail:{
          context:{owner_user_id:1,agent_id:7,conversation_id:11},observed_at:new Date().toISOString(),sequence:1,
          active:true,context_allowed:true,greetings_allowed:true,last_event:'arrival',
          rows:[{participant_id:'known-fixture',display_name:'RAW_FACE_NAME_SENTINEL',embedding:[.1,.2]}],
          object_detection_active:true,object_labels:['chair','computer']}}));
      });await emitCue(page,'energy');await delay(800);await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
        assert.equal(await page.evaluate(()=>window.VP3LiveContext.voiceDeferral().defer),true);}
      assert.equal(packet.owner_user_id,1);assert.equal(packet.agent_id,7);assert.equal(packet.conversation_id,11);
      assert.equal(packet.activity_tab,await page.evaluate(()=>window.StonefellowAgentActivity.activityTab()));
      assert.equal(JSON.stringify(packet).includes('data:image/'),false);
      const planned=await plan(),id=Number(planned.prepare_id),goal=JSON.parse(planned.runs.find(row=>Number(row.id)===id).goal);
      if(!mobile){const live=currentPacket(planned);assert.equal(live.eyes.participant_count,1);assert.equal(live.eyes.identity_provisional,true);
        assert.equal(JSON.stringify(live).includes('RAW_FACE_NAME_SENTINEL'),false);assert.equal(JSON.stringify(live).includes('embedding'),false);}
      assert.equal(goal.tool,mobile?'tasks.lists.read':'live.context.read');
      if(mobile){assert.equal(goal.primary,'task-list:600');assert.ok(Object.keys(goal.sources).some(ref=>ref.startsWith('live-context:')));}
      await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-brain]').click();
      if(!mobile)await until(async()=>currentPacket(await control())?.eyes?.recent_chat_input===true);
      const feed=page.locator('[data-agent-top-of-mind]');await feed.locator('[data-top-of-mind-run="'+id+'"][data-work-state="queued"]').waitFor();
      await control('claim');await feed.locator('[data-top-of-mind-run="'+id+'"][data-work-state="working"]').waitFor();
      let state=await execute();const {post}=verifiedResult(state,id,goal.tool);
      await feed.locator('[data-top-of-mind-run="'+id+'"][data-work-state="completed"]').waitFor();
      await page.locator('[data-notification-drawer-close]').click();
      await page.locator('[data-chat-message-id="'+post.id+'"]').waitFor();
      assert.equal(await page.locator('[data-chat-message-id="'+post.id+'"]').count(),1);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      assert.equal(await page.evaluate(()=>window.speechCalls),0,'the screen/Eyes producer must not independently speak');
      const calls=modelCalls(state),draft=JSON.parse(calls.find(row=>row.protocol==='vp3.initiative-draft.v1').prompt);
      assert.equal(draft.tool_result.tool,goal.tool);assert.ok(calls.every(row=>!row.in_transaction));
      if(mobile){const records=JSON.stringify(draft.tool_result.records);assert.ok(records.includes('LIVE_LIST_MEMBER_SENTINEL'));assert.ok(records.includes('completed'));assert.ok(records.includes('waiting'));assert.equal(state.list_actions.length,0);}
      noRawDurable(state);
      // The exact canonical post, not an unrelated general-chat engine, owns
      // the continuation. Actual Chat transport and idempotency stay enabled.
      await page.locator('[data-initiative-reply="'+post.id+'"]').click();
      await page.locator('#chatInput').fill('Help me think through that comparison.');
      if(process.env.VP3_LIVE_BROWSER_HEARTBEAT_STRESS==='1')await page.evaluate(()=>{
        window.followthroughHeartbeatStress=setInterval(()=>window.StonefellowAgentActivity.refreshCoreContext(),40);
      });
      const sending=page.waitForResponse(response=>response.url().includes('/api/chat-v236.php')
        &&response.request().method()==='POST'&&response.request().postDataJSON()?.action==='send');
      await page.locator('#chatInput').press('Enter');
      const response=await sending,body=await response.json();await idle(page);
      if(process.env.VP3_LIVE_BROWSER_HEARTBEAT_STRESS==='1')await page.evaluate(()=>clearInterval(window.followthroughHeartbeatStress));
      state=await control();const marker=contextOf(post).initiative_work.followthrough;
      const diagnostics={status:response.status(),body,activity:state.activity_rows,goals:state.runs.map(row=>({id:row.id,goal:row.goal})),
        phases:modelCalls(state).map(row=>({protocol:row.protocol,in_transaction:row.in_transaction})),
        exceptions:fs.existsSync(gate+'.exceptions.jsonl')?fs.readFileSync(gate+'.exceptions.jsonl','utf8'):''};
      assert.equal(response.status(),200,JSON.stringify(diagnostics));assert.equal(body.ok,true,JSON.stringify(diagnostics));
      assert.deepEqual(page.sends[0].initiative_reply,{message_id:Number(post.id),reply_ref:marker.reply_ref,revision:marker.revision});
      const ack=state.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);assert.ok(ack,JSON.stringify(diagnostics));
      assert.equal(contextOf(ack).initiative_followthrough.state,'discussed');assert.equal(state.calls.some(row=>row.kind==='ordinary_agent_chat'),false);
      if(!mobile){
        // Exercise the production cognitive presentation -> notification center
        // callback. Unclassified microphone energy defers voice, not the text
        // post that has already arrived above.
        await control('voice');await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh(true));await delay(300);
        assert.equal(await page.evaluate(()=>window.speechCalls),0);
        await emitCue(page,'quiet',2);await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh(true));
        await until(async()=>await page.evaluate(()=>window.speechCalls===1),'Proactive voice did not resume after the actual microphone cue cleared.');
        await until(async()=>(await control()).voice_delivered===9001,'Actual legacy voice acknowledgement was not saved.');
        await page.evaluate(()=>window.VP3AgentScreenShare.stop());await startShare(page,true);
        await until(async()=>{const state=await control();return state.calls.some(row=>row.kind==='native_audio_transport');},'The native browser shared-tab recorder did not upload an event-only audio segment.');
        await page.waitForFunction(()=>window.VP3LiveContext.snapshot()?.screens?.some(row=>row.tab_audio?.text===''&&row.tab_audio.audio_events?.some(event=>event.classification==='provider_label')));
        const observed=await page.evaluate(()=>window.VP3LiveContext.snapshot());assert.equal(observed.screens[0].tab_audio.text,'');
        assert.equal(observed.screens[0].tab_audio.audio_events[0].provider,'elevenlabs');
        pass('native browser screen capture and event-only shared-tab audio reach the actual coordinator; sustained microphone energy gates actual proactive voice while real text reports still arrive');
      }
      assert.deepEqual(page.errors,[]);
      pass((mobile?'mobile named-list':'desktop live-context')+' actual source → model-selected canonical tool → queued/working/completed Brain → verified action/receipt → main Chat grounded question → exact owner follow-through');
    }finally{await context.close();}
  }
  // Timestamp-only refresh preserves the actual semantic revision and review
  // cooldown. It cannot keep launching another background assessment.
  await control('seed',{mode:'live'});
  {
    const context=await makeContext();const page=await openPage(context);
    try{
      await startShare(page);await plan();await execute();
      const before=await control(),count=modelCalls(before).length;
      await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
      const same=await control('plan');assert.equal(same.new_plan,false);assert.equal(modelCalls(same).length,count);
      pass('a real unchanged heartbeat refresh preserves the semantic source revision and does not trigger another plan or model call');
      // A second focused tab has fresh authority. Replay the previous tab's
      // captured actual request without focusing it or replacing source reads.
      const old=page.activityRequests.filter(request=>request.body.context?.live_context).at(-1);assert.ok(old);
      const second=await openPage(context);await startShare(second);
      const newTab=await second.evaluate(()=>window.StonefellowAgentActivity.activityTab());
      await until(async()=>currentPacket(await control())?.activity_tab===newTab);
      const response=await context.request.post(base+'/api/agent-activity-v94.php',{data:old.body,
        headers:{'X-VP3-Working-Attention':old.headers['x-vp3-working-attention']}});
      assert.equal(response.status(),200);assert.equal(currentPacket(await control()).activity_tab,newTab);
      pass('two real owner tabs keep the focused current source; an actual delayed old-tab heartbeat cannot overwrite its session');
      await second.close();
    }finally{await context.close();}
  }
  for(const race of ['stop','hidden','conversation','agent','expiry','account']){
    fs.rmSync(gate+'.entered',{force:true});fs.rmSync(gate+'.release',{force:true});await control('seed',{mode:'live'});
    const context=await makeContext();const page=await openPage(context);
    try{
      await startShare(page);const prepared=await plan(),id=Number(prepared.prepare_id);await control('claim');const before=await control();
      const held=control('execute',{hold:'1'});await until(()=>fs.existsSync(gate+'.entered'),'Actual live model did not reach its held transport.');
      assert.equal(JSON.parse(fs.readFileSync(gate+'.entered','utf8')).in_transaction,false);
      if(race==='stop')await page.evaluate(()=>window.VP3AgentScreenShare.stop());
      if(race==='hidden'){
        // Control the browser visibility input because headless tabs do not
        // consistently become hidden. All actual listeners and requests run.
        await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'));});
      }
      if(race==='conversation'){
        await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(14));await ready(page);
      }
      if(race==='agent'){await page.goto(base+'/?agent=8',{waitUntil:'domcontentloaded'});await ready(page);}
      if(race==='expiry'){const expired=await control('expire');assert.equal(expired.expired,true);}
      if(race==='account'){
        await context.setExtraHTTPHeaders({...headers,'X-Fixture-User':'2'});await page.reload({waitUntil:'domcontentloaded'});await ready(page);
      }
      if(!['expiry','account'].includes(race))await until(async()=>!currentPacket(await control()),'The actual '+race+' transition did not clear server current live context.');
      fs.writeFileSync(gate+'.release','1');const state=await held;
      if(race==='account'){
        // Switching the browser account does not revoke the prior account's
        // independent permitted background work. Any still-valid completion
        // must stay entirely in that account's original Chat and Brain.
        const posts=state.messages.filter(row=>Number(contextOf(row).initiative_run_id)===id);
        assert.ok(posts.every(row=>Number(row.conversation_id)===11));
        assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT.userId),2);
        assert.equal((await page.locator('#chatThread').innerText()).includes('supplier comparison'),false);
        assert.equal(await page.locator('[data-chat-message-id]').filter({hasText:'supplier comparison'}).count(),0);
        await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-brain]').click();
        await page.locator('[data-agent-top-of-mind]').getByText('Top of Mind',{exact:true}).waitFor();
        assert.equal(await page.locator('[data-top-of-mind-run="'+id+'"]').count(),0);noRawDurable(state);
        pass('an authenticated account switch during held generation fences the new owner Chat and Brain from every prior-owner completion');
        continue;
      }
      assert.equal(state.execution_result.ok,false,JSON.stringify(state.execution_result));
      assert.equal(state.messages.length,before.messages.length);
      assert.equal(state.messages.some(row=>Number(contextOf(row).initiative_run_id)===id),false);
      assert.equal(state.actions.find(row=>Number(row.run_id)===id).status,'failed');noRawDurable(state);
      pass('actual '+race+' while generation is held invalidates the live source before receipt completion and main Chat delivery');
    }finally{fs.writeFileSync(gate+'.release','1');await context.close();}
  }
  await control('seed',{mode:'live'});
  {
    const context=await makeContext();const page=await openPage(context);
    try{
      await startShare(page);const old=page.activityRequests.filter(request=>request.body.context?.live_context).at(-1);assert.ok(old);
      await context.setExtraHTTPHeaders({...headers,'X-Fixture-User':'2'});await page.reload({waitUntil:'domcontentloaded'});await ready(page);
      assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT.userId),2);
      assert.equal(await page.evaluate(()=>window.VP3LiveContext.snapshot()),null);
      const rejected=await context.request.post(base+'/api/agent-activity-v94.php',{data:old.body,
        headers:{'X-VP3-Working-Attention':old.headers['x-vp3-working-attention']}});
      assert.equal(rejected.status(),409);assert.match((await rejected.json()).error,/account changed/i);
      const state=await control();assert.equal(state.activity_rows.some(row=>Number(row.user_id)===2&&JSON.parse(row.details_json||'{}').live_context),false);
      assert.equal((await page.locator('#chatThread').innerText()).includes('LIVE_SCREEN_CONTENT_SENTINEL'),false);
      pass('authenticated account switching rejects an old bound sensory heartbeat before any new-owner current-source write and keeps the new Chat private');
    }finally{await context.close();}
  }
  }
  // The canonical cognitive POST performs both assessment and preparation.
  // This real voice-selected check-in is a text post first; its optional spoken
  // rendering uses a single canonical receipt claim, never another greeting.
  await control('seed',{mode:'voice'});
  {
    const context=await makeContext();const page=await openPage(context);
    const api=async(page,action,payload={})=>page.evaluate(async({action,payload})=>{
      const cfg=window.STONEFELLOW_CHAT,chat=window.STONEFELLOW_CHAT_CONTINUITY_V87;
      const response=await fetch('/api/cognitive-presentation-v510.php',{method:'POST',headers:{'Content-Type':'application/json',
        'X-VP3-Working-Attention':cfg.workingAttentionBinding},body:JSON.stringify({action,csrf_token:'fixture',
          agent_id:Number(cfg.agentId),conversation_id:Number(chat.conversationId()),working_attention_binding:cfg.workingAttentionBinding,
          activity_tab:window.StonefellowAgentActivity.activityTab(),...payload})});
      return {status:response.status,body:await response.json()};
    },{action,payload});
    const advance=page=>page.evaluate(()=>window.VP3InitiativeVoice.advance({endpoint:'/api/cognitive-presentation-v510.php',csrf:'fixture',
      userId:Number(window.STONEFELLOW_CHAT.userId),agentId:Number(window.STONEFELLOW_CHAT.agentId),
      conversationId:Number(window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()),
      workingAttentionBinding:window.STONEFELLOW_CHAT.workingAttentionBinding,activityTab:window.StonefellowAgentActivity.activityTab()}));
    try{
      const beforeAuth=modelCalls(await control()).length;
      assert.equal((await api(page,'voice_candidates',{csrf_token:'wrong-session'})).status,419);
      const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
      assert.equal((await page.request.get(base+'/api/cognitive-presentation-v510.php?action=voice_candidates&agent_id=7',
        {headers:{'X-VP3-Working-Attention':binding}})).status(),405);
      assert.equal((await page.request.post(base+'/api/cognitive-presentation-v510.php',{headers:{'X-VP3-Working-Attention':'wrong-page'},
        data:{action:'voice_candidates',csrf_token:'fixture',agent_id:7,conversation_id:11}})).status(),403);
      assert.equal(modelCalls(await control()).length,beforeAuth);
      await emitCue(page,'energy');await delay(800);await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
      const revision=(await control()).policy.revision;
      const assessment=await api(page,'initiative_tick',{policy_revision:revision});assert.equal(assessment.status,200);assert.equal(assessment.body.ok,true,JSON.stringify(assessment));
      const prepared=await api(page,'initiative_tick',{policy_revision:revision});assert.equal(prepared.status,200);assert.equal(prepared.body.ok,true,JSON.stringify(prepared));
      const state=await control(),run=state.runs.find(row=>row.capability_key==='initiative.prepare'),action=state.actions.find(row=>Number(row.run_id)===Number(run.id));
      const saved=JSON.parse(action.result_json),post=state.messages.find(row=>Number(row.id)===Number(saved.initiative_message_id));
      assert.equal(JSON.parse(run.goal).tool,'chat.check_in');assert.equal(saved.initiative_tool_result.output.delivery_preference,'voice');
      assert.equal(saved.initiative_delivery,'delivered');assert.ok(post);assert.equal(contextOf(post).initiative_output.type,'initiative_chat_check_in');
      await page.locator('[data-chat-message-id="'+post.id+'"]').waitFor();
      assert.equal(await page.evaluate(()=>window.speechCalls),0);
      const unavailable=await api(page,'voice_candidates');assert.equal(unavailable.status,409,'actual server mic gate must reject voice without suppressing the text post: '+JSON.stringify(unavailable));
      await advance(page);assert.equal(await page.evaluate(()=>window.speechCalls),0);
      await emitCue(page,'quiet',2);await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
      await until(async()=>currentPacket(await control())?.microphone?.activity==='quiet');
      const available=await api(page,'voice_candidates');assert.equal(available.status,200,JSON.stringify(available));assert.equal(available.body.candidates.length,1,JSON.stringify(available));
      await Promise.all([advance(page),advance(page)]);
      await until(async()=>{await advance(page);return page.evaluate(()=>window.speechCalls===1);},'The actual check-in voice client never spoke its claimed canonical text.');
      await until(async()=>{const state=await control(),row=state.actions.find(row=>Number(row.run_id)===Number(run.id));return JSON.parse(row.result_json).initiative_voice?.status==='spoken';});
      await advance(page);assert.equal(await page.evaluate(()=>window.speechCalls),1);
      const second=await openPage(context);await second.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
      await advance(second);assert.equal(await second.evaluate(()=>window.speechCalls),0);
      const duplicate=await api(second,'voice_claim',{run_id:Number(run.id),message_id:Number(post.id),request_id:'11111111-2222-4333-8444-555555555555'});
      assert.equal(duplicate.status,409,'a fresh second tab cannot claim a spoken check-in');
      const final=await control(),a=final.actions.find(row=>Number(row.run_id)===Number(run.id)),r=final.receipts.find(row=>Number(row.run_id)===Number(run.id));
      assert.equal(a.result_json,r.result_json);assert.equal(JSON.parse(a.result_json).initiative_voice.status,'spoken');
      assert.equal(modelCalls(final).filter(row=>row.protocol==='vp3.initiative-draft.v1').length,1);
      assert.deepEqual(page.errors,[]);assert.deepEqual(second.errors,[]);await second.close();
      pass('actual cognitive POST → model-selected short check-in → saved main Chat text → real mic-aware voice API/client → one canonical spoken claim across concurrent calls and two owner tabs');
    }finally{await context.close();}
    for(const scenario of ['lost_claim_ack','typing_before_tts','master_off_before_tts']){
      await control('seed',{mode:'voice'});const c=await makeContext(),p=await openPage(c),claims=[];
      try{
        let settingsTab=null;
        if(scenario==='master_off_before_tts'){
          settingsTab=await c.newPage();await settingsTab.goto(base+'/health');await p.bringToFront();
          await p.evaluate(()=>{window.masterOffSeenFocused=null;window.addEventListener('storage',event=>{
            if(event.key==='vp3:agent-voice-sync:1'&&JSON.parse(event.newValue||'null')?.enabled===false)
              window.masterOffSeenFocused=document.hasFocus();
          });return window.StonefellowAgentActivity.refreshCoreContext();});
        }
        if(scenario==='lost_claim_ack'){
          let lose=true;
          await p.route('**/api/cognitive-presentation-v510.php*',async route=>{
            const request=route.request(),body=request.method()==='POST'?request.postDataJSON():null;
            if(body?.action==='voice_claim'){claims.push(body);if(lose){lose=false;await route.fetch();return route.abort('connectionreset');}}
            return route.continue();
          });
        }else await p.evaluate(()=>window.holdSpeech=true);
        const revision=(await control()).policy.revision;
        for(let i=0;i<2;i++){const tick=await api(p,'initiative_tick',{policy_revision:revision});assert.equal(tick.status,200);assert.equal(tick.body.ok,true);}
        const state=await control(),run=state.runs.find(row=>row.capability_key==='initiative.prepare'),id=Number(run.id);
        const voiceState=async()=>{const state=await control(),row=state.actions.find(row=>Number(row.run_id)===id);return JSON.parse(row.result_json).initiative_voice;};
        if(scenario==='lost_claim_ack'){
          await advance(p);assert.equal(await p.evaluate(()=>window.speechCalls),0);
          assert.equal((await voiceState()).status,'claimed');
          await advance(p);await until(async()=>(await voiceState())?.status==='spoken');
          assert.equal(claims.length,2);assert.equal(claims[0].request_id,claims[1].request_id);
          assert.equal(claims[0].activity_tab,claims[1].activity_tab);assert.equal(claims[0].message_id,claims[1].message_id);
          assert.equal(await p.evaluate(()=>window.speechCalls),1);assert.equal(await p.evaluate(()=>window.proactiveStarts),1);
          await advance(p);assert.equal(await p.evaluate(()=>window.speechCalls),1);
          pass('a lost actual voice-claim acknowledgement retries the same exact request and canonical consumed claim before one playback, with no second model or post');
        }else{
          const speaking=advance(p);await p.waitForFunction(()=>window.pendingUtterances.length===1);
          assert.equal((await voiceState()).status,'claimed');assert.equal(await p.evaluate(()=>window.proactiveStarts),0);
          if(scenario==='master_off_before_tts'){
            assert.equal(await p.evaluate(()=>document.hasFocus()),true);
            const off=await settingsTab.evaluate(async()=>{
              const response=await fetch('/api/chat-settings-v237.php',{method:'POST',headers:{'Content-Type':'application/json'},
                body:JSON.stringify({action:'save_agent_voice',csrf_token:'fixture',agent_voice_enabled:false})});
              const data=await response.json();
              if(response.ok&&data.ok)localStorage.setItem('vp3:agent-voice-sync:1',JSON.stringify({enabled:data.chat.agent_voice_enabled,at:Date.now()}));
              return {status:response.status,data};
            });
            assert.equal(off.status,200,JSON.stringify(off));assert.equal(off.data.chat.agent_voice_enabled,false);
            await p.waitForFunction(()=>window.masterOffSeenFocused===true);
            assert.equal(await p.evaluate(()=>document.hasFocus()),true);
          }else await p.locator('#chatInput').fill('I am composing an owner message.');
          await speaking;await until(async()=>(await voiceState())?.status==='suppressed');
          // A late platform TTS onset is still fenced by the actual canSpeak
          // callback after the owner cancelled the claim through actual typing
          // or the other tab's saved Voice Master setting and storage event.
          await p.evaluate(()=>{for(const u of window.pendingUtterances){u.onstart?.();u.onend?.();}window.pendingUtterances=[];});
          assert.equal(await p.evaluate(()=>window.proactiveStarts),0);
          await p.locator('#chatInput').fill('');await p.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
          await advance(p);assert.equal(await p.evaluate(()=>window.speechCalls),1);
          await p.reload({waitUntil:'domcontentloaded'});await ready(p);await p.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
          await advance(p);assert.equal(await p.evaluate(()=>window.speechCalls),0);
          assert.equal((await voiceState()).status,'suppressed');
          pass((scenario==='master_off_before_tts'?'the other tab\'s actual saved Voice Master OFF and storage event while Chat stays focused':'actual typing')
            +' during held TTS cancels before onset, suppresses the exact claim, and fences late callbacks, pulses and reload replay');
        }
        const final=await control(),a=final.actions.find(row=>Number(row.run_id)===id),r=final.receipts.find(row=>Number(row.run_id)===id);
        assert.equal(a.result_json,r.result_json);assert.equal(modelCalls(final).filter(row=>row.protocol==='vp3.initiative-draft.v1').length,1);
        assert.deepEqual(p.errors,[]);
      }finally{await c.close();}
    }
  }
  console.log((onlyVoice?'INITIATIVE_LIVE_CONTEXT_BROWSER_VOICE_ONLY':'INITIATIVE_LIVE_CONTEXT_BROWSER')+'=PASS ('+groups+' groups)');
}catch(error){console.error('Fixture '+temp+'\n'+logs.split('\n').filter(line=>/\[(?:4|5)[0-9]{2}\]|Fatal|Warning|Error|exception|Cognitive/i.test(line)).slice(-30).join('\n'));throw error;}
finally{
  if(browser)await browser.close();fs.writeFileSync(gate+'.release','1');
  if(server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(resolve=>server.once('exit',resolve));}
  if(!process.env.VP3_LIVE_KEEP_TEMP)fs.rmSync(temp,{recursive:true,force:true});else console.log('Fixture retained at '+temp);
}
