<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.90 — Portfolio Forecasting & Adaptive Resource Planning.
 *
 * Forecasting is a bounded, ephemeral projection over v24.80 portfolio
 * coordination and Phase 19 execution history. It creates no scheduler,
 * queue, worker, lease, approval, receipt, goal, objective, or forecast store.
 *
 * v24.90 may:
 *   - estimate bounded completion windows with explicit confidence,
 *   - predict deadline/capacity/dependency conflicts,
 *   - provide deterministic adaptive sequencing pressure to v24.80.
 *
 * v24.80 remains claim/materialization admission authority and Phase 19
 * remains the only worker claim/lease/execution authority.
 */
const VP3_COGNITIVE_FORECAST_V2490='vp3-cognitive-forecast-v2490-20260922';
const VP3_COGNITIVE_FORECAST_CONTRACT_V2490='cognitive-portfolio-forecast-v1';
const VP3_COGNITIVE_FORECAST_HISTORY_DAYS_V2490=30;
const VP3_COGNITIVE_FORECAST_MAX_ITEMS_V2490=8;
const VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490=900;
const VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490=1200;
const VP3_COGNITIVE_FORECAST_MIN_ACTION_SECONDS_V2490=60;
const VP3_COGNITIVE_FORECAST_MAX_ACTION_SECONDS_V2490=14400;

function vp3_cognitive_forecast_ready_v2490(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('vp3_cognitive_portfolio_snapshot_v2480')
        && function_exists('agent_job_engine_schema_ready_v1900')
        && agent_job_engine_schema_ready_v1900($pdo)
        && table_exists('agent_workflow_actions'));
}

function vp3_cognitive_forecast_target_ts_v2490(string $targetDate): int
{
    $targetDate=trim($targetDate);
    if($targetDate==='')return 0;
    $ts=strtotime($targetDate);
    if($ts===false)return 0;
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$targetDate)){
        $end=strtotime($targetDate.' 23:59:59 UTC');
        if($end!==false)$ts=$end;
    }
    return max(0,(int)$ts);
}

function vp3_cognitive_forecast_deadline_pressure_v2490(string $targetDate,int $now=0): float
{
    $now=$now>0?$now:time();
    $deadline=vp3_cognitive_forecast_target_ts_v2490($targetDate);
    if($deadline<1)return 0.18;
    $seconds=$deadline-$now;
    if($seconds<=0)return 1.0;
    $days=$seconds/86400;
    if($days<=3)return 0.98;
    if($days<=7)return 0.90;
    if($days<=14)return 0.78;
    if($days<=30)return 0.62;
    if($days<=90)return 0.40;
    return 0.22;
}

function vp3_cognitive_forecast_capacity_pressure_v2490(array $item,array $capacity): float
{
    $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
        ?(string)$item['executor']:'cloud';
    $c=is_array($capacity['executors'][$executor]??null)?$capacity['executors'][$executor]:[];
    $max=max(0,(int)($c['max']??0));$free=max(0,(int)($c['free']??0));
    if($max<1)return 1.0;
    $pressure=1.0-min(1.0,$free/$max);
    if((string)($item['hold_reason']??'')==='worker_capacity')$pressure=1.0;
    return round(max(0.0,min(1.0,$pressure)),3);
}

function vp3_cognitive_forecast_sequence_score_v2490(array $item,array $capacity,int $now=0): float
{
    $base=max(0.0,min(1.0,(float)($item['score']??0.0)));
    $deadline=vp3_cognitive_forecast_deadline_pressure_v2490((string)($item['target_date']??''),$now);
    $leverage=min(1.0,count((array)($item['dependency_leverage_goal_ids']??[]))/3);
    $blockers=min(1.0,count((array)($item['blocked_by_goal_ids']??[]))/3);
    $capacityPressure=vp3_cognitive_forecast_capacity_pressure_v2490($item,$capacity);
    $state=(string)($item['execution_state']??'');
    $stateBoost=match($state){
        'repair_needed'=>0.10,
        'waiting_approval'=>0.08,
        'ready'=>0.07,
        'needs_objective'=>0.06,
        'verification'=>0.04,
        default=>0.0,
    };
    $hold=(string)($item['hold_reason']??'');
    $holdPenalty=$hold==='semantic_overlap'?0.20:0.0;
    $score=($base*0.56)+($deadline*0.24)+($leverage*0.14)+($capacityPressure*0.06)+$stateBoost-($blockers*0.06)-$holdPenalty;
    return round(max(0.0,min(1.25,$score)),4);
}

/**
 * Pure sequencing advisory consumed by v24.80 before it performs admission.
 * This function does not admit, claim, lease, execute, persist, or mutate work.
 */
function vp3_cognitive_forecast_adaptive_resequence_v2490(array $items,array $capacity,int $now=0): array
{
    $now=$now>0?$now:time();
    foreach($items as $idx=>&$item){
        if(!is_array($item))continue;
        $item['forecast_sequence_score']=vp3_cognitive_forecast_sequence_score_v2490($item,$capacity,$now);
        $item['forecast_deadline_pressure']=vp3_cognitive_forecast_deadline_pressure_v2490((string)($item['target_date']??''),$now);
        $item['forecast_capacity_pressure']=vp3_cognitive_forecast_capacity_pressure_v2490($item,$capacity);
        $item['_forecast_original_index']=$idx;
    }
    unset($item);
    usort($items,static function(array $a,array $b): int {
        $modeRank=['autonomous'=>0,'supervised'=>1,'manual'=>2];
        $mode=($modeRank[$a['execution_mode']??'manual']??9)<=>($modeRank[$b['execution_mode']??'manual']??9);
        if($mode!==0)return $mode;
        $score=((float)($b['forecast_sequence_score']??0))<=>((float)($a['forecast_sequence_score']??0));
        if($score!==0)return $score;
        $original=((int)($a['_forecast_original_index']??0))<=>((int)($b['_forecast_original_index']??0));
        if($original!==0)return $original;
        return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
    });
    foreach($items as $idx=>&$item){
        $item['forecast_sequence_rank']=$idx+1;
        unset($item['_forecast_original_index']);
    }
    unset($item);
    return $items;
}

function vp3_cognitive_forecast_history_v2490(PDO $pdo,array $user): array
{
    $defaults=[
        'cloud'=>[
            'samples'=>0,'average_seconds'=>VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490,
            'source'=>'bounded_default','confidence'=>'low',
        ],
        'homeserver'=>[
            'samples'=>0,'average_seconds'=>VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490,
            'source'=>'bounded_default','confidence'=>'low',
        ],
    ];
    $uid=(int)($user['id']??0);if($uid<1||!agent_job_engine_schema_ready_v1900($pdo))return $defaults;
    try{
        $stmt=$pdo->prepare("SELECT execution_target,COUNT(*) AS samples,
          AVG(TIMESTAMPDIFF(SECOND,started_at,completed_at)) AS average_seconds
          FROM agent_workflow_actions
          WHERE owner_user_id=? AND status='completed'
            AND started_at IS NOT NULL AND completed_at IS NOT NULL
            AND completed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_FORECAST_HISTORY_DAYS_V2490." DAY)
            AND TIMESTAMPDIFF(SECOND,started_at,completed_at) BETWEEN 1 AND 86400
          GROUP BY execution_target");
        $stmt->execute([$uid]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $executor=in_array((string)($row['execution_target']??''),['cloud','homeserver'],true)
                ?(string)$row['execution_target']:'cloud';
            $samples=max(0,(int)($row['samples']??0));
            $fallback=$executor==='homeserver'
                ?VP3_COGNITIVE_FORECAST_DEFAULT_HOMESERVER_SECONDS_V2490
                :VP3_COGNITIVE_FORECAST_DEFAULT_CLOUD_SECONDS_V2490;
            $seconds=(int)round((float)($row['average_seconds']??$fallback));
            $seconds=max(VP3_COGNITIVE_FORECAST_MIN_ACTION_SECONDS_V2490,min(VP3_COGNITIVE_FORECAST_MAX_ACTION_SECONDS_V2490,$seconds));
            $defaults[$executor]=[
                'samples'=>$samples,'average_seconds'=>$seconds,'source'=>'phase19_action_history',
                'confidence'=>$samples>=12?'high':($samples>=4?'medium':'low'),
            ];
        }
    }catch(Throwable $e){}
    return $defaults;
}

function vp3_cognitive_forecast_work_units_v2490(array $item): float
{
    $progress=max(0,min(100,(int)($item['progress_percent']??0)));
    $units=max(1.0,min(6.0,ceil(max(1,100-$progress)/20)));
    $state=(string)($item['execution_state']??'');
    if($state==='needs_objective')$units+=1.0;
    elseif($state==='repair_needed')$units+=1.5;
    elseif($state==='verification')$units=max(0.75,$units-0.75);
    elseif(in_array($state,['objective_achieved','achieved'],true))$units=0.25;
    $units+=min(2.0,count((array)($item['blocked_by_goal_ids']??[]))*0.5);
    if(count((array)($item['shared_objective_goal_ids']??[]))>0)$units=max(0.5,$units-0.5);
    return round(max(0.25,min(8.0,$units)),2);
}

function vp3_cognitive_forecast_confidence_v2490(int $samples,array $item,array $capacity): array
{
    $score=min(0.60,$samples/20);
    if(array_key_exists('progress_percent',$item))$score+=0.18;
    if(empty($item['blocked_by_goal_ids']))$score+=0.10;
    $executor=(string)($item['executor']??'cloud');
    if(!empty($capacity['known'])&&isset($capacity['executors'][$executor]))$score+=0.12;
    if((string)($item['execution_state']??'')==='waiting_approval')$score-=0.12;
    if((string)($item['hold_reason']??'')==='semantic_overlap')$score-=0.18;
    $score=max(0.15,min(0.95,$score));
    return [
        'score'=>round($score,2),
        'label'=>$score>=0.72?'high':($score>=0.48?'medium':'low'),
    ];
}

function vp3_cognitive_forecast_snapshot_v2490(PDO $pdo,array $user): array
{
    $empty=[
        'contract'=>VP3_COGNITIVE_FORECAST_CONTRACT_V2490,
        'build'=>VP3_COGNITIVE_FORECAST_V2490,
        'focus'=>null,'items'=>[],'counts'=>[],'conflicts'=>[],
        'adaptive_sequence_goal_ids'=>[],'capacity'=>[],'history'=>[],
        'projection_only'=>true,
    ];
    if(!vp3_cognitive_forecast_ready_v2490($pdo))return $empty;

    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
    catch(Throwable $e){return $empty;}
    $capacity=is_array($portfolio['capacity']??null)?$portfolio['capacity']:[];
    $history=vp3_cognitive_forecast_history_v2490($pdo,$user);
    $items=array_slice((array)($portfolio['items']??[]),0,VP3_COGNITIVE_FORECAST_MAX_ITEMS_V2490);
    $now=time();
    $lanes=[];$conflicts=[];$forecastItems=[];

    foreach(['cloud','homeserver'] as $executor){
        $max=max(1,(int)($capacity['executors'][$executor]['max']??0));
        $active=max(0,min($max,(int)($capacity['executors'][$executor]['active']??0)));
        $base=max(VP3_COGNITIVE_FORECAST_MIN_ACTION_SECONDS_V2490,(int)($history[$executor]['average_seconds']??900));
        $lanes[$executor]=array_fill(0,$max,0);
        for($i=0;$i<$active;$i++)$lanes[$executor][$i]=$base;
    }

    foreach($items as $item){
        if(!is_array($item))continue;
        $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
            ?(string)$item['executor']:'cloud';
        $base=max(VP3_COGNITIVE_FORECAST_MIN_ACTION_SECONDS_V2490,(int)($history[$executor]['average_seconds']??900));
        $units=vp3_cognitive_forecast_work_units_v2490($item);
        $likelyWork=max(60,(int)round($base*$units));
        $laneLoads=$lanes[$executor]??[0];
        $laneIndex=0;$startDelay=$laneLoads[0]??0;
        foreach($laneLoads as $idx=>$load){
            if($load<$startDelay){$startDelay=$load;$laneIndex=$idx;}
        }
        $blockers=count((array)($item['blocked_by_goal_ids']??[]));
        $dependencyDelay=(int)round($blockers*$base*0.75);
        $approvalDelay=(string)($item['execution_state']??'')==='waiting_approval'?86400:0;
        $holdDelay=(string)($item['hold_reason']??'')==='semantic_overlap'?86400:0;
        $likelySeconds=$startDelay+$dependencyDelay+$approvalDelay+$holdDelay+$likelyWork;
        $earliestSeconds=max(0,(int)round($startDelay+($dependencyDelay*0.4)+($likelyWork*0.70)));
        $latestSeconds=max($likelySeconds,(int)round($startDelay+($dependencyDelay*1.5)+$approvalDelay+$holdDelay+($likelyWork*1.65)));
        $lanes[$executor][$laneIndex]=$startDelay+$likelyWork;

        $deadline=vp3_cognitive_forecast_target_ts_v2490((string)($item['target_date']??''));
        $deadlineRisk=$deadline>0&&($now+$likelySeconds)>$deadline;
        $capacityPressure=vp3_cognitive_forecast_capacity_pressure_v2490($item,$capacity);
        $confidence=vp3_cognitive_forecast_confidence_v2490(
            (int)($history[$executor]['samples']??0),$item,$capacity
        );
        $risk='on_track';
        if((string)($item['hold_reason']??'')==='semantic_overlap')$risk='overlap_review';
        elseif($deadlineRisk)$risk='deadline_risk';
        elseif($blockers>0)$risk='dependency_bottleneck';
        elseif($capacityPressure>=0.75||$startDelay>$base)$risk='capacity_pressure';

        $forecast=[
            'goal_id'=>(int)($item['goal_id']??0),
            'title'=>(string)($item['title']??''),
            'executor'=>$executor,
            'sequence_rank'=>(int)($item['forecast_sequence_rank']??0),
            'sequence_score'=>round((float)($item['forecast_sequence_score']??0),4),
            'work_units'=>$units,
            'queue_seconds'=>$startDelay,
            'estimated_action_seconds'=>$base,
            'earliest_completion_at'=>gmdate('c',$now+$earliestSeconds),
            'likely_completion_at'=>gmdate('c',$now+$likelySeconds),
            'latest_completion_at'=>gmdate('c',$now+$latestSeconds),
            'target_date'=>(string)($item['target_date']??''),
            'deadline_risk'=>$deadlineRisk,
            'risk'=>$risk,
            'confidence'=>$confidence,
            'blocked_by_goal_ids'=>array_values(array_map('intval',(array)($item['blocked_by_goal_ids']??[]))),
            'shared_objective_goal_ids'=>array_values(array_map('intval',(array)($item['shared_objective_goal_ids']??[]))),
            'hold_reason'=>(string)($item['hold_reason']??''),
            'advisory_only'=>true,
        ];
        $forecastItems[]=$forecast;
        if($risk!=='on_track'){
            $conflicts[]=[
                'goal_id'=>$forecast['goal_id'],'title'=>$forecast['title'],'type'=>$risk,
                'executor'=>$executor,'likely_completion_at'=>$forecast['likely_completion_at'],
                'target_date'=>$forecast['target_date'],'confidence'=>$confidence['label'],
            ];
        }
    }

    $riskCounts=['deadline_risk'=>0,'capacity_pressure'=>0,'dependency_bottleneck'=>0,'overlap_review'=>0];
    foreach($conflicts as $conflict){
        $type=(string)($conflict['type']??'');
        if(isset($riskCounts[$type]))$riskCounts[$type]++;
    }
    return [
        'contract'=>VP3_COGNITIVE_FORECAST_CONTRACT_V2490,
        'build'=>VP3_COGNITIVE_FORECAST_V2490,
        'focus'=>$forecastItems[0]??null,
        'items'=>$forecastItems,
        'counts'=>[
            'forecasted'=>count($forecastItems),
            'conflicts'=>count($conflicts),
            'deadline_risk'=>$riskCounts['deadline_risk'],
            'capacity_pressure'=>$riskCounts['capacity_pressure'],
            'dependency_bottleneck'=>$riskCounts['dependency_bottleneck'],
            'overlap_review'=>$riskCounts['overlap_review'],
        ],
        'conflicts'=>$conflicts,
        'adaptive_sequence_goal_ids'=>array_values(array_map(
            static fn(array $item): int=>(int)($item['goal_id']??0),$forecastItems
        )),
        'capacity'=>$capacity,
        'history'=>$history,
        'assumptions'=>[
            'history_window_days'=>VP3_COGNITIVE_FORECAST_HISTORY_DAYS_V2490,
            'completion_windows_are_estimates'=>true,
            'approval_wait_is_not_predictable'=>true,
            'dependency_delay_is_bounded_estimate'=>true,
        ],
        'authority'=>[
            'forecast_projection'=>'cognitive_forecast_v2490',
            'portfolio_admission'=>'cognitive_portfolio_v2480',
            'worker_claims_and_leases'=>'agent_job_engine_v1900',
            'live_capability_readiness'=>'agent_worker_runtime_v1910',
            'autonomous_mutations'=>'cognitive_autonomy_v2470',
            'scheduler_authority'=>false,
            'worker_claim_authority'=>false,
            'approval_authority'=>false,
            'execution_authority'=>false,
        ],
        'projection_only'=>true,
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_forecast_context_item_v2490(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_forecast_snapshot_v2490($pdo,$user);
    if(empty($snapshot['items']))return null;
    $items=[];
    foreach(array_slice((array)$snapshot['items'],0,6) as $item){
        $items[]=[
            'goal_id'=>(int)$item['goal_id'],
            'title'=>(string)$item['title'],
            'executor'=>(string)$item['executor'],
            'sequence_rank'=>(int)$item['sequence_rank'],
            'likely_completion_at'=>(string)$item['likely_completion_at'],
            'latest_completion_at'=>(string)$item['latest_completion_at'],
            'risk'=>(string)$item['risk'],
            'confidence'=>(string)($item['confidence']['label']??'low'),
        ];
    }
    $json=json_encode([
        'focus'=>$items[0]??null,
        'goals'=>$items,
        'counts'=>$snapshot['counts'],
        'adaptive_sequence_goal_ids'=>$snapshot['adaptive_sequence_goal_ids'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'forecast','cognitive-forecast:v2490','Portfolio forecast',
        $json,95.8,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_forecast_activity_projection_v2490(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_FORECAST_V2490,'focus'=>null,'items'=>[],
        'counts'=>[],'conflicts'=>[],'projection_only'=>true
    ];
    $snapshot=vp3_cognitive_forecast_snapshot_v2490($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_FORECAST_V2490,
        'focus'=>$snapshot['focus'],
        'items'=>array_slice((array)$snapshot['items'],0,8),
        'counts'=>$snapshot['counts'],
        'conflicts'=>array_slice((array)$snapshot['conflicts'],0,8),
        'adaptive_sequence_goal_ids'=>$snapshot['adaptive_sequence_goal_ids'],
        'capacity'=>$snapshot['capacity'],
        'projection_only'=>true,
    ];
}
