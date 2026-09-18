<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version, X-Request-ID');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_annotated_release_json_v2100(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_ANNOTATED_RELEASE_V2100,'request_id'=>vp3_annotated_request_id_v2100()]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_annotated_release_auth_v2100(PDO $pdo): array
{
    if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_annotated_release_json_v2100(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'extension'=>true,'session'=>$session];
    }
    $user=current_user();return ['user_id'=>(int)($user['id']??0),'extension'=>false,'user'=>$user];
}
function vp3_annotated_release_input_v2100(): array
{
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>16384)vp3_annotated_release_json_v2100(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    $input=json_decode($raw,true);return is_array($input)?$input:$_POST;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if($method==='OPTIONS')vp3_annotated_release_json_v2100(204);
if(!in_array($method,['GET','POST'],true))vp3_annotated_release_json_v2100(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''&&trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1')vp3_annotated_release_json_v2100(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

try{
    $pdo=vp3_annotated_require_ready_v2100(db());$auth=vp3_annotated_release_auth_v2100($pdo);$userId=(int)$auth['user_id'];
    if($userId<1)vp3_annotated_release_json_v2100(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Annotated.']]);
    vp3_annotated_rate_limit_v2100($pdo,$userId,'release_state');
    $version=trim((string)($_SERVER['HTTP_X_VP3_EXTENSION_VERSION']??''));
    $compat=$version!==''?vp3_annotated_extension_compatibility_v2100($version):null;
    $action=trim((string)($_GET['action']??'state'));

    if($method==='GET'){
        if($action==='state')vp3_annotated_release_json_v2100(200,['ok'=>true,'state'=>vp3_annotated_user_state_v2100($pdo,$userId),'compatibility'=>$compat]);
        if($action==='privacy')vp3_annotated_release_json_v2100(200,['ok'=>true,'privacy'=>vp3_annotated_privacy_inventory_v2100($pdo,$userId)]);
        vp3_annotated_release_json_v2100(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Annotated release action.']]);
    }

    $input=vp3_annotated_release_input_v2100();
    if(empty($auth['extension'])){$csrf=trim((string)($input['csrf_token']??''));if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_annotated_release_json_v2100(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);}
    $action=trim((string)($input['action']??$action));
    if($action==='milestone'){
        $milestone=trim((string)($input['milestone']??''));
        if(!in_array($milestone,vp3_annotated_manual_milestones_v2100(),true))vp3_annotated_release_json_v2100(422,['ok'=>false,'error'=>['code'=>'invalid_milestone','message'=>'That onboarding milestone is derived from real product activity.']]);
        vp3_annotated_mark_milestone_v2100($pdo,$userId,$milestone,['surface'=>(string)($input['surface']??'')]);
        vp3_annotated_release_json_v2100(200,['ok'=>true,'state'=>vp3_annotated_user_state_v2100($pdo,$userId)]);
    }
    vp3_annotated_release_json_v2100(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Annotated release action.']]);
}catch(VP3AnnotatedRateLimitExceptionV2100 $e){header('Retry-After: '.$e->retryAfter);vp3_annotated_release_json_v2100(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>$e->getMessage()]]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){vp3_annotated_release_json_v2100($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){vp3_annotated_release_json_v2100(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){vp3_annotated_release_json_v2100(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){error_log('Annotated release v21.00 failed ['.vp3_annotated_request_id_v2100().']: '.$e->getMessage());vp3_annotated_release_json_v2100(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Annotated release state is temporarily unavailable.']]);}
