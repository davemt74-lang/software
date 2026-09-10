<?php
declare(strict_types=1);

/**
 * VP3 v3.90 durable user-Agent lifecycle.
 *
 * Retiring an Agent must never rewrite or delete historical Agent Chat or
 * Profile Agent conversations. The user_agents row remains the durable identity
 * anchor while active surfaces exclude retired rows.
 */
const VP3_USER_AGENT_LIFECYCLE_V390='vp3-user-agent-lifecycle-v390-20260910';

function vp3_user_agent_lifecycle_schema_ready_v390(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo&&table_exists('user_agents')&&column_exists('user_agents','retired_at');
}

function vp3_user_agent_lifecycle_ensure_schema_v390(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if($pdo->inTransaction()&&!vp3_user_agent_lifecycle_schema_ready_v390($pdo)){
        throw new RuntimeException('Agent lifecycle schema must be installed before starting a lifecycle transaction.');
    }
    if(!table_exists('user_agents'))user_agent_system_ensure_schema_v236($pdo);
    if(!column_exists('user_agents','retired_at')){
        $pdo->exec('ALTER TABLE user_agents ADD COLUMN retired_at DATETIME NULL AFTER voice_enabled, ADD INDEX idx_user_agents_owner_retired (owner_user_id,retired_at,is_active,id)');
    }
}

function vp3_user_agent_is_retired_v390(PDO $pdo,int $ownerUserId,int $agentId): bool
{
    if($ownerUserId<1||$agentId<1)return false;
    if(!vp3_user_agent_lifecycle_schema_ready_v390($pdo))return false;
    $stmt=$pdo->prepare('SELECT retired_at IS NOT NULL FROM user_agents WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$agentId,$ownerUserId]);
    return (bool)$stmt->fetchColumn();
}

function vp3_user_agent_visible_ids_v390(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1)return [];
    if(!vp3_user_agent_lifecycle_schema_ready_v390($pdo))return [];
    $stmt=$pdo->prepare('SELECT id FROM user_agents WHERE owner_user_id=? AND retired_at IS NULL ORDER BY id ASC');
    $stmt->execute([$ownerUserId]);
    return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
}

function vp3_user_agent_filter_visible_v390(PDO $pdo,int $ownerUserId,array $agents): array
{
    if(!vp3_user_agent_lifecycle_schema_ready_v390($pdo))return $agents;
    $visible=array_fill_keys(vp3_user_agent_visible_ids_v390($pdo,$ownerUserId),true);
    return array_values(array_filter($agents,static fn(array $agent):bool=>isset($visible[(int)($agent['id']??0)])));
}

function vp3_user_agent_require_current_v390(PDO $pdo,int $ownerUserId,int $agentId): array
{
    $agent=user_agent_get_v236($pdo,$ownerUserId,$agentId);
    if(!$agent||vp3_user_agent_is_retired_v390($pdo,$ownerUserId,$agentId))throw new RuntimeException('Agent not found.');
    return $agent;
}

function vp3_user_agent_retire_v390(PDO $pdo,array $user,int $agentId): void
{
    $ownerUserId=(int)($user['id']??0);
    if($ownerUserId<1||$agentId<1)throw new RuntimeException('Agent not found.');
    vp3_user_agent_lifecycle_ensure_schema_v390($pdo);
    vp3_user_agent_require_current_v390($pdo,$ownerUserId,$agentId);

    $started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id,is_default FROM user_agents WHERE id=? AND owner_user_id=? AND retired_at IS NULL LIMIT 1 FOR UPDATE');
        $lock->execute([$agentId,$ownerUserId]);
        $current=$lock->fetch();
        if(!$current)throw new RuntimeException('Agent not found.');

        // Clear current Profile Agent selection without touching historical
        // profile_agent_conversations that still reference this Agent identity.
        if(table_exists('user_profiles')){
            $pdo->prepare('UPDATE user_profiles SET profile_agent_id=NULL,profile_agent_enabled=0 WHERE user_id=? AND profile_agent_id=?')->execute([$ownerUserId,$agentId]);
        }

        $pdo->prepare('UPDATE user_agents SET is_active=0,is_default=0,is_profile_agent=0,voice_enabled=0,retired_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND retired_at IS NULL')->execute([$agentId,$ownerUserId]);

        if(!empty($current['is_default'])){
            $next=$pdo->prepare('SELECT id FROM user_agents WHERE owner_user_id=? AND retired_at IS NULL AND is_active=1 ORDER BY id ASC LIMIT 1 FOR UPDATE');
            $next->execute([$ownerUserId]);
            $nextId=(int)$next->fetchColumn();
            if($nextId>0)$pdo->prepare('UPDATE user_agents SET is_default=1 WHERE id=? AND owner_user_id=?')->execute([$nextId,$ownerUserId]);
        }

        if($started)$pdo->commit();
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
