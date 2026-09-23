<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-forecast-v2490.php';
require_once __DIR__.'/../includes/cognitive-optimization-v2510.php';
require_once __DIR__.'/../includes/cognitive-resource-budget-v2520.php';
require_once __DIR__.'/../includes/cognitive-replanning-v2530.php';

function v2530_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$now=1700000000;
$capacity=[
    'known'=>true,
    'executors'=>[
        'cloud'=>['ready'=>true,'max'=>1,'active'=>1,'free'=>0],
        'homeserver'=>['ready'=>false,'max'=>0,'active'=>0,'free'=>0],
    ],
];

$items=[
    [
        'goal_id'=>201,'title'=>'Routine cloud work','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>60,'progress_percent'=>30,'target_date'=>gmdate('c',$now+48*3600),
        'score'=>0.62,'forecast_sequence_score'=>0.63,'optimization_strategy_score'=>0.64,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>202,'title'=>'Urgent cloud deadline','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>95,'progress_percent'=>70,'target_date'=>gmdate('c',$now+1200),
        'score'=>0.90,'forecast_sequence_score'=>0.91,'optimization_strategy_score'=>0.93,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>203,'title'=>'HomeServer unavailable','execution_mode'=>'autonomous','executor'=>'homeserver',
        'priority'=>90,'progress_percent'=>40,'target_date'=>gmdate('c',$now+3600),
        'score'=>0.86,'forecast_sequence_score'=>0.87,'optimization_strategy_score'=>0.88,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>204,'title'=>'Blocked dependency','execution_mode'=>'autonomous','executor'=>'cloud',
        'priority'=>99,'progress_percent'=>20,'target_date'=>gmdate('c',$now+900),
        'score'=>0.96,'forecast_sequence_score'=>0.97,'optimization_strategy_score'=>0.98,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[205],
        'execution_state'=>'blocked','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>301,'title'=>'Supervised A','execution_mode'=>'supervised','executor'=>'cloud',
        'priority'=>100,'progress_percent'=>10,'target_date'=>gmdate('c',$now+600),
        'score'=>1.0,'forecast_sequence_score'=>1.0,'optimization_strategy_score'=>1.0,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
    [
        'goal_id'=>302,'title'=>'Supervised B','execution_mode'=>'supervised','executor'=>'cloud',
        'priority'=>90,'progress_percent'=>10,'target_date'=>gmdate('c',$now+600),
        'score'=>0.9,'forecast_sequence_score'=>0.9,'optimization_strategy_score'=>0.9,
        'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
        'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
    ],
];

$overlay=vp3_cognitive_replanning_overlay_v2530($items,$capacity,$now);
v2530_assert(($overlay['replan']['contract']??'')===VP3_COGNITIVE_REPLANNING_CONTRACT_V2530,'replanning contract is canonical');
v2530_assert(!empty($overlay['replan']['replan_needed']),'plan drift is detected');
v2530_assert((int)$overlay['replan']['counts']['deadline_threats']>=1,'deadline threat is detected');
v2530_assert((int)$overlay['replan']['counts']['capacity_loss']===1,'executor capacity loss is detected');
v2530_assert((int)$overlay['replan']['counts']['dependency_stalls']===1,'dependency stall is detected');

$byGoal=[];
foreach($overlay['items'] as $item)$byGoal[(int)$item['goal_id']]=$item;
v2530_assert((string)$byGoal[203]['executor']==='homeserver','replan never switches unavailable executor');
v2530_assert((string)$byGoal[203]['replan_action']==='escalate_executor_unavailable','capacity loss escalates instead of switching executor');
v2530_assert((string)$byGoal[204]['replan_action']==='prioritize_dependency_unlock','blocked work routes to dependency recovery');
v2530_assert(empty($byGoal[204]['replan_safe']),'blocked work is not autonomously recovery-safe');
v2530_assert(!empty($byGoal[202]['replan_safe']),'urgent executable work remains recovery-safe');

$supervised=array_values(array_map(
    static fn(array $item): int=>(int)$item['goal_id'],
    array_filter($overlay['items'],static fn(array $item): bool=>(string)$item['execution_mode']==='supervised')
));
v2530_assert($supervised===[301,302],'supervised work preserves relative order');

$baselineExecutors=[];
foreach($items as $item)$baselineExecutors[(int)$item['goal_id']]=(string)$item['executor'];
foreach($overlay['items'] as $item){
    $id=(int)$item['goal_id'];
    v2530_assert((string)$item['executor']===$baselineExecutors[$id],'executor remains immutable for goal '.$id);
}

$overlayAgain=vp3_cognitive_replanning_overlay_v2530($items,$capacity,$now);
v2530_assert(
    json_encode($overlay,JSON_UNESCAPED_SLASHES)===json_encode($overlayAgain,JSON_UNESCAPED_SLASHES),
    'replanning is deterministic for identical evidence and time'
);

$healthyCapacity=[
    'known'=>true,
    'executors'=>[
        'cloud'=>['ready'=>true,'max'=>4,'active'=>0,'free'=>4],
        'homeserver'=>['ready'=>true,'max'=>2,'active'=>0,'free'=>2],
    ],
];
$healthyItems=[[
    'goal_id'=>401,'title'=>'Healthy work','execution_mode'=>'autonomous','executor'=>'cloud',
    'priority'=>50,'progress_percent'=>60,'target_date'=>gmdate('c',$now+7*86400),
    'score'=>0.5,'forecast_sequence_score'=>0.5,'optimization_strategy_score'=>0.5,
    'dependency_leverage_goal_ids'=>[],'blocked_by_goal_ids'=>[],
    'execution_state'=>'ready','hold_reason'=>'','requires_user'=>false,
]];
$healthy=vp3_cognitive_replanning_overlay_v2530($healthyItems,$healthyCapacity,$now);
v2530_assert(($healthy['replan']['health']??'')==='healthy','healthy portfolio stays healthy');
v2530_assert(empty($healthy['replan']['replan_needed']),'healthy portfolio does not force a replan');

echo "Cognitive Replanning v25.30 deterministic runtime: PASS\n";
