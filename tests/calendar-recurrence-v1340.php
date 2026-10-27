<?php
declare(strict_types=1);

// Real SQLite persistence and production services; only unrelated infrastructure
// is stubbed. The CREATE shim tests ALTER upgrades against an installed schema.
$pdo=new class('sqlite::memory:') extends PDO {
    public function exec(string $sql): int|false {
        if(str_contains($sql,'CREATE TABLE IF NOT EXISTS user_calendar_events'))return 0;
        return parent::exec($sql);
    }
};
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return(bool)$s->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function agent_scheduling_schema_ready_v430(PDO $pdo):bool{return false;}
function agent_scheduling_timezone_v430(string $tz):string{return $tz;}
function agent_tool_log(...$args):void{}
function url(string $path):string{return $path;}
function video_meeting_schema_ready_v1800(PDO $pdo):bool{return false;}
function homeserver_federated_v240_envelope(string $authority,string $dataset,string $key,string $title,string $content,string $updated):array{return ['canonical_id'=>'fixture-'.$key,'record_revision'=>hash('sha256',$key.$content.$updated)];}
function homeserver_federated_v240_observe(...$args):void{}
require dirname(__DIR__).'/includes/user-calendar-v1300.php';
require dirname(__DIR__).'/includes/user-calendar-agent-v1300.php';
require dirname(__DIR__).'/includes/agent-scheduling-tools-v460.php';
require dirname(__DIR__).'/includes/calendar-schedule-awareness-v1330.php';
require dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
require dirname(__DIR__).'/includes/cognitive-cards-v520.php';
require dirname(__DIR__).'/includes/agent-workflow-runs-v1400.php';
require dirname(__DIR__).'/includes/agent-calendar-sync-v500.php';
require dirname(__DIR__).'/includes/homeserver-tasks-calendar-v243.php';
$checks=0;
function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function denied(callable $call,string $message):void{try{$call();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
$pdo->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$user=['id'=>1];
$input=['title'=>'Weekly review','date'=>'2026-10-26','start_time'=>'09:00','end_time'=>'10:00','timezone'=>'America/New_York','recurrence'=>'weekly','agent_notifications'=>true];
denied(fn()=>user_calendar_create_local_event_v1300($pdo,$user,$input),'New options require an upgrade instead of disappearing silently');
check((int)$pdo->query('SELECT count(*) FROM user_calendar_events')->fetchColumn()===0,'Failed create is atomic');
user_calendar_ensure_schema_v1300($pdo);user_calendar_ensure_schema_v1300($pdo);
check(user_calendar_recurrence_schema_ready_v1340($pdo),'Upgrade is idempotent and adds both columns');
$event=user_calendar_create_local_event_v1300($pdo,$user,$input,'user',null,'retry-event-001');
$id=(int)$event['id'];
check($event['recurrence']==='weekly'&&(int)$event['agent_notifications']===1,'Both options persist');
$pdo->exec('CREATE TABLE agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER)');$pdo->exec('INSERT INTO agent_scheduling_schedules VALUES(1,1),(2,2)');
check((agent_calendar_sync_conflict_v500($pdo,1,'2026-11-02 14:30:00','2026-11-02 14:45:00')['id']??0)===$id,'Future weekly occurrences block conflicting bookings');
check(agent_calendar_sync_conflict_v500($pdo,2,'2026-11-02 14:30:00','2026-11-02 14:45:00')===null,'Weekly busy times do not leak between owners');
check(user_calendar_create_local_event_v1300($pdo,$user,$input,'user',null,'retry-event-001')['id']===$event['id'],'Create retry keeps the canonical series');
$events=user_calendar_events_v1300($pdo,$user,'2026-10-26 00:00:00','2026-11-10 00:00:00');
check(count($events)===3,'The month contains each weekly occurrence');
check(array_column($events,'start_at_utc')===['2026-10-26 13:00:00','2026-11-02 14:00:00','2026-11-09 14:00:00'],'Local time survives fall DST');
check(count(array_unique(array_column($events,'occurrence_id')))===3&&count(array_unique(array_column($events,'id')))===1,'Unique occurrences share one editable series');
$card=vp3_cognitive_cards_object_v520($pdo,$user,'personal',['type'=>'calendar_booking','id'=>$events[1]['occurrence_id']]);
check($card['row']['start_at_utc']===$events[1]['start_at_utc'],'Cognitive card resolves the requested week rather than the old anchor');
check(vp3_cognitive_cards_object_v520($pdo,['id'=>2],'personal',['type'=>'calendar_booking','id'=>$events[1]['occurrence_id']])===null,'Occurrence cards retain owner authorization');
check(vp3_cognitive_cards_object_v520($pdo,$user,'personal',['type'=>'calendar_booking','id'=>'event:'.$id.'@20261103T140000Z'])===null,'A fabricated occurrence cannot produce a card');
check(user_calendar_events_v1300($pdo,['id'=>2],'2026-10-26 00:00:00','2026-11-10 00:00:00')===[],'Another owner cannot read the series');
check(count(user_calendar_events_v1300($pdo,$user,'2030-10-01 00:00:00','2030-11-01 00:00:00'))>=4,'An old anchor appears years later without materialization');
$federated=homeserver_task_calendar_v243_cloud_calendar_records(1,'2026-10-01 00:00:00','2027-11-07 00:00:00');
check(count($federated)>52,'The existing 401-day continuity range remains supported');
check(count(array_unique(array_column($federated,'occurrence_id')))===count($federated),'Continuity chunks do not duplicate a weekly occurrence');
check(count(array_unique(array_column($federated,'record_revision')))===1,'Occurrence projections preserve the canonical series revision');
check(homeserver_task_calendar_v243_cloud_calendar_records(2,'2026-10-01 00:00:00','2027-11-07 00:00:00')===[],'Federated recurrence reads retain owner isolation');
check(count(user_calendar_events_v1300($pdo,$user,'2026-10-26 13:30:00','2026-10-26 13:31:00'))===1,'Overlap ranges include an in-progress occurrence');
check(user_calendar_events_v1300($pdo,$user,'2026-10-26 14:00:00','2026-10-26 14:01:00')===[],'Event end is exclusive');
$gap=$event;$gap['start_at_utc']='2026-03-01 07:30:00';$gap['end_at_utc']='2026-03-01 08:00:00';
$spring=user_calendar_occurrences_v1340($gap,'2026-03-01 00:00:00','2026-03-17 00:00:00');
check(array_column($spring,'start_at_utc')===['2026-03-01 07:30:00','2026-03-15 06:30:00'],'Nonexistent spring wall time skips one week, without drifting later');
$allDay=$input+[];$allDay['title']='All-day weekly';$allDay['date']='2026-03-01';$allDay['end_date']='2026-03-01';$allDay['all_day']=true;
$day=user_calendar_create_local_event_v1300($pdo,$user,$allDay);
$days=user_calendar_occurrences_v1340($day,'2026-03-08 00:00:00','2026-03-09 12:00:00');
check(count($days)===1&&(strtotime($days[0]['end_at_utc'].' UTC')-strtotime($days[0]['start_at_utc'].' UTC'))===23*3600,'All-day occurrence respects the 23-hour spring day');
$snapshot=calendar_schedule_awareness_snapshot_v1330($pdo,$user,new DateTimeImmutable('2026-11-02T13:45:00Z'));
$priorities=calendar_schedule_awareness_candidates_v1330($snapshot);
check(count($snapshot['priority_events'])>=1&&$priorities[0]['source']==='calendar_priority','Opted-in events reach canonical Brain priorities');
check(agent_workflow_type_v1400($priorities[0])==='calendar_commitment_prep','Priority calendar events use the existing commitment-preparation workflow');
check($snapshot['priority_events'][0]['ref']!==$events[0]['occurrence_id'],'Each week has a distinct notification/cooldown identity');
$oldRevision=section12_revision($event);
$updated=user_calendar_update_event_v1300($pdo,$user,$id,array_merge($input,['title'=>'Changed title','expected_revision'=>$oldRevision]));
check($updated['recurrence']==='weekly'&&(int)$updated['agent_notifications']===1,'Editing other fields preserves recurrence and priority');
denied(fn()=>user_calendar_update_event_v1300($pdo,$user,$id,array_merge($input,['expected_revision'=>$oldRevision])),'Stale edit cannot overwrite series changes');
denied(fn()=>user_calendar_update_event_v1300($pdo,['id'=>2],$id,$input),'Another owner cannot edit');
denied(fn()=>user_calendar_update_event_v1300($pdo,$user,$id,array_merge($input,['recurrence'=>'daily'])),'Unsupported repeat rules are rejected');
denied(fn()=>user_calendar_update_event_v1300($pdo,$user,$id,array_merge($input,['agent_notifications'=>'yes'])),'Notification flag is validated');
$off=user_calendar_update_event_v1300($pdo,$user,$id,array_merge($input,['recurrence'=>'none','agent_notifications'=>false]));
check($off['recurrence']==='none'&&!(bool)$off['agent_notifications'],'Both options can be disabled');
check(array_values(array_filter(user_calendar_events_v1300($pdo,$user,'2026-11-01 00:00:00','2026-11-10 00:00:00'),static fn(array $row):bool=>$row['id']===$id))===[],'Stopping recurrence removes future projections');
// A newer booking draft must own its confirmation, never an older calendar edit.
user_calendar_agent_set_pending_v1300($user,11,['operation'=>'cancel','event_id'=>$id,'expected_revision'=>section12_revision($off)]);
$_SESSION[agent_scheduling_tools_pending_key_v460(1,11)]=['user_id'=>1,'conversation_id'=>11,'expires_at'=>time()+900];
$handoff=user_calendar_agent_query_v1300('yes',$user,11);
check(empty($handoff['handled'])&&user_calendar_event_v1300($pdo,1,$id)['status']==='active','A newer booking confirmation cannot cancel an old calendar event');
user_calendar_agent_set_pending_v1300($user,11,['title'=>'Fresh draft']);
check(agent_scheduling_tools_pending_v460($user,11)===null,'A new calendar draft retires older booking confirmations');
// Exercise the real conversational create/confirm/update/cancel entry point.
$_SESSION=[];
$prepared=user_calendar_agent_query_v1300('add "Chat review" to my calendar 2026-11-02 at 9 AM every week include agent notifications',$user,11);
check(str_contains($prepared['answer'],'Repeats every week')&&str_contains($prepared['answer'],'Confirm?'),'Chat prepares the requested recurrence and flag');
$originalPending=user_calendar_agent_pending_v1300($user,11);
$confirmed=user_calendar_agent_query_v1300('yes',$user,11);
check(str_contains($confirmed['answer'],'Added'),'Chat confirmation creates the event');
$chat=$pdo->query("SELECT * FROM user_calendar_events WHERE title='Chat review'")->fetch();
check($chat['recurrence']==='weekly'&&(int)$chat['agent_notifications']===1,'Chat writes options through the same canonical service');
user_calendar_agent_set_pending_v1300($user,11,$originalPending);
user_calendar_agent_query_v1300('yes',$user,11);
check((int)$pdo->query("SELECT count(*) FROM user_calendar_events WHERE title='Chat review'")->fetchColumn()===1,'A lost create acknowledgement reuses the durable proposal identity');
$chatId=(int)$chat['id'];
$prepare=user_calendar_agent_query_v1300('move calendar event #'.$chatId.' to 2026-11-03 at 10 AM disable agent notifications',$user,11);
check(str_contains($prepare['answer'],'Confirm?'),'Chat prepares a series update');
check(user_calendar_event_v1300($pdo,1,$chatId)['start_at_utc']===$chat['start_at_utc'],'Preparing never changes the event');
user_calendar_agent_query_v1300('yes',$user,11);$chat=user_calendar_event_v1300($pdo,1,$chatId);
check($chat['start_at_utc']==='2026-11-03 10:00:00'&&!(bool)$chat['agent_notifications'],'Confirmed Chat change persists');
user_calendar_agent_query_v1300('cancel calendar event #'.$chatId,$user,11);
user_calendar_agent_query_v1300('update calendar event #'.$chatId,$user,11);
check(user_calendar_agent_pending_v1300($user,11)===null,'An incomplete new change retires the older cancellation proposal');
$unrelatedYes=user_calendar_agent_query_v1300('yes',$user,11);
check(empty($unrelatedYes['handled'])&&user_calendar_event_v1300($pdo,1,$chatId)['status']==='active','A clarification response cannot confirm a superseded cancellation');
$other=user_calendar_agent_query_v1300('cancel calendar event #'.$chatId,['id'=>2],11);
check(str_contains($other['answer'],'could not find'),'Chat cannot select another owner’s event');
user_calendar_agent_query_v1300('cancel calendar event #'.$chatId,$user,11);
$pdo->prepare('UPDATE user_calendar_events SET title=? WHERE id=?')->execute(['Changed elsewhere',$chatId]);
$stale=user_calendar_agent_query_v1300('yes',$user,11);
check(str_contains($stale['answer'],'Reload')&&user_calendar_event_v1300($pdo,1,$chatId)['status']==='active','Chat cancellation rechecks the prepared revision');
user_calendar_agent_query_v1300('cancel calendar event #'.$chatId,$user,11);user_calendar_agent_query_v1300('yes',$user,11);
check(user_calendar_event_v1300($pdo,1,$chatId)['status']==='cancelled','Confirmed cancellation retires the entire series');
check(count($pdo->query('SELECT * FROM user_calendar_events')->fetchAll())===3,'Occurrences and Chat retries never create duplicate series');
echo 'CALENDAR_RECURRENCE_V1340=PASS ('.$checks.' assertions; real SQLite and production services)'.PHP_EOL;
