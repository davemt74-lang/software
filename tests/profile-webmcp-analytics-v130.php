<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/profile-webmcp-analytics-v130.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$validSession='Sess_12345678';
$validInteraction='Interaction_12345678';
$ref=str_repeat('a',48);
$transport=vp3_profile_webmcp_telemetry_v130([
    'telemetry'=>[
        'webmcp_session_id'=>$validSession,
        'interaction_id'=>$validInteraction,
        'correlation_id'=>'Correlation_12345678',
        'agent_referral'=>strtoupper($ref),
        'extra_secret'=>'do-not-copy',
    ],
    'client_versions'=>[
        'manifest_versions'=>['vp3.profile.webmcp.v1'],
        'release_versions'=>['profile-webmcp-release-v196-20260929'],
        'runtime_build'=>'profile-webmcp-runtime-v100-20260928',
        'negotiation_contract'=>'vp3.profile.webmcp.negotiation.v1',
        'secret'=>'nope',
    ],
]);
t($transport['webmcp_session_id']===$validSession,'session id');
t($transport['interaction_id']===$validInteraction,'interaction id');
t($transport['correlation_id']==='Correlation_12345678','correlation id');
t($transport['agent_referral']===$ref,'referral normalization');
t(!array_key_exists('extra_secret',$transport),'transport allowlist');
t($transport['client_manifest_version']==='vp3.profile.webmcp.v1','manifest version');
t($transport['client_release_version']==='profile-webmcp-release-v196-20260929','release version');
t($transport['client_runtime_build']==='profile-webmcp-runtime-v100-20260928','runtime build');
t($transport['negotiation_contract']==='vp3.profile.webmcp.negotiation.v1','negotiation contract');
t(!array_key_exists('secret',$transport),'version metadata allowlist');

$bad=vp3_profile_webmcp_telemetry_v130(['telemetry'=>[
    'webmcp_session_id'=>'bad id',
    'interaction_id'=>'x',
    'agent_referral'=>'not-a-token',
]]);
t($bad['webmcp_session_id']===''&&$bad['interaction_id']===''&&$bad['agent_referral']==='','invalid transport rejection');
t($bad['client_manifest_version']===''&&$bad['client_release_version']===''&&$bad['client_runtime_build']===''&&$bad['negotiation_contract']==='','empty version defaults');

$context=[
    'owner_user_id'=>123,
    'property_id'=>44,
    'agent_contact_id'=>7,
    'surface'=>'external_site',
    'profile_username'=>'demo',
    'webmcp_session_id'=>$validSession,
    'interaction_id'=>$validInteraction,
    'correlation_id'=>'Correlation_12345678',
    'visitor_user_id'=>42,
    'referral_id'=>91,
    'referral_agent_contact_id'=>7,
    'attribution_origin'=>'agent_referral',
    'client_manifest_version'=>'vp3.profile.webmcp.v1',
    'client_release_version'=>'profile-webmcp-release-v196-20260929',
    'client_runtime_build'=>'profile-webmcp-runtime-v100-20260928',
    'negotiation_contract'=>'vp3.profile.webmcp.negotiation.v1',
    'negotiation_mode'=>'negotiated',
];
$envelope=vp3_profile_webmcp_event_envelope_v130(
    $context,'webmcp_tool_completed','vp3.profile.get','completed',123,
    ['result_code'=>'ok','order_id'=>55]
);
t($envelope['envelope_version']===VP3_PROFILE_WEBMCP_EVENT_ENVELOPE_V130,'envelope version');
t($envelope['event_name']==='webmcp_tool_completed','event name');
t(preg_match('/^[a-f0-9]{32}$/',$envelope['event_id'])===1,'server event id');
t(str_contains($envelope['occurred_at_utc'],'T'),'UTC event time');
t($envelope['owner_user_id']===123,'owner id');
t($envelope['property_id']===44,'property id');
t($envelope['agent_contact_id']===7,'agent contact id');
t($envelope['surface']==='external_site','surface');
t($envelope['tool']==='vp3.profile.get','tool');
t($envelope['status']==='completed','status');
t($envelope['duration_ms']===123,'duration');
t($envelope['correlation_id']==='Correlation_12345678','durable correlation');
t($envelope['referral_id']===91,'referral id');
t($envelope['referral_agent_contact_id']===7,'referral contact');
t($envelope['order_id']===55,'future link support');
t($envelope['result_code']==='OK','result code');
t($envelope['client_runtime_build']==='profile-webmcp-runtime-v100-20260928','durable runtime build');
t($envelope['negotiation_mode']==='negotiated','durable negotiation mode');
t(!array_key_exists('agent_referral',$envelope),'raw referral token must never enter durable envelope');
t(!array_key_exists('input',$envelope),'tool input must never enter durable envelope');
t(!array_key_exists('message',$envelope),'message text must never enter durable envelope');

$clamped=vp3_profile_webmcp_event_envelope_v130($context,'webmcp_tool_failed','VP3.BAD TOOL','error',99999999);
t($clamped['tool']==='','invalid tool name removed');
t($clamped['duration_ms']===3600000,'duration bounded');

try{
    vp3_profile_webmcp_event_envelope_v130($context,'made_up_event');
    t(false,'unknown event must fail');
}catch(InvalidArgumentException $e){t(true,'unknown event denied');}

t(vp3_profile_webmcp_referral_token_v130($ref)===$ref,'referral validator');
t(vp3_profile_webmcp_referral_token_v130('')==='','empty referral');
t(vp3_profile_webmcp_tool_name_v130('vp3.agent.message.send')==='vp3.agent.message.send','tool validator');

echo "PROFILE_WEBMCP_ANALYTICS_V130_PHP=PASS\n";
