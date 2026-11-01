<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require dirname(__DIR__).'/includes/agent-brain-v122.php';
require dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
class ListTestPDO extends PDO {
 public bool $rejectWrite=false;
 public function prepare(string $s,array $o=[]):PDOStatement|false {
  if($this->rejectWrite&&str_contains($s,'INSERT INTO agent_task_list_items_v1'))throw new PDOException('Injected failure');
  if(str_contains($s,'information_schema.statistics'))$s="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";
  $s=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$s);$s=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$s);return parent::prepare($s,$o);
 }
}
$pdo=new ListTestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('LAST_INSERT_ID',fn($n)=>$n);
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function column_exists($t,$c){foreach(db()->query('PRAGMA table_info('.$t.')') as $r)if($r['name']===$c)return true;return false;}
function has_permission($p,$u){return !isset($GLOBALS['denied'][$p]);}function personal_capability_has_v242($p,$u){return has_permission($p,$u);}function agent_brain_schema_ready(){return table_exists('agent_memory_items');}function agent_brain_normalize($s){return mb_strtolower(trim($s));}
function knowledge_retrieval_v162_owner_session($u,$p){return ($p['kind']??'')==='system'||(($p['kind']??'')==='user_agent'&&($p['owner_user_id']??0)===$u['id']);}
function user_data_policy_can_use_v236($pdo,$p,$uid,$domain,$id,$public){return !isset($GLOBALS['denied'][$id]);}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(10,1,7),(11,1,8),(12,2,9);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,role TEXT,context_json TEXT);CREATE TABLE agent_chat_archive(id INTEGER,user_agent_id INTEGER);CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT UNIQUE,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT);CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)");
vp3_task_lists_schema_v1($pdo);vp3_task_lists_schema_v1($pdo);
$u=['id'=>1,'is_active'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$ctx=['conversation_id'=>10];$checks=0;
function ck($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
function runList($action,$extra=[]){return vp3_task_list_execute_v1($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],['action'=>$action]+$extra,$GLOBALS['ctx']);}
function saveReply($r,$cid=10){$q=db()->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(?,'assistant',?)");$q->execute([$cid,json_encode($r)]);}
$r=runList('add',['list_name'=>'Prep','title'=>'Pizza dough']);ck($r['tool_receipt']['status']==='clarification_required'&&(int)$pdo->query('SELECT COUNT(*) FROM agent_task_lists_v1')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===0,'missing list proposal creates neither list nor task');saveReply($r);
$pending=vp3_task_list_pending_v1($pdo,$u,$p,$ctx);ck($pending['action']['title']==='Pizza dough','pending intent comes from frozen server draft');
$other=vp3_task_list_pending_v1($pdo,$u,['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],['conversation_id'=>11,'task_context'=>$r['task_context']]);ck($other===null,'browser hint cannot move a draft to another Agent conversation');
$r=runList('confirm');ck($r['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'confirmation creates one canonical Brain task and list');$id=$r['sticky_notes'][0]['id'];saveReply($r);
$r=runList('confirm');ck(!$r['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'consumed confirmation cannot create a second item');
$r=runList('add',['list_name'=>'  PREP  ','title'=>'  pizza   dough ']);ck(!$r['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'case and whitespace variants reuse list and item');
$r=runList('add',['list_name'=>'Prep','title'=>'Sauce']);ck($r['tool_receipt']['changes_applied'],'existing list adds without another creation confirmation');
$n=vp3_task_list_note_v1($pdo,$u,$p,$id);ck(count($n['items'])===2&&$n['items'][0]['title']==='Pizza dough','note rehydrates canonical task titles and statuses');$taskId=$n['items'][0]['id'];
$GLOBALS['denied']['brain:'.$taskId]=true;$n=vp3_task_list_note_v1($pdo,$u,$p,$id);ck(count($n['items'])===1,'per-Agent task denial is enforced in note content');unset($GLOBALS['denied']['brain:'.$taskId]);
$old=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;vp3_agent_memory_scope_set_current_v410(1,7);$done=agent_task_v123_update($u,$taskId,'completed');$n=vp3_task_list_note_v1($pdo,$u,$p,$id);ck($done&&$n['items'][0]['done'],'canonical completion immediately appears in the note');if($old===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$old;
foreach([['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],['kind'=>'system']] as $wrong){try{vp3_task_list_note_v1($pdo,$u,$wrong,$id);throw new LogicException('exposed');}catch(RuntimeException $e){ck($e->getCode()===404,'note never exposes another Agent namespace');}}
$r=runList('add',['list_name'=>'Groceries','title'=>'Cheese']);saveReply($r);$pdo->exec('UPDATE agent_task_list_actions_v1 SET expires_at=0');$r=runList('confirm');ck(!$r['tool_receipt']['changes_applied']&&vp3_task_list_find_v1($pdo,$u,$p,'Groceries')===null,'expired confirmation creates no records');
$r=runList('add',['list_name'=>'Shopping','title'=>'Tomatoes']);saveReply($r);$r=runList('cancel');saveReply($r);ck(vp3_task_list_find_v1($pdo,$u,$p,'Shopping')===null&&runList('confirm')['tool_receipt']['status']==='clarification_required','cancelled intent cannot be revived');
$r=runList('add',['list_name'=>'Unrelated','title'=>'Oil']);saveReply($r);saveReply(['tool_receipt'=>['capability'=>'calendar.read']]);ck(vp3_task_list_pending_v1($pdo,$u,$p,$ctx)===null,'unrelated assistant turn does not leave a bare yes attached to an older list draft');
$r=runList('create_task',['title'=>'Take a shower']);ck($r['tool_receipt']['changes_applied']&&str_contains($r['answer'],'as task #'),'explicit standalone task save persists immediately');$standalone=$r['tool_receipt']['record_id'];
$r=runList('add',['list_name'=>'Prep','task_id'=>$standalone]);ck($r['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===3,'existing saved task is attached by reference without copying');
$before=(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn();$pdo->rejectWrite=true;$r=runList('add',['list_name'=>'Prep','title'=>'Rollback evidence']);$pdo->rejectWrite=false;ck($r['tool_receipt']['status']==='failed'&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===$before,'membership failure rolls back canonical task creation atomically');
$r=runList('create_list',['list_name'=>'Empty']);saveReply($r);$r=runList('confirm');ck($r['tool_receipt']['changes_applied']&&count(vp3_task_list_note_v1($pdo,$u,$p,$r['sticky_notes'][0]['id'])['items'])===0,'explicit empty list creation is confirmed and saved');
$GLOBALS['denied']['agent_brain.access']=true;$r=runList('create_task',['title'=>'Denied']);ck($r['tool_receipt']['status']==='blocked','Brain permission revocation prevents saves');unset($GLOBALS['denied']['agent_brain.access']);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');$r=runList('show',['list_name'=>'Prep']);ck($r['tool_receipt']['status']==='blocked','live account deactivation prevents note discovery');
echo "TASK_LISTS=PASS ($checks cases; actual canonical Brain persistence, confirmations, atomicity and source reads)\n";
