<?php
declare(strict_types=1);

/**
 * Phase 19 server-side worker entry point.
 * Executors call this instead of directly scanning the queue so expired leases
 * are recovered before new work is claimed.
 */
require_once __DIR__.'/agent-job-engine-v1900.php';
require_once __DIR__.'/agent-worker-runtime-v1910.php';
require_once __DIR__.'/agent-worker-cloud-v1910.php';

function agent_job_worker_poll_v1900(PDO $pdo,array $user,string $executor,string $workerId,int $limit=25): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return ['ok'=>false,'reason'=>'job-engine-not-ready','claim'=>null,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_JOB_ENGINE_V1900];
    $recovery=agent_job_recover_expired_v1900($pdo,$user,$limit);
    $claim=agent_job_claim_next_v1900($pdo,$user,$executor,$workerId,$limit);
    return ['ok'=>true,'claim'=>$claim,'recovery'=>$recovery,'build'=>VP3_AGENT_JOB_ENGINE_V1900];
}

/**
 * Phase 19.1 canonical distributed poll. Cloud orchestration and paired
 * HomeServer execution have different capability authorities, so route each to
 * its dedicated server-side boundary rather than applying one model to both.
 */
function agent_job_worker_poll_distributed_v1910(PDO $pdo,array $user,string $executor,string $workerId='primary',int $limit=25): array
{
    $executor=strtolower(trim($executor));
    if($executor==='cloud')return agent_worker_cloud_poll_v1910($pdo,$user,$limit);
    if($executor==='homeserver')return agent_worker_runtime_poll_v1910($pdo,$user,'homeserver',$workerId,$limit);
    return ['ok'=>false,'reason'=>'invalid_executor','claim'=>null,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
}

/**
 * Execute one HomeServer-targeted durable action through the already-paired
 * trusted relay. This entry point stays server-side and never exposes worker
 * credentials or lease mutation primitives to the browser.
 */
function agent_job_worker_execute_homeserver_v1910(PDO $pdo,array $user,int $limit=25): array
{
    return agent_worker_runtime_execute_homeserver_once_v1910($pdo,$user,$limit);
}
