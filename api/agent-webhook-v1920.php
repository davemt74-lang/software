<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-event-infrastructure-v1920.php';
require_once dirname(__DIR__).'/includes/agent-event-routes-v1920.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Webhook request rejected.']);exit;}
$source=strtolower(agent_event_text_v1920($_GET['source']??'',80));
if($source===''){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Webhook request rejected.']);exit;}
$raw=(string)file_get_contents('php://input');if(strlen($raw)>VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Webhook request rejected.']);exit;}
$headers=[];
if(function_exists('getallheaders')){foreach((array)getallheaders() as $k=>$v)$headers[strtolower((string)$k)]=(string)$v;}
else{foreach($_SERVER as $k=>$v){if(str_starts_with((string)$k,'HTTP_'))$headers[strtolower(str_replace('_','-',substr((string)$k,5)))]=(string)$v;}}
$pdo=db();if(!$pdo||!agent_event_schema_ready_v1920($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Webhook service is unavailable.']);exit;}
try{
    $result=agent_event_accept_webhook_v1920($pdo,$source,$raw,$headers);$event=(array)($result['event']??[]);http_response_code(202);
    echo json_encode(['ok'=>true,'accepted'=>true,'duplicate'=>!empty($result['duplicate']),'event_uuid'=>(string)($event['event_uuid']??''),'processing_status'=>(string)($event['processing_status']??'accepted'),'build'=>VP3_AGENT_EVENT_INFRA_V1920],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Webhook request rejected.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
