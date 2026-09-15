<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.5 — Objective Decomposition + Multi-Workflow Plans.
 *
 * An objective is represented by the existing durable workflow ledger:
 * - one objective parent workflow
 * - one or more child workflows grouped by source_hash
 * - Phase 17.4 cross-workflow dependency edges
 * - Phase 19 remains the only claimant / lease / retry / receipt authority
 *
 * No new scheduler, queue, polling loop, dashboard, or schema is introduced.
 */
const VP3_AGENT_OBJECTIVE_PLANS_V175='agent-objective-plans-v175-20260915';
const VP3_AGENT_OBJECTIVE_MAX_STAGES_V175=8;
const VP3_AGENT_OBJECTIVE_MAX_RUNS_V175=16;

require_once __DIR__.'/agent-work-control-v173.php';
require_once __DIR__.'/agent-work-dependencies-v174.php';

function agent_objective_ready_v175(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_workflow_schema_ready_v1400($pdo)
        && agent_job_engine_schema_ready_v1900($pdo)
        && agent_work_control_schema_ready_v173($pdo)
        && agent_work_dependencies_schema_ready_v174($pdo));
}

function agent_objective_require_v175(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Objective plans are not available for this account.');
    if(!agent_objective_ready_v175($pdo))throw new RuntimeException('Objective plans are not ready yet. Run the normal VP3 database upgrade first.');
    return $uid;
}

function agent_objective_text_v175(mixed $value,int $limit=500): string
{
    return agent_workflow_text_v1400($value,$limit);
}

function agent_objective_empty_tool_v175(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function agent_objective_target_v175(string $text,string $explicit=''): string
{
    $explicit=mb_strtolower(trim($explicit));
    if(in_array($explicit,['home server','home-server','homeserver','local','private'],true))return 'homeserver';
    if(in_array($explicit,['cloud','vp3','vp3 cloud'],true))return 'cloud';
    $q=mb_strtolower($text);
    return preg_match('/\b(?:homeserver|home server|local files?|private files?|local folder|private knowledge|on my computer|on this computer)\b/u',$q)?'homeserver':'cloud';
}

function agent_objective_clean_step_v175(string $text): array
{
    $text=trim($text," \t\n\r\0\x0B-–—>→");
    $target='';
    if(preg_match('/^\[(cloud|vp3|vp3 cloud|homeserver|home server|local|private)\]\s*/i',$text,$m)){
        $target=(string)$m[1];
        $text=trim(substr($text,strlen((string)$m[0])));
    }
    $text=preg_replace('/^\d+[\.)]\s*/','',$text)??$text;
    return ['title'=>agent_objective_text_v175($text,190),'instruction'=>agent_objective_text_v175($text,1500),'target'=>agent_objective_target_v175($text,$target)];
}

function agent_objective_explicit_stages_v175(string $stepsText): array
{
    $stepsText=trim($stepsText);
    if($stepsText==='')return [];
    $stages=preg_split('/\s*(?:->|→|\bthen\b|\band\s+then\b)\s*/iu',$stepsText,-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(count($stages)<=1&&preg_match('/[;\n\r]/',$stepsText))$stages=preg_split('/\s*(?:;|\r?\n)+\s*/u',$stepsText,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $out=[];$runCount=0;
    foreach(array_slice($stages,0,VP3_AGENT_OBJECTIVE_MAX_STAGES_V175) as $stageText){
        $parallel=preg_split('/\s+\+\s+/u',trim((string)$stageText),-1,PREG_SPLIT_NO_EMPTY)?:[];
        $stage=[];
        foreach($parallel as $part){
            if($runCount>=VP3_AGENT_OBJECTIVE_MAX_RUNS_V175)break 2;
            $step=agent_objective_clean_step_v175((string)$part);
            if($step['title']==='')continue;
            $stage[]=$step;$runCount++;
        }
        if($stage)$out[]=$stage;
    }
    return $out;
}

function agent_objective_heuristic_stages_v175(string $goal): array
{
    $goal=agent_objective_text_v175($goal,900);
    $lower=mb_strtolower($goal);
    if(preg_match('/\b(?:launch|release|rollout|go live|publish)\b/u',$lower)){
        $steps=[
            'Review the current state, requirements, blockers, and success criteria for '.$goal,
            'Prepare the assets, configuration, and prerequisites needed for '.$goal,
            'Execute the core launch work for '.$goal,
            'Verify the result, capture evidence, and summarize any follow-up work for '.$goal,
        ];
    }elseif(preg_match('/\b(?:website|site|page|landing page|web app)\b/u',$lower)){
        $steps=[
            'Inspect the current website state and define the required changes for '.$goal,
            'Prepare the implementation plan, content, and dependencies for '.$goal,
            'Implement the approved website changes for '.$goal,
            'Validate the finished website work and report remaining issues for '.$goal,
        ];
    }elseif(preg_match('/\b(?:research|analy[sz]e|investigate|compare|evaluate)\b/u',$lower)){
        $steps=[
            'Define the research scope and decision criteria for '.$goal,
            'Gather and analyze the relevant evidence for '.$goal,
            'Synthesize the findings into a recommendation and next actions for '.$goal,
        ];
    }elseif(preg_match('/\b(?:event|meeting|trip|appointment|schedule)\b/u',$lower)){
        $steps=[
            'Review requirements, constraints, dates, and participants for '.$goal,
            'Prepare the plan, resources, and scheduling prerequisites for '.$goal,
            'Coordinate the approved execution work for '.$goal,
            'Verify completion and capture follow-up actions for '.$goal,
        ];
    }else{
        $steps=[
            'Understand the objective, constraints, available context, and success criteria for '.$goal,
            'Prepare the required work and resolve prerequisites for '.$goal,
            'Execute the approved core work for '.$goal,
            'Verify the outcome and summarize remaining follow-up work for '.$goal,
        ];
    }
    $out=[];
    foreach($steps as $step)$out[]=[agent_objective_clean_step_v175($step)];
    return $out;
}

function agent_objective_parse_create_v175(string $query): ?array
{
    $q=trim($query);if($q==='')return null;
    $matched=(bool)preg_match('/\b(?:objective|multi[- ]workflow|break\s+(?:this|it)\s+into\s+workflows|turn\s+(?:this|it)\s+into\s+workflows)\b/i',$q);
    if(!$matched)return null;
    if(!preg_match('/\b(?:create|build|plan|start|organize|decompose|break|turn)\b/i',$q)&&!preg_match('/^objective\s*:/i',$q))return null;

    $body=preg_replace('/^\s*(?:create|build|plan|start|organize|decompose)\s+(?:an?\s+)?(?:multi[- ]workflow\s+)?objective(?:\s+plan)?\s*(?:for|to)?\s*:?[ ]*/i','',$q)??$q;
    $body=preg_replace('/^\s*(?:break|turn)\s+(?:this|it)\s+into\s+workflows\s*:?[ ]*/i','',$body)??$body;
    $body=preg_replace('/^\s*objective\s*:\s*/i','',$body)??$body;
    $goal=$body;$stepsText='';
    if(preg_match('/^(.*?)\bsteps?\s*:\s*(.+)$/is',$body,$m)){$goal=trim((string)$m[1]);$stepsText=trim((string)$m[2]);}
    if($goal===''&&$stepsText!=='')$goal='Complete the requested multi-step objective';
    $goal=agent_objective_text_v175($goal,900);
    if($goal==='')return null;
    $stages=$stepsText!==''?agent_objective_explicit_stages_v175($stepsText):agent_objective_heuristic_stages_v175($goal);
    if(!$stages)return null;
    return ['goal'=>$goal,'stages'=>$stages,'explicit_steps'=>$stepsText!==''];
}

function agent_objective_step_plan_v175(string $objectiveHash,string $goal,array $step,int $stageNo,int $taskNo): array
{
    $title=agent_objective_text_v175($step['title']??'',190);
    $instruction=agent_objective_text_v175($step['instruction']??$title,1500);
    $target=agent_objective_target_v175($instruction,(string)($step['target']??''));
    $key='objective:'.substr($objectiveHash,0,24).':s'.$stageNo.'t'.$taskNo;
    $action=[
        'source'=>'objective_step',
        'title'=>$title,
        'prompt'=>$instruction,
        'score'=>0.85,
        'hash'=>sha1($objectiveHash.'|'.$stageNo.'|'.$taskNo.'|'.$title),
        'action_id'=>'objective-action-'.sha1($key),
    ];
    $event=['id'=>'objective-event-'.sha1($key)];
    $plan=function_exists('agent_action_v124_plan')
        ? agent_action_v124_plan($action,$event,[])
        : ['risk'=>['level'=>'low','requires_approval'=>false],'requires_approval'=>false,'steps'=>[['id'=>'execute','kind'=>'execute','label'=>$title,'instruction'=>$instruction,'requires_approval'=>false]]];
    $risk=is_array($plan['risk']??null)?$plan['risk']:[];
    $requires=!empty($plan['requires_approval'])||!empty($risk['requires_approval']);
    $riskLevel=in_array((string)($risk['level']??''),['low','medium','high'],true)?(string)$risk['level']:'low';
    $planSteps=array_values(array_filter((array)($plan['steps']??[]),'is_array'));
    if(!$planSteps)$planSteps=[['id'=>'execute','kind'=>'execute','label'=>$title,'instruction'=>$instruction,'requires_approval'=>$requires]];
    return ['key'=>$key,'title'=>$title,'instruction'=>$instruction,'target'=>$target,'risk_level'=>$riskLevel,'requires_approval'=>$requires,'steps'=>$planSteps];
}

function agent_objective_insert_run_v175(PDO $pdo,int $uid,?int $agentId,string $objectiveHash,string $goal,array $step,int $stageNo,int $taskNo): int
{
    $plan=agent_objective_step_plan_v175($objectiveHash,$goal,$step,$stageNo,$taskNo);
    $dedupe=hash('sha256','objective-step|'.$uid.'|'.$plan['key']);
    $status=$plan['requires_approval']?'approval_pending':'approved';
    $approval=$plan['requires_approval']?'pending':'not_required';
    $decision='Objective stage '.$stageNo.' task '.$taskNo.' · '.$goal;
    $stmt=$pdo->prepare("INSERT INTO agent_workflow_runs (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$uid,$agentId&&$agentId>0?$agentId:null,'agent_objective_step','chat','objective_step',$plan['key'],$objectiveHash,$dedupe,$plan['title'],$plan['instruction'],$decision,$status,$plan['risk_level'],$plan['requires_approval']?1:0,$approval,$plan['target'],'agent.next_action']);
    $runId=(int)$pdo->lastInsertId();
    agent_workflow_event_v1400($pdo,$uid,$runId,'objective_step_created','',$status,'agent','Objective decomposition created a durable child workflow.',['objective_hash'=>$objectiveHash,'stage'=>$stageNo,'task'=>$taskNo,'execution_target'=>$plan['target']]);
    $insert=$pdo->prepare('INSERT INTO agent_workflow_actions (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach($plan['steps'] as $index=>$actionStep){
        $requires=!empty($actionStep['requires_approval']);
        $kind=agent_objective_text_v175($actionStep['kind']??'execute',40);
        $target=$kind==='execute'?$plan['target']:'cloud';
        $capability=$kind==='execute'?'agent.next_action':'';
        $insert->execute([$runId,$uid,$index+1,agent_objective_text_v175($actionStep['id']??('step-'.($index+1)),120),$kind,agent_objective_text_v175($actionStep['label']??$plan['title'],190),agent_objective_text_v175($actionStep['instruction']??$plan['instruction'],1500),$requires?'approval_pending':'queued',$requires?1:0,$target,$capability]);
    }
    agent_workflow_event_v1400($pdo,$uid,$runId,$plan['requires_approval']?'approval_requested':'approval_not_required',$status,$status,'system',$plan['requires_approval']?'Objective child workflow is waiting for explicit approval.':'Objective child workflow is ready; no approval is required.',['risk_level'=>$plan['risk_level']]);
    return $runId;
}

function agent_objective_insert_parent_v175(PDO $pdo,int $uid,?int $agentId,string $objectiveHash,string $goal,int $stageCount,int $childCount): int
{
    $sourceKey='objective:'.substr($objectiveHash,0,24);
    $title=agent_objective_text_v175('Objective: '.$goal,190);
    $dedupe=hash('sha256','objective-parent|'.$uid.'|'.$objectiveHash);
    $decision='Multi-workflow objective · '.$stageCount.' stage'.($stageCount===1?'':'s').' · '.$childCount.' child workflow'.($childCount===1?'':'s').'.';
    $stmt=$pdo->prepare("INSERT INTO agent_workflow_runs (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$uid,$agentId&&$agentId>0?$agentId:null,'agent_objective_plan','chat','objective_plan',$sourceKey,$objectiveHash,$dedupe,$title,$goal,$decision,'approved','low',0,'not_required','cloud','agent.next_action']);
    $runId=(int)$pdo->lastInsertId();
    $summary='Review the child workflow receipts, verify the objective outcome, and summarize any remaining follow-up work.';
    $pdo->prepare('INSERT INTO agent_workflow_actions (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$runId,$uid,1,'objective-review','execute','Verify objective completion',$summary,'queued',0,'cloud','agent.next_action']);
    agent_workflow_event_v1400($pdo,$uid,$runId,'objective_created','','approved','agent','Objective parent workflow created in the durable work ledger.',['objective_hash'=>$objectiveHash,'stages'=>$stageCount,'children'=>$childCount]);
    return $runId;
}

function agent_objective_insert_dependency_v175(PDO $pdo,int $uid,int $runId,int $dependsOnRunId,string $kind): void
{
    if($runId<1||$dependsOnRunId<1||$runId===$dependsOnRunId)throw new RuntimeException('Invalid objective dependency edge.');
    $stmt=$pdo->prepare('INSERT IGNORE INTO agent_workflow_run_dependencies (owner_user_id,run_id,depends_on_run_id,created_by) VALUES (?,?,?,?)');
    $stmt->execute([$uid,$runId,$dependsOnRunId,'objective_v175']);
    if($stmt->rowCount()>0){
        $run=agent_workflow_row_v1400($pdo,$uid,$runId);
        $status=(string)($run['status']??'approved');
        agent_workflow_event_v1400($pdo,$uid,$runId,'dependency_added',$status,$status,'agent','Objective planner connected a prerequisite workflow.',['depends_on_run_id'=>$dependsOnRunId,'dependency_kind'=>$kind]);
    }
}

function agent_objective_existing_parent_v175(PDO $pdo,int $uid,string $objectiveHash): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_plan' AND source_hash=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$objectiveHash]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_children_rows_v175(PDO $pdo,int $uid,array $parent): array
{
    $hash=(string)($parent['source_hash']??'');if($hash==='')return [];
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_step' AND source_hash=? ORDER BY id");
    $stmt->execute([$uid,$hash]);return $stmt->fetchAll()?:[];
}

function agent_objective_state_v175(PDO $pdo,array $user,int $objectiveRunId,bool $history=false): array
{
    $uid=agent_objective_require_v175($pdo,$user);
    $parent=agent_workflow_row_v1400($pdo,$uid,$objectiveRunId);
    if(!$parent||((string)($parent['source_kind']??'')!=='objective_plan'&&(string)($parent['workflow_type']??'')!=='agent_objective_plan'))throw new RuntimeException('Objective plan not found.');
    $children=agent_objective_children_rows_v175($pdo,$uid,$parent);
    $counts=['total'=>count($children),'completed'=>0,'failed'=>0,'approval'=>0,'paused'=>0,'active'=>0,'cancelled'=>0];
    $public=[];
    foreach($children as $row){
        $status=(string)($row['status']??'');
        if($status==='completed')$counts['completed']++;
        elseif($status==='failed')$counts['failed']++;
        elseif($status==='approval_pending')$counts['approval']++;
        elseif($status==='paused')$counts['paused']++;
        elseif($status==='cancelled')$counts['cancelled']++;
        else $counts['active']++;
        $entry=agent_work_control_schema_ready_v173($pdo)?agent_work_control_public_run_v173($pdo,$row,$history):agent_workflow_public_run_v1400($pdo,$row,$history);
        if(preg_match('/:s(\d+)t(\d+)$/',(string)($row['source_key']??''),$m)){$entry['objective_stage']=(int)$m[1];$entry['objective_task']=(int)$m[2];}
        $public[]=$entry;
    }
    $total=max(1,$counts['total']);$progress=(int)floor(($counts['completed']/$total)*100);
    $parentPublic=agent_work_dependencies_state_v174($pdo,$user,$objectiveRunId,$history);
    return ['build'=>VP3_AGENT_OBJECTIVE_PLANS_V175,'objective'=>$parentPublic,'goal'=>(string)($parent['goal']??''),'counts'=>$counts,'progress_percent'=>$progress,'children'=>$public];
}

function agent_objective_create_v175(PDO $pdo,array $user,string $goal,array $stages,int $conversationId=0,?int $agentId=null): array
{
    $uid=agent_objective_require_v175($pdo,$user);
    $goal=agent_objective_text_v175($goal,900);if($goal==='')throw new RuntimeException('An objective goal is required.');
    $stages=array_values(array_filter(array_slice($stages,0,VP3_AGENT_OBJECTIVE_MAX_STAGES_V175),'is_array'));
    $childCount=0;foreach($stages as $stage)$childCount+=count(array_filter($stage,'is_array'));
    if($childCount<2)throw new RuntimeException('An objective plan needs at least two durable workflow steps.');
    if($childCount>VP3_AGENT_OBJECTIVE_MAX_RUNS_V175)throw new RuntimeException('This objective is too large for one plan. Split it into smaller objectives.');
    $fingerprint=[];foreach($stages as $stage){$items=[];foreach($stage as $step)$items[]=(string)($step['title']??'');$fingerprint[]=$items;}
    $objectiveHash=sha1($uid.'|'.$conversationId.'|'.$goal.'|'.json_encode($fingerprint,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $existing=agent_objective_existing_parent_v175($pdo,$uid,$objectiveHash);if($existing)return agent_objective_state_v175($pdo,$user,(int)$existing['id'],true);

    try{
        $pdo->beginTransaction();
        $stageRuns=[];$allRuns=[];
        foreach($stages as $stageIndex=>$stage){
            $runs=[];$taskNo=0;
            foreach($stage as $step){
                if(!is_array($step))continue;$taskNo++;
                $runId=agent_objective_insert_run_v175($pdo,$uid,$agentId,$objectiveHash,$goal,$step,$stageIndex+1,$taskNo);
                $runs[]=$runId;$allRuns[]=$runId;
            }
            if($runs)$stageRuns[]=$runs;
        }
        if(count($allRuns)<2)throw new RuntimeException('The objective did not produce enough workflow steps.');
        for($stage=1;$stage<count($stageRuns);$stage++){
            foreach($stageRuns[$stage] as $runId)foreach($stageRuns[$stage-1] as $prerequisite)agent_objective_insert_dependency_v175($pdo,$uid,$runId,$prerequisite,'stage');
        }
        $parentId=agent_objective_insert_parent_v175($pdo,$uid,$agentId,$objectiveHash,$goal,count($stageRuns),count($allRuns));
        foreach($allRuns as $childId)agent_objective_insert_dependency_v175($pdo,$uid,$parentId,$childId,'objective');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return agent_objective_state_v175($pdo,$user,$parentId,true);
}

function agent_objective_rows_v175(PDO $pdo,array $user,int $objectiveRunId,bool $includeParent=true): array
{
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$rows=[];
    foreach($state['children'] as $child){$id=(int)($child['id']??0);if($id>0){$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$id);if($row)$rows[]=$row;}}
    if($includeParent){$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$objectiveRunId);if($row)$rows[]=$row;}
    return $rows;
}

function agent_objective_pause_v175(PDO $pdo,array $user,int $objectiveRunId): array
{
    $changed=0;$requested=0;
    foreach(agent_objective_rows_v175($pdo,$user,$objectiveRunId,true) as $row){$status=(string)($row['status']??'');if(!in_array($status,['approved','executing'],true))continue;try{$result=agent_work_control_pause_v173($pdo,$user,(int)$row['id']);$changed++;if((string)($result['status']??'')==='executing')$requested++;}catch(Throwable $e){}}
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$state['control_result']=['changed'=>$changed,'pause_requested'=>$requested];return $state;
}

function agent_objective_resume_v175(PDO $pdo,array $user,int $objectiveRunId): array
{
    $changed=0;foreach(agent_objective_rows_v175($pdo,$user,$objectiveRunId,true) as $row){if((string)($row['status']??'')!=='paused')continue;try{agent_work_control_resume_v173($pdo,$user,(int)$row['id']);$changed++;}catch(Throwable $e){}}
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$state['control_result']=['changed'=>$changed];return $state;
}

function agent_objective_cancel_v175(PDO $pdo,array $user,int $objectiveRunId): array
{
    $changed=0;foreach(agent_objective_rows_v175($pdo,$user,$objectiveRunId,true) as $row){if(in_array((string)($row['status']??''),['completed','cancelled'],true))continue;try{agent_work_control_cancel_v173($pdo,$user,(int)$row['id']);$changed++;}catch(Throwable $e){}}
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$state['control_result']=['changed'=>$changed];return $state;
}

function agent_objective_priority_v175(PDO $pdo,array $user,int $objectiveRunId,string $priority): array
{
    $changed=0;foreach(agent_objective_rows_v175($pdo,$user,$objectiveRunId,true) as $row){if(in_array((string)($row['status']??''),['completed','cancelled'],true))continue;try{agent_work_control_priority_v173($pdo,$user,(int)$row['id'],$priority);$changed++;}catch(Throwable $e){}}
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$state['control_result']=['changed'=>$changed,'priority'=>$priority];return $state;
}

function agent_objective_delegate_v175(PDO $pdo,array $user,int $objectiveRunId,string $target,?int $childOrdinal=null): array
{
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$changed=0;
    if($childOrdinal!==null){
        $children=(array)$state['children'];$index=$childOrdinal-1;if(!isset($children[$index]))throw new RuntimeException('That objective step does not exist.');
        $id=(int)($children[$index]['id']??0);if($id<1)throw new RuntimeException('That objective step is unavailable.');agent_work_delegate_v174($pdo,$user,$id,$target,'user');$changed=1;
    }else{
        foreach(agent_objective_rows_v175($pdo,$user,$objectiveRunId,true) as $row){if(in_array((string)($row['status']??''),['completed','cancelled','executing'],true))continue;try{agent_work_delegate_v174($pdo,$user,(int)$row['id'],$target,'user');$changed++;}catch(Throwable $e){}}
    }
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,false);$state['control_result']=['changed'=>$changed,'target'=>agent_work_delegate_target_v174($target)];return $state;
}

function agent_objective_extract_id_v175(string $query): int
{
    if(preg_match('/\b(?:objective|plan)\s*#?\s*(\d+)/i',$query,$m))return (int)$m[1];
    return 0;
}

function agent_objective_extract_step_ordinal_v175(string $query): ?int
{
    if(preg_match('/\bstep\s*#?\s*(\d+)\b/i',$query,$m))return max(1,(int)$m[1]);
    return null;
}

function agent_objective_answer_v175(array $state,string $verb='status'): string
{
    $objective=(array)($state['objective']??[]);$id=(int)($objective['id']??0);$goal=(string)($state['goal']??'');$counts=(array)($state['counts']??[]);$progress=(int)($state['progress_percent']??0);$children=(array)($state['children']??[]);
    if($verb==='created'){
        $lines=[];foreach($children as $index=>$child){$stage=(int)($child['objective_stage']??0);$target=(string)($child['execution_target']??'cloud');$approval=(string)($child['approval_status']??'not_required');$lines[]=(($index+1).'. #'.(int)($child['id']??0).' · '.(string)($child['title']??'Workflow').' · stage '.$stage.' · '.($target==='homeserver'?'HomeServer':'Cloud').($approval==='pending'?' · approval required':''));}
        return 'Created objective #'.$id.' — '.$goal.'. It contains '.count($children).' durable workflows. Each stage is wired through the existing dependency graph, and the objective parent will unblock only after its child workflows complete.'.($lines?"\n\n".implode("\n",$lines):'');
    }
    $parts=[];$parts[]=$progress.'% complete';$parts[]=(int)($counts['completed']??0).'/'.(int)($counts['total']??0).' child workflows completed';
    if((int)($counts['approval']??0)>0)$parts[]=(int)$counts['approval'].' waiting approval';if((int)($counts['failed']??0)>0)$parts[]=(int)$counts['failed'].' failed';if((int)($counts['paused']??0)>0)$parts[]=(int)$counts['paused'].' paused';
    return 'Objective #'.$id.' — '.$goal.' is '.implode(' · ',$parts).'. The objective workflow is '.(string)($objective['status']??'unknown').(!empty($objective['blocked'])?' and is still blocked by unfinished child workflows.':'.');
}

function agent_objective_chat_v175(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;
    $create=agent_objective_parse_create_v175($q);
    $id=agent_objective_extract_id_v175($q);
    $objectiveIntent=$create!==null||$id>0||preg_match('/\bobjective\b/i',$q);
    if(!$objectiveIntent)return $empty;
    $pdo=db();if(!$pdo)return $empty;
    try{
        if($create!==null){
            $state=agent_objective_create_v175($pdo,$user,(string)$create['goal'],(array)$create['stages'],$conversationId,null);
            $result=$empty;$result['handled']=true;$result['answer']=agent_objective_answer_v175($state,'created');
            if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.create',$query,'success',['objective_run_id'=>(int)($state['objective']['id']??0),'children'=>(int)($state['counts']['total']??0)],$conversationId);
            return $result;
        }
        if($id<1)return $empty;
        if(preg_match('/\b(?:pause|hold|stop temporarily)\b/i',$q)){$state=agent_objective_pause_v175($pdo,$user,$id);$answer='Paused or requested a safe pause for '.(int)($state['control_result']['changed']??0).' workflow'.((int)($state['control_result']['changed']??0)===1?'':'s').' in objective #'.$id.'. '.agent_objective_answer_v175($state);}
        elseif(preg_match('/\b(?:resume|continue|unpause)\b/i',$q)){$state=agent_objective_resume_v175($pdo,$user,$id);$answer='Resumed '.(int)($state['control_result']['changed']??0).' paused workflow'.((int)($state['control_result']['changed']??0)===1?'':'s').' in objective #'.$id.'. '.agent_objective_answer_v175($state);}
        elseif(preg_match('/\b(?:cancel|abort)\b/i',$q)){$state=agent_objective_cancel_v175($pdo,$user,$id);$answer='Cancelled '.(int)($state['control_result']['changed']??0).' unfinished workflow'.((int)($state['control_result']['changed']??0)===1?'':'s').' in objective #'.$id.'. '.agent_objective_answer_v175($state);}
        elseif(preg_match('/\b(?:priority|prioritize|reprioritize)\b.*\b(urgent|high|normal|low)\b/i',$q,$m)){$state=agent_objective_priority_v175($pdo,$user,$id,(string)$m[1]);$answer='Updated priority across '.(int)($state['control_result']['changed']??0).' unfinished workflows in objective #'.$id.'. '.agent_objective_answer_v175($state);}
        elseif(preg_match('/\b(?:delegate|assign|route|move)\b.*\b(HomeServer|Home Server|Cloud|VP3 Cloud)\b/i',$q,$m)){$ordinal=agent_objective_extract_step_ordinal_v175($q);$state=agent_objective_delegate_v175($pdo,$user,$id,(string)$m[1],$ordinal);$answer='Updated execution delegation for '.(int)($state['control_result']['changed']??0).' workflow'.((int)($state['control_result']['changed']??0)===1?'':'s').' in objective #'.$id.'. '.agent_objective_answer_v175($state);}
        else{$state=agent_objective_state_v175($pdo,$user,$id,true);$answer=agent_objective_answer_v175($state);}
        $result=$empty;$result['handled']=true;$result['answer']=$answer;
        if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.control',$query,'success',['objective_run_id'=>$id],$conversationId);
        return $result;
    }catch(Throwable $e){
        $result=$empty;$result['handled']=true;$result['answer']='I could not update that objective plan: '.$e->getMessage();
        if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.control',$query,'error',['objective_run_id'=>$id,'error'=>get_class($e)],$conversationId);
        return $result;
    }
}
