<?php
declare(strict_types=1);

$GLOBALS['v244_observed']=[];
$GLOBALS['v244_exec_calls']=[];

function url(string $path): string { return $path; }
function homeserver_federated_v240_canonical_id(string $source,string $dataset,mixed $key): string
{
    return 'fd24_'.substr(hash('sha256',$source.'|'.$dataset.'|'.trim((string)$key)),0,40);
}
function homeserver_federated_v240_observe(int $userId,array $record,string $observedSource='vp3_cloud'): array
{
    $GLOBALS['v244_observed'][]=['user_id'=>$userId,'record'=>$record,'observed_source'=>$observedSource];
    return [
      'canonical_id'=>(string)($record['canonical_id']??''),
      'record_revision'=>(string)($record['record_revision']??''),
      'observed_source'=>$observedSource,
    ];
}
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v244_exec_calls'][]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    $validKey='local_file:7';
    $validCanonical=homeserver_federated_v240_canonical_id('homeserver','files',$validKey);
    return ['result'=>['items'=>[
      [
        'ref'=>'hsf-7-0123456789abcdef',
        'name'=>'plan.txt',
        'relative_path'=>'Private/Secret/plan.txt',
        'source_label'=>'Approved Documents',
        'collection_key'=>'projects',
        'collection_name'=>'Projects',
        'kind'=>'text',
        'size_bytes'=>321,
        'updated_at'=>'2026-09-26T14:00:00Z',
        'authority_source'=>'homeserver',
        'authority_key'=>$validKey,
        'canonical_id'=>$validCanonical,
        'record_revision'=>str_repeat('a',64),
        'federation_version'=>'2.4',
        'mirror_only'=>false,
        'allowed_mutations'=>['update','delete'],
        'editable_text'=>true,
      ],
      [
        'ref'=>'hsf-8-fedcba9876543210',
        'name'=>'tampered.txt',
        'relative_path'=>'Should/Never/Appear.txt',
        'source_label'=>'Approved Documents',
        'collection_name'=>'Projects',
        'kind'=>'text',
        'size_bytes'=>10,
        'authority_source'=>'homeserver',
        'authority_key'=>'local_file:8',
        'canonical_id'=>'fd24_'.str_repeat('0',40),
        'record_revision'=>str_repeat('b',64),
        'editable_text'=>true,
      ],
    ]]];
}

require dirname(__DIR__).'/includes/homeserver-files-v244.php';

function v244_assert(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

v244_assert(VP3_HOMESERVER_FILES_V244==='vp3-homeserver-files-v244-20260926','Section 5 build ID');
v244_assert(homeserver_files_v244_matches('project plan','Project launch plan'), 'query matching');
v244_assert(!homeserver_files_v244_matches('project invoice','Project launch plan'), 'query mismatch');

$items=homeserver_files_v244_homeserver_items(7,'plan',20);
v244_assert(count($items)===1,'tampered remote file must be rejected');
$item=$items[0];
v244_assert($item['authority_source']==='homeserver','HomeServer authority');
v244_assert($item['authority_key']==='local_file:7','stable authority key');
v244_assert($item['canonical_id']===homeserver_federated_v240_canonical_id('homeserver','files','local_file:7'),'canonical identity');
v244_assert($item['record_revision']===str_repeat('a',64),'full SHA-256 revision preserved');
v244_assert($item['opaque_ref']==='hsf-7-0123456789abcdef','opaque ref preserved');
v244_assert($item['editable_text']===true,'editable text capability');
v244_assert($item['allowed_mutations']===['update','delete'],'governed mutations projected');
v244_assert($item['mutation_route']==='homeserver_owner_approval','local owner mutation route');
v244_assert($item['mirror_only']===true,'HomeServer record is mirror-only in Cloud');

$encoded=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
v244_assert(is_string($encoded)&&!str_contains($encoded,'Private/Secret'),'relative path must not enter Cloud projection');
v244_assert(!array_key_exists('relative_path',$item),'relative_path key omitted');
v244_assert(!array_key_exists('file_path',$item),'absolute/native file_path key omitted');

v244_assert(count($GLOBALS['v244_exec_calls'])===1,'one files.list execution');
v244_assert($GLOBALS['v244_exec_calls'][0]['operation']==='files.list','files.list routed through execution runtime');
v244_assert($GLOBALS['v244_exec_calls'][0]['payload']['query']==='plan','bounded query forwarded');
v244_assert(count($GLOBALS['v244_observed'])===1,'one valid remote file observed');
$observed=$GLOBALS['v244_observed'][0]['record'];
v244_assert(($observed['dataset']??'')==='files','observed as files dataset');
v244_assert(!str_contains(json_encode($observed,JSON_UNESCAPED_SLASHES),'Private/Secret'),'federation observation omits path');

echo "HomeServer v2.4 Section 5 Files & Document continuity runtime: PASS\n";
