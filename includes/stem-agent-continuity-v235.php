<?php
declare(strict_types=1);

/** Private drafts use the same current production authority as the editor. */
function stem_agent_authorized_track_v235(PDO $pdo,int $trackId,array $user): ?array
{
    if($trackId<1)return null;
    $s=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$s->execute([$trackId]);$track=$s->fetch();
    return $track&&can_manage_track_production($track,$user)?$track:null;
}

/** Resolve only this owner's live Agent conversation; never fall across Agents. */
function stem_agent_v131_conversation(PDO $pdo,int $userId,int $requested,bool $create=false,string $title='Stem Studio',?array $agent=null): int
{
    if(!table_exists('chat_conversations')||!table_exists('chat_messages'))return 0;
    if($requested>0){
        if(!vp3_agent_chat_conversation_v380($pdo,$requested,$userId,$agent))throw new RuntimeException('Conversation access changed.',403);
        return $requested;
    }
    [$scope,$params]=vp3_agent_chat_scope_sql_v380($agent,'c');
    $s=$pdo->prepare("SELECT c.id FROM chat_conversations c WHERE c.user_id=? AND {$scope} ORDER BY c.updated_at DESC,c.id DESC LIMIT 1");
    $s->execute([$userId,...$params]);$found=(int)($s->fetchColumn()?:0);
    if($found>0||!$create)return $found;
    $s=$pdo->prepare('INSERT INTO chat_conversations (user_id,user_agent_id,title,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())');
    $s->execute([$userId,$agent?(int)$agent['id']:null,mb_strimwidth($title,0,190,'…')]);
    return (int)$pdo->lastInsertId();
}

/** A client result may reconcile only the exact proposal issued to this session. */
function stem_agent_result_binding_v235(PDO $pdo,int $userId,int $sessionId,int $historyId,int $conversationId,int $messageId,int $agentId): array
{
    $s=$pdo->prepare("SELECT command_json,status FROM agent_studio_history WHERE id=? AND session_id=? AND user_id=? AND role='assistant' LIMIT 1");
    $s->execute([$historyId,$sessionId,$userId]);$row=$s->fetch();
    $binding=json_decode((string)($row['command_json']??''),true);
    if(!$row||$row['status']!=='pending'||!is_array($binding)
        ||(int)($binding['conversation_id']??0)!==$conversationId
        ||(int)($binding['assistant_message_id']??0)!==$messageId
        ||(int)($binding['agent_id']??-1)!==$agentId){
        throw new RuntimeException('That Studio result does not match the active proposal.',409);
    }
    return $binding;
}
