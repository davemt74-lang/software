<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function has_permission($p,$u){return true;}function url($p){return $p;}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,source_type TEXT,source_id INTEGER,is_read INTEGER);CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT)');reminder_schema_v1($pdo);
$checks=0;function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$now=new DateTimeImmutable('2026-10-31T06:50:00Z');$zone='America/Phoenix';
function save($owner,$title,$at,$when){return reminder_execute_v1(db(),$owner,'cloud:'.$owner,'create-'.hash('crc32b',$title),$title,['operation'=>'create','schedule'=>['title'=>$title,'timezone'=>'America/Phoenix','start_local'=>$at,'recurrence'=>'none','delivery'=>'chat']],[],0,$when);}
save(1,'Today before midnight','2026-10-30T23:55:00',$now);save(1,'Tomorrow after midnight','2026-10-31T00:05:00',$now);save(2,'SECRET foreign reminder','2026-10-30T23:56:00',$now);
save(1,'Overdue today','2026-10-30T22:00:00',$now->modify('-3 hours'));save(1,'Done today','2026-10-30T23:00:00',$now->modify('-3 hours'));reminder_tick_v1($pdo,1,$now);
$rows=reminder_rows_v1($pdo,1);$done=(int)array_values(array_filter($rows,fn($r)=>$r['title']==='Done today'))[0]['id'];reminder_execute_v1($pdo,1,'cloud:1','complete-today','Done',['operation'=>'complete','id'=>$done],$rows,0,$now);
$today=reminder_list_rows_v1($pdo,1,'today',$zone,$now);$titles=array_column($today,'title');
check(in_array('Today before midnight',$titles,true)&&!in_array('Tomorrow after midnight',$titles,true),'today uses Phoenix midnight rather than the UTC date');
check(in_array('Done today',$titles,true)&&!in_array('Done today',array_column(reminder_list_rows_v1($pdo,1,'open',$zone,$now),'title'),true),'today includes completed occurrences while open excludes them');
check(!str_contains(json_encode($today),'SECRET'),'list filters never expose another owner reminder');
$overdue=reminder_list_rows_v1($pdo,1,'overdue',$zone,$now);check(array_column($overdue,'title')===['Overdue today'],'overdue lists pending past occurrences only');
check(str_contains(implode("\n",array_map('reminder_list_label_v1',$today)),'done'),'today rendering distinguishes completion state');
$before=(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn();$attempts=0;
$model=function($prompt)use(&$attempts){$attempts++;return ['ok'=>true,'answer'=>$attempts===1?'{"operation":"list","reminders":[]}':'{"operation":"list","view":"today"}'];};
$r=reminder_chat_v1($pdo,['id'=>1],'What was planned for today on my reminders?','cloud:1','repair-read-one',0,$zone,$model);
check($attempts===2&&$r['tool_receipt']['status']==='read_complete'&&str_contains($r['answer'],'for today'),'unexpected model field is repaired once into a real read');
check((int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===$before&&!$r['tool_receipt']['changes_applied'],'list interpretation/recovery never creates a schedule');
$attempts=0;$r=reminder_chat_v1($pdo,['id'=>1],'Check my open reminders','cloud:1','repair-read-fail',0,$zone,function($p)use(&$attempts){$attempts++;return ['ok'=>true,'answer'=>'{"operation":"list","schedule":{}}'];});
check($attempts===2&&$r['tool_receipt']['reason']==='invalid_plan'&&!str_contains($r['answer'],'Invalid reminder plan.'),'persistent bad output is bounded and returns a human error');
$attempts=0;$r=reminder_chat_v1($pdo,['id'=>1],'Check my reminders','cloud:1','repair-provider-fail',0,$zone,function($p)use(&$attempts){$attempts++;return ['ok'=>false];});check($attempts===1&&$r['tool_receipt']['reason']==='provider_unavailable','provider failure is distinguished from malformed output and is not replayed');
echo "REMINDER_READ_REPAIR=PASS ($checks cases; real SQLite, fixture interpretation)\n";
