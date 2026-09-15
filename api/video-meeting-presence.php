<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$fail=static function(int $status,string $message): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};
if($_SERVER['REQUEST_METHOD']!=='POST')$fail(405,'POST required.');
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$fail(503,'Video Meetings are not ready.');
$user=current_user();if($user&&!verify_csrf())$fail(403,'Session expired. Refresh the meeting and try again.');
$access=video_meeting_access_v1800($pdo,$user,strtolower(trim((string)($_POST['meeting']??''))),strtolower(trim((string)($_POST['invite']??''))));
if(!$access)$fail(403,'This meeting invitation is not available to you.');
$action=strtolower(trim((string)($_POST['action']??'')));if(!in_array($action,['join','leave','end'],true))$fail(422,'Unknown meeting action.');
if($action==='end'&&empty($access['is_organizer']))$fail(403,'Only the meeting organizer can end this meeting.');

try{
    $meeting=video_meeting_mark_presence_v1800($pdo,$access,$action);
    if($action==='join')video_meeting_record_crm_attendance_v1800($pdo,$meeting,$access['participant'],'joined');
    if($action==='leave')video_meeting_record_crm_attendance_v1800($pdo,$meeting,$access['participant'],'left');
    echo json_encode(['ok'=>true,'status'=>(string)$meeting['status']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting presence error: '.$e->getMessage());
    $fail(500,'Meeting status could not be updated.');
}
