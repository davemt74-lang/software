<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-https-relay-v1300.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Relay exchange is too large.']);exit;}
$authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'')));
$bearer='';
if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))$bearer=trim((string)$m[1]);
$token=$bearer!==''?$bearer:trim((string)($_SERVER['HTTP_X_VP3_HOMESERVER_SESSION']??''));
$device=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));
$raw=file_get_contents('php://input');$body=is_string($raw)?json_decode($raw,true):null;
if(!is_array($body))$body=[];

try{
    $session=homeserver_https_v1300_authenticate($token,$device);
}catch(Throwable $e){
    $message=strtolower(trim((string)$e->getMessage()));
    $revoked=str_contains($message,'revoked');
    http_response_code($revoked?410:401);
    echo json_encode([
      'ok'=>false,
      'error'=>$revoked?'HomeServer HTTPS session was revoked.':'HomeServer HTTPS session is not authorized.'
    ],JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    echo json_encode(homeserver_https_v1300_poll($session,$body),JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('HomeServer HTTPS poll failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode([
      'ok'=>false,
      'error'=>'HomeServer HTTPS relay could not process the exchange.'
    ],JSON_UNESCAPED_SLASHES);
}
