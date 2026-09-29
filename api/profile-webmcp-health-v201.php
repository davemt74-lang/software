<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-tool-router-v191.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-actions-v150.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-external-v120.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-continuity-v194.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-health-v201.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']]);exit;}
$pdo=db();$viewer=current_user();
if(!$pdo||!is_array($viewer)||(int)($viewer['id']??0)<1){http_response_code(401);echo json_encode(['ok'=>false,'error'=>['code'=>'AUTH_REQUIRED']]);exit;}
$profile=profile_for_user($pdo,(int)$viewer['id'],false);
if(!$profile){http_response_code(404);echo json_encode(['ok'=>false,'error'=>['code'=>'PROFILE_NOT_FOUND']]);exit;}
try{
    $snapshot=vp3_profile_webmcp_health_snapshot_v201($pdo,$profile,$viewer,max(0,(int)($_GET['property_id']??0)));
    echo json_encode(['ok'=>true,'health'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>['code'=>'HEALTH_UNAVAILABLE','message'=>'Profile WebMCP health is temporarily unavailable.']]);
}
