<?php
declare(strict_types=1);

/**
 * VP3 Phase 19.0 — Durable Agent Job Engine.
 * Extends Phase 14 workflow runs; Agent Brain remains the decision layer.
 */
const VP3_AGENT_JOB_ENGINE_V1900='agent-job-engine-v1900-20260914';
const VP3_AGENT_JOB_DEFAULT_MAX_ATTEMPTS_V1900=3;
const VP3_AGENT_JOB_DEFAULT_RETRY_SECONDS_V1900=60;
const VP3_AGENT_JOB_DEFAULT_TIMEOUT_SECONDS_V1900=900;
const VP3_AGENT_JOB_DEFAULT_LEASE_SECONDS_V1900=120;
require_once __DIR__.'/agent-workflow-runs-v1400.php';

function agent_job_engine_schema_ready_v1900(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo||!agent_workflow_schema_ready_v1400($pdo))return false;
    foreach(['next_attempt_at','lease_owner','lease_token','lease_expires_at','heartbeat_at','progress_percent','progress_message','max_attempts','retry_backoff_seconds','timeout_seconds'] as $c)if(!column_exists('agent_workflow_runs',$c))return false;
    foreach(['available_at','heartbeat_at','progress_percent','progress_message','max_attempts','timeout_seconds'] as $c)if(!column_exists('agent_workflow_actions',$c))return false;
    return table_exists('agent_workflow_action_dependencies')&&table_exists('agent_workflow_receipts');
}
function agent_job_engine_add_column_v1900(PDO $pdo,string $table,string $column,string $definition): void
{
    if(!column_exists($table,$column))$pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition);
}
function agent_job_engine_index_exists_v1900(PDO $pdo,string $table,string $index): bool
{
    $s=$pdo->prepare('SHOW INDEX FROM `'.$table.'` WHERE Key_name=?');$s->execute([$index]);return (bool)$s->fetch();
}
function agent_job_engine_ensure_schema_v1900(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');agent_workflow_ensure_schema_v1400($pdo);
    $runCols=[
        'next_attempt_at'=>'DATETIME NULL AFTER last_error_class','lease_owner'=>"VARCHAR(120) NOT NULL DEFAULT '' AFTER next_attempt_at",'lease_token'=>"CHAR(64) NOT NULL DEFAULT '' AFTER lease_owner",'lease_expires_at'=>'DATETIME NULL AFTER lease_token','heartbeat_at'=>'DATETIME NULL AFTER lease_expires_at','progress_percent'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER heartbeat_at','progress_message'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER progress_percent",'max_attempts'=>'INT UNSIGNED NOT NULL DEFAULT 3 AFTER progress_message','retry_backoff_seconds'=>'INT UNSIGNED NOT NULL DEFAULT 60 AFTER max_attempts','timeout_seconds'=>'INT UNSIGNED NOT NULL DEFAULT 900 AFTER retry_backoff_seconds'];
    foreach($runCols as $c=>$d)agent_job_engine_add_column_v1900($pdo,'agent_workflow_runs',$c,$d);
    $actionCols=['available_at'=>'DATETIME NULL AFTER error_class','heartbeat_at'=>'DATETIME NULL AFTER available_at','progress_percent'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER heartbeat_at','progress_message'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER progress_percent",'max_attempts'=>'INT UNSIGNED NOT NULL DEFAULT 3 AFTER progress_message','timeout_seconds'=>'INT UNSIGNED NOT NULL DEFAULT 900 AFTER max_attempts'];
    foreach($actionCols as $c=>$d)agent_job_engine_add_column_v1900($pdo,'agent_workflow_actions',$c,$d);
    if(!agent_job_engine_index_exists_v1900($pdo,'agent_workflow_runs','idx_agent_job_due'))$pdo->exec('CREATE INDEX idx_agent_job_due ON agent_workflow_runs (owner_user_id,status,next_attempt_at,lease_expires_at,id)');
    if(!agent_job_engine_index_exists_v1900($pdo,'agent_workflow_actions','idx_agent_job_action_due'))$pdo->exec('CREATE INDEX idx_agent_job_action_due ON agent_workflow_actions (run_id,status,sequence_no,available_at,id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_action_dependencies (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_id BIGINT UNSIGNED NOT NULL,owner_user_id INT UNSIGNED NOT NULL,action_id BIGINT UNSIGNED NOT NULL,depends_on_action_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_agent_job_dependency (action_id,depends_on_action_id),INDEX idx_agent_job_dependency_run (run_id,action_id),CONSTRAINT fk_agent_job_dependency_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,CONSTRAINT fk_agent_job_dependency_action FOREIGN KEY (action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_job_dependency_on_action FOREIGN KEY (depends_on_action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_job_dependency_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_workflow_receipts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_id BIGINT UNSIGNED NOT NULL,action_id BIGINT UNSIGNED NOT NULL,owner_user_id INT UNSIGNED NOT NULL,receipt_key CHAR(64) NOT NULL,executor VARCHAR(24) NOT NULL DEFAULT 'cloud',worker_id VARCHAR(120) NOT NULL DEFAULT '',attempt_no INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(24) NOT NULL DEFAULT 'completed',summary VARCHAR(500) NOT NULL DEFAULT '',result_json MEDIUMTEXT NULL,error_class VARCHAR(80) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_agent_job_owner_receipt (owner_user_id,receipt_key),INDEX idx_agent_job_receipt_run (run_id,action_id,id),CONSTRAINT fk_agent_job_receipt_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,CONSTRAINT fk_agent_job_receipt_action FOREIGN KEY (action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_job_receipt_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function agent_job_retry_delay_v1900(int $attempt,int $baseSeconds): int{return min(3600,max(5,$baseSeconds)*(2**min(5,max(0,$attempt-1))));}
function agent_job_release_sql_v1900(): string{return "lease_owner='',lease_token='',lease_expires_at=NULL,heartbeat_at=NULL,current_action_id=NULL";}

function agent_job_public_run_v1900(PDO $pdo,array $row,bool $history=false): array
{
    $out=agent_workflow_public_run_v1400($pdo,$row,$history);$out['job_engine_build']=VP3_AGENT_JOB_ENGINE_V1900;
    foreach(['next_attempt_at','heartbeat_at','lease_expires_at','progress_message'] as $k)$out[$k]=(string)($row[$k]??'');
    $out['progress_percent']=max(0,min(100,(int)($row['progress_percent']??0)));$out['max_attempts']=max(1,(int)($row['max_attempts']??3));$out['retry_backoff_seconds']=max(5,(int)($row['retry_backoff_seconds']??60));$out['timeout_seconds']=max(30,(int)($row['timeout_seconds']??900));
    if($history&&table_exists('agent_workflow_receipts')){$s=$pdo->prepare('SELECT id,action_id,executor,worker_id,attempt_no,status,summary,error_class,created_at FROM agent_workflow_receipts WHERE run_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 100');$s->execute([(int)($row['id']??0),(int)($row['owner_user_id']??0)]);$out['receipts']=$s->fetchAll()?:[];}
    return $out;
}
function agent_job_attach_dependencies_v1900(PDO $pdo,array $user,int $runId,array $priority): void
{
    $uid=(int)($user['id']??0);if($uid<1||$runId<1)return;$actions=agent_workflow_actions_v1400($pdo,$uid,$runId);$byKey=[];foreach($actions as $a)$byKey[(string)($a['action_key']??'')]=(int)$a['id'];
    $plan=is_array($priority['plan']??null)?$priority['plan']:[];$insert=$pdo->prepare('INSERT IGNORE INTO agent_workflow_action_dependencies (run_id,owner_user_id,action_id,depends_on_action_id) VALUES (?,?,?,?)');
    foreach((array)($plan['steps']??[]) as $step){if(!is_array($step))continue;$aid=(int)($byKey[(string)($step['id']??'')]??0);if($aid<1)continue;foreach((array)($step['depends_on']??[]) as $key){$dep=(int)($byKey[(string)$key]??0);if($dep>0&&$dep!==$aid)$insert->execute([$runId,$uid,$aid,$dep]);}}
}
function agent_job_enqueue_from_brain_v1900(PDO $pdo,array $user,array $priority,?int $agentId=null): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))throw new RuntimeException('Durable Agent Jobs are not ready. An administrator needs to run the Phase 19 upgrade.');$uid=(int)($user['id']??0);
    $run=agent_workflow_create_from_priority_v1400($pdo,$user,$priority,$agentId);$runId=(int)($run['id']??0);if($runId<1)return $run;
    $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=COALESCE(next_attempt_at,UTC_TIMESTAMP()),max_attempts=GREATEST(max_attempts,3),retry_backoff_seconds=GREATEST(retry_backoff_seconds,5),timeout_seconds=GREATEST(timeout_seconds,30) WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
    $pdo->prepare("UPDATE agent_workflow_actions SET available_at=COALESCE(available_at,UTC_TIMESTAMP()),max_attempts=GREATEST(max_attempts,3),timeout_seconds=GREATEST(timeout_seconds,30) WHERE run_id=? AND owner_user_id=?")->execute([$runId,$uid]);agent_job_attach_dependencies_v1900($pdo,$user,$runId,$priority);
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);if($row)agent_job_brain_memory_v1900($user,$row,'queued');return $row?agent_job_public_run_v1900($pdo,$row,true):$run;
}
function agent_job_dependencies_satisfied_v1900(PDO $pdo,int $uid,int $actionId): bool
{
    $s=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_action_dependencies d JOIN agent_workflow_actions a ON a.id=d.depends_on_action_id WHERE d.owner_user_id=? AND d.action_id=? AND a.status<>'completed'");$s->execute([$uid,$actionId]);return (int)$s->fetchColumn()===0;
}

/** Server-only claimant. Global sequence is locked before runtime affinity is checked. */
function agent_job_claim_run_v1900(PDO $pdo,array $user,int $runId,string $executor,string $workerId,int $leaseSeconds=VP3_AGENT_JOB_DEFAULT_LEASE_SECONDS_V1900): ?array
{
    $uid=(int)($user['id']??0);$executor=strtolower(trim($executor));$workerId=agent_workflow_text_v1400($workerId,120);if($uid<1||$runId<1||!in_array($executor,['cloud','homeserver'],true)||$workerId===''||!agent_job_engine_schema_ready_v1900($pdo))return null;$leaseSeconds=max(30,min(900,$leaseSeconds));
    try{$pdo->beginTransaction();$run=agent_workflow_row_v1400($pdo,$uid,$runId,true);if(!$run||!in_array((string)$run['status'],['approved','executing'],true)||(int)($run['current_action_id']??0)>0){$pdo->rollBack();return null;}
        if((strtotime((string)($run['lease_expires_at']??''))?:0)>time()||(strtotime((string)($run['next_attempt_at']??''))?:0)>time()){$pdo->rollBack();return null;}
        $s=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status='queued' ORDER BY sequence_no,id LIMIT 1 FOR UPDATE");$s->execute([$runId,$uid]);$action=$s->fetch();if(!$action){$pdo->rollBack();return null;}
        if((string)($action['execution_target']??'cloud')!==$executor){$pdo->rollBack();return null;}if((strtotime((string)($action['available_at']??''))?:0)>time()||!agent_job_dependencies_satisfied_v1900($pdo,$uid,(int)$action['id'])){$pdo->rollBack();return null;}
        $actionId=(int)$action['id'];$effectiveLease=min($leaseSeconds,max(30,(int)($action['timeout_seconds']??$run['timeout_seconds']??900)));$leaseId=hash('sha256',random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+$effectiveLease);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='executing',attempt_count=attempt_count+1,started_at=COALESCE(started_at,UTC_TIMESTAMP()),heartbeat_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='' WHERE id=? AND owner_user_id=? AND status='queued'")->execute([$actionId,$uid]);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='executing',current_action_id=?,attempt_count=attempt_count+1,started_at=COALESCE(started_at,UTC_TIMESTAMP()),lease_owner=?,lease_token=?,lease_expires_at=?,heartbeat_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Execution started' WHERE id=? AND owner_user_id=?")->execute([$actionId,$workerId,$leaseId,$expires,$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'job_claimed',(string)$run['status'],'executing','executor','Durable job lease claimed.',['action_id'=>$actionId,'executor'=>$executor,'worker_id'=>$workerId,'lease_seconds'=>$effectiveLease]);$pdo->commit();$action['status']='executing';$action['attempt_count']=(int)$action['attempt_count']+1;
        return ['run_id'=>$runId,'action'=>agent_workflow_public_action_v1400($action),'lease_token'=>$leaseId,'lease_expires_at'=>$expires,'worker_id'=>$workerId,'executor'=>$executor,'build'=>VP3_AGENT_JOB_ENGINE_V1900];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function agent_job_claim_next_v1900(PDO $pdo,array $user,string $executor,string $workerId,int $limit=25): ?array
{
    $uid=(int)($user['id']??0);if($uid<1||!agent_job_engine_schema_ready_v1900($pdo))return null;$limit=max(1,min(100,$limit));$s=$pdo->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND status IN ('approved','executing') AND current_action_id IS NULL AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP()) ORDER BY COALESCE(next_attempt_at,created_at),id LIMIT ".$limit);$s->execute([$uid]);foreach($s->fetchAll()?:[] as $r){$claim=agent_job_claim_run_v1900($pdo,$user,(int)$r['id'],$executor,$workerId);if($claim)return $claim;}return null;
}
function agent_job_heartbeat_v1900(PDO $pdo,array $user,int $runId,int $actionId,string $leaseToken,int $progress,string $message='',int $leaseSeconds=120): bool
{
    $uid=(int)($user['id']??0);$progress=max(0,min(99,$progress));$message=agent_workflow_text_v1400($message,500);if($uid<1||$runId<1||$actionId<1||!preg_match('/^[a-f0-9]{64}$/',$leaseToken))return false;$leaseSeconds=max(30,min(900,$leaseSeconds));$expires=gmdate('Y-m-d H:i:s',time()+$leaseSeconds);
    $s=$pdo->prepare("UPDATE agent_workflow_runs SET heartbeat_at=UTC_TIMESTAMP(),lease_expires_at=?,progress_percent=?,progress_message=? WHERE id=? AND owner_user_id=? AND status='executing' AND current_action_id=? AND lease_token=? AND lease_expires_at>=UTC_TIMESTAMP()");$s->execute([$expires,$progress,$message,$runId,$uid,$actionId,$leaseToken]);if($s->rowCount()!==1)return false;$pdo->prepare("UPDATE agent_workflow_actions SET heartbeat_at=UTC_TIMESTAMP(),progress_percent=?,progress_message=? WHERE id=? AND run_id=? AND owner_user_id=? AND status='executing'")->execute([$progress,$message,$actionId,$runId,$uid]);return true;
}
function agent_job_receipt_key_v1900(int $uid,int $runId,int $actionId,string $key): string
{
    $key=trim($key);if($key==='')throw new RuntimeException('A result idempotency key is required.');return hash('sha256',$uid.'|'.$runId.'|'.$actionId.'|'.$key);
}

/** Server-only receipt-backed result commit. */
function agent_job_record_result_v1900(PDO $pdo,array $user,int $runId,int $actionId,string $leaseToken,string $idempotencyKey,bool $success,string $summary,array $result=[],string $errorClass='',bool $retryable=true): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');$receiptKey=agent_job_receipt_key_v1900($uid,$runId,$actionId,$idempotencyKey);$summary=agent_workflow_text_v1400($summary,500);$errorClass=agent_workflow_text_v1400($errorClass,80);$terminal='';$receiptId=0;
    try{$pdo->beginTransaction();$e=$pdo->prepare('SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? AND receipt_key=? LIMIT 1 FOR UPDATE');$e->execute([$uid,$receiptKey]);$receipt=$e->fetch();if(is_array($receipt)){$pdo->commit();$row=agent_workflow_row_v1400($pdo,$uid,$runId);return ['duplicate'=>true,'receipt_id'=>(int)$receipt['id'],'run'=>$row?agent_job_public_run_v1900($pdo,$row,true):null,'build'=>VP3_AGENT_JOB_ENGINE_V1900];}
        $run=agent_workflow_row_v1400($pdo,$uid,$runId,true);if(!$run||(string)$run['status']!=='executing'||(int)($run['current_action_id']??0)!==$actionId)throw new RuntimeException('Durable job action is not the active execution.');if(!hash_equals((string)($run['lease_token']??''),$leaseToken))throw new RuntimeException('Durable job lease is invalid.');if((strtotime((string)($run['lease_expires_at']??''))?:0)<time())throw new RuntimeException('Durable job lease expired before result commit.');
        $a=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$a->execute([$actionId,$runId,$uid]);$action=$a->fetch();if(!$action||(string)$action['status']!=='executing')throw new RuntimeException('Durable job action is not executing.');$safe=$result?agent_workflow_public_json_v1400(agent_workflow_json_v1400($result)):[];$attempt=(int)($action['attempt_count']??1);$max=max(1,(int)($action['max_attempts']??$run['max_attempts']??3));$receiptStatus=$success?'completed':(($retryable&&$attempt<$max)?'retry_scheduled':'failed');
        $pdo->prepare('INSERT INTO agent_workflow_receipts (run_id,action_id,owner_user_id,receipt_key,executor,worker_id,attempt_no,status,summary,result_json,error_class) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$runId,$actionId,$uid,$receiptKey,(string)($action['execution_target']??'cloud'),(string)($run['lease_owner']??''),$attempt,$receiptStatus,$summary,$safe?agent_workflow_json_v1400($safe):null,$success?'':$errorClass]);$receiptId=(int)$pdo->lastInsertId();
        if($success){$pdo->prepare("UPDATE agent_workflow_actions SET status='completed',result_summary=?,result_json=?,error_class='',completed_at=UTC_TIMESTAMP(),heartbeat_at=UTC_TIMESTAMP(),progress_percent=100,progress_message='Completed' WHERE id=? AND owner_user_id=?")->execute([$summary,$safe?agent_workflow_json_v1400($safe):null,$actionId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'action_completed','executing','executing','executor',$summary!==''?$summary:'Workflow action completed.',['action_id'=>$actionId,'receipt_id'=>$receiptId]);$p=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','executing')");$p->execute([$runId,$uid]);
            if((int)$p->fetchColumn()===0){$pdo->prepare("UPDATE agent_workflow_runs SET status='completed',".agent_job_release_sql_v1900().",last_error_class='',next_attempt_at=NULL,progress_percent=100,progress_message='Completed',completed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'completed','executing','completed','executor','Durable workflow completed with receipt-backed execution.',['receipt_id'=>$receiptId]);$terminal='completed';}
            else{$pdo->prepare("UPDATE agent_workflow_runs SET status='approved',".agent_job_release_sql_v1900().",next_attempt_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Ready for next action' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);}}
        else{$errorClass=$errorClass!==''?$errorClass:'execution_failed';if($retryable&&$attempt<$max){$delay=agent_job_retry_delay_v1900($attempt,(int)($run['retry_backoff_seconds']??60));$when=gmdate('Y-m-d H:i:s',time()+$delay);$pdo->prepare("UPDATE agent_workflow_actions SET status='queued',result_summary=?,result_json=?,error_class=?,available_at=?,heartbeat_at=NULL,progress_percent=0,progress_message='Retry scheduled' WHERE id=? AND owner_user_id=?")->execute([$summary,$safe?agent_workflow_json_v1400($safe):null,$errorClass,$when,$actionId,$uid]);$pdo->prepare("UPDATE agent_workflow_runs SET status='approved',".agent_job_release_sql_v1900().",last_error_class=?,next_attempt_at=?,progress_percent=0,progress_message='Retry scheduled' WHERE id=? AND owner_user_id=?")->execute([$errorClass,$when,$runId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'retry_scheduled','executing','approved','executor','Action failed and was scheduled for bounded retry.',['action_id'=>$actionId,'receipt_id'=>$receiptId,'attempt'=>$attempt,'max_attempts'=>$max,'retry_at'=>$when,'error_class'=>$errorClass]);}
            else{$pdo->prepare("UPDATE agent_workflow_actions SET status='failed',result_summary=?,result_json=?,error_class=?,completed_at=UTC_TIMESTAMP(),heartbeat_at=UTC_TIMESTAMP(),progress_message='Failed' WHERE id=? AND owner_user_id=?")->execute([$summary,$safe?agent_workflow_json_v1400($safe):null,$errorClass,$actionId,$uid]);$pdo->prepare("UPDATE agent_workflow_runs SET status='failed',".agent_job_release_sql_v1900().",last_error_class=?,next_attempt_at=NULL,progress_message='Failed' WHERE id=? AND owner_user_id=?")->execute([$errorClass,$runId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'failed','executing','failed','executor','Durable workflow exhausted execution attempts or received a terminal failure.',['action_id'=>$actionId,'receipt_id'=>$receiptId,'attempt'=>$attempt,'max_attempts'=>$max,'error_class'=>$errorClass]);$terminal='failed';}}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);if($row){agent_job_brain_memory_v1900($user,$row,$terminal!==''?$terminal:'progress');if($terminal!=='')agent_job_brain_outcome_v1900($user,$row,$terminal);}return ['duplicate'=>false,'receipt_id'=>$receiptId,'run'=>$row?agent_job_public_run_v1900($pdo,$row,true):null,'build'=>VP3_AGENT_JOB_ENGINE_V1900];
}

function agent_job_recover_expired_v1900(PDO $pdo,array $user,int $limit=25): array
{
    $uid=(int)($user['id']??0);if($uid<1||!agent_job_engine_schema_ready_v1900($pdo))return ['recovered'=>0,'failed'=>0];$limit=max(1,min(100,$limit));$s=$pdo->prepare("SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND status='executing' AND lease_expires_at IS NOT NULL AND lease_expires_at<UTC_TIMESTAMP() ORDER BY lease_expires_at,id LIMIT ".$limit);$s->execute([$uid]);$recovered=0;$failed=0;
    foreach($s->fetchAll()?:[] as $item){$runId=(int)$item['id'];$terminal=false;try{$pdo->beginTransaction();$run=agent_workflow_row_v1400($pdo,$uid,$runId,true);if(!$run||(string)$run['status']!=='executing'||(strtotime((string)($run['lease_expires_at']??''))?:0)>=time()){$pdo->rollBack();continue;}$actionId=(int)($run['current_action_id']??0);$q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$q->execute([$actionId,$runId,$uid]);$action=$q->fetch();if(!$action){$pdo->rollBack();continue;}$attempt=(int)($action['attempt_count']??1);$max=max(1,(int)($action['max_attempts']??$run['max_attempts']??3));
        if($attempt<$max){$when=gmdate('Y-m-d H:i:s',time()+agent_job_retry_delay_v1900($attempt,(int)($run['retry_backoff_seconds']??60)));$pdo->prepare("UPDATE agent_workflow_actions SET status='queued',available_at=?,heartbeat_at=NULL,error_class='lease_expired',progress_percent=0,progress_message='Recovered after expired lease' WHERE id=? AND owner_user_id=?")->execute([$when,$actionId,$uid]);$pdo->prepare("UPDATE agent_workflow_runs SET status='approved',".agent_job_release_sql_v1900().",last_error_class='lease_expired',next_attempt_at=?,progress_percent=0,progress_message='Recovered after expired lease' WHERE id=? AND owner_user_id=?")->execute([$when,$runId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'lease_recovered','executing','approved','system','Expired execution lease recovered and retry scheduled.',['action_id'=>$actionId,'retry_at'=>$when]);$recovered++;}
        else{$pdo->prepare("UPDATE agent_workflow_actions SET status='failed',error_class='lease_expired',completed_at=UTC_TIMESTAMP(),progress_message='Lease expired' WHERE id=? AND owner_user_id=?")->execute([$actionId,$uid]);$pdo->prepare("UPDATE agent_workflow_runs SET status='failed',".agent_job_release_sql_v1900().",last_error_class='lease_expired',next_attempt_at=NULL,progress_message='Lease expired' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);agent_workflow_event_v1400($pdo,$uid,$runId,'failed','executing','failed','system','Execution lease expired after the maximum attempt count.',['action_id'=>$actionId]);$failed++;$terminal=true;}$pdo->commit();$row=agent_workflow_row_v1400($pdo,$uid,$runId);if($row){agent_job_brain_memory_v1900($user,$row,$terminal?'failed':'recovered');if($terminal)agent_job_brain_outcome_v1900($user,$row,'failed');}}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}}
    return ['recovered'=>$recovered,'failed'=>$failed,'build'=>VP3_AGENT_JOB_ENGINE_V1900];
}
function agent_job_cancel_v1900(PDO $pdo,array $user,int $runId,string $actorKind='user'): array
{
    $run=agent_workflow_cancel_v1400($pdo,$user,$runId,$actorKind);$uid=(int)($user['id']??0);if($uid>0)$pdo->prepare("UPDATE agent_workflow_runs SET ".agent_job_release_sql_v1900().",next_attempt_at=NULL,progress_message='Cancelled' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);$row=agent_workflow_row_v1400($pdo,$uid,$runId);if($row){agent_job_brain_memory_v1900($user,$row,'cancelled');agent_job_brain_outcome_v1900($user,$row,'cancelled');return agent_job_public_run_v1900($pdo,$row,true);}return $run;
}
function agent_job_retry_v1900(PDO $pdo,array $user,int $runId): array
{
    $run=agent_workflow_retry_v1400($pdo,$user,$runId);$uid=(int)($user['id']??0);if($uid>0){$pdo->prepare("UPDATE agent_workflow_runs SET ".agent_job_release_sql_v1900().",next_attempt_at=UTC_TIMESTAMP(),progress_percent=0,progress_message='Retry requested' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);$pdo->prepare("UPDATE agent_workflow_actions SET available_at=UTC_TIMESTAMP(),heartbeat_at=NULL,progress_percent=0,progress_message='' WHERE run_id=? AND owner_user_id=? AND status='queued'")->execute([$runId,$uid]);}$row=agent_workflow_row_v1400($pdo,$uid,$runId);if($row){agent_job_brain_memory_v1900($user,$row,'retry');return agent_job_public_run_v1900($pdo,$row,true);}return $run;
}

function agent_job_brain_outcome_v1900(array $user,array $run,string $terminal): void
{
    if(!function_exists('agent_action_v124_record_outcome'))return;$hash=trim((string)($run['source_hash']??''));if(!preg_match('/^[a-f0-9]{40}$/',$hash))return;$outcome=match($terminal){'completed'=>'successful','failed'=>'unsuccessful','cancelled'=>'ignored',default=>''};if($outcome==='')return;
    try{agent_action_v124_record_outcome($user,$hash,$outcome,'job_engine',['outcome'=>$outcome,'source'=>(string)($run['source_kind']??'agent_brain'),'context'=>['run_id'=>(int)($run['id']??0),'workflow_type'=>(string)($run['workflow_type']??''),'status'=>(string)($run['status']??''),'build'=>VP3_AGENT_JOB_ENGINE_V1900]]);}catch(Throwable $e){}
}
function agent_job_brain_memory_v1900(array $user,array $run,string $event='progress'): void
{
    if(!function_exists('agent_brain_v122_upsert_system_memory'))return;$runId=(int)($run['id']??0);if($runId<1)return;$status=(string)($run['status']??'');$title=agent_workflow_text_v1400($run['title']??'Agent job',190);$progress=max(0,min(100,(int)($run['progress_percent']??0)));$message=agent_workflow_text_v1400($run['progress_message']??'',300);$text='Durable Agent job #'.$runId.' “'.$title.'” is '.$status.'. Progress '.$progress.'%.'.($message!==''?' '.$message:'');$metadata=['run_id'=>$runId,'title'=>$title,'status'=>$status,'event'=>$event,'progress_percent'=>$progress,'execution_target'=>(string)($run['execution_target']??'cloud'),'attempt_count'=>(int)($run['attempt_count']??0),'last_error_class'=>(string)($run['last_error_class']??''),'updated_at'=>(string)($run['updated_at']??gmdate('c')),'build'=>VP3_AGENT_JOB_ENGINE_V1900];try{agent_brain_v122_upsert_system_memory($user,'job_execution','run-'.$runId,$text,$metadata,0.99);}catch(Throwable $e){}
}
