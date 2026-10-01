<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v320.php';

const VP3_SYSTEM_APPS_V330='vp3-media-processor-v330-20260930';

function vp3_media_processor_status_v330(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.media-processor','processor.status',[],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    return [
      'contract'=>'vp3.media-processor-cloud.v1',
      'active'=>(int)($result['active']??0),
      'queued'=>(int)($result['queued']??0),
      'failed'=>(int)($result['failed']??0),
      'completed'=>(int)($result['completed']??0),
      'derivatives'=>(int)($result['derivatives']??0),
      'ffmpeg_available'=>!empty($result['ffmpeg_available']),
      'ffmpeg_managed_by_homeserver'=>!empty($result['ffmpeg_managed_by_homeserver']),
      'ffmpeg_version'=>(string)($result['ffmpeg_version']??''),
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ];
}

function vp3_media_processor_jobs_v330(int $userId,int $limit=12,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.media-processor','processor.jobs',[
      'limit'=>max(1,min(50,$limit)),
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    $jobs=[];
    foreach((array)($result['jobs']??[]) as $row){
        if(!is_array($row))continue;
        $jobs[]=[
          'job_id'=>(string)($row['job_id']??''),
          'media_id'=>(string)($row['media_id']??''),
          'operation'=>(string)($row['operation']??''),
          'preset'=>(string)($row['preset']??''),
          'status'=>(string)($row['status']??''),
          'progress'=>(float)($row['progress']??0),
          'eta_seconds'=>isset($row['eta_seconds'])?(int)$row['eta_seconds']:null,
          'output_ready'=>!empty($row['output_ready']),
          'error'=>(string)($row['error']??''),
        ];
    }
    return [
      'contract'=>'vp3.media-processor-jobs-cloud.v1',
      'jobs'=>$jobs,
      'count'=>count($jobs),
      'source_paths_exposed'=>false,
      'output_paths_exposed'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_media_processor_brain_context_v330(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.media-processor','processor.brain-context',[
      'limit'=>8,
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    return [
      'contract'=>'vp3.media-processor.brain-cloud.v1',
      'summary'=>is_array($result['summary']??null)?$result['summary']:[],
      'attention'=>array_values(array_filter((array)($result['attention']??[]),'is_array')),
      'recent'=>array_values(array_filter((array)($result['recent']??[]),'is_array')),
      'source_paths_exposed'=>false,
      'output_paths_exposed'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_media_processor_agent_query_v330(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:media processor|transcod|convert|compress|thumbnail|editing prox|video prox|media processing)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{
        $status=vp3_media_processor_status_v330($uid,$remote);
        $context=vp3_media_processor_brain_context_v330($uid,$remote);
        $jobs=vp3_media_processor_jobs_v330($uid,8,$remote);
    }catch(Throwable $e){return $empty;}
    $answer='Media Processor has '.$status['active'].' active, '.$status['queued'].' queued, '
      .$status['failed'].' failed, and '.$status['completed'].' completed processing job'
      .($status['completed']===1?'':'s').'. ';
    if($status['ffmpeg_available']){
        $answer.='HomeServer-managed FFmpeg is available'
          .($status['ffmpeg_version']!==''?' ('.$status['ffmpeg_version'].')':'').'. ';
    }else{
        $answer.='HomeServer-managed FFmpeg is not currently available. ';
    }
    if(!empty($context['attention'])){
        $answer.=count($context['attention']).' processing job'
          .(count($context['attention'])===1?' needs':'s need').' attention. ';
    }
    if($jobs['count']>0){
        $first=$jobs['jobs'][0];
        $answer.='Most recent: '.$first['operation'].' — '.$first['status'].'.';
    }
    if(preg_match('/\b(?:convert|compress|create|make|generate|cancel|retry|process|proxy|thumbnail)\b/i',$query)){
        $answer.=' Processing changes are executed through the governed HomeServer Agent action path; Cloud does not run FFmpeg.';
    }
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.media_processor.read',$query,'success',[
      'active'=>$status['active'],'queued'=>$status['queued'],'failed'=>$status['failed']
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Media Processor','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:media-processor','title'=>'VP3 Media Processor']],
      'media_processor'=>$status,
      'media_processor_brain_context'=>$context,
      'media_processor_jobs'=>$jobs,
    ];
}

function vp3_system_apps_capability_v330(): array
{
    return array_replace(vp3_system_apps_capability_v320(),[
      'media_processor_contract'=>'vp3.media-processor-cloud.v1',
      'media_processor_status'=>true,
      'media_processor_jobs'=>true,
      'media_processor_agent_chat'=>true,
      'media_processor_agent_brain_context'=>true,
      'media_processor_managed_ffmpeg'=>true,
      'media_processor_media_server_handoff'=>true,
      'media_processor_download_manager_handoff'=>true,
      'media_processor_video_editor_handoff'=>true,
      'media_processor_cloud_execution'=>false,
      'media_processor_homeserver_execution'=>true,
      'media_processor_source_paths_exposed'=>false,
      'media_processor_output_paths_exposed'=>false,
    ]);
}
