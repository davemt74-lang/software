<?php
declare(strict_types=1);

function video_meeting_plan_action_snapshot_from_row_v18190(array $plan): array
{
    return [
        'plan_id'=>(int)($plan['id']??0),
        'agenda_item_id'=>(int)($plan['agenda_item_id']??0),
        'owner_label'=>(string)($plan['owner_label']??''),
        'target_at'=>(string)($plan['target_at']??''),
        'target_timezone'=>(string)($plan['target_timezone']??'UTC'),
        'verification_criteria'=>(string)($plan['verification_criteria']??''),
        'readiness'=>(string)($plan['readiness']??'needs_definition'),
    ];
}

function video_meeting_plan_action_snapshot_hash_v18190(array $snapshot): string
{
    $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return hash('sha256',is_string($json)?$json:'{}');
}

function video_meeting_plan_action_bound_draft_hash_v18190(array $execution): string
{
    $draft=json_decode((string)($execution['draft_json']??''),true);if(!is_array($draft))$draft=[];
    $kind=(string)($execution['action_kind']??'');
    $bound=match($kind){
        'task'=>['description'=>(string)($draft['description']??'')],
        'calendar'=>['date'=>(string)($draft['date']??''),'start_time'=>(string)($draft['start_time']??''),'timezone'=>(string)($draft['timezone']??''),'description'=>(string)($draft['description']??'')],
        'crm'=>['summary'=>(string)($draft['summary']??'')],
        'email'=>['body'=>(string)($draft['body']??'')],
        default=>[],
    };
    $json=json_encode($bound,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return hash('sha256',is_string($json)?$json:'{}');
}

function video_meeting_plan_action_guard_execution_v18190(PDO $pdo,array $execution,string $operation): void
{
    if(!table_exists('video_meeting_plan_action_handoffs')||!table_exists('video_meeting_followthrough_plans'))return;
    $lock=$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_plan_action_handoffs WHERE action_execution_id=? AND owner_user_id=? LIMIT 1'.$lock);
    $stmt->execute([(int)($execution['id']??0),(int)($execution['owner_user_id']??0)]);$handoff=$stmt->fetch();
    if(!is_array($handoff))return;
    $planStmt=$pdo->prepare('SELECT * FROM video_meeting_followthrough_plans WHERE id=? AND owner_user_id=? LIMIT 1'.$lock);
    $planStmt->execute([(int)$handoff['plan_id'],(int)$execution['owner_user_id']]);$plan=$planStmt->fetch();
    $planStale=!is_array($plan)||(string)($plan['readiness']??'')!=='ready';
    if(!$planStale)$planStale=!hash_equals((string)$handoff['plan_snapshot_hash'],video_meeting_plan_action_snapshot_hash_v18190(video_meeting_plan_action_snapshot_from_row_v18190($plan)));
    $draftStale=!hash_equals((string)($handoff['action_draft_hash']??''),video_meeting_plan_action_bound_draft_hash_v18190($execution));
    if($planStale||$draftStale){
        if((string)($handoff['status']??'')!=='stale')$pdo->prepare("UPDATE video_meeting_plan_action_handoffs SET status='stale',updated_at=NOW() WHERE id=?")->execute([(int)$handoff['id']]);
        $reason=$planStale?'The linked follow-through plan changed after handoff.':'Plan-bound fields in the Meeting Action draft changed after handoff.';
        throw new RuntimeException($reason.' Refresh the action from the current plan before '.$operation.'.');
    }
}
