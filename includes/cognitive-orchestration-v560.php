<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Plan Orchestration & Follow-Through v5.60 — Phase 11B.7.
 *
 * Accepted cognitive plans become durable reference-only run/step graphs.
 * This layer coordinates handoff, verification, remediation and closure, but
 * never executes registered tools and never grants authority.
 */
const VP3_COGNITIVE_ORCHESTRATION_V560='vp3-cognitive-orchestration-v560-20260919';
const VP3_COGNITIVE_ORCHESTRATION_MAX_SYNC_V560=24;
const VP3_COGNITIVE_ORCHESTRATION_FEED_LIMIT_V560=6;

function vp3_cognitive_orchestration_schema_ready_v560(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_plan_runs_v560')
        && table_exists('cognitive_plan_steps_v560')
        && table_exists('cognitive_plan_step_dependencies_v560')
        && table_exists('cognitive_plan_step_events_v560')
        && table_exists('cognitive_plan_verifications_v560');
}

function vp3_cognitive_orchestration_ensure_schema_v560(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_orchestration_schema_ready_v560($pdo))return;
    if(!table_exists('users')||!table_exists('cognitive_plans_v550'))throw new RuntimeException('Cognitive Planning must exist before Cognitive Orchestration.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_runs_v560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      plan_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      plan_public_id CHAR(36) NOT NULL,
      source_fingerprint CHAR(64) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      verification_state VARCHAR(32) NOT NULL DEFAULT 'waiting',
      current_step_key VARCHAR(80) NOT NULL DEFAULT '',
      completed_steps INT UNSIGNED NOT NULL DEFAULT 0,
      total_steps INT UNSIGNED NOT NULL DEFAULT 0,
      replan_count INT UNSIGNED NOT NULL DEFAULT 0,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      closed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_run_public_v560 (public_id),
      UNIQUE KEY uq_cognitive_plan_run_plan_v560 (owner_user_id,agent_namespace,plan_id),
      INDEX idx_cognitive_plan_run_owner_v560 (owner_user_id,agent_namespace,status,updated_at),
      CONSTRAINT fk_cognitive_plan_run_owner_v560 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_run_plan_v560 FOREIGN KEY (plan_id) REFERENCES cognitive_plans_v550(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_steps_v560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      run_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      sequence_no INT UNSIGNED NOT NULL,
      step_key VARCHAR(80) NOT NULL,
      step_kind VARCHAR(32) NOT NULL,
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      object_id VARCHAR(190) NOT NULL DEFAULT '',
      object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      tool_id VARCHAR(120) NOT NULL DEFAULT '',
      risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
      requires_approval TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'blocked',
      verification_mode VARCHAR(32) NOT NULL DEFAULT 'none',
      attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
      started_at DATETIME NULL,
      completed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_step_public_v560 (public_id),
      UNIQUE KEY uq_cognitive_plan_step_key_v560 (run_id,step_key),
      INDEX idx_cognitive_plan_step_run_v560 (run_id,status,sequence_no),
      CONSTRAINT fk_cognitive_plan_step_run_v560 FOREIGN KEY (run_id) REFERENCES cognitive_plan_runs_v560(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_step_owner_v560 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_step_dependencies_v560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      run_id BIGINT UNSIGNED NOT NULL,
      step_id BIGINT UNSIGNED NOT NULL,
      depends_on_step_id BIGINT UNSIGNED NOT NULL,
      relation_key VARCHAR(32) NOT NULL DEFAULT 'hard',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_dependency_v560 (step_id,depends_on_step_id,relation_key),
      INDEX idx_cognitive_plan_dependency_run_v560 (run_id,step_id),
      CONSTRAINT fk_cognitive_plan_dependency_run_v560 FOREIGN KEY (run_id) REFERENCES cognitive_plan_runs_v560(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_dependency_step_v560 FOREIGN KEY (step_id) REFERENCES cognitive_plan_steps_v560(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_dependency_parent_v560 FOREIGN KEY (depends_on_step_id) REFERENCES cognitive_plan_steps_v560(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_step_events_v560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      run_id BIGINT UNSIGNED NOT NULL,
      step_id BIGINT UNSIGNED NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      from_status VARCHAR(32) NOT NULL DEFAULT '',
      to_status VARCHAR(32) NOT NULL DEFAULT '',
      event_key CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_step_event_v560 (event_key),
      INDEX idx_cognitive_plan_step_event_run_v560 (run_id,created_at),
      CONSTRAINT fk_cognitive_plan_step_event_run_v560 FOREIGN KEY (run_id) REFERENCES cognitive_plan_runs_v560(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_step_event_step_v560 FOREIGN KEY (step_id) REFERENCES cognitive_plan_steps_v560(id) ON DELETE SET NULL,
      CONSTRAINT fk_cognitive_plan_step_event_owner_v560 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_plan_verifications_v560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      run_id BIGINT UNSIGNED NOT NULL,
      step_id BIGINT UNSIGNED NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      verification_kind VARCHAR(40) NOT NULL,
      result_code VARCHAR(32) NOT NULL,
      evidence_ref_type VARCHAR(80) NOT NULL DEFAULT '',
      evidence_ref_id VARCHAR(190) NOT NULL DEFAULT '',
      confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
      verification_key CHAR(64) NOT NULL,
      verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_plan_verification_v560 (verification_key),
      INDEX idx_cognitive_plan_verification_run_v560 (run_id,verified_at),
      CONSTRAINT fk_cognitive_plan_verification_run_v560 FOREIGN KEY (run_id) REFERENCES cognitive_plan_runs_v560(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_plan_verification_step_v560 FOREIGN KEY (step_id) REFERENCES cognitive_plan_steps_v560(id) ON DELETE SET NULL,
      CONSTRAINT fk_cognitive_plan_verification_owner_v560 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_orchestration_plan_has_run_v560(PDO $pdo,int $uid,string $namespace,int $planId): bool
{
    if(!vp3_cognitive_orchestration_schema_ready_v560($pdo)||$uid<1||$planId<1)return false;
    $stmt=$pdo->prepare("SELECT 1 FROM cognitive_plan_runs_v560 WHERE owner_user_id=? AND agent_namespace=? AND plan_id=? LIMIT 1");
    $stmt->execute([$uid,$namespace,$planId]);
    return (bool)$stmt->fetchColumn();
}

function vp3_cognitive_orchestration_run_v560(PDO $pdo,array $user,string $namespace,string|int $id): ?array
{
    if(!vp3_cognitive_orchestration_schema_ready_v560($pdo))return null;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $value=trim((string)$id);if($value==='')return null;
    if(ctype_digit($value)){
        $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560 WHERE id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([(int)$value,$uid,$namespace]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560 WHERE public_id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([$value,$uid,$namespace]);
    }
    return $stmt->fetch()?:null;
}

function vp3_cognitive_orchestration_plan_row_v560(PDO $pdo,array $run): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM cognitive_plans_v550 WHERE id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
    $stmt->execute([(int)$run['plan_id'],(int)$run['owner_user_id'],(string)$run['agent_namespace']]);
    return $stmt->fetch()?:null;
}

function vp3_cognitive_orchestration_underlying_ref_v560(array $plan): array
{
    return vp3_cognitive_object_ref_v500(
        (string)$plan['object_type'],
        (string)$plan['object_id'],
        (string)($plan['object_scope']??'personal')
    );
}

function vp3_cognitive_orchestration_steps_v560(PDO $pdo,int $runId): array
{
    $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_steps_v560 WHERE run_id=? ORDER BY sequence_no,id");
    $stmt->execute([$runId]);
    return $stmt->fetchAll()?:[];
}

function vp3_cognitive_orchestration_step_v560(PDO $pdo,int $runId,string $stepKey): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_steps_v560 WHERE run_id=? AND step_key=? LIMIT 1");
    $stmt->execute([$runId,$stepKey]);
    return $stmt->fetch()?:null;
}

function vp3_cognitive_orchestration_event_v560(PDO $pdo,array $run,?array $step,string $eventType,string $from,string $to,string $dedupe=''): bool
{
    $eventType=vp3_cognitive_id_v500($eventType,40);
    if($eventType==='')return false;
    $dedupe=$dedupe!==''?$dedupe:bin2hex(random_bytes(12));
    $key=hash('sha256',vp3_cognitive_json_v500([
        (int)$run['id'],(int)($step['id']??0),$eventType,$from,$to,$dedupe
    ]));
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_plan_step_events_v560
      (run_id,step_id,owner_user_id,event_type,from_status,to_status,event_key,created_at)
      VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        (int)$run['id'],isset($step['id'])?(int)$step['id']:null,(int)$run['owner_user_id'],
        $eventType,$from,$to,$key
    ]);
    return $stmt->rowCount()>0;
}

function vp3_cognitive_orchestration_insert_step_v560(
    PDO $pdo,array $run,int $sequence,string $stepKey,string $kind,array $plan,string $status,string $verificationMode='none'
): array {
    $toolId=$kind==='handoff'?(string)($plan['tool_id']??''):'';
    $risk=$kind==='handoff'?(string)($plan['risk_level']??'low'):'low';
    $approval=$kind==='handoff'&&!empty($plan['requires_approval']);
    $pdo->prepare("INSERT INTO cognitive_plan_steps_v560
      (public_id,run_id,owner_user_id,sequence_no,step_key,step_kind,object_type,object_id,object_scope,tool_id,risk_level,requires_approval,status,verification_mode,started_at,completed_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([
          vp3_cognitive_uuid_v500(),(int)$run['id'],(int)$run['owner_user_id'],$sequence,$stepKey,$kind,
          (string)$plan['object_type'],(string)$plan['object_id'],(string)$plan['object_scope'],
          $toolId,$risk,$approval?1:0,$status,$verificationMode,
          in_array($status,['ready','awaiting_approval','awaiting_user','completed'],true)?gmdate('Y-m-d H:i:s'):null,
          $status==='completed'?gmdate('Y-m-d H:i:s'):null
      ]);
    return vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],$stepKey)?:[];
}

function vp3_cognitive_orchestration_dependency_v560(PDO $pdo,array $run,array $step,array $depends,string $relation='hard'): void
{
    if(!$step||!$depends)return;
    $pdo->prepare("INSERT IGNORE INTO cognitive_plan_step_dependencies_v560
      (run_id,step_id,depends_on_step_id,relation_key,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())")
      ->execute([(int)$run['id'],(int)$step['id'],(int)$depends['id'],vp3_cognitive_id_v500($relation,32)?:'hard']);
}

function vp3_cognitive_orchestration_materialize_v560(PDO $pdo,array $user,string $namespace,array $plan): ?array
{
    if((string)($plan['status']??'')!=='accepted')return null;
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $ref=vp3_cognitive_orchestration_underlying_ref_v560($plan);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))return null;
    if(function_exists('vp3_cognitive_action_planning_contract_v2330')){
        $actionContract=vp3_cognitive_action_planning_contract_v2330($pdo,$user,$namespace,$plan);
        $capability=(array)($actionContract['capability']??[]);
        if(trim((string)($plan['tool_id']??''))!==''&&(
            empty($capability['available'])||(string)($capability['mode']??'')!=='existing_capability'
        ))return null;
        if(!empty($capability['available'])){
            $plan['risk_level']=(string)($capability['risk']??$plan['risk_level']??'low');
            $plan['requires_approval']=!empty($capability['requires_approval'])?1:0;
        }
    }

    $pdo->prepare("INSERT IGNORE INTO cognitive_plan_runs_v560
      (public_id,plan_id,owner_user_id,agent_namespace,plan_public_id,source_fingerprint,status,verification_state,current_step_key,completed_steps,total_steps,started_at)
      VALUES (?,?,?,?,?,?,'active','waiting','',0,0,UTC_TIMESTAMP())")
      ->execute([
          vp3_cognitive_uuid_v500(),(int)$plan['id'],$uid,$namespace,(string)$plan['public_id'],(string)$plan['source_fingerprint']
      ]);

    $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560 WHERE owner_user_id=? AND agent_namespace=? AND plan_id=? LIMIT 1");
    $stmt->execute([$uid,$namespace,(int)$plan['id']]);
    $run=$stmt->fetch()?:null;if(!$run)return null;

    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM cognitive_plan_steps_v560 WHERE run_id=?");
    $countStmt->execute([(int)$run['id']]);
    if((int)$countStmt->fetchColumn()>0)return $run;

    $inspect=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,10,'inspect','inspect',$plan,'completed','authorization');
    vp3_cognitive_orchestration_event_v560($pdo,$run,$inspect,'step_verified','ready','completed','materialize:inspect');

    $prepare=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,20,'prepare','prepare',$plan,'completed','plan_contract');
    vp3_cognitive_orchestration_dependency_v560($pdo,$run,$prepare,$inspect);
    vp3_cognitive_orchestration_event_v560($pdo,$run,$prepare,'step_prepared','ready','completed','materialize:prepare');

    $hasTool=trim((string)($plan['tool_id']??''))!=='';
    $handoffStatus=$hasTool?(!empty($plan['requires_approval'])?'awaiting_approval':'ready'):'awaiting_user';
    $handoff=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,30,'handoff','handoff',$plan,$handoffStatus,'canonical_outcome');
    vp3_cognitive_orchestration_dependency_v560($pdo,$run,$handoff,$prepare);

    $verify=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,40,'verify','verify',$plan,'blocked','canonical_outcome');
    vp3_cognitive_orchestration_dependency_v560($pdo,$run,$verify,$handoff);

    $close=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,50,'close','close',$plan,'blocked','run_outcome');
    vp3_cognitive_orchestration_dependency_v560($pdo,$run,$close,$verify);

    $pdo->prepare("UPDATE cognitive_plan_runs_v560
      SET total_steps=5,completed_steps=2,current_step_key='handoff',status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([$handoffStatus,(int)$run['id']]);

    vp3_cognitive_orchestration_event_v560($pdo,$run,null,'run_materialized','accepted',$handoffStatus,'materialize');
    return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id']);
}

function vp3_cognitive_orchestration_latest_outcome_v560(PDO $pdo,array $run,array $plan): ?array
{
    if(!table_exists('cognitive_outcomes_v540'))return null;
    $stmt=$pdo->prepare("SELECT * FROM cognitive_outcomes_v540
      WHERE owner_user_id=? AND agent_namespace=? AND object_type=? AND object_id=? AND created_at>=?
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([
        (int)$run['owner_user_id'],(string)$run['agent_namespace'],(string)$plan['object_type'],(string)$plan['object_id'],(string)$run['started_at']
    ]);
    return $stmt->fetch()?:null;
}

function vp3_cognitive_orchestration_verification_v560(
    PDO $pdo,array $run,?array $step,string $kind,string $result,string $evidenceType,string $evidenceId,float $confidence=1.0
): bool {
    $key=hash('sha256',vp3_cognitive_json_v500([
        (int)$run['id'],(int)($step['id']??0),$kind,$result,$evidenceType,$evidenceId
    ]));
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_plan_verifications_v560
      (run_id,step_id,owner_user_id,verification_kind,result_code,evidence_ref_type,evidence_ref_id,confidence,verification_key,verified_at)
      VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        (int)$run['id'],isset($step['id'])?(int)$step['id']:null,(int)$run['owner_user_id'],
        vp3_cognitive_id_v500($kind,40),vp3_cognitive_id_v500($result,32),vp3_cognitive_id_v500($evidenceType,80),
        mb_strimwidth(trim($evidenceId),0,190,''),max(0,min(1,$confidence)),$key
    ]);
    return $stmt->rowCount()>0;
}

function vp3_cognitive_orchestration_set_step_v560(PDO $pdo,array $run,array $step,string $status,string $event,string $dedupe=''): array
{
    $from=(string)$step['status'];if($from===$status)return $step;
    $completed=in_array($status,['completed','failed','superseded','cancelled'],true);
    $pdo->prepare("UPDATE cognitive_plan_steps_v560 SET status=?,started_at=COALESCE(started_at,UTC_TIMESTAMP()),
      completed_at=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=?")
      ->execute([$status,$completed?gmdate('Y-m-d H:i:s'):null,(int)$step['id'],(int)$run['id']]);
    vp3_cognitive_orchestration_event_v560($pdo,$run,$step,$event,$from,$status,$dedupe);
    return vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],(string)$step['step_key'])?:$step;
}

function vp3_cognitive_orchestration_recount_v560(PDO $pdo,array $run,string $status='',string $verification=''): void
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,SUM(status='completed') completed FROM cognitive_plan_steps_v560 WHERE run_id=?");
    $stmt->execute([(int)$run['id']]);$counts=$stmt->fetch()?:[];
    $steps=vp3_cognitive_orchestration_steps_v560($pdo,(int)$run['id']);
    $current='';$terminal=['completed','failed','superseded','cancelled'];
    $actionable=['ready','awaiting_approval','awaiting_user','handoff_requested','verifying'];
    foreach($steps as $step){
        if(in_array((string)$step['status'],$terminal,true))continue;
        if(in_array((string)$step['status'],$actionable,true)){$current=(string)$step['step_key'];break;}
    }
    if($current===''){
        foreach($steps as $step){
            if(!in_array((string)$step['status'],$terminal,true)){$current=(string)$step['step_key'];break;}
        }
    }
    $sets=["completed_steps=?","total_steps=?","current_step_key=?","updated_at=UTC_TIMESTAMP()"];
    $params=[(int)($counts['completed']??0),(int)($counts['total']??0),$current];
    if($status!==''){$sets[]="status=?";$params[]=$status;}
    if($verification!==''){$sets[]="verification_state=?";$params[]=$verification;}
    if(in_array($status,['completed','closed','superseded','cancelled'],true))$sets[]="closed_at=COALESCE(closed_at,UTC_TIMESTAMP())";
    $params[]=(int)$run['id'];
    $pdo->prepare("UPDATE cognitive_plan_runs_v560 SET ".implode(',',$sets)." WHERE id=?")->execute($params);
}

function vp3_cognitive_orchestration_replan_step_v560(PDO $pdo,array $run,array $plan,array $verify,array $outcome): void
{
    $key='replan-'.max(1,(int)$run['replan_count']+1);
    if(vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],$key))return;
    $stmt=$pdo->prepare("SELECT COALESCE(MAX(sequence_no),50)+10 FROM cognitive_plan_steps_v560 WHERE run_id=?");
    $stmt->execute([(int)$run['id']]);$sequence=(int)$stmt->fetchColumn();
    $step=vp3_cognitive_orchestration_insert_step_v560($pdo,$run,$sequence,$key,'replan',$plan,'ready','user_review');
    vp3_cognitive_orchestration_dependency_v560($pdo,$run,$step,$verify,'recovery');
    $pdo->prepare("UPDATE cognitive_plan_runs_v560 SET replan_count=replan_count+1,status='needs_replan',
      verification_state='failed',current_step_key=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([$key,(int)$run['id']]);
    vp3_cognitive_orchestration_event_v560($pdo,$run,$step,'replan_required','blocked','ready','outcome:'.(string)$outcome['id']);
}

function vp3_cognitive_orchestration_reconcile_run_v560(PDO $pdo,array $user,string $namespace,array $run): array
{
    $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);
    if(!$plan)return $run;
    $planStatus=(string)$plan['status'];
    if($planStatus==='superseded'||!hash_equals((string)$run['source_fingerprint'],(string)$plan['source_fingerprint'])){
        foreach(vp3_cognitive_orchestration_steps_v560($pdo,(int)$run['id']) as $step){
            if(!in_array((string)$step['status'],['completed','superseded','cancelled'],true))
                vp3_cognitive_orchestration_set_step_v560($pdo,$run,$step,'superseded','step_superseded','source');
        }
        vp3_cognitive_orchestration_recount_v560($pdo,$run,'superseded','superseded');
        return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
    }
    if($planStatus==='dismissed'){
        foreach(vp3_cognitive_orchestration_steps_v560($pdo,(int)$run['id']) as $step){
            if(!in_array((string)$step['status'],['completed','cancelled'],true))
                vp3_cognitive_orchestration_set_step_v560($pdo,$run,$step,'cancelled','step_cancelled','plan');
        }
        vp3_cognitive_orchestration_recount_v560($pdo,$run,'cancelled','cancelled');
        return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
    }

    $ref=vp3_cognitive_orchestration_underlying_ref_v560($plan);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read')){
        vp3_cognitive_orchestration_recount_v560($pdo,$run,'blocked','authorization_lost');
        return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
    }
    if((string)$run['status']==='blocked'&&(string)$run['verification_state']==='authorization_lost'){
        $handoff=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'handoff');
        $resumeStatus='active';
        if(is_array($handoff)){
            $resumeStatus=match((string)$handoff['status']){
                'awaiting_approval'=>'awaiting_approval',
                'awaiting_user'=>'awaiting_user',
                'handoff_requested'=>'verifying',
                'failed'=>'needs_replan',
                default=>'active',
            };
        }
        vp3_cognitive_orchestration_recount_v560($pdo,$run,$resumeStatus,'waiting');
        $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
    }

    $outcome=vp3_cognitive_orchestration_latest_outcome_v560($pdo,$run,$plan);
    if(!$outcome)return $run;

    $verify=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'verify');
    $handoff=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'handoff');
    $close=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'close');
    $code=(string)$outcome['outcome_code'];
    $isNew=vp3_cognitive_orchestration_verification_v560(
        $pdo,$run,$verify?:null,'canonical_outcome',$code,'cognitive_outcome',(string)$outcome['id'],(float)($outcome['confidence']??1)
    );
    if(!$isNew)return $run;

    if(in_array($code,['successful','resolved'],true)){
        if($handoff)$handoff=vp3_cognitive_orchestration_set_step_v560($pdo,$run,$handoff,'completed','handoff_outcome_verified','outcome:'.$outcome['id']);
        if($verify)$verify=vp3_cognitive_orchestration_set_step_v560($pdo,$run,$verify,'completed','verification_passed','outcome:'.$outcome['id']);
        if($close)$close=vp3_cognitive_orchestration_set_step_v560($pdo,$run,$close,'completed','run_closed','outcome:'.$outcome['id']);
        $runStatus=$code==='successful'?'completed':'closed';
        vp3_cognitive_orchestration_recount_v560($pdo,$run,$runStatus,$code==='successful'?'verified':'resolved');
        $pdo->prepare("UPDATE cognitive_plans_v550 SET status='completed',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='accepted'")
            ->execute([(int)$plan['id'],(int)$run['owner_user_id']]);
    }elseif(in_array($code,['unsuccessful','ignored'],true)){
        if($handoff)$handoff=vp3_cognitive_orchestration_set_step_v560($pdo,$run,$handoff,'failed','handoff_outcome_failed','outcome:'.$outcome['id']);
        if($verify)$verify=vp3_cognitive_orchestration_set_step_v560($pdo,$run,$verify,'failed','verification_failed','outcome:'.$outcome['id']);
        if($verify)vp3_cognitive_orchestration_replan_step_v560($pdo,$run,$plan,$verify,$outcome);
        vp3_cognitive_orchestration_recount_v560($pdo,$run,'needs_replan','failed');
    }
    return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
}

function vp3_cognitive_orchestration_sync_v560(PDO $pdo,array $user,string $namespace): void
{
    if(!vp3_cognitive_orchestration_schema_ready_v560($pdo))return;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $uid=(int)$user['id'];
    $limit=VP3_COGNITIVE_ORCHESTRATION_MAX_SYNC_V560;

    $stmt=$pdo->prepare("SELECT * FROM cognitive_plans_v550 WHERE owner_user_id=? AND agent_namespace=? AND status='accepted' ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $plan)vp3_cognitive_orchestration_materialize_v560($pdo,$user,$namespace,$plan);

    $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560
      WHERE owner_user_id=? AND agent_namespace=? AND status NOT IN ('completed','closed','superseded','cancelled')
      ORDER BY updated_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $run)vp3_cognitive_orchestration_reconcile_run_v560($pdo,$user,$namespace,$run);
}

function vp3_cognitive_orchestration_handoff_v560(PDO $pdo,array $user,string $namespace,string $runId,string $toolId,bool $accepted): array
{
    $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,$runId);
    if(!$run)throw new RuntimeException('Cognitive plan run not found.');
    $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);
    if(!$plan)throw new RuntimeException('Cognitive plan is unavailable.');
    if((string)$plan['status']!=='accepted')throw new RuntimeException('Cognitive plan is no longer accepted.');
    $ref=vp3_cognitive_orchestration_underlying_ref_v560($plan);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))throw new RuntimeException('Cognitive plan source is no longer authorized.');
    $actionContract=null;
    if(function_exists('vp3_cognitive_action_planning_contract_v2330')){
        $actionContract=vp3_cognitive_action_planning_contract_v2330($pdo,$user,$namespace,$plan);
        $capability=(array)($actionContract['capability']??[]);
        if(empty($capability['available'])||(string)($capability['mode']??'')!=='existing_capability'){
            throw new RuntimeException('Cognitive plan capability is unavailable.');
        }
        if(!hash_equals((string)$capability['id'],vp3_cognitive_id_v500($toolId,120))){
            throw new RuntimeException('Cognitive plan capability changed.');
        }
    }

    $step=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'handoff');
    if(!$step)throw new RuntimeException('Handoff step is unavailable.');
    if(!hash_equals((string)$step['tool_id'],vp3_cognitive_id_v500($toolId,120)))throw new RuntimeException('Handoff tool changed.');
    if(is_array($actionContract)){
        $capability=(array)($actionContract['capability']??[]);
        if(function_exists('vp3_cognitive_action_planning_handoff_compatible_v2330')
            &&!vp3_cognitive_action_planning_handoff_compatible_v2330($capability,$step)){
            throw new RuntimeException('Cognitive plan capability boundary changed. Replan before handoff.');
        }
    }
    if(!in_array((string)$step['status'],['ready','awaiting_approval','handoff_requested'],true))throw new RuntimeException('Handoff is not currently actionable.');

    if(!$accepted){
        vp3_cognitive_orchestration_event_v560($pdo,$run,$step,'handoff_rejected',(string)$step['status'],(string)$step['status'],'reject:'.(string)$step['attempt_count']);
        return $run;
    }

    $from=(string)$step['status'];
    $pdo->prepare("UPDATE cognitive_plan_steps_v560 SET status='handoff_requested',attempt_count=attempt_count+1,
      started_at=COALESCE(started_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=?")
      ->execute([(int)$step['id'],(int)$run['id']]);
    $step=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'handoff')?:$step;
    vp3_cognitive_orchestration_event_v560($pdo,$run,$step,'handoff_requested',$from,'handoff_requested','attempt:'.(string)$step['attempt_count']);

    $verify=vp3_cognitive_orchestration_step_v560($pdo,(int)$run['id'],'verify');
    if($verify&&(string)$verify['status']==='blocked')vp3_cognitive_orchestration_set_step_v560($pdo,$run,$verify,'verifying','verification_started','attempt:'.(string)$step['attempt_count']);
    vp3_cognitive_orchestration_recount_v560($pdo,$run,'verifying','waiting');
    return vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(int)$run['id'])?:$run;
}

function vp3_cognitive_orchestration_permission_v560(PDO $pdo,array $user,string $namespace,array $ref,string $operation='read'): bool
{
    if((string)($ref['type']??'')!=='orchestration_run'||$operation!=='read')return false;
    $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$run)return false;
    $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);if(!$plan)return false;
    try{return vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,vp3_cognitive_orchestration_underlying_ref_v560($plan),'read');}
    catch(Throwable $e){return false;}
}

function vp3_cognitive_orchestration_step_label_v560(array $step): string
{
    return match((string)$step['step_kind']){
        'inspect'=>'Inspect current state',
        'prepare'=>'Prepare handoff',
        'handoff'=>'Authorized action handoff',
        'verify'=>'Verify canonical outcome',
        'close'=>'Close and learn',
        'replan'=>'Replan blocked/failed work',
        default=>'Plan step',
    };
}

function vp3_cognitive_orchestration_context_v560(PDO $pdo,array $user,string $namespace,array $ref,array $options=[]): array
{
    $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$run)throw new RuntimeException('Cognitive plan run not found.');
    $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);if(!$plan)throw new RuntimeException('Cognitive plan is unavailable.');
    $underlying=vp3_cognitive_orchestration_underlying_ref_v560($plan);
    if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read'))throw new RuntimeException('Plan source is no longer authorized.');
    $actionContract=function_exists('vp3_cognitive_action_planning_contract_v2330')
        ? vp3_cognitive_action_planning_contract_v2330($pdo,$user,$namespace,$plan)
        : null;
    $steps=[];
    foreach(vp3_cognitive_orchestration_steps_v560($pdo,(int)$run['id']) as $step){
        $steps[]=[
            'id'=>(string)$step['public_id'],
            'kind'=>(string)$step['step_kind'],
            'status'=>(string)$step['status'],
            'tool_id'=>(string)$step['tool_id'],
            'requires_approval'=>!empty($step['requires_approval']),
        ];
    }
    return [
        'run'=>[
            'id'=>(string)$run['public_id'],'status'=>(string)$run['status'],
            'verification_state'=>(string)$run['verification_state'],'current_step_key'=>(string)$run['current_step_key'],
            'completed_steps'=>(int)$run['completed_steps'],'total_steps'=>(int)$run['total_steps'],'replan_count'=>(int)$run['replan_count'],
            'steps'=>$steps,
        ],
        'source_object_ref'=>$underlying,
        'action_contract'=>$actionContract,
        'authority'=>'orchestration_only',
    ];
}

function vp3_cognitive_orchestration_card_v560(PDO $pdo,array $user,string $namespace,array $ref,string $mode): array
{
    $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$run)throw new RuntimeException('Cognitive plan run not found.');
    $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);if(!$plan)throw new RuntimeException('Cognitive plan is unavailable.');
    $actionContract=function_exists('vp3_cognitive_action_planning_contract_v2330')
        ? vp3_cognitive_action_planning_contract_v2330($pdo,$user,$namespace,$plan)
        : [];
    $actionProjection=$actionContract&&function_exists('vp3_cognitive_action_planning_card_sections_v2330')
        ? vp3_cognitive_action_planning_card_sections_v2330($actionContract)
        : ['facts'=>[],'sections'=>[]];
    $steps=vp3_cognitive_orchestration_steps_v560($pdo,(int)$run['id']);
    $items=[];foreach($steps as $step)$items[]=vp3_cognitive_orchestration_step_label_v560($step).' — '.str_replace('_',' ',(string)$step['status']);
    $actions=[[
        'type'=>'prompt','label'=>'Review run with Agent',
        'prompt'=>'Review cognitive plan run '.(string)$run['public_id'].' with me. Explain current step, blockers, approvals, verification evidence, and next safe action. Do not execute anything without my explicit choice.'
    ]];

    if((string)$run['status']==='needs_replan'){
        $actions[]=[
            'type'=>'prompt','label'=>'Replan with Agent',
            'prompt'=>'Replan cognitive plan run '.(string)$run['public_id'].' from current canonical evidence. Preserve completed history, explain what failed or changed, and propose a new safe plan. Do not execute anything.'
        ];
    }else{
        $liveCapability=is_array($actionContract['capability']??null)?$actionContract['capability']:[];
        foreach($steps as $step){
            if((string)$step['step_kind']!=='handoff'||trim((string)$step['tool_id'])==='')continue;
            $capabilityMatches=function_exists('vp3_cognitive_action_planning_handoff_compatible_v2330')
                ? vp3_cognitive_action_planning_handoff_compatible_v2330($liveCapability,$step)
                : (!empty($liveCapability['available'])
                    &&(string)($liveCapability['mode']??'')==='existing_capability'
                    &&hash_equals((string)($liveCapability['id']??''),vp3_cognitive_id_v500($step['tool_id']??'',120)));
            if($capabilityMatches&&in_array((string)$step['status'],['ready','awaiting_approval'],true)){
                $actions[]=['type'=>'tool','label'=>'Continue to tool action','tool_id'=>(string)$liveCapability['id']];
            }
            break;
        }
    }

    $progress=(int)$run['total_steps']>0?(int)round(((int)$run['completed_steps']/(int)$run['total_steps'])*100):0;
    return [
        'title'=>function_exists('vp3_cognitive_planning_title_v550')?vp3_cognitive_planning_title_v550((string)$plan['plan_kind']):'Active cognitive plan',
        'subtitle'=>'Plan orchestration · execution remains authoritative elsewhere',
        'status'=>(string)$run['status'],
        'summary'=>match((string)$run['status']){
            'needs_replan'=>'Canonical outcome evidence indicates this run needs replanning.',
            'awaiting_approval'=>'The next handoff still requires approval in its authoritative subsystem.',
            'verifying'=>'The handoff was requested. VP3 is waiting for canonical outcome evidence.',
            'awaiting_user'=>'The next step needs a user decision before any action can be selected.',
            default=>'VP3 is coordinating the accepted plan without executing tools itself.',
        },
        'timestamp'=>(string)$run['updated_at'],
        'badges'=>array_values(array_filter([
            (int)$run['replan_count']>0?'Replanned '.(int)$run['replan_count'].'×':null,
            ucfirst(str_replace('_',' ',(string)$run['verification_state'])),
        ])),
        'facts'=>array_merge([
            ['label'=>'Progress','value'=>$progress.'%'],
            ['label'=>'Current step','value'=>(string)$run['current_step_key']],
            ['label'=>'Authority','value'=>'Orchestration only'],
        ],(array)($actionProjection['facts']??[])),
        'sections'=>array_merge([
            ['label'=>'Run steps','items'=>$items],
        ],(array)($actionProjection['sections']??[]),[
            ['label'=>'Verification boundary','text'=>'A handoff request is not completion. This run closes only from canonical outcome evidence or an explicit authoritative resolution.'],
        ]),
        'actions'=>$actions,
    ];
}

function vp3_cognitive_orchestration_feed_candidates_v560(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_orchestration_schema_ready_v560($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560
      WHERE owner_user_id=? AND agent_namespace=? AND status NOT IN ('completed','closed','superseded','cancelled')
      ORDER BY updated_at DESC,id DESC LIMIT ".VP3_COGNITIVE_ORCHESTRATION_FEED_LIMIT_V560);
    $stmt->execute([(int)$user['id'],$namespace]);$out=[];
    foreach($stmt->fetchAll()?:[] as $run){
        try{
            $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);if(!$plan)continue;
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,vp3_cognitive_orchestration_underlying_ref_v560($plan),'read'))continue;
            $attention=in_array((string)$run['status'],['awaiting_approval','blocked','needs_replan'],true);
            $score=$attention?94:((string)$run['status']==='verifying'?82:76);
            $request=vp3_cognitive_feed_request_v530('orchestration_run',(string)$run['public_id'],'personal','standard');
            $out[]=vp3_cognitive_feed_candidate_v530(
                'orchestration:'.(string)$run['public_id'],$attention?'attention':'priorities',$score,
                $attention?'Accepted plan needs attention before it can progress.':'Accepted plan is being followed through and verified.',
                $request,'cognitive_orchestration',(string)$run['updated_at'],[
                    'status'=>$run['status'],'verification_state'=>$run['verification_state'],
                    'current_step_key'=>$run['current_step_key'],'replan_count'=>$run['replan_count'],
                ],$attention
            );
        }catch(Throwable $e){}
    }
    return $out;
}

function vp3_cognitive_orchestration_register_v560(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['cognitive_orchestration']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'cognitive_orchestration',
        'version'=>'cognitive-orchestration-v560',
        'objects'=>['orchestration_run'],
        'events'=>[],
        'permission_resolver'=>'vp3_cognitive_orchestration_permission_v560',
        'context_provider'=>'vp3_cognitive_orchestration_context_v560',
        'relationship_provider'=>null,
        'cards'=>['orchestration_run'=>'vp3_cognitive_orchestration_card_v560'],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>60],
        'sensitivity_policy'=>['reference_only'=>true,'execution_authority'=>false],
        'surfaces'=>['brief','away_digest','notification','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_cognitive_orchestration_register_v560();
