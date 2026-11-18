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
pfr_check(!function_exists('vp3_profile_handoff_owner_pa3')&&!function_exists('vp3_profile_call_context_pa3c'),'pure section and URL validation loads no public responder or storage authority');
echo 'PROFILE_SPECIALIST_REPORT_V1=PASS ('.$checks.' checks; skips=0)' ."\n";
