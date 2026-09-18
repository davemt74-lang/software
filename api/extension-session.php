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

function vp3_extension_session_json_v2000(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_session_json_v2000(204);
if($method!=='POST')vp3_extension_session_json_v2000(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
$contract=trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''));
if($contract!=='1')vp3_extension_session_json_v2000(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

$pdo=db();
if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    vp3_extension_session_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion is not ready. Run the database upgrade.']]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>4096)vp3_extension_session_json_v2000(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_extension_session_json_v2000(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);

try{
    $version=trim((string)($_SERVER['HTTP_X_VP3_EXTENSION_VERSION']??''));
    $compatibility=vp3_annotated_extension_compatibility_v2100($version);
    if(!$compatibility['supported'])vp3_extension_session_json_v2000(426,['ok'=>false,'compatibility'=>$compatibility,'error'=>['code'=>'extension_update_required','message'=>'Update Browser Companion before starting a VP3 session.']]);
    $session=vp3_extension_session_issue_v2001(
        $pdo,
        (string)($input['device_id']??''),
        (string)($input['installation_id']??''),
        (string)($input['device_credential']??'')
    );
    $userId=(int)($session['user']['id']??0);
    vp3_annotated_mark_milestone_safe_v2100($pdo,$userId,'extension_connected',['surface'=>'session']);
    $annotated=$userId>0&&vp3_annotated_schema_ready_v2100($pdo)?vp3_annotated_user_state_v2100($pdo,$userId):['available'=>false];
    vp3_extension_session_json_v2000(200,['ok'=>true,'contract_version'=>1,'session'=>$session,'compatibility'=>$compatibility,'annotated'=>$annotated]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_session_json_v2000($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_session_json_v2000(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_session_json_v2000(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion device authentication failed.']]);
}catch(Throwable $e){
    error_log('VP3 extension session issue failed: '.$e->getMessage());
    vp3_extension_session_json_v2000(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Companion could not create a session.']]);
}
