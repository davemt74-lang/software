<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/initiative-marketing-product-media-v1.php';

$checks=0;$allowed=true;$denyMedia=false;$revokeDuringRead=false;
$reads=0;$scopeReads=0;$writes=0;
function p_check(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    $GLOBALS['checks']++;echo 'PASS '.$name.PHP_EOL;
}
function p_denied(int $status,callable $fn,string $name):void{
    try{$fn();}catch(Throwable $e){p_check($e->getCode()===$status,$name);return;}
    throw new RuntimeException('Expected denial '.$name);
}
function has_permission(string $name,array $user):bool {
    return $name==='account.access'&&!empty($user['account']);
}
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope):?int {
    return $GLOBALS['allowed']?(int)($scope['user']['id']??0):null;
}
function initiative_work_product_scope_v1(PDO $pdo,int $owner,array $scope):array {
    $GLOBALS['scopeReads']++;
    if(($scope['agent_id']??null)!==7||($scope['conversation_id']??null)!==11
        ||$owner!==(int)($scope['user']['id']??0))
        throw new DomainException('Original Agent Chat unavailable.',403);
    return $scope['user'];
}
function initiative_work_marketing_products_v1(PDO $pdo,int $owner,array $scope):array {
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    $GLOBALS['reads']++;
    return [
      ['id'=>12,'title'=>'Coffee package','private_notes'=>'Do not display'],
      ['id'=>12,'title'=>'Duplicate item'],
      ['id'=>25,'title'=>'Gift bundle'],
      ['id'=>0,'title'=>'Invalid product'],
    ];
}
function initiative_work_marketing_sources_v1(PDO $pdo,int $owner,array $scope):array {
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    $GLOBALS['reads']++;
    if($GLOBALS['revokeDuringRead'])$GLOBALS['allowed']=false;
    if($GLOBALS['denyMedia'])throw new DomainException('Media permission revoked.',403);
    return [
      ['source_id'=>'16','source_title'=>'Package photo','file_path'=>'/private/image-16.png','private_url'=>'/private/photo'],
      ['source_id'=>'17','source_title'=>'Second photo'],
      ['source_id'=>'17','source_title'=>'Duplicate photo'],
      ['source_id'=>'description','source_title'=>'Not a saved photo'],
      ['source_id'=>'0018','source_title'=>'Noncanonical media source'],
      ['source_id'=>'0','source_title'=>'Invalid photo'],
    ];
}
$pdo=new PDO('sqlite::memory:');
$scope=['user'=>['id'=>1,'account'=>true],'agent_id'=>7,'conversation_id'=>11];
$read=static fn(?array $sc=null,int $owner=1)=>
    vp3_marketing_product_media_choices_v1($pdo,$owner,$sc??$scope);
$first=$read()['selection'];
p_check($first['coverage']==='ready'&&$first['action']==='private_marketing_draft'
    &&$first['will_publish']===false,'selection is read-only and cannot publish');
p_check($first['products']===[['id'=>12,'title'=>'Coffee package'],['id'=>25,'title'=>'Gift bundle']],
    'only bounded owned product titles and IDs survive normalization');
p_check($first['media']===[['id'=>'16','title'=>'Package photo'],['id'=>'17','title'=>'Second photo']],
    'private Media Library returns canonical saved photo titles and IDs only');
$encoded=json_encode($first,JSON_THROW_ON_ERROR);
p_check(!str_contains($encoded,'file_path')&&!str_contains($encoded,'private_notes')
    &&!str_contains($encoded,'private_url')&&!str_contains($encoded,'/private'),
    'no private file paths, tokens, URLs, customer notes or binary media leak');
p_check($writes===0,'selection does not create products, media, messages or campaigns');
$before=$reads;$foreign=$scope;$foreign['user']['id']=2;
p_denied(403,fn()=>$read($foreign),'owner mismatch fails closed before source reads');
p_check($reads===$before,'foreign owner never invokes private product or Media services');
$otherChat=$scope;$otherChat['conversation_id']=14;
p_denied(403,fn()=>$read($otherChat),'different saved Chat cannot use these selections');
$otherAgent=$scope;$otherAgent['agent_id']=8;
p_denied(403,fn()=>$read($otherAgent),'foreign Agent cannot enumerate media');
$account=$scope;$account['user']['account']=false;
p_denied(403,fn()=>$read($account),'account permission must remain active');
$allowed=false;
p_denied(403,fn()=>$read(),'Marketing owner permission revocation blocks read');
$allowed=true;$denyMedia=true;
p_denied(403,fn()=>$read(),'Media source access revocation is not silently replaced by empty list');
$denyMedia=false;$revokeDuringRead=true;
p_denied(403,fn()=>$read(),'mid-read owner revocation discards all selections');
$allowed=true;$revokeDuringRead=false;
p_check($read()['selection']['media'][0]['id']==='16','authorized owner can recover on a fresh request');
echo "Marketing product/media choices: $checks checks passed".PHP_EOL;
