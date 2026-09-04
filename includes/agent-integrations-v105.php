<?php
declare(strict_types=1);

/**
 * Stonefellow v105 provider adapter registry.
 *
 * Provider modules may register capabilities and a callable executor at runtime.
 * Credentials stay inside the provider module/secure credential store; the
 * Release Operations tables only contain connection metadata and audited work.
 */
function agent_integration_v105_registry(): array
{
    return $GLOBALS['STONEFELLOW_AGENT_INTEGRATIONS_V105'] ?? [];
}

function agent_integration_v105_register(
    string $providerKey,
    string $label,
    array $capabilities,
    callable $executor
): void {
    $providerKey = strtolower(trim($providerKey));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $providerKey)) {
        throw new InvalidArgumentException('Invalid Agent integration provider key.');
    }
    $clean=[];
    foreach($capabilities as $capability){
        $capability=strtolower(trim((string)$capability));
        if(preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/',$capability))$clean[$capability]=true;
    }
    $GLOBALS['STONEFELLOW_AGENT_INTEGRATIONS_V105'][$providerKey]=[
        'key'=>$providerKey,
        'label'=>mb_substr(trim($label)?:$providerKey,0,190),
        'capabilities'=>array_keys($clean),
        'executor'=>$executor,
    ];
}

function agent_integration_v105_provider(string $providerKey): ?array
{
    $registry=agent_integration_v105_registry();
    return $registry[strtolower(trim($providerKey))]??null;
}

function agent_integration_v105_capabilities(string $providerKey): array
{
    $provider=agent_integration_v105_provider($providerKey);
    return is_array($provider['capabilities']??null)?$provider['capabilities']:[];
}

function agent_integration_v105_connection(array $user,string $providerKey): ?array
{
    $pdo=db();$owner=release_v105_workspace_owner_id($user);
    if(!$pdo||$owner<1||!release_v105_schema_ready())return null;
    $stmt=$pdo->prepare(
        "SELECT * FROM agent_integrations
         WHERE owner_user_id=? AND provider_key=? AND status='connected'
         ORDER BY updated_at DESC,id DESC LIMIT 1"
    );
    $stmt->execute([$owner,mb_substr(strtolower(trim($providerKey)),0,80)]);
    return $stmt->fetch()?:null;
}

function agent_work_action_v105_get(array $user,int $actionId): ?array
{
    $pdo=db();$owner=release_v105_workspace_owner_id($user);
    if(!$pdo||$owner<1||$actionId<1||!release_v105_schema_ready())return null;
    $stmt=$pdo->prepare('SELECT * FROM agent_work_actions WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$actionId,$owner]);
    return $stmt->fetch()?:null;
}

function agent_work_action_v105_approve(array $user,int $actionId): bool
{
    if(!permission_v105_has('release.manage',$user))return false;
    $pdo=db();$row=agent_work_action_v105_get($user,$actionId);
    if(!$pdo||!$row)return false;
    if(!in_array((string)$row['status'],['draft','awaiting_approval'],true))return false;
    $stmt=$pdo->prepare(
        "UPDATE agent_work_actions
         SET status='queued',approved_at=NOW()
         WHERE id=? AND owner_user_id=? AND status IN ('draft','awaiting_approval')"
    );
    $stmt->execute([$actionId,release_v105_workspace_owner_id($user)]);
    agent_tool_log($user,'agent_action.approve','Approve Agent work action','success',['action_id'=>$actionId,'provider'=>(string)$row['provider_key'],'action_type'=>(string)$row['action_type']]);
    return $stmt->rowCount()>0;
}

function agent_work_action_v105_execute(array $user,int $actionId): array
{
    if(!permission_v105_has('release.manage',$user))throw new RuntimeException('Release Operations permission is required.');
    $pdo=db();$row=agent_work_action_v105_get($user,$actionId);
    if(!$pdo||!$row)throw new RuntimeException('Agent work action not found.');
    if((int)$row['requires_approval']===1&&empty($row['approved_at']))throw new RuntimeException('This Agent action requires approval before execution.');
    if(!in_array((string)$row['status'],['queued','approved'],true))throw new RuntimeException('This Agent action is not queued for execution.');

    $providerKey=(string)$row['provider_key'];
    $provider=agent_integration_v105_provider($providerKey);
    if(!$provider)throw new RuntimeException('No runtime adapter is registered for provider '.$providerKey.'.');
    if(!in_array((string)$row['action_type'],$provider['capabilities'],true))throw new RuntimeException('That provider does not expose the requested Agent capability.');
    $connection=agent_integration_v105_connection($user,$providerKey);
    if(!$connection)throw new RuntimeException('The '.$providerKey.' integration is not connected for this workspace.');

    $owner=release_v105_workspace_owner_id($user);
    $claim=$pdo->prepare("UPDATE agent_work_actions SET status='running' WHERE id=? AND owner_user_id=? AND status IN ('queued','approved')");
    $claim->execute([$actionId,$owner]);
    if($claim->rowCount()<1)throw new RuntimeException('The Agent action is already being processed.');

    $input=json_decode((string)($row['input_json']??''),true);if(!is_array($input))$input=[];
    try{
        $result=($provider['executor'])([
            'action_id'=>$actionId,
            'action_type'=>(string)$row['action_type'],
            'input'=>$input,
            'connection'=>$connection,
            'user'=>$user,
            'release_id'=>(int)($row['release_id']??0),
            'release_item_id'=>(int)($row['release_item_id']??0),
        ]);
        if(!is_array($result))$result=['result'=>$result];
        $stmt=$pdo->prepare("UPDATE agent_work_actions SET status='complete',completed_at=NOW(),result_json=? WHERE id=? AND owner_user_id=?");
        $stmt->execute([json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actionId,$owner]);
        agent_tool_log($user,'agent_action.execute',(string)$row['action_type'],'success',['action_id'=>$actionId,'provider'=>$providerKey,'result'=>$result]);
        return ['ok'=>true,'action_id'=>$actionId,'result'=>$result];
    }catch(Throwable $e){
        $stmt=$pdo->prepare("UPDATE agent_work_actions SET status='failed',result_json=? WHERE id=? AND owner_user_id=?");
        $stmt->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actionId,$owner]);
        agent_tool_log($user,'agent_action.execute',(string)$row['action_type'],'failed',['action_id'=>$actionId,'provider'=>$providerKey,'error'=>$e->getMessage()]);
        throw $e;
    }
}
