<?php
declare(strict_types=1);

// Real canonical schedule validators, mutators, ledgers and SQLite transactions.
// No Agent identity, approval or Initiative authority is replaced by this test.
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
require dirname(__DIR__).'/includes/user-calendar-v1300.php';
require dirname(__DIR__).'/includes/agent-initiative-schedule-actions-v1.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function agent_scheduling_schema_ready_v430(PDO $pdo): bool{return false;}
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER);INSERT INTO user_agents VALUES(11,1,1),(21,2,1);
    CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,source_type TEXT,source_id INTEGER,is_read INTEGER);
    CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,
      created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,
      recurrence TEXT DEFAULT 'none',agent_notifications INTEGER DEFAULT 0,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
reminder_schema_v1($pdo);
$checks=0;
function schedule_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function schedule_reject(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){schedule_check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$now=new DateTimeImmutable('2026-11-06T12:00:00Z');
$schedule=['title'=>'Check the release','timezone'=>'America/New_York','start_local'=>'2026-11-06T09:30:00','recurrence'=>'daily','delivery'=>'both'];
$domain=['operation'=>'create','schedule'=>$schedule];
schedule_reject(fn()=>reminder_execute_locked_v1($pdo,1,'initiative:11:10','action_12345678','payload',$domain,[],10,$now),'locked reminder kernel requires the broker transaction');
$pdo->beginTransaction();
$created=reminder_execute_locked_v1($pdo,1,'initiative:11:10','action_12345678','payload',$domain,[],10,$now);
schedule_check($pdo->inTransaction()&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===1,'locked reminder kernel saves without committing the caller');
$again=reminder_execute_locked_v1($pdo,1,'initiative:11:10','action_12345678','payload',$domain,[],10,$now);
schedule_check($again===$created&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===1,'stable canonical reminder receipt prevents duplicate effects');
schedule_reject(fn()=>reminder_execute_locked_v1($pdo,1,'initiative:11:10','action_12345678','different payload',$domain,[],10,$now),'stable reminder identity cannot be rebound to another reviewed payload');
schedule_check($pdo->inTransaction(),'locked kernel failure preserves caller transaction ownership');
$pdo->rollBack();
schedule_check((int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminder_turns_v1')->fetchColumn()===0,'broker rollback atomically removes reminder and domain receipt');

$saved=reminder_execute_v1($pdo,1,'ordinary:11:10','ordinary_12345678','Explicit existing reminder request',$domain,[],10,$now);
schedule_check(!$pdo->inTransaction()&&$saved['tool_receipt']['changes_applied'],'ordinary reminder executor retains its standalone committed behavior');
$id=$saved['tool_receipt']['record_id'];$snapshot=reminder_rows_v1($pdo,1);
$pdo->beginTransaction();
schedule_reject(fn()=>reminder_execute_locked_v1($pdo,2,'initiative:21:20','other_12345678','other payload',['operation'=>'cancel','id'=>$id],$snapshot,20,$now),'canonical locked reminder mutation rejects another owner');
$pdo->rollBack();
foreach([['timezone'=>'Invalid/Zone'],['start_local'=>'2027-03-14T02:30:00'],['start_local'=>'2026-02-30T09:30:00'],['delivery'=>'sms'],['owner_user_id'=>2]] as $patch)
    schedule_reject(fn()=>reminder_schedule_v1(array_merge($schedule,$patch),$now),'invalid or privileged reminder schedule field is rejected');

$event=['title'=>'Release review','description'=>'Discuss the saved release.','location'=>'Office','timezone'=>'America/New_York',
    'date'=>'2026-11-07','start_time'=>'09:00','end_time'=>'10:00','all_day'=>false,'recurrence'=>'weekly','agent_notifications'=>true];
$normalized=vp3_initiative_schedule_action_event_v1($pdo,$event);
schedule_check($normalized===vp3_initiative_schedule_action_event_v1($pdo,$normalized),'reviewed calendar arguments normalize stably');
$range=user_calendar_local_range_v1300($normalized,$normalized['timezone']);
schedule_check($range['start_at_utc']==='2026-11-07 14:00:00'&&$range['end_at_utc']==='2026-11-07 15:00:00','canonical local calendar range retains explicit timezone');
foreach([['timezone'=>'Invalid/Zone'],['date'=>'2027-03-14','start_time'=>'02:30','end_time'=>'03:30'],['end_time'=>null],['created_by_agent_id'=>21],['all_day'=>'yes'],['recurrence'=>'daily']] as $patch)
    schedule_reject(fn()=>vp3_initiative_schedule_action_event_v1($pdo,array_merge($event,$patch)),'calendar review rejects guessed, invalid or privileged fields');
schedule_reject(fn()=>vp3_initiative_schedule_action_event_v1($pdo,['title'=>'Missing timezone','date'=>'2026-11-07','start_time'=>'09:00','end_time'=>'10:00']),'calendar action requires an explicit valid timezone');
schedule_reject(fn()=>vp3_initiative_schedule_action_fields_v1(['yes'],['target_ref']),'positional model arguments are not a typed action');
$day=vp3_initiative_schedule_action_event_v1($pdo,['title'=>'Day off','timezone'=>'America/New_York','date'=>'2027-03-14','all_day'=>true]);
$dayRange=user_calendar_local_range_v1300($day,$day['timezone']);
schedule_check(strtotime($dayRange['end_at_utc'].' UTC')-strtotime($dayRange['start_at_utc'].' UTC')===23*3600,'all-day calendar review preserves the DST-short day');

$pdo->beginTransaction();
$calendar=user_calendar_create_event_locked_v1300($pdo,['id'=>1],array_merge($normalized,$range),'agent',11,'initiative:kernel-test');
$duplicate=user_calendar_create_event_locked_v1300($pdo,['id'=>1],array_merge($normalized,$range),'agent',11,'initiative:kernel-test');
schedule_check($pdo->inTransaction()&&$calendar['id']===$duplicate['id'],'canonical calendar source reference is idempotent without a nested commit');
$calendarId=(int)$calendar['id'];$revision=section12_revision($calendar);
schedule_reject(fn()=>user_calendar_update_event_locked_v1300($pdo,['id'=>2],$calendarId,$normalized),'canonical personal-calendar kernel rejects another owner');
$updated=user_calendar_update_event_locked_v1300($pdo,['id'=>1],$calendarId,array_merge($normalized,['title'=>'Reviewed change','expected_revision'=>$revision]));
schedule_reject(fn()=>user_calendar_update_event_locked_v1300($pdo,['id'=>1],$calendarId,array_merge($normalized,['expected_revision'=>$revision])),'canonical calendar revision rejects stale parallel edits');
schedule_check(user_calendar_cancel_event_locked_v1300($pdo,['id'=>1],$calendarId,section12_revision($updated)),'canonical cancellation saves the reviewed current series');
$pdo->rollBack();
schedule_check((int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===0,'caller rollback removes all calendar effects');

vp3_initiative_schedule_action_personal_event_v1($pdo,123);
$pdo->exec('CREATE TABLE video_meetings(id INTEGER PRIMARY KEY)');
schedule_reject(fn()=>vp3_initiative_schedule_action_personal_event_v1($pdo,123),'partial meeting linkage schema fails closed');
$pdo->exec('ALTER TABLE video_meetings ADD COLUMN calendar_event_id INTEGER;INSERT INTO video_meetings VALUES(1,123)');
schedule_reject(fn()=>vp3_initiative_schedule_action_personal_event_v1($pdo,123),'personal action cannot bypass linked video-meeting lifecycle');
vp3_initiative_schedule_action_personal_event_v1($pdo,124);
schedule_check(true,'unlinked personal events remain eligible');
echo 'AGENT_INITIATIVE_SCHEDULE_KERNELS_V1=PASS ('.$checks.' checks)'.PHP_EOL;
