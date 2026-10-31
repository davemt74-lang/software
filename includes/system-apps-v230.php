<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v220.php';

const VP3_SYSTEM_APPS_V230='user-app-development-workspace-v230-20260930';

function vp3_user_app_workspace_status_v230(
    int $userId,string $appKey,?callable $remote=null,bool $strict=false
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $key=trim($appKey);
    if($key==='')throw new RuntimeException('App key is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.user.workspace.status',['app_key'=>$key],$remote);
    if((string)($payload['app_key']??'')!==''&&(string)$payload['app_key']!==$key){
        throw new RuntimeException('HomeServer returned mismatched user-app workspace identity.');
    }
    if($strict){
        if(($payload['app_key']??null)!==$key||!is_array($payload['project']??null))throw new RuntimeException('Workspace identity or project unavailable.');
        vp3_app_read_counts_v1($payload['project'],['count']);
        if(isset($payload['validation'])&&(!is_array($payload['validation'])||!is_bool($payload['validation']['valid']??null)))throw new RuntimeException('Workspace validation could not be verified.');
        if(isset($payload['releases'])){if(!is_array($payload['releases']))throw new RuntimeException('Workspace releases unavailable.');vp3_app_read_counts_v1($payload['releases'],['count']);}
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
    $terms=preg_replace('/\b(?:show|inspect|read|check|my|the|for|of|app workspace|project files|app project|build status|validation|validate app|app failing|app error|release status)\b/i',' ',$query);
    return vp3_app_agent_legacy_v1($user,$conversationId,'apps.workspace.read',trim(preg_replace('/\s+/',' ',$terms)),$remote);
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
