<?php
declare(strict_types=1);

/**
 * Phase 18.1 hardening: connect Video Meetings to the existing HomeServer
 * compute/privacy policy without inventing a second pairing or capability
 * system. LiveKit remains the cloud media transport. This policy only governs
 * meeting AI/transcription processing and reports capabilities already exposed
 * by the paired HomeServer.
 */
const VP3_VIDEO_MEETINGS_HOMESERVER_V1801='video-meetings-homeserver-v1801-20260915';

function video_meeting_homeserver_policy_v1801(PDO $pdo,array $meeting,bool $forceRefresh=false): array
{
    static $cache=[];
    $meetingId=(int)($meeting['id']??0);$ownerId=(int)($meeting['owner_user_id']??0);$agentId=(int)($meeting['organizer_agent_id']??0);
    $cacheKey=$meetingId.'|'.($forceRefresh?'1':'0');
    if(!$forceRefresh&&isset($cache[$cacheKey]))return $cache[$cacheKey];

    $state=[
        'version'=>'v18.1',
        'paired'=>false,
        'available'=>false,
        'requested_compute'=>'auto',
        'cloud_processing_allowed'=>true,
        'cloud_block_reason'=>'',
        'scope_checked'=>false,
        'knowledge_searchable'=>false,
        'local_compute_available'=>false,
        'local_transcription_advertised'=>false,
        'local_transcription_operation'=>'',
        'reason'=>'homeserver_not_paired',
    ];
    if($ownerId<1)return $cache[$cacheKey]=$state;

    try{
        if(function_exists('vp3_agent_runtime_preference_v420')){
            $preference=vp3_agent_runtime_preference_v420($pdo,$ownerId,max(0,$agentId));
            $requested=(string)($preference['effective_preference']??'auto');
            if(in_array($requested,['auto','homeserver_only','vp3_cloud'],true))$state['requested_compute']=$requested;
        }
    }catch(Throwable $ignored){}

    if(function_exists('homeserver_agent_v018_credentials')){
        try{$state['paired']=homeserver_agent_v018_credentials($ownerId)!==null;}catch(Throwable $ignored){}
    }

    // Explicit VP3 Cloud is already a deliberate transport choice in the
    // canonical v4.20 router, so do not call HomeServer merely to second-guess
    // it. Otherwise apply the same HomeServer wrapper-scope cloud boundary.
    if($state['requested_compute']!=='vp3_cloud'&&function_exists('homeserver_scope_v026_fetch')&&function_exists('homeserver_scope_v026_blocks_cloud')){
        try{
            $scope=homeserver_scope_v026_fetch($ownerId,$forceRefresh);
            $state['scope_checked']=true;
            if(homeserver_scope_v026_blocks_cloud($scope)){
                $state['cloud_processing_allowed']=false;
                $state['cloud_block_reason']='homeserver_scope';
            }
        }catch(Throwable $ignored){}
    }
    if($state['requested_compute']==='homeserver_only'){
        $state['cloud_processing_allowed']=false;
        if($state['cloud_block_reason']==='')$state['cloud_block_reason']='homeserver_only';
    }

    // v0.33 is a sanitized capability view. It never exposes relay/bearer
    // credentials. Query it only for a paired HomeServer and only when the user
    // has not explicitly selected VP3 Cloud.
    if($state['paired']&&$state['requested_compute']!=='vp3_cloud'&&function_exists('homeserver_capability_v033_registry')){
        try{
            $registry=homeserver_capability_v033_registry($ownerId,$forceRefresh);
            $state['available']=!empty($registry['available']);
            $state['knowledge_searchable']=!empty($registry['knowledge']['available'])&&!empty($registry['knowledge']['searchable']);
            $state['local_compute_available']=!empty($registry['compute']['available']);
            $operations=is_array($registry['operations']??null)?$registry['operations']:[];
            foreach(['meeting.transcription.stream','transcription.stream','transcription.start'] as $operation){
                if(in_array($operation,$operations,true)){
                    $state['local_transcription_advertised']=true;
                    $state['local_transcription_operation']=$operation;
                    break;
                }
            }
            $state['reason']=$state['available']?'homeserver_ready':(string)($registry['reason']??'homeserver_unavailable');
        }catch(Throwable $ignored){
            $state['reason']='homeserver_unavailable';
        }
    }elseif($state['paired']){
        $state['reason']='homeserver_paired';
    }

    return $cache[$cacheKey]=$state;
}

function video_meeting_cloud_transcription_allowed_v1801(PDO $pdo,array $meeting): bool
{
    if(empty($meeting['transcription_enabled']))return false;
    return !empty(video_meeting_homeserver_policy_v1801($pdo,$meeting)['cloud_processing_allowed']);
}

function video_meeting_homeserver_public_policy_v1801(PDO $pdo,array $meeting): array
{
    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting);
    return [
        'version'=>(string)$state['version'],
        'paired'=>!empty($state['paired']),
        'available'=>!empty($state['available']),
        'requested_compute'=>(string)$state['requested_compute'],
        'cloud_processing_allowed'=>!empty($state['cloud_processing_allowed']),
        'cloud_block_reason'=>(string)$state['cloud_block_reason'],
        'knowledge_searchable'=>!empty($state['knowledge_searchable']),
        'local_compute_available'=>!empty($state['local_compute_available']),
        'local_transcription_advertised'=>!empty($state['local_transcription_advertised']),
        'local_transcription_operation'=>(string)$state['local_transcription_operation'],
        'reason'=>(string)$state['reason'],
    ];
}
