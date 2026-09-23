<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.70 — Governed Autonomous Remediation &
 * Long-Horizon Project Execution.
 *
 * v24.70 adds an explicit execution mode to canonical goals and uses existing
 * goal/objective/workflow authorities to continue already-defined roadmaps.
 * It creates no second project store, scheduler, worker, retry engine,
 * approval system, receipt ledger, or tool executor.
 */
const VP3_COGNITIVE_AUTONOMY_V2470='vp3-cognitive-autonomy-v2470-20260922';
const VP3_COGNITIVE_AUTONOMY_CONTRACT_V2470='cognitive-autonomy-v1';
const VP3_COGNITIVE_AUTONOMY_MAX_GOALS_V2470=8;
const VP3_COGNITIVE_AUTONOMY_MAX_OBJECTIVES_PER_PASS_V2470=2;
const VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATIONS_PER_PASS_V2470=2;
const VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATION_CYCLES_V2470=2;

function vp3_cognitive_autonomy_schema_ready_v2470(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && function_exists('agent_goal_planning_schema_ready_v1711')
        && agent_goal_planning_schema_ready_v1711($pdo)
        && column_exists('agent_goals','execution_mode')
        && column_exists('agent_goals','autonomy_updated_at'));
}

function vp3_cognitive_autonomy_ensure_schema_v2470(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_goal_planning_ensure_schema_v1711($pdo);
    if(!column_exists('agent_goals','execution_mode'))$pdo->exec("ALTER TABLE agent_goals ADD COLUMN execution_mode VARCHAR(24) NOT NULL DEFAULT 'manual' AFTER priority");
    if(!column_exists('agent_goals','autonomy_updated_at'))$pdo->exec("ALTER TABLE agent_goals ADD COLUMN autonomy_updated_at DATETIME NULL AFTER execution_mode");
}

function vp3_cognitive_autonomy_mode_v2470(mixed $value): string
{
    $mode=strtolower(trim((string)$value));
    return in_array($mode,['manual','supervised','autonomous'],true)?$mode:'manual';
}

function vp3_cognitive_autonomy_goal_event_v2470(
    PDO $pdo,int $uid,int $goalId,string $type,string $summary,array $payload=[]
): void {
    if($uid<1||$goalId<1||!table_exists('agent_goal_events'))return;
    $stmt=$pdo->prepare('INSERT INTO agent_goal_events (owner_user_id,goal_id,event_type,actor,summary,event_json) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $uid,$goalId,agent_goal_text_v1710($type,64),'agent',
        agent_goal_text_v1710($summary,500),
        $payload?agent_workflow_json_v1400($payload):null,
    ]);
}

function vp3_cognitive_autonomy_set_goal_mode_v2470(
    PDO $pdo,array $user,int $goalId,string $mode
): array {
    vp3_cognitive_autonomy_ensure_schema_v2470($pdo);
    $uid=agent_goal_strategy_require_v1710($pdo,$user);
    $goal=agent_goal_row_v1710($pdo,$uid,$goalId);
    agent_goal_require_active_v1710($goal);
    $mode=vp3_cognitive_autonomy_mode_v2470($mode);
    $from=vp3_cognitive_autonomy_mode_v2470($goal['execution_mode']??'manual');
    if($from!==$mode){
        $pdo->prepare("UPDATE agent_goals SET execution_mode=?,autonomy_updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([$mode,$goalId,$uid]);
        agent_goal_event_v1710(
            $pdo,$uid,$goalId,'goal_execution_mode_changed',
            'Goal execution mode changed from '.$from.' to '.$mode.'.',
            ['from'=>$from,'to'=>$mode,'build'=>VP3_COGNITIVE_AUTONOMY_V2470]
        );
    }
    return agent_goal_state_v1710($pdo,$user,$goalId);
}

function vp3_cognitive_autonomy_parse_mode_v2470(string $query,int $goalId): ?string
{
    if($goalId<1)return null;
    if(preg_match('/\b(?:set|make|switch)\s+goal\s*#?\s*'.preg_quote((string)$goalId,'/').'\s+(?:to\s+)?(manual|supervised|autonomous)\b/i',$query,$m))return strtolower((string)$m[1]);
    if(preg_match('/\bgoal\s*#?\s*'.preg_quote((string)$goalId,'/').'\s+(?:execution\s+mode\s+)?(?:to\s+)?(manual|supervised|autonomous)\b/i',$query,$m))return strtolower((string)$m[1]);
    return null;
}

function vp3_cognitive_autonomy_chat_goal_mode_v2470(
    string $query,array $user,int $conversationId,int $goalId
): ?array {
    $mode=vp3_cognitive_autonomy_parse_mode_v2470($query,$goalId);
    if($mode===null)return null;
    $pdo=db();if(!$pdo)return null;
    $empty=agent_objective_empty_tool_v175();
    try{
        $state=vp3_cognitive_autonomy_set_goal_mode_v2470($pdo,$user,$goalId,$mode);
        $goal=(array)($state['goal']??[]);
        $answer='Goal #'.$goalId.' execution mode is now '.vp3_cognitive_autonomy_mode_v2470($goal['execution_mode']??$mode).'. ';
        $answer.=match($mode){
            'autonomous'=>'VP3 may continue already-defined roadmap milestones, create the next canonical objective when needed, and perform bounded low-risk remediation through existing workflow/approval systems. It will not invent a roadmap, approve high-risk work, claim a worker lease, or bypass permissions.',
            'supervised'=>'VP3 will monitor and recommend next steps, but it will not materialize new objective work automatically.',
            default=>'VP3 will observe the goal, but long-horizon objective creation and remediation require explicit user commands.',
        };
        $out=$empty;$out['handled']=true;$out['answer']=$answer;
        if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.autonomy.mode',$query,'success',['goal_id'=>$goalId,'execution_mode'=>$mode,'build'=>VP3_COGNITIVE_AUTONOMY_V2470],$conversationId);
        return $out;
    }catch(Throwable $e){
        $out=$empty;$out['handled']=true;$out['answer']='I could not change goal #'.$goalId.' execution mode: '.$e->getMessage();
        if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.autonomy.mode',$query,'error',['goal_id'=>$goalId,'error'=>get_class($e)],$conversationId);
        return $out;
    }
}

function vp3_cognitive_autonomy_goal_rows_v2470(PDO $pdo,array $user,bool $includeManual=false): array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_cognitive_autonomy_schema_ready_v2470($pdo))return [];
    $modeSql=$includeManual?'':" AND execution_mode IN ('supervised','autonomous')";
    $stmt=$pdo->prepare("SELECT * FROM agent_goals WHERE owner_user_id=? AND status='active'{$modeSql}
      ORDER BY priority DESC,COALESCE(target_date,'9999-12-31'),id LIMIT ".VP3_COGNITIVE_AUTONOMY_MAX_GOALS_V2470);
    $stmt->execute([$uid]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_cognitive_autonomy_safe_failed_ids_v2470(
    PDO $pdo,int $uid,int $objectiveId
): array {
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveId);
    if(!$parent)return ['safe'=>[],'unsafe'=>[],'cancelled'=>[]];
    $hash=(string)($parent['source_hash']??'');if($hash==='')return ['safe'=>[],'unsafe'=>[],'cancelled'=>[]];
    $stmt=$pdo->prepare("SELECT r.* FROM agent_workflow_runs r
      WHERE r.owner_user_id=? AND r.source_kind='objective_step' AND r.source_hash=?
        AND r.status IN ('failed','cancelled')
        AND NOT EXISTS (
          SELECT 1 FROM agent_workflow_events e
          WHERE e.owner_user_id=r.owner_user_id AND e.run_id=r.id
            AND e.event_type='objective_replaced'
        )
      ORDER BY r.id LIMIT 8");
    $stmt->execute([$uid,$hash]);
    $safe=[];$unsafe=[];$cancelled=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $id=(int)$row['id'];$status=(string)$row['status'];
        if($status==='cancelled'){$cancelled[]=$id;continue;}
        $risk=(string)($row['risk_level']??'low');
        $approval=!empty($row['requires_approval'])||(string)($row['approval_status']??'')==='pending';
        if($risk==='low'&&!$approval)$safe[]=$id;else $unsafe[]=$id;
    }
    return ['safe'=>$safe,'unsafe'=>$unsafe,'cancelled'=>$cancelled];
}

function vp3_cognitive_autonomy_remediate_objective_v2470(
    PDO $pdo,array $user,int $objectiveId
): array {
    $uid=(int)($user['id']??0);if($uid<1)return ['performed'=>false,'reason'=>'signed_out'];
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveId);
    if(!$parent)return ['performed'=>false,'reason'=>'objective_missing'];
    $cycles=max(0,(int)($parent['objective_remediation_count']??0));
    if($cycles>=VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATION_CYCLES_V2470){
        return ['performed'=>false,'reason'=>'remediation_cycle_limit','requires_user'=>true];
    }
    $sets=vp3_cognitive_autonomy_safe_failed_ids_v2470($pdo,$uid,$objectiveId);
    if($sets['unsafe']||$sets['cancelled']){
        return [
            'performed'=>false,'reason'=>$sets['cancelled']?'cancelled_work_requires_user':'risk_or_approval_requires_user',
            'requires_user'=>true,'unsafe_run_ids'=>$sets['unsafe'],'cancelled_run_ids'=>$sets['cancelled'],
        ];
    }
    if(!$sets['safe'])return ['performed'=>false,'reason'=>'no_safe_failed_work'];
    $allowed=array_slice($sets['safe'],0,VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATIONS_PER_PASS_V2470);
    $result=agent_objective_verification_rewire_failed_v176($pdo,$user,$objectiveId,$allowed);
    return [
        'performed'=>(int)($result['replaced']??0)>0,
        'reason'=>(int)($result['replaced']??0)>0?'low_risk_failed_work_replaced':'already_reconciled',
        'replaced'=>(int)($result['replaced']??0),
        'replacement_run_ids'=>(array)($result['replacement_run_ids']??[]),
    ];
}

function vp3_cognitive_autonomy_materialize_milestone_v2470(
    PDO $pdo,array $user,int $goalId,int $milestoneId
): array {
    $uid=agent_goal_planning_require_v1711($pdo,$user);
    $goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);
    if(vp3_cognitive_autonomy_mode_v2470($goal['execution_mode']??'manual')!=='autonomous'){
        return ['performed'=>false,'reason'=>'goal_not_autonomous'];
    }
    $milestone=agent_goal_milestone_row_v1711($pdo,$uid,$goalId,$milestoneId);
    if((string)($milestone['status']??'')==='retired')return ['performed'=>false,'reason'=>'milestone_retired'];
    if((int)($milestone['objective_run_id']??0)>0)return ['performed'=>false,'reason'=>'objective_already_linked','objective_run_id'=>(int)$milestone['objective_run_id']];
    $title=agent_goal_text_v1710($milestone['title']??'',900);
    if($title==='')return ['performed'=>false,'reason'=>'milestone_title_missing'];

    // Objective creation stays inside Phase 17.5. Each child workflow computes
    // its own risk and approval state, and later execution still requires the
    // normal Phase 19 worker claim/lease/receipt path.
    $created=agent_objective_create_v175(
        $pdo,$user,$title,agent_objective_heuristic_stages_v175($title),0,null
    );
    $objectiveId=(int)($created['objective']['id']??0);
    if($objectiveId<1)throw new RuntimeException('Autonomous milestone objective could not be created.');

    $pdo->prepare("INSERT INTO agent_goal_objectives
      (owner_user_id,goal_id,objective_run_id,contribution_weight)
      VALUES (?,?,?,100)
      ON DUPLICATE KEY UPDATE contribution_weight=VALUES(contribution_weight)")
      ->execute([$uid,$goalId,$objectiveId]);
    $stmt=$pdo->prepare("UPDATE agent_goal_milestones SET objective_run_id=?
      WHERE id=? AND owner_user_id=? AND goal_id=? AND objective_run_id IS NULL");
    $stmt->execute([$objectiveId,$milestoneId,$uid,$goalId]);
    $current=agent_goal_milestone_row_v1711($pdo,$uid,$goalId,$milestoneId);
    $linked=(int)($current['objective_run_id']??0);
    if($linked!==$objectiveId&&$linked>0)return ['performed'=>false,'reason'=>'concurrent_objective_link','objective_run_id'=>$linked];

    vp3_cognitive_autonomy_goal_event_v2470(
        $pdo,$uid,$goalId,'autonomous_milestone_objective_created',
        'Autonomous goal execution converted an existing roadmap milestone into a canonical objective.',
        ['milestone_id'=>$milestoneId,'objective_run_id'=>$objectiveId,'build'=>VP3_COGNITIVE_AUTONOMY_V2470]
    );
    return ['performed'=>true,'reason'=>'objective_materialized','objective_run_id'=>$objectiveId,'milestone_id'=>$milestoneId];
}

function vp3_cognitive_autonomy_recommendation_v2470(array $state,string $mode): array
{
    $execution=(string)($state['execution_state']??'unknown');
    $milestone=is_array($state['milestone']??null)?$state['milestone']:[];
    $objective=is_array($state['objective']??null)?$state['objective']:[];
    $objectiveId=(int)($objective['objective']['id']??($milestone['objective_run_id']??0));
    $action=match($execution){
        'needs_objective'=>$mode==='autonomous'?'materialize_objective':'review_next_milestone',
        'repair_needed'=>$mode==='autonomous'?'bounded_remediation':'review_repair',
        'waiting_approval'=>'request_approval',
        'goal_paused'=>'remain_paused',
        'plan_missing'=>'build_roadmap',
        'review_needed'=>'review_roadmap',
        'verification'=>'await_verification_worker',
        'ready'=>'await_worker_claim',
        'executing'=>'observe_execution',
        'scheduled'=>'await_schedule',
        'blocked'=>'await_dependencies',
        'achieved'=>'closed',
        default=>'observe',
    };
    return [
        'action'=>$action,
        'execution_state'=>$execution,
        'objective_run_id'=>$objectiveId,
        'milestone_id'=>(int)($milestone['id']??0),
        'requires_user'=>in_array($action,['review_next_milestone','review_repair','request_approval','build_roadmap','review_roadmap'],true),
    ];
}

function vp3_cognitive_autonomy_goal_projection_v2470(
    PDO $pdo,array $user,array $goalRow
): array {
    $goalId=(int)($goalRow['id']??0);
    $mode=vp3_cognitive_autonomy_mode_v2470($goalRow['execution_mode']??'manual');
    try{$state=agent_goal_execution_state_v1712($pdo,$user,$goalId);}
    catch(Throwable $e){
        return [
            'goal_id'=>$goalId,'title'=>(string)($goalRow['title']??''),'execution_mode'=>$mode,
            'execution_state'=>'unavailable','recommendation'=>['action'=>'inspect','requires_user'=>true],
            'error_class'=>get_class($e),
        ];
    }
    return [
        'goal_id'=>$goalId,
        'title'=>(string)($goalRow['title']??''),
        'execution_mode'=>$mode,
        'priority'=>(int)($goalRow['priority']??50),
        'target_date'=>(string)($goalRow['target_date']??''),
        'progress_percent'=>(int)($state['goal']['progress_percent']??0),
        'execution_state'=>(string)($state['execution_state']??'unknown'),
        'reason'=>(string)($state['reason']??''),
        'recommendation'=>vp3_cognitive_autonomy_recommendation_v2470($state,$mode),
    ];
}

function vp3_cognitive_autonomy_snapshot_v2470(
    PDO $pdo,array $user
): array {
    if(!vp3_cognitive_autonomy_schema_ready_v2470($pdo))return [
        'build'=>VP3_COGNITIVE_AUTONOMY_V2470,'items'=>[],'counts'=>[],'focus'=>null
    ];
    $items=[];
    foreach(vp3_cognitive_autonomy_goal_rows_v2470($pdo,$user,false) as $goal)$items[]=vp3_cognitive_autonomy_goal_projection_v2470($pdo,$user,$goal);
    usort($items,static function(array $a,array $b): int {
        $rank=['autonomous'=>0,'supervised'=>1,'manual'=>2];
        $x=($rank[$a['execution_mode']??'manual']??9)<=>($rank[$b['execution_mode']??'manual']??9);if($x!==0)return $x;
        $x=((int)($b['priority']??0))<=>((int)($a['priority']??0));if($x!==0)return $x;
        return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
    });
    $counts=['autonomous'=>0,'supervised'=>0,'requires_user'=>0,'active'=>count($items)];
    foreach($items as $item){
        $mode=(string)($item['execution_mode']??'manual');if(isset($counts[$mode]))$counts[$mode]++;
        if(!empty($item['recommendation']['requires_user']))$counts['requires_user']++;
    }
    return [
        'contract'=>VP3_COGNITIVE_AUTONOMY_CONTRACT_V2470,
        'build'=>VP3_COGNITIVE_AUTONOMY_V2470,
        'focus'=>$items[0]??null,
        'items'=>$items,
        'counts'=>$counts,
        'authority'=>[
            'goal_store'=>'agent_goals',
            'roadmap'=>'agent_goal_milestones',
            'objective_store'=>'agent_workflow_runs_objective_plan',
            'workflow_store'=>'agent_workflow_runs',
            'worker_runtime'=>'agent_worker_runtime_v1910',
            'approval_system'=>'existing_workflow_approval',
            'projection_only'=>true,
            'direct_tool_execution'=>false,
            'worker_claim_authority'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_autonomy_context_item_v2470(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_autonomy_snapshot_v2470($pdo,$user);
    if(empty($snapshot['items']))return null;
    $safe=[];
    foreach(array_slice((array)$snapshot['items'],0,6) as $item){
        $safe[]=[
            'goal_id'=>(int)$item['goal_id'],
            'title'=>(string)$item['title'],
            'execution_mode'=>(string)$item['execution_mode'],
            'execution_state'=>(string)$item['execution_state'],
            'progress_percent'=>(int)$item['progress_percent'],
            'recommendation'=>(array)$item['recommendation'],
        ];
    }
    $json=json_encode(['focus'=>$safe[0]??null,'goals'=>$safe,'counts'=>$snapshot['counts']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'autonomy','cognitive-autonomy:v2470','Governed long-horizon goal execution',
        $json,96.0,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_autonomy_run_owner_v2470(PDO $pdo,array $user): array
{
    $result=[
        'goals_checked'=>0,'objectives_materialized'=>0,'remediations_performed'=>0,
        'replacement_runs'=>0,'requires_user'=>0,'errors'=>0,
        'build'=>VP3_COGNITIVE_AUTONOMY_V2470,
    ];
    if(!vp3_cognitive_autonomy_schema_ready_v2470($pdo))return $result;
    $objectiveBudget=VP3_COGNITIVE_AUTONOMY_MAX_OBJECTIVES_PER_PASS_V2470;
    $remediationBudget=VP3_COGNITIVE_AUTONOMY_MAX_REMEDIATIONS_PER_PASS_V2470;

    foreach(vp3_cognitive_autonomy_goal_rows_v2470($pdo,$user,false) as $goal){
        $result['goals_checked']++;
        $mode=vp3_cognitive_autonomy_mode_v2470($goal['execution_mode']??'manual');
        if($mode!=='autonomous')continue;
        try{
            $state=agent_goal_execution_state_v1712($pdo,$user,(int)$goal['id']);
            $execution=(string)($state['execution_state']??'unknown');
            if($execution==='needs_objective'){
                if($objectiveBudget<=0)continue;
                $milestone=(array)($state['milestone']??[]);
                $milestoneId=(int)($milestone['id']??0);
                if($milestoneId>0){
                    $made=vp3_cognitive_autonomy_materialize_milestone_v2470($pdo,$user,(int)$goal['id'],$milestoneId);
                    if(!empty($made['performed'])){$result['objectives_materialized']++;$objectiveBudget--;}
                }
            }elseif($execution==='repair_needed'){
                if($remediationBudget<=0)continue;
                $objective=(array)($state['objective']??[]);
                $objectiveId=(int)($objective['objective']['id']??0);
                if($objectiveId>0){
                    $repair=vp3_cognitive_autonomy_remediate_objective_v2470($pdo,$user,$objectiveId);
                    if(!empty($repair['performed'])){
                        $result['remediations_performed']++;
                        $result['replacement_runs']+=(int)($repair['replaced']??0);
                        $remediationBudget--;
                    }elseif(!empty($repair['requires_user']))$result['requires_user']++;
                }
            }elseif(in_array($execution,['waiting_approval','plan_missing','review_needed'],true)){
                $result['requires_user']++;
            }
        }catch(Throwable $e){$result['errors']++;}
    }
    return $result;
}

function vp3_cognitive_autonomy_activity_projection_v2470(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system')return ['build'=>VP3_COGNITIVE_AUTONOMY_V2470,'focus'=>null,'items'=>[],'counts'=>[],'projection_only'=>true];
    $snapshot=vp3_cognitive_autonomy_snapshot_v2470($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_AUTONOMY_V2470,
        'focus'=>$snapshot['focus'],
        'items'=>array_slice((array)$snapshot['items'],0,8),
        'counts'=>$snapshot['counts'],
        'projection_only'=>true,
    ];
}
