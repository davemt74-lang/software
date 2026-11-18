<?php
declare(strict_types=1);

$before=get_defined_functions()['user'];
require_once dirname(__DIR__).'/includes/profile-commerce-followthrough-ref-v1.php';
$checks=0;
function pfr_ok(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function pfr_bad(callable $run,string $label):void{try{$run();}catch(RuntimeException|InvalidArgumentException){pfr_ok(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$now=new DateTimeImmutable('2026-11-18T12:00:00Z');$id=str_repeat('a',32);$cutoff=(new DateTimeImmutable('2026-11-17T00:00:00Z'))->getTimestamp();
$period=vp3_profile_analytics_period_encode_v1(1,$cutoff,'UTC','e');$ref=vp3_profile_commerce_followthrough_ref_v1($id,$period);
$decoded=vp3_profile_commerce_followthrough_decode_v1($ref,$now);
pfr_ok($decoded['ref']===$ref&&$decoded['publication_id']===$id&&$decoded['period_ref']===$period,'one canonical reference binds exact publication and completed period');
pfr_ok($decoded['window']===vp3_profile_analytics_period_decode_v1($period,$now)['window'],'follow-through uses the same owner-local period boundaries');
foreach($decoded['period_options'] as $option){$other=vp3_profile_commerce_followthrough_decode_v1($option['ref'],$now);pfr_ok($other['publication_id']===$id&&$other['cutoff']===$cutoff&&$other['timezone']==='UTC','period choice keeps exact publication and cutoff: '.$option['period']);}
foreach([['America/Havana','2026-03-08'],['America/Havana','2026-11-01'],['Asia/Kathmandu','2026-11-17']] as [$zone,$day]){
    $start=vp3_profile_analytics_day_start_v1($day,new DateTimeZone($zone));
    $r=vp3_profile_commerce_followthrough_ref_v1($id,vp3_profile_analytics_period_encode_v1(7,$start->getTimestamp(),$zone,'s'));
    $p=vp3_profile_commerce_followthrough_decode_v1($r,$now);
    pfr_ok($p['cutoff']===$start->getTimestamp()&&$p['timezone']===$zone&&$p['timezone_source']==='schedule','fixed promotion references preserve calendar and timezone provenance: '.$zone.' '.$day);
}
pfr_ok(vp3_profile_commerce_followthrough_href_v1(7,11,$ref)==='/chat.php?agent=7&conversation_id=11&marketing_followthrough='.rawurlencode($ref),'source links retain the original named Agent and private Chat');
pfr_ok(vp3_profile_commerce_followthrough_href_v1(0,11,$ref)==='/chat.php?agent=system&conversation_id=11&marketing_followthrough='.rawurlencode($ref),'system scope remains an explicit separate destination');
foreach([str_replace('marketing:','profile:',$ref),strtoupper($ref),$ref.'=',str_replace(':1:',':2:',$ref),str_replace(':e:',':u:',str_replace('VVRD','RXRjL1VUQw',$ref)),str_replace((string)$cutoff,(string)($cutoff+1),$ref),$ref.'&agent=9'] as $invalid)
    pfr_bad(fn()=>vp3_profile_commerce_followthrough_decode_v1($invalid,$now),'noncanonical alias, identity, cutoff or suffix is rejected: '.substr(hash('sha256',$invalid),0,8));
pfr_bad(fn()=>vp3_profile_commerce_followthrough_decode_v1($ref,new DateTimeImmutable('2026-11-16T12:00:00Z')),'an injected earlier observation cannot accept a future completed period');
pfr_bad(fn()=>vp3_profile_commerce_followthrough_ref_v1(str_repeat('a',31),$period),'publication identity has exactly thirty-two lowercase hexadecimal characters');
foreach([[-1,11],[7,0],[9007199254740992,11],[7,9007199254740992]] as [$agent,$chat])pfr_bad(fn()=>vp3_profile_commerce_followthrough_href_v1($agent,$chat,$ref),'unsafe or nonprivate destination is rejected: '.$agent.'/'.$chat);
pfr_ok(strlen(substr($ref,strlen('marketing:')))<=155&&preg_match('/\Amarketing:[A-Za-z0-9:@_-]{1,155}\z/D',$ref)===1,'the new source fits the existing shared safe reference envelope');
pfr_ok(!function_exists('db')&&!function_exists('vp3_profile_commerce_followthrough_v1')&&!function_exists('profile_agent_context'),'loading fixed-reference validation does not load storage, responder or provider paths');
echo 'PROFILE_COMMERCE_FOLLOWTHROUGH_REF_V1=PASS ('.$checks.' checks)'.PHP_EOL;
