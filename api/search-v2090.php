<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/search-discovery-v2090.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_search_api_json_v2090(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_SEARCH_DISCOVERY_V2090]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_search_api_auth_v2090(PDO $pdo): array
{
    if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_search_api_json_v2090(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'session'=>$session,'extension'=>true];
    }
    $user=current_user();return ['user_id'=>(int)($user['id']??0),'user'=>$user,'extension'=>false];
}
function vp3_search_api_cap_v2090(array $auth,string $capability='team.chat.read'): void
{
    if(!empty($auth['extension'])&&!vp3_extension_session_has_capability_v2001((array)$auth['session'],$capability)){
        vp3_search_api_json_v2090(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection does not have permission for this action.']]);
    }
}
function vp3_search_api_input_v2090(): array
{
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>32768)vp3_search_api_json_v2090(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    $input=json_decode($raw,true);return is_array($input)?$input:$_POST;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if($method==='OPTIONS')vp3_search_api_json_v2090(204);
if(!in_array($method,['GET','POST'],true))vp3_search_api_json_v2090(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''&&trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1')vp3_search_api_json_v2090(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

try{
    $pdo=vp3_search_require_ready_v2090(db());$auth=vp3_search_api_auth_v2090($pdo);$userId=(int)$auth['user_id'];vp3_search_api_cap_v2090($auth);
    $action=trim((string)($_GET['action']??'search'));
    if($method==='GET'){
        vp3_annotated_rate_limit_v2100($pdo,$userId,'search_read');
        if($action==='search')vp3_search_api_json_v2090(200,['ok'=>true,'search'=>vp3_search_query_v2090($pdo,$userId,(string)($_GET['q']??''),$_GET,max(1,min(100,(int)($_GET['limit']??50))),true),'options'=>vp3_search_filter_options_v2090($pdo,$userId)]);
        if($action==='discover')vp3_search_api_json_v2090(200,['ok'=>true,'discovery'=>vp3_search_discover_v2090($pdo,$userId,$_GET),'options'=>vp3_search_filter_options_v2090($pdo,$userId)]);
        if($action==='recent')vp3_search_api_json_v2090(200,['ok'=>true,'recent'=>vp3_search_recent_v2090($pdo,$userId,20)]);
        if($action==='saved')vp3_search_api_json_v2090(200,['ok'=>true,'saved'=>vp3_search_saved_v2090($pdo,$userId)]);
        vp3_search_api_json_v2090(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Search action.']]);
    }
    if($userId<1)vp3_search_api_json_v2090(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to manage saved or recent searches.']]);
    $input=vp3_search_api_input_v2090();$action=trim((string)($input['action']??$action));
    vp3_annotated_rate_limit_v2100($pdo,$userId,'search_write');
    if(empty($auth['extension'])){$csrf=trim((string)($input['csrf_token']??''));if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_search_api_json_v2090(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);}
    if($action==='save_search')vp3_search_api_json_v2090(201,['ok'=>true,'saved'=>vp3_search_save_query_v2090($pdo,$userId,(string)($input['name']??''),(string)($input['q']??''),is_array($input['filters']??null)?$input['filters']:$input)]);
    if($action==='delete_saved'){vp3_search_delete_saved_v2090($pdo,$userId,(string)($input['id']??''));vp3_search_api_json_v2090(200,['ok'=>true,'saved'=>vp3_search_saved_v2090($pdo,$userId)]);}
    if($action==='clear_recent'){vp3_search_clear_recent_v2090($pdo,$userId);vp3_search_api_json_v2090(200,['ok'=>true,'recent'=>[]]);}
    vp3_search_api_json_v2090(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Search action.']]);
}catch(VP3AnnotatedRateLimitExceptionV2100 $e){header('Retry-After: '.$e->retryAfter);vp3_search_api_json_v2090(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>$e->getMessage()]]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){vp3_search_api_json_v2090($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){vp3_search_api_json_v2090(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){vp3_search_api_json_v2090(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){error_log('VP3 Search v20.90 failed: '.$e->getMessage());vp3_search_api_json_v2090(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Search request failed.']]);}
