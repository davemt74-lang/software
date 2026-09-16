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

function video_meeting_plan_action_existing_v18190(PDO $pdo,int $ownerUserId,int $itemId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_plan_action_handoffs WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$itemId,$ownerUserId]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function video_meeting_plan_action_context_v18190(array $plan): string
{
    $target=(string)($plan['target_at']??'');$tz=(string)($plan['target_timezone']??'UTC');
    if($target!==''){try{$target=(new DateTimeImmutable($target,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i').' '.$tz;}catch(Throwable $e){}}
    return "[VP3_PLAN_CONTEXT_V18190]\nOwner: ".(string)$plan['owner_label']."\nTarget: ".$target."\nVerification: ".(string)$plan['verification_criteria']."\n[/VP3_PLAN_CONTEXT_V18190]";
}

function video_meeting_plan_action_replace_context_v18190(string $text,string $context): string
{
    $clean=preg_replace('/\s*\[VP3_PLAN_CONTEXT_V18190\].*?\[\/VP3_PLAN_CONTEXT_V18190\]\s*/s','',trim($text));
    return trim((string)$clean."\n\n".$context);
}

function video_meeting_plan_action_map_draft_v18190(string $kind,array $draft,array $plan): array
{
    $context=video_meeting_plan_action_context_v18190($plan);
    if($kind==='task'){$draft['description']=video_meeting_plan_action_replace_context_v18190((string)($draft['description']??''),$context);return $draft;}
    if($kind==='calendar'){
        $tz=(string)$plan['target_timezone'];
        try{$dt=(new DateTimeImmutable((string)$plan['target_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));$draft['date']=$dt->format('Y-m-d');$draft['start_time']=$dt->format('H:i');$draft['timezone']=$tz;}catch(Throwable $e){}
        $draft['description']=video_meeting_plan_action_replace_context_v18190((string)($draft['description']??''),$context);return $draft;
    }
    if($kind==='crm'){$draft['summary']=video_meeting_plan_action_replace_context_v18190((string)($draft['summary']??''),$context);return $draft;}
    if($kind==='email'){$draft['body']=video_meeting_plan_action_replace_context_v18190((string)($draft['body']??''),$context);return $draft;}
    return $draft;
}

function video_meeting_plan_action_handoff_v18190(PDO $pdo,array $meeting,array $user,int $itemId): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_plan_action_schema_ready_v18190($pdo))throw new RuntimeException('Plan-to-Action Handoff is not ready. Run the current database upgrade first.');
    $plan=video_meeting_plan_action_plan_v18190($pdo,$ownerUserId,$itemId);
    $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,$itemId);$kind=video_meeting_action_require_eligible_v18150($item);
    $snapshot=video_meeting_plan_action_snapshot_from_row_v18190($plan);$snapshotJson=video_meeting_action_json_v18150($snapshot);$snapshotHash=video_meeting_plan_action_snapshot_hash_v18190($snapshot);
    $existing=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,$itemId);$prior=video_meeting_plan_action_existing_v18190($pdo,$ownerUserId,$itemId);
    if($existing&&$prior&&(int)$prior['action_execution_id']===(int)$existing['id']&&hash_equals((string)$prior['plan_snapshot_hash'],$snapshotHash)&&hash_equals((string)$prior['action_draft_hash'],video_meeting_plan_action_bound_draft_hash_v18190($existing))){
        return ['handoff'=>$prior,'execution'=>video_meeting_action_public_v18150($pdo,$existing,$meeting,$user,true),'idempotent'=>true];
    }
    if($existing&&!in_array((string)$existing['status'],['needs_review','failed'],true))throw new RuntimeException('Reopen the Meeting Action before refreshing it from the plan.');
    if($existing&&(string)$existing['status']==='failed'&&(string)$existing['error_class']==='delivery_uncertain')throw new RuntimeException('This uncertain email delivery cannot be refreshed from the plan.');
    $prepared=video_meeting_action_prepare_v18150($pdo,$meeting,$user,$itemId);$executionId=(int)($prepared['id']??0);if($executionId<1)throw new RuntimeException('Meeting Action draft could not be prepared.');
    $mapped=video_meeting_plan_action_map_draft_v18190($kind,(array)($prepared['draft']??[]),$plan);
    $saved=video_meeting_action_save_draft_v18150($pdo,$meeting,$user,$executionId,$mapped);
    $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:throw new RuntimeException('Meeting Action draft could not be reloaded.');
    $boundDraftHash=video_meeting_plan_action_bound_draft_hash_v18190($execution);
    $pdo->prepare("INSERT INTO video_meeting_plan_action_handoffs (plan_id,action_execution_id,agenda_item_id,meeting_id,owner_user_id,plan_snapshot_json,plan_snapshot_hash,action_draft_hash,status) VALUES (?,?,?,?,?,?,?,?, 'current') ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),action_execution_id=VALUES(action_execution_id),plan_snapshot_json=VALUES(plan_snapshot_json),plan_snapshot_hash=VALUES(plan_snapshot_hash),action_draft_hash=VALUES(action_draft_hash),status='current',updated_at=NOW()")
        ->execute([(int)$plan['id'],$executionId,$itemId,(int)$meeting['id'],$ownerUserId,$snapshotJson,$snapshotHash,$boundDraftHash]);
    $handoff=video_meeting_plan_action_existing_v18190($pdo,$ownerUserId,$itemId);if(!$handoff)throw new RuntimeException('Plan handoff could not be recorded.');
    video_meeting_plan_action_event_v18190($pdo,$handoff,$prior?'refreshed':'handed_off',$prior?'Organizer refreshed the Meeting Action draft from the current follow-through plan.':'Organizer handed the current follow-through plan to Meeting Actions for review.');
    video_meeting_action_event_v18150($pdo,$execution,'plan_handoff','needs_review','needs_review','Follow-through plan applied to this action draft.',['plan_id'=>(int)$plan['id'],'plan_snapshot_hash'=>$snapshotHash,'plan_bound_draft_hash'=>$boundDraftHash]);
    return ['handoff'=>$handoff,'execution'=>$saved,'idempotent'=>false];
}

function video_meeting_plan_action_state_v18190(PDO $pdo,array $meeting,array $user): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_plan_action_schema_ready_v18190($pdo))throw new RuntimeException('Plan-to-Action Handoff is not ready. Run the current database upgrade first.');
    $stmt=$pdo->prepare("SELECT p.*,a.action_kind,a.approval_state,a.status AS agenda_status FROM video_meeting_followthrough_plans p JOIN video_meeting_agenda_items a ON a.id=p.agenda_item_id WHERE p.meeting_id=? AND p.owner_user_id=? ORDER BY a.sort_order,a.id");$stmt->execute([(int)$meeting['id'],$ownerUserId]);$items=[];
    foreach($stmt->fetchAll()?:[] as $plan){
        $itemId=(int)$plan['agenda_item_id'];$handoff=video_meeting_plan_action_existing_v18190($pdo,$ownerUserId,$itemId);$execution=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,$itemId);
        $currentHash=video_meeting_plan_action_snapshot_hash_v18190(video_meeting_plan_action_snapshot_from_row_v18190($plan));$status='none';$drift='';
        if($handoff){
            $planCurrent=(string)$plan['readiness']==='ready'&&hash_equals((string)$handoff['plan_snapshot_hash'],$currentHash);
            $draftCurrent=$execution&&hash_equals((string)$handoff['action_draft_hash'],video_meeting_plan_action_bound_draft_hash_v18190($execution));
            $status=$planCurrent&&$draftCurrent?'current':'stale';$drift=!$planCurrent?'plan':(!$draftCurrent?'action_draft':'');
            if($status!==(string)$handoff['status'])$pdo->prepare('UPDATE video_meeting_plan_action_handoffs SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,(int)$handoff['id']]);
        }
        $executionStatus=(string)($execution['status']??'');$uncertain=$executionStatus==='failed'&&(string)($execution['error_class']??'')==='delivery_uncertain';
        $editable=!$execution||in_array($executionStatus,['needs_review','failed'],true);
        $items[]=['agenda_item_id'=>$itemId,'plan_id'=>(int)$plan['id'],'plan_readiness'=>(string)$plan['readiness'],'action_kind'=>(string)$plan['action_kind'],'approval_state'=>(string)$plan['approval_state'],'handoff_status'=>$status,'drift_source'=>$drift,'execution_id'=>(int)($execution['id']??0),'execution_status'=>$executionStatus,'execution_error_class'=>(string)($execution['error_class']??''),'can_handoff'=>(string)$plan['readiness']==='ready'&&(string)$plan['agenda_status']==='follow_up'&&(string)$plan['approval_state']==='approved_for_agent_review'&&$editable&&!$uncertain];
    }
    return ['version'=>'v18.19','schema'=>'vp3.meeting.plan_action_handoff','items'=>$items,'policy'=>['explicit_handoff'=>true,'auto_approval'=>false,'auto_execution'=>false,'stale_blocks_approval'=>true,'stale_blocks_execution'=>true,'recipient_inference'=>false,'idempotent_same_plan'=>true,'non_plan_action_fields_editable'=>true],'generated_at'=>gmdate('c')];
}
