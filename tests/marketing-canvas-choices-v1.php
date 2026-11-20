<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/initiative-marketing-canvas-choices-v1.php';
$checks=0;$allowed=true;$schema=true;$version=2;$mutateVersion=false;$mutateAuthority=false;
$readCount=0;$canvasCalls=0;$artifactCalls=0;
function c_check(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function c_denied(int $code,callable $attempt,string $label):void{
    try{$attempt();}catch(Throwable $error){c_check($error->getCode()===$code,$label);return;}
    throw new RuntimeException('Expected denial: '.$label);
}
function has_permission(string $permission,array $user):bool{return $permission==='account.access'&&($user['account']??false);}
function url(string $path):string{return '/vp3'.$path;}
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope):?int {
    return $GLOBALS['allowed'] ? (int)$scope['user']['id'] : null;
}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $user,string $capability,bool $lock=false):bool {
    return $GLOBALS['allowed'] && $merchant===7 && $user===1
        && in_array($capability,['campaigns.view','campaigns.edit','analytics.view'],true);
}
function campaigns_rewards_campaign_platform_v100(PDO $pdo,int $campaign,bool $forUpdate=false):?array {
    $GLOBALS['readCount']++;
    $row=$GLOBALS['campaignRow'];
    if($campaign!==(int)$row['id'])return null;
    return $row;
}
function schema_metadata_table_exists(PDO $pdo,string $table):bool {
    return $GLOBALS['schema'];
}
function vp3_marketing_canvas_choices_v1(PDO $pdo,array $scope):array {
    $GLOBALS['canvasCalls']++;
    if(($scope['agent_id']??null)!==7||($scope['conversation_id']??null)!==11)
        throw new DomainException('Wrong Agent or original Chat.',403);
    if($GLOBALS['mutateVersion'])$GLOBALS['campaignRow']['current_version_no']++;
    if($GLOBALS['mutateAuthority'])$GLOBALS['allowed']=false;
    return [
        ['id'=>'01234567-89ab-4cde-8f01-0123456789ab','title'=>'Approved design','version'=>4,
            'attached'=>true,'proof'=>str_repeat('f',64),'private_path'=>'/private/media/secret.png'],
        ['id'=>'01234567-89ab-4cde-8f01-0123456789ab','title'=>'Untested','version'=>0,
            'proof'=>str_repeat('f',64)],
        ['id'=>'javascript:bad','title'=>'Bad','version'=>4,'proof'=>str_repeat('f',64)],
    ];
}
function vp3_marketing_canvas_artifact_v1(PDO $pdo,array $scope,int $campaign,int $version):?array {
    $GLOBALS['artifactCalls']++;
    return ['state'=>'linked','label'=>'Approved design · Version 4',
        'href'=>'/vp3/chat.php?agent=7&conversation_id=11&private=secret'];
}
$pdo=new PDO('sqlite::memory:');
$scope=['user'=>['id'=>1,'account'=>true],'agent_id'=>7,'conversation_id'=>11];
$campaignRow=['id'=>6,'merchant_id'=>7,'merchant_owner_user_id'=>1,'merchant_status'=>'active',
    'merchant_sandbox_mode'=>0,'environment'=>'production','status'=>'active',
    'name'=>'Fall promotion','current_version_no'=>2,'private_customer_notes'=>'sensitive'];
$read=static fn(array $body=['campaign_id'=>6],array $useScope=$scope)=>
    vp3_marketing_workspace_canvas_options_v1($pdo,1,$useScope,$body);
$first=$read()['creative'];
c_check($first['campaign']===['id'=>6,'title'=>'Fall promotion','version'=>2]
    &&count($first['choices'])===1,'only current Campaign and valid tested Canvas versions included');
c_check($first['choices'][0]['attached']===true&&strlen($first['choices'][0]['proof'])===64,
    'exact tested Canvas proof projected to private owner only');
c_check($first['linked']['state']==='linked'&&$first['link_endpoint']==='/vp3/api/marketing-canvas-link-v1.php',
    'link uses the canonical existing private API');
$serialized=json_encode($first,JSON_THROW_ON_ERROR);
c_check(!str_contains($serialized,'private_path')&&!str_contains($serialized,'private_customer_notes')
    &&!str_contains($serialized,'secret.png')&&!str_contains($serialized,'private=secret'),
    'private media paths, customer details and raw artifact links excluded');
$foreign=$scope;$foreign['user']['id']=2;
c_denied(403,fn()=>$read(['campaign_id'=>6],$foreign),'foreign owner rejected');
$wrongChat=$scope;$wrongChat['conversation_id']=12;
c_check($canvasCalls===1,'foreign owner request made no Canvas read');
c_denied(403,fn()=>$read(['campaign_id'=>6],$wrongChat),'different Chat cannot enumerate Canvas versions');
c_denied(422,fn()=>$read(['campaign_id'=>'6']),'string Campaign ID rejected');
c_denied(422,fn()=>$read(['campaign_id'=>0]),'zero Campaign ID rejected');
$campaignRow['merchant_owner_user_id']=2;
c_denied(403,fn()=>$read(),'foreign Campaign denied');
$campaignRow['merchant_owner_user_id']=1;
$campaignRow['environment']='sandbox';
c_denied(403,fn()=>$read(),'sandbox Campaign denied');
$campaignRow['environment']='production';$campaignRow['status']='archived';
c_denied(403,fn()=>$read(),'archived Campaign cannot take new creative link');
$campaignRow['status']='active';
$allowed=false;
c_denied(403,fn()=>$read(),'revoked campaign editor permission denied');
$allowed=true;$schema=false;$before=$canvasCalls;
$unavailable=$read()['creative'];
c_check($unavailable['choices']===[]&&$unavailable['coverage']==='unavailable'
    &&$unavailable['link_endpoint']===null&&$before===$canvasCalls,
    'missing creative schema fails closed without reading Canvas');
$schema=true;$mutateVersion=true;
c_denied(409,fn()=>$read(),'changed Campaign version after creative read rejected');
$mutateVersion=false;$campaignRow['current_version_no']=2;$mutateAuthority=true;
c_denied(403,fn()=>$read(),'revoked owner authority during Canvas selection rejected');
$mutateAuthority=false;
echo 'Marketing Canvas creative selection: '.$checks.' checks passed'.PHP_EOL;
