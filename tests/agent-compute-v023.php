<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-compute-v020.php';
require dirname(__DIR__) . '/includes/agent-compute-v023.php';

function assert_same_v023(mixed $expected,mixed $actual,string $message): void
{
    if($expected!==$actual){
        fwrite(STDERR,$message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

$prefs=agent_compute_v023_preferences();
assert_same_v023(true,isset($prefs['inherit'],$prefs['auto'],$prefs['homeserver_only'],$prefs['vp3_cloud']),'Per-Agent compute must expose inherit plus all account policies.');

$inherit=agent_compute_v023_policy_from_values('homeserver_only','inherit');
assert_same_v023('homeserver_only',$inherit['effective_preference'],'Inherit must resolve to the account preference.');
assert_same_v023('account',$inherit['source'],'Inherited policy must report the account as its source.');

$override=agent_compute_v023_policy_from_values('homeserver_only','vp3_cloud');
assert_same_v023('vp3_cloud',$override['effective_preference'],'Explicit Agent policy must override the account default.');
assert_same_v023('agent',$override['source'],'Explicit Agent policy must report the Agent as its source.');

$auto=agent_compute_v023_policy_from_values('invalid','invalid');
assert_same_v023('auto',$auto['account_preference'],'Invalid account policy must fail safely to Automatic.');
assert_same_v023('inherit',$auto['agent_override'],'Invalid Agent override must fail safely to inherit.');
assert_same_v023('auto',$auto['effective_preference'],'Invalid policy input must resolve safely to Automatic.');

$public=agent_compute_v023_public_policy([
    'account_preference'=>'vp3_cloud',
    'agent_override'=>'homeserver_only',
    'effective_preference'=>'homeserver_only',
    'source'=>'agent',
],42);
assert_same_v023('v0.23',$public['version'],'Execution policy must identify v0.23.');
assert_same_v023(42,$public['agent_id'],'Execution policy must preserve the selected Agent id.');
assert_same_v023('homeserver_only',$public['effective_preference'],'Execution policy must expose the effective sanitized preference.');
assert_same_v023('agent',$public['source'],'Execution policy must expose whether the Agent overrode the account default.');

fwrite(STDOUT,"VP3 v0.23 per-Agent compute policy tests passed\n");
