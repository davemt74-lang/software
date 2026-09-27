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
    if(!empty($_GET['audit'])){
        echo json_encode([
          'ok'=>true,
          'audit'=>tracky_v278_query_audit($pdo,$userId,(int)($_GET['limit']??50)),
          'capability'=>tracky_v278_query_public_capability(),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result=tracky_v278_query_execute($pdo,$userId,[
      'query_id'=>(string)($_GET['query_id']??''),
      'intent'=>(string)($_GET['intent']??'current_state'),
      'destination_site_id'=>(string)($_GET['destination_site']??''),
      'site_id'=>(string)($_GET['site']??$_GET['destination_site']??''),
      'target_ref'=>(string)($_GET['target_ref']??''),
      'since_ms'=>(int)($_GET['since_ms']??0),
      'until_ms'=>(int)($_GET['until_ms']??0),
      'limit'=>(int)($_GET['limit']??50),
    ]);
    echo json_encode([
      'ok'=>true,'query'=>$result,'capability'=>tracky_v278_query_public_capability(),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode([
      'ok'=>false,
      'error'=>mb_strimwidth(trim($e->getMessage())?:'Tracky federated query could not be completed.',0,500,'')
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
