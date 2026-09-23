<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.10 — Strategic Portfolio Optimization.
 *
 * This layer compares bounded portfolio strategies using existing v24.90
 * forecast evidence and v24.80 goal coordination data. It is advisory only:
 * it may change the order presented back to v24.90, but it cannot admit work,
 * mutate execution targets, claim or lease jobs, approve actions, or execute.
 *
 * Authority remains:
 * v25.10 optimize -> v24.90 forecast/sequence -> v24.80 admission
 * -> v24.70 bounded autonomous mutation -> Phase 19 claim/lease/execute/receipt.
 */
const VP3_COGNITIVE_OPTIMIZATION_V2510='vp3-cognitive-optimization-v2510-20260922';
const VP3_COGNITIVE_OPTIMIZATION_CONTRACT_V2510='cognitive-strategic-portfolio-optimization-v1';
const VP3_COGNITIVE_OPTIMIZATION_MAX_ITEMS_V2510=8;
const VP3_COGNITIVE_OPTIMIZATION_HOLD_SECONDS_V2510=86400;

function vp3_cognitive_optimization_ready_v2510(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('vp3_cognitive_forecast_snapshot_v2490')
        && function_exists('vp3_cognitive_portfolio_snapshot_v2480'));
}

function vp3_cognitive_optimization_strategies_v2510(): array
{
    return ['balanced','protect_deadlines','unlock_dependencies','maximize_throughput'];
}

function vp3_cognitive_optimization_work_units_v2510(array $item): float
{
    if(function_exists('vp3_cognitive_forecast_work_units_v2490')){
        try{return vp3_cognitive_forecast_work_units_v2490($item);}catch(Throwable $e){}
    }
    $progress=max(0,min(100,(int)($item['progress_percent']??0)));
    $units=max(1.0,min(6.0,ceil(max(1,100-$progress)/20)));
    $units+=min(2.0,count((array)($item['blocked_by_goal_ids']??[]))*0.5);
    return round(max(0.25,min(8.0,$units)),2);
}

function vp3_cognitive_optimization_deadline_pressure_v2510(array $item,int $now=0): float
{
    if(isset($item['forecast_deadline_pressure'])){
        return max(0.0,min(1.0,(float)$item['forecast_deadline_pressure']));
    }
    if(function_exists('vp3_cognitive_forecast_deadline_pressure_v2490')){
        try{return vp3_cognitive_forecast_deadline_pressure_v2490((string)($item['target_date']??''),$now);}
        catch(Throwable $e){}
    }
    return 0.18;
}

function vp3_cognitive_optimization_capacity_pressure_v2510(array $item,array $capacity): float
{
    if(isset($item['forecast_capacity_pressure'])){
        return max(0.0,min(1.0,(float)$item['forecast_capacity_pressure']));
    }
    if(function_exists('vp3_cognitive_forecast_capacity_pressure_v2490')){
        try{return vp3_cognitive_forecast_capacity_pressure_v2490($item,$capacity);}
        catch(Throwable $e){}
    }
    return 0.0;
}

function vp3_cognitive_optimization_features_v2510(
    array $item,array $capacity,int $now=0
): array {
    $priority=max(1,min(100,(int)($item['priority']??50)))/100;
    $base=max(0.0,min(1.25,(float)($item['forecast_sequence_score']??$item['score']??0.0)));
    $deadline=vp3_cognitive_optimization_deadline_pressure_v2510($item,$now);
    $leverage=min(1.0,count((array)($item['dependency_leverage_goal_ids']??[]))/3);
    $blockers=min(1.0,count((array)($item['blocked_by_goal_ids']??[]))/3);
    $progress=max(0.0,min(1.0,((int)($item['progress_percent']??0))/100));
    $units=vp3_cognitive_optimization_work_units_v2510($item);
    $efficiency=max(0.0,min(1.0,1.0-($units/8)));
    $capacityPressure=vp3_cognitive_optimization_capacity_pressure_v2510($item,$capacity);
    $capacityOpportunity=1.0-$capacityPressure;
    $shared=min(1.0,count((array)($item['shared_objective_goal_ids']??[]))/3);
    return [
        'priority'=>$priority,'base'=>$base,'deadline'=>$deadline,'leverage'=>$leverage,
        'blockers'=>$blockers,'progress'=>$progress,'efficiency'=>$efficiency,
        'capacity_pressure'=>$capacityPressure,'capacity_opportunity'=>$capacityOpportunity,
        'shared'=>$shared,'work_units'=>$units,
    ];
}

function vp3_cognitive_optimization_strategy_score_v2510(
    array $item,string $strategy,array $capacity,int $now=0
): float {
    $f=vp3_cognitive_optimization_features_v2510($item,$capacity,$now);
    $strategy=in_array($strategy,vp3_cognitive_optimization_strategies_v2510(),true)?$strategy:'balanced';
    $score=match($strategy){
        'protect_deadlines'=>
            ($f['deadline']*0.40)+($f['priority']*0.22)+($f['base']*0.18)
            +($f['leverage']*0.10)+($f['efficiency']*0.05)+($f['shared']*0.05),
        'unlock_dependencies'=>
            ($f['leverage']*0.40)+($f['deadline']*0.20)+($f['base']*0.18)
            +($f['priority']*0.12)+($f['shared']*0.06)+($f['efficiency']*0.04),
        'maximize_throughput'=>
            ($f['efficiency']*0.34)+($f['capacity_opportunity']*0.18)+($f['base']*0.20)
            +($f['priority']*0.12)+($f['deadline']*0.10)+($f['leverage']*0.06),
        default=>
            ($f['base']*0.34)+($f['priority']*0.20)+($f['deadline']*0.20)
            +($f['leverage']*0.14)+($f['efficiency']*0.06)+($f['shared']*0.06),
    };
    $score-=($f['blockers']*0.07);
    if((string)($item['hold_reason']??'')==='semantic_overlap')$score-=0.20;
    if((string)($item['execution_state']??'')==='waiting_approval')$score-=0.04;
    return round(max(0.0,min(1.50,$score)),4);
}

function vp3_cognitive_optimization_order_v2510(
    array $items,string $strategy,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    foreach($items as $index=>&$item){
        if(!is_array($item))continue;
        $item['_optimization_original_index']=$index;
        $item['optimization_strategy_score']=vp3_cognitive_optimization_strategy_score_v2510(
            $item,$strategy,$capacity,$now
        );
    }
    unset($item);

    usort($items,static function(array $a,array $b): int {
        $modeRank=['autonomous'=>0,'supervised'=>1,'manual'=>2];
        $aMode=(string)($a['execution_mode']??'manual');
        $bMode=(string)($b['execution_mode']??'manual');
        $mode=($modeRank[$aMode]??9)<=>($modeRank[$bMode]??9);
        if($mode!==0)return $mode;
        // v25.10 only optimizes autonomous ordering. Supervised/manual work
        // keeps its existing order and authority semantics.
        if($aMode!=='autonomous'||$bMode!=='autonomous'){
            return ((int)($a['_optimization_original_index']??0))<=>
                ((int)($b['_optimization_original_index']??0));
        }
        $score=((float)($b['optimization_strategy_score']??0))<=>
            ((float)($a['optimization_strategy_score']??0));
        if($score!==0)return $score;
        $forecast=((float)($b['forecast_sequence_score']??$b['score']??0))<=>
            ((float)($a['forecast_sequence_score']??$a['score']??0));
        if($forecast!==0)return $forecast;
        return ((int)($a['_optimization_original_index']??0))<=>
            ((int)($b['_optimization_original_index']??0));
    });

    foreach($items as $rank=>&$item){
        $item['optimization_strategy']=$strategy;
        $item['optimization_rank']=$rank+1;
        unset($item['_optimization_original_index']);
    }
    unset($item);
    return $items;
}

function vp3_cognitive_optimization_target_ts_v2510(string $targetDate): int
{
    if(function_exists('vp3_cognitive_forecast_target_ts_v2490')){
        try{return vp3_cognitive_forecast_target_ts_v2490($targetDate);}catch(Throwable $e){}
    }
    $ts=strtotime(trim($targetDate));
    return $ts===false?0:max(0,(int)$ts);
}

function vp3_cognitive_optimization_simulate_v2510(
    array $items,string $strategy,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    $ordered=vp3_cognitive_optimization_order_v2510($items,$strategy,$capacity,$now);
    $lanes=[];$deadlineRisk=0;$lateness=0;$makespan=0;$strategicValue=0.0;$unlockValue=0.0;
    foreach(['cloud','homeserver'] as $executor){
        $max=max(0,(int)($capacity['executors'][$executor]['max']??0));
        $active=max(0,(int)($capacity['executors'][$executor]['active']??0));
        $virtual=max(1,$max);
        $base=$executor==='homeserver'
            ?(defined('VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490')
                ?(int)VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490:1200)
            :(defined('VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490')
                ?(int)VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490:900);
        $lanes[$executor]=array_fill(0,$virtual,0);
        for($i=0;$i<min($active,$virtual);$i++)$lanes[$executor][$i]=$base;
        if($max<1)$lanes[$executor][0]+=VP3_COGNITIVE_OPTIMIZATION_HOLD_SECONDS_V2510;
    }

    $simulated=[];
    foreach($ordered as $rank=>$item){
        $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
            ?(string)$item['executor']:'cloud';
        $base=$executor==='homeserver'
            ?(defined('VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490')
                ?(int)VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490:1200)
            :(defined('VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490')
                ?(int)VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490:900);
        $units=vp3_cognitive_optimization_work_units_v2510($item);
        $duration=max(60,(int)round($units*$base));
        $laneLoads=$lanes[$executor]??[0];
        $laneIndex=0;$start=$laneLoads[0]??0;
        foreach($laneLoads as $idx=>$load)if($load<$start){$start=$load;$laneIndex=$idx;}
        $blockers=count((array)($item['blocked_by_goal_ids']??[]));
        $delay=(int)round($blockers*$base*0.75);
        if((string)($item['execution_state']??'')==='waiting_approval')$delay+=VP3_COGNITIVE_OPTIMIZATION_HOLD_SECONDS_V2510;
        if((string)($item['hold_reason']??'')==='semantic_overlap')$delay+=VP3_COGNITIVE_OPTIMIZATION_HOLD_SECONDS_V2510;
        $completion=$start+$delay+$duration;
        $lanes[$executor][$laneIndex]=$start+$duration;
        $deadline=vp3_cognitive_optimization_target_ts_v2510((string)($item['target_date']??''));
        $late=$deadline>0?max(0,($now+$completion)-$deadline):0;
        if($late>0){$deadlineRisk++;$lateness+=$late;}
        $makespan=max($makespan,$completion);
        $f=vp3_cognitive_optimization_features_v2510($item,$capacity,$now);
        $discount=1.0/(1.0+(($rank+1)*0.12));
        $strategicValue+=(($f['priority']*0.40)+($f['deadline']*0.28)+($f['base']*0.20)+($f['shared']*0.12))*$discount;
        $unlockValue+=$f['leverage']*$discount;
        $simulated[]=[
            'goal_id'=>(int)($item['goal_id']??0),
            'title'=>(string)($item['title']??''),
            'rank'=>$rank+1,'executor'=>$executor,
            'estimated_completion_seconds'=>$completion,
            'deadline_risk'=>$late>0,
            'lateness_seconds'=>$late,
            'strategy_score'=>(float)($item['optimization_strategy_score']??0),
        ];
    }

    return [
        'strategy'=>$strategy,
        'sequence_goal_ids'=>array_values(array_map(
            static fn(array $item): int=>(int)($item['goal_id']??0),$simulated
        )),
        'metrics'=>[
            'deadline_risk_count'=>$deadlineRisk,
            'total_lateness_seconds'=>$lateness,
            'makespan_seconds'=>$makespan,
            'strategic_value'=>round($strategicValue,4),
            'dependency_unlock_value'=>round($unlockValue,4),
        ],
        'items'=>$simulated,
        'advisory_only'=>true,
    ];
}

function vp3_cognitive_optimization_compare_v2510(
    array $items,array $capacity,int $now=0
): array {
    $scenarios=[];
    foreach(vp3_cognitive_optimization_strategies_v2510() as $strategy){
        $scenarios[] = vp3_cognitive_optimization_simulate_v2510(
            $items,$strategy,$capacity,$now
        );
    }
    usort($scenarios,static function(array $a,array $b): int {
        $am=(array)($a['metrics']??[]);$bm=(array)($b['metrics']??[]);
        $x=((int)($am['deadline_risk_count']??0))<=>((int)($bm['deadline_risk_count']??0));if($x!==0)return $x;
        $x=((int)($am['total_lateness_seconds']??0))<=>((int)($bm['total_lateness_seconds']??0));if($x!==0)return $x;
        $x=((float)($bm['dependency_unlock_value']??0))<=>((float)($am['dependency_unlock_value']??0));if($x!==0)return $x;
        $x=((float)($bm['strategic_value']??0))<=>((float)($am['strategic_value']??0));if($x!==0)return $x;
        $x=((int)($am['makespan_seconds']??0))<=>((int)($bm['makespan_seconds']??0));if($x!==0)return $x;
        $order=array_flip(vp3_cognitive_optimization_strategies_v2510());
        return ($order[(string)($a['strategy']??'balanced')]??99)<=>
            ($order[(string)($b['strategy']??'balanced')]??99);
    });
    $recommended=$scenarios[0]??null;
    return [
        'recommended_strategy'=>(string)($recommended['strategy']??'balanced'),
        'recommended'=>$recommended,
        'scenarios'=>$scenarios,
    ];
}

/**
 * Pure v25.10 advisory consumed by v24.90. The output only reorders the same
 * items; it does not alter executor, mode, approval, claim, lease or job state.
 */
function vp3_cognitive_optimization_resequence_v2510(
    array $items,array $capacity,int $now=0
): array {
    if(!$items)return [];
    $comparison=vp3_cognitive_optimization_compare_v2510($items,$capacity,$now);
    $strategy=(string)($comparison['recommended_strategy']??'balanced');
    $ordered=vp3_cognitive_optimization_order_v2510($items,$strategy,$capacity,$now);
    foreach($ordered as $idx=>&$item){
        $item['optimization_strategy']=$strategy;
        $item['optimization_rank']=$idx+1;
        // v24.90 owns this sequence field; v25.10 only supplies advisory order.
        $item['forecast_sequence_rank']=$idx+1;
    }
    unset($item);
    return $ordered;
}

function vp3_cognitive_optimization_snapshot_v2510(PDO $pdo,array $user): array
{
    $empty=[
        'contract'=>VP3_COGNITIVE_OPTIMIZATION_CONTRACT_V2510,
        'build'=>VP3_COGNITIVE_OPTIMIZATION_V2510,
        'recommended_strategy'=>'balanced','focus'=>null,'items'=>[],
        'scenarios'=>[],'counts'=>[],'capacity'=>[],'projection_only'=>true,
    ];
    if(!vp3_cognitive_optimization_ready_v2510($pdo))return $empty;

    try{
        $forecast=vp3_cognitive_forecast_snapshot_v2490($pdo,$user);
        $portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
    }catch(Throwable $e){return $empty;}

    $capacity=is_array($portfolio['capacity']??null)?$portfolio['capacity']:[];
    $items=array_slice((array)($portfolio['items']??[]),0,VP3_COGNITIVE_OPTIMIZATION_MAX_ITEMS_V2510);
    if(!$items)return $empty;
    $comparison=vp3_cognitive_optimization_compare_v2510($items,$capacity);
    $strategy=(string)($comparison['recommended_strategy']??'balanced');
    $ordered=vp3_cognitive_optimization_order_v2510($items,$strategy,$capacity);
    $forecastByGoal=[];
    foreach((array)($forecast['items']??[]) as $f){
        if(!is_array($f))continue;
        $goalId=(int)($f['goal_id']??0);if($goalId>0)$forecastByGoal[$goalId]=$f;
    }
    $publicItems=[];
    foreach($ordered as $rank=>$item){
        $goalId=(int)($item['goal_id']??0);$f=(array)($forecastByGoal[$goalId]??[]);
        $publicItems[]=[
            'goal_id'=>$goalId,'title'=>(string)($item['title']??''),
            'rank'=>$rank+1,'strategy'=>$strategy,
            'strategy_score'=>(float)($item['optimization_strategy_score']??0),
            'executor'=>(string)($item['executor']??'cloud'),
            'priority'=>(int)($item['priority']??50),
            'progress_percent'=>(int)($item['progress_percent']??0),
            'risk'=>(string)($f['risk']??'on_track'),
            'likely_completion_at'=>(string)($f['likely_completion_at']??''),
            'confidence'=>(string)($f['confidence']['label']??'low'),
            'dependency_leverage_goal_ids'=>array_values(array_map('intval',(array)($item['dependency_leverage_goal_ids']??[]))),
            'blocked_by_goal_ids'=>array_values(array_map('intval',(array)($item['blocked_by_goal_ids']??[]))),
            'shared_objective_goal_ids'=>array_values(array_map('intval',(array)($item['shared_objective_goal_ids']??[]))),
            'advisory_only'=>true,
        ];
    }
    $recommended=(array)($comparison['recommended']??[]);
    $metrics=(array)($recommended['metrics']??[]);
    return [
        'contract'=>VP3_COGNITIVE_OPTIMIZATION_CONTRACT_V2510,
        'build'=>VP3_COGNITIVE_OPTIMIZATION_V2510,
        'recommended_strategy'=>$strategy,
        'focus'=>$publicItems[0]??null,
        'items'=>$publicItems,
        'scenarios'=>array_map(static function(array $scenario): array {
            return [
                'strategy'=>(string)($scenario['strategy']??'balanced'),
                'sequence_goal_ids'=>array_values(array_map('intval',(array)($scenario['sequence_goal_ids']??[]))),
                'metrics'=>(array)($scenario['metrics']??[]),
                'advisory_only'=>true,
            ];
        },(array)($comparison['scenarios']??[])),
        'counts'=>[
            'strategies'=>count((array)($comparison['scenarios']??[])),
            'goals'=>count($publicItems),
            'projected_deadline_risk'=>(int)($metrics['deadline_risk_count']??0),
        ],
        'capacity'=>$capacity,
        'authority'=>[
            'optimization_projection'=>'cognitive_optimization_v2510',
            'forecast'=>'cognitive_forecast_v2490',
            'portfolio_admission'=>'cognitive_portfolio_v2480',
            'autonomous_mutations'=>'cognitive_autonomy_v2470',
            'worker_claims_and_leases'=>'agent_job_engine_v1900',
            'live_capability_readiness'=>'agent_worker_runtime_v1910',
            'scheduler_authority'=>false,
            'admission_authority'=>false,
            'executor_mutation_authority'=>false,
            'worker_claim_authority'=>false,
            'approval_authority'=>false,
            'execution_authority'=>false,
        ],
        'projection_only'=>true,
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_optimization_context_item_v2510(
    PDO $pdo,array $user,string $namespace
): ?array {
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_optimization_snapshot_v2510($pdo,$user);
    if(empty($snapshot['items']))return null;
    $json=json_encode([
        'recommended_strategy'=>$snapshot['recommended_strategy'],
        'focus'=>$snapshot['focus'],
        'counts'=>$snapshot['counts'],
        'strategies'=>array_map(static fn(array $s): array=>[
            'strategy'=>(string)($s['strategy']??''),
            'metrics'=>(array)($s['metrics']??[]),
        ],(array)$snapshot['scenarios']),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'optimization','cognitive-optimization:v2510','Strategic portfolio optimization',
        $json,95.9,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_optimization_activity_projection_v2510(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_OPTIMIZATION_V2510,'recommended_strategy'=>'',
        'focus'=>null,'items'=>[],'scenarios'=>[],'counts'=>[],'projection_only'=>true,
    ];
    $snapshot=vp3_cognitive_optimization_snapshot_v2510($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_OPTIMIZATION_V2510,
        'recommended_strategy'=>$snapshot['recommended_strategy'],
        'focus'=>$snapshot['focus'],
        'items'=>array_slice((array)$snapshot['items'],0,8),
        'scenarios'=>array_slice((array)$snapshot['scenarios'],0,4),
        'counts'=>$snapshot['counts'],
        'capacity'=>$snapshot['capacity'],
        'projection_only'=>true,
    ];
}
