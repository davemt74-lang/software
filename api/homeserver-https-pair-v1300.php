<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-account-pairing-v1210.php';
require_once __DIR__.'/../includes/homeserver-https-relay-v1300.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>16384){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Pairing request is too large.']);exit;}
$raw=file_get_contents('php://input');$body=is_string($raw)?json_decode($raw,true):null;
if(!is_array($body)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid pairing request.']);exit;}

try{
    $result=homeserver_https_v1300_pair(
        (string)($body['pairing_token']??''),
        (string)($body['device_id']??''),
        (string)($body['homeserver_token']??''),
        (string)($body['version']??''),
        is_array($body['capabilities']??null)?$body['capabilities']:[]
    );
    echo json_encode([
      'ok'=>true,'device_id'=>$result['device_id'],'transport'=>$result['transport'],'protocol'=>$result['protocol'],'cloud_version'=>$result['cloud_version']??VP3_HOMESERVER_RELEASE_VERSION,
      'session_token'=>$result['session_token'],'poll_url'=>$result['poll_url'],'poll_after_ms'=>$result['poll_after_ms'],
      'next_step'=>'Connected. HomeServer will maintain the VP3 HTTPS session automatically.'
    ],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $message=$e->getMessage();
    if(!str_contains(strtolower($message),'pairing token')&&!str_contains(strtolower($message),'device identity'))$message='VP3 could not complete HomeServer pairing.';
    http_response_code(422);echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES);
}
