<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connections-v1.php';
require_permission('account.access');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$pdo = db();
$user = current_user();
if (!$pdo || !$user || !agent_connections_schema_ready_v1($pdo)) {
    redirect(url('/connections.php?result=unavailable'));
}
$ownerId = (int)$user['id'];
$result = 'invalid';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf() || ($_POST['provider'] ?? '') !== 'gmail') {
            throw new AgentConnectionExceptionV1('Invalid connection request.', 400);
        }
        $read = ($_POST['allow_read'] ?? '') === '1';
        $policy = ['read'=>$read,'send'=>($_POST['allow_send'] ?? '') === '1','followups'=>$read && ($_POST['allow_followups'] ?? '') === '1'];
        $action = $_POST['action'] ?? '';
        $id = filter_var($_POST['connection_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
        if ($action === 'start') {
            $callback = agent_connections_callback_v1();
            $fingerprint = agent_connections_client_fingerprint_v1();
            $current = agent_connections_find_v1($pdo,$ownerId,'gmail');
            $state = bin2hex(random_bytes(32));
            $verifier = bin2hex(random_bytes(32));
            $challenge = rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
            $_SESSION['connection_gmail_oauth_v1'] = [
                'owner_id'=>$ownerId,'state'=>$state,'verifier'=>$verifier,'expires'=>time()+600,
                'policy'=>$policy,'callback'=>$callback,'fingerprint'=>$fingerprint,
                'authorization_key'=>(string)($current['authorization_key'] ?? ''),
            ];
            redirect(agent_connection_gmail_oauth_url_v1($callback,$state,$challenge));
        }
        unset($_SESSION['connection_gmail_oauth_v1']);
        $record = $id ? agent_connections_record_v1($ownerId,$id) : null;
        $revision = $_POST['revision'] ?? '';
        if (!$record || !is_string($revision) || !hash_equals($record['revision'],$revision)) {
            throw new AgentConnectionExceptionV1('This connection changed. Reload Connections and try again.', 409);
        }
        if ($action === 'disconnect' && agent_connections_disconnect_v1($ownerId,$id,'gmail',$record['authorization_key'])) {
            $result = 'disconnected';
        } elseif ($action === 'update_policy' && agent_connections_update_policy_v1($ownerId,$id,$policy,$record['authorization_key'])) {
            $result = 'updated';
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $attempt = $_SESSION['connection_gmail_oauth_v1'] ?? null;
        $state = $_GET['state'] ?? null;
        if (!is_array($attempt) || !is_string($state) || !hash_equals($attempt['state'],$state)
            || $attempt['owner_id'] !== $ownerId || $attempt['expires'] < time()) {
            throw new AgentConnectionExceptionV1('Invalid or expired authorization.', 400);
        }
        unset($_SESSION['connection_gmail_oauth_v1']);
        if (!empty($_GET['error'])) {
            redirect(url('/connections.php?result=denied'));
        }
        $code = $_GET['code'] ?? '';
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new AgentConnectionExceptionV1('Invalid authorization response.', 400);
        }
        if (!hash_equals($attempt['fingerprint'],agent_connections_client_fingerprint_v1())
            || $attempt['callback'] !== agent_connections_callback_v1()) {
            throw new AgentConnectionExceptionV1('Gmail setup changed during authorization.', 409);
        }
        $token = agent_connection_gmail_exchange_v1($attempt['callback'],$code,$attempt['verifier']);
        agent_connections_store_gmail_v1($ownerId,$token,$attempt['policy'],$attempt['authorization_key'],$attempt['fingerprint'],$attempt['callback']);
        $result = 'connected';
    }
} catch (AgentConnectionExceptionV1 $e) {
    $result = match ($e->getCode()) {
        400 => 'invalid', 403,503 => 'unavailable', 409 => 'conflict', default => 'failed',
    };
} catch (Throwable) {
    $result = 'failed';
}
redirect(url('/connections.php?result='.$result));
