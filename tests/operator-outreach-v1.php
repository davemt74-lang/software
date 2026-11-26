<?php
declare(strict_types=1);
require __DIR__.'/operator-schedule-calendar-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-outreach-v1.php';
$at=$callTime+86400+120;
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=?')->execute([$at+86400]);
$pdo->exec("UPDATE phone_operator_contact_policies_v1 SET window_start='00:00',window_end='23:59'");
$input=['contact_id'=>$id,'number_id'=>1,'expected_revision'=>0,'enabled'=>false,'review_missed'=>false,
    'topics'=>"Opening day\nMenu",'questions'=>"Which day works for you?\nWho will attend?"];
$agenda=action('save_operator_agenda',$input)['agenda'];
c(!$agenda['enabled']&&$agenda['questions']===$input['questions']&&$agenda['revision']===1,'owner can save exact questions without enabling dialing');
c(detail($id,1,['conversation_id'=>11])['agenda']===$agenda&&detail($id,1,['agent_id'=>7,'conversation_id'=>12])['agenda']['questions']==='','agenda stays in its original private Chat');
denies(fn()=>action('save_operator_agenda',$input),409,'concurrent edit is detected');
denies(fn()=>action('save_operator_agenda',$input,2),404,'foreign contact cannot be edited');
$run=fn($when)=>vp3_operator_outreach_tick_v1($pdo,$when);
c(array_sum($run($at))===0,'no enabled agenda means no work');
$input['enabled']=true;$input['expected_revision']=1;
action('save_operator_agenda',$input);
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=1,holder_proactive=1');
c($run($at)['deferred']===1,'scheduled consent cannot authorize spontaneous dialing');
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=2,holder_proactive=1');
$events=fn()=>(int)$pdo->query("SELECT count(*) FROM user_calendar_events WHERE source_reference LIKE 'operator-outreach:%'")->fetchColumn();
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:01',window_end='00:02'");
c($run($at+61)['deferred']===1&&$events()===0,'failed admission rolls back private event, assignment and reason claim');
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='23:59'");
$when=$at+122;
c($run($when)===['prepared'=>1,'deferred'=>0,'errors'=>0]&&$events()===1,'server chooses an objective without any scheduled call');
c($run($when+61)['deferred']===1&&$events()===1,'repeat worker cannot create another call for an unchanged agenda');
$receipt=$pdo->query("SELECT o.* FROM phone_operator_occurrences_v1 o JOIN phone_operator_outreach_uses_v1 u ON u.owner_user_id=o.owner_user_id AND u.event_id=o.event_id")->fetch(PDO::FETCH_ASSOC);
$plan=vp3_operator_preview_request_v1($pdo,$receipt,$when);
c($plan['reason']==='eligible'&&$plan['opening']==='Which day works for you?'&&str_contains($plan['objectives'],$input['questions']),'consented opening and model briefing carry the saved questions');
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET holder_proactive=0');
c(vp3_operator_preview_request_v1($pdo,$receipt,$when)['reason']==='recipient_permission','withdrawal after planning blocks dispatch');
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET holder_proactive=1');
$sent=0;
$counts=vp3_operator_dispatch_tick_v1($pdo,function($request)use(&$sent,$input){
    $sent++;
    c(str_contains($request['objectives'],$input['topics']),'mock transport receives objective from the worker');
    return ['sid'=>'CA'.str_repeat('d',32),'account_sid'=>vp3_phone_twilio_state_v100()['account_sid'],'from'=>$request['fields']['From'],'to'=>$request['fields']['To']];
},$when);
c($sent===1&&$counts['submitted']===1,'exactly one mock carrier call with browser closed');
$input['enabled']=false;$input['expected_revision']=2;
action('save_operator_agenda',$input);
c(vp3_operator_current_request_v1($pdo,$receipt,$when,'CA'.str_repeat('d',32))['reason']==='agenda_changed','disabling agenda after submission stops conversation');
$row=vp3_operator_agenda_row_v1($pdo,[1,$id,11,0]);
$row['topics']=$row['questions']='';$row['review_missed']=1;
c(vp3_operator_outreach_reason_v1($pdo,$row,'follow_up',$when)===null,'no account-grounded reason for a recurring or unrelated call');
$pdo->prepare("UPDATE user_calendar_events SET recurrence='none' WHERE id=?")->execute([$eventId]);
$rev=section12_revision(event($eventId));
$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET event_revision=? WHERE event_id=?')->execute([$rev,$eventId]);
// A later completed human call resolves the missed-call reason.
$pdo->prepare("UPDATE phone_browser_calls_v1 SET state='completed' WHERE provider_call_sid=?")->execute(['CA'.str_repeat('d',32)]);
c(vp3_operator_outreach_reason_v1($pdo,$row,'follow_up',$when)===null,'a later completed call resolves the missed-call reason');
// Evaluate the account as it stood before that contact was reached.
$reason=vp3_operator_outreach_reason_v1($pdo,$row,'follow_up',$when-1);
c($reason&&$reason['account_reason']&&str_contains($reason['objective'],'missed scheduled call'),'agent chooses an account-grounded follow-up with no owner topics');
$row['conversation_id']=12;
c(vp3_operator_outreach_reason_v1($pdo,$row,'follow_up',$when)===null,'account discovery cannot borrow another chat’s call history');
echo "PASS contact agenda, grounded account discovery, permission changes and one browser-closed mock dispatch\n";
