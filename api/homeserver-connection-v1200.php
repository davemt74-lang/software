<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-cloud-pairing-actions-v1200.php';
require_once dirname(__DIR__) . '/includes/homeserver-account-pairing-v1210.php';
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

function homeserver_connection_v1200_optional_connector(callable $reader, array $fallback): array
{
    try {
        $value = $reader();
        return is_array($value) ? $value : $fallback;
    } catch (Throwable $e) {
        $fallback['error'] = 'Connector status is unavailable until the database upgrade completes.';
        return $fallback;
    }
}

function homeserver_connection_v1200_response(int $userId, bool $force=false): array
{
    $status = homeserver_cloud_v1200_status($userId, $force);
    $row = homeserver_vp3_connection($userId);
    $status['can_remove'] = $row !== null
        && in_array((string)($row['status'] ?? ''), ['disconnected','revoked'], true);
    $status['account_pairing'] = homeserver_account_v1210_token_status($userId);
    $status['pairing_protocol'] = 'account-token-v1';
    $status['scheduling_connector'] = homeserver_connection_v1200_optional_connector(
        static fn(): array => homeserver_scheduling_v620_connector_status($userId),
        ['configured'=>false,'provisioned'=>false,'version'=>'v6.20']
    );
    $status['commerce_agent_connector'] = homeserver_connection_v1200_optional_connector(
        static fn(): array => homeserver_commerce_agent_v1000_status($userId),
        ['configured'=>false,'provisioned'=>false,'contract'=>'commerce-agent-v1','version'=>'v10.00']
    );
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
    $accountToken = null;
    $roundTrip = null;

    if ($action === 'generate_pairing_token') {
        $accountToken = homeserver_account_v1210_generate_token($userId);
    } elseif ($action === 'reconnect') {
        homeserver_cloud_v1200_reconnect($userId);
    } elseif ($action === 'test_connection') {
        if(!function_exists('homeserver_shared_v210_roundtrip'))throw new RuntimeException('HomeServer v2.1 diagnostics are unavailable.');
        $roundTrip=homeserver_shared_v210_roundtrip($userId);
    } elseif ($action === 'disconnect') {
        try { homeserver_commerce_agent_v1000_revoke($userId); } catch (Throwable $ignored) {}
        try { homeserver_scheduling_v620_revoke($userId); } catch (Throwable $ignored) {}
        $pairing = homeserver_cloud_v1200_disconnect($userId);
        homeserver_account_v1210_revoke_user_tokens($userId);
    } elseif ($action === 'remove') {
        try { homeserver_commerce_agent_v1000_revoke($userId); } catch (Throwable $ignored) {}
        try { homeserver_scheduling_v620_revoke($userId); } catch (Throwable $ignored) {}
        $pairing = homeserver_cloud_v1200_remove_pairing($userId);
        homeserver_account_v1210_revoke_user_tokens($userId);
    } elseif ($action === 'reset_pairing') {
        try { homeserver_commerce_agent_v1000_revoke($userId); } catch (Throwable $ignored) {}
        try { homeserver_scheduling_v620_revoke($userId); } catch (Throwable $ignored) {}
        homeserver_cloud_v1200_disconnect($userId);
        homeserver_account_v1210_revoke_user_tokens($userId);
        homeserver_cloud_v1200_remove_pairing($userId);
        $accountToken = homeserver_account_v1210_generate_token($userId);
        $pairing = ['reset'=>true];
    } else {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported HomeServer action.']);
        exit;
    }

    $response = ['ok'=>true,'status'=>homeserver_connection_v1200_response($userId,true)];
    if (is_array($pairing)) $response['pairing'] = $pairing;
    if (is_array($accountToken)) $response['account_pairing_token'] = $accountToken;
    if (is_array($roundTrip)) $response['round_trip'] = $roundTrip;
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    $status = null;
    try { $status = homeserver_connection_v1200_response($userId,false); } catch (Throwable $ignored) {}
    $error = homeserver_cloud_v1200_public_error($e->getMessage());
    if (str_contains(strtolower($e->getMessage()), 'pairing token') || str_contains(strtolower($e->getMessage()), 'homeserver connection already exists')) {
        $error = $e->getMessage();
    }
    echo json_encode([
        'ok'=>false,
        'error'=>$error,
        'status'=>$status,
    ], JSON_UNESCAPED_SLASHES);
}
