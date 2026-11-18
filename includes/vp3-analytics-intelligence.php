<?php
declare(strict_types=1);
require_once __DIR__.'/vp3-analytics.php';

/**
 * VP3 Analytics intelligence v310.
 *
 * Converts privacy-preserving Analytics + Agent Radar traffic into bounded,
 * deterministic signals for Agent Brain. This does not create a second
 * analytics store: it reads the canonical VP3 Radar/session tables and the
 * existing timestamped native Profile visit events.
 */
const VP3_ANALYTICS_INTELLIGENCE_V310 = 'vp3-analytics-intelligence-v310-20260907';
const VP3_ANALYTICS_SPIKE_WINDOW_HOURS_V310 = 24;
const VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310 = 7;
const VP3_ANALYTICS_SPIKE_COOLDOWN_HOURS_V310 = 6;

function vp3_analytics_intelligence_property_metrics_v310(PDO $pdo,int $ownerUserId,array $property): array
{
    $propertyId=max(0,(int)($property['id']??0));
    $out=array_fill_keys(['current_sessions','baseline_sessions','current_views','baseline_views',
        'current_agent_sessions','baseline_agent_sessions','current_human_sessions','baseline_human_sessions',
        'current_profile_visits','baseline_profile_visits'],0);
    $out['ready']=false;
    if($ownerUserId<1||$propertyId<1)return $out;
    $rows=vp3_analytics_query_rows_v1($pdo,'SELECT property_type FROM vp3_radar_properties WHERE id=? AND owner_user_id=?',[$propertyId,$ownerUserId]);
    if(!$rows)return $out;
    $type=$rows[0]['property_type'];
    $window=vp3_analytics_reporting_window_v1($pdo,VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310+1);
    $currentFrom=(new DateTimeImmutable($window['to'],new DateTimeZone('UTC')))->modify('-24 hours')->format('Y-m-d H:i:s');
    $current='e.occurred_at>='.$pdo->quote($currentFrom);$baseline='e.occurred_at<'.$pdo->quote($currentFrom);
    // Compare one day's distinct active sessions with the mean of seven equally aligned days.
    // Counting a long-lived session only once across the baseline would manufacture a lift.
    $anchor=$pdo->quote($window['from']);
    $bucket=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? "(TIMESTAMPDIFF(SECOND,$anchor,e.occurred_at) DIV 86400)"
        : "CAST((CAST(strftime('%s',e.occurred_at) AS INTEGER)-CAST(strftime('%s',$anchor) AS INTEGER))/86400 AS INTEGER)";
    $baselineSession=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? "CONCAT(s.id,':',$bucket)" : "(CAST(s.id AS TEXT)||':'||CAST($bucket AS TEXT))";

    $agent="s.agent_contact_id IS NOT NULL OR SUBSTR(s.visitor_type,1,7)='webmcp_'";
    $human="s.agent_contact_id IS NULL AND s.visitor_type='human'";
    $view="(e.event_type='analytics_page_view' AND e.agent_contact_id IS NULL) OR (e.event_type IN ('agent_profile_view','external_agent_page_view') AND e.agent_contact_id IS NOT NULL)";
    // A session may appear in both windows if it has real activity in each.
    // Its mutable last_seen_at and cumulative counters cannot move prior evidence into today.
    $rows=vp3_analytics_query_rows_v1($pdo,"SELECT
        COUNT(DISTINCT CASE WHEN $current THEN s.id END) AS current_sessions,
        COUNT(DISTINCT CASE WHEN $baseline THEN $baselineSession END) AS baseline_sessions,
        COALESCE(SUM(CASE WHEN $current AND ($view) THEN 1 ELSE 0 END),0) AS current_views,
        COALESCE(SUM(CASE WHEN $baseline AND ($view) THEN 1 ELSE 0 END),0) AS baseline_views,
        COUNT(DISTINCT CASE WHEN $current AND ($agent) THEN s.id END) AS current_agent_sessions,
        COUNT(DISTINCT CASE WHEN $baseline AND ($agent) THEN $baselineSession END) AS baseline_agent_sessions,
        COUNT(DISTINCT CASE WHEN $current AND ($human) THEN s.id END) AS current_human_sessions,
        COUNT(DISTINCT CASE WHEN $baseline AND ($human) THEN $baselineSession END) AS baseline_human_sessions
        FROM vp3_radar_events e
        JOIN vp3_radar_properties p ON p.id=e.property_id AND p.owner_user_id=e.owner_user_id
        LEFT JOIN vp3_radar_sessions s ON s.id=e.session_id AND s.owner_user_id=e.owner_user_id AND s.property_id=e.property_id
        WHERE p.owner_user_id=? AND p.id=? AND (e.session_id IS NULL OR s.id IS NOT NULL)
          AND e.occurred_at>=? AND e.occurred_at<=?",[$ownerUserId,$propertyId,$window['from'],$window['to']]);
    foreach($rows[0] as $key=>$value)$out[$key]=(int)$value;
    if($type==='native'){
        if(!table_exists('profile_events')||!table_exists('profile_visit_sessions'))throw new RuntimeException('Native Profile visit history is unavailable.');
        $current='e.created_at>='.$pdo->quote($currentFrom);$baseline='e.created_at<'.$pdo->quote($currentFrom);
        $rows=vp3_analytics_query_rows_v1($pdo,"SELECT
            COALESCE(SUM(CASE WHEN $current THEN 1 ELSE 0 END),0) AS current_profile_visits,
            COALESCE(SUM(CASE WHEN $baseline THEN 1 ELSE 0 END),0) AS baseline_profile_visits
            FROM profile_events e LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id
            WHERE e.owner_user_id=? AND (e.profile_session_id IS NULL OR s.owner_user_id=e.owner_user_id)
              AND e.event_type='profile_view' AND e.created_at>=? AND e.created_at<=?",[$ownerUserId,$window['from'],$window['to']]);
        foreach($rows[0] as $key=>$value)$out[$key]=(int)$value;
    }
    $out['ready']=true;$out['reporting_window']=$window;$out['current_from']=$currentFrom;
    return $out;
}

function vp3_analytics_intelligence_top_agents_v310(PDO $pdo,int $ownerUserId,int $propertyId,?array $window=null): array
{
    if($ownerUserId<1||$propertyId<1)return [];
    $window??=vp3_analytics_reporting_window_v1($pdo,1);
    $rows=vp3_analytics_query_rows_v1($pdo,"SELECT c.id,c.display_name,c.operator_name,c.visitor_class,COUNT(DISTINCT s.id) AS session_count
        FROM vp3_radar_events e
        JOIN vp3_radar_properties p ON p.id=e.property_id AND p.owner_user_id=e.owner_user_id
        JOIN vp3_radar_sessions s ON s.id=e.session_id AND s.owner_user_id=e.owner_user_id AND s.property_id=e.property_id
        JOIN vp3_agent_contacts c ON c.id=s.agent_contact_id AND c.owner_user_id=s.owner_user_id
        WHERE p.owner_user_id=? AND p.id=? AND e.occurred_at>=? AND e.occurred_at<=?
        GROUP BY c.id,c.display_name,c.operator_name,c.visitor_class ORDER BY session_count DESC,c.id DESC LIMIT 3",
        [$ownerUserId,$propertyId,$window['from'],$window['to']]);
    $out=[];
    foreach($rows as $row){
        $display=trim((string)($row['display_name']??''))?:'Automated agent';$operator=trim((string)($row['operator_name']??''));
        $out[]=['contact_id'=>(int)$row['id'],'name'=>$operator!==''?$operator.' · '.$display:$display,
            'visitor_class'=>(string)($row['visitor_class']??''),'sessions'=>(int)$row['session_count']];
    }
    return $out;
}

function vp3_analytics_intelligence_spike_v310(array $metrics): array
{
    if(array_key_exists('ready',$metrics)&&$metrics['ready']!==true)return ['spike'=>false];
    $days=max(1,VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310);
    $sessionBaseline=(float)$metrics['baseline_sessions']/$days;
    $viewBaseline=(float)$metrics['baseline_views']/$days;
    $currentSessions=(int)$metrics['current_sessions'];$currentViews=(int)$metrics['current_views'];
    $sessionRatio=$currentSessions/max(1.0,$sessionBaseline);
    $viewRatio=$currentViews/max(1.0,$viewBaseline);
    $sessionLift=$currentSessions-$sessionBaseline;$viewLift=$currentViews-$viewBaseline;
    $sessionSpike=$currentSessions>=5&&(($sessionBaseline>=1.0&&$sessionRatio>=2.0&&$sessionLift>=3.0)||($sessionBaseline<1.0&&$currentSessions>=8));
    $viewSpike=$currentViews>=10&&(($viewBaseline>=2.0&&$viewRatio>=2.0&&$viewLift>=5.0)||($viewBaseline<2.0&&$currentViews>=15));
    $currentVisits=(int)($metrics['current_profile_visits']??0);$visitBaseline=(float)($metrics['baseline_profile_visits']??0)/$days;
    $visitRatio=$currentVisits/max(1.0,$visitBaseline);$visitLift=$currentVisits-$visitBaseline;
    $visitSpike=$currentVisits>=5&&(($visitBaseline>=1.0&&$visitRatio>=2.0&&$visitLift>=3.0)||($visitBaseline<1.0&&$currentVisits>=8));
    if(!$sessionSpike&&!$viewSpike&&!$visitSpike)return ['spike'=>false];
    $ratio=max($sessionSpike?$sessionRatio:0.0,$viewSpike?$viewRatio:0.0,$visitSpike?$visitRatio:0.0);
    $volume=max($currentSessions,$currentViews/2,$currentVisits);
    $score=max(70,min(98,(int)round(70+min(18,max(0,$ratio-2)*7)+min(10,$volume/4))));
    return [
        'spike'=>true,'session_spike'=>$sessionSpike,'view_spike'=>$viewSpike,'visit_spike'=>$visitSpike,'score'=>$score,
        'session_ratio'=>round($sessionRatio,2),'view_ratio'=>round($viewRatio,2),
        'baseline_daily_sessions'=>round($sessionBaseline,1),'baseline_daily_views'=>round($viewBaseline,1),
        'baseline_daily_profile_visits'=>round($visitBaseline,1),'profile_visit_ratio'=>round($visitRatio,2),
    ];
}

function vp3_analytics_intelligence_record_spike_v310(PDO $pdo,array $user,array $property,array $metrics,array $spike,array $agents,string $summary): int
{
    $uid=(int)($user['id']??0);$propertyId=(int)($property['id']??0);
    if($uid<1||$propertyId<1||!table_exists('vp3_radar_events'))return 0;
    $check=$pdo->prepare(
        "SELECT id FROM vp3_radar_events
         WHERE owner_user_id=? AND property_id=? AND event_type='analytics_traffic_spike'
           AND occurred_at>=DATE_SUB(NOW(),INTERVAL 6 HOUR)
         ORDER BY id DESC LIMIT 1"
    );
    $check->execute([$uid,$propertyId]);$existing=(int)($check->fetchColumn()?:0);
    if($existing>0)return $existing;
    $severity=(int)$spike['score']>=88?'high':'medium';
    $details=[
        'source'=>'vp3_analytics','window_hours'=>VP3_ANALYTICS_SPIKE_WINDOW_HOURS_V310,
        'baseline_days'=>VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310,'metrics'=>$metrics,'spike'=>$spike,'top_agents'=>$agents,
        'property'=>['id'=>$propertyId,'type'=>(string)($property['property_type']??''),'label'=>(string)($property['label']??''),'domain'=>(string)($property['domain']??'')],
        'generated_at'=>gmdate('c'),
    ];
    $stmt=$pdo->prepare(
        "INSERT INTO vp3_radar_events
         (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
         VALUES (?,?,NULL,NULL,'analytics_traffic_spike',?,'/analytics','SYSTEM',NULL,?,0,?,?,NOW())"
    );
    $stmt->execute([$uid,$propertyId,$severity,(int)$spike['score'],mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $eventId=(int)$pdo->lastInsertId();
    if($eventId>0&&function_exists('vp3_cognitive_domain_record_v2390')){
        vp3_cognitive_domain_record_v2390($pdo,$uid,'analytics_attribution','analytics.signal_detected',[
            vp3_cognitive_ref_v2390('analytics_signal',$eventId)
        ],[
            'signal_type'=>'traffic_spike',
            'property_id'=>$propertyId,
            'severity'=>$severity,
            'score'=>(int)$spike['score'],
        ],['external_event_id'=>'analytics-signal:'.$eventId]);
    }
    if($eventId>0&&function_exists('create_notification')){
        $label=trim((string)($property['label']??''))?:trim((string)($property['domain']??''));if($label==='')$label='VP3 property';
        create_notification($uid,'analytics_traffic_spike','Traffic spike · '.$label,$summary,url('/profile-agent.php?tab=analytics'),'radar_event',$eventId);
    }
    return $eventId;
}

function vp3_analytics_intelligence_signals_v310(PDO $pdo,array $user,bool $record=true): array
{
    $uid=(int)($user['id']??0);if($uid<1||!function_exists('vp3_analytics_property_rows')||!vp3_radar_schema_ready($pdo))return [];
    if(function_exists('vp3_radar_native_property'))vp3_radar_native_property($pdo,$uid);
    $signals=[];
    foreach(vp3_analytics_property_rows($pdo,$uid) as $property){
        if(empty($property['is_active'])&&(string)($property['property_type']??'')!=='native')continue;
        try{
            $metrics=vp3_analytics_intelligence_property_metrics_v310($pdo,$uid,$property);
            $spike=vp3_analytics_intelligence_spike_v310($metrics);if(empty($spike['spike']))continue;
            $propertyId=(int)$property['id'];
            $agents=vp3_analytics_intelligence_top_agents_v310($pdo,$uid,$propertyId,['from'=>$metrics['current_from'],'to'=>$metrics['reporting_window']['to']]);
        }catch(Throwable $error){
            if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('analytics.signal.unavailable',['property_id'=>(int)$property['id'],'error_class'=>get_class($error)]);
            continue;
        }
        $label=trim((string)($property['label']??''))?:trim((string)($property['domain']??''));if($label==='')$label='VP3 property';
        $facts=[];
        if(!empty($spike['session_spike']))$facts[]=(int)$metrics['current_sessions'].' Radar sessions with recorded activity vs '.number_format((float)$spike['baseline_daily_sessions'],1).' daily baseline';
        if(!empty($spike['view_spike']))$facts[]=(int)$metrics['current_views'].' page-view events vs '.number_format((float)$spike['baseline_daily_views'],1).' daily baseline';
        if(!empty($spike['visit_spike']))$facts[]=(int)$metrics['current_profile_visits'].' Profile visit events vs '.number_format((float)$spike['baseline_daily_profile_visits'],1).' daily baseline';
        if((int)$metrics['current_agent_sessions']>0)$facts[]=(int)$metrics['current_agent_sessions'].' recognized/automated agent session'.((int)$metrics['current_agent_sessions']===1?'':'s');
        if($agents){$facts[]='most active agent: '.$agents[0]['name'].' ('.$agents[0]['sessions'].' session'.($agents[0]['sessions']===1?'':'s').')';}
        $summary='Traffic on '.$label.' is materially above its recent baseline: '.implode('; ',$facts).'. Current 24-hour window: '.$metrics['current_from'].' through '.$metrics['reporting_window']['to'].'; database timezone '.$metrics['reporting_window']['zone'].'.';
        $eventId=$record?vp3_analytics_intelligence_record_spike_v310($pdo,$user,$property,$metrics,$spike,$agents,$summary):0;
        $signals[]=[
            'id'=>'analytics-spike-'.$propertyId,'type'=>'opportunity','key'=>'analytics-spike:'.$propertyId,
            'title'=>'Review traffic spike on '.$label,'body'=>$summary,'reason'=>$summary,
            'prompt'=>'Review the VP3 Analytics spike on '.$label.'. Correlate it with Agent Radar, CRM, referrals, Profile Agent conversations and recent activity, explain what is actually supported by the evidence, and recommend the next useful action without assuming visitor intent.',
            'target_url'=>url('/profile-agent.php?tab=analytics'),'url'=>url('/profile-agent.php?tab=analytics'),
            'priority'=>max(140,min(196,(int)$spike['score']*2)),'score'=>round((int)$spike['score']/100,4),'source'=>'analytics',
            'source_event_id'=>$eventId,'property_id'=>$propertyId,'analytics_metrics'=>$metrics,'analytics_spike'=>$spike,'top_agents'=>$agents,
            'created_at'=>date('Y-m-d H:i:s'),
        ];
    }
    usort($signals,static fn(array $a,array $b):int=>(int)($b['priority']??0)<=>(int)($a['priority']??0));
    return $signals;
}
