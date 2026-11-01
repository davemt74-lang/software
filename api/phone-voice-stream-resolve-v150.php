<?php
declare(strict_types=1);
// Private service-to-service resolver. A SIP caller never invokes this directly.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_once dirname(__DIR__).'/includes/phone-voice-stream-v150.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
    {http_response_code(415);exit;}
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length>4096) {http_response_code(413);exit;}
$raw=file_get_contents('php://input',false,null,0,4097);
if (!is_string($raw)||strlen($raw)>4096) {http_response_code(413);exit;}
$secret=vp3_phone_stream_secret_v150();
$stamp=(string)($_SERVER['HTTP_X_VP3_TIMESTAMP']??'');
$signature=(string)($_SERVER['HTTP_X_VP3_SIGNATURE']??'');
if (strlen($secret)<32||!preg_match('/^[0-9]{10}$/D',$stamp)||abs(time()-(int)$stamp)>300
    ||!preg_match('/^[a-f0-9]{64}$/D',$signature)
    ||!hash_equals(hash_hmac('sha256',$stamp.'.'.$raw,$secret),$signature))
    {http_response_code(401);echo '{"ok":false}';exit;}
$body=json_decode($raw,true);
$pdo=db();
if (!is_array($body)||!$pdo||!vp3_phone_voice_schema_ready_v130()||!vp3_phone_stream_ready_v150())
    {http_response_code(503);echo '{"ok":false}';exit;}
try {
    $result=vp3_phone_stream_resolve_v150($pdo,$body,$secret,time());
    http_response_code($result['ok']?200:403);
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable) {http_response_code(503);echo '{"ok":false}';}
