<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-forecast-v2490.php';
require_once __DIR__.'/../includes/cognitive-optimization-v2510.php';
require_once __DIR__.'/../includes/cognitive-resource-budget-v2520.php';

function v2520_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$now=1700000000;
$capacity=[
    'known'=>true,
    'executors'=>[
        'cloud'=>['ready'=>true,'max'=>1,'active'=>0,'free'=>1],
        'homeserver'=>['ready'=>true,'max'=>2,'active'=>0,'free'=>2],
    ],
];

$items=[
    [
        'goal_id'=>101,'title'=>'Urgent launch','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>95,'progress_percent'=>20,'target_date'=>gmdate('c',$now+3600),
        'score'=>0.84,'forecast_sequence_score'=>0.87,'optimization_strategy_score'=>0.90,
        'optimization_strategy'=>'protect_deadlines',
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>102,'title'=>'Routine autonomous work','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>55,'progress_percent'=>50,'target_date'=>gmdate('c',$now+48*3600),
        'score'=>0.60,'forecast_sequence_score'=>0.61,'optimization_strategy_score'=>0.62,
        'optimization_strategy'=>'protect_deadlines',
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>103,'title'=>'Needs approval','execution_mode'=>'autonomous','executor'=>'homeserver',
        'priority'=>99,'progress_percent'=>30,'target_date'=>gmdate('c',$now+1800),
        'score'=>0.92,'forecast_sequence_score'=>0.94,'optimization_strategy_score'=>0.95,
        'optimization_strategy'=>'protect_deadlines',
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'waiting_approval','hold_reason'=>'','requires_user'=>true,
    ],
    [
        'goal_id'=>104,'title'=>'Manual urgent work','execution_mode'=>'manual','executor'=>'cloud',
        'priority'=>100,'progress_percent'=>10,'target_date'=>gmdate('c',$now+900),
        'score'=>1.0,'forecast_sequence_score'=>1.0,'optimization_strategy_score'=>1.0,
        'optimization_strategy'=>'protect_deadlines',
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
];

$plan=vp3_cognitive_resource_budget_plan_v2520($items,$capacity,$now);
v2520_assert(($plan['contract']??'')===VP3_COGNITIVE_RESOURCE_BUDGET_CONTRACT_V2520,'resource budget contract is canonical');
v2520_assert((int)$plan['counts']['selected']>=2,'bounded reservation candidates are selected');
v2520_assert(in_array(101,$plan['executors']['cloud']['active_reserved_goal_ids'],true),'urgent autonomous cloud goal receives active reservation');
v2520_assert(!in_array(104,$plan['executors']['cloud']['selected_goal_ids'],true),'manual work is never reserved by autonomous budget');
v2520_assert((int)$plan['executors']['cloud']['reservation_limit']===1,'single cloud slot has bounded one-slot reservation ceiling');

$conditional=array_values(array_filter(
    $plan['reservations'],
    static fn(array $r): bool=>(int)$r['goal_id']===103
));
v2520_assert(count($conditional)===1,'approval-gated goal remains visible in reservation plan');
v2520_assert((string)$conditional[0]['reservation_state']==='conditional','approval-gated reservation is conditional');
v2520_assert(!in_array(103,$plan['executors']['homeserver']['active_reserved_goal_ids'],true),'conditional reservation does not hold active capacity');

$unreservedCandidates=[['goal_id'=>102]];
$held=vp3_cognitive_resource_claim_budget_v2520('cloud',1,$unreservedCandidates,$plan);
v2520_assert($held['admitted_goal_ids']===[],'unrelated autonomous claim is held while reserved goal is not claimable');
v2520_assert((int)$held['held_reserved_slots']===1,'reserved cloud slot remains protected');

$reservedCandidates=[['goal_id'=>101],['goal_id'=>102]];
$claimed=vp3_cognitive_resource_claim_budget_v2520('cloud',1,$reservedCandidates,$plan);
v2520_assert($claimed['admitted_goal_ids']===[101],'reserved goal consumes its protected slot first');
v2520_assert($claimed['reserved_admitted_goal_ids']===[101],'reserved admission is identified explicitly');
v2520_assert((int)$claimed['held_reserved_slots']===0,'no slot remains held after reserved goal is admitted');

$planAgain=vp3_cognitive_resource_budget_plan_v2520($items,$capacity,$now);
v2520_assert(
    json_encode($plan,JSON_UNESCAPED_SLASHES)===json_encode($planAgain,JSON_UNESCAPED_SLASHES),
    'resource plan is deterministic for identical evidence and time'
);

echo "Cognitive Resource Budget v25.20 deterministic runtime: PASS\n";
