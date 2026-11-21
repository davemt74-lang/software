<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/profile-image-owner-generation-v1.php';
function owner_test(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$GLOBALS['profileOwner']=17;$GLOBALS['grantEnabled']=true;$GLOBALS['sourceChanged']=false;
function profile_by_username(PDO $pdo,string $name): ?array {
    return $name==='dave' ? ['user_id'=>$GLOBALS['profileOwner'],'is_active'=>1] : null;
}
function vp3_profile_image_catalog_public_reference_v1(PDO $pdo,int $owner,int $collection,int $asset,int $revision): array {
    if(!$GLOBALS['grantEnabled']||$owner!==17||$collection!==11||$asset!==201||$revision!==4)
        throw new DomainException('Reference not approved.',404);
    return ['byte_size'=>7,'source_sha256'=>hash('sha256','pixels!'),'source_revision'=>str_repeat('f',64),'mime_type'=>'image/png'];
}
function vp3_image_reference_source_v1(PDO $pdo,array $owner,int $asset,bool $lock=false): array {
    if($GLOBALS['sourceChanged'])return ['asset_id'=>$asset,'sha256'=>hash('sha256','changed'),'revision'=>str_repeat('d',64),
        'mime_type'=>'image/png','bytes'=>'changed','title'=>'Different image'];
    return ['asset_id'=>$asset,'sha256'=>hash('sha256','pixels!'),'revision'=>str_repeat('f',64),
        'mime_type'=>'image/png','bytes'=>'pixels!','title'=>'Saved reference'];
}
function vp3_image_workspace_row_v1(array $row): bool {return ($row['conversation_id']??null)===0;}
function vp3_image_authority_v1(PDO $pdo,array $owner,array $row): array {
    if($row['owner_user_id']!==$owner['id'])throw new DomainException('Wrong owner.',403);
    return $owner;
}
function must_reject(callable $fn,string $label): void {
    try{$fn();}catch(DomainException|InvalidArgumentException|RuntimeException){echo 'PASS '.$label.PHP_EOL;return;}
    throw new RuntimeException('FAIL '.$label);
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('CI must provide pdo_sqlite.');
$pdo=new PDO('sqlite::memory:');
$good=['username'=>'dave','catalog_id'=>11,'catalog_revision'=>4,'image_id'=>201];
$owner=['id'=>17];
owner_test(vp3_profile_owner_image_selection_v1($good)===$good,'only canonical selection fields accepted');
must_reject(fn()=>vp3_profile_owner_image_selection_v1($good+['owner_user_id'=>18]),'client cannot supply owner');
must_reject(fn()=>vp3_profile_owner_image_selection_v1(['username'=>'dave','catalog_id'=>11,'catalog_revision'=>4,'image_id'=>0]),'zero image rejected');
must_reject(fn()=>vp3_profile_owner_image_selection_v1(['username'=>'dave','catalog_id'=>11,'catalog_revision'=>4,'image_id'=>'201']),'numeric string image ID rejected');
must_reject(fn()=>vp3_profile_owner_image_selection_v1(['username'=>'../private','catalog_id'=>11,'catalog_revision'=>4,'image_id'=>201]),'unsafe profile name rejected');
must_reject(fn()=>vp3_profile_owner_image_selection_v1(['username'=>'dave','catalog_id'=>11,'catalog_revision'=>4]),'missing image rejected');
$source=vp3_profile_owner_image_frozen_source_v1($pdo,$owner,$good);
owner_test($source['asset_id']===201&&!array_key_exists('bytes',$source),'private pixels omitted from job config');
$GLOBALS['profileOwner']=18;
must_reject(fn()=>vp3_profile_owner_image_frozen_source_v1($pdo,$owner,$good),'different profile owner cannot borrow image');
$GLOBALS['profileOwner']=17;$GLOBALS['grantEnabled']=false;
must_reject(fn()=>vp3_profile_owner_image_frozen_source_v1($pdo,$owner,$good),'revoked catalog denied');
$GLOBALS['grantEnabled']=true;$GLOBALS['sourceChanged']=true;
must_reject(fn()=>vp3_profile_owner_image_frozen_source_v1($pdo,$owner,$good),'changed pixels are rejected');
$GLOBALS['sourceChanged']=false;
$config=['mode'=>'profile_owner_reference','profile_reference'=>$good,'reference'=>$source];
$row=['conversation_id'=>0,'owner_user_id'=>17];
owner_test(vp3_profile_owner_image_job_source_v1($pdo,$owner,$row,$config)===null,'source recheck returns no pixels by default');
owner_test(vp3_profile_owner_image_job_source_v1($pdo,$owner,$row,$config,false,true)
    ==='data:image/png;base64,'.base64_encode('pixels!'),'only current owner reference pixels reach worker');
must_reject(fn()=>vp3_profile_owner_image_job_source_v1($pdo,$owner,['conversation_id'=>99,'owner_user_id'=>17],$config),'visitor conversation cannot use owner preview job');
$stale=$config;$stale['reference']['sha256']=str_repeat('a',64);
must_reject(fn()=>vp3_profile_owner_image_job_source_v1($pdo,$owner,$row,$stale),'stale frozen image denied before worker');
$GLOBALS['grantEnabled']=false;
must_reject(fn()=>vp3_profile_owner_image_job_source_v1($pdo,$owner,$row,$config,false,true),'revoked grant denied before provider call');
echo "Profile owner image reference authority: all checks passed".PHP_EOL;
