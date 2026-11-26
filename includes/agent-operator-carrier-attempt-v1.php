<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-dispatch-preview-v1.php';
require_once __DIR__.'/phone-managed-transport-v1.php';
require_once __DIR__.'/agent-operator-recording-v1.php';

/** Submit one prepared occurrence through the supplied carrier adapter. */
function vp3_operator_carrier_attempt_v1(PDO $pdo,int $owner,int $series,int $start,callable $carrier,?int $clock=null,?callable $sourceHttp=null):string
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Carrier submission requires its own committed reservation.');
    $key=[$owner,$series,$start];
    $where='owner_user_id=? AND event_id=? AND start_epoch=?';
    $reserved=section12_owner_transaction($pdo,$owner,static function()use($pdo,$key,$where,$clock){
        $prior=$pdo->prepare("SELECT state FROM phone_operator_carrier_attempts_v1 WHERE ".$where);
        $prior->execute($key);
        if(($state=$prior->fetchColumn())!==false)return ['existing'=>$state];
        $query=$pdo->prepare("SELECT * FROM phone_operator_occurrences_v1 WHERE ".$where);
        $query->execute($key);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['existing'=>'unavailable'];
        $now=$clock??time();
        $plan=vp3_operator_preview_request_v1($pdo,$row,$now);
        if($plan['reason']!=='eligible')return ['existing'=>$plan['reason']];
        $pdo->prepare("INSERT INTO phone_operator_carrier_attempts_v1(owner_user_id,event_id,start_epoch,started_epoch,state,destination_hash)
            VALUES(?,?,?,?,'submitting',?)")->execute([...$key,$now,hash('sha256',$plan['fields']['To'])]);
        $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET state='submitting',reason='carrier_submitting' WHERE ".$where)->execute($key);
        if(isset($plan['owner_email_request_id']))vp3_operator_owner_email_sync_v1($pdo,$key[0],$key[1],$key[2],$now);
        return ['plan'=>$plan,'account'=>$plan['account_sid']];
    });
    if(isset($reserved['existing']))return $reserved['existing'];
    // The committed claim also fences another worker while this request is in flight.
    $sid=null;$veto=null;
    if(isset($reserved['plan']['owner_email_request_id'])){
        try{$reserved['plan']=vp3_operator_owner_email_transport_current_v1($pdo,$reserved['plan'],$clock,$sourceHttp);}
        catch(VP3PhoneAgentEmailCallException $error){$veto=vp3_operator_owner_email_reason_v1($error->reason);}
        catch(Throwable){$veto='source_unavailable';}
    }
    if($veto===null){
        try{
            $reply=$carrier($reserved['plan']);
            $fields=$reserved['plan']['fields'];
            if(is_array($reply)&&vp3_managed_transport_sid_v1($reply['sid']??null)
                &&($reply['account_sid']??null)===$reserved['account']&&($reply['from']??null)===$fields['From']
                &&($reply['to']??null)===$fields['To'])$sid=$reply['sid'];
        }catch(Throwable){ /* A timeout can arrive after carrier acceptance. Keep the claim. */ }
    }
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$sid,$key,$where,$reserved,$clock,$veto){
        $state=$veto!==null?'blocked':($sid===null?'uncertain':'submitted');
        $update=$pdo->prepare("UPDATE phone_operator_carrier_attempts_v1 SET state=?,provider_sid=? WHERE ".$where." AND state='submitting'");
        try{
            $update->execute([$state,$sid,...$key]);
        }catch(PDOException $error){
            if($error->getCode()!=='23000'||$sid===null)throw $error;
            $state='uncertain';
            $update->execute([$state,null,...$key]);
        }
        $pdo->prepare('UPDATE phone_operator_occurrences_v1 SET state=?,reason=? WHERE '.$where." AND state='submitting'")
            ->execute([$state,$veto??'carrier_'.$state,...$key]);
        if($state==='submitted'){
            $attempt=vp3_operator_recording_attempt_v1($pdo,$key[0],$sid,true);
            vp3_operator_recording_bind_call_v1($pdo,$attempt,$reserved['plan'],$clock??time());
        }
        if(isset($reserved['plan']['owner_email_request_id']))vp3_operator_owner_email_sync_v1($pdo,$key[0],$key[1],$key[2],$clock??time());
        return $state;
    });
}
