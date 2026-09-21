<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-transaction-continuity-v2260.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_continuity_json_v2260(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_CONTINUITY_V2260]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_continuity_input_v2260(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>49152)vp3_extension_continuity_json_v2260(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Transaction continuity request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_continuity_json_v2260(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_continuity_json_v2260(204);
if($method!=='POST')vp3_extension_continuity_json_v2260(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_continuity_json_v2260(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_continuity_schema_ready_v2260($pdo)){
        vp3_extension_continuity_json_v2260(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Transaction Continuity & Follow-Through.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_continuity_json_v2260(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_continuity_json_v2260(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Transaction continuity requires Browser Agent access.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_continuity_json_v2260(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Transaction continuity is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_continuity_input_v2260();
    $action=trim((string)($input['action']??'list'));
    $base=[
        'ok'=>true,
        'authority_source'=>'v22.50_verified_transaction',
        'continuity_source'=>'v22.60_reference_only_followthrough',
        'raw_page_text_persisted'=>false,
        'raw_url_persisted'=>false,
        'raw_reference_values_persisted'=>false,
        'automatic_external_writes'=>false,
        'external_write_requires_fresh_v2240'=>true,
    ];

    if($action==='list'){
        $domain=vp3_browser_web_domain_v2210($input['domain']??'');
        vp3_extension_continuity_json_v2260(200,$base+['continuities'=>vp3_browser_continuity_list_v2260($pdo,(int)$user['id'],$domain)]);
    }
    if($action==='observe'){
        vp3_extension_continuity_json_v2260(200,$base+vp3_browser_continuity_observe_v2260($pdo,$user,$input));
    }
    if($action==='close'){
        vp3_extension_continuity_json_v2260(200,$base+vp3_browser_continuity_close_v2260($pdo,$user,trim((string)($input['continuity_id']??'')),trim((string)($input['reason']??'user_closed'))));
    }
    if($action==='reopen'){
        vp3_extension_continuity_json_v2260(200,$base+vp3_browser_continuity_reopen_v2260($pdo,$user,trim((string)($input['continuity_id']??''))));
    }
    if($action==='proposal_action'){
        vp3_extension_continuity_json_v2260(200,$base+vp3_browser_followthrough_resolve_v2260($pdo,$user,trim((string)($input['proposal_id']??'')),trim((string)($input['proposal_action']??''))));
    }

    if($action==='ensure'){
        $runtimeId=trim((string)($input['runtime_id']??''));
        $intentId=trim((string)($input['intent_id']??''));
        if($runtimeId===''||$intentId==='')throw new InvalidArgumentException('Verified transaction context is required.');
        $requestedAgentId=max(0,(int)($input['agent_id']??0));
        $agent=$requestedAgentId>0?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
        $agentId=$agent?(int)$agent['id']:0;
        $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
        vp3_extension_continuity_json_v2260(201,$base+vp3_browser_continuity_ensure_v2260($pdo,$user,$namespace,$runtimeId,$intentId));
    }

    vp3_extension_continuity_json_v2260(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Transaction Continuity action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_continuity_json_v2260($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_continuity_json_v2260(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_continuity_json_v2260(422,['ok'=>false,'error'=>['code'=>'continuity_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Transaction Continuity v22.60 failed: '.$e->getMessage());
    vp3_extension_continuity_json_v2260(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Transaction Continuity is temporarily unavailable.']]);
}
