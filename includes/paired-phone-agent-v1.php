<?php
declare(strict_types=1);

require_once __DIR__.'/phone-tracking-v1.php';

/** These terminal states fit the existing turn column. Only their fixed descriptions reach a holder. */
final class PairedPhoneReplyFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::description($reason),503);
    }

    public static function description(string $reason): string
    {
        return match ($reason) {
            'setup' => 'Ask the account owner to check the connected agent’s AI settings, then retry.',
            'offline' => 'The agent’s HomeServer is unavailable. Retry when it is connected.',
            'timeout' => 'The agent did not return a reply in time. You can retry this message.',
            'busy' => 'The agent is busy. Wait a moment, then retry this message.',
            'access' => 'Chat access changed before the agent could reply. Check the connection and retry.',
            default => 'Your message was saved, but the agent did not return a reply. You can retry this message.',
        };
    }
}

function vp3_paired_phone_schema_v1(PDO $pdo,string $options): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_paired_phone_threads_v1 (
        device_id CHAR(32) PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, agent_id INT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        FOREIGN KEY (owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_paired_phone_turns_v1 (
        device_id CHAR(32) NOT NULL, request_id CHAR(36) NOT NULL, owner_user_id INT UNSIGNED NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        user_message_id BIGINT UNSIGNED NOT NULL UNIQUE, assistant_message_id BIGINT UNSIGNED NULL UNIQUE,
        message TEXT NOT NULL, reply TEXT NULL, state VARCHAR(8) NOT NULL, lease CHAR(32) NULL,
        lease_until BIGINT NOT NULL DEFAULT 0, attempted_at BIGINT NOT NULL,
        announced_prompt SMALLINT NOT NULL DEFAULT 0, announced_reply SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY(device_id,request_id),
        FOREIGN KEY (device_id) REFERENCES vp3_paired_phone_threads_v1(device_id) ON DELETE CASCADE,
        FOREIGN KEY (user_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_paired_phone_owner_replies_v1 (
        phone_message_id BIGINT UNSIGNED PRIMARY KEY, owner_message_id BIGINT UNSIGNED NOT NULL UNIQUE,
        message TEXT NOT NULL, announced SMALLINT NOT NULL DEFAULT 0,
        FOREIGN KEY (phone_message_id) REFERENCES vp3_paired_phone_turns_v1(user_message_id) ON DELETE CASCADE,
        FOREIGN KEY (owner_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
    )".$options);
}

function vp3_paired_phone_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT t.lease,t.announced_reply,b.agent_id,o.announced FROM vp3_paired_phone_turns_v1 t
            JOIN vp3_paired_phone_threads_v1 b ON b.device_id=t.device_id
            LEFT JOIN vp3_paired_phone_owner_replies_v1 o ON o.phone_message_id=t.user_message_id WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}

/** Called by approved owner actions or under an authorized device's account lock. */
function vp3_paired_phone_bind_v1(PDO $pdo,string $id,int $owner,int $agent,int $cid): void
{
    if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) throw new RuntimeException('Phone chat unavailable.',403);
    if (vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_paired_phone_threads_v1 WHERE owner_user_id=? AND device_id=?',[$owner,$id])->fetchColumn()) return;
    vp3_phone_sql_v1($pdo,'INSERT INTO vp3_paired_phone_threads_v1 (owner_user_id,device_id,agent_id,conversation_id)
        VALUES (?,?,?,?)',[$owner,$id,$agent,$cid]);
}

function vp3_paired_phone_thread_v1(PDO $pdo,array $device): array
{
    require_once __DIR__.'/agent-chat-boundary-v380.php';
    $owner=(int)$device['owner_user_id'];
    $thread=vp3_phone_sql_v1($pdo,'SELECT agent_id,conversation_id FROM vp3_paired_phone_threads_v1 WHERE owner_user_id=? AND device_id=?',
        [$owner,$device['id']])->fetch(PDO::FETCH_ASSOC);
    if ($thread) {
        $agent=(int)$thread['agent_id']; $cid=(int)$thread['conversation_id'];
        if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) throw new RuntimeException('Phone chat unavailable.',403);
    } else {
        // Legacy pairings have no Chat origin. Start a private thread for this phone under the owner's default agent.
        $agents=schema_metadata_table_exists($pdo,'user_agents') ? user_agents_list_v236($pdo,$owner,true) : [];
        $default=$agents[0]??null;
        $agent=vp3_agent_chat_agent_id_v380($default);
        $chat=vp3_agent_chat_create_conversation_v380($pdo,['id'=>$owner],$default,'Phone · '.$device['device_name']);
        $cid=(int)$chat['id'];
        vp3_paired_phone_bind_v1($pdo,$device['id'],$owner,$agent,$cid);
    }
    return ['agent_id'=>$agent,'conversation_id'=>$cid];
}

function vp3_paired_phone_request_id_v1(mixed $value): string
{
    $id=is_string($value)?strtolower($value):'';
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id))
        throw new RuntimeException('Invalid phone message identifier.',422);
    return $id;
}

function vp3_paired_phone_message_v1(mixed $value): string
{
    $message=is_string($value)?trim($value):'';
    if ($message==='' || strlen($message)>3000 || !mb_check_encoding($message,'UTF-8') || mb_strlen($message)>1000)
        throw new RuntimeException('Invalid phone message.',422);
    return $message;
}

function vp3_paired_phone_turns_v1(PDO $pdo,string $id,?string $focusRequest=null): array
{
    $select="SELECT t.request_id,t.message,t.reply,t.state,t.lease_until,o.message AS owner_reply
        FROM vp3_paired_phone_turns_v1 t LEFT JOIN vp3_paired_phone_owner_replies_v1 o ON o.phone_message_id=t.user_message_id
        JOIN vp3_paired_phone_threads_v1 b ON b.device_id=t.device_id AND b.owner_user_id=t.owner_user_id
            AND b.agent_id=t.agent_id AND b.conversation_id=t.conversation_id WHERE t.device_id=?";
    $rows=vp3_phone_sql_v1($pdo,$select.' ORDER BY COALESCE(o.owner_message_id,t.assistant_message_id,t.user_message_id) DESC LIMIT 30',[$id])->fetchAll(PDO::FETCH_ASSOC);
    // A notification can refer to a reply that has moved beyond the recent
    // page. Include at most one exact turn, still bound to this private thread.
    if($focusRequest!==null&&!in_array($focusRequest,array_column($rows,'request_id'),true)){
        $focused=vp3_phone_sql_v1($pdo,$select.' AND t.request_id=? LIMIT 1',[$id,$focusRequest])->fetch(PDO::FETCH_ASSOC);
        if($focused)$rows[]=$focused;
    }
    $now=time();
    foreach ($rows as &$row) {
        $ownerReply=$row['owner_reply'];
        $leaseUntil=(int)$row['lease_until'];
        unset($row['lease_until'],$row['owner_reply']);
        if ($row['reply']!==null && $row['state']==='replied') {
            $row['reply_author']='agent';
            if ($ownerReply!==null) $row['owner_reply']=$ownerReply;
        } elseif ($ownerReply!==null) {
            $row['reply']=$ownerReply;
            $row['reply_author']='owner';
            $row['state']='replied';
        } elseif ($row['state']!=='pending' || $leaseUntil<=$now) {
            $row['state']='awaiting_owner';
        }
    }
    return ['turns'=>array_reverse($rows)];
}

function vp3_paired_phone_marker_v1(PDO $pdo,int $owner,int $cid,string $role): int
{
    vp3_phone_sql_v1($pdo,'INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,?,?,?,?)',
        [$cid,$owner,$role,'Private paired phone message.','{"source":"paired_phone_agent"}']);
    $id=(int)$pdo->lastInsertId();
    vp3_phone_sql_v1($pdo,'UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND id=?',[$owner,$cid]);
    return $id;
}

/** A holder message is delivered to its owner's private Chat without calling an inference provider. */
function vp3_paired_phone_chat_v1(PDO $pdo,string $token,string $action,array $input): array
{
    $id=vp3_phone_camera_id_v1($input['device_id']??null);
    if (!in_array($action,['chat_status','chat_send'],true)) throw new RuntimeException('Unknown action.',422);
    $send=$action==='chat_send';
    $allowed=$send?['device_id','message','request_id']:['device_id','focus_request_id'];
    if (array_diff(array_keys($input),$allowed)) throw new RuntimeException('Unexpected phone chat fields.',422);
    $message=$send?vp3_paired_phone_message_v1($input['message']??null):null;
    $rid=$send?vp3_paired_phone_request_id_v1($input['request_id']??null):null;
    $focus=!$send&&isset($input['focus_request_id'])?vp3_paired_phone_request_id_v1($input['focus_request_id']):null;
    if (!vp3_phone_secret_valid_v1($token)) throw new RuntimeException('Phone unavailable.',403);
    if (!vp3_paired_phone_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
    $hash=vp3_extension_hash_v2000($token);
    // Discover the lock key before starting a repeatable-read transaction. Recheck the cookie on the locked device.
    $owner=(int)vp3_phone_sql_v1($pdo,'SELECT owner_user_id FROM vp3_phone_devices_v1 WHERE token_hash=? AND id=?',[$hash,$id])->fetchColumn();
    if (!$owner) throw new RuntimeException('Phone unavailable.',403);
    return vp3_phone_transaction_v1($pdo,static function() use($pdo,$owner,$hash,$id,$send,$message,$rid,$focus) {
        vp3_phone_account_v1($pdo,$owner,true);
        $device=vp3_phone_row_v1($pdo,$owner,$id);
        if (!hash_equals($device['token_hash'],$hash) || $device['device_status']!=='approved' || (int)$device['expires_at']<=time())
            throw new RuntimeException('Phone unavailable.',403);
        if (!$send && !vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_paired_phone_threads_v1 WHERE device_id=?',[$id])->fetchColumn()) return ['turns'=>[]];
        $thread=vp3_paired_phone_thread_v1($pdo,$device);
        if (!$send) return vp3_paired_phone_turns_v1($pdo,$id,$focus);
        $existing=vp3_phone_sql_v1($pdo,'SELECT message FROM vp3_paired_phone_turns_v1 WHERE device_id=? AND request_id=?',[$id,$rid])->fetchColumn();
        if ($existing!==false) {
            if ($existing!==$message) throw new RuntimeException('Phone message changed.',409);
            return vp3_paired_phone_turns_v1($pdo,$id);
        }
        $now=time();
        if ((int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_paired_phone_turns_v1 WHERE owner_user_id=? AND attempted_at>?',[$owner,$now-60])->fetchColumn()>=20)
            throw new RuntimeException('Please wait before trying again.',429);
        $cid=$thread['conversation_id'];
        $marker=vp3_paired_phone_marker_v1($pdo,$owner,$cid,'user');
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_paired_phone_turns_v1 (device_id,owner_user_id,agent_id,conversation_id,request_id,message,
            user_message_id,state,lease_until,attempted_at) VALUES (?,?,?,?,?,?,?,'waiting',0,?)",
            [$id,$owner,$thread['agent_id'],$cid,$rid,$message,$marker,$now]);
        return vp3_paired_phone_turns_v1($pdo,$id);
    });
}
