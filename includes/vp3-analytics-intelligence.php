<?php
declare(strict_types=1);

/**
 * VP3 Analytics intelligence v310.
 *
 * Converts privacy-preserving Analytics + Agent Radar traffic into bounded,
 * deterministic signals for Agent Brain. This does not create a second
 * analytics store: it reads the canonical VP3 Radar/session tables and the
 * existing native profile session table.
 */
const VP3_ANALYTICS_INTELLIGENCE_V310 = 'vp3-analytics-intelligence-v310-20260907';
const VP3_ANALYTICS_SPIKE_WINDOW_HOURS_V310 = 24;
const VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310 = 7;
const VP3_ANALYTICS_SPIKE_COOLDOWN_HOURS_V310 = 6;

function vp3_analytics_intelligence_property_metrics_v310(PDO $pdo,int $ownerUserId,array $property): array
{
    $propertyId=max(0,(int)($property['id']??0));
    $type=(string)($property['property_type']??'external');
    $out=[
        'current_sessions'=>0,'baseline_sessions'=>0,'current_views'=>0,'baseline_views'=>0,
        'current_agent_sessions'=>0,'baseline_agent_sessions'=>0,'current_human_sessions'=>0,'baseline_human_sessions'=>0,
    ];
    if($ownerUserId<1||$propertyId<1)return $out;

    $stmt=$pdo->prepare(
        "SELECT
           COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) THEN 1 ELSE 0 END),0) current_sessions,
           COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) THEN 1 ELSE 0 END),0) baseline_sessions,
           COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) THEN page_view_count ELSE 0 END),0) current_views,
           COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) THEN page_view_count ELSE 0 END),0) baseline_views,
           COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND agent_contact_id IS NOT NULL THEN 1 ELSE 0 END),0) current_agent_sessions,
           COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) AND agent_contact_id IS NOT NULL THEN 1 ELSE 0 END),0) baseline_agent_sessions,
           COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND agent_contact_id IS NULL THEN 1 ELSE 0 END),0) current_human_sessions,
           COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) AND agent_contact_id IS NULL THEN 1 ELSE 0 END),0) baseline_human_sessions
         FROM vp3_radar_sessions WHERE owner_user_id=? AND property_id=?"
    );
    $stmt->execute([$ownerUserId,$propertyId]);
    $row=$stmt->fetch()?:[];
    foreach(array_keys($out) as $key)$out[$key]=(int)($row[$key]??0);

    // Native human profile visits intentionally live in the existing
    // privacy-preserving profile session table instead of Radar sessions.
    if($type==='native'&&table_exists('profile_visit_sessions')){
        $native=$pdo->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) THEN 1 ELSE 0 END),0) current_sessions,
               COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) THEN 1 ELSE 0 END),0) baseline_sessions,
               COALESCE(SUM(CASE WHEN last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) THEN view_count ELSE 0 END),0) current_views,
               COALESCE(SUM(CASE WHEN last_seen_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 8 DAY) THEN view_count ELSE 0 END),0) baseline_views
             FROM profile_visit_sessions WHERE owner_user_id=?"
        );
        $native->execute([$ownerUserId]);$n=$native->fetch()?:[];
        $out['current_sessions']+=(int)($n['current_sessions']??0);
        $out['baseline_sessions']+=(int)($n['baseline_sessions']??0);
        $out['current_views']+=(int)($n['current_views']??0);
        $out['baseline_views']+=(int)($n['baseline_views']??0);
        $out['current_human_sessions']+=(int)($n['current_sessions']??0);
        $out['baseline_human_sessions']+=(int)($n['baseline_sessions']??0);
    }
    return $out;
}

function vp3_analytics_intelligence_top_agents_v310(PDO $pdo,int $ownerUserId,int $propertyId): array
{
    if($ownerUserId<1||$propertyId<1)return [];
    $stmt=$pdo->prepare(
        "SELECT c.id,c.display_name,c.operator_name,c.visitor_class,COUNT(*) session_count
         FROM vp3_radar_sessions s
         INNER JOIN vp3_agent_contacts c ON c.id=s.agent_contact_id AND c.owner_user_id=s.owner_user_id
         WHERE s.owner_user_id=? AND s.property_id=? AND s.agent_contact_id IS NOT NULL
           AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)
         GROUP BY c.id,c.display_name,c.operator_name,c.visitor_class
         ORDER BY session_count DESC,c.id DESC LIMIT 3"
    );
    $stmt->execute([$ownerUserId,$propertyId]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $display=trim((string)($row['display_name']??''))?:'Automated agent';
        $operator=trim((string)($row['operator_name']??''));
        $out[]=[
            'contact_id'=>(int)$row['id'],'name'=>$operator!==''?$operator.' · '.$display:$display,
            'visitor_class'=>(string)($row['visitor_class']??''),'sessions'=>(int)($row['session_count']??0),
        ];
    }
    return $out;
}

function vp3_analytics_intelligence_spike_v310(array $metrics): array
{
    $days=max(1,VP3_ANALYTICS_SPIKE_BASELINE_DAYS_V310);
    $sessionBaseline=(float)$metrics['baseline_sessions']/$days;
    $viewBaseline=(float)$metrics['baseline_views']/$days;
    $currentSessions=(int)$metrics['current_sessions'];$currentViews=(int)$metrics['current_views'];
    $sessionRatio=$currentSessions/max(1.0,$sessionBaseline);
    $viewRatio=$currentViews/max(1.0,$viewBaseline);
    $sessionLift=$currentSessions-$sessionBaseline;$viewLift=$currentViews-$viewBaseline;
    $sessionSpike=$currentSessions>=5&&(($sessionBaseline>=1.0&&$sessionRatio>=2.0&&$sessionLift>=3.0)||($sessionBaseline<1.0&&$currentSessions>=8));
    $viewSpike=$currentViews>=10&&(($viewBaseline>=2.0&&$viewRatio>=2.0&&$viewLift>=5.0)||($viewBaseline<2.0&&$currentViews>=15));
    if(!$sessionSpike&&!$viewSpike)return ['spike'=>false];
    $ratio=max($sessionSpike?$sessionRatio:0.0,$viewSpike?$viewRatio:0.0);
    $volume=max($currentSessions,$currentViews/2);
    $score=max(70,min(98,(int)round(70+min(18,max(0,$ratio-2)*7)+min(10,$volume/4))));
    return [
        'spike'=>true,'session_spike'=>$sessionSpike,'view_spike'=>$viewSpike,'score'=>$score,
        'session_ratio'=>round($sessionRatio,2),'view_ratio'=>round($viewRatio,2),
        'baseline_daily_sessions'=>round($sessionBaseline,1),'baseline_daily_views'=>round($viewBaseline,1),
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
        $metrics=vp3_analytics_intelligence_property_metrics_v310($pdo,$uid,$property);
        $spike=vp3_analytics_intelligence_spike_v310($metrics);if(empty($spike['spike']))continue;
        $propertyId=(int)$property['id'];$agents=vp3_analytics_intelligence_top_agents_v310($pdo,$uid,$propertyId);
        $label=trim((string)($property['label']??''))?:trim((string)($property['domain']??''));if($label==='')$label='VP3 property';
        $facts=[];
        if(!empty($spike['session_spike']))$facts[]=(int)$metrics['current_sessions'].' sessions vs '.number_format((float)$spike['baseline_daily_sessions'],1).' daily baseline';
        if(!empty($spike['view_spike']))$facts[]=(int)$metrics['current_views'].' page views vs '.number_format((float)$spike['baseline_daily_views'],1).' daily baseline';
        if((int)$metrics['current_agent_sessions']>0)$facts[]=(int)$metrics['current_agent_sessions'].' recognized/automated agent session'.((int)$metrics['current_agent_sessions']===1?'':'s');
        if($agents){$facts[]='most active agent: '.$agents[0]['name'].' ('.$agents[0]['sessions'].' session'.($agents[0]['sessions']===1?'':'s').')';}
        $summary='Traffic on '.$label.' is materially above its recent baseline: '.implode('; ',$facts).'.';
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
