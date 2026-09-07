<?php
declare(strict_types=1);

/**
 * Canonical package state for an Artist-owned Team.
 *
 * Identity/permissions decide who may manage a Team. The package's team_seats
 * entitlement is the sole commercial authority for whether new members may be
 * added and how many seats are available. A NULL limit means unlimited.
 */
function team_subscription_state(?array $user=null,?PDO $pdo=null): array
{
    $user??=current_user();
    $pdo??=db();

    $state=[
        'authorized'=>false,
        'package_id'=>0,
        'package_name'=>'No package',
        'included'=>false,
        'limit'=>0,
        'unlimited'=>false,
        'used'=>0,
        'remaining'=>0,
        'over_limit'=>false,
        'can_add'=>false,
    ];
    if(!$user||(int)($user['id']??0)<1)return $state;

    $isInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user);
    $state['authorized']=$isInternalAdmin||(user_has_role('artist',$user)
        &&has_permission('admin.access',$user)
        &&has_permission('team.manage',$user));

    if($state['authorized']&&$pdo&&table_exists('artist_team_members')&&function_exists('artist_workspace_v104_team_count')){
        try{$state['used']=artist_workspace_v104_team_count($pdo,(int)$user['id']);}catch(Throwable $e){$state['used']=0;}
    }

    // Before the subscription migration, preserve the historical two-seat
    // behavior. Once package storage exists, package data is authoritative.
    if($isInternalAdmin){
        $state['package_name']='Internal Admin';
        $state['included']=true;
        $state['limit']=null;
        $state['unlimited']=true;
    }elseif(!$pdo||!function_exists('subscription_schema_ready')||!subscription_schema_ready($pdo)){
        $state['package_name']='Legacy access';
        $state['included']=true;
        $state['limit']=2;
    }else{
        $subscription=subscription_current_for_user_id((int)$user['id'],$pdo);
        if($subscription){
            $state['package_id']=(int)$subscription['package_id'];
            $state['package_name']=(string)($subscription['package_name']??'Current package');
            $row=subscription_entitlement_row((int)$subscription['package_id'],'team_seats');
            $state['included']=$row&&(int)($row['is_enabled']??0)===1;
            if($state['included']){
                if($row['limit_value']===null){
                    $state['limit']=null;
                    $state['unlimited']=true;
                }else{
                    $state['limit']=max(0,(int)$row['limit_value']);
                }
            }
        }
    }

    if($state['unlimited']){
        $state['remaining']=null;
        $state['over_limit']=false;
    }else{
        $limit=max(0,(int)$state['limit']);
        $state['remaining']=max(0,$limit-(int)$state['used']);
        $state['over_limit']=(int)$state['used']>$limit;
    }
    $state['can_add']=$state['authorized']
        &&$state['included']
        &&($state['unlimited']||(int)$state['used']<(int)$state['limit']);
    return $state;
}

function team_subscription_limit_label(array $state): string
{
    return !empty($state['unlimited'])?'Unlimited':number_format(max(0,(int)($state['limit']??0)));
}
