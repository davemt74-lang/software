<?php
declare(strict_types=1);

const VP3_TRACKY_PROTOCOL_V270='physical_context.v1';
require __DIR__.'/../includes/tracky-join-v273.php';

function v273_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v273_expect(bool $value,string $message): void { if(!$value)v273_fail($message); }
function v273_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v273_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}

v273_expect(tracky_v273_query_requests_refresh('Check the office again.'),'explicit check intent missing');
v273_expect(tracky_v273_query_requests_refresh('Look again for my glasses.'),'look-again intent missing');
v273_expect(tracky_v273_query_requests_refresh('Where are my keys right now?'),'right-now intent missing');
v273_expect(!tracky_v273_query_requests_refresh('Where were my keys yesterday?'),'historical query should not force a camera check');

v273_same(tracky_v273_query_request_type('Where are my glasses?','where'),'find_entity','where request type');
v273_same(tracky_v273_query_request_type('Who is in the office?','present'),'check_room','presence request type');
v273_same(tracky_v273_query_request_type('Why do you think that?','why'),'re_evaluate','why request type');
v273_same(tracky_v273_query_request_type('Check again','current'),'refresh_current_view','current request type');

$completed=tracky_v273_refresh_note([
    'attempted'=>true,'status'=>'completed','cloud_sync_error'=>''
]);
v273_expect(str_contains($completed,'fresh governed physical context'),'completed refresh wording missing');

$reconciling=tracky_v273_refresh_note([
    'attempted'=>true,'status'=>'unable','reason'=>'homeserver_reconciliation_pending'
]);
v273_expect(str_contains($reconciling,'v2.4 continuity reconciliation'),'reconciliation authority wording missing');

$provider=tracky_v273_refresh_note([
    'attempted'=>true,'status'=>'unable','reason'=>'provider_unavailable'
]);
v273_expect(str_contains($provider,'no local Tracky perception provider'),'provider unavailable honesty missing');

$offline=tracky_v273_refresh_note([
    'attempted'=>true,'status'=>'unable','reason'=>'homeserver_offline'
]);
v273_expect(str_contains($offline,'last synchronized physical state'),'offline stale-state wording missing');

$privacy=tracky_v273_refresh_note([
    'attempted'=>true,'status'=>'denied','reason'=>'privacy_engaged'
]);
v273_expect(str_contains($privacy,'privacy boundary'),'privacy denial wording missing');

echo "TRACKY_V273_CONTRACT_JOIN_UNIT=PASS\n";
