<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.15 — Strategic Check-ins & Commitment Management.
 *
 * This is a read-only decision-support layer over the canonical goal, review,
 * objective, and workflow ledgers. It distinguishes an intentional pause from
 * a stale active commitment, surfaces drift, and recommends an explicit user
 * decision. It never changes goal status/strategy/priority/target dates,
 * roadmap order, approvals, dependencies, execution state, or Phase 19 work.
 */
const VP3_AGENT_GOAL_COMMITMENTS_V1715='agent-goal-commitments-v1715-20260915';
const VP3_AGENT_GOAL_COMMITMENT_CHECKIN_DAYS_V1715=14;
const VP3_AGENT_GOAL_COMMITMENT_STALE_DAYS_V1715=21;
const VP3_AGENT_GOAL_COMMITMENT_DRIFT_DAYS_V1715=7;

function agent_goal_commitment_days_since_v1715(?string $value): ?int
{
    $value=trim((string)$value);if($value==='')return null;$ts=strtotime($value);if($ts===false)return null;
    return max(0,(int)floor((time()-$ts)/86400));
}

function agent_goal_commitment_latest_activity_v1715(array $goal,array $objectives): array
{
    $latest='';$latestTs=0;$source='goal';
    $candidates=[['at'=>(string)($goal['updated_at']??$goal['created_at']??''),'source'=>'goal']];
    foreach($objectives as $row)if(is_array($row))$candidates[]=['at'=>(string)($row['updated_at']??$row['linked_at']??''),'source'=>'objective #'.(int)($row['id']??0)];
    foreach($candidates as $item){$at=trim((string)$item['at']);$ts=$at!==''?(strtotime($at)?:0):0;if($ts>$latestTs){$latestTs=$ts;$latest=$at;$source=(string)$item['source'];}}
    return ['at'=>$latest,'source'=>$source,'days_since'=>$latestTs>0?max(0,(int)floor((time()-$latestTs)/86400)):null];
}

function agent_goal_commitment_review_delta_v1715(array $history): array
{
    $rows=(array)($history['rows']??[]);$latest=is_array($rows[0]??null)?$rows[0]:[];$previous=is_array($rows[1]??null)?$rows[1]:[];
    $forecastDrift=null;$progressDelta=null;
    $a=trim((string)($latest['forecast_date']??''));$b=trim((string)($previous['forecast_date']??''));
    if($a!==''&&$b!==''){$ta=strtotime($a.' 00:00:00 UTC')?:0;$tb=strtotime($b.' 00:00:00 UTC')?:0;if($ta&&$tb)$forecastDrift=(int)round(($ta-$tb)/86400);}
    if($latest&&$previous)$progressDelta=(int)($latest['progress_percent']??0)-(int)($previous['progress_percent']??0);
    return ['forecast_drift_days'=>$forecastDrift,'progress_delta'=>$progressDelta,'snapshot_count'=>count($rows)];
}

function agent_goal_commitment_state_v1715(PDO $pdo,array $user,int $goalId): array
{
    $uid=agent_goal_review_require_v1714($pdo,$user);$state=agent_goal_review_forecast_state_v1714($pdo,$user,$goalId,null);$goal=(array)($state['goal']??[]);$objectives=agent_goal_objectives_v1710($pdo,$uid,$goalId);$history=agent_goal_review_history_v1714($pdo,$uid,$goalId);$delta=agent_goal_commitment_review_delta_v1715($history);$activity=agent_goal_commitment_latest_activity_v1715($goal,$objectives);
    $status=(string)($goal['status']??'active');$derived=(string)($goal['derived_status']??'active');$progress=max(0,min(100,(int)($goal['progress_percent']??0)));$risk=(string)($state['risk']['status']??'');$target=trim((string)($goal['target_date']??''));$daysToTarget=null;
    if($target!==''){$today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();$targetTs=strtotime($target.' 00:00:00 UTC')?:0;if($targetTs)$daysToTarget=(int)floor(($targetTs-$today)/86400);}
    $staleDays=$activity['days_since'];$drift=$delta['forecast_drift_days'];$progressDelta=$delta['progress_delta'];$intentionalPause=$status==='paused';$score=0;$decision='keep_working';$reason='The commitment is active and does not currently need a strategic decision.';
    if($derived==='achieved'){$decision='completed';$reason='Every linked objective is Phase 17.6 verified achieved.';$score=0;}
    elseif($status==='archived'){$decision='historical';$reason='This goal is archived immutable history.';$score=0;}
    elseif($intentionalPause){$score=35;if($daysToTarget!==null&&$daysToTarget<0)$score=65;elseif($staleDays!==null&&$staleDays>=VP3_AGENT_GOAL_COMMITMENT_STALE_DAYS_V1715)$score=50;$decision='revisit_pause';$reason='The inactivity is intentional because the goal is paused; decide whether to keep it paused or explicitly resume it.';}
    elseif(count($objectives)===0){$score=75;$decision='structure_goal';$reason='The active goal has no linked objective, so there is no canonical work path to advance.';}
    elseif($daysToTarget!==null&&$daysToTarget<0&&$progress<100){$score=95;$decision='revise_or_recommit';$reason='The target date has passed without verified goal achievement.';}
    elseif(in_array($risk,['at_risk','likely_miss'],true)){$score=85;$decision='revise_or_recommit';$reason='The learned forecast currently indicates material schedule risk.';}
    elseif($drift!==null&&$drift>=VP3_AGENT_GOAL_COMMITMENT_DRIFT_DAYS_V1715&&($progressDelta===null||$progressDelta<=0)){$score=80;$decision='replan_or_recommit';$reason='The completion forecast moved later by '.$drift.' days without new verified progress between the two latest reviews.';}
    elseif($staleDays!==null&&$staleDays>=VP3_AGENT_GOAL_COMMITMENT_STALE_DAYS_V1715){$score=72;$decision='recommit_pause_or_archive';$reason='This active commitment has had no goal/objective activity for '.$staleDays.' days.';}
    elseif($staleDays!==null&&$staleDays>=VP3_AGENT_GOAL_COMMITMENT_CHECKIN_DAYS_V1715){$score=58;$decision='check_in';$reason='This active commitment has been quiet for '.$staleDays.' days and merits a deliberate check-in.';}
    elseif($drift!==null&&$drift>=VP3_AGENT_GOAL_COMMITMENT_DRIFT_DAYS_V1715){$score=55;$decision='review_drift';$reason='The completion forecast has moved later by '.$drift.' days since the previous review.';}
    $score=max(0,min(100,$score));
    return ['build'=>VP3_AGENT_GOAL_COMMITMENTS_V1715,'goal'=>$goal,'commitment'=>['decision'=>$decision,'score_percent'=>$score,'reason'=>$reason,'intentional_pause'=>$intentionalPause,'last_activity_at'=>$activity['at'],'last_activity_source'=>$activity['source'],'days_since_activity'=>$staleDays,'days_to_target'=>$daysToTarget,'forecast_drift_days'=>$drift,'verified_progress_delta'=>$progressDelta,'snapshot_count'=>$delta['snapshot_count'],'risk_status'=>$risk],'review_state'=>$state];
}

function agent_goal_commitment_portfolio_v1715(PDO $pdo,array $user): array
{
    agent_goal_review_require_v1714($pdo,$user);$goals=agent_goal_list_v1710($pdo,$user,false);$items=[];
    foreach($goals as $state){$goal=(array)($state['goal']??[]);$id=(int)($goal['id']??0);if($id<1)continue;try{$items[]=agent_goal_commitment_state_v1715($pdo,$user,$id);}catch(Throwable $e){}}
    usort($items,static fn(array $a,array $b):int=>((int)($b['commitment']['score_percent']??0)<=>(int)($a['commitment']['score_percent']??0))?:((int)($b['goal']['attention_score_percent']??0)<=>(int)($a['goal']['attention_score_percent']??0)));
    return ['build'=>VP3_AGENT_GOAL_COMMITMENTS_V1715,'items'=>array_slice($items,0,12)];
}

function agent_goal_commitment_decision_label_v1715(string $decision): string
{
    return match($decision){
        'completed'=>'No decision needed',
        'historical'=>'Archived history',
        'revisit_pause'=>'Keep paused or resume',
        'structure_goal'=>'Define or link an objective',
        'revise_or_recommit'=>'Revise the plan/date or recommit',
        'replan_or_recommit'=>'Replan or recommit',
        'recommit_pause_or_archive'=>'Recommit, pause, or archive',
        'check_in'=>'Confirm the commitment',
        'review_drift'=>'Review forecast drift',
        default=>'Keep working',
    };
}

function agent_goal_commitment_answer_v1715(array $state): string
{
    $goal=(array)($state['goal']??[]);$c=(array)($state['commitment']??[]);$id=(int)($goal['id']??0);$answer='Goal #'.$id.' commitment check: '.(int)($c['score_percent']??0).'/100 decision pressure · '.(int)($goal['progress_percent']??0).'% verified progress. '.(string)($c['reason']??'');
    if($c['days_since_activity']!==null)$answer.=' Last canonical goal/objective activity was '.(int)$c['days_since_activity'].' days ago';if(trim((string)($c['last_activity_source']??''))!=='')$answer.=' ('.(string)$c['last_activity_source'].')';$answer.='.';
    if($c['forecast_drift_days']!==null)$answer.=' Latest review-to-review forecast drift: '.((int)$c['forecast_drift_days']>0?'+':'').(int)$c['forecast_drift_days'].' days.';
    $answer.=' Recommended decision: '.agent_goal_commitment_decision_label_v1715((string)($c['decision']??'keep_working')).'.';
    if(!empty($c['intentional_pause']))$answer.=' A paused goal is treated as an intentional hold, not as an abandoned commitment.';
    return $answer.' This check-in is advisory only. I will not pause, resume, archive, reprioritize, change dates, edit the roadmap, approve work, or execute anything unless you explicitly use the existing controls.';
}

function agent_goal_commitment_portfolio_answer_v1715(array $portfolio): string
{
    $items=(array)($portfolio['items']??[]);if(!$items)return 'There are no active or paused goals that need a commitment check-in.';$lines=[];$needs=0;
    foreach(array_slice($items,0,8) as $item){$goal=(array)($item['goal']??[]);$c=(array)($item['commitment']??[]);if((int)($c['score_percent']??0)>=50)$needs++;$lines[]='#'.(int)($goal['id']??0).' · '.(int)($c['score_percent']??0).'/100 · '.agent_goal_commitment_decision_label_v1715((string)($c['decision']??'keep_working')).' · '.agent_goal_text_v1710($goal['goal']??'',150);}
    return 'Strategic commitment check: '.$needs.' goal'.($needs===1?'':'s').' currently merit'.($needs===1?'s':'').' an explicit decision.' ."\n".implode("\n",$lines)."\n".'Paused goals are treated as intentional holds. Active stale/drifting goals are surfaced for a decision, never auto-paused or auto-archived.';
}

function agent_goal_commitment_chat_v1715(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;
    $intent=(bool)preg_match('/\b(?:check[- ]?in|commitment|committed|recommit|re-commit|stale|neglected|abandoned|dormant|forgotten|inactive goal|still working|still committed|should i pause|should i archive|keep working|drop goal|drop the goal)\b/i',$q);
    if(!$intent)return $empty;$goalId=agent_goal_execution_extract_id_v1712($q);$pdo=db();if(!$pdo)return $empty;
    if(!agent_goal_review_schema_ready_v1714($pdo)){$out=$empty;$out['handled']=true;$out['answer']='Strategic goal check-ins need the Phase 17.14 review history. Run the normal VP3 database upgrade, then ask again.';return $out;}
    try{$out=$empty;$out['handled']=true;if($goalId>0){$state=agent_goal_commitment_state_v1715($pdo,$user,$goalId);$out['answer']=agent_goal_commitment_answer_v1715($state);$tool='goal.commitment.inspect';$log=['goal_id'=>$goalId,'decision'=>(string)$state['commitment']['decision'],'pressure'=>(int)$state['commitment']['score_percent']];}else{$portfolio=agent_goal_commitment_portfolio_v1715($pdo,$user);$out['answer']=agent_goal_commitment_portfolio_answer_v1715($portfolio);$tool='goal.commitment.portfolio';$log=['count'=>count((array)$portfolio['items'])];}if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',$log+['build'=>VP3_AGENT_GOAL_COMMITMENTS_V1715],$conversationId);return $out;}
    catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not complete the strategic commitment check: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.commitment',$query,'error',['goal_id'=>$goalId,'error'=>get_class($e),'build'=>VP3_AGENT_GOAL_COMMITMENTS_V1715],$conversationId);return $out;}
}
