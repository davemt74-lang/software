<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function homeserver_acceptance_api_v027(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user || !has_permission('account.access', $user) || !has_permission('chat.access', $user)) {
    homeserver_acceptance_api_v027(false, ['error'=>'HomeServer testing is unavailable for this account.'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    homeserver_acceptance_api_v027(false, ['error'=>'POST required.'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    homeserver_acceptance_api_v027(false, ['error'=>'Session expired. Refresh and try again.'], 419);
}

try {
    homeserver_acceptance_api_v027(true, [
        'acceptance' => homeserver_acceptance_v027_run($user),
    ]);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'HomeServer connection test failed.';
    homeserver_acceptance_api_v027(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
