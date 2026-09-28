<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='GET'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'GET required.']);
    exit;
}
echo json_encode(['ok'=>true,'release'=>tracky_v280_release_public_capability()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
