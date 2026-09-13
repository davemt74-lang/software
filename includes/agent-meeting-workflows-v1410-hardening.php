<?php
declare(strict_types=1);

/**
 * Phase 14.1 rollout hardening.
 *
 * Do not backfill years of completed meetings when the feature is first
 * deployed, and do not manufacture a second automated follow-up when the host
 * has already created one manually.
 */
function agent_meeting_workflow_reconcile_recent_followups_v1410(PDO $pdo,int $limit=80): int
{
    if(!agent_meeting_workflow_ready_v1410($pdo))return 0;
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->query("SELECT id
                       FROM agent_scheduling_bookings
                       WHERE (status='completed' OR lifecycle_status='completed')
                         AND COALESCE(completed_at,last_lifecycle_event_at,updated_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)
                       ORDER BY COALESCE(completed_at,last_lifecycle_event_at,updated_at) DESC,id DESC
                       LIMIT ".$limit);
    $created=0;
    foreach($stmt->fetchAll()?:[] as $row){
        try{
            $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$row['id']);
            if(!$booking)continue;
            $ownerUserId=(int)$booking['owner_user_id'];
            $sourceKey=agent_meeting_workflow_source_key_v1410($booking,'meeting_followup');
            $existingRun=agent_meeting_workflow_existing_v1410($pdo,$ownerUserId,$sourceKey);
            if($existingRun){
                // Existing Phase 14.1 runs may still need their Chat draft
                // surfaced after a transient failure, so reconciliation remains
                // idempotently active for them.
                agent_meeting_workflow_ensure_followup_v1410($pdo,$booking);
                continue;
            }
            $follow=$pdo->prepare('SELECT id FROM agent_scheduling_followups WHERE booking_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 1');
            $follow->execute([(int)$booking['id'],$ownerUserId]);
            if((int)$follow->fetchColumn()>0)continue; // host already handled it
            agent_meeting_workflow_ensure_followup_v1410($pdo,$booking);
            $created++;
        }catch(Throwable $ignored){}
    }
    return $created;
}

function agent_meeting_workflow_housekeeping_hardened_v1410(PDO $pdo,int $limit=80): array
{
    if(!agent_meeting_workflow_ready_v1410($pdo))return ['followups_created'=>0,'followups_executed'=>0];
    $created=agent_meeting_workflow_reconcile_recent_followups_v1410($pdo,$limit);
    $executed=agent_meeting_workflow_execute_followups_v1410($pdo,min(60,$limit));
    return ['followups_created'=>$created,'followups_executed'=>$executed];
}
