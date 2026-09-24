<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-https-relay-v1300.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Relay exchange is too large.']);exit;}
$authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
$token='';
if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))$token=trim((string)$m[1]);
if($token==='')$token=trim((string)($_SERVER['HTTP_X_VP3_HOMESERVER_SESSION']??''));
$device=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));
$raw=file_get_contents('php://input');$body=is_string($raw)?json_decode($raw,true):null;
if(!is_array($body))$body=[];

try{
    $session=homeserver_https_v1300_authenticate($token,$device);
    echo json_encode(homeserver_https_v1300_poll($session,$body),JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $code=(int)$e->getCode()===410?410:401;
    http_response_code($code);
    echo json_encode([
      'ok'=>false,
      'error'=>$code===410?'HomeServer HTTPS session was revoked.':'HomeServer HTTPS session is not authorized.'
    ],JSON_UNESCAPED_SLASHES);
}
