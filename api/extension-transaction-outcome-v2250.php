<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-transaction-outcome-v2250.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_outcome_json_v2250(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_OUTCOME_V2250]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_outcome_input_v2250(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_outcome_json_v2250(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Outcome verification request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_outcome_json_v2250(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_outcome_json_v2250(204);
if($method!=='POST')vp3_extension_outcome_json_v2250(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_outcome_json_v2250(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_outcome_schema_ready_v2250($pdo)){
        vp3_extension_outcome_json_v2250(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Transaction Outcome Verification & Recovery.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_outcome_json_v2250(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_outcome_json_v2250(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Transaction outcome verification requires Browser Agent access.']]);
    }

    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_outcome_json_v2250(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Transaction outcome verification is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_outcome_input_v2250();
    $action=trim((string)($input['action']??'list'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $agent=$requestedAgentId>0?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $runtimeId=trim((string)($input['runtime_id']??''));
    if($runtimeId==='')throw new InvalidArgumentException('Browser Runtime session is required.');

    $runtime=vp3_browser_outcome_runtime_v2250($pdo,$user,$namespace,$runtimeId);
    $base=[
        'ok'=>true,
        'agent'=>['id'=>$agentId,'name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent'],
        'authority_source'=>'v22.40_reviewed_submission',
        'runtime_source'=>'v22.00_browser_agent_runtime',
        'outcome_source'=>'v22.50_destination_verification',
        'raw_page_text_persisted'=>false,
        'raw_reference_values_persisted'=>false,
        'automatic_retry'=>false,
        'recovery_requires_user_resolution'=>true,
    ];

    if($action==='list'){
        vp3_extension_outcome_json_v2250(200,$base+vp3_browser_outcome_list_v2250($pdo,$runtime));
    }

    $intentId=trim((string)($input['intent_id']??''));
    if($intentId==='')throw new InvalidArgumentException('Transaction submission intent is required.');

    if($action==='observe'){
        vp3_extension_outcome_json_v2250(201,$base+vp3_browser_outcome_observe_v2250($pdo,$user,$namespace,$runtimeId,$intentId,$input));
    }
    if($action==='resolve'){
        vp3_extension_outcome_json_v2250(200,$base+vp3_browser_outcome_resolve_v2250(
            $pdo,$user,$namespace,$runtimeId,$intentId,
            trim((string)($input['resolution']??'')),trim((string)($input['acknowledgement']??''))
        ));
    }

    vp3_extension_outcome_json_v2250(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Transaction Outcome Verification action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_outcome_json_v2250($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_outcome_json_v2250(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_outcome_json_v2250(422,['ok'=>false,'error'=>['code'=>'outcome_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Transaction Outcome Verification v22.50 failed: '.$e->getMessage());
    vp3_extension_outcome_json_v2250(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Transaction Outcome Verification is temporarily unavailable.']]);
}
