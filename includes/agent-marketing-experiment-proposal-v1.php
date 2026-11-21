<?php
declare(strict_types=1);

require_once __DIR__.'/agent-marketing-outcome-learning-v1.php';

/**
 * Phase 2B.6F — evidence-bound, private experiment DESIGN only.
 * No experiment identifier, campaign assignment, delivery, exposure, publish,
 * spend, statistical significance, or conversion attribution is fabricated.
 * A native Campaigns experiment engine must independently approve and record
 * randomization/assignment before any design can become an active experiment.
 */
const VP3_MARKETING_EXPERIMENT_PROPOSAL_V1='vp3.marketing-experiment-proposal.v1';

function vp3_marketing_experiment_proposal_v1(int $campaign,int $version,array $follow): ?array
{
    $id=$follow['recommendation_id']??null;
    if($campaign<1||$version<0||!is_int($id)||$id<1
        ||($follow['state']??null)!=='observed'
        ||($follow['scope']??null)!=='observational_not_causal'
        ||!in_array($follow['type']??null,
            ['offer-eligibility','fatigue','low-observed-conversion','conflict-volume'],true))return null;
    $before=$follow['before']??null;$after=$follow['after']??null;
    if(!is_array($before)||!is_array($after))return null;
    // Recalculate from independently verified windows; never trust a supplied
    // natural-language conclusion or client-provided learning data.
    $learning=vp3_marketing_outcome_learning_v1('observed',$before,$after);
    if(($learning['state']??null)!=='descriptive_only')return null;
    $direction=$learning['direction'];
    $hypothesis=match($direction){
        'higher_observed_rate'=>'A separately approved single creative change may outperform the unchanged control; the prior observation does not establish that.',
        'lower_observed_rate'=>'A separately approved revised creative may outperform the unchanged control after the observed conversion-rate decline.',
        default=>'A separately approved single creative variation may change attributed conversion relative to an unchanged control.',
    };
    $revision=hash('sha256',json_encode([
        VP3_MARKETING_EXPERIMENT_PROPOSAL_V1,$campaign,$version,$id,$follow['type'],
        $before['window_start'],$before['window_end'],$after['window_start'],$after['window_end'],
        $learning['prior_entries'],$learning['later_entries'],$learning['prior_rate_bps'],
        $learning['later_rate_bps'],$direction,
    ],JSON_THROW_ON_ERROR));
    return [
        'contract'=>VP3_MARKETING_EXPERIMENT_PROPOSAL_V1,
        'state'=>'proposal_only',
        'campaign_id'=>$campaign,
        'campaign_version'=>$version,
        'recommendation_id'=>$id,
        'source_revision'=>$revision,
        'observed_direction'=>$direction,
        'source_window_end'=>$learning['window_end'],
        'hypothesis'=>$hypothesis,
        'control'=>'Keep the current verified Campaign creative and eligibility unchanged.',
        'treatment'=>'Review and explicitly approve ONE alternative creative; verify actual implementation before measurement.',
        'primary_metric'=>'attributed_conversions_per_admitted_entry',
        'design'=>'Consider randomized, nonoverlapping control and treatment groups with comparable eligible audiences.',
        'measurement'=>'Record independent admission and conversion receipts for each assigned group; choose duration and power with the owner before starting.',
        'owner_next_step'=>'Review the hypothesis and candidate creative in Campaigns; do not launch until a native, audited experiment assignment path is available.',
        'activation'=>'not_supported',
        'causality'=>'not_established',
    ];
}
