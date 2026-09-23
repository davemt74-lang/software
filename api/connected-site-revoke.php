<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$pdo=db();try{$auth=vp3_connected_site_auth_v100($pdo);vp3_connected_site_revoke_v100($pdo,(int)$auth['user_id'],(int)$auth['connection_id']);echo json_encode(['ok'=>true]);}catch(Throwable $e){http_response_code(401);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
