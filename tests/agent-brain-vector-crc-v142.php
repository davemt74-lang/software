<?php
declare(strict_types=1);

require __DIR__ . '/../includes/agent-brain-context-v142.php';

function check_v142(bool $ok,string $label): void
{
    echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;
    if(!$ok)throw new RuntimeException('Failed: '.$label);
}

$vector=agent_brain_v123_vector('123 456 789 2026 42 track release');
check_v142($vector!==[],'numeric-looking feature keys hash without TypeError');
check_v142(count($vector)<=192,'semantic vector remains bounded');

$bootstrap=file_get_contents(__DIR__.'/../includes/bootstrap.php')?:'';
check_v142(str_contains($bootstrap,"agent-brain-context-v142.php"),'bootstrap loads new v142 context pathname');
check_v142(!str_contains($bootstrap,"agent-brain-context-v123.php"),'bootstrap no longer executes stale v123 context pathname');

$source=file_get_contents(__DIR__.'/../includes/agent-brain-context-v142.php')?:'';
check_v142(str_contains($source,'crc32((string)$feature)'),'active context casts crc32 feature keys');
check_v142(!str_contains($source,'crc32($feature)'),'active context has no unsafe strict-type crc32 call');

echo 'AGENT_BRAIN_VECTOR_CRC_V142=PASS'.PHP_EOL;
