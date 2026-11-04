<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_catch_up_api_json_v1(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    header('Allow: GET');
    vp3_catch_up_api_json_v1(405,['ok'=>false,'error'=>'method_not_allowed']);
}
$user=current_user();
if(!$user||empty($user['is_active'])||!has_permission('chat.access',$user)){
    vp3_catch_up_api_json_v1(403,['ok'=>false,'error'=>'forbidden']);
}
$scope=$_GET['scope']??'all';
$rawAgent=$_GET['agent_id']??'0';
if(!in_array($scope,['all','office','commerce'],true)||!is_string($rawAgent)||
    !preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',$rawAgent)){
    vp3_catch_up_api_json_v1(422,['ok'=>false,'error'=>'invalid_brief_request']);
}
$pdo=db();
if(!$pdo)vp3_catch_up_api_json_v1(503,['ok'=>false,'error'=>'brief_unavailable']);
$agentId=(int)$rawAgent;
try{
    vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
}catch(PDOException $e){
    vp3_catch_up_api_json_v1(503,['ok'=>false,'error'=>'brief_unavailable']);
}catch(RuntimeException $e){
    vp3_catch_up_api_json_v1(403,['ok'=>false,'error'=>'agent_unavailable']);
}
try{
    vp3_catch_up_api_json_v1(200,['ok'=>true,'brief'=>vp3_agent_catch_up_v1($pdo,$user,$scope,$agentId)]);
}catch(Throwable $e){
    if($e->getCode()===403)vp3_catch_up_api_json_v1(403,['ok'=>false,'error'=>'forbidden']);
    error_log('VP3 Catch me up: '.$e->getMessage());
    vp3_catch_up_api_json_v1(503,['ok'=>false,'error'=>'brief_unavailable']);
}
