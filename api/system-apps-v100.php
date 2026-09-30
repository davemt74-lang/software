<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/system-apps-v120.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_login();
$user=current_user();$pdo=db();
if(!$pdo||!$user){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Apps are unavailable.']);exit;}
try{
    if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!verify_csrf()){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']);exit;}
        $input=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($input))$input=$_POST;
        $action=(string)($input['action']??'');
        if($action==='acquire'){
            $app=vp3_system_apps_acquire_v100((int)$user['id'],(string)($input['app_key']??''),'self_service',null,$pdo);
            echo json_encode(['ok'=>true,'app'=>$app,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='install'){
            $result=vp3_system_apps_install_v110((int)$user['id'],(string)($input['app_key']??''),null,$pdo);
            echo json_encode(['ok'=>true,'installation'=>$result,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='reconcile'){
            $result=vp3_system_apps_reconcile_all_v130((int)$user['id'],null,$pdo);
            echo json_encode(['ok'=>true,'reconciliation'=>$result,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='hosting.bind'){
            $result=vp3_system_apps_hosting_bind_v120((int)$user['id'],(string)($input['app_key']??''),(int)($input['site_id']??0),null,$pdo);
            echo json_encode(['ok'=>true,'binding'=>$result,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='hosting.unbind'){
            $result=vp3_system_apps_hosting_unbind_v120((int)$user['id'],(string)($input['app_key']??''),null,$pdo);
            echo json_encode(['ok'=>true,'binding'=>$result,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo)],JSON_UNESCAPED_SLASHES);exit;
        }
        http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Apps action.']);exit;
    }
    echo json_encode(['ok'=>true,'catalog'=>vp3_system_apps_catalog_v120($user,$pdo),'capabilities'=>vp3_system_apps_capability_v130()],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,500)],JSON_UNESCAPED_SLASHES);
}
