<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function fq_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function fq_expect(bool $v,string $m): void { if(!$v)fq_fail($m); }
function fq_same(mixed $a,mixed $e,string $m): void { if($a!==$e)fq_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function fq_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;fq_fail($m.' wrong error='.$e->getMessage());}
  fq_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';

fq_expect(tracky_v278_query_timestamp_ms('2026-09-27T20:00:00+00:00')>1700000000000,'ISO timestamp parsing');
fq_same(tracky_v278_query_timestamp_ms('2002'),2002,'numeric timestamp parsing');

$q=tracky_v278_query_normalize([
  'intent'=>'history',
  'destination_site_id'=>$home,
  'site_id'=>$office,
  'target_ref'=>'site:'.$office.'::object%3Alaptop',
  'since_ms'=>1000,
  'until_ms'=>5000,
  'limit'=>250,
]);
fq_same($q['protocol'],'physical_federated_query.v1','protocol');
fq_same($q['intent'],'history','intent');
fq_same($q['destination_site_id'],$home,'destination');
fq_same($q['site_id'],$office,'source');
fq_same($q['target_local_id'],'object:laptop','target local id');
fq_same($q['limit'],100,'limit clamp');
fq_expect(str_starts_with($q['query_id'],'fq:'),'deterministic query id missing');
fq_expect($q['semantic_only']===true&&$q['read_only']===true,'query must be semantic read-only');

$same=tracky_v278_query_normalize([
  'intent'=>'history','destination_site_id'=>$home,'site_id'=>$office,
  'target_ref'=>'site:'.$office.'::object%3Alaptop','since_ms'=>1000,'until_ms'=>5000,'limit'=>250,
]);
fq_same($same['query_id'],$q['query_id'],'query id must be deterministic');

fq_throws(fn()=>tracky_v278_query_normalize([
  'intent'=>'history','destination_site_id'=>$home,'site_id'=>$office,
  'target_ref'=>'site:'.$home.'::object%3Alaptop'
]),'different source site','cross-site target mismatch accepted');

fq_throws(fn()=>tracky_v278_query_normalize([
  'intent'=>'history','destination_site_id'=>$home,'site_id'=>$office,
  'camera_frame'=>'secret'
]),'local-only perception data','raw perception query accepted');

fq_throws(fn()=>tracky_v278_query_normalize([
  'intent'=>'invented','destination_site_id'=>$home,'site_id'=>$office
]),'intent is unsupported','unsupported intent accepted');

$ref=tracky_v278_query_site_ref('site:'.$office.'::object%3Alaptop');
fq_same($ref['site_id'],$office,'site ref site');
fq_same($ref['local_id'],'object:laptop','site ref local id');

$cap=tracky_v278_query_public_capability();
fq_same($cap['protocol'],'physical_federated_query.v1','capability protocol');
fq_same($cap['history_scope'],'history_query','history permission scope');
fq_expect($cap['deny_by_default']===true,'deny-by-default missing');
fq_expect($cap['destination_site_required']===true,'destination-site requirement missing');
fq_expect($cap['person_world_federation']===false,'person world federation leaked');
fq_expect($cap['authority_mutation']===false,'authority mutation leaked');
fq_same($cap['cloud_role'],'mirror_query_only','Cloud role');
fq_expect(in_array('cloud-mirror-only',$cap['boundaries'],true),'Cloud mirror boundary missing');

$caps=tracky_cloud_v270_capabilities([
  'federated_query'=>true,
  'federated_query_protocol'=>'physical_federated_query.v1',
]);
fq_expect($caps['federated_query']===true,'query capability missing');
fq_same($caps['federated_query_protocol'],'physical_federated_query.v1','query protocol');

$health=tracky_cloud_v270_health(['federated_query'=>'available']);
fq_same($health['federated_query'],'available','query health');

echo "TRACKY_V278_FEDERATED_QUERY_UNIT=PASS\n";
