<?php
declare(strict_types=1);

/**
 * Verified post-review observation, not causal lift or proof that an accepted
 * recommendation was actually implemented. The Campaign Manager owns edits.
 */
function vp3_marketing_followthrough_snapshot_v1(array $row): ?array
{
    $start=vp3_marketing_campaign_time_v1($row['window_start']??null);
    $end=vp3_marketing_campaign_time_v1($row['window_end']??null);
    $days=$row['window_days']??null;
    if($start===null||$end===null||$end<=$start||!ctype_digit((string)$days)
        ||(int)$days<7||(int)$days>365
        ||abs(($end->getTimestamp()-$start->getTimestamp())-(int)$days*86400)>3600
        ||!ctype_digit((string)($row['sample_count']??'')))return null;
    $json=$row['metrics_json']??null;
    if(!is_string($json)||strlen($json)>65536)return null;
    try{$payload=json_decode($json,true,20,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    $stats=$payload['campaign']??null;
    if(!is_array($stats)||!is_array($stats['outcomes']??null))return null;
    $metrics=[];
    foreach(['treatment_entries'=>$stats['treatment_entries']??null,
        'converted'=>$stats['outcomes']['converted']??null,
        'reward_claimed'=>$stats['outcomes']['reward_claimed']??null] as $key=>$value){
        if(!is_int($value)||$value<0||$value>9007199254740991)return null;
        $metrics[$key]=$value;
    }
    return ['window_start'=>$start->format(DATE_ATOM),'window_end'=>$end->format(DATE_ATOM),
        'window_days'=>(int)$days,'metrics'=>$metrics];
}

/** No before/after comparison is published without separate nonoverlapping windows. */
function vp3_marketing_followthrough_compare_v1(array $before,?array $after,?string $changeAt): string
{
    if($after===null||$changeAt===null)return 'awaiting_window';
    $preEnd=strtotime($before['window_end']??'');
    $afterStart=strtotime($after['window_start']??'');
    $change=strtotime($changeAt);
    if($preEnd===false||$afterStart===false||$change===false
        ||$afterStart<$change||$afterStart<$preEnd
        ||($before['window_days']??null)!==($after['window_days']??null))return 'unavailable';
    if(($before['metrics']['treatment_entries']??0)<20||($after['metrics']['treatment_entries']??0)<20)
        return 'insufficient_sample';
    return 'observed';
}

/** The native campaign event ledger is the evidence, not owner Chat wording. */
function vp3_marketing_followthrough_event_v1(PDO $pdo,int $merchant,int $campaign,string $event,
    ?int $actor,string $start,string $end,?int $snapshot=null,?string $hash=null,?int $sample=null): ?string
{
    $snapshotId=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.snapshot_id');
    $eventHash=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.evidence_hash',true);
    $eventSample=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.sample_count');
    $actorWhere=$actor===null?"e.actor_type='system'":"e.actor_type='user' AND e.actor_user_id=?";
    $since=$event==='campaign.updated'?'>':'>=';
    $suffix=$snapshot===null?'':" AND $snapshotId=? AND $eventHash=? AND $eventSample=?";
    $q=$pdo->prepare("SELECT e.occurred_at FROM campaign_activity_events e
        WHERE e.merchant_id=? AND e.campaign_id=? AND e.event_type=? AND e.environment='production'
          AND $actorWhere AND e.occurred_at$since? AND e.occurred_at<=? $suffix
        ORDER BY e.occurred_at ASC,e.id ASC LIMIT 1");
    $args=[$merchant,$campaign,$event];
    if($actor!==null)$args[]=$actor;
    array_push($args,$start,$end);
    if($snapshot!==null)array_push($args,$snapshot,$hash,$sample);
    $q->execute($args);
    $value=$q->fetchColumn();
    return vp3_marketing_campaign_time_v1($value)?$value:null;
}

/** Owner-private, bounded observation lifecycle tied to reviewed recommendations. */
function vp3_marketing_campaign_followthrough_v1(PDO $pdo,int $owner,int $merchant,int $campaign,
    DateTimeImmutable $now,array $decisions): array
{
    if($owner<1||$merchant<1||$campaign<1||!$decisions)return [];
    foreach(['campaign_agent_recommendations','campaign_optimization_snapshots','campaign_activity_events'] as $table){
        if(!schema_metadata_table_exists($pdo,$table))return [];
    }
    if(!campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'analytics.view'))return [];
    $at=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $sourceId=vp3_marketing_campaign_json_v1($pdo,'r.evidence_refs_json','$.snapshot_id');
    $selected=$pdo->prepare("SELECT r.id,r.status,r.resolved_at,r.recommendation_type,r.evidence_refs_json,
        s.id AS snapshot_id,s.evidence_hash,s.sample_count,s.window_days,s.window_start,s.window_end,s.metrics_json,s.created_at
        FROM campaign_agent_recommendations r JOIN campaign_optimization_snapshots s
          ON s.id=$sourceId AND s.merchant_id=r.merchant_id AND s.campaign_id=r.campaign_id AND s.status='observed'
        WHERE r.id=? AND r.merchant_id=? AND r.campaign_id=? AND r.created_for_user_id=? AND r.contact_id IS NULL
          AND r.status='accepted' AND r.resolved_at<=? AND r.recommendation_type IN
          ('optimization.offer-eligibility','optimization.fatigue','optimization.low-observed-conversion','optimization.conflict-volume')
        LIMIT 1");
    $afterRows=$pdo->prepare("SELECT s.id AS snapshot_id,s.evidence_hash,s.sample_count,s.window_days,
        s.window_start,s.window_end,s.metrics_json,s.created_at
        FROM campaign_optimization_snapshots s
        WHERE s.merchant_id=? AND s.campaign_id=? AND s.status='observed' AND s.window_days=?
          AND s.window_start>=? AND s.window_end<=? AND s.created_at<=?
        ORDER BY s.window_end DESC,s.id DESC LIMIT 8");
    $out=[];
    foreach(array_slice($decisions,0,5) as $decision){
        if(!is_array($decision)||($decision['status']??null)!=='accepted'||!is_int($decision['id']??null))continue;
        $selected->execute([$decision['id'],$merchant,$campaign,$owner,$at]);
        $row=$selected->fetch(PDO::FETCH_ASSOC);
        if(!$row||!is_string($row['evidence_refs_json'])||strlen($row['evidence_refs_json'])>8192)continue;
        try{$refs=json_decode($row['evidence_refs_json'],true,16,JSON_THROW_ON_ERROR);}catch(JsonException){continue;}
        if(($refs['source']??null)!=='v126_optimization_snapshot'
            ||($refs['snapshot_id']??null)!==(int)$row['snapshot_id']
            ||($refs['evidence_hash']??null)!==$row['evidence_hash']
            ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$row['evidence_hash']))continue;
        $reviewedAt=vp3_marketing_campaign_time_v1($row['resolved_at']);
        if($reviewedAt===null||$reviewedAt>$now)continue;
        $reviewId=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.recommendation_id');
        $reviewDecision=vp3_marketing_campaign_json_v1($pdo,'e.details_json','$.decision',true);
        $q=$pdo->prepare("SELECT 1 FROM campaign_activity_events e
            WHERE e.merchant_id=? AND e.campaign_id=? AND e.event_type='campaign.optimization_recommendation_reviewed'
              AND e.environment='production' AND e.actor_type='user' AND e.actor_user_id=?
              AND e.occurred_at>=? AND e.occurred_at<=? AND $reviewId=? AND $reviewDecision='accepted' LIMIT 1");
        $q->execute([$merchant,$campaign,$owner,$row['resolved_at'],$at,$decision['id']]);
        if(!$q->fetchColumn())continue;
        $proof=vp3_marketing_followthrough_event_v1($pdo,$merchant,$campaign,'campaign.optimization_snapshot_recorded',
            null,$row['created_at'],$row['resolved_at'],(int)$row['snapshot_id'],$row['evidence_hash'],(int)$row['sample_count']);
        if($proof===null)continue;
        $before=vp3_marketing_followthrough_snapshot_v1($row);
        if($before===null)continue;
        $changeAt=vp3_marketing_followthrough_event_v1($pdo,$merchant,$campaign,'campaign.updated',$owner,$row['resolved_at'],$at);
        // An accepted recommendation alone never implies a Campaign mutation.
        $state=$changeAt===null?'awaiting_change':'awaiting_window';
        $after=null;
        if($changeAt!==null){
            $afterRows->execute([$merchant,$campaign,$before['window_days'],max($changeAt,str_replace('T',' ',substr($before['window_end'],0,19))),$at,$at]);
            $candidates=$afterRows->fetchAll(PDO::FETCH_ASSOC);
            foreach($candidates as $candidate){
                $sample=(int)$candidate['sample_count'];
                $verified=vp3_marketing_followthrough_event_v1($pdo,$merchant,$campaign,'campaign.optimization_snapshot_recorded',
                    null,$candidate['created_at'],$at,(int)$candidate['snapshot_id'],$candidate['evidence_hash'],$sample);
                if($verified===null)continue;
                $parsed=vp3_marketing_followthrough_snapshot_v1($candidate);
                if($parsed===null)continue;
                $result=vp3_marketing_followthrough_compare_v1($before,$parsed,$changeAt);
                if($result==='unavailable')continue;
                $after=$parsed;$state=$result;break;
            }
            if($after===null&&$candidates)$state='unavailable';
        }
        $out[]=['recommendation_id'=>$decision['id'],'type'=>$decision['type'],
            'state'=>$state,'reviewed_at'=>$reviewedAt->format(DATE_ATOM),
            'change_at'=>$changeAt===null?null:vp3_marketing_campaign_time_v1($changeAt)?->format(DATE_ATOM),
            'before'=>$before,'after'=>$after,'scope'=>'observational_not_causal'];
        if(count($out)>=3)break;
    }
    return $out;
}
