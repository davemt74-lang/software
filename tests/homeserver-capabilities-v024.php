<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/homeserver-capabilities-v024.php';

function v024_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$raw = [
    'version' => '0.18.0',
    'permissions' => ['agent.chat', 'memory.read', 'knowledge.search', 'tools.execute'],
    'features' => [
        'agent.chat', 'inference.routing', 'inference.status',
        'memory.read', 'memory.provenance', 'knowledge.search',
        'tools.execute', 'skills',
    ],
    'inference' => [
        'available' => true,
        'selected_provider' => 'ollama',
        'model' => 'llama-test',
        'compute_source' => 'homeserver_local',
    ],
];
$status = [
    'state' => 'connected',
    'connected' => true,
    'paired' => true,
    'installed_version' => '0.18.0',
    'capabilities' => $raw['features'],
    'inference' => $raw['inference'],
];
$registry = homeserver_capability_v024_registry_from($status, $raw);

v024_assert($registry['version'] === 'v0.24', 'registry version');
v024_assert($registry['advertised'] === true, 'advertised capability registry');
v024_assert($registry['capabilities']['agent_brain']['ready'] === true, 'Agent Brain should be ready');
v024_assert($registry['capabilities']['memory']['ready'] === true, 'Memory should be ready');
v024_assert($registry['capabilities']['knowledge']['ready'] === true, 'Knowledge should be ready');
v024_assert($registry['capabilities']['contacts']['supported'] === false, 'Unadvertised Contacts must not be assumed');

$brain = homeserver_capability_v024_resolve($registry, 'agent_brain', 'vp3_cloud');
v024_assert($brain['source'] === 'homeserver', 'ready Agent Brain must route to HomeServer');
$contacts = homeserver_capability_v024_resolve($registry, 'contacts', 'vp3');
v024_assert($contacts['source'] === 'vp3', 'unsupported Contacts must fall back to VP3');
v024_assert($contacts['reason'] === 'capability_not_advertised', 'unsupported capability reason');

$offline = $status;
$offline['state'] = 'offline';
$offline['connected'] = false;
$offlineRegistry = homeserver_capability_v024_registry_from($offline, $raw);
v024_assert($offlineRegistry['capabilities']['agent_brain']['supported'] === true, 'offline HomeServer keeps advertised support');
v024_assert($offlineRegistry['capabilities']['agent_brain']['ready'] === false, 'offline HomeServer is not currently ready');
v024_assert($offlineRegistry['capabilities']['agent_brain']['reason'] === 'homeserver_offline', 'offline reason');

$providerDown = $status;
$providerDown['inference']['available'] = false;
$providerRegistry = homeserver_capability_v024_registry_from($providerDown, $raw);
v024_assert($providerRegistry['capabilities']['agent_brain']['supported'] === true, 'provider outage does not erase support');
v024_assert($providerRegistry['capabilities']['agent_brain']['ready'] === false, 'provider outage makes Agent Brain unready');
v024_assert($providerRegistry['capabilities']['agent_brain']['reason'] === 'provider_unavailable', 'provider outage reason');

$legacyStatus = [
    'state' => 'connected',
    'connected' => true,
    'paired' => true,
    'installed_version' => 'legacy',
    'capabilities' => [],
    'inference' => ['available' => true],
];
$legacy = homeserver_capability_v024_registry_from($legacyStatus, []);
v024_assert($legacy['capabilities']['agent_brain']['supported'] === true, 'legacy Agent Brain compatibility');
v024_assert($legacy['capabilities']['agent_brain']['legacy_assumed'] === true, 'legacy assumption must be explicit');
v024_assert($legacy['capabilities']['memory']['supported'] === false, 'legacy compatibility must not assume private Memory');

$route = homeserver_capability_v024_public_route($brain, 'homeserver', false);
v024_assert($route['actual_source'] === 'homeserver', 'public route actual source');
v024_assert(!array_key_exists('permission', $route), 'public route must not expose permissions');
v024_assert(!array_key_exists('operation', $route), 'public route must not expose operation internals');

$unknown = homeserver_capability_v024_resolve($registry, 'does_not_exist', 'vp3');
v024_assert($unknown['source'] === 'vp3', 'unknown capability uses explicit safe fallback');
v024_assert($unknown['reason'] === 'unknown_capability', 'unknown capability reason');

print("VP3 v0.24 HomeServer capability resolver passed\n");
