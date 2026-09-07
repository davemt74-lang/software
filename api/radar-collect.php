<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$pdo=db();
$key=strtolower(trim((string)($_GET['key']??'')));
$property=$pdo?vp3_radar_external_property_by_key($pdo,$key):null;
$origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
$originHost=vp3_radar_external_origin_host();
if(!$pdo||!$property||!vp3_radar_external_origin_allowed((string)$property['domain'],$originHost)){
    http_response_code(403);
    exit;
}
if($origin!==''){
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){http_response_code(204);exit;}
if($method!=='POST'){http_response_code(405);exit;}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>8192){http_response_code(413);exit;}
$payload=json_decode($raw,true);if(!is_array($payload))$payload=[];
$userAgent=mb_strimwidth(trim((string)($_SERVER['HTTP_USER_AGENT']??'')),0,1000,'');
try{vp3_radar_external_collect($pdo,$property,$payload,$userAgent);}catch(Throwable $e){error_log('External Agent Radar collect failed: '.$e->getMessage());}
http_response_code(204);
