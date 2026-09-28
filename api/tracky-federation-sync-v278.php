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

echo json_encode([
  'ok'=>true,
  'sync'=>tracky_v278_sync_report($pdo,$userId),
  'capability'=>tracky_v278_sync_public_capability(),
  'reconciliation_capability'=>tracky_v278_reconciliation_public_capability(),
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
