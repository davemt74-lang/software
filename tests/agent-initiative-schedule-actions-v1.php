<?php
declare(strict_types=1);

// Reuse the real Office persistence/permission fixture, then the production
// shared Initiative authority, canonical policy reader and schedule adapters.
require __DIR__.'/agent-initiative-office-actions-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-actions-v1.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
function url(string $path):string{return $path;}
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,
      source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT);
    CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,
      created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,
      recurrence TEXT DEFAULT 'none',agent_notifications INTEGER DEFAULT 0,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
reminder_schema_v1($pdo);
$scheduleChecks=0;
function schedule_action_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['scheduleChecks']++;echo 'PASS '.$label.PHP_EOL;}
function schedule_action_reject(callable $work,int $code,string $label):void{try{$work();}catch(Throwable $e){schedule_action_check($e->getCode()===$code,$label.' ('.$e->getMessage().')');return;}throw new RuntimeException('Expected rejection: '.$label);}
function schedule_action_seed_policy(int $agent,int $cid):array
{
    $policy=['enabled'=>true,'owner_user_id'=>1,'agent_id'=>$agent,'conversation_id'=>$cid,'revision'=>bin2hex(random_bytes(16)),
        'screen_enabled'=>false,'knowledge_scope'=>['mode'=>'off','folder_id'=>0]];
    vp3_initiative_in_scope_v1($GLOBALS['u'],$agent,static fn()=>agent_brain_v122_upsert_system_memory($GLOBALS['u'],'initiative_policy','owner-opt-in','Active Initiative policy.',$policy));
    return ['owner_user_id'=>1,'agent_id'=>$agent,'conversation_id'=>$cid,'policy_revision'=>$policy['revision'],
        'proposal_message_id'=>123,'reply_ref'=>str_repeat('a',64),'proposal_revision'=>1,'owner_message_id'=>124,'owner_message_hash'=>str_repeat('b',64)];
}
function schedule_action_resources(array $refs):array{return vp3_initiative_schedule_action_resources_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['scheduleBinding'],$refs);}
function schedule_action_plan(string $adapter,array $args,array $resources=[]):array{return vp3_initiative_schedule_action_normalize_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['scheduleBinding'],['adapter'=>$adapter,'arguments'=>$args],$resources);}
function schedule_action_apply(array $plan,string $id):array
{
    db()->beginTransaction();try{section12_lock_owner(db(),1);$r=vp3_initiative_schedule_action_apply_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['scheduleBinding'],$plan,$id);db()->commit();return $r;}
    catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function schedule_action_inspect(array $plan,array $result):array{return vp3_initiative_schedule_action_inspect_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['scheduleBinding'],$plan,$result);}
$scheduleBinding=schedule_action_seed_policy(7,10);$p=vp3_initiative_principal_v1($pdo,$u,7);
schedule_action_check(count(vp3_initiative_schedule_action_catalog_v1($pdo,$u,$p,$scheduleBinding))===10,'actual shared Initiative authority exposes ten typed reminder/personal-calendar reviews');
$schedule=['title'=>'Follow up on the release','timezone'=>'America/New_York','start_local'=>(new DateTimeImmutable('+2 hours'))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d\TH:i:s'),'recurrence'=>'daily','delivery'=>'both'];
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$create=schedule_action_plan('reminder.create',['schedule'=>$schedule]);
schedule_action_check($create['review_required']&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'exact reminder proposal preparation performs no write');
schedule_action_reject(fn()=>vp3_initiative_schedule_action_apply_v1($pdo,$u,$p,$scheduleBinding,$create,'schedule-no-tx'),409,'schedule adapter cannot self-open a transaction');
schedule_action_reject(fn()=>schedule_action_plan('reminder.create',['schedule'=>$schedule,'confirmed'=>true]),0,'model confirmation cannot grant scheduling authority');
$result=schedule_action_apply($create,'schedule-reminder-create');$rid=$result['record_id'];
schedule_action_check($result['verified']&&$result['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===1,'reviewed reminder creates and verifies the actual canonical record');
$replay=schedule_action_apply($create,'schedule-reminder-create');
schedule_action_check($replay['record_id']===$rid&&(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===1,'same stable reminder identity recovers the canonical result without another reminder');
schedule_action_check(schedule_action_inspect($create,$result)['verified'],'inspection uses current canonical domain evidence');
$forged=$result;$forged['record_id']=$rid+1;
schedule_action_reject(fn()=>schedule_action_inspect($create,$forged),409,'substituted reminder receipt record is rejected');
$forged=$result;$forged['effect']['record']['title']='Model claimed a different saved title';
schedule_action_reject(fn()=>schedule_action_inspect($create,$forged),409,'claimed effect payload cannot disagree with its canonical hash and readback');
schedule_action_reject(fn()=>schedule_action_plan('reminder.cancel',['target_ref'=>'reminder:'.$rid]),403,'owned reminder outside exact proposal resources is unwritable');
$resources=schedule_action_resources(['reminder:'.$rid]);
$update=schedule_action_plan('reminder.update',['target_ref'=>'reminder:'.$rid,'schedule'=>array_merge($schedule,['title'=>'Updated follow-up'])],$resources);
$updated=schedule_action_apply($update,'schedule-reminder-update');
schedule_action_check($updated['verified']&&$pdo->query('SELECT title FROM agent_reminders_v1 WHERE id='.$rid)->fetchColumn()==='Updated follow-up','reviewed update reuses reminder mutation kernel and verifies replacement schedule');
schedule_action_reject(fn()=>schedule_action_apply($update,'schedule-reminder-stale'),409,'parallel old reminder review cannot overwrite new state');
schedule_action_reject(fn()=>schedule_action_inspect($create,$result),409,'old reminder effect becomes stale after a later legitimate change');
$pdo->prepare("INSERT INTO agent_reminder_occurrences_v1(reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(?,1,?,?,'pending')")
    ->execute([$rid,gmdate('Y-m-d\TH:i:s\Z',time()-600),gmdate('Y-m-d\TH:i:s\Z',time()-600)]);
$snooze=schedule_action_plan('reminder.snooze',['target_ref'=>'reminder:'.$rid,'minutes'=>10],schedule_action_resources(['reminder:'.$rid]));
$snoozed=schedule_action_apply($snooze,'schedule-reminder-snooze');
schedule_action_check($snoozed['verified']&&$snoozed['effect']['occurrence']['status']==='pending','snooze verifies exact current occurrence and saved wake time');
$complete=schedule_action_plan('reminder.complete',['target_ref'=>'reminder:'.$rid],schedule_action_resources(['reminder:'.$rid]));
$completed=schedule_action_apply($complete,'schedule-reminder-complete');
schedule_action_check($completed['effect']['occurrence']['status']==='done'&&$completed['effect']['record']['status']==='active','completion finishes only the occurrence and retains future repeats');
$cancel=schedule_action_plan('reminder.cancel',['target_ref'=>'reminder:'.$rid],schedule_action_resources(['reminder:'.$rid]));
$cancelled=schedule_action_apply($cancel,'schedule-reminder-cancel');
schedule_action_check($cancelled['effect']['record']['status']==='cancelled'&&$cancelled['source_after']['reminder:'.$rid]===null,'cancel verifies stopped series and exact source removal');
$toDelete=schedule_action_apply($create,'schedule-reminder-delete-source');$deleteId=$toDelete['record_id'];
$pdo->prepare("INSERT INTO agent_reminder_occurrences_v1(reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(?,1,?,?,'pending')")
    ->execute([$deleteId,gmdate('Y-m-d\TH:i:s\Z',time()-300),gmdate('Y-m-d\TH:i:s\Z',time()-300)]);
$dismiss=schedule_action_plan('reminder.dismiss',['target_ref'=>'reminder:'.$deleteId],schedule_action_resources(['reminder:'.$deleteId]));
$dismissed=schedule_action_apply($dismiss,'schedule-reminder-dismiss');
schedule_action_check($dismissed['verified']&&$dismissed['effect']['occurrence']['status']==='dismissed'&&$dismissed['effect']['record']['status']==='active','dismissal affects current occurrence without cancelling the reminder');
$delete=schedule_action_plan('reminder.delete',['target_ref'=>'reminder:'.$deleteId],schedule_action_resources(['reminder:'.$deleteId]));
$deleted=schedule_action_apply($delete,'schedule-reminder-delete');
schedule_action_check($deleted['verified']&&$deleted['effect']['record']===null&&$deleted['source_after']['reminder:'.$deleteId]===null,'delete verifies canonical record absence without a fabricated completion');
schedule_action_check(schedule_action_inspect($delete,$deleted)['verified'],'deleted target has a verified replay tombstone');

$day=(new DateTimeImmutable('+2 days'))->format('Y-m-d');
$event=['title'=>'Review launch','description'=>'Prepare the promotion plan.','location'=>'Office','timezone'=>'America/New_York',
    'date'=>$day,'start_time'=>'09:00','end_time'=>'10:00','all_day'=>false,'recurrence'=>'none','agent_notifications'=>false];
$calendarCreate=schedule_action_plan('calendar.create',['event'=>$event]);
$calendar=schedule_action_apply($calendarCreate,'schedule-calendar-create');$eid=$calendar['record_id'];
schedule_action_check($calendar['verified']&&$calendar['effect']['record']['created_by_agent_id']===7,'personal-calendar create uses actual owner/Agent and canonical idempotency source');
$again=schedule_action_apply($calendarCreate,'schedule-calendar-create');
schedule_action_check($again['record_id']===$eid&&(int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===1,'calendar response recovery reuses the exact saved event');
$calendarResources=schedule_action_resources(['calendar:event:'.$eid]);
$move=schedule_action_plan('calendar.update',['target_ref'=>'calendar:event:'.$eid,'event'=>array_merge($event,['start_time'=>'10:00','end_time'=>'11:00'])],$calendarResources);
$moved=schedule_action_apply($move,'schedule-calendar-move');
schedule_action_check($moved['verified']&&str_ends_with($moved['effect']['record']['start_at_utc'],'15:00:00'),'calendar update verifies exact timezone-aware range through existing kernel');
schedule_action_reject(fn()=>schedule_action_apply($move,'schedule-calendar-stale'),409,'stale reviewed calendar range cannot overwrite another owner edit');
$calendarCancel=schedule_action_plan('calendar.cancel',['target_ref'=>'calendar:event:'.$eid],schedule_action_resources(['calendar:event:'.$eid]));
$retired=schedule_action_apply($calendarCancel,'schedule-calendar-cancel');
schedule_action_check($retired['verified']&&$retired['effect']['record']['status']==='cancelled','calendar cancellation verifies canonical status rather than proposal text');
$allDayPlan=schedule_action_plan('calendar.create',['event'=>['title'=>'Release day','timezone'=>'America/New_York','date'=>$day,'all_day'=>true]]);
$allDay=schedule_action_apply($allDayPlan,'schedule-calendar-all-day');
schedule_action_check($allDay['verified']&&(int)$allDay['effect']['record']['all_day']===1,'all-day calendar action verifies the exact canonical date range');
$weeklyPlan=schedule_action_plan('calendar.create',['event'=>array_merge($event,['recurrence'=>'weekly'])]);
$weekly=schedule_action_apply($weeklyPlan,'schedule-calendar-weekly');$weeklyId=$weekly['record_id'];$weeklyRef=$weekly['output_refs'][0]['ref'];
schedule_action_check(str_contains($weeklyRef,'@')&&schedule_action_resources([$weeklyRef])['calendar_events'][0]['id']===$weeklyId,'weekly create returns an exact canonical occurrence source rather than a competing series alias');
$weeklyCancel=schedule_action_plan('calendar.cancel',['target_ref'=>$weeklyRef],schedule_action_resources([$weeklyRef]));
$weeklyRetired=schedule_action_apply($weeklyCancel,'schedule-calendar-weekly-cancel');
schedule_action_check($weeklyRetired['verified']&&$weeklyRetired['effect']['record']['status']==='cancelled','reviewed weekly cancellation changes the explicitly identified whole series');
$rollback=schedule_action_plan('reminder.create',['schedule'=>array_merge($schedule,['title'=>'Rollback follow-up'])]);$beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn();
$pdo->beginTransaction();section12_lock_owner($pdo,1);$rolled=vp3_initiative_schedule_action_apply_v1($pdo,$u,$p,$scheduleBinding,$rollback,'schedule-rollback');
schedule_action_check($rolled['verified']&&$pdo->inTransaction(),'verified adapter readback leaves commit ownership to broker');$pdo->rollBack();
schedule_action_check((int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()===$beforeCount,'broker rollback atomically removes an otherwise verified schedule effect');

$newCalendar=schedule_action_apply($calendarCreate,'schedule-calendar-linked');$linkedId=$newCalendar['record_id'];
$pdo->exec('CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,calendar_event_id INTEGER)');$pdo->prepare('INSERT INTO video_meetings VALUES(1,?)')->execute([$linkedId]);
schedule_action_check(schedule_action_resources(['calendar:event:'.$linkedId])['calendar_events']===[],'linked video calendar sources remain readable but do not become personal writable resources');
$fakeResources=['calendar_events'=>[['id'=>$linkedId,'ref'=>'calendar:event:'.$linkedId,'revision'=>str_repeat('a',64)]]];
schedule_action_reject(fn()=>schedule_action_plan('calendar.cancel',['target_ref'=>'calendar:event:'.$linkedId],$fakeResources),409,'forged writable linkage cannot bypass source and meeting checks');
$namedBinding=$scheduleBinding;$namedP=$p;$scheduleBinding=schedule_action_seed_policy(0,13);$p=vp3_initiative_principal_v1($pdo,$u,0);
$systemCalendarPlan=schedule_action_plan('calendar.create',['event'=>$event]);$systemCalendar=schedule_action_apply($systemCalendarPlan,'schedule-system-calendar');
schedule_action_check($systemCalendar['verified']&&$systemCalendar['effect']['record']['created_by_agent_id']===null,'canonical System principal keeps null calendar Agent identity');
$systemReminder=schedule_action_apply($create,'schedule-system-reminder');
schedule_action_check($systemReminder['verified']&&(int)$systemReminder['effect']['record']['conversation_id']===13,'System reminder is bound to its actual owner conversation');
$scheduleBinding=$namedBinding;$p=$namedP;
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='chat.access'");
schedule_action_reject(fn()=>schedule_action_apply($create,'schedule-chat-revoked'),403,'fresh canonical Chat permission revoke blocks reminder effects');$pdo->exec("INSERT INTO role_permissions VALUES('member','chat.access')");
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='account.access'");
schedule_action_reject(fn()=>schedule_action_apply($calendarCreate,'schedule-calendar-account-revoked'),403,'fresh canonical account permission revoke blocks calendar effects');$pdo->exec("INSERT INTO role_permissions VALUES('member','account.access')");
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');schedule_action_reject(fn()=>schedule_action_apply($create,'schedule-retired-agent'),403,'retired Agent cannot fall back to System scheduling');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$wrong=$scheduleBinding;$wrong['conversation_id']=12;
schedule_action_reject(fn()=>vp3_initiative_schedule_action_normalize_v1($pdo,$u,$p,$wrong,['adapter'=>'reminder.create','arguments'=>['schedule'=>$schedule]],[]),403,'account-switched conversation is rejected by the actual shared scope');
$paused=vp3_initiative_policy_v1($u,7);$paused['enabled']=false;
vp3_initiative_in_scope_v1($u,7,static fn()=>agent_brain_v122_upsert_system_memory($u,'initiative_policy','owner-opt-in','Paused policy.',$paused));
schedule_action_reject(fn()=>schedule_action_apply($create,'schedule-policy-paused'),403,'current paused Initiative policy fences a previously reviewed action');
echo 'AGENT_INITIATIVE_SCHEDULE_ACTIONS_V1=PASS ('.$scheduleChecks.' checks; actual shared scope, canonical adapters and persistence)'.PHP_EOL;
