<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$fail=static function(int $status,string $message): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};
if($_SERVER['REQUEST_METHOD']!=='GET')$fail(405,'GET required.');
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$fail(503,'Video Meetings are not ready.');
$user=current_user();
$access=video_meeting_secure_access_v1800(
    $pdo,$user,
    strtolower(trim((string)($_GET['meeting']??''))),
    strtolower(trim((string)($_GET['invite']??'')))
);
if(!$access)$fail(403,'This meeting invitation is not available to you.');
$meeting=$access['meeting'];$after=max(0,(int)($_GET['after']??0));
try{
    $state=video_meeting_transcription_state_v1800($pdo,$meeting);
    if(empty($access['is_organizer']))$state['session_id']=0;
    $segments=!empty($meeting['transcription_enabled'])?video_meeting_transcription_segments_v1800($pdo,$meeting,$after,100):[];
    echo json_encode(['ok'=>true,'segments'=>$segments,'state'=>$state,'meeting_status'=>(string)$meeting['status']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting transcript feed error: '.$e->getMessage());
    $fail(500,'Meeting transcript could not be loaded.');
}
