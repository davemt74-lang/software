<?php
declare(strict_types=1);
require __DIR__.'/../includes/agent-job-engine-v1900.php';

function job_assert(bool $value,string $message): void { if(!$value){fwrite(STDERR,$message."\n");exit(1);} }

job_assert(agent_job_retry_delay_v1900(1,60)===60,'first retry should use base delay');
job_assert(agent_job_retry_delay_v1900(2,60)===120,'second retry should back off');
job_assert(agent_job_retry_delay_v1900(3,60)===240,'third retry should back off');
job_assert(agent_job_retry_delay_v1900(20,600)===3600,'retry delay must be capped at one hour');
job_assert(VP3_AGENT_JOB_DEFAULT_MAX_ATTEMPTS_V1900===3,'default max attempts should be three');
job_assert(VP3_AGENT_JOB_DEFAULT_TIMEOUT_SECONDS_V1900===900,'default timeout should be fifteen minutes');

echo "Durable Agent Job Engine v19.0 helpers: OK\n";
