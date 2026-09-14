<?php
declare(strict_types=1);

/**
 * Phase 19 server-side worker entry point.
 * Executors call this instead of directly scanning the queue so expired leases
 * are recovered before new work is claimed.
 */
require_once __DIR__.'/agent-job-engine-v1900.php';

function agent_job_worker_poll_v1900(PDO $pdo,array $user,string $executor,string $workerId,int $limit=25): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return ['ok'=>false,'reason'=>'job-engine-not-ready','claim'=>null,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_JOB_ENGINE_V1900];
    $recovery=agent_job_recover_expired_v1900($pdo,$user,$limit);
    $claim=agent_job_claim_next_v1900($pdo,$user,$executor,$workerId,$limit);
    return ['ok'=>true,'claim'=>$claim,'recovery'=>$recovery,'build'=>VP3_AGENT_JOB_ENGINE_V1900];
}
