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
$item['profile_report']['next_step']="Bad\u{202e}direction";
$invalid=vp3_profile_specialist_report_v1(['coverage'=>'ready','items'=>[$item]]);
pfr_check($invalid['coverage']==='limited'&&$invalid['findings']===[],'malformed saved section text cannot bypass pure source projection');
pfr_check(!function_exists('vp3_profile_handoff_owner_pa3')&&!function_exists('vp3_profile_call_context_pa3c'),'pure section and URL validation loads no public responder or storage authority');
echo 'PROFILE_SPECIALIST_REPORT_V1=PASS ('.$checks.' checks; skips=0)' ."\n";
