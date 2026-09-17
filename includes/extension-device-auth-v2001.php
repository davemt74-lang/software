<?php
declare(strict_types=1);

/**
 * VP3 v20.01 Browser Companion security/audit hardening.
 *
 * v20.00 remains the stable pairing/credential primitive. This layer defines
 * effective authorization and lifecycle policy. A stored device capability is
 * only an approved client scope; every usable capability is intersected with
 * the user's current VP3 permission matrix on issue and on every authenticated
 * API request.
 */
const VP3_EXTENSION_SECURITY_V2001 = 'extension-device-auth-v2001-20260917';
const VP3_EXTENSION_SESSION_ISSUE_LIMIT_V2001 = 60;
const VP3_EXTENSION_ACTIVE_SESSION_LIMIT_V2001 = 8;

final class VP3ExtensionSecurityExceptionV2001 extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

function vp3_extension_capability_permission_map_v2001(): array
{
    return [
        'team.destinations.read' => 'chat.access',
        'team.share.create' => 'chat.access',
        'team.chat.read' => 'chat.access',
        'agent.message' => 'chat.access',
        'knowledge.write' => 'knowledge.manage',
        'task.propose' => 'chat.access',
        'notifications.read' => 'account.access',
        'browser.asset.upload' => 'account.access',
    ];
}

function vp3_extension_user_for_permission_v2001(PDO $pdo, int $userId): ?array
{
    if ($userId < 1) return null;
    $stmt = $pdo->prepare('SELECT id,display_name,role,is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return is_array($user) && (int)($user['is_active'] ?? 0) === 1 ? $user : null;
}

function vp3_extension_live_capabilities_v2001(PDO $pdo, int $userId, mixed $requested): array
{
    $requested = vp3_extension_capability_filter_v2000(is_array($requested) ? $requested : vp3_extension_json_array_v2000($requested));
    if (!$requested) return [];
    $user = vp3_extension_user_for_permission_v2001($pdo, $userId);
    if (!$user) return [];

    $map = vp3_extension_capability_permission_map_v2001();
    $effective = [];
    foreach ($requested as $capability) {
        $permission = (string)($map[$capability] ?? '');
        if ($permission !== '' && has_permission($permission, $user)) $effective[] = $capability;
    }
    return array_values(array_unique($effective));
}

function vp3_extension_apply_cors_v2001(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || headers_sent()) return;

    $configured = site_config('extension_allowed_origins', []);
    if (is_string($configured)) $configured = preg_split('/\s*,\s*/', trim($configured)) ?: [];
    $allowedOrigins = is_array($configured)
        ? array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $configured)))
        : [];

    $allowUnlisted = filter_var(site_config('extension_allow_unlisted_chrome_origins', false), FILTER_VALIDATE_BOOL);
    $allowed = in_array($origin, $allowedOrigins, true)
        || ($allowUnlisted && (bool)preg_match('#^chrome-extension://[a-p]{32}$#', $origin));

    if ($allowed) {
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
    }
}

function vp3_extension_connection_poll_v2001(PDO $pdo, string $publicId, string $pollToken, string $installationId): array
{
    $result = vp3_extension_connection_poll_v2000($pdo, $publicId, $pollToken, $installationId);
    if (($result['status'] ?? '') !== 'approved') return $result;

    if (!empty($result['user']['id']) && isset($result['capabilities'])) {
        $result['capabilities'] = vp3_extension_live_capabilities_v2001(
            $pdo,
            (int)$result['user']['id'],
            $result['capabilities']
        );
    }

    // The device credential is intentionally one-time. If a client lost the
    // successful response, make the recovery state explicit instead of leaving
    // it polling forever for a secret the server cannot reproduce.
    if (!empty($result['credential_delivered']) && empty($result['device_credential'])) {
        $result['reconnect_required'] = true;
    }
    return $result;
}

function vp3_extension_session_issue_v2001(PDO $pdo, string $devicePublicId, string $installationId, string $credential): array
{
    vp3_extension_require_schema_v2000($pdo);
    if (!vp3_extension_valid_uuid_v2000($devicePublicId) || !vp3_extension_valid_uuid_v2000($installationId) || !preg_match('/^[a-f0-9]{64}$/', $credential)) {
        throw new VP3ExtensionSecurityExceptionV2001('authentication_required', 401, 'Device credentials are invalid.');
    }

    $stmt = $pdo->prepare("SELECT d.id,d.user_id,d.credential_hash,d.capabilities_json,d.device_status,d.revoked_at,u.is_active
        FROM extension_devices_v2000 d
        INNER JOIN users u ON u.id=d.user_id
        WHERE d.public_id=? AND d.installation_id=? LIMIT 1");
    $stmt->execute([$devicePublicId, $installationId]);
    $device = $stmt->fetch();
    if (!$device
        || (string)$device['device_status'] !== 'active'
        || !empty($device['revoked_at'])
        || (int)$device['is_active'] !== 1
        || !hash_equals((string)$device['credential_hash'], vp3_extension_hash_v2000($credential))) {
        throw new VP3ExtensionSecurityExceptionV2001('authentication_required', 401, 'Device authentication failed.');
    }

    $deviceDbId = (int)$device['id'];
    $pdo->prepare("DELETE FROM extension_sessions_v2000
        WHERE device_id=? AND expires_at<=NOW() AND created_at<DATE_SUB(NOW(),INTERVAL 1 DAY)")
        ->execute([$deviceDbId]);

    $rate = $pdo->prepare("SELECT COUNT(*) FROM extension_sessions_v2000
        WHERE device_id=? AND issued_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $rate->execute([$deviceDbId]);
    if ((int)$rate->fetchColumn() >= VP3_EXTENSION_SESSION_ISSUE_LIMIT_V2001) {
        throw new VP3ExtensionSecurityExceptionV2001('rate_limited', 429, 'Too many Browser Companion sessions were requested. Try again later.');
    }

    $active = $pdo->prepare("SELECT COUNT(*) FROM extension_sessions_v2000
        WHERE device_id=? AND revoked_at IS NULL AND expires_at>NOW()");
    $active->execute([$deviceDbId]);
    $activeCount = (int)$active->fetchColumn();
    if ($activeCount >= VP3_EXTENSION_ACTIVE_SESSION_LIMIT_V2001) {
        $revoke = $pdo->prepare("UPDATE extension_sessions_v2000
            SET revoked_at=NOW()
            WHERE device_id=? AND revoked_at IS NULL AND expires_at>NOW()
            ORDER BY issued_at ASC,id ASC LIMIT 1");
        while ($activeCount >= VP3_EXTENSION_ACTIVE_SESSION_LIMIT_V2001) {
            $revoke->execute([$deviceDbId]);
            if ($revoke->rowCount() < 1) break;
            $activeCount--;
        }
    }

    $session = vp3_extension_session_issue_v2000($pdo, $devicePublicId, $installationId, $credential);
    $effective = vp3_extension_live_capabilities_v2001($pdo, (int)$device['user_id'], $device['capabilities_json']);
    $session['capabilities'] = $effective;

    if (!empty($session['access_token'])) {
        $pdo->prepare('UPDATE extension_sessions_v2000 SET capabilities_json=? WHERE token_hash=?')
            ->execute([
                json_encode($effective, JSON_UNESCAPED_SLASHES),
                vp3_extension_hash_v2000((string)$session['access_token']),
            ]);
    }
    return $session;
}

function vp3_extension_session_authenticate_v2001(PDO $pdo, string $token = ''): ?array
{
    $session = vp3_extension_session_authenticate_v2000($pdo, $token);
    if (!$session) return null;
    $session['capabilities'] = vp3_extension_live_capabilities_v2001(
        $pdo,
        (int)($session['user_id'] ?? 0),
        $session['capabilities'] ?? []
    );
    return $session;
}

function vp3_extension_session_has_capability_v2001(array $session, string $capability): bool
{
    return in_array($capability, $session['capabilities'] ?? [], true);
}
