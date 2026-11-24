<?php
declare(strict_types=1);

/**
 * PS5E.1: durable, consent-bound managed callback admission.
 * This module deliberately has no public endpoint, carrier POST, recording,
 * worker or autonomous execution. A subsequent transport slice must consume
 * its exact reservation and bind the existing canonical Operator ledger.
 */
require_once __DIR__.'/phone-browser-v1.php';

const VP3_MANAGED_CALLBACK_TTL_V1=120;
require_once __DIR__.'/phone-managed-callback-schema-v1.php';

function vp3_managed_callback_need_v1(bool $ok,int $status=409):void
{
    if(!$ok)vp3_phone_workspace_error_v1('managed_callback_unavailable',$status,'Managed callback request or permission changed.');
}
function vp3_managed_callback_id_v1(mixed $v):string
{
    vp3_managed_callback_need_v1(is_string($v)&&preg_match('/\A[a-f0-9]{32}\z/D',$v)===1,422);
    return $v;
}
function vp3_managed_callback_number_v1(mixed $v):string
{
    vp3_managed_callback_need_v1(is_string($v)&&preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$v)===1,422);
    return $v;
}
function vp3_managed_callback_owner_lock_v1(PDO $pdo,int $owner):array
{
    vp3_managed_callback_need_v1($owner>0,403);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    vp3_managed_callback_need_v1(is_array($user),403);
    return vp3_phone_workspace_live_user_v1($pdo,$user);
}
function vp3_managed_callback_row_v1(PDO $pdo,string $public,bool $lock=false):?array
{
    $q=$pdo->prepare('SELECT * FROM phone_managed_callback_requests_v1 WHERE public_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$public]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function vp3_managed_callback_view_v1(array $row,int $now,bool $holder,?PDO $pdo=null):array
{
    $state=(string)$row['state'];
    if(in_array($state,['pending','approved'],true)&&(int)$row['expires_epoch']<=$now)$state='expired';
    $view=['request_id'=>(string)$row['public_id'],'device_id'=>(string)$row['device_id'],
        'state'=>$state,'transport'=>'twilio_callback','recording_enabled'=>false,
        'expires_epoch'=>(int)$row['expires_epoch'],'approved_epoch'=>(int)$row['approved_epoch']];
    if($holder&&$state==='pending')$view['destination_e164']=(string)$row['destination_e164'];
    if($pdo&&vp3_managed_recording_ready_v1($pdo)){
        require_once __DIR__.'/phone-managed-recording-v1.php';
        $c=vp3_managed_recording_row_v1($pdo,$row['public_id']);if($c)$view['recording']=vp3_managed_recording_view_v1($c);
    }
    if($pdo&&vp3_native_dialthrough_ready_v1($pdo)){
        require_once __DIR__.'/phone-native-dialthrough-v1.php';
        $native=vp3_native_dialthrough_row_v1($pdo,$row['public_id']);
        if($native){$view['transport']='twilio_native';$view['native']=vp3_native_dialthrough_view_v1($row,$native,$now,$holder);}
    }
    return $view;
}
function vp3_managed_callback_prune_v1(PDO $pdo,int $owner,int $now):void
{
    if(vp3_native_dialthrough_ready_v1($pdo))$pdo->prepare("UPDATE phone_native_dialthrough_v1 SET state='revoked',pin_hash=NULL,updated_epoch=? WHERE owner_user_id=? AND state IN ('review','issued','challenge') AND request_id IN (SELECT public_id FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND expires_epoch<=?)")->execute([$now,$owner,$owner,$now]);
    $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='expired',holder_e164='',destination_e164='',updated_epoch=? WHERE owner_user_id=? AND state IN ('pending','approved') AND expires_epoch<=?")->execute([$now,$owner,$now]);
    $pdo->prepare("DELETE FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND created_epoch<=? AND state IN ('expired','declined','canceled')")->execute([$owner,$now-86400]);
    if(vp3_native_dialthrough_ready_v1($pdo))$pdo->prepare("DELETE FROM phone_native_dialthrough_v1 WHERE owner_user_id=? AND state='revoked' AND challenge_sid IS NULL AND updated_epoch<=? AND NOT EXISTS (SELECT 1 FROM phone_managed_callback_requests_v1 r WHERE r.public_id=phone_native_dialthrough_v1.request_id)")->execute([$owner,$now-86400]);
}
/** Revalidate all current grants, exact number assignment and linked conversation under owner lock. */
function vp3_managed_callback_current_v1(PDO $pdo,array $row,array $user,int $now):array
{
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>(int)$row['chat_agent_id'],'conversation_id'=>(int)$row['conversation_id']]);
    vp3_managed_callback_need_v1($scope['owner_user_id']===(int)$row['owner_user_id'],403);
    $n=vp3_phone_browser_require_v1($pdo,$scope,(int)$row['number_id'],true);
    vp3_managed_callback_need_v1((int)$n['agent_id']===(int)$row['line_agent_id']&&hash_equals((string)$n['phone_e164'],(string)$row['line_e164']));
    $q=$pdo->prepare('SELECT generation FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([(int)$row['owner_user_id'],$row['device_id']]);$generation=$q->fetchColumn();$q->closeCursor();
    vp3_managed_callback_need_v1($generation!==false&&(int)$generation===(int)$row['generation']);
    $p=vp3_connected_phone_bound_v1($pdo,$scope['owner_user_id'],$scope['agent_id'],$scope['conversation_id'],
        (string)$row['device_id'],(string)$row['profile_id'],(int)$row['profile_revision'],(int)$row['phone_revision'],$now);
    vp3_managed_callback_need_v1(hash_equals((string)$p['phone_e164'],(string)$row['holder_e164']));
    return ['scope'=>$scope,'profile'=>$p,'number'=>$n];
}
/** Private owner prepares a bounded request. No carrier traffic or browser dial intent. */
function vp3_managed_callback_prepare_v1(PDO $pdo,array $user,array $input,int $now):array
{
    vp3_managed_callback_need_v1(vp3_managed_callback_ready_v1($pdo),503);
    vp3_managed_callback_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    vp3_managed_callback_need_v1(($input['confirmed']??null)===true&&!array_key_exists('recording_enabled',$input)&&!array_key_exists('holder_e164',$input),422);
    vp3_managed_callback_need_v1(is_bool($input['recording_requested']??false),422);
    require_once __DIR__.'/phone-managed-recording-v1.php';
    require_once __DIR__.'/phone-native-dialthrough-v1.php';
    $mode=$input['transport']??'twilio_callback';
    vp3_managed_callback_need_v1(in_array($mode,['twilio_callback','twilio_native'],true),422);
    if($mode==='twilio_native')vp3_managed_callback_need_v1(vp3_native_dialthrough_ready_v1($pdo)&&empty($input['recording_requested'])&&!isset($input['recording_policy_revision']),422);
    $id=vp3_managed_callback_id_v1($input['request_id']??null);
    $device=vp3_managed_callback_id_v1($input['device_id']??null);
    $profile=vp3_managed_callback_id_v1($input['profile_id']??null);
    $destination=vp3_managed_callback_number_v1($input['destination_e164']??null);
    $generation=vp3_phone_workspace_id_v1($input['generation']??null);
    $revision=vp3_phone_workspace_id_v1($input['profile_revision']??null);
    $phoneRevision=vp3_phone_workspace_id_v1($input['phone_revision']??null);
    $numberId=vp3_phone_workspace_id_v1($input['number_id']??null);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$user,$input,$now,$id,$device,$profile,$destination,$generation,$revision,$phoneRevision,$numberId,$mode){
        $live=vp3_managed_callback_owner_lock_v1($pdo,(int)($user['id']??0));
        $scope=vp3_phone_workspace_scope_v1($pdo,$live,$input);
        $owner=$scope['owner_user_id'];
        vp3_managed_callback_prune_v1($pdo,$owner,$now);
        $old=vp3_managed_callback_row_v1($pdo,$id,true);
        if($old){
            vp3_managed_callback_need_v1((int)$old['owner_user_id']===$owner,404);
            vp3_managed_callback_need_v1(in_array($old['state'],['pending','approved'],true)&&(int)$old['expires_epoch']>$now);
            vp3_managed_callback_need_v1($old['device_id']===$device&&$old['profile_id']===$profile&&(int)$old['generation']===$generation
                &&(int)$old['profile_revision']===$revision&&(int)$old['phone_revision']===$phoneRevision&&(int)$old['number_id']===$numberId
                &&(int)$old['chat_agent_id']===$scope['agent_id']&&(int)$old['conversation_id']===$scope['conversation_id']
                &&hash_equals((string)$old['destination_e164'],$destination));
            vp3_managed_callback_need_v1((bool)vp3_native_dialthrough_row_v1($pdo,$id,true)===($mode==='twilio_native'));
            $a=vp3_managed_callback_current_v1($pdo,$old,$live,$now);
            $c=vp3_managed_recording_row_v1($pdo,$id,true);
            vp3_managed_callback_need_v1((bool)$c===($input['recording_requested']??false));
            if($c){vp3_managed_callback_need_v1(($input['recording_policy_revision']??null)===(int)$c['policy_revision']);vp3_managed_recording_current_v1($pdo,$old,$c,$a);}
            else vp3_managed_callback_need_v1(!isset($input['recording_policy_revision']),422);
            return vp3_managed_callback_view_v1($old,$now,false,$pdo);
        }
        $n=vp3_phone_browser_require_v1($pdo,$scope,$numberId,true);
        $p=vp3_connected_phone_prepare_v1($pdo,$scope,['device_id'=>$device,'profile_id'=>$profile,'profile_revision'=>$revision,'phone_revision'=>$phoneRevision,'confirmed'=>true],$now);
        $holder=vp3_managed_callback_number_v1($p['destination']);
        $line=vp3_managed_callback_number_v1($n['phone_e164']);
        vp3_managed_callback_need_v1($destination!==$line&&$destination!==$holder&&$line!==$holder,422);
        $q=$pdo->prepare("SELECT COUNT(*) FROM phone_browser_calls_v1 WHERE owner_user_id=? AND state IN ('prepared','dialing','ringing','answered') AND (state<>'prepared' OR intent_expires_epoch>?)");
        $q->execute([$owner,$now]);vp3_managed_callback_need_v1((int)$q->fetchColumn()===0);$q->closeCursor();
        $q=$pdo->prepare("SELECT COUNT(*) FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND state IN ('pending','approved') AND expires_epoch>?");
        $q->execute([$owner,$now]);vp3_managed_callback_need_v1((int)$q->fetchColumn()===0,409);$q->closeCursor();
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND created_epoch>?');
        $q->execute([$owner,$now-86400]);vp3_managed_callback_need_v1((int)$q->fetchColumn()<20,429);$q->closeCursor();
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND device_id=? AND created_epoch>?');
        $q->execute([$owner,$device,$now-60]);vp3_managed_callback_need_v1((int)$q->fetchColumn()===0,429);$q->closeCursor();
        $row=['public_id'=>$id,'owner_user_id'=>$owner,'device_id'=>$device,'generation'=>$generation,'profile_id'=>$profile,
            'profile_revision'=>$revision,'phone_revision'=>$phoneRevision,'number_id'=>$numberId,'line_agent_id'=>(int)$n['agent_id'],
            'chat_agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],'line_e164'=>$line,
            'holder_e164'=>$holder,'destination_e164'=>$destination,'state'=>'pending','created_epoch'=>$now,
            'expires_epoch'=>$now+VP3_MANAGED_CALLBACK_TTL_V1,'approved_epoch'=>0,'updated_epoch'=>$now];
        vp3_managed_callback_current_v1($pdo,$row,$live,$now);
        $fields=array_keys($row);$pdo->prepare('INSERT INTO phone_managed_callback_requests_v1('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($row));
        if($mode==='twilio_native')$pdo->prepare('INSERT INTO phone_native_dialthrough_v1(request_id,owner_user_id,updated_epoch) VALUES (?,?,?)')->execute([$id,$owner,$now]);
        vp3_managed_recording_request_v1($pdo,$row,$live,$n,$input,$now);
        return vp3_managed_callback_view_v1($row,$now,false,$pdo);
    });
}
/**
 * Internal holder response boundary. The owner/device identity MUST be supplied
 * by the existing authenticated pairing-cookie handler, never request JSON.
 * No callback is placed; a later transport claim must revalidate approval.
 */
function vp3_managed_callback_holder_v1(PDO $pdo,int $owner,string $device,array $input,int $now):array
{
    vp3_managed_callback_need_v1(vp3_managed_callback_ready_v1($pdo),503);
    $id=vp3_managed_callback_id_v1($input['request_id']??null);
    $decision=$input['decision']??null;
    vp3_managed_callback_need_v1(in_array($decision,['approve','decline'],true),422);
    return vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_managed_callback_holder_locked_v1($pdo,$owner,$device,$input,$now));
}
/** Caller already holds the authenticated account/device transaction. */
function vp3_managed_callback_holder_locked_v1(PDO $pdo,int $owner,string $device,array $input,int $now):array
{
        vp3_managed_callback_need_v1(vp3_managed_callback_ready_v1($pdo),503);
        $id=vp3_managed_callback_id_v1($input['request_id']??null);
        $decision=$input['decision']??null;
        vp3_managed_callback_need_v1(in_array($decision,['approve','decline'],true),422);
        $user=vp3_managed_callback_owner_lock_v1($pdo,$owner);
        vp3_managed_callback_prune_v1($pdo,$owner,$now);
        $row=vp3_managed_callback_row_v1($pdo,$id,true);
        vp3_managed_callback_need_v1(is_array($row)&&(int)$row['owner_user_id']===$owner&&hash_equals((string)$row['device_id'],$device),404);
        if($decision==='decline'&&$row['state']==='declined')return vp3_managed_callback_view_v1($row,$now,true,$pdo);
        vp3_managed_callback_need_v1(in_array($row['state'],['pending','approved'],true)&&(int)$row['expires_epoch']>$now);
        require_once __DIR__.'/phone-managed-recording-v1.php';
        if($decision==='approve')$authority=vp3_managed_callback_current_v1($pdo,$row,$user,$now);
        if($decision==='approve'){
            vp3_managed_callback_need_v1(($input['confirmed']??null)===true&&is_string($input['destination_e164']??null)
                &&hash_equals((string)$row['destination_e164'],$input['destination_e164'])&&!array_key_exists('recording_enabled',$input),422);
            vp3_managed_recording_holder_v1($pdo,$row,$authority,$input,$now);
            if($row['state']==='approved')return vp3_managed_callback_view_v1($row,$now,true,$pdo);
            $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='approved',approved_epoch=?,updated_epoch=? WHERE public_id=? AND state='pending'")->execute([$now,$now,$id]);
        }else{
            vp3_managed_recording_revoke_v1($pdo,$id,$now);
            vp3_native_dialthrough_revoke_v1($pdo,$id,$now);
            $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='declined',holder_e164='',destination_e164='',updated_epoch=? WHERE public_id=? AND state IN ('pending','approved')")->execute([$now,$id]);
        }
        return vp3_managed_callback_view_v1(vp3_managed_callback_row_v1($pdo,$id),$now,true,$pdo);
}
/** Pure carrier command builder. No Twilio network request, no credential access. */
function vp3_managed_callback_plan_v1(array $row,int $now,string $answerUrl,string $statusUrl):array
{
    vp3_managed_callback_need_v1(($row['state']??null)==='approved'&&(int)$row['approved_epoch']>0&&(int)$row['expires_epoch']>$now);
    foreach([$answerUrl,$statusUrl] as $url){
        $parts=parse_url($url);
        vp3_managed_callback_need_v1(is_array($parts)&&($parts['scheme']??'')==='https'&&!empty($parts['host'])
            &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment']),422);
    }
    $from=vp3_managed_callback_number_v1($row['line_e164']);$to=vp3_managed_callback_number_v1($row['holder_e164']);
    vp3_managed_callback_need_v1($from!==$to);
    // The destination is NOT sent as the first leg. A signed answer/confirm
    // endpoint must bind the carrier SID and gather affirmative DTMF first.
    return ['From'=>$from,'To'=>$to,'Url'=>$answerUrl,'Method'=>'POST','StatusCallback'=>$statusUrl,
        'StatusCallbackMethod'=>'POST','StatusCallbackEvent'=>'completed','Timeout'=>20,'TimeLimit'=>240,'Record'=>'false'];
}

/** Owner cancels an unsent request; approval alone does not place a call. */
function vp3_managed_callback_cancel_v1(PDO $pdo,array $user,string $id,int $now):array
{
    vp3_managed_callback_need_v1(vp3_managed_callback_ready_v1($pdo),503);
    vp3_managed_callback_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    vp3_managed_callback_id_v1($id);
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$user,$id,$now){
        $owner=(int)($user['id']??0);
        vp3_managed_callback_owner_lock_v1($pdo,$owner);
        $r=vp3_managed_callback_row_v1($pdo,$id,true);
        vp3_managed_callback_need_v1(is_array($r)&&(int)$r['owner_user_id']===$owner,404);
        if($r['state']==='canceled')return vp3_managed_callback_view_v1($r,$now,false,$pdo);
        vp3_managed_callback_need_v1(in_array($r['state'],['pending','approved'],true));
        vp3_managed_recording_revoke_v1($pdo,$id,$now);
        vp3_native_dialthrough_revoke_v1($pdo,$id,$now);
        $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='canceled',holder_e164='',destination_e164='',updated_epoch=? WHERE public_id=? AND state IN ('pending','approved')")->execute([$now,$id]);
        return vp3_managed_callback_view_v1(vp3_managed_callback_row_v1($pdo,$id),$now,false,$pdo);
    });
}
