<?php
declare(strict_types=1);

const VP3_TRACKY_PROTOCOL_V270='physical_context.v1';
require __DIR__.'/../includes/tracky-reliability-v274.php';

function v274_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v274_expect(bool $value,string $message): void { if(!$value)v274_fail($message); }
function v274_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v274_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}
function v274_throws(callable $fn,string $contains,string $message): void {
    try{$fn();}catch(Throwable $e){
        if($contains===''||str_contains($e->getMessage(),$contains))return;
        v274_fail($message.' wrong error='.$e->getMessage());
    }
    v274_fail($message.' did not throw');
}

$projection=[
    'protocol'=>'physical_context.v1',
    'site'=>['id'=>'hs-test','label'=>'HomeServer'],
    'events'=>[
        ['sequence'=>8],
        ['sequence'=>11],
    ],
    'world_state'=>[
        ['sequence'=>10],
    ],
    'context_sequence'=>9,
];
v274_same(tracky_v274_projection_max_sequence($projection),11,'projection max sequence');

$cap=[
    'connected'=>true,'compatible'=>true,'device_id'=>'hs-test',
    'reconciliation'=>['needs_reconciliation'=>false],
    'tracky'=>[
        'provider'=>['available'=>true],
        'reliability'=>['state'=>'healthy'],
    ],
];
$response=[
    'request'=>[
        'request_id'=>'req-12345678',
        'correlation_id'=>'corr-12345678',
        'site_id'=>'hs-test',
        'status'=>'completed',
        'result'=>[
            'reason'=>'completed',
            'semantic_projection'=>$projection,
        ],
    ],
];
$valid=tracky_v274_validate_active_response($cap,$response,'req-12345678','corr-12345678','hs-test');
v274_same($valid['status'],'completed','valid response status');
v274_same($valid['site_id'],'hs-test','valid response site');

v274_throws(
    fn()=>tracky_v274_validate_active_response($cap,$response,'req-other-1234','corr-12345678','hs-test'),
    'request identity','request identity mismatch'
);
v274_throws(
    fn()=>tracky_v274_validate_active_response($cap,$response,'req-12345678','corr-other-1234','hs-test'),
    'correlation identity','correlation identity mismatch'
);

$wrongSite=$response;
$wrongSite['request']['site_id']='hs-other';
v274_throws(
    fn()=>tracky_v274_validate_active_response($cap,$wrongSite,'req-12345678','corr-12345678','hs-test'),
    'site identity','request site mismatch'
);

$wrongProjection=$response;
$wrongProjection['request']['result']['semantic_projection']['site']['id']='hs-other';
v274_throws(
    fn()=>tracky_v274_validate_active_response($cap,$wrongProjection,'req-12345678','corr-12345678','hs-test'),
    'wrong site','projection site mismatch'
);

$wrongProtocol=$response;
$wrongProtocol['request']['result']['semantic_projection']['protocol']='physical_context.v0';
v274_throws(
    fn()=>tracky_v274_validate_active_response($cap,$wrongProtocol,'req-12345678','corr-12345678','hs-test'),
    'incompatible','projection protocol mismatch'
);

v274_same(
    tracky_v274_classify(['connected'=>false,'reason'=>'homeserver_offline']),
    ['state'=>'offline','reason'=>'homeserver_offline','fresh_verification'=>false],
    'offline classification'
);
v274_same(
    tracky_v274_classify(['connected'=>true,'compatible'=>false]),
    ['state'=>'incompatible','reason'=>'protocol_incompatible','fresh_verification'=>false],
    'protocol mismatch classification'
);
v274_same(
    tracky_v274_classify([
        'connected'=>true,'compatible'=>true,
        'reconciliation'=>['needs_reconciliation'=>true],
    ]),
    ['state'=>'reconciling','reason'=>'homeserver_reconciliation_pending','fresh_verification'=>false],
    'reconciliation classification'
);
v274_same(
    tracky_v274_classify([
        'connected'=>true,'compatible'=>true,
        'reconciliation'=>['needs_reconciliation'=>false],
        'tracky'=>['provider'=>['available'=>false]],
    ]),
    ['state'=>'degraded','reason'=>'provider_unavailable','fresh_verification'=>false],
    'provider unavailable classification'
);
v274_same(
    tracky_v274_classify([
        'connected'=>true,'compatible'=>true,
        'reconciliation'=>['needs_reconciliation'=>false],
        'tracky'=>[
            'provider'=>['available'=>true],
            'reliability'=>['state'=>'critical'],
        ],
    ]),
    ['state'=>'critical','reason'=>'homeserver_tracky_backlog_critical','fresh_verification'=>true],
    'critical backlog classification'
);
v274_same(
    tracky_v274_classify([
        'connected'=>true,'compatible'=>true,
        'reconciliation'=>['needs_reconciliation'=>false],
        'tracky'=>[
            'provider'=>['available'=>true],
            'reliability'=>['state'=>'recovering'],
        ],
    ]),
    ['state'=>'recovering','reason'=>'homeserver_tracky_recovering','fresh_verification'=>true],
    'recovering classification'
);
v274_same(
    tracky_v274_classify($cap,['status'=>'healthy'],['state'=>'stale']),
    ['state'=>'stale','reason'=>'cloud_projection_stale','fresh_verification'=>true],
    'stale classification'
);
v274_same(
    tracky_v274_classify($cap,['status'=>'healthy'],['state'=>'current']),
    ['state'=>'healthy','reason'=>'ready','fresh_verification'=>true],
    'healthy classification'
);

v274_expect(str_contains(tracky_v274_failure_note('provider_timeout'),'exceeded its deadline'),'provider timeout note');
v274_expect(str_contains(tracky_v274_failure_note('invalid_homeserver_response'),'identity checks'),'invalid response note');

$vectors=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v274_resilience_vectors.json'),true);
v274_same($vectors['protocol'],'physical_context.v1','shared vector protocol');
$ids=array_column($vectors['scenarios'],'id');
foreach([
    'replay_same_event','sequence_conflict','cloud_outage_backoff','stale_request_after_restart',
    'correlation_supersession','provider_timeout','privacy_denied','reconciliation_pending',
    'protocol_mismatch','wrong_site','backlog_warning','backlog_critical',
    'fresh_projection_identity_mismatch'
] as $id){
    v274_expect(in_array($id,$ids,true),'missing resilience vector '.$id);
}

echo "TRACKY_V274_RELIABILITY_UNIT=PASS\n";
