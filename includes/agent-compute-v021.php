<?php
declare(strict_types=1);

/**
 * VP3 v0.21 — zero-token compute health, route testing and safe fallback reasons.
 *
 * This layer is intentionally diagnostic-only. It reuses v0.20 routing and
 * never sends a chat prompt just to test compute availability.
 */
function agent_compute_v021_reason(
    string $preference,
    bool $homePaired,
    bool $homeReady,
    bool $cloudReady,
    string $homeState = 'unpaired'
): array {
    if (!agent_compute_v020_valid_preference($preference)) {
        $preference = 'auto';
    }
    $homeState = strtolower(trim($homeState));

    if ($preference === 'vp3_cloud') {
        return $cloudReady
            ? ['code'=>'vp3_cloud_selected','label'=>'VP3 Cloud selected','detail'=>'Your Agent is configured to run directly through VP3 Cloud.','severity'=>'ready','action'=>'none']
            : ['code'=>'vp3_cloud_unavailable','label'=>'VP3 Cloud needs attention','detail'=>'VP3 Cloud is selected, but its current provider is not ready.','severity'=>'blocked','action'=>'configure_cloud'];
    }

    if ($preference === 'homeserver_only') {
        if (!$homePaired) {
            return ['code'=>'homeserver_required_unpaired','label'=>'Connect HomeServer','detail'=>'HomeServer only is selected, but this account is not paired with HomeServer.','severity'=>'blocked','action'=>'connect_homeserver'];
        }
        if (!$homeReady) {
            return ['code'=>'homeserver_required_unavailable','label'=>'HomeServer is not ready','detail'=>'HomeServer only is selected, so VP3 Cloud fallback will not be used while HomeServer is unavailable.','severity'=>'blocked','action'=>'check_homeserver'];
        }
        return ['code'=>'homeserver_required_ready','label'=>'HomeServer route ready','detail'=>'Your Agent is constrained to the model or provider selected in HomeServer.','severity'=>'ready','action'=>'none'];
    }

    if ($homeReady) {
        return ['code'=>'homeserver_preferred','label'=>'HomeServer preferred','detail'=>'Automatic routing will use your paired HomeServer before considering VP3 Cloud.','severity'=>'ready','action'=>'none'];
    }
    if (!$homePaired) {
        return $cloudReady
            ? ['code'=>'homeserver_not_paired_fallback','label'=>'Using VP3 Cloud','detail'=>'Automatic routing cannot use HomeServer because it is not paired, so VP3 Cloud is the available route.','severity'=>'warning','action'=>'connect_homeserver']
            : ['code'=>'no_route_ready','label'=>'No compute route is ready','detail'=>'HomeServer is not paired and the VP3 Cloud provider is not ready.','severity'=>'blocked','action'=>'configure_compute'];
    }
    if ($cloudReady) {
        $detail = in_array($homeState, ['offline','error'], true)
            ? 'Automatic routing will use VP3 Cloud because the paired HomeServer is currently unavailable.'
            : 'Automatic routing will use VP3 Cloud because HomeServer Agent compute is not ready.';
        return ['code'=>'homeserver_unavailable_fallback','label'=>'VP3 Cloud fallback','detail'=>$detail,'severity'=>'warning','action'=>'check_homeserver'];
    }
    return ['code'=>'no_route_ready','label'=>'No compute route is ready','detail'=>'The paired HomeServer is not ready and the VP3 Cloud provider is unavailable.','severity'=>'blocked','action'=>'check_compute'];
}

function agent_compute_v021_state(PDO $pdo, array $user): array
{
    $state = agent_compute_v020_state($pdo, $user);
    $home = is_array($state['homeserver'] ?? null) ? $state['homeserver'] : [];
    $cloud = is_array($state['cloud'] ?? null) ? $state['cloud'] : [];
    $reason = agent_compute_v021_reason(
        (string)($state['preference'] ?? 'auto'),
        !empty($home['paired']),
        !empty($home['agent_brain_ready']),
        !empty($cloud['provider_ready']),
        (string)($home['state'] ?? 'unpaired')
    );
    $state['version'] = 'v0.21';
    $state['reason'] = $reason;
    $state['route_test_supported'] = true;
    $state['health'] = [
        'route_ready' => (string)($reason['severity'] ?? '') !== 'blocked',
        'homeserver_ready' => !empty($home['agent_brain_ready']),
        'vp3_cloud_ready' => !empty($cloud['provider_ready']),
    ];
    return $state;
}

function agent_compute_v021_safe_inference(array $result): array
{
    $provider = trim((string)($result['selected_provider'] ?? $result['provider_key'] ?? $result['provider'] ?? ''));
    $model = trim((string)($result['model'] ?? ''));
    $source = trim((string)($result['compute_source'] ?? ''));
    if (!in_array($source, ['homeserver_local','user_provider'], true)) {
        $source = $provider === 'ollama' ? 'homeserver_local' : 'user_provider';
    }
    return [
        'available' => !empty($result['available']),
        'provider' => mb_strimwidth($provider, 0, 80, ''),
        'model' => mb_strimwidth($model, 0, 160, ''),
        'compute_source' => $source,
        'cloud_fallback_required' => !empty($result['cloud_fallback_required']),
    ];
}

/**
 * Test the configured route without generating content or spending model tokens.
 */
function agent_compute_v021_route_test(PDO $pdo, array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Sign in to test Agent compute.');
    }

    $preference = agent_compute_v020_preference($pdo, $userId);
    $cloud = agent_compute_v020_cloud_state($user);
    $cloudReady = !empty($cloud['provider_ready']);
    $credentials = function_exists('homeserver_agent_v018_credentials')
        ? homeserver_agent_v018_credentials($userId)
        : null;
    $homePaired = is_array($credentials);
    $base = [
        'tested_at' => gmdate('c'),
        'preference' => $preference,
        'probe_kind' => 'read_only_status',
        'token_spend' => 0,
        'latency_ms' => null,
        'fallback_used' => false,
        'ready' => false,
        'route' => 'blocked',
        'route_label' => 'No route ready',
        'provider' => '',
        'model' => '',
        'compute_source' => '',
    ];

    if ($preference === 'vp3_cloud') {
        $reason = agent_compute_v021_reason($preference, $homePaired, false, $cloudReady, '');
        return array_replace($base, [
            'ready' => $cloudReady,
            'route' => $cloudReady ? 'vp3_cloud' : 'blocked',
            'route_label' => $cloudReady ? 'VP3 Cloud' : 'VP3 Cloud unavailable',
            'provider' => mb_strimwidth((string)($cloud['provider'] ?? ''), 0, 80, ''),
            'model' => mb_strimwidth((string)($cloud['model'] ?? ''), 0, 160, ''),
            'compute_source' => 'vp3_cloud',
            'reason' => $reason,
        ]);
    }

    $homeAvailable = false;
    $homeProbe = [];
    $homeProbeFailed = false;
    if ($homePaired && function_exists('homeserver_vp3_remote_operation')) {
        $started = microtime(true);
        try {
            $remote = homeserver_vp3_remote_operation(
                (string)$credentials['relay'],
                'inference.status',
                [],
                (string)$credentials['home']
            );
            $base['latency_ms'] = max(0, (int)round((microtime(true) - $started) * 1000));
            $homeProbe = agent_compute_v021_safe_inference($remote);
            $homeAvailable = !empty($homeProbe['available']);
        } catch (Throwable $e) {
            $base['latency_ms'] = max(0, (int)round((microtime(true) - $started) * 1000));
            $homeProbeFailed = true;
        }
    }

    if ($homeAvailable) {
        $source = (string)($homeProbe['compute_source'] ?? 'user_provider');
        $reason = agent_compute_v021_reason($preference, true, true, $cloudReady, 'connected');
        return array_replace($base, [
            'ready' => true,
            'route' => 'homeserver',
            'route_label' => $source === 'homeserver_local' ? 'HomeServer Local' : 'Connected Provider via HomeServer',
            'provider' => (string)($homeProbe['provider'] ?? ''),
            'model' => (string)($homeProbe['model'] ?? ''),
            'compute_source' => $source,
            'reason' => $reason,
            'homeserver' => $homeProbe,
        ]);
    }

    $homeState = !$homePaired ? 'unpaired' : ($homeProbeFailed ? 'offline' : 'connected');
    $reason = agent_compute_v021_reason($preference, $homePaired, false, $cloudReady, $homeState);
    if ($preference === 'auto' && $cloudReady) {
        return array_replace($base, [
            'ready' => true,
            'route' => 'vp3_cloud',
            'route_label' => 'VP3 Cloud fallback',
            'provider' => mb_strimwidth((string)($cloud['provider'] ?? ''), 0, 80, ''),
            'model' => mb_strimwidth((string)($cloud['model'] ?? ''), 0, 160, ''),
            'compute_source' => 'vp3_cloud',
            'fallback_used' => true,
            'reason' => $reason,
        ]);
    }

    return array_replace($base, [
        'reason' => $reason,
        'route_label' => (string)($reason['label'] ?? 'No route ready'),
    ]);
}
