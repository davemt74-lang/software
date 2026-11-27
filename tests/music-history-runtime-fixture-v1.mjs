import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {cpSync,mkdtempSync,mkdirSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';


/** Production typed/NDJSON routes with disposable infrastructure and receipt fixtures. */
export async function startMusicHistoryRuntimeFixture(){
const repo=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-music-history-runtime-'));
const listener=net.createServer();
await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));
const port=listener.address().port;
await new Promise(resolve=>listener.close(resolve));
const base=`http://127.0.0.1:${port}`;
mkdirSync(join(temp,'api'));
mkdirSync(join(temp,'sessions'));
for(const name of ['chat-v236.php','chat-stream-v121.php','music-chat-v1.php','music-history-v1.php'])cpSync(join(repo,'api',name),join(temp,'api',name));
cpSync(join(repo,'includes'),join(temp,'includes'),{recursive:true});
mkdirSync(join(temp,'tests','fixtures'),{recursive:true});
const historyFixture=readFileSync(join(repo,'tests/fixtures/music-history-v1.php'),'utf8')
 .replace("require_once __DIR__.'/music-chat-library-v1.php';",'')
 .replaceAll('$owner=11','$owner=1').replaceAll(',11,',',1,').replaceAll(',22,',',2,')
 .replaceAll(',7,42,',',7,14,').replaceAll('youtube_watch_research_owner_11','youtube_watch_research_owner_1');
writeFileSync(join(temp,'tests/fixtures/music-history-v1.php'),historyFixture);
for(const name of ['initiative-chat-loop-http.php','initiative-media-chat-http.php','image-generation-v1.php','workflow-agent-schema-v1.php','stem-mp3-metadata-v1.json'])
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

require_once __DIR__.'/music-history-chat-v1.php';
function music_chat_fixture_message_v1(PDO $pdo,int $cid=14,string $role='assistant'):int{
 $pdo->prepare('INSERT INTO chat_messages(conversation_id,role,message) VALUES(?,?,?)')->execute([$cid,$role,'Fixture history source']);return(int)$pdo->lastInsertId();
}
function music_chat_fixture_video_v1(int $n=1):array{
 $id='vp3'.str_pad((string)$n,8,'0',STR_PAD_LEFT);
 return ['source'=>'youtube','source_id'=>$id,'expires_at'=>time()+600,'youtube_video'=>['video_id'=>$id,'title'=>'YouTube Song '.$n,
 'channel_title'=>'Fixture Channel','thumbnail_url'=>'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg','made_for_kids'=>false,'music_category'=>true]];
}
require_once __DIR__.'/../tests/fixtures/music-history-v1.php';
if(!table_exists('fixture_history_seed')){
 if(!column_exists('tracks','duration'))db()->exec('ALTER TABLE tracks ADD COLUMN duration TEXT');
 vp3_youtube_playback_install_v1(db());
 db()->prepare('INSERT INTO agent_connection_youtube_consent_v1(owner_user_id,policy_version,accepted_at) VALUES(?,?,?)')->execute([1,VP3_YOUTUBE_CONSENT_POLICY_V1,time()]);
 db()->prepare('INSERT INTO vp3_youtube_history_settings_v1(owner_user_id,capture_key) VALUES(?,?)')->execute([1,str_repeat('c',32)]);
 music_history_fixture_schema_v1(db());$seed=music_history_fixture_seed_v1(db());
 db()->exec('CREATE TABLE fixture_history_seed(payload TEXT)');db()->prepare('INSERT INTO fixture_history_seed VALUES(?)')->execute([json_encode($seed,JSON_THROW_ON_ERROR)]);
}
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
 $out=[];foreach(['chat_messages','music_chat_results_v1','music_chat_playlists_v1','music_chat_play_actions_v1','track_play_sessions','fixture_archive_attempts','fixture_model_calls','fixture_history_seed','agent_connection_youtube_results_v1','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1'] as $table)
 $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
 echo json_encode($out,JSON_THROW_ON_ERROR);return;
}
if($path==='/withdraw'){db()->exec('UPDATE tracks SET is_published=0 WHERE id=41');echo '{"ok":true}';return;}
if($path==='/control'){
 $body=json_decode((string)file_get_contents('php://input'),true);$q=db()->prepare($body['sql']);$q->execute($body['args']??[]);echo json_encode(['ok'=>true]);return;
}
if($path==='/restore'){db()->exec('UPDATE tracks SET is_published=1 WHERE id=41');echo '{"ok":true}';return;}
http_response_code(404);echo '{"ok":false}';
`);
let logs='',cookie='';
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+join(temp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',temp,router],{env:{...process.env,
  VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'music.sqlite'),VP3_INITIATIVE_HTTP_MEDIA:'1',VP3_YOUTUBE_API_KEY:'fixture_youtube_api_key_unused'},
  stdio:['ignore','pipe','pipe']});
server.stderr.on('data',chunk=>{logs+=chunk;});
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

  try {
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>100)throw new Error('PHP fixture did not start: '+logs);
    await new Promise(resolve=>setTimeout(resolve,25));
  }
  const boot=await state();
  const seed=JSON.parse(boot.fixture_history_seed[0].payload);
  return {baseURL:base,base,root:temp,request,json,chat,music,state,seed,
    history:(action,extra={},headers={})=>json('/api/music-history-v1.php',{action,agent_id:7,conversation_id:14,csrf_token:'fixture',...extra},headers),
    control:(sql,args=[])=>json('/control',{sql,args}),
    close:()=>{server.kill();rmSync(temp,{recursive:true,force:true});},
    get logs(){return logs;},get cookie(){return cookie;}};
  } catch(error) { server.kill();rmSync(temp,{recursive:true,force:true});throw error; }
}
