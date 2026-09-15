<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.13 — Goal Forecasting, Capacity & Scenario Intelligence.
 *
 * Forecasts are advisory projections derived from the existing Goal → Roadmap →
 * Objective → Workflow → Action stack plus verified historical objective timing.
 * This layer never schedules, claims, leases, approves, retries, receipts,
 * completes, reprioritizes, resequences, pauses, resumes, or otherwise mutates
 * execution state. Existing goal/objective/workflow systems remain authoritative.
 */
const VP3_AGENT_GOAL_FORECASTING_V1713='agent-goal-forecasting-v1713-20260915';
const VP3_AGENT_GOAL_FORECAST_HISTORY_LIMIT_V1713=80;
const VP3_AGENT_GOAL_FORECAST_ACTIVE_GOAL_SOFT_LIMIT_V1713=4;

require_once __DIR__.'/agent-goal-execution-v1712.php';

function agent_goal_forecasting_require_v1713(PDO $pdo,array $user): int
{
    return agent_goal_execution_require_v1712($pdo,$user);
}

function agent_goal_forecast_median_v1713(array $values): float
{
    $clean=[];
    foreach($values as $value){$n=(float)$value;if($n>0&&is_finite($n))$clean[]=$n;}
    if(!$clean)return 0.0;
    sort($clean,SORT_NUMERIC);$count=count($clean);$mid=intdiv($count,2);
    return $count%2===1?(float)$clean[$mid]:((float)$clean[$mid-1]+(float)$clean[$mid])/2;
}

function agent_goal_forecast_history_v1713(PDO $pdo,int $uid,string $goalText): array
{
    $fallback=['baseline_days'=>3.0,'sample_count'=>0,'similar_count'=>0,'confidence_percent'=>35,'confidence_label'=>'low','source'=>'heuristic'];
    if(!table_exists('agent_workflow_runs')||!column_exists('agent_workflow_runs','created_at')||!column_exists('agent_workflow_runs','updated_at'))return $fallback;
    try{
        $stmt=$pdo->prepare("SELECT id,goal,created_at,updated_at,status,objective_verification_status FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_plan' AND (objective_verification_status='achieved' OR status='completed') ORDER BY id DESC LIMIT ".VP3_AGENT_GOAL_FORECAST_HISTORY_LIMIT_V1713);
        $stmt->execute([$uid]);$all=[];$similar=[];
        foreach($stmt->fetchAll()?:[] as $row){
            $created=strtotime((string)($row['created_at']??''))?:0;$updated=strtotime((string)($row['updated_at']??''))?:0;
            if($created<=0||$updated<=$created)continue;$days=max(0.25,min(365.0,($updated-$created)/86400));$all[]=$days;
            $similarity=agent_objective_portfolio_similarity_v179($goalText,(string)($row['goal']??''));
            if($similarity>=0.12)$similar[]=$days;
        }
        $similarMedian=agent_goal_forecast_median_v1713($similar);$allMedian=agent_goal_forecast_median_v1713($all);
        if(count($similar)>=3)return ['baseline_days'=>round($similarMedian,2),'sample_count'=>count($all),'similar_count'=>count($similar),'confidence_percent'=>85,'confidence_label'=>'high','source'=>'similar_verified_objectives'];
        if(count($similar)>=1)return ['baseline_days'=>round($similarMedian,2),'sample_count'=>count($all),'similar_count'=>count($similar),'confidence_percent'=>68,'confidence_label'=>'medium','source'=>'limited_similar_history'];
        if(count($all)>=5)return ['baseline_days'=>round($allMedian,2),'sample_count'=>count($all),'similar_count'=>0,'confidence_percent'=>55,'confidence_label'=>'medium','source'=>'owner_verified_history'];
        if(count($all)>=1)return ['baseline_days'=>round($allMedian,2),'sample_count'=>count($all),'similar_count'=>0,'confidence_percent'=>44,'confidence_label'=>'low','source'=>'limited_owner_history'];
    }catch(Throwable $e){}
    return $fallback;
}

function agent_goal_forecast_milestone_work_v1713(PDO $pdo,array $user,array $milestone,float $baselineDays): array
{
    $state=(string)($milestone['state']??'advisory');$objectiveId=(int)($milestone['objective_run_id']??0);
    $out=['milestone_id'=>(int)($milestone['id']??0),'objective_id'=>$objectiveId,'state'=>$state,'work_units'=>1.0,'base_days'=>$baselineDays,'penalty_days'=>0.0,'reason'=>'Unresolved roadmap milestone.'];
    if(in_array($state,['achieved','retired'],true)){$out['work_units']=0.0;$out['base_days']=0.0;$out['reason']='Milestone is already resolved.';return $out;}
    if($objectiveId<1){$out['reason']='Advisory milestone still needs an explicitly created canonical objective.';return $out;}
    try{
        $objective=agent_objective_state_v175($pdo,$user,$objectiveId,false);$counts=(array)($objective['counts']??[]);$total=max(0,(int)($counts['total']??0));$completed=max(0,(int)($counts['completed']??0));
        $verification=(string)($objective['verification_status']??'');
        if($verification==='achieved'){$out['work_units']=0.0;$out['base_days']=0.0;$out['reason']='Linked objective is verified achieved.';return $out;}
        if($total>0)$out['work_units']=max(0.2,min(1.0,($total-min($completed,$total))/$total));
        $focus=agent_goal_execution_focus_v1712($pdo,(int)($user['id']??0),$objective);$focusState=(string)($focus['state']??'');
        $out['state']=$focusState!==''?$focusState:$state;
        if($focusState==='waiting_approval'){$out['penalty_days']+=1.5;$out['reason']='Current workflow is waiting for explicit approval.';}
        elseif($focusState==='blocked'){$out['penalty_days']+=2.0;$out['reason']='Current workflow is dependency-blocked.';}
        elseif($focusState==='repair_needed'||$verification==='needs_remediation'){$out['penalty_days']+=max(1.0,$baselineDays*0.5);$out['reason']='Failure/remediation adds recovery work.';}
        elseif($focusState==='paused'){$out['penalty_days']+=3.0;$out['reason']='Current workflow is paused.';}
        elseif($focusState==='scheduled'){
            $ts=strtotime((string)($focus['next_attempt_at']??''))?:0;if($ts>time())$out['penalty_days']+=min(30.0,max(0.0,($ts-time())/86400));$out['reason']='Current workflow is scheduled for a future attempt.';
        }elseif($focusState==='executing')$out['reason']='Current workflow is already executing.';
        elseif($focusState==='ready')$out['reason']='Current workflow is dependency-clear and ready for the existing Phase 19 claimant.';
        elseif($verification==='waiting')$out['reason']='Objective remains active and unresolved.';
    }catch(Throwable $e){$out['penalty_days']+=1.0;$out['reason']='Objective timing is partially unavailable, so the forecast includes a conservative uncertainty buffer.';}
    $out['base_days']=round($baselineDays*$out['work_units'],2);$out['penalty_days']=round((float)$out['penalty_days'],2);return $out;
}

function agent_goal_capacity_v1713(PDO $pdo,array $user): array
{
    agent_goal_forecasting_require_v1713($pdo,$user);$goals=agent_goal_list_v1710($pdo,$user,false);$active=0;$paused=0;$due30=0;$stalled=0;$remainingMilestones=0;$competing=[];$today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();
    foreach($goals as $entry){$g=(array)($entry['goal']??[]);if((string)($g['derived_status']??'')==='achieved')continue;$status=(string)($g['status']??'active');if($status==='paused'){$paused++;continue;}if($status!=='active')continue;$active++;
        $target=trim((string)($g['target_date']??''));$days=null;if($target!==''){$ts=strtotime($target.' 00:00:00 UTC')?:0;if($ts>0){$days=(int)floor(($ts-$today)/86400);if($days<=30)$due30++;}}
        $health='unknown';$remaining=0;try{$plan=agent_goal_plan_state_v1711($pdo,$user,(int)$g['id']);$health=(string)($plan['plan']['health']['status']??'unknown');$remaining=max(0,(int)($plan['plan']['remaining_count']??0));if(!empty($plan['plan']['health']['stalled']))$stalled++;}catch(Throwable $e){}
        $remainingMilestones+=$remaining;$competing[]=['goal_id'=>(int)$g['id'],'attention'=>(int)($g['attention_score_percent']??0),'priority'=>(int)($g['priority']??50),'progress_percent'=>(int)($g['progress_percent']??0),'target_date'=>$target,'days_remaining'=>$days,'health'=>$health,'remaining_milestones'=>$remaining,'goal'=>(string)($g['goal']??'')];
    }
    usort($competing,static fn(array $a,array $b):int=>($b['attention']<=>$a['attention'])?:($b['priority']<=>$a['priority'])?:($a['goal_id']<=>$b['goal_id']));
    $activePressure=min(1.0,$active/max(1,VP3_AGENT_GOAL_FORECAST_ACTIVE_GOAL_SOFT_LIMIT_V1713));$deadlinePressure=min(1.0,$due30/3);$stallPressure=min(1.0,$stalled/max(1,$active));$workPressure=min(1.0,$remainingMilestones/16);
    $score=(int)round(max(0,min(1,($activePressure*0.35)+($deadlinePressure*0.30)+($stallPressure*0.20)+($workPressure*0.15)))*100);
    $label=$score>=80?'overcommitted':($score>=65?'high':($score>=40?'moderate':'available'));
    return ['build'=>VP3_AGENT_GOAL_FORECASTING_V1713,'score_percent'=>$score,'label'=>$label,'overcommitted'=>$score>=80,'counts'=>['active_goals'=>$active,'paused_goals'=>$paused,'due_within_30_days'=>$due30,'stalled_goals'=>$stalled,'remaining_milestones'=>$remainingMilestones],'components'=>['active_goal_pressure'=>(int)round($activePressure*100),'deadline_pressure'=>(int)round($deadlinePressure*100),'stall_pressure'=>(int)round($stallPressure*100),'remaining_work_pressure'=>(int)round($workPressure*100)],'competing_goals'=>array_slice($competing,0,6)];
}

function agent_goal_forecast_scenarios_v1713(int $forecastDays,string $targetDate=''): array
{
    $today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();$targetTs=$targetDate!==''?(strtotime($targetDate.' 00:00:00 UTC')?:0):0;
    $defs=[
        'fastest'=>['label'=>'Fastest safe','factor'=>0.80,'assumption'=>'Prioritize this goal and resolve approvals/dependencies promptly without bypassing them.'],
        'highest_value'=>['label'=>'Highest value','factor'=>1.00,'assumption'=>'Keep the highest-contribution, highest-ranked canonical objective first while preserving current safety boundaries.'],
        'lowest_risk'=>['label'=>'Lowest risk','factor'=>1.30,'assumption'=>'Preserve extra recovery/approval buffer and avoid compressing uncertain work.'],
    ];$out=[];
    foreach($defs as $key=>$def){$days=max(0,(int)ceil($forecastDays*(float)$def['factor']));$date=gmdate('Y-m-d',$today+($days*86400));$slip=$targetTs>0?(int)ceil((strtotime($date.' 00:00:00 UTC')-$targetTs)/86400):null;$out[$key]=['key'=>$key,'label'=>$def['label'],'forecast_days'=>$days,'forecast_date'=>$date,'target_slip_days'=>$slip,'assumption'=>$def['assumption']];}
    return $out;
}

function agent_goal_forecast_risk_v1713(string $forecastDate,string $targetDate): array
{
    if($targetDate==='')return ['status'=>'no_target','slip_days'=>null,'reason'=>'No target date is set, so the forecast cannot measure schedule risk against a deadline.'];
    $forecastTs=strtotime($forecastDate.' 00:00:00 UTC')?:0;$targetTs=strtotime($targetDate.' 00:00:00 UTC')?:0;if($forecastTs<=0||$targetTs<=0)return ['status'=>'unknown','slip_days'=>null,'reason'=>'The target date could not be compared reliably.'];
    $slip=(int)ceil(($forecastTs-$targetTs)/86400);if($slip<=0)return ['status'=>'on_track','slip_days'=>$slip,'reason'=>'The current evidence-based forecast lands on or before the target date.'];if($slip<=3)return ['status'=>'watch','slip_days'=>$slip,'reason'=>'The current forecast is only slightly beyond the target date, so small delays could matter.'];if($slip<=14)return ['status'=>'at_risk','slip_days'=>$slip,'reason'=>'The current forecast is more than a few days beyond the target date.'];return ['status'=>'likely_miss','slip_days'=>$slip,'reason'=>'The current forecast materially exceeds the target date.'];
}

function agent_goal_forecast_recovery_v1713(array $state): array
{
    $risk=(array)($state['risk']??[]);$capacity=(array)($state['capacity']??[]);$execution=(array)($state['execution']??[]);$recommendations=[];
    $execState=(string)($execution['execution_state']??'');
    if($execState==='plan_missing')$recommendations[]='Build the advisory roadmap so remaining work can be forecast from explicit milestones.';
    if($execState==='needs_objective')$recommendations[]='If this milestone is still required, explicitly create its canonical objective before expecting execution progress.';
    if($execState==='waiting_approval')$recommendations[]='Review the existing approval request; forecasting will not approve it automatically.';
    if($execState==='blocked')$recommendations[]='Resolve the canonical prerequisite workflow chain rather than bypassing dependencies.';
    if($execState==='repair_needed')$recommendations[]='Use the existing objective repair/replan path and reforecast after remediation work is defined.';
    if($execState==='paused'||$execState==='goal_paused')$recommendations[]='Decide explicitly whether this paused work should resume; the forecasting layer will not resume it.';
    if(!empty($capacity['overcommitted']))$recommendations[]='Reduce competing commitments by explicitly pausing, deprioritizing, or rescheduling lower-value goals before adding more work.';
    if(in_array((string)($risk['status']??''),['at_risk','likely_miss'],true)){
        $recommendations[]='Consider an explicit roadmap resequence or scope reduction that protects the highest-contribution milestones.';
        $recommendations[]='If scope must remain unchanged, consider explicitly changing the target date or adding resources/capability rather than silently compressing safety checks.';
    }
    if(!$recommendations)$recommendations[]='Keep the current roadmap and reforecast when verified outcomes, approvals, dependencies, or target dates materially change.';
    return array_slice(array_values(array_unique($recommendations)),0,5);
}

function agent_goal_forecast_state_v1713(PDO $pdo,array $user,int $goalId,?string $comparisonDate=null): array
{
    $uid=agent_goal_forecasting_require_v1713($pdo,$user);$plan=agent_goal_plan_state_v1711($pdo,$user,$goalId);$goal=(array)($plan['goal']??[]);$execution=agent_goal_execution_state_v1712($pdo,$user,$goalId);$history=agent_goal_forecast_history_v1713($pdo,$uid,(string)($goal['goal']??''));$baseline=max(0.25,(float)$history['baseline_days']);$work=[];$days=0.0;
    foreach((array)($plan['plan']['milestones']??[]) as $milestone){if(!is_array($milestone))continue;$item=agent_goal_forecast_milestone_work_v1713($pdo,$user,$milestone,$baseline);$work[]=$item;$days+=(float)$item['base_days']+(float)$item['penalty_days'];}
    if((string)($goal['derived_status']??'')==='achieved')$days=0.0;
    $capacity=agent_goal_capacity_v1713($pdo,$user);$capacityBuffer=0.0;if((string)$capacity['label']==='moderate')$capacityBuffer=$days*0.08;elseif((string)$capacity['label']==='high')$capacityBuffer=$days*0.18;elseif((string)$capacity['label']==='overcommitted')$capacityBuffer=$days*0.30;$days+=$capacityBuffer;
    $forecastDays=max(0,(int)ceil($days));$today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();$forecastDate=gmdate('Y-m-d',$today+($forecastDays*86400));$target=trim((string)($comparisonDate??''));if($target==='')$target=trim((string)($goal['target_date']??''));
    $risk=agent_goal_forecast_risk_v1713($forecastDate,$target);$confidence=max(20,min(95,(int)$history['confidence_percent']));if((string)($plan['plan']['health']['status']??'')==='plan_missing')$confidence=min($confidence,30);if((int)($plan['plan']['remaining_count']??0)>8)$confidence=max(20,$confidence-8);$confidenceLabel=$confidence>=75?'high':($confidence>=50?'medium':'low');
    $state=['build'=>VP3_AGENT_GOAL_FORECASTING_V1713,'goal'=>$goal,'plan'=>$plan,'execution'=>$execution,'history'=>$history,'capacity'=>$capacity,'work'=>$work,'forecast'=>['forecast_days'=>$forecastDays,'forecast_date'=>$forecastDate,'baseline_days_per_objective'=>$baseline,'capacity_buffer_days'=>round($capacityBuffer,2),'confidence_percent'=>$confidence,'confidence_label'=>$confidenceLabel,'comparison_date'=>$target],'risk'=>$risk,'scenarios'=>agent_goal_forecast_scenarios_v1713($forecastDays,$target)];$state['recovery']=agent_goal_forecast_recovery_v1713($state);return $state;
}

function agent_goal_forecast_answer_v1713(array $state,string $mode='forecast'): string
{
    $goal=(array)($state['goal']??[]);$forecast=(array)($state['forecast']??[]);$risk=(array)($state['risk']??[]);$capacity=(array)($state['capacity']??[]);$id=(int)($goal['id']??0);$date=(string)($forecast['forecast_date']??'');$days=(int)($forecast['forecast_days']??0);$confidence=(string)($forecast['confidence_label']??'low');$comparison=(string)($forecast['comparison_date']??'');
    if($mode==='capacity')return agent_goal_capacity_answer_v1713($capacity);
    if($mode==='scenarios'){
        $lines=[];foreach((array)($state['scenarios']??[]) as $scenario){if(!is_array($scenario))continue;$line=(string)$scenario['label'].': '.(string)$scenario['forecast_date'].' (~'.(int)$scenario['forecast_days'].' days)';if($scenario['target_slip_days']!==null)$line.=' · '.((int)$scenario['target_slip_days']<=0?'on/before target':'+'.(int)$scenario['target_slip_days'].' days vs target');$lines[]=$line.' — '.(string)$scenario['assumption'];}
        return 'Goal #'.$id.' scenario comparison (advisory only):'."\n".implode("\n",$lines).' No scenario changes priority, dates, scope, approvals, dependencies, or execution unless you explicitly make that change through the existing controls.';
    }
    $answer='Goal #'.$id.' forecast: '.$date.' (~'.$days.' days from today) · '.$confidence.' confidence';if($comparison!=='')$answer.=' · comparison date '.$comparison;$answer.='. '.(string)($risk['reason']??'');
    if($mode==='risk'||in_array((string)($risk['status']??''),['at_risk','likely_miss'],true)){$recovery=(array)($state['recovery']??[]);if($recovery)$answer.=' Recommended recovery: '.implode(' ',array_map(static fn(string $x):string=>'• '.$x,$recovery));}
    $answer.=' Capacity: '.(string)($capacity['label']??'unknown').' ('.(int)($capacity['score_percent']??0).'/100). Forecasting is advisory and does not mutate the roadmap or execution ledger.';return $answer;
}

function agent_goal_capacity_answer_v1713(array $capacity): string
{
    $counts=(array)($capacity['counts']??[]);$answer='Goal capacity: '.(string)($capacity['label']??'unknown').' · '.(int)($capacity['score_percent']??0).'/100 · '.(int)($counts['active_goals']??0).' active goals · '.(int)($counts['due_within_30_days']??0).' due within 30 days · '.(int)($counts['stalled_goals']??0).' stalled · '.(int)($counts['remaining_milestones']??0).' unresolved milestones.';
    $top=(array)($capacity['competing_goals']??[]);if($top){$labels=[];foreach(array_slice($top,0,3) as $g)$labels[]='goal #'.(int)$g['goal_id'].' attention '.(int)$g['attention'].'/100';$answer.=' Highest competing attention: '.implode(', ',$labels).'.';}
    if(!empty($capacity['overcommitted']))$answer.=' The portfolio is overcommitted by the current derived capacity model. Consider explicitly reducing lower-value commitments before adding more work.';else $answer.=' This is an advisory capacity signal; it does not pause, reprioritize, or reschedule anything automatically.';return $answer;
}

function agent_goal_forecast_extract_comparison_date_v1713(string $query): ?string
{
    if(!preg_match('/\b(?:by|before|until)\s+([A-Za-z]+(?:\s+\d{1,2}(?:,?\s+20\d{2})?)?|20\d{2}-\d{1,2}-\d{1,2})\b/i',$query,$m))return null;$raw=trim((string)$m[1]);$ts=strtotime($raw);if($ts===false)return null;return gmdate('Y-m-d',$ts);
}

function agent_goal_forecasting_chat_v1713(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;$capacityIntent=(bool)(preg_match('/\b(?:goal|goals|portfolio)\b.*\b(?:capacity|overcommitted|over-committed|too many commitments|workload)\b/i',$q)||preg_match('/\b(?:am i|are we)\s+(?:overcommitted|over-committed)\b/i',$q));$goalId=agent_goal_execution_extract_id_v1712($q);
    $forecastIntent=$goalId>0&&(bool)preg_match('/\b(?:forecast|predict|prediction|finish|complete|completion|on track|hit|make|achieve|deadline|when will|how long)\b/i',$q);
    $riskIntent=$goalId>0&&(bool)(preg_match('/\b(?:risk|at risk|miss|late|delay|slip|behind|putting|threat)\b/i',$q)&&preg_match('/\b(?:goal|target|deadline|date|risk|miss)\b/i',$q));
    $scenarioIntent=$goalId>0&&(bool)(preg_match('/\b(?:scenario|compare|what if|fastest|lowest[- ]risk|highest[- ]value)\b/i',$q));
    if(!$capacityIntent&&!$forecastIntent&&!$riskIntent&&!$scenarioIntent)return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        $result=$empty;$result['handled']=true;if($capacityIntent&&$goalId<1){$capacity=agent_goal_capacity_v1713($pdo,$user);$result['answer']=agent_goal_capacity_answer_v1713($capacity);$tool='goal.forecast.capacity';$log=['capacity_score'=>(int)$capacity['score_percent'],'capacity_label'=>(string)$capacity['label']];}
        else{$comparison=agent_goal_forecast_extract_comparison_date_v1713($q);$state=agent_goal_forecast_state_v1713($pdo,$user,$goalId,$comparison);$mode=$scenarioIntent?'scenarios':($riskIntent?'risk':'forecast');$result['answer']=agent_goal_forecast_answer_v1713($state,$mode);$tool='goal.forecast.'.$mode;$log=['goal_id'=>$goalId,'forecast_date'=>(string)$state['forecast']['forecast_date'],'risk'=>(string)$state['risk']['status'],'confidence'=>(int)$state['forecast']['confidence_percent'],'capacity'=>(int)$state['capacity']['score_percent']];}
        if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',$log+['build'=>VP3_AGENT_GOAL_FORECASTING_V1713],$conversationId);return $result;
    }catch(Throwable $e){$result=$empty;$result['handled']=true;$result['answer']='I could not calculate the goal forecast: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.forecast',$query,'error',['goal_id'=>$goalId,'error'=>get_class($e),'build'=>VP3_AGENT_GOAL_FORECASTING_V1713],$conversationId);return $result;}
}
