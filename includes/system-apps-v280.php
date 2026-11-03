<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v270.php';

const VP3_SYSTEM_APPS_V280='vp3-video-editor-agent-control-v280-20260930';

function vp3_video_editor_status_v280(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.video.status',[],$remote);
    return [
      'contract'=>'vp3.video-editor-cloud.v1',
      'app_key'=>(string)($payload['app_key']??'vp3.video-editor'),
      'installed_version'=>$payload['installed_version']??null,
      'lifecycle_state'=>(string)($payload['lifecycle_state']??''),
      'projects'=>(int)($payload['projects']??0),
      'clips'=>(int)($payload['clips']??0),
      'renders_active'=>(int)($payload['renders_active']??0),
      'media_server_available'=>!empty($payload['media_server_available']),
      'media_library_count'=>(int)($payload['media_library_count']??0),
      'render_execution'=>(string)($payload['render_execution']??'homeserver_local_worker'),
      'source_media_owned_by_editor'=>false,
      'homeserver_authority'=>true,
    ];
}

function vp3_video_editor_projects_v280(int $userId,int $limit=50,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.video.projects',['limit'=>max(1,min(100,$limit))],$remote);
    $projects=[];
    foreach((array)($payload['projects']??[]) as $row){
        if(!is_array($row))continue;
        $projects[]=[
          'project_id'=>(string)($row['project_id']??''),
          'name'=>(string)($row['name']??''),
          'width'=>(int)($row['width']??0),
          'height'=>(int)($row['height']??0),
          'fps'=>(float)($row['fps']??0),
          'updated_at'=>$row['updated_at']??null,
        ];
    }
    return ['contract'=>'vp3.video-editor-projects-cloud.v1','projects'=>$projects,'count'=>count($projects)];
}

function vp3_video_editor_agent_actions_v280(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.video.agent.actions',[],$remote);
    return [
      'contract'=>'vp3.app-agent-control-cloud.v1',
      'app_key'=>(string)($payload['app_key']??'vp3.video-editor'),
      'complete_control'=>!empty($payload['complete_control']),
      'actions'=>array_values(array_filter((array)($payload['actions']??[]),'is_array')),
      'destructive_actions_require_confirmation'=>true,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_video_editor_invoke_v280(
    int $userId,string $action,array $arguments=[],bool $confirmed=false,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $action=trim($action);
    if($action==='')throw new RuntimeException('Video Editor action is required.');
    return vp3_system_apps_remote_v110($userId,'apps.video.agent.invoke',[
      'action'=>$action,
      'arguments'=>$arguments,
      'confirmed'=>$confirmed,
    ],$remote);
}

function vp3_video_editor_agent_query_v280(string $query,array $user,int $conversationId=0,?callable $remote=null): array
{
    $empty=['handled'=>false,'answer'=>'','actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:video editor|editing project|timeline|render queue|video projects?)\b/i',$query)||!preg_match('/\b(?:show|list|find|search|status|how many|overview|jobs|projects|queue)\b/i',$query))return $empty;
    return vp3_photo_media_legacy_v1($query,$user,$conversationId,'video.projects.read',$remote);
}

function vp3_system_apps_capability_v280(): array
{
    return array_replace(vp3_system_apps_capability_v270(),[
      'video_editor_contract'=>'vp3.video-editor-cloud.v1',
      'video_editor_status'=>true,
      'video_editor_projects'=>true,
      'video_editor_agent_complete_control'=>true,
      'app_agent_manifest_control'=>true,
      'app_agent_destructive_confirmation'=>true,
      'homeserver_app_execution_authority'=>true,
    ]);
}
