<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/agent-integration-diagnostics-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, OPTIONS');
function integration_extension_response(int $status,array $body): never {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')integration_extension_response(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'GET required.']]);
if(($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??'')!=='1')integration_extension_response(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Update the Chrome extension.']]);
try{
    $pdo=db();$session=$pdo?vp3_extension_session_authenticate_v2001($pdo):null;
    if(!$session)integration_extension_response(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Reconnect the browser to Cloud.']]);
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if(!$user)integration_extension_response(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'The connected account is no longer active.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')||!has_permission('chat.access',$user))integration_extension_response(403,['ok'=>false,'error'=>['code'=>'permission_required','message'=>'This browser needs Agent Chat permission for integration diagnostics.']]);
    integration_extension_response(200,['ok'=>true,'diagnostics'=>vp3_agent_integration_diagnostics_v1($user)]);
}catch(Throwable $e){integration_extension_response(503,['ok'=>false,'error'=>['code'=>'diagnostic_unavailable','message'=>'Integration diagnostic unavailable. Check the deployed Cloud upgrade and HomeServer connection.']]);}
