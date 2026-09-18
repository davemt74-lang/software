<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$user=current_user();
if(!$user||!has_permission('users.manage',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Forbidden','request_id'=>vp3_annotated_request_id_v2100()]);exit;}
$pdo=db();
if(!$pdo){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Database unavailable','request_id'=>vp3_annotated_request_id_v2100()]);exit;}
try{
    echo json_encode(['ok'=>true,'request_id'=>vp3_annotated_request_id_v2100(),'health'=>vp3_annotated_health_v2100($pdo)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('Annotated health failed ['.vp3_annotated_request_id_v2100().']: '.$e->getMessage());
    http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Health check failed','request_id'=>vp3_annotated_request_id_v2100()]);
}
