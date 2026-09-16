<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/video-meetings-live-agent-v18110.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$reply=static function(bool $ok,array $data=[],int $status=200): never {
    http_response_code($status);
    echo json_encode(['ok'=>$ok,'build'=>VP3_VIDEO_MEETING_LIVE_AGENT_V18110]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$reply(false,['error'=>'POST required.'],405);
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$reply(false,['error'=>'Video Meetings are not ready.'],503);
$user=current_user();if(!$user)$reply(false,['error'=>'Sign in as the meeting organizer to use the live Agent.'],401);
if(!verify_csrf())$reply(false,['error'=>'Session expired. Refresh and try again.'],419);
$access=video_meeting_secure_access_v1800($pdo,$user,strtolower(trim((string)($_POST['meeting']??''))),strtolower(trim((string)($_POST['invite']??''))));
if(!$access)$reply(false,['error'=>'This meeting is not available to you.'],403);
$meeting=$access['meeting'];
if(empty($access['is_organizer'])||!video_meeting_live_agent_owner_allowed_v18110($user,$meeting))$reply(false,['error'=>'Live Agent control is private to the meeting organizer.'],403);
$action=strtolower(trim((string)($_POST['action']??'state')));

try{
    if($action==='state')$reply(true,['state'=>video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user)]);
    if($action==='start')$reply(true,['state'=>video_meeting_live_agent_start_v18110($pdo,$meeting,$user)]);
    if($action==='stop')$reply(true,['state'=>video_meeting_live_agent_stop_v18110($pdo,$meeting,$user)]);
    if($action==='ask'){
        $result=video_meeting_live_agent_ask_v18110($pdo,$meeting,$user,(string)($_POST['question']??''),(string)($_POST['client_turn_id']??''));
        $reply(true,$result);
    }
    $reply(false,['error'=>'Unknown live meeting Agent action.'],404);
}catch(Throwable $e){
    error_log('VP3 live meeting Agent API error: '.$e->getMessage());
    $reply(false,['error'=>mb_strimwidth($e->getMessage(),0,500,'…')],422);
}
