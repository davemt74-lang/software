<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.12 — Goal Execution Orchestration.
 *
 * This layer translates an explicit goal-level instruction into the next
 * canonical objective/workflow step. It does not schedule, claim, lease,
 * approve, retry, receipt, or complete work. Phase 19 and the existing
 * objective/dependency/approval systems remain authoritative.
 */
const VP3_AGENT_GOAL_EXECUTION_V1712='agent-goal-execution-v1712-20260915';

require_once __DIR__.'/agent-goal-planning-v1711.php';

function agent_goal_execution_require_v1712(PDO $pdo,array $user): int
{
    return agent_goal_planning_require_v1711($pdo,$user);
}

function agent_goal_execution_child_state_v1712(PDO $pdo,int $uid,array $child): array
{
    $id=(int)($child['id']??0);
    $row=$id>0?agent_workflow_row_v1400($pdo,$uid,$id):null;
    if(!$row)return ['run_id'=>$id,'state'=>'missing','title'=>(string)($child['title']??''),'execution_target'=>(string)($child['execution_target']??'cloud'),'blockers'=>[]];

    $status=(string)($row['status']??'');
    $approval=(string)($row['approval_status']??'');
    $blockers=agent_work_dependency_blockers_v174($pdo,$uid,$id);
    $nextAttempt=trim((string)($row['next_attempt_at']??''));
    $scheduled=$nextAttempt!==''&&(strtotime($nextAttempt.' UTC')?:0)>time()+5;

    $state=match(true){
        $status==='executing'=>'executing',
        $status==='approval_pending'||$approval==='pending'=>'waiting_approval',
        $status==='failed'||$status==='cancelled'=>'repair_needed',
        $status==='paused'=>'paused',
        count($blockers)>0=>'blocked',
        $status==='approved'&&$scheduled=>'scheduled',
        $status==='approved'=>'ready',
        $status==='completed'=>'completed',
        default=>$status!==''?$status:'unknown',
    };

    return [
        'run_id'=>$id,
        'title'=>(string)($row['title']??$child['title']??''),
        'status'=>$status,
        'state'=>$state,
        'approval_status'=>$approval,
        'execution_target'=>(string)($row['execution_target']??'cloud'),
        'next_attempt_at'=>$nextAttempt,
        'work_priority'=>(int)($row['work_priority']??VP3_AGENT_WORK_PRIORITY_NORMAL_V173),
        'objective_stage'=>(int)($child['objective_stage']??0),
        'objective_task'=>(int)($child['objective_task']??0),
        'blockers'=>$blockers,
    ];
}

function agent_goal_execution_focus_v1712(PDO $pdo,int $uid,array $objectiveState): ?array
{
    $children=(array)($objectiveState['children']??[]);
    if(!$children)return null;
    $states=[];
    foreach($children as $child){
        if(!is_array($child))continue;
        $state=agent_goal_execution_child_state_v1712($pdo,$uid,$child);
        if($state['state']==='completed')continue;
        $states[]=$state;
    }
    if(!$states)return null;

    usort($states,static function(array $a,array $b): int{
        $stageA=(int)($a['objective_stage']??0);$stageB=(int)($b['objective_stage']??0);
        if($stageA!==$stageB)return $stageA<=>$stageB;
        $rank=['executing'=>0,'ready'=>1,'waiting_approval'=>2,'repair_needed'=>3,'paused'=>4,'scheduled'=>5,'blocked'=>6,'unknown'=>7];
        $ra=$rank[(string)($a['state']??'unknown')]??8;$rb=$rank[(string)($b['state']??'unknown')]??8;
        if($ra!==$rb)return $ra<=>$rb;
        $priority=((int)($b['work_priority']??50))<=>((int)($a['work_priority']??50));
        if($priority!==0)return $priority;
        $task=((int)($a['objective_task']??0))<=>((int)($b['objective_task']??0));
        return $task!==0?$task:((int)$a['run_id']<=>(int)$b['run_id']);
    });

    $firstStage=(int)($states[0]['objective_stage']??0);
    $stageStates=array_values(array_filter($states,static fn(array $x):bool=>(int)($x['objective_stage']??0)===$firstStage));
    usort($stageStates,static function(array $a,array $b): int{
        $rank=['executing'=>0,'ready'=>1,'waiting_approval'=>2,'repair_needed'=>3,'paused'=>4,'scheduled'=>5,'blocked'=>6,'unknown'=>7];
        $ra=$rank[(string)($a['state']??'unknown')]??8;$rb=$rank[(string)($b['state']??'unknown')]??8;
        if($ra!==$rb)return $ra<=>$rb;
        $priority=((int)($b['work_priority']??50))<=>((int)($a['work_priority']??50));
        if($priority!==0)return $priority;
        return ((int)($a['objective_task']??0))<=>((int)($b['objective_task']??0));
    });
    return $stageStates[0]??$states[0];
}

function agent_goal_execution_state_v1712(PDO $pdo,array $user,int $goalId): array
{
    $uid=agent_goal_execution_require_v1712($pdo,$user);
    $plan=agent_goal_plan_state_v1711($pdo,$user,$goalId);
    $goal=(array)($plan['goal']??[]);
    $planState=(array)($plan['plan']??[]);
    $milestone=is_array($planState['next_milestone']??null)?$planState['next_milestone']:null;
    $result=[
        'build'=>VP3_AGENT_GOAL_EXECUTION_V1712,
        'goal'=>$goal,
        'plan'=>$plan,
        'milestone'=>$milestone,
        'objective'=>null,
        'focus'=>null,
        'execution_state'=>'unknown',
        'reason'=>'',
    ];

    if((string)($goal['status']??'')==='paused'){
        $result['execution_state']='goal_paused';$result['reason']='The goal is paused. Resume the goal before changing its roadmap or creating new objective work.';return $result;
    }
    if((string)($goal['derived_status']??'')==='achieved'){
        $result['execution_state']='achieved';$result['reason']='The goal is already achieved from verified linked objective outcomes.';return $result;
    }
    if(!$milestone){
        $health=(string)($planState['health']['status']??'plan_missing');
        $result['execution_state']=$health==='plan_missing'?'plan_missing':'review_needed';
        $result['reason']=$health==='plan_missing'?'No roadmap exists yet.':'The roadmap has no unresolved milestone, but the goal is not yet verified achieved.';
        return $result;
    }

    $objectiveId=(int)($milestone['objective_run_id']??0);
    if($objectiveId<1){
        $result['execution_state']='needs_objective';
        $result['reason']='The next roadmap milestone is still advisory and has no canonical objective yet.';
        return $result;
    }

    $objective=agent_objective_state_v175($pdo,$user,$objectiveId,false);
    $result['objective']=$objective;
    $verification=(string)($objective['verification_status']??'');
    if($verification==='achieved'){
        $result['execution_state']='objective_achieved';
        $result['reason']='The linked objective is verified achieved; the derived roadmap should advance to the next unresolved milestone.';
        return $result;
    }

    $focus=agent_goal_execution_focus_v1712($pdo,$uid,$objective);
    $result['focus']=$focus;
    if($focus){
        $result['execution_state']=(string)$focus['state'];
        $result['reason']=match((string)$focus['state']){
            'executing'=>'The next canonical workflow is already executing.',
            'ready'=>'The next canonical workflow is approved, dependency-clear, and ready for the Phase 19 claimant.',
            'waiting_approval'=>'The next canonical workflow is waiting for explicit approval.',
            'repair_needed'=>'The next canonical workflow failed or was cancelled and needs repair/replanning before the goal can advance.',
            'paused'=>'The next canonical workflow is paused.',
            'scheduled'=>'The next canonical workflow is approved but scheduled for a future time.',
            'blocked'=>'The next canonical workflow is waiting for one or more prerequisite workflows.',
            default=>'The next canonical workflow is not currently claim-ready.',
        };
        return $result;
    }

    $counts=(array)($objective['counts']??[]);
    if((int)($counts['completed']??0)>=(int)($counts['total']??0)&&(int)($counts['total']??0)>0){
        $result['execution_state']='verification';
        $result['reason']='All child workflows are complete. The objective is waiting for canonical Phase 17.6 outcome verification.';
    }else{
        $result['execution_state']='review_needed';
        $result['reason']='The objective has no unfinished child workflow that can be selected safely.';
    }
    return $result;
}

function agent_goal_execution_advance_v1712(PDO $pdo,array $user,int $goalId,int $conversationId=0): array
{
    $state=agent_goal_execution_state_v1712($pdo,$user,$goalId);
    if((string)$state['execution_state']==='plan_missing'){
        agent_goal_plan_seed_v1711($pdo,$user,$goalId);
        $state=agent_goal_execution_state_v1712($pdo,$user,$goalId);
    }
    if((string)$state['execution_state']==='needs_objective'){
        $milestone=(array)($state['milestone']??[]);
        $milestoneId=(int)($milestone['id']??0);
        if($milestoneId<1)throw new RuntimeException('The next roadmap milestone is unavailable.');
        agent_goal_plan_create_objective_v1711($pdo,$user,$goalId,$milestoneId,$conversationId);
        $state=agent_goal_execution_state_v1712($pdo,$user,$goalId);
        $state['objective_created']=true;
    }else{
        $state['objective_created']=false;
    }
    return $state;
}

function agent_goal_execution_answer_v1712(array $state,string $mode='status'): string
{
    $goal=(array)($state['goal']??[]);$goalId=(int)($goal['id']??0);$milestone=is_array($state['milestone']??null)?$state['milestone']:null;$objective=is_array($state['objective']??null)?$state['objective']:null;$focus=is_array($state['focus']??null)?$state['focus']:null;$execution=(string)($state['execution_state']??'unknown');
    $prefix=$mode==='advance'?'Goal #'.$goalId.' orchestration: ':'Goal #'.$goalId.': ';
    if($execution==='achieved')return $prefix.'achieved. '.(string)$state['reason'];
    if($execution==='goal_paused')return $prefix.(string)$state['reason'].' Say “resume goal #'.$goalId.'” first.';
    if($execution==='plan_missing')return $prefix.(string)$state['reason'].' Say “build plan for goal #'.$goalId.'” or “work on goal #'.$goalId.'” to create the advisory roadmap.';
    if($execution==='review_needed')return $prefix.(string)$state['reason'].' Review the goal roadmap before creating more work.';
    if($execution==='needs_objective')return $prefix.(string)$state['reason'].' The next milestone is #'.(int)($milestone['id']??0).' “'.agent_goal_text_v1710($milestone['title']??'',180).'”. Say “work on goal #'.$goalId.'” to explicitly convert it into a fresh Phase 17.5 objective.';
    if($execution==='objective_achieved')return $prefix.(string)$state['reason'];
    if($execution==='verification')return $prefix.(string)$state['reason'].' Objective #'.(int)($objective['objective']['id']??0).' remains governed by Phase 17.6 verification.';

    $milestoneText=$milestone?'Milestone #'.(int)$milestone['id'].' “'.agent_goal_text_v1710($milestone['title']??'',160).'”':'Current milestone';
    $objectiveId=(int)($objective['objective']['id']??($milestone['objective_run_id']??0));
    if(!$focus)return $prefix.$milestoneText.' · objective #'.$objectiveId.'. '.(string)$state['reason'];
    $runId=(int)$focus['run_id'];$target=(string)$focus['execution_target']==='homeserver'?'HomeServer':'Cloud';$title=agent_goal_text_v1710($focus['title']??'',160);
    $created=!empty($state['objective_created'])?' I created the missing canonical objective from the advisory milestone; no duplicate objective was created and no worker state was bypassed.':'';
    $base=$prefix.$milestoneText.' · objective #'.$objectiveId.' · workflow #'.$runId.' “'.$title.'” · '.$target.'.'.$created.' ';
    return $base.match($execution){
        'executing'=>'It is currently executing through the existing Phase 19 worker/receipt path.',
        'ready'=>'It is approved and dependency-clear. Phase 19 can claim it normally; this goal command did not write lease, retry, receipt, or approval state.',
        'waiting_approval'=>'It is waiting for explicit approval. Say “approve workflow #'.$runId.'” to use the existing approval path; goal orchestration will not approve it automatically.',
        'repair_needed'=>'It needs repair or replanning before this goal can advance. Use the existing objective repair/replan controls for objective #'.$objectiveId.'.',
        'paused'=>'It is paused. Say “resume objective #'.$objectiveId.'” or use the existing workflow control; goal orchestration will not silently resume it.',
        'scheduled'=>'It is scheduled for '.((string)$focus['next_attempt_at']!==''?(string)$focus['next_attempt_at'].' UTC':'a future time').'. The existing schedule remains authoritative.',
        'blocked'=>'It is blocked by '.count((array)$focus['blockers']).' unfinished prerequisite'.(count((array)$focus['blockers'])===1?'':'s').'. The dependency graph remains authoritative.',
        default=>(string)$state['reason'],
    };
}

function agent_goal_execution_extract_id_v1712(string $query): int
{
    return preg_match('/\bgoal\s*#?\s*(\d+)\b/i',$query,$m)?(int)$m[1]:0;
}

function agent_goal_execution_chat_v1712(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;$goalId=agent_goal_execution_extract_id_v1712($q);if($goalId<1)return $empty;
    $advance=(bool)(preg_match('/\b(?:advance|work\s+on|start\s+work\s+on|continue\s+work\s+on|move\s+forward\s+on)\s+goal\s*#?\s*\d+\b/i',$q)||preg_match('/\bgoal\s*#?\s*\d+\s+(?:advance|work|continue)\b/i',$q));
    $why=(bool)preg_match('/\bwhy\b.*\bgoal\s*#?\s*\d+\b.*\b(?:not\s+moving|stalled|blocked|stuck|waiting)\b/i',$q);
    $doing=(bool)(preg_match('/\bwhat(?:\'s|\s+is)\s+(?:the\s+)?agent\s+doing\b.*\bgoal\s*#?\s*\d+/i',$q)||preg_match('/\bgoal\s*#?\s*\d+\b.*\bwhat(?:\'s|\s+is)\s+(?:the\s+)?agent\s+doing\b/i',$q));
    $next=(bool)(preg_match('/\bwhat\s+(?:should|needs?\s+to)\s+happen\s+next\b.*\bgoal\s*#?\s*\d+/i',$q)||preg_match('/\bwhat(?:\'s|\s+is)\s+next\b.*\bgoal\s*#?\s*\d+/i',$q)||preg_match('/\bgoal\s*#?\s*\d+\b.*\b(?:what\s+next|next\s+step)\b/i',$q));
    $status=(bool)preg_match('/\bgoal\s*#?\s*\d+\b.*\b(?:execution|orchestration|working|work status)\b/i',$q);
    if(!$advance&&!$why&&!$doing&&!$next&&!$status)return $empty;
    $pdo=db();if(!$pdo)return $empty;
    try{
        $state=$advance?agent_goal_execution_advance_v1712($pdo,$user,$goalId,$conversationId):agent_goal_execution_state_v1712($pdo,$user,$goalId);
        $result=$empty;$result['handled']=true;$result['answer']=agent_goal_execution_answer_v1712($state,$advance?'advance':($why?'why':($doing?'doing':($next?'next':'status'))));
        if(function_exists('agent_tool_log'))agent_tool_log($user,$advance?'goal.execution.advance':'goal.execution.inspect',$query,'success',['goal_id'=>$goalId,'execution_state'=>(string)($state['execution_state']??''),'objective_created'=>!empty($state['objective_created']),'build'=>VP3_AGENT_GOAL_EXECUTION_V1712],$conversationId);
        return $result;
    }catch(Throwable $e){
        $result=$empty;$result['handled']=true;$result['answer']='I could not orchestrate goal #'.$goalId.': '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.execution',$query,'error',['goal_id'=>$goalId,'error'=>get_class($e)],$conversationId);return $result;
    }
}
