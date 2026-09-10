<?php
declare(strict_types=1);

/**
 * VP3 v3.80 canonical Agent Chat principal/conversation boundary.
 *
 * Agent Chat conversations belong to exactly one signed-in VP3 identity and one
 * Agent principal. A NULL user_agent_id is the durable system-agent namespace;
 * it is never a pool of unclaimed conversations for a later user-owned Agent.
 */
const VP3_AGENT_CHAT_BOUNDARY_V380='vp3-agent-chat-boundary-v380-20260910';

function vp3_agent_chat_agent_id_v380(?array $agent): int
{
    return max(0,(int)($agent['id']??0));
}

function vp3_agent_chat_resolve_agent_v380(PDO $pdo,array $user,int $requestedAgentId): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('A signed-in VP3 identity is required.');
    if($requestedAgentId<1)return null;
    $agent=user_agent_get_v236($pdo,$userId,$requestedAgentId);
    if(!$agent||empty($agent['is_active']))throw new RuntimeException('That agent is not available.');
    return $agent;
}

function vp3_agent_chat_scope_sql_v380(?array $agent,string $alias='c'): array
{
    $alias=preg_replace('/[^a-zA-Z0-9_]/','',$alias)?:'c';
    $agentId=vp3_agent_chat_agent_id_v380($agent);
    return $agentId>0
        ? ["{$alias}.user_agent_id=?",[$agentId]]
        : ["{$alias}.user_agent_id IS NULL",[]];
}

function vp3_agent_chat_principal_v380(array $user,?array $agent): array
{
    return user_agent_principal_v236($user,$agent);
}

function vp3_agent_chat_conversation_v380(PDO $pdo,int $conversationId,int $userId,?array $agent,bool $forUpdate=false): ?array
{
    if($conversationId<1||$userId<1)return null;
    [$scope,$params]=vp3_agent_chat_scope_sql_v380($agent,'c');
    $sql="SELECT c.* FROM chat_conversations c WHERE c.id=? AND c.user_id=? AND {$scope} LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute(array_merge([$conversationId,$userId],$params));
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_agent_chat_create_conversation_v380(PDO $pdo,array $user,?array $agent,string $title,int $workspaceId=0): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('A signed-in VP3 identity is required.');
    $title=trim(preg_replace('/\s+/u',' ',$title)??'');
    if($title==='')$title='New chat';
    $title=mb_strimwidth($title,0,190,'…');
    $agentId=vp3_agent_chat_agent_id_v380($agent);
    $stmt=$pdo->prepare('INSERT INTO chat_conversations (user_id,user_agent_id,artist_workspace_id,title) VALUES (?,?,?,?)');
    $stmt->execute([$userId,$agentId>0?$agentId:null,$workspaceId>0?$workspaceId:null,$title]);
    $conversationId=(int)$pdo->lastInsertId();
    $row=vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent);
    if(!$row)throw new RuntimeException('Agent Chat conversation could not be created.');
    return $row;
}

/**
 * Resolve the principal for a streamed/cross-surface turn. Explicit Agent
 * context wins for a new conversation. Existing conversations are authoritative
 * and may only be reopened by the exact same user + Agent namespace.
 */
function vp3_agent_chat_stream_scope_v380(PDO $pdo,array $user,array $input): array
{
    $userId=(int)($user['id']??0);
    $conversationId=max(0,(int)($input['conversation_id']??0));
    $rawContext=is_array($input['agent_context']??null)?$input['agent_context']:[];
    $hasRequested=array_key_exists('user_agent_id',$rawContext);
    $requested=max(0,(int)($rawContext['user_agent_id']??0));

    if($conversationId>0){
        $stmt=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$conversationId,$userId]);
        $stored=$stmt->fetchColumn();
        if($stored===false)throw new RuntimeException('Conversation not found for this Agent.');
        $storedId=$stored===null?0:max(0,(int)$stored);
        if($hasRequested&&$requested!==$storedId)throw new RuntimeException('Conversation not found for this Agent.');
        $requested=$storedId;
    }

    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$requested);
    if($conversationId>0&&!vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent)){
        throw new RuntimeException('Conversation not found for this Agent.');
    }
    return [
        'user_id'=>$userId,
        'agent_id'=>vp3_agent_chat_agent_id_v380($agent),
        'agent'=>$agent,
        'principal'=>vp3_agent_chat_principal_v380($user,$agent),
        'conversation_id'=>$conversationId,
    ];
}

/**
 * Human Conversations are never ambient Agent context. A future explicit Agent
 * messaging tool must first call this authorization boundary, which delegates to
 * the canonical v3.70 Human Messaging authorization rules.
 */
function vp3_agent_chat_human_conversation_allowed_v380(PDO $pdo,array $user,int $conversationId): bool
{
    if($conversationId<1||!function_exists('vp3_human_conversation_v370')||!function_exists('vp3_human_can_access_v370'))return false;
    $conversation=vp3_human_conversation_v370($pdo,$conversationId);
    return $conversation?vp3_human_can_access_v370($pdo,$conversation,(int)($user['id']??0)):false;
}
