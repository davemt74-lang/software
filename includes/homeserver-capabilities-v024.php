<?php
declare(strict_types=1);

/**
 * VP3 v0.24 — normalized HomeServer capability registry and routing.
 *
 * HomeServer remains the source of truth. VP3 derives this registry from the
 * existing capabilities_json cache populated by homeserver_vp3_status(); no
 * parallel capability database is created here.
 */
function homeserver_capability_v024_catalog(): array
{
    return [
        'agent_brain' => [
            'label' => 'Agent Brain',
            'features' => ['agent.chat'],
            'permission' => 'agent.chat',
            'operation' => 'agent.chat',
            'fallback' => 'vp3_cloud',
        ],
        'inference' => [
            'label' => 'Model / Inference',
            'features' => ['inference.routing', 'inference.status'],
            'permission' => 'agent.chat',
            'operation' => 'inference.status',
            'fallback' => 'vp3_cloud',
        ],
        'memory' => [
            'label' => 'Memory',
            'features' => ['memory.read', 'memory.provenance'],
            'permission' => 'memory.read',
            'operation' => 'memory.read',
            'fallback' => 'vp3',
        ],
        'knowledge' => [
            'label' => 'Knowledge',
            'features' => ['knowledge.search'],
            'permission' => 'knowledge.search',
            'operation' => 'knowledge.search',
            'fallback' => 'vp3',
        ],
        'contacts' => [
            'label' => 'Contacts',
            'features' => ['contacts.read'],
            'permission' => 'contacts.read',
            'operation' => 'contacts.search',
            'fallback' => 'vp3',
        ],
        'awareness' => [
            'label' => 'Awareness',
            'features' => ['awareness.read', 'cognition.multi_app_awareness'],
            'permission' => 'awareness.read',
            'operation' => 'awareness.list',
            'fallback' => 'vp3',
        ],
        'tools' => [
            'label' => 'Tools',
            'features' => ['tools.execute', 'agent.tools.read'],
            'permission' => 'tools.execute',
            'operation' => 'tools.list',
            'fallback' => 'vp3',
        ],
        'skills' => [
            'label' => 'Skills',
            'features' => ['skills'],
            'permission' => 'tools.execute',
            'operation' => 'skills.list',
            'fallback' => 'vp3',
        ],
        'plugins' => [
            'label' => 'Plugins',
            'features' => ['plugins.read', 'plugins.registry'],
            'permission' => 'plugins.read',
            'operation' => 'plugins.list',
            'fallback' => 'vp3',
        ],
        'events' => [
            'label' => 'Events',
            'features' => ['events.read', 'cognition.event_bus'],
            'permission' => 'events.read',
            'operation' => 'events.list',
            'fallback' => 'vp3',
        ],
        'tasks' => [
            'label' => 'Tasks',
            'features' => ['tasks.read', 'tasks.reminders'],
            'permission' => 'tasks.read',
            'operation' => 'tool.execute',
            'fallback' => 'vp3',
        ],
        'notifications' => [
            'label' => 'Notifications',
            'features' => ['notifications.read'],
            'permission' => 'notifications.read',
            'operation' => 'tool.execute',
            'fallback' => 'vp3',
        ],
    ];
}

function homeserver_capability_v024_string_list(mixed $value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        if (!is_string($item)) continue;
        $item = trim($item);
        if ($item === '' || strlen($item) > 160) continue;
        $out[$item] = true;
    }
    return array_keys($out);
}

function homeserver_capability_v024_raw(int $userId): array
{
    if ($userId < 1 || !function_exists('homeserver_vp3_connection')) return [];
    try {
        $row = homeserver_vp3_connection($userId);
        if (!$row || empty($row['capabilities_json'])) return [];
        $decoded = json_decode((string)$row['capabilities_json'], true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

function homeserver_capability_v024_registry_from(array $status, array $raw): array
{
    $features = homeserver_capability_v024_string_list($raw['features'] ?? ($status['capabilities'] ?? []));
    $permissions = homeserver_capability_v024_string_list($raw['permissions'] ?? []);
    $featureSet = array_fill_keys($features, true);
    $permissionSet = array_fill_keys($permissions, true);
    $connected = !empty($status['connected']);
    $paired = !empty($status['paired']);
    $hasAdvertisement = $features !== [] || $permissions !== [];
    $inference = is_array($status['inference'] ?? null)
        ? $status['inference']
        : (is_array($raw['inference'] ?? null) ? $raw['inference'] : []);
    $inferenceReady = !empty($inference['available']);

    $capabilities = [];
    foreach (homeserver_capability_v024_catalog() as $key => $spec) {
        $supported = false;
        foreach ($spec['features'] as $feature) {
            if (isset($featureSet[$feature])) {
                $supported = true;
                break;
            }
        }
        if (!$supported && $spec['permission'] !== '' && isset($permissionSet[$spec['permission']])) {
            $supported = true;
        }

        // Pre-capability-registry HomeServers already supported agent.chat. Keep
        // that proven v0.18 compatibility path while refusing to assume newer
        // private capabilities that were never advertised.
        $legacyAssumed = !$hasAdvertisement && $paired && in_array($key, ['agent_brain', 'inference'], true);
        if ($legacyAssumed) $supported = true;

        $ready = $connected && $paired && $supported;
        if (in_array($key, ['agent_brain', 'inference'], true)) {
            $ready = $ready && $inferenceReady;
        }

        $reason = 'homeserver_capability_ready';
        if (!$paired) $reason = 'homeserver_not_paired';
        elseif (!$supported) $reason = 'capability_not_advertised';
        elseif (!$connected) $reason = 'homeserver_offline';
        elseif (in_array($key, ['agent_brain', 'inference'], true) && !$inferenceReady) $reason = 'provider_unavailable';

        $capabilities[$key] = [
            'key' => $key,
            'label' => (string)$spec['label'],
            'supported' => $supported,
            'ready' => $ready,
            'legacy_assumed' => $legacyAssumed,
            'permission' => (string)$spec['permission'],
            'operation' => (string)$spec['operation'],
            'source' => $ready ? 'homeserver' : (string)$spec['fallback'],
            'fallback_source' => (string)$spec['fallback'],
            'reason' => $reason,
        ];
    }

    return [
        'version' => 'v0.24',
        'homeserver_state' => mb_strimwidth((string)($status['state'] ?? 'unpaired'), 0, 40, ''),
        'connected' => $connected,
        'paired' => $paired,
        'installed_version' => mb_strimwidth((string)($status['installed_version'] ?? $raw['version'] ?? ''), 0, 64, ''),
        'advertised' => $hasAdvertisement,
        'feature_count' => count($features),
        'permission_count' => count($permissions),
        'inference' => [
            'available' => $inferenceReady,
            'provider' => mb_strimwidth(trim((string)($inference['provider_key'] ?? $inference['selected_provider'] ?? $inference['provider'] ?? '')), 0, 80, ''),
            'model' => mb_strimwidth(trim((string)($inference['model'] ?? '')), 0, 160, ''),
            'compute_source' => mb_strimwidth(trim((string)($inference['compute_source'] ?? '')), 0, 80, ''),
        ],
        'capabilities' => $capabilities,
    ];
}

function homeserver_capability_v024_registry(int $userId, bool $forceRefresh = false): array
{
    $status = [
        'state' => 'unpaired', 'connected' => false, 'paired' => false,
        'installed_version' => '', 'capabilities' => [], 'inference' => null,
    ];
    if ($userId > 0 && function_exists('homeserver_vp3_status')) {
        try {
            $status = homeserver_vp3_status($userId, $forceRefresh);
        } catch (Throwable $e) {
            $status['state'] = 'error';
        }
    }
    return homeserver_capability_v024_registry_from($status, homeserver_capability_v024_raw($userId));
}

function homeserver_capability_v024_resolve(array $registry, string $capability, ?string $fallback = null): array
{
    $catalog = homeserver_capability_v024_catalog();
    if (!isset($catalog[$capability])) {
        return [
            'version' => 'v0.24', 'capability' => $capability, 'label' => 'Capability',
            'supported' => false, 'ready' => false, 'source' => $fallback ?? 'vp3',
            'fallback_source' => $fallback ?? 'vp3', 'operation' => '', 'reason' => 'unknown_capability',
            'legacy_assumed' => false,
        ];
    }
    $row = is_array($registry['capabilities'][$capability] ?? null)
        ? $registry['capabilities'][$capability]
        : [];
    $fallback ??= (string)$catalog[$capability]['fallback'];
    $ready = !empty($row['ready']);
    return [
        'version' => 'v0.24',
        'capability' => $capability,
        'label' => (string)($row['label'] ?? $catalog[$capability]['label']),
        'supported' => !empty($row['supported']),
        'ready' => $ready,
        'source' => $ready ? 'homeserver' : $fallback,
        'fallback_source' => $fallback,
        'operation' => mb_strimwidth((string)($row['operation'] ?? $catalog[$capability]['operation']), 0, 80, ''),
        'reason' => mb_strimwidth((string)($row['reason'] ?? 'capability_not_advertised'), 0, 80, ''),
        'legacy_assumed' => !empty($row['legacy_assumed']),
    ];
}

function homeserver_capability_v024_public_route(array $route, ?string $actualSource = null, bool $fallbackUsed = false): array
{
    $allowedSources = ['homeserver', 'vp3', 'vp3_cloud', 'vp3_tool', 'unavailable'];
    $planned = (string)($route['source'] ?? 'vp3');
    if (!in_array($planned, $allowedSources, true)) $planned = 'vp3';
    $actual = $actualSource ?? $planned;
    if (!in_array($actual, $allowedSources, true)) $actual = 'vp3';
    return [
        'version' => 'v0.24',
        'capability' => mb_strimwidth((string)($route['capability'] ?? ''), 0, 60, ''),
        'planned_source' => $planned,
        'actual_source' => $actual,
        'supported' => !empty($route['supported']),
        'ready' => !empty($route['ready']),
        'fallback_used' => $fallbackUsed,
        'reason' => mb_strimwidth((string)($route['reason'] ?? 'none'), 0, 80, ''),
    ];
}

/**
 * Execute an allowlisted read-only HomeServer capability operation. This is a
 * common gateway for VP3 surfaces that need HomeServer Memory/Knowledge/etc.
 * Mutation operations remain in their existing approval-aware paths.
 */
function homeserver_capability_v024_read(int $userId, string $capability, array $payload = []): array
{
    $operations = [
        'memory' => 'memory.read',
        'knowledge' => 'knowledge.search',
        'contacts' => 'contacts.search',
        'awareness' => 'awareness.list',
        'tools' => 'tools.list',
        'skills' => 'skills.list',
        'plugins' => 'plugins.list',
        'events' => 'events.list',
    ];
    if (!isset($operations[$capability])) {
        return ['ok' => false, 'error' => 'unsupported_capability'];
    }
    $registry = homeserver_capability_v024_registry($userId, false);
    $route = homeserver_capability_v024_resolve($registry, $capability, 'vp3');
    if (($route['source'] ?? '') !== 'homeserver') {
        return ['ok' => false, 'route' => homeserver_capability_v024_public_route($route), 'error' => 'homeserver_unavailable'];
    }
    if (!function_exists('homeserver_agent_v018_credentials') || !function_exists('homeserver_vp3_remote_operation')) {
        return ['ok' => false, 'route' => homeserver_capability_v024_public_route($route), 'error' => 'homeserver_unavailable'];
    }
    $credentials = homeserver_agent_v018_credentials($userId);
    if (!$credentials) {
        return ['ok' => false, 'route' => homeserver_capability_v024_public_route($route), 'error' => 'homeserver_unavailable'];
    }

    $safePayload = [];
    if (in_array($capability, ['knowledge', 'contacts'], true)) {
        $safePayload['query'] = mb_strimwidth(trim((string)($payload['query'] ?? '')), 0, 500, '');
    } elseif ($capability === 'awareness') {
        $safePayload['limit'] = max(1, min(100, (int)($payload['limit'] ?? 25)));
    } elseif ($capability === 'events') {
        $safePayload['limit'] = max(1, min(100, (int)($payload['limit'] ?? 50)));
        $eventType = mb_strimwidth(trim((string)($payload['event_type'] ?? '')), 0, 120, '');
        if ($eventType !== '') $safePayload['event_type'] = $eventType;
    }

    try {
        $result = homeserver_vp3_remote_operation(
            (string)$credentials['relay'],
            $operations[$capability],
            $safePayload,
            (string)$credentials['home']
        );
        return [
            'ok' => true,
            'route' => homeserver_capability_v024_public_route($route, 'homeserver', false),
            'result' => is_array($result) ? $result : [],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'route' => homeserver_capability_v024_public_route($route, 'vp3', true),
            'error' => 'homeserver_unavailable',
        ];
    }
}

function homeserver_capability_v024_attach_state(array $state, array $user, bool $forceRefresh = false): array
{
    $state['homeserver_capabilities'] = homeserver_capability_v024_registry((int)($user['id'] ?? 0), $forceRefresh);
    return $state;
}
