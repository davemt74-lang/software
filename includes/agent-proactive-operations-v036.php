<?php
declare(strict_types=1);

const VP3_AGENT_PROACTIVE_OPERATIONS_V036 = 'proactive-agent-operations-v036-20260909';

/**
 * Build a deliberately small, privacy-safe operational candidate.
 *
 * The cognitive loop owns ranking, suppression, risk classification, approval
 * planning, cooldowns and Main Feed surfacing. This provider only describes
 * locally observable state; it never creates notifications or executes work.
 */
function agent_proactive_operations_v036_candidate(
    string $key,
    string $title,
    string $reason,
    string $prompt,
    string $source,
    float $confidence = 0.82,
    float $recency = 0.82
): array {
    return [
        'hash' => sha1('v036|' . $key),
        'key' => 'operations:' . $key,
        'title' => $title,
        'prompt' => $prompt,
        'reason' => $reason,
        'source' => $source,
        'url' => '',
        '_confidence' => max(0.2, min(1.0, $confidence)),
        '_recency' => max(0.15, min(1.0, $recency)),
        '_occurred_at' => gmdate('Y-m-d H:i:s'),
    ];
}

/**
 * Profile completeness is based only on presence/absence. User-entered values
 * are never copied into proactive evidence.
 */
function agent_proactive_operations_v036_profile_candidate(PDO $pdo, int $userId): ?array
{
    if ($userId < 1 || !function_exists('table_exists') || !table_exists('user_profiles')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT u.display_name,u.avatar_path,p.username,p.bio '
            . 'FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $checks = [
            'username' => 'username',
            'display_name' => 'display name',
            'bio' => 'bio',
            'avatar_path' => 'profile image',
        ];
        $missingKeys = [];
        $missingLabels = [];
        foreach ($checks as $field => $label) {
            if (trim((string)($row[$field] ?? '')) === '') {
                $missingKeys[] = $field;
                $missingLabels[] = $label;
            }
        }
        if (!$missingKeys) {
            return null;
        }

        $shown = array_slice($missingLabels, 0, 3);
        $remaining = count($missingLabels) - count($shown);
        $summary = implode(', ', $shown);
        if ($remaining > 0) {
            $summary .= ' and ' . $remaining . ' more';
        }

        return agent_proactive_operations_v036_candidate(
            'profile-setup-' . substr(hash('sha256', implode('|', $missingKeys)), 0, 12),
            'Finish your VP3 profile',
            'Your VP3 profile is incomplete: ' . $summary . '.',
            'Help me finish the missing VP3 profile fields, starting with the highest-impact item.',
            'operations_profile',
            0.92,
            0.84
        );
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Read HomeServer state from the local VP3 cache only. Do not call the relay
 * from the five-minute cognitive-loop background run.
 */
function agent_proactive_operations_v036_homeserver_state(PDO $pdo, int $userId): ?array
{
    if ($userId < 1 || !function_exists('table_exists') || !table_exists('homeserver_connections')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT status,last_seen_at,capabilities_json FROM homeserver_connections WHERE user_id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $capabilities = [];
        if (trim((string)($row['capabilities_json'] ?? '')) !== '') {
            $decoded = json_decode((string)$row['capabilities_json'], true);
            if (is_array($decoded)) {
                $capabilities = $decoded;
            }
        }
        $features = isset($capabilities['features']) && is_array($capabilities['features'])
            ? array_map('strval', array_values($capabilities['features']))
            : [];
        $inference = isset($capabilities['inference']) && is_array($capabilities['inference'])
            ? $capabilities['inference']
            : [];
        return [
            'status' => strtolower(trim((string)($row['status'] ?? ''))),
            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
            'local_agent_ready' => !empty($inference['available']) && in_array('agent.chat', $features, true),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Return only an aggregate count; never retrieve prompts, responses or errors. */
function agent_proactive_operations_v036_recent_cloud_fallbacks(PDO $pdo, int $userId, int $since = 0): int
{
    if ($userId < 1 || !function_exists('table_exists') || !table_exists('ai_execution_ledger')) {
        return 0;
    }
    $floor = max(time() - 86400, $since > 0 ? $since : 0);
    try {
        $hasV420 = function_exists('column_exists') && column_exists('ai_execution_ledger', 'actual_route');
        $routeFilter = $hasV420
            ? " AND actual_route='vp3_cloud'"
            : " AND source='vp3_cloud'";
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ai_execution_ledger "
            . "WHERE user_id=? AND created_at>=? AND fallback_used=1" . $routeFilter
        );
        $stmt->execute([$userId, gmdate('Y-m-d H:i:s', $floor)]);
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

function agent_proactive_operations_v036_candidates(PDO $pdo, array $user, int $since = 0): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return [];
    }

    $out = [];
    $profile = agent_proactive_operations_v036_profile_candidate($pdo, $userId);
    if (is_array($profile)) {
        $out[] = $profile;
    }

    $homeServer = agent_proactive_operations_v036_homeserver_state($pdo, $userId);
    if (!is_array($homeServer)) {
        return $out;
    }

    $status = (string)($homeServer['status'] ?? '');
    if ($status === 'awaiting_approval') {
        $out[] = agent_proactive_operations_v036_candidate(
            'homeserver-pairing-pending',
            'Finish HomeServer pairing',
            'HomeServer pairing is incomplete and is waiting for local approval.',
            'Walk me through finishing the pending HomeServer pairing without changing permissions remotely.',
            'operations_homeserver',
            0.97,
            0.98
        );
    } elseif (in_array($status, ['offline', 'error', 'expired', 'denied'], true)) {
        $out[] = agent_proactive_operations_v036_candidate(
            'homeserver-reconnect-' . $status,
            'Restore HomeServer connection',
            'Local HomeServer capabilities are blocked because the cached connection state is ' . $status . '.',
            'Help me restore the HomeServer connection and explain any step that requires local approval.',
            'operations_homeserver',
            0.96,
            0.96
        );
    }

    if (in_array($status, ['paired', 'connected'], true) && !empty($homeServer['local_agent_ready'])) {
        $fallbacks = agent_proactive_operations_v036_recent_cloud_fallbacks($pdo, $userId, $since);
        if ($fallbacks > 0) {
            $out[] = agent_proactive_operations_v036_candidate(
                'ai-routing-fallback',
                'Review AI routing fallback',
                $fallbacks . ' recent AI request' . ($fallbacks === 1 ? '' : 's')
                    . ' fell back to VP3 Cloud while cached HomeServer capabilities report local Agent inference available.',
                'Review my AI routing and explain why recent requests fell back to cloud. Prefer HomeServer when policy and availability allow it.',
                'operations_ai_routing',
                0.90,
                0.95
            );
        }
    }

    return $out;
}
