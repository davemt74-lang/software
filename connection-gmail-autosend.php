<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-gmail-autosend-v1.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
require_permission('account.access');

$pdo = db();
$user = current_user();
$result = 'autosend_invalid';
if ($pdo && $user && is_string($_POST['csrf_token'] ?? null) && verify_csrf()) {
    try {
        agent_connection_gmail_autosend_save_v1($pdo, $user, $_POST);
        $result = 'autosend_saved';
    } catch (AgentConnectionExceptionV1 $error) {
        $result = match ($error->getCode()) {
            422 => 'autosend_invalid',
            409 => 'autosend_conflict',
            default => 'autosend_unavailable',
        };
    } catch (Throwable) {
        $result = 'autosend_unavailable';
    }
}
redirect(url('/connections.php?app=gmail&result='.$result.'#gmail-autosend'));
