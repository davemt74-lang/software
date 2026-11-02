<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)throw new RuntimeException('Request too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Invalid request.');
    $u=current_user();if(!$u)throw new RuntimeException('Sign in to use participant updates.',401);
    if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Refresh the page and try again.',419);
    $pdo=db();if(!$pdo)throw new RuntimeException('Database unavailable.',503);
    $agentId=$input['agent_id']??0;if(!is_int($agentId)||$agentId<0)throw new InvalidArgumentException('Invalid Agent.');
    $agent=$agentId?vp3_agent_chat_resolve_agent_v380($pdo,$u,$agentId):null;$p=vp3_agent_chat_principal_v380($u,$agent);
    $u=vp3_capability_live_user_v1($pdo,$u,$p,[],null);vp3_task_list_authorize_v1($pdo,$u,$p,0);
    if(!vp3_participant_updates_ready_v1($pdo))throw new RuntimeException('Run upgrade.php to enable participant updates.',409);
    $action=$input['action']??'';$id=$input['participant_id']??0;if(!is_int($id)||$id<0)throw new InvalidArgumentException('Invalid participant.');
    if($action==='settings'){
        $settings=vp3_participant_settings_v1($pdo,$u);$profiles=studio_participants_list($pdo,$u);
        $q=$pdo->prepare('SELECT participant_id,resource_kind AS kind,resource_id AS id,access_mode AS access FROM agent_participant_grants_v1 WHERE owner_user_id=? AND agent_scope_id=? ORDER BY participant_id,resource_kind,resource_id');$q->execute([(int)$u['id'],vp3_task_list_agent_v1($p)]);$grants=$q->fetchAll(PDO::FETCH_ASSOC);
        $result=['settings'=>$settings,'profiles'=>$profiles,'catalog'=>vp3_participant_resources_v1($pdo,$u,$p,false),'grants'=>$grants,'presence'=>vp3_participant_presence_rows_v1($pdo,$u)];
    }elseif($action==='configure')$result=['settings'=>vp3_participant_configure_v1($pdo,$u,$p,$input)];
    elseif($action==='heartbeat'){vp3_participant_heartbeat_v1($pdo,$u,$p,$input);$result=[];}
    elseif($action==='presence')$result=['presence'=>vp3_participant_presence_rows_v1($pdo,$u)];
    elseif($action==='offer')$result=['offer'=>vp3_participant_offer_v1($pdo,$u,$id)];
    elseif($action==='ack_offer'){vp3_participant_offer_v1($pdo,$u,$id,$input);$result=[];}
    elseif($action==='show')$result=['updates'=>vp3_participant_with_scope_v1($pdo,$u,$p,$id,false,fn()=>vp3_participant_resources_v1($pdo,$u,$p))];
    else throw new InvalidArgumentException('Unknown participant action.');
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$code=$e instanceof JsonException||$e instanceof InvalidArgumentException?422:(int)$e->getCode();http_response_code(in_array($code,[401,403,405,409,413,419,422,503],true)?$code:500);echo json_encode(['ok'=>false,'error'=>$code>=400&&$code<500?$e->getMessage():'Participant updates could not be loaded.']);}
