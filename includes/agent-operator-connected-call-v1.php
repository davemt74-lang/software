<?php
declare(strict_types=1);

/**
 * Explicit owner-initiated browser calls to an approved paired phone.
 * Caller ID is not authentication. No native Agent voice, recording, autonomous
 * dispatch, inbound identity inference or alternate-number fallback.
 */
function vp3_connected_phone_window_v1(array $p,int $now):bool
{
    $zone=$p['timezone']??null;$start=$p['window_start']??null;$end=$p['window_end']??null;
    if(!is_string($zone)||!is_string($start)||!is_string($end)
        ||preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$start)!==1
        ||preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$end)!==1||$start>=$end)return false;
    try{$local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($zone))->format('H:i');}
    catch(Throwable){return false;}
    return $local>=$start&&$local<$end;
}

/** Caller owns the account transaction and has already locked the owner row. */
function vp3_connected_phone_bound_v1(PDO $pdo,int $owner,int $agent,int $conversation,string $device,string $profile,int $revision,int $phoneRevision,int $now):array
{
    require_once __DIR__.'/phone-communication-profile-v1.php';
    if(!vp3_phone_communication_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_connected_upgrade',503,'Upgrade Phone Tracking communication profiles.');
    if($owner<1||$agent<0||$conversation<1||$revision<1||$phoneRevision<1)
        vp3_phone_workspace_error_v1('operator_connected_scope',403,'Choose an approved connected phone.');
    try{
        // Exact immutable pairing, separate profile identity and consent revision.
        // Owner, device and profile are locked in the same order as map edits.
        $q=$pdo->prepare('SELECT d.device_status,d.expires_at,p.* FROM vp3_phone_devices_v1 d
            JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=d.owner_user_id AND p.device_id=d.id
            WHERE d.owner_user_id=? AND d.id=? AND p.profile_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$device,$profile]);$p=$q->fetch(PDO::FETCH_ASSOC);
        $q->closeCursor();
        if(!$p||$p['device_status']!=='approved'||(int)$p['expires_at']<=$now
            ||(int)$p['revision']!==$revision||(int)$p['phone_revision']!==$phoneRevision
            ||(int)$p['owner_calls']!==1||(int)$p['holder_calls']!==1
            ||preg_match('/\A\+[1-9][0-9]{7,14}\z/D',(string)$p['phone_e164'])!==1)
            vp3_phone_workspace_error_v1('operator_connected_changed',409,'Phone number or calling permission changed. Review Phone Tracking.');
        $q=$pdo->prepare('SELECT t.device_id FROM vp3_paired_phone_threads_v1 t
            JOIN chat_conversations c ON c.id=t.conversation_id AND c.user_id=t.owner_user_id
                AND COALESCE(c.user_agent_id,0)=t.agent_id
            WHERE t.owner_user_id=? AND t.device_id=? AND t.agent_id=? AND t.conversation_id=?'
                .vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$device,$agent,$conversation]);$linked=$q->fetchColumn();$q->closeCursor();
        if(!$linked)vp3_phone_workspace_error_v1('operator_connected_scope',409,'Open the current linked phone conversation.');
    }catch(PDOException){vp3_phone_workspace_error_v1('operator_connected_upgrade',503,'Connected phone calling is unavailable.');}
    if(!vp3_connected_phone_window_v1($p,$now))
        vp3_phone_workspace_error_v1('operator_connected_quiet_hours',409,'This phone is outside its permitted calling hours.');
    $max=(int)$p['max_calls_per_day'];$gap=(int)$p['min_gap_minutes'];
    if($max<1||$max>3||$gap<120||$gap>10080)
        vp3_phone_workspace_error_v1('operator_connected_changed',409,'Review this phone’s calling limits.');
    return $p;
}
function vp3_connected_phone_prepare_v1(PDO $pdo,array $scope,array $input,int $now):array
{
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)
        vp3_phone_workspace_error_v1('operator_connected_scope',403,'Use your private owner Phone Tracking workspace.');
    $device=$input['device_id']??null;$profile=$input['profile_id']??null;
    if(($input['confirmed']??null)!==true||array_key_exists('to_number',$input)||array_key_exists('recording_enabled',$input)
        ||!is_string($device)||preg_match('/\A[a-f0-9]{32}\z/D',$device)!==1
        ||!is_string($profile)||preg_match('/\A[a-f0-9]{32}\z/D',$profile)!==1)
        vp3_phone_workspace_error_v1('operator_connected_confirmation',422,'Confirm one call to the selected connected phone.');
    $revision=vp3_phone_workspace_id_v1($input['profile_revision']??null);
    $phoneRevision=vp3_phone_workspace_id_v1($input['phone_revision']??null);
    $p=vp3_connected_phone_bound_v1($pdo,$scope['owner_user_id'],$scope['agent_id'],$scope['conversation_id'],
        $device,$profile,$revision,$phoneRevision,$now);
    // Count attempts, including canceled/uncertain reservations. Number edits
    // do not reset a profile's budget. The canonical owner busy/rate gate also applies.
    $q=$pdo->prepare('SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND paired_profile_id=? AND created_epoch>?');
    $q->execute([$scope['owner_user_id'],$profile,$now-(int)$p['min_gap_minutes']*60]);$recent=(int)$q->fetchColumn();$q->closeCursor();
    $q->execute([$scope['owner_user_id'],$profile,$now-86400]);$daily=(int)$q->fetchColumn();$q->closeCursor();
    if($recent||$daily>=(int)$p['max_calls_per_day'])
        vp3_phone_workspace_error_v1('operator_connected_limit',429,'This connected phone’s calling limit has been reached.');
    return ['destination'=>$p['phone_e164'],'device_id'=>$device,'profile_id'=>$profile,'revision'=>$revision,'phone_revision'=>$phoneRevision];
}
/** The carrier callback rechecks permission, pairing, number and current hours. */
function vp3_connected_phone_current_v1(PDO $pdo,array $call,int $now):bool
{
    $profile=(string)($call['paired_profile_id']??'');
    if($profile==='')return true;
    if(!$pdo->inTransaction()||(int)$call['recording_enabled']!==0||$call['direction']!=='outbound')return false;
    try{
        $p=vp3_connected_phone_bound_v1($pdo,(int)$call['owner_user_id'],(int)$call['chat_agent_id'],(int)$call['conversation_id'],
            (string)$call['paired_device_id'],$profile,(int)$call['paired_profile_revision'],(int)$call['paired_phone_revision'],$now);
        return hash_equals((string)$p['phone_e164'],(string)$call['peer_e164']);
    }catch(Throwable){return false;}
}
/** Return a small redacted ledger for the selected approved profile. */
function vp3_connected_phone_recent_v1(PDO $pdo,int $owner,string $device,string $profile):?array
{
    try{
        $q=$pdo->prepare('SELECT public_id,state,created_epoch,updated_epoch,duration_seconds
            FROM phone_browser_calls_v1 WHERE owner_user_id=? AND paired_device_id=? AND paired_profile_id=?
            ORDER BY id DESC LIMIT 5');
        $q->execute([$owner,$device,$profile]);$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[]=['call_id'=>(string)$r['public_id'],'state'=>(string)$r['state'],
            'created_epoch'=>(int)$r['created_epoch'],'updated_epoch'=>(int)$r['updated_epoch'],'duration_seconds'=>max(0,min(86400,(int)$r['duration_seconds']))];
        return $out;
    }catch(PDOException){return null;}
}
