<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to read Agent activity.', 401);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Activity storage is unavailable.', 503);
    $binding = (string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? '');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $state = vp3_agent_activity_hub_request_v1($pdo, $user, (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        $_GET['agent_id'] ?? '0', $_GET['conversation_id'] ?? '0', $binding);
    echo json_encode(['ok'=>true, 'state'=>$state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = in_array($error->getCode(), [401,403,405,409,422,503], true) ? (int)$error->getCode() : 503;
    if ($status === 405) header('Allow: GET');
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>match ($status) {
        401,403=>'Your Agent session or access changed.',
        405=>'Use GET to read Agent activity.',
        422=>'Choose an exact Agent and conversation.',
        default=>'Agent activity could not be refreshed.',
    }], JSON_UNESCAPED_SLASHES);
}
