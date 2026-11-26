<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-occurrences-v1.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/phone-recording-pipeline-v1.php';
require_once __DIR__.'/phone-browser-v1.php';

/** Build an outbound request from current grants. This preview never submits or consumes it. */
function vp3_operator_preview_request_v1(PDO $pdo,array $row,int $now):array
{
    if($row['state']!=='prepared')return ['reason'=>'outside_occurrence'];
    $plan=vp3_operator_current_request_v1($pdo,$row,$now);
    if($plan['reason']!=='eligible')return $plan;
    $connection=vp3_phone_twilio_state_v100();
    if(!$connection['configured'])return ['reason'=>'carrier_unavailable'];
    try{
        $answer=vp3_phone_browser_callback_v1('operator_answer',['event'=>'answer']);
        $status=vp3_phone_browser_callback_v1('operator_answer',['event'=>'status']);
    }catch(InvalidArgumentException){return ['reason'=>'carrier_unavailable'];}
    $plan['account_sid']=$connection['account_sid'];
    $plan['fields']+=['Url'=>$answer,'Method'=>'POST','StatusCallback'=>$status,'StatusCallbackMethod'=>'POST'];
    return $plan;
}

/** Revalidate the route and grants for an occurrence whose lifecycle state the caller verified. */
function vp3_operator_current_request_v1(PDO $pdo,array $row,int $now,?string $answeredSid=null):array
{
    $owner=(int)$row['owner_user_id'];
    $id=(int)$row['event_id'];
    $end=(int)$row['end_epoch'];
    if($now<(int)$row['start_epoch']||$now>=$end)return ['reason'=>'outside_occurrence'];
    $query=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?');
    $query->execute([$owner,$id]);
    $assignment=$query->fetch(PDO::FETCH_ASSOC);
    $event=user_calendar_event_v1300($pdo,$owner,$id);
    if(!$assignment||!$assignment['enabled']||(int)$assignment['revision']!==(int)$row['assignment_revision']
        ||!$event||$event['status']!=='active'||!hash_equals($assignment['event_revision'],section12_revision($event))){
        return ['reason'=>'assignment_changed'];
    }
    $scope=vp3_phone_workspace_scope_v1($pdo,['id'=>$owner],['conversation_id'=>$assignment['conversation_id'],'agent_id'=>$assignment['chat_agent_id']]);
    $contact=(int)$assignment['contact_id'];
    $policy=vp3_operator_auto_call_row_v1($pdo,$scope,$contact,(int)$assignment['agent_id']);
    if(!$policy||!$policy['enabled'])return ['reason'=>'owner_permission'];
    $bound=vp3_operator_admission_context_v1($pdo,$scope,['contact_id'=>$contact,'number_id'=>(int)$assignment['number_id'],'expected_revision'=>(int)$policy['revision']]);
    $to=$bound['contact']['destination_e164'];
    $line=$bound['line'];
    if(!hash_equals($assignment['destination_hash'],$bound['destination_hash'])
        ||!hash_equals($assignment['line_binding'],hash('sha256',$line['provider_sid'].'|'.$line['phone_e164']))
        ||vp3_operator_admission_policy_block_v1($pdo,$scope,$bound)!=='')return ['reason'=>'route_or_recording_changed'];
    $withdrawn=vp3_operator_recipient_opted_out_v1($pdo,$owner,$bound['destination_hash']);
    $review=vp3_operator_consent_row_v1($pdo,$scope,$bound);
    if(vp3_operator_consent_state_v1($review,$bound,$withdrawn,$now)!=='owner_attested'
        ||!vp3_operator_holder_modes_v1($pdo,$owner,$contact,$to,$now)['scheduled'])return ['reason'=>'recipient_permission'];
    $query=$pdo->prepare('SELECT p.* FROM vp3_phone_communication_profiles_v1 p JOIN crm_contacts c
        ON c.owner_user_id=p.owner_user_id AND c.connected_phone_id=p.device_id WHERE c.owner_user_id=? AND c.id=? AND p.phone_e164=?');
    $query->execute([$owner,$contact,$to]);
    $holder=$query->fetch(PDO::FETCH_ASSOC);
    if(!$holder)return ['reason'=>'recipient_permission'];
    $seconds=min($end-$now,(int)$policy['max_duration_seconds']);
    if($seconds<=0)return ['reason'=>'outside_occurrence'];
    foreach([$policy,$holder] as $settings){
        $settings['max_duration_seconds']=$seconds;
        if(!vp3_operator_admission_window_v1($settings,$now))return ['reason'=>'calling_window'];
        $history=vp3_operator_admission_history_v1($pdo,$scope,$bound,$settings,$now,$answeredSid);
        if(!$history['gap_ok']||!$history['within_limit'])return ['reason'=>'call_frequency'];
    }
    if(!vp3_operator_admission_idle_v1($pdo,$owner,$now,$answeredSid))return ['reason'=>'phone_busy'];
    $calendar=vp3_operator_occurrence_calendar_reason_v1($pdo,$assignment,(int)$row['start_epoch'],$end,$now);
    if($calendar!=='awaiting_dispatch_checks')return ['reason'=>$calendar];
    return ['reason'=>'eligible','fields'=>['From'=>$line['phone_e164'],'To'=>$to,'TimeLimit'=>$seconds],
        'objectives'=>$event['description'],'conversation_id'=>(int)$assignment['conversation_id'],
        'agent_id'=>(int)$assignment['agent_id'],'number_id'=>(int)$assignment['number_id'],
        'chat_agent_id'=>(int)$assignment['chat_agent_id']];
}

function vp3_operator_preview_due_v1(PDO $pdo,int $now):array
{
    $query=$pdo->prepare("SELECT * FROM phone_operator_occurrences_v1 WHERE state='prepared'
        AND start_epoch<=? AND end_epoch>? ORDER BY start_epoch,owner_user_id,event_id LIMIT 100");
    $query->execute([$now,$now]);
    $results=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{
            $preview=section12_owner_transaction($pdo,(int)$row['owner_user_id'],fn()=>vp3_operator_preview_request_v1($pdo,$row,$now));
            $reason=$preview['reason'];
        }catch(VP3PhoneWorkspaceException){$reason='access_changed';}
        $results[$reason]=($results[$reason]??0)+1;
    }
    return $results;
}
