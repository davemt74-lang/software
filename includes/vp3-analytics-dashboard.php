<?php
declare(strict_types=1);

function vp3_analytics_dashboard_state_v2(PDO $pdo,array $user,int $propertyId=0,int $days=30): array
{
    $uid=(int)($user['id']??0);$days=max(1,min(90,$days));
    if($uid<1)return ['ready'=>false];

    // A user's VP3 profile is always a first-class Analytics property, even if
    // no automated agent has visited yet to create the native Radar property.
    if(function_exists('vp3_radar_native_property'))vp3_radar_native_property($pdo,$uid);
    $state=vp3_analytics_dashboard_state($pdo,$user,$propertyId,$days);
    if(empty($state['ready']))return $state;

    $nativeId=vp3_analytics_native_property_id($pdo,$uid);
    $includeNative=(int)($state['property_id']??0)===0||($nativeId>0&&(int)$state['property_id']===$nativeId);
    if(!$includeNative||!table_exists('profile_visit_sessions'))return $state;

    $since="DATE_SUB(NOW(),INTERVAL {$days} DAY)";
    $stmt=$pdo->prepare("SELECT COUNT(*) sessions,COALESCE(SUM(view_count),0) views FROM profile_visit_sessions WHERE owner_user_id=? AND last_seen_at>={$since}");
    $stmt->execute([$uid]);$row=$stmt->fetch()?:[];
    $nativeSessions=(int)($row['sessions']??0);$nativeViews=(int)($row['views']??0);

    foreach($state['property_stats'] as &$property){
        if((int)($property['id']??0)!==$nativeId)continue;
        $property['human_sessions']=$nativeSessions;
        $property['human_page_views']=$nativeViews;
        $property['sessions']=(int)($property['sessions']??0)+$nativeSessions;
        $property['page_views']=(int)($property['page_views']??0)+$nativeViews;
    }
    unset($property);

    if($nativeViews>0){
        $found=false;
        foreach($state['top_pages'] as &$page){
            if((string)($page['path']??'')!=='VP3 Profile')continue;
            $page['events']=(int)($page['events']??0)+$nativeViews;
            $page['human_events']=(int)($page['human_events']??0)+$nativeViews;
            $found=true;break;
        }
        unset($page);
        if(!$found)$state['top_pages'][]=['path'=>'VP3 Profile','events'=>$nativeViews,'human_events'=>$nativeViews,'agent_events'=>0];
        usort($state['top_pages'],static fn(array $a,array $b):int=>(int)($b['events']??0)<=>(int)($a['events']??0));
        $state['top_pages']=array_slice($state['top_pages'],0,20);
    }
    return $state;
}
