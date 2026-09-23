<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-current-state-v2590.php';
require_once __DIR__.'/../includes/cognitive-presentation-firewall-v2590.php';

function v2590_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

v2590_assert(vp3_cognitive_current_state_domain_v2590('calendar','calendar.event_updated')==='calendar','calendar events map to calendar domain');
v2590_assert(vp3_cognitive_current_state_domain_v2590('browser','browser.transaction_completed')==='browser_operations','browser events map to browser domain');
v2590_assert(vp3_cognitive_current_state_event_attention_v2590('approval.requested','processed')===true,'approval requests are attention candidates');
v2590_assert(vp3_cognitive_current_state_event_attention_v2590('order.paid','processed')===false,'normal terminal events are not attention candidates');

$materialized=vp3_cognitive_current_state_materialize_events_v2590([
    ['domain'=>'calendar','event_type'=>'calendar.event_updated','label'=>'Updated','attention'=>false,'fresh'=>true],
    ['domain'=>'calendar','event_type'=>'calendar.event_failed','label'=>'Older failure','attention'=>true,'fresh'=>true],
    ['domain'=>'subscription_billing','event_type'=>'billing.payment_failed','label'=>'Payment failed','attention'=>true,'fresh'=>true],
    ['domain'=>'research_knowledge','event_type'=>'research.failed','label'=>'Stale research failure','attention'=>true,'fresh'=>false],
]);
v2590_assert(($materialized['domains']['calendar']['event_type']??'')==='calendar.event_updated','latest domain event supersedes older failed state');
v2590_assert(($materialized['attention_candidate']['event_type']??'')==='billing.payment_failed','attention comes from latest fresh domain state');
v2590_assert((int)($materialized['attention_count']??0)===1,'stale or superseded failures do not inflate current attention');

$event=vp3_cognitive_current_state_event_v2590([
    'id'=>7,'source'=>'calendar','event_type'=>'calendar.event_updated','processing_status'=>'processed','verification_status'=>'trusted',
    'occurred_at'=>gmdate('Y-m-d H:i:s'),'received_at'=>gmdate('Y-m-d H:i:s'),
    'payload_json'=>json_encode([
        'calendar_event_id'=>12,'surface'=>'calendar','title'=>'Sensitive title',
        'system_prompt'=>'do not expose','token'=>'secret','payload'=>['raw'=>'secret']
    ]),
]);
v2590_assert(($event['refs']['calendar_event_id']??0)===12,'safe canonical references survive current-state materialization');
v2590_assert(!array_key_exists('title',$event['refs'])&&!array_key_exists('system_prompt',$event['refs'])&&!array_key_exists('token',$event['refs']),'arbitrary payload content does not enter current-state references');

$validated=vp3_cognitive_presentation_firewall_validate_v2590([
    'type'=>'attention','title'=>'Needs attention','summary'=>'Review calendar update','status'=>'attention',
    'next_action'=>'Review it','action_label'=>'Review','href'=>'/calendar.php',
    'raw_json'=>'{"secret":1}','system_prompt'=>'secret','confidence_vector'=>[0.9],
    'meta'=>['domain'=>'calendar','event_type'=>'calendar.event_updated','payload'=>'secret','surface'=>'calendar'],
]);
$p=$validated['presentation'];
v2590_assert(($p['href']??'')==='/calendar.php','same-origin presentation link is allowed');
v2590_assert(!array_key_exists('raw_json',$p)&&!array_key_exists('system_prompt',$p)&&!array_key_exists('confidence_vector',$p),'forbidden internal fields never enter presentation object');
v2590_assert(!array_key_exists('payload',$p['meta']??[]),'presentation metadata is allowlisted');
v2590_assert((int)($validated['audit']['dropped_top_level_fields']??0)>=3,'firewall audit records dropped internal fields');

$external=vp3_cognitive_presentation_firewall_validate_v2590(['href'=>'https://example.com','title'=>'External']);
v2590_assert(($external['presentation']['href']??'')==='','external presentation links are rejected');

$state=[
    'attention_candidate'=>[
        'domain'=>'subscription_billing','event_type'=>'billing.payment_failed','label'=>'Payment failed','fresh'=>true
    ],
    'session'=>[],'activity'=>[]
];
$presentation=vp3_cognitive_presentation_from_current_state_v2590($state);
v2590_assert(($presentation['type']??'')==='attention'&&str_contains((string)$presentation['summary'],'Payment failed'),'current-state attention becomes concise presentation');
v2590_assert(!isset($presentation['refs'])&&!isset($presentation['payload']),'presentation never copies current-state evidence structures');

echo "Cognitive Current State & Presentation Firewall v25.90 deterministic runtime: PASS\n";
