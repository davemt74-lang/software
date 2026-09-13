<?php
declare(strict_types=1);

/**
 * VP3 Phase 14 — Agent Workflow Runs + Execution History.
 *
 * This is an orchestration ledger, not a replacement execution engine.
 * Calendar, Scheduling, Commerce, HomeServer and other domain tools retain
 * authority for their own mutations. Workflow rows record what the Agent is
 * trying to accomplish, approvals, observable actions and structured results.
 */
const VP3_AGENT_WORKFLOW_RUNS_V1400 = 'agent-workflow-runs-v1400-20260912';
const VP3_AGENT_WORKFLOW_RUN_LIMIT_V1400 = 50;

function agent_workflow_schema_ready_v1400(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;
    foreach (['agent_workflow_runs','agent_workflow_actions','agent_workflow_events'] as $table) {
        if (!table_exists($table)) return false;
    }
    foreach (['owner_user_id','workflow_type','status','dedupe_key','requires_approval','execution_target'] as $column) {
        if (!column_exists('agent_workflow_runs', $column)) return false;
    }
    foreach (['run_id','sequence_no','status','execution_target','requires_approval'] as $column) {
        if (!column_exists('agent_workflow_actions', $column)) return false;
    }
    return true;
}

function agent_workflow_ensure_schema_v1400(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_runs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NULL,
      workflow_type VARCHAR(80) NOT NULL DEFAULT 'agent_next_action',
      origin VARCHAR(40) NOT NULL DEFAULT 'brain',
      source_kind VARCHAR(100) NOT NULL DEFAULT '',
      source_key VARCHAR(190) NOT NULL DEFAULT '',
      source_hash CHAR(40) NOT NULL DEFAULT '',
      dedupe_key CHAR(64) NOT NULL,
      title VARCHAR(190) NOT NULL,
      goal TEXT NULL,
      decision_summary TEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'queued',
      risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
      requires_approval TINYINT(1) NOT NULL DEFAULT 0,
      approval_status VARCHAR(24) NOT NULL DEFAULT 'not_required',
      execution_target VARCHAR(24) NOT NULL DEFAULT 'cloud',
      capability_key VARCHAR(160) NOT NULL DEFAULT '',
      current_action_id BIGINT UNSIGNED NULL,
      attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
      last_error_class VARCHAR(80) NOT NULL DEFAULT '',
      started_at DATETIME NULL,
      approved_at DATETIME NULL,
      completed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_workflow_owner_dedupe (owner_user_id,dedupe_key),
      INDEX idx_agent_workflow_owner_status (owner_user_id,status,updated_at,id),
      INDEX idx_agent_workflow_source (owner_user_id,source_kind,source_key),
      INDEX idx_agent_workflow_agent (agent_id,status,updated_at,id),
      CONSTRAINT fk_agent_workflow_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_actions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      run_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      sequence_no INT UNSIGNED NOT NULL,
      action_key VARCHAR(120) NOT NULL DEFAULT '',
      action_type VARCHAR(40) NOT NULL DEFAULT 'execute',
      label VARCHAR(190) NOT NULL,
      summary TEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'queued',
      requires_approval TINYINT(1) NOT NULL DEFAULT 0,
      execution_target VARCHAR(24) NOT NULL DEFAULT 'cloud',
      capability_key VARCHAR(160) NOT NULL DEFAULT '',
      attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
      result_summary TEXT NULL,
      result_json MEDIUMTEXT NULL,
      error_class VARCHAR(80) NOT NULL DEFAULT '',
      started_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_workflow_action_sequence (run_id,sequence_no),
      INDEX idx_agent_workflow_action_owner (owner_user_id,status,run_id,sequence_no),
      CONSTRAINT fk_agent_workflow_action_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_workflow_action_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      run_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      from_status VARCHAR(32) NOT NULL DEFAULT '',
      to_status VARCHAR(32) NOT NULL DEFAULT '',
      actor_kind VARCHAR(32) NOT NULL DEFAULT 'system',
      summary VARCHAR(500) NOT NULL DEFAULT '',
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_agent_workflow_event_run (run_id,id),
      INDEX idx_agent_workflow_event_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_agent_workflow_event_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_workflow_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_workflow_text_v1400(mixed $value, int $limit = 500): string
{
    $text = preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '';
    return mb_strimwidth($text, 0, max(1, $limit), '…');
}

function agent_workflow_json_v1400(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) ? $json : '{}';
}

function agent_workflow_public_json_v1400(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    $safe = [];
    foreach (array_slice($decoded, 0, 30, true) as $key => $value) {
        $key = agent_workflow_text_v1400($key, 80);
        if ($key === '' || preg_match('/(?:token|secret|password|credential|authorization|cookie|prompt|reasoning)/i', $key)) continue;
        if (is_scalar($value) || $value === null) $safe[$key] = agent_workflow_text_v1400($value ?? '', 500);
    }
    return $safe;
}

function agent_workflow_allowed_transition_v1400(string $from, string $to): bool
{
    $map = [
        'queued' => ['planning','cancelled'],
        'planning' => ['approval_pending','approved','failed','cancelled'],
        'approval_pending' => ['approved','cancelled'],
        'approved' => ['executing','cancelled'],
        'executing' => ['completed','failed','cancelled'],
        'failed' => ['queued','cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

function agent_workflow_event_v1400(PDO $pdo, int $ownerUserId, int $runId, string $eventType, string $fromStatus, string $toStatus, string $actorKind, string $summary, array $metadata = []): void
{
    $stmt = $pdo->prepare('INSERT INTO agent_workflow_events (run_id,owner_user_id,event_type,from_status,to_status,actor_kind,summary,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $runId,$ownerUserId,agent_workflow_text_v1400($eventType,60),agent_workflow_text_v1400($fromStatus,32),
        agent_workflow_text_v1400($toStatus,32),agent_workflow_text_v1400($actorKind,32),agent_workflow_text_v1400($summary,500),
        $metadata ? agent_workflow_json_v1400($metadata) : null,
    ]);
}

function agent_workflow_row_v1400(PDO $pdo, int $ownerUserId, int $runId, bool $forUpdate = false): ?array
{
    if ($ownerUserId < 1 || $runId < 1 || !agent_workflow_schema_ready_v1400($pdo)) return null;
    $sql = 'SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);$stmt->execute([$runId,$ownerUserId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function agent_workflow_actions_v1400(PDO $pdo, int $ownerUserId, int $runId): array
{
    $stmt = $pdo->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id');
    $stmt->execute([$runId,$ownerUserId]);
    return $stmt->fetchAll() ?: [];
}

function agent_workflow_events_v1400(PDO $pdo, int $ownerUserId, int $runId, int $limit = 80): array
{
    $limit = max(1,min(200,$limit));
    $stmt = $pdo->prepare('SELECT * FROM agent_workflow_events WHERE run_id=? AND owner_user_id=? ORDER BY id DESC LIMIT '.$limit);
    $stmt->execute([$runId,$ownerUserId]);
    return $stmt->fetchAll() ?: [];
}

function agent_workflow_public_action_v1400(array $row): array
{
    return [
        'id'=>(int)($row['id']??0),'sequence_no'=>(int)($row['sequence_no']??0),
        'action_key'=>(string)($row['action_key']??''),'action_type'=>(string)($row['action_type']??''),
        'label'=>(string)($row['label']??''),'summary'=>(string)($row['summary']??''),'status'=>(string)($row['status']??''),
        'requires_approval'=>!empty($row['requires_approval']),'execution_target'=>(string)($row['execution_target']??'cloud'),
        'capability_key'=>(string)($row['capability_key']??''),'attempt_count'=>(int)($row['attempt_count']??0),
        'result_summary'=>(string)($row['result_summary']??''),'result'=>agent_workflow_public_json_v1400((string)($row['result_json']??'')),
        'error_class'=>(string)($row['error_class']??''),'started_at'=>(string)($row['started_at']??''),
        'completed_at'=>(string)($row['completed_at']??''),'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function agent_workflow_public_event_v1400(array $row): array
{
    return [
        'id'=>(int)($row['id']??0),'event_type'=>(string)($row['event_type']??''),'from_status'=>(string)($row['from_status']??''),
        'to_status'=>(string)($row['to_status']??''),'actor_kind'=>(string)($row['actor_kind']??''),'summary'=>(string)($row['summary']??''),
        'metadata'=>agent_workflow_public_json_v1400((string)($row['metadata_json']??'')),'created_at'=>(string)($row['created_at']??''),
    ];
}

function agent_workflow_public_run_v1400(PDO $pdo, array $row, bool $includeHistory = false): array
{
    $ownerUserId=(int)($row['owner_user_id']??0);$runId=(int)($row['id']??0);
    $out=[
        'id'=>$runId,'workflow_type'=>(string)($row['workflow_type']??''),'origin'=>(string)($row['origin']??''),
        'source_kind'=>(string)($row['source_kind']??''),'source_key'=>(string)($row['source_key']??''),'title'=>(string)($row['title']??''),
        'goal'=>(string)($row['goal']??''),'decision_summary'=>(string)($row['decision_summary']??''),'status'=>(string)($row['status']??''),
        'risk_level'=>(string)($row['risk_level']??'low'),'requires_approval'=>!empty($row['requires_approval']),
        'approval_status'=>(string)($row['approval_status']??'not_required'),'execution_target'=>(string)($row['execution_target']??'cloud'),
        'capability_key'=>(string)($row['capability_key']??''),'current_action_id'=>(int)($row['current_action_id']??0),
        'attempt_count'=>(int)($row['attempt_count']??0),'last_error_class'=>(string)($row['last_error_class']??''),
        'started_at'=>(string)($row['started_at']??''),'approved_at'=>(string)($row['approved_at']??''),
        'completed_at'=>(string)($row['completed_at']??''),'cancelled_at'=>(string)($row['cancelled_at']??''),
        'created_at'=>(string)($row['created_at']??''),'updated_at'=>(string)($row['updated_at']??''),
    ];
    if ($includeHistory && $ownerUserId > 0 && $runId > 0) {
        $out['actions']=array_map('agent_workflow_public_action_v1400',agent_workflow_actions_v1400($pdo,$ownerUserId,$runId));
        $out['events']=array_map('agent_workflow_public_event_v1400',agent_workflow_events_v1400($pdo,$ownerUserId,$runId));
    }
    return $out;
}

function agent_workflow_recent_v1400(PDO $pdo, array $user, int $limit = 20): array
{
    $ownerUserId=(int)($user['id']??0);$limit=max(1,min(VP3_AGENT_WORKFLOW_RUN_LIMIT_V1400,$limit));
    if($ownerUserId<1||!agent_workflow_schema_ready_v1400($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? ORDER BY updated_at DESC,id DESC LIMIT '.$limit);
    $stmt->execute([$ownerUserId]);
    return $stmt->fetchAll() ?: [];
}

function agent_workflow_active_summary_v1400(PDO $pdo, array $user, int $limit = 6): array
{
    $ownerUserId=(int)($user['id']??0);$limit=max(1,min(12,$limit));
    if($ownerUserId<1||!agent_workflow_schema_ready_v1400($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND status NOT IN ('completed','cancelled') ORDER BY updated_at DESC,id DESC LIMIT ".$limit);
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll() ?: [] as $row)$out[]=agent_workflow_public_run_v1400($pdo,$row,false);
    return $out;
}

function agent_workflow_find_brain_priority_v1400(array $user, string $key, string $hash = ''): ?array
{
    $key=trim($key);$hash=trim($hash);
    if($key===''||!function_exists('agent_cognitive_loop_v310_state'))return null;
    $state=agent_cognitive_loop_v310_state($user);
    foreach((array)($state['priorities']??[]) as $priority){
        if(!is_array($priority))continue;
        if((string)($priority['key']??'')!==$key)continue;
        if($hash!==''&&(string)($priority['suggestion_hash']??'')!==$hash)continue;
        return $priority;
    }
    return null;
}

function agent_workflow_type_v1400(array $priority): string
{
    $source=(string)($priority['source']??'');$title=mb_strtolower((string)($priority['title']??''));
    if($source==='calendar_conflict')return 'calendar_conflict_resolution';
    if($source==='calendar_upcoming')return 'calendar_commitment_prep';
    if(str_contains($title,'follow-up')||str_contains($title,'follow up'))return 'post_meeting_followup';
    if(str_contains($source,'commerce'))return 'commerce_followup';
    return 'agent_next_action';
}

function agent_workflow_capability_v1400(string $workflowType): string
{
    return match($workflowType){
        'calendar_conflict_resolution'=>'calendar.review_conflict',
        'calendar_commitment_prep'=>'calendar.prepare_commitment',
        'post_meeting_followup'=>'scheduling.prepare_followup',
        'commerce_followup'=>'commerce.review_next_action',
        default=>'agent.next_action',
    };
}

function agent_workflow_execution_target_v1400(array $priority): string
{
    $source=mb_strtolower((string)($priority['source']??''));
    return str_starts_with($source,'homeserver_')||str_contains($source,'local_private')?'homeserver':'cloud';
}

function agent_workflow_create_from_priority_v1400(PDO $pdo, array $user, array $priority, ?int $agentId = null): array
{
    if(!agent_workflow_schema_ready_v1400($pdo))throw new RuntimeException('Agent Workflows are not ready. An administrator needs to run the database upgrade.');
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1)throw new RuntimeException('A signed-in account is required.');
    $key=agent_workflow_text_v1400($priority['key']??'',190);$hash=agent_workflow_text_v1400($priority['suggestion_hash']??$priority['hash']??'',40);
    $title=agent_workflow_text_v1400($priority['title']??'',190);if($key===''||$title==='')throw new RuntimeException('This Brain priority cannot start a workflow.');
    $source=agent_workflow_text_v1400($priority['source']??'agent_brain',100);
    $workflowType=agent_workflow_type_v1400($priority);$capability=agent_workflow_capability_v1400($workflowType);$target=agent_workflow_execution_target_v1400($priority);
    $dedupe=hash('sha256','brain|'.$ownerUserId.'|'.$key.'|'.$hash);
    $goal=agent_workflow_text_v1400($priority['prompt']??$title,2000);$decision=agent_workflow_text_v1400($priority['reason']??$title,1500);

    $action=[
        'source'=>$source,'title'=>$title,'prompt'=>$goal,'score'=>(float)($priority['score']??0.5),
        'hash'=>$hash!==''?$hash:sha1($source.'|'.$key),'action_id'=>(string)($priority['action_id']??'workflow-action-'.sha1($key)),
    ];
    $event=['id'=>(string)($priority['event_id']??'workflow-event-'.sha1($key))];
    $plan=function_exists('agent_action_v124_plan')?agent_action_v124_plan($action,$event,[]):[
        'risk'=>['level'=>(string)($priority['risk_level']??'low'),'requires_approval'=>!empty($priority['requires_approval'])],
        'requires_approval'=>!empty($priority['requires_approval']),
        'steps'=>[['id'=>'execute','kind'=>'execute','label'=>'Execute next action','instruction'=>$goal,'requires_approval'=>!empty($priority['requires_approval'])]],
    ];
    $risk=is_array($plan['risk']??null)?$plan['risk']:[];$requiresApproval=!empty($plan['requires_approval'])||!empty($risk['requires_approval']);
    $riskLevel=in_array((string)($risk['level']??''),['low','medium','high'],true)?(string)$risk['level']:'low';

    try{
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("INSERT INTO agent_workflow_runs (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,'queued',?,?,?,?,?)");
        $stmt->execute([$ownerUserId,$agentId&&$agentId>0?$agentId:null,$workflowType,'brain',$source,$key,$hash,$dedupe,$title,$goal,$decision,$riskLevel,$requiresApproval?1:0,$requiresApproval?'pending':'not_required',$target,$capability]);
        $runId=(int)$pdo->lastInsertId();
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'created','', 'queued','agent','Workflow created from canonical Agent Brain priority.',['source'=>$source,'key'=>$key]);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='planning' WHERE id=? AND owner_user_id=?")->execute([$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'planned','queued','planning','system','Canonical v124 action plan attached to workflow.',['workflow_type'=>$workflowType]);

        $steps=array_values(array_filter((array)($plan['steps']??[]),'is_array'));if(!$steps)$steps=[['id'=>'execute','kind'=>'execute','label'=>'Execute next action','instruction'=>$goal,'requires_approval'=>$requiresApproval]];
        $insert=$pdo->prepare('INSERT INTO agent_workflow_actions (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach($steps as $index=>$step){
            $stepRequires=!empty($step['requires_approval']);$kind=agent_workflow_text_v1400($step['kind']??'execute',40);
            $stepTarget=$kind==='execute'?$target:'cloud';$stepCapability=$kind==='execute'?$capability:'';
            $insert->execute([$runId,$ownerUserId,$index+1,agent_workflow_text_v1400($step['id']??('step-'.($index+1)),120),$kind,agent_workflow_text_v1400($step['label']??'Workflow action',190),agent_workflow_text_v1400($step['instruction']??'',1500),$stepRequires?'approval_pending':'queued',$stepRequires?1:0,$stepTarget,$stepCapability]);
        }
        $final=$requiresApproval?'approval_pending':'approved';
        $pdo->prepare('UPDATE agent_workflow_runs SET status=?,approval_status=? WHERE id=? AND owner_user_id=?')->execute([$final,$requiresApproval?'pending':'not_required',$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,$requiresApproval?'approval_requested':'approval_not_required','planning',$final,'system',$requiresApproval?'Workflow is waiting for explicit approval.':'Workflow plan is ready; no approval is required.',['risk_level'=>$riskLevel]);
        $pdo->commit();
        $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);if(!$row)throw new RuntimeException('Workflow could not be loaded.');
        return agent_workflow_public_run_v1400($pdo,$row,true);
    }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if((string)$e->getCode()==='23000'){
            $stmt=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=? LIMIT 1');$stmt->execute([$ownerUserId,$dedupe]);$existing=$stmt->fetch();
            if(is_array($existing))return agent_workflow_public_run_v1400($pdo,$existing,true);
        }
        throw $e;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function agent_workflow_approve_v1400(PDO $pdo, array $user, int $runId): array
{
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1)throw new RuntimeException('A signed-in account is required.');
    try{$pdo->beginTransaction();$row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId,true);if(!$row)throw new RuntimeException('Workflow not found.');
        if((string)$row['status']!=='approval_pending')throw new RuntimeException('This workflow is not waiting for approval.');
        $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',approval_status='approved',approved_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='approval_pending'")->execute([$runId,$ownerUserId]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='queued' WHERE run_id=? AND owner_user_id=? AND status='approval_pending'")->execute([$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'approved','approval_pending','approved','user','User explicitly approved the workflow plan.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);return agent_workflow_public_run_v1400($pdo,$row?:[],true);
}

function agent_workflow_cancel_v1400(PDO $pdo, array $user, int $runId, string $actorKind = 'user'): array
{
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1)throw new RuntimeException('A signed-in account is required.');
    try{$pdo->beginTransaction();$row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId,true);if(!$row)throw new RuntimeException('Workflow not found.');$from=(string)$row['status'];
        if(in_array($from,['completed','cancelled'],true))throw new RuntimeException('This workflow is already closed.');
        $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',approval_status=IF(approval_status='pending','rejected',approval_status),cancelled_at=UTC_TIMESTAMP(),current_action_id=NULL WHERE id=? AND owner_user_id=?")->execute([$runId,$ownerUserId]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=COALESCE(completed_at,UTC_TIMESTAMP()) WHERE run_id=? AND owner_user_id=? AND status NOT IN ('completed','cancelled','failed')")->execute([$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'cancelled',$from,'cancelled',$actorKind,'Workflow cancelled before further execution.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);return agent_workflow_public_run_v1400($pdo,$row?:[],true);
}

function agent_workflow_retry_v1400(PDO $pdo, array $user, int $runId): array
{
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1)throw new RuntimeException('A signed-in account is required.');
    try{$pdo->beginTransaction();$row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId,true);if(!$row)throw new RuntimeException('Workflow not found.');
        if((string)$row['status']!=='failed')throw new RuntimeException('Only a failed workflow can be retried.');
        $pdo->prepare("UPDATE agent_workflow_runs SET status='queued',attempt_count=attempt_count+1,last_error_class='',current_action_id=NULL,started_at=NULL,completed_at=NULL WHERE id=? AND owner_user_id=?")->execute([$runId,$ownerUserId]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status=CASE WHEN requires_approval=1 AND ?='pending' THEN 'approval_pending' ELSE 'queued' END,error_class='',started_at=NULL,completed_at=NULL WHERE run_id=? AND owner_user_id=? AND status='failed'")->execute([(string)$row['approval_status'],$runId,$ownerUserId]);
        $next=(string)$row['approval_status']==='pending'?'approval_pending':'approved';
        $pdo->prepare('UPDATE agent_workflow_runs SET status=? WHERE id=? AND owner_user_id=?')->execute([$next,$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'retry_requested','failed',$next,'user','User requested a retry of the failed workflow.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);return agent_workflow_public_run_v1400($pdo,$row?:[],true);
}

/** Server-side executor hook. Browser APIs do not expose this transition. */
function agent_workflow_claim_next_action_v1400(PDO $pdo, array $user, int $runId, string $executor = 'cloud'): ?array
{
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1||!in_array($executor,['cloud','homeserver'],true))return null;
    try{$pdo->beginTransaction();$run=agent_workflow_row_v1400($pdo,$ownerUserId,$runId,true);if(!$run||!in_array((string)$run['status'],['approved','executing'],true)){if($pdo->inTransaction())$pdo->rollBack();return null;}
        $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status='queued' AND execution_target=? ORDER BY sequence_no,id LIMIT 1 FOR UPDATE");$stmt->execute([$runId,$ownerUserId,$executor]);$action=$stmt->fetch();
        if(!$action){$pdo->commit();return null;}
        $actionId=(int)$action['id'];$from=(string)$run['status'];
        $pdo->prepare("UPDATE agent_workflow_actions SET status='executing',attempt_count=attempt_count+1,started_at=COALESCE(started_at,UTC_TIMESTAMP()) WHERE id=? AND owner_user_id=? AND status='queued'")->execute([$actionId,$ownerUserId]);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='executing',current_action_id=?,attempt_count=attempt_count+IF(status='approved',1,0),started_at=COALESCE(started_at,UTC_TIMESTAMP()) WHERE id=? AND owner_user_id=?")->execute([$actionId,$runId,$ownerUserId]);
        if($from==='approved')agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'execution_started','approved','executing','executor','Workflow execution started.',['executor'=>$executor]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'action_started','executing','executing','executor','Workflow action started.',['action_id'=>$actionId,'executor'=>$executor]);$pdo->commit();
        $action['status']='executing';$action['attempt_count']=(int)$action['attempt_count']+1;return agent_workflow_public_action_v1400($action);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Server-side structured-result hook for Cloud or paired HomeServer executors. */
function agent_workflow_record_action_result_v1400(PDO $pdo, array $user, int $runId, int $actionId, bool $success, string $summary, array $result = [], string $errorClass = ''): array
{
    $ownerUserId=(int)($user['id']??0);if($ownerUserId<1)throw new RuntimeException('A signed-in account is required.');
    $summary=agent_workflow_text_v1400($summary,1500);$errorClass=agent_workflow_text_v1400($errorClass,80);
    try{$pdo->beginTransaction();$run=agent_workflow_row_v1400($pdo,$ownerUserId,$runId,true);if(!$run||!in_array((string)$run['status'],['executing','approved'],true))throw new RuntimeException('Workflow is not executing.');
        $stmt=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$actionId,$runId,$ownerUserId]);$action=$stmt->fetch();if(!$action||(string)$action['status']!=='executing')throw new RuntimeException('Workflow action is not executing.');
        $actionStatus=$success?'completed':'failed';
        $pdo->prepare('UPDATE agent_workflow_actions SET status=?,result_summary=?,result_json=?,error_class=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?')->execute([$actionStatus,$summary,$result?agent_workflow_json_v1400($result):null,$success?'':$errorClass,$actionId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,$success?'action_completed':'action_failed','executing','executing','executor',$summary!==''?$summary:($success?'Workflow action completed.':'Workflow action failed.'),['action_id'=>$actionId,'error_class'=>$success?'':$errorClass]);
        if(!$success){
            $pdo->prepare("UPDATE agent_workflow_runs SET status='failed',current_action_id=NULL,last_error_class=? WHERE id=? AND owner_user_id=?")->execute([$errorClass!==''?$errorClass:'execution_failed',$runId,$ownerUserId]);
            agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'failed','executing','failed','executor','Workflow stopped after an action failure.',['error_class'=>$errorClass]);
        }else{
            $pending=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','executing')");$pending->execute([$runId,$ownerUserId]);
            if((int)$pending->fetchColumn()===0){
                $pdo->prepare("UPDATE agent_workflow_runs SET status='completed',current_action_id=NULL,last_error_class='',completed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$runId,$ownerUserId]);
                agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'completed','executing','completed','executor','Workflow completed and all observable actions are closed.');
            }else{
                $pdo->prepare('UPDATE agent_workflow_runs SET current_action_id=NULL WHERE id=? AND owner_user_id=?')->execute([$runId,$ownerUserId]);
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);return agent_workflow_public_run_v1400($pdo,$row?:[],true);
}
