<?php
declare(strict_types=1);

/**
 * Phase 18.4 — truthful HomeServer routing/readiness for Video Meetings.
 *
 * Capability metadata is not execution. Until a concrete meeting transcription
 * executor is shipped, an advertised HomeServer transcription operation is
 * reported as advertised-but-unwired and HomeServer-only fails closed. This
 * layer never exposes relay credentials, device identifiers, endpoints, raw
 * registry data, provider details, or pairing secrets to Meeting clients.
 */
const VP3_VIDEO_MEETINGS_HOMESERVER_V1840='video-meetings-homeserver-v1840-20260915';

$vp3MeetingHomeDeps=[
    __DIR__.'/homeserver-vp3.php',
    __DIR__.'/homeserver-agent-v018.php',
    __DIR__.'/homeserver-capability-registry-v033.php',
];
foreach($vp3MeetingHomeDeps as $vp3MeetingHomeDep){
    if(is_file($vp3MeetingHomeDep))require_once $vp3MeetingHomeDep;
}
unset($vp3MeetingHomeDeps,$vp3MeetingHomeDep);

function video_meeting_homeserver_terminal_v1840(array $meeting): bool
{
    return in_array((string)($meeting['status']??''),['cancelled','ended','processed','no_show'],true);
}

function video_meeting_homeserver_operation_candidates_v1840(): array
{
    return ['meeting.transcription.stream','transcription.stream','transcription.start'];
}

function video_meeting_homeserver_owner_probe_allowed_v1840(array $meeting): bool
{
    $viewer=function_exists('current_user')?current_user():null;
    return (int)($viewer['id']??0)>0&&(int)($viewer['id']??0)===(int)($meeting['owner_user_id']??0);
}

/**
 * Resolve a server-side meeting processing state without pretending that an
 * advertised operation is executable. A future concrete executor may expose
 * video_meeting_homeserver_transcription_execute_v1840(); readiness will then
 * require both that callable and an advertised compatible operation.
 */
function video_meeting_homeserver_runtime_status_v1840(PDO $pdo,array $meeting,bool $forceRefresh=false): array
{
    $policy=function_exists('video_meeting_homeserver_policy_v1801')
        ?video_meeting_homeserver_policy_v1801($pdo,$meeting,$forceRefresh)
        :[
            'policy_resolved'=>true,'requested_compute'=>'auto','cloud_processing_allowed'=>true,
            'cloud_block_reason'=>'','reason'=>'compatibility','available'=>false,
            'local_transcription_advertised'=>false,'local_transcription_operation'=>'',
        ];

    $requested=(string)($policy['requested_compute']??'auto');
    if(!in_array($requested,['auto','homeserver_only','vp3_cloud'],true))$requested='auto';
    $cloudAllowed=($policy['cloud_processing_allowed']??null)===true;
    $policyResolved=!empty($policy['policy_resolved']);
    $terminal=video_meeting_homeserver_terminal_v1840($meeting);
    $transcriptionEnabled=!empty($meeting['transcription_enabled']);
    $ownerProbe=video_meeting_homeserver_owner_probe_allowed_v1840($meeting);

    $state=[
        'version'=>'v18.4',
        'route'=>'off',
        'status'=>'off',
        'reason_code'=>'transcription_disabled',
        'requested_compute'=>$requested,
        'policy_resolved'=>$policyResolved,
        'cloud_allowed'=>$cloudAllowed,
        'homeserver_required'=>$requested==='homeserver_only'||($policyResolved&&!$cloudAllowed),
        'homeserver_available'=>false,
        'capability_advertised'=>false,
        'executor_available'=>false,
        'ready'=>false,
        'terminal'=>$terminal,
    ];

    if(!$transcriptionEnabled)return $state;
    if($terminal){
        $state['route']='blocked';$state['status']='closed';$state['reason_code']='meeting_terminal';
        return $state;
    }
    if(!$policyResolved){
        $state['route']='pending';$state['status']='policy_pending';$state['reason_code']='owner_policy_pending';
        return $state;
    }
    if($requested==='vp3_cloud'&&$cloudAllowed){
        $state['route']='cloud';$state['status']='ready';$state['reason_code']='explicit_vp3_cloud';$state['ready']=true;
        return $state;
    }

    // Non-owner requests never probe the organizer's paired HomeServer. They
    // receive only the already-resolved privacy outcome.
    if(!$ownerProbe){
        if($cloudAllowed){
            $state['route']='cloud';$state['status']='ready';$state['reason_code']='cloud_allowed';$state['ready']=true;
        }else{
            $state['route']='private_required';$state['status']='private_required';$state['reason_code']='private_processing_required';
        }
        return $state;
    }

    // v18.1 already performed the one authenticated, sanitized capability probe
    // for the owner. Reuse that result instead of making a second relay call.
    $operation=(string)($policy['local_transcription_operation']??'');
    if(!in_array($operation,video_meeting_homeserver_operation_candidates_v1840(),true))$operation='';
    $registryAvailable=!empty($policy['available']);
    $state['homeserver_available']=$registryAvailable;
    $state['capability_advertised']=!empty($policy['local_transcription_advertised'])&&$operation!=='';

    // Deliberately require a concrete meeting executor, not merely the generic
    // relay transport. This avoids claiming local STT when no audio/transcript
    // operation contract is implemented in VP3 Cloud.
    $state['executor_available']=function_exists('video_meeting_homeserver_transcription_execute_v1840');
    $homeReady=$registryAvailable&&!empty($state['capability_advertised'])&&!empty($state['executor_available']);

    if(!empty($state['homeserver_required'])){
        if($homeReady){
            $state['route']='homeserver';$state['status']='ready';$state['reason_code']='homeserver_ready';$state['ready']=true;
        }else{
            $state['route']='blocked';$state['status']='required_unavailable';
            $state['reason_code']=!$registryAvailable?'homeserver_unavailable':(empty($state['capability_advertised'])?'capability_unavailable':'capability_advertised_unwired');
        }
        return $state;
    }

    // Automatic routing preserves cloud operation when privacy policy allows
    // it. HomeServer metadata is informational until a concrete executor ships.
    if($cloudAllowed){
        $state['route']='cloud';$state['status']='ready';$state['reason_code']=!empty($state['capability_advertised'])&&!$homeReady?'homeserver_advertised_unwired_cloud_fallback':'automatic_cloud';$state['ready']=true;
        return $state;
    }

    $state['route']='blocked';$state['status']='required_unavailable';$state['reason_code']='no_authorized_processing_route';
    return $state;
}

function video_meeting_homeserver_public_status_v1840(PDO $pdo,array $meeting,bool $forceRefresh=false): array
{
    $state=video_meeting_homeserver_runtime_status_v1840($pdo,$meeting,$forceRefresh);
    // Explicit allow-list: never forward the HomeServer registry or any relay,
    // credential, endpoint, model/provider or device metadata to the browser.
    return [
        'version'=>'v18.4',
        'route'=>(string)$state['route'],
        'status'=>(string)$state['status'],
        'reason_code'=>(string)$state['reason_code'],
        'policy_resolved'=>!empty($state['policy_resolved']),
        'cloud_allowed'=>!empty($state['cloud_allowed']),
        'homeserver_required'=>!empty($state['homeserver_required']),
        'homeserver_available'=>!empty($state['homeserver_available']),
        'capability_advertised'=>!empty($state['capability_advertised']),
        'executor_available'=>!empty($state['executor_available']),
        'ready'=>!empty($state['ready']),
        'terminal'=>!empty($state['terminal']),
    ];
}

function video_meeting_homeserver_route_label_v1840(array $status): string
{
    return match((string)($status['status']??'')){
        'ready'=>(string)($status['route']??'')==='homeserver'?'HomeServer processing ready':'VP3 Cloud processing ready',
        'required_unavailable'=>'Private processing is required but no compatible HomeServer meeting executor is available.',
        'private_required'=>'Private processing is required. The organizer resolves HomeServer readiness when signed in.',
        'policy_pending'=>'Processing route will be resolved by the organizer.',
        'closed'=>'Meeting processing is closed.',
        default=>'Meeting transcription is off.',
    };
}
