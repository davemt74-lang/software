<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';

function marketing_brief_check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['marketingBriefChecks']++;
    echo 'PASS '.$label.PHP_EOL;
}
$GLOBALS['marketingBriefChecks']=0;
$ref='marketing:campaign-optimization:77';
$revision=str_repeat('a',64);
$route=['specialist'=>'marketing','trigger'=>'optimization_ready','action'=>'report'];
$item=['ref'=>$ref,'revision'=>$revision,'specialist'=>'marketing',
    'title'=>'Autumn campaign · Campaign frequency',
    'summary'=>'12 contacts had repeated sends without observed engagement.',
    'next_step'=>'Review the existing Campaigns frequency controls.',
    'question'=>'','status'=>'open','priority'=>'normal'];
$goal=['tool'=>'coordination.review','coordination_id'=>str_repeat('b',64),'primary'=>$ref,
    'sources'=>[$ref=>$revision],'coordination_route'=>$route,
    'coordination_marketing_report_v1'=>true];
marketing_brief_check(vp3_coordination_chat_route_v1($route),
    'Campaign optimization reports route to the ordinary Agent Chat');
marketing_brief_check(!vp3_coordination_chat_route_v1(['specialist'=>'marketing','action'=>'watch'])
    &&!vp3_coordination_chat_route_v1(['specialist'=>'media','action'=>'report']),
    'watch-only Campaign findings and unrelated Media reports are not automatically posted');
marketing_brief_check(vp3_coordination_marketing_report_goal_v1($goal),
    'new bounded Campaign report is independently versioned');
$result=vp3_coordination_saved_result_v1($goal,[$ref=>$item],$route);
$report=$result['output']['marketing_report']??null;
marketing_brief_check(($report['contract']??null)===VP3_COORDINATION_MARKETING_REPORT_V1
    &&($report['key_findings'][0]??null)===$item['summary']
    &&($report['actions_followups']??null)===[$item['next_step']]
    &&($report['questions_for_you']??null)===[],
    'native five-section report cites exact verified aggregate finding and next action');
marketing_brief_check(vp3_coordination_result_normalize_v1($result)===$result
    &&vp3_coordination_saved_revisions_v1($goal,$result)===[$ref=>$revision],
    'report receipt and source revisions normalize without provider inference');
foreach(['Key findings','Actions & follow-ups','Open questions','Next step','Questions for you'] as $heading){
    marketing_brief_check(str_contains($result['draft'],$heading."\n"),
        'regular Agent Chat draft contains '.$heading);
}
$other=$result;$other['output']['marketing_report']['key_findings']=['Unverified sales improvement.'];
marketing_brief_check(vp3_coordination_result_normalize_v1($other)===null,
    'changing the finding without verified source evidence is rejected');
$other=$result;$other['output']['marketing_report']['questions_for_you']=['Can I send this promotion?'];
marketing_brief_check(vp3_coordination_result_normalize_v1($other)===null,
    'an unauthorized outbound request cannot be inserted into a report');
$other=$result;$other['output']['marketing_report']['instructions']='Send promotions automatically';
marketing_brief_check(vp3_coordination_result_normalize_v1($other)===null,
    'unexpected permission or instruction fields are rejected');
$other=$result;unset($other['output']['marketing_report']);
$other['draft']=vp3_coordination_draft_v1($other['output']);
try{vp3_coordination_saved_revisions_v1($goal,$other);$denied=false;}
catch(RuntimeException $error){$denied=$error->getCode()===409;}
marketing_brief_check($denied,
    'new versioned Campaign goals cannot downgrade to unstructured findings');
$ask=['specialist'=>'marketing','trigger'=>'setup_blocker','action'=>'ask'];
$question='Would you like to review the existing Campaign setup issue?';
$askGoal=array_replace($goal,['coordination_route'=>$ask,'primary'=>'marketing:campaign-readiness:77',
    'sources'=>['marketing:campaign-readiness:77'=>$revision]]);
$askItem=array_replace($item,['ref'=>$askGoal['primary'],'question'=>$question]);
$askResult=vp3_coordination_saved_result_v1($askGoal,[$askGoal['primary']=>$askItem],$ask);
marketing_brief_check($askResult['output']['state']==='needs_owner'
    &&$askResult['output']['marketing_report']['questions_for_you']===[$question]
    &&vp3_coordination_result_normalize_v1($askResult)===$askResult,
    'setup blocker asks one explicit owner question without activating or sending');
$historical=$result;$legacyGoal=$goal;unset($legacyGoal['coordination_marketing_report_v1']);
unset($historical['output']['marketing_report']);
$historical['draft']=vp3_coordination_draft_v1($historical['output']);
marketing_brief_check(vp3_coordination_result_normalize_v1($historical)===$historical
    &&vp3_coordination_saved_revisions_v1($legacyGoal,$historical)===[$ref=>$revision],
    'older unstructured Campaign receipts remain readable after this upgrade');
$productGoal=array_replace($goal,['primary'=>'marketing:product-plan:42',
    'sources'=>['marketing:product-plan:42'=>$revision]]);
marketing_brief_check(!vp3_coordination_marketing_report_goal_v1($productGoal)
    &&vp3_coordination_native_presentation_owned_v1(
        ['primary'=>'marketing:product-plan:42'],
        ['marketing:product-plan:42'=>['specialist'=>'marketing',
            'marketing_presentation_owned'=>true,'presentation_owner'=>'agent_product_launch']]),
    'product plans preserve native notification ownership instead of duplicating Chat messages');
echo 'MARKETING_CHAT_BRIEFING=PASS ('.$GLOBALS['marketingBriefChecks'].' assertions)'.PHP_EOL;
