<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

function tracky_sync_v270_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');
    tracky_sync_v270_json(['ok'=>false,'error'=>'POST required.'],405);
}
if((int)($_SERVER['CONTENT_LENGTH']??0)>VP3_TRACKY_MAX_PAYLOAD_BYTES_V270){
    tracky_sync_v270_json(['ok'=>false,'error'=>'Tracky sync payload is too large.'],413);
}

$authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'')));
$bearer='';
if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$m))$bearer=trim((string)$m[1]);
$token=$bearer!==''?$bearer:trim((string)($_SERVER['HTTP_X_VP3_HOMESERVER_SESSION']??''));
$device=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));

try{
    $session=homeserver_https_v1300_authenticate($token,$device);
}catch(Throwable $e){
    $message=strtolower(trim((string)$e->getMessage()));
    tracky_sync_v270_json([
        'ok'=>false,
        'error'=>str_contains($message,'revoked')?'HomeServer HTTPS session was revoked.':'HomeServer HTTPS session is not authorized.',
    ],str_contains($message,'revoked')?410:401);
}

$raw=file_get_contents('php://input');
$body=is_string($raw)?json_decode($raw,true):null;
if(!is_array($body))tracky_sync_v270_json(['ok'=>false,'error'=>'Tracky sync payload must be valid JSON.'],400);

$pdo=db();
if(!$pdo)tracky_sync_v270_json(['ok'=>false,'error'=>'Database unavailable.'],503);
$userId=(int)($session['user_id']??0);
$userStmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$userStmt->execute([$userId]);
$user=$userStmt->fetch();
if(!$user)tracky_sync_v270_json(['ok'=>false,'error'=>'Tracky account could not be resolved.'],403);

if(!tracky_cloud_v270_plugin_enabled($pdo,$user)){
    tracky_sync_v270_json(['ok'=>false,'error'=>'Tracky Cloud plugin is not enabled for this account.'],403);
}

try{
    tracky_sync_v270_json(tracky_cloud_v270_ingest($pdo,$userId,$device,$body));
}catch(Throwable $e){
    $message=trim($e->getMessage());
    $status=422;
    if(str_contains(strtolower($message),'payload is too large'))$status=413;
    if($message===''||preg_match('/(?:sql|database|pdo|constraint|stack trace)/i',$message)){
        error_log('Tracky V2.7 cloud sync failed: '.$e->getMessage());
        $message='Tracky Cloud could not process the physical-context update.';
        $status=503;
    }
    tracky_sync_v270_json(['ok'=>false,'error'=>mb_strimwidth($message,0,500,'')],$status);
}
