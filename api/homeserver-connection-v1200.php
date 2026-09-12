<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-cloud-pairing-actions-v1200.php';
require_once dirname(__DIR__) . '/includes/homeserver-scheduling-connector-v620.php';
require_once dirname(__DIR__) . '/includes/homeserver-commerce-agent-v1000.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$user = current_user();
$userId = (int)($user['id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Authentication required.']);
    exit;
}

function homeserver_connection_v1200_response(int $userId, bool $force=false): array
{
    $status = homeserver_cloud_v1200_status($userId, $force);
    $status['scheduling_connector'] = homeserver_scheduling_v620_connector_status($userId);
    $status['commerce_agent_connector'] = homeserver_commerce_agent_v1000_status($userId);
    return $status;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $force = (string)($_GET['refresh'] ?? '') === '1';
        echo json_encode(['ok'=>true,'status'=>homeserver_connection_v1200_response($userId,$force)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok'=>false,'error'=>'Unsupported request method.']);
        exit;
    }
    if (!verify_csrf()) {
        http_response_code(419);
        echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $pairing = null;

    if ($action === 'start_pairing') {
        $pairing = homeserver_cloud_v1200_start_pairing($userId, (string)($_POST['claim_code'] ?? ''));
    } elseif ($action === 'pairing_status') {
        $now = microtime(true);
        $lastPoll = (float)($_SESSION['vp3_homeserver_pair_poll_at'] ?? 0.0);
        if ($lastPoll > 0 && ($now - $lastPoll) < 1.0) {
            http_response_code(429);
            header('Retry-After: 1');
            echo json_encode(['ok'=>false,'error'=>'Checking too quickly. Try again in a moment.','status'=>homeserver_connection_v1200_response($userId,false)]);
            exit;
        }
        $_SESSION['vp3_homeserver_pair_poll_at'] = $now;
        $pairing = homeserver_cloud_v1200_check_pairing_safe($userId);
        if (!empty($pairing['ready'])) {
            try { homeserver_scheduling_v620_provision($userId, false); } catch (Throwable $ignored) {}
            try { homeserver_commerce_agent_v1000_provision($userId, false); } catch (Throwable $ignored) {}
        }
    } elseif ($action === 'reconnect') {
        homeserver_cloud_v1200_reconnect($userId);
    } elseif ($action === 'repair') {
        $pairing = homeserver_cloud_v1200_repair($userId);
    } elseif ($action === 'cancel_pairing') {
        homeserver_cloud_v1200_cancel_pairing($userId);
    } elseif ($action === 'disconnect') {
        // Fail closed: revoke the exact relay credential before deleting or clearing any secret needed to retry revocation.
        homeserver_cloud_v1200_revoke_access($userId);
        homeserver_commerce_agent_v1000_revoke($userId);
        homeserver_scheduling_v620_revoke($userId);
    } elseif ($action === 'remove') {
        homeserver_cloud_v1200_remove_local($userId);
    } else {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported HomeServer action.']);
        exit;
    }

    $response = ['ok'=>true,'status'=>homeserver_connection_v1200_response($userId,true)];
    if (is_array($pairing)) $response['pairing'] = $pairing;
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    $status = null;
    try { $status = homeserver_connection_v1200_response($userId,false); } catch (Throwable $ignored) {}
    echo json_encode([
        'ok'=>false,
        'error'=>homeserver_cloud_v1200_public_error($e->getMessage()),
        'status'=>$status,
    ], JSON_UNESCAPED_SLASHES);
}
