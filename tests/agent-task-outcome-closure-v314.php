<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-task-outcome-closure-v314.php';

function expect_same(mixed $actual,mixed $expected,string $message): void
{
    if($actual!==$expected){
        fwrite(STDERR,$message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

expect_same(agent_task_outcome_v314_status_outcome('completed'),'resolved','Completed tasks must close as resolved, not assumed business success.');
expect_same(agent_task_outcome_v314_status_outcome('cancelled'),'ignored','Cancelled tasks must close as ignored, not assumed recommendation failure.');
expect_same(agent_task_outcome_v314_status_outcome('open'),'','Open tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_status_outcome('in_progress'),'','In-progress tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_status_outcome('waiting'),'','Waiting tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_hash('task-123'),sha1('task|task-123'),'Task closure hash must exactly match the proactive task suggestion identity.');
expect_same(agent_task_outcome_v314_hash(''),'','Missing task identity must fail closed.');

echo "AGENT_TASK_OUTCOME_CLOSURE_V314=PASS\n";
