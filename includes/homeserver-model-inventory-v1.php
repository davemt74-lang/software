<?php
declare(strict_types=1);

/** Read-only projection of the Otro inventory contract. Not a routing authority. */
const VP3_HOMESERVER_MODEL_INVENTORY_V1 = 'vp3.local-model-inventory.v1';
const VP3_HOMESERVER_MODEL_INVENTORY_MAX_V1 = 256;

function vp3_homeserver_model_id_v1(mixed $value): bool
{
    if (!is_string($value) || preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}\z~D', $value) !== 1) return false;
    if (in_array(substr($value, -1), [':', '.', '-'], true)) return false;
    foreach (explode('/', $value) as $part) {
        if (in_array($part, ['', '.', '..'], true)) return false;
    }
    return true;
}

/**
 * Normalize only after the existing authenticated owner/device boundary.
 * Missing/unknown versions and malformed snapshots cannot enable routing.
 * Never copy arbitrary fields, endpoints, credentials, or remote eligibility claims.
 */
function vp3_homeserver_model_inventory_v1(mixed $raw): array
{
    $out = [
        'version' => VP3_HOMESERVER_MODEL_INVENTORY_V1,
        'state' => 'unsupported', 'provider' => 'ollama', 'enabled' => false,
        'configured_model' => null, 'configured_model_reported' => false,
        'truncated' => false, 'models' => [],
    ];
    if (!is_array($raw) || ($raw['version'] ?? null) !== VP3_HOMESERVER_MODEL_INVENTORY_V1) return $out;
    $out['state'] = 'invalid_response';
    if (($raw['provider'] ?? null) !== 'ollama' || !is_bool($raw['enabled'] ?? null)
        || !is_bool($raw['truncated'] ?? null) || !is_array($raw['models'] ?? null)
        || !array_is_list($raw['models']) || count($raw['models']) > VP3_HOMESERVER_MODEL_INVENTORY_MAX_V1) return $out;
    $state = $raw['discovery_state'] ?? null;
    if (!in_array($state, ['disabled', 'unreachable', 'invalid_response', 'reported'], true)
        || ($raw['enabled'] === false) !== ($state === 'disabled')
        || ($state !== 'reported' && ($raw['models'] !== [] || $raw['truncated']))) return $out;
    $default = $raw['configured_model'] ?? null;
    if ($default !== null && !vp3_homeserver_model_id_v1($default)) return $out;
    $models = [];
    foreach ($raw['models'] as $record) {
        $name = is_array($record) ? ($record['model'] ?? null) : null;
        if (!vp3_homeserver_model_id_v1($name) || isset($models[$name])) return $out;
        $models[$name] = [
            'model' => $name, 'configured_default' => $name === $default,
            'locality' => 'unverified', 'execution_verified' => false, 'routing_eligible' => false,
        ];
    }
    $out['state'] = $state;
    $out['enabled'] = $raw['enabled'];
    $out['configured_model'] = $default;
    $out['configured_model_reported'] = $default !== null && isset($models[$default]);
    $out['truncated'] = $raw['truncated'];
    $out['models'] = array_values($models);
    return $out;
}
