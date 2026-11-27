import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {cpSync,mkdtempSync,mkdirSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

// Real typed and NDJSON voice controllers + shared Chat runtime. Only the
// existing fixture database/auth/provider-routing infrastructure is substituted.
const repo=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-music-chat-runtime-'));
const listener=net.createServer();
await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));
const port=listener.address().port;
await new Promise(resolve=>listener.close(resolve));
const base=`http://127.0.0.1:${port}`;
mkdirSync(join(temp,'api'));
mkdirSync(join(temp,'sessions'));
for(const name of ['chat-v236.php','chat-stream-v121.php','music-chat-v1.php'])cpSync(join(repo,'api',name),join(temp,'api',name));
cpSync(join(repo,'includes'),join(temp,'includes'),{recursive:true});
mkdirSync(join(temp,'tests','fixtures'),{recursive:true});
for(const name of ['initiative-chat-loop-http.php','initiative-media-chat-http.php','image-generation-v1.php','workflow-agent-schema-v1.php'])
  cpSync(join(repo,'tests','fixtures',name),join(temp,'tests','fixtures',name));
writeFileSync(join(temp,'includes','cognitive-presentation-v510.php'),'<?php');
writeFileSync(join(temp,'includes','ai-settings.php'),'<?php function ai_encrypt_secret(string $v):string{return "fixture:".base64_encode($v);}');
const routing=join(temp,'includes','agent-runtime-routing-v420.php');
writeFileSync(routing,readFileSync(routing,'utf8').replace(/function vp3_agent_runtime_plan_v420\([\s\S]*?(?=\nfunction |\Z)/,''));
const runtime=join(temp,'includes','agent-runtime-v125.php');
writeFileSync(runtime,readFileSync(runtime,'utf8').replace(/function agent_runtime_v125_trace(?:_id)?\([\s\S]*?(?=\nfunction )/g,''));
const imageFixture=join(temp,'tests','fixtures','image-generation-v1.php');
writeFileSync(imageFixture,readFileSync(imageFixture,'utf8').replace("return $GLOBALS['pdo'];","return $GLOBALS['fixtureVoiceDatabase']??$GLOBALS['pdo'];"));
const chatFixture=join(temp,'tests','fixtures','initiative-chat-loop-http.php');
writeFileSync(chatFixture,readFileSync(chatFixture,'utf8').replace('$pdo=new InitiativeHttpPDO',"$pdo=$GLOBALS['fixtureVoiceDatabase']=new InitiativeHttpPDO"));
writeFileSync(join(temp,'includes','bootstrap.php'),`<?php
session_start();$_SESSION['csrf_token']='fixture';
define('STONEFELLOW_ROOT',dirname(__DIR__));
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
require_once __DIR__.'/music-chat-library-v1.php';
function agent_brain_archive_and_parse(array $user,int $cid,int $messageId,string $role,string $message,string $mode):int{
    db()->prepare('INSERT INTO fixture_archive_attempts(message_id,role) VALUES(?,?)')->execute([$messageId,$role]);return 0;
}
if(!table_exists('user_agent_preferences')){
 db()->exec('CREATE TABLE user_agent_preferences(user_id INTEGER,preference_key TEXT,preference_value TEXT);
 CREATE TABLE user_relationships(id INTEGER PRIMARY KEY);CREATE TABLE user_data_policy_audit(id INTEGER PRIMARY KEY);
 CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);ALTER TABLE chat_conversations ADD COLUMN created_at TEXT;
 CREATE TABLE fixture_archive_attempts(message_id INTEGER,role TEXT)');
}
if(!agent_connection_youtube_results_ready_v1(db())){
 agent_connection_youtube_consent_schema_v1(db());agent_connection_youtube_results_schema_v1(db());
 agent_connection_youtube_quota_schema_v1(db());vp3_youtube_picks_install_v1(db());
}
initiative_media_http_install_v1(db());db()->exec('PRAGMA foreign_keys=ON');
if(!music_listening_v1_schema_ready(db()))music_listening_v1_install(db());
if(!vp3_music_chat_ready_v1(db()))vp3_music_chat_schema_v1(db());
`);
const audio=Buffer.alloc(44+16000);
audio.write('RIFF');audio.writeUInt32LE(audio.length-8,4);audio.write('WAVEfmt ',8);audio.writeUInt32LE(16,16);
audio.writeUInt16LE(1,20);audio.writeUInt16LE(1,22);audio.writeUInt32LE(8000,24);audio.writeUInt32LE(16000,28);
audio.writeUInt16LE(2,32);audio.writeUInt16LE(16,34);audio.write('data',36);audio.writeUInt32LE(16000,40);
writeFileSync(join(temp,'native.wav'),audio);
const router=join(temp,'router.php');
writeFileSync(router,`<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/health'){echo 'ready';return;}
if(str_starts_with($path,'/api/')){require __DIR__.$path;return;}
require __DIR__.'/includes/bootstrap.php';header('Content-Type: application/json');
if($path==='/state'){
 $out=[];foreach(['chat_messages','music_chat_results_v1','music_chat_playlists_v1','music_chat_play_actions_v1','track_play_sessions','fixture_archive_attempts','fixture_model_calls'] as $table)
 $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
 echo json_encode($out,JSON_THROW_ON_ERROR);return;
}
if($path==='/withdraw'){db()->exec('UPDATE tracks SET is_published=0 WHERE id=41');echo '{"ok":true}';return;}
if($path==='/restore'){db()->exec('UPDATE tracks SET is_published=1 WHERE id=41');echo '{"ok":true}';return;}
http_response_code(404);echo '{"ok":false}';
`);
let logs='',cookie='',checks=0;
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+join(temp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',temp,router],{env:{...process.env,
  VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'music.sqlite'),VP3_INITIATIVE_HTTP_MEDIA:'1',VP3_YOUTUBE_API_KEY:'fixture_youtube_api_key_unused'},
  stdio:['ignore','pipe','pipe']});
server.stderr.on('data',chunk=>{logs+=chunk;});
const equal=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
const ok=(actual,message)=>{assert.ok(actual,message);checks++;};
async function request(path,data,headers={}){
  const response=await fetch(base+path,{method:data===undefined?'GET':'POST',headers:{...(cookie?{Cookie:cookie}:{}),
    ...(data===undefined?{}:{'Content-Type':'application/json'}),...headers},body:data===undefined?undefined:JSON.stringify(data)});
  const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];
  const raw=await response.text();
  if(/Fatal error|Warning:|Parse error/.test(raw))throw new Error(raw);
  return {response,raw};
}
async function json(path,data,headers={}){
  const value=await request(path,data,headers);
  try{return {...value,data:JSON.parse(value.raw)};}catch(error){throw new Error(path+' returned invalid JSON: '+value.raw,{cause:error});}
}
let sequence=0;
const chat=(message,extra={},headers={})=>json('/api/chat-v236.php?agent=7',{
  action:'send',conversation_id:14,csrf_token:'fixture',message,music_request_id:'runtime-request-'+(++sequence),...extra},headers);
const music=(action,extra={},headers={})=>json('/api/music-chat-v1.php',{
  action,agent_id:7,conversation_id:14,csrf_token:'fixture',...extra},headers);
const state=async()=>(await json('/state')).data;
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>100)throw new Error('PHP fixture did not start.');
    await new Promise(resolve=>setTimeout(resolve,25));
  }
  const single=await chat('Show me one calm song from VP3.');
  equal(single.response.status,200,single.raw);equal(single.data.ok,true);
  equal(single.data.music_chat_v1.items.length,1,'Actual typed Chat emits one card');
  equal(single.data.music_chat_v1.items[0].source,'vp3');equal(single.data.media,[],'No legacy answer/query append');
  equal(single.data.youtube_results,undefined);equal(single.data.media_playback,undefined);equal(single.data.music_chat_v1.autoplay,undefined);
  const persisted=await state();
  const row=persisted.chat_messages.find(row=>Number(row.id)===single.data.assistant_message_id);
  const context=JSON.parse(row.context_json);
  equal(Object.keys(context.music_chat_v1).sort(),['agent_id','conversation_id','owner_user_id','protocol','result_id','revision','version']);
  equal(context.skip_brain_archive,true);equal(persisted.fixture_archive_attempts,[],'Music interaction is not silently archived into Brain');
  equal(persisted.fixture_model_calls,[],'Direct music lookup does not call the conversational model');
  equal(persisted.track_play_sessions,[],'A displayed song is not a listen');
  equal(Number(persisted.music_chat_results_v1[0].assistant_message_id),single.data.assistant_message_id,'Result binds actual saved assistant message');
  const history=await json('/api/chat-v236.php?agent=7',{action:'load',conversation_id:14,csrf_token:'fixture'});
  equal(history.response.status,200,history.raw);
  const historical=history.data.messages.find(message=>Number(message.id)===single.data.assistant_message_id);
  const historicalContext=typeof historical.context_json==='string'?JSON.parse(historical.context_json):historical.context;
  equal(historicalContext.music_chat_v1.result_id,single.data.music_chat_v1.result_id,'Actual history projection preserves opaque result reference');
  equal(historicalContext.music_chat_v1.autoplay,undefined,'History has no automatic action');

  const created=await chat('Create an eight-song calm playlist from VP3 called "Runtime Night"',{music_request_id:'runtime-stable-playlist'});
  equal(created.response.status,200,created.raw);equal(created.data.music_chat_v1.items.length,2);
  equal(created.data.music_chat_v1.requested_count,8,'Bound playlist preserves requested count');
  ok(created.data.answer.includes('2 of the 8'),'Bound answer honestly reports provider/catalog shortfall');
  ok(created.data.answer.startsWith('Created “Runtime Night”'),'Create success follows durable binding');
  equal(created.data.tool_receipt.capability,'music.playlists.manage');equal(created.data.tool_receipt.changes_applied,true);
  equal(created.data.music_chat_v1.autoplay,undefined);equal((await state()).music_chat_playlists_v1.length,1);
  const retry=await chat('Create an eight-song calm playlist from VP3 called "Runtime Night"',{music_request_id:'runtime-stable-playlist'});
  equal(retry.response.status,200,retry.raw);equal(retry.data.music_chat_v1.result_id,created.data.music_chat_v1.result_id);
  equal(retry.data.tool_receipt.changes_applied,false);equal(retry.data.music_chat_v1.autoplay,undefined);
  equal((await state()).music_chat_playlists_v1.length,1,'Typed HTTP retry cannot create another private playlist');

  const play=await chat('Play the second one from VP3');
  equal(play.response.status,200,play.raw);equal(play.data.music_chat_v1.items.length,1);
  equal(play.data.music_chat_v1.items[0].source_id,created.data.music_chat_v1.items[1].source_id,'Runtime ordinal uses exact saved order');
  ok(play.data.music_chat_v1.autoplay?.action_id,'Explicit runtime play is executable');
  const resolution={result_id:play.data.music_chat_v1.result_id,revision:play.data.music_chat_v1.revision,index:0,
    auto_action_id:play.data.music_chat_v1.autoplay.action_id};
  const resolved=await music('resolve',resolution);equal(resolved.response.status,200,resolved.raw);
  ok(resolved.data.media_playback,'Actual playback API produces existing native source action');
  equal((await music('resolve',resolution)).data.already_claimed,true,'Automatic source action claims once');
  equal((await state()).track_play_sessions,[],'Preparing playback is not a confirmed listen');

  const voiceBody={conversation_id:14,user_agent_id:7,csrf_token:'fixture',message:'Show me one calm song from VP3.',
    music_request_id:'runtime-voice-stable',idempotency_key:'runtime-voice-transport'};
  const voice=await request('/api/chat-stream-v121.php?agent=7',voiceBody);
  const events=voice.raw.trim().split('\n').map(line=>JSON.parse(line));
  const done=events.find(event=>event.type==='done')?.data;
  ok(done,voice.raw);equal(done.input_mode,'voice');equal(done.music_chat_v1.items.length,1);
  equal(done.music_chat_v1.items[0].source_id,single.data.music_chat_v1.items[0].source_id,'Typed and voice share exact selector');
  equal(done.media,[]);equal(done.youtube_results,undefined);equal(done.music_chat_v1.autoplay,undefined);
  const voiceRetry=await request('/api/chat-stream-v121.php?agent=7',{...voiceBody,idempotency_key:'runtime-voice-new-transport'});
  const replay=voiceRetry.raw.trim().split('\n').map(line=>JSON.parse(line)).find(event=>event.type==='done')?.data;
  ok(replay,voiceRetry.raw);equal(replay.music_chat_v1.result_id,done.music_chat_v1.result_id,'Voice forwards stable music_request_id across transport retries');
  equal(replay.music_chat_v1.autoplay,undefined);
  const replayEvents=raw=>raw.trim().split('\n').map(line=>JSON.parse(line));
  const sameTransport=await request('/api/chat-stream-v121.php?agent=7',voiceBody);
  const cachedMusic=replayEvents(sameTransport.raw).find(event=>event.type==='done')?.data;
  ok(cachedMusic,sameTransport.raw);equal(cachedMusic.music_chat_v1.result_id,done.music_chat_v1.result_id);
  equal(cachedMusic.music_chat_v1.replayed,true,'Same voice transport retry rehydrates current stored selection');
  equal(cachedMusic.music_chat_v1.autoplay,undefined,'Transport replay never repeats a music action');
  const changedVoice=await request('/api/chat-stream-v121.php?agent=7',{...voiceBody,message:'Show me two calm songs from VP3.'});
  ok(replayEvents(changedVoice.raw).some(event=>event.type==='error'&&/music request changed/.test(event.error)),'Same transport ID cannot replay a different music request');
  const changedChat=await request('/api/chat-stream-v121.php?agent=7',{...voiceBody,conversation_id:15});
  ok(replayEvents(changedChat.raw).some(event=>event.type==='error'),'Voice transport reference cannot transfer to another Chat');
  await json('/withdraw');
  const revokedReplay=await request('/api/chat-stream-v121.php?agent=7',voiceBody);
  const revokedMusic=replayEvents(revokedReplay.raw).find(event=>event.type==='done')?.data;
  ok(revokedMusic,revokedReplay.raw);
  equal(revokedMusic.music_chat_v1.items[0].available,false,'Cached voice response rechecks current song access');
  equal(revokedMusic.music_chat_v1.items[0].source_id,'','Revoked voice result hides source identity');
  ok(!revokedReplay.raw.includes(single.data.music_chat_v1.items[0].title),'Revoked song title is absent from response and spoken text');
  await json('/restore');

  const clarifiedList=await chat('Show me two calm songs from VP3');
  const clarification=await chat('Play that one');
  equal(clarification.data.music_chat_v1,undefined,'Ambiguous deictic request does not silently select the first song');
  equal(clarification.data.tool_receipt.status,'clarification_required');
  const afterClarification=await chat('Play the second one');
  equal(afterClarification.data.music_chat_v1.items[0].source_id,clarifiedList.data.music_chat_v1.items[1].source_id,'Numeric followup survives the clarification and keeps the displayed selection');
  const foreign=await music('result',{result_id:single.data.music_chat_v1.result_id},{'X-Fixture-User':'2'});
  equal(foreign.response.status,403,'Real result API rejects other authenticated owner');
  const sibling=await music('result',{result_id:single.data.music_chat_v1.result_id,conversation_id:15});
  equal(sibling.response.status,404,'Exact result is not transferable to sibling conversation');
  const unavailable=await chat('Play one song from YouTube');
  equal(unavailable.response.status,200,unavailable.raw);equal(unavailable.data.music_chat_v1.status,'unavailable');
  equal(unavailable.data.music_chat_v1.items,[]);equal(unavailable.data.media,[],'Unavailable chosen source never falls back to another catalog');
  equal(unavailable.data.music_chat_v1.autoplay,undefined);
  equal((await state()).fixture_model_calls,[],'All actual music routes remain deterministic');
  console.log(`MUSIC_CHAT_RUNTIME_V1=PASS ${checks} actual typed/voice HTTP, persistence, source and history checks`);
}catch(error){throw new Error(error.message+'\nPHP fixture log:\n'+logs,{cause:error});}
finally{server.kill('SIGTERM');await new Promise(resolve=>{server.once('exit',resolve);setTimeout(resolve,1000).unref();});rmSync(temp,{recursive:true,force:true});}
