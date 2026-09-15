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
$pdo=db();if(!$pdo||!video_meeting_transcription_schema_ready_v1800($pdo))$fail(503,'Meeting transcription is not ready.');
$secret=video_meeting_worker_secret_v1800();if($secret==='')$fail(503,'Meeting worker authentication is not configured.');
$authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
$provided='';if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))$provided=trim($m[1]);
if($provided===''||!hash_equals($secret,$provided))$fail(401,'Meeting worker authentication failed.');

$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$fail(400,'JSON body required.');
$publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting)$fail(404,'Meeting not found.');
$status=(string)$meeting['status'];
if(in_array($status,['cancelled','processed'],true))$fail(409,'Meeting is closed.');
if($status==='ended'){
    // LiveKit may deliver the final STT result just after the organizer ends the
    // room. Accept that final flush briefly, but do not leave ended rooms open
    // as indefinite transcript-write targets.
    $endedAt=strtotime((string)($meeting['ended_at']??'').' UTC')?:0;
    if($endedAt<1||time()-$endedAt>300)$fail(409,'Meeting transcript grace period has ended.');
}
if(empty($input['is_final'])){
    echo json_encode(['ok'=>true,'accepted'=>0,'ignored'=>'interim'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
try{
    $result=video_meeting_transcription_append_v1800($pdo,$meeting,$input);
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting worker ingest error: '.$e->getMessage());
    $fail(500,'Meeting transcript segment could not be saved.');
}
