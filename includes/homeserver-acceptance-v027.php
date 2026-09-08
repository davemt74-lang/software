<?php
declare(strict_types=1);

/**
 * VP3 v0.27 — production HomeServer integration acceptance probe.
 *
 * This probe is read-only and zero-token. It verifies the authenticated
 * control plane used by VP3 without sending an Agent prompt or retrieving
 * private Memory/Knowledge contents into VP3.
 */
function homeserver_acceptance_v027_check(
    string $key,
    string $label,
    string $status,
    string $detail,
    ?int $latencyMs = null,
    array $meta = []
): array {
    if (!in_array($status, ['ready','warning','blocked','not_applicable'], true)) {
        $status = 'blocked';
    }
    $safeMeta = [];
    foreach (['count','provider','model','compute_source','version','cloud_allowed','restricted'] as $name) {
        if (!array_key_exists($name, $meta)) continue;
        $value = $meta[$name];
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $safeMeta[$name] = $value;
        } elseif (is_string($value)) {
            $safeMeta[$name] = mb_strimwidth($value, 0, 160, '');
        }
    }
    return [
        'key' => preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?: 'check',
        'label' => mb_strimwidth($label, 0, 80, ''),
        'status' => $status,
        'detail' => mb_strimwidth($detail, 0, 240, ''),
        'latency_ms' => $latencyMs === null ? null : max(0, min(60000, $latencyMs)),
        'meta' => $safeMeta,
    ];
}

function homeserver_acceptance_v027_remote(array $credentials, string $operation): array
{
    $allowed = ['inference.status','tools.list','skills.list','plugins.list'];
    if (!in_array($operation, $allowed, true) || !function_exists('homeserver_vp3_remote_operation')) {
        return ['ok'=>false,'latency_ms'=>null,'result'=>[]];
    }
    $started = microtime(true);
    try {
        $result = homeserver_vp3_remote_operation(
            (string)($credentials['relay'] ?? ''),
            $operation,
            [],
            (string)($credentials['home'] ?? '')
        );
        return [
            'ok' => true,
            'latency_ms' => max(0, (int)round((microtime(true) - $started) * 1000)),
            'result' => is_array($result) ? $result : [],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'latency_ms' => max(0, (int)round((microtime(true) - $started) * 1000)),
            'result' => [],
        ];
    }
}

function homeserver_acceptance_v027_capability_check(array $registry, array $scope, string $key, string $label): array
{
    $cap = is_array($registry['capabilities'][$key] ?? null) ? $registry['capabilities'][$key] : [];
    $supported = !empty($cap['supported']);
    $ready = !empty($cap['ready']);
    $restricted = $key === 'memory'
        ? !empty($scope['memory_restricted'])
        : ($key === 'knowledge' ? !empty($scope['knowledge_restricted']) : false);

    if ($ready) {
        return homeserver_acceptance_v027_check(
            $key,
            $label,
            'ready',
            $restricted ? 'Available through HomeServer with a narrower VP3 resource scope.' : 'Available through the paired HomeServer.',
            null,
            ['restricted'=>$restricted]
        );
    }
    if ($supported) {
        return homeserver_acceptance_v027_check($key, $label, 'warning', 'Supported by HomeServer but not currently ready.');
    }
    return homeserver_acceptance_v027_check($key, $label, 'blocked', 'This paired HomeServer does not currently advertise this capability.');
}

function homeserver_acceptance_v027_run(array $user): array
{
    $started = microtime(true);
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) throw new RuntimeException('Sign in to test HomeServer.');

    $registry = function_exists('homeserver_capability_v024_registry')
        ? homeserver_capability_v024_registry($userId, true)
        : ['paired'=>false,'connected'=>false,'capabilities'=>[],'inference'=>[]];
    $credentials = function_exists('homeserver_agent_v018_credentials')
        ? homeserver_agent_v018_credentials($userId)
        : null;
    $scopeState = function_exists('homeserver_scope_v026_fetch')
        ? homeserver_scope_v026_fetch($userId, true)
        : ['supported'=>false,'available'=>false,'scope'=>null];
    $scopePublic = function_exists('homeserver_scope_v026_public')
        ? homeserver_scope_v026_public($scopeState)
        : ['supported'=>false,'available'=>false,'cloud_allowed'=>null];

    $checks = [];
    $paired = is_array($credentials) && !empty($registry['paired']);
    $checks[] = homeserver_acceptance_v027_check(
        'pairing', 'Pairing', $paired ? 'ready' : 'blocked',
        $paired ? 'VP3 has an active paired HomeServer credential.' : 'Pair HomeServer with this VP3 account before running private capabilities.'
    );

    $toolsProbe = $paired ? homeserver_acceptance_v027_remote($credentials, 'tools.list') : ['ok'=>false,'latency_ms'=>null,'result'=>[]];
    $inferenceProbe = $paired ? homeserver_acceptance_v027_remote($credentials, 'inference.status') : ['ok'=>false,'latency_ms'=>null,'result'=>[]];
    $relayReady = !empty($toolsProbe['ok']) || !empty($inferenceProbe['ok']);
    $relayLatency = $toolsProbe['latency_ms'] ?? $inferenceProbe['latency_ms'] ?? null;
    $checks[] = homeserver_acceptance_v027_check(
        'relay', 'Remote Bridge', $relayReady ? 'ready' : 'blocked',
        $relayReady ? 'Authenticated read-only Remote Bridge request succeeded.' : 'VP3 could not complete an authenticated HomeServer Remote Bridge request.',
        is_int($relayLatency) ? $relayLatency : null
    );

    $scopeStatus = !empty($scopePublic['available']) ? 'ready' : (!empty($scopePublic['supported']) ? 'warning' : 'blocked');
    $scopeDetail = !empty($scopePublic['available'])
        ? 'HomeServer returned the effective VP3 application scope.'
        : (!empty($scopePublic['supported']) ? 'HomeServer scope reporting is supported but temporarily unavailable.' : 'Update HomeServer to a version that advertises app.scopes.v1.');
    $checks[] = homeserver_acceptance_v027_check('scope', 'VP3 scope', $scopeStatus, $scopeDetail);

    $checks[] = homeserver_acceptance_v027_capability_check($registry, $scopePublic, 'agent_brain', 'Agent Brain');

    $inference = [];
    if (!empty($inferenceProbe['ok']) && function_exists('agent_compute_v021_safe_inference')) {
        $inference = agent_compute_v021_safe_inference($inferenceProbe['result']);
    }
    $modelReady = !empty($inferenceProbe['ok']) && !empty($inference['available']);
    $modelDetail = $modelReady
        ? 'HomeServer inference status is ready without generating content.'
        : (!empty($inferenceProbe['ok']) ? 'HomeServer responded, but its selected model/provider is not ready.' : 'Inference status could not be reached through HomeServer.');
    $checks[] = homeserver_acceptance_v027_check(
        'model', 'Model / inference', $modelReady ? 'ready' : 'blocked', $modelDetail,
        is_int($inferenceProbe['latency_ms'] ?? null) ? $inferenceProbe['latency_ms'] : null,
        [
            'provider'=>(string)($inference['provider'] ?? ''),
            'model'=>(string)($inference['model'] ?? ''),
            'compute_source'=>(string)($inference['compute_source'] ?? ''),
        ]
    );

    $checks[] = homeserver_acceptance_v027_capability_check($registry, $scopePublic, 'memory', 'Memory');
    $checks[] = homeserver_acceptance_v027_capability_check($registry, $scopePublic, 'knowledge', 'Knowledge');

    $toolItems = is_array($toolsProbe['result']['items'] ?? null) ? $toolsProbe['result']['items'] : [];
    $toolCount = count($toolItems);
    $checks[] = homeserver_acceptance_v027_check(
        'tools', 'Tools', !empty($toolsProbe['ok']) ? ($toolCount > 0 ? 'ready' : 'warning') : 'blocked',
        !empty($toolsProbe['ok']) ? ($toolCount > 0 ? $toolCount.' tool'.($toolCount===1?'':'s').' available to VP3.' : 'HomeServer is reachable, but no tools are currently granted to VP3.') : 'HomeServer tool registry could not be reached.',
        is_int($toolsProbe['latency_ms'] ?? null) ? $toolsProbe['latency_ms'] : null,
        ['count'=>$toolCount]
    );

    $skillsProbe = $paired ? homeserver_acceptance_v027_remote($credentials, 'skills.list') : ['ok'=>false,'latency_ms'=>null,'result'=>[]];
    $skillItems = is_array($skillsProbe['result']['items'] ?? null) ? $skillsProbe['result']['items'] : [];
    $checks[] = homeserver_acceptance_v027_check(
        'skills', 'Skills', !empty($skillsProbe['ok']) ? 'ready' : 'warning',
        !empty($skillsProbe['ok']) ? count($skillItems).' skill'.(count($skillItems)===1?'':'s').' visible to VP3.' : 'Skill registry is not currently available to VP3.',
        is_int($skillsProbe['latency_ms'] ?? null) ? $skillsProbe['latency_ms'] : null,
        ['count'=>count($skillItems)]
    );

    $pluginsProbe = $paired ? homeserver_acceptance_v027_remote($credentials, 'plugins.list') : ['ok'=>false,'latency_ms'=>null,'result'=>[]];
    $pluginItems = is_array($pluginsProbe['result']['items'] ?? null) ? $pluginsProbe['result']['items'] : [];
    $checks[] = homeserver_acceptance_v027_check(
        'plugins', 'Plugins', !empty($pluginsProbe['ok']) ? 'ready' : 'warning',
        !empty($pluginsProbe['ok']) ? count($pluginItems).' plugin'.(count($pluginItems)===1?'':'s').' visible to VP3.' : 'Plugin registry is not currently available to VP3.',
        is_int($pluginsProbe['latency_ms'] ?? null) ? $pluginsProbe['latency_ms'] : null,
        ['count'=>count($pluginItems)]
    );

    $cloudAllowed = $scopePublic['cloud_allowed'] ?? null;
    $cloudDetail = $cloudAllowed === false
        ? 'Blocked by the HomeServer VP3 scope; VP3 Cloud fallback must not run.'
        : ($cloudAllowed === true ? 'Allowed when the saved VP3 compute policy permits fallback.' : 'Cloud fallback boundary is unknown until the authenticated scope can be refreshed.');
    $checks[] = homeserver_acceptance_v027_check(
        'cloud_fallback', 'Cloud fallback', $cloudAllowed === null ? 'warning' : 'ready', $cloudDetail,
        null, ['cloud_allowed'=>$cloudAllowed]
    );

    $version = trim((string)($registry['installed_version'] ?? ''));
    $checks[] = homeserver_acceptance_v027_check(
        'version', 'HomeServer version', $version !== '' ? 'ready' : 'warning',
        $version !== '' ? 'Connected HomeServer version '.$version.'.' : 'HomeServer version was not reported.',
        null, ['version'=>$version]
    );

    $blocked = count(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') === 'blocked'));
    $warnings = count(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') === 'warning'));
    $ready = count(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') === 'ready'));

    return [
        'version' => 'v0.27',
        'tested_at' => gmdate('c'),
        'probe_kind' => 'read_only_acceptance',
        'token_spend' => 0,
        'production_ready' => $blocked === 0,
        'summary' => ['ready'=>$ready,'warnings'=>$warnings,'blocked'=>$blocked,'total'=>count($checks)],
        'duration_ms' => max(0, (int)round((microtime(true) - $started) * 1000)),
        'checks' => $checks,
    ];
}
