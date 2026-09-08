<?php
declare(strict_types=1);

$GLOBALS['v027_fail_inference'] = false;

function homeserver_capability_v024_registry(int $userId, bool $forceRefresh = false): array
{
    return [
        'version'=>'v0.24',
        'paired'=>true,
        'connected'=>true,
        'installed_version'=>'0.27.0',
        'inference'=>['available'=>true,'provider'=>'ollama','model'=>'llama-test','compute_source'=>'homeserver_local'],
        'capabilities'=>[
            'agent_brain'=>['supported'=>true,'ready'=>true],
            'memory'=>['supported'=>true,'ready'=>true],
            'knowledge'=>['supported'=>true,'ready'=>true],
        ],
    ];
}

function homeserver_agent_v018_credentials(int $userId): ?array
{
    return $userId === 7 ? ['relay'=>'relay-secret','home'=>'home-secret'] : null;
}

function homeserver_scope_v026_fetch(int $userId, bool $forceRefresh = false): array
{
    return [
        'supported'=>true,
        'available'=>true,
        'scope'=>[
            'cloud_allowed'=>false,
            'memory_key_prefixes'=>['vp3:private:'],
            'knowledge_kinds'=>['note'],
            'tool_names'=>['memory.list','knowledge.search'],
            'plugin_keys'=>['vp3.private'],
        ],
    ];
}

function homeserver_scope_v026_public(array $state): array
{
    return [
        'supported'=>true,
        'available'=>true,
        'reason'=>'scope_ready',
        'cloud_allowed'=>false,
        'memory_restricted'=>true,
        'memory_prefix_count'=>1,
        'knowledge_restricted'=>true,
        'knowledge_kind_count'=>1,
        'tools_restricted'=>true,
        'tool_count'=>2,
        'plugins_restricted'=>true,
        'plugin_count'=>1,
    ];
}

function homeserver_vp3_remote_operation(string $relay, string $operation, array $payload = [], string $home = ''): array
{
    if ($relay !== 'relay-secret' || $home !== 'home-secret') {
        throw new RuntimeException('Bad synthetic credentials');
    }
    return match ($operation) {
        'inference.status' => !empty($GLOBALS['v027_fail_inference'])
            ? throw new RuntimeException('Synthetic provider outage')
            : ['available'=>true,'selected_provider'=>'ollama','model'=>'llama-test','compute_source'=>'homeserver_local'],
        'tools.list' => ['items'=>[['key'=>'memory.list'],['key'=>'knowledge.search']], 'app_scope'=>['cloud_allowed'=>false]],
        'skills.list' => ['items'=>[['key'=>'research']]],
        'plugins.list' => ['items'=>[['plugin_key'=>'vp3.private']]],
        default => throw new RuntimeException('Unexpected operation: '.$operation),
    };
}

function agent_compute_v021_safe_inference(array $result): array
{
    return [
        'available'=>!empty($result['available']),
        'provider'=>(string)($result['selected_provider'] ?? ''),
        'model'=>(string)($result['model'] ?? ''),
        'compute_source'=>(string)($result['compute_source'] ?? ''),
    ];
}

require dirname(__DIR__) . '/includes/homeserver-acceptance-v027.php';

function v027_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function v027_check(array $result, string $key): array
{
    foreach ($result['checks'] ?? [] as $check) {
        if (($check['key'] ?? '') === $key) return $check;
    }
    return [];
}

$healthy = homeserver_acceptance_v027_run(['id'=>7]);
v027_assert($healthy['version'] === 'v0.27', 'acceptance version');
v027_assert($healthy['probe_kind'] === 'read_only_acceptance', 'acceptance must be read-only');
v027_assert($healthy['token_spend'] === 0, 'acceptance must spend zero model tokens');
v027_assert($healthy['production_ready'] === true, 'healthy HomeServer should pass production acceptance');
v027_assert(($healthy['summary']['blocked'] ?? -1) === 0, 'healthy acceptance must have no blockers');
v027_assert((v027_check($healthy, 'pairing')['status'] ?? '') === 'ready', 'pairing ready');
v027_assert((v027_check($healthy, 'relay')['status'] ?? '') === 'ready', 'relay ready');
v027_assert((v027_check($healthy, 'scope')['status'] ?? '') === 'ready', 'scope ready');
v027_assert((v027_check($healthy, 'agent_brain')['status'] ?? '') === 'ready', 'Agent Brain ready');
v027_assert((v027_check($healthy, 'model')['status'] ?? '') === 'ready', 'model ready');
v027_assert((v027_check($healthy, 'memory')['status'] ?? '') === 'ready', 'Memory ready');
v027_assert((v027_check($healthy, 'knowledge')['status'] ?? '') === 'ready', 'Knowledge ready');
v027_assert((v027_check($healthy, 'tools')['meta']['count'] ?? 0) === 2, 'tool count only');
v027_assert((v027_check($healthy, 'skills')['meta']['count'] ?? 0) === 1, 'skill count only');
v027_assert((v027_check($healthy, 'plugins')['meta']['count'] ?? 0) === 1, 'plugin count only');
v027_assert((v027_check($healthy, 'cloud_fallback')['status'] ?? '') === 'ready', 'cloud block is a valid enforced state');
v027_assert((v027_check($healthy, 'cloud_fallback')['meta']['cloud_allowed'] ?? true) === false, 'cloud fallback must report blocked scope');

$encoded = json_encode($healthy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
v027_assert(is_string($encoded), 'acceptance result must encode');
foreach (['vp3:private:', 'vp3.private', 'memory.list', 'knowledge.search', 'relay-secret', 'home-secret'] as $secret) {
    v027_assert(!str_contains($encoded, $secret), 'diagnostic output must not expose '.$secret);
}

$GLOBALS['v027_fail_inference'] = true;
$providerDown = homeserver_acceptance_v027_run(['id'=>7]);
v027_assert($providerDown['production_ready'] === false, 'provider outage must fail production acceptance');
v027_assert((v027_check($providerDown, 'model')['status'] ?? '') === 'blocked', 'provider outage must block model check');
v027_assert(($providerDown['summary']['blocked'] ?? 0) >= 1, 'provider outage must produce a blocker');
v027_assert($providerDown['token_spend'] === 0, 'failed acceptance must still spend zero tokens');

print("VP3 v0.27 HomeServer production acceptance regression passed\n");
