<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-transaction-intelligence-v2270.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_intelligence_json_v2270(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_INTELLIGENCE_V2270]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_extension_intelligence_input_v2270(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_intelligence_json_v2270(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Transaction intelligence request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_intelligence_json_v2270(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_intelligence_json_v2270(204);
if($method!=='POST')vp3_extension_intelligence_json_v2270(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_intelligence_json_v2270(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_intelligence_schema_ready_v2270($pdo)){
        vp3_extension_intelligence_json_v2270(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Transaction Intelligence & Exception Management.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_intelligence_json_v2270(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_intelligence_json_v2270(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Transaction intelligence requires Browser Agent access.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_intelligence_json_v2270(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Transaction intelligence is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_intelligence_input_v2270();
    $action=trim((string)($input['action']??'inbox'));
    $base=[
        'ok'=>true,
        'authority_source'=>'v22.60_reference_only_continuity',
        'intelligence_source'=>'v22.70_bounded_transaction_attention',
        'raw_page_text_persisted'=>false,
        'raw_url_persisted'=>false,
        'raw_reference_values_persisted'=>false,
        'normalized_schedule_facts_only'=>true,
        'bounded_financial_facts_only'=>true,
        'cross_transaction_signals_are_advisory'=>true,
        'automatic_external_writes'=>false,
        'external_write_requires_fresh_v2240'=>true,
    ];

    if($action==='inbox'){
        vp3_browser_intelligence_evaluate_all_v2270($pdo,$user);
        vp3_extension_intelligence_json_v2270(200,$base+['inbox'=>vp3_browser_intelligence_inbox_v2270($pdo,$user)]);
    }
    if($action==='evaluate'){
        vp3_browser_intelligence_evaluate_all_v2270($pdo,$user);
        vp3_extension_intelligence_json_v2270(200,$base+['inbox'=>vp3_browser_intelligence_inbox_v2270($pdo,$user)]);
    }
    if($action==='case_action'){
        $result=vp3_browser_intelligence_case_action_v2270(
            $pdo,$user,trim((string)($input['case_id']??'')),trim((string)($input['case_action']??''))
        );
        vp3_extension_intelligence_json_v2270(200,$base+$result+['inbox'=>vp3_browser_intelligence_inbox_v2270($pdo,$user)]);
    }
    if($action==='proposal_action'){
        $result=vp3_browser_intelligence_proposal_action_v2270(
            $pdo,$user,trim((string)($input['proposal_id']??'')),trim((string)($input['proposal_action']??''))
        );
        vp3_extension_intelligence_json_v2270(200,$base+$result+['inbox'=>vp3_browser_intelligence_inbox_v2270($pdo,$user)]);
    }
    vp3_extension_intelligence_json_v2270(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Transaction Intelligence action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_intelligence_json_v2270($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_intelligence_json_v2270(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_intelligence_json_v2270(422,['ok'=>false,'error'=>['code'=>'transaction_intelligence_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Transaction Intelligence v22.70 failed: '.$e->getMessage());
    vp3_extension_intelligence_json_v2270(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Transaction Intelligence is temporarily unavailable.']]);
}
