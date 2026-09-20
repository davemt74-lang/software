<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-multisite-v2220.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_multisite_json_v2220(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_MULTISITE_V2220]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_multisite_input_v2220(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_multisite_json_v2220(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_multisite_json_v2220(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_multisite_json_v2220(204);
if($method!=='POST')vp3_extension_multisite_json_v2220(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_multisite_json_v2220(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_multisite_schema_ready_v2220($pdo)){
        vp3_extension_multisite_json_v2220(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Multi-Site Workflow Automation.']]);
    }
    $auth=vp3_extension_session_authenticate_v2001($pdo);
    if(!$auth)vp3_extension_multisite_json_v2220(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($auth,'agent.message')){
        vp3_extension_multisite_json_v2220(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Multi-Site Workflow Automation is not enabled for this VP3 account.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$auth['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_multisite_json_v2220(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Multi-Site Workflow Automation access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_multisite_input_v2220();
    $action=trim((string)($input['action']??'state'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $agent=$requestedAgentId>0?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $runtimeId=trim((string)($input['runtime_id']??''));
    if($runtimeId==='')throw new InvalidArgumentException('Browser Runtime session is required.');
    $runtime=vp3_browser_multisite_runtime_v2220($pdo,$user,$namespace,$runtimeId);
    $base=[
        'ok'=>true,'agent'=>['id'=>$agentId,'name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent'],
        'authority_source'=>'v21.90_delegation','runtime_source'=>'v22.00_browser_agent_runtime',
        'interaction_source'=>'v22.10_controlled_web_interaction',
        'raw_urls_persisted'=>false,'cookies_persisted'=>false,'credentials_persisted'=>false,
    ];

    if($action==='attach'){
        vp3_extension_multisite_json_v2220(200,$base+['state'=>vp3_browser_multisite_attach_v2220($pdo,$user,$namespace,$runtimeId,(string)($input['current_domain']??''))]);
    }
    $session=vp3_browser_multisite_row_v2220($pdo,$runtime);
    if(!$session)throw new RuntimeException('Attach the multi-site runtime before using this action.');
    vp3_browser_multisite_cleanup_v2220($pdo,$runtime,$session);

    if($action==='state')vp3_extension_multisite_json_v2220(200,$base+['state'=>vp3_browser_multisite_state_v2220($pdo,$runtime)]);
    if($action==='set_policy')vp3_extension_multisite_json_v2220(200,$base+['state'=>vp3_browser_multisite_set_policy_v2220($pdo,$runtime,$session,(string)($input['domain']??''),(string)($input['policy_mode']??'browse'))]);
    if($action==='handoff_preview')vp3_extension_multisite_json_v2220(201,$base+vp3_browser_multisite_handoff_preview_v2220($pdo,$runtime,$session,$input));
    if($action==='handoff_claim')vp3_extension_multisite_json_v2220(200,$base+vp3_browser_multisite_handoff_claim_v2220($pdo,$runtime,$session,trim((string)($input['handoff_id']??'')),$input));
    if($action==='handoff_complete')vp3_extension_multisite_json_v2220(200,$base+vp3_browser_multisite_handoff_complete_v2220(
        $pdo,$runtime,$session,trim((string)($input['handoff_id']??'')),trim((string)($input['permit_token']??'')),$input
    ));
    if($action==='handoff_cancel')vp3_extension_multisite_json_v2220(200,$base+vp3_browser_multisite_handoff_cancel_v2220($pdo,$runtime,$session,trim((string)($input['handoff_id']??''))));
    if($action==='tab_register')vp3_extension_multisite_json_v2220(200,$base+['state'=>vp3_browser_multisite_tab_register_v2220($pdo,$runtime,$session,$input)]);
    if($action==='tab_release')vp3_extension_multisite_json_v2220(200,$base+['state'=>vp3_browser_multisite_tab_release_v2220($pdo,$runtime,$session,(string)($input['client_tab_key']??''))]);
    if($action==='fact_add')vp3_extension_multisite_json_v2220(201,$base+['state'=>vp3_browser_multisite_fact_add_v2220($pdo,$runtime,$session,$input)]);
    if($action==='artifact_add')vp3_extension_multisite_json_v2220(201,$base+['state'=>vp3_browser_multisite_artifact_add_v2220($pdo,$runtime,$session,$input)]);

    vp3_extension_multisite_json_v2220(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Multi-Site Runtime action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_multisite_json_v2220($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_multisite_json_v2220(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_multisite_json_v2220(422,['ok'=>false,'error'=>['code'=>'multisite_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Multi-Site Runtime v22.20 failed: '.$e->getMessage());
    vp3_extension_multisite_json_v2220(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Multi-Site Workflow Automation is temporarily unavailable.']]);
}
