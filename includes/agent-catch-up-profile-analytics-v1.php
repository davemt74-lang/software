<?php
declare(strict_types=1);

/** Explicit private Chat catch-up only; no background attention subscription. */
function vp3_catch_up_profile_analytics_v1(PDO $pdo,array $user,int $agent,int $chat,DateTimeImmutable $now): array
{
    if($chat<1)return ['unconfigured',[]];
    require_once __DIR__.'/profile-analytics-period-v1.php';
    require_once __DIR__.'/agent-profile-analytics-summary-v1.php';
    $resolved=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
    $scope=['user'=>$user,'agent_id'=>$agent,'conversation_id'=>$chat,'agent'=>$resolved,
        'principal'=>vp3_agent_chat_principal_v380($user,$resolved)];
    $data=vp3_profile_analytics_period_v1($pdo,$scope,['period'=>1],$now);
    $coverage=$data['coverage']??'unavailable';
    if(!in_array($coverage,['ready','limited','unconfigured','unavailable'],true))$coverage='unavailable';
    if(!vp3_profile_analytics_discoverable_v1($data))return [$coverage,[]];
    $source=vp3_profile_analytics_source_item_v1($data,$agent,$chat);
    if($source===null)return ['unavailable',[]];
    return [$coverage,[[
        'id'=>$source['ref'],'title'=>$source['title'],'reason'=>$source['summary'],
        'source_label'=>'Profile completed period','url'=>$source['url'],
        'priority'=>20,'time'=>intdiv((int)$data['window']['to_utc_ms'],1000),
        'status'=>'completed_period','_source_revision'=>$source['revision'],
    ]]];
}
