import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {cpSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
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
if(in_array($path,['/chat.js','/chat-youtube-v1.js','/agent-activity-v94.js','/chat-cognitive-presentation-v510.js'],true)){
    header('Content-Type: text/javascript');readfile(${JSON.stringify(repo)}.ltrim($path,'/'));return;
}
if(str_starts_with($path,'/api/')){require __DIR__.$path;return;}
if($path==='/media.php'&&($_GET['type']??null)==='audio'){require __DIR__.'/media.php';return;}
require __DIR__.'/includes/bootstrap.php';
if($path==='/fixture-state'){
    header('Content-Type:application/json');
    echo json_encode([
        'runs'=>db()->query('SELECT id,owner_user_id,agent_id,status,goal FROM agent_workflow_runs ORDER BY id')->fetchAll(),
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
  .'<script src="/chat-youtube-v1.js"></script><script src="/chat.js"></script><script src="/agent-activity-v94.js"></script><script src="/chat-cognitive-presentation-v510.js"></script>';
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
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>60)throw new Error('Fixture PHP did not start.');
    await new Promise(resolve=>setTimeout(resolve,30));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  page=await browser.newPage();
  page.on('pageerror',error=>failed.push(error.message));
  page.on('response',async response=>{if(response.url().startsWith(base)&&(!response.ok()||response.url().includes('/api/'))){
    const text=await response.text().catch(()=>'');
    if(!response.ok()||text.includes('Fatal error')||!text.startsWith('{'))failed.push(`${response.status()} ${response.url()}: ${text.slice(0,500)}`);
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
  const posted=await state();
  const coordinationRuns=(snapshot,primary)=>snapshot.runs.filter(row=>{
    const goal=JSON.parse(row.goal);
    return goal.tool==='coordination.review'&&goal.primary===primary;
  });
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
  await page.waitForFunction(async()=>{
    const {activity}=await (await fetch('/fixture-state')).json();
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
  await page.waitForFunction(async()=>{
    const {activity}=await (await fetch('/fixture-state')).json();
    return activity.conversation_id===16&&activity.chat_busy===false&&activity.media_playing===false;
  });
  await page.unroute(presentation,holdAutomaticTick);
  await coldOffer.waitFor({timeout:45000});
  assert.equal(await coldOffer.count(),1);
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  assert.equal((await state()).plays.filter(row=>row.user_id===1).length,0);
  await coldOffer.getByRole('button',{name:'Reply',exact:true}).click();
  const firstPick=await post('Yes.');
  assert.equal(firstPick.initiative_followthrough.message_id,Number(coldReceipt.initiative_message_id));
  assert.equal(firstPick.media_playback?.operation,'play');
  assert.equal(firstPick.media_playback.track_id,42);
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='42'&&!audio.paused));
  console.log('PASS cold VP3 catalog pick saves one scoped text offer during playback, honors cooldown, and plays the exact accepted track');

  await page.route(presentation,holdAutomaticTick);
  await page.evaluate(async()=>{
    document.querySelectorAll('audio').forEach(audio=>audio.pause());
    await window.StonefellowAgentActivity.refreshCoreContext();
  });
  await page.waitForFunction(async()=>{
    const {activity}=await (await fetch('/fixture-state')).json();
    return activity.conversation_id===16&&activity.chat_busy===false&&activity.media_playing===false;
  });
  assert.ok((await page.request.post(base+'/fixture-catalog-after-cooldown')).ok());
  const laterRef='media:track:41';
  const beforeIdleCatalog=await state();
  const catalogIdentity=snapshot=>{
    const runIds=snapshot.runs.filter(row=>{
      const goal=JSON.parse(row.goal);
      return goal.coordination_route?.specialist==='media'&&goal.binding?.conversation_id===16;
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
  let idleState=await state();
  assertCatalogUnchanged(idleState);
  let idleReport=await catalogDiagnostic({allowInvalidated:true});
  if(idleReport===null){
    // A saved Curiosity report invalidates discovery in the same real tick.
    // Require that exact completed post before allowing one genuine rescan.
    assert.equal(idleTick.coordination,'queued_chat_handoff',JSON.stringify({tick:idleTick,
      run:idleState.runs.find(row=>row.id===idleTick.run_id),
      actions:idleState.actions.filter(row=>row.run_id===idleTick.run_id),
      receipts:idleState.receipts.filter(row=>row.run_id===idleTick.run_id),
      newMessages:idleState.messages.filter(row=>row.conversation_id===16
        &&!beforeIdleCatalog.messages.some(previous=>previous.id===row.id))}));
    const handoff=idleState.runs.find(row=>row.id===idleTick.run_id);
    assert.equal(handoff?.status,'completed');
    const goal=JSON.parse(handoff.goal);
    assert.equal(goal.tool,'coordination.review');
    assert.equal(goal.coordination_route.specialist,'curiosity');
    assert.equal(goal.binding.owner_user_id,1);
    assert.equal(goal.binding.agent_id,7);
    assert.equal(goal.binding.conversation_id,16);
    assert.ok(goal.primary.startsWith('curiosity:'));
    const handoffReceipt=idleState.receipts.find(row=>row.run_id===handoff.id&&row.status==='completed');
    assert.ok(handoffReceipt);
    assert.equal(handoffReceipt.owner_user_id,1);
    assert.equal(handoffReceipt.executor,'cloud');
    const result=JSON.parse(handoffReceipt.result_json);
    assert.equal(result.initiative_delivery,'delivered');
    assert.deepEqual(Object.keys(result.initiative_tool_result.output.evidence_revisions),[goal.primary]);
    const posted=idleState.messages.find(row=>row.id===Number(result.initiative_message_id));
    assert.equal(posted?.conversation_id,16);
    assert.equal(posted.role,'assistant');
    assert.equal(JSON.parse(posted.context_json).initiative_run_id,handoff.id);
    assert.ok(!beforeIdleCatalog.messages.some(row=>row.id===posted.id),'The invalidated checkpoint requires a newly saved report.');
    const rescanned=await catalogTick();
    assert.ok(rescanned.ok(),await rescanned.text());
    const rescanTick=await rescanned.json();
    assert.equal(rescanTick.ok,true,JSON.stringify(rescanTick));
    idleState=await state();
    assertCatalogUnchanged(idleState);
    idleReport=await catalogDiagnostic();
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
  };
  const activityMatches=async(conversation,playing)=>page.waitForFunction(async({conversation,playing})=>{
    const {activity}=await (await fetch('/fixture-state')).json();
    return activity.conversation_id===conversation&&activity.chat_focused===true&&activity.chat_busy===false
      &&activity.media_playing===playing;
  },{conversation,playing});
  const casualRuns=async()=>((await state()).runs.filter(row=>{
    const ref=JSON.parse(row.goal).primary;
    return ref?.startsWith('casual:')&&!ref.startsWith('casual:starter-');
  }));
  await fixturePost('/fixture-media-arm-casual');
  await page.goto(base+'/?conversation_id=17',{waitUntil:'domcontentloaded'});
  await activityMatches(17,false);
  await initiativeTick(17);
  assert.equal((await casualRuns()).length,0,'Casual check-ins are opt-in.');
  const starterState=await state();
  const starterRuns=starterState.runs.filter(row=>JSON.parse(row.goal).primary?.startsWith('casual:starter-'));
  assert.equal(starterRuns.length,1,'Initiative can deliver a specialist example while Media check-ins are disabled.');
  const starterReceipt=JSON.parse(starterState.receipts.find(row=>row.run_id===starterRuns[0].id&&row.status==='completed').result_json);
  assert.equal(starterReceipt.initiative_delivery,'delivered');
  const starterMessage=Number(starterReceipt.initiative_message_id);
  const quietMediaDiagnostic=async()=>{
    const response=await page.request.get(base+'/api/cognitive-presentation-v510.php?action=initiative_diagnostics&agent_id=7&conversation_id=17',{
      headers:{'X-VP3-Working-Attention':binding}
    });
    return (await response.json()).diagnostics.engagement.specialist_scan.specialists.media;
  };
  const disabledMediaScan=await quietMediaDiagnostic();
  assert.equal(disabledMediaScan.offer_gate,'report_only');
  assert.equal(disabledMediaScan.chat_matches,0);
  assert.ok(disabledMediaScan.found>=1,'Disabling chat suggestions keeps verified tracks in the Media report.');
  await fixturePost('/fixture-catalog-availability?published=0');
  await initiativeTick(17);
  const emptyMediaScan=await quietMediaDiagnostic();
  assert.equal(emptyMediaScan.coverage,'ready');
  assert.equal(emptyMediaScan.found,0);
  assert.equal(emptyMediaScan.offer_gate,'no_playable_sources');
  const emptyMedia=await activity(17);
  assert.deepEqual(emptyMedia.media_report.findings,[]);
  assert.match(emptyMedia.specialists.find(row=>row.id==='media'&&row.state==='checked')?.detail||'',/No playable track/u);
  assert.equal((await casualRuns()).length,0,'An empty Media scan does not invent a suggestion.');
  await fixturePost('/fixture-catalog-availability?published=1');
  console.log('PASS Media activity distinguishes playable report-only picks from a verified empty source check');

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
  await page.unroute(presentation,holdAutomaticTick);
  const musicQuestion='Want to listen to some music?';
  const casualMusic=page.locator('[data-chat-message-role="assistant"]').filter({hasText:musicQuestion});
  await casualMusic.waitFor({timeout:45000});
  assert.equal((await state()).plays.filter(row=>row.user_id===1).length,0,'Asking does not create a play session.');
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  const musicState=await state();
  const musicRun=coordinationRuns(musicState,'casual:music')[0];
  assert.equal(musicRun?.status,'completed');
  const musicReceipt=JSON.parse(musicState.receipts.find(row=>row.run_id===musicRun.id&&row.status==='completed').result_json);
  assert.equal(musicReceipt.initiative_delivery,'delivered');
  assert.deepEqual(Object.keys(musicReceipt.initiative_tool_result.output.evidence_revisions),['casual:music']);
  const starterRow=musicState.messages.find(row=>row.id===starterMessage);
  assert.equal(JSON.parse(starterRow.context_json).initiative_work.followthrough.state,'open',
    'An unanswered specialist example does not prevent subsequent opt-in Media engagement.');

  await page.route(presentation,holdAutomaticTick);
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

  await post('Create a VP3 music playlist for energetic.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await initiativeTick(17);
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(17));
  await page.locator('[data-chat-message-role="assistant"]').filter({hasText:musicQuestion}).last().waitFor({timeout:45000});
  assert.equal((await casualRuns()).length,2,'Fresh activity makes the same kind eligible when no other kind is available.');
  const declined = await post('No, thanks.');
  assert.equal(declined.initiative_followthrough?.state,'declined');

  await fixturePost('/fixture-casual-enable-youtube');
  await post('Create a VP3 music playlist for calm.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  const attemptsBeforeYoutube=(await state()).youtube_attempts;
  await initiativeTick(17);
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(17));
  const mediaQuestion='Would you like to listen to music or watch a video?';
  const casualMedia=page.locator('[data-chat-message-role="assistant"]').filter({hasText:mediaQuestion});
  await casualMedia.waitFor({timeout:45000});
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
  const voicePayload={message:'Yes.',conversation_id:17,agent_context:{user_agent_id:7},
    csrf_token:'fixture',idempotency_key:'fixture-casual-media-yes'};
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
  await post('Create a VP3 music playlist for ambient.');
  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await initiativeTick(17);
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(17));
  const casualEmail=page.locator('[data-chat-message-role="assistant"]').filter({hasText:'Want to draft an email?'});
  await casualEmail.waitFor({timeout:45000});
  assert.equal((await casualRuns()).length,4,'A later ordinary turn can make another check-in eligible on the same day.');
  const emailReply=await post('Yes.');
  assert.match(emailReply.answer,/draft.*go to/u);
  assert.deepEqual((await state()).email_drafts,[],'Bare assent does not invent a recipient or create a draft.');
  const exactDraft=await post('Draft an email to team@example.test\nSubject: Quick check-in\nBody: See you at the rehearsal.');
  assert.ok(exactDraft.actions.some(action=>action.type==='email_review'));
  const afterDraft=await state();
  assert.deepEqual(afterDraft.email_drafts,[{recipient:'team@example.test',status:'prepared'}]);
  assert.equal(afterDraft.email_sends,0,'A created email draft awaits the existing review step.');
  console.log('PASS casual email offer → recipient and content prompt → existing private draft review with no send');

  await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
  await activityMatches(17,false);
  await fixturePost('/fixture-coordination-wake?elapsed=1');
  await initiativeTick(17);
  await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(17));
  const beforeChoice=await state();
  const combinedPosts=beforeChoice.messages.filter(row=>row.conversation_id===17&&row.role==='assistant'&&row.message===mediaQuestion);
  assert.equal(combinedPosts.length,2);
  await page.reload({waitUntil:'domcontentloaded'});
  await page.locator(`[data-chat-message-id="${combinedPosts.at(-1).id}"]`).filter({hasText:mediaQuestion}).waitFor({timeout:45000});
  const playsBeforeChoice=beforeChoice.plays.filter(row=>row.user_id===1).length;
  assert.equal(await page.locator('audio').evaluateAll(elements=>elements.some(audio=>!audio.paused)),false);
  const musicChoice=await post('Music, please.');
  assert.equal(musicChoice.media_playback?.operation,'play');
  assert.equal(musicChoice.media_playback.track_id,42);
  assert.equal(musicChoice.initiative_followthrough.state,'owner_resolved');
  await page.waitForFunction(()=>Array.from(document.querySelectorAll('audio')).some(audio=>
    audio.dataset.trackId==='42'&&!audio.paused));
  await page.waitForFunction(async count=>{
    const snapshot=await (await fetch('/fixture-state')).json();
    return snapshot.plays.filter(row=>row.user_id===1).length===count;
  },playsBeforeChoice+1);
  const nextState=await state();
  assert.equal(nextState.plays.filter(row=>row.user_id===1).length,playsBeforeChoice+1);
  assert.equal(nextState.youtube_attempts,attemptsBeforeYoutube);
  console.log('PASS choosing music from the combined invitation opens the verified VP3 pick without a YouTube call');
}catch(error){console.error(logs.slice(-3000),failed.slice(-8));throw error;}
finally{
  if(browser)await browser.close();
  if(server.exitCode===null){server.kill();await new Promise(resolve=>server.once('exit',resolve));}
  rmSync(temp,{recursive:true,force:true});
}
