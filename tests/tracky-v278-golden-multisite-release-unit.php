<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function gm_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function gm_expect(bool $v,string $m): void { if(!$v)gm_fail($m); }
function gm_same(mixed $a,mixed $e,string $m): void { if($a!==$e)gm_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v278_golden_multisite_scenarios.json'),true);
gm_expect(is_array($fixture),'golden fixture must decode');
gm_same($fixture['format']??'','tracky_v278_golden_multisite_scenarios.v1','fixture format');
gm_same($fixture['version']??'','2.78','fixture version');
gm_same(count($fixture['scenarios']??[]),10,'golden scenario count');

$ids=array_column($fixture['scenarios'],'id');
foreach([
  'healthy-two-site-current',
  'transport-partition-stale-query',
  'reconnect-revision-gap-full-snapshot',
  'authority-epoch-change-revalidation',
  'same-revision-fingerprint-conflict',
  'permission-revoked-during-partition',
  'mobile-transition-site-boundary',
  'history-immutable-through-reconciliation',
  'restart-during-reconciliation',
  'three-site-cloud-relay-no-authority',
] as $id) gm_expect(in_array($id,$ids,true),'missing golden scenario '.$id);

foreach([
  'origin-site-authority-only',
  'cloud-remains-relay-and-mirror-only',
  'stale-data-must-be-labeled',
  'partition-never-promotes-remote-or-cloud-authority',
  'same-revision-fingerprint-conflict-fails-closed',
  'revocation-wins-over-cached-permission',
  'history-remains-immutable',
] as $invariant) gm_expect(in_array($invariant,$fixture['invariants'],true),'missing invariant '.$invariant);

$cap=tracky_v278_reconciliation_public_capability();
gm_same($cap['cloud_role'],'relay_and_mirror_only','Cloud role');
gm_same($cap['authority_assignment'],'origin_only','authority assignment');
gm_expect($cap['cloud_can_assign_authority']===false,'Cloud must never assign authority');
gm_expect($cap['cloud_can_mark_destination_current']===false,'Cloud must never mark destination current');

$home=$fixture['sites']['home']['site_id'];
$office=$fixture['sites']['office']['site_id'];
$payload=tracky_v278_reconciliation_payload([
  ['site_id'=>$home,'revision'=>5,'fingerprint'=>str_repeat('a',64),'authority_epoch'=>1],
  ['site_id'=>$office,'revision'=>8,'fingerprint'=>str_repeat('b',64),'authority_epoch'=>2],
]);
gm_same(count($payload['remote_cursors']),2,'three-site relay cursor contract');
gm_same($payload['cloud_role'],'relay_and_mirror_only','relay payload role');
gm_expect($payload['destination_decides_freshness']===true,'destination must decide freshness');

echo "TRACKY_V278_GOLDEN_MULTISITE_RELEASE_UNIT=PASS\n";
