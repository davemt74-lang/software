<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';
require_once __DIR__.'/agent-connection-youtube-v1.php';

const VP3_CONNECTION_TEST_INTERVAL_V1 = 60;

function agent_connection_health_install_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_health_v1 (
        provider VARCHAR(16) NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        binding_key CHAR(64) NOT NULL DEFAULT '',
        result_code VARCHAR(32) NOT NULL DEFAULT '',
        checked_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        attempt_id CHAR(32) NOT NULL DEFAULT '',
        attempted_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (provider,connection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_connection_health_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT provider, connection_id, binding_key, result_code, checked_at, attempt_id, attempted_at FROM agent_connection_health_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_health_view_v1(string $provider, string $code = '', int $checkedAt = 0): array
{
    [$status,$message] = match ($code) {
        'gmail_setup' => ['setup_ready', 'Google OAuth is reachable and the local callback and client settings are present. The linked mailbox test verifies an authorized Gmail account.'],
        'gmail_verified' => ['healthy', 'Gmail confirmed the linked mailbox.'],
        'youtube_verified' => ['healthy', 'YouTube accepted the API key for a YouTube Data API request.'],
        'gmail_config' => ['needs_attention', 'Check the Google OAuth client ID, client secret, HTTPS callback and PHP cURL.'],
        'gmail_reconnect' => ['needs_attention', 'Reconnect this Gmail account to restore access.'],
        'gmail_denied' => ['needs_attention', 'Google denied the mailbox profile request. Check the Gmail API and reconnect with the requested permissions.'],
        'gmail_identity' => ['needs_attention', 'Gmail returned an identity that could not verify the linked mailbox. Reconnect this account.'],
        'youtube_config' => ['needs_attention', 'Check the API key, PHP cURL, YouTube Data API v3 and the key restrictions for this server.'],
        'provider_limit' => ['needs_attention', 'The provider is limiting requests. Check its quota and try again later.'],
        'provider_unavailable' => ['needs_attention', 'VP3 could not verify the provider response. Try again in a moment.'],
        'paused' => ['unavailable', 'Gmail is paused on this installation.'],
        'storage' => ['unavailable', 'Connection health storage is unavailable. Run Database Upgrade if needed.'],
        'changed' => ['unavailable', 'The connection changed while testing. Refresh and try again.'],
        'test_limit' => ['rate_limited', 'Wait a minute between connection tests.'],
        default => ['unchecked', 'Connection has not been checked yet.'],
    };
    return ['provider'=>$provider,'status'=>$status,'checked_at'=>$checkedAt > 0 ? gmdate('Y-m-d H:i:s',$checkedAt) : null,'message'=>$message];
}

/** Bind the health result to the exact configuration without persisting credentials. */
function agent_connection_health_platform_binding_v1(string $provider): string
{
    if ($provider === 'gmail') {
        $config = agent_connection_gmail_config_v1();
        $secret = $config['client_secret'];
        $enabled = agent_connection_gmail_enabled_v1();
        try {
            $callback = agent_connections_callback_v1();
        } catch (AgentConnectionExceptionV1) {
            $callback = '';
        }
        $reference = [$config['client_id'],$callback];
    } elseif ($provider === 'youtube') {
        $config = agent_connection_youtube_config_v1();
        $secret = $config['api_key'];
        $enabled = agent_connection_youtube_enabled_v1();
        $reference = [];
    } else {
        throw new InvalidArgumentException('Unknown connection provider.');
    }
    return hash_hmac('sha256',serialize([$provider,$enabled,$config['source'],function_exists('curl_init'),$reference,$secret]),ai_master_key() ?? $secret);
}

function agent_connection_health_read_v1(PDO $pdo, string $provider, int $connectionId, string $binding): array
{
    $stmt = $pdo->prepare('SELECT result_code,checked_at FROM agent_connection_health_v1 WHERE provider=? AND connection_id=? AND binding_key=?');
    $stmt->execute([$provider,$connectionId,$binding]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return agent_connection_health_view_v1($provider,(string)($row['result_code'] ?? ''),(int)($row['checked_at'] ?? 0));
}

function agent_connection_health_platform_v1(PDO $pdo, string $provider): array
{
    try {
        return agent_connection_health_read_v1($pdo,$provider,0,agent_connection_health_platform_binding_v1($provider));
    } catch (PDOException) {
        return agent_connection_health_view_v1($provider,'storage');
    }
}

function agent_connection_health_clear_platform_v1(PDO $pdo, string $provider): void
{
    if (!in_array($provider,['gmail','youtube'],true)) return;
    try {
        $stmt = $pdo->prepare("UPDATE agent_connection_health_v1 SET binding_key='',result_code='',checked_at=0,attempt_id='' WHERE provider=? AND connection_id=0");
        $stmt->execute([$provider]);
    } catch (PDOException) {
        // Provider configuration stays available while this migration is pending.
    }
}

function agent_connection_health_member_v1(PDO $pdo, int $ownerId, int $connectionId): array
{
    try {
        $row = agent_connections_find_v1($pdo,$ownerId,$connectionId);
        if (!$row || $row['provider'] !== 'gmail' || $row['status'] === 'disconnected') {
            return agent_connection_health_view_v1('gmail');
        }
        return agent_connection_health_read_v1($pdo,'gmail',$connectionId,hash('sha256',$row['authorization_key']));
    } catch (PDOException) {
        return agent_connection_health_view_v1('gmail','storage');
    }
}

/** Count current linked grants only. Each owner has at most one Gmail connection. */
function agent_connection_health_gmail_counts_v1(PDO $pdo): array
{
    $counts = ['healthy'=>0,'needs_attention'=>0,'unchecked'=>0,'available'=>false];
    try {
        $row = $pdo->query("SELECT
            SUM(c.status='connected' AND h.result_code='gmail_verified') AS healthy,
            SUM(c.status='reauthorize' OR (h.checked_at>0 AND h.result_code<>'gmail_verified')) AS needs_attention,
            SUM(c.status='connected' AND COALESCE(h.checked_at,0)=0) AS unchecked
            FROM agent_connections_v1 c LEFT JOIN agent_connection_health_v1 h
            ON h.provider='gmail' AND h.connection_id=c.id AND h.binding_key=SHA2(c.authorization_key,256)
            WHERE c.provider='gmail' AND c.status<>'disconnected'")->fetch(PDO::FETCH_ASSOC);
        foreach (['healthy','needs_attention','unchecked'] as $key) $counts[$key] = (int)($row[$key] ?? 0);
        $counts['available'] = true;
    } catch (PDOException) {
        // Leave the canonical linked-account inventory available on older installs.
    }
    return $counts;
}

/** Acquire a durable per-connection test slot before any remote request. */
function agent_connection_health_reserve_v1(PDO $pdo, string $provider, int $connectionId): ?string
{
    $insert = $pdo->prepare('INSERT INTO agent_connection_health_v1 (provider,connection_id) VALUES (?,?) ON DUPLICATE KEY UPDATE connection_id=connection_id');
    $insert->execute([$provider,$connectionId]);
    $attempt = bin2hex(random_bytes(16));
    $now = time();
    $stmt = $pdo->prepare('UPDATE agent_connection_health_v1 SET attempt_id=?,attempted_at=? WHERE provider=? AND connection_id=? AND attempted_at<=?');
    $stmt->execute([$attempt,$now,$provider,$connectionId,$now - VP3_CONNECTION_TEST_INTERVAL_V1]);
    return $stmt->rowCount() === 1 ? $attempt : null;
}

function agent_connection_health_complete_v1(PDO $pdo, string $provider, int $connectionId, string $binding, string $attempt, string $code): array
{
    $now = time();
    $stmt = $pdo->prepare("UPDATE agent_connection_health_v1 SET binding_key=?,result_code=?,checked_at=?,attempt_id='' WHERE provider=? AND connection_id=? AND attempt_id=?");
    $stmt->execute([$binding,$code,$now,$provider,$connectionId,$attempt]);
    return $stmt->rowCount() === 1
        ? agent_connection_health_view_v1($provider,$code,$now)
        : agent_connection_health_view_v1($provider,'changed');
}

function agent_connection_health_check_gmail_setup_v1(?callable $http = null): string
{
    $config = agent_connection_gmail_config_v1();
    if (!function_exists('curl_init') || !preg_match('/\A[A-Za-z0-9._-]+\.apps\.googleusercontent\.com\z/D',$config['client_id'])
        || !preg_match('/\A[!-~]{1,512}\z/D',$config['client_secret'])) {
        return 'gmail_config';
    }
    try {
        agent_connections_callback_v1();
    } catch (AgentConnectionExceptionV1) {
        return 'gmail_config';
    }
    $metadata = agent_connection_gmail_request_v1('GET','https://accounts.google.com/.well-known/openid-configuration','',null,false,$http);
    return ($metadata['issuer'] ?? '') === 'https://accounts.google.com'
        && ($metadata['authorization_endpoint'] ?? '') === 'https://accounts.google.com/o/oauth2/v2/auth'
        && ($metadata['token_endpoint'] ?? '') === 'https://oauth2.googleapis.com/token'
        ? 'gmail_setup' : 'provider_unavailable';
}

/** Called by the administrator POST route. Gmail setup never uses a member credential. */
function agent_connection_health_test_platform_v1(PDO $pdo, string $provider, ?callable $http = null): array
{
    $binding = agent_connection_health_platform_binding_v1($provider);
    try {
        $attempt = agent_connection_health_reserve_v1($pdo,$provider,0);
        if ($attempt === null) return agent_connection_health_view_v1($provider,'test_limit');
        if ($provider === 'gmail') {
            try {
                $code = agent_connection_health_check_gmail_setup_v1($http);
            } catch (AgentConnectionGmailExceptionV1 $e) {
                $code = $e->getCode() === 429 ? 'provider_limit' : 'provider_unavailable';
            }
        } else {
            try {
                agent_connection_youtube_test_v1($http);
                $code = 'youtube_verified';
            } catch (AgentConnectionYoutubeExceptionV1 $e) {
                $code = match ($e->getCode()) {
                    400,401,403,503 => 'youtube_config',
                    429 => 'provider_limit',
                    default => 'provider_unavailable',
                };
            }
        }
        if (!hash_equals($binding,agent_connection_health_platform_binding_v1($provider))) {
            return agent_connection_health_view_v1($provider,'changed');
        }
        return agent_connection_health_complete_v1($pdo,$provider,0,$binding,$attempt,$code);
    } catch (PDOException) {
        return agent_connection_health_view_v1($provider,'storage');
    }
}

function agent_connection_health_owned_grant_v1(PDO $pdo, int $ownerId, int $connectionId, string $revision, bool $lock = false): array
{
    $row = agent_connections_find_v1($pdo,$ownerId,$connectionId,$lock);
    if (!$row || $row['provider'] !== 'gmail' || !hash_equals(hash('sha256',$row['authorization_key']),$revision)) {
        throw new AgentConnectionExceptionV1('The connection changed. Refresh and try again.',409);
    }
    return $row;
}

/** The member is testing their own grant, independently of the permissions delegated to their Agent. */
function agent_connection_health_member_token_v1(PDO $pdo, int $ownerId, int $connectionId, string $revision, ?callable $http): string
{
    $pdo->beginTransaction();
    try {
        $row = agent_connection_health_owned_grant_v1($pdo,$ownerId,$connectionId,$revision,true);
        if ($row['status'] !== 'connected' || !hash_equals($row['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
            throw new AgentConnectionExceptionV1('Reconnect Gmail to continue.',401);
        }
        $access = agent_connections_token_v1($pdo,$row,$http);
        $pdo->commit();
        return $access;
    } catch (Throwable $e) {
        if ($e instanceof AgentConnectionExceptionV1 && $e->getPrevious() instanceof AgentConnectionGmailExceptionV1
            && $e->getPrevious()->providerError === 'invalid_grant') {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function agent_connection_health_test_member_v1(PDO $pdo, int $ownerId, int $connectionId, string $revision, ?callable $http = null): array
{
    try {
        $row = agent_connection_health_owned_grant_v1($pdo,$ownerId,$connectionId,$revision);
        if ($row['status'] !== 'connected') return agent_connection_health_view_v1('gmail','gmail_reconnect');
        if (!agent_connection_gmail_ready_v1()) return agent_connection_health_view_v1('gmail','paused');
        $attempt = agent_connection_health_reserve_v1($pdo,'gmail',$connectionId);
        if ($attempt === null) return agent_connection_health_view_v1('gmail','test_limit');
        try {
            $token = agent_connection_health_member_token_v1($pdo,$ownerId,$connectionId,$revision,$http);
            $profile = agent_connection_gmail_request_v1('GET',VP3_GMAIL_API_V1.'/profile?fields=emailAddress',$token,null,false,$http);
            $email = $profile['emailAddress'] ?? '';
            $code = is_string($email) && $email !== '' && strcasecmp($email,$row['account_email']) === 0
                ? 'gmail_verified' : 'gmail_identity';
        } catch (AgentConnectionGmailExceptionV1 $e) {
            $code = match ($e->getCode()) {
                401 => 'gmail_reconnect',
                403 => 'gmail_denied',
                429 => 'provider_limit',
                default => 'provider_unavailable',
            };
        } catch (AgentConnectionExceptionV1 $e) {
            if ($e->getCode() === 409) throw $e;
            $code = $e->getCode() === 401 ? 'gmail_reconnect' : 'provider_unavailable';
        }
        agent_connection_health_owned_grant_v1($pdo,$ownerId,$connectionId,$revision);
        return agent_connection_health_complete_v1($pdo,'gmail',$connectionId,$revision,$attempt,$code);
    } catch (PDOException) {
        return agent_connection_health_view_v1('gmail','storage');
    }
}
