<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-opportunity-portfolio-v1.php';
function portfolio_check(bool $good,string $why): void {if(!$good)throw new RuntimeException($why);}
$hash=str_repeat('a',64);
$make=static fn(string $ref,string $path,string $priority='normal',string $status='open'):array=>[
  'ref'=>$ref,'revision'=>$hash,'specialist'=>'marketing','priority'=>$priority,
  'title'=>'Storefront Campaign','summary'=>'Verified owner-scoped Campaign data shows a setup decision.',
  'next_step'=>'Review saved campaign details before preparing draft creative.',
  'question'=>'Would you like a draft prepared for review?',
  'status'=>$status,'url'=>$path
];
$read=$make('marketing:campaign-readiness:7','/vp3/campaigns.php?merchant=2&edit_campaign=7');
$perf=$make('marketing:campaign-performance:7','/vp3/campaigns.php?merchant=2&edit_campaign=7');
$opt=$make('marketing:campaign-optimization:7','/vp3/campaigns.php?merchant=2&edit_campaign=7#campaign-optimization-v126');
$outcome=$make('marketing:campaign-outcome:7','/vp3/campaigns.php?merchant=2&edit_campaign=7');
$other=$make('marketing:campaign-readiness:8','/vp3/campaigns.php?merchant=2&edit_campaign=8','high');
$plan=$make('marketing:product-plan:9','/vp3/chat.php?agent=7&conversation_id=10&marketing_plan=9','low','photo_needed');
$plan['marketing_presentation_owned']=true;$plan['presentation_owner']='agent_product_launch';
$items=[$read['ref']=>$read,$perf['ref']=>$perf,$opt['ref']=>$opt,$outcome['ref']=>$outcome,
    $other['ref']=>$other,$plan['ref']=>$plan];
$portfolio=vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>$items]);
portfolio_check($portfolio['contract']===VP3_MARKETING_OPPORTUNITY_PORTFOLIO_V1
    &&$portfolio['coverage']==='ready'&&count($portfolio['opportunities'])===5,
    'Ranked portfolio must preserve distinct campaigns and bounded source count');
$refs=array_column($portfolio['opportunities'],'source_ref');
portfolio_check($refs[0]===$other['ref'] && $refs[1]===$read['ref'] && $refs[2]===$perf['ref'],
    'Verified Campaign blockers outrank mature performance review without inventing ROI');
portfolio_check(!in_array($opt['ref'],$refs,true)&&!in_array($outcome['ref'],$refs,true)
    &&in_array($plan['ref'],$refs,true),'Do not repeat three findings from one Campaign');
portfolio_check($portfolio['opportunities'][4]['notification_owner']==='product_launch'
    &&$portfolio['opportunities'][4]['source_href']===$plan['url'],
    'Native product specialist retains Chat notification ownership');
portfolio_check(count(vp3_marketing_opportunity_portfolio_v1(
    ['coverage'=>'limited','items'=>$items],2)['opportunities'])===2,
    'Pagination and limited coverage remain explicit');
foreach(['unavailable','unconfigured','bad'] as $coverage){
    $empty=vp3_marketing_opportunity_portfolio_v1(['coverage'=>$coverage,'items'=>$items]);
    portfolio_check($empty['opportunities']===[],'Missing or revoked coverage must fail closed');
}
$poison=$read;$poison['summary']='<script>danger</script> CLICK https://attacker.invalid';
$poison['title']='<img src=x onerror=alert(1)> Verified';
$render=vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[$poison['ref']=>$poison]]);
portfolio_check(count($render['opportunities'])===1
    &&!str_contains(json_encode($render,JSON_THROW_ON_ERROR),'attacker.invalid')
    &&!str_contains(json_encode($render,JSON_THROW_ON_ERROR),'<script>')
    &&!str_contains(json_encode($render,JSON_THROW_ON_ERROR),'<img'),
    'Source text must never become executable markup or outbound URL');
foreach([
  array_replace($read,['url'=>'https://attacker.invalid/campaigns.php?merchant=2&edit_campaign=7']),
  array_replace($read,['url'=>'//attacker.invalid/campaigns.php?merchant=2&edit_campaign=7']),
  array_replace($read,['url'=>'/vp3/campaigns.php?merchant=2&edit_campaign=7&redirect=https://attacker.invalid']),
  array_replace($read,['url'=>'/vp3/campaigns.php?merchant=2&edit_campaign=7%0a']),
  array_replace($read,['revision'=>'stale']),
  array_replace($read,['specialist'=>'profile']),
  array_replace($read,['next_step'=>''])
] as $wrong){
 portfolio_check(vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[$read['ref']=>$wrong]])['opportunities']===[],
    'Invalid source, revision or navigation must not create an action');
}
$wrong=$plan;$wrong['marketing_presentation_owned']=false;
portfolio_check(vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[$plan['ref']=>$wrong]])['opportunities']===[],
    'Do not displace Product Launch notification ownership');
$wrong=$plan;$wrong['url']='/vp3/chat.php?agent=7&conversation_id=10&marketing_plan=10';
portfolio_check(vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[$plan['ref']=>$wrong]])['opportunities']===[],
    'A forged product-plan Chat destination cannot be shown');
$stale=$read;$stale['status']='archived';
portfolio_check(vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>[$stale['ref']=>$stale]])['opportunities']===[],
    'Archived findings should not compete for marketing attention');
$permutation=array_reverse($items,true);
$first=vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>$items]);
$second=vp3_marketing_opportunity_portfolio_v1(['coverage'=>'ready','items'=>$permutation]);
portfolio_check($first===$second,'Portfolio must rank deterministically regardless of source query order');
echo "MARKETING_OPPORTUNITY_PORTFOLIO=PASS owner evidence, stable rank, cross-campaign diversity, native rights, privacy\n";
