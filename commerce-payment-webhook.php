<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$pdo=db();if(!$pdo||!agent_commerce_schema_ready_v800($pdo)){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'commerce_schema_unavailable']);exit;}
$provider=strtolower(trim((string)($_GET['provider']??'')));if(!isset(agent_commerce_provider_registry_v800()[$provider])){http_response_code(404);exit;}
$payload=(string)file_get_contents('php://input');$headers=[];foreach($_SERVER as $key=>$value)if(str_starts_with($key,'HTTP_'))$headers[strtolower(str_replace('_','-',substr($key,5)))]=(string)$value;
try{agent_commerce_process_webhook_v800($pdo,$provider,$payload,$headers);http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);}
catch(Throwable $e){error_log('VP3 commerce webhook failed ['.$provider.']: '.$e->getMessage());http_response_code(str_contains(strtolower($e->getMessage()),'signature')?400:500);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'processing_failed']);}
