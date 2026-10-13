<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-capture-routing-r4.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');
function capture_r4_json(int $status,array $result): never {http_response_code($status);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')capture_r4_json(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
try{
    if(($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??'')!=='1')throw new RuntimeException('Unsupported extension contract.',422);
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)throw new RuntimeException('The annotation request is too large.',413);
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new RuntimeException('An annotation request is required.',422);
    $pdo=db();if(!$pdo)throw new RuntimeException('Cloud storage is unavailable.',503);
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)throw new RuntimeException('Reconnect Browser Companion.',401);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([(int)$session['user_id']]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user)throw new RuntimeException('The connected account is unavailable.',401);
    $user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    capture_r4_permission($session,$user);
    $agentId=$input['agent_id']??null;
    if($agentId!==null&&(!is_int($agentId)||$agentId<0))throw new RuntimeException('Choose an available Agent.',422);
    // Explicit 0 is the system namespace. Omitting the Agent selects the existing default.
    $agent=$agentId===null?vp3_agent_chat_runtime_default_agent_v2160($pdo,$user):vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
    $action=(string)($input['action']??'options');
    if($action==='options')$result=capture_r4_options($pdo,$session,$user,$agent);
    elseif($action==='send'){
        $recheck=static function()use($pdo,$user,$agent,$input):void{
            $current=vp3_extension_session_authenticate_v2001($pdo);
            if(!$current)throw new RuntimeException('The browser connection was revoked.',403);
            $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([(int)$user['id']]);$owner=$q->fetch(PDO::FETCH_ASSOC);
            if(!$owner)throw new RuntimeException('The connected owner is unavailable.',403);
            $owner['roles']=user_account_types_for_user_id((int)$owner['id'],(string)($owner['role']??''));
            capture_r4_permission($current,$owner);
            vp3_agent_chat_resolve_agent_v380($pdo,$owner,vp3_agent_chat_agent_id_v380($agent));
            if(!empty($input['save_knowledge'])&&!capture_r4_knowledge_permission($current,$owner))throw new RuntimeException('Knowledge access changed.',403);
            if(($input['folder_id']??0)>0&&!personal_knowledge_folder($pdo,$owner,(int)$input['folder_id']))throw new RuntimeException('Folder access changed.',403);
            if(!empty($input['project_id'])){
                if(!vp3_extension_session_has_capability_v2001($current,'knowledge.write'))throw new RuntimeException('Research assignment access changed.',403);
                vp3_research_project_require_v2060($pdo,(string)$input['project_id'],(int)$owner['id'],'researcher');
            }
        };
        $result=capture_r4_send($pdo,$session,$user,$agent,$input,$recheck);
    }
    else throw new RuntimeException('Unknown annotation action.',422);
    capture_r4_json(200,['ok'=>true]+$result);
}catch(VP3ExtensionSecurityExceptionV2001 $e){capture_r4_json($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);}
catch(VP3BrowserShareExceptionV2010 $e){capture_r4_json($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);}
catch(RuntimeException $e){$status=in_array($e->getCode(),[401,403,409,410,413,422,503],true)?$e->getCode():422;capture_r4_json($status,['ok'=>false,'error'=>['code'=>'capture_unavailable','message'=>$e->getMessage()]]);}
catch(Throwable $e){error_log('Capture routing failed: '.get_class($e));capture_r4_json(503,['ok'=>false,'error'=>['code'=>'capture_unavailable','message'=>'The annotation could not be saved. Retry the same reviewed capture.']]);}
