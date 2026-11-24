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


/** Administrator configuration is not call authorization and performs no carrier I/O. */
function vp3_managed_pilot_configure_v1(PDO $pdo,array $admin,array $input,int $now):void
{
    require_once __DIR__.'/phone-managed-transport-v1.php';
    vp3_managed_callback_need_v1(has_permission('users.manage',$admin)&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_managed_callback_need_v1(vp3_phone_workspace_schema_ready_v1($pdo)&&vp3_managed_recording_ready_v1($pdo)&&vp3_managed_control_ready_v1($pdo)&&vp3_native_dialthrough_ready_v1($pdo),503);
    vp3_managed_callback_need_v1(($input['confirmed']??null)===true,422);
    $number=vp3_phone_workspace_id_v1($input['number_id']??null);
    $device=vp3_managed_callback_id_v1($input['device_id']??null);
    $dest=vp3_managed_callback_number_v1($input['destination_e164']??null);
    $minutes=$input['minutes']??0;vp3_managed_callback_need_v1(in_array($minutes,[15,30,60],true),422);
    $q=$pdo->prepare("SELECT n.owner_user_id,n.agent_id,n.phone_e164,p.phone_e164 AS holder_e164
        FROM phone_cloud_numbers_v100 n JOIN user_agents a ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=n.owner_user_id AND d.id=? AND d.device_status='approved' AND d.expires_at>?
        JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=d.owner_user_id AND p.device_id=d.id
        JOIN phone_browser_numbers_v1 b ON b.phone_number_id=n.id AND b.owner_user_id=n.owner_user_id AND b.agent_id=n.agent_id
        WHERE n.id=? AND n.provider='twilio' AND n.voice_enabled=1 AND b.browser_enabled=1 AND p.owner_calls=1 AND p.holder_calls=1");
    $q->execute([$device,$now,$number]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    vp3_managed_callback_need_v1(is_array($row)&&vp3_phone_browser_config_state_v1()['ready']&&vp3_phone_package_v100($pdo,(int)$row['owner_user_id'])['voice'],409);
    $holder=vp3_managed_callback_number_v1($row['holder_e164']);
    vp3_managed_callback_need_v1($holder!==$dest&&$dest!==$row['phone_e164'],422);
    $scope=['owner_user_id'=>(int)$row['owner_user_id'],'number_id'=>$number,'device_id'=>$device,'holder_e164'=>$holder,'destination_e164'=>$dest,'expires_epoch'=>$now+$minutes*60];
    // Disable first. An interrupted save leaves no broader live authority.
    save_setting('phone_managed_live_pilot_enabled_v1','0');
    save_setting('phone_native_dialthrough_enabled_v1',($input['native']??false)===true?'1':'0');
    save_setting('phone_managed_pilot_scope_v1',json_encode($scope,JSON_THROW_ON_ERROR));
    save_setting('phone_managed_callback_enabled_v1','1');
    save_setting('phone_managed_live_pilot_enabled_v1','1');
}
function vp3_managed_pilot_disable_v1(array $admin):void
{
    if(!has_permission('users.manage',$admin)||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))throw new RuntimeException('Administrator access required.',403);
    save_setting('phone_managed_live_pilot_enabled_v1','0');
    save_setting('phone_native_dialthrough_enabled_v1','0');
    save_setting('phone_managed_pilot_scope_v1','');
}
function vp3_managed_pilot_admin_status_v1(int $now):array
{
    $scope=vp3_managed_pilot_scope_v1($now);
    $path=STONEFELLOW_ROOT.'/private/phone-recordings-v1/managed-media-health.json';
    $media='not_observed';$driver=false;
    if(!is_link($path)&&is_file($path)&&($size=@filesize($path))!==false&&$size<=2048){
        $r=json_decode((string)@file_get_contents($path),true);
        if(is_array($r)&&($r['source']??null)==='managed_media_cli'&&is_int($r['observed_epoch']??null)&&$r['observed_epoch']<=$now&&$r['observed_epoch']>0){
            $media=$now-$r['observed_epoch']<=75?'observed':'stale';$driver=($r['driver_enabled']??false)===true;
        }
    }
    return ['enabled'=>$scope!==null&&(string)setting('phone_managed_live_pilot_enabled_v1','0')==='1',
        'owner_id'=>$scope['owner_user_id']??0,'number_id'=>$scope['number_id']??0,'expires_epoch'=>$scope['expires_epoch']??0,
        'media_worker'=>$media,'media_driver_enabled'=>$driver,'production_certified'=>false];
}
