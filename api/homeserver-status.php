<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user = current_user();
$userId = (int)($user['id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Authentication required.']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            http_response_code(419);
            echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);
            exit;
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'claim') {
            $pairing = homeserver_vp3_claim_and_pair($userId, (string)($_POST['claim_code'] ?? ''));
            echo json_encode([
                'ok'=>true,
                'pairing'=>$pairing,
                'status'=>homeserver_vp3_status($userId, true),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'check_pairing') {
            $pairing = homeserver_vp3_check_pairing($userId);
            echo json_encode([
                'ok'=>true,
                'pairing'=>$pairing,
                'status'=>homeserver_vp3_status($userId, true),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'disconnect') {
            homeserver_vp3_disconnect($userId);
            echo json_encode(['ok'=>true,'status'=>homeserver_vp3_status($userId, false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported HomeServer action.']);
        exit;
    }

    $force = (string)($_GET['refresh'] ?? '') === '1';
    echo json_encode(['ok'=>true,'status'=>homeserver_vp3_status($userId, $force)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'=>false,
        'error'=>mb_substr($e->getMessage(), 0, 500),
        'status'=>homeserver_vp3_status($userId, false),
    ], JSON_UNESCAPED_SLASHES);
}
