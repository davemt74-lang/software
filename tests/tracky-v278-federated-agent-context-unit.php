<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fac_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fac_expect(bool $v,string $m): void { if(!$v)fac_fail($m); }
function fac_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fac_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function fac_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;fac_fail($m.' wrong error='.$e->getMessage());}
  fac_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$person='44444444-4444-4444-8444-444444444444';

$context=[
 'protocol'=>'physical_federated_agent_context.v1','schema_version'=>1,
 'revision'=>3,'fingerprint'=>str_repeat('a',64),'generated_at'=>2000000,
 'agent_state'=>'current','physical_state'=>'present','local_site_id'=>$home,
 'current_site'=>[
   'site_id'=>$home,'label'=>'Home','member_ref'=>'site:'.$home.'::person%3Adave',
   'location_ref'=>'site:'.$home.'::room%3Akitchen','confidence'=>.97,
   'observed_at'=>1990000,'why'=>'current_relation'
 ],
 'location_conflicts'=>[],
 'authority'=>['site_id'=>$home,'device_id'=>$node,'epoch'=>4,'basis'=>'current_site'],
 'focus_identity'=>[
   'canonical_identity_id'=>$person,'entity_type'=>'person','aliases'=>['Dave'],
   'member_site_ids'=>[$home,$office]
 ],
 'active_mobile_transition'=>null,
 'changed_elsewhere'=>[[
   'site_id'=>$office,'label'=>'Office','from_revision'=>7,'to_revision'=>8,
   'freshness'=>'current','sync_status'=>'current','recent_changes'=>['Desk changed']
 ]],
 'sites'=>[
   ['site_id'=>$home,'label'=>'Home','status'=>'active','authority_device_id'=>$node,
    'authority_epoch'=>4,'world_revision'=>5,'freshness'=>'current','sync_status'=>'local',
    'recent_changes'=>['Door opened']],
   ['site_id'=>$office,'label'=>'Office','status'=>'active',
    'authority_device_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'authority_epoch'=>2,'world_revision'=>8,'freshness'=>'current','sync_status'=>'current',
    'recent_changes'=>['Desk changed']]
 ],
 'site_revisions'=>[$home=>5,$office=>8],
 'explainability'=>[
   'location_candidate_count'=>1,'current_location_selected'=>true,'conflict_count'=>0,
   'reconciliation_state'=>'current','no_location_invention'=>false,
   'focus_selection'=>'single_active_person','mobile_transition_binding'=>'none'
 ],
 'semantic_only'=>true,'summary_only'=>true,'authority_assignment'=>'local_only',
 'cloud_read_only'=>true,'context_mutation_authority'=>false,'site_authority_mutation'=>false
];

$n=tracky_v278_agent_context_normalize($context);
fac_same($n['protocol'],'physical_federated_agent_context.v1','protocol');
fac_same($n['agent_state'],'current','agent state');
fac_same($n['physical_state'],'present','physical state');
fac_same($n['local_site_id'],$home,'local site');
fac_same($n['authority']['device_id'],$node,'authority device');
fac_same($n['focus_identity']['canonical_identity_id'],$person,'focus identity');
fac_expect(strlen($n['semantic_hash'])===64,'semantic hash missing');
fac_expect($n['cloud_read_only']===true,'Cloud read-only lost');
fac_expect($n['context_mutation_authority']===false,'context mutation authority leaked');

$bad=$context;$bad['agent_state']='invented';
fac_throws(fn()=>tracky_v278_agent_context_normalize($bad),'state is unsupported','invalid agent state accepted');

$bad=$context;$bad['physical_state']='teleported';
fac_throws(fn()=>tracky_v278_agent_context_normalize($bad),'physical state is unsupported','invalid physical state accepted');

$bad=$context;$bad['fingerprint']='bad';
fac_throws(fn()=>tracky_v278_agent_context_normalize($bad),'fingerprint must be SHA-256','bad fingerprint accepted');

$bad=$context;$bad['authority_assignment']='cloud';
fac_throws(fn()=>tracky_v278_agent_context_normalize($bad),'cannot change physical authority','Cloud authority assignment accepted');

$bad=$context;$bad['context_mutation_authority']=true;
fac_throws(fn()=>tracky_v278_agent_context_normalize($bad),'cannot receive federated Agent context mutation authority','Cloud context mutation accepted');

$bad=$context;$bad['current_site']=null;$bad['authority']=['site_id'=>'','device_id'=>'','epoch'=>0,'basis'=>''];$bad['physical_state']='unknown';
$unknown=tracky_v278_agent_context_normalize($bad);
fac_same($unknown['physical_state'],'unknown','unknown location handling');

$bad=$context;$bad['active_mobile_transition']=[
 'transition_id'=>'trip-1','subject_kind'=>'explicit_continuity_subject','subject_id'=>$person,
 'source_site_id'=>$home,'destination_site_id'=>$office,'state'=>'temporary_context','confidence'=>.8,
 'temporary_context'=>['id'=>'hotel','label'=>'Hotel','confidence'=>.8,'durable_site'=>true,'site_authority'=>true],
 'offline_since'=>null
];
$temp=tracky_v278_agent_context_normalize($bad);
fac_expect($temp['active_mobile_transition']['temporary_context']['durable_site']===false,'temporary context became durable');
fac_expect($temp['active_mobile_transition']['temporary_context']['site_authority']===false,'temporary context gained authority');

$cap=tracky_v278_agent_context_public_capability();
fac_same($cap['cloud_role'],'mirror_only','Cloud role');
fac_expect($cap['context_mutation_authority']===false,'Cloud context mutation leaked');
fac_expect($cap['site_authority_mutation']===false,'Cloud site authority leaked');
fac_expect($cap['no_location_invention']===true,'no-location-invention guard missing');

$caps=tracky_cloud_v270_capabilities([
 'federated_agent_context'=>true,
 'federated_agent_context_protocol'=>'physical_federated_agent_context.v1'
]);
fac_expect($caps['federated_agent_context']===true,'Agent context capability missing');
fac_same($caps['federated_agent_context_protocol'],'physical_federated_agent_context.v1','Agent context protocol');

$health=tracky_cloud_v270_health(['federated_agent_context'=>'stale']);
fac_same($health['federated_agent_context'],'stale','Agent context health');

echo "TRACKY_V278_FEDERATED_AGENT_CONTEXT_UNIT=PASS\n";
