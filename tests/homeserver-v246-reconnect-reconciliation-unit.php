<?php
declare(strict_types=1);

function db(){ return null; }

require dirname(__DIR__).'/includes/homeserver-federated-data-v240.php';
require dirname(__DIR__).'/includes/homeserver-reconciliation-v246.php';

function v246_assert(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

v246_assert(
    VP3_HOMESERVER_RECONCILIATION_V246==='vp3-homeserver-reconciliation-v246-20260926',
    'Section 7 build ID'
);

$revision=str_repeat('a',64);
$row=[
  'authority_source'=>'homeserver',
  'authority_key'=>'agent_memory:42',
  'canonical_id'=>homeserver_federated_v240_canonical_id('homeserver','memory','agent_memory:42'),
  'record_revision'=>$revision,
  'title'=>'Memory',
  'content'=>'Private body is not used to recalculate a supplied native revision.',
  'updated_at'=>'2026-09-26T12:00:00Z',
];
$normalized=homeserver_federated_v240_normalize($row,'homeserver','memory',0);
v246_assert($normalized['canonical_id']===$row['canonical_id'],'canonical identity preserved');
v246_assert($normalized['record_revision']===$revision,'authoritative native revision preserved');
v246_assert($normalized['mirror_only']===true,'HomeServer record remains a Cloud mirror');

$bad=$row;
$bad['record_revision']='not-a-sha';
try{
    homeserver_federated_v240_normalize($bad,'homeserver','memory',0);
    v246_assert(false,'malformed native revision should fail');
}catch(RuntimeException $e){
    v246_assert(str_contains($e->getMessage(),'revision'),'malformed revision error');
}

$badIdentity=$row;
$badIdentity['canonical_id']='fd24_'.str_repeat('0',40);
try{
    homeserver_federated_v240_normalize($badIdentity,'homeserver','memory',0);
    v246_assert(false,'canonical identity mismatch should fail');
}catch(RuntimeException $e){
    v246_assert(str_contains($e->getMessage(),'canonical identity'),'canonical mismatch error');
}

v246_assert(homeserver_federated_v240_dataset('FILES')==='files','dataset normalization');
v246_assert(homeserver_federated_v240_source('HOMESERVER')==='homeserver','authority normalization');
v246_assert(strlen(homeserver_federated_v240_canonical_id('vp3_cloud','contacts','contact:1'))===45,'canonical ID length');
v246_assert(
    homeserver_reconciliation_v246_text("  one\n two   three  ",20)==='one two three',
    'reconciliation metadata is whitespace bounded'
);

$source=file_get_contents(dirname(__DIR__).'/includes/homeserver-reconciliation-v246.php');
v246_assert(is_string($source)&&str_contains($source,"if(\$mode==='full')"),'full-only absence branch exists');
v246_assert(str_contains($source,'covered_datasets'),'explicit coverage contract exists');
v246_assert(str_contains($source,'Prevalidate every row before touching mirrors'),'prevalidation contract exists');
v246_assert(str_contains($source,'homeserver_federated_v240_mark_tombstone'),'absence tombstone path exists');
v246_assert(str_contains($source,'homeserver_reconciliation_v246_should_retry'),'bounded retry path exists');

echo "HomeServer v2.4 Section 7 reconnect reconciliation runtime: PASS\n";
