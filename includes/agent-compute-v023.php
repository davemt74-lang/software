<?php
declare(strict_types=1);

/**
 * VP3 v0.23 — per-Agent compute policy overrides.
 *
 * Account compute remains the default. Agent id 0 is the account's universal
 * system Agent; positive ids are user-owned rows from user_agents. Explicit
 * overrides are intentionally sparse: inherit is represented by no row.
 */
function agent_compute_v023_preferences(): array
{
    return ['inherit'=>[
        'label'=>'Use account setting',
        'description'=>'Follow the account-level Agent Compute preference.',
    ]] + agent_compute_v020_preferences();
}

function agent_compute_v023_valid_override(string $preference): bool
{
    return isset(agent_compute_v023_preferences()[$preference]);
}

function agent_compute_v023_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_compute_overrides (
      user_id INT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      preference VARCHAR(24) NOT NULL DEFAULT 'inherit',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,agent_id),
      INDEX idx_agent_compute_override_agent (agent_id),
      CONSTRAINT fk_agent_compute_override_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_compute_v023_overrides_for_user(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $stmt=$pdo->prepare('SELECT agent_id,preference FROM agent_compute_overrides WHERE user_id=?');
    $stmt->execute([$userId]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $agentId=max(0,(int)($row['agent_id']??0));
        $preference=(string)($row['preference']??'inherit');
        if(agent_compute_v023_valid_override($preference)&&$preference!=='inherit')$out[$agentId]=$preference;
    }
    return $out;
}

function agent_compute_v023_override(PDO $pdo,int $userId,int $agentId): string
{
    if($userId<1||$agentId<0)return 'inherit';
    $stmt=$pdo->prepare('SELECT preference FROM agent_compute_overrides WHERE user_id=? AND agent_id=? LIMIT 1');
    $stmt->execute([$userId,$agentId]);
    $preference=(string)($stmt->fetchColumn()?:'inherit');
    return agent_compute_v023_valid_override($preference)?$preference:'inherit';
}

function agent_compute_v023_policy_from_values(string $accountPreference,string $agentOverride): array
{
    if(!agent_compute_v020_valid_preference($accountPreference))$accountPreference='auto';
    if(!agent_compute_v023_valid_override($agentOverride))$agentOverride='inherit';
    $effective=$agentOverride==='inherit'?$accountPreference:$agentOverride;
    $meta=agent_compute_v020_preferences()[$effective]??agent_compute_v020_preferences()['auto'];
    return [
        'account_preference'=>$accountPreference,
        'agent_override'=>$agentOverride,
        'effective_preference'=>$effective,
        'source'=>$agentOverride==='inherit'?'account':'agent',
        'effective_label'=>(string)($meta['label']??'Automatic'),
    ];
}

function agent_compute_v023_effective(PDO $pdo,int $userId,int $agentId=0,?string $accountPreference=null): array
{
    $accountPreference ??= agent_compute_v020_preference($pdo,$userId);
    return agent_compute_v023_policy_from_values(
        $accountPreference,
        agent_compute_v023_override($pdo,$userId,$agentId)
    );
}

function agent_compute_v023_assert_owned_agent(PDO $pdo,int $userId,int $agentId): void
{
    if($userId<1||$agentId<0)throw new RuntimeException('Agent not found.');
    if($agentId===0)return;
    if(!function_exists('user_agent_get_v236')||!user_agent_get_v236($pdo,$userId,$agentId)){
        throw new RuntimeException('Agent not found.');
    }
}

function agent_compute_v023_save_override(PDO $pdo,array $user,int $agentId,string $preference): array
{
    $userId=(int)($user['id']??0);
    agent_compute_v023_assert_owned_agent($pdo,$userId,$agentId);
    $preference=trim($preference);
    if(!agent_compute_v023_valid_override($preference))throw new RuntimeException('Choose a valid Agent compute preference.');
    if($preference==='inherit'){
        $pdo->prepare('DELETE FROM agent_compute_overrides WHERE user_id=? AND agent_id=?')->execute([$userId,$agentId]);
    }else{
        $pdo->prepare('INSERT INTO agent_compute_overrides (user_id,agent_id,preference) VALUES (?,?,?) ON DUPLICATE KEY UPDATE preference=VALUES(preference),updated_at=CURRENT_TIMESTAMP')->execute([$userId,$agentId,$preference]);
    }
    return agent_compute_v023_effective($pdo,$userId,$agentId);
}

function agent_compute_v023_delete_override(PDO $pdo,int $userId,int $agentId): void
{
    if($userId<1||$agentId<0)return;
    $pdo->prepare('DELETE FROM agent_compute_overrides WHERE user_id=? AND agent_id=?')->execute([$userId,$agentId]);
}

function agent_compute_v023_attach_state(PDO $pdo,array $user,array $state): array
{
    $userId=(int)($user['id']??0);
    $accountPreference=agent_compute_v020_preference($pdo,$userId);
    $overrides=agent_compute_v023_overrides_for_user($pdo,$userId);
    $state['compute']=is_array($state['compute']??null)?$state['compute']:[];
    $state['compute']['agent_policy_version']='v0.23';
    $state['compute']['agent_preferences']=agent_compute_v023_preferences();
    $state['compute']['account_preference']=$accountPreference;
    $state['compute']['agent_override_count']=count($overrides);
    $systemPolicy=agent_compute_v023_policy_from_values($accountPreference,(string)($overrides[0]??'inherit'));
    $state['system_agent']=is_array($state['system_agent']??null)?$state['system_agent']:[];
    $state['system_agent']['compute']=$systemPolicy;
    if(is_array($state['agents']??null)){
        foreach($state['agents'] as &$agent){
            $agentId=max(0,(int)($agent['id']??0));
            $agent['compute']=agent_compute_v023_policy_from_values($accountPreference,(string)($overrides[$agentId]??'inherit'));
        }
        unset($agent);
    }
    return $state;
}

function agent_compute_v023_public_policy(array $policy,int $agentId): array
{
    $allowed=['inherit','auto','homeserver_only','vp3_cloud'];
    $account=(string)($policy['account_preference']??'auto');
    $override=(string)($policy['agent_override']??'inherit');
    $effective=(string)($policy['effective_preference']??'auto');
    return [
        'version'=>'v0.23',
        'agent_id'=>max(0,$agentId),
        'account_preference'=>in_array($account,$allowed,true)?$account:'auto',
        'agent_override'=>in_array($override,$allowed,true)?$override:'inherit',
        'effective_preference'=>in_array($effective,['auto','homeserver_only','vp3_cloud'],true)?$effective:'auto',
        'source'=>(string)($policy['source']??'account')==='agent'?'agent':'account',
    ];
}
