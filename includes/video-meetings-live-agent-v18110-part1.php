<?php
declare(strict_types=1);

function video_meeting_live_agent_text_v18110(mixed $value,int $limit=4000): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??''),0,max(0,$limit),'…');
}

function video_meeting_live_agent_source_hash_v18110(array $meeting): string
{
    return hash('sha256','vp3-live-meeting-agent|'.(string)($meeting['public_id']??'').'|v18110');
}

function video_meeting_live_agent_owner_allowed_v18110(array $user,array $meeting): bool
{
    return (int)($user['id']??0)>0&&(int)($user['id']??0)===(int)($meeting['owner_user_id']??0);
}

function video_meeting_live_agent_artifact_v18110(PDO $pdo,array $meeting): ?array
{
    $hash=video_meeting_live_agent_source_hash_v18110($meeting);
    $stmt=$pdo->prepare('SELECT result_json,generated_at FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND source_hash=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETING_LIVE_AGENT_APP_V18110,$hash]);
    $row=$stmt->fetch();if(!is_array($row))return null;
    $state=json_decode((string)($row['result_json']??''),true);
    return is_array($state)?$state:null;
}

function video_meeting_live_agent_store_v18110(PDO $pdo,array $meeting,array $state): array
{
    $state['version']='v18.11';$state['meeting_id']=(int)$meeting['id'];$state['updated_at']=gmdate('c');
    $json=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Could not persist the live meeting Agent state.');
    $hash=video_meeting_live_agent_source_hash_v18110($meeting);
    $pdo->prepare("INSERT INTO video_meeting_artifacts (meeting_id,app_id,artifact_type,result_json,source_hash,generated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json),artifact_type=VALUES(artifact_type),generated_at=NOW()")
        ->execute([(int)$meeting['id'],VP3_VIDEO_MEETING_LIVE_AGENT_APP_V18110,VP3_VIDEO_MEETING_LIVE_AGENT_ARTIFACT_V18110,$json,$hash]);
    return $state;
}

function video_meeting_live_agent_runtime_plan_v18110(PDO $pdo,array $meeting,array $user): array
{
    $agentId=max(0,(int)($meeting['organizer_agent_id']??0));
    if(!function_exists('vp3_agent_runtime_plan_v420'))return ['blocked'=>true,'route'=>'blocked','route_reason'=>'runtime_unavailable','agent_id'=>$agentId];
    try{return vp3_agent_runtime_plan_v420($pdo,$user,$agentId,'chat');}
    catch(Throwable $e){return ['blocked'=>true,'route'=>'blocked','route_reason'=>'runtime_unavailable','agent_id'=>$agentId];}
}

function video_meeting_live_agent_capability_v18110(PDO $pdo,array $meeting,array $user): array
{
    $plan=video_meeting_live_agent_runtime_plan_v18110($pdo,$meeting,$user);
    $live=(string)($meeting['status']??'')==='live';
    $chatAllowed=function_exists('has_permission')?has_permission('chat.access',$user):true;
    $runtimeReady=function_exists('chat_generate_answer_v105')||function_exists('chat_generate_answer');
    $cfg=video_meeting_livekit_config_v1800();
    $mediaWorker=video_meeting_livekit_ready_v1800()&&trim((string)($cfg['agent_name']??''))!=='';
    return [
        'available'=>$live&&$chatAllowed&&$runtimeReady&&empty($plan['blocked']),
        'meeting_live'=>$live,'chat_allowed'=>$chatAllowed,'runtime_ready'=>$runtimeReady,
        'interaction_mode'=>'text','spoken_available'=>false,'media_worker_available'=>$mediaWorker,
        'agent_id'=>max(0,(int)($meeting['organizer_agent_id']??0)),
        'agent_name'=>function_exists('video_meeting_agent_name_v1800')?video_meeting_agent_name_v1800($pdo,$meeting):'VP3 Agent',
        'runtime_plan'=>function_exists('vp3_agent_runtime_public_plan_v420')?vp3_agent_runtime_public_plan_v420($plan):['route'=>(string)($plan['route']??'blocked'),'blocked'=>!empty($plan['blocked'])],
    ];
}
