<?php
declare(strict_types=1);

/**
 * PS5E.8: explicit, temporary, narrowly scoped live carrier acceptance.
 * This configuration is set by the deployment administrator, not a member,
 * paired phone, model, request payload, or automatically observed test result.
 */
function vp3_managed_pilot_scope_v1(int $now):?array
{
    $raw=setting('phone_managed_pilot_scope_v1','');
    if(!is_string($raw)||strlen($raw)>1200)return null;
    $p=json_decode($raw,true);
    if(!is_array($p)||array_is_list($p)||array_diff(array_keys($p),['owner_user_id','number_id','device_id','holder_e164','destination_e164','expires_epoch'])
        ||!is_int($p['owner_user_id']??null)||$p['owner_user_id']<1||!is_int($p['number_id']??null)||$p['number_id']<1
        ||!is_string($p['device_id']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$p['device_id'])!==1
        ||!is_string($p['holder_e164']??null)||preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$p['holder_e164'])!==1
        ||!is_string($p['destination_e164']??null)||preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$p['destination_e164'])!==1
        ||$p['holder_e164']===$p['destination_e164']||!is_int($p['expires_epoch']??null)
        ||$p['expires_epoch']<=$now||$p['expires_epoch']>$now+86400)return null;
    return $p;
}
function vp3_managed_pilot_allowed_v1(array $row,int $now):bool
{
    if((string)setting('phone_managed_callback_enabled_v1','0')!=='1'||(string)setting('phone_managed_live_pilot_enabled_v1','0')!=='1')return false;
    $p=vp3_managed_pilot_scope_v1($now);
    return $p!==null&&(int)$row['owner_user_id']===$p['owner_user_id']&&(int)$row['number_id']===$p['number_id']
        &&hash_equals($p['device_id'],(string)$row['device_id'])&&hash_equals($p['holder_e164'],(string)$row['holder_e164'])
        &&hash_equals($p['destination_e164'],(string)$row['destination_e164']);
}
/** Expose reasons, never the deployment allowlist or a private number. */
function vp3_managed_pilot_review_v1(PDO $pdo,array $row,int $now):array
{
    $out=['available'=>false,'reason'=>'pilot_disabled','recording_available'=>false];
    if(!vp3_managed_pilot_allowed_v1($row,$now))return $out;
    if(!vp3_managed_transport_ready_v1($pdo)||!vp3_managed_control_ready_v1($pdo)||!vp3_native_dialthrough_ready_v1($pdo)){
        $out['reason']='upgrade_required';return $out;
    }
    if(!vp3_managed_recording_ready_v1($pdo)){$out['reason']='upgrade_required';return $out;}
    require_once __DIR__.'/phone-managed-recording-v1.php';
    if(vp3_managed_recording_row_v1($pdo,$row['public_id'])){
        $out['reason']='recording_acceptance_required';return $out;
    }
    if($row['state']!=='approved'||(int)$row['approved_epoch']<1||(int)$row['expires_epoch']<=$now){
        $out['reason']='holder_approval_required';return $out;
    }
    return ['available'=>true,'reason'=>'ready_for_unrecorded_acceptance','recording_available'=>false];
}
