<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
$user=current_user();$userId=(int)($user['id']??0);$pdo=db();
if($userId<1||!$pdo){http_response_code($userId<1?401:503);echo json_encode(['ok'=>false,'error'=>$userId<1?'Authentication required.':'Database unavailable.']);exit;}
try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
    echo json_encode(['ok'=>true,'governed_operations'=>tracky_v280_fgo_report($pdo,$userId),'capability'=>tracky_v280_fgo_public_capability(),'csrf_token'=>csrf_token()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
  }
  if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']);exit;}
    $result=tracky_v280_fgo_create_request($pdo,$userId,$_POST);
    echo json_encode(['ok'=>true,'result'=>$result,'governed_operations'=>tracky_v280_fgo_report($pdo,$userId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
  }
  http_response_code(405);echo json_encode(['ok'=>false,'error'=>'GET or POST required.']);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>mb_strimwidth(trim($e->getMessage())?:'Governed federation operation failed.',0,500,'')],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
