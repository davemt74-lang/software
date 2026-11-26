<?php
declare(strict_types=1);
require __DIR__.'/operator-schedule-history-v1.php';

// Restore the series to active after the history fixture and reassign its current objectives.
$pdo->prepare("UPDATE user_calendar_events SET status='active' WHERE id=?")->execute([$eventId]);
$current=$read();
$choice=array_replace($choice,['expected_revision'=>$current['revision'],'assignment_revision'=>$current['assignment_revision']]);
action('assign_scheduled_operator',$choice);
$due=(int)$pdo->query('SELECT next_due_epoch FROM phone_operator_scheduled_assignments_v1')->fetchColumn();
$event=event($eventId);
$duration=strtotime($event['end_at_utc'].' UTC')-strtotime($event['start_at_utc'].' UTC');
$assignment=$pdo->query('SELECT * FROM phone_operator_scheduled_assignments_v1')->fetch();
$reason=static fn()=>vp3_operator_occurrence_calendar_reason_v1($pdo,$assignment,$due,$due+$duration,$due+5);
c($reason()==='awaiting_dispatch_checks','the assigned recurrence is excluded from its own calendar check');
$pdo->prepare("INSERT INTO user_calendar_events(owner_user_id,title,start_at_utc,end_at_utc) VALUES(1,'Private appointment',?,?)")
    ->execute([gmdate('Y-m-d H:i:s',$due+$duration),gmdate('Y-m-d H:i:s',$due+$duration+60)]);
$other=(int)$pdo->lastInsertId();
$sql=$pdo->prepare('UPDATE user_calendar_events SET start_at_utc=?,end_at_utc=? WHERE id=?');
$sql->execute([gmdate('Y-m-d H:i:s',$due+$duration),gmdate('Y-m-d H:i:s',$due+$duration+60),$other]);
c($reason()==='awaiting_dispatch_checks','an adjacent event does not overlap');
$sql->execute([gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$due+$duration),$other]);
c($reason()==='calendar_conflict','an unrelated event with identical times blocks the call');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$other]);
ddl("CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status VARCHAR(24),start_at_utc DATETIME,end_at_utc DATETIME,event_title VARCHAR(190),guest_name VARCHAR(120),location_value VARCHAR(190),organizer_timezone VARCHAR(80),source VARCHAR(32),schedule_id INTEGER)");
$pdo->prepare("INSERT INTO agent_scheduling_bookings VALUES(?,1,'confirmed',?,?,'Booking','','','UTC','user',1)")
    ->execute([$eventId,gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$due+$duration)]);
c($reason()==='calendar_conflict','a booking with the same id and interval as the call still blocks');
$missing=array_replace($assignment,['conversation_id'=>21]);
c(vp3_operator_occurrence_calendar_reason_v1($pdo,$missing,$due,$due+$duration,$due)==='chat_unavailable','a foreign Chat is blocked');
$future=$due+4*7*86400;
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=?')->execute([$future+86400]);
$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET next_due_epoch=? WHERE event_id=?')->execute([$future,$eventId]);
$pdo->prepare('UPDATE agent_scheduling_bookings SET start_at_utc=?,end_at_utc=? WHERE id=?')
    ->execute([gmdate('Y-m-d H:i:s',$future),gmdate('Y-m-d H:i:s',$future+$duration),$eventId]);
c(vp3_operator_occurrences_tick_v1($pdo,$future+1)['blocked']===1,'worker blocks the due call on a later booking');
c($read()['last_occurrence']['reason']==='calendar_conflict','the private schedule history explains the collision');
c(array_sum(vp3_operator_occurrences_tick_v1($pdo,$future+1))===0,'a repeated worker tick cannot add a second outcome');
echo "PASS scheduled call Calendar conflicts, adjacency and Chat revalidation\n";
