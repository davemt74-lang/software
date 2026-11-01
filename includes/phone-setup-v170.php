<?php
declare(strict_types=1);

/** PH3I-A: account setup guidance. No provisioning, carrier charges, or secrets. */
function vp3_phone_setup_plan_v170(array $state): array
{
    $local=[
        'twilio'=>!empty($state['twilio_credentials_saved']),
        'number'=>!empty($state['assigned_numbers']),
        'voice'=>!empty($state['voice_callback_saved']) && !empty($state['inbound_voice_enabled']),
        'sip'=>!empty($state['cloud_sip_saved']),
    ];
    $steps=[
        ['code'=>'twilio','name'=>'Create a Twilio account','status'=>$local['twilio']?'configured':'action_required',
            'description'=>'Create an account, then save its Account SID and Auth Token directly in VP3 Admin. Saving credentials is not a carrier connection test.',
            'action_label'=>'Configure Twilio in VP3','action_url'=>'#twilio-account',
            'guide_label'=>'Twilio account instructions','guide_url'=>'https://www.twilio.com/docs/usage/tutorials/how-to-use-your-free-trial-account'],
        ['code'=>'number','name'=>'Choose a test phone number','status'=>$local['number']?'configured':'action_required',
            'description'=>'Import an existing Twilio number or separately approve a number purchase. Assign the number to its owner and Cloud Agent.',
            'action_label'=>'Manage numbers','action_url'=>'#numbers',
            'guide_label'=>'Number setup instructions','guide_url'=>'https://www.twilio.com/docs/phone-numbers'],
        ['code'=>'voice','name'=>'Connect inbound voicemail first','status'=>$local['voice']?'configured':'action_required',
            'description'=>'Save the fixed HTTPS Voice webhook and enable incoming voicemail on the chosen test number. No AI call starts automatically.',
            'action_label'=>'Configure voice callback','action_url'=>'#voice'],
        ['code'=>'livekit','name'=>'Create LiveKit Cloud project and SIP trunk','status'=>'manual_unverified',
            'description'=>'In LiveKit, create a project, inbound SIP trunk and dispatch rule. Map the three PH3G signed SIP headers before the first test call.',
            'action_label'=>'Configure SIP bridge in VP3','action_url'=>'#voice',
            'guide_label'=>'LiveKit trunk instructions','guide_url'=>'https://docs.livekit.io/telephony/accepting-calls/inbound-trunk/'],
        ['code'=>'sip','name'=>'Save Cloud SIP bridge','status'=>$local['sip']?'configured':'action_required',
            'description'=>'Save the fixed LiveKit TLS SIP destination and a separate 32+ character worker secret. This only configures Cloud; it cannot verify LiveKit.',
            'action_label'=>'Configure SIP bridge','action_url'=>'#voice'],
        ['code'=>'worker','name'=>'Start and verify the HomeServer worker','status'=>'manual_unverified',
            'description'=>'Set LiveKit worker credentials locally, use Cloud resolve mode, grant a separate restricted Agent, and run python -m telephony.preflight.',
            'action_label'=>'View Cloud readiness','action_url'=>'#voice'],
        ['code'=>'staging','name'=>'Supervised test call and approval','status'=>'manual_unverified',
            'description'=>'Only after both preflights pass: verify real audio, disclosure, interruptions, per-turn revocation and voicemail fallback with an approved caller.',
            'action_label'=>'View per-number readiness','action_url'=>'#numbers'],
    ];
    return ['steps'=>$steps,'configured_local_steps'=>count(array_filter($local)),
        'total_local_steps'=>count($local),
        'local_configuration_ready'=>!in_array(false,$local,true),
        'live_certified'=>false,'automatic_activation'=>false];
}
