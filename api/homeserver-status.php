<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-policy-v035.php';
require_once dirname(__DIR__) . '/includes/homeserver-scheduling-connector-v620.php';
require_once dirname(__DIR__) . '/includes/homeserver-commerce-agent-v1000.php';
require_once dirname(__DIR__) . '/includes/homeserver-cloud-pairing-actions-v1200.php';
require_once dirname(__DIR__) . '/includes/homeserver-account-pairing-v1210.php';
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
    try { return homeserver_cloud_v1200_status($userId, false); } catch (Throwable $e) { return null; }
}

function homeserver_status_with_connectors_v1000(int $userId,array $status): array
{
    try { $status['scheduling_connector']=homeserver_scheduling_v620_connector_status($userId); }
    catch (Throwable $e) { $status['scheduling_connector']=['configured'=>false,'provisioned'=>false,'version'=>'v6.20','error'=>'Connector status unavailable.']; }
    try { $status['commerce_agent_connector']=homeserver_commerce_agent_v1000_status($userId); }
    catch (Throwable $e) { $status['commerce_agent_connector']=['configured'=>false,'provisioned'=>false,'version'=>'v10.00','error'=>'Connector status unavailable.']; }
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
        if ($action === 'provision_scheduling' || $action === 'rotate_scheduling') {
            $connector=homeserver_scheduling_v620_provision($userId,$action==='rotate_scheduling');
            $status=homeserver_status_with_connectors_v1000($userId,homeserver_vp3_status($userId,false));$status['scheduling_connector']=$connector;
            echo json_encode(['ok'=>true,'connector'=>$connector,'status'=>$status],JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'provision_commerce_agent' || $action === 'rotate_commerce_agent') {
            $connector=homeserver_commerce_agent_v1000_provision($userId,$action==='rotate_commerce_agent');
            $status=homeserver_status_with_connectors_v1000($userId,homeserver_vp3_status($userId,false));$status['commerce_agent_connector']=$connector;
            echo json_encode(['ok'=>true,'connector'=>$connector,'status'=>$status],JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'disconnect') {
            // Keep legacy callers on the same fail-closed, re-pairable lifecycle as Settings → HomeServer.
            // Reverse connector grants are revoked before relay rotation to preserve the existing security contract.
            try { homeserver_commerce_agent_v1000_revoke($userId); } catch (Throwable $ignored) {}
            try { homeserver_scheduling_v620_revoke($userId); } catch (Throwable $ignored) {}
            homeserver_cloud_v1200_disconnect($userId);
            homeserver_account_v1210_revoke_user_tokens($userId);
            echo json_encode(['ok'=>true,'status'=>homeserver_cloud_v1200_status($userId,false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported HomeServer action.']);
        exit;
    }

    $force = (string)($_GET['refresh'] ?? '') === '1';
    $statusSnapshot=homeserver_status_with_connectors_v1000($userId,homeserver_cloud_v1200_status($userId,$force));
    $response=['ok'=>true,'status'=>$statusSnapshot];
    if((string)($_GET['registry'] ?? '')==='1'){
        try{$response['registry']=homeserver_capability_v033_registry($userId,$force);}
        catch(Throwable $ignored){$response['registry']=['available'=>false,'reason'=>'unavailable'];}
    }
    if((string)($_GET['policy'] ?? '')==='1'){
        try{$response['policy']=homeserver_policy_v035_snapshot($userId,false,$statusSnapshot);}
        catch(Throwable $ignored){$response['policy']=['available'=>false,'reason'=>'remote_unavailable'];}
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    $message = trim($e->getMessage());
    if ($message === '' || preg_match('/(?:credential key|decrypt|database|sql|openssl|curl)/i', $message)) $message = 'HomeServer connection could not be updated. Check the connection settings and try again.';
    echo json_encode(['ok'=>false,'error'=>mb_substr($message,0,500),'status'=>homeserver_status_error_snapshot($userId)],JSON_UNESCAPED_SLASHES);
}
