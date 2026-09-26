<?php
declare(strict_types=1);

$GLOBALS['v245_observed']=[];
$GLOBALS['v245_calls']=[];

function homeserver_federated_v240_canonical_id(string $source,string $dataset,mixed $key): string
{
    return 'fd24_'.substr(hash('sha256',$source.'|'.$dataset.'|'.trim((string)$key)),0,40);
}
function homeserver_federated_v240_observe(int $userId,array $record,string $observedSource='vp3_cloud'): array
{
    $GLOBALS['v245_observed'][]=['user_id'=>$userId,'record'=>$record,'observed_source'=>$observedSource];
    return ['canonical_id'=>$record['canonical_id']??'','record_revision'=>$record['record_revision']??''];
}
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v245_calls'][]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    $key='agent_memory:42';
    $canonical=homeserver_federated_v240_canonical_id('homeserver','memory',$key);
    return ['result'=>['result'=>['items'=>[
      [
        'id'=>42,'agent_id'=>1,'memory_key'=>'project:section6',
        'content'=>'HomeServer canonical memory content.',
        'importance'=>0.9,'memory_type'=>'semantic','confidence'=>0.88,
        'entity_type'=>'project','entity_key'=>'section6','reinforcement_count'=>2,
        'updated_at'=>'2026-09-26T15:00:00Z',
        'authority_source'=>'homeserver','authority_key'=>$key,
        'canonical_id'=>$canonical,'record_revision'=>str_repeat('a',64),
      ],
      [
        'id'=>43,'memory_key'=>'tampered','content'=>'Must be rejected',
        'authority_source'=>'homeserver','authority_key'=>'agent_memory:43',
        'canonical_id'=>'fd24_'.str_repeat('0',40),
        'record_revision'=>str_repeat('b',64),
      ],
    ]]]];
}
function homeserver_reads_v231_rows(mixed $value): array
{
    if(!is_array($value))return [];
    if(isset($value['result'])&&is_array($value['result']))return homeserver_reads_v231_rows($value['result']);
    if(isset($value['items'])&&is_array($value['items']))return array_values(array_filter($value['items'],'is_array'));
    if(array_is_list($value))return array_values(array_filter($value,'is_array'));
    return [$value];
}

require dirname(__DIR__).'/includes/homeserver-agent-brain-memory-v245.php';

function v245_assert(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

v245_assert(VP3_HOMESERVER_AGENT_BRAIN_MEMORY_V245==='vp3-homeserver-agent-brain-memory-v245-20260926','Section 6 build ID');

$row=[
 'memory_type'=>'preference','subject'=>'Status style','memory_text'=>'Concise updates',
 'occurrence_count'=>3,'confidence'=>0.91,'last_seen_at'=>'2026-09-26 15:00:00',
 'metadata_json'=>'{"source_kind":"explicit_user"}','is_active'=>1,
];
$first=homeserver_agent_brain_memory_v245_revision($row);
$second=homeserver_agent_brain_memory_v245_revision($row);
v245_assert(strlen($first)===64 && hash_equals($first,$second),'Cloud revision is deterministic SHA-256');
$row['confidence']=0.92;
v245_assert(!hash_equals($first,homeserver_agent_brain_memory_v245_revision($row)),'Cloud revision changes with durable memory state');

$items=homeserver_agent_brain_memory_v245_homeserver_items(7,'section6',20);
v245_assert(count($items)===1,'tampered HomeServer memory rejected');
$item=$items[0];
v245_assert($item['authority_source']==='homeserver','HomeServer authority preserved');
v245_assert($item['authority_key']==='agent_memory:42','stable native authority key');
v245_assert($item['canonical_id']===homeserver_federated_v240_canonical_id('homeserver','memory','agent_memory:42'),'canonical identity validated');
v245_assert($item['record_revision']===str_repeat('a',64),'native revision preserved');
v245_assert($item['mirror_only']===true,'remote memory is Cloud mirror');
v245_assert($item['mutation_route']==='homeserver_approval','mutation routes home');
v245_assert($item['allowed_mutations']===['update','delete'],'governed mutation set');
v245_assert($item['memory_type']==='semantic' && abs($item['confidence']-0.88)<0.0001,'memory type/confidence preserved');
v245_assert($item['entity_key']==='section6','entity provenance preserved');

v245_assert(count($GLOBALS['v245_calls'])===1,'one remote read');
v245_assert($GLOBALS['v245_calls'][0]['operation']==='tool.execute','memory read uses governed execution runtime');
v245_assert($GLOBALS['v245_calls'][0]['payload']['tool_key']==='memory.list','memory.list tool used');
v245_assert($GLOBALS['v245_calls'][0]['payload']['arguments']['query']==='section6','bounded query forwarded');

v245_assert(count($GLOBALS['v245_observed'])===1,'one valid HomeServer memory observed');
$observed=$GLOBALS['v245_observed'][0]['record'];
v245_assert(($observed['dataset']??'')==='memory','observed as memory dataset');
v245_assert(($observed['canonical_id']??'')===$item['canonical_id'],'observed canonical identity');
v245_assert(($observed['record_revision']??'')===$item['record_revision'],'observed native revision');
v245_assert(!str_contains((string)($observed['content']??''),'HomeServer canonical memory content'),'federation metadata does not persist HomeServer memory body');

echo "HomeServer v2.4 Section 6 Agent Brain & Memory continuity runtime: PASS\n";
