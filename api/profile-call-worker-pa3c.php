<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$respond=static function(int $status,array $payload): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') $respond(405,['ok'=>false,'error'=>'POST required.']);
$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
$secret=video_meeting_worker_secret_v1800();
if (strlen($secret)<32||!preg_match('/^Bearer ([^\s]+)$/D',$authorization,$match)||!hash_equals($secret,$match[1])) {
    $respond(401,['ok'=>false,'error'=>'Worker authentication failed.']);
}
$raw=file_get_contents('php://input',false,null,0,38001);
$input=is_string($raw)&&strlen($raw)<=38000?json_decode($raw,true):null;
if (!is_array($input)) $respond(400,['ok'=>false,'error'=>'Valid worker JSON required.']);
$pdo=db();
if (!$pdo||!vp3_profile_call_assistance_ready_pa3c($pdo)) $respond(503,['ok'=>false,'error'=>'Profile call assistance is unavailable.']);
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {
    $respond(200,['ok'=>true]+vp3_profile_call_worker_pa3c($pdo,$input));
} catch (InvalidArgumentException $error) {
    $respond(400,['ok'=>false,'error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('VP3 Profile call worker request failed ('.get_class($error).').');
    $respond(503,['ok'=>false,'error'=>'Profile call assistance is unavailable.']);
}
