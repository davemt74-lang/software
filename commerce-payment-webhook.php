<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$pdo=db();if(!$pdo||!agent_commerce_schema_ready_v800($pdo)){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'commerce_schema_unavailable']);exit;}
$provider=strtolower(trim((string)($_GET['provider']??'')));if(!isset(agent_commerce_provider_registry_v800()[$provider])){http_response_code(404);exit;}
$stream=fopen('php://input','rb');$payload=$stream===false?'':(string)stream_get_contents($stream,524289);if(is_resource($stream))fclose($stream);$headers=[];foreach($_SERVER as $key=>$value)if(str_starts_with($key,'HTTP_'))$headers[strtolower(str_replace('_','-',substr($key,5)))]=(string)$value;
try{agent_commerce_process_webhook_v800($pdo,$provider,$payload,$headers);http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);}
catch(Throwable $e){error_log('VP3 commerce webhook pending ['.$provider.']['.get_class($e).'].');$status=in_array((int)$e->getCode(),[400,413,503],true)?(int)$e->getCode():500;http_response_code($status);if($status>=500)header('Retry-After: 10');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'processing_failed']);}
