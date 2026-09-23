<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-decision-calibration-v2580.php';

function v2580_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$under=vp3_cognitive_decision_ratio_factor_v2580([1.2,0.8,1.1,0.9],0.75,1.35);
v2580_assert($under['factor']===1.0&&!$under['calibrated']&&$under['sample_count']===4,'fewer than five samples preserve baseline factor');

$median=vp3_cognitive_decision_ratio_factor_v2580([0.5,1.0,1.2,2.0,0.0],0.75,1.35);
v2580_assert($median['calibrated']===true&&abs($median['median_ratio']-1.0)<0.0001,'five samples activate median-ratio calibration');
v2580_assert(abs($median['factor']-1.0)<0.0001,'median ratio inside bounds is preserved');

$high=vp3_cognitive_decision_ratio_factor_v2580([2,2,2,2,2],0.75,1.35);
v2580_assert(abs($high['factor']-1.35)<0.0001,'forecast/cost/token calibration is upper bounded');

$low=vp3_cognitive_decision_ratio_factor_v2580([0,0,0,0,0],0.75,1.35);
v2580_assert(abs($low['factor']-0.75)<0.0001,'zero observed ratio remains evidence and is lower bounded');

$valueLow=vp3_cognitive_decision_ratio_factor_v2580([0,0.2,0.4,0.5,0.6],0.70,1.15);
v2580_assert(abs($valueLow['factor']-0.4)<0.0001,'value reliability uses its independent bounded range');

$valueHigh=vp3_cognitive_decision_ratio_factor_v2580([1.5,1.6,1.7,1.8,1.9],0.70,1.15);
v2580_assert(abs($valueHigh['factor']-1.15)<0.0001,'value reliability upper bound is enforced');

$payload=[
    'goal_id'=>42,'executor'=>'cloud','execution_state'=>'ready','sequence_rank'=>2,
    'target_date'=>'2026-10-01','raw_forecast_seconds'=>7200,'forecast_calibration_factor'=>1.1,
    'raw_projected_remaining_cost_micros'=>120000,'cost_calibration_factor'=>0.9,
    'raw_projected_remaining_tokens'=>4000,'token_calibration_factor'=>1.2,
    'value_profile_id'=>7,'expected_value_micros'=>5000000,'expected_value_score'=>null,
    'value_reliability_factor'=>0.95,'budget_hard_hold'=>false,'reservation_state'=>'active',
    'replan_action'=>'protect_high_value_outcome','commitment_protected'=>false,
];
$a=vp3_cognitive_decision_snapshot_fingerprint_v2580($payload);
$b=vp3_cognitive_decision_snapshot_fingerprint_v2580($payload);
v2580_assert(strlen($a)===64&&hash_equals($a,$b),'decision fingerprint is stable and deterministic');

$changed=$payload;$changed['cost_calibration_factor']=1.1;
$c=vp3_cognitive_decision_snapshot_fingerprint_v2580($changed);
v2580_assert(!hash_equals($a,$c),'meaningful calibration change creates a new decision fingerprint');

v2580_assert(vp3_cognitive_decision_sql_datetime_v2580('2026-09-23T12:34:56Z')==='2026-09-23 12:34:56','ISO forecast timestamps normalize to UTC SQL datetime');
v2580_assert(vp3_cognitive_decision_sql_datetime_v2580('')===null,'empty timestamp remains unavailable rather than invented');

echo "Cognitive Decision Calibration v25.80 deterministic runtime: PASS\n";
