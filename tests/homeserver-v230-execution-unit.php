<?php
declare(strict_types=1);

$GLOBALS['v230_mode']='success';
$GLOBALS['v230_ops']=[];

function db(){ return null; }
function homeserver_execution_v220_can_route(int $userId,string $operation): bool { return $userId===7 && $operation!==''; }
function homeserver_https_v1300_remote_operation(int $userId,string $operation,array $payload=[]): array {
    $GLOBALS['v230_ops'][]=$operation;
    if(($GLOBALS['v230_mode']??'success')==='fail') throw new RuntimeException('HomeServer relay timeout');
    return ['status'=>'completed','count'=>2,'run_id'=>31,'items'=>[['private'=>'not persisted'],['private'=>'not persisted']]];
}

require dirname(__DIR__).'/includes/homeserver-local-execution-v230.php';

assert(homeserver_execution_v230_operation('tools.execute')==='tool.execute');
assert(homeserver_execution_v230_domain('agent.chat')==='agent_compute');
assert(homeserver_execution_v230_domain('files.read')==='files');
assert(homeserver_execution_v230_domain('knowledge.search')==='knowledge');
assert(homeserver_execution_v230_domain('speech.synthesize')==='voice');
assert(homeserver_execution_v230_domain('tool.execute',['tool_key'=>'devices.list'])==='devices');

$success=homeserver_execution_v230_execute(7,'tools.execute',['tool_key'=>'knowledge.search','arguments'=>['query'=>'test']]);
assert($success['ok']===true);
assert($GLOBALS['v230_ops'][0]==='tool.execute');
assert($success['execution']['route']==='homeserver');
assert($success['execution']['fallback_used']===false);
assert($success['execution']['result_meta']['item_count']===2);
assert(!array_key_exists('items',$success['execution']['result_meta']));

$GLOBALS['v230_mode']='fail';
$fallback=homeserver_execution_v230_execute(
    7,
    'knowledge.search',
    ['query'=>'test'],
    static fn(Throwable $e,array $policy):array=>['status'=>'cloud','count'=>1,'items'=>[['cloud'=>true]]]
);
assert($fallback['ok']===true);
assert($fallback['execution']['route']==='cloud_fallback');
assert($fallback['execution']['fallback_used']===true);
assert($fallback['execution']['failure_class']==='timeout');

$threw=false;
try{
    homeserver_execution_v230_execute(
        7,
        'tool.execute',
        ['tool_key'=>'devices.command','arguments'=>['device_key'=>'lamp','command'=>'on']],
        static fn():array=>['should_not'=>'run']
    );
}catch(RuntimeException $e){$threw=true;}
assert($threw===true,'physical/write execution must not silently fall back');

echo "HomeServer v2.3 Section 1 execution runtime: PASS\n";
