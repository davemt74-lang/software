<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-health-v1.php';
require_permission('account.access');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$pdo = db();
$user = current_user();
$connectionId = filter_var($_POST['connection_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$revision = $_POST['revision'] ?? null;
if (!$pdo || !$user || !is_string($_POST['csrf_token'] ?? null) || !verify_csrf()
    || ($_POST['provider'] ?? '') !== 'gmail' || !$connectionId || !is_string($revision)
    || !preg_match('/\A[a-f0-9]{64}\z/D',$revision)) {
    redirect(url('/connections.php?result=test_invalid'));
}

try {
    $outcome = agent_connection_health_test_member_v1($pdo,(int)$user['id'],$connectionId,$revision);
    $result = match (true) {
        $outcome['status'] === 'rate_limited' => 'test_limited',
        $outcome['checked_at'] !== null => 'tested',
        default => 'test_unavailable',
    };
} catch (AgentConnectionExceptionV1) {
    $result = 'test_invalid';
} catch (Throwable) {
    $result = 'test_unavailable';
}
redirect(url('/connections.php?result='.$result));
