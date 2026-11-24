<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** One replacement authorization per approved pairing. No raw bearer persisted. */
function vp3_phone_handoff_install_v1(PDO $pdo,string $options):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install phone handoff outside transactions.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_handoffs_v1 (
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        public_id CHAR(32) NOT NULL UNIQUE,code_hash CHAR(64) NOT NULL UNIQUE,
        source_hash CHAR(64) NOT NULL,source_generation BIGINT NOT NULL,
        source_profile_revision BIGINT NOT NULL,
        target_hash CHAR(64) NULL,comparison_code CHAR(8) NULL,
        state VARCHAR(16) NOT NULL,created_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
}
function vp3_phone_handoff_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT public_id,code_hash,source_hash,source_generation,source_profile_revision,target_hash,comparison_code,state,expires_epoch FROM vp3_phone_handoffs_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_phone_handoff_need_v1(bool $ok,int $status=409):void
{if(!$ok)throw new RuntimeException('Phone installation transfer unavailable.', $status);}
function vp3_phone_handoff_row_v1(PDO $pdo,array $d,bool $lock=false):?array
{
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_handoffs_v1 WHERE owner_user_id=? AND device_id=?'.($lock?vp3_phone_lock_v1($pdo):''),[(int)$d['owner_user_id'],$d['id']]);
    $r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
function vp3_phone_handoff_profile_revision_v1(PDO $pdo,array $d):int
{
    return vp3_phone_communication_ready_v1($pdo)?(int)vp3_phone_communication_row_v1($pdo,$d)['revision']:0;
}
function vp3_phone_handoff_current_v1(PDO $pdo,array $d,array $r,int $now):bool
{
    return $d['device_status']==='approved'&&(int)$d['expires_at']>$now&&(int)$r['expires_epoch']>$now
        &&(int)$r['source_generation']===(int)$d['generation']&&hash_equals($r['source_hash'],$d['token_hash'])
        &&(int)$r['source_profile_revision']===vp3_phone_handoff_profile_revision_v1($pdo,$d);
}
function vp3_phone_handoff_view_v1(PDO $pdo,array $d,int $now,bool $owner=false):?array
{
    if(!vp3_phone_handoff_ready_v1($pdo)||$d['device_status']!=='approved'||(int)$d['expires_at']<=$now)return null;
    $r=vp3_phone_handoff_row_v1($pdo,$d);
    if(!$r||!vp3_phone_handoff_current_v1($pdo,$d,$r,$now)||!in_array($r['state'],['offered','claimed','approved'],true))return ['state'=>'none'];
    $out=['state'=>$r['state'],'transfer_id'=>$r['public_id'],'expires_epoch'=>(int)$r['expires_epoch'],'server_epoch'=>$now];
    if($owner&&in_array($r['state'],['claimed','approved'],true))$out['comparison_code']=$r['comparison_code'];
    return $out;
}
/** Before moving, end live sharing, calls and unresolved recordings. No provider I/O here. */
function vp3_phone_handoff_idle_v1(PDO $pdo,array $d,int $now):void
{
    vp3_phone_handoff_need_v1(in_array($d['location_state'],['off','denied'],true)&&$d['history_state']==='off');
    $scope=[(int)$d['owner_user_id'],$d['id']];
    if(vp3_phone_camera_ready_v1($pdo)){
        $q=vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND device_id=? AND state<>'stopped' AND expires_at>? LIMIT 1",[...$scope,$now]);
        vp3_phone_handoff_need_v1(!$q->fetchColumn());
    }
    if(schema_metadata_table_exists($pdo,'phone_browser_calls_v1')){
        $q=vp3_phone_sql_v1($pdo,"SELECT 1 FROM phone_browser_calls_v1 WHERE owner_user_id=? AND paired_device_id=? AND state IN ('prepared','dialing','ringing','answered') AND (state<>'prepared' OR intent_expires_epoch>?) LIMIT 1",[...$scope,$now]);
        vp3_phone_handoff_need_v1(!$q->fetchColumn());
    }
    if(schema_metadata_table_exists($pdo,'phone_managed_recording_consent_v1')){
        $q=vp3_phone_sql_v1($pdo,"SELECT 1 FROM phone_managed_recording_consent_v1 c JOIN phone_managed_callback_requests_v1 r ON r.public_id=c.request_id AND r.owner_user_id=c.owner_user_id WHERE r.owner_user_id=? AND r.device_id=? AND c.start_epoch>0 AND c.start_state IN ('starting','started','uncertain') AND c.stop_state<>'terminal_verified' LIMIT 1",$scope);
        vp3_phone_handoff_need_v1(!$q->fetchColumn());
    }
}
/** Called from the existing holder cookie transaction. */
function vp3_phone_handoff_holder_v1(PDO $pdo,array $d,string $action,array $input,int $now):array
{
    vp3_phone_handoff_need_v1(vp3_phone_handoff_ready_v1($pdo),503);
    $allowed=$action==='handoff_offer'?['device_id','generation','confirmed']:['device_id','generation','transfer_id','confirmed'];
    vp3_phone_handoff_need_v1(!array_diff(array_keys($input),$allowed)&&($input['confirmed']??null)===true,422);
    vp3_phone_handoff_need_v1($d['device_status']==='approved'&&(int)$d['expires_at']>$now&&($input['device_id']??null)===$d['id'],403);
    vp3_phone_generation_v1($d,$input);
    $r=vp3_phone_handoff_row_v1($pdo,$d,true);
    if($action==='handoff_cancel'){
        vp3_phone_handoff_need_v1($r&&$r['public_id']===($input['transfer_id']??null)&&in_array($r['state'],['offered','claimed','approved'],true));
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_handoffs_v1 SET state='canceled',target_hash=NULL,comparison_code=NULL,updated_epoch=? WHERE public_id=?",[$now,$r['public_id']]);
        return ['handoff'=>['state'=>'none']];
    }
    vp3_phone_handoff_need_v1($action==='handoff_offer',422);
    vp3_phone_handoff_idle_v1($pdo,$d,$now);
    if($r){
        vp3_phone_handoff_need_v1(!vp3_phone_handoff_current_v1($pdo,$d,$r,$now)||!in_array($r['state'],['offered','claimed','approved'],true));
        vp3_phone_handoff_need_v1((int)$r['created_epoch']<=$now-30,429);
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_handoffs_v1 WHERE owner_user_id=? AND device_id=?',[(int)$d['owner_user_id'],$d['id']]);
    }
    $secret=vp3_phone_token_v1(random_bytes(32));$id=bin2hex(random_bytes(16));$expires=min($now+300,(int)$d['expires_at']);
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_handoffs_v1(owner_user_id,device_id,public_id,code_hash,source_hash,source_generation,source_profile_revision,state,created_epoch,expires_epoch,updated_epoch)
        VALUES(?,?,?,?,?,?,?,'offered',?,?,?)",[(int)$d['owner_user_id'],$d['id'],$id,vp3_extension_hash_v2000($secret),$d['token_hash'],(int)$d['generation'],vp3_phone_handoff_profile_revision_v1($pdo,$d),$now,$expires,$now]);
    return ['handoff_ticket'=>['transfer_id'=>$id,'code'=>$secret,'expires_epoch'=>$expires,'server_epoch'=>$now]];
}
function vp3_phone_handoff_owner_v1(PDO $pdo,array $user,string $action,array $input,int $now):array
{
    vp3_phone_handoff_need_v1(vp3_phone_handoff_ready_v1($pdo),503);
    vp3_phone_handoff_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    $allowed=['request_id','device_id','generation','transfer_id','comparison_code','confirmed'];
    vp3_phone_handoff_need_v1(!array_diff(array_keys($input),$allowed)&&($input['confirmed']??null)===true,422);
    $id=vp3_phone_camera_id_v1($input['device_id']??null);$owner=(int)($user['id']??0);
    return vp3_phone_transaction_v1($pdo,function()use($pdo,$owner,$id,$action,$input,$now){
        vp3_phone_account_v1($pdo,$owner,true);
        $d=vp3_phone_row_v1($pdo,$owner,$id);vp3_phone_generation_v1($d,$input);
        $r=vp3_phone_handoff_row_v1($pdo,$d,true);
        vp3_phone_handoff_need_v1($r&&vp3_phone_handoff_current_v1($pdo,$d,$r,$now)&&$r['public_id']===($input['transfer_id']??null));
        if($action==='handoff_owner_cancel'){
            vp3_phone_handoff_need_v1(in_array($r['state'],['offered','claimed','approved'],true));
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_handoffs_v1 SET state='canceled',target_hash=NULL,comparison_code=NULL,updated_epoch=? WHERE public_id=?",[$now,$r['public_id']]);
        }else{
            vp3_phone_handoff_need_v1($action==='handoff_approve'&&in_array($r['state'],['claimed','approved'],true)
                &&is_string($input['comparison_code']??null)&&hash_equals((string)$r['comparison_code'],$input['comparison_code']),422);
            vp3_phone_handoff_idle_v1($pdo,$d,$now);
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_handoffs_v1 SET state='approved',updated_epoch=? WHERE public_id=?",[$now,$r['public_id']]);
        }
        return ['handoff'=>vp3_phone_handoff_view_v1($pdo,$d,$now,true)];
    });
}
/** The next installation cannot carry over another holder's active grants. */
function vp3_phone_handoff_reset_v1(PDO $pdo,array &$d,int $now):void
{
    vp3_phone_push_revoke_v1($pdo,$d,$now);
    vp3_phone_end_location_v1($d,'off');
    $d['places_generation']=null;$d['place_permission_version']=(int)$d['place_permission_version']+1;
    vp3_phone_arrival_clear_v1($pdo,$d);
    vp3_phone_camera_disconnect_v1($pdo,$d);
    vp3_phone_dialer_clear_v1($pdo,$d);
    if(vp3_phone_communication_ready_v1($pdo)){
        $p=vp3_phone_communication_row_v1($pdo,$d,true);
        if((int)$p['revision']>0){
            $next=array_replace($p,['revision'=>(int)$p['revision']+1,'phone_revision'=>(int)$p['phone_revision']+1,
                'phone_e164'=>'','holder_calls'=>0,'holder_proactive'=>0,'holder_requests'=>0,'holder_memory'=>0,'updated_epoch'=>$now]);
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_communication_profiles_v1 SET revision=?,phone_revision=?,phone_e164='',holder_calls=0,holder_proactive=0,holder_requests=0,holder_memory=0,updated_epoch=? WHERE owner_user_id=? AND device_id=? AND revision=?",
                [$next['revision'],$next['phone_revision'],$now,(int)$d['owner_user_id'],$d['id'],(int)$p['revision']]);
            vp3_phone_information_fence_v1($pdo,$p,$next,$now);
        }
    }
    require_once __DIR__.'/phone-managed-callback-schema-v1.php';
    vp3_managed_callback_revoke_v1($pdo,(int)$d['owner_user_id'],$d['id'],$now);
}
/** Unpaired target proves the invitation and its own random claim key.
 * Owner must approve the displayed comparison code before explicit finish.
 * A successful retry with the same two secrets recovers a lost cookie receipt.
 */
function vp3_phone_handoff_target_v1(PDO $pdo,string $cookie,string $action,array $input,int $now):array
{
    vp3_phone_handoff_need_v1(vp3_phone_handoff_ready_v1($pdo),503);
    vp3_phone_handoff_need_v1(in_array($action,['handoff_claim','handoff_check','handoff_finish'],true)
        &&!array_diff(array_keys($input),['code','claim_key','confirmed'])
        &&vp3_phone_secret_valid_v1($input['code']??null)&&is_string($input['claim_key']??null)&&preg_match('/^[a-f0-9]{64}$/D',$input['claim_key'])===1,422);
    if($action!=='handoff_check')vp3_phone_handoff_need_v1(($input['confirmed']??null)===true,422);
    $hash=vp3_extension_hash_v2000($input['code']);
    $token=vp3_phone_token_v1(hash_hmac('sha256','vp3/phone/handoff/'.$input['claim_key'],$input['code'],true));
    $target=vp3_extension_hash_v2000($token);$comparison=strtoupper(substr(hash('sha256','vp3/handoff/compare/'.$target),0,8));
    // Do not replace another live cookie. A valid target cookie may replay only its completed transfer.
    if(vp3_phone_secret_valid_v1($cookie)){
        $old=vp3_phone_sql_v1($pdo,"SELECT token_hash FROM vp3_phone_devices_v1 WHERE token_hash=? AND device_status IN ('approved','pending') AND expires_at>?",[vp3_extension_hash_v2000($cookie),$now])->fetchColumn();
        vp3_phone_handoff_need_v1($old===false||hash_equals((string)$old,$target),409);
    }
    $hint=vp3_phone_sql_v1($pdo,'SELECT owner_user_id,device_id FROM vp3_phone_handoffs_v1 WHERE code_hash=?',[$hash])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_handoff_need_v1(is_array($hint),403);
    return vp3_phone_transaction_v1($pdo,function()use($pdo,$action,$hint,$hash,$target,$comparison,$token,$now){
        $owner=(int)$hint['owner_user_id'];$ownerName=vp3_phone_account_v1($pdo,$owner,true);
        $d=vp3_phone_row_v1($pdo,$owner,$hint['device_id']);$r=vp3_phone_handoff_row_v1($pdo,$d,true);
        vp3_phone_handoff_need_v1($r&&hash_equals($r['code_hash'],$hash)&&(int)$r['expires_epoch']>$now&&$d['device_status']==='approved'&&(int)$d['expires_at']>$now,403);
        $same=is_string($r['target_hash'])&&hash_equals($r['target_hash'],$target);
        if($r['state']==='finished'){
            vp3_phone_handoff_need_v1($same&&hash_equals($d['token_hash'],$target)&&$action==='handoff_finish',403);
            return ['token'=>$token,'owner_name'=>$ownerName,'device'=>vp3_phone_holder_public_v1($pdo,$d,$now),'handoff'=>['state'=>'finished']];
        }
        vp3_phone_handoff_need_v1(vp3_phone_handoff_current_v1($pdo,$d,$r,$now),403);
        if($action==='handoff_claim'&&$r['state']==='offered'){
            $q=vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_handoffs_v1 SET target_hash=?,comparison_code=?,state='claimed',updated_epoch=? WHERE public_id=? AND state='offered'",[$target,$comparison,$now,$r['public_id']]);
            vp3_phone_handoff_need_v1($q->rowCount()===1);
            $r['state']='claimed';$same=true;
        }
        vp3_phone_handoff_need_v1($same&&in_array($r['state'],['claimed','approved'],true),403);
        if($action!=='handoff_finish')return ['handoff'=>['state'=>$r['state'],'comparison_code'=>$comparison,'expires_epoch'=>(int)$r['expires_epoch'],'server_epoch'=>$now]];
        vp3_phone_handoff_need_v1($r['state']==='approved');
        vp3_phone_handoff_idle_v1($pdo,$d,$now);
        // Rotate the only device credential under the same owner/device lock.
        $q=vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE owner_user_id=? AND id=? AND token_hash=? AND generation=?',
            [$target,$owner,$d['id'],$d['token_hash'],(int)$d['generation']]);
        vp3_phone_handoff_need_v1($q->rowCount()===1);
        vp3_phone_handoff_reset_v1($pdo,$d,$now);
        $d['token_hash']=$target;$d['last_seen_at']=$now;vp3_phone_save_row_v1($pdo,$d);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_handoffs_v1 SET state='finished',comparison_code=NULL,updated_epoch=? WHERE public_id=?",[$now,$r['public_id']]);
        return ['token'=>$token,'owner_name'=>$ownerName,'device'=>vp3_phone_holder_public_v1($pdo,$d,$now),'handoff'=>['state'=>'finished']];
    });
}
function vp3_phone_handoff_revoke_v1(PDO $pdo,array $d):void
{
    if(vp3_phone_handoff_ready_v1($pdo))vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_handoffs_v1 WHERE owner_user_id=? AND device_id=?',[(int)$d['owner_user_id'],$d['id']]);
}
