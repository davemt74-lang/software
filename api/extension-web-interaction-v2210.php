<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-web-interaction-v2210.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_web_json_v2210(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_WEB_INTERACTION_V2210]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_web_input_v2210(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_web_json_v2210(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_web_json_v2210(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_web_json_v2210(204);
if($method!=='POST')vp3_extension_web_json_v2210(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_web_json_v2210(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_web_schema_ready_v2210($pdo)){
        vp3_extension_web_json_v2210(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Controlled Web Interaction Runtime.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_web_json_v2210(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_web_json_v2210(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Controlled Web Interaction Runtime is not enabled for this VP3 account.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_web_json_v2210(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Controlled Web Interaction Runtime access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_web_input_v2210();
    $action=trim((string)($input['action']??'actions'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $agent=$requestedAgentId>0?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $base=[
        'ok'=>true,
        'agent'=>['id'=>$agentId,'name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent'],
        'authority_source'=>'v21.90_delegation',
        'runtime_source'=>'v22.00_browser_agent_runtime',
        'raw_dom_persisted'=>false,
        'typed_values_persisted'=>false,
    ];

    if($action==='actions')vp3_extension_web_json_v2210(200,$base+['actions'=>vp3_browser_web_public_actions_v2210()]);
    $runtimeId=trim((string)($input['runtime_id']??''));
    if($runtimeId==='')throw new InvalidArgumentException('Browser Runtime session is required.');

    if($action==='observe'){
        vp3_extension_web_json_v2210(200,$base+['observation'=>vp3_browser_web_observe_v2210($pdo,$user,$namespace,$session,$runtimeId,$input),'actions'=>vp3_browser_web_public_actions_v2210()]);
    }
    if($action==='list'){
        $runtime=vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimeId);
        vp3_extension_web_json_v2210(200,$base+[
            'interactions'=>vp3_browser_web_list_v2210($pdo,$runtime),
            'remaining_interactions'=>vp3_browser_web_remaining_v2210($pdo,$runtime),
            'max_interactions'=>vp3_browser_web_max_interactions_v2210($runtime),
        ]);
    }
    if($action==='preview'){
        vp3_extension_web_json_v2210(201,$base+vp3_browser_web_preview_v2210($pdo,$user,$namespace,$session,$runtimeId,$input));
    }
    if($action==='confirm'){
        vp3_extension_web_json_v2210(200,$base+vp3_browser_web_confirm_v2210($pdo,$user,$namespace,$runtimeId,trim((string)($input['interaction_id']??''))));
    }
    if($action==='claim'){
        vp3_extension_web_json_v2210(200,$base+vp3_browser_web_claim_v2210($pdo,$user,$namespace,$session,$runtimeId,trim((string)($input['interaction_id']??'')),$input));
    }
    if($action==='complete'){
        vp3_extension_web_json_v2210(200,$base+vp3_browser_web_complete_v2210(
            $pdo,$user,$namespace,$runtimeId,trim((string)($input['interaction_id']??'')),trim((string)($input['permit_token']??'')),$input
        ));
    }
    if($action==='cancel'){
        vp3_extension_web_json_v2210(200,$base+vp3_browser_web_cancel_v2210($pdo,$user,$namespace,$runtimeId,trim((string)($input['interaction_id']??''))));
    }

    vp3_extension_web_json_v2210(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Controlled Web Interaction action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_web_json_v2210($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_web_json_v2210(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_web_json_v2210(422,['ok'=>false,'error'=>['code'=>'interaction_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Controlled Web Interaction v22.10 failed: '.$e->getMessage());
    vp3_extension_web_json_v2210(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Controlled Web Interaction Runtime is temporarily unavailable.']]);
}
