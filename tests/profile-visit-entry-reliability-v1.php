<?php
declare(strict_types=1);

// Exercise the production page-entry path, including its native proof, saved
// notification and speech source. No provider requests or public messages run.
require_once __DIR__.'/fixtures/profile-visitor-speech-pa4a.php';
require_once dirname(__DIR__).'/includes/profile-attention-pa4b.php';

$checks=0;
function pvisit_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;
}
function pvisit_reset(): void
{
    ps_reset();vp3_profile_attention_ensure_schema_pa4b(db());
}
function pvisit_last(): array
{
    return db()->query("SELECT * FROM profile_events WHERE event_type='profile_view' ORDER BY id DESC LIMIT 1")->fetch()?:[];
}

pvisit_reset();$pdo=db();$ctx=ps_context(2);pa3_login(0);
profile_runtime_record_view($pdo,$ctx['profile'],current_user());
$event=pvisit_last();$metadata=json_decode($event['metadata_json'],true);
pvisit_check((int)($event['visitor_user_id']??0)===0,'anonymous return records its actual actor');
pvisit_check((int)$pdo->query('SELECT visitor_user_id FROM profile_visit_sessions')->fetchColumn()===2,'anonymous return retains the separate CRM contact association');
pvisit_check(($metadata['source_authority']['visitor_user_id']??null)===0,'anonymous return has native event proof');
$item=$pdo->query("SELECT * FROM profile_speech_items_pa4a WHERE kind='arrival' ORDER BY id DESC LIMIT 1")->fetch();
pvisit_check((int)($item['source_event_id']??0)===(int)$event['id'],'anonymous arrival links its own notification event');
$source=vp3_profile_event_source_pa4b($pdo,ps_owner(),(int)$event['id']);
pvisit_check($source['status']==='verified','owner can verify the anonymous return without reusing CRM identity');
pvisit_check(!str_contains($source['projection']['speaker_label'],'visitor-a'),'anonymous announcement never exposes the remembered member');
profile_runtime_record_view($pdo,$ctx['profile'],null);
pvisit_check(pa3_count('profile_events')===1&&pa3_count('profile_speech_items_pa4a')===1,'same anonymous principal reload stays deduplicated');

pvisit_reset();$ctx=ps_context(2);profile_runtime_record_view($pdo,$ctx['profile'],$ctx['visitor']);$first=pvisit_last();
pa3_login(0);profile_runtime_record_view($pdo,$ctx['profile'],null);$second=pvisit_last();
pvisit_check((int)$second['id']!==(int)$first['id']&&(int)($second['visitor_user_id']??0)===0,'an account change cannot suppress a new principal arrival within the old visit window');
$items=$pdo->query("SELECT visitor_user_id,source_event_id FROM profile_speech_items_pa4a WHERE kind='arrival' ORDER BY id")->fetchAll();
pvisit_check(count($items)===2&&(int)$items[0]['source_event_id']===(int)$first['id']&&(int)$items[1]['source_event_id']===(int)$second['id'],'each current actor links only its matching saved event');
profile_runtime_record_view($pdo,$ctx['profile'],null);
pvisit_check(pa3_count('profile_events')===2,'current actor reload does not add another arrival');
pa3_login(2);profile_runtime_record_view($pdo,$ctx['profile'],current_user());
pvisit_check(pa3_count('profile_events')===2,'returning to a recent native principal reuses its own visit');

pvisit_reset();$ctx=ps_context();profile_runtime_record_view($pdo,$ctx['profile'],null);
$_COOKIE['sf_profile_conversation']=str_repeat('c',64);profile_runtime_record_view($pdo,$ctx['profile'],null);
$_COOKIE['sf_profile_conversation']=str_repeat('b',64);profile_runtime_record_view($pdo,$ctx['profile'],null);
pvisit_check(pa3_count('profile_events')===2,'interleaved native credentials reuse their own recent visit without a duplicate-key failure');

// Telemetry remains useful if optional speech storage is unavailable. The
// public page has not established its chat cookie yet on this first request.
pvisit_reset();$ctx=ps_context();unset($_COOKIE['sf_profile_conversation']);
$pdo->exec('DROP TABLE profile_speech_attempts_pa4a');
profile_runtime_record_view($pdo,$ctx['profile'],null);$event=pvisit_last();$metadata=json_decode($event['metadata_json'],true);
pvisit_check(is_string($_COOKIE['sf_profile_conversation']??null),'native visit establishes its own proof without depending on speech initialization');
pvisit_check(($metadata['source_authority']['visitor_user_id']??null)===0,'first native visit keeps proof when optional speech storage is missing');
pvisit_check(vp3_profile_event_source_pa4b($pdo,ps_owner(),(int)$event['id'])['status']==='verified','first native visit remains eligible for canonical text attention');
pvisit_check(pa3_count('profile_agent_messages')===0&&$GLOBALS['psProviderCalls']===[],'page tracking creates neither fake chat messages nor speech provider work');
pa3_login(1);profile_runtime_record_view($pdo,$ctx['profile'],current_user());
pvisit_check(pa3_count('profile_events')===1,'owner self-visit remains excluded');

require_once dirname(__DIR__).'/includes/agent-radar-native-profile.php';
$_SERVER['HTTP_USER_AGENT']='Fixture Headless Browser';
pvisit_check(vp3_radar_record_native_profile_request($pdo,$ctx['profile'],current_user())===false,'owner automation preview is excluded before Radar storage access');
vp3_radar_native_notify($pdo,['user_id'=>1],['id'=>55,'visitor_class'=>'ai_user_agent','display_name'=>'Fixture Search','operator_name'=>'Fixture'],
    ['id'=>1],['is_new'=>true],['id'=>321,'risk_score'=>5]);
$target=$pdo->query("SELECT target_url FROM notifications WHERE type='radar_agent_visit_needs_attention' ORDER BY id DESC LIMIT 1")->fetchColumn();
pvisit_check($target===url('/profile-agent.php?tab=radar'),'an automated-visitor notification opens the first-class Radar section');

echo 'PROFILE_VISIT_ENTRY_RELIABILITY_V1=PASS ('.$checks." checks)\n";
