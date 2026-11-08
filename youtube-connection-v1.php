<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-youtube-v1.php';
require_once __DIR__.'/includes/agent-connection-youtube-consent-v1.php';
require_permission('account.access');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$user = current_user();
$pdo = db();
$action = $_POST['action'] ?? '';
$result = 'youtube_invalid';
if ($user && is_string($_POST['csrf_token'] ?? null) && verify_csrf() && in_array($action, ['enable', 'disable'], true)) {
    if ($action === 'enable' && ($_POST['accept_youtube_terms'] ?? null) !== '1') {
        $result = 'youtube_accept_required';
    } elseif (!$pdo || !agent_connection_youtube_consent_ready_v1($pdo)
        || ($action === 'enable' && (!has_permission('chat.access', $user) || !agent_connection_youtube_ready_v1()))) {
        $result = 'youtube_unavailable';
    } else {
        try {
            agent_connection_youtube_set_consent_v1($pdo, (int)$user['id'], $action === 'enable');
            $result = $action === 'enable' ? 'youtube_enabled' : 'youtube_disabled';
        } catch (Throwable $e) {
            $result = 'youtube_unavailable';
        }
    }
}
redirect(url('/connections.php?result='.$result.'#connections-app-youtube'));
