<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require dirname(__DIR__).'/includes/agent-brain-v122.php';
require dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
class ParticipantTestPDO extends PDO {
 public bool $rejectWrite=false;
 public function prepare(string $s,array $o=[]):PDOStatement|false {
  if($this->rejectWrite&&str_contains($s,'INSERT INTO agent_task_list_items_v1'))throw new PDOException('Injected failure');
  if(str_contains($s,'information_schema.statistics'))$s="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";
  $s=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$s);$s=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$s);return parent::prepare($s,$o);
 }
}
$pdo=new ParticipantTestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('LAST_INSERT_ID',fn($n)=>$n);
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function column_exists($t,$c){foreach(db()->query('PRAGMA table_info('.$t.')') as $r)if($r['name']===$c)return true;return false;}
function has_permission($p,$u){return !isset($GLOBALS['denied'][$p]);}function personal_capability_has_v242($p,$u){return has_permission($p,$u);}function agent_brain_schema_ready(){return table_exists('agent_memory_items');}function agent_brain_normalize($s){return mb_strtolower(trim($s));}
function knowledge_retrieval_v162_owner_session($u,$p){return ($p['kind']??'')==='system'||(($p['kind']??'')==='user_agent'&&($p['owner_user_id']??0)===$u['id']);}
function user_data_policy_can_use_v236($pdo,$p,$uid,$domain,$id,$public){return !isset($GLOBALS['denied'][$id]);}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(10,1,7),(11,1,8),(12,2,9);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT);CREATE TABLE agent_chat_archive(id INTEGER,user_agent_id INTEGER);CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT UNIQUE,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT);CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)");
vp3_task_lists_schema_v1($pdo);vp3_task_lists_schema_v1($pdo);

require dirname(__DIR__).'/includes/participant-chat-v1.php';
$pdo->exec("CREATE TABLE studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER,recognition_consent INTEGER,cloning_consent INTEGER,linked_user_id INTEGER,relationship_scope TEXT); INSERT INTO studio_participants VALUES(7,1,'David',1,1,0,NULL,'guest'),(8,1,'Alex',1,1,0,2,'contact'),(9,2,'Foreign',1,1,0,1,'self'),(10,1,'Inactive',0,1,0,NULL,'guest'); CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,is_read INTEGER,source_type TEXT,source_id INTEGER)");
vp3_participant_updates_schema_v1($pdo);vp3_participant_updates_schema_v1($pdo);reminder_schema_v1($pdo);
$u=['id'=>1,'is_active'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$ctx=['conversation_id'=>10];
function vp3_capability_live_user_v1($pdo,$u,$p,$ctx,$authority){if($authority)$authority();vp3_task_list_authorize_v1($pdo,$u,$p,(int)($ctx['conversation_id']??0));return $u;}
function ck($ok,$name){if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
function rejects($fn,$name){try{$fn();}catch(Throwable $e){ck(true,$name);return;}throw new LogicException($name);}
function listOp($op,$extra=[]){return vp3_task_list_execute_v1($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],['action'=>$op]+$extra,$GLOBALS['ctx']);}
function ownerList($name,$title){$r=listOp('add',['list_name'=>$name,'title'=>$title]);db()->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(10,'assistant',?)")->execute([json_encode($r)]);return listOp('confirm')['sticky_notes'][0]['id'];}
function settings($grants=[],$extra=[]){$pdo=$GLOBALS['pdo'];$u=$GLOBALS['u'];$p=$GLOBALS['p'];return vp3_participant_configure_v1($pdo,$u,$p,$extra+['enabled'=>true,'self_participant_id'=>7,'offer_updates'=>true,'participant_id'=>8,'grants'=>$grants,'revision'=>vp3_participant_settings_v1($pdo,$u)['revision']]);}
function guest($fn){return vp3_participant_with_scope_v1($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],8,true,$fn);}
function planRun($plan,$query='Show updates',$during=null){$pdo=$GLOBALS['pdo'];$u=$GLOBALS['u'];$p=$GLOBALS['p'];$actor=['participant_id'=>8,'recognition'=>true,'revision'=>vp3_participant_settings_v1($pdo,$u)['revision']];return vp3_participant_chat_tool_v1($pdo,$u,$p,$actor,$query,10,123,static function($prompt)use($plan,$during){$GLOBALS['lastPrompt']=$prompt;if($during)$during();return ['answer'=>json_encode($plan)];});}
$checks=0;
ck(!vp3_participant_settings_v1($pdo,$u)['enabled'],'participant mode is opt-in and upgrade is idempotent');
$prep=ownerList('Prep','Pizza dough');$secret=ownerList('Private budget','Secret owner task');
$task=(int)$pdo->query("SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=$prep")->fetchColumn();
$pdo->exec("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at) VALUES(1,1,'Shared medicine','UTC','2026-11-02 10:00:00','none','text',NULL,'active',10,'2026-11-02T10:00:00Z','2026-11-02T10:00:00Z'),(2,1,'Secret owner reminder','UTC','2026-11-02 10:00:00','none','text',NULL,'active',10,'2026-11-02T10:00:00Z','2026-11-02T10:00:00Z'); INSERT INTO agent_reminder_occurrences_v1(id,reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(1,1,1,'2026-11-02T10:00:00Z','2026-11-02T10:00:00Z','pending')");
settings();$rows=guest(fn()=>vp3_participant_resources_v1($pdo,$u,$p));ck(!$rows['lists']&&!$rows['tasks']&&!$rows['reminders'],'linked contact gets no owner records without explicit shares');
$voice=['input_mode'=>'voice','agent_context'=>['voice_speaker'=>['participant_id'=>7,'source'=>'local_voice_similarity','confidence'=>.96,'authentication_authority'=>false]]];
ck(vp3_participant_chat_actor_v1($pdo,$u,$voice)===null,'explicit own-profile mapping preserves owner voice tools');
$voice['agent_context']['voice_speaker']['participant_id']=8;ck(vp3_participant_chat_actor_v1($pdo,$u,$voice)['participant_id']===8,'recognized contact selects isolated shared-record path');
$voice['agent_context']['voice_speaker']['confidence']=.4;ck(vp3_participant_chat_actor_v1($pdo,$u,$voice)['blocked'],'uncertain voice is asked who is speaking');
ck(vp3_participant_chat_actor_v1($pdo,$u,['input_mode'=>'voice'])['blocked'],'unassigned voice cannot fall back to owner personal retrieval');
ck(vp3_participant_chat_actor_v1($pdo,$u,['input_mode'=>'text'])===null,'ordinary typed Chat remains owner scoped');
ck(vp3_participant_chat_actor_v1($pdo,$u,['agent_context'=>['voice_speaker'=>['participant_id'=>9,'source'=>'manual_selection']]])['blocked'],'foreign participant cannot be selected');
$grant=[['kind'=>'task_list','id'=>$prep,'access'=>'read'],['kind'=>'reminder','id'=>1,'access'=>'read']];settings($grant);
$rows=guest(fn()=>vp3_participant_resources_v1($pdo,$u,$p));ck(count($rows['lists'])===1&&count($rows['tasks'])===1&&count($rows['reminders'])===1,'only shared list and its canonical tasks plus shared reminder are read');
ck(!str_contains(json_encode($rows),'Secret'),'private record titles never enter guest snapshot');
$r=planRun(['operation'=>'show','kind'=>'all','id'=>0]);ck(str_contains($r['answer'],'Pizza dough')&&!str_contains($r['answer'],'Secret')&&$r['sticky_notes'][0]['participant_id']===8,'guest Chat output and note references stay participant scoped');
ck(!str_contains($GLOBALS['lastPrompt'],'Secret'),'classifier receives no owner private history or records');
$r=planRun(['operation'=>'add','id'=>$prep,'title'=>'Sauce']);ck($r['tool_receipt']['status']==='blocked','read-only list cannot create a canonical task');
rejects(fn()=>planRun(['operation'=>'task_status','id'=>$task,'status'=>'completed']),'read-only inherited task cannot be changed');
rejects(fn()=>planRun(['operation'=>'reminder_status','id'=>1,'status'=>'complete']),'read-only reminder cannot be acknowledged');
ck($pdo->query('SELECT status FROM agent_reminder_occurrences_v1 WHERE id=1')->fetchColumn()==='pending','reading shared reminders leaves delivery and completion intact');
$grant[0]['access']='edit';$grant[1]['access']='edit';settings($grant);
$r=planRun(['operation'=>'add','id'=>$prep,'title'=>'Sauce']);ck($r['tool_receipt']['changes_applied'],'editable shared list adds a real canonical task');
$r=planRun(['operation'=>'add','id'=>$prep,'title'=>'Sauce']);ck(!$r['tool_receipt']['changes_applied'],'duplicate shared list add does not duplicate a task');
$r=planRun(['operation'=>'task_status','id'=>$task,'status'=>'completed']);ck($r['tool_receipt']['changes_applied']&&vp3_task_list_note_v1($pdo,$u,$p,$prep)['items'][0]['done'],'guest completion uses canonical task lifecycle');
$r=planRun(['operation'=>'reminder_status','id'=>1,'status'=>'snooze','minutes'=>15]);ck(str_contains($r['answer'],'Snoozed'),'editable reminder uses canonical snooze executor');
$r=guest(fn()=>listOp('add',['list_name'=>'Guest invented list','title'=>'Item']));ck($r['tool_receipt']['status']==='blocked'&&vp3_task_list_find_v1($pdo,$u,$p,'Guest invented list')===null,'guest cannot create an unshared list or leave an owner confirmation');
rejects(fn()=>planRun(['operation'=>'task_status','id'=>$task,'status'=>'open'],'Reopen task',fn()=>settings([])),'share revocation during model call fences writes');
ck(vp3_task_list_note_v1($pdo,$u,$p,$prep)['items'][0]['done'],'revoked write left canonical state unchanged');
ck(vp3_participant_scope_v1()===null,'scope always restores after failure');
settings($grant);$rev=vp3_participant_settings_v1($pdo,$u)['revision'];
rejects(fn()=>settings($grant,['revision'=>$rev-1]),'stale sharing form cannot overwrite another window');
rejects(fn()=>settings([['kind'=>'task_list','id'=>999,'access'=>'read']]),'forged record share is rejected');
rejects(fn()=>settings([],['self_participant_id'=>9]),'another owner participant cannot become self');
rejects(fn()=>settings([],['self_participant_id'=>10]),'inactive profile cannot become self');
$wrong=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8];$rows=vp3_participant_with_scope_v1($pdo,$u,$wrong,8,true,fn()=>vp3_participant_resources_v1($pdo,$u,$wrong));ck(!$rows['lists']&&!$rows['tasks']&&!$rows['reminders'],'sharing never crosses Agent namespace');
$pdo->exec('UPDATE studio_participants SET recognition_consent=0 WHERE id=8');rejects(fn()=>guest(fn()=>vp3_participant_resources_v1($pdo,$u,$p)),'recognition revocation invalidates current voice scope');$pdo->exec('UPDATE studio_participants SET recognition_consent=1 WHERE id=8');
$session='11111111-1111-4111-8111-111111111111';$heartbeat=['session_id'=>$session,'sequence'=>1,'participants'=>[7,8]];
vp3_participant_heartbeat_v1($pdo,$u,$p,$heartbeat,1000);$before=$pdo->query('SELECT * FROM agent_participant_presence_v1 WHERE participant_id=7')->fetch();
vp3_participant_observed_v1($pdo,$u,7,'heard',1010);$row=$pdo->query('SELECT * FROM agent_participant_presence_v1 WHERE participant_id=7')->fetch();ck((int)$row['last_seen']===1000&&(int)$row['last_heard']===1010,'nighttime voice updates heard independently of seen');
vp3_participant_heartbeat_v1($pdo,$u,$p,$heartbeat,1050);$row=$pdo->query('SELECT * FROM agent_participant_presence_v1 WHERE participant_id=7')->fetch();ck((int)$row['last_seen']===1000,'replayed/out-of-order heartbeat cannot extend presence');
$offer=vp3_participant_offer_v1($pdo,$u,7,null,1020);ck($offer&&!str_contains(json_encode($offer),'Secret')&&!str_contains(json_encode($offer),'Pizza'),'proactive offer contains no private task or reminder titles');
ck(vp3_participant_offer_v1($pdo,$u,7,null,1021)===null,'cross-tab lease suppresses duplicate offer');
vp3_participant_offer_v1($pdo,$u,7,['token'=>'wrong','visit_id'=>$offer['visit_id']],1022);ck((int)$pdo->query('SELECT offered_at FROM agent_participant_presence_v1 WHERE participant_id=7')->fetchColumn()===0,'wrong acknowledgement cannot consume offer');
vp3_participant_offer_v1($pdo,$u,7,$offer,1023);vp3_participant_observed_v1($pdo,$u,7,'heard',1050);ck(vp3_participant_offer_v1($pdo,$u,7,null,1051)===null,'acknowledged offer repeats neither after speech nor sight within visit');
vp3_participant_observed_v1($pdo,$u,7,'heard',1700);$second=vp3_participant_offer_v1($pdo,$u,7,null,1701);ck($second&&$second['visit_id']!==$offer['visit_id'],'new visit after 10 minutes inactivity receives a new offer');
vp3_participant_offer_v1($pdo,$u,7,$offer,1702);ck((int)$pdo->query('SELECT offered_at FROM agent_participant_presence_v1 WHERE participant_id=7')->fetchColumn()===0,'old visit receipt cannot acknowledge new offer');
ck(vp3_participant_offer_v1($pdo,$u,8,null,2000)===null,'stale observation cannot trigger new update offer');
foreach(['{"operation":"show","kind":"all","id":0,"answer":"Saved"}','{"operation":"add","id":1,"title":"x","grant":"edit"}','{"operation":"reminder_status","id":1,"status":"delete"}'] as $json)rejects(fn()=>vp3_participant_plan_v1($json),'strict plan rejects fabricated success, permission mutation or unsupported write');
$retry=vp3_participant_offer_v1($pdo,$u,7,null,1747);ck($retry&&$retry['visit_id']===$second['visit_id']&&$retry['token']!==$second['token'],'abandoned display lease retries without inventing a new visit');
for($i=0;$i<28;$i++)listOp('add',['list_name'=>'Prep','title'=>'Bounded item '.$i]);
$preview=guest(fn()=>vp3_participant_resources_v1($pdo,$u,$p));ck(count($preview['lists'][0]['items'])===25&&$preview['lists'][0]['truncated'],'long shared lists report partial previews');
ck(count(vp3_task_list_note_v1($pdo,$u,$p,$prep)['items'])===30,'full sticky note still reads canonical items beyond preview bound');
$catalog=vp3_participant_resources_v1($pdo,$u,$p,false);ck(!$catalog['lists'][0]['items'],'sharing catalog does not expand every list item');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');rejects(fn()=>guest(fn()=>vp3_participant_resources_v1($pdo,$u,$p)),'live account disable blocks participant reads');
echo "PARTICIPANT_UPDATES=PASS ($checks cases; canonical SQLite persistence, isolated Chat, grant revocation, visits and occurrence safety)\n";
