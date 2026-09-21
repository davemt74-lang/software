<?php
declare(strict_types=1);

/**
 * VP3 Node v0.10 — HomeServer Appliance Architecture.
 *
 * This layer describes a HomeServer appliance and resolves placement intent.
 * It does not execute AI work, own Cognitive Runtime state, widen HomeServer
 * permissions, or expose relay/bearer credentials. Existing HomeServer,
 * Agent Runtime Routing and Cognitive Runtime remain authoritative.
 */
const VP3_HOMESERVER_APPLIANCE_V010 = 'vp3-homeserver-appliance-v010-20260921';

function homeserver_appliance_v010_placements(): array
{
    return ['LOCAL', 'LOCAL_ONLY', 'CLOUD', 'HYBRID', 'DEFER'];
}

function homeserver_appliance_v010_profiles(): array
{
    return [
        'custom' => [
            'label' => 'HomeServer',
            'portable' => false,
            'shared' => false,
            'creator' => false,
            'expected_hardware' => [],
        ],
        'node' => [
            'label' => 'VP3 Node',
            'portable' => false,
            'shared' => false,
            'creator' => false,
            'expected_hardware' => ['button', 'status_light', 'microphone', 'speaker', 'privacy_switch'],
        ],
        'desk' => [
            'label' => 'VP3 Desk',
            'portable' => false,
            'shared' => false,
            'creator' => false,
            'expected_hardware' => ['button', 'status_light', 'microphone', 'speaker', 'privacy_switch', 'display'],
        ],
        'studio' => [
            'label' => 'VP3 Studio',
            'portable' => false,
            'shared' => false,
            'creator' => true,
            'expected_hardware' => ['button', 'status_light', 'microphone', 'speaker', 'privacy_switch', 'audio_io'],
        ],
        'team_node' => [
            'label' => 'VP3 Team Node',
            'portable' => false,
            'shared' => true,
            'creator' => false,
            'expected_hardware' => ['button', 'status_light', 'privacy_switch'],
        ],
        'pocket' => [
            'label' => 'VP3 Pocket',
            'portable' => true,
            'shared' => false,
            'creator' => false,
            'expected_hardware' => ['button', 'status_light', 'microphone', 'speaker', 'privacy_switch', 'display', 'battery'],
        ],
    ];
}

function homeserver_appliance_v010_string(mixed $value, int $max = 120): string
{
    $value = trim((string)$value);
    return mb_strimwidth($value, 0, max(1, $max), '');
}

function homeserver_appliance_v010_string_list(mixed $value, int $maxItems = 64, int $maxChars = 120): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) continue;
        $item = homeserver_appliance_v010_string($item, $maxChars);
        if ($item === '' || in_array($item, $out, true)) continue;
        $out[] = $item;
        if (count($out) >= $maxItems) break;
    }
    return $out;
}

function homeserver_appliance_v010_profile(string $profile): array
{
    $profile = strtolower(trim($profile));
    $profiles = homeserver_appliance_v010_profiles();
    return $profiles[$profile] ?? $profiles['custom'];
}

function homeserver_appliance_v010_hardware(array $raw): array
{
    $allowed = [
        'button', 'status_light', 'microphone', 'speaker', 'privacy_switch',
        'display', 'audio_io', 'battery', 'camera', 'storage', 'accelerator',
    ];
    $hardware = [];
    foreach ($allowed as $key) {
        $item = is_array($raw[$key] ?? null) ? $raw[$key] : [];
        $hardware[$key] = [
            'present' => !empty($item['present']),
            'ready' => !empty($item['ready']),
        ];
        if ($key === 'privacy_switch') {
            $hardware[$key]['physical_disconnect'] = !empty($item['physical_disconnect']);
            $hardware[$key]['microphone_powered'] = array_key_exists('microphone_powered', $item)
                ? !empty($item['microphone_powered'])
                : null;
        }
        if ($key === 'storage') {
            $hardware[$key]['bytes_total'] = max(0, (int)($item['bytes_total'] ?? 0));
            $hardware[$key]['bytes_free'] = max(0, (int)($item['bytes_free'] ?? 0));
        }
        if ($key === 'accelerator') {
            $hardware[$key]['kind'] = homeserver_appliance_v010_string($item['kind'] ?? '', 80);
        }
    }
    return $hardware;
}

function homeserver_appliance_v010_manifest(array $raw = []): array
{
    $profileKey = strtolower(homeserver_appliance_v010_string($raw['profile'] ?? 'custom', 40));
    if (!array_key_exists($profileKey, homeserver_appliance_v010_profiles())) $profileKey = 'custom';
    $profile = homeserver_appliance_v010_profile($profileKey);

    $privacyRaw = is_array($raw['privacy'] ?? null) ? $raw['privacy'] : [];
    $runtimeRaw = is_array($raw['runtime'] ?? null) ? $raw['runtime'] : [];

    return [
        'contract' => VP3_HOMESERVER_APPLIANCE_V010,
        'profile' => $profileKey,
        'label' => homeserver_appliance_v010_string($raw['label'] ?? $profile['label'], 120),
        'device_id' => homeserver_appliance_v010_string($raw['device_id'] ?? '', 100),
        'hardware_revision' => homeserver_appliance_v010_string($raw['hardware_revision'] ?? '', 64),
        'firmware_version' => homeserver_appliance_v010_string($raw['firmware_version'] ?? '', 64),
        'runtime_version' => homeserver_appliance_v010_string($runtimeRaw['version'] ?? '', 64),
        'portable' => !empty($profile['portable']),
        'shared' => !empty($profile['shared']),
        'creator' => !empty($profile['creator']),
        'hardware' => homeserver_appliance_v010_hardware(is_array($raw['hardware'] ?? null) ? $raw['hardware'] : []),
        'privacy' => [
            'microphone_requires_local_authorization' => !array_key_exists('microphone_requires_local_authorization', $privacyRaw)
                || !empty($privacyRaw['microphone_requires_local_authorization']),
            'raw_audio_cloud_default' => false,
            'physical_microphone_disconnect' => !empty($privacyRaw['physical_microphone_disconnect']),
        ],
        'services' => homeserver_appliance_v010_string_list($raw['services'] ?? [], 64, 100),
        'operations' => homeserver_appliance_v010_string_list($raw['operations'] ?? [], 128, 100),
    ];
}

function homeserver_appliance_v010_default_manifest(): array
{
    return homeserver_appliance_v010_manifest([
        'profile' => 'custom',
        'label' => 'HomeServer',
        'privacy' => [
            'microphone_requires_local_authorization' => true,
            'physical_microphone_disconnect' => false,
        ],
    ]);
}

function homeserver_appliance_v010_registry_projection(array $registry): array
{
    $services = [];
    foreach ((array)($registry['services'] ?? []) as $service) {
        if (!is_array($service)) continue;
        $key = homeserver_appliance_v010_string($service['key'] ?? '', 100);
        if ($key !== '') $services[] = $key;
    }

    return [
        'available' => !empty($registry['available']),
        'reason' => homeserver_appliance_v010_string($registry['reason'] ?? 'unavailable', 80),
        'installed_version' => homeserver_appliance_v010_string($registry['installed_version'] ?? '', 64),
        'brain_available' => !empty($registry['brain']['available']),
        'compute_available' => !empty($registry['compute']['available']),
        'compute_source' => homeserver_appliance_v010_string($registry['compute']['compute_source'] ?? '', 80),
        'local_models' => homeserver_appliance_v010_string_list($registry['compute']['installed_local_models'] ?? [], 100, 160),
        'memory_available' => !empty($registry['memory']['available']),
        'knowledge_available' => !empty($registry['knowledge']['available']),
        'files_available' => !empty($registry['files']['available']),
        'services' => array_values(array_unique($services)),
        'operations' => homeserver_appliance_v010_string_list($registry['operations'] ?? [], 200, 100),
    ];
}

/**
 * Resolve intended execution placement without executing the work.
 *
 * Work descriptor fields are deliberately metadata-only:
 * - sensitivity: public|internal|private|secret
 * - needs_hardware: bool
 * - needs_local_data: bool
 * - needs_cloud_model: bool
 * - collaboration: bool
 * - raw_audio: bool
 * - cloud_allowed: bool|null (caller may narrow, never widen, HomeServer scope)
 * - local_ready: bool
 * - cloud_ready: bool
 */
function homeserver_appliance_v010_plan(array $work, array $runtime = []): array
{
    $sensitivity = strtolower(homeserver_appliance_v010_string($work['sensitivity'] ?? 'internal', 20));
    if (!in_array($sensitivity, ['public', 'internal', 'private', 'secret'], true)) $sensitivity = 'internal';

    $localReady = !empty($runtime['local_ready']);
    $cloudReady = !empty($runtime['cloud_ready']);
    $scopeCloudAllowed = array_key_exists('cloud_allowed', $runtime) ? !empty($runtime['cloud_allowed']) : true;
    $workCloudAllowed = array_key_exists('cloud_allowed', $work) ? !empty($work['cloud_allowed']) : true;
    $cloudAllowed = $scopeCloudAllowed && $workCloudAllowed;

    $needsHardware = !empty($work['needs_hardware']);
    $needsLocalData = !empty($work['needs_local_data']);
    $needsCloudModel = !empty($work['needs_cloud_model']);
    $collaboration = !empty($work['collaboration']);
    $rawAudio = !empty($work['raw_audio']);

    $placement = 'DEFER';
    $reason = 'no_runtime_ready';

    // Raw meeting/audio capture and explicitly secret work never leave the box
    // through this policy. A later explicit derived artifact can be classified
    // separately by the Cognitive Runtime.
    if ($rawAudio || $sensitivity === 'secret') {
        if ($localReady) {
            $placement = 'LOCAL_ONLY';
            $reason = $rawAudio ? 'raw_audio_local_only' : 'secret_local_only';
        } else {
            $placement = 'DEFER';
            $reason = 'local_only_runtime_unavailable';
        }
    } elseif ($needsHardware || $needsLocalData) {
        if (!$localReady) {
            $placement = 'DEFER';
            $reason = $needsHardware ? 'hardware_runtime_unavailable' : 'local_data_runtime_unavailable';
        } elseif ($needsCloudModel && $cloudAllowed && $cloudReady) {
            $placement = 'HYBRID';
            $reason = 'local_context_cloud_reasoning';
        } else {
            $placement = 'LOCAL';
            $reason = $needsHardware ? 'hardware_required' : 'local_data_required';
        }
    } elseif ($collaboration && $localReady && $cloudAllowed && $cloudReady) {
        $placement = 'HYBRID';
        $reason = 'local_and_collaboration';
    } elseif ($needsCloudModel) {
        if ($cloudAllowed && $cloudReady) {
            $placement = 'CLOUD';
            $reason = 'cloud_model_required';
        } elseif ($localReady) {
            $placement = 'LOCAL';
            $reason = $cloudAllowed ? 'cloud_unavailable_local_available' : 'cloud_disallowed_local_available';
        } else {
            $placement = 'DEFER';
            $reason = $cloudAllowed ? 'required_cloud_unavailable' : 'cloud_disallowed';
        }
    } elseif ($localReady) {
        $placement = 'LOCAL';
        $reason = 'local_preferred';
    } elseif ($cloudAllowed && $cloudReady) {
        $placement = 'CLOUD';
        $reason = 'local_unavailable_cloud_available';
    }

    return [
        'contract' => VP3_HOMESERVER_APPLIANCE_V010,
        'placement' => $placement,
        'reason' => $reason,
        'local_ready' => $localReady,
        'cloud_ready' => $cloudReady,
        'cloud_allowed' => $cloudAllowed,
        'requires_local_presence' => $needsHardware || $needsLocalData || $rawAudio || $sensitivity === 'secret',
        'raw_audio_cloud_allowed' => false,
    ];
}

function homeserver_appliance_v010_snapshot(int $userId, bool $forceRefresh = false): array
{
    $registry = function_exists('homeserver_capability_v033_registry')
        ? homeserver_capability_v033_registry($userId, $forceRefresh)
        : [];

    $status = [];
    if ($userId > 0 && function_exists('homeserver_vp3_status')) {
        try {
            $status = homeserver_vp3_status($userId, $forceRefresh);
        } catch (Throwable $e) {
            $status = [];
        }
    }

    // Current HomeServer versions may not advertise an appliance block yet.
    // v0.10 therefore degrades to a generic HomeServer manifest and never
    // guesses physical hardware that the remote device did not report.
    $rawAppliance = is_array($registry['appliance'] ?? null) ? $registry['appliance'] : [];
    if ($rawAppliance === []) {
        $rawAppliance = [
            'profile' => 'custom',
            'label' => 'HomeServer',
            'device_id' => $status['device_id'] ?? '',
            'runtime' => ['version' => $status['installed_version'] ?? ''],
        ];
    }

    return [
        'version' => 'v0.10',
        'contract' => VP3_HOMESERVER_APPLIANCE_V010,
        'connected' => !empty($status['connected']),
        'manifest' => homeserver_appliance_v010_manifest($rawAppliance),
        'homeserver' => homeserver_appliance_v010_registry_projection(is_array($registry) ? $registry : []),
        'placement_modes' => homeserver_appliance_v010_placements(),
        'authority' => [
            'identity_and_pairing' => 'homeserver_existing',
            'compute_routing' => 'agent_runtime_routing_v420',
            'cognitive_state' => 'cognitive_runtime_v500',
            'hardware_io' => 'homeserver_appliance_runtime_future_v020',
        ],
    ];
}
