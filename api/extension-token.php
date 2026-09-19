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

function vp3_extension_token_json_v2100(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_token_json_v2100(204);
if($method!=='POST')vp3_extension_token_json_v2100(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_token_json_v2100(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

$pdo=db();
if(!$pdo||!vp3_extension_device_token_schema_ready_v2100($pdo)){
    vp3_extension_token_json_v2100(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion is not ready. Run the database upgrade.']]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>4096)vp3_extension_token_json_v2100(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_extension_token_json_v2100(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);

try{
    $result=vp3_extension_device_code_exchange_v2100(
        $pdo,
        (string)($input['code']??''),
        (string)($input['installation_id']??''),
        (string)($_SERVER['HTTP_ORIGIN']??'')
    );
    $result['capabilities']=vp3_extension_live_capabilities_v2001(
        $pdo,
        (int)($result['user']['id']??0),
        $result['capabilities']??[]
    );
    vp3_extension_token_json_v2100(200,['ok'=>true,'contract_version'=>1]+$result);
}catch(InvalidArgumentException $e){
    vp3_extension_token_json_v2100(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_token_json_v2100(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 extension device token exchange failed: '.$e->getMessage());
    vp3_extension_token_json_v2100(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Companion could not finish connecting.']]);
}
