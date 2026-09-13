<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/homeserver-relay-bootstrap-v1210.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok'=>false,'error'=>'GET required.']);
    exit;
}

try {
    echo json_encode([
        'ok'=>true,
        'pairing_protocol'=>'account-token-v1',
        'relay_websocket_url'=>homeserver_relay_bootstrap_v1210_websocket_url(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'HomeServer relay is unavailable.'], JSON_UNESCAPED_SLASHES);
}
