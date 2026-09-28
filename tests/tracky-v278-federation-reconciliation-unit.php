<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fr_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fr_expect(bool $v,string $m): void { if(!$v)fr_fail($m); }
function fr_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fr_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';

$payload=tracky_v278_reconciliation_payload([
  ['site_id'=>$home,'revision'=>9,'fingerprint'=>str_repeat('a',64),'authority_epoch'=>3],
  ['site_id'=>$home,'revision'=>10,'fingerprint'=>str_repeat('b',64),'authority_epoch'=>3],
  ['site_id'=>$office,'revision'=>4,'fingerprint'=>str_repeat('c',64),'authority_epoch'=>1],
]);
fr_same($payload['protocol'],'physical_federation_reconciliation.v1','protocol');
fr_same(count($payload['remote_cursors']),2,'duplicate site cursor must be collapsed');
fr_same($payload['remote_cursors'][0]['revision'],9,'first authoritative cursor must be deterministic');
fr_same($payload['authority_assignment'],'origin_only','Cloud must not assign authority');
fr_same($payload['cloud_role'],'relay_and_mirror_only','Cloud role');
fr_expect($payload['destination_decides_freshness']===true,'destination must decide freshness');

$cap=tracky_v278_reconciliation_public_capability();
fr_same($cap['same_revision_conflicts'],'fail_closed','conflict policy');
fr_expect($cap['cloud_can_mark_destination_current']===false,'Cloud cannot declare destination current');
fr_expect($cap['cloud_can_assign_authority']===false,'Cloud cannot assign authority');
fr_expect(in_array('partition-never-promotes-remote-or-cloud-authority',$cap['boundaries'],true),'partition authority boundary missing');
fr_expect(in_array('stale-data-must-be-labeled',$cap['boundaries'],true),'stale label boundary missing');

$caps=tracky_cloud_v270_capabilities([
  'federation_reconciliation'=>true,
  'federation_reconciliation_protocol'=>'physical_federation_reconciliation.v1',
]);
fr_expect($caps['federation_reconciliation']===true,'reconciliation capability missing');
fr_same($caps['federation_reconciliation_protocol'],'physical_federation_reconciliation.v1','reconciliation protocol missing');

echo "TRACKY_V278_FEDERATION_RECONCILIATION_UNIT=PASS\n";
