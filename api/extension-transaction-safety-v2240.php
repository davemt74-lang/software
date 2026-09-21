<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-transaction-safety-v2240.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_transaction_json_v2240(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_TRANSACTION_V2240]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_transaction_input_v2240(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)vp3_extension_transaction_json_v2240(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Submission safety request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_transaction_json_v2240(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_transaction_json_v2240(204);
if($method!=='POST')vp3_extension_transaction_json_v2240(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_transaction_json_v2240(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_transaction_schema_ready_v2240($pdo)){
        vp3_extension_transaction_json_v2240(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Transaction & Submission Safety.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_transaction_json_v2240(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_transaction_json_v2240(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Transaction & Submission Safety requires Browser Agent access.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_transaction_json_v2240(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Transaction & Submission Safety is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_transaction_input_v2240();
    $action=trim((string)($input['action']??'list'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $agent=$requestedAgentId>0?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $base=[
        'ok'=>true,
        'agent'=>['id'=>$agentId,'name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent'],
        'authority_source'=>'v21.90_delegation',
        'runtime_source'=>'v22.00_browser_agent_runtime',
        'interaction_source'=>'v22.10_controlled_web',
        'submission_source'=>'v22.40_transaction_safety',
        'raw_field_values_persisted'=>false,
        'credentials_persisted'=>false,
        'approval_reusable'=>false,
    ];

    $runtimeId=trim((string)($input['runtime_id']??''));
    if($runtimeId==='')throw new InvalidArgumentException('Browser Runtime session is required.');

    if($action==='preview'){
        vp3_extension_transaction_json_v2240(201,$base+vp3_browser_transaction_preview_v2240($pdo,$user,$namespace,$session,$runtimeId,$input));
    }
    if($action==='list'){
        $runtime=vp3_browser_transaction_runtime_v2240($pdo,$user,$namespace,$runtimeId);
        vp3_extension_transaction_json_v2240(200,$base+['intents'=>vp3_browser_transaction_list_v2240($pdo,$runtime)]);
    }

    $intentId=trim((string)($input['intent_id']??''));
    if($intentId==='')throw new InvalidArgumentException('Final submission review is required.');

    if($action==='approve'){
        vp3_extension_transaction_json_v2240(200,$base+vp3_browser_transaction_approve_v2240(
            $pdo,$user,$namespace,$runtimeId,$intentId,
            trim((string)($input['review_hash']??'')),trim((string)($input['acknowledgement']??''))
        ));
    }
    if($action==='claim'){
        vp3_extension_transaction_json_v2240(200,$base+vp3_browser_transaction_claim_v2240(
            $pdo,$user,$namespace,$session,$runtimeId,$intentId,$input
        ));
    }
    if($action==='complete'){
        vp3_extension_transaction_json_v2240(200,$base+vp3_browser_transaction_complete_v2240(
            $pdo,$user,$namespace,$runtimeId,$intentId,trim((string)($input['permit_token']??'')),$input
        ));
    }
    if($action==='cancel'){
        vp3_extension_transaction_json_v2240(200,$base+vp3_browser_transaction_cancel_v2240($pdo,$user,$namespace,$runtimeId,$intentId));
    }

    vp3_extension_transaction_json_v2240(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Transaction & Submission Safety action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_transaction_json_v2240($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_transaction_json_v2240(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_transaction_json_v2240(422,['ok'=>false,'error'=>['code'=>'submission_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Transaction & Submission Safety v22.40 failed: '.$e->getMessage());
    vp3_extension_transaction_json_v2240(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Transaction & Submission Safety is temporarily unavailable.']]);
}
