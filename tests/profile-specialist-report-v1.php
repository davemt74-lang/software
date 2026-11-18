<?php
declare(strict_types=1);

// Pure projection in a subdirectory installation, without a PDO or public runtime.
function url(string $path): string{return '/vp3'.$path;}
require_once dirname(__DIR__).'/includes/agent-profile-specialist-v1.php';
$checks=0;
function pfr_check(bool $condition,string $label): void
{
    global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";
}
$sections=vp3_profile_specialist_sections_v1(['A verified Profile update.'],[],[]);
$item=['specialist'=>'profile','ref'=>'profile:conversation:10','revision'=>str_repeat('a',64),'profile_source_kind'=>'conversation',
    'title'=>'Profile update','summary'=>'A verified Profile update.','url'=>'/vp3/profile-agent.php?tab=inbox&conversation=10',
    'status'=>'updated','priority'=>'low','trigger'=>'activity','disposition'=>'pending',
    'profile_presentation_owned'=>false,'legacy_delivered'=>false,'profile_report'=>$sections];
$report=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$item]]);
pfr_check($report['coverage']==='ready'&&count($report['findings'])===1&&$report['findings'][0]['url']===$item['url'],'the pure report preserves canonical subdirectory source links');
pfr_check(vp3_profile_specialist_url_v1('event','/vp3/profile-agent.php?tab=visitors&session=10')
    &&vp3_profile_specialist_url_v1('followup','/vp3/artist-listening.php?session=10'),'visit and transcript sources use the same installation base');
foreach(['/profile-agent.php?tab=inbox&conversation=10','/other/profile-agent.php?tab=inbox&conversation=10',
    '//attacker.example/vp3/profile-agent.php?tab=inbox&conversation=10','/vp3/profile-agent.php?tab=inbox&conversation=10&redirect=https://attacker.example'] as $bad)
    pfr_check(!vp3_profile_specialist_url_v1('conversation',$bad),'a noncanonical route or extra source query is rejected');
$visits=[];
for($id=20;$id<28;++$id){
    $visits[]=array_replace($item,['ref'=>'profile:event:'.$id,'revision'=>hash('sha256','visit-'.$id),'profile_source_kind'=>'event',
        'url'=>'/vp3/profile-agent.php?tab=visitors&session='.$id,'title'=>'Profile visit '.$id,'summary'=>'Verified visit record '.$id.'.',
        'profile_event_type'=>$id%2?'return_visitor':'profile_view','presentation_owner'=>$id%2?'profile_speech_pa4a':'profile_attention_pa4b',
        'profile_presentation_owned'=>true,'profile_report'=>vp3_profile_specialist_sections_v1(['Verified visit record '.$id.'.'])]);
}
$urgent=array_replace($item,['priority'=>'high','trigger'=>'needs_owner','presentation_owner'=>'profile_handoff_pa3',
    'profile_presentation_owned'=>true,'profile_report'=>vp3_profile_specialist_sections_v1(['A visitor needs a reviewed answer.'],
        ['Review the exact visitor thread.'],['The request is still unresolved.'],'Open the current conversation.',['Would you like to reply?'])]);
$grouped=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[...$visits,$urgent]]);
pfr_check($grouped['coverage']==='ready'&&$grouped['key_findings']===['A visitor needs a reviewed answer.','8 visit records in this checked view.'],
    'bounded routine traffic cannot consume the six finding slots ahead of an unresolved conversation');
pfr_check(count($grouped['findings'])===9&&array_column($grouped['findings'],'ref')===array_column([...$visits,$urgent],'ref')
    &&array_column($grouped['findings'],'revision')===array_column([...$visits,$urgent],'revision'),
    'grouping preserves each exact original reference and revision without a synthetic source');
pfr_check($grouped['findings'][0]['profile_event_type']==='profile_view'
    &&$grouped['findings'][1]['presentation_owner']==='profile_speech_pa4a'
    &&$grouped['findings'][0]['disposition']==='pending'&&$grouped['findings'][0]['legacy_delivered']===false,
    'group members retain their native event kind, presenter, disposition and actual delivery receipt state');
pfr_check($grouped['actions_followups']===$urgent['profile_report']['actions_followups']
    &&$grouped['open_questions']===$urgent['profile_report']['open_questions']
    &&$grouped['next_step']===$urgent['profile_report']['next_step']
    &&$grouped['questions_for_you']===$urgent['profile_report']['questions_for_you'],
    'visit volume creates no action, unresolved question or inferred intent');
$single=vp3_profile_specialist_report_v1(['coverage'=>'limited','items'=>[$visits[0]]]);
pfr_check($single['coverage']==='limited'&&$single['key_findings']===['1 visit record in this checked view.']
    &&$single['actions_followups']===[]&&$single['next_step']==='',
    'a single routine record retains limited checked-view coverage without claiming visitor totals');
$legacy=$visits[0];unset($legacy['profile_event_type'],$legacy['presentation_owner']);
$legacyReport=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$legacy]]);
pfr_check($legacyReport['key_findings']===$legacy['profile_report']['key_findings']
    &&!isset($legacyReport['findings'][0]['profile_event_type']),
    'an older untyped snapshot remains readable and is not guessed into a visit group');
$withAction=array_replace($visits[0],['profile_report'=>vp3_profile_specialist_sections_v1(['A verified event needs review.'],['Preserve this reviewed action.'])]);
$actionReport=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$withAction]]);
pfr_check($actionReport['key_findings']===['A verified event needs review.']&&$actionReport['actions_followups']===['Preserve this reviewed action.'],
    'a typed event with an actual action is not flattened into routine traffic');
foreach([
    array_replace($visits[0],['profile_event_type'=>['profile_view']]),
    array_replace($visits[0],['profile_event_type'=>'<script>profile_view</script>']),
    array_replace($item,['profile_event_type'=>'profile_view']),
    array_replace($visits[0],['presentation_owner'=>'attacker']),
    array_replace($visits[0],['profile_presentation_owned'=>false]),
] as $invalidMetadata){
    $invalidReport=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$invalidMetadata]]);
    pfr_check($invalidReport['coverage']==='limited'&&$invalidReport['findings']===[]&&$invalidReport['key_findings']===[],
        'malformed or contradictory grouping provenance is withheld');
}
$full=array_replace($item,['profile_report'=>vp3_profile_specialist_sections_v1(['One.','Two.','Three.','Four.','Five.','Six.'])]);
$bounded=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$visits[0],$full]]);
pfr_check($bounded['coverage']==='limited'&&$bounded['key_findings']===$full['profile_report']['key_findings']
    &&count($bounded['findings'])===2,'the bounded summary keeps exact visit sources even when six primary facts fill the prose budget');
$item['profile_report']['next_step']="Bad\u{202e}direction";
$invalid=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$item]]);
pfr_check($invalid['coverage']==='limited'&&$invalid['findings']===[],'malformed saved section text cannot bypass pure source projection');

require_once dirname(__DIR__).'/includes/agent-profile-analytics-summary-v1.php';
$cutoff=(new DateTimeImmutable('2024-11-17T00:00:00Z'))->getTimestamp();
$periodRef=vp3_profile_analytics_period_ref_v1(1,$cutoff,'UTC','utc_fallback');
$periodUrl=vp3_profile_analytics_period_href_v1(7,11,$periodRef);
pfr_check(vp3_profile_specialist_ref_kind_v1($periodRef)==='analytics'
    &&vp3_profile_specialist_url_v1('analytics',$periodUrl,$periodRef),'fixed-period references and exact scoped subdirectory destinations share pure validation');
pfr_check(vp3_profile_specialist_url_v1('analytics',vp3_profile_analytics_period_href_v1(0,11,$periodRef),$periodRef),'the system Agent has an explicit canonical period destination');
foreach([
    str_replace('/vp3/chat.php','/chat.php',$periodUrl),
    $periodUrl.'&agent=8',$periodUrl.'&conversation_id=12',$periodUrl.'#redirect',
    str_replace('agent=7&conversation_id=11','conversation_id=11&agent=7',$periodUrl),
    str_replace('conversation_id=11','conversation_id=9007199254740992',$periodUrl),
] as $bad)pfr_check(!vp3_profile_specialist_url_v1('analytics',$bad,$periodRef),'a period destination cannot introduce fallback, duplicate, reordered or unsafe scope');
$makeMetric=static fn(string $key,int $current,int $previous):array=>[
    'key'=>$key,'label'=>'MODEL_INSTRUCTION_MUST_NOT_BE_USED','unit'=>'count','definition'=>'Fixture native recorded measure.',
    'current'=>['value'=>$current,'observed_value'=>$current,'coverage'=>'complete','reason'=>''],
    'previous'=>['value'=>$previous,'observed_value'=>$previous,'coverage'=>'complete','reason'=>''],
    'comparison'=>['delta'=>$current-$previous,'percent'=>$previous>0?($current-$previous)/$previous*100:null],
];
$periodData=['contract'=>'vp3.profile-analytics-period.v1','ref'=>$periodRef,'revision'=>hash('sha256','fixed-complete-period'),
    'coverage'=>'ready','observed_at_utc_ms'=>1731802000000,
    'window'=>['period'=>1,'timezone'=>'UTC','timezone_source'=>'utc_fallback',
        'from_local'=>'2024-11-16 00:00:00','to_local'=>'2024-11-17 00:00:00',
        'from_utc_ms'=>($cutoff-86400)*1000,'to_utc_ms'=>$cutoff*1000,'as_of_utc_ms'=>$cutoff*1000,
        'previous'=>['from_local'=>'2024-11-15 00:00:00','to_local'=>'2024-11-16 00:00:00','from_utc_ms'=>($cutoff-172800)*1000,'to_utc_ms'=>($cutoff-86400)*1000]],
    'metrics'=>[$makeMetric('visit_records',4,2),$makeMetric('accepted_visitor_turns',3,1),$makeMetric('calls_accepted',1,0)],
    'money'=>[],'backlog'=>['as_of_utc_ms'=>1731802000000,'metrics'=>['unresolved'=>99],'secret'=>'CURRENT_BACKLOG_MUST_NOT_LEAK']];
$periodSource=vp3_profile_analytics_source_item_v1($periodData,7,11);
pfr_check($periodSource!==null&&$periodSource['profile_presentation_owned']&&$periodSource['presentation_owner']==='profile_briefing_v1'
    &&!$periodSource['legacy_delivered']&&$periodSource['question']===''&&$periodSource['next_step']===''
    &&str_contains($periodSource['summary'],'4 visit records; previous period: 2 visit records'),
    'a factual period belongs to existing briefing presentation without claiming a Chat delivery or asking for action');
pfr_check(!str_contains(json_encode($periodSource),'CURRENT_BACKLOG')&&!str_contains(json_encode($periodSource),'MODEL_INSTRUCTION')
    &&!vp3_profile_specialist_routine_visit_v1($periodSource),'period prose excludes dynamic backlog and arbitrary metric labels and never becomes a routine visit group');
$periodReport=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$periodSource]]);
pfr_check(count($periodReport['findings'])===1&&$periodReport['findings'][0]['ref']===$periodRef
    &&$periodReport['actions_followups']===[]&&$periodReport['questions_for_you']===[],'all five Profile report sections retain exact fixed-period evidence without fabricated obligations');
$later=$periodData;$later['observed_at_utc_ms']+=86400000;$later['backlog']['metrics']['unresolved']=150;
pfr_check(vp3_profile_analytics_source_item_v1($later,7,11)===$periodSource,'checked time and current backlog cannot revise fixed-period prose or its source revision');
$later['revision']=hash('sha256','corrected-period-proof');
pfr_check(vp3_profile_analytics_source_item_v1($later,7,11)['revision']!==$periodSource['revision'],'a corrected canonical period proof invalidates its saved source even if display totals happen to match');
$partialMetric=$makeMetric('visit_records',4,2);$partialMetric['current']=['value'=>null,'observed_value'=>4,'coverage'=>'partial','reason'=>'legacy_event_time'];
pfr_check(vp3_profile_analytics_metric_pair_v1($partialMetric)==='4 visit records observed (partial)','partial observations cannot be compared as a complete period total');
$partial=$periodData;$partial['coverage']='limited';$partial['metrics'][0]=$partialMetric;
pfr_check(str_starts_with(vp3_profile_analytics_source_item_v1($partial,7,11)['summary'],'Partial coverage. '),'briefing truncation cannot hide the leading coverage qualification');
$unknown=$partialMetric;$unknown['current']=['value'=>99999,'observed_value'=>null,'coverage'=>'unavailable','reason'=>'failed_read'];
pfr_check(vp3_profile_analytics_metric_text_v1($unknown)===null,'an unavailable measure cannot display an injected numeric value as a verified total');
$empty=$periodData;$empty['metrics']=[$makeMetric('visit_records',0,0)];
pfr_check(!vp3_profile_analytics_discoverable_v1($empty)&&vp3_profile_analytics_source_item_v1($empty,7,11)!==null,
    'empty verified periods stay inspectable through exact links without generating automatic chatter');
$empty['metrics'][0]=$makeMetric('visit_records',0,3);
pfr_check(vp3_profile_analytics_discoverable_v1($empty),'a completed zero following a verified nonzero prior period remains a meaningful comparison');
$cash=['key'=>'verified_payments','currency'=>'USD','amount_unit'=>'commerce_cent','scale'=>2,'scale_source'=>'vp3.commerce.v800',
    'current'=>['value_minor'=>'900719925474099312','observed_value_minor'=>'900719925474099312','coverage'=>'complete','reason'=>''],
    'previous'=>['value_minor'=>'0','observed_value_minor'=>'0','coverage'=>'complete','reason'=>''],'comparison'=>['delta_minor'=>'900719925474099312']];
pfr_check(vp3_profile_analytics_money_text_v1($cash)==='USD 9007199254740993.12 verified payments','large verified ledger amounts retain every minor-unit digit without floating-point rescaling');
$cash['scale']=0;pfr_check(vp3_profile_analytics_money_text_v1($cash)===null,'a conflicting money scale cannot silently rescale a ledger amount');
$cash['scale']=2;$deposits=$periodData;$deposits['metrics']=[$makeMetric('verified_paid_orders',0,0)];$deposits['money']=[$cash];
pfr_check(vp3_profile_analytics_discoverable_v1($deposits)
    &&str_starts_with(vp3_profile_analytics_source_item_v1($deposits,7,11)['summary'],'USD 9007199254740993.12 verified payments'),
    'a verified payment period can be summarized independently of zero fully paid orders');

pfr_check(!function_exists('vp3_profile_handoff_owner_pa3')&&!function_exists('vp3_profile_call_context_pa3c'),'pure section and URL validation loads no public responder or storage authority');
echo 'PROFILE_SPECIALIST_REPORT_V1=PASS ('.$checks.' checks; skips=0)' ."\n";
