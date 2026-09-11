<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-scheduling-connector-v620.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$user=current_user();$userId=(int)($user['id']??0);
if($userId<1){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['ok'=>true,'connector'=>homeserver_scheduling_v620_connector_status($userId)],JSON_UNESCAPED_SLASHES);exit;}
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Unsupported method.']);exit;}
    if(!verify_csrf()){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired.']);exit;}
    $action=trim((string)($_POST['action']??'provision'));
    if(!in_array($action,['provision','rotate'],true)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported connector action.']);exit;}
    $connector=homeserver_scheduling_v620_provision($userId,$action==='rotate');
    echo json_encode(['ok'=>true,'connector'=>$connector],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Scheduling connector could not be provisioned.'],JSON_UNESCAPED_SLASHES);}
