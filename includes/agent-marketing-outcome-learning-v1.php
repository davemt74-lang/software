<?php
declare(strict_types=1);

/**
 * Phase 2B.6E. Descriptive feedback only from independently verified Campaign
 * observation windows. It never estimates causal lift, statistical certainty,
 * creative attribution, revenue, or the effect of an accepted recommendation.
 */
const VP3_MARKETING_OUTCOME_LEARNING_V1='vp3.marketing-outcome-learning.v1';

function vp3_marketing_outcome_learning_v1(string $state,?array $before,?array $after): array
{
    $unavailable=['contract'=>VP3_MARKETING_OUTCOME_LEARNING_V1,'state'=>'unavailable'];
    if($state!=='observed'||$before===null||$after===null)return $unavailable;
    foreach([$before,$after] as $window){
        $days=$window['window_days']??null;
        if(!is_int($days)||$days<7||$days>365
            ||!is_string($window['window_start']??null)||!is_string($window['window_end']??null))return $unavailable;
        foreach(['window_start','window_end'] as $time){
            if(!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$window[$time]))return $unavailable;
        }
        $windowStart=strtotime($window['window_start']);$windowEnd=strtotime($window['window_end']);
        if($windowStart===false||$windowEnd===false||$windowEnd<=$windowStart
            ||abs(($windowEnd-$windowStart)-$days*86400)>3600)return $unavailable;
        foreach(['treatment_entries','converted','reward_claimed'] as $key){
            $value=$window['metrics'][$key]??null;
            if(!is_int($value)||$value<0||$value>9007199254740991)return $unavailable;
        }
        if($window['metrics']['converted']>$window['metrics']['treatment_entries'])return $unavailable;
    }
    if($before['window_days']!==$after['window_days'])return $unavailable;
    $priorEnd=strtotime($before['window_end']);
    $afterStart=strtotime($after['window_start']);
    if($priorEnd===false||$afterStart===false||$afterStart<$priorEnd)return $unavailable;
    $a=$before['metrics']['treatment_entries'];$b=$after['metrics']['treatment_entries'];
    if($a<20||$b<20)return $unavailable;
    // Divide before multiplying to avoid overflow on high-volume campaigns.
    $priorRate=(int)round(10000*($before['metrics']['converted']/$a));
    $laterRate=(int)round(10000*($after['metrics']['converted']/$b));
    if($priorRate<0||$priorRate>10000||$laterRate<0||$laterRate>10000)return $unavailable;
    $direction=$laterRate>$priorRate?'higher_observed_rate'
        :($laterRate<$priorRate?'lower_observed_rate':'same_observed_rate');
    $next=match($direction){
        'higher_observed_rate'=>'Review which creative was actually applied, continue tracking, and consider a controlled test before expanding.',
        'lower_observed_rate'=>'Verify tracking and the saved Campaign edit, then consider a revised private creative draft for approval.',
        default=>'Continue observing and review Campaign implementation before choosing another creative change.',
    };
    return [
        'contract'=>VP3_MARKETING_OUTCOME_LEARNING_V1,
        'state'=>'descriptive_only',
        'direction'=>$direction,
        'prior_rate_bps'=>$priorRate,
        'later_rate_bps'=>$laterRate,
        'prior_entries'=>$a,
        'later_entries'=>$b,
        'window_end'=>$after['window_end'],
        'next_step'=>$next,
        'scope'=>'separate_observations_not_causal',
    ];
}

/** A narrowly worded specialist finding: rates, observation, and one review step. */
function vp3_marketing_outcome_learning_brief_v1(array $learning): ?array
{
    if(($learning['contract']??null)!==VP3_MARKETING_OUTCOME_LEARNING_V1
        ||($learning['state']??null)!=='descriptive_only'
        ||!in_array($learning['direction']??null,
            ['higher_observed_rate','lower_observed_rate','same_observed_rate'],true))return null;
    foreach(['prior_rate_bps','later_rate_bps'] as $key){
        if(!is_int($learning[$key]??null)||$learning[$key]<0||$learning[$key]>10000)return null;
    }
    $prior=number_format($learning['prior_rate_bps']/100,2,'.','');
    $later=number_format($learning['later_rate_bps']/100,2,'.','');
    $direction=match($learning['direction']){
        'higher_observed_rate'=>'higher','lower_observed_rate'=>'lower',
        default=>'the same when rounded to 0.01 percentage points',
    };
    $summary="Verified separate Campaign windows recorded $prior% attributed conversion before and $later% after: "
        ."the observed rate was $direction. This is descriptive only; no causal improvement or creative usage is established.";
    return ['summary'=>$summary,'next_step'=>$learning['next_step']];
}
