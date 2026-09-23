<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.30 — Autonomous Portfolio Replanning & Recovery.
 *
 * v25.30 is a deterministic recovery overlay. It compares the current
 * optimized portfolio order with a bounded recovery order when current
 * evidence shows deadline, dependency or capacity pressure.
 *
 * It does not create jobs, leases, approvals, dependencies, objectives,
 * executors or scheduler state. Phase 19 remains the sole worker execution
 * authority. v24.80 remains portfolio admission authority.
 */
const VP3_COGNITIVE_REPLANNING_V2530='vp3-cognitive-replanning-v2530-20260922';
const VP3_COGNITIVE_REPLANNING_CONTRACT_V2530='cognitive-portfolio-replanning-v1';
const VP3_COGNITIVE_REPLANNING_MAX_ITEMS_V2530=8;
const VP3_COGNITIVE_REPLANNING_MAX_ISSUES_V2530=12;
const VP3_COGNITIVE_REPLANNING_DEADLINE_BUFFER_SECONDS_V2530=900;
const VP3_COGNITIVE_REPLANNING_URGENT_WINDOW_SECONDS_V2530=21600;

function vp3_cognitive_replanning_ready_v2530(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('vp3_cognitive_portfolio_snapshot_v2480')
        && function_exists('vp3_cognitive_resource_budget_plan_v2520')
        && function_exists('vp3_cognitive_optimization_strategy_score_v2510')
        && function_exists('vp3_cognitive_forecast_deadline_pressure_v2490'));
}

function vp3_cognitive_replanning_target_ts_v2530(string $targetDate): int
{
    if(function_exists('vp3_cognitive_resource_target_ts_v2520')){
        try{return vp3_cognitive_resource_target_ts_v2520($targetDate);}catch(Throwable $e){}
    }
    $targetDate=trim($targetDate);
    if($targetDate==='')return 0;
    $ts=strtotime($targetDate);
    return $ts===false?0:max(0,(int)$ts);
}

function vp3_cognitive_replanning_expected_seconds_v2530(array $item,string $executor): int
{
    if(function_exists('vp3_cognitive_resource_expected_seconds_v2520')){
        try{return vp3_cognitive_resource_expected_seconds_v2520($item,$executor);}catch(Throwable $e){}
    }
    $progress=max(0,min(100,(int)($item['progress_percent']??0)));
    $units=max(1.0,min(6.0,ceil(max(1,100-$progress)/20)));
    $base=$executor==='homeserver'?1200:900;
    return max(60,(int)round($base*$units));
}

function vp3_cognitive_replanning_deadline_pressure_v2530(array $item,int $now=0): float
{
    $now=$now>0?$now:time();
    if(function_exists('vp3_cognitive_forecast_deadline_pressure_v2490')){
        try{return max(0.0,min(1.0,vp3_cognitive_forecast_deadline_pressure_v2490(
            (string)($item['target_date']??''),$now
        )));}catch(Throwable $e){}
    }
    return 0.0;
}

function vp3_cognitive_replanning_goal_analysis_v2530(
    array $item,array $capacity,int $baselineRank,int $now=0
): array {
    $now=$now>0?$now:time();
    $mode=(string)($item['execution_mode']??'manual');
    $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
        ?(string)$item['executor']:'cloud';
    $target=vp3_cognitive_replanning_target_ts_v2530((string)($item['target_date']??''));
    $expected=vp3_cognitive_replanning_expected_seconds_v2530($item,$executor);
    $executorCapacity=(array)($capacity['executors'][$executor]??[]);
    $ready=!empty($executorCapacity['ready'])&&(int)($executorCapacity['max']??0)>0;
    $free=max(0,(int)($executorCapacity['free']??0));
    $active=max(0,(int)($executorCapacity['active']??0));
    $max=max(0,(int)($executorCapacity['max']??0));
    $blocked=count((array)($item['blocked_by_goal_ids']??[]))>0;
    $requiresUser=!empty($item['requires_user'])||in_array(
        (string)($item['execution_state']??''),
        ['waiting_approval','review_needed','plan_missing'],
        true
    );
    $semanticHold=(string)($item['hold_reason']??'')==='semantic_overlap';

    $capacityDelay=0;
    if(!$ready)$capacityDelay=VP3_COGNITIVE_REPLANNING_URGENT_WINDOW_SECONDS_V2530;
    elseif($free<1)$capacityDelay=max(
        vp3_cognitive_replanning_expected_seconds_v2530($item,$executor),
        (int)ceil(max(1,$active)/max(1,$max))*600
    );
    $likelyFinish=$now+$capacityDelay+$expected+VP3_COGNITIVE_REPLANNING_DEADLINE_BUFFER_SECONDS_V2530;
    $deadlineThreat=$target>0&&$likelyFinish>$target;
    $urgent=$target>0&&($target-$now)<=VP3_COGNITIVE_REPLANNING_URGENT_WINDOW_SECONDS_V2530;
    $priority=max(1,min(100,(int)($item['priority']??50)))/100;
    $deadline=vp3_cognitive_replanning_deadline_pressure_v2530($item,$now);
    $leverage=min(1.0,count((array)($item['dependency_leverage_goal_ids']??[]))/3);
    $progress=max(0.0,min(1.0,((int)($item['progress_percent']??0))/100));
    $optimized=max(0.0,min(1.25,(float)(
        $item['optimization_strategy_score']
        ??$item['forecast_sequence_score']
        ??$item['score']
        ??0.0
    )));
    $capacityPressure=$ready?($free<1?1.0:max(0.0,min(1.0,1.0-($free/max(1,$max))))):1.0;

    $issues=[];
    if($mode==='autonomous'){
        if(!$ready)$issues[]='capacity_unavailable';
        if($deadlineThreat)$issues[]='deadline_threat';
        if($blocked)$issues[]='dependency_blocked';
        if($requiresUser)$issues[]='approval_or_user_gate';
        if($semanticHold)$issues[]='semantic_overlap';
        if($urgent&&$capacityPressure>=0.75)$issues[]='urgent_capacity_pressure';
    }

    $score=($deadline*0.34)+($priority*0.20)+($leverage*0.18)+($optimized*0.14)
        +($capacityPressure*0.08)+($progress*0.06);
    if($deadlineThreat)$score+=0.18;
    if($urgent)$score+=0.08;
    if($blocked)$score-=0.20;
    if($requiresUser)$score-=0.22;
    if(!$ready)$score-=0.30;
    if($semanticHold)$score-=0.35;
    $score=round(max(0.0,min(1.50,$score)),4);

    $autonomousSafe=$mode==='autonomous'
        &&$ready&&!$blocked&&!$requiresUser&&!$semanticHold;
    $needsReplan=$mode==='autonomous'&&!empty($issues);

    $action='keep_plan';
    if($mode!=='autonomous')$action='authority_passthrough';
    elseif(!$ready)$action='escalate_executor_unavailable';
    elseif($requiresUser)$action='request_user_or_approval';
    elseif($semanticHold)$action='review_overlap';
    elseif($blocked)$action='prioritize_dependency_unlock';
    elseif($deadlineThreat)$action='protect_deadline';
    elseif($urgent&&$capacityPressure>=0.75)$action='protect_capacity';
    elseif($leverage>=0.67)$action='unlock_downstream_work';

    return [
        'goal_id'=>(int)($item['goal_id']??0),
        'title'=>(string)($item['title']??''),
        'execution_mode'=>$mode,
        'executor'=>$executor,
        'baseline_rank'=>$baselineRank,
        'priority'=>(int)($item['priority']??50),
        'progress_percent'=>(int)($item['progress_percent']??0),
        'target_date'=>(string)($item['target_date']??''),
        'estimated_finish_at'=>gmdate('c',$likelyFinish),
        'deadline_threat'=>$deadlineThreat,
        'urgent'=>$urgent,
        'capacity_ready'=>$ready,
        'capacity_free'=>$free,
        'capacity_pressure'=>round($capacityPressure,3),
        'blocked'=>$blocked,
        'requires_user'=>$requiresUser,
        'semantic_overlap'=>$semanticHold,
        'dependency_leverage_goal_ids'=>array_values(array_map(
            'intval',(array)($item['dependency_leverage_goal_ids']??[])
        )),
        'issue_codes'=>$issues,
        'needs_replan'=>$needsReplan,
        'autonomous_replan_safe'=>$autonomousSafe,
        'recovery_score'=>$score,
        'recommended_action'=>$action,
    ];
}

/**
 * Returns the same portfolio items with a bounded recovery order overlay.
 * Supervised/manual relative ordering is never changed.
 */
function vp3_cognitive_replanning_overlay_v2530(
    array $items,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    $analyses=[];
    foreach($items as $index=>&$item){
        if(!is_array($item))continue;
        $analysis=vp3_cognitive_replanning_goal_analysis_v2530(
            $item,$capacity,$index+1,$now
        );
        $analyses[(int)($item['goal_id']??0)]=$analysis;
        $item['_replan_original_index']=$index;
        $item['replan_baseline_rank']=$index+1;
        $item['replan_recovery_score']=$analysis['recovery_score'];
        $item['replan_issue_codes']=$analysis['issue_codes'];
        $item['replan_action']=$analysis['recommended_action'];
        $item['replan_safe']=$analysis['autonomous_replan_safe'];
    }
    unset($item);

    $replanNeeded=count(array_filter(
        $analyses,
        static fn(array $analysis): bool=>!empty($analysis['needs_replan'])
    ))>0;

    if($replanNeeded){
        usort($items,static function(array $a,array $b): int {
            $modeRank=['autonomous'=>0,'supervised'=>1,'manual'=>2];
            $aMode=(string)($a['execution_mode']??'manual');
            $bMode=(string)($b['execution_mode']??'manual');
            $mode=($modeRank[$aMode]??9)<=>($modeRank[$bMode]??9);
            if($mode!==0)return $mode;
            if($aMode!=='autonomous'||$bMode!=='autonomous'){
                return ((int)($a['_replan_original_index']??0))<=>
                    ((int)($b['_replan_original_index']??0));
            }
            $aSafe=!empty($a['replan_safe'])?0:1;
            $bSafe=!empty($b['replan_safe'])?0:1;
            if($aSafe!==$bSafe)return $aSafe<=>$bSafe;
            $score=((float)($b['replan_recovery_score']??0))<=>
                ((float)($a['replan_recovery_score']??0));
            if($score!==0)return $score;
            $optimized=((float)($b['optimization_strategy_score']??$b['forecast_sequence_score']??$b['score']??0))<=>
                ((float)($a['optimization_strategy_score']??$a['forecast_sequence_score']??$a['score']??0));
            if($optimized!==0)return $optimized;
            return ((int)($a['_replan_original_index']??0))<=>
                ((int)($b['_replan_original_index']??0));
        });
    }

    $changes=[];$issues=[];
    foreach($items as $index=>&$item){
        $goalId=(int)($item['goal_id']??0);
        $baseline=(int)($item['replan_baseline_rank']??($index+1));
        $newRank=$index+1;
        $item['replan_rank']=$newRank;
        $item['replan_rank_delta']=$baseline-$newRank;
        $item['replan_applied']=$replanNeeded;
        unset($item['_replan_original_index']);
        $analysis=$analyses[$goalId]??[];
        $analysis['replan_rank']=$newRank;
        $analysis['rank_delta']=$baseline-$newRank;
        if(!empty($analysis['issue_codes']))$issues[]=$analysis;
        if($baseline!==$newRank){
            $changes[]=[
                'goal_id'=>$goalId,
                'title'=>(string)($item['title']??''),
                'from_rank'=>$baseline,
                'to_rank'=>$newRank,
                'rank_delta'=>$baseline-$newRank,
                'reason_codes'=>(array)($item['replan_issue_codes']??[]),
                'action'=>(string)($item['replan_action']??'keep_plan'),
                'autonomous_only'=>true,
            ];
        }
    }
    unset($item);

    $capacityLoss=array_values(array_filter(
        $issues,
        static fn(array $issue): bool=>in_array(
            'capacity_unavailable',(array)($issue['issue_codes']??[]),true
        )
    ));
    $deadlineThreats=array_values(array_filter(
        $issues,
        static fn(array $issue): bool=>in_array(
            'deadline_threat',(array)($issue['issue_codes']??[]),true
        )
    ));
    $blocked=array_values(array_filter(
        $issues,
        static fn(array $issue): bool=>in_array(
            'dependency_blocked',(array)($issue['issue_codes']??[]),true
        )
    ));
    $needsUser=array_values(array_filter(
        $issues,
        static fn(array $issue): bool=>in_array(
            'approval_or_user_gate',(array)($issue['issue_codes']??[]),true
        )
    ));

    $health='healthy';
    if($capacityLoss)$health='capacity_degraded';
    elseif($deadlineThreats)$health='deadline_threatened';
    elseif($blocked)$health='dependency_stalled';
    elseif($needsUser)$health='waiting_for_user';
    elseif($replanNeeded)$health='replan_recommended';

    return [
        'items'=>$items,
        'replan'=>[
            'build'=>VP3_COGNITIVE_REPLANNING_V2530,
            'contract'=>VP3_COGNITIVE_REPLANNING_CONTRACT_V2530,
            'health'=>$health,
            'replan_needed'=>$replanNeeded,
            'replan_applied'=>$replanNeeded,
            'focus'=>$issues[0]??null,
            'issues'=>array_slice($issues,0,VP3_COGNITIVE_REPLANNING_MAX_ISSUES_V2530),
            'changes'=>array_slice($changes,0,VP3_COGNITIVE_REPLANNING_MAX_ITEMS_V2530),
            'sequence_goal_ids'=>array_values(array_map(
                static fn(array $item): int=>(int)($item['goal_id']??0),
                $items
            )),
            'counts'=>[
                'issues'=>count($issues),
                'changes'=>count($changes),
                'deadline_threats'=>count($deadlineThreats),
                'capacity_loss'=>count($capacityLoss),
                'dependency_stalls'=>count($blocked),
                'needs_user'=>count($needsUser),
            ],
            'authority'=>[
                'replanning'=>'cognitive_replanning_v2530_recovery_overlay',
                'resource_budget'=>'cognitive_resource_budget_v2520',
                'optimization'=>'cognitive_optimization_v2510',
                'forecast'=>'cognitive_forecast_v2490',
                'portfolio_admission'=>'cognitive_portfolio_v2480',
                'worker_claims_and_leases'=>'agent_job_engine_v1900',
                'live_capability_readiness'=>'agent_worker_runtime_v1910',
                'scheduler_authority'=>false,
                'executor_mutation_authority'=>false,
                'approval_authority'=>false,
                'execution_authority'=>false,
            ],
            'projection_only'=>true,
        ],
    ];
}

function vp3_cognitive_replanning_snapshot_v2530(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_REPLANNING_V2530,
        'contract'=>VP3_COGNITIVE_REPLANNING_CONTRACT_V2530,
        'health'=>'unavailable','replan_needed'=>false,'replan_applied'=>false,
        'focus'=>null,'issues'=>[],'changes'=>[],'sequence_goal_ids'=>[],
        'counts'=>[],'projection_only'=>true,
    ];
    if(!vp3_cognitive_replanning_ready_v2530($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
    catch(Throwable $e){return $empty;}
    if(is_array($portfolio['replanning']??null))return $portfolio['replanning'];
    try{
        $overlay=vp3_cognitive_replanning_overlay_v2530(
            (array)($portfolio['items']??[]),
            (array)($portfolio['capacity']??[])
        );
        return (array)($overlay['replan']??$empty);
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_replanning_context_item_v2530(
    PDO $pdo,array $user,string $namespace
): ?array {
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_replanning_snapshot_v2530($pdo,$user);
    if(($snapshot['health']??'unavailable')==='unavailable')return null;
    $json=json_encode([
        'health'=>$snapshot['health'],
        'replan_needed'=>$snapshot['replan_needed'],
        'focus'=>$snapshot['focus'],
        'counts'=>$snapshot['counts'],
        'changes'=>array_slice((array)$snapshot['changes'],0,6),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'replanning','cognitive-replanning:v2530','Portfolio replanning and recovery',
        $json,95.97,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_replanning_activity_projection_v2530(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_REPLANNING_V2530,'health'=>'unavailable',
        'replan_needed'=>false,'focus'=>null,'issues'=>[],'changes'=>[],
        'counts'=>[],'projection_only'=>true,
    ];
    $snapshot=vp3_cognitive_replanning_snapshot_v2530($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_REPLANNING_V2530,
        'health'=>$snapshot['health']??'unavailable',
        'replan_needed'=>!empty($snapshot['replan_needed']),
        'replan_applied'=>!empty($snapshot['replan_applied']),
        'focus'=>$snapshot['focus']??null,
        'issues'=>array_slice((array)($snapshot['issues']??[]),0,8),
        'changes'=>array_slice((array)($snapshot['changes']??[]),0,8),
        'sequence_goal_ids'=>$snapshot['sequence_goal_ids']??[],
        'counts'=>$snapshot['counts']??[],
        'projection_only'=>true,
    ];
}
