<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-coordination-sources-v1.php';
require_once dirname(__DIR__).'/includes/agent-marketing-review-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';
function mpo_check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    ++$GLOBALS['mpoChecks'];echo 'PASS '.$message.PHP_EOL;
}
$GLOBALS['mpoChecks']=0;
$now=new DateTimeImmutable('2026-11-20T19:00:00+00:00');
$campaign=['id'=>77,'name'=>'Autumn Launch','business'=>'Shop','href'=>'/vp3/campaigns.php?merchant=17&edit_campaign=77',
    'status'=>'active','version'=>4,'window_state'=>'open','readiness'=>['state'=>'ready','checks'=>[]],
    'outcomes'=>['observations'=>['promotion'=>['state'=>'observed',
        'window_start'=>'2026-11-16T12:00:00+00:00','window_end'=>null,
        'metrics'=>['clicks'=>40,'checkout_starts'=>0,'paid_orders'=>0,'refunded_orders'=>0]]],
    'decisions'=>[]]];
$ref='marketing:campaign-performance:77';
$signal=vp3_marketing_performance_signal_v1($campaign,$now);
mpo_check(($signal['kind']??null)==='click_checkout_gap'
    &&str_contains($signal['summary'],'At least 40 verified promotion clicks'),
    'a mature tracked promotion with 40 clicks and no tracked checkout creates one bounded opportunity');
$findings=vp3_marketing_review_findings_v1(['campaigns'=>[$campaign]],null,$now);
mpo_check(count($findings)===1&&($findings[$ref]['trigger']??null)==='performance_gap'
    &&vp3_coordination_source_ref_valid_v1($ref),'native performance finding has a registered exact source');
$exact=vp3_marketing_review_findings_v1(['campaigns'=>[$campaign]],['campaign-performance:77'],$now);
mpo_check($exact===[$ref=>$findings[$ref]]
    &&vp3_marketing_review_findings_v1(['campaigns'=>[$campaign]],['campaign-outcome:77'],$now)===[],
    'exact reread selects only its named owner-scoped performance reference');
$more=$campaign;$more['outcomes']['observations']['promotion']['metrics']['clicks']=76;
$foundMore=vp3_marketing_review_findings_v1(['campaigns'=>[$more]],null,$now);
mpo_check($foundMore[$ref]['revision']===$findings[$ref]['revision']
    &&$foundMore[$ref]['summary']===$findings[$ref]['summary'],
    'more clicks inside the same evidenced gap do not reopen an identical Chat question');
$checkout=$campaign;$checkout['outcomes']['observations']['promotion']['metrics']['checkout_starts']=10;
$foundCheckout=vp3_marketing_review_findings_v1(['campaigns'=>[$checkout]],null,$now);
mpo_check(($foundCheckout[$ref]['trigger']??null)==='performance_gap'
    &&$foundCheckout[$ref]['revision']!==$findings[$ref]['revision']
    &&str_contains($foundCheckout[$ref]['summary'],'At least 10 tracked checkout starts'),
    'a changed conversion milestone creates one new source revision');
$resolved=$checkout;$resolved['outcomes']['observations']['promotion']['metrics']['paid_orders']=1;
mpo_check(vp3_marketing_performance_signal_v1($resolved,$now)===null,
    'verified paid-order attribution removes this no-order opportunity');
$premature=$campaign;$premature['outcomes']['observations']['promotion']['window_start']='2026-11-20T10:00:00+00:00';
mpo_check(vp3_marketing_performance_signal_v1($premature,$now)===null,
    'a recently published campaign does not receive a premature performance warning');
$unknown=$campaign;unset($unknown['outcomes']['observations']['promotion']['metrics']['paid_orders']);
mpo_check(vp3_marketing_performance_signal_v1($unknown,$now)===null,
    'missing paid-order evidence is not interpreted as a verified zero');
$unavailable=$campaign;$unavailable['outcomes']['observations']['promotion']['state']='unavailable';
mpo_check(vp3_marketing_performance_signal_v1($unavailable,$now)===null,
    'unavailable tracking cannot create a performance warning');
$blocked=$campaign;$blocked['readiness']['state']='needs_setup';
$paused=$campaign;$paused['status']='paused';
mpo_check(vp3_marketing_performance_signal_v1($blocked,$now)===null
    &&vp3_marketing_performance_signal_v1($paused,$now)===null,
    'readiness-blocked and paused campaigns cannot trigger an active-campaign performance recommendation');
$optimized=$campaign;$optimized['outcomes']['decisions']=[['type'=>'low-observed-conversion','status'=>'proposed']];
mpo_check(vp3_marketing_performance_signal_v1($optimized,$now)===null,
    'an existing verified optimization review suppresses a redundant performance alert');
$rules=['rules'=>vp3_coordination_rule_defaults_v1()];
$route=vp3_coordination_route_v1($rules,'marketing',['performance_gap']);
$goal=['tool'=>'coordination.review','coordination_id'=>str_repeat('a',64),
    'primary'=>$ref,'sources'=>[$ref=>$findings[$ref]['revision']],
    'coordination_route'=>$route,'coordination_marketing_report_v1'=>true];
$work=vp3_coordination_saved_result_v1($goal,[$ref=>$findings[$ref]],$route);
mpo_check($route['action']==='ask'&&vp3_coordination_chat_route_v1($route)
    &&vp3_coordination_marketing_report_goal_v1($goal)
    &&$work['output']['marketing_report']['questions_for_you']===[$findings[$ref]['question']]
    &&vp3_coordination_result_normalize_v1($work)===$work,
    'default rule provides a safe proof-bound owner question in the ordinary Agent Chat');
echo 'MARKETING_PERFORMANCE_OPPORTUNITIES=PASS ('.$GLOBALS['mpoChecks'].' assertions)'.PHP_EOL;
