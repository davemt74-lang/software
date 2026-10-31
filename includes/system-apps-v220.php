<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v210.php';
require_once __DIR__.'/agent-app-read-v1.php';

const VP3_SYSTEM_APPS_V220='user-app-sdk-integration-v220-20260930';

function vp3_user_apps_snapshot_v220(int $userId,?callable $remote=null,bool $strict=false): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.user.list',[],$remote);
    if($strict)vp3_app_read_items_v1($payload['items']??null);
    $items=is_array($payload['items']??null)?$payload['items']:[];
    $safe=[];
    foreach($items as $row){
        if(!is_array($row))continue;
        $safe[]=[
          'app_key'=>(string)($row['app_key']??''),
          'name'=>(string)($row['name']??''),
          'source_type'=>(string)($row['source_type']??''),
          'lifecycle_state'=>(string)($row['lifecycle_state']??''),
          'installed_version'=>$row['installed_version']??null,
          'runtime'=>(string)($row['runtime']??''),
          'sdk_version'=>(string)($row['sdk_version']??''),
          'permissions'=>is_array($row['permissions']??null)?$row['permissions']:null,
          'release'=>is_array($row['release']??null)?$row['release']:null,
          'data'=>is_array($row['data']??null)?$row['data']:null,
          'distribution'=>is_array($row['distribution']??null)?$row['distribution']:null,
        ];
    }
    return [
      'contract'=>'vp3.user-app-cloud-projection.v1',
      'authority'=>[
        'registry'=>'homeserver',
        'runtime'=>'homeserver',
        'data'=>'homeserver',
        'permissions'=>'homeserver',
        'distribution_provenance'=>'homeserver',
        'cloud_registry'=>false,
      ],
      'items'=>$safe,
      'count'=>count($safe),
    ];
}

function vp3_user_apps_agent_query_v220(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:my apps|user apps|apps i built|apps i created|created apps|built apps|home ?server apps)\b/i',$query))return $empty;
    return vp3_app_agent_legacy_v1($user,$conversationId,'apps.user.read','',$remote);
}

function vp3_system_apps_capability_v220(): array
{
    return array_replace(vp3_system_apps_capability_v210(),[
      'user_app_sdk_contract'=>'vp3.app.sdk.v1',
      'user_app_sdk_version'=>'1.1',
      'homeserver_user_app_registry_authority'=>true,
      'cloud_user_app_registry'=>false,
      'cloud_user_app_projection'=>true,
      'agent_user_app_reads'=>true,
      'starter_release_integration'=>true,
      'starter_permission_integration'=>true,
      'starter_data_recovery_integration'=>true,
    ]);
}
