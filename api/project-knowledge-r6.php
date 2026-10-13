<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/project-knowledge-r6.php';
require_once dirname(__DIR__).'/includes/homeserver-workspace-sync-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');header('Access-Control-Allow-Methods: POST, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)throw new RuntimeException('Annotation request is too large.',413);
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('An annotation request is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Cloud storage is unavailable.',503);
    $action=$input['action']??'options';if(!in_array($action,['options','set_context','resolve'],true))throw new InvalidArgumentException('Unknown annotation operation.');
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));$homeDevice=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));
    $authenticate=static function()use($pdo,$auth,$homeDevice,$input,$action):array{
        if($homeDevice!==''){
            if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('Reconnect HomeServer.',401);
            $session=homeserver_https_v1300_authenticate(trim($m[1]),$homeDevice);
            $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
        }elseif($auth!==''){
            if(($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??'')!=='1')throw new RuntimeException('Update Browser Companion.',422);
            $session=vp3_extension_session_authenticate_v2001($pdo);
            if(!$session||!vp3_extension_session_has_capability_v2001($session,'agent.message')||($action==='edit'&&!vp3_extension_session_has_capability_v2001($session,'knowledge.write')))throw new RuntimeException('Browser Knowledge access is unavailable.',403);
            $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
        }else{
            require_permission('chat.access');$user=current_user();
            if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh your Chat session.',419);
        }
        if(!$user)throw new RuntimeException('The account is unavailable.',401);
        $user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
        capture_r5_permission($user,$action==='edit');return $user;
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
    if($action==='set_context')$result=['state'=>knowledge_r6_set($pdo,$user,$key,$namespace,$input,$recheck)];
    elseif($action==='resolve')$result=knowledge_r6_resolve($pdo,$user,$key,$namespace,$input);
    else{
        $state=knowledge_r6_state($pdo,$user,$key,$namespace,false);
        $scope=$state['scope'];$f=['q'=>is_string($input['q']??null)?$input['q']:''];
        if($scope['mode']==='project')$f['project_id']=$scope['project_id'];
        if($scope['mode']==='folder')$f['folder_id']=$scope['folder_id'];
        $listing=capture_r5_list($pdo,$user, $state['available']?$f:[]);
        $items=$state['available']&&$scope['mode']!=='off'?array_map('knowledge_r6_annotation',array_values(array_filter($listing['items'],static fn($i)=>!$i['knowledge_conflict']))):[];
        $reports=$state['available']?knowledge_r6_reports($pdo,$user,$namespace,$scope,$f['q']):[];
        $result=['state'=>$state,'projects'=>$listing['projects'],'folders'=>$listing['folders'],'items'=>$items,'reports'=>array_slice($reports,0,25)];
    }
    $result['contract']=VP3_PROJECT_KNOWLEDGE_R6;$result['cloud_origin']=knowledge_r6_origin();$recheck();
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:(int)$e->getCode();if(!in_array($status,[401,403,405,409,410,413,419,422],true))$status=503;
    http_response_code($status);echo json_encode(['ok'=>false,'error'=>$status===503?'Annotation Knowledge is temporarily unavailable.':$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
