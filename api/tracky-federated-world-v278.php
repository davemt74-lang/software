<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user=current_user();
$userId=(int)($user['id']??0);
$pdo=db();
if($userId<1||!$pdo){
    http_response_code($userId<1?401:503);
    echo json_encode(['ok'=>false,'error'=>$userId<1?'Authentication required.':'Database unavailable.']);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'GET required.']);
    exit;
}
if(!tracky_cloud_v270_schema_ready($pdo)){
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Tracky Cloud requires a database upgrade.']);
    exit;
}

try{
    $site=trim((string)($_GET['site']??''));
    echo json_encode([
      'ok'=>true,
      'world'=>tracky_v278_world_report($pdo,$userId,$site!==''?$site:null),
      'capability'=>tracky_v278_world_public_capability(),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode([
      'ok'=>false,
      'error'=>mb_strimwidth(trim($e->getMessage())?:'Tracky federated world could not be read.',0,500,'')
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
