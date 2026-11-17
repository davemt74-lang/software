<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-media-stoneward-playback-v1.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function vp3_stoneward_playback_api_reply_v1(int $status,array $body): never
{
    http_response_code($status);
    echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');
    vp3_stoneward_playback_api_reply_v1(405,['ok'=>false,'error'=>'POST is required.']);
}
$user=current_user();
if(!$user||!has_permission('chat.access',$user)||!has_permission('account.access',$user)||vp3_participant_scope_v1()!==null){
    vp3_stoneward_playback_api_reply_v1(403,['ok'=>false,'error'=>'Open your private Agent Chat for playback history.']);
}
$raw=file_get_contents('php://input',false,null,0,8193);
if(!is_string($raw)||strlen($raw)>8192)vp3_stoneward_playback_api_reply_v1(413,['ok'=>false,'error'=>'The playback event is too large.']);
$input=json_decode($raw,true,8);
if(!is_array($input)||array_is_list($input)
    ||array_diff(array_keys($input),['csrf_token','ticket','sequence','event','position_seconds','duration_seconds'])){
    vp3_stoneward_playback_api_reply_v1(422,['ok'=>false,'error'=>'The Player sent an invalid event.']);
}
$csrf=$input['csrf_token']??null;
if(!is_string($csrf)||$csrf===''||!hash_equals(csrf_token(),$csrf)){
    vp3_stoneward_playback_api_reply_v1(419,['ok'=>false,'error'=>'Your Chat session changed.']);
}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    $pdo=db();
    if(!$pdo)vp3_stoneward_playback_api_reply_v1(503,['ok'=>false,'error'=>'Playback history is unavailable.']);
    vp3_stoneward_playback_api_reply_v1(200,vp3_stoneward_playback_event_v1($pdo,$user,$input));
}catch(RuntimeException $error){
    $status=(int)$error->getCode();
    if(!$error instanceof PDOException&&in_array($status,[403,404,409,422,503],true)){
        vp3_stoneward_playback_api_reply_v1($status,['ok'=>false,'error'=>$error->getMessage()]);
    }
    vp3_stoneward_playback_api_reply_v1(503,['ok'=>false,'error'=>'Playback history is unavailable.']);
}catch(Throwable $error){
    vp3_stoneward_playback_api_reply_v1(503,['ok'=>false,'error'=>'Playback history is unavailable.']);
}
