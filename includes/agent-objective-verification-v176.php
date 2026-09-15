<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.6 — Objective Verification + Adaptive Replanning.
 *
 * Verification metadata lives on the canonical workflow run. Remediation work
 * is represented as normal Phase 14/19 child workflows and Phase 17.4 edges.
 * No second scheduler, queue, worker runtime, or objective dashboard exists.
 */
const VP3_AGENT_OBJECTIVE_VERIFICATION_V176='agent-objective-verification-v176-20260915';
const VP3_AGENT_OBJECTIVE_REMEDIATION_STAGE_BASE_V176=9000;
const VP3_AGENT_OBJECTIVE_MAX_REMEDIATION_STEPS_V176=4;

require_once __DIR__.'/agent-objective-plans-v175.php';

function agent_objective_verification_schema_ready_v176(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!agent_objective_ready_v175($pdo))return false;
    foreach(['objective_verification_status','objective_success_criteria','objective_verification_summary','objective_verification_evidence','objective_verified_at','objective_remediation_count'] as $column){
        if(!column_exists('agent_workflow_runs',$column))return false;
    }
    return true;
}

function agent_objective_verification_index_exists_v176(PDO $pdo,string $index): bool
{
    $stmt=$pdo->prepare("SHOW INDEX FROM agent_workflow_runs WHERE Key_name=?");
    $stmt->execute([$index]);
    return (bool)$stmt->fetch();
}

function agent_objective_verification_ensure_schema_v176(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_work_control_ensure_schema_v173($pdo);
    agent_work_dependencies_ensure_schema_v174($pdo);
    if(!column_exists('agent_workflow_runs','objective_verification_status'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_verification_status VARCHAR(32) NOT NULL DEFAULT '' AFTER paused_from_status");
    if(!column_exists('agent_workflow_runs','objective_success_criteria'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_success_criteria MEDIUMTEXT NULL AFTER objective_verification_status");
    if(!column_exists('agent_workflow_runs','objective_verification_summary'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_verification_summary VARCHAR(500) NOT NULL DEFAULT '' AFTER objective_success_criteria");
    if(!column_exists('agent_workflow_runs','objective_verification_evidence'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_verification_evidence MEDIUMTEXT NULL AFTER objective_verification_summary");
    if(!column_exists('agent_workflow_runs','objective_verified_at'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_verified_at DATETIME NULL AFTER objective_verification_evidence");
    if(!column_exists('agent_workflow_runs','objective_remediation_count'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN objective_remediation_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER objective_verified_at");
    if(!agent_objective_verification_index_exists_v176($pdo,'idx_agent_objective_verification'))$pdo->exec("CREATE INDEX idx_agent_objective_verification ON agent_workflow_runs (owner_user_id,source_kind,objective_verification_status,id)");
}

function agent_objective_verification_require_v176(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Objective verification is not available for this account.');
    if(!agent_objective_verification_schema_ready_v176($pdo))throw new RuntimeException('Objective verification is not ready yet. Run the normal VP3 database upgrade first.');
    return $uid;
}

function agent_objective_verification_json_v176(mixed $value): array
{
    if(is_array($value))return $value;
    $text=trim((string)$value);if($text==='')return [];
    $decoded=json_decode($text,true);return is_array($decoded)?$decoded:[];
}

function agent_objective_verification_queue_label_v176(PDO $pdo,int $uid,int $runId,string $state): void
{
    $labels=['verifying'=>'Verifying','needs_remediation'=>'Needs remediation','achieved'=>'Achieved'];
    if(!isset($labels[$state])||$uid<1||$runId<1)return;
    $row=agent_objective_verification_parent_v176($pdo,$uid,$runId);if(!$row)return;
    $goal=agent_objective_text_v175((string)($row['goal']??''),150);
    $title=agent_objective_text_v175('Objective: '.$goal.' · '.$labels[$state],190);
    $progress=match($state){'verifying'=>'Verifying objective outcome','needs_remediation'=>'Needs remediation','achieved'=>'Objective achieved',default=>''};
    $pdo->prepare('UPDATE agent_workflow_runs SET title=?,progress_message=? WHERE id=? AND owner_user_id=?')->execute([$title,$progress,$runId,$uid]);
}

function agent_objective_success_criteria_v176(string $goal,array $stages=[],array $explicit=[]): array
{
    $clean=[];
    foreach($explicit as $criterion){$text=agent_objective_text_v175($criterion,260);if($text!==''&&!in_array($text,$clean,true))$clean[]=$text;}
    if(!$clean){
        $clean[]='Every planned child workflow reaches completed with receipt-backed execution evidence.';
        $lower=mb_strtolower($goal);
        if(preg_match('/\b(?:website|site|page|landing page|web app)\b/u',$lower))$clean[]='The requested website result is reachable and the intended change is visibly/functionally present.';
        elseif(preg_match('/\b(?:launch|release|rollout|publish|go live)\b/u',$lower))$clean[]='The requested launch/release state is live and a post-launch verification check passes.';
        elseif(preg_match('/\b(?:research|analy[sz]e|investigate|compare|evaluate)\b/u',$lower))$clean[]='The final result includes evidence-backed findings, a conclusion, and actionable next steps.';
        else $clean[]='The final verification evidence explicitly confirms the requested outcome exists and satisfies the objective goal.';
        $clean[]='No unresolved failed, cancelled, approval-pending, paused, or dependency-blocked objective work remains.';
    }
    return array_slice($clean,0,6);
}

function agent_objective_verification_instruction_v176(string $goal,array $criteria,int $cycle=0): string
{
    $lines=[];foreach($criteria as $index=>$criterion)$lines[]=(($index+1).'. '.$criterion);
    return 'Verify whether the objective was actually achieved, not merely whether its workflows executed. Goal: '.$goal."\nSuccess criteria:\n".implode("\n",$lines)."\nReturn structured result data with objective_achieved as a boolean, verification_summary, criteria_results, evidence, unmet_criteria, and remediation_steps. If evidence is insufficient, objective_achieved must be false. This is verification cycle ".($cycle+1).'.';
}

function agent_objective_verification_parent_v176(PDO $pdo,int $uid,int $objectiveRunId,bool $forUpdate=false): ?array
{
    $sql="SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=? AND (source_kind='objective_plan' OR workflow_type='agent_objective_plan') LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$objectiveRunId,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_verification_parent_by_hash_v176(PDO $pdo,int $uid,string $hash): ?array
{
    if($hash==='')return null;
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_plan' AND source_hash=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$hash]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_verification_latest_review_v176(PDO $pdo,int $uid,int $objectiveRunId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND action_key LIKE 'objective-review%' ORDER BY sequence_no DESC,id DESC LIMIT 1");
    $stmt->execute([$objectiveRunId,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_verification_add_review_action_v176(PDO $pdo,int $uid,array $parent,array $criteria,int $cycle): int
{
    $runId=(int)$parent['id'];$stmt=$pdo->prepare('SELECT COALESCE(MAX(sequence_no),0)+1 FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=?');$stmt->execute([$runId,$uid]);$sequence=max(1,(int)$stmt->fetchColumn());
    $instruction=agent_objective_verification_instruction_v176((string)($parent['goal']??''),$criteria,$cycle);
    $key='objective-review-'.($cycle+1);
    $pdo->prepare('INSERT INTO agent_workflow_actions (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$runId,$uid,$sequence,$key,'execute','Verify objective achievement',$instruction,'queued',0,'cloud','agent.next_action']);
    return (int)$pdo->lastInsertId();
}

function agent_objective_verification_initialize_v176(PDO $pdo,array $user,int $objectiveRunId,array $stages=[],array $explicitCriteria=[]): array
{
    $uid=agent_objective_verification_require_v176($pdo,$user);
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId);if(!$parent)throw new RuntimeException('Objective plan not found.');
    $criteria=agent_objective_success_criteria_v176((string)($parent['goal']??''),$stages,$explicitCriteria);
    $encoded=agent_workflow_json_v1400($criteria);
    $current=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');
    if($current)$criteria=$current;else $pdo->prepare("UPDATE agent_workflow_runs SET objective_success_criteria=?,objective_verification_status=CASE WHEN objective_verification_status='' THEN 'waiting' ELSE objective_verification_status END WHERE id=? AND owner_user_id=?")->execute([$encoded,$objectiveRunId,$uid]);

    $review=agent_objective_verification_latest_review_v176($pdo,$uid,$objectiveRunId);
    $instruction=agent_objective_verification_instruction_v176((string)($parent['goal']??''),$criteria,(int)($parent['objective_remediation_count']??0));
    if($review&&in_array((string)($review['status']??''),['queued','approval_pending'],true)){
        $pdo->prepare("UPDATE agent_workflow_actions SET label='Verify objective achievement',summary=? WHERE id=? AND owner_user_id=?")->execute([$instruction,(int)$review['id'],$uid]);
    }elseif((string)($parent['status']??'')==='completed'&&trim((string)($parent['objective_verification_status']??''))===''){
        agent_objective_verification_add_review_action_v176($pdo,$uid,$parent,$criteria,(int)($parent['objective_remediation_count']??0));
        $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',completed_at=NULL,next_attempt_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Verification queued',objective_verification_status='verifying' WHERE id=? AND owner_user_id=?")->execute([$objectiveRunId,$uid]);
        agent_objective_verification_queue_label_v176($pdo,$uid,$objectiveRunId,'verifying');
        agent_workflow_event_v1400($pdo,$uid,$objectiveRunId,'objective_verification_queued','completed','approved','system','Objective reopened for explicit outcome verification.',['build'=>VP3_AGENT_OBJECTIVE_VERIFICATION_V176]);
    }
    $row=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId);return $row?:$parent;
}

function agent_objective_verification_public_fields_v176(PDO $pdo,array $user,array $parent,array $children=[]): array
{
    if(!agent_objective_verification_schema_ready_v176($pdo))return ['verification_build'=>VP3_AGENT_OBJECTIVE_VERIFICATION_V176,'verification_status'=>'unavailable','success_criteria'=>[]];
    $status=trim((string)($parent['objective_verification_status']??''));
    $criteria=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');
    if($status==='')$status='waiting';
    $hasFailed=false;$hasCancelled=false;$allComplete=$children!==[];
    foreach($children as $child){$childStatus=(string)($child['status']??'');if($childStatus==='failed')$hasFailed=true;if($childStatus==='cancelled')$hasCancelled=true;if($childStatus!=='completed')$allComplete=false;}
    if($status!=='achieved'){
        if($hasFailed||$hasCancelled)$status='needs_remediation';
        elseif($status!=='needs_remediation'&&((string)($parent['status']??'')==='executing'||$allComplete))$status='verifying';
    }
    return [
        'verification_build'=>VP3_AGENT_OBJECTIVE_VERIFICATION_V176,
        'verification_status'=>$status,
        'success_criteria'=>$criteria,
        'verification_summary'=>(string)($parent['objective_verification_summary']??''),
        'verification_evidence'=>agent_objective_verification_json_v176($parent['objective_verification_evidence']??''),
        'verified_at'=>(string)($parent['objective_verified_at']??''),
        'remediation_count'=>(int)($parent['objective_remediation_count']??0),
    ];
}

function agent_objective_verification_state_v176(PDO $pdo,array $user,int $objectiveRunId,bool $history=true): array
{
    $uid=agent_objective_verification_require_v176($pdo,$user);
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId);if(!$parent)throw new RuntimeException('Objective plan not found.');
    if(trim((string)($parent['objective_success_criteria']??''))===''){$parent=agent_objective_verification_initialize_v176($pdo,$user,$objectiveRunId);}
    $state=agent_objective_state_v175($pdo,$user,$objectiveRunId,$history);
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId)?:$parent;
    return array_merge($state,agent_objective_verification_public_fields_v176($pdo,$user,$parent,agent_objective_children_rows_v175($pdo,$uid,$parent)));
}

function agent_objective_verification_signal_v176(array $result): ?bool
{
    foreach(['objective_achieved','verification_passed'] as $key){if(array_key_exists($key,$result)){if(is_bool($result[$key]))return $result[$key];if(in_array(strtolower((string)$result[$key]),['true','yes','1','achieved','passed'],true))return true;if(in_array(strtolower((string)$result[$key]),['false','no','0','not_achieved','failed'],true))return false;}}
    $status=strtolower(trim((string)($result['verification_status']??'')));if(in_array($status,['achieved','passed','verified'],true))return true;if(in_array($status,['needs_remediation','failed','not_achieved','insufficient_evidence'],true))return false;
    return null;
}

function agent_objective_verification_remediation_steps_v176(array $result,array $criteria,string $goal): array
{
    $steps=[];$raw=$result['remediation_steps']??[];
    if(is_string($raw))$raw=preg_split('/\s*(?:;|\r?\n)+\s*/u',$raw,-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(is_array($raw))foreach($raw as $item){$text=is_array($item)?(string)($item['instruction']??$item['title']??''):(string)$item;$text=agent_objective_text_v175($text,700);if($text!=='')$steps[]=$text;}
    if(!$steps){$unmet=$result['unmet_criteria']??[];if(is_string($unmet))$unmet=[$unmet];if(is_array($unmet))foreach($unmet as $item){$text=agent_objective_text_v175(is_array($item)?($item['criterion']??$item['summary']??''):$item,320);if($text!=='')$steps[]='Resolve unmet success criterion: '.$text;}}
    if(!$steps)$steps[]='Collect missing verification evidence, resolve any unmet success criteria, and make the objective verifiably complete: '.$goal;
    return array_slice(array_values(array_unique($steps)),0,VP3_AGENT_OBJECTIVE_MAX_REMEDIATION_STEPS_V176);
}

function agent_objective_verification_insert_remediation_v176(PDO $pdo,int $uid,array $parent,string $instruction,int $cycle,int $taskNo,?int $agentId=null): int
{
    $hash=(string)($parent['source_hash']??'');if($hash==='')throw new RuntimeException('Objective grouping is unavailable.');
    $stage=VP3_AGENT_OBJECTIVE_REMEDIATION_STAGE_BASE_V176+$cycle;
    $step=agent_objective_clean_step_v175($instruction);
    $runId=agent_objective_insert_run_v175($pdo,$uid,$agentId,$hash,(string)($parent['goal']??''),$step,$stage,$taskNo);
    $remediationRow=agent_workflow_row_v1400($pdo,$uid,$runId);$remediationStatus=is_array($remediationRow)?(string)($remediationRow['status']??'approved'):'approved';
    agent_workflow_event_v1400($pdo,$uid,$runId,'objective_remediation_created','',$remediationStatus,'agent','Adaptive objective replanning created remediation work.',['objective_run_id'=>(int)$parent['id'],'cycle'=>$cycle,'task'=>$taskNo]);
    return $runId;
}

function agent_objective_verification_queue_remediation_v176(PDO $pdo,array $user,array $parent,array $steps,string $summary,array $evidence=[]): array
{
    $uid=(int)$user['id'];$parentId=(int)$parent['id'];$cycle=max(1,(int)($parent['objective_remediation_count']??0)+1);$criteria=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');if(!$criteria)$criteria=agent_objective_success_criteria_v176((string)($parent['goal']??''));
    $runIds=[];$previous=0;$taskNo=0;
    foreach(array_slice($steps,0,VP3_AGENT_OBJECTIVE_MAX_REMEDIATION_STEPS_V176) as $instruction){$taskNo++;$runId=agent_objective_verification_insert_remediation_v176($pdo,$uid,$parent,(string)$instruction,$cycle,$taskNo,(int)($parent['agent_id']??0)?:null);if($previous>0)agent_objective_insert_dependency_v175($pdo,$uid,$runId,$previous,'remediation');$runIds[]=$runId;$previous=$runId;}
    foreach($runIds as $runId)agent_objective_insert_dependency_v175($pdo,$uid,$parentId,$runId,'objective_remediation');
    $reviewId=agent_objective_verification_add_review_action_v176($pdo,$uid,$parent,$criteria,$cycle);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',completed_at=NULL,next_attempt_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Needs remediation',objective_verification_status='needs_remediation',objective_verification_summary=?,objective_verification_evidence=?,objective_verified_at=NULL,objective_remediation_count=? WHERE id=? AND owner_user_id=?")->execute([agent_objective_text_v175($summary,500),$evidence?agent_workflow_json_v1400($evidence):null,$cycle,$parentId,$uid]);
    agent_objective_verification_queue_label_v176($pdo,$uid,$parentId,'needs_remediation');
    agent_workflow_event_v1400($pdo,$uid,$parentId,'objective_remediation_queued','completed','approved','agent','Objective verification found unmet criteria and queued targeted remediation.',['cycle'=>$cycle,'remediation_run_ids'=>$runIds,'verification_action_id'=>$reviewId]);
    return $runIds;
}

function agent_objective_verification_child_result_v176(PDO $pdo,array $run,bool $success,string $receiptStatus): void
{
    if((string)($run['source_kind']??'')!=='objective_step')return;
    if(!in_array($receiptStatus,['completed','failed'],true))return;
    $uid=(int)($run['owner_user_id']??0);$hash=(string)($run['source_hash']??'');if($uid<1||$hash==='')return;
    $parent=agent_objective_verification_parent_by_hash_v176($pdo,$uid,$hash);if(!$parent)return;$parentId=(int)$parent['id'];
    if(!$success||$receiptStatus==='failed'){
        $pdo->prepare("UPDATE agent_workflow_runs SET objective_verification_status='needs_remediation',objective_verification_summary='Objective child work failed and requires targeted remediation.' WHERE id=? AND owner_user_id=? AND objective_verification_status<>'achieved'")->execute([$parentId,$uid]);
        agent_objective_verification_queue_label_v176($pdo,$uid,$parentId,'needs_remediation');
        return;
    }
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_step' AND source_hash=? AND status<>'completed'");$stmt->execute([$uid,$hash]);
    if((int)$stmt->fetchColumn()===0){
        $pdo->prepare("UPDATE agent_workflow_runs SET objective_verification_status='verifying' WHERE id=? AND owner_user_id=? AND objective_verification_status NOT IN ('achieved','needs_remediation')")->execute([$parentId,$uid]);
        agent_objective_verification_queue_label_v176($pdo,$uid,$parentId,'verifying');
    }
}

function agent_objective_verification_after_result_v176(PDO $pdo,array $user,array $run,array $action,bool $success,string $summary,array $result,int $receiptId,string $receiptStatus): array
{
    if(!agent_objective_verification_schema_ready_v176($pdo))return ['handled'=>false,'reopened'=>false];
    agent_objective_verification_child_result_v176($pdo,$run,$success,$receiptStatus);
    if(!$success||$receiptStatus!=='completed'||(string)($run['source_kind']??'')!=='objective_plan'||!str_starts_with((string)($action['action_key']??''),'objective-review'))return ['handled'=>false,'reopened'=>false];
    $uid=(int)($user['id']??0);$runId=(int)($run['id']??0);if($uid<1||$runId<1)return ['handled'=>false,'reopened'=>false];
    $parent=agent_objective_verification_parent_v176($pdo,$uid,$runId,true);if(!$parent)return ['handled'=>false,'reopened'=>false];
    $criteria=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');if(!$criteria)$criteria=agent_objective_success_criteria_v176((string)($parent['goal']??''));
    $signal=agent_objective_verification_signal_v176($result);$verificationSummary=agent_objective_text_v175($result['verification_summary']??$summary,500);$evidence=$result['evidence']??($result['criteria_results']??[]);if(!is_array($evidence))$evidence=['summary'=>agent_objective_text_v175($evidence,800)];
    if($signal===true){
        $pdo->prepare("UPDATE agent_workflow_runs SET objective_verification_status='achieved',objective_verification_summary=?,objective_verification_evidence=?,objective_verified_at=UTC_TIMESTAMP(),progress_message='Objective achieved' WHERE id=? AND owner_user_id=?")->execute([$verificationSummary,$evidence?agent_workflow_json_v1400($evidence):null,$runId,$uid]);
        agent_objective_verification_queue_label_v176($pdo,$uid,$runId,'achieved');
        agent_workflow_event_v1400($pdo,$uid,$runId,'objective_achieved','completed','completed','agent','Objective verification confirmed the requested outcome.',['receipt_id'=>$receiptId,'criteria_count'=>count($criteria)]);
        return ['handled'=>true,'reopened'=>false,'verification_status'=>'achieved'];
    }
    $steps=agent_objective_verification_remediation_steps_v176($result,$criteria,(string)($parent['goal']??''));
    $runIds=agent_objective_verification_queue_remediation_v176($pdo,$user,$parent,$steps,$verificationSummary!==''?$verificationSummary:'Objective verification did not confirm achievement.',$evidence);
    return ['handled'=>true,'reopened'=>true,'verification_status'=>'needs_remediation','remediation_run_ids'=>$runIds];
}

function agent_objective_verification_rewire_failed_v176(PDO $pdo,array $user,int $objectiveRunId): array
{
    $uid=agent_objective_verification_require_v176($pdo,$user);$parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId);if(!$parent)throw new RuntimeException('Objective plan not found.');
    $hash=(string)($parent['source_hash']??'');$stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_step' AND source_hash=? AND status IN ('failed','cancelled') ORDER BY id");$stmt->execute([$uid,$hash]);$failed=$stmt->fetchAll()?:[];
    if(!$failed)return ['replaced'=>0,'replacement_run_ids'=>[]];
    $cycle=max(1,(int)($parent['objective_remediation_count']??0)+1);$replacements=[];$taskNo=0;
    foreach($failed as $old){$taskNo++;$oldId=(int)$old['id'];$instruction='Repair and replace failed objective workflow #'.$oldId.' — '.((string)($old['title']??'workflow')).'. Complete its intended outcome before dependent objective work continues. Latest error: '.((string)($old['last_error_class']??'unknown')).'.';$newId=agent_objective_verification_insert_remediation_v176($pdo,$uid,$parent,$instruction,$cycle,$taskNo,(int)($old['agent_id']??0)?:null);
        $deps=$pdo->prepare('SELECT depends_on_run_id FROM agent_workflow_run_dependencies WHERE owner_user_id=? AND run_id=? ORDER BY id');$deps->execute([$uid,$oldId]);foreach($deps->fetchAll()?:[] as $dep){$depId=(int)$dep['depends_on_run_id'];if($depId>0&&$depId!==$newId)agent_objective_insert_dependency_v175($pdo,$uid,$newId,$depId,'replacement_prerequisite');}
        $dependents=$pdo->prepare('SELECT d.run_id,r.status FROM agent_workflow_run_dependencies d INNER JOIN agent_workflow_runs r ON r.id=d.run_id AND r.owner_user_id=d.owner_user_id WHERE d.owner_user_id=? AND d.depends_on_run_id=? ORDER BY d.id');$dependents->execute([$uid,$oldId]);foreach($dependents->fetchAll()?:[] as $dependent){$dependentId=(int)$dependent['run_id'];if($dependentId<1||(string)$dependent['status']==='completed')continue;$pdo->prepare('DELETE FROM agent_workflow_run_dependencies WHERE owner_user_id=? AND run_id=? AND depends_on_run_id=?')->execute([$uid,$dependentId,$oldId]);agent_objective_insert_dependency_v175($pdo,$uid,$dependentId,$newId,'replacement');}
        agent_workflow_event_v1400($pdo,$uid,$oldId,'objective_replaced',(string)$old['status'],(string)$old['status'],'agent','Adaptive replanning preserved the failed workflow as history and rewired remaining dependents to replacement work.',['replacement_run_id'=>$newId,'objective_run_id'=>$objectiveRunId]);$replacements[]=$newId;
    }
    $pdo->prepare("UPDATE agent_workflow_runs SET objective_verification_status='needs_remediation',objective_verification_summary='Failed objective work was replaced and remaining dependencies were rewired.',objective_remediation_count=? WHERE id=? AND owner_user_id=?")->execute([$cycle,$objectiveRunId,$uid]);
    agent_objective_verification_queue_label_v176($pdo,$uid,$objectiveRunId,'needs_remediation');
    return ['replaced'=>count($replacements),'replacement_run_ids'=>$replacements];
}

function agent_objective_verification_verify_v176(PDO $pdo,array $user,int $objectiveRunId): array
{
    $uid=agent_objective_verification_require_v176($pdo,$user);$parent=agent_objective_verification_parent_v176($pdo,$uid,$objectiveRunId);if(!$parent)throw new RuntimeException('Objective plan not found.');
    if(trim((string)($parent['objective_success_criteria']??''))==='')$parent=agent_objective_verification_initialize_v176($pdo,$user,$objectiveRunId);
    $children=agent_objective_children_rows_v175($pdo,$uid,$parent);$unfinished=[];foreach($children as $child){if((string)($child['status']??'')!=='completed')$unfinished[]=['id'=>(int)$child['id'],'status'=>(string)$child['status'],'title'=>(string)$child['title']];}
    if($unfinished)return ['queued'=>false,'unfinished'=>$unfinished,'state'=>agent_objective_verification_state_v176($pdo,$user,$objectiveRunId,true)];
    if((string)($parent['objective_verification_status']??'')==='achieved')return ['queued'=>false,'unfinished'=>[],'state'=>agent_objective_verification_state_v176($pdo,$user,$objectiveRunId,true)];
    $review=agent_objective_verification_latest_review_v176($pdo,$uid,$objectiveRunId);if(!$review||in_array((string)$review['status'],['completed','failed'],true)){$criteria=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');agent_objective_verification_add_review_action_v176($pdo,$uid,$parent,$criteria,(int)($parent['objective_remediation_count']??0));}
    $from=(string)($parent['status']??'');$pdo->prepare("UPDATE agent_workflow_runs SET status='approved',completed_at=NULL,next_attempt_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Verification queued',objective_verification_status='verifying' WHERE id=? AND owner_user_id=?")->execute([$objectiveRunId,$uid]);agent_objective_verification_queue_label_v176($pdo,$uid,$objectiveRunId,'verifying');agent_workflow_event_v1400($pdo,$uid,$objectiveRunId,'objective_verification_queued',$from,'approved','user','Objective verification was explicitly requested in Agent Chat.',[]);
    return ['queued'=>true,'unfinished'=>[],'state'=>agent_objective_verification_state_v176($pdo,$user,$objectiveRunId,true)];
}

function agent_objective_verification_answer_v176(array $state,string $mode='status'): string
{
    $objective=(array)($state['objective']??[]);$id=(int)($objective['id']??0);$goal=(string)($state['goal']??'');$status=(string)($state['verification_status']??'waiting');$summary=(string)($state['verification_summary']??'');$criteria=(array)($state['success_criteria']??[]);$counts=(array)($state['counts']??[]);
    $label=match($status){'achieved'=>'Achieved','needs_remediation'=>'Needs remediation','verifying'=>'Verifying',default=>'Waiting for execution'};
    $parts=['Objective #'.$id.' — '.$goal.'. Verification: '.$label.'.',((int)($counts['completed']??0)).'/'.((int)($counts['total']??0)).' child workflows completed.'];if($summary!=='')$parts[]=$summary;
    if($mode==='why'&&$status!=='achieved'){if((int)($counts['failed']??0)>0)$parts[]=(int)$counts['failed'].' child workflow(s) failed.';if((int)($counts['cancelled']??0)>0)$parts[]=(int)$counts['cancelled'].' child workflow(s) were cancelled.';if((int)($counts['approval']??0)>0)$parts[]=(int)$counts['approval'].' child workflow(s) are waiting approval.';if((int)($counts['paused']??0)>0)$parts[]=(int)$counts['paused'].' child workflow(s) are paused.';if($criteria)$parts[]='Achievement still requires '.count($criteria).' stored success criterion/criteria to be verified.';}
    return implode(' ',$parts);
}

function agent_objective_verification_chat_v176(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;$id=agent_objective_extract_id_v175($q);if($id<1)return $empty;
    $verify=(bool)preg_match('/\b(?:verify|validate|confirm)\b.*\bobjective\b|\bobjective\b.*\b(?:verify|validate|confirm)\b/i',$q);
    $why=(bool)preg_match('/\b(?:why|what)\b.*\b(?:blocking|block|complete|finished|done|achieved)\b/i',$q);
    $repair=(bool)preg_match('/\b(?:repair|fix)\b.*\bobjective\b|\bobjective\b.*\b(?:repair|fix)\b/i',$q);
    $replan=(bool)preg_match('/\b(?:replan|re-plan|adapt|revise)\b.*\bobjective\b|\bobjective\b.*\b(?:replan|re-plan|adapt|revise)\b/i',$q);
    $status=(bool)preg_match('/\b(?:show|status|inspect)\b.*\bobjective\b|\bobjective\b.*\b(?:status|progress)\b/i',$q);
    if(!$verify&&!$why&&!$repair&&!$replan&&!$status)return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        if($repair||$replan){$pdo->beginTransaction();$changed=agent_objective_verification_rewire_failed_v176($pdo,$user,$id);$pdo->commit();$state=agent_objective_verification_state_v176($pdo,$user,$id,true);$answer=($changed['replaced']>0?'Replanned the affected portion of objective #'.$id.' and created '.(int)$changed['replaced'].' replacement workflow(s). Completed work was preserved and remaining dependents were rewired. ':'No failed or cancelled child workflows required replacement. ').agent_objective_verification_answer_v176($state,'why');$tool=$repair?'objective.repair':'objective.replan';}
        elseif($verify){$result=agent_objective_verification_verify_v176($pdo,$user,$id);$state=(array)$result['state'];if(!empty($result['unfinished'])){$ids=[];foreach(array_slice((array)$result['unfinished'],0,6) as $row)$ids[]='#'.(int)$row['id'].' '.(string)$row['status'];$answer='Objective #'.$id.' is not ready for final verification because child work is unfinished: '.implode(', ',$ids).'. '.agent_objective_verification_answer_v176($state,'why');}else{$answer=(!empty($result['queued'])?'Queued receipt-backed objective verification. ':'').agent_objective_verification_answer_v176($state);}$tool='objective.verify';}
        else{$state=agent_objective_verification_state_v176($pdo,$user,$id,true);$answer=agent_objective_verification_answer_v176($state,$why?'why':'status');$tool='objective.inspect';}
        $out=$empty;$out['handled']=true;$out['answer']=$answer;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',['objective_run_id'=>$id,'verification_status'=>(string)($state['verification_status']??'')],$conversationId);return $out;
    }catch(Throwable $e){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$out=$empty;$out['handled']=true;$out['answer']='I could not verify or replan that objective: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.verify',$query,'error',['objective_run_id'=>$id,'error'=>get_class($e)],$conversationId);return $out;}
}
