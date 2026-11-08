<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-gmail-popup-v1.php';

header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$finishing = ($_POST['action'] ?? '') === 'finish';
$result = 'invalid';
$status = 400;
try {
    if ($_GET !== []) throw new AgentConnectionExceptionV1('The Gmail request could not be verified.',400);
    $user = current_user();
    if (!$user || !has_permission('account.access',$user)) {
        throw new AgentConnectionExceptionV1('Sign in to connect Gmail.',403);
    }
    $pdo = db();
    if (!$pdo || !agent_connections_schema_ready_v1($pdo)) {
        throw new AgentConnectionExceptionV1('Connections are unavailable.',503);
    }
    if ($finishing) {
        agent_connection_gmail_popup_finish_v1($pdo,(int)$user['id'],$_POST,$_SERVER,$_SESSION);
        $result = 'connected';
        $status = 200;
    } else {
        $popup = agent_connection_gmail_popup_prepare_v1($pdo,(int)$user['id'],$_POST,$_SESSION);
        header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; "
            ."script-src 'self' https://accounts.google.com/gsi/client; style-src 'self' https://accounts.google.com/gsi/style; "
            ."style-src-attr 'unsafe-inline'; frame-src https://accounts.google.com/gsi/; connect-src 'self' https://accounts.google.com/gsi/; img-src 'self'");
        require __DIR__.'/includes/agent-connection-gmail-popup-view-v1.php';
        exit;
    }
} catch (AgentConnectionExceptionV1 $error) {
    [$result,$status] = match ($error->getCode()) {
        403 => ['unavailable',403],
        503 => ['unavailable',503],
        409 => ['conflict',409],
        428 => ['offline',428],
        default => ['invalid',400],
    };
} catch (AgentConnectionGmailExceptionV1 $error) {
    $result = 'denied';
    $status = 422;
} catch (Throwable) {
    $result = 'failed';
    $status = 500;
}

if ($finishing) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
    exit;
}
redirect(url('/connections.php?app=gmail&result='.$result.'#connections-app-gmail'));
