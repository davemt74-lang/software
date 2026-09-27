<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function mt_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function mt_expect(bool $v,string $m): void { if(!$v)mt_fail($m); }
function mt_same(mixed $a,mixed $e,string $m): void { if($a!==$e)mt_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function mt_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;mt_fail($m.' wrong error='.$e->getMessage());}
  mt_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$pocket='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

$projection=[
 'protocol'=>'physical_mobile_transition.v1','schema_version'=>1,
 'identity_linking'=>false,'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,
 'authority_assignment'=>'source_site','person_object_identity_linking'=>false,
 'temporary_context_site_authority'=>false,'origin_scope'=>'local_source_site_only',
 'transitions'=>[[
   'transition_id'=>'trip-1','subject_kind'=>'mobile_device','subject_id'=>$pocket,
   'subject_scope'=>'stable_mobile_device','source_site_id'=>$home,'destination_site_id'=>$office,
   'state'=>'in_transit','previous_state'=>'departing','resume_state'=>'',
   'state_reason'=>'mobile_motion','confidence'=>0.88,'destination_confidence'=>0.60,
   'temporary_context'=>null,'evidence'=>[['type'=>'mobile_motion','confidence'=>0.9]],
   'revision'=>4,'identity_linking'=>false,'authority_scope'=>'source_site','origin_role'=>'local_authority',
   'started_at'=>1000,'state_changed_at'=>1200,'updated_at'=>1300,
   'arrived_at'=>null,'canceled_at'=>null,'offline_since'=>null,
   'source_authority_device_id'=>$node,'source_authority_epoch'=>3,
 ]],
];

$n=tracky_v278_mobile_normalize($projection);
mt_same($n['protocol'],'physical_mobile_transition.v1','protocol');
mt_same($n['authority_assignment'],'source_site','authority scope');
mt_same($n['transitions'][0]['subject_scope'],'stable_mobile_device','mobile subject scope');
mt_same($n['transitions'][0]['state'],'in_transit','state');
mt_same($n['transitions'][0]['source_authority_epoch'],3,'authority epoch');
mt_expect($n['person_object_identity_linking']===false,'identity linking leaked');

$bad=$projection;$bad['transitions'][0]['subject_kind']='person';
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'subject kind is unsupported','person inference accepted');

$bad=$projection;$bad['transitions'][0]['identity_linking']=true;
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'Section 5','identity linking accepted');

$bad=$projection;$bad['transitions'][0]['temporary_context']=[
 'id'=>'hotel-room-1','label'=>'Hotel','durable_site'=>true,'site_authority'=>false
];
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'cannot become a site authority or durable site','temporary context promoted');

$bad=$projection;$bad['transitions'][0]['destination_site_id']=$home;
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'must differ from source','same-site transition accepted');

$bad=$projection;$bad['transitions'][0]['evidence'][0]['frame_data']='private';
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'local-only perception data','raw perception accepted');

$bad=$projection;$bad['transitions'][0]['origin_role']='cloud_mirror';
mt_throws(fn()=>tracky_v278_mobile_normalize($bad),'source-site authoritative','mirror re-origin accepted');

$cap=tracky_v278_mobile_public_capability();
mt_same($cap['version'],'2.78','version');
mt_same($cap['cloud_role'],'mirror_relay_only','cloud role');
mt_expect($cap['world_mutation_authority']===false,'Cloud mutation authority leaked');
mt_expect($cap['person_object_identity_linking']===false,'identity linking leaked');
mt_expect($cap['temporary_context_site_authority']===false,'temporary site authority leaked');

$caps=tracky_cloud_v270_capabilities(['mobile_transitions'=>true,'mobile_transition_protocol'=>'physical_mobile_transition.v1']);
mt_expect($caps['mobile_transitions']===true,'mobile transition capability missing');
mt_same($caps['mobile_transition_protocol'],'physical_mobile_transition.v1','mobile transition protocol');
$health=tracky_cloud_v270_health(['mobile_transitions'=>'active']);
mt_same($health['mobile_transitions'],'active','mobile transition health');

echo "TRACKY_V278_MOBILE_TRANSITION_UNIT=PASS\n";
