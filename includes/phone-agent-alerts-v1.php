<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-workspace-v1.php';

/** Under the existing owner/device transaction. The durable marker contains no
 * source content or provider credentials, and never revives a cancelled send. */
function vp3_phone_agent_alert_enqueue_v1(PDO $pdo,array $device,array $source,int $now,?string $testKey=null):array
{
    if(!vp3_phone_transaction_active_v1($pdo))throw new LogicException('Agent alert identity requires source locks.');
    $source=vp3_phone_push_delivery_source_v1($source,$now);
    if(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$source,$now))return [];
    $owner=(int)$device['owner_user_id'];$grant=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$device['id']);
    $source['grant_id']=$grant['grant_id'];
    $params=[$owner,$device['id'],$grant['grant_id'],$source['id']];
    $old=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_alerts_v1 WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?'.vp3_phone_lock_v1($pdo),$params)->fetch(PDO::FETCH_ASSOC);
    $wake=(int)($source['wake_revision']??0);
    if($old&&(int)$old['item_revision']===$source['revision']&&(int)$old['wake_revision']===$wake)return ['state'=>'already_recorded'];
    $event=vp3_phone_push_delivery_enqueue_v1($pdo,$device,$source,$now,'vp3_phone_push_source_current_v1',$testKey);
    if($old)vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_alerts_v1 SET item_revision=?,wake_revision=?,event_ref=?,recorded_epoch=? WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?',
        [$source['revision'],$wake,$event['event_ref'],$now,...$params]);
    else vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_alerts_v1(owner_user_id,device_id,grant_id,item_ref,item_revision,wake_revision,event_ref,recorded_epoch) VALUES(?,?,?,?,?,?,?,?)',
        [...$params,$source['revision'],$wake,$event['event_ref'],$now]);
    return $event;
}
