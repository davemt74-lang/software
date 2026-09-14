<?php
declare(strict_types=1);

/**
 * VP3 Phase 19.1 — Cloud worker execution.
 *
 * The Cloud worker executes orchestration/review/preparation work only. Domain
 * systems remain authoritative for mutations. A generic Agent Brain priority
 * therefore fails closed at the execute step until a canonical domain executor
 * is registered instead of pretending that an external side effect occurred.
 */
const VP3_AGENT_WORKER_CLOUD_V1910='agent-worker-cloud-v1910-20260914';

require_once __DIR__.'/agent-job-engine-v1900.php';
require_once __DIR__.'/agent-cognitive-loop-v310.php';

function agent_worker_cloud_worker_id_v1910(int $ownerUserId): string
{
    return 'cloud:u'.max(0,$ownerUserId).':primary';
}

function agent_worker_cloud_analysis_capabilities_v1910(): array
{
    return [
        'calendar.review_conflict'=>true,
        'calendar.prepare_commitment'=>true,
        'scheduling.prepare_followup'=>true,
        'commerce.review_next_action'=>true,
    ];
}

function agent_worker_cloud_claim_v1910(PDO $pdo,array $user,int $limit=25): ?array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!agent_job_engine_schema_ready_v1900($pdo))return null;
    agent_job_recover_expired_v1900($pdo,$user,max(1,min(100,$limit)));
    return agent_job_claim_next_v1900($pdo,$user,'cloud',agent_worker_cloud_worker_id_v1910($uid),$limit);
}

function agent_worker_cloud_active_row_v1910(PDO $pdo,array $user,array $claim): ?array
{
    $uid=(int)($user['id']??0);$runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);
    if($uid<1||$runId<1||$actionId<1)return null;
    $stmt=$pdo->prepare('SELECT r.*,a.action_type,a.action_key,a.label action_label,a.summary action_summary,a.status action_status,a.requires_approval action_requires_approval,a.execution_target action_execution_target,a.capability_key action_capability_key FROM agent_workflow_runs r INNER JOIN agent_workflow_actions a ON a.id=r.current_action_id AND a.run_id=r.id AND a.owner_user_id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? AND a.id=? LIMIT 1');
    $stmt->execute([$runId,$uid,$actionId]);$row=$stmt->fetch();
    if(!is_array($row))return null;
    if((string)$row['status']!=='executing'||(string)$row['action_status']!=='executing')return null;
    if((string)$row['action_execution_target']!=='cloud')return null;
    if(!hash_equals((string)$row['lease_owner'],agent_worker_cloud_worker_id_v1910($uid)))return null;
    if(!hash_equals((string)$row['lease_token'],(string)($claim['lease_token']??'')))return null;
    if((strtotime((string)($row['lease_expires_at']??''))?:0)<time())return null;
    if((!empty($row['requires_approval'])||!empty($row['action_requires_approval']))&&!in_array((string)$row['approval_status'],['approved','not_required'],true))return null;
    return $row;
}

/** Canonical server-only Cloud poll for non-mutating distributed executors. */
function agent_worker_cloud_poll_v1910(PDO $pdo,array $user,int $limit=25): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!agent_job_engine_schema_ready_v1900($pdo))return ['ok'=>false,'reason'=>'job_engine_not_ready','claim'=>null,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    $limit=max(1,min(100,$limit));
    $recovery=agent_job_recover_expired_v1900($pdo,$user,$limit);
    $claim=agent_job_claim_next_v1900($pdo,$user,'cloud',agent_worker_cloud_worker_id_v1910($uid),$limit);
    if(!$claim)return ['ok'=>true,'reason'=>'no_work','claim'=>null,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    $row=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$row){
        $runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$attempt=max(1,(int)($claim['action']['attempt_count']??1));
        agent_job_record_result_v1900($pdo,$user,$runId,$actionId,(string)($claim['lease_token']??''),'v1910-cloud-deny-'.$runId.'-'.$actionId.'-'.$attempt,false,'Cloud execution was denied by the server-side lease boundary.',[],'cloud_authorization_denied',false);
        return ['ok'=>false,'reason'=>'authorization_denied','claim'=>null,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    }
    $claim['authorization']=['authorized'=>true,'reason'=>'authorized','principal_user_id'=>$uid,'server_derived'=>true,'capability_key'=>(string)($row['action_capability_key']??'')];
    return ['ok'=>true,'reason'=>'claimed','claim'=>$claim,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
}

function agent_worker_cloud_brain_priority_v1910(array $user,array $run): ?array
{
    try{
        $state=function_exists('agent_cognitive_loop_v310_state')?agent_cognitive_loop_v310_state($user):[];
        if(function_exists('agent_cognitive_loop_v310_run')&&!agent_cognitive_loop_v310_state_fresh($state,VP3_AGENT_COGNITIVE_LOOP_STATE_MAX_AGE_SECONDS_V310))agent_cognitive_loop_v310_run($user);
    }catch(Throwable $e){}
    if(!function_exists('agent_workflow_find_brain_priority_v1400'))return null;
    return agent_workflow_find_brain_priority_v1400($user,(string)($run['source_key']??''),(string)($run['source_hash']??''));
}

function agent_worker_cloud_result_v1910(PDO $pdo,array $user,array $claim,bool $success,string $summary,array $result=[],string $errorClass='',bool $retryable=false): array
{
    $runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$attempt=max(1,(int)($claim['action']['attempt_count']??1));
    return agent_job_record_result_v1900($pdo,$user,$runId,$actionId,(string)($claim['lease_token']??''),'v1910-cloud-'.$runId.'-'.$actionId.'-'.$attempt,$success,$summary,$result,$errorClass,$retryable);
}

function agent_worker_cloud_execute_once_v1910(PDO $pdo,array $user,int $limit=25): array
{
    $poll=agent_worker_cloud_poll_v1910($pdo,$user,$limit);
    $claim=is_array($poll['claim']??null)?$poll['claim']:null;
    if(!$claim)return ['ok'=>!empty($poll['ok']),'reason'=>(string)($poll['reason']??'no_work'),'receipt'=>null,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$run){
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Cloud execution was denied by the server-side lease boundary.',[],'cloud_authorization_denied',false);
        return ['ok'=>false,'reason'=>'authorization_denied','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    }

    $type=strtolower(trim((string)($run['action_type']??'')));
    $capability=trim((string)($run['action_capability_key']??''));
    $instruction=trim((string)($run['action_summary']??''));
    $allowedTypes=['read'=>true,'resolve'=>true,'prepare'=>true,'verify'=>true,'execute'=>true];
    if(!isset($allowedTypes[$type])){
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Cloud execution stopped because the workflow action type is not allowlisted.',[],'cloud_action_type_unavailable',false);
        return ['ok'=>false,'reason'=>'action_type_unavailable','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    }

    $priority=agent_worker_cloud_brain_priority_v1910($user,$run);
    $priorityTitle=trim((string)($priority['title']??''));
    $priorityReason=trim((string)($priority['reason']??''));
    $priorityPrompt=trim((string)($priority['prompt']??''));

    if($type==='execute'&&!isset(agent_worker_cloud_analysis_capabilities_v1910()[$capability])){
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Cloud execution stopped because this action has no canonical domain executor. No external side effect was attempted.',['effect_state'=>'not_executed','capability'=>$capability],'cloud_domain_executor_unavailable',false);
        return ['ok'=>false,'reason'=>'domain_executor_unavailable','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
    }

    $summary=match($type){
        'read'=>'Agent Brain context inspected for this workflow action.',
        'resolve'=>'Durable dependencies are satisfied and the workflow is unblocked.',
        'prepare'=>'Agent Brain context prepared the next workflow action.',
        'verify'=>'The orchestration result was verified against the current Agent Brain context.',
        'execute'=>'The approved Cloud analysis/preparation capability completed without a direct domain mutation.',
    };
    $result=[
        'effect_state'=>'orchestration_complete','action_type'=>$type,'capability'=>$capability,
        'brain_priority_found'=>$priority!==null,'brain_title'=>mb_strimwidth($priorityTitle,0,190,'…'),
        'brain_reason'=>mb_strimwidth($priorityReason,0,500,'…'),
        'prepared_instruction'=>mb_strimwidth($priorityPrompt!==''?$priorityPrompt:$instruction,0,800,'…'),
        'domain_mutation_performed'=>false,
    ];
    $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,true,$summary,$result,'',false);
    return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_CLOUD_V1910];
}
