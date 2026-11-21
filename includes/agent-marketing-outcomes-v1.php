<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-sources-v1.php';
require_once __DIR__.'/agent-coordination-marketing-v1.php';
require_once __DIR__.'/profile-promotion-attribution-v1.php';
require_once __DIR__.'/agent-marketing-followthrough-v1.php';

/** Read an exact owner-authorized Campaign and its recorded, attributable results. */
function vp3_marketing_campaign_outcomes_v1(PDO $pdo,int $owner,int $merchant,int $campaign,DateTimeImmutable $now): array
{
    if ($owner<1||$merchant<1||$campaign<1) throw new DomainException('Choose a campaign in this business.',422);
    $query=$pdo->prepare("SELECT c.id,c.name,c.status,c.current_version_no,c.starts_at,c.ends_at,c.launched_at,c.completed_at,c.archived_at
        FROM campaigns c JOIN merchant_accounts m ON m.id=c.merchant_id
        WHERE c.id=? AND c.merchant_id=? AND m.owner_user_id=? AND m.status='active' AND m.sandbox_mode=0 AND c.environment='production'");
    $query->execute([$campaign,$merchant,$owner]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if (!$row||!campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'analytics.view')) {
        throw new DomainException('Campaign outcomes are unavailable for this business.',404);
    }
    $status=$row['status'];
    if (!in_array($status,['draft','scheduled','active','paused','completed','archived'],true)) {
        throw new RuntimeException('The campaign lifecycle could not be verified.',503);
    }
    $now=$now->setTimezone(new DateTimeZone('UTC'));
    $at=$now->format('Y-m-d H:i:s');
    $start=vp3_marketing_campaign_time_v1($row['starts_at']);
    $end=vp3_marketing_campaign_time_v1($row['ends_at']);
    $launched=vp3_marketing_campaign_time_v1($row['launched_at']);
    $completed=vp3_marketing_campaign_time_v1($row['completed_at']);
    $archived=vp3_marketing_campaign_time_v1($row['archived_at']);
    $closure=null;
    if ($status!=='draft'&&$launched!==null&&$launched<=$now) {
        $ends=array_filter([$end,$completed,$archived],static fn(?DateTimeImmutable $date):bool =>
            $date!==null&&$date>=$launched&&$date<=$now);
        if ($ends) $closure=min($ends);
    }
    $report=[
        'campaign'=>['id'=>$campaign,'name'=>vp3_agent_activity_text_v1($row['name'],190),'status'=>$status,
            'version'=>(int)$row['current_version_no'],'starts_at'=>$start?->format(DATE_ATOM),'ends_at'=>$end?->format(DATE_ATOM),
            'window_state'=>match(true) {
                $closure!==null=>'closed',
                $start!==null&&$start>$now&&in_array($status,['scheduled','active'],true)=>'upcoming',
                $status==='active'&&($end===null||$end>$now)=>'open',
                default=>'not_open',
            }],
        'decisions'=>vp3_marketing_campaign_decisions_v1($pdo,$owner,$merchant,$campaign,$row['name'],(int)$row['current_version_no'],$at),
        'observations'=>[
            'claims'=>vp3_marketing_campaign_claims_v1($pdo,$merchant,$campaign,$launched,$closure??$now,$at),
            'redemptions'=>vp3_marketing_campaign_redemptions_v1($pdo,$merchant,$campaign,$at),
            'promotion'=>vp3_marketing_campaign_promotion_v1($pdo,$owner,$campaign,$now),
        ],
        'handoff'=>null,
    ];
    $report['followthrough']=vp3_marketing_campaign_followthrough_v1($pdo,$owner,$merchant,$campaign,$now,$report['decisions']);
    if ($closure!==null) {
        $closed=$report['observations']['claims'];
        if ($closed['state']!=='observed') {
            $closed=vp3_marketing_campaign_redemptions_v1($pdo,$merchant,$campaign,$at,$closure->format('Y-m-d H:i:s'));
        }
        if ($closed['state']==='observed') {
            // The same campaign close can be backed by either native source.
            // Later snapshots do not create another notice for that close.
            $cohort=vp3_coordination_source_hash_v1(['campaign_redemption_closure',$merchant,$campaign,$closure->format(DATE_ATOM)]);
            $report['handoff']=['cohort'=>$cohort,'closed_at'=>$closure->format(DATE_ATOM),
                'window_start'=>$closed['window_start'],'window_end'=>$closed['window_end'],
                'reward_redemptions'=>$closed['metrics']['reward_redemptions'],
                'revision'=>$cohort];
        }
    }
    $report['revision']=vp3_coordination_source_hash_v1($report);
    return $report;
}

function vp3_marketing_campaign_time_v1(mixed $raw): ?DateTimeImmutable
{
    if (!is_string($raw)) return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));
    return $date!==false&&$date->format('Y-m-d H:i:s')===$raw?$date:null;
}

/** All field and column expressions here are code-owned. SQLite already unquotes scalars. */
function vp3_marketing_campaign_json_v1(PDO $pdo,string $column,string $path,bool $text=false): string
{
    $sql="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
    return $text&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"JSON_UNQUOTE($sql)":$sql;
}

function vp3_marketing_campaign_decisions_v1(PDO $pdo,int $owner,int $merchant,int $campaign,string $name,int $version,string $at): array
{
    foreach (['campaign_agent_recommendations','campaign_optimization_snapshots','campaign_activity_events'] as $table) {
        if (!schema_metadata_table_exists($pdo,$table)) return [];
    }
    $snapshotId=vp3_marketing_campaign_json_v1($pdo,'r.evidence_refs_json','$.snapshot_id');
    $eventRecommendation=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.recommendation_id');
    $eventDecision=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.decision',true);
    $query=$pdo->prepare("SELECT r.id,r.merchant_id,r.campaign_id,r.recommendation_type,r.summary,r.status,r.created_at,r.resolved_at,
        r.evidence_refs_json,s.id AS snapshot_id,s.evidence_hash,s.recommendations_json,
        EXISTS (SELECT 1 FROM campaign_activity_events e WHERE e.merchant_id=r.merchant_id AND e.campaign_id=r.campaign_id
            AND e.event_type='campaign.optimization_recommendation_reviewed' AND e.environment='production'
            AND e.actor_type='user' AND e.actor_user_id>0 AND e.occurred_at>=r.resolved_at AND e.occurred_at<=?
            AND $eventRecommendation=r.id AND $eventDecision=r.status) AS reviewed
        FROM campaign_agent_recommendations r JOIN campaign_optimization_snapshots s
          ON s.id=$snapshotId AND s.merchant_id=r.merchant_id AND s.campaign_id=r.campaign_id AND s.status='observed'
        WHERE r.merchant_id=? AND r.campaign_id=? AND r.created_for_user_id=? AND r.contact_id IS NULL AND r.created_at<=?
          AND r.status IN ('proposed','accepted','dismissed')
          AND r.recommendation_type IN ('optimization.offer-eligibility','optimization.fatigue',
              'optimization.low-observed-conversion','optimization.conflict-volume')
        ORDER BY CASE WHEN r.status='proposed' THEN 1 ELSE 0 END,COALESCE(r.resolved_at,r.created_at) DESC,r.id DESC LIMIT 16");
    $query->execute([$at,$merchant,$campaign,$owner,$at]);
    $items=[];
    $seen=[];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $proposed=$row['status']==='proposed';
        if (!$proposed&&(int)$row['reviewed']!==1) continue;
        $when=vp3_marketing_campaign_time_v1($proposed?$row['created_at']:$row['resolved_at']);
        $item=vp3_coordination_marketing_item_v1($row+['campaign_name'=>$name,'current_version_no'=>$version]);
        if ($when===null||$item===null) continue;
        $type=substr($row['recommendation_type'],strlen('optimization.'));
        $key=$type.'|'.$item['summary'];
        if (isset($seen[$key])) continue;
        $seen[$key]=true;
        $items[]=['id'=>(int)$row['id'],'type'=>$type,'status'=>$row['status'],'at'=>$when->format(DATE_ATOM)];
        if (count($items)>=5) break;
    }
    return $items;
}

function vp3_marketing_campaign_observation_v1(string $state): array
{
    return ['state'=>$state,'window_start'=>null,'window_end'=>null,'metrics'=>[]];
}

/** Count exact accepted claim receipts for a launched campaign, excluding reversals. */
function vp3_marketing_campaign_claims_v1(PDO $pdo,int $merchant,int $campaign,?DateTimeImmutable $launch,
    DateTimeImmutable $end,string $observedAt): array
{
    if ($launch===null||$launch>$end) return vp3_marketing_campaign_observation_v1('unobserved');
    foreach (['reward_claims','reward_issuances','campaign_activity_events'] as $table) {
        if (!schema_metadata_table_exists($pdo,$table)) return vp3_marketing_campaign_observation_v1('unavailable');
    }
    $query=$pdo->prepare("SELECT COUNT(*) FROM reward_claims c
        JOIN reward_issuances i ON i.id=c.reward_issuance_id AND i.merchant_id=c.merchant_id
            AND i.campaign_id=c.campaign_id AND i.environment='production'
        WHERE c.merchant_id=? AND c.campaign_id=? AND c.environment='production'
          AND c.status='claimed' AND c.reversed_at IS NULL AND c.claimed_at BETWEEN ? AND ?
          AND EXISTS (SELECT 1 FROM campaign_activity_events a WHERE a.merchant_id=c.merchant_id AND a.campaign_id=c.campaign_id
              AND a.claim_id=c.id AND a.reward_issuance_id=i.id AND a.event_type='claim.accepted'
              AND a.environment='production' AND a.occurred_at>=c.claimed_at AND a.occurred_at<=?)");
    $until=$end->format('Y-m-d H:i:s');
    $query->execute([$merchant,$campaign,$launch->format('Y-m-d H:i:s'),$until,$observedAt]);
    $count=(int)$query->fetchColumn();
    if ($count===0) return vp3_marketing_campaign_observation_v1('unobserved');
    return ['state'=>'observed','window_start'=>$launch->format(DATE_ATOM),'window_end'=>$end->format(DATE_ATOM),
        'metrics'=>['reward_redemptions'=>$count]];
}

/** Project a native observed snapshot and its exact audit proof without reading contact-level metrics. */
function vp3_marketing_campaign_redemptions_v1(PDO $pdo,int $merchant,int $campaign,string $at,?string $closure=null): array
{
    if (!schema_metadata_table_exists($pdo,'campaign_optimization_snapshots')
        ||!schema_metadata_table_exists($pdo,'campaign_activity_events')) {
        return vp3_marketing_campaign_observation_v1('unavailable');
    }
    $count=vp3_marketing_campaign_json_v1($pdo,'s.metrics_json','$.campaign.outcomes.reward_claimed');
    $eventSnapshot=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.snapshot_id');
    $eventHash=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.evidence_hash',true);
    $eventSample=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.sample_count');
    $window=$closure===null?'':" AND s.window_start<=? AND s.window_end>=? AND (s.sample_count>0 OR ($count+0)>0)";
    $order=$closure===null?'s.window_end DESC,s.id DESC':'s.window_end ASC,s.id ASC';
    $query=$pdo->prepare("SELECT s.id,s.window_start,s.window_end,s.sample_count,$count AS redemptions,
        EXISTS (SELECT 1 FROM campaign_activity_events e WHERE e.merchant_id=s.merchant_id AND e.campaign_id=s.campaign_id
          AND e.event_type='campaign.optimization_snapshot_recorded' AND e.actor_type='system' AND e.environment='production'
          AND e.occurred_at>=s.created_at AND e.occurred_at<=? AND $eventSnapshot=s.id
          AND $eventHash=s.evidence_hash AND $eventSample=s.sample_count) AS verified
        FROM campaign_optimization_snapshots s
        WHERE s.merchant_id=? AND s.campaign_id=? AND s.status='observed'
          AND s.window_start<s.window_end AND s.window_end<=? AND s.created_at<=? $window
        ORDER BY $order LIMIT 4");
    $params=[$at,$merchant,$campaign,$at,$at];
    if ($closure!==null) array_push($params,$closure,$closure);
    $query->execute($params);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $start=vp3_marketing_campaign_time_v1($row['window_start']);
        $end=vp3_marketing_campaign_time_v1($row['window_end']);
        $count=filter_var($row['redemptions'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ((int)$row['verified']!==1||$start===null||$end===null||$count===false) continue;
        $sample=(int)$row['sample_count'];
        $state=$sample>0||$count>0?'observed':'unobserved';
        return ['state'=>$state,'window_start'=>$start->format(DATE_ATOM),'window_end'=>$end->format(DATE_ATOM),
            'snapshot_id'=>(int)$row['id'],
            'metrics'=>$state==='observed'?['reward_redemptions'=>$count,'decisions_sampled'=>$sample]:[]];
    }
    return vp3_marketing_campaign_observation_v1($rows?'unavailable':'unobserved');
}

/** Counts are lifetime totals for tracked VP3 campaign promotions, separate from the redemption window. */
function vp3_marketing_campaign_promotion_v1(PDO $pdo,int $owner,int $campaign,DateTimeImmutable $now): array
{
    if (!profile_promotion_attribution_ready_v1($pdo)) return vp3_marketing_campaign_observation_v1('unavailable');
    $query=$pdo->prepare('SELECT MIN(created_at) FROM agent_promotion_publications_v1 WHERE owner_user_id=? AND campaign_id=?');
    $query->execute([$owner,$campaign]);
    $start=vp3_marketing_campaign_time_v1($query->fetchColumn());
    if ($start===null||$start>$now) return vp3_marketing_campaign_observation_v1('unobserved');
    foreach (['agent_commerce_orders_v800','agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800'] as $table) {
        if (!schema_metadata_table_exists($pdo,$table)) return vp3_marketing_campaign_observation_v1('unavailable');
    }
    $metrics=['clicks'=>0,'checkout_starts'=>0,'paid_orders'=>0,'refunded_orders'=>0];
    foreach (profile_promotion_attribution_campaign_summary_v1($pdo,$owner,$campaign) as $row) {
        foreach ($metrics as $key=>$value) $metrics[$key]+=(int)$row[$key];
    }
    return ['state'=>'observed','window_start'=>$start->format(DATE_ATOM),'window_end'=>null,'metrics'=>$metrics];
}
