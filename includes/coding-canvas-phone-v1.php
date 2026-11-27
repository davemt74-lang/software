<?php
declare(strict_types=1);
require_once __DIR__.'/phone-agent-workspace-v1.php';
require_once __DIR__.'/coding-canvas-store-v1.php';

function vp3_coding_canvas_phone_source_v1(PDO $pdo,array $user,int $agent,int $conversation,string $canvas,int $revision):array
{
    $source=vp3_phone_agent_workspace_source_v1($pdo,$user,$agent,'canvas',$canvas,time());
    vp3_phone_agent_workspace_need_v1($source!==null&&$source['conversation_id']===$conversation&&$source['target']['revision']===$revision,409);
    return $source;
}
/** Main-app session only. A paired native Canvas cannot enumerate or grant other phones. */
function vp3_coding_canvas_phone_v1(PDO $pdo,array $user,int $agent,int $conversation,string $canvas,int $revision,string $action,array $input):array
{
    vp3_phone_agent_workspace_need_v1(!defined('VP3_PHONE_AGENT_NATIVE_V1'),403);
    if(session_status()!==PHP_SESSION_ACTIVE)vp3_phone_agent_workspace_need_v1(session_id()!==''&&session_start(),401);
    try{
        $user=vp3_phone_agent_workspace_owner_v1($pdo,$user);
        if(!vp3_phone_agent_device_grants_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to send a Canvas to a connected phone.',503);
        $source=vp3_coding_canvas_phone_source_v1($pdo,$user,$agent,$conversation,$canvas,$revision);
        if($action==='phone_targets'){
            $rows=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND device_status='approved' AND expires_at>? ORDER BY device_name,id LIMIT 32",[(int)$user['id'],time()])->fetchAll(PDO::FETCH_ASSOC);
            return ['phones'=>array_map(static fn(array $d):array=>['id'=>$d['id'],'name'=>$d['device_name'],'generation'=>(int)$d['generation'],'agent_access'=>vp3_phone_agent_device_owner_view_v1($pdo,$d)],$rows),
                'canvas_id'=>$canvas,'revision'=>$revision,'agent'=>vp3_phone_agent_workspace_agent_v1($pdo,(int)$user['id'],$agent)];
        }
        vp3_phone_agent_workspace_need_v1($action==='phone_send'&&($input['confirmed']??null)===true);
        $id=vp3_phone_agent_workspace_id_v1($input['device_id']??null);
        vp3_phone_agent_workspace_need_v1(is_string($input['request_id']??null));$request=vp3_coding_canvas_id_v1($input['request_id']);
        $signature=hash('sha256',json_encode([$agent,$conversation,$canvas,$revision,$id,$input['generation']??null,$input['expected_revision']??null,$source['source_revision']],JSON_THROW_ON_ERROR));
        return vp3_phone_transaction_v1($pdo,static function()use($pdo,$user,$agent,$conversation,$canvas,$revision,$source,$input,$id,$request,$signature):array{
            $user=vp3_phone_agent_workspace_owner_v1($pdo,$user,true);$owner=(int)$user['id'];$now=time();$d=vp3_phone_row_v1($pdo,$owner,$id);
            vp3_phone_agent_workspace_need_v1($d['device_status']==='approved'&&(int)$d['expires_at']>$now,403);vp3_phone_generation_v1($d,$input);
            $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$id,true);
            $prior=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_canvas_deliveries_v1 WHERE owner_user_id=? AND request_id=?',[$owner,$request])->fetch(PDO::FETCH_ASSOC);
            if($prior){
                vp3_phone_agent_workspace_need_v1(hash_equals($prior['signature'],$signature)&&$e!==null&&(int)$e['enabled']===1
                    &&hash_equals($e['grant_id'],$prior['grant_id'])&&hash_equals($e['credential_hash'],$d['token_hash'])
                    &&hash_equals($e['owner_fingerprint'],hash('sha256',$owner.':'.(string)$user['password_hash']))&&vp3_phone_agent_device_marker_v1($pdo,$e),409);
                return ['phone_delivery'=>['device_id'=>$id,'device_name'=>$d['device_name'],'item_ref'=>$prior['item_ref'],'revision'=>$revision,'duplicate'=>true]];
            }
            vp3_phone_agent_workspace_need_v1(is_int($input['expected_revision']??null)&&$input['expected_revision']===(int)($e['revision']??0),409);
            $live=vp3_coding_canvas_phone_source_v1($pdo,$user,$agent,$conversation,$canvas,$revision);
            vp3_phone_agent_workspace_need_v1(hash_equals($source['source_revision'],$live['source_revision']),409);
            vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_canvas_deliveries_v1 WHERE owner_user_id=? AND created_epoch<=?',[$owner,$now-30*86400]);
            $count=(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_canvas_deliveries_v1 WHERE owner_user_id=? AND device_id=? AND created_epoch>?',[$owner,$id,$now-86400])->fetchColumn();
            vp3_phone_agent_workspace_need_v1($count<32,429);
            if(!$e||!(int)$e['enabled']||(int)$e['selected_agent_id']!==$agent||!vp3_phone_agent_device_marker_v1($pdo,$e)
                ||!hash_equals($e['credential_hash'],$d['token_hash'])||!hash_equals($e['owner_fingerprint'],hash('sha256',$owner.':'.(string)$user['password_hash']))){
                vp3_phone_agent_device_grant_v1($pdo,$user,$d,['device_id'=>$id,'generation'=>(int)$d['generation'],'expected_revision'=>(int)($e['revision']??0),'enabled'=>true,'confirmed'=>true,'agent_id'=>$agent,'notifications_enabled'=>$e!==null&&(bool)$e['notifications_enabled']],$now);
                $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$id,true);
            }
            $row=vp3_phone_agent_workspace_store_v1($pdo,$owner,$agent,$live,$now);
            // Deliver again to this phone without altering another phone's read receipt.
            vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_agent_receipts_v1 WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?',[$owner,$id,$e['grant_id'],$row['item_ref']]);
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_canvas_deliveries_v1(owner_user_id,device_id,request_id,signature,grant_id,item_ref,created_epoch) VALUES(?,?,?,?,?,?,?)',[$owner,$id,$request,$signature,$e['grant_id'],$row['item_ref'],$now]);
            $event=vp3_phone_push_event_enqueue_v1($pdo,$d,['type'=>'agent_item','id'=>$row['item_ref'],'revision'=>(int)$row['revision'],'expires_epoch'=>$now+3600],$now);
            $last=vp3_coding_canvas_phone_source_v1($pdo,$user,$agent,$conversation,$canvas,$revision);
            vp3_phone_agent_workspace_need_v1(hash_equals($live['source_revision'],$last['source_revision']),409);
            return ['phone_delivery'=>['device_id'=>$id,'device_name'=>$d['device_name'],'item_ref'=>$row['item_ref'],'revision'=>$revision,'duplicate'=>false,
                'notification_queued'=>$event!==null&&in_array($event['state']??null,['queued','retry','accepted'],true)]];
        });
    }finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
