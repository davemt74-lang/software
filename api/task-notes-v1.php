<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>8000)throw new RuntimeException('Request too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new RuntimeException('Invalid request.',422);
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to view your notes.',401);
    if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Refresh the page and try again.',419);
    $pdo=db();if(!$pdo)throw new RuntimeException('Database unavailable.',503);
    $agentId=$input['agent_id']??0;if(!is_int($agentId)||$agentId<0)throw new RuntimeException('Invalid Agent.',422);
    $agent=$agentId?vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId):null;
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $user=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
    if(!has_permission('account.access',$user)||!has_permission('chat.access',$user)||!knowledge_retrieval_v162_owner_session($user,$principal))throw new RuntimeException('Note access denied.',403);
    $ref=$input['note']??null;if(!is_array($ref))throw new RuntimeException('Choose a note.',422);
    if(($ref['kind']??'')==='task_list'){
        if(!vp3_task_lists_ready_v1($pdo))throw new RuntimeException('Run upgrade.php to enable lists.',409);
        if(!is_int($ref['id']??null)||$ref['id']<1)throw new RuntimeException('Invalid list.',422);
        $note=vp3_task_list_note_v1($pdo,$user,$principal,$ref['id']);
    }elseif(($ref['kind']??'')==='reminders'){
        if(!reminder_ready_v1($pdo))throw new RuntimeException('Run upgrade.php to enable reminders.',409);
        $zone=reminder_zone_v1($ref['timezone']??user_calendar_default_timezone_v1300($pdo,$user));$view=$ref['view']??'open';
        if(!is_string($view))throw new RuntimeException('Invalid reminder view.',422);
        $rows=reminder_list_rows_v1($pdo,(int)$user['id'],$view,$zone,reminder_now_v1());$items=[];
        foreach($rows as $row)$items[]=['id'=>(int)$row['id'],'title'=>$row['title'],'status'=>$row['list_state']??$row['status'],'done'=>in_array($row['list_state']??$row['status'],['done','dismissed','finished','cancelled'],true),'detail'=>reminder_list_label_v1($row)];
        $note=['kind'=>'reminders','id'=>0,'title'=>$view==='today'?'Today’s reminders':'Reminders','items'=>$items,'truncated'=>count($items)>=100,'view'=>$view,'timezone'=>$zone];
    }else throw new RuntimeException('Unknown note.',422);
    echo json_encode(['ok'=>true,'note'=>$note],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$code=$e instanceof JsonException?422:(int)$e->getCode();http_response_code(in_array($code,[401,403,404,405,409,413,419,422,503],true)?$code:500);echo json_encode(['ok'=>false,'error'=>$code>=400&&$code<500?$e->getMessage():'This note could not be loaded.']);}
