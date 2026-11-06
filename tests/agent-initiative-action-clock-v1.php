<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-initiative-actions-v1.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function table_exists(string $table): bool{$q=$GLOBALS['pdo']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
$checks=0;
function clock_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
$clock=vp3_initiative_action_clock_v1($pdo,['id'=>1]);
clock_check($clock['owner_timezone']===null&&$clock['timezone_status']==='not_configured','server UTC does not become an invented owner timezone');
$pdo->exec('CREATE TABLE agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER,timezone TEXT,is_default INTEGER,is_active INTEGER)');
$pdo->exec("INSERT INTO agent_scheduling_schedules VALUES(1,2,'Asia/Tokyo',1,1),(2,1,'America/Phoenix',0,1),(3,1,'Europe/London',1,0)");
clock_check(vp3_initiative_action_clock_v1($pdo,['id'=>1])['owner_timezone']===null,'another owner, nondefault or inactive schedule cannot establish local time');
$pdo->exec("INSERT INTO agent_scheduling_schedules VALUES(4,1,'America/Phoenix',1,1)");
$clock=vp3_initiative_action_clock_v1($pdo,['id'=>1]);
clock_check($clock['owner_timezone']==='America/Phoenix'&&$clock['timezone_status']==='configured_default_schedule'&&str_ends_with($clock['current_local_time'],'-07:00'),'actual owner configured default supplies a validated local clock');
clock_check(vp3_initiative_action_clock_v1($pdo,['id'=>2])['owner_timezone']==='Asia/Tokyo','configured clocks stay isolated by exact account');
$pdo->exec("UPDATE agent_scheduling_schedules SET timezone='invented/zone' WHERE id=4");
$clock=vp3_initiative_action_clock_v1($pdo,['id'=>1]);
clock_check($clock['owner_timezone']===null&&$clock['timezone_status']==='unavailable','invalid configured zone cannot silently authorize UTC');
$pdo->exec('ALTER TABLE agent_scheduling_schedules RENAME COLUMN timezone TO unavailable_timezone');
$clock=vp3_initiative_action_clock_v1($pdo,['id'=>1]);
clock_check($clock['owner_timezone']===null&&$clock['timezone_status']==='unavailable','unreadable timezone cannot silently authorize UTC');
echo 'INITIATIVE_ACTION_CLOCK=PASS ('.$checks.' checks)'.PHP_EOL;
