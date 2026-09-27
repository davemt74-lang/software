<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function v278_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function v278_expect(bool $v,string $m): void { if(!$v)v278_fail($m); }
function v278_same(mixed $a,mixed $e,string $m): void { if($a!==$e)v278_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function v278_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;v278_fail($m.' wrong error='.$e->getMessage());}
  v278_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$pocket='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$valid=[
 'protocol'=>'physical_site_topology.v1','schema_version'=>1,'revision'=>12,'generated_at'=>'2026-09-27T15:20:00+00:00',
 'summary_only'=>true,'cloud_read_only'=>true,'authority_assignment'=>'local_only',
 'sites'=>[[
   'id'=>$home,'label'=>'Home','kind'=>'physical_site','status'=>'active','device_count'=>2,
   'authority_device_id'=>$node,'authority_epoch'=>3,'profiles'=>['node','pocket'],'mobile_device_count'=>1,
 ]],
 'devices'=>[
   ['id'=>$node,'label'=>'Node','site_id'=>$home,'hardware_profile'=>'node','hardware_profile_label'=>'Node','mobility'=>'fixed','trust_state'=>'trusted','roles'=>['site_authority','persistence'],'capabilities'=>['reconciliation','site_authority_eligible']],
   ['id'=>$pocket,'label'=>'Pocket','site_id'=>$home,'hardware_profile'=>'pocket','hardware_profile_label'=>'Pocket','mobility'=>'mobile','trust_state'=>'trusted','roles'=>['identity_continuity','mobile_presence'],'capabilities'=>['location_transition']],
 ],
 'relationships'=>[['subject_id'=>$pocket,'type'=>'travels_with','object_id'=>$node]],
];
$n=tracky_v278_normalize($valid);
v278_same($n['protocol'],'physical_site_topology.v1','protocol');
v278_same($n['revision'],12,'revision');
v278_expect($n['cloud_read_only']===true,'cloud read-only');
v278_same($n['authority_assignment'],'local_only','authority');
v278_same($n['sites'][0]['authority_device_id'],$node,'site authority');

$bad=$valid;$bad['authority_assignment']='cloud';
v278_throws(fn()=>tracky_v278_normalize($bad),'must remain local','cloud authority accepted');
$bad=$valid;$bad['devices'][1]['roles'][]='site_authority';
v278_throws(fn()=>tracky_v278_normalize($bad),'mobile device','mobile authority accepted');
$bad=$valid;$bad['devices'][0]['frame_data']='private';
v278_throws(fn()=>tracky_v278_normalize($bad),'local-only perception data','raw data accepted');
$bad=$valid;$bad['sites'][0]['authority_device_id']=$pocket;
v278_throws(fn()=>tracky_v278_normalize($bad),'not locally eligible','ineligible authority accepted');
$bad=$valid;$bad['devices'][0]['site_id']='22222222-2222-4222-8222-222222222222';
v278_throws(fn()=>tracky_v278_normalize($bad),'unknown site','unknown site accepted');

$cap=tracky_v278_public_capability();
v278_same($cap['version'],'2.78','version');
v278_expect($cap['cloud_read_only']===true,'Cloud mutation authority leaked');
v278_same($cap['authority_assignment'],'local_only','authority capability');
v278_expect($cap['topology_mutation_authority']===false,'topology write authority leaked');

$caps=tracky_cloud_v270_capabilities(['site_topology'=>true,'site_topology_protocol'=>'physical_site_topology.v1']);
v278_expect($caps['site_topology']===true,'topology capability missing');
v278_same($caps['site_topology_protocol'],'physical_site_topology.v1','topology protocol');
$health=tracky_cloud_v270_health(['site_topology'=>'available']);
v278_same($health['site_topology'],'available','topology health');

echo "TRACKY_V278_SITE_TOPOLOGY_UNIT=PASS\n";
