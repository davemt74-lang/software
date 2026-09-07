<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function vp3_analytics_collect_json(int $status,array $payload=[]): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){
    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if($origin!=='')header('Access-Control-Allow-Origin: '.$origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    vp3_analytics_collect_json(204,[]);
}
if($method!=='POST')vp3_analytics_collect_json(405,['ok'=>false,'error'=>'Method not allowed.']);

$pdo=db();$key=strtolower(trim((string)($_GET['key']??'')));
$property=$pdo?vp3_radar_external_property_by_key($pdo,$key):null;
if(!$pdo||!$property)vp3_analytics_collect_json(404,['ok'=>false,'error'=>'Connected site not found.']);
$origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
$originHost=vp3_radar_external_origin_host();
if(!vp3_radar_external_origin_allowed((string)$property['domain'],$originHost))vp3_analytics_collect_json(403,['ok'=>false,'error'=>'Origin not allowed.']);
if($origin!==''){
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
}
$raw=(string)file_get_contents('php://input');
if(strlen($raw)>8192)vp3_analytics_collect_json(413,['ok'=>false,'error'=>'Payload too large.']);
$payload=json_decode($raw,true);if(!is_array($payload))vp3_analytics_collect_json(400,['ok'=>false,'error'=>'Invalid JSON payload.']);
$userAgent=mb_strimwidth(trim((string)($_SERVER['HTTP_USER_AGENT']??'')),0,1000,'');
try{vp3_analytics_external_collect($pdo,$property,$payload,$userAgent);}catch(Throwable $e){error_log('VP3 Analytics collect failed: '.$e->getMessage());}
vp3_analytics_collect_json(200,['ok'=>true]);
