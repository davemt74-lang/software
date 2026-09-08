<?php
declare(strict_types=1);

/**
 * VP3 v0.26 — authenticated HomeServer wrapper-scope awareness.
 *
 * HomeServer is authoritative. VP3 reads the effective scope through the
 * existing authenticated tools.list bridge path and only uses it to narrow
 * VP3 behavior. VP3 never writes or widens this scope.
 */
function homeserver_scope_v026_string_list(mixed $value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach (array_slice($value, 0, 32) as $item) {
        if (!is_string($item)) continue;
        $item = trim($item);
        if ($item === '' || mb_strlen($item) > 160) continue;
        $out[$item] = true;
    }
    return array_keys($out);
}

function homeserver_scope_v026_normalize(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    return [
        'cloud_allowed' => !array_key_exists('cloud_allowed', $raw) || !empty($raw['cloud_allowed']),
        'memory_key_prefixes' => homeserver_scope_v026_string_list($raw['memory_key_prefixes'] ?? []),
        'knowledge_kinds' => homeserver_scope_v026_string_list($raw['knowledge_kinds'] ?? []),
        'tool_names' => homeserver_scope_v026_string_list($raw['tool_names'] ?? []),
        'plugin_keys' => homeserver_scope_v026_string_list($raw['plugin_keys'] ?? []),
    ];
}

function homeserver_scope_v026_supported(int $userId): bool
{
    if ($userId < 1 || !function_exists('homeserver_capability_v024_raw')) return false;
    $raw = homeserver_capability_v024_raw($userId);
    $features = function_exists('homeserver_capability_v024_string_list')
        ? homeserver_capability_v024_string_list($raw['features'] ?? [])
        : homeserver_scope_v026_string_list($raw['features'] ?? []);
    return in_array('app.scopes.v1', $features, true);
}

function homeserver_scope_v026_fetch(int $userId, bool $forceRefresh = false): array
{
    static $cache = [];
    if (!$forceRefresh && isset($cache[$userId])) return $cache[$userId];

    $state = [
        'version' => 'v0.26',
        'supported' => false,
        'available' => false,
        'reason' => 'scope_not_supported',
        'scope' => null,
    ];
    if ($userId < 1 || !homeserver_scope_v026_supported($userId)) {
        return $cache[$userId] = $state;
    }
    $state['supported'] = true;
    if (!function_exists('homeserver_agent_v018_credentials') || !function_exists('homeserver_vp3_remote_operation')) {
        $state['reason'] = 'homeserver_unavailable';
        return $cache[$userId] = $state;
    }

    try {
        $credentials = homeserver_agent_v018_credentials($userId);
        if (!$credentials) {
            $state['reason'] = 'homeserver_not_paired';
            return $cache[$userId] = $state;
        }
        $result = homeserver_vp3_remote_operation(
            (string)($credentials['relay'] ?? ''),
            'tools.list',
            [],
            (string)($credentials['home'] ?? '')
        );
        $scope = $result['app_scope'] ?? ($result['payload']['app_scope'] ?? null);
        if (!is_array($scope)) {
            $state['reason'] = 'scope_not_reported';
            return $cache[$userId] = $state;
        }
        $state['available'] = true;
        $state['reason'] = 'scope_ready';
        $state['scope'] = homeserver_scope_v026_normalize($scope);
    } catch (Throwable $e) {
        $state['reason'] = 'homeserver_unavailable';
    }
    return $cache[$userId] = $state;
}

function homeserver_scope_v026_blocks_cloud(array $state): bool
{
    return !empty($state['available'])
        && is_array($state['scope'] ?? null)
        && empty($state['scope']['cloud_allowed']);
}

function homeserver_scope_v026_public(array $state): array
{
    $scope = is_array($state['scope'] ?? null) ? homeserver_scope_v026_normalize($state['scope']) : null;
    return [
        'version' => 'v0.26',
        'supported' => !empty($state['supported']),
        'available' => !empty($state['available']),
        'reason' => mb_strimwidth((string)($state['reason'] ?? 'scope_not_supported'), 0, 80, ''),
        'cloud_allowed' => $scope === null ? null : !empty($scope['cloud_allowed']),
        'memory_restricted' => $scope !== null && $scope['memory_key_prefixes'] !== [],
        'memory_prefix_count' => $scope === null ? 0 : count($scope['memory_key_prefixes']),
        'knowledge_restricted' => $scope !== null && $scope['knowledge_kinds'] !== [],
        'knowledge_kind_count' => $scope === null ? 0 : count($scope['knowledge_kinds']),
        'tools_restricted' => $scope !== null && $scope['tool_names'] !== [],
        'tool_count' => $scope === null ? 0 : count($scope['tool_names']),
        'plugins_restricted' => $scope !== null && $scope['plugin_keys'] !== [],
        'plugin_count' => $scope === null ? 0 : count($scope['plugin_keys']),
    ];
}

function homeserver_scope_v026_apply_compute_policy(array $policy, array $state): array
{
    $policy['homeserver_scope'] = homeserver_scope_v026_public($state);
    if (!homeserver_scope_v026_blocks_cloud($state)) return $policy;

    $policy['pre_scope_effective_preference'] = (string)($policy['effective_preference'] ?? 'auto');
    $policy['effective_preference'] = 'homeserver_only';
    $policy['effective_label'] = 'HomeServer only';
    $policy['source'] = 'homeserver_scope';
    $policy['scope_override'] = true;
    return $policy;
}

function homeserver_scope_v026_attach_state(array $state, array $user, bool $forceRefresh = false): array
{
    $state['homeserver_scope'] = homeserver_scope_v026_public(
        homeserver_scope_v026_fetch((int)($user['id'] ?? 0), $forceRefresh)
    );
    return $state;
}
