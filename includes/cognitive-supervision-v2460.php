<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.60 — Autonomous Work Supervision.
 *
 * Supervision observes existing durable work authorities, performs only
 * already-governed state reconciliation, and projects health/escalation state.
 * It does not create a second scheduler, task store, retry engine, approval
 * system, or execution path.
 */
const VP3_COGNITIVE_SUPERVISION_V2460='vp3-cognitive-supervision-v2460-20260922';
const VP3_COGNITIVE_SUPERVISION_CONTRACT_V2460='cognitive-supervision-v1';
const VP3_COGNITIVE_SUPERVISION_MAX_ITEMS_V2460=16;
const VP3_COGNITIVE_SUPERVISION_READY_STALL_SECONDS_V2460=1800;
const VP3_COGNITIVE_SUPERVISION_PLANNING_STALL_SECONDS_V2460=1800;
const VP3_COGNITIVE_SUPERVISION_VERIFY_STALL_SECONDS_V2460=3600;
const VP3_COGNITIVE_SUPERVISION_PLAN_STALL_SECONDS_V2460=7200;
const VP3_COGNITIVE_SUPERVISION_MEETING_VERIFY_SECONDS_V2460=86400;

function vp3_cognitive_supervision_age_v2460(mixed $value): int
{
    $stamp=strtotime((string)$value);
    return $stamp===false?0:max(0,time()-$stamp);
}

function vp3_cognitive_supervision_severity_score_v2460(string $severity): int
{
    return match($severity){
        'critical'=>100,
        'high'=>90,
        'medium'=>75,
        'low'=>55,
        default=>45,
    };
}

function vp3_cognitive_supervision_issue_v2460(
    array $item,string $health,string $severity,string $action,string $reason,array $extra=[]
): array {
    $ref=vp3_cognitive_text_v500($item['ref']??'',190);
    $title=vp3_cognitive_text_v500($item['title']??$ref,190);
    return [
        'continuity_ref'=>$ref,
        'kind'=>vp3_cognitive_text_v500($item['kind']??'',80),
        'title'=>$title,
        'health_state'=>vp3_cognitive_id_v500($health,64),
        'severity'=>in_array($severity,['critical','high','medium','low','info'],true)?$severity:'info',
        'score'=>vp3_cognitive_supervision_severity_score_v2460($severity),
        'supervisor_action'=>vp3_cognitive_id_v500($action,96),
        'reason'=>vp3_cognitive_text_v500($reason,700),
        'requires_user'=>!empty($extra['requires_user']),
        'requires_approval'=>!empty($extra['requires_approval']),
        'auto_reconcile'=>!empty($extra['auto_reconcile']),
        'source'=>vp3_cognitive_text_v500($extra['source']??($item['source']??''),100),
        'updated_at'=>(string)($extra['updated_at']??($item['updated_at']??'')),
        'age_seconds'=>max(0,(int)($extra['age_seconds']??vp3_cognitive_supervision_age_v2460($item['updated_at']??''))),
        'details'=>vp3_cognitive_sanitize_value_v500(is_array($extra['details']??null)?$extra['details']:[]),
    ];
}

function vp3_cognitive_supervision_workflow_rows_v2460(
    PDO $pdo,array $user,string $namespace,array $ids
): array {
    $uid=(int)($user['id']??0);
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id): bool=>$id>0)));
    if($uid<1||!$ids||!table_exists('agent_workflow_runs'))return [];
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $scope=$agentId>0?'agent_id=?':'agent_id IS NULL';
    $params=array_merge([$uid],$ids,$agentId>0?[$agentId]:[]);
    try{
        $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs
          WHERE owner_user_id=? AND id IN ({$marks}) AND {$scope}");
        $stmt->execute($params);
        $rows=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$rows[(int)$row['id']]=$row;
        return $rows;
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_supervision_blocker_counts_v2460(PDO $pdo,int $uid,array $runIds): array
{
    $runIds=array_values(array_unique(array_filter(array_map('intval',$runIds),static fn(int $id): bool=>$id>0)));
    if($uid<1||!$runIds||!table_exists('agent_workflow_run_dependencies')||!table_exists('agent_workflow_runs'))return [];
    $marks=implode(',',array_fill(0,count($runIds),'?'));
    try{
        $stmt=$pdo->prepare("SELECT d.run_id,COUNT(*) AS blockers
          FROM agent_workflow_run_dependencies d
          INNER JOIN agent_workflow_runs p
            ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id
          WHERE d.owner_user_id=? AND d.run_id IN ({$marks}) AND p.status<>'completed'
          GROUP BY d.run_id");
        $stmt->execute(array_merge([$uid],$runIds));
        $out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(int)$row['run_id']]=(int)$row['blockers'];
        return $out;
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_supervision_plan_rows_v2460(
    PDO $pdo,array $user,string $namespace,array $publicIds
): array {
    $uid=(int)($user['id']??0);
    $publicIds=array_values(array_unique(array_filter(array_map('strval',$publicIds))));
    if($uid<1||!$publicIds||!table_exists('cognitive_plan_runs_v560'))return [];
    $marks=implode(',',array_fill(0,count($publicIds),'?'));
    try{
        $stmt=$pdo->prepare("SELECT r.*,s.status AS current_step_status,s.step_kind AS current_step_kind,
            s.updated_at AS current_step_updated_at,s.attempt_count AS current_step_attempt_count
          FROM cognitive_plan_runs_v560 r
          LEFT JOIN cognitive_plan_steps_v560 s
            ON s.run_id=r.id AND s.step_key=r.current_step_key
          WHERE r.owner_user_id=? AND r.agent_namespace=? AND r.public_id IN ({$marks})");
        $stmt->execute(array_merge([$uid,$namespace],$publicIds));
        $out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(string)$row['public_id']]=$row;
        return $out;
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_supervision_generic_issue_v2460(array $item): ?array
{
    $state=(string)($item['state']??'');
    $kind=(string)($item['kind']??'');
    $metadata=is_array($item['metadata']??null)?$item['metadata']:[];
    if($state==='repair_needed'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'failure_detected','high','review_repair',
            'Canonical work reported a failure or repair-needed state. Review the existing recovery path before advancing.',
            ['requires_user'=>true]
        );
    }
    if($state==='blocked'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'dependency_blocked','high','resolve_dependency',
            'Canonical work is blocked and cannot safely advance until its dependency or blocker is resolved.'
        );
    }
    if($state==='needs_approval'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'waiting_approval','medium','request_approval',
            'Canonical work is waiting for explicit approval. Supervision must not execute around this gate.',
            ['requires_user'=>true,'requires_approval'=>true]
        );
    }
    if($state==='needs_user'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'waiting_user','medium','request_user_decision',
            'Canonical work needs a user decision before it can continue.',
            ['requires_user'=>true]
        );
    }
    if($kind==='meeting_commitment'){
        $bucket=(string)($metadata['bucket']??'');
        if(in_array($bucket,['verification_needs_attention','verification_review'],true)){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'verification_needs_attention','high','review_verification',
                'Meeting follow-through verification needs review before closure.',
                ['requires_user'=>true]
            );
        }
        if($bucket==='overdue'){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'commitment_overdue','high','review_overdue_commitment',
                'A meeting commitment is overdue in its authoritative follow-through system.',
                ['requires_user'=>true]
            );
        }
        if($state==='verifying'&&vp3_cognitive_supervision_age_v2460($item['updated_at']??'')>=VP3_COGNITIVE_SUPERVISION_MEETING_VERIFY_SECONDS_V2460){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'verification_overdue','medium','check_verification',
                'Meeting follow-through has remained in verification long enough to warrant review.',
                ['requires_user'=>true]
            );
        }
    }
    if($state==='needs_planning'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'planning_required','medium','continue_planning',
            'Open work requires planning before execution can safely continue.'
        );
    }
    if($state==='needs_review'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'review_required','medium','review_state',
            'Open work requires review before the next canonical step.'
        );
    }
    return null;
}

function vp3_cognitive_supervision_workflow_issue_v2460(
    array $item,array $row,int $blockers
): ?array {
    $status=(string)($row['status']??'');
    $updatedAge=vp3_cognitive_supervision_age_v2460($row['updated_at']??'');
    $heartbeatAge=vp3_cognitive_supervision_age_v2460($row['heartbeat_at']??'');
    $leaseExpires=strtotime((string)($row['lease_expires_at']??''))?:0;
    $nextAttempt=strtotime((string)($row['next_attempt_at']??''))?:0;
    $attempt=max(0,(int)($row['attempt_count']??0));
    $max=max(1,(int)($row['max_attempts']??3));
    $timeout=max(30,(int)($row['timeout_seconds']??900));
    $base=[
        'source'=>'agent_job_engine_v1900',
        'updated_at'=>(string)($row['updated_at']??''),
        'details'=>[
            'workflow_status'=>$status,
            'attempt_count'=>$attempt,
            'max_attempts'=>$max,
            'blocker_count'=>$blockers,
            'last_error_class'=>(string)($row['last_error_class']??''),
            'next_attempt_at'=>(string)($row['next_attempt_at']??''),
            'lease_expires_at'=>(string)($row['lease_expires_at']??''),
        ],
    ];

    if($status==='failed'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'terminal_failure','critical','review_failed_workflow',
            'The durable workflow reached terminal failure. Automatic supervision will not retry a terminal failure without the existing user-controlled retry/replan path.',
            $base+['requires_user'=>true]
        );
    }
    if($status==='approval_pending'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'waiting_approval','medium','request_approval',
            'The durable workflow is waiting for explicit approval.',
            $base+['requires_user'=>true,'requires_approval'=>true]
        );
    }
    if($status==='executing'){
        if($leaseExpires>0&&$leaseExpires<time()){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'expired_execution_lease','high','recover_expired_lease',
                'The durable execution lease expired. The existing job engine can safely recover it using bounded retry/backoff or terminal failure rules.',
                $base+['auto_reconcile'=>true]
            );
        }
        $stale=max(120,min(1800,$timeout));
        if($heartbeatAge>=$stale&&$heartbeatAge>0){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'heartbeat_stale','medium','await_lease_or_inspect',
                'The active workflow heartbeat is stale, but its lease is still authoritative. Supervision will not steal the lease or run a duplicate executor.',
                $base
            );
        }
    }
    if($status==='approved'){
        if($blockers>0){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'dependency_blocked','high','wait_for_dependencies',
                'The workflow is approved but has unresolved canonical workflow dependencies.',
                $base
            );
        }
        if($nextAttempt>time())return null;
        if($updatedAge>=VP3_COGNITIVE_SUPERVISION_READY_STALL_SECONDS_V2460){
            return vp3_cognitive_supervision_issue_v2460(
                $item,'ready_unclaimed','medium','check_worker_availability',
                'The workflow is ready and due but has not been claimed by an authorized Cloud or HomeServer worker.',
                $base
            );
        }
    }
    if($status==='planning'&&$updatedAge>=VP3_COGNITIVE_SUPERVISION_PLANNING_STALL_SECONDS_V2460){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'planning_stalled','high','review_planning',
            'Workflow planning has not advanced within the supervision window.',
            $base+['requires_user'=>true]
        );
    }
    if((string)($row['objective_verification_status']??'')==='needs_remediation'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'objective_remediation_pending','high','review_objective_remediation',
            'Objective verification requires remediation. Existing objective verification/replacement logic remains authoritative.',
            $base+['requires_user'=>true]
        );
    }
    return null;
}

function vp3_cognitive_supervision_plan_issue_v2460(array $item,array $row): ?array
{
    $status=(string)($row['status']??'');
    $verification=(string)($row['verification_state']??'');
    $runAge=vp3_cognitive_supervision_age_v2460($row['updated_at']??'');
    $stepAge=vp3_cognitive_supervision_age_v2460($row['current_step_updated_at']??$row['updated_at']??'');
    $replans=max(0,(int)($row['replan_count']??0));
    $base=[
        'source'=>'cognitive_orchestration_v560',
        'updated_at'=>(string)($row['updated_at']??''),
        'details'=>[
            'run_status'=>$status,
            'verification_state'=>$verification,
            'current_step_key'=>(string)($row['current_step_key']??''),
            'current_step_status'=>(string)($row['current_step_status']??''),
            'current_step_kind'=>(string)($row['current_step_kind']??''),
            'replan_count'=>$replans,
        ],
    ];
    if($replans>=3&&$status==='needs_replan'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'replan_loop','critical','escalate_replan_loop',
            'The accepted cognitive plan has required repeated replanning. Escalate to user review instead of continuing an autonomous replan loop.',
            $base+['requires_user'=>true]
        );
    }
    if($status==='needs_replan'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'replan_required','high','review_replan',
            'Authoritative outcome verification failed and the existing Cognitive Orchestration runtime requires replanning.',
            $base+['requires_user'=>true]
        );
    }
    if($status==='blocked'&&$verification==='authorization_lost'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'authorization_lost','critical','restore_authorization',
            'The cognitive plan lost authorization to its source object. Supervision cannot bypass access controls.',
            $base+['requires_user'=>true]
        );
    }
    if($status==='awaiting_approval'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'waiting_approval','medium','request_approval',
            'The cognitive plan is waiting for explicit approval.',
            $base+['requires_user'=>true,'requires_approval'=>true]
        );
    }
    if($status==='awaiting_user'){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'waiting_user','medium','request_user_decision',
            'The cognitive plan is waiting for a user decision.',
            $base+['requires_user'=>true]
        );
    }
    if($status==='verifying'&&$stepAge>=VP3_COGNITIVE_SUPERVISION_VERIFY_STALL_SECONDS_V2460){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'verification_overdue','high','check_authoritative_outcome',
            'The cognitive plan has remained in verification without a new authoritative outcome for the supervision window.',
            $base
        );
    }
    if(in_array($status,['active','blocked'],true)&&$runAge>=VP3_COGNITIVE_SUPERVISION_PLAN_STALL_SECONDS_V2460){
        return vp3_cognitive_supervision_issue_v2460(
            $item,'plan_stalled','medium','inspect_plan_progress',
            'The accepted cognitive plan has not changed state within the supervision window.',
            $base
        );
    }
    return null;
}

function vp3_cognitive_supervision_assess_items_v2460(
    PDO $pdo,array $user,string $namespace,array $items
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $workflowIds=[];$planIds=[];
    foreach($items as $item){
        if(!is_array($item))continue;
        $ref=(string)($item['ref']??'');
        if(preg_match('/^workflow:(\d+)$/',$ref,$m))$workflowIds[]=(int)$m[1];
        elseif(preg_match('/^cognitive-plan:([a-f0-9-]{16,64})$/i',$ref,$m))$planIds[]=(string)$m[1];
    }
    $workflowRows=vp3_cognitive_supervision_workflow_rows_v2460($pdo,$user,$namespace,$workflowIds);
    $blockers=vp3_cognitive_supervision_blocker_counts_v2460($pdo,(int)($user['id']??0),array_keys($workflowRows));
    $planRows=vp3_cognitive_supervision_plan_rows_v2460($pdo,$user,$namespace,$planIds);

    $issues=[];
    foreach($items as $item){
        if(!is_array($item))continue;
        $ref=(string)($item['ref']??'');$issue=null;
        if(preg_match('/^workflow:(\d+)$/',$ref,$m)&&isset($workflowRows[(int)$m[1]])){
            $issue=vp3_cognitive_supervision_workflow_issue_v2460($item,$workflowRows[(int)$m[1]],(int)($blockers[(int)$m[1]]??0));
        }elseif(preg_match('/^cognitive-plan:([a-f0-9-]{16,64})$/i',$ref,$m)&&isset($planRows[(string)$m[1]])){
            $issue=vp3_cognitive_supervision_plan_issue_v2460($item,$planRows[(string)$m[1]]);
        }
        if(!$issue)$issue=vp3_cognitive_supervision_generic_issue_v2460($item);
        if($issue)$issues[]=$issue;
    }
    usort($issues,static function(array $a,array $b): int {
        $x=((int)($b['score']??0))<=>((int)($a['score']??0));if($x!==0)return $x;
        $user=((int)!empty($b['requires_user']))<=>((int)!empty($a['requires_user']));if($user!==0)return $user;
        $aa=strtotime((string)($a['updated_at']??''))?:0;$bb=strtotime((string)($b['updated_at']??''))?:0;
        return $bb<=>$aa;
    });
    return array_slice($issues,0,VP3_COGNITIVE_SUPERVISION_MAX_ITEMS_V2460);
}

function vp3_cognitive_supervision_snapshot_v2460(
    PDO $pdo,array $user,string $namespace='system'
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $continuity=function_exists('vp3_cognitive_continuity_snapshot_v2440')
        ?vp3_cognitive_continuity_snapshot_v2440($pdo,$user,$namespace)
        :['items'=>[]];
    $issues=vp3_cognitive_supervision_assess_items_v2460($pdo,$user,$namespace,(array)($continuity['items']??[]));
    $counts=['critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'requires_user'=>0,'auto_reconcile'=>0];
    foreach($issues as $issue){
        $severity=(string)($issue['severity']??'info');if(isset($counts[$severity]))$counts[$severity]++;
        if(!empty($issue['requires_user']))$counts['requires_user']++;
        if(!empty($issue['auto_reconcile']))$counts['auto_reconcile']++;
    }
    return [
        'contract'=>VP3_COGNITIVE_SUPERVISION_CONTRACT_V2460,
        'build'=>VP3_COGNITIVE_SUPERVISION_V2460,
        'agent_namespace'=>$namespace,
        'focus'=>$issues[0]??null,
        'issues'=>$issues,
        'counts'=>$counts,
        'authority'=>[
            'projection_only'=>true,
            'automatic_state_reconciliation_only'=>true,
            'workflow_recovery'=>'agent_job_recover_expired_v1900',
            'plan_reconciliation'=>'cognitive_orchestration_v560',
            'terminal_retry_requires_existing_authority'=>true,
            'execution_authority'=>false,
            'approval_authority'=>false,
            'worker_claim_authority'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_supervision_context_item_v2460(
    PDO $pdo,array $user,string $namespace
): ?array {
    $snapshot=vp3_cognitive_supervision_snapshot_v2460($pdo,$user,$namespace);
    if(empty($snapshot['issues']))return null;
    $public=[];
    foreach(array_slice((array)$snapshot['issues'],0,6) as $issue){
        $public[]=[
            'continuity_ref'=>(string)$issue['continuity_ref'],
            'health_state'=>(string)$issue['health_state'],
            'severity'=>(string)$issue['severity'],
            'title'=>(string)$issue['title'],
            'reason'=>(string)$issue['reason'],
            'supervisor_action'=>(string)$issue['supervisor_action'],
            'requires_user'=>!empty($issue['requires_user']),
            'requires_approval'=>!empty($issue['requires_approval']),
        ];
    }
    $json=json_encode([
        'focus'=>$public[0]??null,
        'issues'=>$public,
        'counts'=>$snapshot['counts'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'supervision','cognitive-supervision:v2460','Autonomous work supervision',
        $json,97.0,['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_supervision_reconcile_owner_v2460(
    PDO $pdo,array $user,int $workflowLimit=20,int $planLimit=24
): array {
    $uid=(int)($user['id']??0);
    if($uid<1)return ['workflow_recovered'=>0,'workflow_failed'=>0,'plans_reconciled'=>0,'plan_errors'=>0];
    $result=['workflow_recovered'=>0,'workflow_failed'=>0,'plans_reconciled'=>0,'plan_errors'=>0];

    // Existing durable job-engine recovery is the only automatic workflow
    // recovery path used here. It preserves leases, max attempts, backoff,
    // pause requests, approvals, and execution target authority.
    if(function_exists('agent_job_engine_schema_ready_v1900')
        &&agent_job_engine_schema_ready_v1900($pdo)
        &&function_exists('agent_job_recover_expired_v1900')){
        try{
            $recovered=agent_job_recover_expired_v1900($pdo,$user,max(1,min(50,$workflowLimit)));
            $result['workflow_recovered']=max(0,(int)($recovered['recovered']??0));
            $result['workflow_failed']=max(0,(int)($recovered['failed']??0));
        }catch(Throwable $e){}
    }

    // Cognitive Orchestration reconciliation consumes canonical outcomes and
    // may move a plan to verified/closed/needs_replan. It does not execute an
    // external tool and cannot bypass a handoff or approval gate.
    if(function_exists('vp3_cognitive_orchestration_schema_ready_v560')
        &&vp3_cognitive_orchestration_schema_ready_v560($pdo)
        &&function_exists('vp3_cognitive_orchestration_reconcile_run_v560')){
        try{
            $limit=max(1,min(50,$planLimit));
            $stmt=$pdo->prepare("SELECT * FROM cognitive_plan_runs_v560
              WHERE owner_user_id=? AND status NOT IN ('completed','closed','superseded','cancelled')
              ORDER BY updated_at ASC,id ASC LIMIT ".$limit);
            $stmt->execute([$uid]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $run){
                try{
                    vp3_cognitive_orchestration_reconcile_run_v560(
                        $pdo,$user,(string)($run['agent_namespace']??'system'),$run
                    );
                    $result['plans_reconciled']++;
                }catch(Throwable $e){$result['plan_errors']++;}
            }
        }catch(Throwable $e){}
    }
    $result['build']=VP3_COGNITIVE_SUPERVISION_V2460;
    return $result;
}

function vp3_cognitive_supervision_activity_projection_v2460(
    PDO $pdo,array $user,string $namespace
): array {
    $snapshot=vp3_cognitive_supervision_snapshot_v2460($pdo,$user,$namespace);
    return [
        'build'=>VP3_COGNITIVE_SUPERVISION_V2460,
        'focus'=>$snapshot['focus'],
        'issues'=>array_slice((array)$snapshot['issues'],0,8),
        'counts'=>$snapshot['counts'],
        'projection_only'=>true,
    ];
}
