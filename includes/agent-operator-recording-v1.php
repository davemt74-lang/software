<?php
declare(strict_types=1);

require_once __DIR__.'/agent-operator-dispatch-preview-v1.php';
require_once __DIR__.'/phone-recording-pipeline-v1.php';

function vp3_operator_recording_attempt_v1(PDO $pdo,int $owner,string $sid,bool $lock=false):?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND provider_sid=?'.
        ($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$owner,$sid]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}

/** Create the canonical call/history row only after the carrier proves acceptance. */
function vp3_operator_recording_bind_call_v1(PDO $pdo,array $attempt,array $plan,int $now):array
{
    $sid=(string)$attempt['provider_sid'];
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE provider_call_sid=?');$q->execute([$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);
    if($call){
        if((int)$call['owner_user_id']!==(int)$attempt['owner_user_id']||($call['transport_kind']??'')!=='operator_scheduled')
            throw new RuntimeException('Scheduled call receipt conflicts with an existing call.',409);
        return $call;
    }
    $public=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,
        provider_call_sid,intent_expires_epoch,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch,transport_kind)
        VALUES (?,?,?,?,?,?,'outbound',?,?,?,?,'dialing',0,?,?,'operator_scheduled')")
        ->execute([$public,(int)$attempt['owner_user_id'],(int)$plan['agent_id'],(int)$plan['number_id'],
            $plan['fields']['From'],$plan['fields']['To'],$sid,$now+(int)$plan['fields']['TimeLimit'],
            (int)$plan['conversation_id'],(int)$plan['chat_agent_id'],$now,$now]);
    $pdo->prepare('UPDATE phone_operator_carrier_attempts_v1 SET call_public_id=? WHERE owner_user_id=? AND event_id=? AND start_epoch=?')
        ->execute([$public,(int)$attempt['owner_user_id'],(int)$attempt['event_id'],(int)$attempt['start_epoch']]);
    $q->execute([$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);
    if(function_exists('vp3_phone_workspace_delivery_v1')){
        $delivery=vp3_phone_workspace_delivery_v1($pdo,$call,'call','call:'.$public,'Scheduled Operator call submitted.');
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET chat_message_id=? WHERE id=?')->execute([(int)$delivery['message_id'],(int)$call['id']]);
    }
    return $call;
}

function vp3_operator_recording_saved_policy_v1(PDO $pdo,array $call,string $record):array
{
    if(($call['transport_kind']??'')!=='operator_scheduled')throw new RuntimeException('Scheduled recording source is invalid.',409);
    $q=$pdo->prepare("SELECT a.*,o.reason,o.state occurrence_state FROM phone_operator_carrier_attempts_v1 a
        JOIN phone_operator_occurrences_v1 o ON o.owner_user_id=a.owner_user_id AND o.event_id=a.event_id AND o.start_epoch=a.start_epoch
        WHERE a.owner_user_id=? AND a.provider_sid=? AND a.call_public_id=?");
    $q->execute([(int)$call['owner_user_id'],(string)$call['provider_call_sid'],(string)$call['public_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $consented=in_array((string)($row['reason']??''),['answer_consented','conversation_active','conversation_completed','conversation_silent',
        'conversation_reply_withheld','conversation_model_failed','conversation_turn_limit','conversation_ended'],true);
    if(!$row||!$consented||(int)$row['recording_started_epoch']<1
        ||!in_array($row['recording_state'],['started','received'],true)||!is_string($row['recording_sid'])
        ||!hash_equals($row['recording_sid'],$record))throw new RuntimeException('Scheduled recording consent or source changed.',403);
    $policy=vp3_phone_recording_decode_v1($row['recording_policy_json']);
    if(empty($policy['enabled'])||empty($policy['retain_audio'])||empty($policy['transcribe']))
        throw new RuntimeException('Scheduled recording policy is unavailable.',409);
    return $policy;
}

/** Start exactly once after the called party presses 1. Carrier I/O is outside all locks. */
function vp3_operator_recording_start_v1(PDO $pdo,int $owner,string $sid,int $now,?callable $carrier=null):array
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Scheduled recording start requires a committed consent receipt.');
    $snapshot=section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$sid,$now){
        $attempt=vp3_operator_recording_attempt_v1($pdo,$owner,$sid,true);
        if(!$attempt||$attempt['state']!=='submitted'||$attempt['recording_state']!=='none')return ['state'=>$attempt['recording_state']??'unavailable'];
        $q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$attempt['event_id'],$attempt['start_epoch']]);$occurrence=$q->fetch(PDO::FETCH_ASSOC);
        if(!$occurrence||$occurrence['state']!=='submitted'||$occurrence['reason']!=='answer_consented')return ['state'=>'unavailable'];
        $current=vp3_operator_current_request_v1($pdo,$occurrence,$now,$sid);
        if($current['reason']!=='eligible')return ['state'=>'unavailable'];
        $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND provider_call_sid=? AND transport_kind='operator_scheduled'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call)return ['state'=>'unavailable'];
        $user=vp3_phone_recording_owner_v1($pdo,$owner);$policy=vp3_phone_recording_policy_v1($pdo,$user,(int)$call['agent_id']);
        if(empty($policy['enabled'])||empty($policy['retain_audio'])||empty($policy['transcribe'])||(int)$policy['revision']<1)return ['state'=>'unavailable'];
        $pdo->prepare("UPDATE phone_operator_carrier_attempts_v1 SET recording_state='starting',recording_policy_json=?,recording_started_epoch=?
            WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND recording_state='none'")
            ->execute([vp3_phone_recording_json_v1($policy),$now,$owner,$attempt['event_id'],$attempt['start_epoch']]);
        $pdo->prepare("UPDATE phone_browser_calls_v1 SET state='answered',updated_epoch=? WHERE id=?")->execute([$now,$call['id']]);
        return ['state'=>'starting','claimed'=>true,'account'=>(string)vp3_phone_twilio_state_v100()['account_sid']];
    });
    if(empty($snapshot['claimed']))return $snapshot;
    $carrier??='vp3_phone_twilio_http_v100';$account=$snapshot['account'];$result=null;
    try{$result=$carrier('POST','/2010-04-01/Accounts/'.$account.'/Calls/'.$sid.'/Recordings.json',[
        'RecordingChannels'=>'dual','RecordingTrack'=>'both','Trim'=>'do-not-trim','PlayBeep'=>'true',
        'RecordingStatusCallback'=>vp3_phone_browser_callback_v1('operator_answer',['event'=>'recording']),
        'RecordingStatusCallbackMethod'=>'POST','RecordingStatusCallbackEvent'=>'completed absent']);}
    catch(Throwable){/* A lost mutation response is uncertain and must never be replayed. */}
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$sid,$now,$result,$account){
        $attempt=vp3_operator_recording_attempt_v1($pdo,$owner,$sid,true);if(!$attempt)return ['state'=>'unavailable'];
        if($attempt['recording_state']!=='starting')return ['state'=>$attempt['recording_state']];
        $valid=is_array($result)&&preg_match('/^RE[a-f0-9]{32}$/iD',(string)($result['sid']??''))===1
            &&($result['account_sid']??null)===$account&&($result['call_sid']??null)===$sid
            &&($result['source']??null)==='StartCallRecordingAPI'&&in_array($result['status']??null,['in-progress','processing','completed'],true)
            &&in_array($result['channels']??null,[2,'2'],true);
        if($valid){$q=$pdo->prepare('SELECT 1 FROM phone_operator_carrier_attempts_v1 WHERE recording_sid=? AND provider_sid<>?');$q->execute([$result['sid'],$sid]);$valid=!$q->fetchColumn();}
        $state=$valid?'started':'uncertain';
        $pdo->prepare('UPDATE phone_operator_carrier_attempts_v1 SET recording_sid=?,recording_state=? WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND recording_state=\'starting\'')
            ->execute([$valid?$result['sid']:null,$state,$owner,$attempt['event_id'],$attempt['start_epoch']]);
        if($valid)$pdo->prepare('UPDATE phone_browser_calls_v1 SET recording_enabled=1,updated_epoch=? WHERE owner_user_id=? AND provider_call_sid=?')->execute([$now,$owner,$sid]);
        return ['state'=>$state];
    });
}

/** Accept only the signed terminal receipt bound by the post-consent start response. */
function vp3_operator_recording_event_v1(PDO $pdo,int $owner,string $sid,array $form,int $now):array
{
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$sid,$form,$now){
        $attempt=vp3_operator_recording_attempt_v1($pdo,$owner,$sid,true);$record=$form['RecordingSid']??null;$status=$form['RecordingStatus']??null;
        if(!$attempt||!is_string($record)||preg_match('/^RE[a-f0-9]{32}$/iD',$record)!==1
            ||($form['RecordingSource']??null)!=='StartCallRecordingAPI'||!in_array($status,['completed','absent','failed'],true)
            ||($form['CallSid']??null)!==$sid)return ['accepted'=>false];
        if($status==='completed'&&(($form['RecordingChannels']??null)!=='2'||($form['RecordingTrack']??null)!=='both'))return ['accepted'=>false];
        // A signed terminal callback can beat or recover a lost REST start response.
        if(in_array($attempt['recording_state'],['starting','uncertain'],true)&&$attempt['recording_sid']===null){
            $q=$pdo->prepare('SELECT 1 FROM phone_operator_carrier_attempts_v1 WHERE recording_sid=? AND provider_sid<>?');$q->execute([$record,$sid]);
            if($q->fetchColumn())return ['accepted'=>false];
            $pdo->prepare("UPDATE phone_operator_carrier_attempts_v1 SET recording_sid=?,recording_state='started'
                WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND recording_sid IS NULL AND recording_state IN ('starting','uncertain')")
                ->execute([$record,$owner,$attempt['event_id'],$attempt['start_epoch']]);
            $attempt=vp3_operator_recording_attempt_v1($pdo,$owner,$sid,true);
        }
        if(!in_array($attempt['recording_state'],['started','received'],true)||!is_string($attempt['recording_sid'])
            ||!hash_equals($attempt['recording_sid'],$record))return ['accepted'=>false];
        if($attempt['recording_state']==='received')return ['accepted'=>true,'duplicate'=>true];
        $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND provider_call_sid=? AND transport_kind='operator_scheduled'");
        $q->execute([$owner,$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);if(!$call)return ['accepted'=>false];
        $policy=vp3_operator_recording_saved_policy_v1($pdo,$call,$record);
        $current=vp3_phone_recording_policy_v1($pdo,vp3_phone_recording_owner_v1($pdo,$owner),(int)$call['agent_id']);
        if($policy!==$current)return ['accepted'=>false];
        $pdo->prepare("UPDATE phone_operator_carrier_attempts_v1 SET recording_state='received' WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND recording_state='started'")
            ->execute([$owner,$attempt['event_id'],$attempt['start_epoch']]);
        $pdo->prepare("UPDATE phone_browser_calls_v1 SET state='completed',recording_enabled=1,duration_seconds=?,ended_epoch=?,updated_epoch=? WHERE id=?")
            ->execute([(int)($form['RecordingDuration']??0),$now,$now,$call['id']]);
        $call['recording_enabled']=1;$call['state']='completed';$call['ended_epoch']=$now;$call['source_kind']='browser';
        return vp3_phone_recording_receive_v1($pdo,$call,$form)+['accepted'=>true];
    });
}
