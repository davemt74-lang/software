<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $name):bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
require dirname(__DIR__).'/includes/notifications.php';
require dirname(__DIR__).'/includes/browser-trust-v2080.php';
$checks=0;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
$pdo->exec('CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,read_at TEXT)');
$pdo->exec('CREATE TABLE browser_notifications_v2080(id INTEGER PRIMARY KEY,user_id INTEGER,notification_type TEXT,title TEXT,body TEXT,target_url TEXT,is_read INTEGER DEFAULT 0,created_at TEXT,read_at TEXT,dismissed_at TEXT)');
foreach([[1,1,'browser_mentions','browser_phase9',19],[2,1,'browser_replies','browser_phase9',20],[3,2,'browser_mentions','browser_phase9',21],[4,1,'profile_view','profile_event',22],[5,1,'agent_activity_test','browser_phase9',23]] as $row){
 $pdo->prepare('INSERT INTO notifications(id,user_id,type,source_type,source_id) VALUES(?,?,?,?,?)')->execute($row);
 $pdo->prepare("INSERT INTO browser_notifications_v2080(id,user_id,notification_type,title,body,target_url,created_at) VALUES(?,?,'mentions','Fixture','Body','/chat.php',CURRENT_TIMESTAMP)")->execute([$row[4],$row[1]]);
}
$read=static fn(int $id):int=>(int)$pdo->query('SELECT is_read FROM browser_notifications_v2080 WHERE id='.$id)->fetchColumn();
mark_notification_read(1,2);check($read(19)===0,'foreign owner cannot read a canonical mirror');
mark_notification_read(1,1);check($read(19)===1,'canonical single read synchronizes browser inbox');
check(vp3_browser_trust_notifications_v2080($pdo,1)['unread']===3,'browser count uses synchronized read state');
mark_notification_read(1,1);check($read(19)===1,'read retry is idempotent');
mark_all_notifications_read(1);check($read(20)===1&&$read(21)===0&&$read(23)===0,'bulk read respects owner and Brain separation');
$pdo->exec('UPDATE notifications SET is_read=0,read_at=NULL');$pdo->exec('UPDATE browser_notifications_v2080 SET is_read=0,read_at=NULL');
vp3_browser_trust_mark_notification_v2080($pdo,1,19);
check($read(19)===1&&(int)$pdo->query('SELECT is_read FROM notifications WHERE id=1')->fetchColumn()===1,'browser read preserves reverse synchronization');
vp3_browser_trust_dismiss_notification_v2080($pdo,1,20);
check($read(20)===1&&(int)$pdo->query('SELECT is_read FROM notifications WHERE id=2')->fetchColumn()===1,'browser dismiss commits canonical read too');
$pdo->exec('UPDATE notifications SET is_read=0,read_at=NULL');$pdo->exec('UPDATE browser_notifications_v2080 SET is_read=0,read_at=NULL');
$pdo->exec("CREATE TRIGGER fail_mirror BEFORE UPDATE ON browser_notifications_v2080 BEGIN SELECT RAISE(ABORT,'fixture mirror unavailable'); END");
try{mark_notification_read(1,1);throw new LogicException('Expected mirror failure');}catch(PDOException){}
check((int)$pdo->query('SELECT is_read FROM notifications WHERE id=1')->fetchColumn()===0,'mirror failure rolls back canonical read');
$pdo->beginTransaction();$pdo->exec("UPDATE notifications SET type='outer_work' WHERE id=3");
try{mark_all_notifications_read(1);throw new LogicException('Expected mirror failure');}catch(PDOException){}
check($pdo->inTransaction()&&$pdo->query('SELECT type FROM notifications WHERE id=3')->fetchColumn()==='outer_work','failed nested read preserves caller transaction');
check((int)$pdo->query('SELECT is_read FROM notifications WHERE id=1')->fetchColumn()===0,'nested failure rolls back only read savepoint');$pdo->rollBack();
$pdo->exec('DROP TRIGGER fail_mirror');$pdo->beginTransaction();mark_notification_read(1,1);check($read(19)===1,'joined successful read is visible in parent');$pdo->rollBack();
check($read(19)===0&&(int)$pdo->query('SELECT is_read FROM notifications WHERE id=1')->fetchColumn()===0,'parent rollback retains both unread states');
echo 'PASS notification read synchronization: '.$checks.' checks.'.PHP_EOL;
