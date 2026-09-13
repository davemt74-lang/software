<?php
declare(strict_types=1);
require __DIR__.'/../includes/agent-meeting-workflows-v1410.php';

function meeting_assert(bool $value,string $message): void { if(!$value){fwrite(STDERR,$message."\n");exit(1);} }

$booking=[
    'id'=>42,
    'owner_user_id'=>7,
    'start_at_utc'=>'2026-09-15 18:00:00',
    'completed_at'=>'2026-09-15 19:00:00',
    'updated_at'=>'2026-09-15 19:01:00',
];
$prepA=agent_meeting_workflow_source_key_v1410($booking,'meeting_prep');
$prepB=agent_meeting_workflow_source_key_v1410($booking,'meeting_prep');
meeting_assert($prepA===$prepB,'Automatic prep source keys must be deterministic for one appointment occurrence.');
meeting_assert(str_starts_with($prepA,'meeting_prep:booking:42:'),'Prep source key must identify the canonical booking.');
$prepRefresh=agent_meeting_workflow_source_key_v1410($booking,'meeting_prep','manual-refresh');
meeting_assert($prepRefresh!==$prepA,'Manual prep refreshes must be able to create a distinct report run.');
$followup=agent_meeting_workflow_source_key_v1410($booking,'meeting_followup');
meeting_assert(str_starts_with($followup,'meeting_followup:booking:42:'),'Follow-up source key must identify the canonical booking.');
meeting_assert($followup!==$prepA,'Prep and follow-up workflow occurrences must never dedupe together.');
meeting_assert(VP3_AGENT_MEETING_FOLLOWUP_NOTE_V1410==='Agent-generated post-meeting follow-up draft. Review before sending.','Follow-up idempotency marker must match the canonical Scheduling draft marker.');

echo "Agent Meeting Workflows v14.10 helpers: OK\n";