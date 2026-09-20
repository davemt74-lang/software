<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

const VP3_EXTENSION_AGENT_WORKSPACE_V2160='extension-agent-workspace-v2160-20260920';

function vp3_extension_agent_json_v2160(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_EXTENSION_AGENT_WORKSPACE_V2160]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_agent_input_v2160(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)vp3_extension_agent_json_v2160(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Agent request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_agent_json_v2160(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_agent_user_v2160(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);$user=$stmt->fetch();
    if(!is_array($user)||empty($user['is_active']))return null;
    $user['roles']=user_account_types_for_user_id($userId,(string)($user['role']??''));
    return $user;
}

function vp3_extension_agent_conversation_v2160(PDO $pdo,int $conversationId,int $userId,?array $agent): ?array
{
    return vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent);
}

function vp3_extension_agent_message_v2160(array $row): array
{
    return [
        'id'=>max(0,(int)($row['id']??0)),
        'role'=>in_array((string)($row['role']??''),['user','assistant'],true)?(string)$row['role']:'assistant',
        'message'=>mb_strimwidth((string)($row['message']??''),0,24000,'…'),
        'created_at'=>(string)($row['created_at']??''),
    ];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_agent_json_v2160(204);
if($method!=='POST')vp3_extension_agent_json_v2160(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_agent_json_v2160(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo||!table_exists('chat_conversations')||!user_agent_system_schema_ready_v236($pdo)){
        vp3_extension_agent_json_v2160(503,['ok'=>false,'error'=>['code'=>'chat_unavailable','message'=>'Agent Chat storage is unavailable. Run the VP3 database upgrade.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_agent_json_v2160(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_agent_json_v2160(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection cannot use Agent Chat.']]);
    }
    $user=vp3_extension_agent_user_v2160($pdo,(int)$session['user_id']);
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_agent_json_v2160(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Agent Chat access is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_agent_input_v2160();
    $action=trim((string)($input['action']??'list'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $activeAgent=$requestedAgentId>0
        ?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId)
        :vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $principal=vp3_agent_chat_principal_v380($user,$activeAgent);
    $userId=(int)$user['id'];

    if($action==='list'){
        [$scope,$params]=vp3_agent_chat_scope_sql_v380($activeAgent,'c');
        $stmt=$pdo->prepare("SELECT c.id,c.title,c.created_at,c.updated_at,COALESCE(MAX(m.id),0) latest_message_id
          FROM chat_conversations c
          LEFT JOIN chat_messages m ON m.conversation_id=c.id
          WHERE c.user_id=? AND {$scope}
          GROUP BY c.id
          ORDER BY latest_message_id DESC,c.updated_at DESC,c.id DESC
          LIMIT 30");
        $stmt->execute(array_merge([$userId],$params));
        $conversations=[];
        foreach($stmt->fetchAll()?:[] as $row)$conversations[]=[
            'id'=>(int)$row['id'],
            'title'=>mb_strimwidth((string)($row['title']??'New chat'),0,160,'…'),
            'updated_at'=>(string)($row['updated_at']??''),
            'latest_message_id'=>(int)($row['latest_message_id']??0),
        ];
        vp3_extension_agent_json_v2160(200,[
            'ok'=>true,'conversations'=>$conversations,
            'agent'=>['id'=>(int)$principal['agent_id'],'name'=>(string)$principal['display_name']],
        ]);
    }

    if($action==='load'){
        $conversationId=max(0,(int)($input['conversation_id']??0));
        $conversation=vp3_extension_agent_conversation_v2160($pdo,$conversationId,$userId,$activeAgent);
        if(!$conversation)vp3_extension_agent_json_v2160(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Conversation not found for this Agent.']]);
        $stmt=$pdo->prepare('SELECT id,role,message,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 80');
        $stmt->execute([$conversationId]);
        $messages=array_reverse(array_map('vp3_extension_agent_message_v2160',$stmt->fetchAll()?:[]));
        vp3_extension_agent_json_v2160(200,[
            'ok'=>true,
            'conversation'=>['id'=>$conversationId,'title'=>mb_strimwidth((string)($conversation['title']??'Chat'),0,160,'…')],
            'messages'=>$messages,
            'agent'=>['id'=>(int)$principal['agent_id'],'name'=>(string)$principal['display_name']],
        ]);
    }

    if($action==='messages_after'){
        $conversationId=max(0,(int)($input['conversation_id']??0));
        if(!vp3_extension_agent_conversation_v2160($pdo,$conversationId,$userId,$activeAgent)){
            vp3_extension_agent_json_v2160(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Conversation not found for this Agent.']]);
        }
        $afterId=max(0,(int)($input['after_id']??0));
        $stmt=$pdo->prepare('SELECT id,role,message,created_at FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 80');
        $stmt->execute([$conversationId,$afterId]);
        $messages=array_map('vp3_extension_agent_message_v2160',$stmt->fetchAll()?:[]);
        vp3_extension_agent_json_v2160(200,['ok'=>true,'conversation_id'=>$conversationId,'messages'=>$messages]);
    }

    if($action==='send'){
        $message=trim((string)($input['message']??''));
        if($message==='')vp3_extension_agent_json_v2160(422,['ok'=>false,'error'=>['code'=>'message_required','message'=>'Enter a message.']]);
        if(mb_strlen($message)>6000)vp3_extension_agent_json_v2160(422,['ok'=>false,'error'=>['code'=>'message_too_long','message'=>'That message is too long.']]);
        $runtimeInput=[
            'message'=>$message,
            'conversation_id'=>max(0,(int)($input['conversation_id']??0)),
            'input_mode'=>'text',
            'knowledge_scope'=>$input['knowledge_scope']??null,
            'agent_context'=>is_array($input['agent_context']??null)?$input['agent_context']:[],
        ];
        $brainAllowed=personal_capability_has_v242('agent_brain.access',$user);
        $result=vp3_agent_chat_send_v2160($pdo,$user,$activeAgent,$principal,$runtimeInput,$brainAllowed);
        // Browser workspace consumes the same canonical result, but does not
        // receive server-internal Agent context_json or credentials.
        vp3_extension_agent_json_v2160(200,$result);
    }

    vp3_extension_agent_json_v2160(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Agent action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_agent_json_v2160($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_agent_json_v2160(422,['ok'=>false,'error'=>['code'=>'agent_error','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Agent Workspace v21.60 failed: '.$e->getMessage());
    vp3_extension_agent_json_v2160(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Agent Workspace is temporarily unavailable.']]);
}
