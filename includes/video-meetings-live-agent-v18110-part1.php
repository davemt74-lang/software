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

function video_meeting_live_agent_homeserver_executor_ready_v18110(array $user): bool
{
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('homeserver_agent_v018_credentials')||!function_exists('homeserver_vp3_remote_operation'))return false;
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!is_array($credentials))return false;
    if(function_exists('homeserver_capability_v024_registry')&&function_exists('homeserver_capability_v024_resolve')){
        try{
            $registry=homeserver_capability_v024_registry($userId,false);
            $cap=homeserver_capability_v024_resolve($registry,'agent_brain','vp3_cloud');
            return !empty($cap['supported'])&&!empty($cap['ready']);
        }catch(Throwable $e){return false;}
    }
    return true;
}

function video_meeting_live_agent_runtime_plan_v18110(PDO $pdo,array $meeting,array $user): array
{
    $agentId=max(0,(int)($meeting['organizer_agent_id']??0));
    if(!function_exists('vp3_agent_runtime_plan_v420'))return ['blocked'=>true,'route'=>'blocked','route_reason'=>'runtime_unavailable','agent_id'=>$agentId];
    try{$plan=vp3_agent_runtime_plan_v420($pdo,$user,$agentId,'chat');}
    catch(Throwable $e){return ['blocked'=>true,'route'=>'blocked','route_reason'=>'runtime_unavailable','agent_id'=>$agentId];}

    // Meeting privacy outranks the account/Agent compute preference. A meeting
    // that disallows cloud AI is forced onto the existing HomeServer agent.chat
    // executor with cloud delegation and VP3 fallback both disabled.
    try{
        $public=video_meeting_intelligence_public_state_v1890($pdo,$meeting);
        $policy=is_array($public['processing_policy']??null)?$public['processing_policy']:[];
        if(array_key_exists('policy_resolved',$policy)&&empty($policy['policy_resolved'])){
            $plan['blocked']=true;$plan['route']='blocked';$plan['route_reason']='meeting_policy_unresolved';
            $plan['allow_vp3_fallback']=false;$plan['fallback_target']='none';$plan['homeserver_cloud_allowed']=false;return $plan;
        }
        $requiresHome=(($policy['cloud_ai_allowed']??null)===false)||(string)($policy['requested_compute']??'')==='homeserver_only';
        if($requiresHome){
            $ready=video_meeting_live_agent_homeserver_executor_ready_v18110($user);
            $plan['blocked']=!$ready;$plan['route']=$ready?'homeserver':'blocked';$plan['route_reason']=$ready?'meeting_private_homeserver':'meeting_homeserver_unavailable';
            $plan['try_homeserver']=$ready;$plan['allow_vp3_fallback']=false;$plan['fallback_target']='none';$plan['homeserver_cloud_allowed']=false;
            return $plan;
        }
        if(!empty($plan['blocked']))return $plan;
        if(((string)($plan['route']??'')==='homeserver'||!empty($plan['try_homeserver']))&&!video_meeting_live_agent_homeserver_executor_ready_v18110($user)){
            if(!empty($plan['allow_vp3_fallback'])&&!empty($plan['cloud']['ready'])){
                $plan['route']='vp3_cloud';$plan['try_homeserver']=false;$plan['route_reason']='homeserver_executor_unavailable_cloud_fallback';
            }else{
                $plan['blocked']=true;$plan['route']='blocked';$plan['route_reason']='homeserver_live_agent_executor_unavailable';
            }
        }
    }catch(Throwable $e){
        $plan['blocked']=true;$plan['route']='blocked';$plan['route_reason']='meeting_policy_unavailable';
        $plan['allow_vp3_fallback']=false;$plan['fallback_target']='none';$plan['homeserver_cloud_allowed']=false;
    }
    return $plan;
}

function video_meeting_live_agent_capability_v18110(PDO $pdo,array $meeting,array $user): array
{
    $plan=video_meeting_live_agent_runtime_plan_v18110($pdo,$meeting,$user);
    $live=(string)($meeting['status']??'')==='live';$enabled=(string)($meeting['agent_mode']??'off')!=='off';
    $chatAllowed=function_exists('has_permission')?has_permission('chat.access',$user):true;
    $route=(string)($plan['route']??'blocked');
    $provider=function_exists('ai_active_provider')?(string)ai_active_provider():'local';
    $cloudReady=function_exists('chat_remote_answer')&&in_array($provider,['openai','anthropic'],true)&&function_exists('ai_provider_ready')&&ai_provider_ready($provider);
    $homeReady=video_meeting_live_agent_homeserver_executor_ready_v18110($user);
    $generatorReady=$route==='vp3_cloud'?$cloudReady:($route==='homeserver'?$homeReady:false);
    $cfg=video_meeting_livekit_config_v1800();$mediaWorker=video_meeting_livekit_ready_v1800()&&trim((string)($cfg['agent_name']??''))!=='';
    return [
        'available'=>$live&&$enabled&&$chatAllowed&&$generatorReady&&empty($plan['blocked']),
        'meeting_live'=>$live,'agent_enabled'=>$enabled,'chat_allowed'=>$chatAllowed,'runtime_ready'=>$generatorReady,
        'provider'=>$route==='homeserver'?'homeserver':($cloudReady?$provider:''),'interaction_mode'=>'text','spoken_available'=>false,'media_worker_available'=>$mediaWorker,
        'agent_id'=>max(0,(int)($meeting['organizer_agent_id']??0)),
        'agent_name'=>function_exists('video_meeting_agent_name_v1800')?video_meeting_agent_name_v1800($pdo,$meeting):'VP3 Agent',
        'runtime_plan'=>function_exists('vp3_agent_runtime_public_plan_v420')?vp3_agent_runtime_public_plan_v420($plan):['route'=>$route,'blocked'=>!empty($plan['blocked'])],
    ];
}
