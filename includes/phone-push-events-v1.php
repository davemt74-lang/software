<?php
declare(strict_types=1);

/** Canonical sources for PS5C.4. A paired phone is never a general owner inbox.
 * These reads run under the delivery core's owner/device locks. They neither
 * create a Chat thread nor grant access to contacts, voicemail or another phone.
 */
function vp3_phone_push_source_target_v1(PDO $pdo,array $d,array $source,int $now):?array
{
    if($d['device_status']!=='approved'||(int)$d['expires_at']<=$now
        ||!is_int($source['expires_epoch']??null)||$source['expires_epoch']<=$now)return null;
    $type=$source['type']??null;$id=$source['id']??null;$revision=$source['revision']??null;
    if($type==='test')return is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id)===1&&$revision===1?['tab'=>'info']:null;
    if($type==='agent_item'){
        require_once __DIR__.'/phone-agent-workspace-v1.php';
        // This opaque navigation target never grants private content access.
        // The owner-only workspace API independently requires the real session.
        return vp3_phone_agent_workspace_push_current_v1($pdo,$d,$source,$now)
            ?['tab'=>'agent','item_ref'=>$id]:null;
    }
    if($type==='private_chat'){
        if(!is_string($id)||preg_match('/^[1-9][0-9]{0,18}$/D',$id)!==1||$revision!==1)return null;
        require_once __DIR__.'/paired-phone-agent-v1.php';
        if(!vp3_paired_phone_ready_v1($pdo))return null;
        $q=vp3_phone_sql_v1($pdo,'SELECT t.request_id,t.agent_id,t.conversation_id FROM vp3_paired_phone_owner_replies_v1 o
            JOIN vp3_paired_phone_turns_v1 t ON t.user_message_id=o.phone_message_id
            JOIN vp3_paired_phone_threads_v1 b ON b.owner_user_id=t.owner_user_id AND b.device_id=t.device_id
                AND b.agent_id=t.agent_id AND b.conversation_id=t.conversation_id
            JOIN chat_messages m ON m.id=o.owner_message_id AND m.user_id=t.owner_user_id AND m.conversation_id=t.conversation_id
            WHERE o.owner_message_id=? AND t.owner_user_id=? AND t.device_id=?',[$id,(int)$d['owner_user_id'],$d['id']]);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row||!vp3_phone_camera_scope_v1($pdo,(int)$d['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id']))return null;
        return ['tab'=>'chat','request_id'=>$row['request_id']];
    }
    if($type==='agent_request'){
        if(!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1||!is_int($revision)||$revision<1
            ||!vp3_phone_communication_ready_v1($pdo)||!vp3_phone_information_ready_v1($pdo))return null;
        $p=vp3_phone_communication_row_v1($pdo,$d);
        if(!vp3_phone_information_allowed_v1($p)||(int)$p['revision']!==$revision)return null;
        $q=vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_information_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?
            AND profile_id=? AND profile_revision=? AND version=1 AND state='pending' AND expires_epoch>?",
            [(int)$d['owner_user_id'],$d['id'],$id,$p['profile_id'],$revision,$now]);
        $found=(bool)$q->fetchColumn();$q->closeCursor();
        return $found?['tab'=>'requests','exchange_id'=>$id]:null;
    }
    return null;
}

function vp3_phone_push_source_current_v1(PDO $pdo,array $d,array $source,int $now):bool
{return vp3_phone_push_source_target_v1($pdo,$d,$source,$now)!==null;}

/** Optional, atomic source hook. Missing upgrade/opt-in is a normal no-op.
 * An enabled queue write failure rolls back with its source, so retrying the
 * original action cannot silently lose the notification or create a duplicate.
 */
function vp3_phone_push_event_enqueue_v1(PDO $pdo,array $d,array $source,int $now,?string $testKey=null):?array
{
    if(!vp3_phone_push_delivery_ready_v1($pdo))return null;
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$d,$source,$now,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$d['owner_user_id'],$d['id'],$now,$testKey);
        if($ctx['reason']!==''||!hash_equals($d['token_hash'],$ctx['device']['token_hash']))return [];
        try{return vp3_phone_push_delivery_enqueue_v1($pdo,$d,$source,$now,'vp3_phone_push_source_current_v1',$testKey);}
        catch(RuntimeException $error){
            if($error->getCode()!==429)throw $error;
            // A bounded optional notification queue must not prevent the
            // primary message/request from being saved. Record the skip for
            // the administrator; an explicit holder test still receives429.
            vp3_phone_push_delivery_capacity_skipped_v1($pdo,$now);
            return ['state'=>'skipped','code'=>'queue_capacity'];
        }
    })?:null;
}

/** Reached only through the existing authenticated holder API transaction. */
function vp3_phone_push_delivery_holder_v1(PDO $pdo,array $d,string $action,array $input,int $now,?string $testKey=null):array
{
    vp3_phone_push_delivery_need_v1($d['device_status']==='approved'&&(int)$d['expires_at']>$now&&($input['device_id']??null)===$d['id'],403);
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    if($action==='push_test'){
        vp3_phone_push_delivery_need_v1(!array_diff(array_keys($input),['device_id','subscription_revision','confirmed','request_id'])
            &&($input['confirmed']??null)===true&&is_int($input['subscription_revision']??null)
            &&is_string($input['request_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$input['request_id'])===1);
        $s=vp3_phone_push_row_v1($pdo,$d,true);
        vp3_phone_push_delivery_need_v1($s!==null&&$input['subscription_revision']===(int)$s['revision'],409);
        return vp3_phone_push_delivery_enqueue_v1($pdo,$d,['type'=>'test','id'=>$input['request_id'],'revision'=>1,'expires_epoch'=>$now+600],
            $now,'vp3_phone_push_source_current_v1',$testKey);
    }
    vp3_phone_push_delivery_need_v1($action==='push_resolve'&&!array_diff(array_keys($input),['device_id','event_ref'])
        &&is_string($input['event_ref']??null)&&preg_match('/^[a-f0-9]{32}$/D',$input['event_ref'])===1);
    $receipt=vp3_phone_push_delivery_resolve_v1($pdo,$d,$input['event_ref'],$now,'vp3_phone_push_source_current_v1',$testKey);
    $target=vp3_phone_push_source_target_v1($pdo,$d,$receipt['source'],$now);
    vp3_phone_push_delivery_need_v1($target!==null,404);
    unset($receipt['source']);
    return $receipt+['target'=>$target];
}
