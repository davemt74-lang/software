<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/profile-analytics-period-ref-v1.php';
$checks=0;
function papr_check(bool $condition,string $label):void{if(!$condition)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function papr_invalid(string $ref,string $label,?DateTimeImmutable $now=null):void
{
    try{vp3_profile_analytics_period_decode_v1($ref,$now);}catch(RuntimeException $error){papr_check($error->getCode()===422,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
$now=new DateTimeImmutable('2026-11-18 12:00:00',new DateTimeZone('UTC'));
$cutoff=(new DateTimeImmutable('2026-11-17 00:00:00',new DateTimeZone('UTC')))->getTimestamp();
$ref=vp3_profile_analytics_period_ref_v1(1,$cutoff,'UTC','explicit');
$decoded=vp3_profile_analytics_period_decode_v1($ref,$now);
papr_check($ref==='profile:analytics:v1:1:'.$cutoff.':e:VVRD'&&$decoded['timezone_source']==='explicit','canonical compact references preserve period, cutoff and explicit timezone provenance');
papr_check($decoded['window']['from_local']==='2026-11-16T00:00:00+00:00'&&$decoded['window']['to_local']==='2026-11-17T00:00:00+00:00','the current day is a completed half-open owner-local window');
papr_check($decoded['window']['previous']['from_local']==='2026-11-15T00:00:00+00:00'&&$decoded['window']['previous']['to_utc_ms']===$decoded['window']['from_utc_ms'],'the previous comparison window directly precedes the selected window');
foreach($decoded['period_options'] as $option){
    $choice=vp3_profile_analytics_period_decode_v1($option['ref'],$now);
    papr_check($choice['cutoff']===$cutoff&&$choice['timezone']==='UTC'&&$choice['timezone_source']==='explicit'&&$choice['period']===$option['period'],'every period choice retains exact cutoff and timezone: '.$option['period']);
}
foreach([['2026-03-09',23],['2026-11-02',25]] as [$day,$hours]){
    $end=(new DateTimeImmutable($day.' 00:00:00',new DateTimeZone('America/New_York')))->getTimestamp();
    $dst=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,$end,'America/New_York','schedule'),$now);
    papr_check(($dst['window']['to_utc_ms']-$dst['window']['from_utc_ms'])===3600000*$hours,'a local day follows the real DST boundary: '.$hours.' hours');
    papr_check($dst['timezone_source']==='schedule'&&str_ends_with($dst['window']['from_local'],$hours===23?'-05:00':'-04:00'),'encoded schedule provenance and historical offsets stay exact');
}
$havana=new DateTimeZone('America/Havana');
$gap=vp3_profile_analytics_day_start_v1('2026-03-08',$havana);
$gapPeriod=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,$gap->getTimestamp(),'America/Havana','explicit'),$now);
papr_check($gap->format('c')==='2026-03-08T01:00:00-04:00'&&$gapPeriod['window']['from_local']==='2026-03-07T00:00:00-05:00','a skipped midnight uses the first real local-day instant without moving the previous boundary');
$fold=vp3_profile_analytics_day_start_v1('2026-11-01',$havana);
$foldPeriod=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,$fold->getTimestamp(),'America/Havana','explicit'),$now);
papr_check($fold->format('c')==='2026-11-01T00:00:00-04:00'&&$foldPeriod['window']['to_utc_ms']===$fold->getTimestamp()*1000,'a repeated midnight chooses its first occurrence');
papr_invalid(vp3_profile_analytics_period_encode_v1(1,$fold->getTimestamp()+3600,'America/Havana','e'),'the second occurrence of repeated midnight is not another day boundary',$now);
papr_invalid(vp3_profile_analytics_period_encode_v1(1,$gap->getTimestamp()+1,'America/Havana','e'),'one second after a normalized midnight gap is not a valid cutoff',$now);
$gapFollowing=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,vp3_profile_analytics_day_start_v1('2026-03-09',$havana)->getTimestamp(),'America/Havana','explicit'),$now);
papr_check($gapFollowing['window']['from_local']==='2026-03-08T01:00:00-04:00'&&$gapFollowing['window']['to_utc_ms']-$gapFollowing['window']['from_utc_ms']===23*3600000,'a skipped-midnight reporting day still spans its exact 23 real hours');
$k=(new DateTimeImmutable('2026-11-17 00:00:00',new DateTimeZone('Asia/Kathmandu')))->getTimestamp();
$quarter=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(30,$k,'Asia/Kathmandu','explicit'),$now);
papr_check($quarter['window']['to_local']==='2026-11-17T00:00:00+05:45'&&$quarter['window']['from_local']==='2026-10-18T00:00:00+05:45','non-hour timezone offsets and 30 calendar days remain exact');
$fallback=vp3_profile_analytics_period_ref_v1(7,$cutoff,'UTC','utc_fallback');
papr_check(vp3_profile_analytics_period_decode_v1($fallback,$now)['timezone_source']==='utc_fallback','UTC fallback is an explicit retained provenance choice');
foreach([['America/Havana','2026-03-08'],['America/Santiago','2026-09-06']] as [$zone,$date]){
    $local=new DateTimeImmutable($date.' 15:00:00',new DateTimeZone($zone));
    $start=vp3_profile_analytics_local_day_start_v1($local);
    $skipped=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,$start->getTimestamp(),$zone,'explicit'),$now);
    papr_check(str_starts_with($skipped['window']['to_local'],$date.'T01:00:00'),'a skipped midnight retains the actual local-day boundary in '.$zone);
}
$havana=new DateTimeZone('America/Havana');$repeated=new DateTimeImmutable('2026-11-01 15:00:00',$havana);
$first=vp3_profile_analytics_local_day_start_v1($repeated);$later=$repeated->setTime(0,0);
papr_check($first->getTimestamp()===(new DateTimeImmutable('2026-11-01T04:00:00Z'))->getTimestamp()
    &&$later->getTimestamp()>$first->getTimestamp(),'a repeated midnight selects the same earliest boundary even after the offset changes');
papr_invalid(vp3_profile_analytics_period_encode_v1(1,$later->getTimestamp(),'America/Havana','e'),
    'the later repeated midnight cannot create a second reporting identity for the same date',$now);
$next=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_ref_v1(1,(new DateTimeImmutable('2026-11-02 00:00:00',$havana))->getTimestamp(),'America/Havana','e'),$now);
papr_check($next['window']['from_utc_ms']===$first->getTimestamp()*1000
    &&$next['window']['to_utc_ms']-$next['window']['from_utc_ms']===25*3600000,'a completed repeated-midnight day includes its entire 25 hours');
foreach([$ref.'=',str_replace(':e:',':u:',str_replace('VVRD','RXRjL1VUQw',$ref)),str_replace(':1:',':2:',$ref),
    str_replace(':e:',':x:',$ref),str_replace((string)$cutoff,(string)($cutoff+1),$ref),str_replace('VVRD','KzAwOjAw',$ref),
    str_replace('VVRD','Tm90L0Fa b25l',$ref),str_replace('VVRD','Li4vZXRjL3Bhc3N3ZA',$ref),str_replace('profile:analytics','profile.analytics',$ref),
    str_replace((string)$cutoff,'0946684800',$ref),str_replace((string)$cutoff,'9007199254740991',$ref)] as $bad)papr_invalid($bad,'malformed or noncanonical ref is rejected: '.substr(hash('sha256',$bad),0,8),$now);
$future=vp3_profile_analytics_period_encode_v1(1,(new DateTimeImmutable('2026-11-19',new DateTimeZone('UTC')))->getTimestamp(),'UTC','e');
papr_invalid($future,'a future local-midnight cutoff cannot claim a completed period',$now);
papr_check(strlen($quarter['ref'])<=180&&preg_match('/\A[a-zA-Z0-9:@_-]+\z/D',$quarter['ref'])===1,'fixed references remain inside the shared safe character and length contract');
papr_check(!function_exists('db')&&!function_exists('vp3_profile_handoff_owner_pa3')&&!function_exists('vp3_profile_call_row_pa3c'),'pure period parsing loads no storage, public responder or provider');
echo 'PROFILE_ANALYTICS_PERIOD_REF_V1=PASS ('.$checks.' checks)'.PHP_EOL;
