<?php
declare(strict_types=1);

$GLOBALS['receipts']=[];
$GLOBALS['attempt']=[
  'attempted'=>true,'success'=>false,'latency_ms'=>91,'failure_class'=>'timeout',
  'provider'=>'','model'=>'','compute_source'=>'',
];

function homeserver_execution_v230_policy(string $operation,array $payload=[]): array {
    return ['version'=>'2.3','operation'=>$operation,'domain'=>'agent_compute','fallback_allowed'=>true,'write_or_physical'=>false];
}
function homeserver_execution_v230_receipt(
    int $userId,string $requestId,array $policy,string $route,string $status,
    bool $fallbackUsed,string $failureClass,int $durationMs,array $resultMeta=[]
): array {
    $row=['user_id'=>$userId,'request_id'=>$requestId,'domain'=>$policy['domain'],'operation'=>$policy['operation'],
      'route'=>$route,'status'=>$status,'fallback_used'=>$fallbackUsed,'failure_class'=>$failureClass,
      'duration_ms'=>$durationMs,'result_meta'=>$resultMeta];
    $GLOBALS['receipts'][]=$row;
    return $row;
}
function homeserver_agent_v018_last_attempt(): array { return $GLOBALS['attempt']; }

require dirname(__DIR__).'/includes/homeserver-compute-v232.php';

$local=['compute_source'=>'homeserver_local','provider'=>'ollama','model'=>'llama-test','run_id'=>12,'latency_ms'=>44,'answer'=>'secret answer'];
$r=homeserver_compute_v232_success(7,$local);
assert($r['route']==='homeserver_local');
assert($r['status']==='completed');
assert($r['fallback_used']===false);
assert(($r['result_meta']['provider']??'')==='ollama');
assert(!array_key_exists('answer',$r['result_meta']));

$userProvider=homeserver_compute_v232_success(7,['compute_source'=>'user_provider','provider'=>'openai','model'=>'gpt-x','latency_ms'=>50]);
assert($userProvider['route']==='homeserver_user_provider');

$viaHome=homeserver_compute_v232_success(7,['compute_source'=>'vp3_cloud','provider'=>'vp3','model'=>'cloud','latency_ms'=>60]);
assert($viaHome['route']==='vp3_cloud_via_homeserver');

$execution=['source'=>'vp3_cloud','actual_route'=>'vp3_cloud'];
$fallback=homeserver_compute_v232_fallback(7,$execution);
assert($fallback['route']==='cloud_fallback');
assert($fallback['fallback_used']===true);
assert($fallback['failure_class']==='timeout');
assert(($fallback['result_meta']['fallback_actual_route']??'')==='vp3_cloud');

$failed=homeserver_compute_v232_failure(7);
assert($failed['route']==='homeserver');
assert($failed['status']==='failed');
assert($failed['failure_class']==='timeout');

$attached=homeserver_compute_v232_attach(7,['source'=>'homeserver_local'],$local,true);
assert(isset($attached['homeserver_compute_receipt']));
assert($attached['homeserver_compute_receipt']['route']==='homeserver_local');

$GLOBALS['attempt']=['attempted'=>false,'success'=>false,'latency_ms'=>0,'failure_class'=>'none'];
assert(homeserver_compute_v232_failure(7)===null);

echo "HomeServer v2.3 Section 3 compute runtime: PASS\n";
