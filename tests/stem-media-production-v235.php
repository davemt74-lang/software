<?php
declare(strict_types=1);
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/vp3-stem-'.bin2hex(random_bytes(6));mkdir($temp);mkdir($temp.'/uploads/stems',0777,true);define('STONEFELLOW_ROOT',$temp);
require $root.'/includes/user-agent-system-v236.php';
require $root.'/includes/agent-media-production-v1.php';
require $root.'/includes/stem-agent-continuity-v235.php';
require $root.'/includes/agent-chat-boundary-v380.php';
require $root.'/includes/agent-media-entertainment-v1.php';
require $root.'/includes/agent-coordination-sources-v1.php';
require $root.'/includes/agent-coordination-work-v1.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',fn()=>'2026-11-22 21:00:00');$permission=true;$workspace=true;$participant=null;$checks=0;
function db():PDO{return $GLOBALS['pdo'];}
function setting($key,$default=''){return $default;}
function has_permission($key,$u):bool{return $GLOBALS['permission'];}
function music_workspace_enabled_v320($u):bool{return (bool)$u['enabled'];}
function can_manage_track_production($track,$user):bool{return $GLOBALS['workspace']&&(int)$track['owner_user_id']===(int)$user['id'];}
function can_view_track($track,$user):bool{return can_manage_track_production($track,$user);}
function url($p){return $p;}
function table_exists($table):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return (bool)$s->fetchColumn();}
function check($value,$why):void{if(!$value)throw new RuntimeException($why);$GLOBALS['checks']++;}
function blocked(callable $work):bool{try{$work();return false;}catch(RuntimeException){return true;}}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,enabled INTEGER);INSERT INTO users VALUES(1,1,1),(2,1,1);
CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);INSERT INTO user_agents VALUES(7,1,1,'Main'),(8,2,1,'Foreign'),(9,1,0,'Disabled');
CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
INSERT INTO user_data_policies VALUES(1,'knowledge','music-production','private',1,0,0);
CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT);
INSERT INTO chat_conversations VALUES(101,1,7,'Main','now','a'),(102,1,NULL,'System','now','z'),(103,2,8,'Foreign','now','z');
CREATE TABLE chat_messages(id INTEGER PRIMARY KEY);
CREATE TABLE agent_studio_history(id INTEGER PRIMARY KEY,session_id INTEGER,user_id INTEGER,role TEXT,command_json TEXT,status TEXT);
CREATE TABLE tracks(id INTEGER PRIMARY KEY,owner_user_id INTEGER,producer_user_id INTEGER,workspace_id INTEGER,title TEXT,updated_at TEXT);
INSERT INTO tracks VALUES(1,1,1,1,'Private song','now'),(2,2,2,2,'SECRET','now');
CREATE TABLE track_stems(id INTEGER PRIMARY KEY,track_id INTEGER,project_id INTEGER,stem_name TEXT,stem_role TEXT,file_path TEXT,duration_seconds REAL,is_active INTEGER,sort_order INTEGER);
INSERT INTO track_stems VALUES(6,1,10,'Bass','Bass','/uploads/stems/tone.mp3',0,1,0),(7,2,20,'Secret','Vocal','/uploads/stems/tone.mp3',1.2,1,0);");
try{
 $fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/stem-mp3-metadata-v1.json'),true);file_put_contents($temp.'/uploads/stems/tone.mp3',base64_decode($fixtures['fixtures'][0]['base64']));
 $scope=['user'=>['id'=>1,'is_active'=>1],'agent_id'=>7,'conversation_id'=>101];
 $read=fn($refs=null)=>vp3_media_production_sources_v1($pdo,$scope,$refs);
 $items=$read();$item=$items['media:studio:1']??[];
 check(count($items)===1&&!str_contains(json_encode($items),'SECRET'),'private production discovery cannot include another owner');
 check($item['trigger']==='production_project'&&str_contains($item['summary'],'1 have readable audio headers'),'native metadata repairs zero-duration evidence without marking healthy MP3 missing');
 check($read()['media:studio:1']['revision']===$item['revision'],'unchanged evidence has stable revision');
 check($read(['media:studio:2'])===[]&&$read(['media:track:1'])===[],'exact source reads respect family and production ownership');
 $workspace=false;check($read()===[],'workspace authority is rechecked');$workspace=true;
 $pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','music-production','deny')");check($read()===[],'named Agent deny rule is rechecked');$pdo->exec('DELETE FROM user_agent_data_rules');
 $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['id'=>4];check(blocked($read),'participant/public contexts cannot read private production');$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=null;
 $permission=false;check(blocked($read),'account permission revocation blocks production');$permission=true;
 $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');check(blocked($read),'disabled owner is rejected');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
 unlink($temp.'/uploads/stems/tone.mp3');$missing=$read()['media:studio:1'];
 check($missing['trigger']==='production_attention'&&$missing['revision']!==$item['revision']&&str_contains($missing['summary'],'Bass (missing)'),'missing saved bytes create an exact named attention finding');
 check(vp3_coordination_source_ref_valid_v1('media:studio:1')&&!vp3_coordination_source_ref_valid_v1('media:studio:../1'),'production refs use canonical validation');
 $rules=vp3_coordination_rule_definitions_v1();
 check(($rules['media']['triggers']['production_project']['default']??null)==='watch','normal production uses Watch by default');
 $hold=null;check(vp3_media_chat_ready_v1($pdo,$scope['user'],['coordination_route'=>['specialist'=>'media','trigger'=>'production_attention']],$hold),'production reports do not inherit playback-offer cooldown');
 $agent=['id'=>7];check(stem_agent_v131_conversation($pdo,1,0,false,'Studio',$agent)===101,'Studio fallback stays in its exact Agent');
 check(stem_agent_v131_conversation($pdo,1,0,false,'Studio',null)===102,'system fallback stays in system namespace');
 check(blocked(fn()=>stem_agent_v131_conversation($pdo,1,102,false,'Studio',$agent))&&blocked(fn()=>stem_agent_v131_conversation($pdo,1,103,false,'Studio',$agent)),'explicit cross-Agent and cross-owner conversations are refused');
 $binding=['conversation_id'=>101,'assistant_message_id'=>55,'agent_id'=>7];$q=$pdo->prepare("INSERT INTO agent_studio_history VALUES(4,3,1,'assistant',?,'pending')");$q->execute([json_encode($binding)]);
 check(stem_agent_result_binding_v235($pdo,1,3,4,101,55,7)===$binding,'verified result matches the issued exact proposal');
 foreach([[1,3,4,102,55,7],[1,3,4,101,56,7],[1,9,4,101,55,7],[2,3,4,101,55,7],[1,3,4,101,55,8]] as $args)check(blocked(fn()=>stem_agent_result_binding_v235($pdo,...$args)),'forged result scope rejected');
 $pdo->exec("UPDATE agent_studio_history SET status='success' WHERE id=4");check(blocked(fn()=>stem_agent_result_binding_v235($pdo,1,3,4,101,55,7)),'completed proposals cannot be replayed');
 echo "STEM_MEDIA_PRODUCTION_V235=PASS {$checks} authority, evidence, source and conversation checks\n";
}finally{if(is_file($temp.'/uploads/stems/tone.mp3'))unlink($temp.'/uploads/stems/tone.mp3');rmdir($temp.'/uploads/stems');rmdir($temp.'/uploads');rmdir($temp);}
