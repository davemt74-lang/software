<?php
declare(strict_types=1);
require_once __DIR__.'/phone-browser-v1.php';
require_once __DIR__.'/agent-operator-dispatch-preview-v1.php';
require_once __DIR__.'/agent-operator-conversation-v1.php';
require_once __DIR__.'/agent-operator-recording-v1.php';

/** The answer URL is a public carrier callback. Only the accepted SID may change its own receipt. */
function vp3_operator_answer_v1(PDO $pdo,string $event,array $form,string $signature,int $now,?callable $generator=null,?callable $recordingCarrier=null):string
{
    $url=static fn(string $step)=>vp3_phone_browser_callback_v1('operator_answer',['event'=>$step]);
    if(!in_array($event,['answer','retry','last','confirm','status','recording'],true)&&!preg_match('/^turn[1-8]$/D',$event))throw new InvalidArgumentException('Unknown answer event.');
    $sid=vp3_phone_browser_verify_v1($form,$signature,$url($event));
    $query=$pdo->prepare("SELECT owner_user_id FROM phone_operator_carrier_attempts_v1 WHERE provider_sid=? AND state='submitted'");
    $query->execute([$sid]);
    $owner=$query->fetchColumn();
    $query->closeCursor();
    $hangup=vp3_phone_browser_hangup_v1();
    if($owner===false){
        if($event==='status')throw new RuntimeException('Submission receipt is unavailable.');
        // An answer can beat the REST response that binds this SID. Give it two seconds to commit.
        if($event==='answer'||$event==='retry'){
            $next=$event==='answer'?'retry':'last';
            return '<Response><Pause length="1"/><Redirect method="POST">'.vp3_phone_browser_xml_v1($url($next)).'</Redirect></Response>';
        }
        return $hangup;
    }
    if(preg_match('/^turn([1-8])$/D',$event,$match))
        return vp3_operator_conversation_turn_v1($pdo,(int)$owner,$sid,(int)$match[1],$form,$now,$generator);
    if($event==='recording'){
        vp3_operator_recording_event_v1($pdo,(int)$owner,$sid,$form,$now);
        return $hangup;
    }
    $response=section12_owner_transaction($pdo,(int)$owner,static function()use($pdo,$owner,$sid,$event,$form,$now,$url,$hangup){
        $query=$pdo->prepare("SELECT o.* FROM phone_operator_occurrences_v1 o JOIN phone_operator_carrier_attempts_v1 a
            ON a.owner_user_id=o.owner_user_id AND a.event_id=o.event_id AND a.start_epoch=o.start_epoch
            WHERE a.owner_user_id=? AND a.provider_sid=? AND a.state='submitted' AND o.state='submitted'");
        $query->execute([$owner,$sid]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row)return $hangup;
        if($event==='status'){
            $outcomes=['busy'=>'carrier_busy','failed'=>'carrier_failed','no-answer'=>'carrier_no_answer',
                'canceled'=>'carrier_canceled','completed'=>'ended_before_consent'];
            $status=$form['CallStatus']??'';
            if(!isset($outcomes[$status]))return $hangup;
            $reason=in_array($row['reason'],['carrier_submitted','answer_prompted'],true)?$outcomes[$status]:$row['reason'];
            $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET state='ended',reason=? WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'")
                ->execute([$reason,$owner,$row['event_id'],$row['start_epoch']]);
            if(!empty($row['event_id']))$pdo->prepare("UPDATE phone_browser_calls_v1 SET state=?,ended_epoch=?,updated_epoch=? WHERE owner_user_id=? AND provider_call_sid=? AND transport_kind='operator_scheduled'")
                ->execute([$status==='completed'?'completed':str_replace('-','_',$status),$now,$now,$owner,$sid]);
            return $hangup;
        }
        if(!in_array($row['reason'],['carrier_submitted','answer_prompted'],true))return $hangup;
        if($event==='confirm'&&$row['reason']!=='answer_prompted')return $hangup;
        $update=$pdo->prepare("UPDATE phone_operator_occurrences_v1 SET reason=? WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted' AND reason=?");
        $save=static fn(string $reason)=>$update->execute([$reason,$owner,$row['event_id'],$row['start_epoch'],$row['reason']]);
        try{$current=vp3_operator_current_request_v1($pdo,$row,$now,$sid);}
        catch(VP3PhoneWorkspaceException){$current=['reason'=>'access_changed'];}
        if($current['reason']!=='eligible'){
            $save('answer_permission_changed');
            return $hangup;
        }
        if(($form['From']??null)!==$current['fields']['From']||($form['To']??null)!==$current['fields']['To'])return $hangup;
        if($event!=='confirm'){
            if($row['reason']==='carrier_submitted')$save('answer_prompted');
            return '<Response><Gather input="dtmf" numDigits="1" timeout="8" actionOnEmptyResult="true" method="POST" action="'.
                vp3_phone_browser_xml_v1($url('confirm')).'"><Say>This is the VP3 AI phone agent calling for your scheduled conversation. '.
                'To agree to speak with the agent and have this conversation recorded and transcribed, press 1. To decline, press any other key or hang up.</Say></Gather><Hangup/></Response>';
        }
        $digit=$form['Digits']??'';
        $save($digit==='1'?'answer_consented':($digit===''?'answer_no_input':'answer_declined'));
        return $digit==='1'?vp3_operator_conversation_gather_v1('Thank you. How can I help with today’s scheduled conversation?',1):$hangup;
    });
    if($event==='confirm'&&($form['Digits']??null)==='1'&&str_contains($response,'input="speech"')){
        $recording=vp3_operator_recording_start_v1($pdo,(int)$owner,$sid,$now,$recordingCarrier);
        if(($recording['state']??'')!=='started')return $hangup;
    }
    return $response;
}
