<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fs_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fs_expect(bool $v,string $m): void { if(!$v)fs_fail($m); }
function fs_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fs_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function fs_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;fs_fail($m.' wrong error='.$e->getMessage());}
  fs_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$cabin='33333333-3333-4333-8333-333333333333';

$req=[
 'protocol'=>'physical_federation_sync.v1','schema_version'=>1,'available'=>true,
 'local_site_id'=>$home,'authority_assignment'=>'local_only','max_envelopes'=>24,
 'received_cursors'=>[
   ['site_id'=>$office,'revision'=>9,'fingerprint'=>str_repeat('a',64),'authority_epoch'=>4],
 ],
];
$n=tracky_v278_sync_normalize($req);
fs_same($n['protocol'],'physical_federation_sync.v1','protocol');
fs_same($n['local_site_id'],$home,'local site');
fs_same($n['max_envelopes'],24,'max envelopes');
fs_same($n['received_cursors'][0]['revision'],9,'cursor revision');

$topology=[
 'sites'=>[['id'=>$home],['id'=>$office],['id'=>$cabin]],
 'relationships'=>[
   ['subject_id'=>$home,'type'=>'peers_with','object_id'=>$office],
   ['subject_id'=>$home,'type'=>'bridges_to','object_id'=>$cabin],
 ],
];
fs_expect(tracky_v278_sync_peer_allowed($topology,$home,$office),'peer relation');
fs_expect(tracky_v278_sync_peer_allowed($topology,$office,$home),'peer relation symmetric');
fs_expect(tracky_v278_sync_peer_allowed($topology,$home,$cabin),'bridge relation');
fs_expect(!tracky_v278_sync_peer_allowed($topology,$office,$cabin),'unapproved peer accepted');

$bad=$req;$bad['authority_assignment']='cloud';
fs_throws(fn()=>tracky_v278_sync_normalize($bad),'must remain local','cloud authority accepted');

$bad=$req;$bad['received_cursors'][]=$bad['received_cursors'][0];
fs_throws(fn()=>tracky_v278_sync_normalize($bad),'duplicated','duplicate cursor accepted');

$off=[
 'protocol'=>'physical_federation_sync.v1','schema_version'=>1,'available'=>false,
 'local_site_id'=>'','authority_assignment'=>'local_only','received_cursors'=>[],
];
$disabled=tracky_v278_sync_normalize($off);
fs_expect($disabled['available']===false,'disabled federation request');

$cap=tracky_v278_sync_public_capability();
fs_same($cap['version'],'2.78','version');
fs_same($cap['cloud_role'],'relay_only','cloud role');
fs_expect($cap['world_mutation_authority']===false,'cloud mutation authority leaked');
fs_same($cap['same_revision_conflicts'],'quarantine','conflict policy');
fs_same($cap['topology_ahead'],'hold','topology catch-up policy');
fs_expect($cap['cross_site_identity_linking']===false,'identity linking leaked');

$caps=tracky_cloud_v270_capabilities(['federation_sync'=>true,'federation_sync_protocol'=>'physical_federation_sync.v1']);
fs_expect($caps['federation_sync']===true,'federation capability missing');
fs_same($caps['federation_sync_protocol'],'physical_federation_sync.v1','federation protocol');
$health=tracky_cloud_v270_health(['federation_sync'=>'available']);
fs_same($health['federation_sync'],'available','federation health');

echo "TRACKY_V278_FEDERATION_SYNC_UNIT=PASS\n";
