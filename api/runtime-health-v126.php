<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_permission('admin.access');
$user=current_user();
if(!$user||!user_has_role('admin',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Admin account required.']);exit;}

try{
    $action=(string)($_GET['action']??$_POST['action']??'snapshot');
    if($action==='snapshot'){
        echo json_encode(['ok'=>true,'runtime'=>'phase6-v126','health'=>agent_runtime_v126_health_snapshot()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
    if(!verify_csrf()){http_response_code(419);throw new RuntimeException('Session expired.');}
    if($action==='self_test'){
        $result=agent_runtime_v126_self_test();http_response_code($result['passed']?200:500);echo json_encode(['ok'=>$result['passed'],'runtime'=>'phase6-v126','self_test'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='cleanup'){
        $result=agent_runtime_v126_cleanup();echo json_encode(['ok'=>true,'runtime'=>'phase6-v126','cleanup'=>$result,'health'=>agent_runtime_v126_health_snapshot()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    throw new RuntimeException('Unknown runtime health action.');
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace_id'=>agent_runtime_v125_trace_id()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
