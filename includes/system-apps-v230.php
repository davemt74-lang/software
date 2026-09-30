<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v220.php';

const VP3_SYSTEM_APPS_V230='user-app-development-workspace-v230-20260930';

function vp3_user_app_workspace_status_v230(
    int $userId,string $appKey,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $key=trim($appKey);
    if($key==='')throw new RuntimeException('App key is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.user.workspace.status',['app_key'=>$key],$remote);
    if((string)($payload['app_key']??'')!==''&&(string)$payload['app_key']!==$key){
        throw new RuntimeException('HomeServer returned mismatched user-app workspace identity.');
    }
    return [
      'contract'=>'vp3.user-app-development-workspace.v1',
      'app_key'=>$key,
      'validation'=>is_array($payload['validation']??null)?$payload['validation']:null,
      'validation_error'=>(string)($payload['validation_error']??''),
      'project'=>is_array($payload['project']??null)?$payload['project']:['files'=>[],'count'=>0],
      'permissions'=>is_array($payload['permissions']??null)?$payload['permissions']:null,
      'resources'=>is_array($payload['resources']??null)?$payload['resources']:null,
      'runtime'=>is_array($payload['runtime']??null)?$payload['runtime']:null,
      'releases'=>is_array($payload['releases']??null)?$payload['releases']:null,
      'source'=>is_array($payload['source']??null)?$payload['source']:null,
      'source_content_exposed'=>false,
      'authority'=>[
        'source'=>'homeserver',
        'runtime'=>'homeserver',
        'releases'=>'homeserver',
        'permissions'=>'homeserver',
        'cloud_source_repository'=>false,
      ],
    ];
}

function vp3_user_app_workspace_query_v230(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:app workspace|project files|app project|build status|validation|validate app|app failing|app error|release status)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;

    try{$apps=vp3_user_apps_snapshot_v220($uid,$remote);}
    catch(Throwable $e){return $empty;}
    $q=mb_strtolower($query);
    $match=null;
    foreach((array)$apps['items'] as $app){
        $name=mb_strtolower((string)($app['name']??''));
        $key=mb_strtolower((string)($app['app_key']??''));
        if(($name!==''&&str_contains($q,$name))||($key!==''&&str_contains($q,$key))){$match=$app;break;}
    }
    if(!$match){
        return [
          'handled'=>true,
          'answer'=>'Name the user-created app you want me to inspect.',
          'stem_media'=>[],'media'=>[],
          'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
          'sources'=>[['source'=>'homeserver:user-app-workspace','title'=>'HomeServer Development Workspace']],
        ];
    }
    try{$status=vp3_user_app_workspace_status_v230($uid,(string)$match['app_key'],$remote);}
    catch(Throwable $e){
        return [
          'handled'=>true,
          'answer'=>'I could not read that app’s HomeServer development workspace right now.',
          'stem_media'=>[],'media'=>[],'actions'=>[],
          'sources'=>[['source'=>'homeserver:user-app-workspace','title'=>'HomeServer Development Workspace unavailable']],
        ];
    }
    $validation=$status['validation'];
    $valid=is_array($validation)&&!empty($validation['valid']);
    $answer=(string)$match['name'].' · '.($valid?'project validation passes':'project needs attention').'.';
    if(!$valid&&!empty($status['validation_error']))$answer.=' '.(string)$status['validation_error'];
    $answer.=' Project files: '.(int)($status['project']['count']??0).'.';
    if(is_array($status['releases']))$answer.=' Releases: '.(int)($status['releases']['count']??0).'.';
    if(is_array($status['permissions']))$answer.=' Permissions allowed: '.(int)($status['permissions']['allowed_count']??0).' of '.(int)($status['permissions']['declared_count']??0).'.';
    $answer.=' Source contents stay on HomeServer; Cloud only receives diagnostics and file metadata.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.user_app_workspace.read',$query,'success',['app_key'=>$match['app_key'],'valid'=>$valid],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Apps','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:user-app-workspace','title'=>'HomeServer Development Workspace']],
      'user_app_workspace'=>$status,
    ];
}

function vp3_system_apps_capability_v230(): array
{
    return array_replace(vp3_system_apps_capability_v220(),[
      'user_app_development_workspace_contract'=>'vp3.user-app-development-workspace.v1',
      'cloud_workspace_diagnostics'=>true,
      'cloud_workspace_source_contents'=>false,
      'agent_workspace_diagnostics'=>true,
      'homeserver_workspace_authority'=>true,
      'workspace_build_uses_canonical_release_engine'=>true,
      'workspace_preview_uses_canonical_runtime'=>true,
    ]);
}
