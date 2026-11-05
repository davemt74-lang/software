<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-chat-read-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_chat_read_api_json_v1(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user)||!has_permission('account.access',$user)){
    vp3_chat_read_api_json_v1(403,['ok'=>false,'error'=>'forbidden']);
}
$binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
if($binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding)){
    vp3_chat_read_api_json_v1(403,['ok'=>false,'error'=>'account_changed']);
}
$pdo=db();
if(!$pdo)vp3_chat_read_api_json_v1(503,['ok'=>false,'error'=>'database_unavailable']);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true))vp3_chat_read_api_json_v1(405,['ok'=>false,'error'=>'method_not_allowed']);
try{
    $input=$_GET;
    if($method==='POST'){
        $raw=(string)file_get_contents('php://input');
        if(strlen($raw)>16384)vp3_chat_read_api_json_v1(413,['ok'=>false,'error'=>'payload_too_large']);
        $input=$raw!==''?json_decode($raw,true,16,JSON_THROW_ON_ERROR):$_POST;
        if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('invalid_request');
        $csrf=$input['csrf_token']??null;
        if(!is_string($csrf)||!hash_equals(csrf_token(),$csrf))vp3_chat_read_api_json_v1(419,['ok'=>false,'error'=>'csrf']);
        if(($input['action']??null)!=='acknowledge')throw new InvalidArgumentException('invalid_action');
    }
    $agent=vp3_chat_read_id_v1($input['agent_id']??null,true);
    $conversation=vp3_chat_read_id_v1($input['conversation_id']??null);
    $ids=$input['message_ids']??null;
    if($method==='GET'){
        if(!is_string($ids))throw new InvalidArgumentException('invalid_message_ids');
        $ids=$ids===''?[]:explode(',',$ids);
    }
    $ids=vp3_chat_read_ids_v1($ids);
    $state=$method==='GET'?vp3_chat_read_state_v1($pdo,$user,$agent,$conversation,$ids)
        :vp3_chat_read_acknowledge_v1($pdo,$user,$agent,$conversation,$ids);
    vp3_chat_read_api_json_v1(200,['ok'=>true]+$state);
}catch(JsonException|InvalidArgumentException|DomainException $e){
    vp3_chat_read_api_json_v1(422,['ok'=>false,'error'=>$e instanceof JsonException?'invalid_request':$e->getMessage()]);
}catch(RuntimeException $e){
    $status=in_array($e->getCode(),[403,404,503],true)?$e->getCode():500;
    if($status===500)error_log('VP3 Chat read state: '.$e->getMessage());
    vp3_chat_read_api_json_v1($status,['ok'=>false,'error'=>$status===500?'chat_read_unavailable':$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Chat read state: '.$e->getMessage());
    vp3_chat_read_api_json_v1(500,['ok'=>false,'error'=>'chat_read_unavailable']);
}
