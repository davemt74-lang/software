<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/profile-webmcp-analytics-v130.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$validSession='Sess_12345678';
$validInteraction='Interaction_12345678';
$ref=str_repeat('a',48);
$transport=vp3_profile_webmcp_telemetry_v130(['telemetry'=>[
    'webmcp_session_id'=>$validSession,
    'interaction_id'=>$validInteraction,
    'agent_referral'=>strtoupper($ref),
    'extra_secret'=>'do-not-copy',
]]);
t($transport['webmcp_session_id']===$validSession,'session id');
t($transport['interaction_id']===$validInteraction,'interaction id');
t($transport['agent_referral']===$ref,'referral normalization');
t(!array_key_exists('extra_secret',$transport),'transport allowlist');

$bad=vp3_profile_webmcp_telemetry_v130(['telemetry'=>[
    'webmcp_session_id'=>'bad id',
    'interaction_id'=>'x',
    'agent_referral'=>'not-a-token',
]]);
t($bad===['webmcp_session_id'=>'','interaction_id'=>'','agent_referral'=>''],'invalid transport rejection');

$context=[
    'owner_user_id'=>123,
    'property_id'=>44,
    'agent_contact_id'=>7,
    'surface'=>'external_site',
    'profile_username'=>'demo',
    'webmcp_session_id'=>$validSession,
    'interaction_id'=>$validInteraction,
    'visitor_user_id'=>42,
    'referral_id'=>91,
    'referral_agent_contact_id'=>7,
    'attribution_origin'=>'agent_referral',
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
t($envelope['referral_id']===91,'referral id');
t($envelope['referral_agent_contact_id']===7,'referral contact');
t($envelope['order_id']===55,'future link support');
t($envelope['result_code']==='OK','result code');
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
