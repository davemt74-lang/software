<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fw_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fw_expect(bool $v,string $m): void { if(!$v)fw_fail($m); }
function fw_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fw_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function fw_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;fw_fail($m.' wrong error='.$e->getMessage());}
  fw_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$valid=[
 'protocol'=>'physical_federated_world.v1','schema_version'=>1,'site_count'=>1,
 'identity_scope'=>'site_local','cross_site_identity_links'=>[],
 'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'local_only',
 'sites'=>[[
   'protocol'=>'physical_federated_world.v1','schema_version'=>1,'site_id'=>$home,
   'authority_device_id'=>$node,'authority_epoch'=>2,'topology_revision'=>9,'revision'=>12,
   'observed_at'=>'2026-09-27T16:30:00+00:00','semantic_only'=>true,'identity_scope'=>'site_local',
   'entities'=>[
     ['local_id'=>'person:dave','ref'=>'ignored','type'=>'person','label'=>'Dave','state'=>'observed','confidence'=>0.98,'observed_at'=>1000],
     ['local_id'=>'room:office','ref'=>'ignored','type'=>'room','label'=>'Office','state'=>'user-confirmed','confidence'=>1.0,'observed_at'=>1000],
   ],
   'relations'=>[[
     'subject_local_id'=>'person:dave','subject_ref'=>'ignored','predicate'=>'located_in',
     'object_local_id'=>'room:office','object_ref'=>'ignored','value'=>[],'confidence'=>0.98,
     'temporal_state'=>'current','source_event_id'=>'event-12','sequence'=>12,'as_of'=>1000
   ]],
   'context'=>['current_room'=>'Office'],
 ]],
];
$n=tracky_v278_world_normalize($valid);
fw_same($n['protocol'],'physical_federated_world.v1','protocol');
fw_same($n['identity_scope'],'site_local','identity scope');
fw_expect($n['cloud_read_only']===true,'cloud read-only');
fw_same($n['sites'][0]['authority_epoch'],2,'authority epoch');
fw_expect(str_starts_with($n['sites'][0]['entities'][0]['ref'],'site:'.$home.'::'),'qualified entity ref');

$bad=$valid;$bad['authority_assignment']='cloud';
fw_throws(fn()=>tracky_v278_world_normalize($bad),'must remain local','cloud authority accepted');

$bad=$valid;$bad['cross_site_identity_links']=[['a'=>'person:dave','b'=>'person:dave']];
fw_throws(fn()=>tracky_v278_world_normalize($bad),'deferred','identity linking accepted');

$bad=$valid;$bad['sites'][0]['entities'][0]['frame_data']='private';
fw_throws(fn()=>tracky_v278_world_normalize($bad),'local-only perception data','raw perception accepted');

$bad=$valid;$bad['sites'][0]['relations'][0]['object_local_id']='object:missing';
fw_throws(fn()=>tracky_v278_world_normalize($bad),'object is not present','missing relation entity accepted');

$cap=tracky_v278_world_public_capability();
fw_same($cap['version'],'2.78','version');
fw_expect($cap['cross_site_identity_linking']===false,'identity-link authority leaked');
fw_expect($cap['cloud_read_only']===true,'Cloud write authority leaked');
fw_expect($cap['world_mutation_authority']===false,'world mutation authority leaked');

$caps=tracky_cloud_v270_capabilities(['federated_world'=>true,'federated_world_protocol'=>'physical_federated_world.v1']);
fw_expect($caps['federated_world']===true,'federated world capability missing');
fw_same($caps['federated_world_protocol'],'physical_federated_world.v1','federated world protocol');
$health=tracky_cloud_v270_health(['federated_world'=>'available']);
fw_same($health['federated_world'],'available','federated world health');

echo "TRACKY_V278_FEDERATED_WORLD_UNIT=PASS\n";
