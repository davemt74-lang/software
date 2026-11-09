<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-gmail-inbox-settings-v1.php';

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
$result = $pdo ? 'inbox_invalid' : 'inbox_unavailable';
if ($pdo && $user && is_string($_POST['csrf_token'] ?? null) && verify_csrf()) {
    try {
        $result = agent_connection_gmail_inbox_save_v1($pdo,$user,$_POST);
    } catch (AgentConnectionExceptionV1 | AgentConnectionGmailExceptionV1 $error) {
        $result = match ($error->getCode()) {
            400,422 => 'inbox_invalid',
            401 => 'inbox_reconnect',
            403,503 => 'inbox_unavailable',
            409 => 'inbox_conflict',
            default => 'inbox_failed',
        };
    } catch (Throwable) {
        $result = 'inbox_failed';
    }
}
redirect(url('/connections.php?app=gmail&result='.$result.'#gmail-inbox-review'));
