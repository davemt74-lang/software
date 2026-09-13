<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-cloud-pairing-v1200.php';

const VP3_HOMESERVER_ACCOUNT_PAIRING_V1210 = 'homeserver-account-pairing-v1210-20260913';
const VP3_HOMESERVER_ACCOUNT_PAIRING_TTL_SECONDS = 900;

function homeserver_account_v1210_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    homeserver_vp3_ensure_schema($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS homeserver_pairing_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            device_id VARCHAR(100) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            redeemed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_homeserver_pairing_token_hash (token_hash),
            INDEX idx_homeserver_pairing_user_status (user_id, status, expires_at),
            CONSTRAINT fk_homeserver_pairing_token_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function homeserver_account_v1210_normalize_token(string $raw): string
{
    $token = strtoupper(trim($raw));
    if (!preg_match('/^VP3-(?:[A-F0-9]{8}-){7}[A-F0-9]{8}$/', $token)) {
        throw new RuntimeException('The VP3 pairing token is invalid. Generate a new token in VP3 Cloud.');
    }
    return $token;
}

function homeserver_account_v1210_hash(string $raw): string
{
    return hash('sha256', homeserver_account_v1210_normalize_token($raw));
}

function homeserver_account_v1210_generate_token(int $userId): array
{
    if ($userId < 1) throw new RuntimeException('Authentication required.');
    if (homeserver_vp3_connection($userId)) {
        throw new RuntimeException('A HomeServer connection already exists. Disconnect and remove it before generating a first-time pairing token.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    homeserver_account_v1210_ensure_schema($pdo);

    $hex = strtoupper(bin2hex(random_bytes(32)));
    $token = 'VP3-' . implode('-', str_split($hex, 8));
    $hash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . VP3_HOMESERVER_ACCOUNT_PAIRING_TTL_SECONDS . ' seconds');

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE homeserver_pairing_tokens
             SET status='revoked'
             WHERE user_id=? AND status IN ('pending','redeeming')"
        )->execute([$userId]);
        $stmt = $pdo->prepare(
            "INSERT INTO homeserver_pairing_tokens(user_id,token_hash,status,expires_at)
             VALUES (?,?,'pending',?)"
        );
        $stmt->execute([$userId, $hash, $expiresAt->format('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'token'=>$token,
        'token_id'=>$id,
        'expires_at'=>$expiresAt->format(DateTimeInterface::ATOM),
        'ttl_seconds'=>VP3_HOMESERVER_ACCOUNT_PAIRING_TTL_SECONDS,
    ];
}

function homeserver_account_v1210_token_status(int $userId): ?array
{
    if ($userId < 1) return null;
    $pdo = db();
    if (!$pdo) return null;
    homeserver_account_v1210_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id,status,device_id,expires_at,redeemed_at,created_at
         FROM homeserver_pairing_tokens
         WHERE user_id=?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((string)$row['status'] === 'pending' && strtotime((string)$row['expires_at'] . ' UTC') <= time()) {
        $pdo->prepare("UPDATE homeserver_pairing_tokens SET status='expired' WHERE id=? AND status='pending'")->execute([(int)$row['id']]);
        $row['status'] = 'expired';
    }
    return [
        'id'=>(int)$row['id'],
        'status'=>(string)$row['status'],
        'device_id'=>(string)$row['device_id'],
        'expires_at'=>(string)$row['expires_at'],
        'redeemed_at'=>$row['redeemed_at'] ?? null,
        'created_at'=>(string)$row['created_at'],
    ];
}

function homeserver_account_v1210_begin_redeem(string $rawToken): array
{
    $hash = homeserver_account_v1210_hash($rawToken);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    homeserver_account_v1210_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM homeserver_pairing_tokens WHERE token_hash=? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('The VP3 pairing token is invalid or expired.');
        if ((string)$row['status'] !== 'pending') {
            throw new RuntimeException('The VP3 pairing token has already been used or revoked.');
        }
        if (strtotime((string)$row['expires_at'] . ' UTC') <= time()) {
            $pdo->prepare("UPDATE homeserver_pairing_tokens SET status='expired' WHERE id=?")->execute([(int)$row['id']]);
            $pdo->commit();
            throw new RuntimeException('The VP3 pairing token expired. Generate a new token in VP3 Cloud.');
        }
        if (homeserver_vp3_connection((int)$row['user_id'])) {
            throw new RuntimeException('This VP3 account already has a HomeServer connection.');
        }
        $pdo->prepare("UPDATE homeserver_pairing_tokens SET status='redeeming' WHERE id=?")->execute([(int)$row['id']]);
        $pdo->commit();
        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function homeserver_account_v1210_reset_redeem(int $tokenId): void
{
    $pdo = db();
    if (!$pdo) return;
    $pdo->prepare(
        "UPDATE homeserver_pairing_tokens
         SET status=IF(expires_at>UTC_TIMESTAMP(),'pending','expired'),device_id=''
         WHERE id=? AND status='redeeming'"
    )->execute([$tokenId]);
}

function homeserver_account_v1210_mark_awaiting(int $tokenId, string $deviceId): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $stmt = $pdo->prepare(
        "UPDATE homeserver_pairing_tokens
         SET status='awaiting_approval',device_id=?,redeemed_at=UTC_TIMESTAMP()
         WHERE id=? AND status='redeeming'"
    );
    $stmt->execute([$deviceId,$tokenId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('The VP3 pairing token could not be finalized.');
}

function homeserver_account_v1210_mark_paired(int $userId, string $deviceId=''): void
{
    $pdo = db();
    if (!$pdo) return;
    homeserver_account_v1210_ensure_schema($pdo);
    $sql = "UPDATE homeserver_pairing_tokens SET status='paired' WHERE user_id=? AND status='awaiting_approval'";
    $args = [$userId];
    if ($deviceId !== '') {
        $sql .= ' AND device_id=?';
        $args[] = $deviceId;
    }
    $pdo->prepare($sql)->execute($args);
}

function homeserver_account_v1210_redeem(string $rawToken, string $relayClaim): array
{
    homeserver_cloud_v1200_relay_security();
    $relayClaim = strtoupper(trim($relayClaim));
    if (!preg_match('/^[A-Z0-9-]{8,40}$/', $relayClaim)) {
        throw new RuntimeException('HomeServer relay device proof is invalid.');
    }

    $tokenRow = homeserver_account_v1210_begin_redeem($rawToken);
    $tokenId = (int)$tokenRow['id'];
    $userId = (int)$tokenRow['user_id'];
    $relayToken = '';
    try {
        $claim = homeserver_vp3_relay_request('POST', '/v1/claim', ['claim_code'=>$relayClaim]);
        $relayToken = trim((string)($claim['relay_token'] ?? ''));
        $deviceId = trim((string)($claim['device_id'] ?? ''));
        if (strlen($relayToken) < 32 || strlen($relayToken) > 512 || !preg_match('/^hs-[a-f0-9]{24}$/', $deviceId)) {
            throw new RuntimeException('HomeServer relay returned an invalid device claim.');
        }
        if (isset($claim['trust_model']) && (string)$claim['trust_model'] !== 'trusted-relay') {
            throw new RuntimeException('HomeServer relay trust model is not supported.');
        }

        $pairing = homeserver_cloud_v1200_pair_request($relayToken);
        homeserver_cloud_v1200_store_pending($userId, $deviceId, $relayToken, $pairing, false);
        homeserver_account_v1210_mark_awaiting($tokenId, $deviceId);

        return [
            'user_id'=>$userId,
            'device_id'=>$deviceId,
            'request_id'=>(string)$pairing['request_id'],
            'expires_at'=>(string)$pairing['expires_at'],
            'permissions'=>$pairing['permissions'],
        ];
    } catch (Throwable $e) {
        if ($relayToken !== '') {
            try { homeserver_vp3_relay_request('POST', '/v1/session/release', [], $relayToken); } catch (Throwable $ignored) {}
        }
        homeserver_account_v1210_reset_redeem($tokenId);
        throw $e;
    }
}
