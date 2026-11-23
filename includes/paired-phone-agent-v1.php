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
}

function vp3_paired_phone_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT t.lease,t.announced_reply,b.agent_id FROM vp3_paired_phone_turns_v1 t
            JOIN vp3_paired_phone_threads_v1 b ON b.device_id=t.device_id WHERE 1=0');
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

/** All holder entry points bind the supplied device ID to its own cookie. */
function vp3_paired_phone_device_v1(PDO $pdo,string $token,string $id): array
{
    if (!vp3_phone_secret_valid_v1($token)) throw new RuntimeException('Phone unavailable.',403);
    $hash=vp3_extension_hash_v2000($token);
    $owner=vp3_phone_sql_v1($pdo,'SELECT owner_user_id FROM vp3_phone_devices_v1 WHERE token_hash=? AND id=?',[$hash,$id])->fetchColumn();
    if (!$owner) throw new RuntimeException('Phone unavailable.',403);
    vp3_phone_account_v1($pdo,(int)$owner,true);
    $row=vp3_phone_row_v1($pdo,(int)$owner,$id);
    if (!hash_equals($row['token_hash'],$hash) || $row['device_status']!=='approved' || (int)$row['expires_at']<=time()) {
        throw new RuntimeException('Phone unavailable.',403);
    }
    return $row;
}

function vp3_paired_phone_turns_v1(PDO $pdo,string $id): array
{
    $rows=vp3_phone_sql_v1($pdo,"SELECT request_id,message,reply,
        CASE WHEN state='pending' AND lease_until<=? THEN 'timeout' ELSE state END AS state
        FROM vp3_paired_phone_turns_v1 WHERE device_id=? ORDER BY user_message_id DESC LIMIT 30",[time(),$id])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        if ($row['state']==='pending' || $row['state']==='replied') continue;
        $row['failure_message']=PairedPhoneReplyFailure::description($row['state']);
        $row['state']='failed';
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

/** Reserve before inference, release the DB lock during provider I/O, then reauthorize before delivering. */
function vp3_paired_phone_chat_v1(PDO $pdo,string $token,string $action,array $input,?callable $model=null): array
{
    $id=vp3_phone_camera_id_v1($input['device_id']??null);
    if (!in_array($action,['chat_status','chat_send'],true)) throw new RuntimeException('Unknown action.',422);
    $send=$action==='chat_send';
    $allowed=$send?['device_id','message','request_id']:['device_id'];
    if (array_diff(array_keys($input),$allowed)) throw new RuntimeException('Unexpected phone chat fields.',422);
    $message=is_string($input['message']??null)?trim($input['message']):'';
    $rid=strtolower(is_string($input['request_id']??null)?$input['request_id']:'');
    if ($send && (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$rid)
        || $message==='' || mb_strlen($message)>1000 || strlen($message)>3000)) throw new RuntimeException('Invalid phone message.',422);
    $work=vp3_phone_transaction_v1($pdo,static function() use($pdo,$token,$id,$send,$message,$rid) {
        $device=vp3_paired_phone_device_v1($pdo,$token,$id);
        if (!vp3_paired_phone_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
        $owner=(int)$device['owner_user_id'];
        if (!$send && !vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_paired_phone_threads_v1 WHERE device_id=?',[$id])->fetchColumn()) return ['turns'=>[]];
        $thread=vp3_paired_phone_thread_v1($pdo,$device);
        if (!$send) return vp3_paired_phone_turns_v1($pdo,$id);
        $now=time();
        $existing=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_paired_phone_turns_v1 WHERE device_id=? AND request_id=?',[$id,$rid])->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['message']!==$message) throw new RuntimeException('Phone message changed.',409);
        if ($existing && ($existing['state']==='replied' || (int)$existing['lease_until']>$now)) return vp3_paired_phone_turns_v1($pdo,$id);
        if (vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_paired_phone_turns_v1 WHERE device_id=? AND state='pending' AND lease_until>? LIMIT 1",[$id,$now])->fetchColumn()) {
            throw new RuntimeException('Another phone reply is being prepared.',429);
        }
        if ($existing && (int)$existing['attempted_at']>$now-3 ||
            (int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_paired_phone_turns_v1 WHERE owner_user_id=? AND attempted_at>?',[$owner,$now-60])->fetchColumn()>=20) {
            throw new RuntimeException('Please wait before trying again.',429);
        }
        $cid=$thread['conversation_id']; $agent=$thread['agent_id']; $lease=bin2hex(random_bytes(16));
        if ($existing) {
            $marker=(int)$existing['user_message_id'];
            vp3_phone_sql_v1($pdo,"UPDATE vp3_paired_phone_turns_v1 SET state='pending',lease=?,lease_until=?,attempted_at=?
                WHERE device_id=? AND request_id=?",[$lease,$now+90,$now,$id,$rid]);
        } else {
            $marker=vp3_paired_phone_marker_v1($pdo,$owner,$cid,'user');
            vp3_phone_sql_v1($pdo,"INSERT INTO vp3_paired_phone_turns_v1 (device_id,owner_user_id,agent_id,conversation_id,request_id,message,
                user_message_id,state,lease,lease_until,attempted_at) VALUES (?,?,?,?,?,?,?,'pending',?,?,?)",
                [$id,$owner,$agent,$cid,$rid,$message,$marker,$lease,$now+90,$now]);
        }
        $prior=vp3_phone_sql_v1($pdo,"SELECT message,reply FROM vp3_paired_phone_turns_v1
            WHERE device_id=? AND user_message_id<? AND state='replied' ORDER BY user_message_id DESC LIMIT 6",[$id,$marker])->fetchAll(PDO::FETCH_ASSOC);
        $history=[];
        foreach (array_reverse($prior) as $turn) {
            $history[]=['role'=>'user','content'=>$turn['message']];
            $history[]=['role'=>'assistant','content'=>$turn['reply']];
        }
        $history[]=['role'=>'user','content'=>$message];
        return compact('owner','agent','cid','lease','history');
    });
    if (!isset($work['lease'])) return $work;
    $authorized=static function() use($pdo,$work,$token,$id,$rid): bool {
        vp3_paired_phone_device_v1($pdo,$token,$id);
        if (!vp3_phone_camera_scope_v1($pdo,$work['owner'],$work['agent'],$work['cid'])) return false;
        return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_paired_phone_turns_v1 WHERE device_id=? AND request_id=?
            AND conversation_id=? AND agent_id=? AND lease=? AND lease_until>? AND state='pending'",
            [$id,$rid,$work['cid'],$work['agent'],$work['lease'],time()])->fetchColumn();
    };
    $failure='failed';
    try {
        if ($model===null) {
            require_once __DIR__.'/paired-phone-agent-model-v1.php';
            $model='vp3_paired_phone_model_reply_v1';
        }
        $answer=$model($pdo,$work['owner'],$work['agent'],$work['history'],$authorized);
        $reply=is_string($answer)?trim(mb_substr($answer,0,8000)):'';
    } catch (PairedPhoneReplyFailure $error) {
        $failure=$error->reason;
        $reply='';
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('paired_phone.reply_failed',['error_class'=>get_class($error)]);
        }
        $reply='';
    }
    return vp3_phone_transaction_v1($pdo,static function() use($pdo,$authorized,$work,$id,$rid,$reply,$failure) {
        if (!$authorized()) throw new RuntimeException('Phone chat changed.',409);
        $assistant=$reply==='' ? null : vp3_paired_phone_marker_v1($pdo,$work['owner'],$work['cid'],'assistant');
        vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_turns_v1 SET assistant_message_id=?,reply=?,state=?,lease=NULL,lease_until=0
            WHERE device_id=? AND request_id=? AND lease=?',
            [$assistant,$assistant===null?null:$reply,$assistant===null?$failure:'replied',$id,$rid,$work['lease']]);
        return vp3_paired_phone_turns_v1($pdo,$id);
    });
}
