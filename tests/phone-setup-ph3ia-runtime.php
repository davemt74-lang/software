<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/phone-setup-v170.php';
$checks=0;
function verify_ph3ia(bool $passed,string $label): void {global $checks;if (!$passed)throw new RuntimeException($label);$checks++;}
$none=vp3_phone_setup_plan_v170([]);
verify_ph3ia($none['configured_local_steps']===0,'empty setup starts at zero');
verify_ph3ia(!$none['local_configuration_ready'],'empty setup not staging ready');
verify_ph3ia(!$none['live_certified']&&!$none['automatic_activation'],'never silently activated or certified');
verify_ph3ia(count($none['steps'])===7,'all seven stages visible');
verify_ph3ia($none['steps'][0]['status']==='action_required','Twilio creation is first step');
verify_ph3ia($none['steps'][3]['status']==='manual_unverified','LiveKit provisioning cannot be inferred');
$all=vp3_phone_setup_plan_v170(['twilio_credentials_saved'=>true,'assigned_numbers'=>1,
    'voice_callback_saved'=>true,'inbound_voice_enabled'=>1,'cloud_sip_saved'=>true]);
verify_ph3ia($all['configured_local_steps']===4 && $all['local_configuration_ready'],'local config may be ready');
verify_ph3ia(!$all['live_certified'],'saved credentials are never live certification');
verify_ph3ia($all['steps'][3]['status']==='manual_unverified'&&$all['steps'][5]['status']==='manual_unverified',
    'external LiveKit and HomeServer checks are still pending');
$partial=vp3_phone_setup_plan_v170(['twilio_credentials_saved'=>true,'assigned_numbers'=>1,
    'voice_callback_saved'=>true,'inbound_voice_enabled'=>0,'cloud_sip_saved'=>true]);
verify_ph3ia($partial['configured_local_steps']===3 && !$partial['local_configuration_ready'],
    'saved callback without live inbound voicemail is insufficient');
$private='private-auth-token-should-not-appear';
$safe=vp3_phone_setup_plan_v170(['twilio_credentials_saved'=>true,'auth_token'=>$private,
    'livekit_api_secret'=>$private]);
verify_ph3ia(!str_contains((string)json_encode($safe),$private),'provider secrets are never copied to UI state');
foreach ($none['steps'] as $step) {
    verify_ph3ia(str_starts_with($step['action_url'],'#'),'all in-app actions are navigation only');
    if (isset($step['guide_url'])) verify_ph3ia(str_starts_with($step['guide_url'],'https://'),
        'external setup guide is HTTPS');
}
echo 'PHONE_SETUP_PH3IA_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
