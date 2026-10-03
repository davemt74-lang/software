<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'error'=>'GET required.']);exit;}
$user=current_user();$pdo=db();
if(!$pdo){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Status unavailable.']);exit;}
if(!tracky_agent_enabled_v271($pdo,$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Enable the Tracky plugin to view scene status.']);exit;}
try{
 if(!tracky_cloud_v270_schema_ready($pdo))throw new RuntimeException('Upgrade required.');
 $site=trim((string)($_GET['site']??''));if($site!=='')$site=tracky_cloud_v270_site_id($site);
 $statuses=tracky_eyes_reports_v1g4($pdo,(int)$user['id'],$site);
 if(($_GET['report']??'')==='1'){
  header('Content-Disposition: attachment; filename="Agent-Eyes-Cloud-Acceptance.json"');
  echo json_encode(tracky_eyes_acceptance_report_v1g5($statuses),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }else echo json_encode(['ok'=>true,'statuses'=>$statuses],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Scene status unavailable. Check the database upgrade and HomeServer connection.']);}
