<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-carrier-attempt-v1.php';

/** Only carrier form fields may leave the server. Objectives and Chat bindings stay local. */
function vp3_operator_submit_scheduled_v1(array $plan, ?callable $http=null):array
{
    $fields=[];
    foreach(['From','To','TimeLimit','Url','Method','StatusCallback','StatusCallbackMethod'] as $key){
        if(!isset($plan['fields'][$key]))throw new InvalidArgumentException('Incomplete scheduled call.');
        $fields[$key]=$plan['fields'][$key];
    }
    $http??='vp3_phone_twilio_http_v100';
    [$account]=vp3_phone_twilio_credentials_v100();
    return $http('POST','/2010-04-01/Accounts/'.$account.'/Calls.json',$fields);
}

/** Scan a snapshot of due times. Each reservation rechecks the current clock and grants. */
function vp3_operator_dispatch_tick_v1(PDO $pdo,callable $carrier,?int $clock=null):array
{
    // Verify receipt, conversation and recording storage before a carrier can accept a call.
    if(!vp3_operator_schedule_assignment_ready_v1($pdo)||!vp3_phone_recording_schema_ready_v1($pdo)){
        throw new RuntimeException('Install the Cloud phone upgrade before enabling scheduled dispatch.');
    }
    $pdo->query('SELECT call_public_id,recording_state,recording_sid,recording_policy_json,recording_started_epoch
        FROM phone_operator_carrier_attempts_v1 WHERE 1=0');
    $pdo->query('SELECT provider_call_sid,transport_kind,recording_enabled,chat_message_id FROM phone_browser_calls_v1 WHERE 1=0');
    $pdo->query('SELECT call_sid,step_number,owner_user_id,agent_id,speech_hash,speech_text,response_text,state FROM phone_voice_turns_v140 WHERE 1=0');
    $cutoff=$clock??time();
    $after=[0,0,0];
    $counts=['submitted'=>0,'uncertain'=>0,'skipped'=>0,'errors'=>0];
    $query=$pdo->prepare("SELECT start_epoch,owner_user_id,event_id FROM phone_operator_occurrences_v1
        WHERE state='prepared' AND start_epoch<=? AND end_epoch>? AND (start_epoch,owner_user_id,event_id)>(?,?,?)
        ORDER BY start_epoch,owner_user_id,event_id LIMIT 100");
    do{
        $query->execute([$cutoff,$clock??time(),...$after]);
        $batch=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($batch as $row){
            $after=[(int)$row['start_epoch'],(int)$row['owner_user_id'],(int)$row['event_id']];
            try{
                $state=vp3_operator_carrier_attempt_v1($pdo,$after[1],$after[2],$after[0],$carrier,$clock);
                ++$counts[in_array($state,['submitted','uncertain'],true)?$state:'skipped'];
            }catch(VP3PhoneWorkspaceException){
                ++$counts['skipped'];
            }catch(Throwable){
                ++$counts['errors'];
            }
        }
    }while(count($batch)===100);
    return $counts;
}
