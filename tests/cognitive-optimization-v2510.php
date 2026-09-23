<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-optimization-v2510.php';

function v2510_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$now=1700000000;
$capacity=[
    'known'=>true,
    'executors'=>[
        'cloud'=>['max'=>1,'active'=>0,'free'=>1],
        'homeserver'=>['max'=>1,'active'=>0,'free'=>1],
    ],
];
$items=[
    [
        'goal_id'=>11,'title'=>'Urgent launch','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>95,'progress_percent'=>80,'target_date'=>gmdate('c',$now+1800),
        'score'=>0.82,'forecast_sequence_score'=>0.84,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'',
    ],
    [
        'goal_id'=>12,'title'=>'Unlock downstream work','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>75,'progress_percent'=>40,'target_date'=>gmdate('c',$now+86400),
        'score'=>0.78,'forecast_sequence_score'=>0.79,
        'dependency_leverage_goal_ids'=>[13,14,15],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'',
    ],
    [
        'goal_id'=>13,'title'=>'Quick verification','execution_mode'=>'autonomous','executor'=>'homeserver',
        'priority'=>60,'progress_percent'=>90,'target_date'=>gmdate('c',$now+172800),
        'score'=>0.65,'forecast_sequence_score'=>0.68,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[14],'execution_state'=>'verification','hold_reason'=>'',
    ],
    [
        'goal_id'=>21,'title'=>'Supervised one','execution_mode'=>'supervised','executor'=>'cloud',
        'priority'=>99,'progress_percent'=>10,'target_date'=>gmdate('c',$now+900),
        'score'=>0.99,'forecast_sequence_score'=>0.99,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'',
    ],
    [
        'goal_id'=>22,'title'=>'Supervised two','execution_mode'=>'supervised','executor'=>'cloud',
        'priority'=>98,'progress_percent'=>10,'target_date'=>gmdate('c',$now+900),
        'score'=>0.98,'forecast_sequence_score'=>0.98,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'shared_objective_goal_ids'=>[],'execution_state'=>'ready','hold_reason'=>'',
    ],
];

$strategies=vp3_cognitive_optimization_strategies_v2510();
v2510_assert($strategies===['balanced','protect_deadlines','unlock_dependencies','maximize_throughput'],'strategy set is canonical');

$comparison=vp3_cognitive_optimization_compare_v2510($items,$capacity,$now);
v2510_assert(count($comparison['scenarios'])===4,'all four strategies are simulated');
v2510_assert(in_array($comparison['recommended_strategy'],$strategies,true),'recommended strategy is canonical');

$comparisonAgain=vp3_cognitive_optimization_compare_v2510($items,$capacity,$now);
v2510_assert(
    json_encode($comparison,JSON_UNESCAPED_SLASHES)===json_encode($comparisonAgain,JSON_UNESCAPED_SLASHES),
    'strategy comparison is deterministic for identical evidence'
);

$expectedIds=array_map(static fn(array $item): int=>(int)$item['goal_id'],$items);
sort($expectedIds);
foreach($comparison['scenarios'] as $scenario){
    $ids=array_map('intval',$scenario['sequence_goal_ids']);sort($ids);
    v2510_assert($ids===$expectedIds,'strategy preserves the exact goal set: '.$scenario['strategy']);
    v2510_assert(!empty($scenario['advisory_only']),'strategy remains advisory: '.$scenario['strategy']);
    foreach(['deadline_risk_count','total_lateness_seconds','makespan_seconds','strategic_value','dependency_unlock_value'] as $metric){
        v2510_assert(array_key_exists($metric,$scenario['metrics']),'strategy exposes '.$metric.': '.$scenario['strategy']);
    }
}

$ordered=vp3_cognitive_optimization_resequence_v2510($items,$capacity,$now);
$byId=[];
foreach($items as $item)$byId[(int)$item['goal_id']]=$item;
foreach($ordered as $item){
    $id=(int)$item['goal_id'];
    v2510_assert((string)$item['executor']===(string)$byId[$id]['executor'],'optimizer never changes executor for goal '.$id);
    v2510_assert((string)$item['execution_mode']===(string)$byId[$id]['execution_mode'],'optimizer never changes execution mode for goal '.$id);
}
$supervised=array_values(array_map(
    static fn(array $item): int=>(int)$item['goal_id'],
    array_filter($ordered,static fn(array $item): bool=>(string)$item['execution_mode']==='supervised')
));
v2510_assert($supervised===[21,22],'supervised work preserves its existing relative order');

echo "Cognitive Optimization v25.10 deterministic runtime: PASS\n";
