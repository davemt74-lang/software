<?php
declare(strict_types=1);

require_once __DIR__ . '/chrome-extension-releases.php';

const VP3_CLIENT_RELEASE_INTELLIGENCE_V100 = 'client-release-intelligence-v100-20260922';

function client_release_version_valid_v100(string $version): bool
{
    return (bool)preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', trim($version));
}

function client_release_version_state_v100(string $installed, string $latest): string
{
    $installed = trim($installed);
    $latest = trim($latest);
    if (!client_release_version_valid_v100($latest)) return 'unavailable';
    if (!client_release_version_valid_v100($installed)) return 'unknown';
    $cmp = version_compare($installed, $latest);
    if ($cmp < 0) return 'update_available';
    if ($cmp > 0) return 'ahead';
    return 'current';
}

function client_release_browser_latest_v100(): ?array
{
    try {
        $release = chrome_extension_latest_release('stable');
    } catch (Throwable $e) {
        return null;
    }
    if (!$release) return null;
    return [
        'id' => (int)($release['id'] ?? 0),
        'product' => 'browser_companion',
        'version' => trim((string)($release['version'] ?? '')),
        'channel' => (string)($release['channel'] ?? 'stable'),
        'published_at' => $release['published_at'] ?? null,
        'release_notes' => (string)($release['release_notes'] ?? ''),
        'sha256' => (string)($release['package_sha256'] ?? ''),
        'download_url' => url('/chrome-extension-download.php'),
        'rollout' => 'general_availability',
    ];
}

function client_release_browser_snapshot_v100(PDO $pdo, int $userId): array
{
    $latest = client_release_browser_latest_v100();
    $devices = [];
    if ($userId > 0 && function_exists('vp3_extension_schema_ready_v2000') && vp3_extension_schema_ready_v2000($pdo)) {
        try {
            $devices = vp3_extension_devices_for_user_v2000($pdo, $userId);
        } catch (Throwable $e) {
            $devices = [];
        }
    }

    $out = [];
    $updateCount = 0;
    $activeCount = 0;
    foreach ($devices as $device) {
        $installed = trim((string)($device['extension_version'] ?? ''));
        $state = client_release_version_state_v100($installed, (string)($latest['version'] ?? ''));
        $active = (string)($device['device_status'] ?? '') === 'active';
        if ($active) $activeCount++;
        if ($active && $state === 'update_available') $updateCount++;
        $out[] = [
            'device_id' => (string)($device['public_id'] ?? ''),
            'device_name' => (string)($device['device_name'] ?? 'Chrome Browser'),
            'browser_family' => (string)($device['browser_family'] ?? 'Chrome'),
            'installed_version' => $installed,
            'latest_version' => (string)($latest['version'] ?? ''),
            'version_state' => $active ? $state : 'inactive',
            'update_available' => $active && $state === 'update_available',
            'status' => (string)($device['device_status'] ?? ''),
            'last_used_at' => $device['last_used_at'] ?? null,
        ];
    }

    return [
        'product' => 'browser_companion',
        'label' => 'VP3 Browser Companion',
        'channel' => (string)($latest['channel'] ?? 'stable'),
        'latest_release' => $latest,
        'devices' => $out,
        'active_device_count' => $activeCount,
        'update_count' => $updateCount,
        'update_available' => $updateCount > 0,
        'manage_url' => url('/connected-browsers.php'),
        'download_url' => url('/chrome-extension-download.php'),
    ];
}

function client_release_homeserver_snapshot_v100(PDO $pdo, int $userId): array
{
    $latest = null;
    try {
        $row = homeserver_vp3_latest_release('stable');
        $latest = $row ? homeserver_vp3_release_public($row) : null;
    } catch (Throwable $e) {
        $row = null;
    }

    $connection = null;
    if ($userId > 0) {
        try { $connection = homeserver_vp3_connection($userId); } catch (Throwable $e) { $connection = null; }
    }
    $installed = trim((string)($connection['installed_version'] ?? ''));
    $latestVersion = trim((string)($latest['version'] ?? ''));
    $state = client_release_version_state_v100($installed, $latestVersion);
    $connectedState = strtolower(trim((string)($connection['status'] ?? 'unpaired')));
    $paired = !empty($connection['homeserver_token_enc']);
    $update = $paired && $state === 'update_available';

    return [
        'product' => 'homeserver',
        'label' => 'VP3 HomeServer',
        'channel' => (string)($latest['channel'] ?? 'stable'),
        'latest_release' => $latest,
        'installed_version' => $installed,
        'latest_version' => $latestVersion,
        'version_state' => $connection ? $state : 'unpaired',
        'update_available' => $update,
        'paired' => $paired,
        'connection_state' => $connectedState,
        'last_seen_at' => $connection['last_seen_at'] ?? null,
        'manage_url' => url('/settings-homeserver.php'),
    ];
}

function client_release_intelligence_snapshot_v100(PDO $pdo, array $user, bool $reconcile = true): array
{
    $userId = (int)($user['id'] ?? 0);
    $browser = client_release_browser_snapshot_v100($pdo, $userId);
    $homeserver = client_release_homeserver_snapshot_v100($pdo, $userId);
    if ($reconcile && $userId > 0) {
        client_release_intelligence_reconcile_user_v100($pdo, $userId, $browser, $homeserver);
    }
    $updateCount = (int)$browser['update_count'] + (!empty($homeserver['update_available']) ? 1 : 0);

    return [
        'build' => VP3_CLIENT_RELEASE_INTELLIGENCE_V100,
        'channel' => 'stable',
        'browser_companion' => $browser,
        'homeserver' => $homeserver,
        'update_count' => $updateCount,
        'updates_available' => $updateCount > 0,
        'generated_at' => gmdate(DATE_ATOM),
    ];
}

function client_release_intelligence_resolve_notifications_v100(PDO $pdo, int $userId, string $sourceType, ?int $keepReleaseId = null): void
{
    if ($userId < 1 || !table_exists('notifications')) return;
    if ($keepReleaseId && $keepReleaseId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,NOW())
             WHERE user_id=? AND type='client_release_update' AND source_type=?
               AND is_read=0 AND (source_id IS NULL OR source_id<>?)"
        );
        $stmt->execute([$userId, $sourceType, $keepReleaseId]);
        return;
    }
    $stmt = $pdo->prepare(
        "UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,NOW())
         WHERE user_id=? AND type='client_release_update' AND source_type=? AND is_read=0"
    );
    $stmt->execute([$userId, $sourceType]);
}

function client_release_intelligence_reconcile_user_v100(
    PDO $pdo,
    int $userId,
    ?array $browserSnapshot = null,
    ?array $homeserverSnapshot = null
): void {
    if (function_exists('client_release_intelligence_reconcile_user_v110')
        && function_exists('client_release_rollouts_schema_ready_v110')
        && client_release_rollouts_schema_ready_v110($pdo)) {
        client_release_intelligence_reconcile_user_v110($pdo, $userId);
        return;
    }
    if ($userId < 1 || !table_exists('notifications') || !function_exists('create_notification')) return;

    $browserSnapshot ??= client_release_browser_snapshot_v100($pdo, $userId);
    $homeserverSnapshot ??= client_release_homeserver_snapshot_v100($pdo, $userId);

    $browserLatest = is_array($browserSnapshot['latest_release'] ?? null) ? $browserSnapshot['latest_release'] : null;
    $browserReleaseId = (int)($browserLatest['id'] ?? 0);
    if (!empty($browserSnapshot['update_available']) && $browserReleaseId > 0) {
        client_release_intelligence_resolve_notifications_v100($pdo, $userId, 'client_release_browser', $browserReleaseId);
        $count = max(1, (int)($browserSnapshot['update_count'] ?? 1));
        $version = (string)($browserLatest['version'] ?? '');
        create_notification(
            $userId,
            'client_release_update',
            'Browser Companion update available',
            'VP3 Browser Companion v' . $version . ' is available for ' . $count . ' connected browser' . ($count === 1 ? '' : 's') . '. Review the release and update when convenient.',
            '/client-updates.php#browser-companion',
            'client_release_browser',
            $browserReleaseId
        );
    } else {
        client_release_intelligence_resolve_notifications_v100($pdo, $userId, 'client_release_browser');
    }

    $homeLatest = is_array($homeserverSnapshot['latest_release'] ?? null) ? $homeserverSnapshot['latest_release'] : null;
    $homeReleaseId = (int)($homeLatest['id'] ?? 0);
    if (!empty($homeserverSnapshot['update_available']) && $homeReleaseId > 0) {
        client_release_intelligence_resolve_notifications_v100($pdo, $userId, 'client_release_homeserver', $homeReleaseId);
        $version = (string)($homeLatest['version'] ?? '');
        create_notification(
            $userId,
            'client_release_update',
            'HomeServer update available',
            'VP3 HomeServer v' . $version . ' is available for your paired HomeServer. Review the release before installing the update.',
            '/client-updates.php#homeserver',
            'client_release_homeserver',
            $homeReleaseId
        );
    } else {
        client_release_intelligence_resolve_notifications_v100($pdo, $userId, 'client_release_homeserver');
    }
}

function client_release_intelligence_reconcile_all_v100(PDO $pdo, int $limit = 2000): int
{
    $limit = max(1, min(5000, $limit));
    $ids = [];
    if (table_exists('extension_devices_v2000')) {
        $rows = $pdo->query(
            "SELECT DISTINCT user_id FROM extension_devices_v2000
             WHERE device_status='active' AND revoked_at IS NULL
             ORDER BY user_id LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $id) $ids[(int)$id] = true;
    }
    if (table_exists('homeserver_connections')) {
        $rows = $pdo->query(
            "SELECT DISTINCT user_id FROM homeserver_connections
             WHERE homeserver_token_enc IS NOT NULL
             ORDER BY user_id LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $id) $ids[(int)$id] = true;
    }

    $count = 0;
    foreach (array_slice(array_keys($ids), 0, $limit) as $userId) {
        if ($userId < 1) continue;
        try {
            client_release_intelligence_reconcile_user_v100($pdo, $userId);
            $count++;
        } catch (Throwable $e) {
            error_log('Client release reconcile failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
    return $count;
}

function client_release_intelligence_admin_summary_v100(PDO $pdo): array
{
    $browserLatest = client_release_browser_latest_v100();
    $browserVersions = [];
    $browserActive = 0;
    $browserOutdated = 0;
    $browserCurrent = 0;
    $browserUnknown = 0;
    if (table_exists('extension_devices_v2000')) {
        $stmt = $pdo->query(
            "SELECT extension_version,COUNT(*) AS total
             FROM extension_devices_v2000
             WHERE device_status='active' AND revoked_at IS NULL
             GROUP BY extension_version ORDER BY total DESC,extension_version DESC"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $version = trim((string)($row['extension_version'] ?? ''));
            $total = (int)($row['total'] ?? 0);
            $browserActive += $total;
            $state = client_release_version_state_v100($version, (string)($browserLatest['version'] ?? ''));
            if ($state === 'update_available') $browserOutdated += $total;
            elseif (in_array($state, ['current','ahead'], true)) $browserCurrent += $total;
            else $browserUnknown += $total;
            $browserVersions[] = ['version' => $version, 'count' => $total, 'state' => $state];
        }
    }

    $homeLatestRow = null;
    try { $homeLatestRow = homeserver_vp3_latest_release('stable'); } catch (Throwable $e) {}
    $homeLatestVersion = trim((string)($homeLatestRow['version'] ?? ''));
    $homeVersions = [];
    $homePaired = 0;
    $homeOutdated = 0;
    $homeCurrent = 0;
    $homeUnknown = 0;
    if (table_exists('homeserver_connections')) {
        $stmt = $pdo->query(
            "SELECT installed_version,COUNT(*) AS total
             FROM homeserver_connections
             WHERE homeserver_token_enc IS NOT NULL
             GROUP BY installed_version ORDER BY total DESC,installed_version DESC"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $version = trim((string)($row['installed_version'] ?? ''));
            $total = (int)($row['total'] ?? 0);
            $homePaired += $total;
            $state = client_release_version_state_v100($version, $homeLatestVersion);
            if ($state === 'update_available') $homeOutdated += $total;
            elseif (in_array($state, ['current','ahead'], true)) $homeCurrent += $total;
            else $homeUnknown += $total;
            $homeVersions[] = ['version' => $version, 'count' => $total, 'state' => $state];
        }
    }

    return [
        'build' => VP3_CLIENT_RELEASE_INTELLIGENCE_V100,
        'browser_companion' => [
            'latest_version' => (string)($browserLatest['version'] ?? ''),
            'channel' => (string)($browserLatest['channel'] ?? 'stable'),
            'active_clients' => $browserActive,
            'outdated_clients' => $browserOutdated,
            'current_clients' => $browserCurrent,
            'unknown_clients' => $browserUnknown,
            'versions' => $browserVersions,
        ],
        'homeserver' => [
            'latest_version' => $homeLatestVersion,
            'channel' => (string)($homeLatestRow['channel'] ?? 'stable'),
            'paired_clients' => $homePaired,
            'outdated_clients' => $homeOutdated,
            'current_clients' => $homeCurrent,
            'unknown_clients' => $homeUnknown,
            'versions' => $homeVersions,
        ],
    ];
}
