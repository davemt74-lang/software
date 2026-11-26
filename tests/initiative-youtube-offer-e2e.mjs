import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {cpSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {isDeepStrictEqual} from 'node:util';
import {chromium} from 'playwright';

const repo=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-youtube-offer-'));
const probe=net.createServer();
await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;
await new Promise(resolve=>probe.close(resolve));
const base=`http://127.0.0.1:${port}`;
const apis=['agent-activity-v94.php','agent-activity-hub-v1.php','cognitive-presentation-v510.php','chat-v236.php','chat-stream-v121.php',
  'player-library-v76.php','playback.php'];
mkdirSync(join(temp,'api'));
for(const name of apis)cpSync(join(repo,'api',name),join(temp,'api',name));
cpSync(join(repo,'includes'),join(temp,'includes'),{recursive:true});
mkdirSync(join(temp,'tests','fixtures'),{recursive:true});
for(const name of ['initiative-chat-loop-http.php','initiative-media-chat-http.php','image-generation-v1.php','workflow-agent-schema-v1.php'])
  cpSync(join(repo,'tests','fixtures',name),join(temp,'tests','fixtures',name));
writeFileSync(join(temp,'includes','cognitive-presentation-v510.php'),'<?php');
writeFileSync(join(temp,'includes','ai-settings.php'),`<?php
function ai_encrypt_secret(string $value):string{return 'fixture:'.base64_encode($value);}
`);
const routing=join(temp,'includes','agent-runtime-routing-v420.php');
writeFileSync(routing,readFileSync(routing,'utf8').replace(/function vp3_agent_runtime_plan_v420\([\s\S]*?(?=\nfunction |\Z)/,'') );
const runtime=join(temp,'includes','agent-runtime-v125.php');
writeFileSync(runtime,readFileSync(runtime,'utf8').replace(/function agent_runtime_v125_trace(?:_id)?\([\s\S]*?(?=\nfunction )/g,''));
const imageFixture=join(temp,'tests','fixtures','image-generation-v1.php');
writeFileSync(imageFixture,readFileSync(imageFixture,'utf8')
  .replace("return $GLOBALS['pdo'];","return $GLOBALS['fixtureVoiceDatabase']??$GLOBALS['pdo'];"));
const chatLoopFixture=join(temp,'tests','fixtures','initiative-chat-loop-http.php');
writeFileSync(chatLoopFixture,readFileSync(chatLoopFixture,'utf8')
  .replace('$pdo=new InitiativeHttpPDO',"$pdo=$GLOBALS['fixtureVoiceDatabase']=new InitiativeHttpPDO"));
writeFileSync(join(temp,'includes','bootstrap.php'),`<?php
session_start();
define('STONEFELLOW_ROOT', dirname(__DIR__));
require __DIR__.'/../tests/fixtures/initiative-chat-loop-http.php';
require __DIR__.'/../tests/fixtures/initiative-media-chat-http.php';
require_once __DIR__.'/agent-runtime-v125.php';
require_once __DIR__.'/agent-initiative-youtube-offer-v1.php';
require_once __DIR__.'/agent-runtime-routing-v420.php';
require_once __DIR__.'/ai-runtime-v100.php';
require_once __DIR__.'/ai-gateway-v031.php';
require_once __DIR__.'/ai-usage-accounting-v032.php';
require_once __DIR__.'/recommendations.php';
require_once __DIR__.'/player-v76.php';
require_once __DIR__.'/music-catalog.php';
require_once __DIR__.'/music-listening-v1.php';
function agent_brain_archive_and_parse(array $user,int $cid,int $messageId,string $role,string $message,string $mode):int{return 0;}
if(!table_exists('user_agent_preferences')){
    db()->exec('CREATE TABLE user_agent_preferences(user_id INTEGER, preference_key TEXT, preference_value TEXT);
      CREATE TABLE user_relationships(id INTEGER PRIMARY KEY);
      CREATE TABLE user_data_policy_audit(id INTEGER PRIMARY KEY);
      CREATE TABLE IF NOT EXISTS settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
      ALTER TABLE chat_conversations ADD COLUMN created_at TEXT;');
}
if(!agent_connection_youtube_results_ready_v1(db())){
    agent_connection_youtube_consent_schema_v1(db());
    agent_connection_youtube_results_schema_v1(db());
    require_once __DIR__.'/agent-connection-youtube-quota-v1.php';
    agent_connection_youtube_quota_schema_v1(db());
    vp3_youtube_picks_install_v1(db());
    agent_connection_youtube_set_consent_v1(db(),1,true);
    vp3_youtube_research_save_v1(db(),1,'topic',true);
    vp3_youtube_picks_save_v1(db(),1,'ambient',true);
}
initiative_media_http_install_v1(db());
db()->exec('PRAGMA foreign_keys=ON');
if(!music_listening_v1_schema_ready(db()))music_listening_v1_install(db());
`);
const pcm=Buffer.alloc(8000*2*60);
const wav=Buffer.alloc(44+pcm.length);
wav.write('RIFF');
wav.writeUInt32LE(36+pcm.length,4);
wav.write('WAVEfmt ',8);
wav.writeUInt32LE(16,16);
wav.writeUInt16LE(1,20);
wav.writeUInt16LE(1,22);
wav.writeUInt32LE(8000,24);
wav.writeUInt32LE(16000,28);
wav.writeUInt16LE(2,32);
wav.writeUInt16LE(16,34);
wav.write('data',36);
wav.writeUInt32LE(pcm.length,40);
pcm.copy(wav,44);
writeFileSync(join(temp,'native.wav'),wav);
cpSync(join(repo,'media.php'),join(temp,'media.php'));
const router=join(temp,'router.php');
writeFileSync(router,`<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/health'){echo 'ready';return;}
if(in_array($path,['/chat.js','/assets/js/music-listening-v1.js','/chat-youtube-v1.js','/agent-activity-v94.js','/chat-cognitive-presentation-v510.js'],true)){
    header('Content-Type: text/javascript');readfile(${JSON.stringify(repo)}.ltrim($path,'/'));return;
}
if(str_starts_with($path,'/api/')){require __DIR__.$path;return;}
if($path==='/media.php'&&($_GET['type']??null)==='audio'){require __DIR__.'/media.php';return;}
require __DIR__.'/includes/bootstrap.php';
if($path==='/fixture-state'){
    // Keep the actual persisted checkpoint visible after a completed result
    // invalidates its public projection. Use the production scope and hash.
    [$scanScope,$scanParams]=vp3_agent_memory_scope_sql_v410(7);
    $scanHash=vp3_agent_memory_scope_hash_v410(7,agent_brain_v122_memory_hash('initiative_sweep','coordination-scan'));
    $read=db()->prepare('SELECT id,user_id,user_agent_id,memory_type,subject,memory_hash,memory_scope_version,is_active,metadata_json
        FROM agent_memory_items WHERE user_id=? AND '.$scanScope.' AND memory_hash=? LIMIT 1');
    $read->execute([1,...$scanParams,$scanHash]);
    $scan=$read->fetch(PDO::FETCH_ASSOC)?:null;
    if($scan!==null){
        $metadata=json_decode((string)$scan['metadata_json'],true);
        $scan['metadata']=is_array($metadata)?$metadata:null;
    }
    header('Content-Type:application/json');
    echo json_encode([
        'server_time'=>time(),'coordination_scan_hash'=>$scanHash,'coordination_scan'=>$scan,
        'runs'=>db()->query('SELECT id,owner_user_id,agent_id,capability_key,status,goal FROM agent_workflow_runs ORDER BY id')->fetchAll(),
        'actions'=>db()->query('SELECT id,run_id,owner_user_id,sequence_no,action_key,capability_key,execution_target,attempt_count,status,result_json FROM agent_workflow_actions ORDER BY id')->fetchAll(),
        'receipts'=>db()->query('SELECT * FROM agent_workflow_receipts ORDER BY id')->fetchAll(),
        'plays'=>db()->query('SELECT id,user_id,track_id,source_context,last_position_seconds FROM track_play_sessions ORDER BY id')->fetchAll(),
        'messages'=>db()->query('SELECT id,conversation_id,role,message,context_json FROM chat_messages ORDER BY id')->fetchAll(),
        'youtube_attempts'=>(int)db()->query('SELECT COALESCE(SUM(attempts),0) FROM agent_connection_youtube_usage_v1')->fetchColumn(),
        'email_drafts'=>table_exists('agent_connection_email_drafts_v1')
            ?db()->query('SELECT recipient,status FROM agent_connection_email_drafts_v1')->fetchAll():[],
        'email_sends'=>table_exists('agent_connection_sends_v1')?(int)db()->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn():0,
        'activity'=>json_decode((string)db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=1')->fetchColumn(),true),
    ],JSON_THROW_ON_ERROR);return;
}
if($path==='/fixture-youtube-arrival'){
    $id=(int)db()->query("SELECT id FROM chat_messages WHERE conversation_id=11 AND role='assistant' ORDER BY id LIMIT 1")->fetchColumn();
    db()->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute(['{"youtube_search_results_v1":true}',$id]);
    agent_connection_youtube_results_save_v1(db(),1,11,$id,['query'=>'ambient music','music_suggestion'=>true,'suggestion_kind'=>'music',
      'owner_topic'=>'ambient music',
      'expires_at'=>time()+3600,'videos'=>[['video_id'=>'abcdefghijk','title'=>'Quiet signal','channel_title'=>'Fixture Artist','made_for_kids'=>false]]]);
    db()->exec("UPDATE agent_memory_items SET is_active=0 WHERE subject='coordination-scan'");
    header('Content-Type:application/json');echo json_encode(['id'=>$id]);return;
}
if($path==='/fixture-youtube-revoke'){
    vp3_youtube_research_save_v1(db(),1,'topic',false);
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-media-arm-resume'){
    initiative_media_http_arm_resume_v1(db());
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-media-revoke'){
    require_once __DIR__.'/includes/agent-media-entertainment-v1.php';
    vp3_media_offers_save_v1(db(),1,false);
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-media-arm-cold-catalog'){
    initiative_media_http_arm_cold_catalog_v1(db());
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-media-arm-casual'){
    initiative_media_http_arm_casual_v1(db());
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-casual-enable'){
    require_once __DIR__.'/includes/agent-casual-checkin-settings-v1.php';
    vp3_casual_checkin_save_v1(db(),1,true);
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-casual-enable-youtube'){
    agent_connection_youtube_set_consent_v1(db(),1,true);
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-casual-enable-gmail'){
    initiative_media_http_mailbox_v1(db());
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-curiosity-route'){
    require_once __DIR__.'/includes/agent-coordination-rules-v1.php';
    $action=$_GET['action']??null;
    if(!in_array($action,['ask','watch'],true))throw new InvalidArgumentException('Expected an explicit Curiosity route.');
    $before=vp3_coordination_rule_settings_v1(db(),current_user(),7,17);
    $saved=vp3_coordination_rule_settings_v1(db(),current_user(),7,17,$before['revision'],
        ['curiosity'=>['open_question'=>$action]]);
    header('Content-Type:application/json');echo json_encode([
        'owner_user_id'=>$saved['owner_user_id'],'agent_id'=>$saved['agent_id'],'conversation_id'=>$saved['conversation_id'],
        'before'=>$before['rules'],'saved'=>$saved['rules'],
    ],JSON_THROW_ON_ERROR);return;
}
if($path==='/fixture-casual-readiness'){
    require_once __DIR__.'/includes/agent-idle-evidence-v1.php';
    require_once __DIR__.'/includes/agent-casual-checkin-v1.php';
    $scope=vp3_agent_activity_scope_v1(db(),current_user(),7,17);
    $now=time();$hold=null;$watermark=null;
    $ready=vp3_casual_checkin_media_idle_v1(db(),$scope['user'],17,
        vp3_casual_checkin_delivery_v1(db(),1),$now,$hold,$watermark);
    header('Content-Type:application/json');echo json_encode([
        'owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],
        'conversation_id'=>$scope['conversation_id'],'server_time'=>$now,
        'ready'=>$ready,'hold'=>$hold,'activity_watermark'=>$watermark,
        'idle'=>vp3_agent_idle_evidence_v1(db(),$scope['user'],7,17,new DateTimeImmutable('@'.$now)),
    ],JSON_THROW_ON_ERROR);return;
}
if($path==='/fixture-casual-prerequisites'){
    require_once __DIR__.'/includes/agent-casual-checkin-v1.php';
    $result=vp3_initiative_in_scope_v1(current_user(),7,static function():array{
        $scope=vp3_agent_activity_scope_v1(db(),current_user(),7,17);
        $policy=vp3_initiative_policy_v1($scope['user'],7);
        $delivery=vp3_casual_checkin_delivery_v1(db(),1);
        $sources=vp3_casual_checkin_sources_v1(db(),$scope,$policy,null,new DateTimeImmutable('now',new DateTimeZone('UTC')),
            allCandidates:true,allowStartersWithPrivateWork:true);
        $starters=[];$eligible=[];
        foreach(VP3_SPECIALIST_STARTERS_V1 as $kind=>$definition){
            $ref='casual:'.$kind;$starters[$ref]=$definition['question'];
            if(isset($sources['items'][$ref]))$eligible[$ref]=$sources['items'][$ref]['revision'];
        }
        $hold=null;$watermark=null;
        $mediaReady=vp3_casual_checkin_media_idle_v1(db(),$scope['user'],17,$delivery,time(),$hold,$watermark);
        return ['policy'=>$policy,'starters'=>$starters,'eligible'=>(object)$eligible,
            'offered'=>(object)vp3_casual_starter_offers_v1($delivery),'media_ready'=>$mediaReady,'media_hold'=>$hold];
    });
    header('Content-Type:application/json');echo json_encode($result,JSON_THROW_ON_ERROR);return;
}
if($path==='/fixture-coordination-wake'){
    db()->exec("UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND subject='coordination-scan'");
    if(($_GET['elapsed']??'')==='1')db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=17");
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-catalog-availability'){
    db()->prepare('UPDATE tracks SET is_published=? WHERE id IN (41,42)')->execute([($_GET['published']??'')==='1'?1:0]);
    db()->exec("UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND subject='coordination-scan'");
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
if($path==='/fixture-catalog-after-cooldown'){
    db()->exec("DELETE FROM settings WHERE setting_key='media_next_offer_owner_1';
        UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND subject='coordination-scan'");
    if(($_GET['activity']??'')==='owner')db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
        (16,1,'user','I am back in Chat after working on the launch.'),(16,NULL,'assistant','Ready for the next topic.')");
    header('Content-Type:application/json');echo '{"ok":true}';return;
}
$binding=vp3_catch_up_page_binding_v1(current_user());
$chat=['userId'=>1,'agentId'=>7,'initialConversationId'=>(int)($_GET['conversation_id']??11),'initialView'=>'chat',
    'endpoint'=>'/api/chat-v236.php?agent=7','csrf'=>csrf_token(),'workingAttentionBinding'=>$binding,
    'libraryEndpoint'=>'/api/player-library-v76.php','playbackEndpoint'=>'/api/playback.php'];
$presentation=['agentId'=>7,'csrf'=>csrf_token(),'workingAttentionBinding'=>$binding,'liveContext'=>true,'initiativeRuntime'=>true];
echo '<!doctype html><button id="newChatButton">New Chat</button><div id="chatHistory"></div><aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div></aside>'
  .'<section id="chatThread" data-view="chat"><div id="chatWelcome"></div><section id="chatLiveContext" hidden></section></section>'
  .'<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form>'
  .'<script>window.STONEFELLOW_CHAT='.json_encode($chat).';window.VP3_COGNITIVE_PRESENTATION_V510='.json_encode($presentation)
  .';window.STONEFELLOW_ACTIVITY={endpoint:"/api/agent-activity-v94.php",csrf:"fixture",surface:"chat"};'
  .'window.VP3_YOUTUBE_PLAYBACK_V1={endpoint:"/playback",connections:"/connections.php",csrf:"fixture"};</script>'
  .'<script src="/chat-youtube-v1.js"></script><script src="/assets/js/music-listening-v1.js"></script><script src="/chat.js"></script><script src="/agent-activity-v94.js"></script><script src="/chat-cognitive-presentation-v510.js"></script>';
`);
const php=process.env.VP3_PHP_BINARY||'php';
let logs='';
const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',temp,router],{env:{...process.env,
  VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'youtube.sqlite'),VP3_YOUTUBE_API_KEY:'fixture_youtube_api_key_unused',
  VP3_INITIATIVE_HTTP_MEDIA:'1',
  VP3_GMAIL_CLIENT_ID:'fixture_gmail_client_id',VP3_GMAIL_CLIENT_SECRET:'fixture_gmail_secret_unused',
  VP3_GMAIL_REDIRECT_URI:'https://example.test/connection-oauth.php'},stdio:['ignore','pipe','pipe']});
server.stderr.on('data',data=>{logs+=data;});
let browser;
let page;
const failed=[];
const initiativeTrace=[];
const traceInitiative=entry=>{
  initiativeTrace.push({at:Date.now(),...entry});
  if(initiativeTrace.length>80)initiativeTrace.shift();
};
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>60)throw new Error('Fixture PHP did not start.');
    await new Promise(resolve=>setTimeout(resolve,30));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage();
  page.on('pageerror',error=>failed.push(error.message));
  page.on('request',request=>{
    if(!request.url().startsWith(base+'/api/'))return;
    let data;try{data=request.postDataJSON();}catch{return;}
    if(data?.action==='initiative_tick')traceInitiative({event:'request',action:data.action,
      conversation_id:data.conversation_id,policy_revision:data.policy_revision});
  });
  page.on('response',async response=>{if(response.url().startsWith(base)&&(!response.ok()||response.url().includes('/api/'))){
    const text=await response.text().catch(()=>'');
    if(!response.ok()||text.includes('Fatal error')||!text.startsWith('{'))failed.push(`${response.status()} ${response.url()}: ${text.slice(0,500)}`);
    let data,body;try{data=response.request().postDataJSON();body=JSON.parse(text);}catch{return;}
    if(data?.action==='initiative_tick')traceInitiative({event:'response',action:data.action,
      conversation_id:data.conversation_id,status:response.status(),ok:body.ok,reason:body.reason,
      step:body.step,run_id:body.run_id,coordination:body.coordination,coordination_failure:body.coordination_failure,
      health:body.health});
    if(data?.action==='heartbeat')traceInitiative({event:'heartbeat',conversation_id:data.context?.conversation_id,
      status:response.status(),ok:body.ok,reason:data.reason,context:{activity_tab:data.context?.activity_tab,
        chat_focused:data.context?.chat_focused,chat_busy:data.context?.chat_busy,media_playing:data.context?.media_playing,
        owner_interaction_at_ms:data.context?.owner_interaction_at_ms},
      core_health:body.agent_core_context?.health,core_error:body.agent_core_context_error?.reason});
  }});
  await page.route('https://www.youtube.com/embed/**',route=>route.fulfill({contentType:'text/html',body:'<title>YouTube fixture</title>'}));
  await page.route('https://www.youtube.com/iframe_api',route=>route.fulfill({contentType:'text/javascript',body:''}));
  const opened=await page.goto(base,{waitUntil:'domcontentloaded'});
  const boot=await opened.text();
  assert.ok(opened.ok()&&boot.startsWith('<!doctype html>'),boot.slice(0,1500));
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring(),null,{timeout:8000});
  const arrival=await page.request.post(base+'/fixture-youtube-arrival');
  assert.ok(arrival.ok(),await arrival.text());
  const sourceId=(await arrival.json()).id;
  const offer=page.locator('[data-chat-message-role="assistant"]').filter({hasText:'Want me to play “Quiet signal” here?'});
  await offer.waitFor({timeout:40000});
  assert.equal(await page.locator('.vp3-youtube-frame').count(),0);
  const state=async()=>{
    const response=await page.request.get(base+'/fixture-state');
    const text=await response.text();
    assert.ok(response.ok(),text);
    return JSON.parse(text);
  };
  const pollFixture=async(path,accept,{timeout=30000,label=path}={})=>{
    const deadline=Date.now()+timeout;
    while(Date.now()<deadline){
      const response=await page.request.get(base+path,{timeout:Math.max(1,deadline-Date.now())});
      const text=await response.text();
      assert.ok(response.ok(),text);
      const snapshot=JSON.parse(text);
      const accepted=await accept(snapshot);
      assert.equal(typeof accepted,'boolean','A fixture poll must evaluate the saved response condition.');
      if(accepted)return snapshot;
      const remaining=deadline-Date.now();
      if(remaining>0)await new Promise(resolve=>setTimeout(resolve,Math.min(250,remaining)));
    }
    assert.fail(label+' did not become ready within '+timeout+'ms.');
  };
  const posted=await state();
  const coordinationRuns=(snapshot,primary)=>snapshot.runs.filter(row=>{
    const goal=JSON.parse(row.goal);
    return goal.tool==='coordination.review'&&goal.primary===primary;
  });
  const completedMediaScan=(before,after,tick,conversation,revision)=>{
    const row=after.coordination_scan,prior=before.coordination_scan;
    assert.ok(row,'The completed tick must persist its real source scan.');
    assert.deepEqual([row.user_id,row.user_agent_id,row.memory_type,row.subject,row.memory_scope_version],
      [1,7,'initiative_sweep','coordination-scan',410]);
    assert.equal(row.memory_hash,after.coordination_scan_hash);
    assert.equal(row.is_active,0,'The verified new result invalidates the public scan projection.');
    if(prior){
      assert.equal(row.id,prior.id,'The coordinator updates its canonical scoped checkpoint.');
      assert.equal(row.memory_hash,prior.memory_hash);
      assert.notEqual(row.metadata_json,prior.metadata_json,'This tick must replace the previous scan evidence.');
    }
    const metadata=row.metadata;
    assert.ok(metadata&&typeof metadata==='object');
    assert.equal(metadata.conversation_id,conversation);
    assert.equal(metadata.revision,revision);
    assert.deepEqual(metadata.agent_scope,{version:'v4.10',user_agent_id:7,agent_kind:'user_agent',conversation_id:conversation});
    assert.equal(metadata.outcome,tick.coordination);
    assert.ok(Number.isInteger(metadata.scanned_at)&&metadata.scanned_at>=before.server_time
      &&metadata.scanned_at<=after.server_time,'The scan must belong to this observed tick, including within one second.');
    assert.ok(metadata.specialists?.media);
    return metadata.specialists.media;
  };
  const verifiedIndependentPost=(before,after,tick,conversation,revision,allowed)=>{
    const diagnostic=JSON.stringify({tick:tick,
      run:after.runs.find(row=>row.id===tick.run_id),
      actions:after.actions.filter(row=>row.run_id===tick.run_id),
      receipts:after.receipts.filter(row=>row.run_id===tick.run_id),
      newMessages:after.messages.filter(row=>row.conversation_id===conversation
        &&!before.messages.some(previous=>previous.id===row.id))});
    const handoff=after.runs.find(row=>row.id===tick.run_id);
    assert.equal(handoff?.status,'completed',diagnostic);
    assert.equal(handoff.owner_user_id,1);
    assert.equal(handoff.agent_id,7);
    const goal=JSON.parse(handoff.goal);
    assert.equal(goal.tool,'coordination.review');
    const route=goal.coordination_route;
    const curiosity=route?.specialist==='curiosity'&&route.trigger==='open_question'&&route.action==='ask'
      &&goal.primary.startsWith('curiosity:');
    const starter=['starter-image','starter-mission'].find(kind=>route?.specialist==='casual'
      &&route.trigger===kind&&route.action==='ask'&&goal.primary==='casual:'+kind);
    assert.ok(allowed.includes(curiosity?'curiosity':starter?'casual:'+starter:''),diagnostic);
    assert.equal(tick.coordination,curiosity?'queued_chat_handoff':'queued_checkin',diagnostic);
    assert.equal(goal.binding.owner_user_id,1);
    assert.equal(goal.binding.agent_id,7);
    assert.equal(goal.binding.conversation_id,conversation);
    assert.equal(goal.binding.revision,revision);
    const actions=after.actions.filter(row=>row.run_id===handoff.id&&row.owner_user_id===1
      &&row.sequence_no===1&&row.action_key==='initiative.prepare'&&row.capability_key==='initiative.prepare'
      &&row.execution_target==='cloud');
    assert.equal(actions.length,1);
    const action=actions[0];
    assert.equal(action.status,'completed');
    const receipts=after.receipts.filter(row=>row.run_id===handoff.id&&row.owner_user_id===1
      &&row.action_id===action.id&&row.attempt_no===action.attempt_count&&row.executor==='cloud'&&row.status==='completed');
    assert.equal(receipts.length,1);
    const handoffReceipt=receipts[0];
    assert.equal(action.result_json,handoffReceipt.result_json);
    const result=JSON.parse(handoffReceipt.result_json);
    assert.equal(result.initiative_delivery,'delivered');
    assert.equal(result.initiative_tool_result.output.type,'initiative_coordination');
    assert.equal(result.initiative_tool_result.output.situation_id,goal.coordination_id);
    assert.deepEqual(result.initiative_tool_result.output.evidence_revisions,goal.sources);
    assert.deepEqual(Object.keys(goal.sources),[goal.primary]);
    const posted=after.messages.find(row=>row.id===Number(result.initiative_message_id));
    assert.equal(posted?.conversation_id,conversation);
    assert.equal(posted.role,'assistant');
    assert.equal(posted.message,result.initiative_draft);
    const postedContext=JSON.parse(posted.context_json);
    assert.equal(postedContext.source,'agent_initiative');
    assert.equal(postedContext.initiative_run_id,handoff.id);
    assert.deepEqual(postedContext.initiative_output,result.initiative_tool_result.output);
    assert.deepEqual([postedContext.initiative_work.owner_user_id,postedContext.initiative_work.agent_id,
      postedContext.initiative_work.conversation_id,postedContext.initiative_work.run_id],[1,7,conversation,handoff.id]);
    assert.deepEqual(after.messages.filter(row=>row.conversation_id===conversation
      &&!before.messages.some(previous=>previous.id===row.id)).map(row=>row.id),[posted.id],
    'The invalidated checkpoint requires exactly one newly saved independent report.');
    return {run:handoff,goal,action,receipt:handoffReceipt,result,post:posted};
  };
  const youtubeRef=`media:youtube:${sourceId}`;
  const run=coordinationRuns(posted,youtubeRef)[0];
  assert.equal(run?.status,'completed');
  const receipt=posted.receipts.find(row=>row.run_id===run.id&&row.status==='completed');
  assert.ok(receipt);
  const delivered=JSON.parse(receipt.result_json);
  assert.equal(delivered.initiative_delivery,'delivered');
  assert.equal(delivered.initiative_tool_result.output.type,'initiative_coordination');
  assert.deepEqual(Object.keys(delivered.initiative_tool_result.output.evidence_revisions),[youtubeRef]);
  assert.equal(receipt.result_json.includes('abcdefghijk'),false,'The report does not persist provider playback identifiers.');
  const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding);
  const activity=async(conversation=11,view='media')=>{
    const response=await page.request.get(base+`/api/agent-activity-hub-v1.php?agent_id=7&conversation_id=${conversation}&view=${view}`,
      {headers:{'X-VP3-Working-Attention':binding}});
    const text=await response.text();
    assert.ok(response.ok()&&text.startsWith('{'),text.slice(0,1500));
    return JSON.parse(text).state;
  };
  const media=(await activity()).media_report.findings.find(item=>item.id===youtubeRef);
  assert.equal(media?.title,'Quiet signal');
  assert.match(media.summary,/ambient music.*Quiet signal/u);
  assert.equal(media.question,'Want me to play “Quiet signal” here?');
  assert.match(media.actions[0].href,new RegExp(`message_id=${sourceId}$`));
  assert.ok(!(await activity(15)).media_report.findings.some(item=>item.id===youtubeRef),'A sibling Chat cannot discover this exact search.');
  const post=async(text)=>{
    const response=page.waitForResponse(response=>response.url().includes('/api/chat-v236.php')&&response.request().postDataJSON()?.action==='send');
    await page.locator('#chatInput').fill(text);
    await page.getByRole('button',{name:'Send',exact:true}).click();
    const replyResponse=await response;
    const replyBody=await replyResponse.text();
    assert.ok(replyResponse.ok(),replyBody);
    const reply=JSON.parse(replyBody);
    assert.ok(reply.ok,JSON.stringify(reply));
    return reply;
  };
  const reply=await post('Yes, play it.');
  assert.equal(reply.youtube_results.play_selected,true);
  assert.equal(reply.youtube_results.selected_video_id,'abcdefghijk');
  await page.locator('iframe[src*="youtube.com/embed/abcdefghijk"][src*="autoplay=1"]').waitFor();
  await page.reload({waitUntil:'domcontentloaded'});
  await offer.waitFor();
  assert.equal(await offer.count(),1);
  assert.equal(await page.locator('.vp3-youtube-frame').count(),0);
  assert.equal(coordinationRuns(await state(),youtubeRef).length,1);
  await page.request.post(base+'/fixture-youtube-revoke');
  assert.equal((await activity()).media_report.findings.find(item=>item.id===youtubeRef)?.question,'');
  console.log('PASS saved YouTube search → Media report → shared Top of Mind receipt → exact Chat playback without replay');

  const armed=await page.request.post(base+'/fixture-media-arm-resume');
  assert.ok(armed.ok(),await armed.text());
  await page.goto(base+'/?conversation_id=14',{waitUntil:'domcontentloaded'});
  const resumeOffer=page.locator('[data-chat-message-role="assistant"]').filter({hasText:'Want to pick up where you left off?'});
  await resumeOffer.waitFor({timeout:40000});
  assert.match(await resumeOffer.innerText(),/Native Midnight.*0:24/u);
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  const native=(await activity(14)).media_report.findings.find(item=>item.id==='media:resume:41');
  assert.equal(native?.title,'Native Midnight');
  assert.equal(native.next_step,'Continue from 0:24.');
  assert.equal(native.actions[0].href,'/track.php?id=41');
  const beforePlay=await state();
  const nativeRun=coordinationRuns(beforePlay,'media:resume:41')[0];
  assert.equal(nativeRun?.status,'completed');
  const nativeReceipt=JSON.parse(beforePlay.receipts.find(row=>row.run_id===nativeRun.id&&row.status==='completed').result_json);
  assert.equal(nativeReceipt.initiative_delivery,'delivered');
  assert.deepEqual(Object.keys(nativeReceipt.initiative_tool_result.output.evidence_revisions),['media:resume:41']);
  const overview=await activity(14,'');
  assert.ok(overview.coordination_findings.some(item=>
    item.title==='Native Midnight'&&item.actions.some(action=>action.message_id===Number(nativeReceipt.initiative_message_id))),
  JSON.stringify(overview.coordination_findings));
  assert.ok(!(await activity(14)).media_report.findings.some(item=>item.title==='PRIVATE FOREIGN TRACK'));
  const playbackRequest=page.waitForRequest(request=>request.url().includes('/api/player-library-v76.php')
    &&request.postDataJSON()?.action==='playback_action');
  const nativeStarts=[];
  const recordNativeStart=request=>{
    if(new URL(request.url()).pathname==='/api/playback.php'&&request.method()==='POST'
      &&request.postDataJSON()?.action==='start')nativeStarts.push(request.postDataJSON());
  };
  page.on('request',recordNativeStart);
  const playbackStarted=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/playback.php'
    &&response.request().method()==='POST'&&response.request().postDataJSON()?.action==='start'
    &&response.request().postDataJSON()?.track_id===41);
  const resumed=await post('Yes.');
  assert.equal(resumed.media_playback?.operation,'resume');
  assert.equal(resumed.media_playback.track_id,41);
  assert.equal(resumed.media_playback.position_seconds,24);
  const action=(await playbackRequest).postDataJSON().media_playback;
  assert.equal(action.track_id,41);
  assert.equal(action.conversation_id,14);
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='41'&&!audio.paused&&audio.currentTime>=24),null,{timeout:10000});
  const startedResponse=await playbackStarted;
  const started=await startedResponse.json();
  assert.ok(startedResponse.ok()&&started.ok,JSON.stringify(started));
  const active=await state();
  assert.deepEqual(active.plays.filter(row=>!beforePlay.plays.some(previous=>previous.id===row.id)).map(row=>({
    id:row.id,user_id:row.user_id,track_id:row.track_id,source_context:row.source_context
  })),[{id:started.session_id,user_id:1,track_id:41,source_context:'agent_chat'}],
  'Acceptance completes exactly one new native playback session before the reload baseline.');
  assert.equal(nativeStarts.length,1);
  const otherChat=await page.request.post(base+'/api/player-library-v76.php',{
    data:{action:'playback_action',csrf_token:'fixture',media_playback:{...action,conversation_id:15}}
  });
  assert.equal((await otherChat.json()).ok,false,'A signed native action cannot be moved to a sibling Chat.');
  await page.reload({waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  assert.equal(nativeStarts.length,1,'Reload cannot request another playback session.');
  assert.deepEqual((await state()).plays.map(row=>row.id),active.plays.map(row=>row.id),
    'Reload cannot start another playback session.');
  page.off('request',recordNativeStart);
  assert.equal(coordinationRuns(await state(),'media:resume:41').length,1);
  await page.request.post(base+'/fixture-media-revoke');
  console.log('PASS native saved position → Media report and exact shared offer → “yes” resumes the VP3 Player at 0:24 → scope and replay guards');

  const draft=await post('Create a VP3 music playlist for calm.');
  assert.equal(draft.media_playback?.operation,'playlist');
  assert.deepEqual(draft.media_playback.queue.map(item=>item.track_id).sort((a,b)=>a-b),[41,42]);
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  const dj=await post('Start DJ with calm.');
  assert.equal(dj.media_playback?.operation,'dj_start');
  assert.deepEqual(dj.media_playback.queue.map(item=>item.track_id).sort((a,b)=>a-b),[41,42]);
  const [firstTrack,nextTrack]=dj.media_playback.queue.map(item=>item.track_id);
  const firstAudio=page.locator(`[data-media-queue-audio][data-track-id="${firstTrack}"]`);
  await firstAudio.waitFor({state:'attached'});
  await page.waitForFunction(id=>{
    const audio=document.querySelector(`[data-media-queue-audio][data-track-id="${id}"]`);
    return audio&&Number.isFinite(audio.duration)&&!audio.paused;
  },firstTrack);
  await firstAudio.evaluate(audio=>{audio.currentTime=audio.duration-.25;});
  await page.waitForFunction(id=>{
    const audio=document.querySelector(`[data-media-queue-audio][data-track-id="${id}"]`);
    return audio&&!audio.paused;
  },nextTrack);
  const stopped=await post('Stop DJ.');
  assert.equal(stopped.media_playback?.operation,'dj_stop');
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).every(audio=>audio.paused));
  console.log('PASS native playlist draft and DJ start, next track, and stop use the VP3 catalog and Player');

  const presentation='**/api/cognitive-presentation-v510.php';
  const holdAutomaticTick=route=>route.request().postDataJSON()?.action==='initiative_tick'
    ?route.fulfill({json:{ok:false,reason:'blocked'}}):route.fallback();
  await page.route(presentation,holdAutomaticTick);
  await page.goto('about:blank');
  const coldArm=await page.request.post(base+'/fixture-media-arm-cold-catalog');
  assert.ok(coldArm.ok(),await coldArm.text());
  const coldRef='media:track:42';
  const beforeColdRead=await state();
  assert.equal(beforeColdRead.plays.filter(row=>row.user_id===1).length,0);
  const coldReport=(await activity(16)).media_report;
  assert.ok(!coldReport.findings.some(item=>item.id.startsWith('media:resume:')));
  const coldPick=coldReport.findings.find(item=>item.id===coldRef);
  assert.equal(coldPick?.title,'First Light');
  assert.match(coldPick.summary,/Fixture Artist.*VP3 Music Library/u);
  assert.equal(coldPick.question,'Want to hear this track?');
  assert.ok(coldPick.actions.some(action=>action.href==='/fixture-artist'));
  assert.deepEqual((await state()).runs,beforeColdRead.runs,'Opening the report cannot schedule work.');

  await page.goto(base+'/?conversation_id=16',{waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>window.StonefellowAgentActivity?.coreContext()?.conversation_id===16);
  await page.evaluate(async()=>{
    const audio=new Audio('/media.php?type=audio&track=41');
    audio.id='fixture-playing-audio';
    audio.muted=true;
    document.body.appendChild(audio);
    await audio.play();
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await pollFixture('/fixture-state',({activity})=>{
    return activity.conversation_id===16&&activity.chat_busy===false&&activity.media_playing===true;
  });
  await page.waitForFunction(()=>window.StonefellowAgentActivity?.coreContext()?.conversation_id===16);
  const policyRevision=await page.evaluate(()=>window.StonefellowAgentActivity.coreContext()?.health.policy_revision);
  assert.match(policyRevision,/^[a-f0-9]{32}$/);
  const catalogTick=()=>page.request.post(base+'/api/cognitive-presentation-v510.php',{
    headers:{'X-VP3-Working-Attention':binding},
    data:{action:'initiative_tick',agent_id:7,conversation_id:16,csrf_token:'fixture',policy_revision:policyRevision}
  });
  const catalogDiagnostic=async({allowInvalidated=false}={})=>{
    const packet=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=16',{
      headers:{'X-VP3-Working-Attention':binding}
    });
    assert.ok(packet.ok(),await packet.text());
    const scan=(await packet.json()).diagnostics.engagement.specialist_scan;
    if(scan===null&&allowInvalidated)return null;
    assert.ok(scan?.specialists?.media,'A completed catalog scan must expose its Media checkpoint.');
    return scan.specialists.media;
  };
  const guarded=await catalogTick();
  assert.ok(guarded.ok(),await guarded.text());
  assert.equal((await guarded.json()).coordination,'queued_media_offer');
  const coldState=await state();
  assert.equal(coordinationRuns(coldState,coldRef).length,1,'The worker saves one catalog offer while media is playing.');
  const coldRun=coordinationRuns(coldState,coldRef)[0];
  assert.equal(coldRun.status,'completed');
  const coldReceipt=JSON.parse(coldState.receipts.find(row=>row.run_id===coldRun.id&&row.status==='completed').result_json);
  assert.equal(coldReceipt.initiative_delivery,'delivered');
  assert.deepEqual(Object.keys(coldReceipt.initiative_tool_result.output.evidence_revisions),[coldRef]);
  const coldMessage=coldState.messages.find(row=>row.id===Number(coldReceipt.initiative_message_id));
  assert.equal(coldMessage?.conversation_id,16);
  assert.equal(coldMessage.role,'assistant');
  assert.deepEqual(JSON.parse(coldMessage.context_json).sources.map(source=>source.source),[coldRef]);
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(16));
  const coldOffer=page.locator('[data-chat-message-role="assistant"]').filter({hasText:'Want to hear this track?'});
  await coldOffer.waitFor({timeout:45000});
  assert.equal(await coldOffer.count(),1);
  assert.match(await coldOffer.innerText(),/First Light/u);
  assert.equal(await page.locator('#fixture-playing-audio').evaluate(audio=>audio.paused),false,
    'Saving the offer leaves the existing audio playing.');
  assert.equal(await page.locator('audio:not(#fixture-playing-audio)').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  assert.equal(coldState.plays.filter(row=>row.user_id===1).length,0,'Saving a catalog offer does not start playback.');

  // Delivery invalidates the discovery checkpoint. A new scan must enforce
  // the recorded cooldown while the original audio is still playing.
  const wake=await page.request.post(base+'/fixture-coordination-wake');
  assert.ok(wake.ok(),await wake.text());
  assert.ok((await catalogTick()).ok());
  const playingReport=await catalogDiagnostic();
  assert.equal(playingReport.offer_gate,'cooldown');
  assert.ok(playingReport.found>=2 && playingReport.chat_matches>=2,
    'The shared scan still reads real VP3 catalog results while media is playing.');
  assert.equal(playingReport.queued,0);
  const repeatedColdState=await state();
  assert.equal(coordinationRuns(repeatedColdState,coldRef).length,1);
  assert.equal(repeatedColdState.messages.filter(row=>row.conversation_id===16&&row.role==='assistant'
    &&JSON.parse(row.context_json||'{}').initiative_run_id===coldRun.id).length,1,
  'Repeated polling cannot save a second copy of the catalog offer.');
  assert.equal(repeatedColdState.plays.filter(row=>row.user_id===1).length,0);

  await page.evaluate(async()=>{
    const audio=document.getElementById('fixture-playing-audio');
    audio.pause();
    audio.remove();
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await pollFixture('/fixture-state',({activity})=>{
    return activity.conversation_id===16&&activity.chat_busy===false&&activity.media_playing===false;
  });
  await page.unroute(presentation,holdAutomaticTick);
  await coldOffer.waitFor({timeout:45000});
  assert.equal(await coldOffer.count(),1);
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  assert.equal((await state()).plays.filter(row=>row.user_id===1).length,0);
  await coldOffer.getByRole('button',{name:'Reply',exact:true}).click();
  // The offered title, not a generic model response, owns a named acceptance.
  const firstPick=await post('Yeah play a First Light');
  assert.equal(firstPick.media_offer_reply_v1,true,'The exact title routes through the native Media offer handler.');
  assert.equal(firstPick.initiative_followthrough.message_id,Number(coldReceipt.initiative_message_id));
  assert.equal(firstPick.media_playback?.operation,'play');
  assert.equal(firstPick.media_playback.track_id,42);
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='42'&&!audio.paused));
  const namedAccepted=await state();
  assert.equal(namedAccepted.messages.filter(row=>row.conversation_id===16&&row.role==='user'
    &&row.message==='Yeah play a First Light').length,1,'Named reply must save exactly one owner turn.');
  assert.equal(namedAccepted.messages.filter(row=>row.conversation_id===16&&row.role==='assistant'
    &&JSON.parse(row.context_json||'{}').chat_user_message_id===firstPick.user_message_id).length,1,
  'Named reply must save exactly one verified answer, without a model failure or duplicate.');
  console.log('PASS cold VP3 catalog pick saves one scoped offer, honors cooldown, and accepts an exact named track without AI reply inference or duplicate turns');

  await page.route(presentation,holdAutomaticTick);
  await page.evaluate(async()=>{
    document.querySelectorAll('audio').forEach(audio=>audio.pause());
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await pollFixture('/fixture-state',({activity})=>{
    return activity.conversation_id===16&&activity.chat_busy===false&&activity.media_playing===false;
  });
  assert.ok((await page.request.post(base+'/fixture-catalog-after-cooldown')).ok());
  const laterRef='media:track:41';
  const beforeIdleCatalog=await state();
  const catalogIdentity=snapshot=>{
    const runIds=snapshot.runs.filter(row=>{
      const goal=JSON.parse(row.goal);
      const media=goal.coordination_route?.specialist==='media'
        ||['casual:youtube_history','casual:media','casual:music','casual:youtube','casual:starter-media'].includes(goal.primary);
      return media&&goal.binding?.conversation_id===16;
    }).map(row=>row.id);
    return {runIds,messageIds:snapshot.messages.filter(row=>row.conversation_id===16&&row.role==='assistant'
      &&runIds.includes(JSON.parse(row.context_json||'{}').initiative_run_id)).map(row=>row.id)};
  };
  const idleIdentity=catalogIdentity(beforeIdleCatalog);
  const assertCatalogUnchanged=snapshot=>{
    assert.equal(coordinationRuns(snapshot,laterRef).length,0);
    assert.deepEqual(catalogIdentity(snapshot),idleIdentity,'An idle scan cannot queue or post another Media offer.');
  };
  const idleResponse=await catalogTick();
  assert.ok(idleResponse.ok(),await idleResponse.text());
  const idleTick=await idleResponse.json();
  assert.equal(idleTick.ok,true,JSON.stringify(idleTick));
  const idleState=await state();
  assertCatalogUnchanged(idleState);
  let idleReport=await catalogDiagnostic({allowInvalidated:true});
  if(idleReport===null){
    // A verified Curiosity question or Image example can complete in this tick.
    // Require its exact fresh saved post before reading that tick's scan.
    verifiedIndependentPost(beforeIdleCatalog,idleState,idleTick,16,policyRevision,['curiosity','casual:starter-image']);
    idleReport=completedMediaScan(beforeIdleCatalog,idleState,idleTick,16,policyRevision);
  }
  assert.equal(idleReport.offer_gate,'new_activity_required',
    'An acceptance and a cooldown expiry cannot cause an idle Chat to work through the catalog.');
  assert.equal(idleReport.queued,0);
  assert.ok((await page.request.post(base+'/fixture-catalog-after-cooldown?activity=owner')).ok());
  const laterResponse=await catalogTick();
  assert.ok(laterResponse.ok(),await laterResponse.text());
  const laterState=await state();
  const laterRun=coordinationRuns(laterState,laterRef)[0];
  assert.equal(laterRun?.status,'completed');
  const laterReceipt=JSON.parse(laterState.receipts.find(row=>row.run_id===laterRun.id&&row.status==='completed').result_json);
  assert.equal(laterReceipt.initiative_delivery,'delivered');
  assert.deepEqual(Object.keys(laterReceipt.initiative_tool_result.output.evidence_revisions),[laterRef]);
  assert.equal(laterReceipt.initiative_tool_result.output.title,'Native Midnight');
  assert.equal(coordinationRuns(laterState,coldRef).length,1);
  console.log('PASS a new owner turn unlocks another ranked VP3 track through shared Chat delivery without replaying the first track');

  await page.goto('about:blank');
  const fixturePost=async(path)=>{
    const response=await page.request.post(base+path);
    assert.ok(response.ok(),await response.text());
  };
  const initiativeTick=async(conversation)=>{
    await page.waitForFunction(cid=>window.StonefellowAgentActivity?.coreContext()?.conversation_id===cid
      &&/^[a-f0-9]{32}$/.test(window.StonefellowAgentActivity.coreContext()?.health.policy_revision||''),conversation);
    const revision=await page.evaluate(()=>window.StonefellowAgentActivity.coreContext().health.policy_revision);
    const response=await page.request.post(base+'/api/cognitive-presentation-v510.php',{
      headers:{'X-VP3-Working-Attention':binding},
      data:{action:'initiative_tick',agent_id:7,conversation_id:conversation,csrf_token:'fixture',policy_revision:revision}
    });
    const body=await response.text();
    assert.ok(response.ok()&&body.startsWith('{'),body);
    const result=JSON.parse(body);
    assert.equal(result.ok,true,body);
    return {result,revision};
  };
  const activityMatches=async(conversation,playing)=>pollFixture('/fixture-state',({activity})=>{
    return activity.conversation_id===conversation&&activity.chat_focused===true&&activity.chat_busy===false
      &&activity.media_playing===playing;
  },{label:'The accepted Chat '+conversation+' playback observation'});
  const casualRuns=async()=>((await state()).runs.filter(row=>{
    const ref=JSON.parse(row.goal).primary;
    return ref?.startsWith('casual:')&&!ref.startsWith('casual:starter-');
  }));
  const setCuriosityRoute=async(action)=>{
    const response=await page.request.post(base+'/fixture-curiosity-route?action='+action);
    assert.ok(response.ok(),await response.text());
    const rules=await response.json();
    assert.deepEqual([rules.owner_user_id,rules.agent_id,rules.conversation_id],[1,7,17]);
    const expected=structuredClone(rules.before);expected.curiosity.open_question=action;
    assert.deepEqual(rules.saved,expected,'Only the selected Curiosity preference changes; Media rules stay intact.');
    return rules.before.curiosity.open_question;
  };
  const waitForCasualIdle=async()=>{
    // The fixture's elapsed-message setup does not age actual foreground
    // interactions or quiet_chat_since. Observe the real 30-second gate before
    // starting the separate assessment/preparation completion deadline.
    const evidence=await pollFixture('/fixture-casual-readiness',snapshot=>snapshot.ready===true,
      {timeout:45000,label:'The actual scoped Media idle gate'});
    assert.deepEqual([evidence.owner_user_id,evidence.agent_id,evidence.conversation_id],[1,7,17]);
    assert.equal(evidence.ready,true);assert.equal(evidence.hold,null);
    assert.equal(evidence.idle.readiness,'ready');assert.equal(evidence.idle.busy,false);
    assert.ok(evidence.idle.activity_at>0&&evidence.idle.quiet_since>0);
    assert.ok(evidence.server_time-Math.max(evidence.idle.activity_at,evidence.idle.quiet_since)>=30,
      'Automatic Media preparation starts only after actual foreground activity and quiet time have both aged.');
    traceInitiative({event:'casual_idle_ready',evidence});
    return evidence;
  };
  const waitForCasualPost=async(ref,before,question,expectedRun=null)=>{
    const known=before.runs.map(row=>row.id);
    const queuedSnapshot=await state();
    const revision=await page.evaluate(()=>window.StonefellowAgentActivity.coreContext()?.health.policy_revision);
    assert.match(revision||'',/^[a-f0-9]{32}$/);
    const currentScope=row=>{
      const binding=JSON.parse(row.goal).binding;
      return row.owner_user_id===1&&row.agent_id===7&&binding?.owner_user_id===1&&binding.agent_id===7
        &&binding.conversation_id===17&&binding.revision===revision;
    };
    const admitted=queuedSnapshot.runs.filter(row=>currentScope(row)&&!known.includes(row.id)
      &&JSON.parse(row.goal).primary===ref);
    assert.equal(admitted.length,1,'The real scan must admit one exact current-bound invitation before automatic preparation.');
    const target=admitted[0];
    if(expectedRun!==null)assert.equal(target.id,expectedRun);
    const plans=queuedSnapshot.runs.filter(row=>currentScope(row)&&row.capability_key==='initiative.plan');
    const pendingPlans=plans.filter(row=>['approved','executing'].includes(row.status));
    assert.ok(pendingPlans.length<=1,'At most one current-bound assessment can precede this invitation.');
    const priorPlanIds=new Set(plans.map(row=>row.id));
    const completedReceipt=(snapshot,run)=>{
      const action=snapshot.actions.find(row=>row.run_id===run.id&&row.owner_user_id===1&&row.sequence_no===1
        &&row.execution_target==='cloud'&&row.status==='completed');
      return action&&snapshot.receipts.find(row=>row.run_id===run.id&&row.owner_user_id===1&&row.action_id===action.id
        &&row.attempt_no===action.attempt_count&&row.executor==='cloud'&&row.status==='completed'
        &&row.result_json===action.result_json);
    };
    const delivered=snapshot=>{
      const run=snapshot.runs.find(row=>row.id===target.id&&currentScope(row)&&row.status==='completed');
      const receipt=run&&completedReceipt(snapshot,run);
      return Boolean(receipt&&JSON.parse(receipt.result_json).initiative_delivery==='delivered');
    };
    const autoStarted=Date.now();
    const completedPlans=snapshot=>snapshot.runs.filter(row=>currentScope(row)&&row.capability_key==='initiative.plan'
      &&row.status==='completed'&&(pendingPlans.length?row.id===pendingPlans[0].id:!priorPlanIds.has(row.id))
      &&completedReceipt(snapshot,row)&&initiativeTrace.some(entry=>entry.at>=autoStarted&&entry.event==='response'
        &&entry.action==='initiative_tick'&&entry.conversation_id===17&&entry.ok===true
        &&entry.step==='assessment'&&entry.run_id===row.id));
    traceInitiative({event:'casual_phase_queue',ref,target_run_id:target.id,
      pending_assessment_ids:pendingPlans.map(row=>row.id)});
    await page.unroute(presentation,holdAutomaticTick);
    // A due source assessment can be queued by the next automatic tick itself.
    // Give that observed phase its own bound, then verify its exact receipt
    // before starting the invitation's separate preparation deadline.
    let snapshot=await pollFixture('/fixture-state',snapshot=>delivered(snapshot)||completedPlans(snapshot).length>0,
      {timeout:45000,label:'The exact '+ref+' preparation or its automatic assessment'});
    if(!delivered(snapshot)){
      const assessments=completedPlans(snapshot);
      assert.equal(assessments.length,1,'Only one verified automatic assessment can precede preparation.');
      const assessment=assessments[0],assessmentGoal=JSON.parse(assessment.goal);
      if(pendingPlans.length)assert.equal(assessment.goal,pendingPlans[0].goal,'The queued assessment goal is immutable.');
      assert.deepEqual(assessmentGoal.binding,JSON.parse(target.goal).binding);
      const actions=snapshot.actions.filter(row=>row.run_id===assessment.id);
      assert.equal(actions.length,1);
      const action=actions[0];
      assert.deepEqual([action.owner_user_id,action.sequence_no,action.action_key,action.capability_key,
        action.execution_target,action.status],[1,1,'initiative.plan','initiative.plan','cloud','completed']);
      const receipts=snapshot.receipts.filter(row=>row.run_id===assessment.id&&row.owner_user_id===1
        &&row.action_id===action.id&&row.attempt_no===action.attempt_count&&row.executor==='cloud'&&row.status==='completed');
      assert.equal(receipts.length,1);assert.equal(action.result_json,receipts[0].result_json);
      const result=JSON.parse(receipts[0].result_json);
      assert.equal(result.effect_state,'idle');assert.equal(Number(result.jobs),0);
      assert.equal(result.initiative_tool_result.output.type,'initiative_brainstorm');
      assert.equal(result.initiative_tool_result.output.status,'assessed');
      assert.deepEqual(result.initiative_tool_result.output.opportunities,[]);
      const pending=snapshot.runs.find(row=>row.id===target.id);
      assert.equal(pending.goal,target.goal,'Assessment cannot replace the invitation or its source evidence.');
      assert.equal(pending.status,'approved');
      assert.deepEqual(snapshot.messages.filter(row=>row.conversation_id===17).map(row=>row.id),
        queuedSnapshot.messages.filter(row=>row.conversation_id===17).map(row=>row.id),
        'The empty assessment cannot manufacture a Chat post.');
      traceInitiative({event:'casual_assessment_completed',ref,target_run_id:target.id,assessment_run_id:assessment.id,
        action_id:action.id,attempt:action.attempt_count,receipt_id:receipts[0].id});
      snapshot=await pollFixture('/fixture-state',delivered,
        {timeout:45000,label:'The exact saved '+ref+' invitation after its verified assessment'});
    }
    await page.route(presentation,holdAutomaticTick);
    const runs=snapshot.runs.filter(row=>!known.includes(row.id)&&row.status==='completed'
      &&JSON.parse(row.goal).primary===ref&&JSON.parse(row.goal).binding?.conversation_id===17);
    assert.equal(runs.length,1,'Fresh activity must deliver exactly one new invitation for this source.');
    const run=runs[0],goal=JSON.parse(run.goal);
    assert.equal(run.id,target.id,'The automatic client must finish the exact queued invitation.');
    assert.equal(run.goal,target.goal);
    assert.deepEqual([run.owner_user_id,run.agent_id,goal.binding.owner_user_id,goal.binding.agent_id,goal.binding.conversation_id],[1,7,1,7,17]);
    assert.equal(goal.tool,'coordination.review');
    const actions=snapshot.actions.filter(row=>row.run_id===run.id&&row.owner_user_id===1&&row.sequence_no===1
      &&row.action_key==='initiative.prepare'&&row.capability_key==='initiative.prepare'&&row.execution_target==='cloud');
    assert.equal(actions.length,1);
    const action=actions[0];assert.equal(action.status,'completed');
    const receipts=snapshot.receipts.filter(row=>row.run_id===run.id&&row.owner_user_id===1&&row.action_id===action.id
      &&row.attempt_no===action.attempt_count&&row.executor==='cloud'&&row.status==='completed');
    assert.equal(receipts.length,1);
    const receipt=receipts[0];assert.equal(action.result_json,receipt.result_json);
    const result=JSON.parse(receipt.result_json);
    assert.equal(result.initiative_delivery,'delivered');
    assert.deepEqual(result.initiative_tool_result.output.evidence_revisions,goal.sources);
    assert.deepEqual(Object.keys(goal.sources),[ref]);
    const message=snapshot.messages.find(row=>row.id===Number(result.initiative_message_id));
    assert.equal(message?.conversation_id,17);assert.equal(message.role,'assistant');
    const context=JSON.parse(message.context_json);
    assert.equal(context.source,'agent_initiative');assert.equal(context.initiative_run_id,run.id);
    assert.deepEqual(context.initiative_output,result.initiative_tool_result.output);
    assert.equal(message.message,result.initiative_draft);
    assert.ok(!before.messages.some(row=>row.id===message.id));
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(17));
    const element=page.locator('[data-chat-message-id="'+message.id+'"]').filter({hasText:question});
    await element.waitFor({timeout:45000});
    return {state:snapshot,run,receipt,result,message,context,element};
  };
  const settleCasualPrerequisites=async(label)=>{
    // Starters legitimately interleave with regular invitations. Finish their
    // real current-bound work before the next fresh owner turn, while keeping
    // automatic ticks held and the already-posted examples unanswered.
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()
      &&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy()
      &&window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===17);
    await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
    await activityMatches(17,false);
    const read=async()=>{
      const response=await page.request.get(base+'/fixture-casual-prerequisites');
      assert.ok(response.ok(),await response.text());
      return response.json();
    };
    let prerequisites=await read(),snapshot=await state();
    const policy=prerequisites.policy,starters=prerequisites.starters;
    assert.deepEqual([policy.enabled,policy.owner_user_id,policy.agent_id,policy.conversation_id],[true,1,7,17]);
    assert.equal(policy.revision,await page.evaluate(()=>window.StonefellowAgentActivity.coreContext()?.health.policy_revision));
    const current=row=>row.owner_user_id===1&&row.agent_id===7&&isDeepStrictEqual(JSON.parse(row.goal).binding,policy);
    const starter=row=>Object.hasOwn(starters,JSON.parse(row.goal).primary);
    const regular=state=>state.runs.filter(row=>JSON.parse(row.goal).primary?.startsWith('casual:')&&!starter(row));
    const originalRegular=regular(snapshot),required=new Set(),completed=new Set();
    const verifiedReceipt=(state,run)=>{
      assert.equal(run.status,'completed');
      const actions=state.actions.filter(row=>row.run_id===run.id);
      assert.equal(actions.length,1);const action=actions[0];
      assert.deepEqual([action.owner_user_id,action.sequence_no,action.action_key,action.capability_key,
        action.execution_target,action.status],[1,1,run.capability_key,run.capability_key,'cloud','completed']);
      const receipts=state.receipts.filter(row=>row.run_id===run.id&&row.owner_user_id===1&&row.action_id===action.id
        &&row.attempt_no===action.attempt_count&&row.executor==='cloud'&&row.status==='completed');
      assert.equal(receipts.length,1);assert.equal(action.result_json,receipts[0].result_json);
      return JSON.parse(receipts[0].result_json);
    };
    const verifiedStarter=(state,run)=>{
      assert.ok(current(run)&&starter(run));assert.equal(run.capability_key,'initiative.prepare');
      const goal=JSON.parse(run.goal),result=verifiedReceipt(state,run);
      assert.equal(goal.tool,'coordination.review');
      assert.deepEqual(goal.coordination_route,{specialist:'casual',trigger:goal.primary.slice('casual:'.length),action:'ask'});
      assert.deepEqual(Object.keys(goal.sources),[goal.primary]);
      assert.equal(result.initiative_delivery,'delivered');
      assert.equal(result.initiative_tool_result.output.type,'initiative_coordination');
      assert.equal(result.initiative_tool_result.output.situation_id,goal.coordination_id);
      assert.deepEqual(result.initiative_tool_result.output.evidence_revisions,goal.sources);
      const message=state.messages.find(row=>row.id===Number(result.initiative_message_id));
      assert.deepEqual([message?.conversation_id,message?.role,message?.message],[17,'assistant',starters[goal.primary]]);
      assert.equal(message.message,result.initiative_draft);
      const context=JSON.parse(message.context_json);
      assert.equal(context.source,'agent_initiative');assert.equal(context.initiative_run_id,run.id);
      assert.deepEqual(context.initiative_output,result.initiative_tool_result.output);
      assert.deepEqual([context.initiative_work.owner_user_id,context.initiative_work.agent_id,
        context.initiative_work.conversation_id,context.initiative_work.run_id],[1,7,17,run.id]);
      return message.id;
    };
    const limit=Object.keys(starters).length*2+2;
    for(let pass=0;pass<=limit;pass++){
      assert.deepEqual(prerequisites.policy,policy,'Prerequisite work cannot cross a policy or Chat change.');
      assert.deepEqual(prerequisites.starters,starters);
      const pending=snapshot.runs.filter(row=>current(row)&&starter(row)&&['approved','executing'].includes(row.status));
      for(const ref of [...Object.keys(prerequisites.eligible),...pending.map(row=>JSON.parse(row.goal).primary)])required.add(ref);
      if(Object.keys(prerequisites.eligible).length===0&&pending.length===0){
        for(const ref of required){
          const messageId=prerequisites.offered[ref.slice('casual:'.length)];
          assert.ok(Number.isSafeInteger(messageId)&&messageId>0,'Every prerequisite has a recorded canonical starter post.');
          const message=snapshot.messages.find(row=>row.id===messageId),context=JSON.parse(message?.context_json||'null');
          const run=snapshot.runs.find(row=>row.id===context?.initiative_run_id);
          assert.ok(run&&completed.has(run.id),'Every newly required starter was completed and verified in this phase.');
          assert.equal(verifiedStarter(snapshot,run),messageId);
        }
        traceInitiative({event:'casual_prerequisites_settled',label,passes:pass,run_ids:[...completed]});
        return;
      }
      assert.ok(pass<limit,'Starter prerequisites exceeded their bounded canonical progress limit.');
      assert.equal(prerequisites.media_ready,false,'Settle starters before fresh activity makes another Media invitation eligible.');
      assert.ok(['idle_spacing','new_activity_required'].includes(prerequisites.media_hold),JSON.stringify(prerequisites));
      assert.equal(snapshot.runs.filter(row=>current(row)&&['approved','executing'].includes(row.status)
        &&row.capability_key!=='initiative.plan'&&!starter(row)).length,0,'Unexpected current-bound work is not a starter prerequisite.');
      const before=snapshot,eligible=prerequisites.eligible;
      await fixturePost('/fixture-coordination-wake'); // Preserve actual message and foreground activity times.
      const {result:tick,revision}=await initiativeTick(17);
      assert.equal(revision,policy.revision);assert.equal(tick.reason,'completed',JSON.stringify(tick));
      snapshot=await state();prerequisites=await read();
      assert.deepEqual(regular(snapshot),originalRegular,'Prerequisite ticks cannot admit or change a regular invitation.');
      for(const old of before.runs){
        const saved=snapshot.runs.find(row=>row.id===old.id);assert.ok(saved);
        assert.equal(saved.goal,old.goal,'Prerequisite goals and source evidence are immutable.');
        if(!current(old))assert.deepEqual(saved,old,'Another policy, Agent or Chat keeps its stored work unchanged.');
      }
      for(const added of snapshot.runs.filter(row=>!before.runs.some(old=>old.id===row.id))){
        assert.ok(current(added),'New prerequisite work must use the exact current binding.');
        if(added.capability_key==='initiative.plan')continue;
        const goal=JSON.parse(added.goal);assert.ok(starter(added));
        assert.equal(added.capability_key,'initiative.prepare');assert.ok(Object.hasOwn(eligible,goal.primary));
        assert.deepEqual(goal.sources,{[goal.primary]:eligible[goal.primary]});
      }
      const newMessages=snapshot.messages.filter(row=>!before.messages.some(old=>old.id===row.id));
      if(tick.step==='assessment'){
        const run=snapshot.runs.find(row=>row.id===tick.run_id);assert.ok(run&&current(run));
        assert.equal(run.capability_key,'initiative.plan');assert.equal(completed.has(run.id),false);
        const result=verifiedReceipt(snapshot,run);
        assert.equal(result.effect_state,'idle');assert.equal(Number(result.jobs),0);
        assert.equal(result.initiative_tool_result.output.type,'initiative_brainstorm');
        assert.equal(result.initiative_tool_result.output.status,'assessed');
        assert.deepEqual(result.initiative_tool_result.output.opportunities,[]);assert.deepEqual(newMessages,[]);
        completed.add(run.id);
      }else{
        assert.ok(['preparation','saved_delivery'].includes(tick.step),JSON.stringify(tick));
        assert.equal(newMessages.length,1,'Each intervening starter tick must deliver one exact canonical post.');
        const context=JSON.parse(newMessages[0].context_json),run=snapshot.runs.find(row=>row.id===context.initiative_run_id);
        assert.ok(run);assert.equal(completed.has(run.id),false);
        if(tick.step==='preparation')assert.equal(run.id,tick.run_id);
        else assert.equal(before.runs.find(row=>row.id===run.id)?.status,'completed','Saved delivery must finish an existing exact receipt.');
        assert.equal(verifiedStarter(snapshot,run),newMessages[0].id);completed.add(run.id);
      }
      traceInitiative({event:'casual_prerequisite_progress',label,pass:pass+1,step:tick.step,run_id:tick.run_id,
        remaining:Object.keys(prerequisites.eligible)});
    }
  };
  await fixturePost('/fixture-media-arm-casual');
  await page.goto(base+'/?conversation_id=17',{waitUntil:'domcontentloaded'});
  await activityMatches(17,false);
  const beforeStarter=await state();
  const {result:starterTick,revision:casualRevision}=await initiativeTick(17);
  assert.equal((await casualRuns()).length,0,'Casual check-ins are opt-in.');
  const starterState=await state();
  const starterRuns=starterState.runs.filter(row=>!beforeStarter.runs.some(prior=>prior.id===row.id)
    &&JSON.parse(row.goal).binding?.conversation_id===17&&JSON.parse(row.goal).primary?.startsWith('casual:starter-'));
  assert.equal(starterRuns.length,1,'This Chat selects one new specialist example independently of the earlier Chat.');
  const selectedStarter=starterRuns[0],starterGoal=JSON.parse(selectedStarter.goal);
  assert.equal(starterGoal.primary,'casual:starter-mission');
  assert.deepEqual([selectedStarter.owner_user_id,selectedStarter.agent_id,starterGoal.binding.conversation_id],[1,7,17]);
  assert.equal(starterGoal.binding.revision,casualRevision);
  assert.equal(selectedStarter.status,'approved');
  const queuedStarterActions=starterState.actions.filter(row=>row.run_id===selectedStarter.id);
  assert.equal(queuedStarterActions.length,1);
  assert.equal(queuedStarterActions[0].status,'queued');
  assert.equal(queuedStarterActions[0].result_json,null);
  assert.equal(starterTick.step,'assessment');
  const assessment=starterState.runs.find(row=>row.id===starterTick.run_id);
  assert.equal(assessment?.capability_key,'initiative.plan');
  assert.equal(assessment.status,'completed');
  assert.deepEqual([assessment.owner_user_id,assessment.agent_id,JSON.parse(assessment.goal).binding.conversation_id],[1,7,17]);
  const assessmentAction=starterState.actions.find(row=>row.run_id===assessment.id&&row.owner_user_id===1
    &&row.sequence_no===1&&row.action_key==='initiative.plan'&&row.capability_key==='initiative.plan'&&row.execution_target==='cloud');
  assert.equal(assessmentAction?.status,'completed');
  const assessmentReceipts=starterState.receipts.filter(row=>row.run_id===assessment.id&&row.action_id===assessmentAction.id
    &&row.owner_user_id===1&&row.attempt_no===assessmentAction.attempt_count&&row.executor==='cloud'&&row.status==='completed');
  assert.equal(assessmentReceipts.length,1);
  assert.equal(assessmentAction.result_json,assessmentReceipts[0].result_json);
  const quietMediaDiagnostic=async({allowInvalidated=false}={})=>{
    const response=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=17',{
      headers:{'X-VP3-Working-Attention':binding}
    });
    assert.ok(response.ok(),await response.text());
    const scan=(await response.json()).diagnostics.engagement.specialist_scan;
    if(scan===null&&allowInvalidated)return null;
    assert.ok(scan?.specialists?.media,'A completed quiet Media scan must expose its checkpoint.');
    return scan.specialists.media;
  };
  const disabledMediaScan=await quietMediaDiagnostic();
  assert.equal(disabledMediaScan.offer_gate,'report_only');
  assert.equal(disabledMediaScan.chat_matches,0);
  assert.ok(disabledMediaScan.found>=1,'Disabling chat suggestions keeps verified tracks in the Media report.');
  await fixturePost('/fixture-catalog-availability?published=0');
  const beforeEmptyCatalog=await state();
  const {result:emptyTick,revision:emptyRevision}=await initiativeTick(17);
  assert.equal(emptyTick.step,'preparation');
  assert.equal(emptyTick.run_id,selectedStarter.id);
  const emptyState=await state();
  const starter=verifiedIndependentPost(beforeEmptyCatalog,emptyState,emptyTick,17,emptyRevision,['casual:starter-mission']);
  assert.equal(starter.run.goal,selectedStarter.goal);
  const starterMessage=starter.post.id;
  assert.equal(await quietMediaDiagnostic({allowInvalidated:true}),null,
    'The completed Mission example invalidates the same tick’s public scan projection.');
  const emptyMediaScan=completedMediaScan(beforeEmptyCatalog,emptyState,emptyTick,17,emptyRevision);
  assert.equal(emptyMediaScan.coverage,'ready');
  assert.equal(emptyMediaScan.found,0);
  assert.equal(emptyMediaScan.offer_gate,'no_playable_sources');
  const emptyMedia=await activity(17);
  assert.equal(emptyMedia.media_report.coverage,'ready');
  assert.deepEqual(emptyMedia.media_report.findings,[]);
  const emptySpecialist=emptyMedia.specialists.find(row=>row.id==='media');
  assert.equal(emptySpecialist?.state,'idle');
  assert.equal(emptySpecialist.detail,'Waiting for the first Media check in this Chat.',
    'An invalidated checkpoint cannot present the Media specialist as currently checked.');
  assert.equal((await casualRuns()).length,0,'An empty Media scan does not invent a suggestion.');
  await fixturePost('/fixture-catalog-availability?published=1');
  console.log('PASS Media activity distinguishes playable report-only picks from a verified empty source check');

  // Curiosity questions legitimately outrank pending casual invitations. Keep
  // this focused Media/email sequence under the owner's saved Watch preference,
  // retaining every other rule and source; restore Ask after its final assertion.
  const previousCuriosityRoute=await setCuriosityRoute('watch');
  assert.equal(previousCuriosityRoute,'ask');
  await post('Create a VP3 music playlist for ambient.');
  await fixturePost('/fixture-casual-enable');
  await page.evaluate(async()=>{
    const audio=new Audio('/media.php?type=audio&track=41');
    audio.id='fixture-casual-playing-audio';
    audio.muted=true;
    document.body.appendChild(audio);
    await audio.play();
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await activityMatches(17,true);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await initiativeTick(17);
  assert.equal((await casualRuns()).length,0,'An actual playing track suppresses casual check-ins.');
  await page.evaluate(async()=>{
    const audio=document.getElementById('fixture-casual-playing-audio');
    audio.pause();
    audio.remove();
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake');
  const beforeFirstMusic=await state();
  await waitForCasualIdle();
  const {result:firstMusicTick,revision:firstMusicRevision}=await initiativeTick(17);
  const firstMusicQueued=await state();
  const newMusicRuns=coordinationRuns(firstMusicQueued,'casual:music')
    .filter(row=>!beforeFirstMusic.runs.some(prior=>prior.id===row.id));
  assert.equal(newMusicRuns.length,1,'The first eligible scan must admit one exact music invitation.');
  const queuedMusic=newMusicRuns[0],queuedMusicGoal=JSON.parse(queuedMusic.goal);
  assert.equal(queuedMusic.status,'approved');
  assert.deepEqual([queuedMusic.owner_user_id,queuedMusic.agent_id,queuedMusicGoal.binding.owner_user_id,
    queuedMusicGoal.binding.agent_id,queuedMusicGoal.binding.conversation_id],[1,7,1,7,17]);
  assert.equal(queuedMusicGoal.binding.revision,firstMusicRevision);
  const queuedMusicActions=firstMusicQueued.actions.filter(row=>row.run_id===queuedMusic.id);
  assert.equal(queuedMusicActions.length,1);
  assert.deepEqual([queuedMusicActions[0].owner_user_id,queuedMusicActions[0].action_key,
    queuedMusicActions[0].capability_key,queuedMusicActions[0].execution_target,queuedMusicActions[0].status,
    queuedMusicActions[0].result_json],[1,'initiative.prepare','initiative.prepare','cloud','queued',null]);
  assert.equal(firstMusicTick.step,'assessment');
  assert.equal(firstMusicTick.coordination,'queued_media_offer');
  const musicAssessment=firstMusicQueued.runs.find(row=>row.id===firstMusicTick.run_id);
  assert.equal(musicAssessment?.status,'completed');
  assert.deepEqual([musicAssessment.owner_user_id,musicAssessment.agent_id,musicAssessment.capability_key],
    [1,7,'initiative.plan']);
  assert.equal(JSON.parse(musicAssessment.goal).binding.conversation_id,17);
  const musicAssessmentActions=firstMusicQueued.actions.filter(row=>row.run_id===musicAssessment.id);
  assert.equal(musicAssessmentActions.length,1);
  const musicAssessmentAction=musicAssessmentActions[0];
  assert.deepEqual([musicAssessmentAction.owner_user_id,musicAssessmentAction.action_key,
    musicAssessmentAction.capability_key,musicAssessmentAction.execution_target,musicAssessmentAction.status],
    [1,'initiative.plan','initiative.plan','cloud','completed']);
  const musicAssessmentReceipts=firstMusicQueued.receipts.filter(row=>row.run_id===musicAssessment.id
    &&row.owner_user_id===1&&row.action_id===musicAssessmentAction.id
    &&row.attempt_no===musicAssessmentAction.attempt_count&&row.executor==='cloud'&&row.status==='completed');
  assert.equal(musicAssessmentReceipts.length,1);
  assert.equal(musicAssessmentAction.result_json,musicAssessmentReceipts[0].result_json);
  const musicQuestion='Want to listen to some music?';
  const firstMusic=await waitForCasualPost('casual:music',beforeFirstMusic,musicQuestion,queuedMusic.id);
  const casualMusic=firstMusic.element;
  assert.equal((await state()).plays.filter(row=>row.user_id===1).length,0,'Asking does not create a play session.');
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  const musicState=firstMusic.state,musicReceipt=firstMusic.result;
  const starterRow=musicState.messages.find(row=>row.id===starterMessage);
  assert.equal(JSON.parse(starterRow.context_json).initiative_work.followthrough.state,'open',
    'An unanswered specialist example does not prevent subsequent opt-in Media engagement.');

  await fixturePost('/fixture-coordination-wake');
  await initiativeTick(17);
  assert.equal((await casualRuns()).length,1,'Repeated idle polling does not add an unanswered invitation.');
  await page.goto(base+'/?conversation_id=18',{waitUntil:'domcontentloaded'});
  await activityMatches(18,false);
  await fixturePost('/fixture-coordination-wake');
  await initiativeTick(18);
  assert.equal((await casualRuns()).length,1,'Switching Chats does not add another unanswered invitation.');
  await page.goto(base+'/?conversation_id=17',{waitUntil:'domcontentloaded'});
  await activityMatches(17,false);
  await casualMusic.waitFor();
  const selectedReply=page.waitForRequest(request=>request.url().includes('/api/chat-v236.php')
    &&request.postDataJSON()?.action==='send');
  await casualMusic.getByRole('button',{name:'Reply',exact:true}).click();
  const musicReply=await post('Yes.');
  assert.equal(musicReply.media_playback?.operation,'play');
  assert.equal(musicReply.media_playback.track_id,42);
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='42'&&!audio.paused));
  const replyRequest=await selectedReply;
  const replyData=replyRequest.postDataJSON();
  assert.equal(replyData.initiative_reply?.message_id,Number(musicReceipt.initiative_message_id));
  const replayResponse=await page.request.post(replyRequest.url(),{headers:{'X-VP3-Working-Attention':binding},data:replyData});
  const replay=await replayResponse.json();
  assert.ok(replayResponse.ok()&&replay.ok,JSON.stringify(replay));
  assert.equal(replay.replayed,true);
  assert.equal(replay.assistant_message_id,musicReply.assistant_message_id);
  assert.equal(replay.media_playback,undefined);
  const offerRow=(await state()).messages.find(row=>row.id===Number(musicReceipt.initiative_message_id));
  assert.equal(JSON.parse(offerRow.context_json).initiative_followthrough_state.state,'owner_resolved');
  await page.reload({waitUntil:'domcontentloaded'});
  await casualMusic.waitFor();
  assert.equal(await casualMusic.count(),1);
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);

  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake');
  await initiativeTick(17);
  assert.equal((await casualRuns()).length,1,'Answering a check-in does not generate a new one right away.');

  await settleCasualPrerequisites('after first music acceptance');
  const beforeSecondMusic=await state();
  await post('Create a VP3 music playlist for energetic.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await waitForCasualIdle();
  await initiativeTick(17);
  const secondMusic=await waitForCasualPost('casual:music',beforeSecondMusic,musicQuestion);
  assert.equal((await casualRuns()).length,2,'Fresh activity makes the same kind eligible when no other kind is available.');
  await secondMusic.element.getByRole('button',{name:'Reply',exact:true}).click();
  const declined = await post('No, thanks.');
  assert.equal(declined.initiative_followthrough?.state,'declined');
  assert.equal(declined.initiative_followthrough.message_id,secondMusic.message.id);

  await fixturePost('/fixture-casual-enable-youtube');
  await settleCasualPrerequisites('after enabling YouTube');
  const beforeCombinedMedia=await state();
  await post('Create a VP3 music playlist for calm.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  const attemptsBeforeYoutube=(await state()).youtube_attempts;
  await waitForCasualIdle();
  await initiativeTick(17);
  const mediaQuestion='Would you like to listen to music or watch a video?';
  const combinedMedia=await waitForCasualPost('casual:media',beforeCombinedMedia,mediaQuestion);
  const casualMedia=combinedMedia.element;
  assert.equal((await casualRuns()).length,3,'Another check-in follows fresh activity the same day.');
  const mediaState=await state();
  const mediaRun=coordinationRuns(mediaState,'casual:media')[0];
  assert.equal(mediaRun?.status,'completed');
  const mediaReceipt=JSON.parse(mediaState.receipts.find(row=>row.run_id===mediaRun.id&&row.status==='completed').result_json);
  assert.deepEqual(Object.keys(mediaReceipt.initiative_tool_result.output.evidence_revisions),['casual:media']);
  const pendingMedia=(await activity(17)).specialists.find(row=>row.id==='media');
  assert.notEqual(pendingMedia.state,'waiting_reply');
  const mediaEngagement=pendingMedia.open_engagements.find(item=>item.message_id===Number(mediaReceipt.initiative_message_id));
  assert.match(mediaEngagement?.result_url||'',new RegExp('message_id='+mediaReceipt.initiative_message_id+'$'));
  const mediaDebugResponse=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=17',
    {headers:{'X-VP3-Working-Attention':binding}});
  const mediaDebug=(await mediaDebugResponse.json()).diagnostics;
  assert.equal(mediaDebug.engagement.last_post.specialist,'media');
  assert.equal(mediaDebug.engagement.last_post.message_id,Number(mediaReceipt.initiative_message_id));
  const mediaReplyBinding=combinedMedia.context.initiative_work.followthrough;
  const voicePayload={message:'Yes.',conversation_id:17,agent_context:{user_agent_id:7},
    csrf_token:'fixture',idempotency_key:'fixture-casual-media-yes',initiative_reply:{message_id:combinedMedia.message.id,
      reply_ref:mediaReplyBinding.reply_ref,revision:mediaReplyBinding.revision}};
  const voiceReply=async()=>{
    const response=await page.request.post(base+'/api/chat-stream-v121.php',{
      headers:{'X-VP3-Working-Attention':binding},data:voicePayload
    });
    const body=await response.text();
    assert.ok(response.ok(),body);
    const events=body.trim().split('\n').map(line=>JSON.parse(line));
    const done=events.find(event=>event.type==='done')?.data;
    assert.ok(done?.ok,body);
    return done;
  };
  const clarifyMedia=await voiceReply();
  assert.equal(clarifyMedia.input_mode,'voice');
  assert.match(clarifyMedia.answer,/prefer, music or a video/u);
  assert.equal(clarifyMedia.initiative_followthrough.state,'open');
  assert.equal(clarifyMedia.initiative_followthrough.message_id,Number(mediaReceipt.initiative_message_id));
  assert.equal(clarifyMedia.youtube_results,undefined);
  assert.equal(clarifyMedia.media_playback,undefined);
  assert.equal((await state()).youtube_attempts,attemptsBeforeYoutube,'The casual offer and bare yes make no provider call.');
  const secondVoiceReply=await voiceReply();
  assert.equal(secondVoiceReply.replayed,true);
  assert.equal(secondVoiceReply.assistant_message_id,clarifyMedia.assistant_message_id);
  assert.equal(secondVoiceReply.youtube_results,undefined);
  const youtubeReply=await post('Watch a video.');
  assert.match(youtubeReply.answer,/what would you like to watch.*topic/iu);
  assert.equal(youtubeReply.initiative_followthrough.state,'owner_resolved');
  assert.equal(youtubeReply.initiative_followthrough.message_id,Number(mediaReceipt.initiative_message_id));
  assert.equal(youtubeReply.youtube_results,undefined);
  assert.equal(youtubeReply.media_playback,undefined);
  assert.equal((await state()).youtube_attempts,attemptsBeforeYoutube,'Choosing video asks for a topic without a provider call.');
  assert.equal(await page.locator('.vp3-youtube-frame').count(),0);
  console.log('PASS music-only and combined media check-ins share one slot; a vague voice reply clarifies, and the exact video choice asks for a topic');

  await fixturePost('/fixture-casual-enable-gmail');
  await settleCasualPrerequisites('after enabling Gmail');
  const beforeEmailOffer=await state();
  await post('Create a VP3 music playlist for ambient.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  // Email uses the new ordinary turn as its prerequisite. Keep the recent
  // Media post and its real idle spacing intact while this email is prepared.
  await fixturePost('/fixture-coordination-wake');
  const emailMediaReadinessResponse=await page.request.get(base+'/fixture-casual-readiness');
  assert.ok(emailMediaReadinessResponse.ok(),await emailMediaReadinessResponse.text());
  const emailMediaReadiness=await emailMediaReadinessResponse.json();
  assert.deepEqual([emailMediaReadiness.owner_user_id,emailMediaReadiness.agent_id,emailMediaReadiness.conversation_id],[1,7,17]);
  assert.equal(emailMediaReadiness.ready,false);
  assert.equal(emailMediaReadiness.hold,'idle_spacing');
  await initiativeTick(17);
  const emailOffer=await waitForCasualPost('casual:email',beforeEmailOffer,'Want to draft an email?');
  const casualEmail=emailOffer.element;
  assert.equal((await casualRuns()).length,4,'A later ordinary turn can make another check-in eligible on the same day.');
  await casualEmail.getByRole('button',{name:'Reply',exact:true}).click();
  const emailReply=await post('Yes.');
  assert.equal(emailReply.initiative_followthrough.message_id,emailOffer.message.id);
  assert.match(emailReply.answer,/draft.*go to/u);
  assert.deepEqual((await state()).email_drafts,[],'Bare assent does not invent a recipient or create a draft.');
  const exactDraft=await post('Draft an email to team@example.test\nSubject: Quick check-in\nBody: See you at the rehearsal.');
  assert.ok(exactDraft.actions.some(action=>action.type==='email_review'));
  const afterDraft=await state();
  assert.deepEqual(afterDraft.email_drafts,[{recipient:'team@example.test',status:'prepared'}]);
  assert.equal(afterDraft.email_sends,0,'A created email draft awaits the existing review step.');
  console.log('PASS casual email offer → recipient and content prompt → existing private draft review with no send');

  await settleCasualPrerequisites('before the later Media invitation');
  const beforeRepeatedMedia=await state();
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await waitForCasualIdle();
  await initiativeTick(17);
  const repeatedMedia=await waitForCasualPost('casual:media',beforeRepeatedMedia,mediaQuestion);
  const beforeChoice=repeatedMedia.state;
  const combinedPosts=beforeChoice.messages.filter(row=>row.conversation_id===17&&row.role==='assistant'&&row.message===mediaQuestion);
  assert.equal(combinedPosts.length,2);
  await page.reload({waitUntil:'domcontentloaded'});
  await page.locator(`[data-chat-message-id="${combinedPosts.at(-1).id}"]`).filter({hasText:mediaQuestion}).waitFor({timeout:45000});
  const playsBeforeChoice=beforeChoice.plays.filter(row=>row.user_id===1).length;
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  await page.locator('[data-chat-message-id="'+repeatedMedia.message.id+'"]').getByRole('button',{name:'Reply',exact:true}).click();
  const musicChoice=await post('Music, please.');
  assert.equal(musicChoice.media_playback?.operation,'play');
  assert.equal(musicChoice.media_playback.track_id,42);
  assert.equal(musicChoice.initiative_followthrough.state,'owner_resolved');
  assert.equal(musicChoice.initiative_followthrough.message_id,repeatedMedia.message.id);
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='42'&&!audio.paused));
  const nextState=await pollFixture('/fixture-state',snapshot=>
    snapshot.plays.filter(row=>row.user_id===1).length===playsBeforeChoice+1,
    {label:'The single explicitly accepted playback session'});
  assert.equal(nextState.plays.filter(row=>row.user_id===1).length,playsBeforeChoice+1);
  assert.equal(nextState.youtube_attempts,attemptsBeforeYoutube);
  assert.equal(await setCuriosityRoute(previousCuriosityRoute),'watch');
  console.log('PASS choosing music from the combined invitation opens the verified VP3 pick without a YouTube call');
}catch(error){
  if(page&&!page.isClosed())try{
    const snapshot=await (await page.request.get(base+'/fixture-state')).json();
    const runs=snapshot.runs.filter(row=>JSON.parse(row.goal).binding?.conversation_id===17);
    const runIds=new Set(runs.map(row=>row.id));
    const {binding:currentBinding,...client}=await page.evaluate(()=>{
      const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
      const core=window.StonefellowAgentActivity?.coreContext?.();
      return {binding:window.STONEFELLOW_CHAT?.workingAttentionBinding,visibility:document.visibilityState,
        focused:document.hasFocus(),conversation_id:chat?.conversationId?.(),agent_id:chat?.agentId?.(),
        ready:chat?.isReady?.(),restoring:chat?.isRestoring?.(),busy:chat?.isBusy?.(),
        composer_length:document.getElementById('chatInput')?.value.length,
        core:core?{owner_user_id:core.owner_user_id,agent_id:core.agent_id,conversation_id:core.conversation_id,
          health:core.health}:null,
        media:Array.from(document.querySelectorAll('audio,video')).map(element=>({track:element.dataset.trackId,
          paused:element.paused,ended:element.ended,current_time:element.currentTime})),
        assistant_messages:Array.from(document.querySelectorAll('[data-chat-message-role="assistant"]'))
          .map(element=>({id:element.dataset.chatMessageId,text:element.textContent.slice(0,400)})).slice(-20)};
    });
    const readiness=await (await page.request.get(base+'/fixture-casual-readiness')).json();
    let diagnostics=null;
    if(currentBinding&&client.conversation_id>0){
      const response=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id='+client.conversation_id,
        {headers:{'X-VP3-Working-Attention':currentBinding}});
      diagnostics={status:response.status(),body:await response.json()};
    }
    console.error('YouTube offer failure evidence',JSON.stringify({trace:initiativeTrace,client,readiness,diagnostics,
      saved:{server_time:snapshot.server_time,activity:snapshot.activity,coordination_scan:snapshot.coordination_scan,
        runs,actions:snapshot.actions.filter(row=>runIds.has(row.run_id)),
        receipts:snapshot.receipts.filter(row=>runIds.has(row.run_id)),
        messages:snapshot.messages.filter(row=>row.conversation_id===17)}}));
  }catch(diagnosticError){console.error('YouTube offer failure evidence unavailable:',diagnosticError.message);}
  console.error(logs.slice(-3000),failed.slice(-8));throw error;
}
finally{
  if(browser)await browser.close();
  if(server.exitCode===null){server.kill();await new Promise(resolve=>server.once('exit',resolve));}
  rmSync(temp,{recursive:true,force:true});
}
