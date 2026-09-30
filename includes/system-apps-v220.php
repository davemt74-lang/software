<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v210.php';

const VP3_SYSTEM_APPS_V220='user-app-sdk-integration-v220-20260930';

function vp3_user_apps_snapshot_v220(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.user.list',[],$remote);
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
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{$snapshot=vp3_user_apps_snapshot_v220($uid,$remote);}
    catch(Throwable $e){
        return [
          'handled'=>true,
          'answer'=>'I could not read your HomeServer user-app registry right now.',
          'stem_media'=>[],'media'=>[],'actions'=>[],
          'sources'=>[['source'=>'homeserver:user-apps','title'=>'HomeServer user-app registry unavailable']],
          'user_apps'=>['contract'=>'vp3.user-app-cloud-projection.v1','failed'=>true],
        ];
    }
    $rows=[];
    foreach($snapshot['items'] as $app){
        $parts=[
          (string)$app['name'],
          (string)$app['lifecycle_state'],
          !empty($app['installed_version'])?'v'.(string)$app['installed_version']:'',
          !empty($app['runtime'])?(string)$app['runtime']:'',
          !empty($app['sdk_version'])?'SDK '.(string)$app['sdk_version']:'',
        ];
        $rows[]='• '.implode(' · ',array_values(array_filter($parts,static fn($v)=>trim((string)$v)!=='')));
    }
    $answer=$rows
      ? "These are the user-created apps currently registered on HomeServer:\n".implode("\n",$rows)
      : 'HomeServer does not currently report any user-created apps.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.user_apps.read',$query,'success',['count'=>$snapshot['count']],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:user-apps','title'=>'HomeServer user-app registry']],
      'user_apps'=>$snapshot,
    ];
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
