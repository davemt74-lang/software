<?php
declare(strict_types=1);
// Actual proactive candidate builders over SQLite; adapt only MySQL date syntax.
class FixturePDO extends PDO {public function prepare(string $sql,array $options=[]): PDOStatement|false {$sql=preg_replace("/DATE_SUB\\(NOW\\(\\),INTERVAL (\\d+) DAY\\)/","datetime('now','-$1 days')",$sql);return parent::prepare($sql,$options);}}
$pdo=new FixturePDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE agent_tool_history(id INTEGER PRIMARY KEY,user_id INTEGER,tool_key TEXT,status TEXT,created_at TEXT);CREATE TABLE agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER)');
for($i=1;$i<=100;$i++){$pdo->exec("INSERT INTO agent_chat_archive VALUES($i,1)");$pdo->exec("INSERT INTO agent_tool_history VALUES($i,1,'broken_tool','error',CURRENT_TIMESTAMP)");}
for($i=101;$i<=103;$i++)$pdo->exec("INSERT INTO agent_tool_history VALUES($i,1,'useful_tool','success',CURRENT_TIMESTAMP)");
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function url($p){return $p;}function has_permission($p,...$a){return $p==='chat.access';}function agent_brain_normalize($s){return strtolower(trim($s));}function agent_memory_v123_tasks(...$a){return [];}function agent_memory_v123_overlap(...$a){return 0;}
$activity=['surface'=>'chat','state'=>'idle','task_title'=>'Agent Chat · Do I have any events on my calendar Hmm do I have any events on my calendar','context_key'=>'chat:42'];function agent_activity_v94_snapshot(...$a){return $GLOBALS['activity'];}
require dirname(__DIR__).'/includes/agent-proactive-v93.php';require dirname(__DIR__).'/includes/agent-proactive-v123.php';require dirname(__DIR__).'/includes/agent-ecosystem-v118.php';
$checks=0;function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
foreach(['idle','paused','working'] as $state){$activity['state']=$state;$old=agent_proactive_v93_suggestions(['id'=>1],'chat',[]);$new=agent_proactive_v123_evidence_candidates(['id'=>1],'chat',[],$activity);check(!str_contains(json_encode([$old['suggestions'],$new]),'calendar Hmm'),'raw Chat question does not become a task while '.$state);}
$activity['state']='idle';$old=agent_proactive_v93_suggestions(['id'=>1],'chat',[]);$new=agent_proactive_v123_evidence_candidates(['id'=>1],'chat',[],$activity);
check(!str_contains(json_encode([$old['suggestions'],$new]),'broken_tool'),'failed tool attempts never establish a useful behavior pattern');
check(count(array_filter($new,fn($c)=>$c['source']==='tool_pattern'&&str_contains($c['title'],'useful tool')))===1,'successful tool pattern appears once across legacy ecosystem and current candidate sources');
$activity=['surface'=>'stem','state'=>'paused','task_title'=>'Stem Studio · Real song','context_key'=>'stem:7'];$new=agent_proactive_v123_evidence_candidates(['id'=>1],'stem',[],$activity);$resumes=array_values(array_filter($new,fn($c)=>$c['source']==='activity_resume'));check(count($resumes)===1&&str_contains($resumes[0]['title'],'Real song'),'paused editor work retains one real resume suggestion');
require dirname(__DIR__).'/includes/agent-chat-activity.php';
$pdo->exec("CREATE TABLE agent_proactive_events(id INTEGER PRIMARY KEY,user_id INTEGER,event_type TEXT,title TEXT,prompt TEXT,source_kind TEXT,context_json TEXT,created_at TEXT);CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,source_type TEXT,source_id INTEGER)");
$pdo->exec("INSERT INTO agent_proactive_events VALUES(1,1,'shown','Resume Agent Chat question','Resume question','activity_resume','{}',CURRENT_TIMESTAMP),(2,1,'shown','Real release deadline','Review release deadline','release_calendar','{}',CURRENT_TIMESTAMP)");
function create_notification($uid,$type,$title,$body,$url,$source,$id,...$a){$q=db()->prepare('INSERT INTO notifications(user_id,source_type,source_id)VALUES(?,?,?)');$q->execute([$uid,$source,$id]);}
agent_chat_activity_reconcile_proactive($pdo,['id'=>1]);agent_chat_activity_reconcile_proactive($pdo,['id'=>1]);
check($pdo->query('SELECT source_id FROM notifications')->fetchAll(PDO::FETCH_COLUMN)===[2],'shown resume suggestions never feed back into fresh opportunities; real events promote once');
echo "PROACTIVE_CHAT_REPAIR=PASS ($checks cases; production builders, SQLite)\n";
