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

function expect_true(bool $value,string $message): void
{
    if(!$value){fwrite(STDERR,$message."\n");exit(1);}
}

expect_same(agent_task_outcome_v314_status_outcome('completed'),'resolved','Completed tasks must close as resolved, not assumed business success.');
expect_same(agent_task_outcome_v314_status_outcome('cancelled'),'ignored','Cancelled tasks must close as ignored, not assumed recommendation failure.');
expect_same(agent_task_outcome_v314_status_outcome('open'),'','Open tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_status_outcome('in_progress'),'','In-progress tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_status_outcome('waiting'),'','Waiting tasks must not create final outcomes.');
expect_same(agent_task_outcome_v314_hash('task-123'),sha1('task|task-123'),'Task closure hash must exactly match the proactive task suggestion identity.');
expect_same(agent_task_outcome_v314_hash(''),'','Missing task identity must fail closed.');

$cognitive=(string)file_get_contents(dirname(__DIR__).'/includes/agent-cognitive-loop-v310.php');
expect_true(str_contains($cognitive,"'suggestion_hash'=>(string)(\$item['hash']??'')"),'Cognitive priority state must retain the exact canonical suggestion hash.');
expect_true(str_contains($cognitive,'agent_action_v124_mark_shown($user,['),'Cognitive Main Feed surface must record canonical shown exposure.');
expect_true(str_contains($cognitive,"],'brain');"),'Cognitive exposure must use the canonical Brain surface label.');
$persisted=strpos($cognitive,'if($conversationId<1)return false;');
$shown=strpos($cognitive,'agent_action_v124_mark_shown($user,[');
expect_true($persisted!==false&&$shown!==false&&$shown>$persisted,'A priority must be persisted to Main Feed before it can count as shown learning evidence.');

echo "AGENT_TASK_OUTCOME_CLOSURE_V314=PASS\n";
