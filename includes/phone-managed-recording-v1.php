<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Managed recording is a separate capability, off by default. */
require_once __DIR__.'/phone-managed-recording-schema-v1.php';
require_once __DIR__.'/phone-managed-callback-v1.php';
require_once __DIR__.'/phone-recording-pipeline-v1.php';

function vp3_managed_recording_enabled_v1():bool
{
    return (string)setting('phone_managed_recording_enabled_v1','0')==='1';
}
function vp3_managed_recording_row_v1(PDO $pdo,string $id,bool $lock=false):?array
{
    if(!vp3_managed_recording_ready_v1($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM phone_managed_recording_consent_v1 WHERE request_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
/** Snapshot is exact: later owner policy edits cannot expand consent. */
function vp3_managed_recording_policy_v1(PDO $pdo,array $user,array $number,bool $lock=false):?array
{
    if(!vp3_managed_recording_ready_v1($pdo)||!vp3_phone_recording_schema_ready_v1($pdo)||empty($number['recording_enabled']))return null;
    if($lock){
        $q=$pdo->prepare('SELECT revision FROM phone_recording_policies_v1 WHERE owner_user_id=? AND agent_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([(int)$user['id'],(int)$number['agent_id']]);$q->fetchColumn();$q->closeCursor();
    }
    $p=vp3_phone_recording_policy_v1($pdo,$user,(int)$number['agent_id']);
    // This initial integration requires retained audio. The existing playback
    // path must not bypass a no-retention policy for a managed call.
    if(!$p['enabled']||!$p['retain_audio']||(int)$p['revision']<1||($p['analyze']&&!$p['transcribe']))return null;
    if(!has_permission('artist_listening.access',$user))return null;
    if(($p['transcribe']||$p['analyze'])&&!subscription_has_entitlement($user,'main_ai.access'))return null;
    return $p;
}
function vp3_managed_recording_view_v1(?array $consent):array
{
    if(!$consent)return ['requested'=>false];
    $p=vp3_phone_recording_decode_v1($consent['policy_json']);
    return ['requested'=>true,'revision'=>(int)$consent['consent_revision'],'policy_revision'=>(int)$consent['policy_revision'],
        'retain_audio'=>!empty($p['retain_audio']),'transcribe'=>!empty($p['transcribe']),'analyze'=>!empty($p['analyze']),
        'holder_consented'=>(int)$consent['holder_epoch']>0,'parent_consented'=>(int)$consent['parent_epoch']>0,
        'destination_consented'=>(int)$consent['child_epoch']>0,
        'state'=>(int)$consent['revoked_epoch']>0?'revoked':(string)$consent['start_state'],
        'stop_state'=>(string)($consent['stop_state']??'none')];
}
function vp3_managed_recording_request_v1(PDO $pdo,array $row,array $user,array $number,array $input,int $now):void
{
    $want=$input['recording_requested']??false;
    vp3_managed_callback_need_v1(is_bool($want),422);
    if(!$want){
        vp3_managed_callback_need_v1(!isset($input['recording_policy_revision']),422);
        return;
    }
    $p=vp3_managed_recording_policy_v1($pdo,$user,$number,true);
    vp3_managed_callback_need_v1(is_array($p)&&($input['recording_policy_revision']??null)===$p['revision'],409);
    $pdo->prepare('INSERT INTO phone_managed_recording_consent_v1(request_id,owner_user_id,policy_json,policy_revision,owner_epoch,updated_epoch) VALUES (?,?,?,?,?,?)')
        ->execute([$row['public_id'],(int)$row['owner_user_id'],vp3_phone_recording_json_v1($p),$p['revision'],$now,$now]);
}
function vp3_managed_recording_current_v1(PDO $pdo,array $row,array $c,array $authority):array
{
    vp3_managed_callback_need_v1($c['request_id']===$row['public_id']&&(int)$c['owner_user_id']===(int)$row['owner_user_id']
        &&(int)$c['owner_epoch']>0&&(int)$c['revoked_epoch']===0);
    $p=vp3_managed_recording_policy_v1($pdo,$authority['scope']['user'],$authority['number'],true);
    vp3_managed_callback_need_v1(is_array($p)&&(int)$c['policy_revision']===$p['revision']&&$p===vp3_phone_recording_decode_v1($c['policy_json']));
    return $p;
}
/** Holder explicitly accepts the exact policy, in addition to the destination. */
function vp3_managed_recording_holder_v1(PDO $pdo,array $row,array $authority,array $input,int $now):void
{
    $c=vp3_managed_recording_row_v1($pdo,$row['public_id'],true);
    if(!$c){vp3_managed_callback_need_v1(!isset($input['recording_consent_revision'])&&!isset($input['recording_consent']),422);return;}
    vp3_managed_recording_current_v1($pdo,$row,$c,$authority);
    vp3_managed_callback_need_v1(($input['recording_consent']??null)===true&&($input['recording_consent_revision']??null)===(int)$c['consent_revision'],422);
    if((int)$c['holder_epoch']===0)$pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET holder_epoch=?,updated_epoch=? WHERE request_id=? AND holder_epoch=0')
        ->execute([$now,$now,$row['public_id']]);
}
/** Do not let a recording-requested call silently fall back to an unrecorded mode. */
function vp3_managed_recording_parent_v1(PDO $pdo,array $row,array $authority,bool $confirmed,int $now):?array
{
    $c=vp3_managed_recording_row_v1($pdo,$row['public_id'],true);if(!$c)return null;
    vp3_managed_callback_need_v1(vp3_managed_recording_enabled_v1()&&(int)$c['holder_epoch']>0);
    $p=vp3_managed_recording_current_v1($pdo,$row,$c,$authority);
    if($confirmed){
        vp3_managed_callback_need_v1((int)$c['parent_epoch']===0);
        $q=$pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET parent_epoch=?,updated_epoch=? WHERE request_id=? AND parent_epoch=0 AND revoked_epoch=0');
        $q->execute([$now,$now,$row['public_id']]);vp3_managed_callback_need_v1($q->rowCount()===1);
    }
    return $p;
}
function vp3_managed_recording_notice_v1(array $policy):string
{
    return 'This managed call requests recording and private audio storage by the VP3 account owner.'
        .(!empty($policy['transcribe'])?' Cloud AI will transcribe the conversation.':'')
        .(!empty($policy['analyze'])?' Cloud AI will create a conversation report.':'')
        .' Press 1 to agree and continue. Otherwise hang up. You can end the call at any time.';
}
/** Signed Number whisper: the called participant consents BEFORE recording admission. */
function vp3_managed_recording_child_v1(PDO $pdo,string $event,array $row,array $t,array $call,string $sid,string $nonce,array $form,int $now):array
{
    $hangup=['accepted'=>false,'xml'=>vp3_phone_browser_hangup_v1()];
    if(!vp3_managed_recording_enabled_v1()||!vp3_managed_transport_enabled_v1()||$row['state']!=='dispatch_claimed'||(int)$row['expires_epoch']<=$now
        ||(int)$t['confirmation_epoch']===0||$t['parent_state']!==''||!in_array($call['state'],['dialing','ringing','answered'],true)
        ||!vp3_managed_transport_sid_v1($call['provider_call_sid'])||$sid===$call['provider_call_sid']
        ||!hash_equals($row['line_e164'],(string)($form['From']??''))||!hash_equals($row['destination_e164'],(string)($form['To']??'')))return $hangup;
    if($call['provider_child_sid']){
        if(!hash_equals((string)$call['provider_child_sid'],$sid))return $hangup;
        if(isset($form['ParentCallSid'])&&!hash_equals((string)$call['provider_call_sid'],(string)$form['ParentCallSid']))return $hangup;
    }elseif(!isset($form['ParentCallSid'])||!hash_equals((string)$call['provider_call_sid'],(string)$form['ParentCallSid']))return $hangup;
    $c=vp3_managed_recording_row_v1($pdo,$row['public_id'],true);
    if(!$c||(int)$c['holder_epoch']===0||(int)$c['parent_epoch']===0||($c['child_sid']!==null&&!hash_equals($c['child_sid'],$sid)))return $hangup;
    try{
        $live=vp3_managed_callback_owner_lock_v1($pdo,(int)$row['owner_user_id']);
        $a=vp3_managed_callback_current_v1($pdo,$row,$live,$now);$p=vp3_managed_recording_current_v1($pdo,$row,$c,$a);
    }catch(VP3PhoneWorkspaceException){return $hangup;}
    if(!$call['provider_child_sid']){
        $q=$pdo->prepare('SELECT id FROM phone_browser_calls_v1 WHERE (provider_call_sid=? OR provider_child_sid=?) AND id<>?');
        $q->execute([$sid,$sid,$call['id']]);$other=$q->fetchColumn();$q->closeCursor();if($other!==false)return $hangup;
        $pdo->prepare('UPDATE phone_browser_calls_v1 SET provider_child_sid=? WHERE id=? AND provider_child_sid IS NULL')->execute([$sid,$call['id']]);
    }
    if($event==='record_notice'){
        if(!in_array($c['start_state'],['requested','awaiting_destination'],true)||(int)$c['child_epoch']!==0)return $hangup;
        $pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET child_sid=?,start_state='awaiting_destination',updated_epoch=? WHERE request_id=? AND revoked_epoch=0")->execute([$sid,$now,$row['public_id']]);
        $url=vp3_phone_browser_xml_v1(vp3_managed_transport_url_v1('record_consent',$call['public_id'],$nonce));
        $notice=vp3_phone_browser_xml_v1(vp3_managed_recording_notice_v1($p));
        return ['accepted'=>true,'xml'=>'<?xml version="1.0" encoding="UTF-8"?><Response><Gather input="dtmf" numDigits="1" timeout="8" actionOnEmptyResult="true" action="'.$url.'" method="POST"><Say>'.$notice.'</Say></Gather><Hangup/></Response>'];
    }
    if($event!=='record_consent'||$c['start_state']!=='awaiting_destination'||$c['child_sid']!==$sid||(int)$c['child_epoch']!==0)return $hangup;
    if(($form['Digits']??null)!=='1'){
        vp3_managed_recording_revoke_v1($pdo,$row['public_id'],$now);return $hangup;
    }
    $q=$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET child_epoch=?,start_state='consented',updated_epoch=? WHERE request_id=? AND child_epoch=0 AND revoked_epoch=0");
    $q->execute([$now,$now,$row['public_id']]);if($q->rowCount()!==1)return $hangup;
    // Returning from the whisper allows Twilio to connect the parties. It does
    // not itself start recording. Start admission separately verifies both legs.
    return ['accepted'=>true,'xml'=>'<?xml version="1.0" encoding="UTF-8"?><Response></Response>'];
}


function vp3_managed_recording_sid_v1(mixed $sid):bool
{
    return is_string($sid)&&preg_match('/^RE[a-f0-9]{32}$/iD',$sid)===1;
}
/** Read-only verification and one durable start claim. Never start on a whisper alone. */
function vp3_managed_recording_start_snapshot_v1(PDO $pdo,string $public,string $nonce,int $now,string $expected):array
{
    vp3_managed_callback_need_v1(vp3_managed_recording_enabled_v1()&&vp3_managed_transport_enabled_v1(),503);
    $hint=vp3_managed_transport_row_v1($pdo,$public);
    vp3_managed_callback_need_v1(is_array($hint)&&hash_equals($hint['callback_nonce_hash'],hash('sha256',$nonce)),403);
    $owner=(int)$hint['owner_user_id'];$live=vp3_managed_callback_owner_lock_v1($pdo,$owner);
    $t=vp3_managed_transport_row_v1($pdo,$public,true);$call=vp3_managed_transport_call_v1($pdo,$public,true);
    vp3_managed_callback_need_v1(is_array($t)&&is_array($call)&&(int)$call['owner_user_id']===$owner&&$call['state']==='answered'
        &&$t['state']==='bridging'&&$t['parent_state']===''&&hash_equals($t['callback_nonce_hash'],hash('sha256',$nonce)));
    $row=vp3_managed_callback_row_v1($pdo,$t['request_id'],true);$c=vp3_managed_recording_row_v1($pdo,$t['request_id'],true);
    vp3_managed_callback_need_v1(is_array($row)&&is_array($c)&&$row['state']==='dispatch_claimed'&&(int)$row['expires_epoch']>$now
        &&$c['start_state']===$expected&&(int)$c['holder_epoch']>0&&(int)$c['parent_epoch']>0&&(int)$c['child_epoch']>0
        &&(int)$c['start_epoch']===0&&vp3_managed_transport_sid_v1($call['provider_call_sid'])&&vp3_managed_transport_sid_v1($call['provider_child_sid'])
        &&hash_equals((string)$c['child_sid'],(string)$call['provider_child_sid']));
    $a=vp3_managed_callback_current_v1($pdo,$row,$live,$now);
    vp3_managed_recording_current_v1($pdo,$row,$c,$a);
    return ['call'=>$call,'transport'=>$t,'request'=>$row,'consent'=>$c];
}
function vp3_managed_recording_bind_sid_v1(PDO $pdo,array $c,string $sid,int $now):bool
{
    if(!vp3_managed_recording_sid_v1($sid))return false;
    if($c['recording_sid'])return hash_equals((string)$c['recording_sid'],$sid);
    $q=$pdo->prepare('SELECT request_id FROM phone_managed_recording_consent_v1 WHERE recording_sid=?');$q->execute([$sid]);$other=$q->fetchColumn();$q->closeCursor();
    if($other!==false)return false;
    $q=$pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET recording_sid=?,updated_epoch=? WHERE request_id=? AND recording_sid IS NULL');
    $q->execute([$sid,$now,$c['request_id']]);return $q->rowCount()===1;
}
/**
 * Internal server-only start. No web/user/worker entry point invokes it yet.
 * A future real-time driver must call it outside the carrier webhook transaction.
 */
function vp3_managed_recording_start_v1(PDO $pdo,string $public,string $nonce,?callable $carrier=null,?int $clock=null):array
{
    require_once __DIR__.'/phone-managed-transport-v1.php';
    require_once __DIR__.'/phone-managed-control-v1.php';
    vp3_managed_callback_need_v1(!vp3_phone_transaction_active_v1($pdo));
    vp3_managed_callback_id_v1($public);vp3_managed_transport_nonce_v1($nonce);
    $now=$clock??time();
    $snapshot=vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$public,$nonce,$now){
        $s=vp3_managed_recording_start_snapshot_v1($pdo,$public,$nonce,$now,'consented');
        $q=$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET start_state='checking',updated_epoch=? WHERE request_id=? AND start_state='consented'");
        $q->execute([$now,$s['request']['public_id']]);vp3_managed_callback_need_v1($q->rowCount()===1);return $s;
    });
    $call=$snapshot['call'];$request=$snapshot['request'];$id=$request['public_id'];
    $carrier=$carrier??'vp3_phone_twilio_http_v100';$account=(string)vp3_phone_twilio_state_v100()['account_sid'];
    $result=null;$attempted=false;
    try{
        $parent=vp3_managed_control_evidence_v1($carrier('GET',vp3_managed_control_resource_v1($account,$call['provider_call_sid']),[]),$account,$call);
        $child=vp3_managed_control_evidence_v1($carrier('GET',vp3_managed_control_resource_v1($account,$call['provider_child_sid']),[]),$account,$call,$request);
        if($parent&&$child&&$parent['state']==='answered'&&$child['state']==='answered'){
            vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$public,$nonce,$clock,$call,$id){
                $now=$clock??time();$s=vp3_managed_recording_start_snapshot_v1($pdo,$public,$nonce,$now,'checking');
                vp3_managed_callback_need_v1($s['call']['provider_call_sid']===$call['provider_call_sid']&&$s['call']['provider_child_sid']===$call['provider_child_sid']);
                $q=$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET start_state='starting',start_epoch=?,updated_epoch=? WHERE request_id=? AND start_state='checking' AND revoked_epoch=0");
                $q->execute([$now,$now,$id]);vp3_managed_callback_need_v1($q->rowCount()===1);return true;
            });
            $attempted=true;
            $result=$carrier('POST','/2010-04-01/Accounts/'.$account.'/Calls/'.$call['provider_call_sid'].'/Recordings.json',[
                'RecordingChannels'=>'dual','RecordingTrack'=>'both','Trim'=>'do-not-trim','PlayBeep'=>'true',
                'RecordingStatusCallback'=>vp3_managed_transport_url_v1('recording',$public,$nonce),
                'RecordingStatusCallbackMethod'=>'POST','RecordingStatusCallbackEvent'=>'completed absent']);
        }
    }catch(Throwable){ /* A lost mutation response is uncertain, never replayed. */ }
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$snapshot,$id,$account,$result,$attempted,$clock){
        $now=$clock??time();$call=$snapshot['call'];vp3_managed_callback_need_v1(vp3_managed_transport_owner_lock_v1($pdo,(int)$call['owner_user_id']));
        $c=vp3_managed_recording_row_v1($pdo,$id,true);vp3_managed_callback_need_v1(is_array($c));
        if(!in_array($c['start_state'],['checking','starting'],true))return vp3_managed_recording_view_v1($c);
        $valid=is_array($result)&&vp3_managed_recording_sid_v1($result['sid']??null)
            &&($result['account_sid']??null)===$account&&($result['call_sid']??null)===$call['provider_call_sid']
            &&($result['source']??null)==='StartCallRecordingAPI'&&in_array($result['status']??null,['in-progress','processing','completed'],true)
            &&in_array($result['channels']??null,[2,'2'],true);
        $bound=$attempted&&$valid&&vp3_managed_recording_bind_sid_v1($pdo,$c,$result['sid'],$now);
        $state=$bound?'started':($attempted?'uncertain':'not_started');
        $pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET start_state=?,updated_epoch=? WHERE request_id=?')->execute([$state,$now,$id]);
        if($bound)$pdo->prepare("UPDATE phone_browser_calls_v1 SET recording_enabled=1 WHERE public_id=? AND transport_kind='managed_callback'")->execute([$call['public_id']]);
        return vp3_managed_recording_view_v1(vp3_managed_recording_row_v1($pdo,$id,true));
    });
}
/** Only a signed, exact parent receipt can enqueue a consented recording. */
function vp3_managed_recording_event_v1(PDO $pdo,array $row,array $t,array $call,string $sid,array $form,int $now):array
{
    $reject=['accepted'=>false,'xml'=>vp3_phone_browser_hangup_v1()];
    $c=vp3_managed_recording_row_v1($pdo,$row['public_id'],true);$record=$form['RecordingSid']??null;$status=$form['RecordingStatus']??null;
    if(!$c||(int)$c['owner_user_id']!==(int)$call['owner_user_id']
        ||(int)$c['holder_epoch']===0||(int)$c['parent_epoch']===0||(int)$c['child_epoch']===0||(int)$c['start_epoch']===0
        ||!hash_equals((string)$call['provider_call_sid'],$sid)||!hash_equals((string)$call['provider_child_sid'],(string)$c['child_sid'])
        ||!in_array($c['start_state'],['starting','started','uncertain','received','absent','discard_required'],true)||!vp3_managed_recording_sid_v1($record)
        ||($form['RecordingSource']??null)!=='StartCallRecordingAPI'||!in_array($status,['completed','absent','failed'],true))return $reject;
    if($status==='completed'&&(($form['RecordingChannels']??null)!=='2'||($form['RecordingTrack']??null)!=='both'
        ||!is_string($form['RecordingDuration']??null)||preg_match('/^\d{1,3}$/D',$form['RecordingDuration'])!==1||(int)$form['RecordingDuration']<1||(int)$form['RecordingDuration']>240))return $reject;
    if((int)$c['revoked_epoch']>0){
        // A signed terminal receipt may close a withdrawn recording, but may not enqueue processing.
        if(!vp3_managed_recording_bind_sid_v1($pdo,$c,$record,$now))return $reject;
        $pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET start_state='discard_required',stop_state='terminal_verified',updated_epoch=? WHERE request_id=?")->execute([$now,$row['public_id']]);
        return ['accepted'=>true,'queued'=>false,'xml'=>vp3_phone_browser_hangup_v1()];
    }
    if(in_array($c['start_state'],['received','absent'],true)){
        if(!$c['recording_sid']||!hash_equals($c['recording_sid'],$record))return $reject;
        return ['accepted'=>true,'duplicate'=>true,'xml'=>vp3_phone_browser_hangup_v1()];
    }
    // Current owner and the exact original policy must still authorize ingestion.
    $user=vp3_managed_callback_owner_lock_v1($pdo,(int)$call['owner_user_id']);
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>(int)$call['chat_agent_id'],'conversation_id'=>(int)$call['conversation_id']]);
    $n=vp3_phone_browser_require_v1($pdo,$scope,(int)$call['phone_number_id'],true);
    if((int)$n['agent_id']!==(int)$call['agent_id']||$n['phone_e164']!==$call['phone_e164'])return $reject;
    vp3_managed_recording_current_v1($pdo,$row,$c,['scope'=>$scope,'number'=>$n]);
    if(!vp3_managed_recording_bind_sid_v1($pdo,$c,$record,$now))return $reject;
    $state=$status==='completed'?'received':'absent';
    $pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET start_state=?,updated_epoch=? WHERE request_id=?')->execute([$state,$now,$row['public_id']]);
    $pdo->prepare("UPDATE phone_browser_calls_v1 SET recording_enabled=1 WHERE public_id=? AND transport_kind='managed_callback'")->execute([$call['public_id']]);
    $call['recording_enabled']=1;
    $saved=vp3_phone_recording_receive_v1($pdo,$call,$form);
    return $saved+['xml'=>vp3_phone_browser_hangup_v1()];
}
/** Reused by the existing pipeline. Consent snapshot cannot be replaced on retry. */
function vp3_managed_recording_saved_policy_v1(PDO $pdo,array $call,string $record):array
{
    require_once __DIR__.'/phone-managed-transport-v1.php';
    $t=vp3_managed_transport_row_v1($pdo,$call['public_id']);$c=$t?vp3_managed_recording_row_v1($pdo,$t['request_id']):null;
    if(!$c||(int)$c['owner_user_id']!==(int)$call['owner_user_id']||(int)$c['revoked_epoch']>0
        ||(int)$c['holder_epoch']===0||(int)$c['parent_epoch']===0||(int)$c['child_epoch']===0||(int)$c['start_epoch']===0
        ||!in_array($c['start_state'],['received','absent'],true)||!vp3_managed_recording_sid_v1($record)
        ||!hash_equals((string)$c['recording_sid'],$record)||!hash_equals((string)$c['child_sid'],(string)$call['provider_child_sid']))
        throw new RuntimeException('Managed recording consent or source changed.',403);
    return vp3_phone_recording_decode_v1($c['policy_json']);
}
