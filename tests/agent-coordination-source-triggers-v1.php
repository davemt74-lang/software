<?php
declare(strict_types=1);

define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-sources-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-records-v1.php';

$phoenix = new DateTimeZone('America/Phoenix');
$local = (new DateTimeImmutable('2026-11-13T05:00:00Z'))->setTimezone($phoenix);
$tomorrow = $local->modify('+1 day');
$task = ['kind'=>'task','closed'=>false,'record'=>['status'=>'open','due_at'=>'2026-11-12']];
canvas_pipeline_check(vp3_coordination_task_trigger_v1($task,[],$local)==='due_soon'
    && vp3_coordination_task_trigger_v1($task,[],$tomorrow)==='overdue',
    'owner-local midnight changes the deadline trigger without changing the saved task');
canvas_pipeline_check(vp3_coordination_task_trigger_v1($task,['owner_attention'=>true,'followup_question'=>'Which date works?'],$tomorrow)==='waiting_owner'
    && vp3_coordination_task_trigger_v1($task,['owner_attention'=>true],$tomorrow)==='overdue',
    'an exact decision question takes precedence while an empty question does not');
$task['record']['assigned_to_someone_else'] = true;
canvas_pipeline_check(vp3_coordination_task_trigger_v1($task,[],$tomorrow)===null,
    'another person’s due task does not request owner attention');
$list = ['kind'=>'task_list','closed'=>false,'record'=>['items'=>[
    ['status'=>'waiting','assigned_to_someone_else'=>false,'due_at'=>''],
    ['status'=>'open','assigned_to_someone_else'=>true,'due_at'=>'2026-11-11'],
]]];
canvas_pipeline_check(vp3_coordination_task_trigger_v1($list,[],$local)==='blocked',
    'a list’s waiting member is a blocker, and another person’s overdue member is excluded');
$list['closed'] = true;
canvas_pipeline_check(vp3_coordination_task_trigger_v1($list,[],$local)===null
    && vp3_coordination_task_trigger_v1($list,['agent_task'=>['state'=>'completed','result'=>'Verified preparation.']],$local)==='completed',
    'completion requires a useful verified result');
$reminder = ['kind'=>'reminder','closed'=>false,'record'=>['next_delivery_at'=>'2026-11-13T04:30:00Z']];
canvas_pipeline_check(vp3_coordination_task_trigger_v1($reminder,[],$local)==='overdue',
    'a scheduled occurrence recognizes the reminder ledger’s ISO timestamp');

$pdo->exec('CREATE TABLE user_calendar_events (id INTEGER PRIMARY KEY,owner_user_id INTEGER,title VARCHAR(190),
    description TEXT,location VARCHAR(190),start_at_utc DATETIME,end_at_utc DATETIME,timezone VARCHAR(80),all_day INTEGER,
    recurrence VARCHAR(16),agent_notifications INTEGER,source VARCHAR(32),source_reference VARCHAR(190),
    status VARCHAR(24),created_by_agent_id INTEGER,updated_at DATETIME,cancelled_at DATETIME)');
$pdo->exec('CREATE TABLE agent_scheduling_schedules (id INTEGER PRIMARY KEY,owner_user_id INTEGER,
    agent_id INTEGER,name VARCHAR(100),is_active INTEGER,is_default INTEGER)');
$pdo->exec('CREATE TABLE agent_scheduling_event_types (id INTEGER PRIMARY KEY,minimum_notice_minutes INTEGER)');
$pdo->exec('CREATE TABLE agent_scheduling_availability (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE agent_scheduling_overrides (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE agent_scheduling_bookings (id INTEGER PRIMARY KEY,owner_user_id INTEGER,
    schedule_id INTEGER,agent_id INTEGER,event_title VARCHAR(190),start_at_utc DATETIME,end_at_utc DATETIME,
    organizer_timezone VARCHAR(80),status VARCHAR(24),lifecycle_status VARCHAR(24),rescheduled_from_id INTEGER,public_token CHAR(64),
    buffer_before_minutes INTEGER,buffer_after_minutes INTEGER,guest_name VARCHAR(190),guest_email VARCHAR(190),
    location_value VARCHAR(190),source VARCHAR(40))');
$pdo->exec("INSERT INTO agent_scheduling_schedules VALUES (81,1,7,'Planning',1,1),(82,1,8,'Other Agent',1,0)");
$event = $pdo->prepare("INSERT INTO user_calendar_events VALUES (?,?,?,'','',?,?,'America/Phoenix',?,'none',0,'user','',?,NULL,?,?)");
$event->execute([901,1,'Venue meeting','2026-11-13 16:00:00','2026-11-13 17:00:00',0,'active','2026-11-12 12:00:00',null]);
$event->execute([902,1,'Staff briefing','2026-11-13 16:30:00','2026-11-13 17:30:00',0,'active','2026-11-12 12:00:00',null]);
$event->execute([903,1,'Entire day note','2026-11-13 07:00:00','2026-11-14 07:00:00',1,'active','2026-11-12 12:00:00',null]);
$event->execute([904,1,'Cancelled session','2026-11-13 18:00:00','2026-11-13 19:00:00',0,'cancelled','2026-11-12 12:00:00','2026-11-12 12:00:00']);
$event->execute([905,2,'Another account','2026-11-13 16:30:00','2026-11-13 17:30:00',0,'active','2026-11-12 12:00:00',null]);
$event->execute([906,1,'Event at the boundary','2026-11-13 17:30:00','2026-11-13 18:00:00',0,'active','2026-11-12 12:00:00',null]);
$booking = $pdo->prepare("INSERT INTO agent_scheduling_bookings VALUES (?,?,?,?,'Venue visit',?,?,'America/Phoenix',?,?,?,
    'PRIVATE_BOOKING_TOKEN',0,0,'Guest','PRIVATE_GUEST_EMAIL','','manual')");
$booking->execute([811,1,81,7,'2026-11-14 18:00:00','2026-11-14 18:30:00','pending',null,null]);
$booking->execute([812,1,81,7,'2026-11-15 18:00:00','2026-11-15 18:30:00','cancelled','cancelled',null]);
$booking->execute([813,1,81,7,'2026-11-16 18:00:00','2026-11-16 18:30:00','confirmed','confirmed',812]);
$booking->execute([814,1,82,8,'2026-11-14 18:00:00','2026-11-14 18:30:00','confirmed','confirmed',null]);

$binding = ['agent_id'=>7,'owner_user_id'=>1,'conversation_id'=>11];
$planning = vp3_coordination_sources_v1($pdo,$owner,$binding,null,$local)['items'];
$venue = 'planning:calendar:event:901';
$staff = 'planning:calendar:event:902';
canvas_pipeline_check(($planning[$venue]['trigger'] ?? null)==='conflict' && $planning[$venue]['related_ref']===$staff
    && str_contains($planning[$venue]['summary'],'Staff briefing') && !isset($planning[$staff]['trigger'])
    && isset($planning['planning:calendar:event:903']) && !isset($planning['planning:calendar:event:903']['trigger'])
    && !isset($planning['planning:calendar:event:905'])
    && !isset($planning['planning:calendar:event:906']['trigger']),
    'a verified timed overlap has one anchor and excludes all-day, adjacent and other-account events');
canvas_pipeline_check(($planning['planning:calendar:event:904']['trigger'] ?? null)==='changed'
    && ($planning['planning:scheduling:booking:811']['trigger'] ?? null)==='pending_confirmation'
    && isset($planning['planning:scheduling:booking:812']) && !isset($planning['planning:scheduling:booking:812']['trigger'])
    && !isset($planning['planning:scheduling:booking:814'])
    && ($planning['planning:scheduling:booking:813']['trigger'] ?? null)==='changed'
    && !str_contains(json_encode($planning,JSON_THROW_ON_ERROR),'PRIVATE_'),
    'Planning distinguishes confirmation, cancellation and a verified replacement without surfacing duplicate or foreign booking data');
$exact = vp3_coordination_sources_v1($pdo,$owner,$binding,[$venue],$local)['items'];
canvas_pipeline_check($exact===[$venue=>$planning[$venue]],
    'exact verification recreates the same scoped overlap when only its anchor is requested');
$pdo->exec("UPDATE user_calendar_events SET start_at_utc='2026-11-13 19:00:00',end_at_utc='2026-11-13 20:00:00' WHERE id=902");
$moved = vp3_coordination_sources_v1($pdo,$owner,$binding,[$venue],$local)['items'][$venue];
canvas_pipeline_check(!isset($moved['trigger']) && !isset($moved['related_ref']) && $moved['revision']!==$planning[$venue]['revision'],
    'the saved conflict loses its route and prior evidence when the second event moves');

$pdo->exec('CREATE TABLE agent_event_inbox (id INTEGER PRIMARY KEY,owner_user_id INTEGER,source VARCHAR(80),
    event_type VARCHAR(120),external_event_id VARCHAR(190),verification_status VARCHAR(24),occurred_at DATETIME,payload_json TEXT)');
$ledger = $pdo->prepare("INSERT INTO agent_event_inbox VALUES (?,?,'calendar',?,?,?,?,?)");
$timeEntry = static function(int $id,int $ownerId,string $eventType,string $verification,string $at,string $start,string $end) use ($ledger): void {
    $ledger->execute([$id,$ownerId,$eventType,'calendar:906:'.$eventType.':'.$id,$verification,$at,json_encode([
        'domain_source'=>'calendar','record_only'=>true,'object_refs'=>[['type'=>'calendar_event','id'=>'906','scope'=>'personal']],
        'start_at_utc'=>$start,'end_at_utc'=>$end,
    ],JSON_THROW_ON_ERROR)]);
};
$timeEntry(1,1,'calendar.event_created','trusted','2026-11-12 12:00:00','2026-11-13 17:30:00','2026-11-13 18:00:00');
$timeEntry(2,1,'calendar.event_updated','trusted','2026-11-12 13:00:00','2026-11-13 17:30:00','2026-11-13 18:00:00');
$timed = 'planning:calendar:event:906';
$ordinary = vp3_coordination_sources_v1($pdo,$owner,$binding,[$timed],$local)['items'][$timed];
canvas_pipeline_check(!isset($ordinary['trigger']), 'a Calendar metadata edit with unchanged times remains quiet');
$pdo->exec("UPDATE user_calendar_events SET start_at_utc='2026-11-13 20:00:00',end_at_utc='2026-11-13 20:30:00' WHERE id=906");
$timeEntry(3,1,'calendar.event_updated','trusted','2026-11-12 14:00:00','2026-11-13 20:00:00','2026-11-13 20:30:00');
$timeEntry(4,1,'calendar.event_updated','trusted','2026-11-12 15:00:00','2026-11-13 20:00:00','2026-11-13 20:30:00');
$timeEntry(5,2,'calendar.event_updated','trusted','2026-11-12 16:00:00','2026-11-13 21:00:00','2026-11-13 21:30:00');
$timeEntry(6,1,'calendar.event_updated','verified','2026-11-12 17:00:00','2026-11-13 21:00:00','2026-11-13 21:30:00');
$changed = vp3_coordination_sources_v1($pdo,$owner,$binding,[$timed],$local)['items'][$timed];
canvas_pipeline_check(($changed['trigger'] ?? null)==='changed' && str_contains($changed['summary'],'The calendar time changed.')
    && $changed['revision']!==$ordinary['revision'],
    'a real Calendar reschedule uses the current row and trusted same-owner time history despite later description edits');

echo 'COORDINATION_SOURCE_TRIGGERS=PASS ('.$checks.' assertions, '.($mysql ? 'mysql' : 'sqlite').")\n";
