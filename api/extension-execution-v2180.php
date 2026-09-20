<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-execution-v2180.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_execution_json_v2180(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_EXECUTION_V2180]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_execution_input_v2180(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_execution_json_v2180(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_execution_json_v2180(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_execution_agent_v2180(PDO $pdo,array $user,int $requestedAgentId): array
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

function vp3_extension_execution_context_v2180(PDO $pdo,array $user,array $session,array $input): array
{
    $raw=is_array($input['context']??null)?$input['context']:[];
    $context=vp3_browser_context_validate_v2130($raw);
    $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]));
    return [$context,$relations];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_execution_json_v2180(204);
if($method!=='POST')vp3_extension_execution_json_v2180(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_execution_json_v2180(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_execution_schema_ready_v2180($pdo)){
        vp3_extension_execution_json_v2180(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Browser Execution.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_execution_json_v2180(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_execution_json_v2180(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Agent Execution is not enabled for this VP3 account.']]);
    }

    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_execution_json_v2180(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Agent Execution access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_execution_input_v2180();
    $action=trim((string)($input['action']??'list'));
    $agentState=vp3_extension_execution_agent_v2180($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $namespace=(string)$agentState['namespace'];
    $base=[
        'ok'=>true,
        'agent'=>['id'=>$agentState['agent_id'],'name'=>$agentState['agent_name']],
        'storage'=>'reference_only',
        'lifecycle'=>'propose_confirm_execute_verify',
    ];

    if($action==='list'){
        vp3_extension_execution_json_v2180(200,$base+[
            'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace),
            'continuity'=>vp3_browser_execution_continuity_v2180($pdo,(int)$user['id'],$namespace),
        ]);
    }

    if($action==='candidates'){
        [$context,$relations]=vp3_extension_execution_context_v2180($pdo,$user,$session,$input);
        $candidates=vp3_browser_execution_candidates_v2180($pdo,$user,$namespace,$session,$context,$relations);
        vp3_extension_execution_json_v2180(200,$base+[
            'candidates'=>$candidates,
            'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace),
            'continuity'=>vp3_browser_execution_continuity_v2180($pdo,(int)$user['id'],$namespace),
            'page_context'=>['ephemeral'=>true,'title'=>(string)$context['title'],'domain'=>(string)$context['domain']],
        ]);
    }

    if($action==='propose'){
        [$context,$relations]=vp3_extension_execution_context_v2180($pdo,$user,$session,$input);
        $candidates=vp3_browser_execution_candidates_v2180($pdo,$user,$namespace,$session,$context,$relations);
        $actionKey=trim((string)($input['action_key']??''));
        $targetType=trim((string)($input['target_type']??''));
        $targetId=trim((string)($input['target_id']??''));
        $candidate=vp3_browser_execution_find_candidate_v2180($candidates,$actionKey,$targetType,$targetId);
        if(!$candidate)throw new RuntimeException('That action is no longer available for the current authorized page relationship.');
        $ticket=vp3_browser_execution_propose_v2180($pdo,$user,$namespace,$candidate);
        vp3_extension_execution_json_v2180(201,$base+[
            'ticket'=>$ticket,
            'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace),
        ]);
    }

    $ticketId=trim((string)($input['ticket_id']??''));
    if($action==='confirm'){
        $ticket=vp3_browser_execution_confirm_v2180($pdo,$user,$namespace,$ticketId);
        vp3_extension_execution_json_v2180(200,$base+['ticket'=>$ticket,'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace)]);
    }
    if($action==='cancel'){
        $ticket=vp3_browser_execution_cancel_v2180($pdo,$user,$namespace,$ticketId);
        vp3_extension_execution_json_v2180(200,$base+['ticket'=>$ticket,'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace)]);
    }
    if($action==='complete'){
        $ticket=vp3_browser_execution_complete_user_v2180($pdo,$user,$namespace,$ticketId);
        vp3_extension_execution_json_v2180(200,$base+['ticket'=>$ticket,'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace)]);
    }
    if($action==='execute'){
        $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$ticketId);
        if(!$ticket)throw new RuntimeException('Browser Execution ticket was not found.');
        [$context,$relations]=vp3_extension_execution_context_v2180($pdo,$user,$session,$input);
        $candidates=vp3_browser_execution_candidates_v2180($pdo,$user,$namespace,$session,$context,$relations);
        $candidate=vp3_browser_execution_find_candidate_v2180(
            $candidates,(string)$ticket['action_key'],(string)$ticket['target_type'],(string)$ticket['target_id']
        );
        if(!$candidate)throw new RuntimeException('The current page or VP3 authorization changed. Prepare this action again.');
        $result=vp3_browser_execution_execute_v2180($pdo,$user,$namespace,$ticket,$candidate);
        vp3_extension_execution_json_v2180(200,$base+$result+[
            'tickets'=>vp3_browser_execution_list_v2180($pdo,(int)$user['id'],$namespace),
        ]);
    }

    vp3_extension_execution_json_v2180(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Execution action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_execution_json_v2180($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_execution_json_v2180(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_execution_json_v2180(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Companion Execution v21.80 failed: '.$e->getMessage());
    vp3_extension_execution_json_v2180(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Execution is temporarily unavailable.']]);
}
