<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/system-apps-v330.php';
require dirname(__DIR__).'/includes/tv-watching-v1.php';
require __DIR__.'/fixtures/media-agent-schema-v1.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$checks=0;$media_calls=[];$media_mode='success';$permissions=['account.access'=>true,'chat.access'=>true];
function db(){return $GLOBALS['pdo'];}function has_permission($key,$u){return $GLOBALS['permissions'][$key]??false;}function personal_capability_has_v242(...$a){return false;}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function column_exists($t,$c){return false;}function url($p){return $p;}function homeserver_vp3_remote_operation_for_user($u,$o,$p){return media_fixture_remote_v1($u,$o,$p);}function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(1,1,7),(2,2,9);CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER);INSERT INTO user_agents VALUES(7,1,1),(8,1,1),(9,2,1)');media_agent_fixture_v1($pdo);
$user=['id'=>1,'is_active'=>1];$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
function readMedia($cap,$extra=[]){$p=['operation'=>'media_read','ids'=>[$cap]]+$extra;return vp3_capability_chat_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],'Explicit record request',['conversation_id'=>1],'off',[],fn($prompt)=>['ok'=>true,'answer'=>json_encode($p)]);}

foreach(['photos.library.read','downloads.jobs.read','media.processing.read','video.projects.read'] as $cap){
 $view=$cap==='photos.library.read'?'search':'list';
 $read=fn($v)=>vp3_capability_chat_v1($pdo,$user,$principal,'Read my records',['conversation_id'=>1],'off',[],fn()=>['ok'=>true,'answer'=>json_encode(['operation'=>'photo_media_read','ids'=>[$cap],'view'=>$v])]);
 foreach(['status',$view] as $v){
  $media_mode='success';$r=$read($v);check($r['tool_receipt']['status']==='read_complete'&&!str_contains(json_encode($r),'SECRET')&&!$r['tool_receipt']['changes_applied'],$cap.' '.$v.' returns bounded verified evidence without raw fields');
  foreach(['malformed','offline','denied','identity','revoke','swap'] as $mode){$media_mode=$mode;$r=$read($v);check(in_array($r['tool_receipt']['status'],['failed','blocked'],true)&&!str_contains(json_encode($r),'Fixture')&&!str_contains(json_encode($r),'SECRET'),$cap.' '.$v.' rejects '.$mode.' without exposing records');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1;UPDATE chat_conversations SET user_agent_id=7 WHERE id=1');}
 }
 $media_mode='empty';$r=$read($view);check($r['tool_receipt']['status']==='no_matches',$cap.' confirmed empty list is distinct from an error');
 $media_mode='zero';$r=$read('status');check($r['tool_receipt']['status']==='read_complete',$cap.' confirmed zero counts remain readable');
 $media_mode='negative';$r=$read('status');check($r['tool_receipt']['status']==='failed',$cap.' negative counts cannot become status evidence');
 $media_mode='success';$before=count($media_calls);$r=vp3_capability_chat_v1($pdo,$user,$principal,'Can you access media?',['conversation_id'=>1],'off',[],fn()=>['ok'=>true,'answer'=>json_encode(['operation'=>'inspect','ids'=>[$cap]])]);check(count($media_calls)===$before&&str_contains($r['answer'],'not been checked'),$cap.' ability question performs no remote read');
}
$media_mode='success';
foreach([['vp3_photo_library_agent_query_v310','Show photo library sunset'],['vp3_download_manager_agent_query_v320','Show download manager jobs'],['vp3_media_processor_agent_query_v330','Show media processor jobs'],['vp3_video_editor_agent_query_v280','Show video editor projects']] as [$f,$q]){ $r=$f($q,$user,1,'media_fixture_remote_v1');check($r['handled']&&$r['tool_receipt']['status']==='read_complete','legacy '.$f.' uses the same scoped read');}
$snapshot=vp3_capability_snapshot_v1($pdo,$user,$principal,['conversation_id'=>1]);
foreach([['ids'=>['photos.library.read'],'view'=>'delete'],['ids'=>['downloads.jobs.read'],'view'=>'search'],['ids'=>['photos.library.read'],'view'=>'status','query'=>'sunset'],['ids'=>['photos.library.read'],'confirmed'=>true],['ids'=>['photos.library.read','video.projects.read']]] as $fields){try{vp3_capability_plan_v1(json_encode(['operation'=>'photo_media_read']+$fields),$snapshot);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'model plan rejects incompatible selectors or actions');}
echo "PHOTO_MEDIA_AGENT_S9=PASS ($checks cases)\n";
