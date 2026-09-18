<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-trust-v2080.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_browser_trust_api_json_v2080(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_TRUST_V2080]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_browser_trust_api_auth_v2080(PDO $pdo): array
{
    if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_browser_trust_api_json_v2080(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'session'=>$session,'extension'=>true];
    }
    $user=current_user();
    return ['user_id'=>(int)($user['id']??0),'user'=>$user,'extension'=>false];
}
function vp3_browser_trust_api_cap_v2080(array $auth,string $capability): void
{
    if(empty($auth['extension']))return;
    if(!vp3_extension_session_has_capability_v2001((array)$auth['session'],$capability)){
        vp3_browser_trust_api_json_v2080(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection does not have permission for this action.']]);
    }
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_browser_trust_api_json_v2080(204);
if(!in_array($method,['GET','POST'],true))vp3_browser_trust_api_json_v2080(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''&&trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_browser_trust_api_json_v2080(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}
try{
    $pdo=vp3_browser_trust_require_ready_v2080(db());
    $auth=vp3_browser_trust_api_auth_v2080($pdo);$userId=(int)$auth['user_id'];
    $action=trim((string)($_GET['action']??''));
    if($method==='GET'){
        if($userId<1)vp3_browser_trust_api_json_v2080(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Phase 9.']]);
        vp3_browser_trust_api_cap_v2080($auth,'team.chat.read');
        if($action==='notifications')vp3_browser_trust_api_json_v2080(200,['ok'=>true,'notifications'=>vp3_browser_trust_notifications_v2080($pdo,$userId,max(1,min(100,(int)($_GET['limit']??50)))),'preferences'=>vp3_browser_trust_preferences_v2080($pdo,$userId)]);
        if($action==='source_history')vp3_browser_trust_api_json_v2080(200,['ok'=>true]+vp3_browser_trust_source_history_v2080($pdo,$userId,trim((string)($_GET['source_id']??'')),max(1,min(100,(int)($_GET['limit']??25)))));
        if($action==='claims_for_source')vp3_browser_trust_api_json_v2080(200,['ok'=>true,'claims'=>vp3_browser_trust_claims_for_source_v2080($pdo,$userId,trim((string)($_GET['source_id']??'')),50)]);
        if($action==='claim'){
            $row=vp3_browser_trust_claim_row_v2080($pdo,trim((string)($_GET['claim_id']??'')));
            if(!$row||!vp3_browser_trust_claim_access_v2080($pdo,$row,$userId))vp3_browser_trust_api_json_v2080(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Claim was not found.']]);
            vp3_browser_trust_api_json_v2080(200,['ok'=>true,'claim'=>vp3_browser_trust_claim_public_v2080($pdo,$row,$userId,true)]);
        }
        vp3_browser_trust_api_json_v2080(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Phase 9 action.']]);
    }

    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_browser_trust_api_json_v2080(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    $input=json_decode($raw,true);if(!is_array($input))$input=$_POST;$action=trim((string)($input['action']??$action));
    if($userId<1)vp3_browser_trust_api_json_v2080(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Phase 9.']]);
    if(empty($auth['extension'])){
        $csrf=trim((string)($input['csrf_token']??''));
        if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_browser_trust_api_json_v2080(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);
    }

    if($action==='observe_source'){
        vp3_browser_trust_api_cap_v2080($auth,'team.chat.read');
        vp3_browser_trust_api_json_v2080(200,['ok'=>true]+vp3_browser_trust_observe_source_v2080($pdo,$userId,trim((string)($input['url']??'')),trim((string)($input['canonical_url']??'')),trim((string)($input['title']??'')),trim((string)($input['source_version_hash']??''))));
    }
    if($action==='notification_read'){
        vp3_browser_trust_api_cap_v2080($auth,'team.chat.read');
        vp3_browser_trust_api_json_v2080(200,['ok'=>true,'notifications'=>vp3_browser_trust_mark_notification_v2080($pdo,$userId,max(0,(int)($input['notification_id']??0)),!empty($input['all']))]);
    }
    if($action==='preference'){
        vp3_browser_trust_api_cap_v2080($auth,'team.chat.read');
        vp3_browser_trust_api_json_v2080(200,['ok'=>true]+vp3_browser_trust_set_preference_v2080($pdo,$userId,trim((string)($input['type']??'')),!empty($input['enabled'])));
    }
    if($action==='claim_create'){
        vp3_browser_trust_api_cap_v2080($auth,'team.share.create');
        vp3_browser_trust_api_json_v2080(201,['ok'=>true,'claim'=>vp3_browser_trust_claim_create_v2080($pdo,$userId,$input)]);
    }
    if($action==='claim_status'){
        vp3_browser_trust_api_cap_v2080($auth,'team.share.create');
        vp3_browser_trust_api_json_v2080(200,['ok'=>true,'claim'=>vp3_browser_trust_claim_status_v2080($pdo,$userId,trim((string)($input['claim_id']??'')),trim((string)($input['status']??'')),(string)($input['note']??''))]);
    }
    if($action==='report_create'){
        vp3_browser_trust_api_cap_v2080($auth,'team.chat.read');
        vp3_browser_trust_api_json_v2080(201,['ok'=>true,'report'=>vp3_browser_trust_report_create_v2080($pdo,$userId,trim((string)($input['target_type']??'')),trim((string)($input['target_id']??'')),trim((string)($input['reason']??'')),(string)($input['detail']??''))]);
    }
    vp3_browser_trust_api_json_v2080(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Phase 9 action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_browser_trust_api_json_v2080($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_browser_trust_api_json_v2080(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_browser_trust_api_json_v2080(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Trust v20.80 failed: '.$e->getMessage());
    vp3_browser_trust_api_json_v2080(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Trust request failed.']]);
}
