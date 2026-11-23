<?php
declare(strict_types=1);

/** All three projections are scoped to the signed-in owner's matching private conversation. */
function vp3_paired_phone_pending_v1(PDO $pdo, int $uid, int $aid, int $cid): bool
{
    return vp3_paired_phone_ready_v1($pdo) && (bool)vp3_phone_sql_v1($pdo,
        'SELECT 1 FROM vp3_paired_phone_turns_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
         AND (announced_prompt=0 OR (announced_reply=0 AND assistant_message_id IS NOT NULL)) LIMIT 1',
        [$uid,$aid,$cid])->fetchColumn();
}

function vp3_paired_phone_owner_text_v1(array $row, bool $prompt): string
{
    $name = vp3_phone_chat_label_v1(['name'=>$row['device_name'] ?? 'Phone']);
    return ($prompt ? 'From ' : 'Reply to ').$name."\n".($prompt ? $row['message'] : $row['reply']);
}

/** The caller holds the owner account lock. Each newly saved side of the exchange is announced once. */
function vp3_paired_phone_owner_updates_v1(PDO $pdo,int $uid,int $aid,int $cid): array
{
    $rows=vp3_phone_sql_v1($pdo,'SELECT t.*,d.device_name FROM vp3_paired_phone_turns_v1 t
        LEFT JOIN vp3_phone_devices_v1 d ON d.owner_user_id=t.owner_user_id AND d.id=t.device_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?
        AND (t.announced_prompt=0 OR (t.announced_reply=0 AND t.assistant_message_id IS NOT NULL))
        ORDER BY t.user_message_id LIMIT 10',[$uid,$aid,$cid])->fetchAll(PDO::FETCH_ASSOC);
    $updates=[];
    foreach ($rows as $row) {
        $hasReply=$row['assistant_message_id']!==null;
        foreach ([true,false] as $prompt) {
            if ($prompt ? $row['announced_prompt'] : (!$hasReply || $row['announced_reply'])) continue;
            $updates[]=['owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid,
                'message_id'=>(int)$row[$prompt?'user_message_id':'assistant_message_id'],
                'speech'=>vp3_paired_phone_owner_text_v1($row,$prompt)];
        }
        vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_turns_v1 SET announced_prompt=1,announced_reply=?
            WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND user_message_id=?',
            [(int)$hasReply,$uid,$aid,$cid,$row['user_message_id']]);
    }
    usort($updates,static fn(array $a,array $b): int=>$a['message_id']<=>$b['message_id']);
    return $updates;
}

function vp3_paired_phone_owner_history_v1(PDO $pdo,int $uid,int $aid,int $cid,array $rows,array $markers): array
{
    if (!vp3_paired_phone_ready_v1($pdo)) return $rows;
    $ids=array_keys($markers);
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $records=vp3_phone_sql_v1($pdo,"SELECT t.user_message_id,t.assistant_message_id,t.message,t.reply,d.device_name
        FROM vp3_paired_phone_turns_v1 t LEFT JOIN vp3_phone_devices_v1 d ON d.owner_user_id=t.owner_user_id AND d.id=t.device_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?
        AND (t.user_message_id IN ($slots) OR t.assistant_message_id IN ($slots))",[$uid,$aid,$cid,...$ids,...$ids]);
    foreach ($records->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ([true,false] as $prompt) {
            $id=(int)$row[$prompt?'user_message_id':'assistant_message_id'];
            if (!isset($markers[$id])) continue;
            $i=$markers[$id];
            if ($prompt) {
                $rows[$i]['message']=$row['message'];
                $rows[$i]['context_json']=json_encode(['source'=>'paired_phone_agent',
                    'phone_sender'=>vp3_phone_chat_label_v1(['name'=>$row['device_name'] ?? 'Phone'])],JSON_THROW_ON_ERROR);
            } else $rows[$i]['message']=vp3_paired_phone_owner_text_v1($row,false);
        }
    }
    return $rows;
}

function vp3_paired_phone_owner_links_v1(PDO $pdo,int $uid,array $devices): array
{
    if (!$devices || !vp3_paired_phone_ready_v1($pdo)) return $devices;
    $ids=array_column($devices,'id');
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $rows=vp3_phone_sql_v1($pdo,"SELECT t.device_id,t.agent_id,t.conversation_id FROM vp3_paired_phone_threads_v1 t
        JOIN chat_conversations c ON c.user_id=t.owner_user_id AND c.id=t.conversation_id AND COALESCE(c.user_agent_id,0)=t.agent_id
        WHERE t.owner_user_id=? AND t.device_id IN ($slots)",[$uid,...$ids])->fetchAll(PDO::FETCH_ASSOC);
    $byDevice=array_column($rows,null,'device_id');
    foreach ($devices as &$device) {
        $thread=$byDevice[$device['id']] ?? null;
        if ($device['status']==='approved' && $thread) {
            $device['agent_chat']=['agent_id'=>(int)$thread['agent_id'],'conversation_id'=>(int)$thread['conversation_id']];
        }
    }
    return $devices;
}
