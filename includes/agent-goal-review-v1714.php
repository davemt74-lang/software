<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.14 — Goal Review & Continuous Improvement.
 *
 * This owner-scoped learning layer records advisory snapshots of the existing
 * goal/roadmap/forecast state, settles forecast outcomes only from verified
 * Phase 17.6 goal achievement, and uses observed forecast error plus recurring
 * blockers to improve later advisory forecasts and strategy recommendations.
 *
 * It never executes work, approves anything, changes priority/target/scope,
 * edits roadmap order, mutates objective/workflow terminal state, claims or
 * leases Phase 19 jobs, or stores HomeServer-private execution context.
 */
const VP3_AGENT_GOAL_REVIEW_V1714='agent-goal-review-v1714-20260915';
const VP3_AGENT_GOAL_REVIEW_HISTORY_LIMIT_V1714=120;
const VP3_AGENT_GOAL_REVIEW_DEDUPE_SECONDS_V1714=43200;

require_once __DIR__.'/agent-goal-forecasting-v1713.php';
require_once __DIR__.'/agent-objective-memory-v177.php';
require_once __DIR__.'/agent-goal-commitments-v1715.php';

function agent_goal_review_schema_ready_v1714(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_goal_planning_schema_ready_v1711($pdo)
        && table_exists('agent_goal_review_snapshots')
        && column_exists('agent_goal_review_snapshots','forecast_date')
        && column_exists('agent_goal_review_snapshots','forecast_error_days')
        && column_exists('agent_goal_review_snapshots','state_hash'));
}

function agent_goal_review_ensure_schema_v1714(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_goal_planning_ensure_schema_v1711($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_goal_review_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        goal_id BIGINT UNSIGNED NOT NULL,
        snapshot_kind VARCHAR(32) NOT NULL DEFAULT 'review',
        state_hash CHAR(64) NOT NULL DEFAULT '',
        forecast_date DATE NULL,
        target_date DATE NULL,
        forecast_days INT UNSIGNED NOT NULL DEFAULT 0,
        confidence_percent INT UNSIGNED NOT NULL DEFAULT 0,
        calibration_factor DECIMAL(6,3) NOT NULL DEFAULT 1.000,
        risk_status VARCHAR(32) NOT NULL DEFAULT '',
        capacity_score INT UNSIGNED NOT NULL DEFAULT 0,
        capacity_label VARCHAR(32) NOT NULL DEFAULT '',
        plan_health VARCHAR(32) NOT NULL DEFAULT '',
        execution_state VARCHAR(32) NOT NULL DEFAULT '',
        progress_percent INT UNSIGNED NOT NULL DEFAULT 0,
        remaining_milestones INT UNSIGNED NOT NULL DEFAULT 0,
        outcome_status VARCHAR(32) NOT NULL DEFAULT 'open',
        actual_achieved_at DATETIME NULL,
        forecast_error_days INT NULL,
        resolved_at DATETIME NULL,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_agent_goal_review_goal (owner_user_id,goal_id,id),
        KEY idx_agent_goal_review_outcome (owner_user_id,outcome_status,resolved_at,id),
        KEY idx_agent_goal_review_recent (owner_user_id,captured_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function agent_goal_review_require_v1714(PDO $pdo,array $user): int
{
    $uid=agent_goal_forecasting_require_v1713($pdo,$user);
    if(!agent_goal_review_schema_ready_v1714($pdo))throw new RuntimeException('Goal review learning is not ready yet. Run the normal VP3 database upgrade first.');
    return $uid;
}

function agent_goal_review_actual_achievement_v1714(PDO $pdo,int $uid,int $goalId): ?string
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total_count,
        SUM(CASE WHEN r.objective_verification_status='achieved' THEN 1 ELSE 0 END) achieved_count,
        MAX(CASE WHEN r.objective_verification_status='achieved' THEN COALESCE(r.objective_verified_at,r.updated_at) ELSE NULL END) achieved_at
        FROM agent_goal_objectives l
        INNER JOIN agent_workflow_runs r ON r.id=l.objective_run_id AND r.owner_user_id=l.owner_user_id
        WHERE l.owner_user_id=? AND l.goal_id=? AND r.source_kind='objective_plan'");
    $stmt->execute([$uid,$goalId]);$row=$stmt->fetch();
    if(!is_array($row))return null;$total=max(0,(int)($row['total_count']??0));$achieved=max(0,(int)($row['achieved_count']??0));
    if($total<1||$achieved!==$total)return null;$at=trim((string)($row['achieved_at']??''));return $at!==''?$at:null;
}

function agent_goal_review_settle_goal_v1714(PDO $pdo,array $user,int $goalId): array
{
    $uid=agent_goal_review_require_v1714($pdo,$user);$goal=agent_goal_state_v1710($pdo,$user,$goalId);$g=(array)($goal['goal']??[]);$derived=(string)($g['derived_status']??'');$status=(string)($g['status']??'active');$settled=0;
    if($derived==='achieved'){
        $actual=agent_goal_review_actual_achievement_v1714($pdo,$uid,$goalId);
        if($actual!==null){
            $stmt=$pdo->prepare("UPDATE agent_goal_review_snapshots
                SET outcome_status='achieved',actual_achieved_at=?,forecast_error_days=CASE WHEN forecast_date IS NULL THEN NULL ELSE DATEDIFF(DATE(?),forecast_date) END,resolved_at=CURRENT_TIMESTAMP
                WHERE owner_user_id=? AND goal_id=? AND outcome_status='open' AND captured_at<=?");
            $stmt->execute([$actual,$actual,$uid,$goalId,$actual]);$settled=$stmt->rowCount();
        }
    }elseif($status==='archived'){
        $stmt=$pdo->prepare("UPDATE agent_goal_review_snapshots SET outcome_status='closed_unachieved',resolved_at=CURRENT_TIMESTAMP WHERE owner_user_id=? AND goal_id=? AND outcome_status='open'");
        $stmt->execute([$uid,$goalId]);$settled=$stmt->rowCount();
    }
    return ['goal'=>$g,'settled'=>$settled,'actual_achieved_at'=>$derived==='achieved'?($actual??null):null];
}

function agent_goal_review_settle_owner_v1714(PDO $pdo,array $user,int $limit=40): int
{
    $uid=agent_goal_review_require_v1714($pdo,$user);$limit=max(1,min(80,$limit));$stmt=$pdo->prepare("SELECT DISTINCT goal_id FROM agent_goal_review_snapshots WHERE owner_user_id=? AND outcome_status='open' ORDER BY goal_id DESC LIMIT ".$limit);$stmt->execute([$uid]);$count=0;
    foreach($stmt->fetchAll()?:[] as $row){$goalId=(int)($row['goal_id']??0);if($goalId<1)continue;try{$settled=agent_goal_review_settle_goal_v1714($pdo,$user,$goalId);$count+=(int)($settled['settled']??0);}catch(Throwable $e){}}
    return $count;
}

function agent_goal_review_median_v1714(array $values): float
{
    $clean=[];foreach($values as $value){$n=(float)$value;if(is_finite($n))$clean[]=$n;}if(!$clean)return 0.0;sort($clean,SORT_NUMERIC);$count=count($clean);$mid=intdiv($count,2);return $count%2?(float)$clean[$mid]:((float)$clean[$mid-1]+(float)$clean[$mid])/2;
}

function agent_goal_review_calibration_v1714(PDO $pdo,array $user,string $goalText=''): array
{
    $uid=agent_goal_review_require_v1714($pdo,$user);agent_goal_review_settle_owner_v1714($pdo,$user);
    $stmt=$pdo->prepare("SELECT s.*,g.goal FROM agent_goal_review_snapshots s INNER JOIN agent_goals g ON g.id=s.goal_id AND g.owner_user_id=s.owner_user_id WHERE s.owner_user_id=? AND s.outcome_status='achieved' AND s.actual_achieved_at IS NOT NULL AND s.forecast_days>0 ORDER BY s.resolved_at DESC,s.id DESC LIMIT ".VP3_AGENT_GOAL_REVIEW_HISTORY_LIMIT_V1714);$stmt->execute([$uid]);$latestByGoal=[];
    foreach($stmt->fetchAll()?:[] as $row){$gid=(int)($row['goal_id']??0);if($gid<1||isset($latestByGoal[$gid]))continue;$latestByGoal[$gid]=$row;}
    $all=[];$similar=[];$goalText=trim($goalText);
    foreach($latestByGoal as $row){$captured=strtotime((string)($row['captured_at']??''))?:0;$actual=strtotime((string)($row['actual_achieved_at']??''))?:0;$forecast=max(1,(int)($row['forecast_days']??0));if($captured<=0||$actual<$captured)continue;$actualDays=max(0.0,($actual-$captured)/86400);$ratio=max(0.50,min(2.00,$actualDays/$forecast));$error=(int)($row['forecast_error_days']??0);$sample=['ratio'=>$ratio,'error'=>$error,'goal'=>(string)($row['goal']??'')];$all[]=$sample;if($goalText!==''&&agent_objective_portfolio_similarity_v179($goalText,$sample['goal'])>=0.12)$similar[]=$sample;}
    $chosen=count($similar)>=2?$similar:$all;$source=count($similar)>=2?'similar_verified_goals':'owner_verified_goals';$count=count($chosen);
    if($count<1)return ['factor'=>1.0,'sample_count'=>0,'similar_count'=>count($similar),'median_error_days'=>0,'median_absolute_error_days'=>0,'confidence_label'=>'none','source'=>'no_settled_forecasts'];
    $ratios=array_column($chosen,'ratio');$errors=array_column($chosen,'error');$absolute=array_map(static fn($v):int=>abs((int)$v),$errors);$raw=agent_goal_review_median_v1714($ratios);$weight=$count>=5?1.0:($count>=3?0.80:($count===2?0.55:0.30));$factor=max(0.75,min(1.50,1.0+(($raw-1.0)*$weight)));$confidence=$count>=5?'high':($count>=2?'medium':'low');
    return ['factor'=>round($factor,3),'sample_count'=>$count,'similar_count'=>count($similar),'median_error_days'=>(int)round(agent_goal_review_median_v1714($errors)),'median_absolute_error_days'=>(int)round(agent_goal_review_median_v1714($absolute)),'confidence_label'=>$confidence,'source'=>$source];
}

function agent_goal_review_forecast_state_v1714(PDO $pdo,array $user,int $goalId,?string $comparisonDate=null): array
{
    agent_goal_review_require_v1714($pdo,$user);$state=agent_goal_forecast_state_v1713($pdo,$user,$goalId,$comparisonDate);$goal=(array)($state['goal']??[]);$forecast=(array)($state['forecast']??[]);$calibration=agent_goal_review_calibration_v1714($pdo,$user,(string)($goal['goal']??''));$factor=(float)($calibration['factor']??1.0);$baseDays=max(0,(int)($forecast['forecast_days']??0));$estimable=!empty($forecast['estimable']);$achieved=(string)($goal['derived_status']??'')==='achieved';
    if($estimable&&!$achieved&&$baseDays>0){$days=max(1,(int)ceil($baseDays*$factor));$today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();$date=gmdate('Y-m-d',$today+($days*86400));$target=(string)($forecast['comparison_date']??'');$forecast['uncalibrated_days']=$baseDays;$forecast['uncalibrated_date']=(string)($forecast['forecast_date']??'');$forecast['forecast_days']=$days;$forecast['forecast_date']=$date;$state['risk']=agent_goal_forecast_risk_v1713($date,$target);$state['scenarios']=agent_goal_forecast_scenarios_v1713($days,$target);}
    $confidence=max(20,min(95,(int)($forecast['confidence_percent']??35)));$samples=(int)($calibration['sample_count']??0);$mae=(int)($calibration['median_absolute_error_days']??0);if($samples>=3)$confidence=min(95,$confidence+5);if($mae>=7)$confidence=max(20,$confidence-10);elseif($mae>=3)$confidence=max(20,$confidence-5);$forecast['confidence_percent']=$confidence;$forecast['confidence_label']=$confidence>=75?'high':($confidence>=50?'medium':'low');$forecast['calibration_factor']=$factor;$forecast['calibration_sample_count']=$samples;$forecast['calibration_median_error_days']=(int)($calibration['median_error_days']??0);$forecast['calibration_median_absolute_error_days']=$mae;$forecast['calibration_source']=(string)($calibration['source']??'');$state['forecast']=$forecast;$state['calibration']=$calibration;$state['review_build']=VP3_AGENT_GOAL_REVIEW_V1714;$state['recovery']=agent_goal_forecast_recovery_v1713($state);return $state;
}

function agent_goal_review_snapshot_hash_v1714(array $state,string $kind): string
{
    $goal=(array)($state['goal']??[]);$forecast=(array)($state['forecast']??[]);$risk=(array)($state['risk']??[]);$capacity=(array)($state['capacity']??[]);$plan=(array)($state['plan']['plan']??[]);$execution=(array)($state['execution']??[]);
    return hash('sha256',agent_workflow_json_v1400(['kind'=>$kind,'goal_id'=>(int)($goal['id']??0),'forecast_date'=>(string)($forecast['forecast_date']??''),'target_date'=>(string)($forecast['comparison_date']??$goal['target_date']??''),'forecast_days'=>(int)($forecast['forecast_days']??0),'confidence'=>(int)($forecast['confidence_percent']??0),'factor'=>(float)($forecast['calibration_factor']??1.0),'risk'=>(string)($risk['status']??''),'capacity'=>(int)($capacity['score_percent']??0),'plan'=>(string)($plan['health']['status']??''),'execution'=>(string)($execution['execution_state']??''),'progress'=>(int)($goal['progress_percent']??0),'remaining'=>(int)($plan['remaining_count']??0)]));
}

function agent_goal_review_capture_v1714(PDO $pdo,array $user,array $state,string $kind='review'): ?int
{
    $uid=agent_goal_review_require_v1714($pdo,$user);$goal=(array)($state['goal']??[]);$goalId=(int)($goal['id']??0);if($goalId<1||(!empty($state['forecast'])&&empty($state['forecast']['estimable'])))return null;if((string)($goal['derived_status']??'')==='achieved')return null;$kind=agent_goal_text_v1710($kind,32);if($kind==='')$kind='review';$hash=agent_goal_review_snapshot_hash_v1714($state,$kind);
    $latest=$pdo->prepare('SELECT id,state_hash,captured_at FROM agent_goal_review_snapshots WHERE owner_user_id=? AND goal_id=? ORDER BY id DESC LIMIT 1');$latest->execute([$uid,$goalId]);$row=$latest->fetch();if(is_array($row)&&hash_equals((string)($row['state_hash']??''),$hash)){ $captured=strtotime((string)($row['captured_at']??''))?:0;if($captured>0&&$captured>=time()-VP3_AGENT_GOAL_REVIEW_DEDUPE_SECONDS_V1714)return (int)$row['id']; }
    $forecast=(array)($state['forecast']??[]);$risk=(array)($state['risk']??[]);$capacity=(array)($state['capacity']??[]);$plan=(array)($state['plan']['plan']??[]);$execution=(array)($state['execution']??[]);$forecastDate=trim((string)($forecast['forecast_date']??''));$target=trim((string)($forecast['comparison_date']??$goal['target_date']??''));
    $stmt=$pdo->prepare('INSERT INTO agent_goal_review_snapshots (owner_user_id,goal_id,snapshot_kind,state_hash,forecast_date,target_date,forecast_days,confidence_percent,calibration_factor,risk_status,capacity_score,capacity_label,plan_health,execution_state,progress_percent,remaining_milestones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$uid,$goalId,$kind,$hash,$forecastDate!==''?$forecastDate:null,$target!==''?$target:null,max(0,(int)($forecast['forecast_days']??0)),max(0,min(100,(int)($forecast['confidence_percent']??0))),(float)($forecast['calibration_factor']??1.0),(string)($risk['status']??''),max(0,min(100,(int)($capacity['score_percent']??0))),(string)($capacity['label']??''),(string)($plan['health']['status']??''),(string)($execution['execution_state']??''),max(0,min(100,(int)($goal['progress_percent']??0))),max(0,(int)($plan['remaining_count']??0))]);return (int)$pdo->lastInsertId();
}

function agent_goal_review_history_v1714(PDO $pdo,int $uid,int $goalId): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_goal_review_snapshots WHERE owner_user_id=? AND goal_id=? ORDER BY id DESC LIMIT '.VP3_AGENT_GOAL_REVIEW_HISTORY_LIMIT_V1714);$stmt->execute([$uid,$goalId]);$rows=$stmt->fetchAll()?:[];$stats=['snapshot_count'=>count($rows),'risk_count'=>0,'blocked_count'=>0,'approval_count'=>0,'repair_count'=>0,'paused_count'=>0,'overcommit_count'=>0,'earliest_forecast_date'=>'','latest_forecast_date'=>'','forecast_drift_days'=>null,'latest_forecast_error_days'=>null];$chronological=array_reverse($rows);$firstForecast='';$lastForecast='';
    foreach($chronological as $row){$date=(string)($row['forecast_date']??'');if($date!==''){if($firstForecast==='')$firstForecast=$date;$lastForecast=$date;}if(in_array((string)($row['risk_status']??''),['at_risk','likely_miss'],true))$stats['risk_count']++;$exec=(string)($row['execution_state']??'');if($exec==='blocked')$stats['blocked_count']++;if($exec==='waiting_approval')$stats['approval_count']++;if($exec==='repair_needed')$stats['repair_count']++;if(in_array($exec,['paused','goal_paused'],true))$stats['paused_count']++;if(in_array((string)($row['capacity_label']??''),['high','overcommitted'],true))$stats['overcommit_count']++;}
    $stats['earliest_forecast_date']=$firstForecast;$stats['latest_forecast_date']=$lastForecast;if($firstForecast!==''&&$lastForecast!==''){$a=strtotime($firstForecast.' 00:00:00 UTC')?:0;$b=strtotime($lastForecast.' 00:00:00 UTC')?:0;if($a&&$b)$stats['forecast_drift_days']=(int)round(($b-$a)/86400);}foreach($rows as $row)if($row['forecast_error_days']!==null&&$row['forecast_error_days']!==''){$stats['latest_forecast_error_days']=(int)$row['forecast_error_days'];break;}return ['rows'=>$rows,'stats'=>$stats];
}

function agent_goal_review_lessons_v1714(PDO $pdo,array $user,array $goal,array $history,array $calibration): array
{
    $stats=(array)($history['stats']??[]);$lessons=[];$recommendations=[];
    if((int)($stats['blocked_count']??0)>=2){$lessons[]='Dependency blocking has repeated across reviews.';$recommendations[]='Front-load prerequisite discovery and link dependencies before committing the downstream milestone.';}
    if((int)($stats['approval_count']??0)>=2){$lessons[]='Approval waits have repeated across reviews.';$recommendations[]='Surface approval-requiring steps earlier in the roadmap so human decisions do not become late critical-path surprises.';}
    if((int)($stats['repair_count']??0)>=1){$lessons[]='At least one review observed remediation/repair work.';$recommendations[]='Tighten success criteria and add validation earlier, while keeping Phase 17.6 as the only achievement authority.';}
    if((int)($stats['overcommit_count']??0)>=2){$lessons[]='High portfolio load has repeatedly overlapped this goal.';$recommendations[]='Reduce parallel commitments or explicitly move lower-value goals before compressing this roadmap.';}
    $bias=(int)($calibration['median_error_days']??0);$mae=(int)($calibration['median_absolute_error_days']??0);$samples=(int)($calibration['sample_count']??0);if($samples>=2&&$bias>=2){$lessons[]='Verified forecast history has been optimistic by a median '.$bias.' days.';$recommendations[]='Keep the learned schedule buffer in future forecasts instead of promising the uncalibrated date.';}elseif($samples>=2&&$bias<=-2){$lessons[]='Verified forecast history has tended to finish earlier than predicted.';$recommendations[]='Keep the safety buffer, but treat the earlier completion tendency as evidence rather than automatically pulling target dates forward.';}if($samples>=2&&$mae>=5){$lessons[]='Forecast variability remains material (median absolute error '.$mae.' days).';$recommendations[]='Prefer ranges/scenario comparisons and reforecast after each verified milestone rather than over-trusting a single date.';}
    $memory=[];try{if(agent_objective_memory_schema_ready_v177($pdo))$memory=agent_objective_memory_similar_v177($pdo,$user,(string)($goal['goal']??''),5,true);}catch(Throwable $e){}$remediated=0;$failed=0;$achieved=0;foreach($memory as $row){$outcome=(string)($row['outcome_status']??'');if($outcome==='remediated')$remediated++;elseif($outcome==='failed')$failed++;elseif($outcome==='achieved')$achieved++;}if($remediated>0)$recommendations[]='Reuse the successful portions of similar verified objective plans, but incorporate their remediation lessons before execution.';if($failed>0)$recommendations[]='Avoid blindly replaying similar failed historical plans; Phase 17.7 memory should remain evidence, not authority.';if(!$lessons)$lessons[]='No repeated negative pattern is strong enough yet to call a learned failure mode.';if(!$recommendations)$recommendations[]='Keep the current strategy and gather another verified milestone/outcome before making evidence-based changes.';
    $buffer=(float)($calibration['factor']??1.0);$candidate='Keep the highest-contribution unblocked objective first; surface prerequisites and approvals before they enter the critical path; verify each milestone through Phase 17.6; and reforecast after verified outcomes';if($buffer>1.03)$candidate.=' while preserving the learned '.number_format($buffer,2).'× timing calibration';$candidate.='.';
    return ['lessons'=>array_slice(array_values(array_unique($lessons)),0,5),'recommendations'=>array_slice(array_values(array_unique($recommendations)),0,5),'strategy_candidate'=>$candidate,'memory'=>['matched'=>count($memory),'achieved'=>$achieved,'remediated'=>$remediated,'failed'=>$failed]];
}

function agent_goal_review_state_v1714(PDO $pdo,array $user,int $goalId): array
{
    $uid=agent_goal_review_require_v1714($pdo,$user);$settled=agent_goal_review_settle_goal_v1714($pdo,$user,$goalId);$state=agent_goal_review_forecast_state_v1714($pdo,$user,$goalId,null);$goal=(array)($state['goal']??[]);if((string)($goal['derived_status']??'')!=='achieved')agent_goal_review_capture_v1714($pdo,$user,$state,'explicit_review');$history=agent_goal_review_history_v1714($pdo,$uid,$goalId);$calibration=(array)($state['calibration']??[]);$learning=agent_goal_review_lessons_v1714($pdo,$user,$goal,$history,$calibration);return ['build'=>VP3_AGENT_GOAL_REVIEW_V1714,'goal'=>$goal,'forecast_state'=>$state,'settled'=>$settled,'history'=>$history,'calibration'=>$calibration,'learning'=>$learning];
}

function agent_goal_review_answer_v1714(array $review): string
{
    $goal=(array)($review['goal']??[]);$state=(array)($review['forecast_state']??[]);$forecast=(array)($state['forecast']??[]);$risk=(array)($state['risk']??[]);$plan=(array)($state['plan']['plan']??[]);$history=(array)($review['history']['stats']??[]);$cal=(array)($review['calibration']??[]);$learning=(array)($review['learning']??[]);$id=(int)($goal['id']??0);$answer='Goal #'.$id.' review: '.(int)($goal['progress_percent']??0).'% verified · plan '.(string)($plan['health']['status']??'unknown').'.';
    if((string)($goal['derived_status']??'')==='achieved'){$error=$history['latest_forecast_error_days']??null;$answer.=' The goal is verified achieved.';if($error!==null)$answer.=' The most recent settled forecast error was '.((int)$error>0?'+':'').(int)$error.' days.';}elseif(!empty($forecast['estimable'])){$answer.=' Current learned forecast: '.(string)($forecast['forecast_date']??'').' (~'.(int)($forecast['forecast_days']??0).' days) · '.(string)($forecast['confidence_label']??'low').' confidence · risk '.(string)($risk['status']??'unknown').'.';}else{$answer.=' There is still insufficient roadmap evidence for a completion date.';}
    if((int)($cal['sample_count']??0)>0)$answer.=' Forecast calibration: '.number_format((float)($cal['factor']??1.0),2).'× from '.(int)$cal['sample_count'].' verified completed-goal sample'.((int)$cal['sample_count']===1?'':'s').' · median error '.((int)$cal['median_error_days']>0?'+':'').(int)$cal['median_error_days'].' days · median absolute error '.(int)$cal['median_absolute_error_days'].' days.';else $answer.=' Forecast calibration: no settled verified forecast history yet, so 17.13 remains the baseline.';
    $lessons=(array)($learning['lessons']??[]);if($lessons)$answer.=' Learned pattern: '.implode(' ',array_slice($lessons,0,2));$recommendations=(array)($learning['recommendations']??[]);if($recommendations)$answer.=' Recommended improvement: '.implode(' ',array_slice($recommendations,0,2));$candidate=trim((string)($learning['strategy_candidate']??''));if($candidate!=='')$answer.=' Strategy candidate: “'.$candidate.'”';
    return $answer.' Review snapshots are advisory learning data only. Nothing here changes the goal, roadmap, target date, approvals, dependencies, or execution state unless you explicitly use the existing controls.';
}

function agent_goal_review_portfolio_v1714(PDO $pdo,array $user): array
{
    $uid=agent_goal_review_require_v1714($pdo,$user);agent_goal_review_settle_owner_v1714($pdo,$user);$capacity=agent_goal_capacity_v1713($pdo,$user);$calibration=agent_goal_review_calibration_v1714($pdo,$user,'');$stmt=$pdo->prepare('SELECT risk_status,capacity_label,execution_state FROM agent_goal_review_snapshots WHERE owner_user_id=? ORDER BY id DESC LIMIT '.VP3_AGENT_GOAL_REVIEW_HISTORY_LIMIT_V1714);$stmt->execute([$uid]);$patterns=['at_risk'=>0,'blocked'=>0,'approval'=>0,'repair'=>0,'overcommit'=>0];foreach($stmt->fetchAll()?:[] as $row){if(in_array((string)($row['risk_status']??''),['at_risk','likely_miss'],true))$patterns['at_risk']++;$exec=(string)($row['execution_state']??'');if($exec==='blocked')$patterns['blocked']++;if($exec==='waiting_approval')$patterns['approval']++;if($exec==='repair_needed')$patterns['repair']++;if(in_array((string)($row['capacity_label']??''),['high','overcommitted'],true))$patterns['overcommit']++;}$goals=agent_goal_list_v1710($pdo,$user,false);return ['build'=>VP3_AGENT_GOAL_REVIEW_V1714,'capacity'=>$capacity,'calibration'=>$calibration,'patterns'=>$patterns,'goals'=>array_slice($goals,0,5)];
}

function agent_goal_review_portfolio_answer_v1714(array $review): string
{
    $capacity=(array)($review['capacity']??[]);$cal=(array)($review['calibration']??[]);$patterns=(array)($review['patterns']??[]);$answer='Goal portfolio review: capacity '.(string)($capacity['label']??'unknown').' ('.(int)($capacity['score_percent']??0).'/100).';if((int)($cal['sample_count']??0)>0)$answer.=' Learned forecast calibration is '.number_format((float)($cal['factor']??1.0),2).'× from '.(int)$cal['sample_count'].' verified completed-goal sample'.((int)$cal['sample_count']===1?'':'s').' with '.(int)$cal['median_absolute_error_days'].'-day median absolute error.';else $answer.=' There is not enough settled forecast history for portfolio calibration yet.';
    $signals=[];if((int)($patterns['blocked']??0)>=2)$signals[]='dependency blocking';if((int)($patterns['approval']??0)>=2)$signals[]='approval waits';if((int)($patterns['repair']??0)>=1)$signals[]='remediation';if((int)($patterns['overcommit']??0)>=2)$signals[]='high parallel load';if((int)($patterns['at_risk']??0)>=2)$signals[]='repeated schedule risk';$answer.=$signals?' Recurring review signals: '.implode(', ',$signals).'.':' No recurring negative review signal is strong enough yet.';
    $goals=(array)($review['goals']??[]);if($goals){$top=(array)($goals[0]['goal']??[]);$answer.=' Highest current attention is goal #'.(int)($top['id']??0).' at '.(int)($top['attention_score_percent']??0).'/100.';}return $answer.' Continuous improvement stays advisory; it never reprioritizes or changes execution automatically.';
}

function agent_goal_review_chat_v1714(string $query,array $user,int $conversationId=0): array
{
    $commitment=agent_goal_commitment_chat_v1715($query,$user,$conversationId);if(!empty($commitment['handled']))return $commitment;
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;$goalId=agent_goal_execution_extract_id_v1712($q);$reviewIntent=(bool)preg_match('/\b(?:review|retrospective|postmortem|post-mortem|lesson|lessons|learned|learning|forecast accuracy|accuracy|continuous improvement|improve|improvement)\b/i',$q)||(bool)preg_match('/\bwhy\b.*\b(?:goal|goals)\b.*\b(?:slip|late|miss|delay|fail|stuck)\b/i',$q)||(bool)preg_match('/\bwhat\b.*\b(?:change|improve)\b.*\bgoal\b/i',$q);$portfolioIntent=$goalId<1&&$reviewIntent&&(bool)preg_match('/\b(?:goals|portfolio|overall|across)\b/i',$q);
    $forecastIntent=$goalId>0&&(bool)preg_match('/\b(?:forecast|predict|prediction|finish|complete|completion|on track|hit|make|achieve|deadline|when will|how long)\b/i',$q);$riskIntent=$goalId>0&&(bool)(preg_match('/\b(?:risk|at risk|miss|late|delay|slip|behind|putting|threat)\b/i',$q)&&preg_match('/\b(?:goal|target|deadline|date|risk|miss)\b/i',$q));$scenarioIntent=$goalId>0&&(bool)preg_match('/\b(?:scenario|compare|what if|fastest|lowest[- ]risk|highest[- ]value)\b/i',$q);
    if(!$reviewIntent&&!$forecastIntent&&!$riskIntent&&!$scenarioIntent)return $empty;$pdo=db();if(!$pdo)return $empty;
    if(!agent_goal_review_schema_ready_v1714($pdo)){if(!$reviewIntent)return $empty;$out=$empty;$out['handled']=true;$out['answer']='Goal review learning is not ready yet. Run the normal VP3 database upgrade, then review the goal again.';return $out;}
    try{
        $out=$empty;$out['handled']=true;if($portfolioIntent){$review=agent_goal_review_portfolio_v1714($pdo,$user);$out['answer']=agent_goal_review_portfolio_answer_v1714($review);$tool='goal.review.portfolio';$log=['capacity'=>(int)$review['capacity']['score_percent'],'samples'=>(int)$review['calibration']['sample_count']];}
        elseif($goalId>0&&$reviewIntent&&!$forecastIntent&&!$riskIntent&&!$scenarioIntent){$review=agent_goal_review_state_v1714($pdo,$user,$goalId);$out['answer']=agent_goal_review_answer_v1714($review);$tool='goal.review.inspect';$log=['goal_id'=>$goalId,'samples'=>(int)$review['calibration']['sample_count'],'snapshot_count'=>(int)$review['history']['stats']['snapshot_count']];}
        elseif($goalId>0){$comparison=agent_goal_forecast_extract_comparison_date_v1713($q);$state=agent_goal_review_forecast_state_v1714($pdo,$user,$goalId,$comparison);agent_goal_review_capture_v1714($pdo,$user,$state,'forecast_query');$mode=$scenarioIntent?'scenarios':($riskIntent?'risk':'forecast');$out['answer']=agent_goal_forecast_answer_v1713($state,$mode);$cal=(array)($state['calibration']??[]);if((int)($cal['sample_count']??0)>0)$out['answer'].=' Learning calibration: '.number_format((float)($cal['factor']??1.0),2).'× from '.(int)$cal['sample_count'].' verified completed-goal sample'.((int)$cal['sample_count']===1?'':'s').' (median absolute error '.(int)$cal['median_absolute_error_days'].' days).';$tool='goal.review.forecast.'.$mode;$log=['goal_id'=>$goalId,'forecast_date'=>(string)$state['forecast']['forecast_date'],'factor'=>(float)$state['forecast']['calibration_factor'],'samples'=>(int)$state['forecast']['calibration_sample_count']];}
        else return $empty;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',$log+['build'=>VP3_AGENT_GOAL_REVIEW_V1714],$conversationId);return $out;
    }catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not complete the goal review: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.review',$query,'error',['goal_id'=>$goalId,'error'=>get_class($e),'build'=>VP3_AGENT_GOAL_REVIEW_V1714],$conversationId);return $out;}
}
