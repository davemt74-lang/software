<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-approvals-v028.php';
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

function homeserver_approvals_v028_api_error(Throwable $e, int $status=400): never
{
    $message = trim($e->getMessage());
    if ($message === '' || preg_match('/(?:credential key|decrypt|database|sql|openssl|curl)/i', $message)) {
        $message = 'HomeServer approvals could not be updated. Refresh the connection and try again.';
    }
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>mb_substr($message, 0, 500)], JSON_UNESCAPED_SLASHES);
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
        if ($action === 'approve' || $action === 'deny') {
            $requestId = trim((string)($_POST['request_id'] ?? ''));
            echo json_encode(homeserver_approvals_v028_review($userId, $requestId, $action), JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'upgrade_permission') {
            echo json_encode(homeserver_approvals_v028_begin_permission_upgrade($userId), JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'check_permission') {
            echo json_encode(homeserver_approvals_v028_check_permission_upgrade($userId), JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported approvals action.']);
        exit;
    }

    $status = trim((string)($_GET['status'] ?? 'pending'));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    echo json_encode(homeserver_approvals_v028_list($userId, $status, $limit), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    homeserver_approvals_v028_api_error($e);
}
