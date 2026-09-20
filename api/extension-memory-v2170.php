<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/cognitive-memory-v570.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_memory_json_v2170(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_MEMORY_V2170]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_memory_input_v2170(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_memory_json_v2170(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_memory_json_v2170(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_memory_agent_v2170(PDO $pdo,array $user,int $requestedAgentId): array
{
    $agent=$requestedAgentId>0
        ?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId)
        :vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    return [
        'agent'=>$agent,
        'agent_id'=>$agentId,
        'agent_name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent',
        'namespace'=>vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId),
    ];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_memory_json_v2170(204);
if($method!=='POST')vp3_extension_memory_json_v2170(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_memory_json_v2170(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_memory_schema_ready_v2170($pdo)||!vp3_cognitive_memory_schema_ready_v570($pdo)){
        vp3_extension_memory_json_v2170(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Browser Memory.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_memory_json_v2170(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_memory_json_v2170(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Agent Memory is not enabled for this VP3 account.']]);
    }

    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_memory_json_v2170(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Agent Memory access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_memory_input_v2170();
    $action=trim((string)($input['action']??'list'));
    $agentState=vp3_extension_memory_agent_v2170($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $namespace=(string)$agentState['namespace'];

    if($action==='list'){
        vp3_extension_memory_json_v2170(200,[
            'ok'=>true,
            'agent'=>['id'=>$agentState['agent_id'],'name'=>$agentState['agent_name']],
            'remembered'=>vp3_browser_memory_list_v2170($pdo,$user,$namespace),
            'storage'=>'reference_only',
        ]);
    }

    if($action==='candidates'){
        $rawContext=is_array($input['context']??null)?$input['context']:[];
        $context=vp3_browser_context_validate_v2130($rawContext);
        $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]));
        vp3_extension_memory_json_v2170(200,[
            'ok'=>true,
            'agent'=>['id'=>$agentState['agent_id'],'name'=>$agentState['agent_name']],
            'candidates'=>vp3_browser_memory_candidates_v2170($pdo,$user,$namespace,$relations),
            'remembered'=>vp3_browser_memory_list_v2170($pdo,$user,$namespace),
            'page_context'=>['ephemeral'=>true,'title'=>(string)$context['title'],'domain'=>(string)$context['domain']],
            'storage'=>'reference_only',
        ]);
    }

    if($action==='approve'){
        $type=vp3_browser_memory_target_type_v2170($input['target_type']??'');
        $id=vp3_browser_memory_target_id_v2170($input['target_id']??'');
        if($type===''||$id==='')throw new InvalidArgumentException('Choose a valid VP3 object to remember.');
        $item=vp3_browser_memory_approve_v2170($pdo,$user,$namespace,$type,$id);
        vp3_extension_memory_json_v2170(200,[
            'ok'=>true,'item'=>$item,
            'remembered'=>vp3_browser_memory_list_v2170($pdo,$user,$namespace),
            'storage'=>'reference_only',
        ]);
    }

    if($action==='revoke'){
        $approvalId=trim((string)($input['approval_id']??''));
        if(!preg_match('/^[a-f0-9-]{36}$/i',$approvalId))throw new InvalidArgumentException('Browser Memory approval identity is invalid.');
        vp3_browser_memory_revoke_v2170($pdo,$user,$namespace,$approvalId);
        vp3_extension_memory_json_v2170(200,[
            'ok'=>true,
            'remembered'=>vp3_browser_memory_list_v2170($pdo,$user,$namespace),
            'storage'=>'reference_only',
        ]);
    }

    vp3_extension_memory_json_v2170(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Memory action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_memory_json_v2170($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_memory_json_v2170(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Companion Memory v21.70 failed: '.$e->getMessage());
    vp3_extension_memory_json_v2170(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Memory is temporarily unavailable.']]);
}
