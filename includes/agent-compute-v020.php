<?php
declare(strict_types=1);

/**
 * VP3 v0.20 — account-scoped Agent compute routing preferences and status.
 *
 * Preferences are intentionally separate from HomeServer pairing state so a
 * user can choose VP3 Cloud before pairing, or keep HomeServer-only selected
 * while their private server is temporarily offline.
 */
function agent_compute_v020_preferences(): array
{
    return [
        'auto' => [
            'label' => 'Automatic',
            'description' => 'Use HomeServer first when it is ready, then fall back to VP3 Cloud when needed.',
        ],
        'homeserver_only' => [
            'label' => 'HomeServer only',
            'description' => 'Use the model or provider selected in HomeServer. VP3 Cloud fallback is disabled.',
        ],
        'vp3_cloud' => [
            'label' => 'VP3 Cloud',
            'description' => 'Send Agent requests directly through your VP3 cloud plan and token balance.',
        ],
    ];
}

function agent_compute_v020_valid_preference(string $preference): bool
{
    return isset(agent_compute_v020_preferences()[$preference]);
}

/**
 * Pure routing plan shared by the settings surface and canonical Chat path.
 *
 * HomeServer-only constrains the VP3 route, not HomeServer's own provider
 * selection. A user-configured provider behind HomeServer is still a
 * HomeServer route; only fallback into VP3-managed cloud billing is disabled.
 */
function agent_compute_v020_route_plan(string $preference, bool $homePaired, bool $homeReady): array
{
    if (!agent_compute_v020_valid_preference($preference)) {
        $preference = 'auto';
    }

    if ($preference === 'vp3_cloud') {
        return [
            'preference' => $preference,
            'try_homeserver' => false,
            'homeserver_cloud_allowed' => false,
            'allow_vp3_fallback' => true,
            'resolved_route' => 'vp3_cloud',
            'resolved_label' => 'VP3 Cloud',
            'blocked' => false,
        ];
    }

    if ($preference === 'homeserver_only') {
        return [
            'preference' => $preference,
            'try_homeserver' => $homePaired,
            'homeserver_cloud_allowed' => true,
            'allow_vp3_fallback' => false,
            'resolved_route' => $homeReady ? 'homeserver' : 'blocked',
            'resolved_label' => $homeReady ? 'HomeServer' : 'Waiting for HomeServer',
            'blocked' => !$homeReady,
        ];
    }

    return [
        'preference' => 'auto',
        'try_homeserver' => $homePaired,
        'homeserver_cloud_allowed' => true,
        'allow_vp3_fallback' => true,
        'resolved_route' => $homeReady ? 'homeserver' : 'vp3_cloud',
        'resolved_label' => $homeReady ? 'HomeServer first' : 'VP3 Cloud',
        'blocked' => false,
    ];
}

function agent_compute_v020_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS agent_compute_preferences (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            preference VARCHAR(24) NOT NULL DEFAULT 'auto',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_agent_compute_preference_user
              FOREIGN KEY (user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function agent_compute_v020_preference(?PDO $pdo, int $userId): string
{
    if ($userId < 1) {
        return 'auto';
    }
    $pdo ??= db();
    if (!$pdo) {
        return 'auto';
    }
    try {
        agent_compute_v020_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT preference FROM agent_compute_preferences WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $preference = trim((string)$stmt->fetchColumn());
        return agent_compute_v020_valid_preference($preference) ? $preference : 'auto';
    } catch (Throwable $e) {
        return 'auto';
    }
}

function agent_compute_v020_save_preference(PDO $pdo, array $user, string $preference): string
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Sign in to change Agent compute settings.');
    }
    $preference = strtolower(trim($preference));
    if (!agent_compute_v020_valid_preference($preference)) {
        throw new RuntimeException('Choose a valid Agent compute preference.');
    }
    agent_compute_v020_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO agent_compute_preferences (user_id,preference)
         VALUES (?,?)
         ON DUPLICATE KEY UPDATE preference=VALUES(preference),updated_at=NOW()"
    );
    $stmt->execute([$userId, $preference]);
    return $preference;
}

function agent_compute_v020_cloud_state(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $balance = [
        'unlimited' => false,
        'remaining' => 0,
        'package_remaining' => 0,
        'credits_remaining' => 0,
        'reserved' => 0,
    ];
    $recent = [];
    $summary = ['requests' => 0, 'total_tokens' => 0];

    try {
        if (function_exists('subscription_ai_balance')) {
            $balance = subscription_ai_balance($user);
        }
        if ($userId > 0 && function_exists('subscription_recent_usage')) {
            $recent = subscription_recent_usage($userId, 15);
        }
        $pdo = db();
        if ($userId > 0 && $pdo && table_exists('ai_usage_ledger')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) requests,COALESCE(SUM(total_tokens),0) total_tokens FROM ai_usage_ledger WHERE user_id=?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch() ?: [];
            $summary = [
                'requests' => max(0, (int)($row['requests'] ?? 0)),
                'total_tokens' => max(0, (int)($row['total_tokens'] ?? 0)),
            ];
        }
    } catch (Throwable $e) {
        // Compute settings remain usable even if subscription storage is being upgraded.
    }

    $provider = function_exists('ai_active_provider') ? ai_active_provider() : 'local';
    $providerReady = function_exists('ai_provider_ready') ? ai_provider_ready($provider) : $provider === 'local';
    $model = ($provider !== 'local' && function_exists('ai_provider_model')) ? ai_provider_model($provider) : '';
    $providerLabels = function_exists('ai_provider_labels') ? ai_provider_labels() : [];

    $items = [];
    foreach ($recent as $row) {
        if (!is_array($row)) continue;
        $items[] = [
            'source' => 'vp3_cloud',
            'provider' => mb_strimwidth((string)($row['provider'] ?? ''), 0, 80, ''),
            'model' => mb_strimwidth((string)($row['model'] ?? ''), 0, 160, ''),
            'request_kind' => mb_strimwidth((string)($row['scope'] ?? 'chat'), 0, 80, ''),
            'prompt_tokens' => max(0, (int)($row['input_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int)($row['output_tokens'] ?? 0)),
            'total_tokens' => max(0, (int)($row['total_tokens'] ?? 0)),
            'billable_tokens' => max(0, (int)($row['total_tokens'] ?? 0)),
            'created_at' => mb_strimwidth((string)($row['created_at'] ?? ''), 0, 64, ''),
        ];
    }

    return [
        'provider' => $provider,
        'provider_label' => (string)($providerLabels[$provider] ?? ucfirst($provider)),
        'provider_ready' => $providerReady,
        'model' => $model,
        'balance' => [
            'unlimited' => !empty($balance['unlimited']),
            'remaining' => !empty($balance['unlimited']) ? null : max(0, (int)($balance['remaining'] ?? 0)),
            'package_remaining' => !empty($balance['unlimited']) ? null : max(0, (int)($balance['package_remaining'] ?? 0)),
            'credits_remaining' => !empty($balance['unlimited']) ? null : max(0, (int)($balance['credits_remaining'] ?? 0)),
            'reserved' => !empty($balance['unlimited']) ? 0 : max(0, (int)($balance['reserved'] ?? 0)),
        ],
        'summary' => $summary,
        'recent' => $items,
    ];
}

function agent_compute_v020_homeserver_usage(array $user, int $limit = 15): array
{
    $userId = (int)($user['id'] ?? 0);
    $empty = ['available' => false, 'items' => [], 'summary' => [], 'error' => ''];
    if ($userId < 1 || !function_exists('homeserver_agent_v018_credentials')) {
        return $empty;
    }
    $credentials = homeserver_agent_v018_credentials($userId);
    if (!$credentials) {
        return $empty;
    }
    try {
        $result = homeserver_vp3_remote_operation(
            (string)$credentials['relay'],
            'usage.read',
            ['limit' => max(1, min(50, $limit))],
            (string)$credentials['home']
        );
        $rows = is_array($result['items'] ?? null) ? $result['items'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $source = (string)($row['compute_source'] ?? '');
            if (!in_array($source, ['homeserver_local', 'user_provider', 'vp3_cloud'], true)) {
                $source = 'homeserver_local';
            }
            $items[] = [
                'source' => $source,
                'provider' => mb_strimwidth((string)($row['provider_key'] ?? ''), 0, 80, ''),
                'model' => mb_strimwidth((string)($row['model'] ?? ''), 0, 160, ''),
                'request_kind' => mb_strimwidth((string)($row['request_kind'] ?? 'chat'), 0, 80, ''),
                'prompt_tokens' => max(0, (int)($row['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int)($row['completion_tokens'] ?? 0)),
                'total_tokens' => max(0, (int)($row['total_tokens'] ?? 0)),
                'billable_tokens' => max(0, (int)($row['billable_tokens'] ?? 0)),
                'created_at' => mb_strimwidth((string)($row['created_at'] ?? ''), 0, 64, ''),
            ];
        }
        $rawSummary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $summary = [
            'cloud_tokens_debited' => max(0, (int)($rawSummary['cloud_tokens_debited'] ?? 0)),
            'cloud_model_tokens' => max(0, (int)($rawSummary['cloud_model_tokens'] ?? 0)),
            'homeserver_tokens' => max(0, (int)($rawSummary['homeserver_tokens'] ?? 0)),
            'cloud_requests' => max(0, (int)($rawSummary['cloud_requests'] ?? 0)),
            'homeserver_requests' => max(0, (int)($rawSummary['homeserver_requests'] ?? 0)),
            'balance_tokens' => isset($rawSummary['balance_tokens']) ? max(0, (int)$rawSummary['balance_tokens']) : null,
        ];
        return [
            'available' => true,
            'items' => $items,
            'summary' => $summary,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return ['available' => false, 'items' => [], 'summary' => [], 'error' => 'HomeServer usage history is temporarily unavailable.'];
    }
}

function agent_compute_v020_state(PDO $pdo, array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $preference = agent_compute_v020_preference($pdo, $userId);
    $home = [
        'state' => 'unpaired', 'connected' => false, 'paired' => false,
        'agent_brain_ready' => false, 'last_seen_at' => null, 'installed_version' => '',
        'inference' => null, 'error' => '',
    ];
    try {
        $home = homeserver_vp3_status($userId, false);
    } catch (Throwable $e) {
        $home['state'] = 'error';
        $home['error'] = 'HomeServer status is temporarily unavailable.';
    }

    $cloud = agent_compute_v020_cloud_state($user);
    $homeUsage = (!empty($home['connected']) && !empty($home['paired']))
        ? agent_compute_v020_homeserver_usage($user, 15)
        : ['available' => false, 'items' => [], 'summary' => [], 'error' => ''];

    $inference = is_array($home['inference'] ?? null) ? $home['inference'] : [];
    $homeProvider = trim((string)($inference['provider_key'] ?? $inference['provider'] ?? $inference['active_provider'] ?? ''));
    $homeModel = trim((string)($inference['model'] ?? $inference['active_model'] ?? ''));
    $homeReady = !empty($home['agent_brain_ready']);
    $homePaired = !empty($home['paired']);
    $plan = agent_compute_v020_route_plan($preference, $homePaired, $homeReady);

    return [
        'version' => 'v0.20',
        'preference' => $preference,
        'preferences' => agent_compute_v020_preferences(),
        'resolved_route' => (string)$plan['resolved_route'],
        'resolved_label' => (string)$plan['resolved_label'],
        'blocked' => !empty($plan['blocked']),
        'homeserver' => [
            'state' => (string)($home['state'] ?? 'unpaired'),
            'connected' => !empty($home['connected']),
            'paired' => $homePaired,
            'agent_brain_ready' => $homeReady,
            'last_seen_at' => $home['last_seen_at'] ?? null,
            'installed_version' => (string)($home['installed_version'] ?? ''),
            'update_available' => !empty($home['update_available']),
            'provider' => mb_strimwidth($homeProvider, 0, 80, ''),
            'model' => mb_strimwidth($homeModel, 0, 160, ''),
            'error' => trim((string)($home['error'] ?? '')) !== '' ? 'HomeServer needs attention.' : '',
            'usage' => $homeUsage,
        ],
        'cloud' => $cloud,
    ];
}
