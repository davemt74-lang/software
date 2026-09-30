<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/system-apps-v100.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_login();
$user=current_user();$pdo=db();
if(!$pdo||!$user){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Apps are unavailable.']);exit;}
try{
    if(!vp3_system_apps_schema_ready_v100($pdo))vp3_system_apps_ensure_schema_v100($pdo);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!verify_csrf()){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']);exit;}
        $input=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($input))$input=$_POST;
        $action=(string)($input['action']??'');
        if($action!=='acquire'){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Apps action.']);exit;}
        $app=vp3_system_apps_acquire_v100((int)$user['id'],(string)($input['app_key']??''),'self_service',null,$pdo);
        echo json_encode(['ok'=>true,'app'=>$app,'catalog'=>vp3_system_apps_catalog_v100($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['ok'=>true,'catalog'=>vp3_system_apps_catalog_v100($user,$pdo),'capabilities'=>vp3_system_apps_capability_v100()],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,500)],JSON_UNESCAPED_SLASHES);
}
