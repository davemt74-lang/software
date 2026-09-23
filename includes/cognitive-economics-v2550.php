<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.50 — Cost & Resource Economics.
 *
 * Reads the existing AI execution ledger, subscription/token balance and
 * canonical goal→workflow lineage. It creates no billing/cost/token store and
 * owns no route, admission, claim, lease, execution or receipt authority.
 */
const VP3_COGNITIVE_ECONOMICS_V2550='vp3-cognitive-economics-v2550-20260923';
const VP3_COGNITIVE_ECONOMICS_CONTRACT_V2550='cognitive-cost-resource-economics-v1';
const VP3_COGNITIVE_ECONOMICS_LOOKBACK_DAYS_V2550=30;
const VP3_COGNITIVE_ECONOMICS_MAX_ITEMS_V2550=8;
const VP3_COGNITIVE_ECONOMICS_MAX_RUN_IDS_V2550=160;

function vp3_cognitive_economics_ready_v2550(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo&&function_exists('vp3_cognitive_portfolio_capacity_v2480'));
}

function vp3_cognitive_economics_usage_v2550(PDO $pdo,int $uid,int $days=VP3_COGNITIVE_ECONOMICS_LOOKBACK_DAYS_V2550): array
{
    $empty=[
        'available'=>false,'window_days'=>$days,'requests'=>0,'known_cost_requests'=>0,
        'unknown_cost_requests'=>0,'known_cost_micros'=>0,'total_tokens'=>0,
        'cloud_tokens_charged'=>0,'local_requests'=>0,'cloud_requests'=>0,
        'average_known_cost_micros'=>null,'cloud_known_cost_requests'=>0,
        'cloud_known_cost_micros'=>0,'cloud_average_known_cost_micros'=>null,'by_source'=>[],
        'authority'=>'ai_execution_ledger_v032',
    ];
    if($uid<1||!function_exists('table_exists')||!table_exists('ai_execution_ledger'))return $empty;
    $days=max(1,min(90,$days));
    try{
        $s=$pdo->prepare("SELECT
            COUNT(*) requests,
            SUM(CASE WHEN estimated_cost_micros IS NOT NULL THEN 1 ELSE 0 END) known_cost_requests,
            SUM(CASE WHEN estimated_cost_micros IS NULL THEN 1 ELSE 0 END) unknown_cost_requests,
            COALESCE(SUM(estimated_cost_micros),0) known_cost_micros,
            COALESCE(SUM(total_tokens),0) total_tokens,
            COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens_charged,
            COALESCE(SUM(source='homeserver_local'),0) local_requests,
            COALESCE(SUM(source='vp3_cloud'),0) cloud_requests,
            SUM(CASE WHEN source='vp3_cloud' AND estimated_cost_micros IS NOT NULL THEN 1 ELSE 0 END) cloud_known_cost_requests,
            COALESCE(SUM(CASE WHEN source='vp3_cloud' THEN estimated_cost_micros ELSE 0 END),0) cloud_known_cost_micros
            FROM ai_execution_ledger
            WHERE user_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)");
        $s->execute([$uid]);$row=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $by=$pdo->prepare("SELECT source,COUNT(*) requests,
            SUM(CASE WHEN estimated_cost_micros IS NOT NULL THEN 1 ELSE 0 END) known_cost_requests,
            SUM(CASE WHEN estimated_cost_micros IS NULL THEN 1 ELSE 0 END) unknown_cost_requests,
            COALESCE(SUM(estimated_cost_micros),0) known_cost_micros,
            COALESCE(SUM(total_tokens),0) total_tokens,
            COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens_charged
            FROM ai_execution_ledger
            WHERE user_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)
            GROUP BY source ORDER BY requests DESC,source");
        $by->execute([$uid]);$bySource=$by->fetchAll(PDO::FETCH_ASSOC)?:[];
        $known=max(0,(int)($row['known_cost_requests']??0));
        $cost=max(0,(int)($row['known_cost_micros']??0));
        $cloudKnown=max(0,(int)($row['cloud_known_cost_requests']??0));
        $cloudCost=max(0,(int)($row['cloud_known_cost_micros']??0));
        return [
            'available'=>true,'window_days'=>$days,
            'requests'=>max(0,(int)($row['requests']??0)),
            'known_cost_requests'=>$known,
            'unknown_cost_requests'=>max(0,(int)($row['unknown_cost_requests']??0)),
            'known_cost_micros'=>$cost,
            'total_tokens'=>max(0,(int)($row['total_tokens']??0)),
            'cloud_tokens_charged'=>max(0,(int)($row['cloud_tokens_charged']??0)),
            'local_requests'=>max(0,(int)($row['local_requests']??0)),
            'cloud_requests'=>max(0,(int)($row['cloud_requests']??0)),
            'average_known_cost_micros'=>$known>0?(int)round($cost/$known):null,
            'cloud_known_cost_requests'=>$cloudKnown,
            'cloud_known_cost_micros'=>$cloudCost,
            'cloud_average_known_cost_micros'=>$cloudKnown>0?(int)round($cloudCost/$cloudKnown):null,
            'by_source'=>$bySource,
            'authority'=>'ai_execution_ledger_v032',
        ];
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_economics_quota_v2550(array $user,?PDO $pdo=null): array
{
    $base=[
        'available'=>false,'state'=>'unavailable','remaining'=>null,'available_tokens'=>null,
        'used'=>null,'reserved'=>null,'package_allowance'=>null,'credits_remaining'=>null,
        'unlimited'=>false,'pressure'=>0.0,'remaining_ratio'=>null,
        'authority'=>'subscription_ai_balance',
    ];
    if(!function_exists('subscription_ai_balance'))return $base;
    try{$balance=subscription_ai_balance($user,$pdo,false);}catch(Throwable $e){return $base;}
    $unlimited=!empty($balance['unlimited'])||!empty($balance['compatibility']);
    if($unlimited)return array_merge($base,[
        'available'=>true,'state'=>'unlimited','unlimited'=>true,'pressure'=>0.0,
        'remaining'=>(int)($balance['remaining']??PHP_INT_MAX),
        'available_tokens'=>(int)($balance['available']??PHP_INT_MAX),
        'used'=>max(0,(int)($balance['used']??0)),
        'reserved'=>max(0,(int)($balance['reserved']??0)),
        'package_allowance'=>max(0,(int)($balance['package_allowance']??0)),
        'credits_remaining'=>max(0,(int)($balance['credits_remaining']??0)),
    ]);
    $remaining=max(0,(int)($balance['remaining']??0));
    $used=max(0,(int)($balance['used']??0));
    $reserved=max(0,(int)($balance['reserved']??0));
    $pool=max(0,$used+$remaining+$reserved);
    $ratio=$pool>0?max(0.0,min(1.0,$remaining/$pool)):0.0;
    $pressure=round(max(0.0,min(1.0,1.0-$ratio)),4);
    $state=$remaining<=0?'exhausted':($ratio<0.10?'critical':($ratio<0.25?'constrained':($ratio<0.50?'watch':'healthy')));
    return array_merge($base,[
        'available'=>true,'state'=>$state,'remaining'=>$remaining,
        'available_tokens'=>max(0,(int)($balance['available']??0)),
        'used'=>$used,'reserved'=>$reserved,
        'package_allowance'=>max(0,(int)($balance['package_allowance']??0)),
        'credits_remaining'=>max(0,(int)($balance['credits_remaining']??0)),
        'unlimited'=>false,'pressure'=>$pressure,'remaining_ratio'=>round($ratio,4),
    ]);
}

function vp3_cognitive_economics_run_costs_v2550(PDO $pdo,int $uid,array $runIds,int $days=VP3_COGNITIVE_ECONOMICS_LOOKBACK_DAYS_V2550): array
{
    $runIds=array_values(array_unique(array_filter(array_map('intval',$runIds),static fn(int $id): bool=>$id>0)));
    $runIds=array_slice($runIds,0,VP3_COGNITIVE_ECONOMICS_MAX_RUN_IDS_V2550);
    if($uid<1||!$runIds||!function_exists('table_exists')||!table_exists('ai_execution_ledger'))return [];
    $days=max(1,min(90,$days));$ph=implode(',',array_fill(0,count($runIds),'?'));
    try{
        $stmt=$pdo->prepare("SELECT run_id,COUNT(*) requests,
            SUM(CASE WHEN estimated_cost_micros IS NOT NULL THEN 1 ELSE 0 END) known_cost_requests,
            SUM(CASE WHEN estimated_cost_micros IS NULL THEN 1 ELSE 0 END) unknown_cost_requests,
            COALESCE(SUM(estimated_cost_micros),0) known_cost_micros,
            COALESCE(SUM(total_tokens),0) total_tokens,
            COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens_charged,
            COALESCE(SUM(source='vp3_cloud'),0) cloud_requests,
            COALESCE(SUM(source='homeserver_local'),0) local_requests
            FROM ai_execution_ledger
            WHERE user_id=? AND run_id IN ({$ph})
              AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)
            GROUP BY run_id");
        $stmt->execute(array_merge([$uid],$runIds));$out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $runId=(int)($row['run_id']??0);if($runId<1)continue;
            $known=max(0,(int)($row['known_cost_requests']??0));
            $cost=max(0,(int)($row['known_cost_micros']??0));
            $out[$runId]=[
                'run_id'=>$runId,'requests'=>max(0,(int)($row['requests']??0)),
                'known_cost_requests'=>$known,
                'unknown_cost_requests'=>max(0,(int)($row['unknown_cost_requests']??0)),
                'known_cost_micros'=>$cost,'total_tokens'=>max(0,(int)($row['total_tokens']??0)),
                'cloud_tokens_charged'=>max(0,(int)($row['cloud_tokens_charged']??0)),
                'cloud_requests'=>max(0,(int)($row['cloud_requests']??0)),
                'local_requests'=>max(0,(int)($row['local_requests']??0)),
                'average_known_cost_micros'=>$known>0?(int)round($cost/$known):null,
            ];
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_economics_efficiency_v2550(?int $goalAverage,?int $accountAverage): float
{
    if($goalAverage===null||$accountAverage===null||$accountAverage<=0)return 0.5;
    $index=max(0.0,$goalAverage/$accountAverage);
    return round(max(0.0,min(1.0,1.0/(1.0+$index))),4);
}

function vp3_cognitive_economics_adjustment_v2550(array $item,float $quotaPressure,float $efficiency,bool $costKnown): float
{
    // Explicit commitments are protected by v25.40 and are never deprioritized
    // for cost. Manual/supervised work retains its authority as well.
    if((float)($item['commitment_protection_score']??0)>=0.65)return 0.0;
    if((string)($item['execution_mode']??'manual')!=='autonomous')return 0.0;
    if((string)($item['executor']??'cloud')!=='cloud')return 0.0;
    if($quotaPressure<0.25||!$costKnown)return 0.0;
    return round(max(-0.10,min(0.08,($efficiency-0.5)*0.20*$quotaPressure)),4);
}

function vp3_cognitive_economics_apply_v2550(
    PDO $pdo,array $user,array $items,array $capacity,int $now=0
): array {
    $uid=(int)($user['id']??0);
    $usage=vp3_cognitive_economics_usage_v2550($pdo,$uid);
    $quota=vp3_cognitive_economics_quota_v2550($user,$pdo);
    $runIds=[];$runGoalCounts=[];
    foreach($items as $item){
        $unique=array_values(array_unique(array_filter(array_map('intval',(array)($item['workflow_run_ids']??[])),static fn(int $id): bool=>$id>0)));
        foreach($unique as $runId){$runIds[]=$runId;$runGoalCounts[$runId]=($runGoalCounts[$runId]??0)+1;}
    }
    $runCosts=vp3_cognitive_economics_run_costs_v2550($pdo,$uid,$runIds);
    $accountAvg=is_int($usage['cloud_average_known_cost_micros']??null)
        ?$usage['cloud_average_known_cost_micros']
        :(is_int($usage['average_known_cost_micros']??null)?$usage['average_known_cost_micros']:null);
    $economics=[];

    foreach($items as &$item){
        if(!is_array($item))continue;
        $goalRunIds=array_values(array_unique(array_filter(array_map('intval',(array)($item['workflow_run_ids']??[])),static fn(int $id): bool=>$id>0)));
        $requests=0;$known=0;$unknown=0;$cost=0;$tokens=0;$cloudTokens=0;$cloudRequests=0;$localRequests=0;
        $attributedCost=0.0;$attributedKnown=0.0;$attributedTokens=0.0;$attributedCloudTokens=0.0;
        foreach($goalRunIds as $runId){
            $row=$runCosts[$runId]??null;if(!$row)continue;
            $share=max(1,(int)($runGoalCounts[$runId]??1));
            $requests+=(int)$row['requests'];$known+=(int)$row['known_cost_requests'];
            $unknown+=(int)$row['unknown_cost_requests'];$cost+=(int)$row['known_cost_micros'];
            $tokens+=(int)$row['total_tokens'];$cloudTokens+=(int)$row['cloud_tokens_charged'];
            $cloudRequests+=(int)$row['cloud_requests'];$localRequests+=(int)$row['local_requests'];
            $attributedCost+=((int)$row['known_cost_micros'])/$share;
            $attributedKnown+=((int)$row['known_cost_requests'])/$share;
            $attributedTokens+=((int)$row['total_tokens'])/$share;
            $attributedCloudTokens+=((int)$row['cloud_tokens_charged'])/$share;
        }
        $attributedCostMicros=max(0,(int)round($attributedCost));
        $goalAvg=$attributedKnown>0?(int)round($attributedCost/$attributedKnown):null;
        $efficiency=vp3_cognitive_economics_efficiency_v2550($goalAvg,$accountAvg);
        $relative=($goalAvg!==null&&$accountAvg!==null&&$accountAvg>0)?round($goalAvg/$accountAvg,4):null;
        $costKnown=$known>0;
        $quotaPressure=(float)($quota['pressure']??0.0);
        $adjustment=vp3_cognitive_economics_adjustment_v2550($item,$quotaPressure,$efficiency,$costKnown);
        $cloudExposed=(string)($item['executor']??'cloud')==='cloud';
        $attention=round(max(0.0,min(1.5,
            ($cloudExposed?$quotaPressure*0.55:0.0)
            +($costKnown?max(0.0,min(1.0,($relative??1.0)-1.0))*0.25:0.0)
            +($unknown>0?0.12:0.0)
        )),4);
        $row=[
            'goal_id'=>(int)($item['goal_id']??0),'title'=>(string)($item['title']??''),
            'executor'=>(string)($item['executor']??'cloud'),'workflow_run_ids'=>$goalRunIds,
            'historical_requests'=>$requests,'known_cost_requests'=>$known,
            'unknown_cost_requests'=>$unknown,'linked_known_cost_micros'=>$cost,
            'attributed_known_cost_micros'=>$attributedCostMicros,
            'linked_total_tokens'=>$tokens,'attributed_total_tokens'=>max(0,(int)round($attributedTokens)),
            'linked_cloud_tokens_charged'=>$cloudTokens,'attributed_cloud_tokens_charged'=>max(0,(int)round($attributedCloudTokens)),
            'historical_cloud_requests'=>$cloudRequests,'historical_local_requests'=>$localRequests,
            'average_attributed_known_cost_micros'=>$goalAvg,'account_cloud_average_known_cost_micros'=>$accountAvg,
            'relative_cost_index'=>$relative,'efficiency_score'=>$efficiency,
            'quota_state'=>(string)($quota['state']??'unavailable'),
            'quota_pressure'=>$quotaPressure,'cloud_exposed'=>$cloudExposed,
            'planning_adjustment'=>$adjustment,'attention_score'=>$attention,
            'commitment_exempt'=>(float)($item['commitment_protection_score']??0)>=0.65,
            'cost_known'=>$costKnown,'cost_attribution_non_additive'=>true,
            'recommendation'=>$adjustment<0?'defer_if_safe':($adjustment>0?'prefer_if_equivalent':($unknown>0?'price_unknown':'neutral')),
            'authority'=>'ai_execution_ledger_v032_and_subscription_ai_balance',
        ];
        $economics[]=$row;
        $item['economic_efficiency_score']=$efficiency;
        $item['economic_planning_adjustment']=$adjustment;
        $item['economic_attention_score']=$attention;
        $item['economic_cost_known']=$costKnown;
        $item['economic_known_cost_micros']=$attributedCostMicros;
        $item['economic_unknown_cost_requests']=$unknown;
        $item['economic_quota_state']=(string)($quota['state']??'unavailable');
    }
    unset($item);

    usort($economics,static function(array $a,array $b): int {
        $x=((float)($b['attention_score']??0))<=>((float)($a['attention_score']??0));if($x!==0)return $x;
        $x=((int)($b['attributed_known_cost_micros']??0))<=>((int)($a['attributed_known_cost_micros']??0));if($x!==0)return $x;
        return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
    });
    $economics=array_slice($economics,0,VP3_COGNITIVE_ECONOMICS_MAX_ITEMS_V2550);
    $priced=array_values(array_filter($economics,static fn(array $x): bool=>!empty($x['cost_known'])));
    $unknown=array_values(array_filter($economics,static fn(array $x): bool=>(int)($x['unknown_cost_requests']??0)>0));
    $adjusted=array_values(array_filter($economics,static fn(array $x): bool=>(float)($x['planning_adjustment']??0)!==0.0));
    $exempt=array_values(array_filter($economics,static fn(array $x): bool=>!empty($x['commitment_exempt'])));

    return [
        'items'=>$items,
        'economics'=>[
            'build'=>VP3_COGNITIVE_ECONOMICS_V2550,
            'contract'=>VP3_COGNITIVE_ECONOMICS_CONTRACT_V2550,
            'focus'=>$economics[0]??null,'goals'=>$economics,
            'usage'=>$usage,'quota'=>$quota,
            'counts'=>[
                'goals'=>count($economics),'priced_goals'=>count($priced),
                'unknown_pricing_goals'=>count($unknown),'planning_adjusted'=>count($adjusted),
                'commitment_exempt'=>count($exempt),
            ],
            'policy'=>[
                'explicit_cost_budget_micros'=>null,
                'no_fabricated_cost_budget'=>true,
                'unknown_pricing_is_not_zero'=>true,
                'shared_run_cost_is_proportionally_attributed'=>true,
                'goal_attribution_is_non_additive'=>true,
                'commitments_outrank_economics'=>true,
                'economics_can_block_execution'=>false,
                'economics_can_change_executor'=>false,
                'economics_can_change_deadline'=>false,
            ],
            'authority'=>[
                'usage_cost'=>'ai_execution_ledger_v032',
                'token_balance'=>'subscription_ai_balance',
                'cloud_entitlement_and_token_enforcement'=>'subscription_quota_and_ai_gateway',
                'portfolio_admission'=>'cognitive_portfolio_v2480',
                'replanning'=>'cognitive_replanning_v2530',
                'resource_budget'=>'cognitive_resource_budget_v2520',
                'commitments'=>'cognitive_commitment_protection_v2540',
                'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
            ],
            'projection_only'=>true,
        ],
    ];
}

function vp3_cognitive_economics_snapshot_v2550(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_ECONOMICS_V2550,'contract'=>VP3_COGNITIVE_ECONOMICS_CONTRACT_V2550,
        'focus'=>null,'goals'=>[],'usage'=>[],'quota'=>[],'counts'=>[],'projection_only'=>true,
    ];
    if(!vp3_cognitive_economics_ready_v2550($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}catch(Throwable $e){return $empty;}
    if(is_array($portfolio['economics']??null))return $portfolio['economics'];
    try{
        $applied=vp3_cognitive_economics_apply_v2550(
            $pdo,$user,(array)($portfolio['items']??[]),(array)($portfolio['capacity']??[])
        );
        return (array)($applied['economics']??$empty);
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_economics_context_item_v2550(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_economics_snapshot_v2550($pdo,$user);
    if(empty($snapshot['usage'])&&empty($snapshot['quota']))return null;
    $json=json_encode([
        'focus'=>$snapshot['focus']??null,'counts'=>$snapshot['counts']??[],
        'usage'=>$snapshot['usage']??[],'quota'=>$snapshot['quota']??[],
        'goals'=>array_slice((array)($snapshot['goals']??[]),0,6),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'economics','cognitive-economics:v2550','Cost and resource economics',$json,95.985,
        ['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_economics_activity_projection_v2550(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_ECONOMICS_V2550,'focus'=>null,'goals'=>[],
        'usage'=>[],'quota'=>[],'counts'=>[],'projection_only'=>true,
    ];
    $snapshot=vp3_cognitive_economics_snapshot_v2550($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_ECONOMICS_V2550,
        'focus'=>$snapshot['focus']??null,
        'goals'=>array_slice((array)($snapshot['goals']??[]),0,8),
        'usage'=>$snapshot['usage']??[],'quota'=>$snapshot['quota']??[],
        'counts'=>$snapshot['counts']??[],'projection_only'=>true,
    ];
}
