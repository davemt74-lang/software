<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function homeserver_task_calendar_v243_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user)){
    homeserver_task_calendar_v243_json(['ok'=>false,'error'=>'Task and Calendar continuity is unavailable for this account.'],403);
}
$userId=(int)($user['id']??0);$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));

if($method==='GET'){
    $dataset=strtolower(trim((string)($_GET['dataset']??'tasks')));
    try{
        if($dataset==='tasks'){
            $q=is_scalar($_GET['q']??null)?trim((string)$_GET['q']):'';
            homeserver_task_calendar_v243_json(['ok'=>true,'continuity'=>homeserver_task_calendar_v243_unified_tasks($userId,$q,150)]);
        }
        if($dataset==='calendar'){
            $from=trim((string)($_GET['from']??gmdate('Y-m-d 00:00:00',time()-30*86400)));
            $to=trim((string)($_GET['to']??gmdate('Y-m-d 23:59:59',time()+370*86400)));
            homeserver_task_calendar_v243_json(['ok'=>true,'continuity'=>homeserver_task_calendar_v243_unified_calendar($userId,$from,$to)]);
        }
        homeserver_task_calendar_v243_json(['ok'=>false,'error'=>'Unknown continuity dataset.'],422);
    }catch(Throwable $e){
        homeserver_task_calendar_v243_json(['ok'=>false,'error'=>'Task and Calendar continuity could not be loaded.'],503);
    }
}

if($method!=='POST'){
    header('Allow: GET, POST');
    homeserver_task_calendar_v243_json(['ok'=>false,'error'=>'GET or POST required.'],405);
}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf))homeserver_task_calendar_v243_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
$dataset=strtolower(trim((string)($input['dataset']??'')));$action=strtolower(trim((string)($input['action']??'')));
$payload=is_array($input['payload']??null)?$input['payload']:[];
try{
    $result=homeserver_task_calendar_v243_request_homeserver($userId,$dataset,$action,$payload);
    homeserver_task_calendar_v243_json(['ok'=>true,'dataset'=>$dataset,'action'=>$action,'result'=>$result]);
}catch(Throwable $e){
    $message=trim($e->getMessage());
    $status=preg_match('/(?:changed after|mutation_id|authority|unavailable|already)/i',$message)?409:422;
    if($message===''||preg_match('/(?:sql|database|decrypt|token|bearer|curl)/i',$message)){$message='Task or Calendar action could not be completed.';$status=503;}
    homeserver_task_calendar_v243_json(['ok'=>false,'error'=>mb_strimwidth($message,0,500,'')],$status);
}
