<?php
declare(strict_types=1);
// Pure-contract tests: no database, carrier traffic, or paid speech inference.
$settings=[
    'phone_voice_stream_sip_uri_v150'=>'sip:vp3@us1.sip.livekit.cloud;transport=tls',
    'phone_voice_stream_worker_secret_v150'=>str_repeat('q',48),
];
function setting(string $key,string $default=''): string {global $settings;return $settings[$key]??$default;}
function ai_decrypt_secret(string $cipher): string {return $cipher;}
require dirname(__DIR__).'/includes/phone-voice-v130.php';
require dirname(__DIR__).'/includes/phone-voice-stream-v150.php';

$checks=0;
function verify(bool $condition,string $label): void {global $checks;if (!$condition)throw new RuntimeException($label);$checks++;}
$route=['owner_user_id'=>12,'agent_id'=>7,'caller_e164'=>'+16025550101','called_e164'=>'+16025550100'];
$parent='CA'.str_repeat('a',32);$issued=1793520000;
verify(vp3_phone_stream_configured_v150(),'streaming configuration');
verify(vp3_phone_stream_sip_uri_v150(setting('phone_voice_stream_sip_uri_v150'))===setting('phone_voice_stream_sip_uri_v150'),'trusted SIP TLS target');
foreach (['sip:any@evil.test;transport=tls',
          'sip:vp3@us1.sip.livekit.cloud;transport=udp',
          'sip:vp3@us1.sip.livekit.cloud;transport=tls?X-Forged=1',
          'sip:user:password@us1.sip.livekit.cloud;transport=tls'] as $invalid) {
    try {vp3_phone_stream_sip_uri_v150($invalid);verify(false,'rejected SIP URI');}
    catch(InvalidArgumentException) {verify(true,'untrusted SIP URI refused');}
}
$secret=vp3_phone_stream_secret_v150();
$proof=vp3_phone_stream_proof_v150($secret,$parent,$issued,$route,42);
verify(strlen($proof)===64,'bounded HMAC');
verify($proof!==vp3_phone_stream_proof_v150($secret,$parent,$issued,$route,43),'HomeServer Agent bound');
verify($proof!==vp3_phone_stream_proof_v150($secret,$parent,$issued,[...$route,'called_e164'=>'+16025550102'],42),'DID bound');
verify($proof!==vp3_phone_stream_proof_v150($secret,$parent,$issued+1,$route,42),'invite time bound');
verify($proof!==vp3_phone_stream_proof_v150($secret,'CA'.str_repeat('b',32),$issued,$route,42),'parent call bound');
$xml=vp3_phone_stream_dial_v150($parent,$route,42,'https://vp3.example.test/api/phone-voice-inbound-v130.php',$issued);
verify(str_contains($xml,'<Dial')&&str_contains($xml,'<Sip>')&&!str_contains($xml,'<Gather'),'SIP media route');
verify(str_contains($xml,'phone-voice-stream-complete-v150.php'),'signed failure callback');
verify(str_contains($xml,'&amp;x-vp3-issued='.$issued),'escaped invite headers');
verify(str_contains($xml,'&amp;x-vp3-proof='.$proof),'signed proof delivered');
verify(!str_contains($xml,$secret),'secret stays server-side');
echo 'PHONE_VOICE_PH3G_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
