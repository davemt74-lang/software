<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/initiative-marketing-workspace-v1.php';

function has_permission(string $key,array $user): bool { return $key==='account.access' && ($user['account']??false); }
function url(string $path): string { return '/vp3'.$path; }
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope): ?int {
    $GLOBALS['authorityReads']++;
    if(!($GLOBALS['authorized']??true)||($GLOBALS['revokeOnRead']??0)===$GLOBALS['authorityReads'])return null;
    return (int)$scope['user']['id'];
}
function vp3_marketing_review_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $refs=null,
    bool $includeArtifacts=false,bool $includeChoices=false): array {
    $GLOBALS['reportReads']++;
    if($includeArtifacts||!$includeChoices)throw new RuntimeException('Review must not include private artifacts or disable create capability lookup.');
    return $GLOBALS['fixture'];
}
function review_check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo "PASS ".$label.PHP_EOL;
}
$checks=0;$authorityReads=0;$reportReads=0;$authorized=true;$revokeOnRead=0;
$pdo=new PDO('sqlite::memory:');
$scope=['user'=>['id'=>11,'account'=>true],'agent_id'=>7,'conversation_id'=>42];
$fixture=[
    'coverage'=>'ready',
    'campaigns'=>[
        ['id'=>5,'name'=>'Autumn Launch','business'=>'Shop','status'=>'active','version'=>3,
            'href'=>'/vp3/campaigns.php?merchant=17&edit_campaign=5',
            'readiness'=>['state'=>'needs_setup','checks'=>[
                'reward'=>['state'=>'missing','detail'=>'Choose an active reward'],
                'audience'=>['state'=>'ready','detail'=>'Ready']]],
            'window_state'=>'open','outcomes'=>['observations'=>[
                'claims'=>['state'=>'observed','metrics'=>['reward_redemptions'=>42,'customer_email'=>'sensitive'],
                    'window_start'=>'2026-11-01T00:00:00+00:00','window_end'=>'2026-11-20T00:00:00+00:00'],
                'redemptions'=>['state'=>'unavailable'],
                'promotion'=>['state'=>'observed','metrics'=>['clicks'=>18,'checkout_starts'=>3,'paid_orders'=>0,'refunded_orders'=>0,
                    'customer_email'=>'sensitive'],'window_start'=>'2026-11-10T00:00:00+00:00']],
            'decisions'=>[['id'=>12,'type'=>'fatigue','status'=>'proposed','summary'=>'sensitive'],
                ['id'=>13,'type'=>'unsupported','status'=>'proposed']]]],
        ['id'=>6,'name'=>'Paused Offer','business'=>'Shop','status'=>'paused','version'=>1,
            'href'=>'/vp3/campaigns.php?merchant=17&edit_campaign=6','readiness'=>['state'=>'limited','checks'=>[]]],
        ['id'=>7,'name'=>'Unpublished','business'=>'Shop','status'=>'internal_secret',
            'private_contact'=>'should-not-leak'],
    ],
    'secret'=>'customer-token',
];
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['coverage']==='ready'&&count($out['campaigns'])===2,'only allowlisted owner Campaign statuses returned');
review_check($out['campaigns'][0]['issues']===['Choose an active reward'],'only setup reasons projected');
review_check($out['campaigns'][0]['window_state']==='open','canonical campaign observation window preserved');

$performance=$out['campaigns'][0]['performance'];
review_check($performance['sources']['promotion']['metrics']===['clicks'=>18,'checkout_starts'=>3,'paid_orders'=>0,'refunded_orders'=>0],
    'recorded Profile promotion performance is projected without private fields');
review_check($performance['sources']['claims']['metrics']===['reward_redemptions'=>42]
    &&$performance['sources']['redemptions']['state']==='unavailable',
    'claim receipts and absent snapshots are independently attributed');
review_check($performance['sources']['claims']['window']['start']==='2026-11-01T00:00:00+00:00',
    'original verified observation window is preserved');
review_check($performance['decisions']===[['id'=>12,'type'=>'fatigue','status'=>'proposed']],
    'only recognized human-review decision metadata is released');

review_check($out['campaigns'][0]['observations']===['claims'=>'observed','redemptions'=>'unavailable','promotion'=>'observed'],
    'analytics coverage is observational rather than invented counts');
review_check(!str_contains(json_encode($out,JSON_THROW_ON_ERROR),'sensitive')
    &&!str_contains(json_encode($out,JSON_THROW_ON_ERROR),'customer-token')
    &&!str_contains(json_encode($out,JSON_THROW_ON_ERROR),'should-not-leak'),'no CRM or private campaign details');
$fixture['campaigns'][0]['outcomes']['observations']['promotion']['metrics']['paid_orders']=null;
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['campaigns'][0]['performance']['sources']['promotion']['state']==='unavailable'
    &&$out['campaigns'][0]['performance']['sources']['promotion']['metrics']===[],
    'incomplete observed metrics fail closed rather than inventing zero');
$fixture['campaigns'][0]['outcomes']['observations']['promotion']['state']='unobserved';
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['campaigns'][0]['performance']['sources']['promotion']['state']==='unobserved'
    &&$out['campaigns'][0]['performance']['sources']['promotion']['metrics']===[],
    'unobserved metrics do not masquerade as measured zero');
review_check($out['campaigns'][1]['readiness']==='limited'&&$out['campaigns'][1]['window_state']==='unavailable',
    'missing readiness and window metrics remain unavailable');
review_check($out['links']===['campaigns'=>'/vp3/campaigns.php','products'=>'/vp3/profile-commerce-products.php','rewards'=>'/vp3/rewards.php'],
    'canonical existing editor routes reused');
$reportReads=0;$out=vp3_initiative_marketing_workspace_overview_v1($pdo,12,$scope);
review_check($out['campaigns']===[]&&$out['links']['campaigns']===null&&$reportReads===0,'owner mismatch denies all data before review');
$reportReads=0;$denied=$scope;$denied['user']['account']=false;
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$denied);
review_check($out['campaigns']===[]&&$out['links']['products']===null&&$reportReads===0,'account permission denies all links and review');
$reportReads=0;$authorized=false;
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['campaigns']===[]&&$reportReads===0,'revoked Marketing permission fails closed');
$authorized=true;$authorityReads=0;$reportReads=0;$revokeOnRead=2;
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['campaigns']===[]&&$out['links']['rewards']===null,'permission revocation during review discards all data');
$revokeOnRead=0;$authorityReads=0;
$fixture=['coverage'=>'unavailable','campaigns'=>[]];
$out=vp3_initiative_marketing_workspace_overview_v1($pdo,11,$scope);
review_check($out['coverage']==='unavailable'&&$out['campaigns']===[],'unavailable reporting is never counted as zero');
echo "Marketing workspace owner-private read: $checks checks passed".PHP_EOL;
