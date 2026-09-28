<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function csp_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function csp_expect(bool $v,string $m): void { if(!$v)csp_fail($m); }
function csp_same(mixed $a,mixed $e,string $m): void { if($a!==$e)csp_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$pocket='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$operations=[
 'sites'=>[
  ['id'=>$home,'label'=>'Home','health'=>'healthy','federation'=>['status'=>'current'],'authority'=>['device_id'=>$node,'epoch'=>2]],
  ['id'=>$office,'label'=>'Office','health'=>'healthy','federation'=>['status'=>'current'],'authority'=>['device_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','epoch'=>1]],
 ],
 'devices'=>[['id'=>$pocket,'site_id'=>$home,'label'=>'Dave Pocket','hardware_profile'=>'pocket']],
];
$make=function(string $state)use($home,$office,$pocket):array{
 return [
  'transition_id'=>'trip-pocket-1','subject_kind'=>'mobile_device','subject_id'=>$pocket,
  'source_site_id'=>$home,'destination_site_id'=>$office,'state'=>$state,'previous_state'=>'departing',
  'state_reason'=>'test','confidence'=>.88,'destination_confidence'=>.8,'revision'=>3,
  'started_at'=>1000,'state_changed_at'=>1200,'updated_at'=>1250,
  'arrived_at'=>$state==='arrived'?1300:null,'offline_since'=>$state==='offline'?1220:null,
  'temporary_context'=>$state==='temporary_context'?['id'=>'hotel-room','label'=>'Hotel','confidence'=>.7,'durable_site'=>false,'site_authority'=>false]:null,
  'evidence'=>[
    ['type'=>'source_absence_confirmed','site_id'=>$home,'confidence'=>.95,'observed_at'=>1100],
    ['type'=>'destination_candidate','site_id'=>$office,'confidence'=>.8,'observed_at'=>1200],
  ],
  'identity_linking'=>false,
 ];
};

$report=tracky_v280_presence_build($operations,['transitions'=>[$make('in_transit')]]);
csp_same($report['protocol'],VP3_TRACKY_CROSS_SITE_PRESENCE_PROTOCOL_V280,'protocol');
csp_same($report['active_count'],1,'active count');
$item=$report['active_transitions'][0];
csp_same($item['last_confirmed_site']['site_id'],$home,'last confirmed site');
csp_same($item['current_presence']['status'],'in_transit','transit presence state');
csp_expect($item['current_presence']['confirmed']===false,'transit cannot confirm destination');
csp_expect($item['may_claim_present_at_destination']===false,'Agent cannot claim destination presence');
csp_same($report['agent_context']['destination_claim_rule'],'present_at_destination_only_after_arrived','Agent claim rule');

$arrived=tracky_v280_presence_build($operations,['transitions'=>[$make('arrived')]])['transitions'][0];
csp_same($arrived['current_presence']['site_id'],$office,'arrival destination');
csp_expect($arrived['current_presence']['confirmed']===true,'arrival confirms destination');
csp_expect($arrived['may_claim_present_at_destination']===true,'arrived claim allowed');
csp_expect($arrived['authority']['authority_transfer']===false,'movement cannot transfer authority');
csp_expect($arrived['identity']['cross_site_merge']===false,'movement cannot merge identities');

$temp=tracky_v280_presence_build($operations,['transitions'=>[$make('temporary_context')]])['active_transitions'][0]['current_presence'];
csp_expect($temp['durable_site']===false&&$temp['site_authority']===false,'temporary context promoted to durable site');

$raw=$make('arriving');
$history=[
 ['transition_id'=>'trip-pocket-1','revision'=>1,'fingerprint'=>'a','snapshot'=>array_merge($raw,['state'=>'departing','revision'=>1,'state_changed_at'=>1000])],
 ['transition_id'=>'trip-pocket-1','revision'=>2,'fingerprint'=>'b','snapshot'=>array_merge($raw,['state'=>'in_transit','revision'=>2,'state_changed_at'=>1100])],
 ['transition_id'=>'trip-pocket-1','revision'=>3,'fingerprint'=>'c','snapshot'=>array_merge($raw,['state'=>'arriving','revision'=>3,'state_changed_at'=>1200])],
];
$timeline=tracky_v280_presence_build($operations,['transitions'=>[$raw]],$history);
csp_same($timeline['history_source'],'immutable_transition_revision_history','history source');
csp_same(array_column($timeline['timeline'],'state'),['arriving','in_transit','departing'],'timeline order');
csp_expect(!in_array(false,array_column($timeline['timeline'],'immutable'),true),'history must be immutable');

$cap=tracky_v280_presence_public_capability();
csp_expect($cap['transition_history']===true,'history capability');
csp_expect($cap['destination_claim_requires_arrived']===true,'arrival rule capability');
csp_expect($cap['authority_mutation']===false,'Cloud authority mutation');
csp_expect($cap['physical_location_mutation']===false,'Cloud location mutation');
csp_expect($cap['cross_site_identity_merge']===false,'Cloud identity merge');

echo "TRACKY_V280_CROSS_SITE_PRESENCE_UNIT=PASS\n";
