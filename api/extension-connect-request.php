<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_connect_request_json_v2000(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_connect_request_json_v2000(204);
if($method!=='POST')vp3_extension_connect_request_json_v2000(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

$pdo=db();
if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    vp3_extension_connect_request_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion is not ready. Run the database upgrade.']]);
}

// The installation-level throttle in the service protects accidental/repeated
// pairing. This independent IP throttle prevents anonymous callers from simply
// rotating installation UUIDs to fill the connection-request table.
$requestIp=vp3_extension_request_ip_v2000();
if($requestIp!==''){
    $rate=$pdo->prepare("SELECT COUNT(*) FROM extension_connection_requests_v2000 WHERE request_ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $rate->execute([$requestIp]);
    if((int)$rate->fetchColumn()>=50){
        vp3_extension_connect_request_json_v2000(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>'Too many Browser Companion connection requests. Try again later.']]);
    }
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>8192)vp3_extension_connect_request_json_v2000(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_extension_connect_request_json_v2000(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
if((int)($input['contract_version']??0)!==1)vp3_extension_connect_request_json_v2000(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

try{
    $compatibility=vp3_annotated_extension_compatibility_v2100((string)($input['extension_version']??''));
    if(!$compatibility['supported'])vp3_extension_connect_request_json_v2000(426,['ok'=>false,'compatibility'=>$compatibility,'error'=>['code'=>'extension_update_required','message'=>'Update Browser Companion before connecting to VP3.']]);
    $connection=vp3_extension_connection_create_v2000($pdo,$input);
    vp3_extension_connect_request_json_v2000(202,[
        'ok'=>true,
        'contract_version'=>1,
        'compatibility'=>$compatibility,
        'connection_request'=>$connection,
    ]);
}catch(InvalidArgumentException $e){
    vp3_extension_connect_request_json_v2000(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    $message=$e->getMessage();
    if(str_contains($message,'Too many connection requests')){
        vp3_extension_connect_request_json_v2000(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>$message]]);
    }
    if(str_contains($message,'already pending')){
        vp3_extension_connect_request_json_v2000(409,['ok'=>false,'error'=>['code'=>'connection_pending','message'=>$message]]);
    }
    vp3_extension_connect_request_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion could not create a connection request.']]);
}catch(Throwable $e){
    error_log('VP3 extension connection request failed: '.$e->getMessage());
    vp3_extension_connect_request_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion could not create a connection request.']]);
}