<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function ffh_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function ffh_expect(bool $v,string $m): void { if(!$v)ffh_fail($m); }
function ffh_same(mixed $a,mixed $e,string $m): void { if($a!==$e)ffh_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$input=[
 'protocol'=>'physical_federation_fleet_health.v1','version'=>'2.80','schema_version'=>1,
 'generated_at'=>1759063200000,'local_site_id'=>$home,'overall_state'=>'stale',
 'sites'=>[
  ['site_id'=>$home,'label'=>'Home','state'=>'healthy','severity'=>'info','diagnostics_allowed'=>true,'diagnostics_current'=>true,
   'federation_state'=>'current','federation_recovery_complete'=>true,'agent_visible'=>true,'current_claims_allowed'=>true,
   'devices'=>[[
     'device_id'=>'hs-aaaaaaaaaaaaaaaaaaaaaaaa','label'=>'HomeServer','hardware_profile'=>'node','os_version'=>'v2.4',
     'release_channel'=>'stable','rollout_ring'=>'broad','commissioning_state'=>'ready','certification_result'=>'passed',
     'update_status'=>'idle','backup_state'=>'ready','storage_state'=>'ok','watchdog_failures'=>0,'privacy_fault'=>false,
     'last_seen_at'=>'2026-09-28T15:20:00+00:00','state'=>'healthy','severity'=>'info','issues'=>[]
   ]]],
  ['site_id'=>$office,'label'=>'Office','state'=>'stale','severity'=>'warning','cause'=>'federation_partitioned',
   'diagnostics_allowed'=>true,'diagnostics_current'=>false,'federation_state'=>'partitioned','federation_recovery_complete'=>false,
   'agent_visible'=>true,'current_claims_allowed'=>false,'devices'=>[]],
 ],
 'privacy'=>[
   'diagnostic_content_included'=>false,'local_path_details_included'=>false,'network_endpoint_details_included'=>false,
   'secret_material_included'=>false,'conversation_content_included'=>false,'captured_media_content_included'=>false,'knowledge_content_included'=>false
 ],
 'summary_only'=>true,'cloud_read_only'=>true,'authority_mutation'=>false,'remote_command_execution'=>false,
];

$n=tracky_v280_ffh_normalize($input);
ffh_same($n['protocol'],'physical_federation_fleet_health.v1','protocol');
ffh_same($n['local_site_id'],$home,'local site');
ffh_same($n['overall_state'],'stale','overall state');
ffh_same(count($n['sites']),2,'site count');
ffh_same($n['counts']['devices'],1,'device count');
$officeRow=array_values(array_filter($n['sites'],static fn($r)=>$r['site_id']===$office))[0];
ffh_same($officeRow['state'],'stale','office state');
ffh_expect($officeRow['diagnostics_current']===false,'partitioned diagnostics marked current');
ffh_expect($officeRow['current_claims_allowed']===false,'partitioned fleet allowed current claims');
ffh_expect($n['privacy']['diagnostic_content_included']===false,'diagnostic content privacy boundary');

$bad=$input;$bad['cloud_read_only']=false;
try{tracky_v280_ffh_normalize($bad);ffh_fail('Writable Cloud fleet projection was accepted');}
catch(Throwable $e){ffh_expect(str_contains($e->getMessage(),'read-only'),'wrong read-only error');}

$bad=$input;$bad['authority_mutation']=true;
try{tracky_v280_ffh_normalize($bad);ffh_fail('Fleet authority mutation was accepted');}
catch(Throwable $e){ffh_expect(str_contains($e->getMessage(),'mutate authority'),'wrong authority error');}

$bad=$input;$bad['remote_command_execution']=true;
try{tracky_v280_ffh_normalize($bad);ffh_fail('Fleet remote command execution was accepted');}
catch(Throwable $e){ffh_expect(str_contains($e->getMessage(),'remote commands'),'wrong command error');}

$bad=$input;$bad['sites'][0]['devices'][0]['raw_logs']='forbidden';
try{tracky_v280_ffh_normalize($bad);ffh_fail('Governance firewall accepted local-only diagnostic content');}
catch(Throwable $e){ffh_expect(str_contains($e->getMessage(),'local-only'),'wrong governance error');}

$cap=tracky_v280_ffh_public_capability();
ffh_expect($cap['section7_health_is_authoritative']===true,'Section 7 authority capability');
ffh_expect($cap['diagnostics_never_promote_federation_freshness']===true,'freshness guard capability');
ffh_expect($cap['cloud_read_only']===true,'Cloud read-only capability');
ffh_expect($cap['remote_command_execution']===false,'remote command capability');
ffh_expect($cap['authority_mutation']===false,'authority mutation capability');

echo "TRACKY_V280_FEDERATION_FLEET_HEALTH_UNIT=PASS\n";
