<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);header('Allow: POST');exit;
}
if(!billing_schema_ready()){
    http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'billing_schema_unavailable']);exit;
}
$payload=(string)file_get_contents('php://input');
$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
if(!billing_stripe_verify_webhook($payload,$signature)){
    http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'invalid_signature']);exit;
}
$event=json_decode($payload,true);
if(!is_array($event)){
    http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'invalid_json']);exit;
}
try{
    $result=billing_process_stripe_event($event,$payload);
    http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 Stripe webhook failed: '.$e->getMessage());
    http_response_code(500);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'processing_failed']);
}
