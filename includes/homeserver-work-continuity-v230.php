<?php
declare(strict_types=1);

/**
 * VP3 Cloud / HomeServer v2.3 — Durable Cross-Runtime Work Continuity.
 *
 * The Phase 19 durable Agent Job Engine remains authoritative for leases,
 * retries, receipts, approvals and terminal job state. This layer only binds
 * those durable runs to HomeServer availability, conversation continuity and
 * user-facing lifecycle updates.
 */
require_once __DIR__.'/agent-job-engine-v1900.php';
require_once __DIR__.'/agent-chat-continuity-v101.php';

const VP3_HOMESERVER_WORK_CONTINUITY_V230='vp3-homeserver-work-continuity-v230-20260924';
const VP3_HOMESERVER_WORK_CONTINUITY_VERSION='2.3';

function homeserver_work_v230_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(function_exists('agent_job_engine_ensure_schema_v1900'))agent_job_engine_ensure_schema_v1900($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_work_continuity (
      run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      action_id BIGINT UNSIGNED NULL,
      conversation_id VARCHAR(190) NOT NULL DEFAULT '',
      source_surface VARCHAR(40) NOT NULL DEFAULT 'agent_chat',
      continuity_key CHAR(64) NOT NULL DEFAULT '',
      state VARCHAR(32) NOT NULL DEFAULT 'queued',
      desired_executor VARCHAR(24) NOT NULL DEFAULT 'homeserver',
      fallback_allowed TINYINT(1) NOT NULL DEFAULT 1,
      last_job_status VARCHAR(32) NOT NULL DEFAULT '',
      last_error_class VARCHAR(80) NOT NULL DEFAULT '',
      last_notified_state VARCHAR(32) NOT NULL DEFAULT '',
      last_transition_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_homeserver_work_continuity_key (owner_user_id,continuity_key),
      INDEX idx_homeserver_work_continuity_owner (owner_user_id,state,updated_at,run_id),
      CONSTRAINT fk_homeserver_work_continuity_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
      CONSTRAINT fk_homeserver_work_continuity_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_work_v230_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo instanceof PDO
      && function_exists('agent_job_engine_schema_ready_v1900')
      && agent_job_engine_schema_ready_v1900($pdo)
      && table_exists('homeserver_work_continuity');
}

function homeserver_work_v230_text(mixed $value,int $limit=500): string
{
    if(function_exists('agent_workflow_text_v1400'))return agent_workflow_text_v1400($value,$limit);
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??''),0,max(1,$limit),'…');
}

function homeserver_work_v230_key(int $uid,int $runId,int $actionId): string
{
    if($uid<1||$runId<1||$actionId<1)throw new RuntimeException('Durable work requires owner, run and action IDs.');
    return hash('sha256','vp3-v230|'.$uid.'|'.$runId.'|'.$actionId);
}

function homeserver_work_v230_connection_ready(int $uid): bool
{
    if($uid<1)return false;
    if(function_exists('homeserver_execution_v220_registry')){
        try{$registry=homeserver_execution_v220_registry($uid,true);return !empty($registry['available'])&&!empty($registry['connected']);}
        catch(Throwable $e){return false;}
    }
    if(function_exists('homeserver_https_v1300_status')){
        try{$status=homeserver_https_v1300_status($uid);return !empty($status['paired'])&&!empty($status['connected']);}
        catch(Throwable $e){return false;}
    }
    return false;
}

function homeserver_work_v230_conversation_owned(PDO $pdo,int $uid,string $conversationId): int
{
    $conversationId=trim($conversationId);
    if($uid<1||$conversationId===''||!ctype_digit($conversationId)||!table_exists('chat_conversations'))return 0;
    $id=(int)$conversationId;if($id<1)return 0;
    $s=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,$uid]);
    return (int)($s->fetchColumn()?:0);
}

function homeserver_work_v230_append_chat(PDO $pdo,array $user,string $conversationId,string $message,array $context=[]): int
{
    $uid=(int)($user['id']??0);$message=trim($message);
    if($uid<1||$message===''||!table_exists('chat_messages'))return 0;
    $cid=homeserver_work_v230_conversation_owned($pdo,$uid,$conversationId);
    if($cid<1&&function_exists('agent_chat_v101_update_conversation'))$cid=agent_chat_v101_update_conversation($pdo,$uid,$user);
    if($cid<1)return 0;
    $json=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $s=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,NULL,?,?,?,NOW())');
    $s->execute([$cid,'assistant',homeserver_work_v230_text($message,3000),is_string($json)?$json:'{}']);
    $messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,$uid]);
    if(function_exists('agent_brain_archive_and_parse'))agent_brain_archive_and_parse($user,$cid,$messageId,'assistant',$message,'text');
    return $cid;
}

function homeserver_work_v230_public(PDO $pdo,array $row): array
{
    $runId=(int)($row['run_id']??0);$uid=(int)($row['owner_user_id']??0);
    $run=$runId>0&&function_exists('agent_workflow_row_v1400')?agent_workflow_row_v1400($pdo,$uid,$runId):null;
    return [
      'version'=>VP3_HOMESERVER_WORK_CONTINUITY_VERSION,
      'run_id'=>$runId,
      'action_id'=>(int)($row['action_id']??0),
      'conversation_id'=>(string)($row['conversation_id']??''),
      'source_surface'=>(string)($row['source_surface']??'agent_chat'),
      'state'=>(string)($row['state']??'queued'),
      'desired_executor'=>(string)($row['desired_executor']??'homeserver'),
      'fallback_allowed'=>!empty($row['fallback_allowed']),
      'last_job_status'=>(string)($row['last_job_status']??''),
      'last_error_class'=>(string)($row['last_error_class']??''),
      'updated_at'=>(string)($row['updated_at']??''),
      'job'=>$run&&function_exists('agent_job_public_run_v1900')?agent_job_public_run_v1900($pdo,$run,true):null,
    ];
}

function homeserver_work_v230_row(PDO $pdo,int $uid,int $runId,bool $forUpdate=false): ?array
{
    if($uid<1||$runId<1||!homeserver_work_v230_schema_ready($pdo))return null;
    $s=$pdo->prepare('SELECT * FROM homeserver_work_continuity WHERE owner_user_id=? AND run_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $s->execute([$uid,$runId]);$row=$s->fetch();
    return is_array($row)?$row:null;
}

function homeserver_work_v230_action(PDO $pdo,int $uid,int $runId): ?array
{
    $s=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE owner_user_id=? AND run_id=? ORDER BY
      CASE status WHEN 'executing' THEN 0 WHEN 'queued' THEN 1 WHEN 'approval_pending' THEN 2 ELSE 3 END,
      sequence_no,id LIMIT 1");
    $s->execute([$uid,$runId]);$row=$s->fetch();
    return is_array($row)?$row:null;
}

function homeserver_work_v230_ensure_run(PDO $pdo,array $user,array $run,string $conversationId='',string $sourceSurface='agent_chat',bool $fallbackAllowed=true): array
{
    $uid=(int)($user['id']??0);$runId=(int)($run['id']??0);
    if($uid<1||$runId<1)throw new RuntimeException('Durable work could not be associated with an owner and run.');
    $action=homeserver_work_v230_action($pdo,$uid,$runId);$actionId=(int)($action['id']??0);
    if($actionId<1)throw new RuntimeException('Durable work has no executable action.');
    $key=homeserver_work_v230_key($uid,$runId,$actionId);
    $conversationId=homeserver_work_v230_text($conversationId,190);
    $sourceSurface=in_array($sourceSurface,['agent_chat','profile_agent','agent_brain','system'],true)?$sourceSurface:'agent_chat';
    $target=(string)($action['execution_target']??$run['execution_target']??'homeserver');
    if(!in_array($target,['cloud','homeserver'],true))$target='homeserver';
    $pdo->prepare("INSERT INTO homeserver_work_continuity
      (run_id,owner_user_id,action_id,conversation_id,source_surface,continuity_key,state,desired_executor,fallback_allowed,last_job_status,last_error_class,last_transition_at)
      VALUES (?,?,?,?,?,?, 'queued',?,?,?, '',UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE action_id=VALUES(action_id),conversation_id=IF(VALUES(conversation_id)<>'',VALUES(conversation_id),conversation_id),
        source_surface=VALUES(source_surface),continuity_key=VALUES(continuity_key),desired_executor=VALUES(desired_executor),
        fallback_allowed=VALUES(fallback_allowed),last_job_status=VALUES(last_job_status),updated_at=UTC_TIMESTAMP()")
      ->execute([$runId,$uid,$actionId,$conversationId,$sourceSurface,$key,$target,$fallbackAllowed?1:0,(string)($run['status']??'')]);
    if($target==='homeserver'&&column_exists('agent_workflow_runs','max_attempts')){
        $pdo->prepare("UPDATE agent_workflow_runs SET max_attempts=GREATEST(max_attempts,20),retry_backoff_seconds=LEAST(GREATEST(retry_backoff_seconds,5),30),timeout_seconds=GREATEST(timeout_seconds,900) WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
        $pdo->prepare("UPDATE agent_workflow_actions SET max_attempts=GREATEST(max_attempts,20),timeout_seconds=GREATEST(timeout_seconds,900) WHERE id=? AND run_id=? AND owner_user_id=?")->execute([$actionId,$runId,$uid]);
    }
    $row=homeserver_work_v230_row($pdo,$uid,$runId);
    if(!$row)throw new RuntimeException('Durable work continuity could not be persisted.');
    return $row;
}

function homeserver_work_v230_enqueue(PDO $pdo,array $user,string $title,string $instruction,array $options=[]): array
{
    if(!homeserver_work_v230_schema_ready($pdo))throw new RuntimeException('Durable cross-runtime work is not ready.');
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');
    $title=homeserver_work_v230_text($title,190);$instruction=homeserver_work_v230_text($instruction,3000);
    if($title===''||$instruction==='')throw new RuntimeException('Durable work requires a title and instruction.');
    $conversationId=homeserver_work_v230_text($options['conversation_id']??'',190);
    $surface=(string)($options['source_surface']??'agent_chat');
    $target=in_array((string)($options['execution_target']??'homeserver'),['cloud','homeserver'],true)?(string)$options['execution_target']:'homeserver';
    $capability=homeserver_work_v230_text($options['capability_key']??'agent.next_action',160);
    $requiresApproval=!empty($options['requires_approval']);$risk=in_array((string)($options['risk_level']??'low'),['low','medium','high'],true)?(string)$options['risk_level']:'low';
    $fallbackAllowed=!array_key_exists('fallback_allowed',$options)||!empty($options['fallback_allowed']);
    $clientKey=homeserver_work_v230_text($options['idempotency_key']??'',190);
    if($clientKey==='')$clientKey=hash('sha256',$conversationId.'|'.$title.'|'.$instruction.'|'.$target.'|'.$capability);
    $dedupe=hash('sha256','v230|'.$uid.'|'.$surface.'|'.$clientKey);
    $agentId=max(0,(int)($options['agent_id']??0));
    $sourceKind=$surface==='profile_agent'?'profile_agent_conversation':'chat_conversation';
    $status=$requiresApproval?'approval_pending':'approved';$approval=$requiresApproval?'pending':'not_required';
    try{
        $pdo->beginTransaction();
        $s=$pdo->prepare("INSERT INTO agent_workflow_runs
          (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,
           next_attempt_at,max_attempts,retry_backoff_seconds,timeout_seconds)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),20,15,900)");
        $s->execute([$uid,$agentId?:null,'cross_runtime_job',$surface,$sourceKind,$conversationId,sha1($instruction),$dedupe,$title,$instruction,'Durable cross-runtime Agent work.',$status,$risk,$requiresApproval?1:0,$approval,$target,$capability]);
        $runId=(int)$pdo->lastInsertId();
        $a=$pdo->prepare("INSERT INTO agent_workflow_actions
          (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,available_at,max_attempts,timeout_seconds)
          VALUES (?,?,1,'execute','execute',?,?,?,?,?,?,UTC_TIMESTAMP(),20,900)");
        $a->execute([$runId,$uid,$title,$instruction,$requiresApproval?'approval_pending':'queued',$requiresApproval?1:0,$target,$capability]);
        if(function_exists('agent_workflow_event_v1400'))agent_workflow_event_v1400($pdo,$uid,$runId,'created','',$status,$surface,'Durable cross-runtime work created.',['conversation_id'=>$conversationId,'execution_target'=>$target]);
        $pdo->commit();
    }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if((string)$e->getCode()==='23000'){
            $s=$pdo->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=? LIMIT 1');$s->execute([$uid,$dedupe]);$existing=$s->fetch();
            if(is_array($existing)){$row=homeserver_work_v230_ensure_run($pdo,$user,$existing,$conversationId,$surface,$fallbackAllowed);return homeserver_work_v230_public($pdo,$row);}
        }
        throw $e;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $run=agent_workflow_row_v1400($pdo,$uid,$runId);if(!$run)throw new RuntimeException('Durable work could not be loaded.');
    $row=homeserver_work_v230_ensure_run($pdo,$user,$run,$conversationId,$surface,$fallbackAllowed);
    $message='I queued “'.$title.'” for '.($target==='homeserver'?'your HomeServer':'VP3 Cloud').'. I’ll keep it attached to this conversation and report meaningful progress here.';
    homeserver_work_v230_append_chat($pdo,$user,$conversationId,$message,['work_continuity'=>['run_id'=>$runId,'state'=>'queued','version'=>'2.3']]);
    if(function_exists('create_notification'))create_notification($uid,'workflow_update','Work queued',$message,url('/agent-workflows.php'),'agent_workflow_run',$runId);
    return homeserver_work_v230_public($pdo,$row);
}

function homeserver_work_v230_message(string $state,string $title,bool $fallbackAllowed=true): string
{
    $title=homeserver_work_v230_text($title,190);
    return match($state){
      'waiting_homeserver'=>'“'.$title.'” is waiting for HomeServer. I can keep using Cloud capabilities, but I won’t pretend local-only work completed.',
      'resuming'=>'HomeServer is back. I resumed “'.$title.'” from its durable job state.',
      'running'=>'I sent “'.$title.'” to HomeServer and it is running under a durable lease.',
      'completed'=>'“'.$title.'” finished. The result is attached to this durable job and this conversation.',
      'waiting_local_approval'=>'“'.$title.'” reached HomeServer and is waiting for a local approval before the protected action can continue.',
      'failed'=>'“'.$title.'” could not complete. I kept the failure reason and retry history so we can retry or choose another allowed runtime.',
      'cancelled'=>'“'.$title.'” was cancelled. No further execution will be claimed.',
      default=>'“'.$title.'” changed to '.$state.'.',
    };
}

function homeserver_work_v230_transition(PDO $pdo,array $user,array $row,string $state,string $jobStatus='',string $errorClass='',bool $notify=true): array
{
    $uid=(int)($user['id']??0);$runId=(int)($row['run_id']??0);if($uid<1||$runId<1)return $row;
    $previous=(string)($row['state']??'queued');
    if($previous===$state&&(string)($row['last_job_status']??'')===$jobStatus&&(string)($row['last_error_class']??'')===$errorClass)return $row;
    $pdo->prepare("UPDATE homeserver_work_continuity SET state=?,last_job_status=?,last_error_class=?,last_transition_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND run_id=?")
      ->execute([$state,$jobStatus,homeserver_work_v230_text($errorClass,80),$uid,$runId]);
    $run=agent_workflow_row_v1400($pdo,$uid,$runId);$title=(string)($run['title']??'Agent work');
    $message=homeserver_work_v230_message($state,$title,!empty($row['fallback_allowed']));
    if(function_exists('agent_workflow_event_v1400'))agent_workflow_event_v1400($pdo,$uid,$runId,'continuity_'.$state,$jobStatus,$jobStatus,'continuity',$message,['continuity_state'=>$state,'previous_state'=>$previous,'error_class'=>$errorClass]);
    if($notify&&in_array($state,['waiting_homeserver','resuming','waiting_local_approval','completed','failed','cancelled'],true)){
        $type=$state==='failed'?'workflow_needs_attention':'workflow_update';
        if(function_exists('create_notification'))create_notification($uid,$type,$state==='failed'?'Agent work needs attention':'Agent work update',$message,url('/agent-workflows.php'),'agent_workflow_run',$runId);
        homeserver_work_v230_append_chat($pdo,$user,(string)($row['conversation_id']??''),$message,['work_continuity'=>['run_id'=>$runId,'state'=>$state,'version'=>'2.3']]);
        $pdo->prepare('UPDATE homeserver_work_continuity SET last_notified_state=? WHERE owner_user_id=? AND run_id=?')->execute([$state,$uid,$runId]);
    }
    $fresh=homeserver_work_v230_row($pdo,$uid,$runId);return $fresh?:$row;
}

function homeserver_work_v230_reconcile_owner(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);if($uid<1||!homeserver_work_v230_schema_ready($pdo))return ['updated'=>0,'waiting'=>0,'resumed'=>0,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
    $connected=homeserver_work_v230_connection_ready($uid);
    $s=$pdo->prepare("SELECT r.* FROM agent_workflow_runs r
      WHERE r.owner_user_id=? AND r.execution_target='homeserver'
        AND r.status NOT IN ('completed','cancelled') ORDER BY r.updated_at ASC,r.id ASC LIMIT 100");
    $s->execute([$uid]);$updated=0;$waiting=0;$resumed=0;
    foreach($s->fetchAll()?:[] as $run){
        $row=homeserver_work_v230_ensure_run($pdo,$user,$run,(string)($run['source_key']??''),(string)($run['origin']??'agent_brain'),true);
        $job=(string)($run['status']??'');$state=(string)($row['state']??'queued');
        $next=$state;
        if($job==='failed')$next='failed';
        elseif($job==='approval_pending')$next='waiting_approval';
        elseif($job==='executing')$next='running';
        elseif($job==='approved'&&!$connected)$next='waiting_homeserver';
        elseif($job==='approved'&&$connected&&$state==='waiting_homeserver')$next='resuming';
        elseif($job==='approved'&&$connected)$next='ready';
        if($next!==$state){$row=homeserver_work_v230_transition($pdo,$user,$row,$next,$job,(string)($run['last_error_class']??''));$updated++;if($next==='waiting_homeserver')$waiting++;if($next==='resuming')$resumed++;}
    }
    $t=$pdo->prepare("SELECT c.*,r.status job_status,r.last_error_class,r.title FROM homeserver_work_continuity c JOIN agent_workflow_runs r ON r.id=c.run_id AND r.owner_user_id=c.owner_user_id
      WHERE c.owner_user_id=? AND r.status IN ('completed','failed','cancelled') ORDER BY r.updated_at DESC LIMIT 100");
    $t->execute([$uid]);
    foreach($t->fetchAll()?:[] as $row){
        $job=(string)$row['job_status'];$target=$job==='completed'?'completed':($job==='cancelled'?'cancelled':'failed');
        if((string)$row['state']!==$target){homeserver_work_v230_transition($pdo,$user,$row,$target,$job,(string)($row['last_error_class']??''));$updated++;}
    }
    return ['updated'=>$updated,'waiting'=>$waiting,'resumed'=>$resumed,'connected'=>$connected,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
}

function homeserver_work_v230_before_homeserver_dispatch(PDO $pdo,array $user,array $claim): array
{
    $uid=(int)($user['id']??0);$runId=(int)($claim['run_id']??0);if($uid<1||$runId<1)return [];
    $run=agent_workflow_row_v1400($pdo,$uid,$runId);if(!$run)return [];
    $row=homeserver_work_v230_ensure_run($pdo,$user,$run,(string)($run['source_key']??''),(string)($run['origin']??'agent_brain'),true);
    $actionId=(int)($claim['action']['id']??$row['action_id']??0);$key=homeserver_work_v230_key($uid,$runId,$actionId);
    $pdo->prepare("UPDATE homeserver_work_continuity SET action_id=?,continuity_key=?,state='running',last_job_status='executing',last_transition_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND run_id=?")
      ->execute([$actionId,$key,$uid,$runId]);
    return ['key'=>$key,'cloud_run_id'=>$runId,'cloud_action_id'=>$actionId,'conversation_id'=>(string)($row['conversation_id']??''),'version'=>'2.3'];
}

function homeserver_work_v230_after_homeserver_dispatch(PDO $pdo,array $user,array $claim,array $result): void
{
    $uid=(int)($user['id']??0);$runId=(int)($claim['run_id']??0);if($uid<1||$runId<1)return;
    $row=homeserver_work_v230_row($pdo,$uid,$runId);if(!$row)return;
    $reason=(string)($result['reason']??'');
    if($reason==='homeserver_approval_pending')$state='waiting_local_approval';
    elseif($reason==='homeserver_continuity_pending')$state='running';
    elseif(!empty($result['ok'])&&$reason==='completed')$state='completed';
    elseif(!empty($result['retryable']))$state='waiting_homeserver';
    else $state='failed';
    homeserver_work_v230_transition($pdo,$user,$row,$state,$state==='completed'?'completed':'executing',(string)($result['reason']??''),true);
}

function homeserver_work_v230_status(PDO $pdo,array $user,int $runId): ?array
{
    $uid=(int)($user['id']??0);$row=homeserver_work_v230_row($pdo,$uid,$runId);
    return $row?homeserver_work_v230_public($pdo,$row):null;
}


function homeserver_work_v230_cancel(PDO $pdo,array $user,int $runId): array
{
    $uid=(int)($user['id']??0);$row=homeserver_work_v230_row($pdo,$uid,$runId);
    if(!$row)throw new RuntimeException('Durable work was not found.');
    $key=(string)($row['continuity_key']??'');
    if($key!==''&&homeserver_work_v230_connection_ready($uid)&&function_exists('homeserver_https_v1300_remote_operation')){
        try{homeserver_https_v1300_remote_operation($uid,'work.continuity.cancel',['key'=>$key]);}catch(Throwable $e){}
    }
    $run=agent_job_cancel_v1900($pdo,$user,$runId,'user');
    $fresh=homeserver_work_v230_row($pdo,$uid,$runId);
    if($fresh)homeserver_work_v230_transition($pdo,$user,$fresh,'cancelled','cancelled','',true);
    return ['continuity'=>$fresh?homeserver_work_v230_public($pdo,$fresh):null,'job'=>$run,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
}

function homeserver_work_v230_retry(PDO $pdo,array $user,int $runId): array
{
    $uid=(int)($user['id']??0);$row=homeserver_work_v230_row($pdo,$uid,$runId);
    if(!$row)throw new RuntimeException('Durable work was not found.');
    $run=agent_job_retry_v1900($pdo,$user,$runId);
    $fresh=homeserver_work_v230_row($pdo,$uid,$runId);
    if($fresh){
        $next=((string)($fresh['desired_executor']??'homeserver')==='homeserver'&&!homeserver_work_v230_connection_ready($uid))?'waiting_homeserver':'ready';
        $fresh=homeserver_work_v230_transition($pdo,$user,$fresh,$next,'approved','',true);
    }
    return ['continuity'=>$fresh?homeserver_work_v230_public($pdo,$fresh):null,'job'=>$run,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
}

function homeserver_work_v230_resume(PDO $pdo,array $user,int $runId): array
{
    $uid=(int)($user['id']??0);$row=homeserver_work_v230_row($pdo,$uid,$runId);
    if(!$row)throw new RuntimeException('Durable work was not found.');
    $run=agent_workflow_row_v1400($pdo,$uid,$runId);
    if(!$run)throw new RuntimeException('Durable Agent job was not found.');
    $status=(string)($run['status']??'');
    if(in_array($status,['completed','cancelled'],true))throw new RuntimeException('Closed durable work cannot be resumed.');
    if($status==='failed')return homeserver_work_v230_retry($pdo,$user,$runId);
    if($status==='paused'&&function_exists('agent_work_control_resume_v173')){
        agent_work_control_resume_v173($pdo,$user,$runId);
    }elseif(in_array($status,['approved','executing'],true)){
        $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=UTC_TIMESTAMP(),progress_message='Resume requested' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
        $pdo->prepare("UPDATE agent_workflow_actions SET available_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=? AND status='queued'")->execute([$runId,$uid]);
    }
    $connected=homeserver_work_v230_connection_ready($uid);
    $target=(string)($row['desired_executor']??'homeserver');
    $next=$target==='homeserver'&&!$connected?'waiting_homeserver':'resuming';
    $fresh=homeserver_work_v230_transition($pdo,$user,$row,$next,(string)($run['status']??''),'',true);
    return ['continuity'=>homeserver_work_v230_public($pdo,$fresh),'job'=>agent_workflow_row_v1400($pdo,$uid,$runId),'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
}

function homeserver_work_v230_route(PDO $pdo,array $user,int $runId,string $target): array
{
    $uid=(int)($user['id']??0);$target=strtolower(trim($target));
    if(!in_array($target,['cloud','homeserver'],true))throw new RuntimeException('Execution target must be Cloud or HomeServer.');
    $row=homeserver_work_v230_row($pdo,$uid,$runId);
    if(!$row)throw new RuntimeException('Durable work was not found.');
    if($target==='cloud'&&empty($row['fallback_allowed']))throw new RuntimeException('This job does not permit Cloud fallback.');
    $run=agent_workflow_row_v1400($pdo,$uid,$runId);
    if(!$run)throw new RuntimeException('Durable Agent job was not found.');
    $status=(string)($run['status']??'');
    if($status==='executing'||(int)($run['current_action_id']??0)>0)throw new RuntimeException('Wait for the active leased action to finish or expire before changing runtimes.');
    if(in_array($status,['completed','cancelled'],true))throw new RuntimeException('Closed durable work cannot change runtimes.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE agent_workflow_runs SET execution_target=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$target,$runId,$uid]);
        $pdo->prepare("UPDATE agent_workflow_actions SET execution_target=?,updated_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','failed')")->execute([$target,$runId,$uid]);
        $pdo->prepare("UPDATE homeserver_work_continuity SET desired_executor=?,state='queued',last_transition_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=?")->execute([$target,$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'continuity_rerouted',$status,$status,'user','User changed the durable execution runtime.',['execution_target'=>$target]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($status==='failed')agent_job_retry_v1900($pdo,$user,$runId);
    $fresh=homeserver_work_v230_row($pdo,$uid,$runId);
    $message='I will continue “'.homeserver_work_v230_text($run['title']??'Agent work',190).'” on '.($target==='homeserver'?'HomeServer':'VP3 Cloud').'.';
    if(function_exists('create_notification'))create_notification($uid,'workflow_update','Execution route changed',$message,url('/agent-workflows.php'),'agent_workflow_run',$runId);
    homeserver_work_v230_append_chat($pdo,$user,(string)($row['conversation_id']??''),$message,['work_continuity'=>['run_id'=>$runId,'state'=>'rerouted','target'=>$target,'version'=>'2.3']]);
    return ['continuity'=>$fresh?homeserver_work_v230_public($pdo,$fresh):null,'job'=>agent_workflow_row_v1400($pdo,$uid,$runId),'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230];
}

function homeserver_work_v230_recent(PDO $pdo,array $user,int $limit=30): array
{
    $uid=(int)($user['id']??0);if($uid<1||!homeserver_work_v230_schema_ready($pdo))return [];
    $limit=max(1,min(100,$limit));
    $s=$pdo->prepare("SELECT * FROM homeserver_work_continuity WHERE owner_user_id=? ORDER BY updated_at DESC,run_id DESC LIMIT ".$limit);
    $s->execute([$uid]);$out=[];
    foreach($s->fetchAll()?:[] as $row)$out[]=homeserver_work_v230_public($pdo,$row);
    return $out;
}
