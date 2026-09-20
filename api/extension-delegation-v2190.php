<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-delegation-v2190.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_delegation_json_v2190(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_DELEGATION_V2190]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_delegation_input_v2190(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)vp3_extension_delegation_json_v2190(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_delegation_json_v2190(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_delegation_agent_v2190(PDO $pdo,array $user,int $requestedAgentId): array
{
    $agent=$requestedAgentId>0
        ?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId)
        :vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    return [
        'agent'=>$agent,'agent_id'=>$agentId,
        'agent_name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent',
        'namespace'=>vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId),
    ];
}

function vp3_extension_delegation_context_v2190(PDO $pdo,array $user,array $session,array $input): array
{
    $raw=is_array($input['context']??null)?$input['context']:[];
    $context=vp3_browser_context_validate_v2130($raw);
    $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]));
    return [$context,$relations];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_delegation_json_v2190(204);
if($method!=='POST')vp3_extension_delegation_json_v2190(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_delegation_json_v2190(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_delegation_schema_ready_v2190($pdo)){
        vp3_extension_delegation_json_v2190(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Delegated Browser Workflows.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_delegation_json_v2190(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_delegation_json_v2190(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Delegated Browser Workflows are not enabled for this VP3 account.']]);
    }

    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_delegation_json_v2190(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Delegated Browser Workflow access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_delegation_input_v2190();
    $action=trim((string)($input['action']??'list'));
    $agentState=vp3_extension_delegation_agent_v2190($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $namespace=(string)$agentState['namespace'];
    $base=[
        'ok'=>true,
        'agent'=>['id'=>$agentState['agent_id'],'name'=>$agentState['agent_name']],
        'authority'=>'bounded_delegation',
        'storage'=>'task_scope_and_vp3_references',
        'lifecycle'=>'intent_plan_delegate_execute_checkpoint_verify_recover_complete',
    ];

    if($action==='list'){
        vp3_extension_delegation_json_v2190(200,$base+[
            'delegations'=>vp3_browser_delegation_list_v2190($pdo,$user,$namespace,20),
        ]);
    }

    if(in_array($action,['preview','create'],true)){
        [$context,$relations]=vp3_extension_delegation_context_v2190($pdo,$user,$session,$input);
        $constraints=is_array($input['constraints']??null)?$input['constraints']:[];
        $plan=vp3_browser_delegation_plan_v2190(
            $pdo,$user,$namespace,$session,$context,$relations,
            (string)($input['instruction']??''),$constraints
        );
        if($action==='preview'){
            vp3_extension_delegation_json_v2190(200,$base+[
                'plan'=>$plan,'page_context'=>['ephemeral'=>true,'title'=>(string)$context['title'],'domain'=>(string)$context['domain']]
            ]);
        }
        $delegation=vp3_browser_delegation_create_v2190(
            $pdo,$user,$namespace,$agentState['agent_id']>0?(int)$agentState['agent_id']:null,
            $plan,trim((string)($input['plan_hash']??''))
        );
        vp3_extension_delegation_json_v2190(201,$base+[
            'delegation'=>$delegation,'delegations'=>vp3_browser_delegation_list_v2190($pdo,$user,$namespace,20)
        ]);
    }

    $delegationId=trim((string)($input['delegation_id']??''));
    if($action==='detail'){
        $delegation=vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$delegationId,true);
        if(!$delegation)throw new RuntimeException('Delegated Browser job was not found.');
        vp3_extension_delegation_json_v2190(200,$base+['delegation'=>$delegation]);
    }

    if(in_array($action,['pause','resume','cancel'],true)){
        $delegation=vp3_browser_delegation_update_status_v2190($pdo,$user,$namespace,$delegationId,$action);
        vp3_extension_delegation_json_v2190(200,$base+['delegation'=>$delegation]);
    }

    if($action==='next'){
        vp3_extension_delegation_json_v2190(200,$base+vp3_browser_delegation_next_v2190($pdo,$user,$namespace,$delegationId));
    }

    if($action==='verify_navigation'){
        [$context]=$tmp=vp3_extension_delegation_context_v2190($pdo,$user,$session,$input);
        $delegation=vp3_browser_delegation_verify_navigation_v2190(
            $pdo,$user,$namespace,$delegationId,max(0,(int)($input['action_id']??0)),$context
        );
        vp3_extension_delegation_json_v2190(200,$base+['delegation'=>$delegation,'state'=>(string)$delegation['status']]);
    }

    if($action==='complete_checkpoint'){
        $delegation=vp3_browser_delegation_complete_checkpoint_v2190(
            $pdo,$user,$namespace,$delegationId,max(0,(int)($input['action_id']??0))
        );
        vp3_extension_delegation_json_v2190(200,$base+['delegation'=>$delegation,'state'=>(string)$delegation['status']]);
    }

    if($action==='retry'){
        $delegation=vp3_browser_delegation_retry_v2190(
            $pdo,$user,$namespace,$delegationId,max(0,(int)($input['action_id']??0))
        );
        vp3_extension_delegation_json_v2190(200,$base+['delegation'=>$delegation,'state'=>(string)$delegation['status']]);
    }

    vp3_extension_delegation_json_v2190(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Delegated Browser Workflow action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_delegation_json_v2190($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_delegation_json_v2190(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_delegation_json_v2190(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Companion Delegation v21.90 failed: '.$e->getMessage());
    vp3_extension_delegation_json_v2190(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Delegated Browser Workflows are temporarily unavailable.']]);
}
