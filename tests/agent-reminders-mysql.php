<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');if(!$dsn)throw new RuntimeException('Explicit reminder fixture DSN required.');
$pdo=new PDO($dsn,'root',(string)getenv('VP3_SECTION7_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name=(string)getenv('VP3_REMINDER_FIXTURE_DATABASE');$child=($argv[1]??'')!=='';
if(!$child){$name='reminders_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE `'.$name.'`');putenv('VP3_REMINDER_FIXTURE_DATABASE='.$name);}
if(!preg_match('/^reminders_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Invalid reminder fixture.');
$pdo->exec('USE `'.$name.'`');
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(bool)$q->fetchColumn();}
function url(string $path): string{return $path;}
function check(bool $ok,string $why): void{if(!$ok)throw new RuntimeException($why);}
$now=new DateTimeImmutable('2026-10-30T15:00:00Z');
if($child){
    try{if($argv[1]==='tick')echo reminder_tick_v1($pdo,1,$now);elseif($argv[1]==='claim')echo reminder_present_v1($pdo,1,['action'=>'claim'],$now)['item']?'1':'0';else throw new RuntimeException('Invalid worker.');}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}exit;
}
function parallel(string $mode): array{
    $workers=[];for($i=0;$i<4;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$process,$pipes];}
    $out=[];foreach($workers as [$process,$pipes]){$value=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$error==='','Concurrent reminder worker failed: '.$error);$out[]=(int)$value;}return $out;
}
try{
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL) ENGINE=InnoDB;INSERT INTO users VALUES(1,1);CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT,type VARCHAR(50),title VARCHAR(190),body VARCHAR(500),target_url VARCHAR(500),source_type VARCHAR(80),source_id BIGINT,is_read INT DEFAULT 0,created_at DATETIME) ENGINE=InnoDB;CREATE TABLE chat_conversations(id INT PRIMARY KEY,user_id INT,updated_at DATETIME) ENGINE=InnoDB;INSERT INTO chat_conversations VALUES(1,1,NOW());CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id INT,user_id INT NULL,role VARCHAR(20),message TEXT,context_json TEXT) ENGINE=InnoDB");
    reminder_schema_v1($pdo);reminder_schema_v1($pdo);check(reminder_ready_v1($pdo),'Schema ready after idempotent MySQL upgrade.');
    $schedule=['title'=>'Yoga','timezone'=>'America/Phoenix','start_local'=>'2026-10-30T08:00','recurrence'=>'daily','delivery'=>'both'];
    reminder_execute_v1($pdo,1,'cloud:1','request-one','Remind me daily',['operation'=>'create','schedule'=>$schedule],[],1,$now->modify('-1 hour'));
    check(array_sum(parallel('tick'))===1,'Four schedulers must deliver exactly one occurrence.');
    check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===1,'One durable Chat/notification delivery.');
    check(array_sum(parallel('claim'))===1,'Four visible clients must obtain exactly one presentation lease.');
    $rows=reminder_rows_v1($pdo,1);reminder_execute_v1($pdo,1,'cloud:1','request-done','Done',['operation'=>'complete','id'=>1],$rows,1,$now);
    check(reminder_rows_v1($pdo,1)[0]['next_at']==='2026-10-31T15:00:00Z','Done retains tomorrow.');
    $pdo->exec('DELETE FROM users WHERE id=1');foreach(['agent_reminders_v1','agent_reminder_occurrences_v1','agent_reminder_context_v1','agent_reminder_turns_v1'] as $table)check((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'Owner cleanup failed.');
    echo "AGENT_REMINDERS_MYSQL=PASS (real schema/idempotency, four parallel schedulers, four competing delivery clients, recurrence and owner cleanup)\n";
}finally{$pdo->exec('DROP DATABASE `'.$name.'`');}
