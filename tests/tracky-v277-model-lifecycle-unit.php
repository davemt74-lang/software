<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function v277_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function v277_expect(bool $v,string $m): void { if(!$v)v277_fail($m); }
function v277_same(mixed $a,mixed $e,string $m): void { if($a!==$e)v277_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function v277_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;v277_fail($m.' wrong error='.$e->getMessage());}
  v277_fail($m.' did not throw');
}

$v=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v277_lifecycle_vectors.json'),true);
$n=tracky_v277_normalize($v['valid']);
v277_same($n['protocol'],'physical_model_lifecycle.v1','protocol');
v277_same($n['health_state'],'healthy','health');
v277_same($n['active'][0]['model_version'],'2.76','active version');
v277_same($n['canary'][0]['canary_percent'],10.0,'canary percent');
v277_same($n['environment_profile_count'],3,'profile count');
v277_expect($n['cloud_read_only']===true,'cloud read-only');
v277_same($n['activation_authority'],'local_only','activation authority');
v277_same($n['rollback_authority'],'local_only','rollback authority');

$bad=$v['valid'];$bad['activation_authority']='cloud';
v277_throws(fn()=>tracky_v277_normalize($bad),'must remain local','cloud activation authority accepted');

$bad=$v['valid'];$bad['rollback_authority']='cloud';
v277_throws(fn()=>tracky_v277_normalize($bad),'must remain local','cloud rollback authority accepted');

$bad=$v['valid'];$bad['active'][0]['package_checksum']='private';
v277_throws(fn()=>tracky_v277_normalize($bad),'unsupported field','package checksum accepted');

$bad=$v['valid'];$bad['environment_profiles']=[['room'=>'office']];
v277_throws(fn()=>tracky_v277_normalize($bad),'unsupported field','environment profile details accepted');

$bad=$v['valid'];$bad['active'][0]['frame_data']='private';
v277_throws(fn()=>tracky_v277_normalize($bad),'local-only perception data','raw-style lifecycle field accepted');

$cap=tracky_v277_public_capability();
v277_same($cap['version'],'2.77','version');
v277_expect($cap['cloud_read_only']===true,'Cloud write authority leaked');
v277_same($cap['activation_authority'],'local_only','activation authority capability');
v277_same($cap['rollback_authority'],'local_only','rollback authority capability');
v277_expect($cap['promotion_authority']===false,'promotion authority leaked');
v277_expect($cap['candidate_registration_authority']===false,'candidate registration authority leaked');

$caps=tracky_cloud_v270_capabilities(['model_lifecycle'=>true,'model_lifecycle_protocol'=>'physical_model_lifecycle.v1']);
v277_expect($caps['model_lifecycle']===true,'lifecycle capability missing');
v277_same($caps['model_lifecycle_protocol'],'physical_model_lifecycle.v1','lifecycle protocol');
$health=tracky_cloud_v270_health(['model_lifecycle'=>'healthy']);
v277_same($health['model_lifecycle'],'healthy','lifecycle health');

echo "TRACKY_V277_MODEL_LIFECYCLE_UNIT=PASS\n";
