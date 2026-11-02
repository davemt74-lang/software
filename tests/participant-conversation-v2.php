<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require dirname(__DIR__).'/includes/agent-brain-v122.php';
require dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
class ParticipantV2TestPDO extends PDO {
 public bool $rejectWrite=false; public bool $rejectLog=false;
 public function prepare(string $s,array $o=[]):PDOStatement|false {
  if($this->rejectLog&&str_contains($s,'INSERT INTO chat_messages'))throw new PDOException('Injected confirmation log failure');
  if($this->rejectWrite&&str_contains($s,'INSERT INTO agent_task_list_items_v1'))throw new PDOException('Injected failure');
  if(str_contains($s,'information_schema.statistics'))$s="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";
  $s=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$s);$s=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$s);return parent::prepare($s,$o);
 }
}
$pdo=new ParticipantV2TestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('LAST_INSERT_ID',fn($n)=>$n);
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
$pdo->exec('ALTER TABLE chat_conversations ADD COLUMN updated_at TEXT');
function proposal($action,$extra=[]){return vp3_participant_owner_tool_v2($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],['action'=>$action]+$extra,['conversation_id'=>10]);}
function review($r){return vp3_participant_review_v2($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],$r['actions'][0]['token'],10);}
function confirm($r,$v,$extra=[]){return vp3_participant_confirm_v2($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],$extra+['token'=>$r['actions'][0]['token'],'conversation_id'=>10,'decision'=>'confirm','owner_confirmed'=>true,'revision'=>$v['revision'],'participant_id'=>7,'list_id'=>0,'access_mode'=>'']);}
function rowCount($t){return (int)db()->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}
$prep=ownerList('Prep','Pizza dough');$private=ownerList('Owner-only budget','PRIVATE owner task');
$r=proposal('link_self');ck(!$r['tool_receipt']['changes_applied']&&rowCount('agent_participant_settings_v1')===0,'profile request prepares a card without mapping or enabling anything');
$v=review($r);ck($v['kind']==='link_self'&&count($v['profiles'])===2&&!in_array(9,array_column($v['profiles'],'id'),true),'review lists only active owned profiles with IDs, without creating a self profile');
rejects(fn()=>confirm($r,$v,['owner_confirmed'=>false]),'confirmation requires explicit signed-in owner approval');
rejects(fn()=>confirm($r,$v,['revision'=>'forged']),'forged or stale review digest cannot be confirmed');
rejects(fn()=>confirm($r,$v,['participant_id'=>9]),'foreign profile cannot be mapped');
rejects(fn()=>vp3_participant_review_v2($pdo,$u,['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],$r['actions'][0]['token'],11),'proposal cannot move to another Agent or conversation');
$profile=$pdo->query('SELECT * FROM studio_participants WHERE id=7')->fetch();$count=rowCount('chat_messages');$result=confirm($r,$v);
ck($result['tool_receipt']['changes_applied']&&$result['settings']['self_participant_id']===7&&$result['settings']['enabled'],'explicit owner click maps the profile and enables participant-aware tasks');
ck($profile===$pdo->query('SELECT * FROM studio_participants WHERE id=7')->fetch(),'mapping changes no account link, recognition/cloning consent or participant identity');
ck(rowCount('chat_messages')===$count+2,'owner confirmation and saved receipt both appear in canonical Chat history');
$rev=$result['settings']['revision'];$again=confirm($r,$v);ck($again['replayed']&&$again['assistant_message_id']===$result['assistant_message_id']&&rowCount('chat_messages')===$count+2&&vp3_participant_settings_v1($pdo,$u)['revision']===$rev,'repeated button request replays the receipt without another change or message');
$task=(int)$pdo->query("SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=$prep")->fetchColumn();
$pdo->prepare("INSERT INTO agent_participant_grants_v1(owner_user_id,participant_id,agent_scope_id,resource_kind,resource_id,access_mode) VALUES(1,8,7,'task',?,'read')")->execute([$task]);
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$prep]);$v=review($r);
ck($v['access_modes']===['read','edit']&&rowCount('agent_participant_grants_v1')===1,'unspecified sharing asks the owner to choose access without a silent default');
rejects(fn()=>confirm($r,$v,['participant_id'=>8,'list_id'=>$prep]),'sharing cannot proceed without choosing view or edit access');
$result=confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'read']);
ck($result['tool_receipt']['changes_applied']&&rowCount('agent_participant_grants_v1')===2,'sharing one list preserves unrelated existing grants');
$read=guest(fn()=>vp3_task_list_note_v1($pdo,$u,$p,$prep));ck($read['items'][0]['title']==='Pizza dough','confirmed share immediately reads canonical task items');
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'read']);$v=review($r);
rejects(fn()=>confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'edit']),'explicit view-only proposal cannot become edit access');
$result=confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'read']);ck(!$result['tool_receipt']['changes_applied'],'already-matching sharing returns a read receipt instead of claiming another change');
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'edit']);$v=review($r);$result=confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'edit']);
ck($result['tool_receipt']['changes_applied']&&guest(fn()=>vp3_participant_resource_v1($pdo,$u,$p,'task_list',$prep,true)),'explicitly reviewed edit access includes canonical additions and task updates');
$r=proposal('link_self',['participant_id'=>8]);$v=review($r);$pdo->exec("UPDATE studio_participants SET display_name='Alex renamed' WHERE id=8");rejects(fn()=>confirm($r,$v,['participant_id'=>8]),'profile rename between review and confirm requires fresh choices');$pdo->exec("UPDATE studio_participants SET display_name='Alex' WHERE id=8");
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$prep]);$v=review($r);$pdo->exec("UPDATE agent_task_lists_v1 SET name='Prep renamed' WHERE id=$prep");rejects(fn()=>confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'edit']),'list rename between review and confirm requires fresh choices');$pdo->exec("UPDATE agent_task_lists_v1 SET name='Prep' WHERE id=$prep");
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$private]);$v=review($r);settings([]);rejects(fn()=>confirm($r,$v,['participant_id'=>8,'list_id'=>$private,'access_mode'=>'edit']),'settings revision changes invalidate older proposals');
$r=proposal('share_list',['participant_id'=>8,'list_id'=>$prep]);$v=review($r);$before=vp3_participant_settings_v1($pdo,$u);$pdo->rejectLog=true;rejects(fn()=>confirm($r,$v,['participant_id'=>8,'list_id'=>$prep,'access_mode'=>'edit']),'failed Chat receipt rolls back permission mutation atomically');$pdo->rejectLog=false;
ck(vp3_participant_settings_v1($pdo,$u)===$before&&rowCount('agent_participant_grants_v1')===0,'failed confirmation preserves settings and sharing');
$result=confirm($r,$v,['decision'=>'cancel']);ck(!$result['tool_receipt']['changes_applied']&&rowCount('agent_participant_grants_v1')===0,'cancel closes the proposal without granting access');
$r=proposal('link_self');$pdo->prepare('UPDATE agent_task_list_actions_v1 SET expires_at=0 WHERE token=?')->execute([$r['actions'][0]['token']]);rejects(fn()=>review($r),'expired proposal cannot be revived by a saved Chat button');
$guestCard=guest(fn()=>proposal('link_self',['participant_id'=>8]));ck(vp3_participant_settings_v1($pdo,$u)['self_participant_id']===7&&!$guestCard['tool_receipt']['changes_applied'],'guest saying that is me only prepares an owner review');
rejects(fn()=>guest(fn()=>proposal('share_list',['participant_id'=>8,'list_id'=>$private])),'guest cannot prepare an owner sharing proposal');
rejects(fn()=>guest(fn()=>review($guestCard)),'guest scope cannot load the private owner review catalog');
$r=proposal('link_self',['participant_id'=>7]);$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(10,'assistant',?)")->execute([json_encode($r)]);
$pending=vp3_participant_pending_v2($pdo,$u,$p,['conversation_id'=>10]);ck($pending['kind']==='link_self','pending participant setup comes from the saved assistant action');
$rr=proposal('review');ck(!$rr['tool_receipt']['changes_applied']&&$rr['actions'][0]['token']===$r['actions'][0]['token'],'a bare yes can review the same card but cannot approve it');
$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(10,'assistant','{}')")->execute();ck(vp3_participant_pending_v2($pdo,$u,$p,['conversation_id'=>10])===null,'unrelated assistant turn does not revive an older participant proposal');
// Open work combines canonical rows, deduplicates list tasks and leaves reminder delivery untouched.
$pdo->exec("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at) VALUES(1,1,'Due shared reminder','UTC','2030-01-01 09:00:00','none','text',NULL,'active',10,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z'),(2,1,'PRIVATE due reminder','UTC','2030-01-01 09:00:00','none','text',NULL,'active',10,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z'),(3,1,'Future shared reminder','UTC','2030-01-02 09:00:00','none','text','2030-01-02T09:00:00Z','active',10,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z'),(4,1,'Finished reminder','UTC','2030-01-01 09:00:00','none','text',NULL,'finished',10,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z'); INSERT INTO agent_reminder_occurrences_v1(id,reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(1,1,1,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z','pending'),(2,2,1,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z','pending'),(4,4,1,'2030-01-01T09:00:00Z','2030-01-01T09:00:00Z','pending')");
settings([['kind'=>'task_list','id'=>$prep,'access'=>'read'],['kind'=>'reminder','id'=>1,'access'=>'read'],['kind'=>'reminder','id'=>3,'access'=>'read']]);
$done=listOp('add',['list_name'=>'Prep','title'=>'Completed prep item']);$n=vp3_task_list_note_v1($pdo,$u,$p,$prep);$doneId=$n['items'][1]['id'];$old=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;vp3_agent_memory_scope_set_current_v410(1,7);agent_task_v123_update($u,$doneId,'completed');if($old===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$old;
$open=listOp('create_task',['title'=>'Call supplier']);$now=new DateTimeImmutable('2030-01-01T12:00:00Z');$occurrences=$pdo->query('SELECT * FROM agent_reminder_occurrences_v1')->fetchAll();
$overview=guest(fn()=>vp3_participant_overview_v2($pdo,$u,$p,8,$now));
ck(substr_count($overview['answer'],'Pizza dough')===1&&!str_contains($overview['answer'],'Completed prep item'),'combined overview deduplicates canonical list tasks and excludes completed items');
ck(str_contains($overview['answer'],'Due shared reminder')&&!str_contains($overview['answer'],'Future shared reminder')&&!str_contains($overview['answer'],'Finished reminder'),'combined overview includes due reminders and excludes future/finished ones');
ck(!str_contains($overview['answer'],'PRIVATE')&&!str_contains($overview['answer'],'Call supplier'),'guest overview excludes unshared owner tasks and reminders');
ck($pdo->query('SELECT * FROM agent_reminder_occurrences_v1')->fetchAll()===$occurrences&&!$overview['tool_receipt']['changes_applied'],'overview never ticks, leases, presents or completes reminder occurrences');
$owner=vp3_participant_overview_v2($pdo,$u,$p,0,$now);ck(str_contains($owner['answer'],'PRIVATE owner task')&&str_contains($owner['answer'],'Call supplier')&&str_contains($owner['answer'],'PRIVATE due reminder'),'owner combined update includes authorized standalone tasks and due reminders');
vp3_participant_observed_v1($pdo,$u,7,'seen',9980);vp3_participant_observed_v1($pdo,$u,8,'heard',9950);
$here=vp3_participant_presence_chat_v2($pdo,$u,$p,10000);ck(str_contains($here['answer'],'David')&&str_contains($here['answer'],'Alex')&&str_contains($here['answer'],'seen')&&str_contains($here['answer'],'heard'),'room lookup separates recent sight and nighttime speech with timestamps');
$stale=vp3_participant_presence_chat_v2($pdo,$u,$p,11000);ck($stale['tool_receipt']['status']==='no_matches','room lookup does not call stale observations currently present');
$pdo->exec("INSERT INTO studio_participants VALUES(11,1,'Third',1,1,0,NULL,'guest'),(12,1,'Fourth',1,1,0,NULL,'guest'),(13,1,'Fifth',1,1,0,NULL,'guest')");foreach([11,12,13] as $id)vp3_participant_observed_v1($pdo,$u,$id,'heard',9990+$id-11);
$here=vp3_participant_presence_chat_v2($pdo,$u,$p,10000);ck($here['tool_receipt']['count']===4,'room lookup is bounded to four recent participants');
$pdo->exec('UPDATE studio_participants SET recognition_consent=0 WHERE id=13');$here=vp3_participant_presence_chat_v2($pdo,$u,$p,10000);ck(!str_contains($here['answer'],'Fifth'),'recognition consent revocation removes that participant from room lookup');
foreach(['{"operation":"link_self","participant_id":7}','{"operation":"presence","id":1}','{"operation":"overview","grant":"edit"}'] as $json)rejects(fn()=>vp3_participant_plan_v1($json),'guest read/link plan cannot inject another identity, selector or permission');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');rejects(fn()=>proposal('link_self'),'deactivated owner cannot prepare an access proposal');rejects(fn()=>vp3_participant_presence_chat_v2($pdo,$u,$p),'deactivated owner cannot read room observations');
echo "PARTICIPANT_CONVERSATION_V2=PASS ($checks cases; canonical configuration, explicit owner approval, saved Chat receipts, scoped open work and recent observations)\n";
