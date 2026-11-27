<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-workspace-v1.php';
require_once __DIR__.'/phone-agent-workspace-sources-v1.php';
require_once __DIR__.'/phone-push-events-v1.php';

/** A worker verifies a saved explicit owner enrollment without opening or manufacturing a session. */
function vp3_phone_agent_workspace_worker_scope_v1(PDO $pdo,array $expected,int $now,bool $lock=false): array
{
    $owner=(int)$expected['owner_user_id'];$id=$expected['device_id'];
    $user=vp3_phone_agent_workspace_user_v1($pdo,$owner,$lock);
    $device=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$id])->fetch(PDO::FETCH_ASSOC);
    $enrollment=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$id,$lock);
    vp3_phone_agent_workspace_need_v1($device!==false&&$device['device_status']==='approved'&&(int)$device['expires_at']>$now
        &&$enrollment!==null&&(int)$enrollment['enabled']===1&&(int)$enrollment['notifications_enabled']===1
        &&(int)$enrollment['notifications_since_epoch']>0&&hash_equals($enrollment['credential_hash'],$device['token_hash'])
        &&hash_equals($enrollment['owner_fingerprint'],hash('sha256',$owner.':'.(string)($user['password_hash']??''))),403);
    foreach(['grant_id','credential_hash','owner_fingerprint'] as $field)
        vp3_phone_agent_workspace_need_v1(is_string($expected[$field]??null)&&hash_equals($enrollment[$field],$expected[$field]),409);
    foreach(['revision','selected_agent_id','conversation_id','notifications_since_epoch'] as $field)
        vp3_phone_agent_workspace_need_v1((int)$enrollment[$field]===(int)($expected[$field]??-1),409);
    $agent=(int)$enrollment['selected_agent_id'];
    vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent,(int)$enrollment['conversation_id']);
    vp3_phone_agent_source_scope_v1($pdo,$user,$agent);
    return ['user'=>$user,'device'=>$device,'enrollment'=>$enrollment];
}

/** Silently index old visible work; only a newer native semantic update may request an opted-in push. */
function vp3_phone_agent_workspace_worker_enrollment_v1(PDO $pdo,array $expected,int $now): array
{
    $context=vp3_phone_agent_workspace_worker_scope_v1($pdo,$expected,$now);
    $owner=(int)$context['user']['id'];$agent=(int)$context['enrollment']['selected_agent_id'];
    $discovery=vp3_phone_agent_sources_discover_v1($pdo,$context['user'],$agent,$now,40);
    // Explicit wakes must not disappear behind the newest-source discovery cap.
    // Rotate bounded pending receipts using internal metadata, without marking
    // anything read/seen or altering the receipt's public concurrency revision.
    $wakeHints=vp3_phone_transaction_v1($pdo,static function()use($pdo,$expected,$owner,$agent,$now):array{
        $current=vp3_phone_agent_workspace_worker_scope_v1($pdo,$expected,$now,true);$e=$current['enrollment'];
        $rows=vp3_phone_sql_v1($pdo,"SELECT r.item_ref,r.source_revision,r.wake_revision,i.source_kind,i.source_id
            FROM vp3_phone_agent_receipts_v1 r JOIN vp3_phone_agent_items_v1 i ON i.owner_user_id=r.owner_user_id AND i.item_ref=r.item_ref AND i.source_revision=r.source_revision
            LEFT JOIN vp3_phone_agent_alerts_v1 a ON a.owner_user_id=r.owner_user_id AND a.device_id=r.device_id AND a.grant_id=r.grant_id AND a.item_ref=r.item_ref AND a.item_revision=i.revision AND a.wake_revision=r.wake_revision
            WHERE r.owner_user_id=? AND r.device_id=? AND r.grant_id=? AND i.agent_id=? AND r.wake_revision>0 AND r.wake_requested_epoch>?
                AND r.read_epoch IS NULL AND r.acknowledged_epoch IS NULL AND (r.snoozed_until IS NULL OR r.snoozed_until<=?) AND a.item_ref IS NULL
            ORDER BY r.updated_epoch,r.item_ref LIMIT 10",[$owner,$e['device_id'],$e['grant_id'],$agent,(int)$e['notifications_since_epoch'],$now])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row)vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_receipts_v1 SET updated_epoch=? WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=? AND source_revision=? AND wake_revision=?',
            [$now,$owner,$e['device_id'],$e['grant_id'],$row['item_ref'],$row['source_revision'],$row['wake_revision']]);
        return $rows;
    });
    foreach($wakeHints as $hint){
        try{$candidate=vp3_phone_agent_workspace_source_v1($pdo,$context['user'],$agent,$hint['source_kind'],$hint['source_id'],$now);}
        catch(RuntimeException|DomainException|JsonException $error){if($error instanceof PDOException)throw $error;continue;}
        if($candidate!==null&&hash_equals($hint['source_revision'],$candidate['source_revision']))$discovery['items'][]=$candidate;
    }
    vp3_phone_agent_workspace_worker_scope_v1($pdo,$expected,$now);
    $stored=vp3_phone_agent_workspace_ingest_v1($pdo,$owner,$agent,$discovery['items'],$now);
    $candidates=[];
    foreach($discovery['items'] as $candidate)$candidates[$candidate['source_kind'].':'.$candidate['source_id']]=$candidate;
    $queue=vp3_phone_transaction_v1($pdo,static function()use($pdo,$expected,$now,$owner,$agent,$stored,$candidates):array{
        $current=vp3_phone_agent_workspace_worker_scope_v1($pdo,$expected,$now,true);
        $since=(int)$current['enrollment']['notifications_since_epoch'];$queued=0;
        foreach($stored as $reference){
            $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=? AND item_ref=?',
                [$owner,$agent,$reference['item_ref']])->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['revision']!==$reference['revision'])continue;
            $candidate=$candidates[$row['source_kind'].':'.$row['source_id']]??null;
            $receipt=vp3_phone_agent_workspace_receipt_v1($pdo,['enrollment'=>$current['enrollment']],$row);
            $wake=(int)($receipt['wake_revision']??0);
            if($candidate===null||!hash_equals($candidate['source_revision'],$row['source_revision'])
                ||($candidate['notification_eligible']??true)===false
                ||($candidate['updated_epoch']<=$since&&(!$wake||(int)($receipt['wake_requested_epoch']??0)<=$since))
                ||!in_array($candidate['state'],['ready','needs_review'],true))continue;
            $event=vp3_phone_push_event_enqueue_v1($pdo,$current['device'],['type'=>'agent_item','id'=>$row['item_ref'],
                'revision'=>(int)$row['revision'],'expires_epoch'=>min($now+3600,(int)$current['device']['expires_at'])]
                +($wake>0?['wake_revision'=>$wake]:[]),$now);
            if(is_array($event)&&in_array($event['state']??null,['queued','retry','sending','accepted'],true))$queued++;
        }
        return ['eligible_events'=>$queued];
    });
    return ['indexed'=>count($stored),'eligible_events'=>$queue['eligible_events'],'coverage'=>$discovery['coverage'],'has_more'=>$discovery['has_more']];
}

/** Bounded rotating background ingestion. This does not dispatch push HTTP, inference, Gmail, or calls. */
function vp3_phone_agent_workspace_sweep_v1(PDO $pdo,int $now,int $limit=10): array
{
    if($now<1||$limit<1||$limit>25)throw new InvalidArgumentException('Choose a bounded Phone Agent sweep.',422);
    $out=['state'=>'upgrade_required','scopes_checked'=>0,'indexed'=>0,'eligible_events'=>0,'unavailable'=>0];
    if(!vp3_phone_agent_workspace_worker_ready_v1($pdo))return $out;
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Phone Agent ingestion requires an independent worker boundary.');
    $out['state']='ready';
    $query=$pdo->prepare("SELECT e.* FROM vp3_phone_agent_enrollments_v1 e
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=e.owner_user_id AND d.id=e.device_id
        LEFT JOIN vp3_phone_agent_ingestion_v1 s ON s.owner_user_id=e.owner_user_id AND s.device_id=e.device_id
        WHERE e.enabled=1 AND e.notifications_enabled=1 AND e.notifications_since_epoch>0
            AND d.device_status='approved' AND d.expires_at>? AND d.token_hash=e.credential_hash
        ORDER BY COALESCE(s.checked_epoch,0),e.owner_user_id,e.device_id LIMIT ".$limit);
    $query->execute([$now]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $enrollment){
        $out['scopes_checked']++;
        try{
            $result=vp3_phone_agent_workspace_worker_enrollment_v1($pdo,$enrollment,$now);
            $out['indexed']+=$result['indexed'];$out['eligible_events']+=$result['eligible_events'];
        }catch(RuntimeException|DomainException|JsonException $error){
            $out['unavailable']++;
        }
        // Recording only rotation time cannot renew a grant, change a preference, or acknowledge an item.
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$enrollment,$now):array{
            $params=[(int)$enrollment['owner_user_id'],$enrollment['device_id']];
            $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            vp3_phone_sql_v1($pdo,$insert.' INTO vp3_phone_agent_ingestion_v1(owner_user_id,device_id,checked_epoch) VALUES(?,?,0)',$params);
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_ingestion_v1 SET checked_epoch=? WHERE owner_user_id=? AND device_id=?',[$now,...$params]);
            return ['updated'=>true];
        });
    }
    return $out;
}
