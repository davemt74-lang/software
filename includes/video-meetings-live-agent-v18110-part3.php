<?php
declare(strict_types=1);

function video_meeting_live_agent_start_v18110(PDO $pdo,array $meeting,array $user): array
{
    if(!video_meeting_live_agent_owner_allowed_v18110($user,$meeting))throw new RuntimeException('Only the meeting organizer can start the live Agent.');
    $cap=video_meeting_live_agent_capability_v18110($pdo,$meeting,$user);
    if(empty($cap['meeting_live']))throw new RuntimeException('Join the live meeting before starting the Agent.');
    if(empty($cap['agent_enabled']))throw new RuntimeException('Meeting Agent mode is off for this meeting.');
    if(empty($cap['chat_allowed']))throw new RuntimeException('Agent Chat access is not available for this account.');
    if(empty($cap['runtime_ready']))throw new RuntimeException('The selected live Agent compute route is unavailable.');
    if(!empty($cap['runtime_plan']['blocked'])){
        $plan=video_meeting_live_agent_runtime_plan_v18110($pdo,$meeting,$user);$reason=(string)($plan['route_reason']??'');
        if($reason==='meeting_policy_unresolved')throw new RuntimeException('The meeting privacy policy is not resolved yet. The live Agent remains blocked.');
        if(in_array($reason,['meeting_homeserver_unavailable','homeserver_live_agent_executor_unavailable'],true))throw new RuntimeException('This meeting requires HomeServer Agent compute, but the paired HomeServer Agent runtime is not ready.');
        if($reason==='meeting_policy_unavailable')throw new RuntimeException('The meeting privacy policy could not be verified. The live Agent remains blocked.');
        throw new RuntimeException(function_exists('vp3_agent_runtime_block_message_v420')?vp3_agent_runtime_block_message_v420($plan):'No authorized Agent compute route is currently available.');
    }
    $route=(string)($cap['runtime_plan']['route']??'');if(!in_array($route,['vp3_cloud','homeserver'],true))throw new RuntimeException('The live Agent generator is not bound to the selected compute route.');
    $state=video_meeting_live_agent_artifact_v18110($pdo,$meeting)?:[];
    if(!empty($state['active']))return video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user);

    $media=['ok'=>true,'dispatched'=>false,'reason'=>'media_worker_not_required'];
    if(!empty($cap['media_worker_available'])&&function_exists('video_meeting_livekit_agent_dispatch_v1800')){
        $dispatch=video_meeting_livekit_agent_dispatch_v1800($pdo,$meeting);
        $media=['ok'=>!empty($dispatch['ok']),'dispatched'=>!empty($dispatch['dispatched']),'reason'=>video_meeting_live_agent_text_v18110($dispatch['reason']??'',100)];
    }
    $state=[
        'active'=>true,'session_id'=>'mla-'.substr(hash('sha256',(string)$meeting['public_id'].'|'.bin2hex(random_bytes(16))),0,32),
        'started_at'=>gmdate('c'),'stopped_at'=>'','media_worker'=>$media,'turns'=>[],
        'receipts'=>[],'homeserver_conversation_id'=>'',
    ];
    video_meeting_live_agent_store_v18110($pdo,$meeting,$state);
    return video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user);
}

function video_meeting_live_agent_stop_v18110(PDO $pdo,array $meeting,array $user): array
{
    if(!video_meeting_live_agent_owner_allowed_v18110($user,$meeting))throw new RuntimeException('Only the meeting organizer can stop the live Agent.');
    $state=video_meeting_live_agent_artifact_v18110($pdo,$meeting)?:[];
    if(!$state)return video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user);
    $state['active']=false;$state['stopped_at']=gmdate('c');
    // The LiveKit worker may still be providing the canonical meeting transcript.
    // Stopping live participation therefore stops Agent turns only; it never tears
    // down transcription or the room media path.
    video_meeting_live_agent_store_v18110($pdo,$meeting,$state);
    return video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user);
}

function video_meeting_live_agent_sources_v18110(mixed $context): array
{
    $out=[];$seen=[];foreach(is_array($context)?$context:[] as $row){if(!is_array($row))continue;$source=video_meeting_live_agent_text_v18110($row['source']??'',300);$title=video_meeting_live_agent_text_v18110($row['title']??'',300);if($source===''||isset($seen[$source]))continue;$seen[$source]=true;$out[]=['source'=>$source,'title'=>$title];if(count($out)>=12)break;}return $out;
}
