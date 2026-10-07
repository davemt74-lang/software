<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-workspace-sync-v1.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=file_get_contents('php://input',false,null,0,2097153);
    if(!is_string($raw)||strlen($raw)>2097152)throw new RuntimeException('Workspace request too large.',413);
    $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($body)||array_is_list($body))throw new InvalidArgumentException('JSON object required.');
    $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'')));
    $token=preg_match('/^Bearer\s+(.+)$/i',$authorization,$m)?trim($m[1]):trim((string)($_SERVER['HTTP_X_VP3_HOMESERVER_SESSION']??''));
    try{$session=homeserver_https_v1300_authenticate($token,trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??'')));}
    catch(Throwable $e){throw new HomeServerHttpsSessionError('HomeServer HTTPS session is not authorized.',401);}
    echo json_encode(workspace_sync_exchange_v1($session,$body),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:(int)$e->getCode();
    if(!in_array($status,[401,403,405,409,410,413,422],true))$status=503;
    http_response_code($status);
    if($status===503)error_log('Workspace sync unavailable: '.get_class($e));
    echo json_encode(['ok'=>false,'error'=>$status===503?'Workspace sync temporarily unavailable.':$e->getMessage()]);
}
