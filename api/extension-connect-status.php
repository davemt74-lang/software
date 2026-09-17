<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2000();
header('Access-Control-Allow-Headers: Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_connect_status_json_v2000(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_connect_status_json_v2000(204);
if($method!=='POST')vp3_extension_connect_status_json_v2000(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

$pdo=db();
if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    vp3_extension_connect_status_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion is not ready. Run the database upgrade.']]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>4096)vp3_extension_connect_status_json_v2000(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_extension_connect_status_json_v2000(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);

try{
    $result=vp3_extension_connection_poll_v2000(
        $pdo,
        (string)($input['connection_request_id']??''),
        (string)($input['poll_token']??''),
        (string)($input['installation_id']??'')
    );
    $payload=['ok'=>true,'contract_version'=>1,'status'=>$result['status']];
    if(isset($result['credential_delivered']))$payload['credential_delivered']=(bool)$result['credential_delivered'];
    if(!empty($result['device_id']))$payload['device_id']=$result['device_id'];
    if(!empty($result['device_credential']))$payload['device_credential']=$result['device_credential'];
    if(!empty($result['user']))$payload['user']=$result['user'];
    if(isset($result['capabilities']))$payload['capabilities']=$result['capabilities'];
    vp3_extension_connect_status_json_v2000(200,$payload);
}catch(InvalidArgumentException $e){
    vp3_extension_connect_status_json_v2000(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    if(str_contains($e->getMessage(),'not found')){
        vp3_extension_connect_status_json_v2000(404,['ok'=>false,'error'=>['code'=>'connection_not_found','message'=>'Connection request not found.']]);
    }
    error_log('VP3 extension connection poll failed: '.$e->getMessage());
    vp3_extension_connect_status_json_v2000(403,['ok'=>false,'error'=>['code'=>'connection_unavailable','message'=>'This connection request is no longer available.']]);
}catch(Throwable $e){
    error_log('VP3 extension connection poll failed: '.$e->getMessage());
    vp3_extension_connect_status_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion could not check this connection.']]);
}
