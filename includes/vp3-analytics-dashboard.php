<?php
declare(strict_types=1);

function vp3_analytics_enrich_site_state(PDO $pdo,array $user,array $state): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||empty($state['sites'])||!is_array($state['sites']))return $state;
    $stmt=$pdo->prepare("SELECT p.id,
      (SELECT COUNT(*) FROM vp3_radar_sessions s WHERE s.property_id=p.id) AS total_sessions,
      (SELECT COUNT(*) FROM vp3_radar_sessions s WHERE s.property_id=p.id AND s.agent_contact_id IS NULL) AS human_sessions,
      (SELECT COUNT(*) FROM vp3_radar_sessions s WHERE s.property_id=p.id AND s.agent_contact_id IS NOT NULL) AS agent_sessions,
      (SELECT COUNT(*) FROM vp3_radar_events e WHERE e.property_id=p.id) AS total_events,
      (SELECT MAX(e.occurred_at) FROM vp3_radar_events e WHERE e.property_id=p.id) AS last_event_at
      FROM vp3_radar_properties p
      WHERE p.owner_user_id=? AND p.property_type='external'");
    $stmt->execute([$uid]);
    $counts=[];
    foreach($stmt->fetchAll()?:[] as $row)$counts[(int)$row['id']]=$row;
    foreach($state['sites'] as &$site){
        $row=$counts[(int)($site['id']??0)]??[];
        $site['session_count']=(int)($row['total_sessions']??0);
        $site['human_session_count']=(int)($row['human_sessions']??0);
        $site['agent_session_count']=(int)($row['agent_sessions']??0);
        $site['event_count']=(int)($row['total_events']??0);
        $site['last_event_at']=(string)($row['last_event_at']??'');
    }
    unset($site);
    return $state;
}

function vp3_analytics_dashboard_state_v2(PDO $pdo,array $user,int $propertyId=0,int $days=30): array
{
    $uid=(int)($user['id']??0);$days=max(1,min(90,$days));
    if($uid<1)return ['ready'=>false];

    // A user's VP3 profile is always a first-class Analytics property, even if
    // no automated agent has visited yet to create the native Radar property.
    if(function_exists('vp3_radar_native_property'))vp3_radar_native_property($pdo,$uid);
    // Native profile_events / profile_visit_sessions are aggregated in the base read.
    // Never add cumulative browser-contact counters to period totals here.
    return vp3_analytics_dashboard_state($pdo,$user,$propertyId,$days);
}
