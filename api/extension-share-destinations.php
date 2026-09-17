<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2000();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, OPTIONS');

function vp3_browser_destinations_json_v2010(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_browser_destinations_json_v2010(204);
if($method!=='GET')vp3_browser_destinations_json_v2010(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

$contract=trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''));
if($contract!=='1')vp3_browser_destinations_json_v2010(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

$pdo=db();
if(!$pdo||!vp3_browser_share_schema_ready_v2010($pdo)||!vp3_human_messaging_v370_ready($pdo)){
    vp3_browser_destinations_json_v2010(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'VP3 Browser Share is not ready. Run the database upgrade.']]);
}

try{
    $session=vp3_extension_session_authenticate_v2000($pdo);
    if(!$session)throw new VP3BrowserShareExceptionV2010('authentication_required',401,'Browser Companion authentication is required.');
    vp3_browser_share_require_capability_v2010($session,'team.destinations.read');
    $destinations=vp3_browser_share_destinations_v2010($pdo,(int)$session['user_id']);
    vp3_browser_destinations_json_v2010(200,[
        'ok'=>true,
        'contract_version'=>1,
        'destinations'=>$destinations,
    ]);
}catch(VP3BrowserShareExceptionV2010 $e){
    vp3_browser_destinations_json_v2010($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 extension share destinations failed: '.$e->getMessage());
    vp3_browser_destinations_json_v2010(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Share destinations could not be loaded.']]);
}
