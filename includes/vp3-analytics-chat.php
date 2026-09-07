<?php
declare(strict_types=1);

function vp3_analytics_chat_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    // Explicit Agent/Radar/Gateway requests belong to the dedicated Radar tool.
    if(preg_match('/\b(?:agent radar|agent gateway|ai agent|bot|crawler|block agent|allow agent|limit agent)\b/',$q))return false;
    foreach(['analytics','my traffic','website traffic','profile traffic','page views','pageviews','visitors this','sessions this','traffic this','traffic today','traffic yesterday','which website','which site','top page','top pages','referrers','referral traffic','conversions','conversion rate','device mix','browser mix'] as $needle){
        if(str_contains($q,$needle))return true;
    }
    return false;
}

function vp3_analytics_chat_days(string $query): int
{
    $q=mb_strtolower($query);
    if(str_contains($q,'today'))return 1;
    if(str_contains($q,'yesterday'))return 2;
    if(str_contains($q,'week')||str_contains($q,'7 day'))return 7;
    if(str_contains($q,'90 day')||str_contains($q,'quarter'))return 90;
    return 30;
}

function vp3_analytics_chat_property_name(array $row): string
{
    return (string)($row['property_type']??'')==='native'?'VP3 Profile':trim((string)($row['label']??''))?:trim((string)($row['domain']??''))?:'Connected site';
}

function vp3_analytics_chat_summary(PDO $pdo,array $user,string $query): string
{
    $days=vp3_analytics_chat_days($query);$state=vp3_analytics_dashboard_state_v2($pdo,$user,0,$days);$s=$state['stats']??[];
    $sessions=(int)($s['sessions']??0);$views=(int)($s['page_views']??0);$human=(int)($s['human_sessions']??0);$agents=(int)($s['agent_sessions']??0);$conversions=(int)($s['conversions']??0);
    $label=$days===1?'today':($days===7?'the last 7 days':($days===90?'the last 90 days':'the last 30 days'));
    $lines=['VP3 Analytics for '.$label.': '.$sessions.' sessions and '.$views.' page views.'];
    $lines[]=$human.' human sessions and '.$agents.' Agent Radar sessions'.($sessions>0?' ('.round($agents/$sessions*100).'‌% agent share).':'.');
    if($conversions>0)$lines[]=$conversions.' tracked conversion'.($conversions===1?'':'s').' from connected-site custom events.';
    $properties=array_values(array_filter($state['property_stats']??[],static fn(array $r):bool=>(int)($r['sessions']??0)>0||(int)($r['page_views']??0)>0));
    usort($properties,static fn(array $a,array $b):int=>((int)($b['page_views']??0)<=>(int)($a['page_views']??0))?:((int)($b['sessions']??0)<=>(int)($a['sessions']??0)));
    if($properties){$top=$properties[0];$lines[]='Top property: '.vp3_analytics_chat_property_name($top).' with '.(int)$top['page_views'].' views across '.(int)$top['sessions'].' sessions.';}
    $pages=$state['top_pages']??[];if($pages){$page=$pages[0];$lines[]='Top content: '.(string)$page['path'].' with '.(int)$page['events'].' tracked interactions.';}
    $refs=$state['referrers']??[];if($refs){$ref=$refs[0];$lines[]='Top external referrer: '.(string)$ref['referrer_host'].' with '.(int)$ref['sessions'].' sessions.';}
    if((int)($s['high_risk_events']??0)>0)$lines[]=(int)$s['high_risk_events'].' high-risk automated event'.((int)$s['high_risk_events']===1?'':'s').' also appeared in this period; ask me for high-risk agents to review them.';
    return implode("\n",$lines);
}

function vp3_analytics_chat_tool(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!vp3_analytics_chat_intent($query)||!personal_capability_has_v242('profile_agent.access',$user))return $empty;
    $pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))return $empty;
    $answer=vp3_analytics_chat_summary($pdo,$user,$query);
    if(function_exists('agent_tool_log'))agent_tool_log($user,'vp3.analytics',$query,'success',['days'=>vp3_analytics_chat_days($query)],$conversationId);
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'vp3-analytics','title'=>'VP3 Analytics']]];
}
