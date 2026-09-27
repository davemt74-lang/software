<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function v277_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v277_expect(bool $value,string $message): void { if(!$value)v277_fail($message); }
function v277_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v277_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}
function v277_throws(callable $fn,string $contains,string $message): void {
    try{$fn();}catch(Throwable $e){
        if($contains===''||str_contains($e->getMessage(),$contains))return;
        v277_fail($message.' wrong error='.$e->getMessage());
    }
    v277_fail($message.' did not throw');
}

$vectors=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v277_lifecycle_vectors.json'),true);
$valid=$vectors['valid'];
$normalized=tracky_v277_normalize($valid);
v277_same($normalized['protocol'],'physical_model_lifecycle.v1','protocol');
v277_same($normalized['active_models'][0]['model_version'],'2','active model');
v277_same($normalized['canary_models'][0]['canary_percent'],10.0,'canary percentage');
v277_same($normalized['environment_profile_count'],8,'environment profile count');
v277_same($normalized['last_decision']['type'],'activate','last decision');
v277_expect($normalized['summary_only']===true,'summary-only');
v277_same($normalized['activation_authority'],'local_tracky','activation authority');
v277_expect($normalized['environment_profiles_exposed']===false,'environment details exposed');
v277_expect($normalized['decision_history_exposed']===false,'decision history exposed');
v277_expect($normalized['scenario_details_exposed']===false,'scenario details exposed');
tracky_cloud_v270_assert_governed_value($normalized);

$badAuthority=$valid;$badAuthority['activation_authority']='cloud';
v277_throws(fn()=>tracky_v277_normalize($badAuthority),'must remain local','Cloud activation authority accepted');

$profiles=$valid;$profiles['environment_profiles_exposed']=true;
v277_throws(fn()=>tracky_v277_normalize($profiles),'local rollout details','environment profile exposure accepted');

$history=$valid;$history['decision_history_exposed']=true;
v277_throws(fn()=>tracky_v277_normalize($history),'local rollout details','decision history exposure accepted');

$scenarios=$valid;$scenarios['scenario_details_exposed']=true;
v277_throws(fn()=>tracky_v277_normalize($scenarios),'local rollout details','scenario exposure accepted');

$unknown=$valid;$unknown['promote_model']='detector@3';
v277_throws(fn()=>tracky_v277_normalize($unknown),'unsupported field','write-like field accepted');

$badModel=$valid;$badModel['canary_models'][0]['package_checksum']='private';
v277_throws(fn()=>tracky_v277_normalize($badModel),'unsupported field','package checksum detail accepted');

$cap=tracky_v277_public_capability();
v277_same($cap['version'],'2.77','capability version');
v277_expect($cap['cloud_read_only']===true,'Cloud must be read only');
v277_expect($cap['promotion_authority']===false,'Cloud promotion authority leaked');
v277_expect($cap['rollback_authority']===false,'Cloud rollback authority leaked');
v277_same($cap['activation_authority'],'local_tracky','local activation authority');

$caps=tracky_cloud_v270_capabilities([
  'model_lifecycle'=>true,
  'model_lifecycle_protocol'=>'physical_model_lifecycle.v1',
]);
v277_expect($caps['model_lifecycle']===true,'lifecycle capability missing');
v277_same($caps['model_lifecycle_protocol'],'physical_model_lifecycle.v1','lifecycle protocol');

$health=tracky_cloud_v270_health(['model_lifecycle'=>'canary']);
v277_same($health['model_lifecycle'],'canary','lifecycle health missing');

echo "TRACKY_V277_MODEL_LIFECYCLE_UNIT=PASS\n";
