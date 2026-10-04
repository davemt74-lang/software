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
if($_SERVER['REQUEST_METHOD']!=='POST')$fail(405,'POST required.');
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$fail(503,'Video Meetings are not ready.');
$user=current_user();if($user&&!verify_csrf())$fail(403,'Session expired. Refresh the meeting and try again.');
$access=video_meeting_secure_access_v1800(
    $pdo,$user,
    strtolower(trim((string)($_POST['meeting']??''))),
    strtolower(trim((string)($_POST['invite']??'')))
);
if(!$access)$fail(403,'This meeting invitation is not available to you.');
$meeting=$access['meeting'];$after=max(0,(int)($_POST['after']??0));
try{
    if((string)($_POST['action']??'')==='correct_speaker'){
        if(!$user||empty($access['is_organizer']))$fail(403,'Only the meeting owner can correct speaker labels.');
        $result=meeting_speaker_correct_section9($pdo,$meeting,$user,(int)($_POST['segment_id']??0),(string)($_POST['speaker_label']??''),(int)($_POST['revision']??0));
        echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    $state=video_meeting_transcription_state_v1800($pdo,$meeting);
    $session=video_meeting_transcription_session_v1800($pdo,$meeting);
    $canonical=$session?artist_listening_v172_segments($pdo,(int)$session['id']):[];
    $prefix=static fn(int $cursor): array => array_values(array_filter($canonical,static fn(array $row): bool => (int)$row['segment_index']<=$cursor));
    $state['review_hash']=(string)artist_listening_transcript_page_map($prefix($after))['source_hash'];
    $submittedHash=(string)($_POST['review_hash']??'');
    $replace=$submittedHash!==''&&!hash_equals($submittedHash,$state['review_hash']);
    if($replace)$after=0;
    if(empty($access['is_organizer']))$state['session_id']=0;
    $segments=!empty($meeting['transcription_enabled'])?video_meeting_transcription_segments_v1800($pdo,$meeting,$after,100):[];
    $cursor=$after;foreach($segments as $segment)$cursor=max($cursor,(int)$segment['id']);
    $state['review_hash']=(string)artist_listening_transcript_page_map($prefix($cursor))['source_hash'];
    echo json_encode(['ok'=>true,'segments'=>$segments,'state'=>$state,'replace_segments'=>$replace,'meeting_status'=>(string)$meeting['status']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting transcript feed error: '.$e->getMessage());
    $fail(500,'Meeting transcript could not be loaded.');
}
