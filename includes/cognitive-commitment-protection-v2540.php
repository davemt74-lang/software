<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.40 — Commitment & Deadline Protection.
 *
 * This layer projects existing commitments from canonical VP3 authorities:
 * - Phase 17.15 goal commitments / agent_goals
 * - Phase 18.23 meeting follow-up commitment lineage
 * - Agent Brain task/commitment memory lifecycle
 *
 * It creates no commitment store. It may annotate portfolio goals, provide a
 * bounded preference ordering over already-eligible Phase 19 claim candidates,
 * and surface deadline/capacity conflicts. It never creates work, changes a
 * deadline, changes an executor, approves work, claims a lease, or declares
 * completion without the canonical source's verification/closure state.
 */
const VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540='vp3-cognitive-commitment-protection-v2540-20260922';
const VP3_COGNITIVE_COMMITMENT_PROTECTION_CONTRACT_V2540='cognitive-commitment-protection-v1';
const VP3_COGNITIVE_COMMITMENT_MAX_ITEMS_V2540=20;
const VP3_COGNITIVE_COMMITMENT_MAX_CLAIM_WINDOW_V2540=100;
const VP3_COGNITIVE_COMMITMENT_DUE_SOON_SECONDS_V2540=86400;
const VP3_COGNITIVE_COMMITMENT_URGENT_SECONDS_V2540=21600;
const VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540=900;

function vp3_cognitive_commitment_ready_v2540(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo&&function_exists('vp3_cognitive_portfolio_capacity_v2480'));
}

function vp3_cognitive_commitment_ts_v2540(mixed $value): int
{
    $value=trim((string)$value);
    if($value==='')return 0;
    $ts=strtotime($value);
    return $ts===false?0:max(0,(int)$ts);
}

function vp3_cognitive_commitment_deadline_state_v2540(int $dueTs,int $now=0): string
{
    $now=$now>0?$now:time();
    if($dueTs<1)return 'no_deadline';
    if($dueTs<=$now)return 'overdue';
    if($dueTs-$now<=VP3_COGNITIVE_COMMITMENT_URGENT_SECONDS_V2540)return 'urgent';
    if($dueTs-$now<=VP3_COGNITIVE_COMMITMENT_DUE_SOON_SECONDS_V2540)return 'due_soon';
    return 'scheduled';
}

function vp3_cognitive_commitment_strength_v2540(string $kind,bool $explicit,float $confidence=1.0): float
{
    $base=match($kind){
        'meeting'=>1.00,
        'goal'=>0.82,
        'memory_commitment'=>0.90,
        'memory_task'=>0.66,
        default=>0.50,
    };
    if(!$explicit)$base*=0.78;
    return round(max(0.0,min(1.0,$base*max(0.25,min(1.0,$confidence)))),4);
}

function vp3_cognitive_commitment_goal_projection_v2540(
    PDO $pdo,array $user,array $items,int $now=0
): array {
    $now=$now>0?$now:time();$out=[];$byGoal=[];$verifiedCount=0;
    foreach($items as $item){
        if(!is_array($item))continue;
        $goalId=(int)($item['goal_id']??0);if($goalId<1)continue;
        $target=trim((string)($item['target_date']??''));$dueTs=vp3_cognitive_commitment_ts_v2540($target);
        if($dueTs<1)continue;
        $derived='';$decision='keep_working';$pressure=0;$verified=false;$status='active';
        if(function_exists('agent_goal_commitment_state_v1715')){
            try{
                $state=agent_goal_commitment_state_v1715($pdo,$user,$goalId);
                $goal=(array)($state['goal']??[]);
                $commit=(array)($state['commitment']??[]);
                $derived=(string)($goal['derived_status']??'');
                $status=(string)($goal['status']??'active');
                $decision=(string)($commit['decision']??'keep_working');
                $pressure=max(0,min(100,(int)($commit['score_percent']??0)));
                $verified=$derived==='achieved'||$decision==='completed';
            }catch(Throwable $e){}
        }
        if($verified){$verifiedCount++;continue;}
        if(in_array($status,['archived'],true))continue;
        $deadlineState=vp3_cognitive_commitment_deadline_state_v2540($dueTs,$now);
        $atRisk=in_array($deadlineState,['overdue','urgent'],true)
            ||in_array($decision,['revise_or_recommit','replan_or_recommit'],true);
        $strength=vp3_cognitive_commitment_strength_v2540('goal',true);
        $protection=round(max(0.0,min(1.25,
            ($strength*0.45)+(($pressure/100)*0.25)
            +(in_array($deadlineState,['overdue','urgent'],true)?0.30:($deadlineState==='due_soon'?0.18:0.05))
        )),4);
        $executor=in_array((string)($item['executor']??''),['cloud','homeserver'],true)
            ?(string)$item['executor']:'cloud';
        $expected=function_exists('vp3_cognitive_resource_expected_seconds_v2520')
            ?vp3_cognitive_resource_expected_seconds_v2520($item,$executor)
            :VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540;
        $row=[
            'key'=>'goal:'.$goalId,'source_kind'=>'goal','source_id'=>$goalId,
            'goal_id'=>$goalId,'workflow_run_id'=>0,
            'title'=>(string)($item['title']??('Goal #'.$goalId)),
            'summary'=>(string)($item['goal']??''),
            'explicit'=>true,'strength'=>$strength,'protection_score'=>$protection,
            'due_at'=>$target,'due_ts'=>$dueTs,'deadline_state'=>$deadlineState,
            'status'=>$status,'decision'=>$decision,'verified_complete'=>false,
            'at_risk'=>$atRisk,'requires_user'=>!empty($item['requires_user']),
            'executor'=>$executor,'expected_seconds'=>max(60,(int)$expected),
            'canonical_authority'=>'agent_goals_and_agent_goal_commitments_v1715',
            'review_path'=>'','claim_protectable'=>false,
        ];
        $out[]=$row;$byGoal[$goalId]=$row;
    }
    return ['items'=>$out,'by_goal'=>$byGoal,'verified_count'=>$verifiedCount];
}

function vp3_cognitive_commitment_meeting_rows_v2540(
    PDO $pdo,int $uid,int $limit=VP3_COGNITIVE_COMMITMENT_MAX_ITEMS_V2540
): array {
    if($uid<1)return [];
    foreach([
        'video_meetings','video_meeting_agenda_items','video_meeting_followthrough_plans',
        'video_meeting_action_executions','video_meeting_followthrough_closures',
        'video_meeting_plan_action_handoffs'
    ] as $table){if(!table_exists($table))return [];}
    $limit=max(1,min(100,$limit));
    $sql="SELECT a.id AS agenda_item_id,a.item_text,a.priority,a.action_kind,a.approval_state,a.status AS agenda_status,
      m.id AS meeting_id,m.public_id AS meeting_public_id,m.title AS meeting_title,
      p.id AS plan_id,p.owner_label,p.target_at,p.target_timezone,p.verification_criteria,p.readiness,
      h.id AS handoff_id,h.status AS handoff_status,h.plan_snapshot_json,
      e.id AS execution_id,e.action_kind AS execution_action_kind,e.status AS execution_status,e.workflow_run_id,
      c.id AS closure_id,c.status AS closure_status,c.verified_at
      FROM video_meeting_agenda_items a
      JOIN video_meetings m ON m.id=a.meeting_id AND m.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_followthrough_plans p ON p.agenda_item_id=a.id AND p.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_plan_action_handoffs h ON h.agenda_item_id=a.id AND h.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_action_executions e ON e.agenda_item_id=a.id AND e.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_followthrough_closures c ON c.agenda_item_id=a.id AND c.owner_user_id=a.owner_user_id
      WHERE a.owner_user_id=? AND a.status<>'skipped' AND (a.item_type='follow_up' OR a.status='follow_up')
      ORDER BY COALESCE(p.target_at,m.start_at_utc),a.id LIMIT ".$limit;
    try{$stmt=$pdo->prepare($sql);$stmt->execute([$uid]);return $stmt->fetchAll()?:[];}
    catch(Throwable $e){return [];}
}

function vp3_cognitive_commitment_meeting_projection_v2540(
    PDO $pdo,array $user,int $now=0
): array {
    $uid=(int)($user['id']??0);$now=$now>0?$now:time();$out=[];$runMap=[];$verifiedCount=0;
    foreach(vp3_cognitive_commitment_meeting_rows_v2540($pdo,$uid) as $row){
        if(!is_array($row))continue;
        $closure=(string)($row['closure_status']??'');
        if($closure==='verified'){$verifiedCount++;continue;}
        $target=(string)($row['target_at']??'');$dueTs=vp3_cognitive_commitment_ts_v2540($target);
        $ready=(string)($row['readiness']??'')==='ready';
        $criteria=trim((string)($row['verification_criteria']??''));
        $explicit=$ready&&$dueTs>0&&$criteria!=='';
        $strength=vp3_cognitive_commitment_strength_v2540('meeting',$explicit);
        $deadlineState=vp3_cognitive_commitment_deadline_state_v2540($dueTs,$now);
        $runId=max(0,(int)($row['workflow_run_id']??0));
        $executor='external';$expected=VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540;
        $runStatus='';$actionAvailable=false;
        if($runId>0&&function_exists('agent_workflow_row_v1400')){
            try{
                $run=agent_workflow_row_v1400($pdo,$uid,$runId);
                if(is_array($run)){
                    $runStatus=(string)($run['status']??'');
                    $q=$pdo->prepare("SELECT execution_target,timeout_seconds,status,available_at FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status='queued' ORDER BY sequence_no,id LIMIT 1");
                    $q->execute([$runId,$uid]);$action=$q->fetch();
                    if(is_array($action)){
                        $executor=in_array((string)($action['execution_target']??''),['cloud','homeserver'],true)
                            ?(string)$action['execution_target']:'cloud';
                        $expected=max(60,min(7200,(int)($action['timeout_seconds']??VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540)));
                        $available=vp3_cognitive_commitment_ts_v2540($action['available_at']??'');
                        $actionAvailable=$available<1||$available<=$now;
                    }
                }
            }catch(Throwable $e){}
        }
        $handoffCurrent=(string)($row['handoff_status']??'')==='current';
        $snapshot=json_decode((string)($row['plan_snapshot_json']??''),true);
        if(is_array($snapshot)&&$ready){
            foreach(['target_at','owner_label','verification_criteria'] as $field){
                if(array_key_exists($field,$snapshot)&&(string)$snapshot[$field]!=(string)($row[$field]??''))$handoffCurrent=false;
            }
        }
        $execution=(string)($row['execution_status']??'');
        $atRisk=in_array($deadlineState,['overdue','urgent'],true)
            ||$execution==='failed'||(!$handoffCurrent&&$runId>0);
        $requiresUser=!$ready||in_array($execution,['needs_review','failed'],true)
            ||(string)($row['approval_state']??'')!=='approved_for_agent_review';
        $protection=round(max(0.0,min(1.35,
            ($strength*0.55)
            +(in_array($deadlineState,['overdue','urgent'],true)?0.35:($deadlineState==='due_soon'?0.20:0.05))
            +($runId>0?0.10:0.0)
            -($requiresUser?0.08:0.0)
        )),4);
        $claimProtectable=$explicit&&$runId>0&&$handoffCurrent
            &&in_array($runStatus,['approved','executing'],true)&&$actionAvailable;
        $item=[
            'key'=>'meeting:'.(int)$row['agenda_item_id'],'source_kind'=>'meeting',
            'source_id'=>(int)$row['agenda_item_id'],'goal_id'=>0,'workflow_run_id'=>$runId,
            'title'=>(string)($row['item_text']??'Meeting follow-up'),
            'summary'=>'Meeting commitment from '.(string)($row['meeting_title']??'Meeting'),
            'explicit'=>$explicit,'strength'=>$strength,'protection_score'=>$protection,
            'due_at'=>$target,'due_ts'=>$dueTs,'deadline_state'=>$deadlineState,
            'status'=>$closure!==''?$closure:($execution!==''?$execution:'follow_up'),
            'decision'=>$requiresUser?'needs_user_or_approval':'protect',
            'verified_complete'=>false,'at_risk'=>$atRisk,'requires_user'=>$requiresUser,
            'executor'=>$executor,'expected_seconds'=>$expected,
            'canonical_authority'=>'video_meeting_commitment_command_v18230_and_followthrough_closure_v18200',
            'review_path'=>'/meeting.php?meeting='.rawurlencode((string)($row['meeting_public_id']??'')),
            'meeting_id'=>(int)($row['meeting_id']??0),
            'agenda_item_id'=>(int)($row['agenda_item_id']??0),
            'handoff_current'=>$handoffCurrent,'claim_protectable'=>$claimProtectable,
        ];
        $out[]=$item;if($runId>0)$runMap[$runId]=$item;
    }
    return ['items'=>$out,'by_run'=>$runMap,'verified_count'=>$verifiedCount];
}

function vp3_cognitive_commitment_memory_projection_v2540(array $user,int $now=0): array
{
    $now=$now>0?$now:time();$out=[];$verifiedCount=0;
    if(!function_exists('agent_memory_v123_tasks'))return ['items'=>[],'verified_count'=>0];
    try{$tasks=agent_memory_v123_tasks($user,true);}catch(Throwable $e){return ['items'=>[],'verified_count'=>0];}
    foreach(array_slice($tasks,0,VP3_COGNITIVE_COMMITMENT_MAX_ITEMS_V2540) as $task){
        if(!is_array($task))continue;
        $status=(string)($task['status']??'open');
        if($status==='completed'){$verifiedCount++;continue;}
        if($status==='cancelled')continue;
        $due=(string)($task['due_at']??'');$dueTs=vp3_cognitive_commitment_ts_v2540($due);
        if($dueTs<1)continue;
        $kind=(string)($task['kind']??'task');
        $source=(string)($task['source_kind']??'');
        $explicit=$source==='explicit_user'||$kind==='commitment';
        $strength=vp3_cognitive_commitment_strength_v2540(
            $kind==='commitment'?'memory_commitment':'memory_task',
            $explicit,(float)($task['confidence']??0.7)
        );
        $deadlineState=vp3_cognitive_commitment_deadline_state_v2540($dueTs,$now);
        $out[]=[
            'key'=>'memory:'.(string)($task['task_key']??$task['memory_id']??''),
            'source_kind'=>$kind==='commitment'?'memory_commitment':'memory_task',
            'source_id'=>(int)($task['memory_id']??0),'goal_id'=>0,'workflow_run_id'=>0,
            'title'=>(string)($task['title']??'Commitment'),
            'summary'=>(string)($task['text']??''),
            'explicit'=>$explicit,'strength'=>$strength,
            'protection_score'=>round(max(0.0,min(1.1,$strength+(in_array($deadlineState,['overdue','urgent'],true)?0.1:0.0))),4),
            'due_at'=>$due,'due_ts'=>$dueTs,'deadline_state'=>$deadlineState,
            'status'=>$status,'decision'=>$status==='waiting'?'waiting':'protect',
            'verified_complete'=>false,
            'at_risk'=>in_array($deadlineState,['overdue','urgent'],true)||$status==='waiting',
            'requires_user'=>$status==='waiting','executor'=>'external','expected_seconds'=>0,
            'canonical_authority'=>'agent_memory_items_task_lifecycle_v123',
            'review_path'=>(string)($task['source_url']??''),'claim_protectable'=>false,
        ];
    }
    return ['items'=>$out,'verified_count'=>$verifiedCount];
}

function vp3_cognitive_commitment_apply_capacity_conflicts_v2540(
    array $commitments,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    foreach(['cloud','homeserver'] as $executor){
        $indexes=[];
        foreach($commitments as $i=>$item){
            if((string)($item['executor']??'')===$executor
                &&empty($item['verified_complete'])
                &&(int)($item['due_ts']??0)>0)$indexes[]=$i;
        }
        usort($indexes,static function(int $a,int $b) use($commitments): int {
            $x=((int)($commitments[$a]['due_ts']??PHP_INT_MAX))<=>
               ((int)($commitments[$b]['due_ts']??PHP_INT_MAX));
            if($x!==0)return $x;
            $x=((float)($commitments[$b]['strength']??0))<=>
               ((float)($commitments[$a]['strength']??0));
            return $x!==0?$x:$a<=>$b;
        });
        $max=max(0,(int)($capacity['executors'][$executor]['max']??0));
        $active=max(0,(int)($capacity['executors'][$executor]['active']??0));
        if($max<1){
            foreach($indexes as $i){
                $commitments[$i]['at_risk']=true;
                $commitments[$i]['conflict_code']='capacity_unavailable';
                $commitments[$i]['projected_finish_at']='';
            }
            continue;
        }
        $lanes=array_fill(0,$max,$now);
        for($i=0;$i<min($active,$max);$i++)$lanes[$i]=$now+VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540;
        foreach($indexes as $i){
            asort($lanes,SORT_NUMERIC);$lane=(int)array_key_first($lanes);
            $finish=$lanes[$lane]+max(60,(int)($commitments[$i]['expected_seconds']??VP3_COGNITIVE_COMMITMENT_DEFAULT_WORK_SECONDS_V2540));
            $lanes[$lane]=$finish;$commitments[$i]['projected_finish_at']=gmdate('c',$finish);
            if($finish>(int)$commitments[$i]['due_ts']){
                $commitments[$i]['at_risk']=true;
                $commitments[$i]['conflict_code']='capacity_deadline_conflict';
            }else $commitments[$i]['conflict_code']=$commitments[$i]['conflict_code']??'';
        }
    }
    return $commitments;
}

function vp3_cognitive_commitment_apply_v2540(
    PDO $pdo,array $user,array $items,array $capacity,int $now=0
): array {
    $now=$now>0?$now:time();
    $goals=vp3_cognitive_commitment_goal_projection_v2540($pdo,$user,$items,$now);
    $meetings=vp3_cognitive_commitment_meeting_projection_v2540($pdo,$user,$now);
    $memoryProjection=vp3_cognitive_commitment_memory_projection_v2540($user,$now);
    $memory=(array)($memoryProjection['items']??[]);
    $commitments=array_merge((array)$goals['items'],(array)$meetings['items'],$memory);
    $commitments=vp3_cognitive_commitment_apply_capacity_conflicts_v2540($commitments,$capacity,$now);

    $byGoal=[];$byRun=[];
    foreach($commitments as $commitment){
        $goalId=(int)($commitment['goal_id']??0);if($goalId>0)$byGoal[$goalId]=$commitment;
        $runId=(int)($commitment['workflow_run_id']??0);if($runId>0)$byRun[$runId]=$commitment;
    }
    foreach($items as &$item){
        $goalId=(int)($item['goal_id']??0);$commit=$byGoal[$goalId]??null;
        $item['commitment_key']=$commit?(string)$commit['key']:'';
        $item['commitment_strength']=$commit?(float)$commit['strength']:0.0;
        $item['commitment_protection_score']=$commit?(float)$commit['protection_score']:0.0;
        $item['commitment_deadline_state']=$commit?(string)$commit['deadline_state']:'';
        $item['commitment_at_risk']=$commit?!empty($commit['at_risk']):false;
        $item['commitment_conflict_code']=$commit?(string)($commit['conflict_code']??''):'';
    }
    unset($item);

    usort($commitments,static function(array $a,array $b): int {
        $riskA=!empty($a['at_risk'])?0:1;$riskB=!empty($b['at_risk'])?0:1;
        if($riskA!==$riskB)return $riskA<=>$riskB;
        $dueA=(int)($a['due_ts']??0);$dueB=(int)($b['due_ts']??0);
        $dueA=$dueA>0?$dueA:PHP_INT_MAX;$dueB=$dueB>0?$dueB:PHP_INT_MAX;
        if($dueA!==$dueB)return $dueA<=>$dueB;
        $x=((float)($b['protection_score']??0))<=>((float)($a['protection_score']??0));
        if($x!==0)return $x;
        return strcmp((string)($a['key']??''),(string)($b['key']??''));
    });
    $commitments=array_slice($commitments,0,VP3_COGNITIVE_COMMITMENT_MAX_ITEMS_V2540);
    $atRisk=array_values(array_filter($commitments,static fn(array $x): bool=>!empty($x['at_risk'])));
    $needsUser=array_values(array_filter($commitments,static fn(array $x): bool=>!empty($x['requires_user'])));
    $protected=array_values(array_filter($commitments,static fn(array $x): bool=>(float)($x['protection_score']??0)>=0.65));
    $conflicts=array_values(array_filter($commitments,static fn(array $x): bool=>(string)($x['conflict_code']??'')!==''));

    return [
        'items'=>$items,
        'commitment_protection'=>[
            'build'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540,
            'contract'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_CONTRACT_V2540,
            'focus'=>$commitments[0]??null,'commitments'=>$commitments,
            'by_goal'=>$byGoal,'by_run'=>$byRun,
            'counts'=>[
                'total'=>count($commitments),'protected'=>count($protected),
                'at_risk'=>count($atRisk),'needs_user'=>count($needsUser),
                'conflicts'=>count($conflicts),
                'verified_complete'=>(int)($goals['verified_count']??0)+(int)($meetings['verified_count']??0)+(int)($memoryProjection['verified_count']??0),
                'goals'=>count(array_filter($commitments,static fn(array $x): bool=>(string)$x['source_kind']==='goal')),
                'meetings'=>count(array_filter($commitments,static fn(array $x): bool=>(string)$x['source_kind']==='meeting')),
                'memory'=>count(array_filter($commitments,static fn(array $x): bool=>str_starts_with((string)$x['source_kind'],'memory_'))),
            ],
            'authority'=>[
                'goal_commitments'=>'agent_goal_commitments_v1715',
                'meeting_commitments'=>'video_meeting_commitment_command_v18230',
                'meeting_verification'=>'video_meeting_followthrough_closure_v18200',
                'memory_commitments'=>'agent_memory_items_task_lifecycle_v123',
                'portfolio_admission'=>'cognitive_portfolio_v2480',
                'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
                'completion_authority'=>false,'deadline_mutation_authority'=>false,
                'executor_mutation_authority'=>false,'approval_authority'=>false,
            ],
            'projection_only'=>true,
        ],
    ];
}

/**
 * Stable preference ordering over the existing Phase 19 candidate window.
 * It does not add/remove candidates and does not itself claim anything.
 */
function vp3_cognitive_commitment_rank_claims_v2540(
    PDO $pdo,array $user,string $executor,array $rows,int $now=0
): array {
    $uid=(int)($user['id']??0);$now=$now>0?$now:time();
    if($uid<1||!in_array($executor,['cloud','homeserver'],true)||!$rows)return $rows;
    $runIds=array_values(array_unique(array_filter(array_map(
        static fn(array $r): int=>(int)($r['id']??0),$rows
    ),static fn(int $id): bool=>$id>0)));
    if(!$runIds)return $rows;
    $meeting=vp3_cognitive_commitment_meeting_projection_v2540($pdo,$user,$now);
    $protected=[];
    foreach((array)$meeting['by_run'] as $runId=>$commitment){
        if(!in_array((int)$runId,$runIds,true))continue;
        if(empty($commitment['claim_protectable']))continue;
        if((string)($commitment['executor']??'')!==$executor)continue;
        $state=(string)($commitment['deadline_state']??'');
        if(!in_array($state,['overdue','urgent','due_soon'],true))continue;
        $protected[(int)$runId]=$commitment;
    }
    if(!$protected)return $rows;
    foreach($rows as $i=>&$row)$row['_commitment_original_index']=$i;
    unset($row);
    usort($rows,static function(array $a,array $b) use($protected): int {
        $aId=(int)($a['id']??0);$bId=(int)($b['id']??0);
        $aC=$protected[$aId]??null;$bC=$protected[$bId]??null;
        if((bool)$aC!==(bool)$bC)return $aC?-1:1;
        if($aC&&$bC){
            $x=((int)$aC['due_ts'])<=>((int)$bC['due_ts']);if($x!==0)return $x;
            $x=((float)$bC['protection_score'])<=>((float)$aC['protection_score']);if($x!==0)return $x;
        }
        return ((int)($a['_commitment_original_index']??0))<=>
            ((int)($b['_commitment_original_index']??0));
    });
    foreach($rows as &$row)unset($row['_commitment_original_index']);
    unset($row);
    return $rows;
}

function vp3_cognitive_commitment_snapshot_v2540(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540,
        'contract'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_CONTRACT_V2540,
        'focus'=>null,'commitments'=>[],'counts'=>[],'projection_only'=>true,
    ];
    if(!vp3_cognitive_commitment_ready_v2540($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
    catch(Throwable $e){return $empty;}
    if(is_array($portfolio['commitment_protection']??null))return $portfolio['commitment_protection'];
    try{
        $applied=vp3_cognitive_commitment_apply_v2540(
            $pdo,$user,(array)($portfolio['items']??[]),(array)($portfolio['capacity']??[])
        );
        return (array)($applied['commitment_protection']??$empty);
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_commitment_context_item_v2540(
    PDO $pdo,array $user,string $namespace
): ?array {
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_commitment_snapshot_v2540($pdo,$user);
    if(empty($snapshot['commitments']))return null;
    $json=json_encode([
        'focus'=>$snapshot['focus']??null,'counts'=>$snapshot['counts']??[],
        'commitments'=>array_slice((array)($snapshot['commitments']??[]),0,8),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'commitment_protection','cognitive-commitment-protection:v2540',
        'Commitment and deadline protection',$json,95.99,
        ['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_commitment_activity_projection_v2540(
    PDO $pdo,array $user,string $namespace
): array {
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540,'focus'=>null,
        'commitments'=>[],'counts'=>[],'projection_only'=>true,
    ];
    $snapshot=vp3_cognitive_commitment_snapshot_v2540($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540,
        'focus'=>$snapshot['focus']??null,
        'commitments'=>array_slice((array)($snapshot['commitments']??[]),0,12),
        'counts'=>$snapshot['counts']??[],
        'projection_only'=>true,
    ];
}
