<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fh_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fh_expect(bool $v,string $m): void { if(!$v)fh_fail($m); }
function fh_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fh_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$input=[
 'protocol'=>'physical_federation_agent_health.v1','version'=>'2.80','schema_version'=>1,
 'generated_at'=>2000000,'local_site_id'=>$home,'overall_state'=>'recovering',
 'sites'=>[
  ['site_id'=>$home,'label'=>'Home','is_local'=>true,'state'=>'connected','state_since'=>1990000,'state_age_ms'=>10000,'priority'=>'info','health'=>'healthy','sync_status'=>'current','reconciliation_required'=>false,'fresh'=>true,'issue_code'=>'','message'=>'Home is connected and authoritative federation state is current.','authority'=>['status'=>'current','device_id'=>$node,'epoch'=>4],'authority_device'=>['id'=>$node,'label'=>'Home Node','hardware_profile'=>'node','runtime_status'=>'online'],'trust'=>['local_physical_truth_current'=>true,'remote_federation_truth_current'=>true,'cloud_transport_connected'=>true,'agent_may_treat_remote_state_as_current'=>true,'agent_may_treat_local_state_as_current'=>true],'access'=>['revocation_wins'=>true],'recovery_pending'=>false],
  ['site_id'=>$office,'label'=>'Office','is_local'=>false,'state'=>'recovering','state_since'=>1999000,'state_age_ms'=>1000,'priority'=>'warning','health'=>'degraded','sync_status'=>'reconciling','reconciliation_required'=>true,'fresh'=>false,'issue_code'=>'reconciliation_recovery_pending','message'=>'Office has connectivity again but is still reconciling. Recovery is not complete.','authority'=>['status'=>'current','device_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','epoch'=>2],'authority_device'=>['id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','label'=>'Office Node','hardware_profile'=>'node','runtime_status'=>'online'],'trust'=>['local_physical_truth_current'=>false,'remote_federation_truth_current'=>false,'cloud_transport_connected'=>true,'agent_may_treat_remote_state_as_current'=>false,'agent_may_treat_local_state_as_current'=>false],'access'=>['policy_peer_allowed'=>true,'federation_enabled'=>true,'revocation_wins'=>true],'recovery_pending'=>true],
 ],
 'bridge'=>['state'=>'connected','connected'=>true,'paired'=>true,'state_since'=>1900000,'state_age_ms'=>100000,'last_error'=>'','reconnect_count'=>2,'transport'=>'vp3_https'],
 'events'=>[],
 'history'=>[
  ['event_id'=>'federation-health:1','event_type'=>'tracky.federation_health.site_recovering','entity_key'=>$office,'summary'=>'Office reconnecting','importance'=>0.7,'payload'=>['event_type'=>'site_recovering','site_id'=>$office,'priority'=>'warning','reconnect_is_not_recovery'=>true],'occurred_at'=>'2026-09-28T12:00:00+00:00','created_at'=>'2026-09-28 12:00:00','immutable'=>true],
 ],
 'agent_context'=>['state'=>'recovering'],
 'delivery'=>['chat_events'=>true,'priority_notifications'=>true,'voice_respects_existing_settings'=>true],
 'cloud_read_only'=>true,'cloud_can_mark_recovered'=>false,'authority_mutation'=>false,
];

$n=tracky_v280_health_normalize($input);
fh_same($n['protocol'],'physical_federation_agent_health.v1','protocol');
fh_same($n['overall_state'],'recovering','overall state');
fh_same(count($n['sites']),2,'site count');
$officeRow=array_values(array_filter($n['sites'],static fn($r)=>$r['site_id']===$office))[0];
fh_same($officeRow['state'],'recovering','recovery state');
fh_expect($officeRow['fresh']===false,'recovering site cannot be fresh');
fh_expect($officeRow['recovery_pending']===true,'recovery pending missing');
fh_expect($officeRow['trust']['agent_may_treat_remote_state_as_current']===false,'Agent current-state leak');
fh_same($n['bridge']['state'],'connected','bridge state');
fh_same(count($n['history']),1,'history count');
fh_expect($n['history'][0]['immutable']===true,'history immutability');
fh_expect($n['cloud_can_mark_recovered']===false,'Cloud recovery authority leaked');
fh_expect($n['authority_mutation']===false,'Cloud authority mutation leaked');

$bad=$input;$bad['cloud_can_mark_recovered']=true;
try{tracky_v280_health_normalize($bad);fh_fail('Cloud recovery authority was accepted');}
catch(Throwable $e){fh_expect(str_contains($e->getMessage(),'cannot mark federation recovery'),'wrong recovery authority error');}

$bad=$input;$bad['events']=[['event_type'=>'site_recovered']];
try{tracky_v280_health_normalize($bad);fh_fail('Actionable event queue was accepted');}
catch(Throwable $e){fh_expect(str_contains($e->getMessage(),'history, not an actionable event queue'),'wrong event queue error');}

$cap=tracky_v280_health_public_capability();
fh_expect($cap['recovery_requires_current_reconciliation']===true,'recovery invariant');
fh_expect($cap['duplicate_state_suppression']===true,'dedupe capability');
fh_expect($cap['duration_escalation']===true,'escalation capability');
fh_expect($cap['cloud_read_only']===true,'Cloud read only');
fh_expect($cap['cloud_can_mark_recovered']===false,'Cloud recovery capability');
fh_expect($cap['authority_mutation']===false,'authority mutation');

echo "TRACKY_V280_AGENT_FEDERATION_HEALTH_UNIT=PASS\n";
