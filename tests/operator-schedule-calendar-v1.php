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

require_once dirname(__DIR__).'/includes/agent-operator-dispatch-preview-v1.php';
$pdo->exec('DELETE FROM agent_scheduling_bookings');
vp3_operator_auto_call_ensure_schema_v1($pdo);
vp3_phone_recording_ensure_schema_v1($pdo);
vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
$scope=vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>0,'conversation_id'=>11]);
$callTime=$future+7*86400;
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=?')->execute([$callTime+86400]);
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='23:59',timezone='UTC'");
c(vp3_operator_occurrences_tick_v1($pdo,$callTime)['prepared']===1,'fixture prepares the following weekly occurrence');
$preview=fn()=>vp3_operator_preview_due_v1($pdo,$callTime);
c($preview()===['owner_permission'=>1],'preparation alone cannot authorize an outbound request');
$callingPolicy=array_replace(vp3_operator_auto_call_defaults_v1(),['window_start'=>'00:00','window_end'=>'23:59']);
$permission=['agent_id'=>0,'conversation_id'=>11,'contact_id'=>$id,'number_id'=>1,'expected_revision'=>0,
    'expected_destination'=>$choice['expected_destination'],'expected_line'=>$choice['expected_line'],'settings'=>$callingPolicy,'enabled'=>true];
vp3_operator_auto_call_save_v1($pdo,$scope,$permission);
$attest=array_intersect_key($permission,array_flip(['agent_id','conversation_id','contact_id','number_id','expected_destination','expected_line']));
$attest+=['decision'=>'attest','expected_revision'=>1,'expected_consent_revision'=>0,'purpose'=>'follow_up','recipient_agreement_attested'=>true];
vp3_operator_consent_save_v1($pdo,$scope,$attest,$callTime);
$q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=1 AND event_id=? AND start_epoch=?');
$q->execute([$eventId,$callTime]);
$row=$q->fetch(PDO::FETCH_ASSOC);
$plan=vp3_operator_preview_request_v1($pdo,$row,$callTime);
c($plan['reason']==='eligible'&&$plan['fields']['To']===$choice['expected_destination']&&$plan['fields']['From']===$choice['expected_line']
    &&$plan['fields']['TimeLimit']===$duration&&$plan['objectives']===$event['description'],'verified route, time limit and objectives reach the simulated carrier boundary');
c($preview()===['eligible'=>1]&&$preview()===['eligible'=>1],'preview returns only counts and leaves the occurrence available');
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='10:00',window_end='11:00'");
c($preview()===['calling_window'=>1],'recipient local calling hours apply immediately');
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=0');
c($preview()===['recipient_permission'=>1],'recipient withdrawal after preparation prevents a carrier plan');
echo "PASS scheduled outbound request preview, current consent and privacy\n";

require_once dirname(__DIR__).'/includes/agent-operator-carrier-attempt-v1.php';
$attempt=fn(callable $send)=>vp3_operator_carrier_attempt_v1($pdo,1,$eventId,$callTime,$send,$callTime);
$unexpected=static function(){throw new LogicException('Unexpected adapter invocation');};
c($attempt($unexpected)==='recipient_permission','withdrawal blocks reservation before simulated transport');
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=1,window_start='00:00',window_end='23:59'");
$sent=0;
$scenario=$argv[1]??'success';
$result=$attempt(function($request)use(&$sent,$plan,$pdo,$attempt,$unexpected,$scenario){
    ++$sent;
    c(!vp3_phone_transaction_active_v1($pdo)&&$request===$plan,'mock carrier receives the approved objective outside a transaction');
    c($attempt($unexpected)==='submitting','a second worker cannot submit the in-flight occurrence');
    if($scenario==='timeout')throw new RuntimeException('Response lost after submission');
    return ['sid'=>'CA'.str_repeat('c',32),'account_sid'=>vp3_phone_twilio_state_v100()['account_sid'],
        'from'=>$request['fields']['From'],'to'=>$scenario==='mismatch'?'wrong destination':$request['fields']['To']];
});
$expected=$scenario==='success'?'submitted':'uncertain';
c($result===$expected&&$attempt($unexpected)===$expected&&$sent===1,'terminal outcome fences replay');
c($read()['last_occurrence']['reason']==='carrier_'.$expected,'original private schedule history shows the submission outcome');
echo "PASS single carrier attempt ($scenario), in-flight exclusion and retry handling\n";
