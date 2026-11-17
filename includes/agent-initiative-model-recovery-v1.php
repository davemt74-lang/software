<?php
declare(strict_types=1);

/** Recover only an exact saved specialist report whose current evidence is still authorized. */
function vp3_initiative_recover_rejected_assessment_v1(PDO $pdo,array $user,array $work,string $hash): bool
{
    $reportKey=$work['report_only']??null;
    if(count($work)!==3||!is_array($work['binding']??null)||($work['sources']??null)!==[]
        ||!is_string($reportKey)||!preg_match('/\A[a-f0-9]{64}\z/D',$reportKey))return false;
    return vp3_initiative_recover_rejected_model_run_v1($pdo,$user,$work,'initiative.plan',$hash,$hash);
}

/** The caller holds the owner lock. The original attempt and its meter evidence remain intact. */
function vp3_initiative_recover_rejected_model_run_v1(PDO $pdo,array $user,array $work,string $capability,string $sourceKey,string $hash): bool
{
    if(!$pdo->inTransaction())return false;
    [$phase,$stage,$officeStage,$progress,$event,$summary]=match($capability){
        'initiative.plan'=>['assessment','vp3.initiative-plan.v1','vp3.initiative-plan.v1','Assessment requeued','assessment_requeued',
            'Requeued the saved report assessment after confirmed provider rejection.'],
        'initiative.prepare'=>['tool','vp3.initiative-draft.v1',VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1,'Task review requeued','office_review_requeued',
            'Requeued the saved Office review after confirmed provider rejection.'],
        default=>throw new InvalidArgumentException('Unsupported Initiative recovery step.'),
    };
    $owner=(int)$user['id'];$binding=$work['binding'];$agent=vp3_initiative_binding_agent_v1($binding);
    $run=$pdo->prepare("SELECT id,goal FROM agent_workflow_runs
        WHERE owner_user_id=? AND dedupe_key=? AND source_key=? AND source_kind='agent_initiative'
        AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND capability_key=? AND execution_target='cloud'
        AND status='failed' AND last_error_class='model_unavailable' AND attempt_count=1
        AND current_action_id IS NULL AND max_attempts>1 LIMIT 1 FOR UPDATE");
    $run->execute([$owner,hash('sha256',$capability.'|'.$hash),$sourceKey,(string)$agent,$capability]);
    $row=$run->fetch(PDO::FETCH_ASSOC);
    if(!$row||json_decode((string)$row['goal'],true)!==$work)return false;
    $runId=(int)$row['id'];
    $action=$pdo->prepare("SELECT a.id,a.result_json,c.result_json AS receipt_json FROM agent_workflow_actions a
        JOIN agent_workflow_receipts c ON c.run_id=a.run_id AND c.action_id=a.id AND c.owner_user_id=a.owner_user_id
            AND c.attempt_no=1 AND c.executor='cloud' AND c.status='failed' AND c.error_class=a.error_class
        WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1 AND a.capability_key=? AND a.execution_target='cloud'
        AND a.status='failed' AND a.error_class='model_unavailable' AND a.attempt_count=1 AND a.max_attempts>1
        AND a.completed_at<=? ORDER BY c.id DESC LIMIT 1 FOR UPDATE");
    $action->execute([$runId,$owner,$capability,gmdate('Y-m-d H:i:s',time()-300)]);
    $failure=$action->fetch(PDO::FETCH_ASSOC);
    $diagnostic=json_decode((string)($failure['result_json']??''),true);
    if(!$failure||$failure['result_json']!==$failure['receipt_json']||($diagnostic['failure_phase']??null)!==$phase
        ||in_array($diagnostic['model_diagnostic']??null,['attempt_unconfirmed','invalid_response'],true)
        ||isset($diagnostic['initiative_tool_result']))return false;

    require_once __DIR__.'/ai-service-usage-v1.php';
    if(!vp3_ai_service_usage_ready_v1($pdo))return false;
    $meter=$pdo->prepare("SELECT COUNT(*) AS attempts, SUM(CASE WHEN run_id=? AND CAST(COALESCE(agent_id,0) AS CHAR)=?
        AND conversation_id=? AND service_stage IN (?,?) AND is_background=1
        AND service_attempt_key IS NOT NULL AND service_status='rejected' AND cost_rate_source='provider_rejected'
        AND total_tokens=0 AND cloud_tokens_charged=0 AND cloud_ledger_id IS NULL THEN 1 ELSE 0 END) AS rejected
        FROM ai_execution_ledger WHERE user_id=? AND service_key='initiative' AND service_job_key=?");
    $meter->execute([$runId,(string)$agent,$binding['conversation_id'],$stage,$officeStage,$owner,hash('sha256','run:'.$runId)]);
    $proof=$meter->fetch(PDO::FETCH_ASSOC);
    if((int)($proof['attempts']??0)<1||(int)$proof['attempts']!==(int)$proof['rejected'])return false;

    if($capability==='initiative.plan'){
        require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
        try{vp3_initiative_specialist_report_only_v1($pdo,$user,$binding,$work['report_only']);}
        catch(Vp3InitiativeSourcesChangedV1 $error){return false;}
    }

    vp3_initiative_requeue_failed_model_run_v1($pdo,$owner,$runId,(int)$failure['id'],$progress,$event,$summary);
    return true;
}

/** One new analysis of a legacy Office response is allowed after its previous usage is settled. */
function vp3_initiative_recover_office_report_v1(PDO $pdo,array $user,array $work,string $sourceKey,string $hash): bool
{
    if(!$pdo->inTransaction()||($work['office_entry']??null)!==true||($work['report']??null)!==true
        ||isset($work['office_agent_task'])||isset($work['followthrough'])||isset($work['followup'])
        ||!str_starts_with($sourceKey,'office-entry:'))return false;
    $owner=(int)$user['id'];$binding=$work['binding'];$agent=vp3_initiative_binding_agent_v1($binding);
    $run=$pdo->prepare("SELECT id,goal,attempt_count FROM agent_workflow_runs
        WHERE owner_user_id=? AND dedupe_key=? AND source_key=? AND source_kind='agent_initiative'
        AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND capability_key='initiative.prepare' AND execution_target='cloud'
        AND status='failed' AND last_error_class='model_unavailable' AND attempt_count BETWEEN 1 AND 3
        AND current_action_id IS NULL LIMIT 1 FOR UPDATE");
    $run->execute([$owner,hash('sha256','initiative.prepare|'.$hash),$sourceKey,(string)$agent]);
    $row=$run->fetch(PDO::FETCH_ASSOC);
    if(!$row||json_decode((string)$row['goal'],true)!==$work)return false;
    $runId=(int)$row['id'];$attempt=(int)$row['attempt_count'];
    $event='office_report_reanalyzed';
    $previous=$pdo->prepare('SELECT 1 FROM agent_workflow_events WHERE run_id=? AND owner_user_id=? AND event_type=? LIMIT 1');
    $previous->execute([$runId,$owner,$event]);
    if($previous->fetchColumn()!==false)return false;

    $action=$pdo->prepare("SELECT id,result_json,COALESCE(available_at,started_at) AS attempt_started,completed_at
        FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND sequence_no=1
        AND capability_key='initiative.prepare' AND execution_target='cloud' AND status='failed'
        AND error_class='model_unavailable' AND attempt_count=? AND completed_at<=? LIMIT 1 FOR UPDATE");
    $action->execute([$runId,$owner,$attempt,gmdate('Y-m-d H:i:s',time()-300)]);
    $failed=$action->fetch(PDO::FETCH_ASSOC);
    if(!$failed||!is_string($failed['attempt_started'])||$failed['attempt_started']>$failed['completed_at'])return false;
    $actionId=(int)$failed['id'];
    $receipts=$pdo->prepare('SELECT attempt_no,status,error_class,executor,result_json FROM agent_workflow_receipts
        WHERE run_id=? AND owner_user_id=? AND action_id=? ORDER BY attempt_no LIMIT 4');
    $receipts->execute([$runId,$owner,$actionId]);
    $history=$receipts->fetchAll(PDO::FETCH_ASSOC);
    if(count($history)!==$attempt)return false;
    foreach($history as $index=>$receipt){
        $last=$index+1===$attempt;
        $diagnostic=json_decode((string)$receipt['result_json'],true);
        if((int)$receipt['attempt_no']!==$index+1||$receipt['executor']!=='cloud'
            ||$receipt['status']!==($last?'failed':'retry_scheduled')
            ||($diagnostic['model_diagnostic']??null)==='attempt_unconfirmed')return false;
        if($last&&($receipt['result_json']!==$failed['result_json']||$receipt['error_class']!=='model_unavailable'
            ||($diagnostic['model_diagnostic']??null)!=='invalid_response'||($diagnostic['failure_phase']??null)!=='tool'
            ||isset($diagnostic['model_http_status'])||isset($diagnostic['office_report_error'])
            ||isset($diagnostic['initiative_tool_result'])))return false;
    }

    require_once __DIR__.'/ai-service-usage-v1.php';
    if(!vp3_ai_service_usage_ready_v1($pdo))return false;
    // Only the old Office protocol qualifies. Pending or ambiguous usage for any prior attempt prevents replay.
    $meter=$pdo->prepare("SELECT COUNT(*) AS attempts,
        SUM(CASE WHEN run_id=? AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND conversation_id=?
            AND service_stage='vp3.initiative-draft.v1' AND is_background=1 AND service_attempt_key IS NOT NULL
            AND service_reservation_id IS NULL AND ((usage_known=1 AND service_status='completed'
                AND credential_source IN ('own_key','system'))
                OR (service_status='rejected' AND cost_rate_source='provider_rejected' AND total_tokens=0
                    AND cloud_tokens_charged=0 AND cloud_ledger_id IS NULL)) THEN 1 ELSE 0 END) AS settled,
        SUM(CASE WHEN service_status='completed' AND usage_known=1 AND output_tokens>0
            AND created_at>=? AND created_at<=? THEN 1 ELSE 0 END) AS answered
        FROM ai_execution_ledger WHERE user_id=? AND service_key='initiative' AND service_job_key=?");
    $meter->execute([$runId,(string)$agent,$binding['conversation_id'],$failed['attempt_started'],$failed['completed_at'],
        $owner,hash('sha256','run:'.$runId)]);
    $proof=$meter->fetch(PDO::FETCH_ASSOC);
    if((int)($proof['answered']??0)<1||(int)$proof['attempts']!==(int)$proof['settled'])return false;

    vp3_initiative_requeue_failed_model_run_v1($pdo,$owner,$runId,$actionId,'Task review requeued',$event,
        'Requeued the saved Office review with the updated report format.',$attempt+1);
    return true;
}

/** Preserve the job identity and every old receipt. A migration repair can cap the next claim. */
function vp3_initiative_requeue_failed_model_run_v1(PDO $pdo,int $owner,int $runId,int $actionId,string $progress,
    string $event,string $summary,?int $maxAttempts=null): void
{
    $pdo->prepare("UPDATE agent_workflow_actions SET status='queued',result_summary='',result_json=NULL,error_class='',
        started_at=NULL,completed_at=NULL,available_at=UTC_TIMESTAMP(),heartbeat_at=NULL,progress_percent=0,progress_message='',
        max_attempts=COALESCE(?,max_attempts) WHERE id=? AND run_id=? AND owner_user_id=?")
        ->execute([$maxAttempts,$actionId,$runId,$owner]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',last_error_class='',next_attempt_at=UTC_TIMESTAMP(),
        started_at=NULL,completed_at=NULL,progress_percent=0,progress_message=?,max_attempts=COALESCE(?,max_attempts)
        WHERE id=? AND owner_user_id=?")->execute([$progress,$maxAttempts,$runId,$owner]);
    agent_workflow_event_v1400($pdo,$owner,$runId,$event,'failed','approved','system',$summary);
}
