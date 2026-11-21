<?php
declare(strict_types=1);

require_once __DIR__.'/schema-metadata.php';
if (!function_exists('campaigns_rewards_owner_plugin_enabled_v100')) require_once __DIR__.'/campaigns-rewards-v100.php';
if (!function_exists('campaigns_rewards_platform_can_v100')) require_once __DIR__.'/campaigns-rewards-domain-v100.php';

function vp3_coordination_marketing_owner_v1(PDO $pdo, array $scope): ?int
{
    $owner=(int)($scope['user']['id']??0);
    $agent=$scope['agent_id']??null;
    $principal=$scope['principal']??[];
    $ownedPrincipal=($principal['viewer_user_id']??null)===$owner && ($principal['agent_id']??null)===$agent
        && (($agent===0 && ($principal['kind']??null)==='system')
            || (is_int($agent) && $agent>0 && ($principal['kind']??null)==='user_agent'
                && ($principal['owner_user_id']??null)===$owner));
    if ($owner<1 || !$ownedPrincipal || ($scope['conversation_id']??0)<1
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1()!==null)
        || !campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner)) return null;
    return $owner;
}

/** Project only owner-authorized, reviewable Campaign v1.26 recommendations. */
function vp3_coordination_marketing_recommendations_v1(PDO $pdo, array $scope, ?array $refs, DateTimeImmutable $now): array
{
    $out=['items'=>[],'coverage'=>'unconfigured'];
    $owner=vp3_coordination_marketing_owner_v1($pdo,$scope);
    if ($owner===null) return $out;
    foreach (['merchant_accounts','campaigns','campaign_agent_recommendations','campaign_optimization_snapshots'] as $table) {
        if (!schema_metadata_table_exists($pdo,$table)) return ['items'=>[],'coverage'=>'unavailable'];
    }

    // A discovery sweep is bounded. Exact re-reads are not subject to the discovery cap.
    $ids=[];
    $filters='';
    $params=[$owner];
    if ($refs!==null) {
        foreach ($refs as $ref) {
            if (preg_match('/\Acampaign-optimization:([1-9][0-9]{0,17})\z/D',(string)$ref,$match)) $ids[]=(int)$match[1];
        }
        if (!$ids) return $out;
        $filters=' AND EXISTS (SELECT 1 FROM campaign_agent_recommendations r WHERE r.merchant_id=m.id
            AND r.id IN ('.implode(',',array_fill(0,count($ids),'?')).'))';
        array_push($params,...$ids);
    }
    $merchants=$pdo->prepare("SELECT m.id FROM merchant_accounts m
        WHERE m.owner_user_id=? AND m.status='active' AND m.sandbox_mode=0 $filters ORDER BY m.id"
        .($refs===null?' LIMIT 21':''));
    $merchants->execute($params);
    $merchantIds=$merchants->fetchAll(PDO::FETCH_COLUMN);
    $limited=$refs===null && count($merchantIds)>20;
    if ($limited) $merchantIds=array_slice($merchantIds,0,20);
    $allowed=array_values(array_filter(array_map('intval',$merchantIds),static fn(int $id): bool =>
        campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.view')
        && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'analytics.view')));
    if (!$allowed) return $out;
    $out['coverage']=$limited?'limited':'ready';

    $at=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $record="CASE WHEN JSON_VALID(r.evidence_refs_json) THEN r.evidence_refs_json ELSE '{}' END";
    $params=[...$allowed,$owner,$at,$at];
    if ($refs!==null) {
        $filters=' AND r.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        array_push($params,...$ids);
    } else {
        // The Campaign scheduler can propose an unchanged finding again after seven days.
        // Keep the first proposal for the same aggregate evidence, including reviewed proposals.
        $prior="CASE WHEN JSON_VALID(earlier.evidence_refs_json) THEN earlier.evidence_refs_json ELSE '{}' END";
        $priorHash="JSON_EXTRACT($prior,'$.evidence_hash')";
        $priorSource="JSON_EXTRACT($prior,'$.source')";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
            $priorHash="JSON_UNQUOTE($priorHash)";
            $priorSource="JSON_UNQUOTE($priorSource)";
        }
        $filters=" AND NOT EXISTS (
            SELECT 1 FROM campaign_agent_recommendations earlier
            JOIN campaign_optimization_snapshots prior_snapshot ON prior_snapshot.merchant_id=earlier.merchant_id
              AND prior_snapshot.campaign_id=earlier.campaign_id AND prior_snapshot.status='observed'
              AND prior_snapshot.id=JSON_EXTRACT($prior,'$.snapshot_id')
              AND prior_snapshot.evidence_hash=$priorHash
            WHERE earlier.merchant_id=r.merchant_id AND earlier.campaign_id=r.campaign_id
              AND earlier.created_for_user_id=r.created_for_user_id AND earlier.contact_id IS NULL
              AND earlier.status IN ('proposed','accepted','dismissed')
              AND earlier.recommendation_type=r.recommendation_type AND earlier.id<r.id
              AND $priorSource='v126_optimization_snapshot'
              AND JSON_EXTRACT($prior,'$.evidence')=JSON_EXTRACT($record,'$.evidence')
        )";
    }
    $slots=implode(',',array_fill(0,count($allowed),'?'));
    $query=$pdo->prepare("SELECT r.id,r.merchant_id,r.campaign_id,r.recommendation_type,r.summary,r.evidence_refs_json,
        c.name AS campaign_name,c.current_version_no,s.id AS snapshot_id,s.evidence_hash,s.recommendations_json
        FROM campaign_agent_recommendations r
        JOIN campaigns c ON c.id=r.campaign_id AND c.merchant_id=r.merchant_id
        LEFT JOIN campaign_optimization_snapshots s ON s.merchant_id=r.merchant_id AND s.campaign_id=r.campaign_id
            AND s.id=JSON_EXTRACT($record,'$.snapshot_id') AND s.status='observed'
        WHERE r.merchant_id IN ($slots) AND r.created_for_user_id=? AND r.contact_id IS NULL AND r.status='proposed'
          AND r.recommendation_type IN ('optimization.offer-eligibility','optimization.fatigue',
              'optimization.low-observed-conversion','optimization.conflict-volume')
          AND c.status='active' AND c.environment='production'
          AND (c.starts_at IS NULL OR c.starts_at<=?) AND (c.ends_at IS NULL OR c.ends_at>?) $filters
        ORDER BY r.id DESC".($refs===null?' LIMIT 13':''));
    $query->execute($params);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    if ($refs===null && count($rows)>12) {
        $rows=array_slice($rows,0,12);
        $out['coverage']='limited';
    }
    foreach ($rows as $row) {
        $item=vp3_coordination_marketing_item_v1($row);
        if ($item===null) $out['coverage']='limited';
        else $out['items'][$item['ref']]=$item;
    }
    return $out;
}

function vp3_coordination_campaign_marketing_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $recommendationRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'campaign-optimization:')));
    $reportRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>
        preg_match('/\Acampaign-(?:readiness|outcome|performance):[1-9][0-9]{0,17}\z/D',$ref)===1));
    $workRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>
        preg_match('/\Acampaign-work:[1-9][0-9]{0,17}\z/D',$ref)===1));
    $recommendations=$recommendationRefs===[]?null:vp3_coordination_marketing_recommendations_v1($pdo,$scope,$recommendationRefs,$now);
    $review=null;
    if($reportRefs!==[]){
        require_once __DIR__.'/agent-marketing-review-v1.php';
        $report=vp3_marketing_review_v1($pdo,$scope,$now,$reportRefs);
        $review=['items'=>vp3_marketing_review_findings_v1($report,$reportRefs,$now),
            'coverage'=>$report['coverage']];
    }
    $work=null;
    if($workRefs!==[]){
        require_once __DIR__.'/initiative-marketing-recommendation-links-v1.php';
        $work=vp3_marketing_recommendation_sources_v1($pdo,$scope,$workRefs,$now);
    }
    $sources=array_filter([$review,$recommendations,$work],static fn($value):bool=>$value!==null);
    if(!$sources)return ['items'=>[],'coverage'=>'unconfigured'];
    $items=[];$states=[];
    foreach($sources as $source){$items+=$source['items'];$states[]=$source['coverage'];}
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    $coverage=vp3_product_marketing_coverage_v1($states);
    if($refs===null&&count($items)>24){$items=array_slice($items,0,24,true);$coverage='limited';}
    return ['items'=>$items,'coverage'=>$coverage];
}

/** Product plans are independent of the optional Campaigns plugin. */
function vp3_coordination_marketing_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    $campaignRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,'campaign-')));
    $productRefs=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>!str_starts_with($ref,'campaign-')));
    try{
        $campaign=$campaignRefs===[]?['items'=>[],'coverage'=>'unconfigured']
            :vp3_coordination_campaign_marketing_sources_v1($pdo,$scope,$campaignRefs,$now);
    }catch(PDOException $error){
        if(vp3_profile_analytics_retryable_v1($error))throw $error;
        $campaign=['items'=>[],'coverage'=>'unavailable'];
    }
    try{
        $product=$productRefs===[]?['items'=>[],'coverage'=>'unconfigured']
            :vp3_coordination_product_marketing_sources_v1($pdo,$scope,$productRefs,$now);
    }catch(PDOException $error){
        if(vp3_profile_analytics_retryable_v1($error))throw $error;
        $product=['items'=>[],'coverage'=>'unavailable'];
    }
    $items=$campaign['items']+$product['items'];
    $coverage=vp3_product_marketing_coverage_v1([$campaign['coverage'],$product['coverage']]);
    if($refs===null&&count($items)>24){$items=array_slice($items,0,24,true);$coverage='limited';}
    return ['items'=>$items,'coverage'=>$coverage];
}

function vp3_coordination_marketing_item_v1(array $row): ?array
{
    if ((int)$row['snapshot_id']<1 || strlen($row['evidence_refs_json'])>8192
        || !is_string($row['recommendations_json']) || strlen($row['recommendations_json'])>16384) return null;
    try {
        $source=json_decode($row['evidence_refs_json'],true,16,JSON_THROW_ON_ERROR);
        $recommendations=json_decode($row['recommendations_json'],true,16,JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (($source['source']??null)!=='v126_optimization_snapshot' || ($source['snapshot_id']??null)!==(int)$row['snapshot_id']
        || !is_string($source['evidence_hash']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$source['evidence_hash'])
        || !hash_equals((string)$row['evidence_hash'],$source['evidence_hash']) || !is_array($source['evidence']??null)
        || !is_array($recommendations) || !array_is_list($recommendations)) return null;
    $kind=substr($row['recommendation_type'],strlen('optimization.'));
    foreach ($recommendations as $record) {
        if (($record['key']??null)===$kind && ($record['summary']??null)===$row['summary']
            && ($record['evidence']??null)===$source['evidence']) {
            $details=vp3_coordination_marketing_details_v1($kind,$source['evidence']);
            if ($details===null) return null;
            [$title,$summary,$next]=$details;
            $campaign=(int)$row['campaign_id'];
            $merchant=(int)$row['merchant_id'];
            $ref='marketing:campaign-optimization:'.(int)$row['id'];
            if (!vp3_coordination_source_ref_valid_v1($ref)) return null;
            return vp3_coordination_source_item_v1($ref,'marketing',$row['campaign_name'].' · '.$title,$summary,$next,
                'Would you like to review this campaign recommendation?',
                url('/campaigns.php?merchant='.$merchant.'&edit_campaign='.$campaign.'#campaign-optimization-v126'),'open','normal',
                [$merchant,$campaign,(int)$row['current_version_no'],$row['campaign_name'],$kind,$source['evidence_hash'],$summary])
                +['trigger'=>'optimization_ready'];
        }
    }
    return null;
}

/** Use an explicit metric projection. The snapshot may also contain per-contact fatigue data. */
function vp3_coordination_marketing_details_v1(string $kind, array $metrics): ?array
{
    $count=static fn(string $key,int $min=0): ?int => is_int($metrics[$key]??null) && $metrics[$key]>=$min ? $metrics[$key] : null;
    if ($kind==='offer-eligibility' && ($offers=$count('offers',10))!==null && ($unavailable=$count('unavailable'))!==null
        && $unavailable<=$offers && $unavailable/$offers>=0.25) {
        return ['Reward eligibility',"$unavailable of $offers offer decisions had no eligible reward.",
            'Review the attached rewards, their eligibility, inventory, and limits.'];
    }
    if ($kind==='fatigue' && ($contacts=$count('high_fatigue_contacts',5))!==null && ($days=$count('window_days',7))!==null && $days<=365) {
        return ['Campaign frequency',"$contacts contacts received repeated sends with no observed view or conversion over $days days.",
            'Review audience cadence and message frequency controls.'];
    }
    $rate=$metrics['observed_treatment_conversion_rate']??null;
    if ($kind==='low-observed-conversion' && ($entries=$count('treatment_entries',20))!==null
        && (is_int($rate)||is_float($rate)) && $rate>=0 && $rate<2.0) {
        return ['Observed conversion',"$rate% observed conversion across $entries admitted entries.",
            'Review the campaign copy, audience, rewards, and journey branches before changing the live campaign.'];
    }
    if ($kind==='conflict-volume' && ($suppressed=$count('conflict_suppressed',10))!==null) {
        return ['Campaign overlap',"Campaign conflict controls suppressed $suppressed entries.",
            'Review which campaigns overlap and when each can reach the audience.'];
    }
    return null;
}
