<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-outcome-learning-v1.php';

function learning_check(bool $yes,string $label): void
{
    if(!$yes)throw new RuntimeException('FAIL '.$label);
    ++$GLOBALS['learningChecks'];echo 'PASS '.$label.PHP_EOL;
}
$GLOBALS['learningChecks']=0;
$before=['window_days'=>7,'window_start'=>'2026-10-01T00:00:00+00:00',
    'window_end'=>'2026-10-08T00:00:00+00:00',
    'metrics'=>['treatment_entries'=>45,'converted'=>2,'reward_claimed'=>1,'private_email'=>'must-not-leak']];
$after=['window_days'=>7,'window_start'=>'2026-10-11T12:00:00+00:00',
    'window_end'=>'2026-10-18T12:00:00+00:00',
    'metrics'=>['treatment_entries'=>60,'converted'=>5,'reward_claimed'=>3]];
$observed=vp3_marketing_outcome_learning_v1('observed',$before,$after);
$brief=vp3_marketing_outcome_learning_brief_v1($observed);
learning_check($observed['state']==='descriptive_only'
    &&$observed['direction']==='higher_observed_rate'
    &&$observed['prior_rate_bps']===444&&$observed['later_rate_bps']===833,
    'higher observed conversion rates use per-entry denominators, not raw order totals');
learning_check($observed['prior_entries']===45&&$observed['later_entries']===60
    &&$observed['scope']==='separate_observations_not_causal',
    'the exact separate observation denominators and noncausal scope are retained');
learning_check(str_contains($brief['summary'],'4.44%')
    &&str_contains($brief['summary'],'8.33%')
    &&str_contains($brief['summary'],'no causal improvement'),
    'specialist message reports rates and explicitly rejects causal improvement claims');
learning_check(!str_contains(json_encode($observed,JSON_THROW_ON_ERROR),'must-not-leak')
    &&!str_contains(json_encode($brief,JSON_THROW_ON_ERROR),'must-not-leak'),
    'only allowlisted aggregate facts are released, never private contact fields');
learning_check(vp3_marketing_outcome_learning_v1('awaiting_window',$before,$after)['state']==='unavailable'
    &&vp3_marketing_outcome_learning_v1('insufficient_sample',$before,$after)['state']==='unavailable'
    &&vp3_marketing_outcome_learning_v1('observed',null,$after)['state']==='unavailable',
    'unverified or incomplete windows cannot generate speculative learning');
$changed=$after;$changed['metrics']['converted']=1;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$changed)['direction']==='lower_observed_rate',
    'lower observed conversion is flagged for tracking and campaign review, not automatic mutation');
$equal=$before;
$equal['window_start']='2026-10-11T12:00:00+00:00';
$equal['window_end']='2026-10-18T12:00:00+00:00';
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$equal)['direction']==='same_observed_rate',
    'equal observed per-entry rates do not become an invented improvement');
$invalid=$after;$invalid['metrics']['treatment_entries']=5;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'low sample size is not interpreted as a recommendation win or loss');
$invalid=$after;$invalid['metrics']['converted']=null;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'unknown conversion evidence is not turned into a verified zero');
$invalid=$after;$invalid['metrics']['converted']=200;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'attributed conversion rate above the admitted-entry population is refused');
$invalid=$after;$invalid['window_days']=30;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'incomparable observation durations are refused');
$invalid=$after;$invalid['window_start']='2026-10-06T12:00:00+00:00';
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'overlapping observation periods are refused');
$invalid=$after;$invalid['window_start']='not-a-date';
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'malformed snapshot timestamps do not become learning');
$invalid=$after;$invalid['window_end']='2026-10-17T12:00:00+00:00';
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='unavailable',
    'a truncated observation period cannot be treated as an equal-duration comparison');
$invalid=$after;$invalid['metrics']['treatment_entries']=9007199254740991;
learning_check(vp3_marketing_outcome_learning_v1('observed',$before,$invalid)['state']==='descriptive_only',
    'very large verified denominators use bounded division without integer multiplication overflow');
learning_check(vp3_marketing_outcome_learning_brief_v1(['state'=>'descriptive_only','contract'=>'wrong'])===null,
    'an unversioned or forged learning brief is never used for Chat');
echo 'MARKETING_OUTCOME_LEARNING=PASS ('.$GLOBALS['learningChecks'].' assertions)'.PHP_EOL;
