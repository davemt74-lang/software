<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fo_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fo_expect(bool $v,string $m): void { if(!$v)fo_fail($m); }
function fo_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fo_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$desk='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$topology=[
 'revision'=>17,
 'sites'=>[
  ['id'=>$home,'label'=>'Home','status'=>'active','authority_device_id'=>$node,'authority_epoch'=>3],
  ['id'=>$office,'label'=>'Office','status'=>'active','authority_device_id'=>$desk,'authority_epoch'=>2],
 ],
 'devices'=>[
  ['id'=>$node,'site_id'=>$home,'label'=>'Home Node','hardware_profile'=>'node','mobility'=>'fixed','trust_state'=>'trusted','roles'=>['site_authority'],'capabilities'=>['site_authority_eligible']],
  ['id'=>$desk,'site_id'=>$office,'label'=>'Office Desk','hardware_profile'=>'desk','mobility'=>'fixed','trust_state'=>'trusted','roles'=>['site_authority'],'capabilities'=>['site_authority_eligible']],
 ],
 'relationships'=>[['subject_id'=>$home,'type'=>'peers_with','object_id'=>$office]],
];
$health=[
 ['site_id'=>$home,'status'=>'current'],
 ['site_id'=>$office,'status'=>'current'],
];
$report=tracky_v280_operations_snapshot($topology,$health);
fo_same($report['protocol'],VP3_TRACKY_FEDERATION_OPERATIONS_PROTOCOL_V280,'protocol');
fo_same($report['health'],'healthy','healthy topology');
fo_same($report['summary']['profile_counts'],['desk'=>1,'node'=>1],'profile inventory');
fo_same($report['summary']['authority_count'],2,'authority count');
fo_expect($report['read_only']===true,'operations must be read only');
fo_same($report['authority_assignment'],'origin_only','origin authority');

$partition=tracky_v280_operations_snapshot($topology,[
 ['site_id'=>$home,'status'=>'current'],
 ['site_id'=>$office,'status'=>'partitioned'],
]);
fo_same($partition['health'],'critical','partition should be critical');
$officeRow=array_values(array_filter($partition['sites'],static fn($s)=>$s['id']===$office))[0];
fo_same($officeRow['authority']['device_id'],$desk,'partition must not alter authority');
fo_expect(in_array('federation_partitioned',array_column($partition['issues'],'code'),true),'partition issue');

$unknown=tracky_v280_operations_snapshot($topology,[]);
fo_same($unknown['health'],'degraded','Cloud must not invent current peer freshness');
fo_expect(in_array('cloud-does-not-decide-destination-freshness',$unknown['boundaries'],true),'Cloud freshness boundary');

$bad=$topology;$bad['devices'][1]['trust_state']='revoked';
$badReport=tracky_v280_operations_snapshot($bad,$health);
$badOffice=array_values(array_filter($badReport['sites'],static fn($s)=>$s['id']===$office))[0];
fo_same($badOffice['authority']['status'],'invalid','revoked authority must fail closed');

$cap=tracky_v280_operations_public_capability();
fo_same($cap['version'],'2.80','capability version');
fo_expect($cap['cloud_decides_destination_freshness']===false,'Cloud cannot decide destination freshness');
fo_expect($cap['authority_mutation']===false,'Cloud cannot mutate authority');
fo_expect($cap['identity_mutation']===false,'Cloud cannot mutate identity');

echo "TRACKY_V280_FEDERATION_OPERATIONS_UNIT=PASS\n";
