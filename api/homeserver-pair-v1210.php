<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/homeserver-account-pairing-v1210.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST required.']);
    exit;
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 4096) {
    http_response_code(413);
    echo json_encode(['ok'=>false,'error'=>'Pairing request is too large.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid pairing request.']);
    exit;
}

$pairingToken = (string)($body['pairing_token'] ?? '');
$relayClaim = (string)($body['relay_claim'] ?? '');
$deviceId = (string)($body['device_id'] ?? '');

try {
    $result = homeserver_account_v1210_redeem($pairingToken, $relayClaim, $deviceId);
    echo json_encode([
        'ok'=>true,
        'device_id'=>(string)$result['device_id'],
        'request_id'=>(string)$result['request_id'],
        'expires_at'=>(string)$result['expires_at'],
        'permissions'=>$result['permissions'],
        'next_step'=>'Review and approve VP3 locally in HomeServer.',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $message = homeserver_cloud_v1200_public_error($e->getMessage());
    if (str_contains(strtolower($e->getMessage()), 'pairing token')
        || str_contains(strtolower($e->getMessage()), 'device identity')) {
        $message = $e->getMessage();
    }
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_SLASHES);
}
