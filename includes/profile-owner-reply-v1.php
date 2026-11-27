<?php
declare(strict_types=1);

/** Stable owner inbox replies reuse the canonical message/turn authorities. */
const VP3_PROFILE_OWNER_REPLY_V1 = 'vp3.profile-owner-reply.v1';

function vp3_profile_owner_reply_request_v1(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
        throw new VP3ProfileAgentPublicException('OWNER_REPLY_REQUEST_REQUIRED',
            'Refresh Profile Activity, then check the conversation before sending again.', 422);
    }
    return $value;
}

function vp3_profile_owner_reply_conflict_v1(): never
{
    throw new VP3ProfileAgentPublicException('OWNER_REPLY_CONFLICT',
        'This reply request no longer matches its saved message. Check the conversation before sending another reply.', 409);
}

function vp3_profile_owner_reply_marker_v1(int $owner, int $conversation, string $request, string $message): array
{
    return ['contract'=>VP3_PROFILE_OWNER_REPLY_V1, 'request_id'=>$request,
        'request_hash'=>hash('sha256', json_encode([$owner, $conversation, $message], JSON_THROW_ON_ERROR))];
}

/** All legacy owner replies lock in owner/conversation order, including retries. */
function vp3_profile_owner_reply_lock_v1(PDO $pdo, array $user): array
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Owner replies require a transaction.');
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
    $query=$pdo->prepare('SELECT * FROM users WHERE id=?'.$lock);
    $query->execute([(int)$user['id']]);
    $live=$query->fetch(PDO::FETCH_ASSOC);
    if (!$live || empty($live['is_active']) || !has_permission('account.access',$live)
        || !personal_capability_has_v242('profile_agent.access',$live)) {
        throw new VP3ProfileAgentPublicException('OWNER_REPLY_UNAVAILABLE', 'Profile reply access changed. Refresh before continuing.', 403);
    }
    return $live;
}

/**
 * Caller has reauthorized and locked the exact owned conversation. Recover before
 * reading a new turn revision or rotating responder authority. Locking reads also
 * see a concurrent committed reply under MySQL's default REPEATABLE READ.
 */
function vp3_profile_owner_reply_recover_v1(PDO $pdo, array $user, int $conversation, string $request, string $message): ?array
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Owner reply recovery requires a conversation transaction.');
    $owner=(int)$user['id'];
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
    require_once __DIR__.'/profile-agent-handoff-pa3.php';
    if (vp3_profile_handoff_schema_ready_pa3($pdo)) {
        $query=$pdo->prepare('SELECT o.turn_id,o.operation,o.message_id,t.conversation_id FROM profile_agent_handoff_operations_pa3 o LEFT JOIN profile_agent_handoff_turns_pa3 t ON t.id=o.turn_id AND t.owner_user_id=o.owner_user_id WHERE o.owner_user_id=? AND o.request_id=?'.$lock);
        $query->execute([$owner,$request]);
        if ($saved=$query->fetch(PDO::FETCH_ASSOC)) {
            if ($saved['operation']!=='reply' || (int)$saved['conversation_id']!==$conversation || (int)$saved['message_id']<1) vp3_profile_owner_reply_conflict_v1();
            vp3_profile_handoff_owner_pa3($pdo,$user,0,0,true);
            $query=$pdo->prepare("SELECT id,message,context_json FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='owner' AND sender_user_id=?".$lock);
            $query->execute([(int)$saved['message_id'],$conversation,$owner]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            $context=$row?json_decode((string)$row['context_json'],true):null;
            $marker=is_array($context)?($context['profile_handoff']??null):null;
            if (!$row || $row['message']!==$message || !is_array($marker)
                || ($marker['contract']??null)!==VP3_PROFILE_HANDOFF_PA3
                || ($marker['turn_id']??null)!==(int)$saved['turn_id']
                || ($marker['owner_request_id']??null)!==$request) vp3_profile_owner_reply_conflict_v1();
            return ['message_id'=>(int)$row['id'],'duplicate'=>true];
        }
    }

    // Historical conversations may have no handoff turn/schema. Their existing
    // owner message is the receipt; no extra table or request-time DDL is needed.
    // A fixed quoted hexadecimal token survives equivalent JSON whitespace/key
    // ordering. It is only a candidate filter; parsed provenance is authoritative.
    $query=$pdo->prepare('SELECT m.id,m.conversation_id,m.sender_type,m.sender_user_id,m.message,m.context_json FROM profile_agent_messages m INNER JOIN profile_agent_conversations c ON c.id=m.conversation_id AND c.owner_user_id=? WHERE m.context_json LIKE ? ORDER BY m.id LIMIT 2'.$lock);
    $query->execute([$owner,'%"'.$request.'"%']);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    if (count($rows)!==1) vp3_profile_owner_reply_conflict_v1();
    $row=$rows[0];
    $context=json_decode((string)$row['context_json'],true);
    $marker=is_array($context)?($context['profile_owner_reply_v1']??null):null;
    $expected=vp3_profile_owner_reply_marker_v1($owner,$conversation,$request,$message);
    if ((int)$row['conversation_id']!==$conversation || $row['sender_type']!=='owner' || (int)$row['sender_user_id']!==$owner
        || $row['message']!==$message || !is_array($marker)
        || ($marker['contract']??null)!==$expected['contract'] || ($marker['request_id']??null)!==$request
        || !is_string($marker['request_hash']??null) || !hash_equals($expected['request_hash'],$marker['request_hash'])) vp3_profile_owner_reply_conflict_v1();
    return ['message_id'=>(int)$row['id'],'duplicate'=>true];
}

/** The caller holds current owner/conversation authority and checked receipt replay. */
function vp3_profile_owner_reply_legacy_save_v1(PDO $pdo,array $user,array $conversation,string $request,string $message): array
{
    if(!$pdo->inTransaction()||(int)$conversation['owner_user_id']!==(int)$user['id'])throw new RuntimeException('Owner replies require current conversation authority.');
    $owner=(int)$user['id'];$cid=(int)$conversation['id'];
    vp3_profile_agent_response_token_v1($pdo,$cid,true);
    $context=['profile_owner_reply_v1'=>vp3_profile_owner_reply_marker_v1($owner,$cid,$request,$message)];
    $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message,context_json) VALUES (?,'owner',?,?,?)")
        ->execute([$cid,$owner,$message,json_encode($context,JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    require_once __DIR__.'/profile-agent-responses-v1.php';
    vp3_profile_owner_requests_settle_v1($pdo,$owner,$cid,'answered',0,$messageId);
    $pdo->prepare("UPDATE profile_agent_conversations SET status='owner_joined',last_summary=?,last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=?")
        ->execute([mb_strimwidth($message,0,900,'…'),$cid,$owner]);
    require_once __DIR__.'/profile-attention-pa4b.php';
    vp3_profile_attention_message_sent_pa4b($pdo,$conversation,$messageId);
    vp3_profile_attention_settle_conversation_pa4b($pdo,$owner,$cid,null,$messageId-1);
    return ['message_id'=>$messageId,'duplicate'=>false];
}
