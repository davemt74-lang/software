<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Authenticated review UI and explicitly gated owner pilot dispatch. */
require_once __DIR__.'/phone-managed-callback-schema-v1.php';

function vp3_managed_ui_device_v1(PDO $pdo,int $owner,string $id):?array
{
    $q=$pdo->prepare("SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=? AND device_status='approved'");
    $q->execute([$owner,$id]);$d=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $d?:null;
}
function vp3_managed_ui_thread_v1(PDO $pdo,int $owner,string $device):?array
{
    $q=$pdo->prepare('SELECT t.agent_id,t.conversation_id FROM vp3_paired_phone_threads_v1 t JOIN chat_conversations c
        ON c.id=t.conversation_id AND c.user_id=t.owner_user_id AND COALESCE(c.user_agent_id,0)=t.agent_id
        WHERE t.owner_user_id=? AND t.device_id=? LIMIT 1');
    $q->execute([$owner,$device]);$t=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    return $t?['agent_id'=>(int)$t['agent_id'],'conversation_id'=>(int)$t['conversation_id']]:null;
}
/** Non-secret, owner-scoped call review state. Holder alone receives pending destination. */
function vp3_managed_ui_projection_v1(PDO $pdo,array $d,int $now,bool $holder):?array
{
    if(($d['device_status']??'')!=='approved'||(int)($d['expires_at']??0)<=$now)return null;
    $out=['available'=>false,'dispatch_enabled'=>false,'server_epoch'=>$now,'reason'=>'upgrade_required','request'=>null,'lines'=>[]];
    if(!vp3_managed_callback_ready_v1($pdo))return $out;
    require_once __DIR__.'/phone-managed-callback-v1.php';
    require_once __DIR__.'/phone-managed-transport-schema-v1.php';
    try{
        if(!vp3_phone_workspace_schema_ready_v1($pdo))return $out;
        $owner=(int)$d['owner_user_id'];$device=(string)$d['id'];
        $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$user||!vp3_phone_workspace_available_v1($pdo,$user)){$out['reason']='no_assigned_line';return $out;}
        $thread=vp3_managed_ui_thread_v1($pdo,$owner,$device);
        if(!$thread){$out['reason']='chat_required';return $out;}
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,$thread);
        if(!vp3_phone_package_v100($pdo,$owner)['voice']){$out['reason']='voice_required';return $out;}
        $q=$pdo->prepare('SELECT * FROM vp3_phone_communication_profiles_v1 WHERE owner_user_id=? AND device_id=?');
        $q->execute([$owner,$device]);$p=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$p||empty($p['owner_calls'])||empty($p['holder_calls'])||!preg_match('/\A\+[1-9][0-9]{7,14}\z/D',(string)$p['phone_e164'])){
            $out['reason']='permission_required';return $out;
        }
        if(!vp3_phone_browser_config_state_v1()['ready']){$out['reason']='carrier_not_ready';return $out;}
        if(!$holder){
            foreach(vp3_phone_workspace_numbers_v1($pdo,$scope) as $n){
                if($n['voice_enabled']&&$n['browser_enabled']&&preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$n['phone_e164'])&&$n['phone_e164']!==$p['phone_e164']){
                    require_once __DIR__.'/phone-managed-recording-v1.php';
                    $policy=vp3_managed_recording_policy_v1($pdo,$user,$n);
                    $out['lines'][]=['id'=>(int)$n['id'],'phone_e164'=>$n['phone_e164'],'agent_name'=>(string)$n['agent_name'],
                        'recording_policy'=>$policy?['revision'=>$policy['revision'],'retain_audio'=>true,'transcribe'=>$policy['transcribe'],'analyze'=>$policy['analyze']]:null];
                }
            }
        }
        $out['available']=$holder||count($out['lines'])>0;
        $out['reason']=$out['available']?'review_only':'no_assigned_line';
        if(!vp3_connected_phone_window_v1($p,$now)){$out['available']=false;$out['reason']='outside_calling_hours';}
        $q=$pdo->prepare('SELECT * FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND device_id=? AND profile_id=? ORDER BY created_epoch DESC,public_id DESC LIMIT 1');
        $q->execute([$owner,$device,$p['profile_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)return $out;
        // Never expose a stale destination even if the stored request has not been pruned.
        if((int)$row['generation']!==(int)$d['generation']||(int)$row['profile_revision']!==(int)$p['revision']||
            (int)$row['phone_revision']!==(int)$p['phone_revision']||(int)$row['conversation_id']!==$thread['conversation_id']||
            (int)$row['chat_agent_id']!==$thread['agent_id'])return $out;
        $view=vp3_managed_callback_view_v1($row,$now,$holder,$pdo);
        if(in_array($view['state'],['pending','approved'],true)){
            try{vp3_managed_callback_current_v1($pdo,$row,$user,$now);}catch(VP3PhoneWorkspaceException){
                $view['state']='unavailable';unset($view['destination_e164']);
                if(isset($view['native']))$view['native']['can_issue_pin']=false;
            }
        }
        if(vp3_managed_transport_ready_v1($pdo)){
            $q=$pdo->prepare("SELECT t.state AS dispatch_state,t.parent_state,b.public_id,b.state AS call_state,b.duration_seconds
                FROM phone_managed_callback_dispatch_v1 t JOIN phone_browser_calls_v1 b ON b.public_id=t.call_public_id AND b.owner_user_id=t.owner_user_id
                WHERE t.owner_user_id=? AND t.request_id=? AND b.paired_device_id=? AND b.paired_profile_id=? AND b.transport_kind='managed_callback'");
            $q->execute([$owner,$row['public_id'],$device,$p['profile_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($call){
                $view['call']=['public_id'=>(string)$call['public_id'],'state'=>(string)$call['call_state'],
                    'dispatch_state'=>(string)$call['dispatch_state'],'parent_state'=>(string)$call['parent_state'],
                    'duration_seconds'=>max(0,(int)$call['duration_seconds'])];
                if(!$holder){
                    require_once __DIR__.'/phone-managed-control-v1.php';
                    $full=vp3_managed_transport_call_v1($pdo,$call['public_id']);
                    if($full)$view['call']['managed_control']=vp3_managed_control_view_v1($pdo,$scope,$full);
                }
            }
        }
        require_once __DIR__.'/phone-managed-pilot-v1.php';
        $pilot=vp3_managed_pilot_review_v1($pdo,$row,$now);
        if($view['state']!=='approved')$pilot['available']=false;
        $view['pilot']=$pilot;
        $out['dispatch_enabled']=!$holder&&$view['transport']==='twilio_callback'&&$pilot['available'];
        $out['request']=$view;return $out;
    }catch(Throwable){
        // This optional feature must not break location, camera or messaging status.
        $out['available']=false;$out['lines']=[];$out['request']=null;$out['reason']='unavailable';return $out;
    }
}
function vp3_managed_ui_owner_action_v1(PDO $pdo,array $user,string $action,array $input,int $now):array
{
    require_once __DIR__.'/phone-managed-callback-v1.php';
    vp3_managed_callback_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    vp3_managed_callback_need_v1(in_array($action,['managed_prepare','managed_cancel','managed_reconcile','managed_end','managed_pilot_dispatch','managed_recording_withdraw'],true),422);
    if($action==='managed_recording_withdraw')return vp3_managed_ui_withdraw_owner_v1($pdo,$user,$input,$now);
    if($action==='managed_pilot_dispatch')return vp3_managed_ui_pilot_dispatch_v1($pdo,$user,$input,$now);
    if(in_array($action,['managed_reconcile','managed_end'],true))return vp3_managed_ui_control_v1($pdo,$user,$action,$input,$now);
    $allowed=$action==='managed_prepare'
        ?['request_id','managed_request_id','device_id','generation','profile_id','profile_revision','phone_revision','number_id','destination_e164','confirmed','recording_requested','recording_policy_revision','transport']
        :['request_id','managed_request_id','device_id','generation'];
    vp3_managed_callback_need_v1(!array_diff(array_keys($input),$allowed),422);
    $owner=(int)($user['id']??0);$device=vp3_managed_callback_id_v1($input['device_id']??null);
    $id=vp3_managed_callback_id_v1($input['managed_request_id']??null);
    $d=vp3_managed_ui_device_v1($pdo,$owner,$device);
    vp3_managed_callback_need_v1(is_array($d)&&(int)$d['expires_at']>$now,404);
    vp3_managed_callback_need_v1(is_int($input['generation']??null)&&(int)$d['generation']===$input['generation']);
    if($action==='managed_prepare'){
        $thread=vp3_managed_ui_thread_v1($pdo,$owner,$device);vp3_managed_callback_need_v1(is_array($thread));
        $args=$input;unset($args['managed_request_id']);$args['request_id']=$id;$args+=$thread;
        vp3_managed_callback_prepare_v1($pdo,$user,$args,$now);
    }else{
        $row=vp3_managed_callback_row_v1($pdo,$id);
        vp3_managed_callback_need_v1(is_array($row)&&(int)$row['owner_user_id']===$owner&&$row['device_id']===$device,404);
        vp3_managed_callback_cancel_v1($pdo,$user,$id,$now);
    }
    $fresh=vp3_managed_ui_device_v1($pdo,$owner,$device);
    return ['managed_callback'=>$fresh?vp3_managed_ui_projection_v1($pdo,$fresh,$now,false):null];
}
/** Called only inside the existing authenticated pairing-cookie transaction. */
function vp3_managed_ui_holder_action_v1(PDO $pdo,array $d,array $input,int $now):array
{
    require_once __DIR__.'/phone-managed-callback-v1.php';
    vp3_managed_callback_need_v1(!array_diff(array_keys($input),['device_id','generation','managed_request_id','decision','destination_e164','confirmed','recording_consent','recording_consent_revision']),422);
    vp3_managed_callback_need_v1(($d['device_status']??'')==='approved'&&(int)$d['expires_at']>$now&&($input['device_id']??null)===$d['id'],403);
    vp3_managed_callback_need_v1(is_int($input['generation']??null)&&$input['generation']===(int)$d['generation']);
    $args=['request_id'=>vp3_managed_callback_id_v1($input['managed_request_id']??null),'decision'=>$input['decision']??null,
        'destination_e164'=>$input['destination_e164']??null,'confirmed'=>$input['confirmed']??false];
    foreach(['recording_consent','recording_consent_revision'] as $field)if(array_key_exists($field,$input))$args[$field]=$input[$field];
    vp3_managed_callback_holder_locked_v1($pdo,(int)$d['owner_user_id'],$d['id'],$args,$now);
    return ['managed_callback'=>vp3_managed_ui_projection_v1($pdo,$d,$now,true)];
}


/** Existing managed call controls do not authorize a fresh call or another device. */
function vp3_managed_ui_control_v1(PDO $pdo,array $user,string $action,array $input,int $now):array
{
    require_once __DIR__.'/phone-managed-control-v1.php';
    vp3_managed_callback_need_v1(!array_diff(array_keys($input),['request_id','device_id','generation','call_id','confirmed']),422);
    $owner=(int)($user['id']??0);$device=vp3_managed_callback_id_v1($input['device_id']??null);
    $public=vp3_managed_callback_id_v1($input['call_id']??null);
    $d=vp3_managed_ui_device_v1($pdo,$owner,$device);
    vp3_managed_callback_need_v1(is_array($d)&&(int)$d['expires_at']>$now,404);
    vp3_managed_callback_need_v1(is_int($input['generation']??null)&&(int)$d['generation']===$input['generation']);
    $call=vp3_managed_transport_call_v1($pdo,$public);
    vp3_managed_callback_need_v1(is_array($call)&&(int)$call['owner_user_id']===$owner&&$call['paired_device_id']===$device,404);
    $thread=vp3_managed_ui_thread_v1($pdo,$owner,$device);vp3_managed_callback_need_v1(is_array($thread));
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,$thread);
    $result=vp3_managed_control_action_v1($pdo,$scope,['action'=>$action,'call_id'=>$public,'confirmed'=>($input['confirmed']??false)===true]);
    $fresh=vp3_managed_ui_device_v1($pdo,$owner,$device);
    return $result+['managed_callback'=>$fresh?vp3_managed_ui_projection_v1($pdo,$fresh,time(),false):null];
}


/** Explicit owner action only; the injected carrier/clock are server test seams. */
function vp3_managed_ui_pilot_dispatch_v1(PDO $pdo,array $user,array $input,int $now,?callable $carrier=null):array
{
    require_once __DIR__.'/phone-managed-transport-v1.php';
    require_once __DIR__.'/phone-managed-recording-v1.php';
    vp3_managed_callback_need_v1(!array_diff(array_keys($input),['request_id','device_id','generation','managed_request_id','confirmed'])
        &&($input['confirmed']??null)===true,422);
    vp3_managed_callback_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    $owner=(int)($user['id']??0);$device=vp3_managed_callback_id_v1($input['device_id']??null);
    $id=vp3_managed_callback_id_v1($input['managed_request_id']??null);
    $d=vp3_managed_ui_device_v1($pdo,$owner,$device);
    $row=vp3_managed_callback_row_v1($pdo,$id);
    vp3_managed_callback_need_v1(is_array($d)&&is_array($row)&&(int)$row['owner_user_id']===$owner&&$row['device_id']===$device&&(int)$d['expires_at']>$now,404);
    vp3_managed_callback_need_v1(is_int($input['generation']??null)&&(int)$d['generation']===$input['generation']&&(int)$row['generation']===$input['generation']);
    $result=vp3_managed_transport_dispatch_v1($pdo,$user,$id,true,$carrier,$carrier!==null?$now:null,true);
    $fresh=vp3_managed_ui_device_v1($pdo,$owner,$device);
    return $result+['managed_callback'=>$fresh?vp3_managed_ui_projection_v1($pdo,$fresh,$carrier!==null?$now:time(),false):null];
}


function vp3_managed_ui_withdraw_locked_v1(PDO $pdo,array $d,array $input,int $now,bool $holder):array
{
    require_once __DIR__.'/phone-managed-media-worker-v1.php';
    vp3_managed_callback_need_v1(vp3_phone_transaction_active_v1($pdo)&&!array_diff(array_keys($input),['request_id','device_id','generation','managed_request_id','confirmed'])&&($input['confirmed']??null)===true,422);
    vp3_managed_callback_need_v1(($d['device_status']??'')==='approved'&&(int)$d['expires_at']>$now&&($input['device_id']??null)===$d['id']&&($input['generation']??null)===(int)$d['generation'],403);
    $id=vp3_managed_callback_id_v1($input['managed_request_id']??null);$owner=(int)$d['owner_user_id'];
    $row=vp3_managed_callback_row_v1($pdo,$id,true);
    vp3_managed_callback_need_v1(is_array($row)&&(int)$row['owner_user_id']===$owner&&$row['device_id']===$d['id']&&(int)$row['generation']===(int)$d['generation'],404);
    vp3_managed_callback_need_v1(in_array($row['state'],['pending','approved','dispatch_claimed','revoked'],true),409);
    $receipt=vp3_managed_media_withdraw_locked_v1($pdo,$row,$now);
    if(in_array($row['state'],['pending','approved'],true)){
        $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='canceled',holder_e164='',destination_e164='',updated_epoch=? WHERE public_id=?")->execute([$now,$id]);
    }elseif($row['state']==='dispatch_claimed'){
        $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='revoked',updated_epoch=? WHERE public_id=?")->execute([$now,$id]);
    }
    return ['recording_withdrawal'=>$receipt,'managed_callback'=>vp3_managed_ui_projection_v1($pdo,$d,$now,$holder)];
}
function vp3_managed_ui_withdraw_owner_v1(PDO $pdo,array $user,array $input,int $now):array
{
    require_once __DIR__.'/phone-managed-callback-v1.php';
    vp3_managed_callback_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    $device=vp3_managed_callback_id_v1($input['device_id']??null);$owner=(int)$user['id'];
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$owner,$device,$input,$now){
        vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $d=vp3_managed_ui_device_v1($pdo,$owner,$device);
        vp3_managed_callback_need_v1(is_array($d),404);
        return vp3_managed_ui_withdraw_locked_v1($pdo,$d,$input,$now,false);
    });
}
function vp3_managed_ui_withdraw_holder_v1(PDO $pdo,array $d,array $input,int $now):array
{
    // Only the existing authenticated pairing-cookie transaction can call this.
    return vp3_managed_ui_withdraw_locked_v1($pdo,$d,$input,$now,true);
}
