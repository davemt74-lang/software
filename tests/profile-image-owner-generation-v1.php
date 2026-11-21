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
owner_test(($source['byte_size']??null)===7,'frozen image metadata carries exact byte size required by provider');
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
require_once dirname(__DIR__).'/includes/agent-image-provider-v1.php';
$jpeg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAQDAwMDAgQDAwMEBAQFBgoGBgUFBgwICQcKDgwPDg4MDQ0PERYTDxAVEQ0NExoTFRcYGRkZDxIbHRsYHRYYGRj/2wBDAQQEBAYFBgsGBgsYEA0QGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBgYGBj/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erxvP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDPooor85P1A//Z',true);
owner_test(is_string($jpeg)&&getimagesizefromstring($jpeg)['mime']==='image/jpeg','fixture is a real image, not fake bytes');
$reference=['mime_type'=>'image/jpeg','byte_size'=>strlen($jpeg),'sha256'=>hash('sha256',$jpeg)];
vp3_image_profile_reference_check_v1('data:image/jpeg;base64,'.base64_encode($jpeg),$reference,true);
owner_test(true,'provider accepts real image with complete frozen reference metadata');
$withoutSize=$reference;unset($withoutSize['byte_size']);
must_reject(fn()=>vp3_image_profile_reference_check_v1('data:image/jpeg;base64,'.base64_encode($jpeg),$withoutSize,true),
    'provider rejects frozen image metadata without byte size');
echo "Profile owner image reference authority: all checks passed".PHP_EOL;
