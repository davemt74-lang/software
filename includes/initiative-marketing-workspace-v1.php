<?php
declare(strict_types=1);

/**
 * Phase 2A: one owner-private, read-only projection for the Marketing Chat Canvas.
 * Campaign status and permission always come from canonical Campaigns & Rewards.
 * This endpoint does not create, publish, message, transfer, or schedule anything.
 */
function vp3_initiative_marketing_workspace_overview_v1(PDO $pdo,int $owner,array $scope): array
{
    $empty=['coverage'=>'unconfigured','campaigns'=>[],'links'=>['campaigns'=>null,'products'=>null,'rewards'=>null]];
    if($owner<1 || (int)($scope['user']['id']??0)!==$owner
        || !function_exists('has_permission') || !has_permission('account.access',$scope['user']))return $empty;
    if(!function_exists('vp3_coordination_marketing_owner_v1'))require_once __DIR__.'/agent-coordination-marketing-v1.php';
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner)return $empty;
    if(!function_exists('vp3_marketing_review_v1'))require_once __DIR__.'/agent-marketing-review-v1.php';
    $report=vp3_marketing_review_v1($pdo,$scope,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,false,true);
    $campaigns=[];
    foreach(array_slice((array)($report['campaigns']??[]),0,5) as $item) {
        if(!is_array($item)||(int)($item['id']??0)<1
            ||!in_array($item['status']??null,['draft','scheduled','active','paused','completed','archived'],true))continue;
        $issues=[];
        foreach((array)($item['readiness']['checks']??[]) as $check) {
            if(!is_array($check)||!in_array($check['state']??null,['missing','limited'],true))continue;
            $detail=$check['detail']??null;
            if(is_string($detail)&&trim($detail)!=='')$issues[]=mb_substr(trim($detail),0,240);
            if(count($issues)>=4)break;
        }
        $observations=[];
        foreach(['claims','redemptions','promotion'] as $key) {
            $state=$item['outcomes']['observations'][$key]['state']??null;
            $observations[$key]=in_array($state,['observed','unavailable','unconfigured','limited','not_started'],true)
                ?$state:'unavailable';
        }
        $campaigns[]=[
            'id'=>(int)$item['id'],
            'name'=>mb_substr((string)($item['name']??''),0,160),
            'business'=>mb_substr((string)($item['business']??''),0,140),
            'status'=>$item['status'],
            'version'=>max(0,(int)($item['version']??0)),
            'href'=>is_string($item['href']??null)?$item['href']:null,
            'readiness'=>in_array($item['readiness']['state']??null,['ready','limited','needs_setup'],true)
                ?$item['readiness']['state']:'unavailable',
            'issues'=>$issues,
            'window_state'=>in_array($item['outcomes']['window_state']??null,['open','closed','upcoming','not_open'],true)
                ?$item['outcomes']['window_state']:'unavailable',
            'observations'=>$observations,
        ];
    }
    // Revalidate current owner/plugin authority after the read, including empty or revoked businesses.
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner)return $empty;
    return [
        'coverage'=>in_array($report['coverage']??null,['ready','limited','unavailable','unconfigured'],true)
            ?$report['coverage']:'unavailable',
        'campaigns'=>$campaigns,
        'links'=>[
            'campaigns'=>url('/campaigns.php'),
            'products'=>url('/profile-commerce-products.php'),
            'rewards'=>url('/rewards.php'),
        ],
    ];
}
