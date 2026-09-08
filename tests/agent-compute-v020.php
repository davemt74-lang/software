<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-compute-v020.php';

function assert_same_v020(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$autoReady = agent_compute_v020_route_plan('auto', true, true);
assert_same_v020(true, $autoReady['try_homeserver'], 'Automatic must try a paired HomeServer.');
assert_same_v020(true, $autoReady['homeserver_cloud_allowed'], 'Automatic allows HomeServer cloud/provider fallback.');
assert_same_v020(true, $autoReady['allow_vp3_fallback'], 'Automatic must allow VP3 fallback.');
assert_same_v020('homeserver', $autoReady['resolved_route'], 'Automatic resolves to HomeServer when ready.');

$autoOffline = agent_compute_v020_route_plan('auto', true, false);
assert_same_v020(true, $autoOffline['try_homeserver'], 'Automatic still attempts a paired HomeServer before VP3 fallback.');
assert_same_v020('vp3_cloud', $autoOffline['resolved_route'], 'Automatic reports VP3 Cloud when HomeServer is not ready.');

$homeOnly = agent_compute_v020_route_plan('homeserver_only', true, true);
assert_same_v020(true, $homeOnly['try_homeserver'], 'HomeServer-only must try HomeServer.');
assert_same_v020(false, $homeOnly['homeserver_cloud_allowed'], 'HomeServer-only must disable HomeServer cloud fallback.');
assert_same_v020(false, $homeOnly['allow_vp3_fallback'], 'HomeServer-only must disable VP3 fallback.');
assert_same_v020(false, $homeOnly['blocked'], 'Ready HomeServer-only route must not be blocked.');

$homeOnlyUnpaired = agent_compute_v020_route_plan('homeserver_only', false, false);
assert_same_v020(false, $homeOnlyUnpaired['try_homeserver'], 'Unpaired HomeServer-only cannot attempt HomeServer.');
assert_same_v020(true, $homeOnlyUnpaired['blocked'], 'Unpaired HomeServer-only must be blocked.');
assert_same_v020('blocked', $homeOnlyUnpaired['resolved_route'], 'Unpaired HomeServer-only must report blocked.');

$vp3 = agent_compute_v020_route_plan('vp3_cloud', true, true);
assert_same_v020(false, $vp3['try_homeserver'], 'VP3 Cloud must bypass HomeServer compute.');
assert_same_v020(true, $vp3['allow_vp3_fallback'], 'VP3 Cloud route must permit VP3 execution.');
assert_same_v020('vp3_cloud', $vp3['resolved_route'], 'VP3 Cloud must resolve directly to VP3.');

$invalid = agent_compute_v020_route_plan('invalid', false, false);
assert_same_v020('auto', $invalid['preference'], 'Invalid preferences must fail safely to Automatic.');

fwrite(STDOUT, "VP3 v0.20 Agent compute route plan tests passed\n");
