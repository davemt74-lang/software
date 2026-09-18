<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-share-v2011.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version, X-VP3-Idempotency-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_browser_share_json_v2010(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_browser_share_json_v2010(204);
if($method!=='POST')vp3_browser_share_json_v2010(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

$contract=trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''));
if($contract!=='1')vp3_browser_share_json_v2010(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

$pdo=db();
if(!$pdo||!vp3_browser_share_schema_ready_v2010($pdo)||!vp3_human_messaging_v370_ready($pdo)){
    vp3_browser_share_json_v2010(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Share is not ready. Run the database upgrade.']]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>65536)vp3_browser_share_json_v2010(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Browser Share payload is too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_browser_share_json_v2010(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
if((int)($input['schema_version']??0)!==1)vp3_browser_share_json_v2010(422,['ok'=>false,'error'=>['code'=>'unsupported_schema','message'=>'Unsupported Browser Share schema version.']]);

$idempotencyKey=strtolower(trim((string)($_SERVER['HTTP_X_VP3_IDEMPOTENCY_KEY']??'')));

try{
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    vp3_annotated_rate_limit_v2100($pdo,(int)($session['user_id']??0),'browser_share_create');
    $result=vp3_browser_share_create_v2011($pdo,$session,$input,$idempotencyKey);
    $status=!empty($result['idempotent_replay'])?200:201;
    vp3_browser_share_json_v2010($status,[
        'ok'=>true,
        'contract_version'=>1,
        'browser_share'=>$result['browser_share'],
        'chat_message'=>$result['chat_message'],
        'idempotent_replay'=>(bool)$result['idempotent_replay'],
        'deep_link'=>null,
    ]);
}catch(VP3AnnotatedRateLimitExceptionV2100 $e){header('Retry-After: '.$e->retryAfter);vp3_browser_share_json_v2010(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>$e->getMessage()]]);
}catch(VP3BrowserShareExceptionV2010 $e){
    vp3_browser_share_json_v2010($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Share create failed: '.$e->getMessage());
    vp3_browser_share_json_v2010(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Share could not be created.']]);
}
