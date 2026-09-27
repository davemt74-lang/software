<?php
declare(strict_types=1);
require __DIR__.'/../includes/tracky-cloud-v270.php';

function ic_fail(string $m): never { fwrite(STDERR,$m."\n"); exit(1); }
function ic_expect(bool $v,string $m): void { if(!$v)ic_fail($m); }
function ic_same(mixed $a,mixed $e,string $m): void { if($a!==$e)ic_fail($m.' actual='.var_export($a,true).' expected='.var_export($e,true)); }
function ic_throws(callable $fn,string $contains,string $m): void {
  try{$fn();}catch(Throwable $e){if($contains===''||str_contains($e->getMessage(),$contains))return;ic_fail($m.' wrong error='.$e->getMessage());}
  ic_fail($m.' did not throw');
}

$home='11111111-1111-4111-8111-111111111111';
$office='22222222-2222-4222-8222-222222222222';
$node='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$canonical='44444444-4444-4444-8444-444444444444';
$link='66666666-6666-4666-8666-666666666666';
$left='site:'.$home.'::person%3Adave';
$right='site:'.$office.'::person%3Adave';

$projection=[
 'protocol'=>'physical_identity_continuity.v1','schema_version'=>1,
 'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,
 'site_local_entities_immutable'=>true,'reversible'=>true,
 'authority_assignment'=>'homeserver_governed',
 'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
 'identities'=>[[
   'canonical_identity_id'=>$canonical,'entity_type'=>'person','status'=>'active',
   'members'=>[$left,$right],'aliases'=>['Dave'],'revision'=>1,'created_at'=>1000,'updated_at'=>1200
 ]],
 'links'=>[[
   'link_id'=>$link,'canonical_identity_id'=>$canonical,'entity_type'=>'person',
   'left_ref'=>$left,'right_ref'=>$right,'status'=>'confirmed','reason'=>'user_confirmed',
   'evidence'=>[[
     'evidence_id'=>'evidence-1','type'=>'user_confirmed','source'=>'user',
     'source_class'=>'user','confidence'=>1,'at'=>1200,'consent_scope'=>[],'metadata'=>[]
   ]],
   'confidence'=>1,'auto_confirmed'=>false,'revision'=>2,'created_at'=>1000,'updated_at'=>1200,
   'confirmed_at'=>1200,'rejected_at'=>null,'revoked_at'=>null,'split_at'=>null,
   'governing_site_id'=>$home,'governing_authority_device_id'=>$node,'governing_authority_epoch'=>3,
   'fingerprint'=>str_repeat('a',64)
 ]],
 'blocked_pairs'=>[]
];

$n=tracky_v278_identity_normalize($projection);
ic_same($n['protocol'],'physical_identity_continuity.v1','protocol');
ic_same($n['identities'][0]['status'],'active','identity status');
ic_same($n['links'][0]['status'],'confirmed','link status');
ic_same($n['links'][0]['governing_site_id'],$home,'governing site');
ic_same($n['links'][0]['governing_authority_epoch'],3,'authority epoch');
ic_expect(strlen($n['links'][0]['semantic_hash'])===64,'semantic hash');
ic_expect($n['site_local_entities_immutable']===true,'local identity mutability leaked');
ic_expect($n['reversible']===true,'reversibility lost');

$proposed=$projection;
$proposed['identities']=[];
$proposed['links'][0]['status']='proposed';
$proposed['links'][0]['revision']=1;
$p=tracky_v278_identity_normalize($proposed);
ic_same($p['identities'][0]['status'],'candidate','candidate identity synthesis');

$bad=$projection;$bad['links'][0]['right_ref']=$left;
ic_throws(fn()=>tracky_v278_identity_normalize($bad),'distinct refs','same ref link accepted');

$bad=$projection;$bad['links'][0]['right_ref']='site:'.$home.'::person%3Aother';
ic_throws(fn()=>tracky_v278_identity_normalize($bad),'must cross sites','same-site link accepted');

$bad=$projection;$bad['links'][0]['governing_site_id']='33333333-3333-4333-8333-333333333333';
ic_throws(fn()=>tracky_v278_identity_normalize($bad),'must be one of the linked member sites','unrelated governing site accepted');

$bad=$projection;$bad['links'][0]['evidence'][0]['embedding']=[1,2,3];
ic_throws(fn()=>tracky_v278_identity_normalize($bad),'local-only perception data','raw biometric evidence accepted');

$bad=$projection;$bad['cloud_can_confirm_links']=true;
ic_throws(fn()=>tracky_v278_identity_normalize($bad),'cannot receive identity decision authority','Cloud confirm authority accepted');

$cap=tracky_v278_identity_public_capability();
ic_same($cap['version'],'2.78','version');
ic_same($cap['cloud_role'],'mirror_relay_only','Cloud role');
ic_expect($cap['world_mutation_authority']===false,'Cloud mutation authority leaked');
ic_expect($cap['cloud_can_confirm_links']===false,'Cloud confirm authority leaked');
ic_expect($cap['cloud_can_merge_identities']===false,'Cloud merge authority leaked');
ic_expect($cap['cloud_can_split_identities']===false,'Cloud split authority leaked');

$caps=tracky_cloud_v270_capabilities(['identity_continuity'=>true,'identity_continuity_protocol'=>'physical_identity_continuity.v1']);
ic_expect($caps['identity_continuity']===true,'identity capability missing');
ic_same($caps['identity_continuity_protocol'],'physical_identity_continuity.v1','identity protocol');
$health=tracky_cloud_v270_health(['identity_continuity'=>'available']);
ic_same($health['identity_continuity'],'available','identity health');

echo "TRACKY_V278_IDENTITY_CONTINUITY_UNIT=PASS\n";
