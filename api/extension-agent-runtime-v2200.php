<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-agent-runtime-v2200.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_runtime_json_v2200(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_AGENT_RUNTIME_V2200]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_runtime_input_v2200(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)vp3_extension_runtime_json_v2200(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_runtime_json_v2200(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_runtime_agent_v2200(PDO $pdo,array $user,int $requestedAgentId): array
{
    $agent=$requestedAgentId>0
        ?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId)
        :vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $agentId=$agent?(int)$agent['id']:0;
    return [
        'agent_id'=>$agentId,
        'agent_name'=>$agent?(string)($agent['display_name']??$agent['name']??'VP3 Agent'):'VP3 Agent',
        'namespace'=>vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId),
    ];
}

function vp3_extension_runtime_context_v2200(PDO $pdo,array $user,array $session,array $input): array
{
    $raw=is_array($input['context']??null)?$input['context']:[];
    $context=vp3_browser_context_validate_v2130($raw);
    $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]));
    return [$context,$relations];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_runtime_json_v2200(204);
if($method!=='POST')vp3_extension_runtime_json_v2200(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_runtime_json_v2200(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_runtime_schema_ready_v2200($pdo)){
        vp3_extension_runtime_json_v2200(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Browser Agent Runtime.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_runtime_json_v2200(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_runtime_json_v2200(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Browser Agent Runtime is not enabled for this VP3 account.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_runtime_json_v2200(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Browser Agent Runtime access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_runtime_input_v2200();
    $action=trim((string)($input['action']??'list'));
    $agent=vp3_extension_runtime_agent_v2200($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $namespace=(string)$agent['namespace'];
    $base=[
        'ok'=>true,'agent'=>['id'=>$agent['agent_id'],'name'=>$agent['agent_name']],
        'lifecycle'=>'goal_plan_runtime_observe_act_verify_replan_checkpoint_complete',
        'authority_source'=>'v21.90_delegation','raw_page_content_persisted'=>false,
    ];

    if($action==='list'){
        vp3_extension_runtime_json_v2200(200,$base+[
            'sessions'=>vp3_browser_runtime_list_v2200($pdo,$user,$namespace,20),
            'skills'=>vp3_browser_runtime_public_skills_v2200(),
        ]);
    }
    if($action==='skills'){
        vp3_extension_runtime_json_v2200(200,$base+['skills'=>vp3_browser_runtime_public_skills_v2200()]);
    }
    if($action==='attach'){
        $runtime=vp3_browser_runtime_attach_v2200($pdo,$user,$namespace,trim((string)($input['delegation_id']??'')));
        vp3_extension_runtime_json_v2200(201,$base+['runtime'=>$runtime]);
    }

    $runtimeId=trim((string)($input['runtime_id']??''));
    if($action==='detail'){
        $runtime=vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimeId,true);
        if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
        vp3_extension_runtime_json_v2200(200,$base+['runtime'=>$runtime]);
    }
    if($action==='tick'){
        [$context,$relations]=vp3_extension_runtime_context_v2200($pdo,$user,$session,$input);
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_tick_v2200($pdo,$user,$namespace,$session,$runtimeId,$context,$relations));
    }
    if($action==='observe'){
        [$context,$relations]=vp3_extension_runtime_context_v2200($pdo,$user,$session,$input);
        $row=vp3_browser_runtime_row_v2200($pdo,(int)$user['id'],$namespace,$runtimeId);
        if(!$row)throw new RuntimeException('Browser Agent Runtime session was not found.');
        $observation=vp3_browser_runtime_observe_v2200($pdo,$user,$namespace,$row,$context,$relations);
        vp3_extension_runtime_json_v2200(200,$base+['observation'=>$observation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimeId,true)]);
    }
    if($action==='verify_navigation'){
        [$context,$relations]=vp3_extension_runtime_context_v2200($pdo,$user,$session,$input);
        $result=vp3_browser_runtime_verify_navigation_v2200($pdo,$user,$namespace,$runtimeId,max(0,(int)($input['action_id']??0)),$context,max(0,(int)($input['tab_id']??0)));
        vp3_extension_runtime_json_v2200(200,$base+$result);
    }
    if($action==='complete_checkpoint'){
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_complete_checkpoint_v2200($pdo,$user,$namespace,$runtimeId,max(0,(int)($input['action_id']??0))));
    }
    if(in_array($action,['pause','resume','cancel'],true)){
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_lifecycle_v2200($pdo,$user,$namespace,$runtimeId,$action));
    }
    if($action==='retry'){
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_retry_v2200($pdo,$user,$namespace,$runtimeId,max(0,(int)($input['action_id']??0))));
    }
    if($action==='skip'){
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_skip_v2200($pdo,$user,$namespace,$runtimeId,max(0,(int)($input['action_id']??0))));
    }
    if($action==='replan'){
        [$context,$relations]=vp3_extension_runtime_context_v2200($pdo,$user,$session,$input);
        vp3_extension_runtime_json_v2200(200,$base+vp3_browser_runtime_replan_v2200($pdo,$user,$namespace,$session,$runtimeId,$context,$relations));
    }
    if($action==='tab_seen'){
        $tabs=vp3_browser_runtime_tab_seen_v2200(
            $pdo,$user,$namespace,$runtimeId,max(0,(int)($input['tab_id']??0)),
            trim((string)($input['role']??'runtime')),trim((string)($input['target_type']??'')),trim((string)($input['target_id']??''))
        );
        vp3_extension_runtime_json_v2200(200,$base+['tabs'=>$tabs]);
    }
    if($action==='tab_closed'){
        $tabs=vp3_browser_runtime_tab_closed_v2200($pdo,$user,$namespace,$runtimeId,max(0,(int)($input['tab_id']??0)));
        vp3_extension_runtime_json_v2200(200,$base+['tabs'=>$tabs]);
    }

    vp3_extension_runtime_json_v2200(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Agent Runtime action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_runtime_json_v2200($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_runtime_json_v2200(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_runtime_json_v2200(422,['ok'=>false,'error'=>['code'=>'runtime_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Agent Runtime v22.00 failed: '.$e->getMessage());
    vp3_extension_runtime_json_v2200(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Agent Runtime is temporarily unavailable.']]);
}
