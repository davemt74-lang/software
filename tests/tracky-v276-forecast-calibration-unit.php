<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function v276_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v276_expect(bool $value,string $message): void { if(!$value)v276_fail($message); }
function v276_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v276_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}
function v276_throws(callable $fn,string $contains,string $message): void {
    try{$fn();}catch(Throwable $e){
        if($contains===''||str_contains($e->getMessage(),$contains))return;
        v276_fail($message.' wrong error='.$e->getMessage());
    }
    v276_fail($message.' did not throw');
}

$vectors=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v276_calibration_vectors.json'),true);
$valid=$vectors['valid'];
$normalized=tracky_v276_normalize($valid);
v276_same($normalized['protocol'],'forecast_calibration.v1','protocol');
v276_same($normalized['predictions'],48,'prediction count');
v276_same($normalized['settlements'],32,'settlement count');
v276_same($normalized['profiles'][0]['channel'],'active','active channel');
v276_same($normalized['profiles'][0]['mean_original_confidence'],0.82,'original confidence');
v276_same($normalized['profiles'][0]['brier_score'],0.18,'Brier score');
v276_same($normalized['profiles'][0]['expected_calibration_error'],0.07,'calibration error');
v276_expect($normalized['summary_only']===true,'summary-only boundary');
v276_expect($normalized['prediction_records_exposed']===false,'prediction record boundary');
v276_expect($normalized['settlement_records_exposed']===false,'settlement record boundary');
tracky_cloud_v270_assert_governed_value($normalized);

$bucket=$valid;
$bucket['profiles'][0]['buckets']=[];
v276_throws(fn()=>tracky_v276_normalize($bucket),'does not accept confidence buckets','confidence buckets accepted');

$records=$valid;$records['prediction_records_exposed']=true;
v276_throws(fn()=>tracky_v276_normalize($records),'prediction or settlement records','prediction records accepted');

$settlements=$valid;$settlements['settlement_records_exposed']=true;
v276_throws(fn()=>tracky_v276_normalize($settlements),'prediction or settlement records','settlement records accepted');

$raw=$valid;$raw['profiles'][0]['mean_raw_confidence']=0.9;
v276_throws(fn()=>tracky_v276_normalize($raw),'local-only perception data','raw-style wire key accepted');

$unknown=$valid;$unknown['training_data']=['private'];
v276_throws(fn()=>tracky_v276_normalize($unknown),'unsupported field','unknown training field accepted');

$cap=tracky_v276_public_capability();
v276_same($cap['version'],'2.76','capability version');
v276_expect($cap['cloud_read_only']===true,'Cloud must be read only');
v276_expect($cap['training_authority']===false,'Cloud training authority leaked');
v276_expect($cap['settlement_authority']===false,'Cloud settlement authority leaked');
v276_expect($cap['prediction_authority']===false,'Cloud prediction authority leaked');

$caps=tracky_cloud_v270_capabilities([
  'forecast_calibration'=>true,
  'forecast_calibration_protocol'=>'forecast_calibration.v1',
]);
v276_expect($caps['forecast_calibration']===true,'calibration capability missing');
v276_same($caps['forecast_calibration_protocol'],'forecast_calibration.v1','calibration protocol capability');

$health=tracky_cloud_v270_health(['forecast_calibration'=>'available']);
v276_same($health['forecast_calibration'],'available','calibration health missing');

echo "TRACKY_V276_FORECAST_CALIBRATION_UNIT=PASS\n";
