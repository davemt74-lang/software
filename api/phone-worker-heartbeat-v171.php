<?php
declare(strict_types=1);
// HomeServer outbound check-in only; does not expose an unauthenticated status API.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-voice-stream-v150.php';
require_once dirname(__DIR__).'/includes/phone-worker-health-v171.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
    {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>1024) {http_response_code(413);exit;}
$raw=file_get_contents('php://input',false,null,0,1025);
if (!is_string($raw)||strlen($raw)>1024) {http_response_code(413);exit;}
$body=vp3_phone_worker_health_verify_v171($raw,
    (string)($_SERVER['HTTP_X_VP3_TIMESTAMP']??''),
    (string)($_SERVER['HTTP_X_VP3_SIGNATURE']??''),
    vp3_phone_stream_secret_v150(),time());
if ($body===false) {http_response_code(401);echo '{"ok":false}';exit;}
$pdo=db();
if (!$pdo || !vp3_phone_worker_health_ready_v171())
    {http_response_code(503);echo '{"ok":false}';exit;}
$accepted=vp3_phone_worker_health_record_v171($pdo,$body,time());
http_response_code($accepted?200:409);
echo json_encode(['ok'=>$accepted],JSON_THROW_ON_ERROR);
