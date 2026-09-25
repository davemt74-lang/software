<?php
declare(strict_types=1);

$GLOBALS['v230_compute_calls']=[];
$GLOBALS['legacy_compute_calls']=[];

function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[],?callable $fallback=null): array {
    $GLOBALS['v230_compute_calls'][]=[$userId,$operation,$payload];
    return [
      'ok'=>true,
      'result'=>[
        'reply'=>'Local answer',
        'provider'=>'ollama',
        'model'=>'local-model',
        'compute_source'=>'homeserver_local',
      ],
      'execution'=>[
        'version'=>'2.3',
        'request_id'=>'0123456789abcdef0123456789abcdef',
        'domain'=>'agent_compute',
        'operation'=>'agent.chat',
        'route'=>'homeserver',
        'status'=>'completed',
        'fallback_used'=>false,
        'failure_class'=>'none',
        'duration_ms'=>12,
      ],
    ];
}

function homeserver_vp3_remote_operation_for_user(int $userId,string $operation,array $payload=[],string $homeServerToken=''): array {
    $GLOBALS['legacy_compute_calls'][]=[$userId,$operation,$payload,$homeServerToken];
    return [
      'reply'=>'Legacy answer',
      'provider'=>'custom',
      'model'=>'custom-model',
      'compute_source'=>'user_provider',
    ];
}

require dirname(__DIR__).'/includes/homeserver-agent-v018.php';

$https=homeserver_agent_v018_execute(
    7,
    ['transport'=>'vp3_https','relay'=>'','home'=>'H'.str_repeat('x',40)],
    ['message'=>'hello']
);
assert(count($GLOBALS['v230_compute_calls'])===1);
assert(count($GLOBALS['legacy_compute_calls'])===0);
assert($GLOBALS['v230_compute_calls'][0][1]==='agent.chat');
assert(($https['result']['reply']??'')==='Local answer');
assert(($https['execution_receipt']['domain']??'')==='agent_compute');
assert(($https['execution_receipt']['request_id']??'')==='0123456789abcdef0123456789abcdef');

$custom=homeserver_agent_v018_execute(
    7,
    ['transport'=>'custom_websocket','relay'=>'R'.str_repeat('x',40),'home'=>'H'.str_repeat('x',40)],
    ['message'=>'hello legacy']
);
assert(count($GLOBALS['legacy_compute_calls'])===1);
assert($GLOBALS['legacy_compute_calls'][0][1]==='agent.chat');
assert(($custom['result']['reply']??'')==='Legacy answer');
assert(($custom['execution_receipt']??[])===[]);

echo "HomeServer v2.3 Section 3 Agent compute runtime: PASS\n";
