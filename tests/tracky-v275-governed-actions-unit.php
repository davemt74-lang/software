<?php
declare(strict_types=1);

require __DIR__.'/../includes/tracky-actions-v275.php';

function v275_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v275_expect(bool $value,string $message): void { if(!$value)v275_fail($message); }
function v275_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v275_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}
function v275_throws(callable $fn,string $contains,string $message): void {
    try{$fn();}catch(Throwable $e){
        if($contains===''||str_contains($e->getMessage(),$contains))return;
        v275_fail($message.' wrong error='.$e->getMessage());
    }
    v275_fail($message.' did not throw');
}

$safe=tracky_v275_validate_action_payload([
    'device_key'=>'office-light',
    'command'=>'off',
    'arguments'=>[],
    'reason'=>'Office appears empty.',
    'requested_mode'=>'request_approval',
    'origin_kind'=>'agent_suggestion',
    'source_event_id'=>'tracky-event-12345678',
]);
v275_same($safe['device_key'],'office-light','device key');
v275_same($safe['command'],'off','command');
v275_same($safe['requested_mode'],'request_approval','mode');
v275_same($safe['origin_kind'],'agent_suggestion','origin');

v275_throws(
    fn()=>tracky_v275_validate_action_payload([
        'device_key'=>'bad device key','command'=>'off','reason'=>'x'
    ]),
    'device key','invalid device key'
);
v275_throws(
    fn()=>tracky_v275_validate_action_payload([
        'device_key'=>'office-light','command'=>'off','reason'=>'x','origin_kind'=>'automation_trigger'
    ]),
    'cannot impersonate','remote automation impersonation'
);
v275_throws(
    fn()=>tracky_v275_validate_action_payload([
        'device_key'=>'office-light','command'=>'off','reason'=>'x','requested_mode'=>'execute_now'
    ]),
    'Invalid Tracky action proposal mode','invalid mode'
);

v275_expect(str_contains(
    tracky_v275_result_note([
        'status'=>'suggested','permission_upgrade_required'=>true
    ]),
    'did not widen that permission automatically'
),'permission downgrade note');
v275_expect(str_contains(
    tracky_v275_result_note(['status'=>'requested']),
    'requires approval from local HomeServer owner control'
),'local approval note');
v275_expect(str_contains(
    tracky_v275_result_note(['reason'=>'homeserver_reconciliation_pending']),
    'v2.4 reconciliation'
),'v2.4 gate note');

$vectors=json_decode(file_get_contents(__DIR__.'/fixtures/tracky_v275_action_vectors.json'),true);
v275_same($vectors['protocol'],'physical_action.v1','vector protocol');
$ids=array_column($vectors['scenarios'],'id');
foreach([
    'default_cloud_permission','explicit_device_permission','remote_approval','direct_execution',
    'blocked_lock','blocked_garage','blocked_security','safety_event',
    'v24_reconciliation_pending','action_intent_replay','event_rule_replay',
    'event_rule_low_confidence','event_rule_stale'
] as $id){
    v275_expect(in_array($id,$ids,true),'missing V2.75 vector '.$id);
}

echo "TRACKY_V275_GOVERNED_ACTIONS_UNIT=PASS\n";
