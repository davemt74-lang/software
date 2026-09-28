<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'GET required.']);exit;}
$user=current_user();$userId=(int)($user['id']??0);$pdo=db();if($userId<1||!$pdo){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Federated automation mirror unavailable.']);exit;}
try{$origin=trim((string)($_GET['origin_site_id']??''));$reporting=trim((string)($_GET['reporting_site_id']??''));echo json_encode(['ok'=>true,'automation'=>tracky_v281_fa_report($pdo,$userId,$origin,$reporting),'capability'=>tracky_v281_fa_public_capability()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>mb_strimwidth(trim($e->getMessage()),0,500,'')]);}
