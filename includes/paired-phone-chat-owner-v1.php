<?php
declare(strict_types=1);

function vp3_paired_phone_owner_reply_v1(PDO $pdo,array $user,array $principal,array $input): array
{
    if (array_diff(array_keys($input),['agent_id','conversation_id','device_id','request_id','message'])
        || !is_int($input['conversation_id']??null) || $input['conversation_id']<1
        || !is_int($input['agent_id']??null) || $input['agent_id']<0) throw new RuntimeException('Invalid phone reply.',422);
    $uid=(int)($user['id']??0); $aid=$input['agent_id']; $cid=$input['conversation_id'];
    if ($uid<=0 || ($principal['agent_id']??null)!==$aid) throw new RuntimeException('Phone Chat unavailable.',403);
    $device=vp3_phone_camera_id_v1($input['device_id']??null);
    $rid=vp3_paired_phone_request_id_v1($input['request_id']??null);
    $message=vp3_paired_phone_message_v1($input['message']??null);
    if (!vp3_paired_phone_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);

    return vp3_phone_transaction_v1($pdo,static function() use($pdo,$user,$principal,$input,$uid,$aid,$cid,$device,$rid,$message):array {
        vp3_phone_account_v1($pdo,$uid,true);
        vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
        $phone=vp3_phone_row_v1($pdo,$uid,$device);
        if ($phone['device_status']!=='approved' || (int)$phone['expires_at']<=time()) throw new RuntimeException('Phone unavailable.',403);
        $turn=vp3_phone_sql_v1($pdo,'SELECT t.user_message_id,t.state FROM vp3_paired_phone_turns_v1 t
            JOIN vp3_paired_phone_threads_v1 b ON b.device_id=t.device_id AND b.owner_user_id=t.owner_user_id
                AND b.agent_id=t.agent_id AND b.conversation_id=t.conversation_id
            WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND t.device_id=? AND t.request_id=?'.vp3_phone_lock_v1($pdo),
            [$uid,$aid,$cid,$device,$rid])->fetch(PDO::FETCH_ASSOC);
        if (!$turn) throw new RuntimeException('Phone message unavailable.',404);
        $original=(int)$turn['user_message_id'];
        $existing=vp3_phone_sql_v1($pdo,'SELECT owner_message_id,message FROM vp3_paired_phone_owner_replies_v1 WHERE phone_message_id=?',[$original])->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['message']!==$message) throw new RuntimeException('This phone message already has a reply.',409);
            return ['reply_message_id'=>(int)$existing['owner_message_id']];
        }
        // Invalidate an old deployment's still-running inference lease before the human response is delivered.
        if ($turn['state']==='pending') vp3_phone_sql_v1($pdo,"UPDATE vp3_paired_phone_turns_v1 SET state='waiting',lease=NULL,lease_until=0
            WHERE user_message_id=?",[$original]);
        $reply=vp3_paired_phone_marker_v1($pdo,$uid,$cid,'user');
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_paired_phone_owner_replies_v1(phone_message_id,owner_message_id,message) VALUES(?,?,?)',
            [$original,$reply,$message]);
        $now=time();
        vp3_phone_push_event_enqueue_v1($pdo,$phone,['type'=>'private_chat','id'=>(string)$reply,'revision'=>1,'expires_epoch'=>$now+86400],$now);
        return ['reply_message_id'=>$reply];
    });
}

function vp3_paired_phone_pending_v1(PDO $pdo,int $uid,int $aid,int $cid): bool
{
    return vp3_paired_phone_ready_v1($pdo) && (bool)vp3_phone_sql_v1($pdo,
        'SELECT 1 FROM vp3_paired_phone_turns_v1 t LEFT JOIN vp3_paired_phone_owner_replies_v1 o ON o.phone_message_id=t.user_message_id
         WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=?
         AND (t.announced_prompt=0 OR (t.announced_reply=0 AND t.assistant_message_id IS NOT NULL) OR o.announced=0) LIMIT 1',[$uid,$aid,$cid])->fetchColumn();
}

/** Both history and foreground announcements use the same scoped markers and reply action. */
function vp3_paired_phone_owner_rows_v1(PDO $pdo,int $uid,int $aid,int $cid,string $filter,array $params,int $limit=0): array
{
    return vp3_phone_sql_v1($pdo,"SELECT t.*,d.device_name,d.device_status,d.expires_at,
        o.owner_message_id,o.message AS owner_reply,o.announced AS announced_owner
        FROM vp3_paired_phone_turns_v1 t JOIN vp3_phone_devices_v1 d ON d.owner_user_id=t.owner_user_id AND d.id=t.device_id
        LEFT JOIN vp3_paired_phone_owner_replies_v1 o ON o.phone_message_id=t.user_message_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND ($filter)
        ORDER BY t.user_message_id".($limit>0?" LIMIT ".$limit:""),[$uid,$aid,$cid,...$params])->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_paired_phone_prompt_context_v1(array $row): array
{
    $name=vp3_phone_chat_label_v1(['name'=>$row['device_name']]);
    $context=['source'=>'paired_phone_agent','phone_sender'=>$name];
    if ($row['owner_message_id']===null && $row['device_status']==='approved' && (int)$row['expires_at']>time()) {
        $context['actions']=[['type'=>'phone_owner_reply','label'=>'Reply','device_id'=>$row['device_id'],'device_name'=>$name,
            'request_id'=>$row['request_id'],'message_id'=>(int)$row['user_message_id'],'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id']]];
    }
    return $context;
}

function vp3_paired_phone_reply_text_v1(array $row,bool $owner): string
{
    $name=vp3_phone_chat_label_v1(['name'=>$row['device_name']]);
    return ($owner?'Your reply to ':'Reply to ').$name."\n".($owner?$row['owner_reply']:$row['reply']);
}

/** Called inside the private Chat poll's owner lock. Each side is announced at most once. */
function vp3_paired_phone_owner_updates_v1(PDO $pdo,int $uid,int $aid,int $cid): array
{
    $rows=vp3_paired_phone_owner_rows_v1($pdo,$uid,$aid,$cid,
        't.announced_prompt=0 OR (t.announced_reply=0 AND t.assistant_message_id IS NOT NULL) OR o.announced=0',[],10);
    $updates=[];
    $scope=['owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid];
    foreach ($rows as $row) {
        $original=(int)$row['user_message_id'];
        if (!$row['announced_prompt']) {
            $context=vp3_paired_phone_prompt_context_v1($row);
            $updates[]=$scope+['message_id'=>$original,'speech'=>'From '.$context['phone_sender']."\n".$row['message'],'actions'=>$context['actions']??[]];
        }
        if ($row['assistant_message_id']!==null && !$row['announced_reply']) {
            $updates[]=$scope+['message_id'=>(int)$row['assistant_message_id'],'speech'=>vp3_paired_phone_reply_text_v1($row,false)];
        }
        if ($row['owner_message_id']!==null && !$row['announced_owner']) {
            $updates[]=$scope+['message_id'=>(int)$row['owner_message_id'],'speech'=>vp3_paired_phone_reply_text_v1($row,true),
                'phone_owner_reply'=>true,'phone_reply_to'=>$original];
            vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_owner_replies_v1 SET announced=1 WHERE phone_message_id=?',[$original]);
        }
        vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_turns_v1 SET announced_prompt=1,announced_reply=? WHERE user_message_id=?',
            [(int)($row['assistant_message_id']!==null),$original]);
    }
    usort($updates,static fn(array $a,array $b): int=>$a['message_id']<=>$b['message_id']);
    return $updates;
}

function vp3_paired_phone_owner_history_v1(PDO $pdo,int $uid,int $aid,int $cid,array $rows,array $markers): array
{
    if (!$markers || !vp3_paired_phone_ready_v1($pdo)) return $rows;
    $ids=array_keys($markers); $slots=implode(',',array_fill(0,count($ids),'?'));
    $records=vp3_paired_phone_owner_rows_v1($pdo,$uid,$aid,$cid,
        "t.user_message_id IN ($slots) OR t.assistant_message_id IN ($slots) OR o.owner_message_id IN ($slots)",[...$ids,...$ids,...$ids]);
    foreach ($records as $row) {
        $original=(int)$row['user_message_id'];
        if (isset($markers[$original])) {
            $i=$markers[$original];
            $rows[$i]['message']=$row['message'];
            $rows[$i]['context_json']=json_encode(vp3_paired_phone_prompt_context_v1($row),JSON_THROW_ON_ERROR);
        }
        if ($row['assistant_message_id']!==null && isset($markers[$row['assistant_message_id']])) {
            $rows[$markers[$row['assistant_message_id']]]['message']=vp3_paired_phone_reply_text_v1($row,false);
        }
        if ($row['owner_message_id']!==null && isset($markers[$row['owner_message_id']])) {
            $i=$markers[$row['owner_message_id']];
            $rows[$i]['message']=vp3_paired_phone_reply_text_v1($row,true);
            $rows[$i]['context_json']=json_encode(['source'=>'paired_phone_agent','phone_owner_reply'=>true,'phone_reply_to'=>$original],JSON_THROW_ON_ERROR);
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
