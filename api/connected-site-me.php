<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$pdo=db();try{$auth=vp3_connected_site_auth_v100($pdo,'account.identity.read');echo json_encode(['ok'=>true,'data'=>['id'=>(string)$auth['user_id'],'display_name'=>(string)$auth['display_name'],'email'=>(string)$auth['email'],'scopes'=>$auth['scopes']]],JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(401);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
