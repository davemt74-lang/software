<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function vp3_radar_server_collect_json(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')vp3_radar_server_collect_json(405,['ok'=>false,'error'=>'Method not allowed.']);
$pdo=db();
$key=strtolower(trim((string)($_GET['key']??'')));
$property=$pdo?vp3_radar_external_property_by_key($pdo,$key):null;
if(!$pdo||!$property)vp3_radar_server_collect_json(404,['ok'=>false,'error'=>'Connected site not found.']);
$token=vp3_radar_server_request_token();
if(!vp3_radar_server_token_valid($property,$token))vp3_radar_server_collect_json(401,['ok'=>false,'error'=>'Invalid server token.']);

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>16384)vp3_radar_server_collect_json(413,['ok'=>false,'error'=>'Payload too large.']);
$payload=json_decode($raw,true);
if(!is_array($payload))vp3_radar_server_collect_json(400,['ok'=>false,'error'=>'Invalid JSON payload.']);
try{vp3_radar_server_collect($pdo,$property,$payload);}catch(Throwable $e){error_log('Server Agent Radar collect failed: '.$e->getMessage());}
vp3_radar_server_collect_json(204);
