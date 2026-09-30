<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v200.php';

const VP3_SYSTEM_APPS_V210='system-app-permission-governance-v210-20260930';

function vp3_system_apps_permission_status_v210(
    int $userId,string $appKey,?callable $remote=null,?PDO $pdo=null
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
    if(!function_exists('vp3_system_apps_agent_find_app_v160'))return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    $app=vp3_system_apps_agent_find_app_v160($query,$user,$pdo);
    if(!$app)return ['handled'=>true,'answer'=>'I need the System App name to show its permissions.','stem_media'=>[],'media'=>[],'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],'sources'=>[]];
    try{$status=vp3_system_apps_permission_status_v210((int)$user['id'],(string)$app['app_key'],$remote,$pdo);}
    catch(Throwable $e){return ['handled'=>true,'answer'=>'I could not load that System App’s permission state: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];}
    $rows=$status['permissions'];$name=(string)$app['name'];
    if(!$rows)$answer=$name.' does not currently declare any privileged HomeServer capabilities.';
    else{
        $answer=$name.' declares '.count($rows).' governed permission'.(count($rows)===1?'':'s').'.';
        foreach($rows as $row){
            $answer.="\n• ".(string)$row['permission'].' — '.(!empty($row['allowed'])?'allowed':'denied').' · '.(string)($row['risk']??'unknown').' risk';
        }
        $answer.="\nNew permissions introduced by an update default to denied and require review.";
    }
    if(function_exists('agent_tool_log'))agent_tool_log($user,'system_apps.permissions',$query,'success',['app_key'=>$app['app_key'],'declared_count'=>$status['declared_count'],'allowed_count'=>$status['allowed_count']],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'system-apps:permissions','title'=>'VP3 System App permission governance']],
      'system_app_permissions'=>$status,
    ];
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
