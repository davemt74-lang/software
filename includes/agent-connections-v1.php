<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
require_once __DIR__.'/agent-connection-notice-v1.php';
require_once __DIR__.'/ai-settings.php';

final class AgentConnectionExceptionV1 extends RuntimeException {}

function agent_connections_install_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connections_v1 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL,
        external_account_id VARCHAR(191) NOT NULL DEFAULT '',
        account_email VARCHAR(254) NOT NULL DEFAULT '',
        status ENUM('connected','reauthorize','disconnected') NOT NULL DEFAULT 'disconnected',
        read_enabled TINYINT(1) NOT NULL DEFAULT 0,
        send_enabled TINYINT(1) NOT NULL DEFAULT 0,
        followups_enabled TINYINT(1) NOT NULL DEFAULT 0,
        authorization_key CHAR(64) NOT NULL,
        client_fingerprint CHAR(64) NOT NULL DEFAULT '',
        access_token_enc TEXT NOT NULL,
        refresh_token_enc TEXT NOT NULL,
        access_expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_agent_connection_owner_provider_v1 (owner_user_id,provider),
        KEY idx_agent_connection_provider_status_v1 (provider,status),
        CONSTRAINT fk_agent_connection_owner_v1 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_sends_v1 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        authorization_key CHAR(64) NOT NULL,
        operation_key CHAR(64) NOT NULL,
        payload_digest CHAR(64) NOT NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        provider_message_id VARCHAR(191) NOT NULL DEFAULT '',
        provider_thread_id VARCHAR(191) NOT NULL DEFAULT '',
        sent_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_agent_connection_send_v1 (connection_id,operation_key),
        CONSTRAINT fk_agent_connection_send_v1 FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    require_once __DIR__.'/agent-connection-email-chat-v1.php';
    require_once __DIR__.'/agent-connection-email-followup-v1.php';
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    agent_connection_email_draft_schema_v1($pdo);
    agent_connection_email_followup_install_v1($pdo);
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_gmail_inbox_receipts_install_v1($pdo);
}

function agent_connections_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT authorization_key, client_fingerprint FROM agent_connections_v1 WHERE 1=0');
        $pdo->query('SELECT operation_key FROM agent_connection_sends_v1 WHERE 1=0');
        $pdo->query('SELECT authorization_key FROM agent_connection_email_drafts_v1 WHERE 1=0');
        $pdo->query('SELECT claim_key FROM agent_connection_email_followups_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connections_callback_v1(?string $override = null): string
{
    global $config;
    $saved = $override === null ? trim((string)setting('connections_gmail_redirect_uri', '')) : '';
    $environment = trim((string)getenv('VP3_GMAIL_REDIRECT_URI'));
    $uri = $override ?? ($saved !== '' ? $saved : ($environment !== '' ? $environment : (string)($config['connections']['gmail']['redirect_uri'] ?? '')));
    $parts = parse_url(trim($uri));
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || ($parts['path'] ?? '') !== url('/connection-oauth.php')
        || array_intersect(['user','pass','query','fragment'], array_keys($parts))
        || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
        throw new AgentConnectionExceptionV1('A valid HTTPS Gmail callback URL is required.', 503);
    }
    return $uri;
}

function agent_connections_gmail_popup_origin_v1(?string $callback = null): string
{
    $parts = parse_url(agent_connections_callback_v1($callback));
    $port = $parts['port'] ?? 443;
    return 'https://'.strtolower($parts['host']).($port === 443 ? '' : ':'.$port);
}

function agent_connections_permissions_v1(array $row): array
{
    $read = (bool)($row['read_enabled'] ?? false);
    return ['read'=>$read, 'send'=>(bool)($row['send_enabled'] ?? false),
        'followups'=>$read && (bool)($row['followups_enabled'] ?? false)];
}

function agent_connections_find_v1(PDO $pdo, int $ownerId, string|int $selector, bool $lock = false): ?array
{
    $key = is_int($selector) ? 'id' : 'provider';
    $stmt = $pdo->prepare("SELECT * FROM agent_connections_v1 WHERE owner_user_id=? AND $key=?".($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$ownerId,$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function agent_connections_view_v1(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'provider'=>(string)$row['provider'],
        'account_email'=>(string)$row['account_email'],
        'status'=>(string)$row['status'],
        'revision'=>hash('sha256',(string)$row['authorization_key']),
        'permissions'=>agent_connections_permissions_v1($row),
    ];
}

function agent_connections_record_v1(int $ownerId, int $connectionId, bool $lock = false): ?array
{
    $pdo = db();
    if (!$pdo || $ownerId < 1 || $connectionId < 1) return null;
    $row = agent_connections_find_v1($pdo, $ownerId, $connectionId, $lock);
    return $row ? agent_connections_view_v1($row) + ['authorization_key'=>(string)$row['authorization_key']] : null;
}

function agent_connections_summary_v1(int $ownerId, string $provider = 'gmail'): array
{
    $pdo = db();
    $result = ['schema_ready'=>false,'provider_ready'=>false,'connection'=>null];
    if (!$pdo || $ownerId < 1 || $provider !== 'gmail' || !agent_connections_schema_ready_v1($pdo)) return $result;
    $result['schema_ready'] = true;
    $row = agent_connections_find_v1($pdo, $ownerId, $provider);
    if ($row) $result['connection'] = agent_connections_view_v1($row);
    try {
        agent_connections_callback_v1();
        $result['provider_ready'] = agent_connection_gmail_ready_v1();
        if ($result['provider_ready'] && $row && $row['status'] === 'connected'
            && !hash_equals($row['client_fingerprint'], agent_connections_client_fingerprint_v1())) {
            $result['connection']['status'] = 'reauthorize';
        }
    } catch (AgentConnectionExceptionV1) {
        // The member can still disconnect while setup is unavailable.
    }
    return $result;
}

function agent_connections_client_fingerprint_v1(): string
{
    $client = agent_connection_gmail_config_v1();
    if (!agent_connection_gmail_enabled_v1() || $client['client_id'] === '' || $client['client_secret'] === '' || !function_exists('curl_init')) {
        throw new AgentConnectionExceptionV1('Gmail is currently unavailable on this site.', 403);
    }
    return hash('sha256', $client['client_id']);
}

function agent_connections_store_gmail_v1(int $ownerId, array $token, array $policy, string $expectedKey, string $fingerprint, ?string $callback = null, bool $allowExistingRefresh = false): void
{
    $pdo = db();
    if (!$pdo) throw new AgentConnectionExceptionV1('Connections are unavailable.', 503);
    $values = [
        (string)$token['external_account_id'], (string)$token['account_email'], bin2hex(random_bytes(32)), $fingerprint,
        ai_encrypt_secret((string)$token['access_token']), ai_encrypt_secret((string)$token['refresh_token']),
        time() + (int)$token['expires_in'],
        (int)$policy['read'], (int)$policy['send'], (int)($policy['read'] && $policy['followups']),
    ];
    if ($values[0] === '' || $values[1] === '' || $values[4] === '' || ($values[5] === '' && !$allowExistingRefresh)) {
        throw new AgentConnectionExceptionV1('Google did not provide a usable mailbox grant.', 400);
    }
    $pdo->beginTransaction();
    try {
        $owner = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $owner->execute([$ownerId]);
        if (!$owner->fetchColumn()) throw new AgentConnectionExceptionV1('The connection owner is unavailable.', 403);
        $pdo->query("SELECT setting_key FROM settings WHERE setting_key IN ('connections_gmail_client_id','connections_gmail_client_secret','connections_gmail_redirect_uri','connections_gmail_enabled') FOR UPDATE")->fetchAll();
        if (!hash_equals(agent_connections_client_fingerprint_v1(),$fingerprint)
            || ($callback !== null && $callback !== agent_connections_callback_v1())) {
            throw new AgentConnectionExceptionV1('Gmail setup changed during authorization.', 409);
        }
        $row = agent_connections_find_v1($pdo, $ownerId, 'gmail', true);
        if (!hash_equals((string)($row['authorization_key'] ?? ''), $expectedKey)) {
            throw new AgentConnectionExceptionV1('This connection changed during authorization.', 409);
        }
        if ($values[5] === '') {
            // Google may omit a refresh token when the same account has previously consented.
            if (!$row || $row['status'] !== 'connected' || !hash_equals($row['client_fingerprint'], $fingerprint)
                || !hash_equals($row['external_account_id'], $values[0])
                || strcasecmp($row['account_email'], $values[1]) !== 0
                || !agent_connection_gmail_valid_token_v1(ai_decrypt_secret($row['refresh_token_enc']))) {
                throw new AgentConnectionExceptionV1('Google did not provide offline Gmail access for this connection.', 428);
            }
            $values[5] = $row['refresh_token_enc'];
        }
        if ($row) {
            $stmt = $pdo->prepare("UPDATE agent_connections_v1 SET external_account_id=?,account_email=?,authorization_key=?,client_fingerprint=?,access_token_enc=?,refresh_token_enc=?,access_expires_at=?,read_enabled=?,send_enabled=?,followups_enabled=?,status='connected' WHERE owner_user_id=? AND id=?");
            $stmt->execute([...$values,$ownerId,(int)$row['id']]);
            agent_connection_gmail_inbox_revoke_v1($pdo,$ownerId,(int)$row['id'],$row['authorization_key']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO agent_connections_v1 (external_account_id,account_email,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at,read_enabled,send_enabled,followups_enabled,owner_user_id,provider,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'gmail','connected')");
            $stmt->execute([...$values,$ownerId]);
        }
        agent_connection_notice_record_v1($ownerId, 'gmail');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function agent_connections_update_policy_v1(int $ownerId, int $connectionId, array $policy, string $expectedKey): bool
{
    $pdo = db();
    if (!$pdo) return false;
    require_once __DIR__.'/agent-connection-email-followup-v1.php';
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$ownerId,$connectionId,$policy,$expectedKey): bool {
        $row = agent_connections_find_v1($pdo,$ownerId,$connectionId,true);
        if (!$row || $row['provider'] !== 'gmail' || $row['status'] !== 'connected'
            || !hash_equals($row['authorization_key'],$expectedKey)) return false;
        $allowFollowups = $policy['read'] && $policy['followups'];
        $pdo->prepare('UPDATE agent_connections_v1 SET read_enabled=?,send_enabled=?,followups_enabled=? WHERE owner_user_id=? AND id=?')
            ->execute([(int)$policy['read'],(int)$policy['send'],(int)$allowFollowups,$ownerId,$connectionId]);
        if (!$policy['read']) agent_connection_gmail_inbox_revoke_v1($pdo,$ownerId,$connectionId,$expectedKey);
        // An explicit revocation stops pending work immediately, even if the owner re-enables the permission before the next worker run.
        if (!$allowFollowups && agent_connection_email_followup_ready_v1($pdo)) {
            $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='stopped',claim_key=NULL,next_check_at=NULL
                WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND status='waiting'")
                ->execute([$ownerId,$connectionId,$expectedKey]);
        }
        return true;
    });
}

function agent_connections_disconnect_v1(int $ownerId, int $connectionId, string $provider = 'gmail', ?string $expectedKey = null): bool
{
    $pdo = db();
    if (!$pdo) return false;
    require_once __DIR__.'/agent-commerce-recovery-pa2.php';
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$ownerId,$connectionId,$provider,$expectedKey): bool {
        $row = agent_connections_find_v1($pdo,$ownerId,$connectionId,true);
        if (!$row || $row['provider'] !== $provider || $row['status'] === 'disconnected'
            || ($expectedKey !== null && !hash_equals($row['authorization_key'],$expectedKey))) return false;
        $stmt = $pdo->prepare("UPDATE agent_connections_v1 SET status='disconnected',authorization_key=?,client_fingerprint='',external_account_id='',account_email='',access_token_enc='',refresh_token_enc='',access_expires_at=0,read_enabled=0,send_enabled=0,followups_enabled=0 WHERE owner_user_id=? AND id=?");
        $stmt->execute([bin2hex(random_bytes(32)),$ownerId,$connectionId]);
        if ($provider === 'gmail') agent_connection_gmail_inbox_revoke_v1($pdo,$ownerId,$connectionId,$row['authorization_key']);
        return $stmt->rowCount() === 1;
    });
}

function agent_connections_require_grant_v1(PDO $pdo, int $ownerId, int $connectionId, string $capability, ?string $expectedKey = null): array
{
    $row = agent_connections_find_v1($pdo, $ownerId, $connectionId, true);
    if (!$row || $row['provider'] !== 'gmail') throw new AgentConnectionExceptionV1('Connection not found.', 404);
    if ($expectedKey !== null && !hash_equals($row['authorization_key'], $expectedKey)) {
        throw new AgentConnectionExceptionV1('The approved connection has changed.', 409);
    }
    if ($row['status'] !== 'connected') throw new AgentConnectionExceptionV1('Connect Gmail to continue.', 401);
    if (!(agent_connections_permissions_v1($row)[$capability] ?? false)) {
        throw new AgentConnectionExceptionV1('This action is disabled for the Gmail connection.', 403);
    }
    if (!hash_equals($row['client_fingerprint'], agent_connections_client_fingerprint_v1())) {
        throw new AgentConnectionExceptionV1('Reconnect Gmail to the current OAuth app.', 401);
    }
    return $row;
}

function agent_connections_token_v1(PDO $pdo, array $row, ?callable $http = null): string
{
    if ((int)$row['access_expires_at'] > time() + 60) {
        $token = ai_decrypt_secret($row['access_token_enc']);
        if ($token !== '') return $token;
    }
    $refresh = ai_decrypt_secret($row['refresh_token_enc']);
    if ($refresh === '') throw new AgentConnectionExceptionV1('Reconnect Gmail to restore access.', 401);
    try {
        $next = agent_connection_gmail_refresh_v1($refresh,$http);
    } catch (AgentConnectionGmailExceptionV1 $e) {
        if ($e->providerError !== 'invalid_grant') throw $e;
        $stmt = $pdo->prepare("UPDATE agent_connections_v1 SET status='reauthorize',access_token_enc='',refresh_token_enc='',access_expires_at=0 WHERE id=?");
        $stmt->execute([(int)$row['id']]);
        agent_connection_gmail_inbox_revoke_v1($pdo,(int)$row['owner_user_id'],(int)$row['id'],$row['authorization_key']);
        throw new AgentConnectionExceptionV1('Google removed access. Reconnect Gmail to continue.', 401, $e);
    }
    $stmt = $pdo->prepare('UPDATE agent_connections_v1 SET access_token_enc=?,refresh_token_enc=?,access_expires_at=? WHERE id=?');
    $stmt->execute([
        ai_encrypt_secret($next['access_token']),
        !empty($next['refresh_token']) ? ai_encrypt_secret($next['refresh_token']) : $row['refresh_token_enc'],
        time()+(int)$next['expires_in'],
        (int)$row['id'],
    ]);
    return $next['access_token'];
}

function agent_connections_access_v1(int $ownerId, int $connectionId, string $capability, ?callable $http = null, ?string $expectedAuthorizationKey = null): array
{
    $pdo = db();
    if (!$pdo || !agent_connections_schema_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Connections are unavailable.', 503);
    $pdo->beginTransaction();
    try {
        $row = agent_connections_require_grant_v1($pdo, $ownerId, $connectionId, $capability, $expectedAuthorizationKey);
        $access = agent_connections_token_v1($pdo,$row,$http);
        $pdo->commit();
        return agent_connections_view_v1($row) + ['authorization_key'=>$row['authorization_key'], 'access_token'=>$access];
    } catch (Throwable $e) {
        // A confirmed revoked grant must survive the failed access request.
        if ($e instanceof AgentConnectionExceptionV1 && $e->getPrevious() instanceof AgentConnectionGmailExceptionV1
            && $e->getPrevious()->providerError === 'invalid_grant') {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function agent_connections_send_v1(int $ownerId, int $connectionId, string $to, string $subject, string $body, string $operationKey, ?string $expectedAuthorizationKey = null, ?callable $onVerified = null, ?callable $http = null, ?callable $beforeDispatch = null): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $operationKey)) {
        throw new AgentConnectionExceptionV1('A reviewed send key is required.', 400);
    }
    $pdo = db();
    if (!$pdo || !agent_connections_schema_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Connections are unavailable.', 503);
    $digest = hash('sha256', json_encode([$to,$subject,$body], JSON_THROW_ON_ERROR));
    $access = agent_connections_access_v1($ownerId,$connectionId,'send',$http);
    $grant = $access['authorization_key'];
    if ($expectedAuthorizationKey !== null && !hash_equals($grant, $expectedAuthorizationKey)) {
        throw new AgentConnectionExceptionV1('The approved connection has changed.', 409);
    }
    // Persist the one-time send claim before any provider side effect. A crash
    // after this point can only reconcile the same RFC Message-ID.
    $claim = $pdo->prepare("INSERT INTO agent_connection_sends_v1 (owner_user_id,connection_id,authorization_key,operation_key,payload_digest) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id");
    $claim->execute([$ownerId,$connectionId,$grant,$operationKey,$digest]);
    $created = $claim->rowCount() === 1;
    $dispatched = false;

    try {
        $pdo->beginTransaction();
        $row = agent_connections_require_grant_v1($pdo, $ownerId, $connectionId, 'send', $grant);
        $lookup = $pdo->prepare('SELECT * FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=? AND operation_key=? FOR UPDATE');
        $lookup->execute([$ownerId,$connectionId,$operationKey]);
        $send = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$send || !hash_equals($send['authorization_key'],$grant) || !hash_equals($send['payload_digest'],$digest)) {
            throw new AgentConnectionExceptionV1('The approved message has changed.', 409);
        }
        if ($send['status'] === 'failed') throw new AgentConnectionExceptionV1('Gmail rejected this send. Review a new draft to try again.', 422);

        if ($send['status'] === 'pending') {
            // The earlier access call refreshed the token before taking this send lock.
            $token = ai_decrypt_secret($row['access_token_enc']);
            if ($token === '') throw new AgentConnectionExceptionV1('Reconnect Gmail to send.', 401);
            if ($created && $beforeDispatch !== null) $beforeDispatch($pdo,agent_connections_view_v1($row));
            try {
                $dispatched = $created;
                $verified = $created
                    ? agent_connection_gmail_send_v1($token,$row['account_email'],$to,$subject,$body,$operationKey,$http)
                    : agent_connection_gmail_find_sent_v1($token,$operationKey,$http);
            } catch (AgentConnectionGmailSendUnknownV1 $e) {
                throw new AgentConnectionExceptionV1('Gmail delivery is unconfirmed. Check this review again to reconcile it.', 502, $e);
            } catch (AgentConnectionGmailExceptionV1 $e) {
                if (!$created) throw $e;
                $failed = $pdo->prepare("UPDATE agent_connection_sends_v1 SET status='failed' WHERE id=?");
                $failed->execute([(int)$send['id']]);
                if ($e->getCode() === 401) {
                    $invalidate = $pdo->prepare('UPDATE agent_connections_v1 SET access_expires_at=0 WHERE id=?');
                    $invalidate->execute([$connectionId]);
                }
                $pdo->commit();
                throw new AgentConnectionExceptionV1('Gmail rejected this send. Review a new draft to try again.', 422, $e);
            }
            if (!$verified) throw new AgentConnectionExceptionV1('Gmail delivery is still unconfirmed.', 502);
            $send['provider_message_id'] = $verified['message_id'];
            $send['provider_thread_id'] = $verified['thread_id'];
            $send['sent_at'] = (int)($verified['sent_at'] ?? time());
            $update = $pdo->prepare("UPDATE agent_connection_sends_v1 SET status='sent',provider_message_id=?,provider_thread_id=?,sent_at=? WHERE id=?");
            $update->execute([$send['provider_message_id'],$send['provider_thread_id'],$send['sent_at'],(int)$send['id']]);
        }
        $receipt = [
            'message_id'=>$send['provider_message_id'],'thread_id'=>$send['provider_thread_id'],
            'from'=>$row['account_email'],'to'=>$to,'sent_at'=>(int)$send['sent_at'],
            'operation_key'=>$operationKey,'connection_id'=>$connectionId,'authorization_key'=>$grant,
        ];
        if ($onVerified !== null) $onVerified($pdo, $receipt);
        $pdo->commit();
        return $receipt;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($created && !$dispatched) {
            $release = $pdo->prepare("DELETE FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND operation_key=? AND status='pending'");
            $release->execute([$ownerId,$connectionId,$grant,$operationKey]);
        }
        throw $e;
    }
}
