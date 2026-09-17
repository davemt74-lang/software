<?php
declare(strict_types=1);

/**
 * VP3 v20.00 Browser Companion device authentication.
 *
 * The browser extension is a first-party VP3 client, but it never receives the
 * user's password or reuses the web session as an API credential. Pairing is
 * explicitly approved in VP3, persistent device credentials are stored only as
 * SHA-256 hashes, and normal extension calls use short-lived bearer sessions.
 */
const VP3_EXTENSION_DEVICE_AUTH_V2000 = 'extension-device-auth-v2000-20260917';
const VP3_EXTENSION_CONNECTION_TTL_V2000 = 600;
const VP3_EXTENSION_SESSION_TTL_V2000 = 3600;

function vp3_extension_capabilities_v2000(): array
{
    return [
        'team.destinations.read',
        'team.share.create',
        'team.chat.read',
        'agent.message',
        'knowledge.write',
        'task.propose',
        'notifications.read',
        'browser.asset.upload',
    ];
}

function vp3_extension_schema_ready_v2000(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('extension_devices_v2000')
        && table_exists('extension_connection_requests_v2000')
        && table_exists('extension_sessions_v2000');
}

function vp3_extension_require_schema_v2000(?PDO $pdo = null): PDO
{
    $pdo ??= db();
    if (!$pdo || !vp3_extension_schema_ready_v2000($pdo)) {
        throw new RuntimeException('Run the VP3 database upgrade to enable Browser Companion connections.');
    }
    return $pdo;
}

function vp3_extension_ensure_schema_v2000(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    if (vp3_extension_schema_ready_v2000($pdo)) return;
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Browser Companion schema must be installed before starting a transaction.');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS extension_devices_v2000 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      installation_id CHAR(36) NOT NULL,
      device_name VARCHAR(120) NOT NULL,
      browser_family VARCHAR(40) NOT NULL DEFAULT 'Chrome',
      extension_version VARCHAR(40) NOT NULL DEFAULT '',
      credential_hash CHAR(64) NOT NULL,
      capabilities_json TEXT NOT NULL,
      device_status VARCHAR(20) NOT NULL DEFAULT 'active',
      approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_used_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_extension_device_public (public_id),
      UNIQUE KEY uq_extension_device_installation (installation_id),
      UNIQUE KEY uq_extension_device_credential (credential_hash),
      INDEX idx_extension_device_user (user_id,device_status,updated_at),
      CONSTRAINT fk_extension_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS extension_connection_requests_v2000 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      installation_id CHAR(36) NOT NULL,
      device_name VARCHAR(120) NOT NULL,
      browser_family VARCHAR(40) NOT NULL DEFAULT 'Chrome',
      extension_version VARCHAR(40) NOT NULL DEFAULT '',
      poll_token_hash CHAR(64) NOT NULL,
      approval_token_hash CHAR(64) NOT NULL,
      requested_capabilities_json TEXT NOT NULL,
      request_ip VARCHAR(45) NOT NULL DEFAULT '',
      request_status VARCHAR(20) NOT NULL DEFAULT 'pending',
      approved_by_user_id INT UNSIGNED NULL,
      device_id BIGINT UNSIGNED NULL,
      expires_at DATETIME NOT NULL,
      approved_at DATETIME NULL,
      denied_at DATETIME NULL,
      credential_delivered_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_extension_connection_public (public_id),
      UNIQUE KEY uq_extension_connection_poll (poll_token_hash),
      UNIQUE KEY uq_extension_connection_approval (approval_token_hash),
      INDEX idx_extension_connection_installation (installation_id,request_status,created_at),
      INDEX idx_extension_connection_expiry (request_status,expires_at),
      INDEX idx_extension_connection_user (approved_by_user_id,request_status,updated_at),
      CONSTRAINT fk_extension_connection_user FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_extension_connection_device FOREIGN KEY (device_id) REFERENCES extension_devices_v2000(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS extension_sessions_v2000 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      device_id BIGINT UNSIGNED NOT NULL,
      token_hash CHAR(64) NOT NULL,
      capabilities_json TEXT NOT NULL,
      request_ip VARCHAR(45) NOT NULL DEFAULT '',
      issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      last_used_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_extension_session_public (public_id),
      UNIQUE KEY uq_extension_session_token (token_hash),
      INDEX idx_extension_session_device (device_id,revoked_at,expires_at),
      INDEX idx_extension_session_expiry (expires_at,revoked_at),
      CONSTRAINT fk_extension_session_device FOREIGN KEY (device_id) REFERENCES extension_devices_v2000(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_extension_uuid_v2000(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
}

function vp3_extension_valid_uuid_v2000(string $value): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value));
}

function vp3_extension_secret_v2000(): string
{
    return bin2hex(random_bytes(32));
}

function vp3_extension_hash_v2000(string $secret): string
{
    return hash('sha256', $secret);
}

function vp3_extension_request_ip_v2000(): string
{
    return substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
}

function vp3_extension_capability_filter_v2000(mixed $requested): array
{
    if (!is_array($requested)) return [];
    $allowed = array_fill_keys(vp3_extension_capabilities_v2000(), true);
    $result = [];
    foreach ($requested as $capability) {
        $capability = trim((string)$capability);
        if ($capability !== '' && isset($allowed[$capability])) $result[$capability] = true;
    }
    return array_keys($result);
}

function vp3_extension_json_array_v2000(mixed $value): array
{
    if (is_array($value)) return array_values(array_filter(array_map('strval', $value), static fn(string $v): bool => $v !== ''));
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn(string $v): bool => $v !== '')) : [];
}

function vp3_extension_absolute_url_v2000(string $path): string
{
    $relative = url($path);
    $baseUrl = rtrim(trim((string)site_config('base_url', '')), '/');
    if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL)) return $baseUrl.$relative;

    $host = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '' || !preg_match('/^(?:localhost|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/', $host)) return $relative;
    $https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '';
    $scheme = $https ? 'https' : 'http';
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $suffix = ($port > 0 && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) ? ':'.$port : '';
    return $scheme.'://'.$host.$suffix.$relative;
}

function vp3_extension_apply_cors_v2000(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return;

    $allowed = false;
    if (preg_match('#^chrome-extension://[a-p]{32}$#', $origin)) $allowed = true;

    $configured = site_config('extension_allowed_origins', []);
    if (is_string($configured)) $configured = preg_split('/\s*,\s*/', trim($configured)) ?: [];
    if (is_array($configured) && in_array($origin, array_map('strval', $configured), true)) $allowed = true;

    if ($allowed && !headers_sent()) {
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
    }
}

function vp3_extension_connection_create_v2000(PDO $pdo, array $input): array
{
    vp3_extension_require_schema_v2000($pdo);
    $installationId = strtolower(trim((string)($input['installation_id'] ?? '')));
    if (!vp3_extension_valid_uuid_v2000($installationId)) throw new InvalidArgumentException('A valid installation_id is required.');

    $deviceName = trim((string)($input['device_name'] ?? ''));
    if ($deviceName === '' || mb_strlen($deviceName) > 120) throw new InvalidArgumentException('Choose a device name up to 120 characters.');
    $extensionVersion = trim((string)($input['extension_version'] ?? ''));
    if (mb_strlen($extensionVersion) > 40) throw new InvalidArgumentException('Extension version is too long.');
    $browserFamily = trim((string)($input['browser_family'] ?? 'Chrome')) ?: 'Chrome';
    if (mb_strlen($browserFamily) > 40) throw new InvalidArgumentException('Browser family is too long.');

    $requested = vp3_extension_capability_filter_v2000($input['requested_capabilities'] ?? []);
    if (!$requested) throw new InvalidArgumentException('At least one supported capability is required.');

    $ip = vp3_extension_request_ip_v2000();
    $throttle = $pdo->prepare("SELECT COUNT(*) FROM extension_connection_requests_v2000 WHERE installation_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $throttle->execute([$installationId]);
    if ((int)$throttle->fetchColumn() >= 10) throw new RuntimeException('Too many connection requests. Try again later.');

    // Only one live request per installation is useful. Expire stale pending rows
    // before creating a new request so repeated clicks cannot build an approval queue.
    $pdo->prepare("UPDATE extension_connection_requests_v2000 SET request_status='expired',updated_at=NOW() WHERE installation_id=? AND request_status='pending' AND expires_at<=NOW()")
        ->execute([$installationId]);
    $existing = $pdo->prepare("SELECT public_id FROM extension_connection_requests_v2000 WHERE installation_id=? AND request_status='pending' AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
    $existing->execute([$installationId]);
    if ($existing->fetchColumn()) throw new RuntimeException('A Browser Companion connection request is already pending for this installation.');

    $publicId = vp3_extension_uuid_v2000();
    $pollToken = vp3_extension_secret_v2000();
    $approvalToken = vp3_extension_secret_v2000();
    $stmt = $pdo->prepare("INSERT INTO extension_connection_requests_v2000
      (public_id,installation_id,device_name,browser_family,extension_version,poll_token_hash,approval_token_hash,requested_capabilities_json,request_ip,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
    $stmt->execute([
        $publicId,$installationId,$deviceName,$browserFamily,$extensionVersion,
        vp3_extension_hash_v2000($pollToken),vp3_extension_hash_v2000($approvalToken),
        json_encode($requested, JSON_UNESCAPED_SLASHES),$ip,
    ]);

    return [
        'id' => $publicId,
        'poll_token' => $pollToken,
        'approval_url' => vp3_extension_absolute_url_v2000('/extension-connect.php?id='.rawurlencode($publicId).'&approval_token='.rawurlencode($approvalToken)),
        'expires_at' => date(DATE_ATOM, time() + VP3_EXTENSION_CONNECTION_TTL_V2000),
    ];
}

function vp3_extension_connection_for_approval_v2000(PDO $pdo, string $publicId, string $approvalToken, bool $forUpdate = false): ?array
{
    if (!vp3_extension_valid_uuid_v2000($publicId) || !preg_match('/^[a-f0-9]{64}$/', $approvalToken)) return null;
    $stmt = $pdo->prepare('SELECT * FROM extension_connection_requests_v2000 WHERE public_id=? AND approval_token_hash=? LIMIT 1'.($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$publicId, vp3_extension_hash_v2000($approvalToken)]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function vp3_extension_connection_decide_v2000(PDO $pdo, string $publicId, string $approvalToken, int $userId, string $decision): array
{
    vp3_extension_require_schema_v2000($pdo);
    if ($userId < 1) throw new RuntimeException('Sign in to approve this connection.');
    if (!in_array($decision, ['approve','deny'], true)) throw new InvalidArgumentException('Choose approve or deny.');

    $pdo->beginTransaction();
    try {
        $row = vp3_extension_connection_for_approval_v2000($pdo, $publicId, $approvalToken, true);
        if (!$row) throw new RuntimeException('This Browser Companion request is not available.');
        $status = (string)$row['request_status'];
        if ($status !== 'pending') {
            $pdo->commit();
            return ['status' => $status];
        }
        if (strtotime((string)$row['expires_at']) <= time()) {
            $pdo->prepare("UPDATE extension_connection_requests_v2000 SET request_status='expired',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
            $pdo->commit();
            return ['status' => 'expired'];
        }

        $active = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_active=1 LIMIT 1');
        $active->execute([$userId]);
        if (!$active->fetchColumn()) throw new RuntimeException('This VP3 account is not active.');

        if ($decision === 'deny') {
            $pdo->prepare("UPDATE extension_connection_requests_v2000 SET request_status='denied',approved_by_user_id=?,denied_at=NOW(),updated_at=NOW() WHERE id=?")
                ->execute([$userId,(int)$row['id']]);
            $pdo->commit();
            return ['status' => 'denied'];
        }

        $pdo->prepare("UPDATE extension_connection_requests_v2000 SET request_status='approved',approved_by_user_id=?,approved_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([$userId,(int)$row['id']]);
        $pdo->commit();
        return ['status' => 'approved'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function vp3_extension_connection_poll_v2000(PDO $pdo, string $publicId, string $pollToken, string $installationId): array
{
    vp3_extension_require_schema_v2000($pdo);
    $publicId = strtolower(trim($publicId));
    $installationId = strtolower(trim($installationId));
    if (!vp3_extension_valid_uuid_v2000($publicId) || !vp3_extension_valid_uuid_v2000($installationId) || !preg_match('/^[a-f0-9]{64}$/', $pollToken)) {
        throw new InvalidArgumentException('Connection request credentials are invalid.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM extension_connection_requests_v2000 WHERE public_id=? AND installation_id=? AND poll_token_hash=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$publicId,$installationId,vp3_extension_hash_v2000($pollToken)]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Connection request not found.');

        $status = (string)$row['request_status'];
        if ($status === 'pending' && strtotime((string)$row['expires_at']) <= time()) {
            $status = 'expired';
            $pdo->prepare("UPDATE extension_connection_requests_v2000 SET request_status='expired',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        }
        if ($status !== 'approved') {
            $pdo->commit();
            return ['status' => $status];
        }

        $deviceId = (int)($row['device_id'] ?? 0);
        if ($deviceId > 0 || !empty($row['credential_delivered_at'])) {
            $devicePublic = null;
            if ($deviceId > 0) {
                $deviceStmt = $pdo->prepare('SELECT public_id FROM extension_devices_v2000 WHERE id=? LIMIT 1');
                $deviceStmt->execute([$deviceId]);
                $devicePublic = $deviceStmt->fetchColumn() ?: null;
            }
            $pdo->commit();
            return ['status' => 'approved','credential_delivered' => true,'device_id' => $devicePublic];
        }

        $userId = (int)($row['approved_by_user_id'] ?? 0);
        if ($userId < 1) throw new RuntimeException('Approved connection is missing its VP3 account.');
        $userStmt = $pdo->prepare('SELECT id,display_name,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || (int)$user['is_active'] !== 1) throw new RuntimeException('The approving VP3 account is no longer active.');

        $credential = vp3_extension_secret_v2000();
        $credentialHash = vp3_extension_hash_v2000($credential);
        $capabilities = vp3_extension_capability_filter_v2000(vp3_extension_json_array_v2000($row['requested_capabilities_json']));
        $devicePublic = vp3_extension_uuid_v2000();

        $existing = $pdo->prepare('SELECT id FROM extension_devices_v2000 WHERE installation_id=? LIMIT 1 FOR UPDATE');
        $existing->execute([$installationId]);
        $existingId = (int)($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $pdo->prepare("UPDATE extension_sessions_v2000 SET revoked_at=COALESCE(revoked_at,NOW()) WHERE device_id=? AND revoked_at IS NULL")->execute([$existingId]);
            $pdo->prepare("UPDATE extension_devices_v2000 SET public_id=?,user_id=?,device_name=?,browser_family=?,extension_version=?,credential_hash=?,capabilities_json=?,device_status='active',approved_at=NOW(),last_used_at=NULL,revoked_at=NULL,updated_at=NOW() WHERE id=?")
                ->execute([$devicePublic,$userId,(string)$row['device_name'],(string)$row['browser_family'],(string)$row['extension_version'],$credentialHash,json_encode($capabilities,JSON_UNESCAPED_SLASHES),$existingId]);
            $deviceId = $existingId;
        } else {
            $pdo->prepare("INSERT INTO extension_devices_v2000 (public_id,user_id,installation_id,device_name,browser_family,extension_version,credential_hash,capabilities_json) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$devicePublic,$userId,$installationId,(string)$row['device_name'],(string)$row['browser_family'],(string)$row['extension_version'],$credentialHash,json_encode($capabilities,JSON_UNESCAPED_SLASHES)]);
            $deviceId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE extension_connection_requests_v2000 SET device_id=?,credential_delivered_at=NOW(),updated_at=NOW() WHERE id=? AND credential_delivered_at IS NULL')
            ->execute([$deviceId,(int)$row['id']]);
        $pdo->commit();

        return [
            'status' => 'approved',
            'credential_delivered' => true,
            'device_id' => $devicePublic,
            'device_credential' => $credential,
            'user' => ['id' => $userId,'display_name' => (string)$user['display_name']],
            'capabilities' => $capabilities,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function vp3_extension_session_issue_v2000(PDO $pdo, string $devicePublicId, string $installationId, string $credential): array
{
    vp3_extension_require_schema_v2000($pdo);
    if (!vp3_extension_valid_uuid_v2000($devicePublicId) || !vp3_extension_valid_uuid_v2000($installationId) || !preg_match('/^[a-f0-9]{64}$/', $credential)) {
        throw new InvalidArgumentException('Device credentials are invalid.');
    }

    $stmt = $pdo->prepare("SELECT d.*,u.display_name,u.is_active FROM extension_devices_v2000 d INNER JOIN users u ON u.id=d.user_id WHERE d.public_id=? AND d.installation_id=? LIMIT 1");
    $stmt->execute([$devicePublicId,$installationId]);
    $device = $stmt->fetch();
    if (!$device || (string)$device['device_status'] !== 'active' || !empty($device['revoked_at']) || (int)$device['is_active'] !== 1 || !hash_equals((string)$device['credential_hash'], vp3_extension_hash_v2000($credential))) {
        throw new RuntimeException('Device authentication failed.');
    }

    $token = vp3_extension_secret_v2000();
    $publicId = vp3_extension_uuid_v2000();
    $capabilities = vp3_extension_capability_filter_v2000(vp3_extension_json_array_v2000($device['capabilities_json']));
    $pdo->prepare("INSERT INTO extension_sessions_v2000 (public_id,device_id,token_hash,capabilities_json,request_ip,expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))")
        ->execute([$publicId,(int)$device['id'],vp3_extension_hash_v2000($token),json_encode($capabilities,JSON_UNESCAPED_SLASHES),vp3_extension_request_ip_v2000()]);
    $pdo->prepare('UPDATE extension_devices_v2000 SET last_used_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$device['id']]);

    return [
        'access_token' => $token,
        'expires_at' => date(DATE_ATOM, time() + VP3_EXTENSION_SESSION_TTL_V2000),
        'capabilities' => $capabilities,
        'device_id' => (string)$device['public_id'],
        'user' => ['id' => (int)$device['user_id'],'display_name' => (string)$device['display_name']],
    ];
}

function vp3_extension_bearer_token_v2000(): string
{
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $match) ? strtolower($match[1]) : '';
}

function vp3_extension_session_authenticate_v2000(PDO $pdo, string $token = ''): ?array
{
    vp3_extension_require_schema_v2000($pdo);
    $token = $token !== '' ? $token : vp3_extension_bearer_token_v2000();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $stmt = $pdo->prepare("SELECT s.id session_db_id,s.public_id session_id,s.capabilities_json session_capabilities,s.expires_at,
      d.id device_db_id,d.public_id device_id,d.user_id,d.capabilities_json device_capabilities,d.device_status,d.revoked_at device_revoked_at,
      u.display_name,u.is_active
      FROM extension_sessions_v2000 s
      INNER JOIN extension_devices_v2000 d ON d.id=s.device_id
      INNER JOIN users u ON u.id=d.user_id
      WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW() LIMIT 1");
    $stmt->execute([vp3_extension_hash_v2000($token)]);
    $row = $stmt->fetch();
    if (!$row || (string)$row['device_status'] !== 'active' || !empty($row['device_revoked_at']) || (int)$row['is_active'] !== 1) return null;

    $caps = array_values(array_intersect(
        vp3_extension_capability_filter_v2000(vp3_extension_json_array_v2000($row['session_capabilities'])),
        vp3_extension_capability_filter_v2000(vp3_extension_json_array_v2000($row['device_capabilities']))
    ));
    $pdo->prepare('UPDATE extension_sessions_v2000 SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['session_db_id']]);
    $pdo->prepare('UPDATE extension_devices_v2000 SET last_used_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$row['device_db_id']]);

    return [
        'session_id' => (string)$row['session_id'],
        'device_id' => (string)$row['device_id'],
        'user_id' => (int)$row['user_id'],
        'display_name' => (string)$row['display_name'],
        'capabilities' => $caps,
        'expires_at' => (string)$row['expires_at'],
    ];
}

function vp3_extension_session_has_capability_v2000(array $session, string $capability): bool
{
    return in_array($capability, $session['capabilities'] ?? [], true);
}

function vp3_extension_device_revoke_v2000(PDO $pdo, int $userId, string $devicePublicId): bool
{
    vp3_extension_require_schema_v2000($pdo);
    if ($userId < 1 || !vp3_extension_valid_uuid_v2000($devicePublicId)) return false;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM extension_devices_v2000 WHERE public_id=? AND user_id=? AND device_status='active' LIMIT 1 FOR UPDATE");
        $stmt->execute([$devicePublicId,$userId]);
        $deviceId = (int)($stmt->fetchColumn() ?: 0);
        if ($deviceId < 1) {
            $pdo->commit();
            return false;
        }
        $pdo->prepare("UPDATE extension_devices_v2000 SET device_status='revoked',revoked_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$deviceId]);
        $pdo->prepare('UPDATE extension_sessions_v2000 SET revoked_at=COALESCE(revoked_at,NOW()) WHERE device_id=? AND revoked_at IS NULL')->execute([$deviceId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function vp3_extension_devices_for_user_v2000(PDO $pdo, int $userId): array
{
    vp3_extension_require_schema_v2000($pdo);
    if ($userId < 1) return [];
    $stmt = $pdo->prepare('SELECT public_id,device_name,browser_family,extension_version,capabilities_json,device_status,approved_at,last_used_at,revoked_at,created_at FROM extension_devices_v2000 WHERE user_id=? ORDER BY updated_at DESC,id DESC');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) $row['capabilities'] = vp3_extension_json_array_v2000($row['capabilities_json'] ?? '[]');
    unset($row);
    return $rows;
}
