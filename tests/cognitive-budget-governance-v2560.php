<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-budget-governance-v2560.php';

function v2560_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$now=strtotime('2026-09-23 12:00:00 UTC');
$daily=vp3_cognitive_budget_period_bounds_v2560('daily',$now);
$weekly=vp3_cognitive_budget_period_bounds_v2560('weekly',$now);
$monthly=vp3_cognitive_budget_period_bounds_v2560('monthly',$now);

v2560_assert($daily['start_at']==='2026-09-23T00:00:00+00:00','daily period starts at UTC midnight');
v2560_assert($daily['end_at']==='2026-09-24T00:00:00+00:00','daily period ends at next UTC midnight');
v2560_assert($weekly['start_at']==='2026-09-21T00:00:00+00:00','weekly period starts Monday UTC');
v2560_assert($weekly['end_at']==='2026-09-28T00:00:00+00:00','weekly period ends next Monday UTC');
v2560_assert($monthly['start_at']==='2026-09-01T00:00:00+00:00','monthly period starts first day UTC');
v2560_assert($monthly['end_at']==='2026-10-01T00:00:00+00:00','monthly period ends first day next month UTC');

v2560_assert(vp3_cognitive_budget_scope_kind_v2560('goal')==='goal','goal budget scope is supported');
v2560_assert(vp3_cognitive_budget_scope_kind_v2560('agent')==='agent','Agent budget scope is supported');
v2560_assert(vp3_cognitive_budget_scope_kind_v2560('project')==='project','project budget scope is supported');
v2560_assert(vp3_cognitive_budget_scope_key_v2560('account','anything')==='','account scope has no synthetic scope key');
v2560_assert(vp3_cognitive_budget_mode_v2560('soft')==='soft','soft budget mode is supported');
v2560_assert(vp3_cognitive_budget_mode_v2560('hard')==='hard','hard budget mode is supported');

$policy=[
    'cost_limit_micros'=>1000000,
    'token_limit'=>1000,
    'warning_percent'=>80,
];
$usage=['known_cost_micros'=>700000,'cloud_tokens_charged'=>700,'unknown_cost_requests'=>0];

$healthy=vp3_cognitive_budget_state_v2560($policy,$usage,50000,50);
v2560_assert($healthy['state']==='healthy','budget below warning threshold remains healthy');

$watch=vp3_cognitive_budget_state_v2560($policy,$usage,150000,150);
v2560_assert($watch['state']==='watch','forecast at warning threshold enters watch');

$risk=vp3_cognitive_budget_state_v2560($policy,$usage,400000,400);
v2560_assert($risk['state']==='at_risk','forecast beyond hard limit is at risk before actual exhaustion');

$exhausted=vp3_cognitive_budget_state_v2560($policy,['known_cost_micros'=>1000000,'cloud_tokens_charged'=>500,'unknown_cost_requests'=>0],0,0);
v2560_assert($exhausted['state']==='exhausted','actual cap exhaustion is explicit');

$uncertain=vp3_cognitive_budget_state_v2560($policy,['known_cost_micros'=>500000,'cloud_tokens_charged'=>500,'unknown_cost_requests'=>1],0,0);
v2560_assert($uncertain['state']==='uncertain','unknown priced usage under a configured cost cap is uncertain');

$zeroLimit=vp3_cognitive_budget_state_v2560(
    ['cost_limit_micros'=>0,'token_limit'=>null,'warning_percent'=>80],
    ['known_cost_micros'=>0,'cloud_tokens_charged'=>0,'unknown_cost_requests'=>0],
    1,0
);
v2560_assert($zeroLimit['state']==='at_risk','zero soft cap still registers projected pressure');
v2560_assert((float)$zeroLimit['max_forecast_ratio']>=1.0,'zero-limit projected pressure is not collapsed to zero');

$autonomousCloud=[
    'goal_id'=>1,'execution_mode'=>'autonomous','executor'=>'cloud',
    'progress_percent'=>80,'workflow_run_ids'=>[11],
];
$projection=vp3_cognitive_budget_goal_projection_v2560(
    $autonomousCloud,
    ['average_attributed_known_cost_micros'=>1000,'attributed_cloud_tokens_charged'=>400,'attributed_cloud_requests'=>2],
    ['cloud_average_known_cost_micros'=>2000,'cloud_tokens_charged'=>1000,'cloud_requests'=>10]
);
v2560_assert($projection['cost_known']===true&&$projection['cost_micros']===1000,'goal projection uses goal-specific attributed cost evidence');
v2560_assert($projection['tokens_known']===true&&$projection['tokens']===200,'goal projection uses proportional Cloud token history');

$manual=vp3_cognitive_budget_goal_projection_v2560(
    ['execution_mode'=>'manual','executor'=>'cloud'],null,[]
);
v2560_assert($manual['cost_micros']===0&&$manual['tokens']===0,'manual work is outside autonomous budget projection');

$local=vp3_cognitive_budget_goal_projection_v2560(
    ['execution_mode'=>'autonomous','executor'=>'homeserver'],null,[]
);
v2560_assert($local['cost_micros']===0&&$local['tokens']===0,'HomeServer work is outside Cloud spend projection');

$items=[
    ['goal_id'=>10,'commitment_protection_score'=>0.10,'commitment_at_risk'=>false,'target_date'=>'2026-09-24','priority'=>90,'score'=>0.9],
    ['goal_id'=>20,'commitment_protection_score'=>0.90,'commitment_at_risk'=>true,'target_date'=>'2026-09-25','priority'=>50,'score'=>0.5],
];
usort($items,'vp3_cognitive_budget_allocation_compare_v2560');
v2560_assert((int)$items[0]['goal_id']===20,'protected commitment receives budget allocation precedence');

$goalPolicy=['scope_kind'=>'goal','scope_key'=>'20'];
$agentPolicy=['scope_kind'=>'agent','scope_key'=>'7'];
$projectPolicy=['scope_kind'=>'project','scope_key'=>'project-x'];
$context=['goal_id'=>20,'agent_ids'=>[7],'project_keys'=>['project-x']];
v2560_assert(vp3_cognitive_budget_policy_matches_v2560($goalPolicy,$context),'goal policy matches canonical goal context');
v2560_assert(vp3_cognitive_budget_policy_matches_v2560($agentPolicy,$context),'Agent policy matches canonical Agent context');
v2560_assert(vp3_cognitive_budget_policy_matches_v2560($projectPolicy,$context),'project policy matches canonical workflow source context');

echo "Cognitive Budget Governance v25.60 deterministic runtime: PASS\n";
