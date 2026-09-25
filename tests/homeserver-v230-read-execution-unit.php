<?php
declare(strict_types=1);

$GLOBALS['read_calls']=[];
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[],?callable $fallback=null): array {
    $GLOBALS['read_calls'][]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    $result=match($operation){
        'files.list'=>['items'=>[['name'=>'Plan.txt','ref'=>'hsf-12-0123456789abcdef','snippet'=>'Local plan']]],
        'files.read'=>['name'=>'Plan.txt','ref'=>'hsf-12-0123456789abcdef','content'=>'Private local file text returned on explicit request.'],
        'knowledge.search'=>['items'=>[['title'=>'Local note','snippet'=>'Knowledge result']]],
        'tools.list'=>['items'=>[['key'=>'knowledge.search','name'=>'Knowledge search','mode'=>'read']]],
        'tool.execute'=>['tool'=>'devices.list','result'=>['items'=>[['device_key'=>'light.office','name'=>'Office Lamp','category'=>'light','room_name'=>'Office','state'=>['on'=>true]]]]],
        default=>[],
    };
    return ['ok'=>true,'result'=>$result,'execution'=>[
        'version'=>'2.3','request_id'=>'0123456789abcdef0123456789abcdef',
        'domain'=>$operation==='tool.execute'?'devices':'files','operation'=>$operation,
        'route'=>'homeserver','status'=>'completed','fallback_used'=>false,'failure_class'=>'none','duration_ms'=>8,
    ]];
}
function agent_tool_log(array $user,string $toolKey,string $requestText,string $status,array $result=[],?int $conversationId=null): void {}

require dirname(__DIR__).'/includes/homeserver-agent-read-v230.php';

assert(homeserver_agent_read_v230_intent('find documents about taxes')===null);
$files=homeserver_agent_read_v230_intent('search my HomeServer files for the launch plan');
assert($files['operation']==='files.list');
$read=homeserver_agent_read_v230_intent('read hsf-12-0123456789abcdef');
assert($read['operation']==='files.read');
$knowledge=homeserver_agent_read_v230_intent('search local knowledge for launch notes');
assert($knowledge['operation']==='knowledge.search');
$tools=homeserver_agent_read_v230_intent('what HomeServer tools are available?');
assert($tools['operation']==='tools.list');
$devices=homeserver_agent_read_v230_intent('show local lights connected to HomeServer');
assert($devices['operation']==='tool.execute');
assert($devices['payload']['tool_key']==='devices.list');
assert(($devices['payload']['arguments']['category']??'')==='light');
assert(homeserver_agent_read_v230_intent('turn on my HomeServer light')===null,'physical command must not enter read bridge');

$user=['id'=>7];
$result=homeserver_agent_read_v230_query('search my HomeServer files for the launch plan',$user,44);
assert($result['handled']===true);
assert(str_contains($result['answer'],'Plan.txt'));
assert(($result['execution']['route']??'')==='homeserver');
assert(($GLOBALS['read_calls'][0]['operation']??'')==='files.list');

$deviceResult=homeserver_agent_read_v230_query('show local lights connected to HomeServer',$user,44);
assert(str_contains($deviceResult['answer'],'Office Lamp'));
assert(($deviceResult['execution']['domain']??'')==='devices');

echo "HomeServer v2.3 Section 2 read execution runtime: PASS\n";
