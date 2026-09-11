<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-approvals-v028.php';
require_once dirname(__DIR__) . '/includes/homeserver-policy-v035.php';
require_once dirname(__DIR__) . '/includes/homeserver-scheduling-connector-v620.php';
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

function homeserver_status_error_snapshot(int $userId): ?array
{
    try { return homeserver_vp3_status($userId, false); } catch (Throwable $e) { return null; }
}

function homeserver_status_with_scheduling_v620(int $userId,array $status): array
{
    $status['scheduling_connector']=homeserver_scheduling_v620_maybe_provision($userId,$status);
    return $status;
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
            $pairing = homeserver_approvals_v028_claim_and_pair($userId, (string)($_POST['claim_code'] ?? ''));
            echo json_encode(['ok'=>true,'pairing'=>$pairing,'status'=>homeserver_status_with_scheduling_v620($userId,homeserver_vp3_status($userId,true))], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'check_pairing') {
            $pairing = homeserver_vp3_check_pairing($userId);
            echo json_encode(['ok'=>true,'pairing'=>$pairing,'status'=>homeserver_status_with_scheduling_v620($userId,homeserver_vp3_status($userId,true))], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'provision_scheduling' || $action === 'rotate_scheduling') {
            $connector=homeserver_scheduling_v620_provision($userId,$action==='rotate_scheduling');
            echo json_encode(['ok'=>true,'connector'=>$connector,'status'=>homeserver_status_with_scheduling_v620($userId,homeserver_vp3_status($userId,false))],JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'disconnect') {
            homeserver_scheduling_v620_revoke($userId);
            homeserver_vp3_disconnect($userId);
            echo json_encode(['ok'=>true,'status'=>homeserver_vp3_status($userId,false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported HomeServer action.']);
        exit;
    }

    $force = (string)($_GET['refresh'] ?? '') === '1';
    $statusSnapshot=homeserver_status_with_scheduling_v620($userId,homeserver_vp3_status($userId,$force));
    $response=['ok'=>true,'status'=>$statusSnapshot];
    if((string)($_GET['registry'] ?? '')==='1')$response['registry']=homeserver_capability_v033_registry($userId,$force);
    if((string)($_GET['policy'] ?? '')==='1')$response['policy']=homeserver_policy_v035_snapshot($userId,false,$statusSnapshot);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    $message = trim($e->getMessage());
    if ($message === '' || preg_match('/(?:credential key|decrypt|database|sql|openssl|curl)/i', $message)) $message = 'HomeServer connection could not be updated. Check the connection settings and try again.';
    echo json_encode(['ok'=>false,'error'=>mb_substr($message,0,500),'status'=>homeserver_status_error_snapshot($userId)],JSON_UNESCAPED_SLASHES);
}
