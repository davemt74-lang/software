<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.4 — Agent Work Delegation & Dependencies.
 *
 * Adds owner-scoped cross-workflow prerequisite edges and controlled execution
 * delegation without creating a parallel scheduler. Phase 19 remains the only
 * durable claimant; this layer only determines whether a run is eligible and
 * where its remaining queued actions are allowed to execute.
 */
const VP3_AGENT_WORK_DEPENDENCIES_V174='agent-work-dependencies-v174-20260915';

require_once __DIR__.'/agent-workflow-runs-v1400.php';

function agent_work_dependencies_schema_ready_v174(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo&&agent_workflow_schema_ready_v1400($pdo)&&table_exists('agent_workflow_run_dependencies'));
}

function agent_work_dependencies_ensure_schema_v174(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_workflow_ensure_schema_v1400($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_run_dependencies (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      run_id BIGINT UNSIGNED NOT NULL,
      depends_on_run_id BIGINT UNSIGNED NOT NULL,
      created_by VARCHAR(32) NOT NULL DEFAULT 'user',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_workflow_run_dependency (run_id,depends_on_run_id),
      INDEX idx_agent_workflow_run_dependency_owner (owner_user_id,run_id,id),
      INDEX idx_agent_workflow_run_dependency_reverse (owner_user_id,depends_on_run_id,id),
      CONSTRAINT fk_agent_workflow_run_dependency_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_workflow_run_dependency_on_run FOREIGN KEY (depends_on_run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_workflow_run_dependency_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_work_dependencies_require_v174(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Agent Work Dependencies are not available for this account.');
    if(!agent_work_dependencies_schema_ready_v174($pdo))throw new RuntimeException('Agent Work Dependencies are not installed yet. An administrator needs to run the Phase 17.4 upgrade.');
    return $uid;
}

function agent_work_dependencies_rows_v174(PDO $pdo,int $uid,int $runId): array
{
    if($uid<1||$runId<1||!agent_work_dependencies_schema_ready_v174($pdo))return [];
    $stmt=$pdo->prepare("SELECT d.id,d.run_id,d.depends_on_run_id,d.created_by,d.created_at,p.title,p.status,p.execution_target,p.updated_at
      FROM agent_workflow_run_dependencies d
      INNER JOIN agent_workflow_runs p ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id
      WHERE d.owner_user_id=? AND d.run_id=? ORDER BY d.id");
    $stmt->execute([$uid,$runId]);
    return $stmt->fetchAll()?:[];
}

function agent_work_dependents_rows_v174(PDO $pdo,int $uid,int $runId): array
{
    if($uid<1||$runId<1||!agent_work_dependencies_schema_ready_v174($pdo))return [];
    $stmt=$pdo->prepare("SELECT d.id,d.run_id,d.depends_on_run_id,d.created_by,d.created_at,r.title,r.status,r.execution_target,r.updated_at
      FROM agent_workflow_run_dependencies d
      INNER JOIN agent_workflow_runs r ON r.id=d.run_id AND r.owner_user_id=d.owner_user_id
      WHERE d.owner_user_id=? AND d.depends_on_run_id=? ORDER BY d.id");
    $stmt->execute([$uid,$runId]);
    return $stmt->fetchAll()?:[];
}

function agent_work_dependency_public_edge_v174(array $row,bool $dependent=false): array
{
    $id=$dependent?(int)($row['run_id']??0):(int)($row['depends_on_run_id']??0);
    return ['run_id'=>$id,'title'=>(string)($row['title']??''),'status'=>(string)($row['status']??''),'execution_target'=>(string)($row['execution_target']??'cloud'),'created_at'=>(string)($row['created_at']??''),'updated_at'=>(string)($row['updated_at']??'')];
}

function agent_work_dependency_blockers_v174(PDO $pdo,int $uid,int $runId): array
{
    $out=[];foreach(agent_work_dependencies_rows_v174($pdo,$uid,$runId) as $row){if((string)($row['status']??'')==='completed')continue;$out[]=agent_work_dependency_public_edge_v174($row,false);}return $out;
}

function agent_work_dependencies_satisfied_v174(PDO $pdo,int $uid,int $runId): bool
{
    if(!agent_work_dependencies_schema_ready_v174($pdo))return true;
    if($uid<1||$runId<1)return false;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_run_dependencies d INNER JOIN agent_workflow_runs p ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id WHERE d.owner_user_id=? AND d.run_id=? AND p.status<>'completed'");
    $stmt->execute([$uid,$runId]);return (int)$stmt->fetchColumn()===0;
}

function agent_work_dependency_blocked_count_v174(PDO $pdo,int $uid): int
{
    if($uid<1||!agent_work_dependencies_schema_ready_v174($pdo))return 0;
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT d.run_id) FROM agent_workflow_run_dependencies d INNER JOIN agent_workflow_runs r ON r.id=d.run_id AND r.owner_user_id=d.owner_user_id INNER JOIN agent_workflow_runs p ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id WHERE d.owner_user_id=? AND r.status NOT IN ('completed','cancelled','paused','approval_pending','failed') AND r.approval_status<>'pending' AND p.status<>'completed'");
    $stmt->execute([$uid]);return max(0,(int)$stmt->fetchColumn());
}

function agent_work_dependency_cycle_v174(PDO $pdo,int $uid,int $runId,int $dependsOnRunId): bool
{
    if($runId===$dependsOnRunId)return true;$frontier=[$dependsOnRunId];$seen=[];$iterations=0;
    while($frontier&&$iterations++<200){$batch=[];foreach($frontier as $id){$id=(int)$id;if($id>0&&!isset($seen[$id])){$seen[$id]=true;$batch[]=$id;}}if(!$batch)return false;$placeholders=implode(',',array_fill(0,count($batch),'?'));$stmt=$pdo->prepare("SELECT run_id,depends_on_run_id FROM agent_workflow_run_dependencies WHERE owner_user_id=? AND run_id IN ({$placeholders})");$stmt->execute(array_merge([$uid],$batch));$next=[];foreach($stmt->fetchAll()?:[] as $edge){$candidate=(int)($edge['depends_on_run_id']??0);if($candidate===$runId)return true;if($candidate>0&&!isset($seen[$candidate]))$next[]=$candidate;}$frontier=$next;}
    if($frontier)throw new RuntimeException('The dependency graph is too deep to validate safely.');return false;
}

function agent_work_dependency_lock_runs_v174(PDO $pdo,int $uid,int $runId,int $dependsOnRunId): array
{
    $ids=[$runId,$dependsOnRunId];sort($ids,SORT_NUMERIC);$stmt=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND id IN (?,?) ORDER BY id FOR UPDATE');$stmt->execute([$uid,$ids[0],$ids[1]]);$rows=$stmt->fetchAll()?:[];$byId=[];foreach($rows as $row)$byId[(int)$row['id']]=$row;if(!isset($byId[$runId])||!isset($byId[$dependsOnRunId]))throw new RuntimeException('Both workflows must belong to your account.');return [$byId[$runId],$byId[$dependsOnRunId]];
}

function agent_work_dependency_assert_mutable_v174(array $row): void
{
    $status=(string)($row['status']??'');if($status==='executing')throw new RuntimeException('Pause the workflow before changing its dependencies or delegation.');if(in_array($status,['completed','cancelled'],true))throw new RuntimeException('Closed workflows cannot have their execution graph changed.');
}

function agent_work_dependency_add_v174(PDO $pdo,array $user,int $runId,int $dependsOnRunId,string $actor='user'): array
{
    $uid=agent_work_dependencies_require_v174($pdo,$user);if($runId<1||$dependsOnRunId<1)throw new RuntimeException('Two workflow numbers are required.');if($runId===$dependsOnRunId)throw new RuntimeException('A workflow cannot depend on itself.');
    try{$pdo->beginTransaction();[$run,$prerequisite]=agent_work_dependency_lock_runs_v174($pdo,$uid,$runId,$dependsOnRunId);agent_work_dependency_assert_mutable_v174($run);if(agent_work_dependency_cycle_v174($pdo,$uid,$runId,$dependsOnRunId))throw new RuntimeException('That dependency would create a cycle, so it was not added.');$stmt=$pdo->prepare('INSERT IGNORE INTO agent_workflow_run_dependencies (owner_user_id,run_id,depends_on_run_id,created_by) VALUES (?,?,?,?)');$stmt->execute([$uid,$runId,$dependsOnRunId,agent_workflow_text_v1400($actor,32)]);if($stmt->rowCount()>0)agent_workflow_event_v1400($pdo,$uid,$runId,'dependency_added',(string)$run['status'],(string)$run['status'],$actor,'Workflow now waits for a prerequisite workflow.',['depends_on_run_id'=>$dependsOnRunId,'depends_on_status'=>(string)$prerequisite['status']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return agent_work_dependencies_state_v174($pdo,$user,$runId,true);
}

function agent_work_dependency_remove_v174(PDO $pdo,array $user,int $runId,int $dependsOnRunId,string $actor='user'): array
{
    $uid=agent_work_dependencies_require_v174($pdo,$user);try{$pdo->beginTransaction();[$run]=agent_work_dependency_lock_runs_v174($pdo,$uid,$runId,$dependsOnRunId);agent_work_dependency_assert_mutable_v174($run);$stmt=$pdo->prepare('DELETE FROM agent_workflow_run_dependencies WHERE owner_user_id=? AND run_id=? AND depends_on_run_id=?');$stmt->execute([$uid,$runId,$dependsOnRunId]);if($stmt->rowCount()>0)agent_workflow_event_v1400($pdo,$uid,$runId,'dependency_removed',(string)$run['status'],(string)$run['status'],$actor,'Workflow prerequisite was removed.',['depends_on_run_id'=>$dependsOnRunId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return agent_work_dependencies_state_v174($pdo,$user,$runId,true);
}

function agent_work_delegate_target_v174(string $target): string
{
    $target=mb_strtolower(trim($target));if(in_array($target,['home server','home-server','local','private'],true))$target='homeserver';if(in_array($target,['vp3','vp3 cloud'],true))$target='cloud';if(!in_array($target,['cloud','homeserver'],true))throw new RuntimeException('Delegation target must be Cloud or HomeServer.');return $target;
}

function agent_work_delegate_v174(PDO $pdo,array $user,int $runId,string $target,string $actor='user'): array
{
    $uid=agent_work_dependencies_require_v174($pdo,$user);$target=agent_work_delegate_target_v174($target);try{$pdo->beginTransaction();$run=agent_workflow_row_v1400($pdo,$uid,$runId,true);if(!$run)throw new RuntimeException('Workflow not found.');agent_work_dependency_assert_mutable_v174($run);$from=(string)($run['execution_target']??'cloud');$pdo->prepare('UPDATE agent_workflow_runs SET execution_target=? WHERE id=? AND owner_user_id=?')->execute([$target,$runId,$uid]);$pdo->prepare("UPDATE agent_workflow_actions SET execution_target=? WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending')")->execute([$target,$runId,$uid]);if($from!==$target)agent_workflow_event_v1400($pdo,$uid,$runId,'execution_delegated',(string)$run['status'],(string)$run['status'],$actor,'Remaining workflow execution was delegated.',['from'=>$from,'to'=>$target]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return agent_work_dependencies_state_v174($pdo,$user,$runId,true);
}

function agent_work_dependencies_state_v174(PDO $pdo,array $user,int $runId,bool $history=false): array
{
    $uid=agent_work_dependencies_require_v174($pdo,$user);$row=agent_workflow_row_v1400($pdo,$uid,$runId);if(!$row)throw new RuntimeException('Workflow not found.');if(function_exists('agent_work_control_public_run_v173')&&function_exists('agent_work_control_schema_ready_v173')&&agent_work_control_schema_ready_v173($pdo))$run=agent_work_control_public_run_v173($pdo,$row,$history);elseif(function_exists('agent_job_public_run_v1900')&&function_exists('agent_job_engine_schema_ready_v1900')&&agent_job_engine_schema_ready_v1900($pdo))$run=agent_job_public_run_v1900($pdo,$row,$history);else $run=agent_workflow_public_run_v1400($pdo,$row,$history);$dependencies=array_map(static fn(array $edge):array=>agent_work_dependency_public_edge_v174($edge,false),agent_work_dependencies_rows_v174($pdo,$uid,$runId));$blockers=[];foreach($dependencies as $edge)if((string)$edge['status']!=='completed')$blockers[]=$edge;$run['work_dependencies_build']=VP3_AGENT_WORK_DEPENDENCIES_V174;$run['dependencies']=$dependencies;$run['blockers']=$blockers;$run['blocked']=count($blockers)>0;if($history)$run['dependents']=array_map(static fn(array $edge):array=>agent_work_dependency_public_edge_v174($edge,true),agent_work_dependents_rows_v174($pdo,$uid,$runId));$run['delegation_targets']=['cloud','homeserver'];return $run;
}

function agent_work_dependencies_extract_pair_v174(string $query): array
{
    if(preg_match('/\b(?:workflow|work|job|run)\s*#?\s*(\d+)\s+(?:depends?\s+on|waits?\s+for|blocked\s+by)\s+(?:workflow|work|job|run)?\s*#?\s*(\d+)/i',$query,$m))return [(int)$m[1],(int)$m[2]];if(preg_match('/\b(?:make|set|have)\s+(?:workflow|work|job|run)\s*#?\s*(\d+)\s+(?:depend\s+on|wait\s+for)\s+(?:workflow|work|job|run)?\s*#?\s*(\d+)/i',$query,$m))return [(int)$m[1],(int)$m[2]];return [0,0];
}
function agent_work_dependencies_extract_run_v174(string $query): int{return preg_match('/\b(?:workflow|work|job|run)\s*#?\s*(\d+)/i',$query,$m)?(int)$m[1]:0;}

function agent_work_dependencies_answer_v174(array $run,string $action): string
{
    $id=(int)($run['id']??0);$target=(string)($run['execution_target']??'cloud');$blockers=is_array($run['blockers']??null)?$run['blockers']:[];if($action==='delegate')return 'Workflow #'.$id.' is delegated to '.($target==='homeserver'?'HomeServer':'Cloud').'. Approval requirements, schedule, retries and durable receipts are unchanged.';if($action==='add')return $blockers?'Workflow #'.$id.' now waits for '.count($blockers).' unfinished prerequisite'.(count($blockers)===1?'':'s').'.':'Workflow #'.$id.' dependency was recorded; its prerequisites are already complete.';if($action==='remove')return $blockers?'Workflow #'.$id.' still has '.count($blockers).' unfinished prerequisite'.(count($blockers)===1?'':'s').'.':'Workflow #'.$id.' has no unfinished prerequisites and can advance when its normal approval, schedule and worker conditions are satisfied.';if(!$blockers)return 'Workflow #'.$id.' has no unfinished prerequisites. It is not blocked by another workflow and is assigned to '.($target==='homeserver'?'HomeServer':'Cloud').'.';$parts=[];foreach(array_slice($blockers,0,8) as $edge)$parts[]='#'.(int)$edge['run_id'].' “'.(string)$edge['title'].'” ('.(string)$edge['status'].')';return 'Workflow #'.$id.' is blocked by '.implode(', ',$parts).'. It will become claim-eligible automatically when every prerequisite is completed; failed or cancelled prerequisites remain visible until you remove or replace the dependency.';
}

function agent_work_dependencies_chat_v174(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];$q=trim($query);if($q==='')return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        if(preg_match('/\b(?:delegate|assign|route|move)\s+(?:workflow|work|job|run)\s*#?\s*(\d+)\s+(?:to|onto)\s+(?:the\s+)?(cloud|vp3 cloud|vp3|homeserver|home server|home-server|local|private)\b/i',$q,$m)){$run=agent_work_delegate_v174($pdo,$user,(int)$m[1],(string)$m[2]);$answer=agent_work_dependencies_answer_v174($run,'delegate');$action='delegate';}
        elseif(preg_match('/\b(?:remove|delete|clear)\s+(?:the\s+)?dependency\s+(?:of|from|for)\s+(?:workflow|work|job|run)\s*#?\s*(\d+)\s+(?:on|from|to)\s+(?:workflow|work|job|run)?\s*#?\s*(\d+)/i',$q,$m)){$run=agent_work_dependency_remove_v174($pdo,$user,(int)$m[1],(int)$m[2]);$answer=agent_work_dependencies_answer_v174($run,'remove');$action='remove_dependency';}
        else{[$runId,$dependsOn]=agent_work_dependencies_extract_pair_v174($q);if($runId>0&&$dependsOn>0){$run=agent_work_dependency_add_v174($pdo,$user,$runId,$dependsOn);$answer=agent_work_dependencies_answer_v174($run,'add');$action='add_dependency';}elseif(preg_match('/\b(?:what(?:\'s| is)?|show|list|inspect|check|why)\b.*\b(?:blocking|blocked|dependencies|dependency|depends)\b/i',$q)||preg_match('/\b(?:blocking|dependencies|dependency)\b.*\b(?:workflow|work|job|run)\b/i',$q)){$runId=agent_work_dependencies_extract_run_v174($q);if($runId<1)return $empty;$run=agent_work_dependencies_state_v174($pdo,$user,$runId,true);$answer=agent_work_dependencies_answer_v174($run,'inspect');$action='inspect_dependencies';}else return $empty;}
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_work.'.$action,$q,'success',['run_id'=>(int)($run['id']??0),'blocked'=>!empty($run['blocked']),'execution_target'=>(string)($run['execution_target']??'')],$conversationId);return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'workflow'=>$run,'work_dependencies_build'=>VP3_AGENT_WORK_DEPENDENCIES_V174];
    }catch(RuntimeException $e){if(!preg_match('/\b(?:workflow|work|job|run|depend|block|delegate|assign|route)\b/i',$q))return $empty;return ['handled'=>true,'answer'=>$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'work_dependencies_build'=>VP3_AGENT_WORK_DEPENDENCIES_V174];}catch(Throwable $e){return ['handled'=>true,'answer'=>'Agent Work Dependencies could not complete that request.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'work_dependencies_build'=>VP3_AGENT_WORK_DEPENDENCIES_V174];}
}
