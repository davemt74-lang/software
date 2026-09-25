<?php
declare(strict_types=1);

$GLOBALS['calls']=[];
$GLOBALS['mode']='success';

function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array {
    $GLOBALS['calls'][]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    if(($GLOBALS['mode']??'success')==='fail')throw new RuntimeException('HomeServer relay timeout');
    $result=match($operation){
        'files.list'=>['items'=>[['ref'=>'hsf-12-0123456789abcdef','name'=>'Plan.txt','size_bytes'=>1200]]],
        'files.read'=>['ref'=>'hsf-12-0123456789abcdef','name'=>'Plan.txt','content'=>'Local plan body'],
        'knowledge.search'=>['items'=>[['title'=>'Local note','snippet'=>'A local knowledge result']]],
        'tools.list'=>['items'=>[['key'=>'knowledge.search','name'=>'Knowledge Search','description'=>'Search local knowledge']]],
        'tool.execute'=>['tool'=>'devices.list','result'=>['items'=>[['device_key'=>'lamp-1','name'=>'Desk Lamp','category'=>'lights','room_name'=>'Office','state'=>['on'=>true]]]]],
        default=>[],
    };
    return ['ok'=>true,'result'=>$result,'execution'=>['version'=>'2.3','request_id'=>str_repeat('a',32),'route'=>'homeserver','status'=>'completed','fallback_used'=>false]];
}
function homeserver_execution_v230_failure_class(Throwable $e): string { return 'timeout'; }

require dirname(__DIR__).'/includes/homeserver-local-reads-v231.php';

$user=['id'=>7];

$generic=homeserver_reads_v231_context($user,'What should I work on today?');
assert($generic['attempted']===false);
assert(count($GLOBALS['calls'])===0,'generic chat must not inspect HomeServer');

$fileList=homeserver_reads_v231_context($user,'Find my local files about launch plan');
assert($fileList['attempted']===true&&$fileList['domain']==='files');
assert($GLOBALS['calls'][0]['operation']==='files.list');
assert(count($fileList['context'])===1);
assert(str_contains($fileList['context'][0]['text'],'hsf-12-0123456789abcdef'));

$fileRead=homeserver_reads_v231_context($user,'Read hsf-12-0123456789abcdef');
assert($GLOBALS['calls'][1]['operation']==='files.read');
assert(str_contains($fileRead['context'][0]['text'],'Local plan body'));

$knowledge=homeserver_reads_v231_context($user,'Search my HomeServer knowledge about launch');
assert($GLOBALS['calls'][2]['operation']==='knowledge.search');
assert(str_contains($knowledge['context'][0]['text'],'local knowledge'));
$beforeRepeat=count($GLOBALS['calls']);
$knowledgeRepeat=homeserver_reads_v231_context($user,'Search my HomeServer knowledge about launch');
assert(count($GLOBALS['calls'])===$beforeRepeat,'same-turn local read should be request-cached');
assert($knowledgeRepeat['context']===$knowledge['context']);

$tools=homeserver_reads_v231_context($user,'What local tools can my HomeServer use?');
assert($GLOBALS['calls'][3]['operation']==='tools.list');

$devices=homeserver_reads_v231_context($user,'Show my local device states');
assert($GLOBALS['calls'][4]['operation']==='tool.execute');
assert(($GLOBALS['calls'][4]['payload']['tool_key']??'')==='devices.list');
assert(str_contains($devices['context'][0]['text'],'Office'));

$enriched=homeserver_reads_v231_enrich_context([['source'=>'cloud','title'=>'Cloud','text'=>'base']],$user,'Search my local knowledge about launch');
assert(count($enriched['context'])===2);
assert(str_starts_with($enriched['context'][0]['source'],'homeserver-local:'));

$GLOBALS['mode']='fail';
$failed=homeserver_reads_v231_context($user,'Search my local knowledge about launch');
assert($failed['attempted']===true);
assert($failed['context']===[]);
assert(($failed['execution']['failure_class']??'')==='timeout');

echo "HomeServer v2.3 Section 2 local read runtime: PASS\n";
