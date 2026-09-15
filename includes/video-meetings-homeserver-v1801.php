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
    $viewer=function_exists('current_user')?current_user():null;
    $ownerContext=(int)($viewer['id']??0)>0&&(int)($viewer['id']??0)===$ownerId;
    $cacheKey=$meetingId.'|'.($ownerContext?'owner':'nonowner').'|'.($forceRefresh?'1':'0');
    if(!$forceRefresh&&isset($cache[$cacheKey]))return $cache[$cacheKey];

    $state=[
        'version'=>'v18.1',
        'paired'=>false,
        'available'=>false,
        'policy_resolved'=>false,
        'owner_context'=>$ownerContext,
        'requested_compute'=>'auto',
        // null means the caller is not authorized to probe the owner's live
        // HomeServer scope and therefore must not claim a processing route.
        'cloud_processing_allowed'=>null,
        'cloud_block_reason'=>'',
        'scope_checked'=>false,
        'knowledge_searchable'=>false,
        'local_compute_available'=>false,
        'local_transcription_advertised'=>false,
        'local_transcription_operation'=>'',
        'reason'=>'owner_policy_not_resolved',
    ];
    if($ownerId<1)return $cache[$cacheKey]=$state;

    try{
        if(function_exists('vp3_agent_runtime_preference_v420')){
            $preference=vp3_agent_runtime_preference_v420($pdo,$ownerId,max(0,$agentId));
            $requested=(string)($preference['effective_preference']??'auto');
            if(in_array($requested,['auto','homeserver_only','vp3_cloud'],true))$state['requested_compute']=$requested;
        }
    }catch(Throwable $ignored){}

    // A stored explicit preference is enough to resolve these two cases without
    // contacting HomeServer. This is safe for guest rendering because no relay
    // request or capability discovery is triggered by an invitation bearer.
    if($state['requested_compute']==='vp3_cloud'){
        $state['cloud_processing_allowed']=true;
        $state['policy_resolved']=true;
        $state['reason']='explicit_vp3_cloud';
        return $cache[$cacheKey]=$state;
    }
    if($state['requested_compute']==='homeserver_only'){
        $state['cloud_processing_allowed']=false;
        $state['cloud_block_reason']='homeserver_only';
        $state['policy_resolved']=true;
        $state['reason']='homeserver_only';
        // Continue only in owner context so the organizer can see whether a
        // compatible local capability is actually available.
        if(!$ownerContext)return $cache[$cacheKey]=$state;
    }

    // Never let an attendee/guest request use the owner's stored relay tokens or
    // cause VP3 to probe the owner's HomeServer. The organizer's authenticated
    // join request resolves the live scope immediately before Agent dispatch.
    if(!$ownerContext)return $cache[$cacheKey]=$state;

    if(function_exists('homeserver_agent_v018_credentials')){
        try{$state['paired']=homeserver_agent_v018_credentials($ownerId)!==null;}catch(Throwable $ignored){}
    }

    if(function_exists('homeserver_scope_v026_fetch')&&function_exists('homeserver_scope_v026_blocks_cloud')){
        try{
            $scope=homeserver_scope_v026_fetch($ownerId,$forceRefresh);
            $state['scope_checked']=true;
            $state['cloud_processing_allowed']=!homeserver_scope_v026_blocks_cloud($scope);
            $state['policy_resolved']=true;
            if(empty($state['cloud_processing_allowed']))$state['cloud_block_reason']='homeserver_scope';
        }catch(Throwable $ignored){
            // Privacy uncertainty is not permission. Automatic routing fails
            // closed for live speech until the owner scope can be resolved.
            $state['cloud_processing_allowed']=false;
            $state['cloud_block_reason']='homeserver_scope_unavailable';
            $state['policy_resolved']=true;
        }
    }else{
        // If wrapper scope is not supported, Automatic keeps the historical
        // cloud-allowed behavior while HomeServer-only remains blocked above.
        $state['cloud_processing_allowed']=$state['requested_compute']==='auto';
        $state['policy_resolved']=true;
    }

    // v0.33 is a sanitized capability view. It never exposes relay/bearer
    // credentials. Query it only in the authenticated owner context.
    if($state['paired']&&function_exists('homeserver_capability_v033_registry')){
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
    }elseif($state['requested_compute']==='homeserver_only'){
        $state['reason']='homeserver_required_unpaired';
    }else{
        $state['reason']='homeserver_not_paired';
    }

    return $cache[$cacheKey]=$state;
}

function video_meeting_cloud_transcription_allowed_v1801(PDO $pdo,array $meeting): bool
{
    if(empty($meeting['transcription_enabled']))return false;
    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting);
    // Live speech is sensitive. An unresolved owner policy is never treated as
    // permission to dispatch cloud STT.
    return $state['cloud_processing_allowed']===true;
}

function video_meeting_homeserver_public_policy_v1801(PDO $pdo,array $meeting): array
{
    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting);
    return [
        'version'=>(string)$state['version'],
        'paired'=>!empty($state['paired']),
        'available'=>!empty($state['available']),
        'policy_resolved'=>!empty($state['policy_resolved']),
        'requested_compute'=>(string)$state['requested_compute'],
        'cloud_processing_allowed'=>$state['cloud_processing_allowed']===null?null:($state['cloud_processing_allowed']===true),
        'cloud_block_reason'=>(string)$state['cloud_block_reason'],
        'knowledge_searchable'=>!empty($state['knowledge_searchable']),
        'local_compute_available'=>!empty($state['local_compute_available']),
        'local_transcription_advertised'=>!empty($state['local_transcription_advertised']),
        'local_transcription_operation'=>(string)$state['local_transcription_operation'],
        'reason'=>(string)$state['reason'],
    ];
}
