<?php
declare(strict_types=1);

const VP3_VIDEO_MEETINGS_PLAN_ACTION_HANDOFF_V18190='video-meetings-plan-action-handoff-v18190-20260916';

require_once __DIR__.'/video-meetings-actions-v18150.php';
require_once __DIR__.'/video-meetings-adaptive-planning-v18180.php';
require_once __DIR__.'/video-meetings-plan-action-guard-v18190.php';

function video_meeting_plan_action_schema_ready_v18190(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach(['video_meeting_plan_action_handoffs','video_meeting_plan_action_handoff_events'] as $table){if(!table_exists($table))return false;}
    foreach(['plan_id','action_execution_id','agenda_item_id','plan_snapshot_json','plan_snapshot_hash','action_draft_hash','status'] as $column){if(!column_exists('video_meeting_plan_action_handoffs',$column))return false;}
    return true;
}

function video_meeting_plan_action_ensure_schema_v18190(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_adaptive_planning_schema_ready_v18180($pdo))video_meeting_adaptive_planning_ensure_schema_v18180($pdo);
    if(!video_meeting_action_schema_ready_v18150($pdo))video_meeting_action_ensure_schema_v18150($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_plan_action_handoffs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      plan_id BIGINT UNSIGNED NOT NULL,
      action_execution_id BIGINT UNSIGNED NOT NULL,
      agenda_item_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      plan_snapshot_json TEXT NOT NULL,
      plan_snapshot_hash CHAR(64) NOT NULL,
      action_draft_hash CHAR(64) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'current',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_plan_action_item (agenda_item_id),
      UNIQUE KEY uq_video_meeting_plan_action_execution (action_execution_id),
      INDEX idx_video_meeting_plan_action_owner (owner_user_id,status,updated_at,id),
      CONSTRAINT fk_video_meeting_plan_action_plan FOREIGN KEY (plan_id) REFERENCES video_meeting_followthrough_plans(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_execution FOREIGN KEY (action_execution_id) REFERENCES video_meeting_action_executions(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_item FOREIGN KEY (agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_plan_action_handoff_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      handoff_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_plan_action_event (handoff_id,id),
      CONSTRAINT fk_video_meeting_plan_action_event_handoff FOREIGN KEY (handoff_id) REFERENCES video_meeting_plan_action_handoffs(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_plan_action_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_plan_action_event_v18190(PDO $pdo,array $handoff,string $type,string $summary): void
{
    $pdo->prepare('INSERT INTO video_meeting_plan_action_handoff_events (handoff_id,meeting_id,owner_user_id,event_type,summary) VALUES (?,?,?,?,?)')->execute([(int)$handoff['id'],(int)$handoff['meeting_id'],(int)$handoff['owner_user_id'],video_meeting_action_text_v18150($type,40),video_meeting_action_text_v18150($summary,500)]);
}

function video_meeting_plan_action_plan_v18190(PDO $pdo,int $ownerUserId,int $itemId): array
{
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_followthrough_plans WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$itemId,$ownerUserId]);$plan=$stmt->fetch();
    if(!is_array($plan))throw new RuntimeException('Create the follow-through plan before handing it to Meeting Actions.');
    if((string)$plan['readiness']!=='ready')throw new RuntimeException('Define the plan owner, target, and verification criteria before handoff.');
    return $plan;
}

function video_meeting_plan_action_context_v18190(array $plan): string
{
    $target=(string)($plan['target_at']??'');$tz=(string)($plan['target_timezone']??'UTC');
    if($target!==''){try{$target=(new DateTimeImmutable($target,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i').' '.$tz;}catch(Throwable $e){}}
    return "Follow-through plan:\nOwner: ".(string)$plan['owner_label']."\nTarget: ".$target."\nVerification: ".(string)$plan['verification_criteria'];
}

function video_meeting_plan_action_map_draft_v18190(string $kind,array $draft,array $plan): array
{
    $context=video_meeting_plan_action_context_v18190($plan);
    if($kind==='task'){$draft['description']=trim((string)($draft['description']??'')."\n\n".$context);return $draft;}
    if($kind==='calendar'){
        $tz=(string)$plan['target_timezone'];
        try{$dt=(new DateTimeImmutable((string)$plan['target_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));$draft['date']=$dt->format('Y-m-d');$draft['start_time']=$dt->format('H:i');$draft['timezone']=$tz;}catch(Throwable $e){}
        $draft['description']=trim((string)($draft['description']??'')."\n\n".$context);return $draft;
    }
    if($kind==='crm'){$draft['summary']=trim((string)($draft['summary']??'')."\n\n".$context);return $draft;}
    if($kind==='email'){$draft['body']=trim((string)($draft['body']??'')."\n\n".$context);return $draft;}
    return $draft;
}

function video_meeting_plan_action_handoff_v18190(PDO $pdo,array $meeting,array $user,int $itemId): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_plan_action_schema_ready_v18190($pdo))throw new RuntimeException('Plan-to-Action Handoff is not ready. Run the current database upgrade first.');
    $plan=video_meeting_plan_action_plan_v18190($pdo,$ownerUserId,$itemId);
    $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,$itemId);$kind=video_meeting_action_require_eligible_v18150($item);
    $existing=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,$itemId);
    if($existing&&!in_array((string)$existing['status'],['needs_review','failed'],true))throw new RuntimeException('Reopen the Meeting Action before refreshing it from the plan.');
    if($existing&&(string)$existing['status']==='failed'&&(string)$existing['error_class']==='delivery_uncertain')throw new RuntimeException('This uncertain email delivery cannot be refreshed from the plan.');
    $prepared=video_meeting_action_prepare_v18150($pdo,$meeting,$user,$itemId);$executionId=(int)($prepared['id']??0);if($executionId<1)throw new RuntimeException('Meeting Action draft could not be prepared.');
    $mapped=video_meeting_plan_action_map_draft_v18190($kind,(array)($prepared['draft']??[]),$plan);
    $saved=video_meeting_action_save_draft_v18150($pdo,$meeting,$user,$executionId,$mapped);
    $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:throw new RuntimeException('Meeting Action draft could not be reloaded.');
    $snapshot=video_meeting_plan_action_snapshot_from_row_v18190($plan);$snapshotJson=video_meeting_action_json_v18150($snapshot);$snapshotHash=video_meeting_plan_action_snapshot_hash_v18190($snapshot);
    $pdo->prepare("INSERT INTO video_meeting_plan_action_handoffs (plan_id,action_execution_id,agenda_item_id,meeting_id,owner_user_id,plan_snapshot_json,plan_snapshot_hash,action_draft_hash,status) VALUES (?,?,?,?,?,?,?,?, 'current') ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),action_execution_id=VALUES(action_execution_id),plan_snapshot_json=VALUES(plan_snapshot_json),plan_snapshot_hash=VALUES(plan_snapshot_hash),action_draft_hash=VALUES(action_draft_hash),status='current',updated_at=NOW()")
        ->execute([(int)$plan['id'],$executionId,$itemId,(int)$meeting['id'],$ownerUserId,$snapshotJson,$snapshotHash,(string)$execution['draft_hash']]);
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_plan_action_handoffs WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$itemId,$ownerUserId]);$handoff=$stmt->fetch();if(!is_array($handoff))throw new RuntimeException('Plan handoff could not be recorded.');
    video_meeting_plan_action_event_v18190($pdo,$handoff,'handed_off','Organizer handed the current follow-through plan to Meeting Actions for review.');
    video_meeting_action_event_v18150($pdo,$execution,'plan_handoff','needs_review','needs_review','Follow-through plan applied to this action draft.',['plan_id'=>(int)$plan['id'],'plan_snapshot_hash'=>$snapshotHash]);
    return ['handoff'=>$handoff,'execution'=>$saved];
}

function video_meeting_plan_action_state_v18190(PDO $pdo,array $meeting,array $user): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_plan_action_schema_ready_v18190($pdo))throw new RuntimeException('Plan-to-Action Handoff is not ready. Run the current database upgrade first.');
    $stmt=$pdo->prepare("SELECT p.*,a.action_kind,a.approval_state,a.status AS agenda_status FROM video_meeting_followthrough_plans p JOIN video_meeting_agenda_items a ON a.id=p.agenda_item_id WHERE p.meeting_id=? AND p.owner_user_id=? ORDER BY a.sort_order,a.id");$stmt->execute([(int)$meeting['id'],$ownerUserId]);$items=[];
    foreach($stmt->fetchAll()?:[] as $plan){
        $itemId=(int)$plan['agenda_item_id'];$h=$pdo->prepare('SELECT * FROM video_meeting_plan_action_handoffs WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$h->execute([$itemId,$ownerUserId]);$handoff=$h->fetch();$execution=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,$itemId);
        $currentHash=video_meeting_plan_action_snapshot_hash_v18190(video_meeting_plan_action_snapshot_from_row_v18190($plan));$status='none';
        if(is_array($handoff)){$status=((string)$plan['readiness']==='ready'&&hash_equals((string)$handoff['plan_snapshot_hash'],$currentHash))?'current':'stale';if($status!==(string)$handoff['status'])$pdo->prepare('UPDATE video_meeting_plan_action_handoffs SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,(int)$handoff['id']]);}
        $items[]=['agenda_item_id'=>$itemId,'plan_id'=>(int)$plan['id'],'plan_readiness'=>(string)$plan['readiness'],'action_kind'=>(string)$plan['action_kind'],'approval_state'=>(string)$plan['approval_state'],'handoff_status'=>$status,'execution_id'=>(int)($execution['id']??0),'execution_status'=>(string)($execution['status']??''),'can_handoff'=>(string)$plan['readiness']==='ready'&&(string)$plan['agenda_status']==='follow_up'&&(string)$plan['approval_state']==='approved_for_agent_review'&&(!$execution||in_array((string)$execution['status'],['needs_review','failed'],true))];
    }
    return ['version'=>'v18.19','schema'=>'vp3.meeting.plan_action_handoff','items'=>$items,'policy'=>['explicit_handoff'=>true,'auto_approval'=>false,'auto_execution'=>false,'stale_blocks_approval'=>true,'stale_blocks_execution'=>true,'recipient_inference'=>false],'generated_at'=>gmdate('c')];
}
