<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/phone-voice-readiness-v160.php';
$checks=0;
function check_ph3h(bool $condition,string $message): void {global $checks;if (!$condition)throw new RuntimeException($message);$checks++;}
$number=['provider'=>'twilio','provider_sid'=>'PN'.str_repeat('a',32),
    'phone_e164'=>'+16025550100','voice_enabled'=>1];
$context=['schema_ready'=>true,'twilio_ready'=>true,'owner_authorized'=>true,
    'callback_url'=>'https://vp3.test/api/phone-voice-inbound-v130.php','callback_valid'=>true,
    'bridge_configured'=>true,'homeserver_agent_id'=>42];
$carrier=['sid'=>$number['provider_sid'],'phone_number'=>$number['phone_e164'],
    'voice_url'=>$context['callback_url'],'voice_method'=>'POST'];
$ready=vp3_phone_stream_readiness_evaluate_v160($number,$context,$carrier);
check_ph3h($ready['ready_for_staging'],'all nonbillable checks ready');
check_ph3h($ready['live_certified']===false,'carrier call never auto-certified');
check_ph3h(count($ready['live_tests_pending'])===4,'staging call test plan');
foreach (['sid'=>'PN'.str_repeat('b',32),'phone_number'=>'+16025550101',
          'voice_url'=>'https://attacker.test/api/phone-voice-inbound-v130.php','voice_method'=>'GET'] as $key=>$value) {
    $broken=vp3_phone_stream_readiness_evaluate_v160($number,$context,[...$carrier,$key=>$value]);
    check_ph3h(!$broken['ready_for_staging'],'carrier '.$key.' mismatch blocked');
}
foreach (array_keys($context) as $key) {
    if ($key==='callback_url') continue;
    $broken=vp3_phone_stream_readiness_evaluate_v160($number,[...$context,$key=>false],$carrier);
    check_ph3h(!$broken['ready_for_staging'],'configuration '.$key.' failure blocked');
}
$unverified=vp3_phone_stream_readiness_evaluate_v160($number,$context,null);
check_ph3h(!$unverified['ready_for_staging'],'carrier read not skipped');
$carrierCheck=end($unverified['checks']);
check_ph3h($carrierCheck['status']==='unverified','unknown carrier labeled unverified');
check_ph3h(!str_contains(json_encode($ready),'secret'),'no secret field in output');
echo 'PHONE_VOICE_PH3H_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
