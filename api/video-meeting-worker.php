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

$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$fail(400,'JSON body required.');
$publicId=strtolower(trim((string)($input['meeting']??'')));
$roomName=trim((string)($input['room_name']??''));
if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||$roomName==='')$fail(401,'Meeting worker authentication failed.');

$authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
$provided='';if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))$provided=trim($m[1]);
$globalSecret=video_meeting_worker_secret_v1800();
$globalAuthorized=$globalSecret!==''&&$provided!==''&&hash_equals($globalSecret,$provided);
$homeserverAuthorized=!$globalAuthorized
    &&function_exists('video_meeting_homeserver_callback_verify_v1850')
    &&video_meeting_homeserver_callback_verify_v1850($publicId,$roomName,$provided);
if(!$globalAuthorized&&!$homeserverAuthorized)$fail(401,'Meeting worker authentication failed.');
if($homeserverAuthorized&&strtolower(trim((string)($input['source']??'')))!=='homeserver')$fail(403,'HomeServer transcript source binding failed.');

$pdo=db();if(!$pdo||!video_meeting_transcription_schema_ready_v1800($pdo))$fail(503,'Meeting transcription is not ready.');
$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);if(!$meeting)$fail(404,'Meeting not found.');
if(!hash_equals((string)$meeting['room_name'],$roomName))$fail(403,'Meeting worker room binding failed.');
$status=(string)$meeting['status'];
if(in_array($status,['cancelled','processed','no_show'],true))$fail(409,'Meeting is closed.');
if($status==='ended'){
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
