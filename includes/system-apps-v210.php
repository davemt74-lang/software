<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v200.php';

const VP3_SYSTEM_APPS_V210='system-app-permission-governance-v210-20260930';

function vp3_system_apps_permission_status_v210(
    int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null,bool $strict=false
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $installation=vp3_system_apps_install_projection_v110($userId,(int)$app['id'],$pdo);
    if(empty($installation['installed']))throw new RuntimeException('Install this System App before managing its permissions.');
    $status=vp3_system_apps_remote_v110($userId,'apps.system.permissions.status',[
      'app_key'=>(string)$app['homeserver_catalog_key'],
    ],$remote);
    if((string)($status['app_key']??'')!==''&&(string)$status['app_key']!==(string)$app['homeserver_catalog_key']){
        throw new RuntimeException('HomeServer returned mismatched System App permission identity.');
    }
    if($strict){
        if(($status['app_key']??null)!==(string)$app['homeserver_catalog_key']||!is_array($status['permissions']??null)||!array_is_list($status['permissions']))throw new RuntimeException('Permission response identity or shape could not be verified.');
        vp3_app_read_counts_v1($status,['declared_count','allowed_count','denied_count']);$allowed=0;$seen=[];
        foreach($status['permissions'] as $row){if(!is_array($row)||!is_string($row['permission']??null)||trim($row['permission'])===''||mb_strlen($row['permission'])>160||preg_match('/[\x00-\x20\x7F]/',$row['permission'])||isset($seen[$row['permission']])||!is_bool($row['allowed']??null))throw new RuntimeException('Permission row could not be verified.');$seen[$row['permission']]=true;if($row['allowed'])$allowed++;}
        if($status['declared_count']!==count($status['permissions'])||$status['allowed_count']!==$allowed||$status['denied_count']!==count($status['permissions'])-$allowed)throw new RuntimeException('Permission counts contradict returned rows.');
        $fresh=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
        if((int)$fresh['id']!==(int)$app['id']||$fresh['homeserver_catalog_key']!==$app['homeserver_catalog_key']||empty(vp3_system_apps_install_projection_v110($userId,(int)$fresh['id'],$pdo)['installed']))throw new RuntimeException('App ownership or installation changed.',403);
    }
    $rows=is_array($status['permissions']??null)?$status['permissions']:[];
    return [
      'contract'=>'vp3.system-app-permission-governance.v1',
      'app_key'=>(string)$app['app_key'],
      'permissions'=>$rows,
      'declared_count'=>(int)($status['declared_count']??count($rows)),
      'allowed_count'=>(int)($status['allowed_count']??0),
      'denied_count'=>(int)($status['denied_count']??0),
      'high_risk_declared'=>(int)($status['high_risk_declared']??0),
      'high_risk_allowed'=>(int)($status['high_risk_allowed']??0),
      'effective_capabilities'=>is_array($status['effective_capabilities']??null)?array_values(array_map('strval',$status['effective_capabilities'])):[],
      'default_for_new_permissions'=>(string)($status['default_for_new_permissions']??'denied'),
      'permission_expansion_requires_review'=>array_key_exists('permission_expansion_requires_review',$status)?(bool)$status['permission_expansion_requires_review']:true,
      'homeserver'=>$status,
    ];
}

function vp3_system_apps_permission_set_v210(
    int $userId,string $appKey,string $permission,bool $allowed,string $reason='owner_confirmed',
    ?callable $remote=null,?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $app=vp3_system_apps_owned_row_v110($userId,$appKey,$pdo);
    $before=vp3_system_apps_permission_status_v210($userId,$appKey,$remote,$pdo);
    $target=null;
    foreach($before['permissions'] as $row){
        if((string)($row['permission']??'')===$permission){$target=$row;break;}
    }
    if(!$target)throw new RuntimeException('That permission is not declared by this System App.');
    if((bool)($target['allowed']??false)===$allowed){
        return ['contract'=>'vp3.system-app-permission-operation.v1','changed'=>false,'app_key'=>$appKey,'permission'=>$permission,'allowed'=>$allowed,'status'=>$before];
    }
    $response=vp3_system_apps_remote_v110($userId,'apps.system.permissions.set',[
      'app_key'=>(string)$app['homeserver_catalog_key'],
      'permission'=>$permission,
      'allowed'=>$allowed,
      'reason'=>mb_substr($reason,0,160),
    ],$remote);
    vp3_system_apps_event_v110($userId,(int)$app['id'],$allowed?'app.permission.granted':'app.permission.revoked',[
      'permission'=>$permission,
      'risk'=>(string)($target['risk']??''),
      'category'=>(string)($target['category']??''),
      'reason'=>mb_substr($reason,0,160),
      'source'=>'homeserver_authoritative',
    ],$pdo);
    return [
      'contract'=>'vp3.system-app-permission-operation.v1',
      'changed'=>true,
      'app_key'=>$appKey,
      'permission'=>$permission,
      'allowed'=>$allowed,
      'status'=>[
        'contract'=>'vp3.system-app-permission-governance.v1',
        'app_key'=>$appKey,
        'permissions'=>is_array($response['permissions']??null)?$response['permissions']:[],
        'declared_count'=>(int)($response['declared_count']??0),
        'allowed_count'=>(int)($response['allowed_count']??0),
        'denied_count'=>(int)($response['denied_count']??0),
        'high_risk_declared'=>(int)($response['high_risk_declared']??0),
        'high_risk_allowed'=>(int)($response['high_risk_allowed']??0),
        'effective_capabilities'=>is_array($response['effective_capabilities']??null)?$response['effective_capabilities']:[],
        'default_for_new_permissions'=>(string)($response['default_for_new_permissions']??'denied'),
        'permission_expansion_requires_review'=>true,
        'homeserver'=>$response,
      ],
    ];
}

function vp3_system_apps_permission_query_v210(
    string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\\b(?:permission|permissions|capability|capabilities|camera|microphone|network access|notifications)\\b/i',$query))return $empty;
    if(preg_match('/\\b(?:allow|grant|enable|approve|revoke|deny|disable|remove)\\b/i',$query))return $empty;
    $terms=preg_replace('/\b(?:show|list|inspect|read|check|permission|permissions|capability|capabilities|camera|microphone|network access|notifications|my|the|for|of)\b/i',' ',$query);
    return vp3_system_app_agent_legacy_v1($user,$conversationId,'system_apps.permissions.read',trim(preg_replace('/\s+/',' ',$terms)),'all',$remote,$pdo);
}

function vp3_system_apps_catalog_v210(?array $user=null,?PDO $pdo=null): array
{
    $catalog=vp3_system_apps_catalog_v200($user,$pdo);
    $catalog['permission_governance_contract']='vp3.system-app-permission-governance.v1';
    return $catalog;
}

function vp3_system_apps_capability_v210(): array
{
    return array_replace(vp3_system_apps_capability_v200(),[
      'permission_governance_contract'=>'vp3.system-app-permission-governance.v1',
      'homeserver_permission_authority'=>true,
      'cloud_permission_projection'=>true,
      'new_permission_default_denied'=>true,
      'permission_expansion_requires_review'=>true,
      'permission_risk_classification'=>true,
      'effective_capability_projection'=>true,
      'confirmed_agent_permission_changes'=>true,
      'permission_grant_audit'=>true,
      'silent_permission_expansion'=>false,
    ]);
}
