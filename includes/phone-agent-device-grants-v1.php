<?php
declare(strict_types=1);

/** Main-app approval delegates only the paired phone's native AGENT workspace. */
function vp3_phone_agent_device_marker_v1(PDO $pdo,array $e):bool
{
    if(!vp3_phone_agent_device_grants_ready_v1($pdo))return false;
    $id=vp3_phone_sql_v1($pdo,'SELECT grant_id FROM vp3_phone_agent_device_grants_v1 WHERE owner_user_id=? AND device_id=?',[(int)$e['owner_user_id'],$e['device_id']])->fetchColumn();
    return is_string($id)&&hash_equals($e['grant_id'],$id);
}
/** No user_id or password fingerprint is written to the phone's anonymous session. */
function vp3_phone_agent_device_scope_v1(PDO $pdo,string $token,string $id,bool $lock,int $now):?array
{
    if(!vp3_phone_secret_valid_v1($token)||!vp3_phone_agent_workspace_ready_v1($pdo)||!vp3_phone_agent_device_grants_ready_v1($pdo))return null;
    $hint=vp3_phone_sql_v1($pdo,'SELECT owner_user_id FROM vp3_phone_devices_v1 WHERE id=? AND token_hash=?',[$id,vp3_extension_hash_v2000($token)])->fetchColumn();
    if(!$hint)return null;
    $user=vp3_phone_agent_workspace_user_v1($pdo,(int)$hint,$lock);
    $d=vp3_phone_agent_workspace_device_v1($pdo,(int)$hint,$token,$id,$now,$lock);
    $e=vp3_phone_agent_workspace_enrollment_v1($pdo,(int)$hint,$id,$lock);
    if(!$e||!vp3_phone_agent_device_marker_v1($pdo,$e))return null;
    vp3_phone_agent_workspace_need_v1((int)$e['enabled']===1&&hash_equals($e['credential_hash'],$d['token_hash'])
        &&hash_equals($e['owner_fingerprint'],hash('sha256',(int)$hint.':'.(string)($user['password_hash']??''))),403);
    $agent=vp3_phone_agent_workspace_agent_v1($pdo,(int)$hint,(int)$e['selected_agent_id']);
    vp3_phone_agent_workspace_chat_v1($pdo,(int)$hint,$agent['id'],(int)$e['conversation_id']);
    return ['user'=>$user,'device'=>$d,'enrollment'=>$e,'agent'=>$agent,'now'=>$now,'paired_access'=>true,
        'scope_revision'=>vp3_phone_agent_workspace_binding_v1($e,vp3_phone_agent_workspace_key_v1())];
}
function vp3_phone_agent_device_status_v1(PDO $pdo,array $scope):array
{
    $csrf=$_SESSION['csrf_token']??null;
    if(!is_string($csrf)||$csrf==='')$_SESSION['csrf_token']=$csrf=bin2hex(random_bytes(32));
    return vp3_phone_agent_workspace_envelope_v1($scope)+['state'=>'ready','enrolled'=>true,'owner_eligible'=>true,'enrollable'=>false,
        'authorization_mode'=>'paired_device','revision'=>(int)$scope['enrollment']['revision'],'agent'=>$scope['agent'],
        'agents'=>[$scope['agent']],'conversation_id'=>(int)$scope['enrollment']['conversation_id'],'csrf_token'=>$csrf,
        'preferences'=>['notifications_enabled'=>(bool)$scope['enrollment']['notifications_enabled']],'counts'=>null];
}
function vp3_phone_agent_device_owner_view_v1(PDO $pdo,array $d):array
{
    $ready=vp3_phone_agent_device_grants_ready_v1($pdo)&&vp3_phone_agent_workspace_ready_v1($pdo);
    $e=$ready?vp3_phone_agent_workspace_enrollment_v1($pdo,(int)$d['owner_user_id'],$d['id']):null;
    $password=$e?vp3_phone_sql_v1($pdo,'SELECT password_hash FROM users WHERE id=?',[(int)$d['owner_user_id']])->fetchColumn():null;
    return ['ready'=>$ready,'enabled'=>$e!==null&&(int)$e['enabled']===1&&hash_equals($e['credential_hash'],$d['token_hash'])
            &&is_string($password)&&hash_equals($e['owner_fingerprint'],hash('sha256',(int)$d['owner_user_id'].':'.$password))&&vp3_phone_agent_device_marker_v1($pdo,$e),
        'revision'=>(int)($e['revision']??0),'agent_id'=>(int)($e['selected_agent_id']??0),
        'notifications_enabled'=>$e!==null&&(bool)$e['notifications_enabled']];
}
/** Caller holds the owner and device locks; rotation invalidates all earlier phone views. */
function vp3_phone_agent_device_grant_v1(PDO $pdo,array $user,array $d,array $input,int $now):array
{
    vp3_phone_agent_workspace_need_v1(vp3_phone_agent_device_grants_ready_v1($pdo),503);
    vp3_phone_generation_v1($d,$input);
    vp3_phone_agent_workspace_need_v1((int)$user['id']===(int)$d['owner_user_id']&&($input['device_id']??null)===$d['id']&&$d['device_status']==='approved'&&(int)$d['expires_at']>$now,403);
    $e=vp3_phone_agent_workspace_enrollment_v1($pdo,(int)$user['id'],$d['id'],true);
    vp3_phone_agent_workspace_need_v1(is_int($input['expected_revision']??null)&&$input['expected_revision']===(int)($e['revision']??0),409);
    vp3_phone_agent_workspace_need_v1(is_bool($input['enabled']??null));
    if(!$input['enabled']){
        if($e)vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0,notifications_enabled=0,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[$now,(int)$user['id'],$d['id']]);
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_agent_device_grants_v1 WHERE owner_user_id=? AND device_id=?',[(int)$user['id'],$d['id']]);
        return vp3_phone_agent_device_owner_view_v1($pdo,$d);
    }
    vp3_phone_agent_workspace_need_v1(($input['confirmed']??null)===true&&is_int($input['agent_id']??null)&&$input['agent_id']>=0
        &&is_bool($input['notifications_enabled']??null));
    $agent=$input['agent_id'];vp3_phone_agent_workspace_agent_v1($pdo,(int)$user['id'],$agent);
    $cid=vp3_phone_agent_workspace_primary_chat_v1($pdo,$user,$agent);$grant=bin2hex(random_bytes(16));
    $fp=hash('sha256',(int)$user['id'].':'.(string)$user['password_hash']);$notify=(int)$input['notifications_enabled'];
    if($e)vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET grant_id=?,credential_hash=?,owner_fingerprint=?,enabled=1,revision=revision+1,selected_agent_id=?,conversation_id=?,notifications_enabled=?,notifications_since_epoch=?,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[$grant,$d['token_hash'],$fp,$agent,$cid,$notify,$notify?$now:0,$now,(int)$user['id'],$d['id']]);
    else vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_enrollments_v1(owner_user_id,device_id,grant_id,credential_hash,owner_fingerprint,enabled,selected_agent_id,conversation_id,notifications_enabled,notifications_since_epoch,created_epoch,updated_epoch) VALUES(?,?,?,?,?,1,?,?,?,?,?,?)',[(int)$user['id'],$d['id'],$grant,$d['token_hash'],$fp,$agent,$cid,$notify,$notify?$now:0,$now,$now]);
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_agent_device_grants_v1 WHERE owner_user_id=? AND device_id=?',[(int)$user['id'],$d['id']]);
    vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_device_grants_v1(owner_user_id,device_id,grant_id,approved_epoch) VALUES(?,?,?,?)',[(int)$user['id'],$d['id'],$grant,$now]);
    return vp3_phone_agent_device_owner_view_v1($pdo,$d);
}
function vp3_phone_agent_device_owner_action_v1(PDO $pdo,array $user,array $input):array
{
    $live=vp3_phone_agent_workspace_owner_v1($pdo,$user);$id=vp3_phone_agent_workspace_id_v1($input['device_id']??null);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$live,$id,$input){
        $user=vp3_phone_agent_workspace_owner_v1($pdo,$live,true);$d=vp3_phone_row_v1($pdo,(int)$user['id'],$id);
        $access=vp3_phone_agent_device_grant_v1($pdo,$user,$d,$input,time());
        return ['agent_access'=>$access,'device'=>vp3_phone_public_v1($d,time())+['agent_access'=>$access]];
    });
}
