<?php
declare(strict_types=1);

/**
 * VP3 v4.10 — canonical Agent-scoped Brain memory.
 *
 * The durable system Agent namespace is represented by user_agent_id=NULL.
 * Custom Agents retain their exact user_agents.id even after retirement. Brain
 * memory must never fall back from a custom Agent to the system namespace.
 */
const VP3_AGENT_MEMORY_SCOPE_V410='vp3-agent-memory-scope-v410-20260910';
const VP3_AGENT_MEMORY_SCOPE_VERSION_V410=410;

function vp3_agent_memory_scope_index_exists_v410(PDO $pdo,string $table,string $index): bool
{
    try{
        $stmt=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
        $stmt->execute([$table,$index]);
        return (bool)$stmt->fetchColumn();
    }catch(Throwable $e){return false;}
}

function vp3_agent_memory_scope_schema_ready_v410(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('agent_memory_items')
        && column_exists('agent_memory_items','user_agent_id')
        && column_exists('agent_memory_items','memory_scope_version');
}

function vp3_agent_memory_scope_ensure_schema_v410(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('agent_memory_items'))return;
    if($pdo->inTransaction()&&!vp3_agent_memory_scope_schema_ready_v410($pdo)){
        throw new RuntimeException('Agent memory scope schema must be installed before starting a Brain transaction.');
    }
    if(!column_exists('agent_memory_items','user_agent_id')){
        $pdo->exec('ALTER TABLE agent_memory_items ADD COLUMN user_agent_id INT UNSIGNED NULL AFTER user_id');
    }
    if(!column_exists('agent_memory_items','memory_scope_version')){
        $pdo->exec('ALTER TABLE agent_memory_items ADD COLUMN memory_scope_version SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER memory_hash');
    }

    // Existing owner-wide memories predate durable Agent identity. They belong
    // only to the canonical system Agent. Re-hash them once into that namespace
    // so the existing UNIQUE(user_id,memory_hash) remains collision-safe for
    // system and custom Agents without relying on nullable unique semantics.
    $pdo->exec("UPDATE agent_memory_items
               SET user_agent_id=NULL,
                   memory_hash=SHA1(CONCAT('v410|agent:0|',memory_hash)),
                   memory_scope_version=410
               WHERE memory_scope_version<410");

    if(!vp3_agent_memory_scope_index_exists_v410($pdo,'agent_memory_items','idx_agent_memory_agent_type_v410')){
        $pdo->exec('ALTER TABLE agent_memory_items ADD INDEX idx_agent_memory_agent_type_v410 (user_id,user_agent_id,memory_type,is_active,last_seen_at)');
    }
    if(!vp3_agent_memory_scope_index_exists_v410($pdo,'agent_memory_items','idx_agent_memory_agent_occurrence_v410')){
        $pdo->exec('ALTER TABLE agent_memory_items ADD INDEX idx_agent_memory_agent_occurrence_v410 (user_id,user_agent_id,occurrence_count,last_seen_at)');
    }
}

function vp3_agent_memory_scope_id_v410(?int $userAgentId): int
{
    return max(0,(int)$userAgentId);
}

function vp3_agent_memory_scope_sql_v410(?int $userAgentId,string $alias=''): array
{
    $prefix=$alias!==''?rtrim($alias,'.').'.':'';
    $agentId=vp3_agent_memory_scope_id_v410($userAgentId);
    return $agentId>0
        ? [$prefix.'user_agent_id=?',[$agentId]]
        : [$prefix.'user_agent_id IS NULL',[]];
}

function vp3_agent_memory_scope_hash_v410(?int $userAgentId,string $legacyHash): string
{
    return sha1('v410|agent:'.vp3_agent_memory_scope_id_v410($userAgentId).'|'.$legacyHash);
}

function vp3_agent_memory_scope_conversation_id_v410(PDO $pdo,int $userId,int $conversationId): int
{
    if($userId<1||$conversationId<1||!table_exists('chat_conversations'))return -1;
    $stmt=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId,$userId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return -1;
    return $row['user_agent_id']===null?0:max(0,(int)$row['user_agent_id']);
}

function vp3_agent_memory_scope_set_current_v410(int $userId,?int $userAgentId): void
{
    if($userId<1)return;
    $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=[
        'user_id'=>$userId,
        'user_agent_id'=>vp3_agent_memory_scope_id_v410($userAgentId),
    ];
}

function vp3_agent_memory_scope_current_v410(array $user,?int $fallbackAgentId=null): int
{
    $userId=(int)($user['id']??0);
    $scope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
    if(is_array($scope)&&(int)($scope['user_id']??0)===$userId){
        return vp3_agent_memory_scope_id_v410((int)($scope['user_agent_id']??0));
    }
    return vp3_agent_memory_scope_id_v410($fallbackAgentId);
}

function vp3_agent_memory_scope_from_conversation_v410(array $user,int $conversationId,bool $setCurrent=true): int
{
    $pdo=db();$userId=(int)($user['id']??0);
    if(!$pdo||$userId<1||$conversationId<1)return 0;
    $agentId=vp3_agent_memory_scope_conversation_id_v410($pdo,$userId,$conversationId);
    if($agentId<0)throw new RuntimeException('Conversation does not belong to this user.');
    if($setCurrent)vp3_agent_memory_scope_set_current_v410($userId,$agentId);
    return $agentId;
}

function vp3_agent_memory_scope_archive_where_v410(?int $userAgentId,string $archiveAlias='a',string $conversationAlias='c'): array
{
    [$scope,$params]=vp3_agent_memory_scope_sql_v410($userAgentId,$conversationAlias);
    return [
        $archiveAlias.'.user_id=? AND '.$conversationAlias.'.user_id='.$archiveAlias.'.user_id AND '.$scope,
        $params,
    ];
}

function vp3_agent_memory_scope_provenance_v410(array $metadata,int $userAgentId,int $conversationId=0,int $archiveId=0): array
{
    $metadata['agent_scope']=[
        'version'=>'v4.10',
        'user_agent_id'=>$userAgentId>0?$userAgentId:null,
        'agent_kind'=>$userAgentId>0?'user_agent':'system',
    ];
    if($conversationId>0)$metadata['agent_scope']['conversation_id']=$conversationId;
    if($archiveId>0)$metadata['agent_scope']['archive_id']=$archiveId;
    return $metadata;
}
