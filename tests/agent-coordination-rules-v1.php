<?php
declare(strict_types=1);

// The canonical fixture supplies real memory, authorization and the owner lock.
define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-rules-v1.php';

function coordination_rule_reject(int $status, callable $operation): void
{
    try { $operation(); }
    catch (Throwable $error) {
        $actual = $error instanceof InvalidArgumentException ? 422 : $error->getCode();
        canvas_pipeline_check($actual === $status, 'Expected '.$status.', got '.$actual.' '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected '.$status.' from the rule boundary.');
}

$base = vp3_coordination_rule_settings_v1($pdo,$owner,7,11);
$defaults = vp3_coordination_rule_defaults_v1();
$count = static fn(): int => canvas_pipeline_count('agent_memory_items', "subject='top-of-mind-routing'");
canvas_pipeline_check($base['rules'] === $defaults && $count() === 0 && strlen($base['revision']) === 64,
    'Reading the default Agent rules does not create a preference or enqueue work.');
canvas_pipeline_check(array_keys($base['catalog']['specialists']) === array_keys($defaults)
    && array_keys($base['catalog']['actions']) === ['watch','prepare','ask','report'],
    'The control response exposes only the known specialists and four private routing actions.');
canvas_pipeline_check(vp3_coordination_route_v1($base,'tasks',['unknown','blocked','due_soon'])
        === ['specialist'=>'tasks','trigger'=>'blocked','action'=>'ask']
    && vp3_coordination_route_v1($base,'gmail',['routine'])
        === ['specialist'=>'gmail','trigger'=>'routine','action'=>'watch']
    && vp3_coordination_route_v1([],'planning',['pending_confirmation'])['action'] === 'watch',
    'Source trigger order selects one rule. Unknown triggers and unavailable choices stay in Top of Mind.');

$policy = vp3_initiative_policy_v1($owner,7);
$jobs = canvas_pipeline_count('agent_workflow_runs','1=1');
$changed = vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$base['revision'],[
    'planning'=>['pending_confirmation'=>'watch'],
    'gmail'=>['needs_attention'=>'report'],
]);
canvas_pipeline_check($changed['revision'] !== $base['revision'] && $count() === 1
    && $changed['rules']['planning']['pending_confirmation'] === 'watch'
    && $changed['rules']['gmail']['needs_attention'] === 'report'
    && $changed['rules']['tasks'] === $defaults['tasks']
    && vp3_initiative_policy_v1($owner,7) === $policy && canvas_pipeline_count('agent_workflow_runs','1=1') === $jobs,
    'One owner-locked patch rotates the revision without altering unrelated rules, Initiative controls or jobs.');
canvas_pipeline_check(vp3_coordination_rule_settings_v1($pdo,$owner,7,11) === $changed,
    'A fresh read restores the saved owner choices.');
canvas_pipeline_check(vp3_coordination_route_v1($changed,'planning',['pending_confirmation','conflict'])
    === ['specialist'=>'planning','trigger'=>'pending_confirmation','action'=>'watch'],
    'An explicit watch on the matched rule does not fall through to a lower-priority Chat action.');
coordination_rule_reject(409,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$base['revision'],[
    'gmail'=>['important'=>'ask'],
]));
foreach ([
    ['gmail'=>['needs_attention'=>'send_email']],
    ['gmail'=>['needs_attention'=>true]],
    ['tasks'=>['routine'=>'ask']],
    ['calendar'=>['changed'=>'report']],
    ['planning'=>[]],
    [],
] as $invalid) {
    coordination_rule_reject(422,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$changed['revision'],$invalid));
}
canvas_pipeline_check(vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$changed['revision'],$changed['rules']) === $changed
    && !$pdo->inTransaction(),
    'An unchanged full save preserves its revision and invalid saves roll back cleanly.');

$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(14,1,7),(15,1,NULL)");
canvas_pipeline_check(vp3_coordination_rule_settings_v1($pdo,$owner,7,14)['rules'] === $changed['rules']
    && vp3_coordination_rule_settings_v1($pdo,$owner,8,12)['rules'] === $defaults
    && vp3_coordination_rule_settings_v1($pdo,$owner,0,15)['rules'] === $defaults
    && vp3_coordination_rule_settings_v1($pdo,['id'=>2,'is_active'=>1],9,13)['rules'] === $defaults,
    'Rules follow their exact Agent across its Chats while sibling, System and foreign Agents remain independent.');
foreach ([
    [$owner,7,12],
    [$owner,0,11],
    [$owner,9,13],
    [['id'=>2,'is_active'=>1],7,11],
] as [$viewer,$agentId,$conversationId]) {
    coordination_rule_reject(403,fn()=>vp3_coordination_rule_settings_v1($pdo,$viewer,$agentId,$conversationId));
    coordination_rule_reject(403,fn()=>vp3_coordination_rule_settings_v1($pdo,$viewer,$agentId,$conversationId,
        $changed['revision'],['planning'=>['pending_confirmation'=>'ask']]));
}
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant'=>8];
try {
    coordination_rule_reject(403,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11));
    coordination_rule_reject(403,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$changed['revision'],$defaults));
} finally {
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
}
$reset = vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$changed['revision'],$defaults);
canvas_pipeline_check($reset['rules'] === $defaults && $reset['revision'] !== $base['revision'],
    'Returning to defaults does not revive an old form revision.');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{}' WHERE subject='top-of-mind-routing' AND user_id=1");
coordination_rule_reject(503,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11));
coordination_rule_reject(503,fn()=>vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$reset['revision'],$defaults));
canvas_pipeline_check($count() === 1 && !$pdo->inTransaction(), 'Malformed stored settings fail closed instead of reopening Chat delivery.');

echo 'COORDINATION_RULES=PASS ('.$checks.' assertions, '.($mysql?'mysql':'sqlite').")\n";
