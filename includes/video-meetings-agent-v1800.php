<?php
declare(strict_types=1);

/**
 * Dispatch the configured meeting transcription runtime. LiveKit is the media
 * transport; processing may use the existing cloud Agent worker or a paired
 * HomeServer when the canonical privacy/readiness boundary selects it.
 */
function video_meeting_livekit_twirp_v1800(array $meeting,string $method,array $payload): array
{
    if(!video_meeting_livekit_ready_v1800())return ['ok'=>false,'status'=>0,'json'=>[],'error'=>'livekit_not_configured'];
    if(!function_exists('curl_init'))return ['ok'=>false,'status'=>0,'json'=>[],'error'=>'curl_unavailable'];
    $base=video_meeting_livekit_http_base_v1800();if($base==='')return ['ok'=>false,'status'=>0,'json'=>[],'error'=>'livekit_url_invalid'];
    $ch=curl_init(rtrim($base,'/').'/twirp/livekit.AgentDispatchService/'.$method);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.video_meeting_livekit_service_token_v1800($meeting,'roomAdmin'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);$curlError=curl_error($ch);curl_close($ch);
    $json=is_string($raw)&&$raw!==''?json_decode($raw,true):[];if(!is_array($json))$json=[];
    return ['ok'=>$errno===0&&$status>=200&&$status<300,'status'=>$status,'json'=>$json,'error'=>$errno!==0?$curlError:(string)($json['msg']??$json['message']??'')];
}

function video_meeting_agent_dispatch_metadata_v1800(PDO $pdo,array $meeting): string
{
    return json_encode([
        'vp3_meeting_public_id'=>(string)$meeting['public_id'],
        'room_name'=>(string)$meeting['room_name'],
        'agent_display_name'=>video_meeting_agent_name_v1800($pdo,$meeting),
        'mode'=>(string)$meeting['agent_mode'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
}

function video_meeting_livekit_agent_dispatch_v1800(PDO $pdo,array $meeting): array
{
    $mode=(string)($meeting['agent_mode']??'off');
    if($mode==='off')return ['ok'=>true,'dispatched'=>false,'reason'=>'agent_off'];
    if($mode==='notes'&&empty($meeting['transcription_enabled']))return ['ok'=>true,'dispatched'=>false,'reason'=>'transcription_off'];

    if(!empty($meeting['transcription_enabled'])&&function_exists('video_meeting_homeserver_runtime_status_v1840')){
        $processing=video_meeting_homeserver_runtime_status_v1840($pdo,$meeting,true);
        $route=(string)($processing['route']??'');$status=(string)($processing['status']??'');
        if($route==='homeserver'&&$status==='ready'&&function_exists('video_meeting_homeserver_transcription_execute_v1840')){
            $operation=function_exists('video_meeting_homeserver_transcription_operation_v1850')
                ?video_meeting_homeserver_transcription_operation_v1850()
                :'meeting.transcription.start';
            $result=video_meeting_homeserver_transcription_execute_v1840($pdo,$meeting,$operation);
            if(!empty($result['ok']))return $result;
            return ['ok'=>false,'dispatched'=>false,'reason'=>'homeserver_dispatch_failed'];
        }
        if($route!=='cloud'||$status!=='ready'){
            return ['ok'=>true,'dispatched'=>false,'reason'=>'homeserver_private_processing_required'];
        }
    }elseif(!empty($meeting['transcription_enabled'])&&function_exists('video_meeting_cloud_transcription_allowed_v1801')&&!video_meeting_cloud_transcription_allowed_v1801($pdo,$meeting)){
        return ['ok'=>true,'dispatched'=>false,'reason'=>'homeserver_private_processing_required'];
    }

    $cfg=video_meeting_livekit_config_v1800();$worker=trim((string)($cfg['agent_name']??''));
    if($worker==='')return ['ok'=>true,'dispatched'=>false,'reason'=>'agent_worker_not_configured'];
    if(!video_meeting_livekit_ready_v1800())return ['ok'=>false,'dispatched'=>false,'reason'=>'livekit_not_configured'];

    $listed=video_meeting_livekit_twirp_v1800($meeting,'ListDispatch',['room'=>(string)$meeting['room_name']]);
    if(!empty($listed['ok'])){
        $rows=(array)($listed['json']['agent_dispatches']??$listed['json']['agentDispatches']??[]);
        foreach($rows as $row){
            if(is_array($row)&&trim((string)($row['agent_name']??$row['agentName']??''))===$worker){
                return ['ok'=>true,'dispatched'=>false,'reason'=>'already_dispatched','dispatch'=>$row];
            }
        }
    }

    $created=video_meeting_livekit_twirp_v1800($meeting,'CreateDispatch',[
        'agent_name'=>$worker,
        'room'=>(string)$meeting['room_name'],
        'metadata'=>video_meeting_agent_dispatch_metadata_v1800($pdo,$meeting),
    ]);
    if(empty($created['ok'])){
        error_log('VP3 LiveKit Agent dispatch failed for meeting '.(string)$meeting['public_id'].': '.(string)($created['error']??'unknown'));
        return ['ok'=>false,'dispatched'=>false,'reason'=>'dispatch_failed','status'=>(int)($created['status']??0)];
    }
    return ['ok'=>true,'dispatched'=>true,'reason'=>'created','dispatch'=>$created['json']];
}
