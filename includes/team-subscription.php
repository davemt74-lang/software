<?php
declare(strict_types=1);

/**
 * Canonical commercial state for a VP3-owned collaborative workspace.
 *
 * Identity/permissions decide who may manage a Team. Product entitlements decide
 * capacity only. The base package and active add-on grants compose through the
 * canonical subscription resolver; a NULL limit means unlimited.
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
    $workspaceOwner=false;
    if(function_exists('music_workspace_enabled_v320'))$workspaceOwner=music_workspace_enabled_v320($user);
    if(!$workspaceOwner&&function_exists('artist_workspace_v104_is_artist'))$workspaceOwner=artist_workspace_v104_is_artist($user);
    $canManage=function_exists('music_workspace_owner_permission_v320')
        ? music_workspace_owner_permission_v320('team.manage',$user)
        : has_permission('team.manage',$user);

    $state['authorized']=$isInternalAdmin||($workspaceOwner&&$canManage);

    if($state['authorized']&&$pdo&&table_exists('artist_team_members')){
        try{
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id AND u.is_active=1 WHERE atm.artist_user_id=?');
            $stmt->execute([(int)$user['id']]);
            $state['used']=(int)$stmt->fetchColumn();
        }catch(Throwable $e){$state['used']=0;}
    }

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
        }
        $state['included']=subscription_has_entitlement($user,'team_seats');
        if($state['included']){
            $limit=subscription_entitlement_limit($user,'team_seats',0);
            if($limit===null){
                $state['limit']=null;
                $state['unlimited']=true;
            }else{
                $state['limit']=max(0,$limit);
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