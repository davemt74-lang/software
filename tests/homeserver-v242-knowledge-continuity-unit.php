<?php
declare(strict_types=1);

$GLOBALS['v242_runs']=[];
$GLOBALS['v242_observed']=[];
$GLOBALS['v242_governed']=[];

function homeserver_shared_v210_matches(string $query,string $text): bool
{
    foreach(preg_split('/\\s+/u',mb_strtolower(trim($query)))?:[] as $term){
        if($term!==''&&!str_contains(mb_strtolower($text),$term))return false;
    }
    return true;
}
function homeserver_federated_v240_canonical_id(string $source,string $dataset,mixed $key): string
{
    return 'fd24_'.substr(hash('sha256',strtolower(trim($source)).'|'.strtolower(trim($dataset)).'|'.trim((string)$key)),0,40);
}
function homeserver_federated_v240_revision(string $title,string $content,?string $updatedAt=null): string
{
    return hash('sha256',$title.'|'.$content.'|'.($updatedAt??''));
}
function homeserver_federated_v240_envelope(string $source,string $dataset,mixed $key,string $title='',string $content='',?string $updatedAt=null): array
{
    return [
      'federation_version'=>'2.4',
      'canonical_id'=>homeserver_federated_v240_canonical_id($source,$dataset,$key),
      'authority_source'=>$source,'authority_key'=>(string)$key,'dataset'=>$dataset,
      'title'=>$title,'content'=>$content,'updated_at'=>$updatedAt,
      'record_revision'=>homeserver_federated_v240_revision($title,$content,$updatedAt),
      'mirror_only'=>$source!=='vp3_cloud',
    ];
}
function homeserver_federated_v240_observe(int $userId,array $record,string $observedSource='vp3_cloud'): array
{
    $GLOBALS['v242_observed'][]=[$userId,$record,$observedSource];
    return ['canonical_id'=>(string)($record['canonical_id']??'')];
}
function homeserver_execution_v220_can_route(int $userId,string $operation): bool
{
    return $userId===7&&$operation==='knowledge.search';
}
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v242_runs'][]=[$userId,$operation,$payload];
    if($operation!=='knowledge.search')throw new RuntimeException('unexpected operation');
    $key='knowledge_item:9';
    $canonical=homeserver_federated_v240_canonical_id('homeserver','knowledge',$key);
    return ['ok'=>true,'result'=>[
      'items'=>[[
        'id'=>9,'title'=>'Local launch plan','kind'=>'note','snippet'=>'Phoenix local launch notes',
        'collection_key'=>'general','source_type'=>'local_item','updated_at'=>'2026-09-25 12:00:00',
        'authority_source'=>'homeserver','authority_key'=>$key,'canonical_id'=>$canonical,
        'record_revision'=>str_repeat('a',64),'federation_version'=>'2.4','read_only'=>false,
        'allowed_mutations'=>['update','delete'],
        'citation'=>[
          'id'=>'hs-knowledge:9:0:abc','uri'=>'homeserver://knowledge/9?chunk=0&version=abc',
          'title'=>'Local launch plan','kind'=>'note','collection_key'=>'general',
          'collection_name'=>'General','source_type'=>'local_item','source_label'=>'HomeServer Knowledge',
          'relative_path'=>'folder/private.md','chunk_index'=>0,'char_start'=>0,'char_end'=>30,'version'=>'abc',
        ],
      ]],
      'count'=>1,
    ],'execution'=>['operation'=>'knowledge.search','route'=>'homeserver']];
}
function homeserver_governed_v233_request(int $userId,string $tool,array $payload): array
{
    $GLOBALS['v242_governed'][]=[$userId,$tool,$payload];
    return ['ok'=>true,'status'=>'pending_approval','tool'=>$tool,'request_id'=>'req-knowledge-123'];
}
function db(){return null;}
function table_exists(string $table): bool{return false;}
function column_exists(string $table,string $column): bool{return false;}

function personal_knowledge_available(?array $user=null): bool{return false;}
require dirname(__DIR__).'/includes/homeserver-knowledge-v242.php';
require dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';

function v242_assert(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$home=homeserver_knowledge_v242_homeserver_items(7,'Phoenix',6);
v242_assert(count($home)===1,'HomeServer search count');
v242_assert($home[0]['authority_source']==='homeserver','HomeServer authority');
v242_assert($home[0]['authority_key']==='knowledge_item:9','HomeServer authority key');
v242_assert($home[0]['canonical_id']===homeserver_federated_v240_canonical_id('homeserver','knowledge','knowledge_item:9'),'HomeServer canonical');
v242_assert($home[0]['mutation_route']==='homeserver_governed','HomeServer governed mutation route');
v242_assert($GLOBALS['v242_runs'][0][1]==='knowledge.search','read uses knowledge.search');

$agent=homeserver_knowledge_v242_agent_search(7,'Phoenix',6);
v242_assert($agent['status']==='queried','Agent HomeServer search status');
v242_assert(count($agent['items'])===1,'Agent HomeServer result count');
v242_assert(($agent['items'][0]['source']??'')==='homeserver','Agent HomeServer provenance');
v242_assert(($agent['items'][0]['citation']['relative_path']??'')==='folder/private.md','native adapter may retain relative citation path internally');

$cloud=[
  ['source'=>'cloud','canonical_id'=>homeserver_federated_v240_canonical_id('vp3_cloud','knowledge','knowledge:2'),'title'=>'Cloud plan'],
  ['source'=>'cloud','canonical_id'=>homeserver_federated_v240_canonical_id('vp3_cloud','knowledge','knowledge:3'),'title'=>'Cloud backup'],
];
$merged=homeserver_knowledge_v242_merge_retrieval($cloud,$agent['items'],3);
v242_assert(count($merged)===3,'merged result count');
v242_assert(($merged[0]['source']??'')==='cloud','round-robin starts with Cloud');
v242_assert(($merged[1]['source']??'')==='homeserver','round-robin includes HomeServer before second Cloud');

$unavailable=homeserver_knowledge_v242_agent_search(8,'Phoenix',6);
v242_assert($unavailable['status']==='unavailable'&&$unavailable['items']===[],'unrouteable HomeServer status');

$cloudCanonical=homeserver_federated_v240_canonical_id('vp3_cloud','knowledge','knowledge:2');
$blocked=false;
try{
    homeserver_knowledge_v242_request_homeserver(7,'update',[
      'canonical_id'=>$cloudCanonical,'mutation_id'=>'cloud-update-001','expected_revision'=>str_repeat('b',64),'title'=>'Nope'
    ]);
}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'not a writable HomeServer-native record');}
v242_assert($blocked,'Cloud canonical must never route to HomeServer mutation');

$create=homeserver_knowledge_v242_request_homeserver(7,'create',[
  'mutation_id'=>'local-create-001','title'=>'New Local','content'=>'Knowledge body','kind'=>'note','collection_key'=>'general'
]);
v242_assert($create['status']==='pending_approval','HomeServer create is governed');
v242_assert($GLOBALS['v242_governed'][0][1]==='knowledge.create','HomeServer create tool');

$pdo=new PDO('sqlite::memory:');
$retrieved=knowledge_retrieval_v162_for_chat(
  $pdo,
  ['id'=>7],
  ['kind'=>'system','viewer_user_id'=>7,'owner_user_id'=>0],
  'Phoenix',
  'off',
  []
);
v242_assert(($retrieved['homeserver_local_knowledge']??'')==='queried','HomeServer retrieval runs when Cloud Knowledge scope is off');
v242_assert(($retrieved['provenance']??[])===['homeserver'],'HomeServer-only provenance');
v242_assert(count($retrieved['citations']??[])===1,'HomeServer citation count');
v242_assert(($retrieved['citations'][0]['source']??'')==='homeserver','HomeServer citation provenance');
v242_assert(($retrieved['citations'][0]['canonical_id']??'')===$home[0]['canonical_id'],'HomeServer citation canonical identity');
v242_assert(!array_key_exists('homeserver_relative_path',$retrieved['citations'][0]),'relative path excluded from public citation');
v242_assert(str_contains((string)($retrieved['context'][0]['text']??''),'Federated Knowledge — UNTRUSTED EVIDENCE'),'federated evidence context');
v242_assert(str_contains((string)($retrieved['context'][0]['text']??''),'HomeServer'),'HomeServer evidence label');

echo "HomeServer v2.4 Section 3 Knowledge continuity runtime: PASS\n";
