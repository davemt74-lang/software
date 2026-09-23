<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.80 — Autonomous Portfolio Coordination.
 *
 * This layer coordinates already-authorized autonomous goals across shared
 * worker capacity, deadlines, priorities, semantic overlap, shared objectives,
 * and the canonical workflow dependency graph.
 *
 * It owns no scheduler, queue, worker, lease, receipt, approval, goal, project,
 * objective, or dependency store. It may only:
 *   - derive a bounded coordination projection,
 *   - delegate admitted long-horizon mutations to v24.70,
 *   - answer a claim-admission question from the existing v19.00 claimant.
 */
const VP3_COGNITIVE_PORTFOLIO_V2480='vp3-cognitive-portfolio-v2480-20260922';
const VP3_COGNITIVE_PORTFOLIO_CONTRACT_V2480='cognitive-portfolio-v1';
const VP3_COGNITIVE_PORTFOLIO_MAX_GOALS_V2480=8;
const VP3_COGNITIVE_PORTFOLIO_MAX_RUNS_V2480=160;
const VP3_COGNITIVE_PORTFOLIO_MAX_MATERIALIZE_V2480=2;
const VP3_COGNITIVE_PORTFOLIO_MAX_REPAIR_V2480=2;
const VP3_COGNITIVE_PORTFOLIO_OVERLAP_THRESHOLD_V2480=0.58;

function vp3_cognitive_portfolio_ready_v2480(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('vp3_cognitive_autonomy_schema_ready_v2470')
        && vp3_cognitive_autonomy_schema_ready_v2470($pdo)
        && function_exists('agent_objective_portfolio_ready_v179')
        && agent_objective_portfolio_ready_v179($pdo)
        && function_exists('agent_worker_runtime_summary_v1910')
        && agent_job_engine_schema_ready_v1900($pdo)
        && table_exists('agent_goal_objectives')
        && table_exists('agent_workflow_run_dependencies'));
}

function vp3_cognitive_portfolio_capacity_v2480(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    $out=[
        'known'=>false,'total_max'=>0,'total_active'=>0,'total_free'=>0,
        'executors'=>[
            'cloud'=>['ready'=>false,'max'=>0,'active'=>0,'free'=>0,'state'=>'unavailable','reason'=>'unavailable'],
            'homeserver'=>['ready'=>false,'max'=>0,'active'=>0,'free'=>0,'state'=>'unavailable','reason'=>'unavailable'],
        ],
        // This is an admission/capacity projection only. Live capability
        // readiness remains authoritative in agent_worker_runtime_v1910.
        'execution_authority'=>false,
    ];
    if($uid<1||!function_exists('agent_worker_runtime_active_count_v1910'))return $out;
    $out['known']=true;

    $cloudMax=defined('VP3_AGENT_WORKER_CLOUD_MAX_CONCURRENCY_V1910')
        ?max(0,(int)VP3_AGENT_WORKER_CLOUD_MAX_CONCURRENCY_V1910):1;
    $cloudActive=agent_worker_runtime_active_count_v1910($pdo,$uid,'cloud');
    $out['executors']['cloud']=[
        'ready'=>true,'max'=>$cloudMax,'active'=>$cloudActive,
        'free'=>max(0,$cloudMax-$cloudActive),'state'=>'ready','reason'=>'cloud_runtime',
    ];

    $homeReady=false;$homeState='unpaired';$homeReason='homeserver_unpaired';
    if(function_exists('homeserver_vp3_connection')){
        try{
            $connection=homeserver_vp3_connection($uid);
            if(is_array($connection)){
                $deviceId=trim((string)($connection['device_id']??''));
                $paired=$deviceId!==''&&!empty($connection['homeserver_token_enc']);
                if($paired){
                    $seen=strtotime((string)($connection['last_seen_at']??''))?:0;
                    $staleSeconds=defined('VP3_AGENT_WORKER_STALE_SECONDS_V1910')
                        ?max(60,(int)VP3_AGENT_WORKER_STALE_SECONDS_V1910):300;
                    $homeReady=$seen>0&&$seen>=time()-$staleSeconds;
                    $homeState=$homeReady?'paired_recent':'stale';
                    $homeReason=$homeReady?'recent_pair_state':'homeserver_stale';
                }
            }
        }catch(Throwable $e){}
    }
    $homeMax=$homeReady&&defined('VP3_AGENT_WORKER_HOMESERVER_MAX_CONCURRENCY_V1910')
        ?max(0,(int)VP3_AGENT_WORKER_HOMESERVER_MAX_CONCURRENCY_V1910):0;
    $homeActive=$homeReady?agent_worker_runtime_active_count_v1910($pdo,$uid,'homeserver'):0;
    $out['executors']['homeserver']=[
        'ready'=>$homeReady,'max'=>$homeMax,'active'=>$homeActive,
        'free'=>max(0,$homeMax-$homeActive),'state'=>$homeState,'reason'=>$homeReason,
    ];

    foreach(['cloud','homeserver'] as $executor){
        $out['total_max']+=(int)$out['executors'][$executor]['max'];
        $out['total_active']+=(int)$out['executors'][$executor]['active'];
        $out['total_free']+=(int)$out['executors'][$executor]['free'];
    }
    return $out;
}

function vp3_cognitive_portfolio_deadline_score_v2480(array $goal,int $progress): float
{
    $deadline=agent_goal_deadline_v1710($goal,$progress);
    $days=$deadline['days_remaining']??null;
    if($days===null)return 0.22;
    $days=(int)$days;
    if($days<0)return 1.0;
    if($days<=3)return 0.98;
    if($days<=7)return 0.92;
    if($days<=30)return 0.72;
    if($days<=90)return 0.48;
    return 0.26;
}

function vp3_cognitive_portfolio_state_score_v2480(string $state): float
{
    return match($state){
        'repair_needed'=>1.0,
        'waiting_approval'=>0.94,
        'ready'=>0.90,
        'executing'=>0.86,
        'needs_objective'=>0.82,
        'verification'=>0.74,
        'blocked'=>0.62,
        'scheduled'=>0.54,
        'review_needed'=>0.50,
        'plan_missing'=>0.46,
        'objective_achieved'=>0.25,
        'achieved'=>0.0,
        'goal_paused'=>0.0,
        'archived'=>0.0,
        default=>0.42,
    };
}

function vp3_cognitive_portfolio_preview_executor_v2480(array $state): string
{
    $focus=is_array($state['focus']??null)?$state['focus']:[];
    $target=strtolower(trim((string)($focus['execution_target']??'')));
    if(in_array($target,['cloud','homeserver'],true))return $target;

    if((string)($state['execution_state']??'')!=='needs_objective')return 'cloud';
    $milestone=is_array($state['milestone']??null)?$state['milestone']:[];
    $title=agent_goal_text_v1710($milestone['title']??'',900);
    if($title===''||!function_exists('agent_objective_heuristic_stages_v175'))return 'cloud';
    try{
        $stages=agent_objective_heuristic_stages_v175($title);
        $step=is_array($stages[0][0]??null)?$stages[0][0]:[];
        $instruction=(string)($step['instruction']??$step['title']??$title);
        $hint=(string)($step['target']??'');
        if(function_exists('agent_objective_target_v175')){
            $target=agent_objective_target_v175($instruction,$hint);
            if(in_array($target,['cloud','homeserver'],true))return $target;
        }
    }catch(Throwable $e){}
    return 'cloud';
}

function vp3_cognitive_portfolio_goal_graph_v2480(PDO $pdo,int $uid,array $goalIds): array
{
    $goalIds=array_values(array_unique(array_filter(array_map('intval',$goalIds),static fn(int $id): bool=>$id>0)));
    $base=[
        'goal_objectives'=>[],'objective_goals'=>[],'run_goals'=>[],
        'blocked_by_goals'=>[],'dependency_leverage'=>[],'shared_goal_ids'=>[],
        'active_executors'=>[],'claimable_executors'=>[],'run_count'=>0,
    ];
    if($uid<1||!$goalIds)return $base;

    $ph=implode(',',array_fill(0,count($goalIds),'?'));
    $stmt=$pdo->prepare("SELECT l.goal_id,l.objective_run_id,r.source_hash,r.status
      FROM agent_goal_objectives l
      INNER JOIN agent_workflow_runs r
        ON r.id=l.objective_run_id AND r.owner_user_id=l.owner_user_id
      WHERE l.owner_user_id=? AND l.goal_id IN ({$ph})
        AND r.source_kind='objective_plan'
      ORDER BY l.id");
    $stmt->execute(array_merge([$uid],$goalIds));
    $hashGoals=[];$runGoals=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $goalId=(int)$row['goal_id'];$objectiveId=(int)$row['objective_run_id'];
        $base['goal_objectives'][$goalId][]=$objectiveId;
        $base['objective_goals'][$objectiveId][]=$goalId;
        $runGoals[$objectiveId][$goalId]=true;
        $hash=trim((string)($row['source_hash']??''));
        if($hash!=='')$hashGoals[$hash][$goalId]=true;
    }
    foreach($base['objective_goals'] as $objectiveId=>$goals){
        $goals=array_values(array_unique(array_map('intval',$goals)));
        $base['objective_goals'][$objectiveId]=$goals;
        if(count($goals)>1){
            foreach($goals as $goalId){
                foreach($goals as $other)if($other!==$goalId)$base['shared_goal_ids'][$goalId][$other]=true;
            }
        }
    }

    $hashes=array_slice(array_keys($hashGoals),0,VP3_COGNITIVE_PORTFOLIO_MAX_RUNS_V2480);
    if($hashes){
        $hph=implode(',',array_fill(0,count($hashes),'?'));
        $children=$pdo->prepare("SELECT r.id,r.source_hash,r.status,r.execution_target,
          r.current_action_id,r.next_attempt_at,r.lease_expires_at,r.pause_requested_at,
          (SELECT COUNT(*) FROM agent_workflow_run_dependencies d
             INNER JOIN agent_workflow_runs p
               ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id
           WHERE d.owner_user_id=r.owner_user_id AND d.run_id=r.id
             AND p.status<>'completed') AS blocker_count
          FROM agent_workflow_runs r
          WHERE r.owner_user_id=? AND r.source_kind='objective_step'
            AND r.source_hash IN ({$hph})
            AND r.status NOT IN ('completed','cancelled')
            AND NOT EXISTS (
              SELECT 1 FROM agent_workflow_events e
              WHERE e.owner_user_id=r.owner_user_id AND e.run_id=r.id
                AND e.event_type='objective_replaced'
            )
          ORDER BY r.id
          LIMIT ".VP3_COGNITIVE_PORTFOLIO_MAX_RUNS_V2480);
        $children->execute(array_merge([$uid],$hashes));
        $now=time();
        foreach($children->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $runId=(int)$row['id'];$hash=(string)$row['source_hash'];
            foreach(array_keys($hashGoals[$hash]??[]) as $goalId)$runGoals[$runId][(int)$goalId]=true;
            $target=in_array((string)($row['execution_target']??''),['cloud','homeserver'],true)?(string)$row['execution_target']:'cloud';
            if((string)$row['status']==='executing'){
                foreach(array_keys($runGoals[$runId]??[]) as $goalId)$base['active_executors'][(int)$goalId][$target]=true;
            }
            $next=strtotime((string)($row['next_attempt_at']??''))?:0;
            $lease=strtotime((string)($row['lease_expires_at']??''))?:0;
            $claimable=(string)$row['status']==='approved'
                &&(int)($row['current_action_id']??0)===0
                &&empty($row['pause_requested_at'])
                &&(int)($row['blocker_count']??0)===0
                &&($next===0||$next<=$now)
                &&($lease===0||$lease<=$now);
            if($claimable){
                foreach(array_keys($runGoals[$runId]??[]) as $goalId)$base['claimable_executors'][(int)$goalId][$target]=true;
            }
        }
    }

    $runIds=array_slice(array_map('intval',array_keys($runGoals)),0,VP3_COGNITIVE_PORTFOLIO_MAX_RUNS_V2480);
    if($runIds){
        $rph=implode(',',array_fill(0,count($runIds),'?'));
        $deps=$pdo->prepare("SELECT run_id,depends_on_run_id
          FROM agent_workflow_run_dependencies
          WHERE owner_user_id=? AND run_id IN ({$rph})
          ORDER BY id LIMIT ".(VP3_COGNITIVE_PORTFOLIO_MAX_RUNS_V2480*3));
        $deps->execute(array_merge([$uid],$runIds));
        foreach($deps->fetchAll(PDO::FETCH_ASSOC)?:[] as $edge){
            $dependentGoals=array_keys($runGoals[(int)$edge['run_id']]??[]);
            $prerequisiteGoals=array_keys($runGoals[(int)$edge['depends_on_run_id']]??[]);
            foreach($dependentGoals as $dependentGoal){
                foreach($prerequisiteGoals as $prerequisiteGoal){
                    $dependentGoal=(int)$dependentGoal;$prerequisiteGoal=(int)$prerequisiteGoal;
                    if($dependentGoal<1||$prerequisiteGoal<1||$dependentGoal===$prerequisiteGoal)continue;
                    $base['blocked_by_goals'][$dependentGoal][$prerequisiteGoal]=true;
                    $base['dependency_leverage'][$prerequisiteGoal][$dependentGoal]=true;
                }
            }
        }
    }

    foreach($runGoals as $runId=>$goals)$base['run_goals'][(int)$runId]=array_values(array_map('intval',array_keys($goals)));
    foreach(['blocked_by_goals','dependency_leverage','shared_goal_ids'] as $key){
        foreach($base[$key] as $goalId=>$set)$base[$key][$goalId]=array_values(array_map('intval',array_keys($set)));
    }
    foreach(['active_executors','claimable_executors'] as $key){
        foreach($base[$key] as $goalId=>$set)$base[$key][$goalId]=array_values(array_keys($set));
    }
    $base['run_count']=count($runGoals);
    return $base;
}

function vp3_cognitive_portfolio_goal_score_v2480(
    array $goalRow,array $state,int $dependencyLeverage
): array {
    $progress=max(0,min(100,(int)($state['goal']['progress_percent']??0)));
    $priority=max(1,min(100,(int)($goalRow['priority']??50)))/100;
    $deadline=vp3_cognitive_portfolio_deadline_score_v2480($goalRow,$progress);
    $stateScore=vp3_cognitive_portfolio_state_score_v2480((string)($state['execution_state']??'unknown'));
    $leverage=min(1.0,max(0,$dependencyLeverage)/2);
    $completion=$progress/100;
    $score=($priority*0.30)+($deadline*0.25)+($stateScore*0.18)+($leverage*0.17)+($completion*0.10);
    return [
        'score'=>round(max(0.0,min(1.0,$score)),4),
        'components'=>[
            'user_priority'=>round($priority,3),
            'deadline'=>round($deadline,3),
            'execution_state'=>round($stateScore,3),
            'dependency_leverage'=>round($leverage,3),
            'completion_proximity'=>round($completion,3),
        ],
    ];
}

function vp3_cognitive_portfolio_semantic_text_v2480(array $item): string
{
    return trim(implode(' ',[
        (string)($item['goal']??''),
        (string)($item['next_milestone_title']??''),
    ]));
}

function vp3_cognitive_portfolio_snapshot_v2480(PDO $pdo,array $user): array
{
    $empty=[
        'contract'=>VP3_COGNITIVE_PORTFOLIO_CONTRACT_V2480,
        'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,
        'focus'=>null,'items'=>[],'counts'=>[],
        'capacity'=>vp3_cognitive_portfolio_capacity_v2480($pdo,$user),
        'claim_admitted_goal_ids'=>['cloud'=>[],'homeserver'=>[]],
        'materialize_goal_ids'=>[],'repair_goal_ids'=>[],
        'projection_only'=>true,
    ];
    if(!vp3_cognitive_portfolio_ready_v2480($pdo))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;

    $rows=array_slice(vp3_cognitive_autonomy_goal_rows_v2470($pdo,$user,false),0,VP3_COGNITIVE_PORTFOLIO_MAX_GOALS_V2480);
    $goalIds=array_map(static fn(array $row): int=>(int)($row['id']??0),$rows);
    $graph=vp3_cognitive_portfolio_goal_graph_v2480($pdo,$uid,$goalIds);
    $capacity=vp3_cognitive_portfolio_capacity_v2480($pdo,$user);
    $items=[];

    foreach($rows as $row){
        $goalId=(int)($row['id']??0);if($goalId<1)continue;
        try{$state=agent_goal_execution_state_v1712($pdo,$user,$goalId);}
        catch(Throwable $e){$state=['goal'=>[],'milestone'=>null,'objective'=>null,'focus'=>null,'execution_state'=>'unavailable','reason'=>'Goal execution state is unavailable.'];}
        $rank=vp3_cognitive_portfolio_goal_score_v2480(
            $row,$state,count((array)($graph['dependency_leverage'][$goalId]??[]))
        );
        $milestone=is_array($state['milestone']??null)?$state['milestone']:[];
        $mode=vp3_cognitive_autonomy_mode_v2470($row['execution_mode']??'manual');
        $execution=(string)($state['execution_state']??'unknown');
        $target=vp3_cognitive_portfolio_preview_executor_v2480($state);
        $items[]=[
            'goal_id'=>$goalId,
            'title'=>(string)($row['title']??$row['goal']??('Goal #'.$goalId)),
            'goal'=>(string)($row['goal']??''),
            'execution_mode'=>$mode,
            'priority'=>max(1,min(100,(int)($row['priority']??50))),
            'target_date'=>(string)($row['target_date']??''),
            'progress_percent'=>max(0,min(100,(int)($state['goal']['progress_percent']??0))),
            'execution_state'=>$execution,
            'reason'=>(string)($state['reason']??''),
            'next_milestone_id'=>(int)($milestone['id']??0),
            'next_milestone_title'=>(string)($milestone['title']??''),
            'executor'=>$target,
            'score'=>$rank['score'],
            'score_percent'=>(int)round($rank['score']*100),
            'score_components'=>$rank['components'],
            'dependency_leverage_goal_ids'=>(array)($graph['dependency_leverage'][$goalId]??[]),
            'blocked_by_goal_ids'=>(array)($graph['blocked_by_goals'][$goalId]??[]),
            'shared_objective_goal_ids'=>(array)($graph['shared_goal_ids'][$goalId]??[]),
            'active_executors'=>(array)($graph['active_executors'][$goalId]??[]),
            'claimable_executors'=>(array)($graph['claimable_executors'][$goalId]??[]),
            'hold_reason'=>'',
            'conflicts_with_goal_id'=>0,
            'coordination_action'=>'observe',
            'requires_user'=>in_array($execution,['waiting_approval','plan_missing','review_needed'],true),
        ];
    }

    usort($items,static function(array $a,array $b): int {
        $modeRank=['autonomous'=>0,'supervised'=>1,'manual'=>2];
        $x=($modeRank[$a['execution_mode']??'manual']??9)<=>($modeRank[$b['execution_mode']??'manual']??9);if($x!==0)return $x;
        $x=((float)($b['score']??0))<=>((float)($a['score']??0));if($x!==0)return $x;
        $x=((int)($b['priority']??0))<=>((int)($a['priority']??0));if($x!==0)return $x;
        return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
    });

    // v24.90 may adapt future sequencing pressure, but v24.80 remains the
    // admission authority. Forecast failure leaves the proven v24.80 order.
    if(function_exists('vp3_cognitive_forecast_adaptive_resequence_v2490')){
        try{$items=vp3_cognitive_forecast_adaptive_resequence_v2490($items,$capacity);}
        catch(Throwable $e){}
    }

    // Prevent creation of substantially overlapping new project work. Existing
    // canonical objectives are never cancelled or blocked merely by similarity.
    for($i=0;$i<count($items);$i++){
        if((string)$items[$i]['execution_mode']!=='autonomous'||(string)$items[$i]['execution_state']!=='needs_objective')continue;
        for($j=$i+1;$j<count($items);$j++){
            if((string)$items[$j]['execution_mode']!=='autonomous'||(string)$items[$j]['execution_state']!=='needs_objective')continue;
            $a=vp3_cognitive_portfolio_semantic_text_v2480($items[$i]);
            $b=vp3_cognitive_portfolio_semantic_text_v2480($items[$j]);
            if($a===''||$b==='')continue;
            $similarity=function_exists('agent_objective_portfolio_similarity_v179')
                ?agent_objective_portfolio_similarity_v179($a,$b):0.0;
            if($similarity>=VP3_COGNITIVE_PORTFOLIO_OVERLAP_THRESHOLD_V2480){
                $items[$j]['hold_reason']='semantic_overlap';
                $items[$j]['conflicts_with_goal_id']=(int)$items[$i]['goal_id'];
                $items[$j]['requires_user']=true;
                $items[$j]['coordination_action']='review_overlap';
            }
        }
    }

    // v25.30 may apply a bounded recovery overlay after the normal
    // forecast/optimization/overlap pass. It can only reorder existing
    // autonomous portfolio items and annotate recovery intent. v24.80 remains
    // admission authority, and failure preserves the proven v24.80 order.
    $replanning=[
        'build'=>'','health'=>'unavailable','replan_needed'=>false,
        'replan_applied'=>false,'focus'=>null,'issues'=>[],'changes'=>[],
        'sequence_goal_ids'=>[],'counts'=>[],'projection_only'=>true,
    ];
    if(function_exists('vp3_cognitive_replanning_overlay_v2530')){
        try{
            $overlay=vp3_cognitive_replanning_overlay_v2530($items,$capacity);
            if(is_array($overlay['items']??null))$items=$overlay['items'];
            if(is_array($overlay['replan']??null))$replanning=$overlay['replan'];
        }catch(Throwable $e){}
    }

    // v25.20 derives bounded admission reservations from the recovered order.
    // These are not worker leases: they can only protect v24.80 autonomous
    // admission capacity. Failure preserves the v24.80 behavior.
    $resourceBudget=[
        'build'=>'','focus'=>null,'reservations'=>[],'executors'=>[],'counts'=>[],
        'projection_only'=>true,
    ];
    if(function_exists('vp3_cognitive_resource_budget_plan_v2520')){
        try{$resourceBudget=vp3_cognitive_resource_budget_plan_v2520($items,$capacity);}
        catch(Throwable $e){}
    }

    $claimAdmitted=['cloud'=>[],'homeserver'=>[]];
    $remainingFree=[];$reservationAdmission=[];
    foreach(['cloud','homeserver'] as $executor){
        $free=max(0,(int)($capacity['executors'][$executor]['free']??0));
        $candidates=array_values(array_filter($items,static fn(array $item): bool=>
            (string)($item['execution_mode']??'')==='autonomous'
            &&in_array($executor,(array)($item['claimable_executors']??[]),true)
        ));
        usort($candidates,static function(array $a,array $b) use($executor): int {
            $aActive=in_array($executor,(array)($a['active_executors']??[]),true)?1:0;
            $bActive=in_array($executor,(array)($b['active_executors']??[]),true)?1:0;
            if($aActive!==$bActive)return $aActive<=>$bActive;
            $aReplan=max(1,(int)($a['replan_rank']??PHP_INT_MAX));
            $bReplan=max(1,(int)($b['replan_rank']??PHP_INT_MAX));
            if($aReplan!==$bReplan)return $aReplan<=>$bReplan;
            return ((float)($b['score']??0))<=>((float)($a['score']??0));
        });

        if(function_exists('vp3_cognitive_resource_claim_budget_v2520')&&!empty($resourceBudget['build'])){
            try{
                $budget=vp3_cognitive_resource_claim_budget_v2520($executor,$free,$candidates,$resourceBudget);
                $claimAdmitted[$executor]=array_values(array_map('intval',(array)($budget['admitted_goal_ids']??[])));
                $remainingFree[$executor]=max(0,(int)($budget['remaining_unreserved_free']??0));
                $reservationAdmission[$executor]=$budget;
                continue;
            }catch(Throwable $e){}
        }

        foreach(array_slice($candidates,0,$free) as $candidate)$claimAdmitted[$executor][]=(int)$candidate['goal_id'];
        $remainingFree[$executor]=max(0,$free-count($claimAdmitted[$executor]));
        $reservationAdmission[$executor]=[
            'executor'=>$executor,'free_before'=>$free,'active_reserved_goal_ids'=>[],
            'reserved_admitted_goal_ids'=>[],'unreserved_admitted_goal_ids'=>$claimAdmitted[$executor],
            'admitted_goal_ids'=>$claimAdmitted[$executor],'held_reserved_slots'=>0,
            'remaining_unreserved_free'=>$remainingFree[$executor],'admission_only'=>true,
        ];
    }

    $materialize=[];$repairs=[];$actionByGoal=[];
    foreach($items as &$item){
        if((string)$item['execution_mode']!=='autonomous')continue;
        $state=(string)$item['execution_state'];$goalId=(int)$item['goal_id'];
        if($state==='repair_needed'){
            if(count($repairs)<VP3_COGNITIVE_PORTFOLIO_MAX_REPAIR_V2480){
                $repairs[]=$goalId;$actionByGoal[$goalId]='bounded_remediation';$item['coordination_action']='repair_existing_work';
            }
            continue;
        }
        if($state==='needs_objective'){
            if((string)$item['hold_reason']!=='')continue;
            $executor=in_array((string)$item['executor'],['cloud','homeserver'],true)?(string)$item['executor']:'cloud';
            $activeReserved=in_array(
                $goalId,
                array_map('intval',(array)($resourceBudget['executors'][$executor]['active_reserved_goal_ids']??[])),
                true
            );
            if(($activeReserved||($remainingFree[$executor]??0)>0)&&count($materialize)<VP3_COGNITIVE_PORTFOLIO_MAX_MATERIALIZE_V2480){
                $materialize[]=$goalId;$actionByGoal[$goalId]='materialize_objective';
                if(!$activeReserved)$remainingFree[$executor]--;
                $item['coordination_action']=$activeReserved?'admit_reserved_milestone':'admit_next_milestone';
            }else{
                $item['hold_reason']='worker_capacity';
                $item['coordination_action']='hold_for_capacity';
            }
        }elseif($state==='waiting_approval')$item['coordination_action']='request_approval';
        elseif($state==='blocked')$item['coordination_action']='respect_dependencies';
        elseif($state==='ready')$item['coordination_action']=in_array($goalId,$claimAdmitted[$item['executor']]??[],true)?'admit_claim':'hold_claim_for_portfolio';
        elseif($state==='executing')$item['coordination_action']='observe_execution';
        elseif($state==='verification')$item['coordination_action']='complete_verification';
    }
    unset($item);

    $held=array_values(array_filter($items,static fn(array $item): bool=>(string)($item['hold_reason']??'')!==''));
    $requiresUser=array_values(array_filter($items,static fn(array $item): bool=>!empty($item['requires_user'])));
    $shared=array_values(array_filter($items,static fn(array $item): bool=>!empty($item['shared_objective_goal_ids'])));
    $chains=array_values(array_filter($items,static fn(array $item): bool=>!empty($item['dependency_leverage_goal_ids'])||!empty($item['blocked_by_goal_ids'])));

    return [
        'contract'=>VP3_COGNITIVE_PORTFOLIO_CONTRACT_V2480,
        'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,
        'focus'=>$items[0]??null,
        'items'=>$items,
        'counts'=>[
            'active'=>count($items),
            'autonomous'=>count(array_filter($items,static fn(array $x): bool=>(string)$x['execution_mode']==='autonomous')),
            'supervised'=>count(array_filter($items,static fn(array $x): bool=>(string)$x['execution_mode']==='supervised')),
            'held'=>count($held),
            'requires_user'=>count($requiresUser),
            'shared_work'=>count($shared),
            'dependency_chains'=>count($chains),
            'claim_admitted'=>count($claimAdmitted['cloud'])+count($claimAdmitted['homeserver']),
            'materialize_admitted'=>count($materialize),
            'repair_admitted'=>count($repairs),
            'capacity_reservations'=>(int)($resourceBudget['counts']['selected']??0),
            'active_reserved_slots'=>(int)($resourceBudget['counts']['active']??0),
            'conditional_reservations'=>(int)($resourceBudget['counts']['conditional']??0),
            'held_reserved_slots'=>array_sum(array_map(
                static fn(array $budget): int=>max(0,(int)($budget['held_reserved_slots']??0)),
                array_values($reservationAdmission)
            )),
            'replan_issues'=>(int)($replanning['counts']['issues']??0),
            'replan_changes'=>(int)($replanning['counts']['changes']??0),
            'deadline_threats'=>(int)($replanning['counts']['deadline_threats']??0),
            'capacity_loss'=>(int)($replanning['counts']['capacity_loss']??0),
        ],
        'capacity'=>$capacity,
        'replanning'=>$replanning,
        'resource_budget'=>$resourceBudget,
        'reservation_admission'=>$reservationAdmission,
        'claim_admitted_goal_ids'=>$claimAdmitted,
        'materialize_goal_ids'=>$materialize,
        'repair_goal_ids'=>$repairs,
        'allowed_actions'=>$actionByGoal,
        'graph'=>[
            'run_count'=>(int)($graph['run_count']??0),
            'shared_objectives'=>count(array_filter((array)$graph['objective_goals'],static fn(array $goals): bool=>count($goals)>1)),
        ],
        'authority'=>[
            'goal_store'=>'agent_goals',
            'goal_links'=>'agent_goal_objectives',
            'roadmap'=>'agent_goal_milestones',
            'objective_store'=>'agent_workflow_runs',
            'dependencies'=>'agent_workflow_run_dependencies',
            'capacity'=>'agent_worker_runtime_v1910',
            'replanning'=>'cognitive_replanning_v2530_recovery_overlay',
            'resource_budget'=>'cognitive_resource_budget_v2520_admission_policy',
            'claimant'=>'agent_job_engine_v1900',
            'autonomous_mutations'=>'cognitive_autonomy_v2470',
            'projection_only'=>true,
            'scheduler_authority'=>false,
            'worker_claim_authority'=>false,
            'approval_authority'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_portfolio_policy_v2480(array $snapshot): array
{
    $materialize=array_values(array_unique(array_map('intval',(array)($snapshot['materialize_goal_ids']??[]))));
    $repairs=array_values(array_unique(array_map('intval',(array)($snapshot['repair_goal_ids']??[]))));
    return [
        'allowed_goal_ids'=>array_values(array_unique(array_merge($materialize,$repairs))),
        'allowed_actions'=>(array)($snapshot['allowed_actions']??[]),
        'objective_budget'=>min(VP3_COGNITIVE_PORTFOLIO_MAX_MATERIALIZE_V2480,count($materialize)),
        'remediation_budget'=>min(VP3_COGNITIVE_PORTFOLIO_MAX_REPAIR_V2480,count($repairs)),
        'source'=>'cognitive_portfolio_v2480',
    ];
}

function vp3_cognitive_portfolio_run_owner_v2480(PDO $pdo,array $user): array
{
    $snapshot=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
    $policy=vp3_cognitive_portfolio_policy_v2480($snapshot);
    $autonomy=[
        'goals_checked'=>0,'objectives_materialized'=>0,'remediations_performed'=>0,
        'replacement_runs'=>0,'requires_user'=>0,'errors'=>0,'held_by_portfolio'=>0,
        'portfolio_policy_applied'=>true,'build'=>VP3_COGNITIVE_AUTONOMY_V2470,
    ];
    if(function_exists('vp3_cognitive_autonomy_run_owner_v2470')){
        try{$autonomy=vp3_cognitive_autonomy_run_owner_v2470($pdo,$user,$policy);}
        catch(Throwable $e){$autonomy['errors']=max(1,(int)($autonomy['errors']??0)+1);}
    }
    return [
        'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,
        'portfolio'=>$snapshot,
        'policy'=>$policy,
        'autonomy_run'=>$autonomy,
    ];
}

function vp3_cognitive_portfolio_claim_scope_v2480(PDO $pdo,int $uid,int $runId): array
{
    if($uid<1||$runId<1)return ['source_kind'=>'','goal_ids'=>[],'modes'=>[]];
    $stmt=$pdo->prepare("SELECT id,source_kind,source_hash FROM agent_workflow_runs WHERE id=? AND owner_user_id=? LIMIT 1");
    $stmt->execute([$runId,$uid]);$run=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$run)return ['source_kind'=>'','goal_ids'=>[],'modes'=>[]];

    $sourceKind=(string)($run['source_kind']??'');
    if($sourceKind!=='objective_step')return ['source_kind'=>$sourceKind,'goal_ids'=>[],'modes'=>[]];

    $hash=trim((string)($run['source_hash']??''));if($hash==='')return ['source_kind'=>$sourceKind,'goal_ids'=>[],'modes'=>[]];
    $parent=$pdo->prepare("SELECT id FROM agent_workflow_runs
      WHERE owner_user_id=? AND source_kind='objective_plan' AND source_hash=?
      ORDER BY id DESC LIMIT 1");
    $parent->execute([$uid,$hash]);$objectiveId=(int)$parent->fetchColumn();
    if($objectiveId<1)return ['source_kind'=>$sourceKind,'goal_ids'=>[],'modes'=>[]];

    $links=$pdo->prepare("SELECT g.id,g.execution_mode
      FROM agent_goal_objectives l
      INNER JOIN agent_goals g ON g.id=l.goal_id AND g.owner_user_id=l.owner_user_id
      WHERE l.owner_user_id=? AND l.objective_run_id=? AND g.status='active'
      ORDER BY g.id");
    $links->execute([$uid,$objectiveId]);
    $goalIds=[];$modes=[];
    foreach($links->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $goalId=(int)$row['id'];if($goalId<1)continue;
        $goalIds[]=$goalId;$modes[$goalId]=vp3_cognitive_autonomy_mode_v2470($row['execution_mode']??'manual');
    }
    return ['source_kind'=>$sourceKind,'objective_run_id'=>$objectiveId,'goal_ids'=>$goalIds,'modes'=>$modes];
}

function vp3_cognitive_portfolio_claim_admission_v2480(
    PDO $pdo,array $user,int $runId,string $executor
): array {
    $uid=(int)($user['id']??0);$executor=strtolower(trim($executor));
    if($uid<1||$runId<1||!in_array($executor,['cloud','homeserver'],true)){
        return ['allowed'=>true,'reason'=>'invalid_scope_passthrough'];
    }
    $scope=vp3_cognitive_portfolio_claim_scope_v2480($pdo,$uid,$runId);
    if((string)($scope['source_kind']??'')!=='objective_step'||empty($scope['goal_ids'])){
        return ['allowed'=>true,'reason'=>'not_autonomous_portfolio_work'];
    }
    foreach((array)$scope['modes'] as $mode){
        if((string)$mode!=='autonomous')return ['allowed'=>true,'reason'=>'shared_non_autonomous_authority'];
    }

    static $cache=[];
    $cacheKey=$uid.'|'.$executor;
    if(!isset($cache[$cacheKey])){
        try{$cache[$cacheKey]=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
        catch(Throwable $e){return ['allowed'=>true,'reason'=>'portfolio_projection_unavailable'];}
    }
    $admitted=array_fill_keys(array_map('intval',(array)($cache[$cacheKey]['claim_admitted_goal_ids'][$executor]??[])),true);
    foreach((array)$scope['goal_ids'] as $goalId){
        if(isset($admitted[(int)$goalId])){
            return ['allowed'=>true,'reason'=>'portfolio_admitted','goal_id'=>(int)$goalId,'executor'=>$executor];
        }
    }
    return [
        'allowed'=>false,'reason'=>'portfolio_not_admitted',
        'goal_ids'=>array_values(array_map('intval',(array)$scope['goal_ids'])),
        'executor'=>$executor,'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,
    ];
}

function vp3_cognitive_portfolio_context_item_v2480(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
    if(empty($snapshot['items']))return null;
    $items=[];
    foreach(array_slice((array)$snapshot['items'],0,6) as $item){
        $items[]=[
            'goal_id'=>(int)$item['goal_id'],
            'title'=>(string)$item['title'],
            'mode'=>(string)$item['execution_mode'],
            'state'=>(string)$item['execution_state'],
            'score_percent'=>(int)$item['score_percent'],
            'executor'=>(string)$item['executor'],
            'coordination_action'=>(string)$item['coordination_action'],
            'hold_reason'=>(string)$item['hold_reason'],
            'blocked_by_goal_ids'=>(array)$item['blocked_by_goal_ids'],
            'shared_objective_goal_ids'=>(array)$item['shared_objective_goal_ids'],
        ];
    }
    $json=json_encode([
        'focus'=>$items[0]??null,
        'goals'=>$items,
        'counts'=>$snapshot['counts'],
        'capacity'=>$snapshot['capacity'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'portfolio','cognitive-portfolio:v2480','Autonomous portfolio coordination',
        $json,96.0,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_portfolio_activity_projection_v2480(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,'focus'=>null,'items'=>[],
        'counts'=>[],'capacity'=>[],'projection_only'=>true
    ];
    $snapshot=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_PORTFOLIO_V2480,
        'focus'=>$snapshot['focus'],
        'items'=>array_slice((array)$snapshot['items'],0,8),
        'counts'=>$snapshot['counts'],
        'capacity'=>$snapshot['capacity'],
        'claim_admitted_goal_ids'=>$snapshot['claim_admitted_goal_ids'],
        'projection_only'=>true,
    ];
}
