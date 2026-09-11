<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-scheduling-connector-v620.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
try{
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
    $userId=homeserver_scheduling_v620_authenticate(trim($m[1]));
    if(!$userId){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Invalid scheduling connector credential.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=[];
    $operation=trim((string)($input['operation']??''));$args=is_array($input['arguments']??null)?$input['arguments']:[];$key=trim((string)($input['idempotency_key']??''));
    $result=homeserver_scheduling_v620_execute($userId,$operation,$args,$key);
    echo json_encode(['ok'=>true,'version'=>'v6.20','result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,300)]);}
catch(RuntimeException $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,300)]);}
catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Scheduling connector request failed.']);}
