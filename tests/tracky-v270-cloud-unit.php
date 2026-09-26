<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fail_test(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function expect_true(bool $value,string $message): void { if(!$value)fail_test($message); }
function expect_throw(callable $fn,string $message): void {
    try{$fn();}catch(Throwable $e){return;}
    fail_test($message);
}

expect_true(tracky_cloud_v270_site_id('home-1')==='home-1','site id normalization failed');
expect_throw(fn()=>tracky_cloud_v270_site_id('../etc'),'unsafe site id accepted');
expect_true(tracky_cloud_v270_event_id('event-0001')==='event-0001','event id normalization failed');
expect_true(tracky_cloud_v270_event_type('room.entered')==='room.entered','event type failed');
expect_throw(fn()=>tracky_cloud_v270_event_type('medical.diagnosis'),'unsupported event namespace accepted');
expect_true(tracky_cloud_v270_privacy_class('cloud_derived')==='cloud_derived','privacy class failed');
expect_throw(fn()=>tracky_cloud_v270_privacy_class('raw_visual'),'raw privacy class accepted');
expect_throw(fn()=>tracky_cloud_v270_assert_governed_value(['frame'=>'base64']),'raw frame key accepted');
expect_throw(fn()=>tracky_cloud_v270_assert_governed_value(['nested'=>['face_embedding'=>'x']]),'embedding key accepted');
expect_true(tracky_cloud_v270_confidence(1.8)===1.0,'confidence upper clamp failed');
expect_true(tracky_cloud_v270_confidence(-1)===0.0,'confidence lower clamp failed');

$context=tracky_cloud_v270_context([
    'current_room'=>'Office',
    'people_present'=>['Dave'],
    'recent_changes'=>['Door closed'],
    'environment_status'=>'normal',
    'confidence'=>0.98,
]);
expect_true($context['current_room']==='Office','context room missing');
expect_true($context['people_present']===['Dave'],'context people missing');

$sim=tracky_cloud_v270_simulator_payload(7,'sim-home');
expect_true($sim['protocol']===VP3_TRACKY_PROTOCOL_V270,'sim protocol mismatch');
expect_true($sim['events'][0]['sequence']===7,'sim sequence mismatch');
expect_true($sim['world_state'][0]['predicate']==='located_in','sim relation mismatch');
tracky_cloud_v270_assert_governed_value($sim);

echo "TRACKY_V270_CLOUD_UNIT=PASS\n";
