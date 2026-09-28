<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fah_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fah_expect(bool $v,string $m): void { if(!$v)fah_fail($m); }
function fah_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fah_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$device='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$input=[
 'protocol'=>'physical_federation_agent_health.v1','version'=>'2.80','schema_version'=>1,
 'generated_at'=>1759062000000,'local_site_id'=>$home,'overall_state'=>'recovering',
 'relay'=>['state'=>'connected','connected'=>true,'paired'=>true,'transport'=>'vp3_https'],
 'relay_health'=>['component'=>'vp3_cloud_relay','state'=>'current','severity'=>'info','recovery_complete'=>true,'message'=>'connected'],
 'sites'=>[
  ['site_id'=>$home,'label'=>'Home','state'=>'current','severity'=>'info','fresh'=>true,'reconciliation_required'=>false,
   'authority'=>['status'=>'current','device_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','epoch'=>4],
   'trust'=>['semantic_state'=>'current','agent_use'=>'current','physical_claims'=>'current','reason'=>'authoritative_reconciliation_current'],
   'agent_visible'=>true,'recovery_complete'=>true,'message'=>'current'],
  ['site_id'=>$office,'label'=>'Office','state'=>'recovering','previous_state'=>'partitioned','severity'=>'warning',
   'cause'=>'federation_reconciling','fresh'=>false,'reconciliation_required'=>true,'revision_gap'=>2,'stale_age_ms'=>120000,
   'authority'=>['status'=>'current','device_id'=>$device,'epoch'=>2],'authority_device_runtime'=>'online',
   'trust'=>['semantic_state'=>'stale','agent_use'=>'qualified_stale','physical_claims'=>'do_not_claim_current','reason'=>'authoritative_reconciliation_not_current'],
   'agent_visible'=>true,'recovery_complete'=>false,'message'=>'not complete'],
 ],
 'history'=>[
  ['event_id'=>'federation-health:office:recovering:1','event_type'=>'tracky.federation.site.recovering','summary'=>'Office recovering','importance'=>0.75,'occurred_at'=>'2026-09-28T13:00:00+00:00',
   'payload'=>['site_id'=>$office,'label'=>'Office','state'=>'recovering','previous_state'=>'partitioned','severity'=>'warning','cause'=>'federation_reconciling','recovery_complete'=>false]]
 ],
 'agent_context'=>[
  'overall_state'=>'recovering',
  'sites'=>[['site_id'=>$office,'label'=>'Office','state'=>'recovering','severity'=>'warning','cause'=>'federation_reconciling','fresh'=>false,'recovery_complete'=>false,
    'trust'=>['semantic_state'=>'stale','agent_use'=>'qualified_stale','physical_claims'=>'do_not_claim_current','reason'=>'authoritative_reconciliation_not_current']]],
  'active_issues'=>[['site_id'=>$office,'label'=>'Office','state'=>'recovering','severity'=>'warning','cause'=>'federation_reconciling','message'=>'not complete',
    'trust'=>['semantic_state'=>'stale','agent_use'=>'qualified_stale','physical_claims'=>'do_not_claim_current','reason'=>'authoritative_reconciliation_not_current']]],
  'summary'=>'Office is recovering','recovery_requires_authoritative_reconciliation'=>true,'connectivity_returned_is_not_recovery'=>true
 ],
 'summary_only'=>true,'cloud_read_only'=>true,'authority_mutation'=>false,
];

$n=tracky_v280_fah_normalize($input);
fah_same($n['protocol'],'physical_federation_agent_health.v1','protocol');
fah_same($n['local_site_id'],$home,'local site');
fah_same($n['overall_state'],'recovering','overall state');
fah_same(count($n['sites']),2,'site count');
$officeRow=array_values(array_filter($n['sites'],static fn($r)=>$r['site_id']===$office))[0];
fah_same($officeRow['state'],'recovering','office state');
fah_expect($officeRow['fresh']===false,'recovering site freshness');
fah_expect($officeRow['recovery_complete']===false,'recovering site was marked recovered');
fah_same($officeRow['trust']['physical_claims'],'do_not_claim_current','physical claim gate');
fah_same($n['agent_context']['connectivity_returned_is_not_recovery'],true,'reconnect rule');
fah_same($n['history'][0]['payload']['recovery_complete'],false,'history recovery flag');

$bad=$input;$bad['cloud_read_only']=false;
try{tracky_v280_fah_normalize($bad);fah_fail('Cloud mutation projection was accepted');}
catch(Throwable $e){fah_expect(str_contains($e->getMessage(),'read-only'),'wrong read-only error');}

$bad=$input;$bad['authority_mutation']=true;
try{tracky_v280_fah_normalize($bad);fah_fail('Cloud authority mutation was accepted');}
catch(Throwable $e){fah_expect(str_contains($e->getMessage(),'authority mutation'),'wrong authority error');}

$bad=$input;$bad['sites'][1]['state']='current';$bad['sites'][1]['fresh']=false;$bad['sites'][1]['reconciliation_required']=true;$bad['sites'][1]['recovery_complete']=true;
$normalized=tracky_v280_fah_normalize($bad);
$officeBad=array_values(array_filter($normalized['sites'],static fn($r)=>$r['site_id']===$office))[0];
fah_expect($officeBad['recovery_complete']===false,'Cloud accepted recovered without current authoritative freshness');

$cap=tracky_v280_fah_public_capability();
fah_expect($cap['recovery_requires_authoritative_reconciliation']===true,'recovery gate capability');
fah_expect($cap['connectivity_returned_is_not_recovery']===true,'connectivity rule capability');
fah_expect($cap['cloud_read_only']===true,'Cloud read-only capability');
fah_expect($cap['cloud_can_mark_recovered']===false,'Cloud recovery authority');
fah_expect($cap['authority_mutation']===false,'authority mutation capability');

echo "TRACKY_V280_FEDERATION_AGENT_HEALTH_UNIT=PASS\n";
