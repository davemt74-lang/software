<?php
declare(strict_types=1);

/**
 * Phase 18.5 — concrete VP3 -> HomeServer meeting transcription executor.
 *
 * LiveKit remains the realtime media transport. HomeServer may subscribe to the
 * exact meeting room with a restricted token and send final transcript segments
 * back through a meeting-scoped VP3 callback capability. The global meeting
 * worker secret is never sent to HomeServer.
 */
const VP3_VIDEO_MEETINGS_HOMESERVER_V1850='video-meetings-homeserver-v1850-20260915';

function video_meeting_homeserver_transcription_operation_v1850(): string
{
    return 'meeting.transcription.stream';
}

function video_meeting_homeserver_runtime_expiry_v1850(array $meeting): int
{
    $now=time();
    $scheduledEnd=strtotime((string)($meeting['end_at_utc']??'').' UTC')?:0;
    $target=$scheduledEnd>0?$scheduledEnd+1800:$now+7200;
    return max($now+900,min($now+43200,$target));
}

function video_meeting_homeserver_callback_key_v1850(): string
{
    if(!function_exists('video_meeting_worker_secret_v1800'))return '';
    $secret=video_meeting_worker_secret_v1800();
    if(strlen($secret)<20)return '';
    return hash_hmac('sha256','vp3-video-meeting-homeserver-callback-v1850',$secret,true);
}

function video_meeting_homeserver_callback_token_v1850(array $meeting,?int $expiresAt=null): string
{
    $key=video_meeting_homeserver_callback_key_v1850();
    if($key==='')throw new RuntimeException('Meeting callback authentication is not configured.');
    $publicId=strtolower(trim((string)($meeting['public_id']??'')));
    $roomName=trim((string)($meeting['room_name']??''));
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||$roomName==='')throw new RuntimeException('Meeting callback binding is invalid.');
    $expiresAt=$expiresAt??video_meeting_homeserver_runtime_expiry_v1850($meeting);
    $payload=$publicId.'|'.$roomName.'|'.$expiresAt;
    $signature=hash_hmac('sha256',$payload,$key);
    return 'v1850.'.$expiresAt.'.'.$signature;
}

function video_meeting_homeserver_callback_verify_v1850(string $publicId,string $roomName,string $token): bool
{
    $publicId=strtolower(trim($publicId));$roomName=trim($roomName);$token=trim($token);
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||$roomName===''||!preg_match('/^v1850\.(\d{10})\.([a-f0-9]{64})$/',$token,$m))return false;
    $expiresAt=(int)$m[1];
    if($expiresAt<time()-30||$expiresAt>time()+43260)return false;
    $key=video_meeting_homeserver_callback_key_v1850();if($key==='')return false;
    $expected=hash_hmac('sha256',$publicId.'|'.$roomName.'|'.$expiresAt,$key);
    return hash_equals($expected,(string)$m[2]);
}

function video_meeting_homeserver_callback_url_v1850(): string
{
    if(!function_exists('video_meeting_absolute_url_v1800'))return '';
    $url=video_meeting_absolute_url_v1800('/api/video-meeting-worker.php');
    $parts=parse_url($url);if(!is_array($parts))return '';
    $scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));
    $loopback=in_array($host,['localhost','127.0.0.1','::1'],true);
    if($scheme!=='https'&&!($scheme==='http'&&$loopback))return '';
    if(isset($parts['user'])||isset($parts['pass']))return '';
    return $url;
}

function video_meeting_homeserver_livekit_identity_v1850(array $meeting): string
{
    return 'vp3-homeserver-'.substr(hash('sha256',(string)($meeting['public_id']??'')),0,24);
}

function video_meeting_homeserver_livekit_token_v1850(array $meeting,?int $expiresAt=null): string
{
    if(!function_exists('video_meeting_livekit_jwt_v1800'))throw new RuntimeException('LiveKit token support is unavailable.');
    $cfg=video_meeting_livekit_config_v1800();$now=time();$expiresAt=$expiresAt??video_meeting_homeserver_runtime_expiry_v1850($meeting);
    $identity=video_meeting_homeserver_livekit_identity_v1850($meeting);
    return video_meeting_livekit_jwt_v1800([
        'iss'=>(string)$cfg['api_key'],'sub'=>$identity,'name'=>'VP3 HomeServer Transcriber',
        'nbf'=>$now-5,'exp'=>$expiresAt,
        'metadata'=>json_encode(['vp3_meeting'=>(string)$meeting['public_id'],'processor'=>'homeserver'],JSON_UNESCAPED_SLASHES),
        'video'=>[
            'room'=>(string)$meeting['room_name'],'roomJoin'=>true,
            'canPublish'=>false,'canSubscribe'=>true,'canPublishData'=>false,
        ],
    ]);
}

function video_meeting_homeserver_transcription_executor_available_v1850(array $meeting,string $operation): bool
{
    if($operation!==video_meeting_homeserver_transcription_operation_v1850())return false;
    if(empty($meeting['transcription_enabled'])||video_meeting_homeserver_terminal_v1840($meeting))return false;
    if(!function_exists('video_meeting_livekit_ready_v1800')||!video_meeting_livekit_ready_v1800())return false;
    if(video_meeting_homeserver_callback_key_v1850()===''||video_meeting_homeserver_callback_url_v1850()==='')return false;
    if(!function_exists('homeserver_agent_v018_credentials')||!function_exists('homeserver_vp3_remote_operation'))return false;
    return homeserver_agent_v018_credentials((int)($meeting['owner_user_id']??0))!==null;
}

/**
 * Start or reuse one HomeServer transcription session for the exact VP3 room.
 * The HomeServer operation is expected to be idempotent for idempotency_key and
 * to return ready/started/running/already_running when the local subscriber is
 * accepted. No raw HomeServer response is forwarded to the browser.
 */
function video_meeting_homeserver_transcription_execute_v1840(PDO $pdo,array $meeting,string $operation=''): array
{
    $operation=trim($operation);
    if($operation==='')$operation=video_meeting_homeserver_transcription_operation_v1850();
    if(!video_meeting_homeserver_owner_probe_allowed_v1840($meeting))return ['ok'=>false,'dispatched'=>false,'reason'=>'organizer_required'];
    if(!video_meeting_homeserver_transcription_executor_available_v1850($meeting,$operation))return ['ok'=>false,'dispatched'=>false,'reason'=>'executor_unavailable'];

    $credentials=homeserver_agent_v018_credentials((int)$meeting['owner_user_id']);
    if(!$credentials)return ['ok'=>false,'dispatched'=>false,'reason'=>'executor_unavailable'];
    $expiresAt=video_meeting_homeserver_runtime_expiry_v1850($meeting);
    $callbackUrl=video_meeting_homeserver_callback_url_v1850();
    $language=trim((string)(getenv('VP3_MEETING_STT_LANGUAGE')?:'en'))?:'en';
    $payload=[
        'contract'=>'vp3.meeting.transcription.v1',
        'idempotency_key'=>'vp3-meeting-transcription:'.(string)$meeting['public_id'],
        'meeting'=>[
            'public_id'=>(string)$meeting['public_id'],
            'room_name'=>(string)$meeting['room_name'],
            'title'=>mb_strimwidth((string)($meeting['title']??''),0,190,''),
        ],
        'livekit'=>[
            'url'=>(string)video_meeting_livekit_config_v1800()['url'],
            'participant_identity'=>video_meeting_homeserver_livekit_identity_v1850($meeting),
            'participant_token'=>video_meeting_homeserver_livekit_token_v1850($meeting,$expiresAt),
            'subscribe_audio_only'=>true,
        ],
        'callback'=>[
            'url'=>$callbackUrl,
            'bearer_token'=>video_meeting_homeserver_callback_token_v1850($meeting,$expiresAt),
            'expires_at'=>gmdate('c',$expiresAt),
            'final_only'=>true,
        ],
        'transcription'=>['language'=>mb_strimwidth($language,0,32,''),'final_only'=>true],
    ];

    try{
        $result=homeserver_vp3_remote_operation($credentials['relay'],$operation,$payload,$credentials['home']);
    }catch(Throwable $e){
        error_log('VP3 HomeServer meeting transcription dispatch failed for meeting '.(string)$meeting['public_id']);
        return ['ok'=>false,'dispatched'=>false,'reason'=>'dispatch_failed'];
    }
    $status=strtolower(trim((string)($result['status']??'')));
    $accepted=!empty($result['ready'])||!empty($result['started'])||in_array($status,['ready','running','started','already_running'],true);
    if(!$accepted)return ['ok'=>false,'dispatched'=>false,'reason'=>'dispatch_failed'];
    return [
        'ok'=>true,'dispatched'=>true,
        'reason'=>$status==='already_running'?'homeserver_already_running':'homeserver_created',
        'status'=>in_array($status,['ready','running','started','already_running'],true)?$status:'ready',
    ];
}
