<?php
declare(strict_types=1);

require __DIR__ . '/../includes/agent-brain-context-v123.php';

function v141_assert(bool $ok,string $label): void
{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    if(!$ok)throw new RuntimeException('Failed: '.$label);
}

$numeric=agent_brain_v123_vector('123 2026 4567');
$mixed=agent_brain_v123_vector('conversation 123 project 2026 track 4567');

v141_assert($numeric!==[],'numeric-looking features hash without TypeError');
v141_assert($mixed!==[],'mixed Agent Brain text produces a semantic vector');
v141_assert(count($numeric)<=192&&count($mixed)<=192,'vector dimensions remain bounded');

echo "AGENT_BRAIN_VECTOR_CRC_V141=PASS\n";
