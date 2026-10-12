<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-research-save-v2230.php';
require_once dirname(__DIR__).'/includes/chat-browser-research-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');
function chat_research_response_v1(int $status,array $result): never {http_response_code($status);echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input',false,null,0,16385);
    if(strlen($raw)>16384)throw new RuntimeException('Research request is too large.',413);
    $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Research request must be a JSON object.');
    $pdo=db();if(!$pdo||!table_exists('chat_browser_research_requests_v1')||!vp3_browser_research_schema_ready_v2230($pdo))throw new RuntimeException('Run the Cloud upgrade to enable Chat research.',409);
    $device='';$extension=null;$home=false;
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
    if(trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''))!==''){
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('HomeServer authentication required.',401);
        try{$session=homeserver_https_v1300_authenticate(trim($m[1]),trim((string)$_SERVER['HTTP_X_HOMESERVER_DEVICE']));}
        catch(Throwable $e){throw new RuntimeException('Reconnect HomeServer to Cloud.',401);}
        $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);$home=true;
        $input['context_key']='home:'.(string)$session['device_id'].':'.(string)($input['local_conversation']??'');
        $input['conversation_id']=0;
    }elseif($auth!==''){
        if(($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??'')!=='1')throw new RuntimeException('Update the Chrome extension.',422);
        $extension=vp3_extension_session_authenticate_v2001($pdo);
        if(!$extension)throw new RuntimeException('Reconnect Chrome to Cloud.',401);
        if(!vp3_extension_session_has_capability_v2001($extension,'agent.message')||!vp3_extension_session_has_capability_v2001($extension,'team.chat.read'))throw new RuntimeException('Chrome research permissions are unavailable.',403);
        $user=vp3_extension_user_for_permission_v2001($pdo,(int)$extension['user_id']);
        $device=(string)($extension['device_id']??$extension['installation_id']??$extension['id']??'');
    }else{
        require_permission('chat.access');$user=current_user();
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh your Chat session.',419);
        $input['context_key']='cloud:'.max(0,(int)($input['conversation_id']??0));
    }
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent Chat permission is unavailable.',403);
    $user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    $agent=array_key_exists('agent_id',$input)?vp3_agent_chat_resolve_agent_v380($pdo,$user,(int)$input['agent_id']):vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,(int)($agent['id']??0));
    $action=(string)($input['action']??'list');$result=[];
    if($action==='list'||$action==='worker.list'){
        if($action==='worker.list'&&!$extension)throw new RuntimeException('Chrome worker authentication required.',403);
        $filter=$action==='worker.list'?" AND status IN ('waiting_chrome','running','attention')":(!$extension?' AND context_key=? AND agent_namespace=?':'');
        $params=[(int)$user['id']];if(!$extension)$params=array_merge($params,[$input['context_key'],$namespace]);
        $q=$pdo->prepare('SELECT * FROM chat_browser_research_requests_v1 WHERE owner_user_id=?'.$filter.' ORDER BY created_at DESC,id DESC LIMIT 20');$q->execute($params);$requests=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if($action==='worker.list'&&!in_array($row['status'],['waiting_chrome','running','attention'],true))continue;
            if($home&&$row['context_key']!==$input['context_key'])continue;
            if(!$home&&!$extension&&$row['context_key']!==$input['context_key'])continue;
            try{$requests[]=chat_research_view_v1($pdo,$user,$row);}catch(RuntimeException $e){continue;}
        }
        $runtimes=[];
        foreach(vp3_browser_runtime_list_v2200($pdo,$user,$namespace,20) as $r){
            try{$runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,(string)$r['runtime_id']);
                $runtimes[]=['id'=>$r['runtime_id'],'title'=>$r['title']??'Chrome research','domains'=>vp3_browser_research_allowed_domains_v2230($pdo,$runtime['_multisite'])];
            }catch(Throwable $e){continue;}
        }
        $result=['requests'=>$requests,'runtimes'=>$runtimes,'projects'=>array_values(array_filter(vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false),static fn(array $p):bool=>vp3_research_role_at_least_v2060((string)($p['role']??''),'researcher')))];
    }elseif($action==='prepare'){
        if($extension)throw new RuntimeException('Prepare the source plan on the Chat canvas.',403);
        $result=['request'=>chat_research_prepare_v1($pdo,$user,$namespace,$agent,$input)];
    }else{
        $row=chat_research_row_v1($pdo,(int)$user['id'],(string)($input['id']??''));
        if(!$extension&&($row['context_key']!==$input['context_key']||$row['agent_namespace']!==$namespace))throw new RuntimeException('Open the original research conversation and lead.',403);
        if($action==='get')$result=['request'=>chat_research_view_v1($pdo,$user,$row)];
        elseif($action==='start'){
            if($extension)throw new RuntimeException('Approve this exact plan on the Chat canvas.',403);
            $result=['request'=>chat_research_start_v1($pdo,$user,$input)];
        }elseif($action==='worker.claim'||$action==='worker.step'){
            if(!$extension)throw new RuntimeException('Chrome worker authentication required.',403);
            $result=$action==='worker.claim'?chat_research_claim_v1($pdo,$user,$device,$input):chat_research_step_v1($pdo,$user,$device,$input);
        }elseif($action==='cancel'){
            if(($input['confirmed']??null)!==true)throw new InvalidArgumentException('Confirm stopping this research run.');
            $pdo->prepare("UPDATE chat_browser_research_requests_v1 SET status='cancelled',lease_token=NULL,lease_until=NULL WHERE id=? AND owner_user_id=?")->execute([$row['id'],(int)$user['id']]);
            if($row['mission_id'])vp3_browser_research_cancel_v2230($pdo,$user,$row['mission_id']);
            $result=['request'=>chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$row['id']))];
        }elseif($action==='save'){
            if(($input['confirmed']??null)!==true||$row['status']!=='complete')throw new InvalidArgumentException('Review completed research before saving the draft.');
            if($extension&&!vp3_extension_session_has_capability_v2001($extension,'knowledge.write'))throw new RuntimeException('Research write permission required.',403);
            chat_research_view_v1($pdo,$user,$row);
            vp3_browser_research_save_v2230($pdo,$user,$row['mission_id'],(string)($input['project_id']??''));
            $result=['request'=>chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$row['id']))];
        }else throw new InvalidArgumentException('Unsupported research operation.');
    }
    chat_research_response_v1(200,['ok'=>true,'contract'=>'vp3.chat-browser-research.v1']+$result);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:(int)$e->getCode();
    if(!in_array($status,[401,403,404,405,409,413,419,422],true))$status=503;
    chat_research_response_v1($status,['ok'=>false,'error'=>$status===503?'Chat research is temporarily unavailable.':$e->getMessage()]);
}
