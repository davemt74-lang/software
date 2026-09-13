<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/homeserver-knowledge-v062.php';

$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$collection=homeserver_knowledge_v062_collection([
    'collection_key'=>'private-work',
    'name'=>str_repeat('A',180),
    'description'=>'Scoped private collection',
    'source_count'=>4,
    'direct_item_count'=>9,
    'path'=>'C:\\Users\\owner\\Private',
]);
$check($collection['collection_key']==='private-work','Collection key projection changed unexpectedly.');
$check(mb_strlen($collection['name'])===120,'Collection name must be bounded.');
$check(!array_key_exists('path',$collection),'Collection projection leaked an unapproved key.');

$mapping=homeserver_knowledge_v062_mapping([
    'mapping_id'=>'source-42',
    'label'=>'Private Work',
    'collection_key'=>'private-work',
    'collection_name'=>'Private Work',
    'enabled'=>true,
    'recursive'=>true,
    'scan_interval_seconds'=>5,
    'status'=>'ready',
    'tracked_files'=>18,
    'indexed_files'=>17,
    'error_files'=>1,
    'path'=>'C:\\Users\\owner\\Private',
    'absolute_path'=>'C:\\Users\\owner\\Private',
]);
$check($mapping['mapping_id']==='source-42','Opaque mapping ID was not preserved.');
$check($mapping['scan_interval_seconds']===30,'Scan interval must be clamped to HomeServer contract minimum.');
$check(!array_key_exists('path',$mapping)&&!array_key_exists('absolute_path',$mapping),'Mapping projection leaked native location metadata.');

$invalid=homeserver_knowledge_v062_mapping(['mapping_id'=>'../private','label'=>'bad']);
$check($invalid['mapping_id']==='','Invalid mapping ID must be discarded.');

$json=json_encode(['collection'=>$collection,'mapping'=>$mapping],JSON_UNESCAPED_SLASHES);
$check(is_string($json)&&!str_contains($json,'Users\\owner'),'Safe projections must not serialize source locations.');

if($failures){foreach($failures as $failure)fwrite(STDERR,"FAIL: {$failure}\n");exit(1);}
echo "HomeServer Knowledge v0.62 safe projection tests passed\n";
