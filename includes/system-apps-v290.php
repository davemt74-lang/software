<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v280.php';

const VP3_SYSTEM_APPS_V290='vp3-universal-app-control-v290-20260930';

function vp3_app_control_status_v290(int $userId,string $appKey,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $appKey=strtolower(trim($appKey));
    if($appKey==='')throw new RuntimeException('app_key is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.control.status',['app_key'=>$appKey],$remote);
    return [
      'contract'=>'vp3.app-control-cloud.v1',
      'app_key'=>(string)($payload['app_key']??$appKey),
      'compatibility'=>is_array($payload['compatibility']??null)?$payload['compatibility']:[],
      'manifest'=>is_array($payload['manifest']??null)?$payload['manifest']:[],
      'settings'=>is_array($payload['settings']??null)?$payload['settings']:[],
      'hosting'=>is_array($payload['hosting']??null)?$payload['hosting']:[],
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
      'secret_values_exposed'=>false,
    ];
}

function vp3_app_control_actions_v290(int $userId,string $appKey,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.control.actions',['app_key'=>strtolower(trim($appKey))],$remote);
    return [
      'contract'=>'vp3.app-control-actions-cloud.v1',
      'app_key'=>(string)($payload['app_key']??strtolower(trim($appKey))),
      'manifest_contract'=>(string)($payload['manifest_contract']??''),
      'actions'=>array_values(array_filter((array)($payload['actions']??[]),'is_array')),
      'count'=>(int)($payload['count']??count((array)($payload['actions']??[]))),
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_app_control_settings_v290(int $userId,string $appKey,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.control.settings',['app_key'=>strtolower(trim($appKey))],$remote);
    return [
      'contract'=>'vp3.app-settings-cloud.v1',
      'app_key'=>(string)($payload['app_key']??strtolower(trim($appKey))),
      'schema'=>is_array($payload['schema']??null)?$payload['schema']:[],
      'values'=>is_array($payload['values']??null)?$payload['values']:[],
      'secret_values_exposed'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_app_control_hosting_v290(int $userId,string $appKey,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.control.hosting',['app_key'=>strtolower(trim($appKey))],$remote);
    return [
      'contract'=>'vp3.app-hosting-control-cloud.v1',
      'app_key'=>(string)($payload['app_key']??strtolower(trim($appKey))),
      'sites'=>array_values(array_filter((array)($payload['sites']??[]),'is_array')),
      'count'=>(int)($payload['count']??0),
      'homeserver_runtime_authority'=>true,
      'cloud_identity_authority'=>true,
    ];
}

function vp3_app_control_invoke_v290(
    int $userId,string $appKey,string $action,array $arguments=[],bool $confirmed=false,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $appKey=strtolower(trim($appKey));$action=trim($action);
    if($appKey===''||$action==='')throw new RuntimeException('app_key and action are required.');
    return vp3_system_apps_remote_v110($userId,'apps.control.invoke',[
      'app_key'=>$appKey,'action'=>$action,'arguments'=>$arguments,'confirmed'=>$confirmed,
    ],$remote);
}

function vp3_app_control_settings_set_v290(
    int $userId,string $appKey,array $values,bool $confirmed=false,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    if(!$confirmed)throw new RuntimeException('App settings changes require explicit confirmation.');
    return vp3_system_apps_remote_v110($userId,'apps.control.settings.set',[
      'app_key'=>strtolower(trim($appKey)),'values'=>$values,'confirmed'=>true,
    ],$remote);
}

function vp3_app_control_match_v290(string $query,int $userId,?callable $remote=null): ?array
{
    try{$manager=vp3_system_apps_remote_v110($userId,'apps.manager.status',[],$remote);}catch(Throwable $e){return null;}
    $needle=mb_strtolower($query);
    $best=null;$bestLen=0;
    foreach((array)($manager['items']??[]) as $row){
        if(!is_array($row)||empty($row['installed']))continue;
        foreach([(string)($row['app_key']??''),(string)($row['name']??'')] as $candidate){
            $candidate=trim($candidate);if($candidate==='')continue;
            $lower=mb_strtolower($candidate);
            if(mb_strlen($lower)>=$bestLen&&str_contains($needle,$lower)){
                $best=$row;$bestLen=mb_strlen($lower);
            }
        }
    }
    return is_array($best)?$best:null;
}

function vp3_app_control_agent_query_v290(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:app control|agent control|settings|permissions|actions|capabilities|what can .* app|control .* app|manage .* app)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    $match=vp3_app_control_match_v290($query,$uid,$remote);if(!$match)return $empty;
    $appKey=(string)($match['app_key']??'');if($appKey==='')return $empty;
    try{$status=vp3_app_control_status_v290($uid,$appKey,$remote);}catch(Throwable $e){return $empty;}
    $compatible=!empty($status['compatibility']['compatible']);
    $actions=(array)($status['manifest']['actions']??[]);
    $settings=(array)($status['settings']['schema']['fields']??[]);
    $hosting=(int)($status['hosting']['count']??0);
    $name=(string)($match['name']??$appKey);
    $answer=$name.' is '.($compatible?'compatible':'not fully compatible').' with the universal HomeServer Agent control contract. ';
    $answer.='It exposes '.count($actions).' app action'.(count($actions)===1?'':'s').', '.count($settings).' setting'.(count($settings)===1?'':'s').', and '.$hosting.' hosting binding'.($hosting===1?'':'s').'. ';
    $answer.='Execution remains authoritative on the HomeServer; Cloud can inspect and orchestrate but does not become a second app runtime.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.app_control.read',$query,'success',[
      'app_key'=>$appKey,'compatible'=>$compatible,'actions'=>count($actions),'settings'=>count($settings)
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:app-control','title'=>$name.' App Control']],
      'app_control'=>$status,
    ];
}

function vp3_system_apps_capability_v290(): array
{
    return array_replace(vp3_system_apps_capability_v280(),[
      'universal_app_control_contract'=>'vp3.app.agent-control.v3',
      'generic_app_control_status'=>true,
      'generic_app_action_discovery'=>true,
      'generic_app_action_invocation'=>true,
      'generic_app_settings_control'=>true,
      'generic_app_hosting_projection'=>true,
      'control_compatibility_negotiation'=>true,
      'cloud_execution_authority'=>false,
      'homeserver_execution_authority'=>true,
      'secret_values_exposed'=>false,
    ]);
}
