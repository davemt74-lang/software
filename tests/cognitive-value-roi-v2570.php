<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-value-roi-v2570.php';

function v2570_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

v2570_assert(vp3_cognitive_value_scope_kind_v2570('goal')==='goal','goal value scope is supported');
v2570_assert(vp3_cognitive_value_scope_kind_v2570('workflow')==='workflow','workflow value scope is supported');
v2570_assert(vp3_cognitive_value_scope_kind_v2570('project')==='project','project value scope is supported');
v2570_assert(vp3_cognitive_value_scope_kind_v2570('agent')==='agent','Agent value scope is supported');
v2570_assert(vp3_cognitive_value_scope_kind_v2570('meeting')==='meeting','meeting value scope is supported');
v2570_assert(vp3_cognitive_value_kind_v2570('money')==='money','money value kind is explicit');
v2570_assert(vp3_cognitive_value_kind_v2570('score')==='score','neutral score value kind is explicit');
v2570_assert(vp3_cognitive_value_realization_mode_v2570('manual_confirmation')==='manual_confirmation','manual realization mode is supported');
v2570_assert(vp3_cognitive_value_realization_mode_v2570('verified_completion')==='verified_completion','verified completion mode is supported');
v2570_assert(vp3_cognitive_value_realization_mode_v2570('profile_conversion')==='profile_conversion','Profile conversion mode is supported');

v2570_assert(vp3_cognitive_value_roi_percent_v2570(2000000,1000000,'USD')===100.0,'USD AI-cost ROI is deterministic');
v2570_assert(vp3_cognitive_value_roi_percent_v2570(500000,1000000,'USD')===-50.0,'negative USD AI-cost ROI is deterministic');
v2570_assert(vp3_cognitive_value_roi_percent_v2570(2000000,1000000,'EUR')===null,'non-USD value is not silently FX-converted');
v2570_assert(vp3_cognitive_value_roi_percent_v2570(null,1000000,'USD')===null,'unknown value has no ROI');
v2570_assert(vp3_cognitive_value_roi_percent_v2570(2000000,null,'USD')===null,'unknown AI cost has no ROI');
v2570_assert(vp3_cognitive_value_roi_percent_v2570(2000000,0,'USD')===null,'zero AI cost does not create infinite numeric ROI');

$base=['execution_mode'=>'autonomous','budget_hard_hold'=>false,'commitment_protection_score'=>0.0];
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570($base,['value_kind'=>'score','expected_score'=>100],1000000)===0.08,'high explicit score gets bounded positive value pressure');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570($base,['value_kind'=>'score','expected_score'=>0],1000000)===-0.08,'low explicit score gets bounded negative value pressure');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570(array_merge($base,['execution_mode'=>'manual']),['value_kind'=>'score','expected_score'=>100],1000000)===0.0,'manual work is not value-reordered');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570(array_merge($base,['budget_hard_hold'=>true]),['value_kind'=>'score','expected_score'=>100],1000000)===0.0,'hard budget hold outranks value');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570(array_merge($base,['commitment_protection_score'=>0.9]),['value_kind'=>'score','expected_score'=>100],1000000)===0.0,'protected commitment outranks value');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570($base,['value_kind'=>'money','currency'=>'EUR','expected_value_micros'=>5000000],1000000)===0.0,'non-USD money stays neutral for AI-cost planning');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570($base,['value_kind'=>'money','currency'=>'USD','expected_value_micros'=>5000000],null)===0.0,'unknown AI cost keeps monetary planning neutral');
v2570_assert(vp3_cognitive_value_planning_adjustment_v2570($base,['value_kind'=>'money','currency'=>'USD','expected_value_micros'=>5000000],1000000)>0.0,'explicit USD value with known AI cost may create positive bounded pressure');

v2570_assert(vp3_cognitive_value_item_at_risk_v2570(['budget_hard_hold'=>true])===true,'hard budget hold marks explicit unrealized value at risk');
v2570_assert(vp3_cognitive_value_item_at_risk_v2570(['commitment_at_risk'=>true])===true,'commitment risk marks value at risk');
v2570_assert(vp3_cognitive_value_item_at_risk_v2570(['execution_state'=>'ready'])===false,'healthy ready work is not value at risk');

$map=[
    'goal'=>['10'=>['id'=>1,'scope_kind'=>'goal','scope_key'=>'10']],
    'workflow'=>['101'=>['id'=>2,'scope_kind'=>'workflow','scope_key'=>'101']],
    'project'=>['project-x'=>['id'=>3,'scope_kind'=>'project','scope_key'=>'project-x']],
    'agent'=>['7'=>['id'=>4,'scope_kind'=>'agent','scope_key'=>'7']],
];
$runMeta=[101=>['agent_id'=>7,'source_key'=>'project-x']];
$resolved=vp3_cognitive_value_resolve_item_profile_v2570(['goal_id'=>10,'workflow_run_ids'=>[101]],$map,$runMeta);
v2570_assert((int)$resolved['profile']['id']===1&&$resolved['source']==='goal','exact goal profile wins inheritance precedence');

$mapNoGoal=$map;unset($mapNoGoal['goal']);
$resolved=vp3_cognitive_value_resolve_item_profile_v2570(['goal_id'=>10,'workflow_run_ids'=>[101]],$mapNoGoal,$runMeta);
v2570_assert((int)$resolved['profile']['id']===2&&$resolved['source']==='workflow','workflow value precedes project and Agent inheritance');

$ambiguousMap=['workflow'=>[
    '101'=>['id'=>2,'scope_kind'=>'workflow','scope_key'=>'101'],
    '102'=>['id'=>5,'scope_kind'=>'workflow','scope_key'=>'102'],
]];
$ambiguous=vp3_cognitive_value_resolve_item_profile_v2570(
    ['goal_id'=>10,'workflow_run_ids'=>[101,102]],$ambiguousMap,
    [101=>['agent_id'=>0,'source_key'=>''],102=>['agent_id'=>0,'source_key'=>'']]
);
v2570_assert($ambiguous['profile']===null&&!empty($ambiguous['ambiguous']),'conflicting inherited workflow values remain ambiguous');

$calibration=vp3_cognitive_value_calibration_v2570([
    ['profile'=>['value_kind'=>'score','expected_score'=>50],'realization'=>['verified'=>true,'score_value'=>50]],
    ['profile'=>['value_kind'=>'score','expected_score'=>100],'realization'=>['verified'=>true,'score_value'=>50]],
    ['profile'=>['value_kind'=>'score','expected_score'=>80],'realization'=>['verified'=>false,'score_value'=>80]],
],'score');
v2570_assert((int)$calibration['sample_count']===2,'calibration uses verified value evidence only');
v2570_assert(abs((float)$calibration['median_realization_ratio']-0.75)<0.0001,'calibration median realized/expected ratio is deterministic');

v2570_assert(vp3_cognitive_value_profile_target_key_v2570('product_converted',['target_id'=>42])==='product:id:42','canonical product conversion target key is stable');
v2570_assert(vp3_cognitive_value_profile_target_key_v2570('booking_converted',['target_slug'=>'desert-modern'])==='booking:slug:desert-modern','canonical booking conversion target key is stable');

echo "Cognitive Outcome Value & ROI v25.70 deterministic runtime: PASS\n";
