<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-radar-conversion-outcome-v315.php';

function expect_same(mixed $actual,mixed $expected,string $message): void
{
    if($actual!==$expected){
        fwrite(STDERR,$message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

expect_same(agent_radar_outcome_v315_notification_hash(42),sha1('notification|42'),'Radar opportunity hash must match the cognitive notification suggestion identity.');
expect_same(agent_radar_outcome_v315_notification_hash(0),'','Missing notification identity must fail closed.');
expect_same(agent_radar_outcome_v315_event_name('conversion:purchase'),'purchase','Conversion ledger type must expose its exact event name.');
expect_same(agent_radar_outcome_v315_event_name('conversion:booking'),'booking','Named conversion events must preserve their exact suffix.');
expect_same(agent_radar_outcome_v315_event_name('referral'),'','A human referral without conversion must never auto-close as successful.');
expect_same(agent_radar_outcome_v315_event_name('page_view'),'','A page view must never auto-close as successful.');
expect_same(agent_radar_outcome_v315_time('not-a-date'),'','Invalid conversion timestamps must fail closed.');

$closure=file_get_contents(dirname(__DIR__) . '/includes/agent-radar-conversion-outcome-v315.php') ?: '';
$cognitive=file_get_contents(dirname(__DIR__) . '/includes/agent-cognitive-loop-v310.php') ?: '';
$persist=strpos($cognitive,'if($conversationId<1)return false;');
$shown=strpos($cognitive,'agent_action_v124_mark_shown($user');
if($persist===false||$shown===false||$persist>=$shown){
    fwrite(STDERR,"Cognitive shown evidence must remain after successful Main Feed persistence.\n");
    exit(1);
}
if(!str_contains($closure,"event_type LIKE 'conversion:%'")){
    fwrite(STDERR,"Automatic Radar closure must only scan explicit conversion events.\n");
    exit(1);
}
if(str_contains($closure,'display_name')||str_contains($closure,'operator_name')){
    fwrite(STDERR,"Automatic Radar closure must never correlate identity by display names.\n");
    exit(1);
}

echo "AGENT_RADAR_CONVERSION_OUTCOME_V315=PASS\n";
