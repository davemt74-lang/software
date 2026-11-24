<?php
declare(strict_types=1);

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
        'state'=>(int)$consent['revoked_epoch']>0?'revoked':(string)$consent['start_state']];
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
