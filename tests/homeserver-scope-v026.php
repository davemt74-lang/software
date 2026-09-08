<?php
declare(strict_types=1);

$GLOBALS['v026_scope_payload'] = [
    'cloud_allowed' => false,
    'memory_key_prefixes' => ['vp3:', 'vp3:'],
    'knowledge_kinds' => ['note'],
    'tool_names' => ['memory.list', 'knowledge.search'],
    'plugin_keys' => ['vp3.private'],
];

function homeserver_capability_v024_raw(int $userId): array
{
    return $userId === 7 ? ['features' => ['app.scopes.v1']] : [];
}
function homeserver_capability_v024_string_list(mixed $value): array
{
    return is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
}
function homeserver_agent_v018_credentials(int $userId): ?array
{
    return $userId === 7 ? ['relay' => 'relay-token', 'home' => 'home-token'] : null;
}
function homeserver_vp3_remote_operation(string $relay, string $operation, array $payload = [], string $home = ''): array
{
    if ($relay !== 'relay-token' || $home !== 'home-token' || $operation !== 'tools.list') {
        throw new RuntimeException('Unexpected scope handshake request.');
    }
    return ['items' => [], 'app' => 'vp3', 'app_scope' => $GLOBALS['v026_scope_payload']];
}

require dirname(__DIR__) . '/includes/homeserver-scope-v026.php';

function v026_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$state = homeserver_scope_v026_fetch(7, true);
v026_assert($state['supported'] === true, 'scope feature should be recognized');
v026_assert($state['available'] === true, 'authenticated scope should be available');
v026_assert($state['reason'] === 'scope_ready', 'scope ready reason');
v026_assert($state['scope']['cloud_allowed'] === false, 'cloud block must be preserved');
v026_assert($state['scope']['memory_key_prefixes'] === ['vp3:'], 'scope lists must be deduplicated');
v026_assert(homeserver_scope_v026_blocks_cloud($state), 'available cloud=false scope must block cloud');

$public = homeserver_scope_v026_public($state);
v026_assert($public['cloud_allowed'] === false, 'public scope cloud state');
v026_assert($public['memory_restricted'] === true && $public['memory_prefix_count'] === 1, 'public Memory restriction count');
v026_assert($public['knowledge_restricted'] === true && $public['knowledge_kind_count'] === 1, 'public Knowledge restriction count');
v026_assert($public['tools_restricted'] === true && $public['tool_count'] === 2, 'public Tool restriction count');
v026_assert($public['plugins_restricted'] === true && $public['plugin_count'] === 1, 'public Plugin restriction count');
v026_assert(!array_key_exists('memory_key_prefixes', $public), 'public state must not expose Memory prefixes');
v026_assert(!array_key_exists('plugin_keys', $public), 'public state must not expose Plugin keys');

$policy = [
    'account_preference' => 'auto',
    'agent_override' => 'vp3_cloud',
    'effective_preference' => 'vp3_cloud',
    'effective_label' => 'VP3 Cloud',
    'source' => 'agent',
];
$scoped = homeserver_scope_v026_apply_compute_policy($policy, $state);
v026_assert($scoped['pre_scope_effective_preference'] === 'vp3_cloud', 'original effective policy must remain auditable');
v026_assert($scoped['effective_preference'] === 'homeserver_only', 'cloud-blocked wrapper must resolve HomeServer-only');
v026_assert($scoped['source'] === 'homeserver_scope', 'scope must be the effective policy source');
v026_assert($scoped['scope_override'] === true, 'scope override marker');

$unavailable = [
    'version' => 'v0.26',
    'supported' => true,
    'available' => false,
    'reason' => 'homeserver_unavailable',
    'scope' => null,
];
$preserved = homeserver_scope_v026_apply_compute_policy($policy, $unavailable);
v026_assert($preserved['effective_preference'] === 'vp3_cloud', 'unavailable scope must not be guessed');
v026_assert(empty($preserved['scope_override']), 'unavailable scope must not override policy');

$unsupported = homeserver_scope_v026_fetch(8, true);
v026_assert($unsupported['supported'] === false && $unsupported['available'] === false, 'unadvertised scope remains legacy-compatible');

print("VP3 v0.26 HomeServer scope resolver passed\n");
