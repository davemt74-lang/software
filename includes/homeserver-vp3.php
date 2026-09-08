<?php
declare(strict_types=1);

const VP3_HOMESERVER_STATUS_CACHE_SECONDS = 20;
const VP3_HOMESERVER_MAX_EXE_BYTES = 536870912; // 512 MB

function homeserver_vp3_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS homeserver_connections (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL DEFAULT '',
            relay_token_enc LONGTEXT NULL,
            homeserver_token_enc LONGTEXT NULL,
            pending_request_id VARCHAR(128) NOT NULL DEFAULT '',
            pending_claim_token_enc LONGTEXT NULL,
            pending_code VARCHAR(40) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'unpaired',
            installed_version VARCHAR(64) NOT NULL DEFAULT '',
            last_seen_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            capabilities_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_homeserver_connection_status (status, last_seen_at),
            CONSTRAINT fk_homeserver_connection_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS homeserver_releases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(64) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'stable',
            release_notes TEXT NOT NULL,
            portable_name VARCHAR(255) NOT NULL DEFAULT '',
            portable_path VARCHAR(500) NOT NULL DEFAULT '',
            portable_sha256 CHAR(64) NOT NULL DEFAULT '',
            portable_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            installer_name VARCHAR(255) NOT NULL DEFAULT '',
            installer_path VARCHAR(500) NOT NULL DEFAULT '',
            installer_sha256 CHAR(64) NOT NULL DEFAULT '',
            installer_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_latest TINYINT(1) NOT NULL DEFAULT 0,
            created_by_user_id INT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_homeserver_release_version_channel (version, channel),
            INDEX idx_homeserver_release_current (channel, is_published, is_latest, id),
            CONSTRAINT fk_homeserver_release_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function homeserver_vp3_relay_base_url(): string
{
    global $config;
    $value = trim((string)(getenv('VP3_HOMESERVER_RELAY_URL') ?: ($config['homeserver']['relay_base_url'] ?? '')));
    if ($value === '') {
        return '';
    }
    $value = rtrim($value, '/');
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    return $value;
}

function homeserver_vp3_private_dir(): string
{
    $dir = STONEFELLOW_ROOT . '/private/homeserver-releases';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create private HomeServer storage.');
    }
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n", LOCK_EX);
    }
    return $dir;
}

function homeserver_vp3_credential_key(): string
{
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to protect HomeServer credentials.');
    }
    $privateDir = STONEFELLOW_ROOT . '/private';
    if (!is_dir($privateDir) && !mkdir($privateDir, 0750, true) && !is_dir($privateDir)) {
        throw new RuntimeException('Could not create private credential storage.');
    }
    $path = $privateDir . '/homeserver-vp3.key';
    if (is_file($path)) {
        $decoded = base64_decode(trim((string)file_get_contents($path)), true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }
        throw new RuntimeException('HomeServer credential key is invalid.');
    }

    $key = random_bytes(32);
    $encoded = base64_encode($key) . "\n";
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        try {
            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException('Could not save HomeServer credential key.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
        return $key;
    }

    // Another request may have won the first-run key race.
    $decoded = is_file($path) ? base64_decode(trim((string)file_get_contents($path)), true) : false;
    if (is_string($decoded) && strlen($decoded) === 32) {
        return $decoded;
    }
    throw new RuntimeException('Could not initialize HomeServer credential protection.');
}

function homeserver_vp3_encrypt(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    $key = homeserver_vp3_credential_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Could not protect HomeServer credential.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function homeserver_vp3_decrypt(?string $encoded): string
{
    $encoded = (string)$encoded;
    if ($encoded === '') {
        return '';
    }
    if (!str_starts_with($encoded, 'v1:')) {
        throw new RuntimeException('Unsupported HomeServer credential format.');
    }
    $raw = base64_decode(substr($encoded, 3), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('HomeServer credential is invalid.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', homeserver_vp3_credential_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plaintext)) {
        throw new RuntimeException('Could not decrypt HomeServer credential.');
    }
    return $plaintext;
}

function homeserver_vp3_relay_request(string $method, string $path, ?array $payload = null, string $relayToken = ''): array
{
    $base = homeserver_vp3_relay_base_url();
    if ($base === '') {
        throw new RuntimeException('HomeServer relay is not configured on VP3.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for HomeServer relay access.');
    }
    if (!preg_match('#^/v1/(?:claim|session(?:/rotate)?|request)$#', $path) && $path !== '/health') {
        throw new RuntimeException('Unsupported HomeServer relay path.');
    }

    $ch = curl_init($base . $path);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize HomeServer relay request.');
    }
    $headers = ['Accept: application/json'];
    if ($relayToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $relayToken;
    }
    $method = strtoupper($method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if ($method !== 'GET') {
        $json = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            curl_close($ch);
            throw new RuntimeException('Could not encode HomeServer relay request.');
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body)) {
        throw new RuntimeException($error !== '' ? 'HomeServer relay connection failed.' : 'HomeServer relay returned no response.');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('HomeServer relay returned an invalid response.');
    }
    if ($status < 200 || $status >= 300) {
        $detail = trim((string)($data['detail'] ?? $data['payload']['detail'] ?? ''));
        throw new RuntimeException($detail !== '' ? $detail : 'HomeServer relay request failed.');
    }
    return $data;
}

function homeserver_vp3_remote_operation(string $relayToken, string $operation, array $payload = [], string $homeServerToken = ''): array
{
    $data = homeserver_vp3_relay_request('POST', '/v1/request', [
        'operation' => $operation,
        'payload' => $payload,
        'bearer_token' => $homeServerToken !== '' ? $homeServerToken : null,
    ], $relayToken);
    $result = $data['payload'] ?? [];
    return is_array($result) ? $result : [];
}

function homeserver_vp3_connection(int $userId): ?array
{
    if ($userId < 1) {
        return null;
    }
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM homeserver_connections WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function homeserver_vp3_claim_and_pair(int $userId, string $claimCode): array
{
    if ($userId < 1) {
        throw new RuntimeException('A signed-in user is required.');
    }
    $claimCode = strtoupper(trim($claimCode));
    if (!preg_match('/^[A-Z0-9-]{8,40}$/', $claimCode)) {
        throw new RuntimeException('Enter the Remote Bridge claim code shown by HomeServer.');
    }
    $claim = homeserver_vp3_relay_request('POST', '/v1/claim', ['claim_code' => $claimCode]);
    $relayToken = trim((string)($claim['relay_token'] ?? ''));
    $deviceId = trim((string)($claim['device_id'] ?? ''));
    if (strlen($relayToken) < 20 || $deviceId === '') {
        throw new RuntimeException('HomeServer relay returned an invalid claim response.');
    }

    $permissions = [
        'agent.chat','awareness.read','contacts.read','events.read','events.write',
        'knowledge.search','memory.read','memory.write','notifications.read','plugins.read',
        'tasks.read','tasks.write','tools.execute','usage.read','usage.write',
    ];
    $pairing = homeserver_vp3_remote_operation($relayToken, 'pair.request', [
        'app_key' => 'vp3',
        'app_name' => 'VP3',
        'permissions' => $permissions,
    ]);
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = trim((string)($pairing['code'] ?? ''));
    if ($requestId === '' || strlen($claimToken) < 20 || $approvalCode === '') {
        // Rotate before discarding the just-issued relay credential.
        try { homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken); } catch (Throwable $e) {}
        throw new RuntimeException('HomeServer returned an unsupported pairing response.');
    }

    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO homeserver_connections (
            user_id,device_id,relay_token_enc,pending_request_id,pending_claim_token_enc,pending_code,status,last_error
         ) VALUES (?,?,?,?,?,?,'awaiting_approval','')
         ON DUPLICATE KEY UPDATE
            device_id=VALUES(device_id), relay_token_enc=VALUES(relay_token_enc),
            homeserver_token_enc=NULL, pending_request_id=VALUES(pending_request_id),
            pending_claim_token_enc=VALUES(pending_claim_token_enc), pending_code=VALUES(pending_code),
            status='awaiting_approval', installed_version='', last_error='', last_checked_at=NULL"
    );
    $stmt->execute([
        $userId,
        $deviceId,
        homeserver_vp3_encrypt($relayToken),
        $requestId,
        homeserver_vp3_encrypt($claimToken),
        $approvalCode,
    ]);
    return [
        'device_id' => $deviceId,
        'approval_code' => $approvalCode,
        'expires_at' => (string)($pairing['expires_at'] ?? ''),
        'permissions' => $pairing['permissions'] ?? $permissions,
    ];
}

function homeserver_vp3_check_pairing(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) {
        throw new RuntimeException('HomeServer has not been claimed.');
    }
    $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    $claimToken = homeserver_vp3_decrypt((string)($row['pending_claim_token_enc'] ?? ''));
    $requestId = trim((string)($row['pending_request_id'] ?? ''));
    if ($relayToken === '' || $claimToken === '' || $requestId === '') {
        throw new RuntimeException('No HomeServer pairing approval is pending.');
    }
    $pairing = homeserver_vp3_remote_operation($relayToken, 'pair.status', [
        'request_id' => $requestId,
        'claim_token' => $claimToken,
    ]);
    $status = (string)($pairing['status'] ?? 'pending');
    if (!empty($pairing['ready'])) {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE homeserver_connections
             SET homeserver_token_enc=?, pending_request_id='', pending_claim_token_enc=NULL,
                 pending_code='', status='paired', last_error='', last_checked_at=NULL
             WHERE user_id=?"
        );
        $stmt->execute([homeserver_vp3_encrypt($claimToken), $userId]);
        $status = 'paired';
    } elseif (in_array($status, ['expired', 'denied'], true)) {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE homeserver_connections
             SET pending_request_id='', pending_claim_token_enc=NULL, pending_code='', status=?, last_error=?
             WHERE user_id=?"
        );
        $stmt->execute([$status, 'HomeServer pairing ' . $status . '.', $userId]);
    }
    return ['status' => $status, 'ready' => $status === 'paired', 'permissions' => $pairing['permissions'] ?? []];
}

function homeserver_vp3_disconnect(int $userId): void
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) {
        return;
    }
    try {
        $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
        if ($relayToken !== '') {
            // Rotation invalidates the credential VP3 stored. The replacement is intentionally discarded.
            homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken);
        }
    } catch (Throwable $e) {
        // Local disconnect must still succeed if the relay is unavailable.
    }
    $pdo = db();
    if ($pdo) {
        $pdo->prepare('DELETE FROM homeserver_connections WHERE user_id=?')->execute([$userId]);
    }
}

function homeserver_vp3_version_valid(string $version): bool
{
    return (bool)preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', trim($version));
}

function homeserver_vp3_channel_valid(string $channel): bool
{
    return in_array($channel, ['stable', 'beta', 'dev'], true);
}

function homeserver_vp3_latest_release(string $channel = 'stable'): ?array
{
    if (!homeserver_vp3_channel_valid($channel)) {
        $channel = 'stable';
    }
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM homeserver_releases WHERE channel=? AND is_published=1 ORDER BY is_latest DESC, published_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$channel]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function homeserver_vp3_release_public(array $release): array
{
    $id = (int)($release['id'] ?? 0);
    $channel = (string)($release['channel'] ?? 'stable');
    $portable = (string)($release['portable_path'] ?? '') !== '' ? [
        'name' => (string)$release['portable_name'],
        'size' => (int)$release['portable_size'],
        'sha256' => (string)$release['portable_sha256'],
        'url' => url('/homeserver-download.php?id=' . $id . '&type=portable'),
    ] : null;
    $installer = (string)($release['installer_path'] ?? '') !== '' ? [
        'name' => (string)$release['installer_name'],
        'size' => (int)$release['installer_size'],
        'sha256' => (string)$release['installer_sha256'],
        'url' => url('/homeserver-download.php?id=' . $id . '&type=installer'),
    ] : null;
    return [
        'id' => $id,
        'version' => (string)($release['version'] ?? ''),
        'channel' => $channel,
        'release_notes' => (string)($release['release_notes'] ?? ''),
        'published_at' => $release['published_at'] ?? null,
        'is_latest' => !empty($release['is_latest']),
        'portable' => $portable,
        'installer' => $installer,
    ];
}

function homeserver_vp3_validate_pe_file(string $path): void
{
    $size = is_file($path) ? filesize($path) : false;
    if (!is_int($size) || $size < 68) {
        throw new RuntimeException('HomeServer executable is not a valid Windows PE file.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not inspect uploaded HomeServer executable.');
    }
    try {
        $header = fread($handle, 64);
        if (!is_string($header) || strlen($header) < 64 || substr($header, 0, 2) !== 'MZ') {
            throw new RuntimeException('HomeServer executable is missing the Windows MZ header.');
        }
        $unpacked = unpack('Voffset', substr($header, 60, 4));
        $peOffset = (int)($unpacked['offset'] ?? 0);
        if ($peOffset < 64 || $peOffset > $size - 4 || fseek($handle, $peOffset) !== 0) {
            throw new RuntimeException('HomeServer executable has an invalid PE header offset.');
        }
        $signature = fread($handle, 4);
        if ($signature !== "PE\0\0") {
            throw new RuntimeException('HomeServer executable is missing the Windows PE signature.');
        }
    } finally {
        fclose($handle);
    }
}

function homeserver_vp3_store_exe(array $file, string $kind, string $version): ?array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('HomeServer executable upload failed.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > VP3_HOMESERVER_MAX_EXE_BYTES) {
        throw new RuntimeException('HomeServer executable must be 512 MB or smaller.');
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'exe') {
        throw new RuntimeException('Only Windows .exe HomeServer files are accepted.');
    }
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('HomeServer executable was not received as a valid HTTP upload.');
    }
    homeserver_vp3_validate_pe_file($tmp);
    $dir = homeserver_vp3_private_dir();
    $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version) ?: 'release';
    $stored = bin2hex(random_bytes(16)) . '-' . $kind . '-' . $safeVersion . '.exe';
    $destination = $dir . '/' . $stored;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save HomeServer executable.');
    }
    @chmod($destination, 0640);
    $hash = hash_file('sha256', $destination);
    $actualSize = filesize($destination);
    if (!is_string($hash) || strlen($hash) !== 64 || !is_int($actualSize)) {
        @unlink($destination);
        throw new RuntimeException('Could not verify saved HomeServer executable.');
    }
    $downloadName = $kind === 'installer'
        ? 'HomeServer-v' . $version . '-Setup.exe'
        : 'HomeServer-v' . $version . '.exe';
    return [
        'name' => $downloadName,
        'path' => $destination,
        'sha256' => $hash,
        'size' => $actualSize,
    ];
}

function homeserver_vp3_create_release(array $input, array $files, int $createdBy): int
{
    $version = trim((string)($input['version'] ?? ''));
    $channel = strtolower(trim((string)($input['channel'] ?? 'stable')));
    if (!homeserver_vp3_version_valid($version)) {
        throw new RuntimeException('Enter a semantic version such as 0.17.1.');
    }
    if (!homeserver_vp3_channel_valid($channel)) {
        throw new RuntimeException('Choose a valid release channel.');
    }
    $portable = homeserver_vp3_store_exe($files['portable_exe'] ?? [], 'portable', $version);
    $installer = null;
    try {
        $installer = homeserver_vp3_store_exe($files['installer_exe'] ?? [], 'installer', $version);
        if (!$portable && !$installer) {
            throw new RuntimeException('Upload at least one HomeServer executable.');
        }
        $pdo = db();
        if (!$pdo) {
            throw new RuntimeException('Database connection is unavailable.');
        }
        homeserver_vp3_ensure_schema($pdo);
        $published = !empty($input['is_published']) ? 1 : 0;
        $latest = $published && !empty($input['is_latest']) ? 1 : 0;
        $pdo->beginTransaction();
        if ($latest) {
            $pdo->prepare('UPDATE homeserver_releases SET is_latest=0 WHERE channel=?')->execute([$channel]);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO homeserver_releases (
                version,channel,release_notes,
                portable_name,portable_path,portable_sha256,portable_size,
                installer_name,installer_path,installer_sha256,installer_size,
                is_published,is_latest,created_by_user_id,published_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL))"
        );
        $stmt->execute([
            $version,$channel,trim((string)($input['release_notes'] ?? '')),
            (string)($portable['name'] ?? ''),(string)($portable['path'] ?? ''),(string)($portable['sha256'] ?? ''),(int)($portable['size'] ?? 0),
            (string)($installer['name'] ?? ''),(string)($installer['path'] ?? ''),(string)($installer['sha256'] ?? ''),(int)($installer['size'] ?? 0),
            $published,$latest,$createdBy,$published,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ([$portable, $installer] as $stored) {
            if (is_array($stored) && !empty($stored['path'])) {
                @unlink((string)$stored['path']);
            }
        }
        throw $e;
    }
}

function homeserver_vp3_set_release_state(int $releaseId, string $action): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM homeserver_releases WHERE id=? LIMIT 1');
    $stmt->execute([$releaseId]);
    $release = $stmt->fetch();
    if (!$release) {
        throw new RuntimeException('HomeServer release was not found.');
    }
    if ($action === 'publish') {
        $pdo->prepare('UPDATE homeserver_releases SET is_published=1,published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([$releaseId]);
        return;
    }
    if ($action === 'unpublish') {
        $pdo->prepare('UPDATE homeserver_releases SET is_published=0,is_latest=0 WHERE id=?')->execute([$releaseId]);
        return;
    }
    if ($action === 'latest') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE homeserver_releases SET is_latest=0 WHERE channel=?')->execute([(string)$release['channel']]);
            $pdo->prepare('UPDATE homeserver_releases SET is_published=1,is_latest=1,published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([$releaseId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }
    throw new RuntimeException('Unsupported HomeServer release action.');
}

function homeserver_vp3_delete_release(int $releaseId): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT portable_path,installer_path FROM homeserver_releases WHERE id=? LIMIT 1');
    $stmt->execute([$releaseId]);
    $release = $stmt->fetch();
    if (!$release) {
        return;
    }
    $pdo->prepare('DELETE FROM homeserver_releases WHERE id=?')->execute([$releaseId]);
    $base = realpath(homeserver_vp3_private_dir());
    foreach (['portable_path','installer_path'] as $key) {
        $path = (string)($release[$key] ?? '');
        $real = $path !== '' && is_file($path) ? realpath($path) : false;
        if ($base && $real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }
}

function homeserver_vp3_releases(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    homeserver_vp3_ensure_schema($pdo);
    return $pdo->query('SELECT * FROM homeserver_releases ORDER BY created_at DESC,id DESC')->fetchAll();
}

function homeserver_vp3_status(int $userId, bool $forceRefresh = false): array
{
    $latest = homeserver_vp3_latest_release('stable');
    $latestPublic = $latest ? homeserver_vp3_release_public($latest) : null;
    $relayConfigured = homeserver_vp3_relay_base_url() !== '';
    $row = homeserver_vp3_connection($userId);
    if (!$row) {
        return [
            'state' => 'unpaired','connected' => false,'paired' => false,'relay_configured' => $relayConfigured,
            'device_id' => '', 'last_seen_at' => null, 'installed_version' => '', 'latest_release' => $latestPublic,
            'update_available' => false, 'agent_brain_ready' => false, 'inference' => null, 'capabilities' => [],
            'pairing' => null, 'error' => $relayConfigured ? '' : 'VP3 HomeServer relay is not configured.',
        ];
    }

    $cachedAt = !empty($row['last_checked_at']) ? strtotime((string)$row['last_checked_at']) : false;
    $cacheFresh = !$forceRefresh && $cachedAt && (time() - $cachedAt) < VP3_HOMESERVER_STATUS_CACHE_SECONDS;
    $capabilities = [];
    if (!empty($row['capabilities_json'])) {
        $decoded = json_decode((string)$row['capabilities_json'], true);
        if (is_array($decoded)) $capabilities = $decoded;
    }
    $connected = in_array((string)$row['status'], ['connected','paired'], true) && !empty($row['last_seen_at']);
    $error = (string)($row['last_error'] ?? '');

    if (!$cacheFresh && $relayConfigured) {
        try {
            $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
            if ($relayToken === '') {
                throw new RuntimeException('VP3 does not have a relay credential for this HomeServer.');
            }
            $session = homeserver_vp3_relay_request('GET', '/v1/session', null, $relayToken);
            $connected = !empty($session['connected']);
            if ($connected) {
                $capabilities = homeserver_vp3_remote_operation($relayToken, 'capabilities');
                $error = '';
            } else {
                $error = 'HomeServer is offline.';
            }
            $installed = $connected ? trim((string)($capabilities['version'] ?? '')) : (string)$row['installed_version'];
            $status = $connected ? (!empty($row['homeserver_token_enc']) ? 'paired' : 'connected') : 'offline';
            $pdo = db();
            $stmt = $pdo->prepare(
                'UPDATE homeserver_connections SET status=?,installed_version=?,last_seen_at=IF(?=1,NOW(),last_seen_at),last_checked_at=NOW(),last_error=?,capabilities_json=? WHERE user_id=?'
            );
            $stmt->execute([
                $status,$installed,$connected ? 1 : 0,$error,
                $connected ? json_encode($capabilities, JSON_UNESCAPED_SLASHES) : (string)($row['capabilities_json'] ?? ''),
                $userId,
            ]);
            $row = homeserver_vp3_connection($userId) ?? $row;
        } catch (Throwable $e) {
            $connected = false;
            $error = mb_substr($e->getMessage(), 0, 500);
            $pdo = db();
            if ($pdo) {
                $pdo->prepare("UPDATE homeserver_connections SET status='error',last_checked_at=NOW(),last_error=? WHERE user_id=?")
                    ->execute([$error,$userId]);
            }
            $row['status'] = 'error';
        }
    }

    $installedVersion = trim((string)($row['installed_version'] ?? ($capabilities['version'] ?? '')));
    $latestVersion = trim((string)($latest['version'] ?? ''));
    $updateAvailable = homeserver_vp3_version_valid($installedVersion)
        && homeserver_vp3_version_valid($latestVersion)
        && version_compare($installedVersion, $latestVersion, '<');
    $inference = isset($capabilities['inference']) && is_array($capabilities['inference']) ? $capabilities['inference'] : null;
    $features = isset($capabilities['features']) && is_array($capabilities['features']) ? array_values($capabilities['features']) : [];
    $paired = !empty($row['homeserver_token_enc']);
    $pairing = null;
    if (!$paired && !empty($row['pending_request_id'])) {
        $pairing = [
            'status' => (string)($row['status'] ?? 'awaiting_approval'),
            'approval_code' => (string)($row['pending_code'] ?? ''),
        ];
    }
    return [
        'state' => $connected ? ($paired ? 'connected' : 'claimed') : (string)($row['status'] ?? 'offline'),
        'connected' => $connected,
        'paired' => $paired,
        'relay_configured' => $relayConfigured,
        'device_id' => (string)($row['device_id'] ?? ''),
        'last_seen_at' => $row['last_seen_at'] ?? null,
        'installed_version' => $installedVersion,
        'latest_release' => $latestPublic,
        'update_available' => $updateAvailable,
        'agent_brain_ready' => $connected && $paired && !empty($inference['available']) && in_array('agent.chat', $features, true),
        'inference' => $inference,
        'capabilities' => $features,
        'pairing' => $pairing,
        'error' => $error,
    ];
}
