<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function pwd_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function pwd_expect(bool $v,string $m): void { if(!$v)pwd_fail($m); }
function pwd_same(mixed $a,mixed $e,string $m): void { if($a!==$e)pwd_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$desk='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$operations=[
 'local_site_id'=>$home,
 'sites'=>[
  ['id'=>$home,'label'=>'Home','health'=>'healthy','federation'=>['status'=>'current'],'authority'=>['device_id'=>$node,'epoch'=>3]],
  ['id'=>$office,'label'=>'Office','health'=>'healthy','federation'=>['status'=>'current'],'authority'=>['device_id'=>$desk,'epoch'=>2]],
 ],
 'devices'=>[
  ['id'=>$node,'site_id'=>$home,'label'=>'Home Node','hardware_profile'=>'node','trust_state'=>'trusted'],
  ['id'=>$desk,'site_id'=>$office,'label'=>'Office Desk','hardware_profile'=>'desk','trust_state'=>'trusted'],
 ],
];
$world=['sites'=>[
 ['site_id'=>$home,'revision'=>4,'entities'=>[['local_id'=>'home-room','type'=>'room','label'=>'Living Room','state'=>'observed','confidence'=>.95,'observed_at'=>100]],'relations'=>[]],
 ['site_id'=>$office,'revision'=>7,'observed_at'=>200,'entities'=>[
  ['local_id'=>'office-room','type'=>'room','label'=>'Studio','state'=>'user-confirmed','confidence'=>1,'observed_at'=>190],
  ['local_id'=>'person-dave','type'=>'person','label'=>'Dave','state'=>'observed','confidence'=>.9,'observed_at'=>200],
  ['local_id'=>'mug','type'=>'object','label'=>'Coffee Mug','state'=>'last-known','confidence'=>.8,'observed_at'=>180],
  ['local_id'=>'camera','type'=>'camera','label'=>'Desk Camera','state'=>'observed','confidence'=>.99,'observed_at'=>200],
 ],'relations'=>[
  ['subject_local_id'=>'person-dave','predicate'=>'located_in','object_local_id'=>'office-room','confidence'=>.95,'temporal_state'=>'current','as_of'=>200],
  ['subject_local_id'=>'mug','predicate'=>'located_on','object_local_id'=>'office-room','confidence'=>.7,'temporal_state'=>'last_seen','as_of'=>180],
 ]],
]];
$context=['agent_state'=>'current','physical_state'=>'present','current_site'=>['site_id'=>$home]];

$report=tracky_v280_dashboard_build($operations,$world,$context,$office);
pwd_same($report['protocol'],VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_PROTOCOL_V280,'protocol');
pwd_same($report['selected_site']['site_id'],$office,'selected site');
pwd_same($report['selected_site']['basis'],'explicit_user_selection','selection basis');
pwd_same($report['agent_context']['view_site_id'],$office,'Agent view site');
pwd_same($report['agent_context']['physical_current_site_id'],$home,'physical current site preserved');
pwd_expect($report['agent_context']['changes_physical_authority']===false,'site switching cannot change authority');
pwd_expect($report['agent_context']['changes_physical_location']===false,'site switching cannot change physical location');
pwd_same($report['counts'],['rooms'=>1,'people'=>1,'objects'=>1,'world_devices'=>1,'hardware_units'=>1],'category counts');
pwd_same($report['people'][0]['location']['location_label'],'Studio','evidence-backed person location');
pwd_same($report['people'][0]['identity_scope'],'site_local','site-local identity');
pwd_expect($report['hardware_units'][0]['is_authority']===true,'authority hardware annotation');

$fallback=tracky_v280_dashboard_build($operations,$world,$context,'33333333-3333-4333-8333-333333333333');
pwd_same($fallback['selected_site']['site_id'],$home,'unavailable site falls back to current Agent site');
pwd_same($fallback['issues'][0]['code'],'requested_site_not_available','unavailable site issue');

$cap=tracky_v280_dashboard_public_capability();
pwd_expect($cap['site_switching']===true,'site switching capability');
pwd_expect($cap['agent_context_follows_selected_site']===true,'Agent context view follows selection');
pwd_expect($cap['authority_mutation']===false,'no authority mutation');
pwd_expect($cap['physical_location_mutation']===false,'no physical location mutation');
pwd_expect($cap['cross_site_identity_merge']===false,'no cross-site identity merge');

echo "TRACKY_V280_PHYSICAL_WORLD_DASHBOARD_UNIT=PASS\n";
