<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function sv_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function sv_expect(bool $v,string $m): void { if(!$v)sv_fail($m); }
function sv_same(mixed $a,mixed $e,string $m): void { if($a!==$e)sv_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$cabin='33333333-3333-4333-8333-333333333333';
$input=[
 'protocol'=>'physical_federation_sync_visibility.v1','version'=>'2.80','schema_version'=>1,
 'generated_at'=>1759061100000,'local_site_id'=>$home,'overall_state'=>'partitioned',
 'sites'=>[
  ['site_id'=>$home,'label'=>'Home','is_local'=>true,'status'=>'current','fresh'=>true,'reconciliation_required'=>false,
   'local_cursor'=>['revision'=>12,'fingerprint'=>'','authority_epoch'=>4],'remote_cursor'=>['revision'=>12,'fingerprint'=>'','authority_epoch'=>4],
   'revision_gap'=>0,'authority_epoch_mismatch'=>false,'fingerprint_conflict'=>false,'catch_up'=>['applied_revision'=>12,'target_revision'=>12,'remaining_revisions'=>0,'progress'=>1],
   'message'=>'Local authoritative site is current by local ownership.','remote_authority_promotion'=>false],
  ['site_id'=>$office,'label'=>'Office','is_local'=>false,'status'=>'reconciling','fresh'=>false,'reconciliation_required'=>true,
   'stale_since'=>1759060800000,'stale_age_ms'=>300000,'reconciling_since'=>1759060920000,'last_contact_at'=>1759061040000,
   'retry_count'=>2,'next_retry_at'=>1759061160000,'last_error'=>'reconciliation_incomplete',
   'local_cursor'=>['revision'=>15,'fingerprint'=>'abc','authority_epoch'=>2],'remote_cursor'=>['revision'=>18,'fingerprint'=>'def','authority_epoch'=>3],
   'revision_gap'=>3,'authority_epoch_mismatch'=>true,'fingerprint_conflict'=>false,'catch_up'=>['applied_revision'=>15,'target_revision'=>18,'remaining_revisions'=>3,'progress'=>15/18],
   'conflict_code'=>'authority_epoch_mismatch','message'=>'Reconciling 3 revisions from the origin site.','remote_authority_promotion'=>false],
  ['site_id'=>$cabin,'label'=>'Cabin','is_local'=>false,'status'=>'partitioned','fresh'=>false,'reconciliation_required'=>true,
   'local_cursor'=>['revision'=>7,'fingerprint'=>'cab','authority_epoch'=>1],'remote_cursor'=>['revision'=>7,'fingerprint'=>'cab','authority_epoch'=>1],
   'revision_gap'=>0,'catch_up'=>['applied_revision'=>7,'target_revision'=>7,'remaining_revisions'=>0,'progress'=>1],
   'message'=>'Partitioned; remote data remains labeled stale until authoritative reconciliation completes.','remote_authority_promotion'=>false],
 ],
 'reconciliation_runs'=>[
  ['reconciliation_id'=>'r1','site_id'=>$office,'site_label'=>'Office','status'=>'running','request_mode'=>'full_snapshot',
   'reason'=>'revision_gap','local_revision'=>15,'remote_revision'=>18,'applied_revision'=>0,'authority_epoch'=>3,
   'started_at'=>1759060920000,'completed_at'=>0,'fingerprint_conflict'=>false,'authority_epoch_changed'=>true,'immutable'=>true]
 ],
 'transition_sync_hints'=>[],
 'agent_context'=>['state'=>'partitioned','alerts'=>[
  ['site_id'=>$office,'label'=>'Office','status'=>'reconciling','severity'=>'degraded','message'=>'Office is reconciling','stale_age_ms'=>300000,'revision_gap'=>3,'retry_count'=>2,'last_error'=>'reconciliation_incomplete']
 ],'summary'=>'Office is reconciling; Cabin is partitioned','no_remote_authority_promotion'=>true,'remote_freshness_requires_origin_confirmation'=>true],
 'read_only'=>true,'semantic_only'=>true,'authority_assignment'=>'origin_only','cloud_role'=>'relay_and_mirror_only',
 'cloud_can_mark_destination_current'=>false,'summary_only'=>true,'cloud_read_only'=>true,'authority_mutation'=>false,
];

$n=tracky_v280_syncv_normalize($input);
sv_same($n['protocol'],'physical_federation_sync_visibility.v1','protocol');
sv_same($n['local_site_id'],$home,'local site');
sv_same(count($n['sites']),3,'site count');
$officeRow=array_values(array_filter($n['sites'],static fn($r)=>$r['site_id']===$office))[0];
sv_same($officeRow['status'],'reconciling','office state');
sv_same($officeRow['revision_gap'],3,'revision gap');
sv_expect($officeRow['authority_epoch_mismatch']===true,'epoch mismatch');
sv_expect($officeRow['fresh']===false,'reconciling peer cannot be fresh');
sv_expect($officeRow['remote_authority_promotion']===false,'remote authority promotion leaked');
sv_same($n['reconciliation_runs'][0]['immutable'],true,'history immutability');

$dashboard=tracky_v280_syncv_annotate_dashboard([
 'selected_site'=>['site_id'=>$office],'people'=>[['label'=>'Dave']],'rooms'=>[],'objects'=>[],'world_devices'=>[],'agent_context'=>[]
],$n);
sv_same($dashboard['federation_freshness']['status'],'reconciling','dashboard freshness');
sv_expect($dashboard['people'][0]['federation_freshness']['fresh']===false,'entity freshness');
sv_expect($dashboard['federation_freshness']['cloud_decision']===false,'Cloud freshness decision leaked');
sv_expect($dashboard['agent_context']['no_remote_authority_promotion']===true,'Agent authority boundary');

$bad=$input;$bad['cloud_can_mark_destination_current']=true;
try{tracky_v280_syncv_normalize($bad);sv_fail('Cloud current authority was accepted');}
catch(Throwable $e){sv_expect(str_contains($e->getMessage(),'cannot mark a destination current'),'wrong current-authority error');}

$bad=$input;$bad['authority_assignment']='cloud';
try{tracky_v280_syncv_normalize($bad);sv_fail('Cloud authority assignment was accepted');}
catch(Throwable $e){sv_expect(str_contains($e->getMessage(),'origin-site authority'),'wrong authority error');}

$cap=tracky_v280_syncv_public_capability();
sv_expect($cap['retry_visibility']===true,'retry visibility');
sv_expect($cap['physical_world_freshness_annotations']===true,'world annotations');
sv_expect($cap['read_only']===true,'read-only capability');
sv_expect($cap['authority_mutation']===false,'authority mutation');
sv_expect($cap['cloud_can_mark_destination_current']===false,'Cloud current capability');

echo "TRACKY_V280_SYNC_VISIBILITY_UNIT=PASS\n";
