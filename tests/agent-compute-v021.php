<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-compute-v020.php';
require dirname(__DIR__) . '/includes/agent-compute-v021.php';

function assert_same_v021(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$autoHome = agent_compute_v021_reason('auto', true, true, true, 'connected');
assert_same_v021('homeserver_preferred', $autoHome['code'], 'Automatic must explain a ready HomeServer preference.');
assert_same_v021('ready', $autoHome['severity'], 'Ready HomeServer route must be healthy.');

$autoUnpaired = agent_compute_v021_reason('auto', false, false, true, 'unpaired');
assert_same_v021('homeserver_not_paired_fallback', $autoUnpaired['code'], 'Automatic must explain unpaired fallback.');
assert_same_v021('warning', $autoUnpaired['severity'], 'Healthy fallback should be a warning, not blocked.');

$autoOffline = agent_compute_v021_reason('auto', true, false, true, 'offline');
assert_same_v021('homeserver_unavailable_fallback', $autoOffline['code'], 'Automatic must explain paired HomeServer fallback.');
assert_same_v021('warning', $autoOffline['severity'], 'VP3 fallback should remain usable.');

$none = agent_compute_v021_reason('auto', true, false, false, 'offline');
assert_same_v021('no_route_ready', $none['code'], 'Automatic must block when neither route is ready.');
assert_same_v021('blocked', $none['severity'], 'No available route must be blocked.');

$homeUnpaired = agent_compute_v021_reason('homeserver_only', false, false, true, 'unpaired');
assert_same_v021('homeserver_required_unpaired', $homeUnpaired['code'], 'HomeServer-only must explain missing pairing.');
assert_same_v021('blocked', $homeUnpaired['severity'], 'HomeServer-only cannot fall through to VP3 Cloud.');

$homeOffline = agent_compute_v021_reason('homeserver_only', true, false, true, 'offline');
assert_same_v021('homeserver_required_unavailable', $homeOffline['code'], 'HomeServer-only must explain unavailable HomeServer.');
assert_same_v021('blocked', $homeOffline['severity'], 'HomeServer-only stays blocked when HomeServer is down.');

$homeReady = agent_compute_v021_reason('homeserver_only', true, true, true, 'connected');
assert_same_v021('homeserver_required_ready', $homeReady['code'], 'HomeServer-only ready state must be explicit.');
assert_same_v021('ready', $homeReady['severity'], 'Ready HomeServer-only route must be healthy.');

$cloudReady = agent_compute_v021_reason('vp3_cloud', true, true, true, 'connected');
assert_same_v021('vp3_cloud_selected', $cloudReady['code'], 'Explicit VP3 Cloud must explain the user selection.');
assert_same_v021('ready', $cloudReady['severity'], 'Ready VP3 Cloud selection must be healthy.');

$cloudDown = agent_compute_v021_reason('vp3_cloud', true, true, false, 'connected');
assert_same_v021('vp3_cloud_unavailable', $cloudDown['code'], 'Explicit VP3 Cloud must report provider unavailability.');
assert_same_v021('blocked', $cloudDown['severity'], 'Unavailable explicit VP3 Cloud must be blocked.');

$safe = agent_compute_v021_safe_inference([
    'available' => true,
    'selected_provider' => 'ollama',
    'model' => 'llama3.2',
    'compute_source' => 'homeserver_local',
    'cloud_fallback_required' => false,
    'bearer_token' => 'must-not-escape',
    'raw_error' => 'must-not-escape',
]);
assert_same_v021(true, $safe['available'], 'Safe inference must preserve readiness.');
assert_same_v021('ollama', $safe['provider'], 'Safe inference must preserve provider identity.');
assert_same_v021('homeserver_local', $safe['compute_source'], 'Safe inference must preserve compute source.');
assert_same_v021(false, array_key_exists('bearer_token', $safe), 'Safe inference must discard credentials.');
assert_same_v021(false, array_key_exists('raw_error', $safe), 'Safe inference must discard raw provider errors.');

fwrite(STDOUT, "VP3 v0.21 compute health reason tests passed\n");
