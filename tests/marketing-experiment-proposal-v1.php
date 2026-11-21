<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-experiment-proposal-v1.php';
function ck(bool $v,string $msg):void {if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
$before=['window_days'=>7,'window_start'=>'2026-10-01T00:00:00+00:00','window_end'=>'2026-10-08T00:00:00+00:00',
    'metrics'=>['treatment_entries'=>40,'converted'=>4,'reward_claimed'=>1]];
$after=['window_days'=>7,'window_start'=>'2026-10-15T00:00:00+00:00','window_end'=>'2026-10-22T00:00:00+00:00',
    'metrics'=>['treatment_entries'=>50,'converted'=>2,'reward_claimed'=>2]];
$row=['recommendation_id'=>501,'type'=>'low-observed-conversion','state'=>'observed',
    'scope'=>'observational_not_causal','before'=>$before,'after'=>$after];
$p=vp3_marketing_experiment_proposal_v1(42,3,$row);
ck(($p['contract']??null)===VP3_MARKETING_EXPERIMENT_PROPOSAL_V1,'contract');
ck(($p['state']??null)==='proposal_only'&&$p['activation']==='not_supported','cannot launch');
ck($p['observed_direction']==='lower_observed_rate'&&$p['causality']==='not_established','no causal claim');
ck($p['source_revision']===vp3_marketing_experiment_proposal_v1(42,3,$row)['source_revision'],'stable evidence revision');
ck($p['source_revision']!==vp3_marketing_experiment_proposal_v1(42,4,$row)['source_revision'],'version change invalidates proposal');
ck($p['source_revision']!==vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>array_replace_recursive($after,['metrics'=>['converted'=>3]])]))['source_revision'],'new evidence invalidates proposal');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['state'=>'awaiting_window']))===null,'cannot infer from pending window');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['scope'=>'causal_lift']))===null,'unsupported causal claim rejected');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>null]))===null,'missing observation blocked');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>array_replace_recursive($after,['metrics'=>['converted'=>null]])]))===null,'unknown count not zero');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>array_replace_recursive($after,['metrics'=>['treatment_entries'=>5]])]))===null,'low sample blocked');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>array_replace($after,['window_start'=>'2026-10-06T00:00:00+00:00','window_end'=>'2026-10-13T00:00:00+00:00'])]))===null,'overlap blocked');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['after'=>array_replace($after,['window_days'=>8])]))===null,'mismatched windows blocked');
ck(vp3_marketing_experiment_proposal_v1(42,3,array_replace($row,['type'=>'unsupported']))===null,'unrecognized decision blocked');
ck(vp3_marketing_experiment_proposal_v1(0,3,$row)===null,'invalid campaign blocked');
ck(!str_contains(json_encode($p,JSON_THROW_ON_ERROR),'private_email'),'private evidence not exposed');
echo "16 experiment proposal checks passed\n";
