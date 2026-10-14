<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-research-save-v2230.php';
require_once dirname(__DIR__).'/includes/knowledge-actions-r8.php';
require_once dirname(__DIR__).'/includes/knowledge-actions-r9.php';
require_once dirname(__DIR__).'/includes/homeserver-workspace-sync-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');header('Access-Control-Allow-Methods: POST, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)throw new RuntimeException('Refresh request is too large.',413);
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('An Knowledge action request is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Cloud storage is unavailable.',503);
    $action=$input['action']??'options';if(!in_array($action,['options','suggest','prepare','get','decide','converse','validate_draft'],true))throw new InvalidArgumentException('Unknown Knowledge action.');
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));$homeDevice=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));
    $authenticate=static function()use($pdo,$auth,$homeDevice,$input,$action):array{
        if($homeDevice!==''){
            if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('Reconnect HomeServer.',401);
            $session=homeserver_https_v1300_authenticate(trim($m[1]),$homeDevice);
            $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
        }elseif($auth!==''){
            if(($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??'')!=='1')throw new RuntimeException('Update Browser Companion.',422);
            $session=vp3_extension_session_authenticate_v2001($pdo);
            if(!$session||!vp3_extension_session_has_capability_v2001($session,'agent.message')||!vp3_extension_session_has_capability_v2001($session,'team.chat.read')||($action==='decide'&&(!vp3_extension_session_has_capability_v2001($session,'knowledge.write')||!vp3_extension_session_has_capability_v2001($session,'task.propose'))))throw new RuntimeException('Browser Knowledge access is unavailable.',403);
            $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
        }else{
            require_permission('chat.access');$user=current_user();
            if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh your Chat session.',419);
        }
        if(!$user)throw new RuntimeException('The account is unavailable.',401);
        $user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
        capture_r5_permission($user,$action==='decide');return $user;
    };
    $user=$authenticate();
    $agent=array_key_exists('agent_id',$input)?vp3_agent_chat_resolve_agent_v380($pdo,$user,(int)$input['agent_id']):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,(int)($agent['id']??0));
    $conversation=(int)($input['conversation_id']??0);
    if($conversation>0){
        if(!vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$user['id'],$agent))throw new RuntimeException('Conversation unavailable for this lead.',403);
        $key='cloud:'.$conversation;
    }elseif($homeDevice!==''&&is_string($input['local_conversation']??null)&&preg_match('/^[a-zA-Z0-9:._-]{1,100}$/D',$input['local_conversation'])){
        $key='home:'.substr(hash('sha256',$homeDevice),0,32).':'.(int)($input['parent_agent_id']??0).':'.$input['local_conversation'];
    }else throw new RuntimeException('Open a saved conversation before choosing context.',409);
    $recheck=static function()use($authenticate,$user,$pdo,$input,$namespace,$conversation):void{
        $fresh=$authenticate();if((int)$fresh['id']!==(int)$user['id'])throw new RuntimeException('The connected account changed.',403);
        $freshAgent=array_key_exists('agent_id',$input)?vp3_agent_chat_resolve_agent_v380($pdo,$fresh,(int)$input['agent_id']):vp3_agent_chat_runtime_default_agent_v2160($pdo,$fresh);
        if(vp3_cognitive_agent_namespace_v500($pdo,$fresh,(int)($freshAgent['id']??0))!==$namespace)throw new RuntimeException('The selected lead changed.',403);
        if($conversation>0&&!vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$fresh['id'],$freshAgent))throw new RuntimeException('Conversation access changed.',403);
    };
    vp3_agent_memory_scope_set_current_v410((int)$user['id'],(int)($agent['id']??0));
    if($action==='options')$result=knowledge_r8_options($pdo,$user,$key,$namespace);
    elseif($action==='converse')$result=['conversation_draft'=>knowledge_r9_chat($pdo,$user,$key,$namespace,$input,$recheck)];
    elseif($action==='validate_draft'){
        $existing=null;
        if(isset($input['request_id'])&&table_exists('chat_knowledge_actions_r8')){
            $id=chat_research_uuid_v1($input['request_id']);$q=$pdo->prepare('SELECT id FROM chat_knowledge_actions_r8 WHERE id=?');$q->execute([$id]);
            if($q->fetchColumn())$existing=knowledge_r8_row($pdo,$user,$key,$namespace,$id);
        }
        $result=$existing?['action'=>knowledge_r8_view($pdo,$user,$key,$namespace,$existing)]:['draft'=>knowledge_r9_restore($pdo,$user,$key,$namespace,$input)];
    }
    elseif($action==='suggest')$result=['suggestion'=>knowledge_r8_suggest($pdo,$user,$key,$namespace,$input,$recheck)];
    elseif($action==='prepare')$result=['action'=>knowledge_r8_prepare($pdo,$user,$key,$namespace,$input,$recheck)];
    elseif($action==='decide')$result=['action'=>knowledge_r8_decide($pdo,$user,$key,$namespace,$input,$recheck)];
    else $result=['action'=>knowledge_r8_view($pdo,$user,$key,$namespace,knowledge_r8_row($pdo,$user,$key,$namespace,(string)($input['id']??'')))];
    $result['contract']=VP3_KNOWLEDGE_ACTIONS_R8;$result['context_key']=$key;$result['cloud_origin']=knowledge_r6_origin();$recheck();
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:(int)$e->getCode();if(!in_array($status,[401,403,405,409,410,413,419,422],true))$status=503;
    http_response_code($status);echo json_encode(['ok'=>false,'error'=>$status===503?'Knowledge action review is temporarily unavailable.':$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
