<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.20 — Resource Budgeting & Capacity Reservations.
 *
 * Reservations in this layer are derived admission budgets, not worker leases.
 * They are recomputed from canonical goal/deadline/capacity evidence and may
 * only reserve v24.80 admission capacity for autonomous work. Phase 19 remains
 * the sole worker claim/lease/execution/receipt authority.
 */
const VP3_COGNITIVE_RESOURCE_BUDGET_V2520='vp3-cognitive-resource-budget-v2520-20260922';
const VP3_COGNITIVE_RESOURCE_BUDGET_CONTRACT_V2520='cognitive-resource-budget-v1';
const VP3_COGNITIVE_RESOURCE_HORIZON_SECONDS_V2520=259200; // 72h
const VP3_COGNITIVE_RESOURCE_OVERDUE_GRACE_SECONDS_V2520=21600; // 6h
const VP3_COGNITIVE_RESOURCE_MIN_LEAD_SECONDS_V2520=1800; // 30m
const VP3_COGNITIVE_RESOURCE_MAX_LEAD_SECONDS_V2520=28800; // 8h
const VP3_COGNITIVE_RESOURCE_MAX_RESERVED_SLOTS_V2520=2;
const VP3_COGNITIVE_RESOURCE_MAX_RESERVATIONS_V2520=8;

function vp3_cognitive_resource_budget_ready_v2520(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('vp3_cognitive_portfolio_snapshot_v2480')
        && function_exists('vp3_cognitive_optimization_strategy_score_v2510')
        && function_exists('vp3_cognitive_forecast_deadline_pressure_v2490'));
}

function vp3_cognitive_resource_target_ts_v2520(string $targetDate): int
{
    if(function_exists('vp3_cognitive_forecast_target_ts_v2490')){
        try{return vp3_cognitive_forecast_target_ts_v2490($targetDate);}catch(Throwable $e){}
    }
    $targetDate=trim($targetDate);
    if($targetDate==='')return 0;
    $ts=strtotime($targetDate);
    return $ts===false?0:max(0,(int)$ts);
}

function vp3_cognitive_resource_work_units_v2520(array $item): float
{
    if(function_exists('vp3_cognitive_forecast_work_units_v2490')){
        try{return vp3_cognitive_forecast_work_units_v2490($item);}catch(Throwable $e){}
    }
    $progress=max(0,min(100,(int)($item['progress_percent']??0)));
    return round(max(0.25,min(8.0,ceil(max(1,100-$progress)/20))),2);
}

function vp3_cognitive_resource_executor_base_seconds_v2520(string $executor): int
{
    if($executor==='homeserver'){
        return defined('VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490')
            ?max(60,(int)VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490):1200;
    }
    return defined('VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490')
        ?max(60,(int)VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490):900;
}

function vp3_cognitive_resource_expected_seconds_v2520(array $item,string $executor): int
{
    $base=vp3_cognitive_resource_executor_base_seconds_v2520($executor);
    return max(60,(int)round($base*vp3_cognitive_resource_work_units_v2520($item)));
}

function vp3_cognitive_resource_lead_seconds_v2520(array $item,string $executor): int
{
    $expected=vp3_cognitive_resource_expected_seconds_v2520($item,$executor);
    $lead=(int)round(($expected*2.0)+900);
    return max(
        VP3_COGNITIVE_RESOURCE_MIN_LEAD_SECONDS_V2520,
        min(VP3_COGNITIVE_RESOURCE_MAX_LEAD_SECONDS_V2520,$lead)
    );
}

function vp3_cognitive_resource_reservation_score_v2520(array $item,array $capacity,int $now=0): float
{
    $now=$now>0?$now:time();
    $priority=max(1,min(100,(int)($item['priority']??50)))/100;
    $deadline=function_exists('vp3_cognitive_forecast_deadline_pressure_v2490')
        ?vp3_cognitive_forecast_deadline_pressure_v2490((string)($item['target_date']??''),$now)
        :0.18;
    $optimized=max(0.0,min(1.0,(float)($item['optimization_strategy_score']??$item['forecast_sequence_score']??$item['score']??0.0)));
    $leverage=min(1.0,count((array)($item['dependency_leverage_goal_ids']??[]))/3);
    $progress=max(0.0,min(1.0,((int)($item['progress_percent']??0))/100));
    $blockers=min(1.0,count((array)($item['blocked_by_goal_ids']??[]))/3);
    $commitment=max(0.0,min(1.0,(float)($item['commitment_protection_score']??0.0)));
    $economicAdjustment=max(-0.10,min(0.08,(float)($item['economic_planning_adjustment']??0.0)));
    $capacityPressure=0.0;
    if(function_exists('vp3_cognitive_forecast_capacity_pressure_v2490')){
        try{$capacityPressure=vp3_cognitive_forecast_capacity_pressure_v2490($item,$capacity);}
        catch(Throwable $e){$capacityPressure=0.0;}
    }
    $score=($deadline*0.34)+($priority*0.22)+($optimized*0.13)+($leverage*0.09)+($commitment*0.12)+($progress*0.05)+($capacityPressure*0.05)-($blockers*0.06)+$economicAdjustment;
    return round(max(0.0,min(1.25,$score)),4);
}

function vp3_cognitive_resource_reservation_limit_v2520(array $capacity,string $executor): int
{
    $max=max(0,(int)($capacity['executors'][$executor]['max']??0));
    if($max<1)return 0;
    $half=max(1,(int)floor($max*0.50));
    return min($max,VP3_COGNITIVE_RESOURCE_MAX_RESERVED_SLOTS_V2520,$half);
}

function vp3_cognitive_resource_reservation_candidate_v2520(
    array $item,array $capacity,int $now=0
): ?array {
    $now=$now>0?$now:time();
    if((string)($item['execution_mode']??'')!=='autonomous')return null;
    if((string)($item['hold_reason']??'')==='semantic_overlap')return null;

    $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
        ?(string)$item['executor']:'cloud';
    if(empty($capacity['executors'][$executor]['ready'])||(int)($capacity['executors'][$executor]['max']??0)<1)return null;

    $target=vp3_cognitive_resource_target_ts_v2520((string)($item['target_date']??''));
    if($target<1)return null;
    $delta=$target-$now;
    if($delta>VP3_COGNITIVE_RESOURCE_HORIZON_SECONDS_V2520)return null;
    if($delta<(-VP3_COGNITIVE_RESOURCE_OVERDUE_GRACE_SECONDS_V2520))return null;

    $lead=vp3_cognitive_resource_lead_seconds_v2520($item,$executor);
    $expected=vp3_cognitive_resource_expected_seconds_v2520($item,$executor);
    $reservedFrom=$target-$lead;
    $reservedUntil=$target+min(VP3_COGNITIVE_RESOURCE_OVERDUE_GRACE_SECONDS_V2520,max(900,$expected));

    $blocked=!empty($item['blocked_by_goal_ids']);
    $state=(string)($item['execution_state']??'unknown');
    $requiresUser=!empty($item['requires_user'])||in_array($state,['waiting_approval','review_needed','plan_missing'],true);
    $windowOpen=$now>=$reservedFrom&&$now<=$reservedUntil;
    $reservationState='planned';
    if($windowOpen&&($blocked||$requiresUser))$reservationState='conditional';
    elseif($windowOpen)$reservationState='active';
    elseif($now>$reservedUntil)$reservationState='expired';

    return [
        'goal_id'=>(int)($item['goal_id']??0),
        'title'=>(string)($item['title']??''),
        'executor'=>$executor,
        'priority'=>(int)($item['priority']??50),
        'target_date'=>(string)($item['target_date']??''),
        'reservation_score'=>vp3_cognitive_resource_reservation_score_v2520($item,$capacity,$now),
        'reservation_state'=>$reservationState,
        'reserved_from'=>gmdate('c',$reservedFrom),
        'reserved_until'=>gmdate('c',$reservedUntil),
        'expected_work_seconds'=>$expected,
        'lead_seconds'=>$lead,
        'blocked'=>$blocked,
        'requires_user'=>$requiresUser,
        'optimization_strategy'=>(string)($item['optimization_strategy']??''),
        'slot_count'=>1,
        'admission_only'=>true,
    ];
}

/**
 * Pure derived resource plan. It does not persist reservations or create
 * worker leases. Selected reservations protect only v24.80 autonomous
 * admission slots during their active window.
 */
function vp3_cognitive_resource_budget_plan_v2520(
    array $items,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    $out=[
        'build'=>VP3_COGNITIVE_RESOURCE_BUDGET_V2520,
        'contract'=>VP3_COGNITIVE_RESOURCE_BUDGET_CONTRACT_V2520,
        'horizon_seconds'=>VP3_COGNITIVE_RESOURCE_HORIZON_SECONDS_V2520,
        'focus'=>null,'reservations'=>[],
        'executors'=>[
            'cloud'=>[
                'capacity_max'=>max(0,(int)($capacity['executors']['cloud']['max']??0)),
                'capacity_free'=>max(0,(int)($capacity['executors']['cloud']['free']??0)),
                'reservation_limit'=>0,'selected_reservations'=>0,'active_reserved_slots'=>0,
                'planned_reserved_slots'=>0,'conditional_reservations'=>0,
                'active_reserved_goal_ids'=>[],'selected_goal_ids'=>[],
            ],
            'homeserver'=>[
                'capacity_max'=>max(0,(int)($capacity['executors']['homeserver']['max']??0)),
                'capacity_free'=>max(0,(int)($capacity['executors']['homeserver']['free']??0)),
                'reservation_limit'=>0,'selected_reservations'=>0,'active_reserved_slots'=>0,
                'planned_reserved_slots'=>0,'conditional_reservations'=>0,
                'active_reserved_goal_ids'=>[],'selected_goal_ids'=>[],
            ],
        ],
        'counts'=>['selected'=>0,'active'=>0,'planned'=>0,'conditional'=>0],
        'projection_only'=>true,
    ];

    foreach(['cloud','homeserver'] as $executor){
        $limit=vp3_cognitive_resource_reservation_limit_v2520($capacity,$executor);
        $out['executors'][$executor]['reservation_limit']=$limit;
        if($limit<1)continue;

        $candidates=[];
        foreach($items as $item){
            if(!is_array($item))continue;
            $candidate=vp3_cognitive_resource_reservation_candidate_v2520($item,$capacity,$now);
            if(!$candidate||(string)$candidate['executor']!==$executor||(string)$candidate['reservation_state']==='expired')continue;
            $candidate['_original_index']=count($candidates);
            $candidates[]=$candidate;
        }
        usort($candidates,static function(array $a,array $b): int {
            $stateRank=['active'=>0,'conditional'=>1,'planned'=>2];
            $x=($stateRank[$a['reservation_state']??'planned']??9)<=>
                ($stateRank[$b['reservation_state']??'planned']??9);
            if($x!==0)return $x;
            $x=((float)($b['reservation_score']??0))<=>((float)($a['reservation_score']??0));
            if($x!==0)return $x;
            $aTs=strtotime((string)($a['target_date']??''))?:PHP_INT_MAX;
            $bTs=strtotime((string)($b['target_date']??''))?:PHP_INT_MAX;
            if($aTs!==$bTs)return $aTs<=>$bTs;
            return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
        });

        $selected=array_slice($candidates,0,$limit);
        foreach($selected as &$reservation){
            unset($reservation['_original_index']);
            $reservation['selected']=true;
            $out['reservations'][]=$reservation;
            $goalId=(int)$reservation['goal_id'];
            $out['executors'][$executor]['selected_goal_ids'][]=$goalId;
            $state=(string)$reservation['reservation_state'];
            if($state==='active'){
                $out['executors'][$executor]['active_reserved_slots']++;
                $out['executors'][$executor]['active_reserved_goal_ids'][]=$goalId;
                $out['counts']['active']++;
            }elseif($state==='conditional'){
                $out['executors'][$executor]['conditional_reservations']++;
                $out['counts']['conditional']++;
            }else{
                $out['executors'][$executor]['planned_reserved_slots']++;
                $out['counts']['planned']++;
            }
            $out['counts']['selected']++;
        }
        unset($reservation);
        $out['executors'][$executor]['selected_reservations']=count($selected);
    }

    usort($out['reservations'],static function(array $a,array $b): int {
        $stateRank=['active'=>0,'conditional'=>1,'planned'=>2];
        $x=($stateRank[$a['reservation_state']??'planned']??9)<=>
            ($stateRank[$b['reservation_state']??'planned']??9);
        if($x!==0)return $x;
        $x=((float)($b['reservation_score']??0))<=>((float)($a['reservation_score']??0));
        if($x!==0)return $x;
        return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
    });
    $out['reservations']=array_slice($out['reservations'],0,VP3_COGNITIVE_RESOURCE_MAX_RESERVATIONS_V2520);
    $out['focus']=$out['reservations'][0]??null;
    $out['authority']=[
        'resource_budget'=>'cognitive_resource_budget_v2520',
        'portfolio_admission'=>'cognitive_portfolio_v2480',
        'forecast'=>'cognitive_forecast_v2490',
        'optimization'=>'cognitive_optimization_v2510',
        'worker_claims_and_leases'=>'agent_job_engine_v1900',
        'live_capability_readiness'=>'agent_worker_runtime_v1910',
        'reservation_store'=>false,
        'worker_lease_authority'=>false,
        'execution_authority'=>false,
    ];
    return $out;
}

/**
 * Apply active reservations to the already-sorted v24.80 claim candidate set.
 * A reserved candidate consumes its protected slot. If the reserved goal is
 * not yet claimable, its slot remains held from unrelated autonomous claims.
 */
function vp3_cognitive_resource_claim_budget_v2520(
    string $executor,int $free,array $candidates,array $plan
): array {
    $executor=in_array($executor,['cloud','homeserver'],true)?$executor:'cloud';
    $free=max(0,$free);
    $activeIds=array_values(array_unique(array_map(
        'intval',(array)($plan['executors'][$executor]['active_reserved_goal_ids']??[])
    )));
    $candidateByGoal=[];$candidateIds=[];
    foreach($candidates as $candidate){
        if(!is_array($candidate))continue;
        $goalId=(int)($candidate['goal_id']??0);if($goalId<1)continue;
        $candidateByGoal[$goalId]=$candidate;$candidateIds[]=$goalId;
    }

    $admitted=[];$reservedAdmitted=[];$remaining=$free;
    foreach($activeIds as $goalId){
        if($remaining<1)break;
        if(isset($candidateByGoal[$goalId])){
            $admitted[]=$goalId;$reservedAdmitted[]=$goalId;$remaining--;
        }
    }

    $outstanding=0;
    foreach($activeIds as $goalId){
        if(in_array($goalId,$reservedAdmitted,true))continue;
        $outstanding++;
    }
    $heldSlots=min($remaining,$outstanding);
    $unreservedBudget=max(0,$remaining-$heldSlots);
    $unreservedAdmitted=[];
    foreach($candidateIds as $goalId){
        if($unreservedBudget<1)break;
        if(in_array($goalId,$admitted,true)||in_array($goalId,$activeIds,true))continue;
        $admitted[]=$goalId;$unreservedAdmitted[]=$goalId;$unreservedBudget--;
    }

    return [
        'executor'=>$executor,
        'free_before'=>$free,
        'active_reserved_goal_ids'=>$activeIds,
        'reserved_admitted_goal_ids'=>$reservedAdmitted,
        'unreserved_admitted_goal_ids'=>$unreservedAdmitted,
        'admitted_goal_ids'=>$admitted,
        'held_reserved_slots'=>$heldSlots,
        'remaining_unreserved_free'=>$unreservedBudget,
        'admission_only'=>true,
    ];
}

function vp3_cognitive_resource_snapshot_v2520(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_RESOURCE_BUDGET_V2520,
        'contract'=>VP3_COGNITIVE_RESOURCE_BUDGET_CONTRACT_V2520,
        'focus'=>null,'reservations'=>[],'executors'=>[],'counts'=>[],
        'projection_only'=>true,
    ];
    if(!vp3_cognitive_resource_budget_ready_v2520($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
    catch(Throwable $e){return $empty;}
    $plan=is_array($portfolio['resource_budget']??null)
        ?$portfolio['resource_budget']
        :vp3_cognitive_resource_budget_plan_v2520(
            (array)($portfolio['items']??[]),(array)($portfolio['capacity']??[])
        );
    $plan['portfolio_counts']=(array)($portfolio['counts']??[]);
    return $plan;
}

function vp3_cognitive_resource_context_item_v2520(
    PDO $pdo,array $user,string $namespace
): ?array {
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_resource_snapshot_v2520($pdo,$user);
    if(empty($snapshot['reservations']))return null;
    $json=json_encode([
        'focus'=>$snapshot['focus']??null,
        'counts'=>$snapshot['counts']??[],
        'executors'=>$snapshot['executors']??[],
        'reservations'=>array_slice((array)($snapshot['reservations']??[]),0,6),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'resource_budget','cognitive-resource-budget:v2520','Resource budget and capacity reservations',
        $json,95.95,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_resource_activity_projection_v2520(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_RESOURCE_BUDGET_V2520,'focus'=>null,'reservations'=>[],
        'executors'=>[],'counts'=>[],'projection_only'=>true,
    ];
    $snapshot=vp3_cognitive_resource_snapshot_v2520($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_RESOURCE_BUDGET_V2520,
        'focus'=>$snapshot['focus']??null,
        'reservations'=>array_slice((array)($snapshot['reservations']??[]),0,8),
        'executors'=>$snapshot['executors']??[],
        'counts'=>$snapshot['counts']??[],
        'projection_only'=>true,
    ];
}
