<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_disconnect_json_v2030(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_disconnect_json_v2030(204);
if($method!=='POST')vp3_extension_disconnect_json_v2030(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_disconnect_json_v2030(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

$pdo=db();
if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    vp3_extension_disconnect_json_v2030(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Companion is unavailable.']]);
}

try{
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_disconnect_json_v2030(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    $revoked=vp3_extension_device_revoke_v2000($pdo,(int)$session['user_id'],(string)$session['device_id']);
    vp3_extension_disconnect_json_v2030(200,['ok'=>true,'revoked'=>$revoked]);
}catch(Throwable $e){
    error_log('VP3 extension self-disconnect failed: '.$e->getMessage());
    vp3_extension_disconnect_json_v2030(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Companion could not disconnect this device.']]);
}
