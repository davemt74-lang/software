<?php
declare(strict_types=1);

function video_meeting_adaptive_planning_schema_ready_v18180(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach(['video_meeting_followthrough_plans','video_meeting_followthrough_plan_events'] as $table){if(!table_exists($table))return false;}
    foreach(['agenda_item_id','owner_label','target_at','target_timezone','verification_criteria','readiness'] as $column){if(!column_exists('video_meeting_followthrough_plans',$column))return false;}
    return true;
}

function video_meeting_adaptive_planning_ensure_schema_v18180(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_outcome_learning_schema_ready_v18170($pdo))video_meeting_outcome_learning_ensure_schema_v18170($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_plans (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      agenda_item_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      owner_label VARCHAR(190) NOT NULL DEFAULT '',
      target_at DATETIME NULL,
      target_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      verification_criteria VARCHAR(1000) NOT NULL DEFAULT '',
      readiness VARCHAR(24) NOT NULL DEFAULT 'needs_definition',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_followthrough_plan_item (agenda_item_id),
      INDEX idx_video_meeting_followthrough_plan_meeting (meeting_id,readiness,id),
      INDEX idx_video_meeting_followthrough_plan_owner (owner_user_id,meeting_id,id),
      CONSTRAINT fk_video_meeting_followthrough_plan_item FOREIGN KEY (agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_plan_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_plan_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_plan_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      plan_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_followthrough_plan_event (plan_id,id),
      CONSTRAINT fk_video_meeting_followthrough_plan_event_plan FOREIGN KEY (plan_id) REFERENCES video_meeting_followthrough_plans(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_plan_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_plan_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_adaptive_planning_text_v18180(mixed $value,int $limit): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
    return mb_strimwidth($text,0,$limit,'…');
}

function video_meeting_adaptive_planning_item_v18180(PDO $pdo,array $meeting,int $ownerUserId,int $itemId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if($itemId<1)throw new RuntimeException('Agenda item not found.');
    $s=$pdo->prepare('SELECT * FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=? LIMIT 1');$s->execute([$itemId,(int)$meeting['id'],$ownerUserId]);$item=$s->fetch();
    if(!is_array($item))throw new RuntimeException('Agenda item not found.');
    if((string)$item['status']!=='follow_up'&&(string)$item['item_type']!=='follow_up')throw new RuntimeException('Adaptive follow-through planning is available only for follow-up agenda items.');
    return $item;
}

function video_meeting_adaptive_planning_row_v18180(PDO $pdo,int $ownerUserId,int $itemId): ?array
{
    if($ownerUserId<1||$itemId<1||!video_meeting_adaptive_planning_schema_ready_v18180($pdo))return null;
    $s=$pdo->prepare('SELECT * FROM video_meeting_followthrough_plans WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$s->execute([$itemId,$ownerUserId]);$row=$s->fetch();return is_array($row)?$row:null;
}

function video_meeting_adaptive_planning_timezone_v18180(array $meeting): string
{
    $timezone=trim((string)($meeting['timezone']??'UTC'))?:'UTC';try{new DateTimeZone($timezone);}catch(Throwable $e){$timezone='UTC';}return $timezone;
}

function video_meeting_adaptive_planning_target_utc_v18180(string $local,array $meeting): ?string
{
    $local=trim($local);if($local==='')return null;
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$local))throw new RuntimeException('Choose a valid follow-through target date and time.');
    $timezone=video_meeting_adaptive_planning_timezone_v18180($meeting);
    try{$dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new DateTimeZone($timezone));}catch(Throwable $e){$dt=false;}
    if(!$dt||$dt->format('Y-m-d\TH:i')!==$local)throw new RuntimeException('Choose a valid follow-through target date and time.');
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function video_meeting_adaptive_planning_readiness_v18180(string $ownerLabel,?string $targetAt,string $verification): string
{
    return $ownerLabel!==''&&$targetAt!==null&&$verification!==''?'ready':'needs_definition';
}

function video_meeting_adaptive_planning_event_v18180(PDO $pdo,array $plan,string $eventType,string $summary): void
{
    $pdo->prepare('INSERT INTO video_meeting_followthrough_plan_events (plan_id,meeting_id,owner_user_id,event_type,summary) VALUES (?,?,?,?,?)')->execute([(int)$plan['id'],(int)$plan['meeting_id'],(int)$plan['owner_user_id'],video_meeting_adaptive_planning_text_v18180($eventType,40),video_meeting_adaptive_planning_text_v18180($summary,500)]);
}

function video_meeting_adaptive_planning_save_v18180(PDO $pdo,array $meeting,array $user,int $itemId,array $input): array
{
    $ownerUserId=(int)($user['id']??0);$item=video_meeting_adaptive_planning_item_v18180($pdo,$meeting,$ownerUserId,$itemId);
    if(!video_meeting_adaptive_planning_schema_ready_v18180($pdo))throw new RuntimeException('Adaptive Meeting Planning is not ready. Run the current database upgrade first.');
    $ownerLabel=video_meeting_adaptive_planning_text_v18180($input['owner_label']??'',190);
    $verification=video_meeting_adaptive_planning_text_v18180($input['verification_criteria']??'',1000);
    $targetAt=video_meeting_adaptive_planning_target_utc_v18180((string)($input['target_local']??''),$meeting);
    $timezone=video_meeting_adaptive_planning_timezone_v18180($meeting);$readiness=video_meeting_adaptive_planning_readiness_v18180($ownerLabel,$targetAt,$verification);
    $existing=video_meeting_adaptive_planning_row_v18180($pdo,$ownerUserId,$itemId);
    $pdo->prepare("INSERT INTO video_meeting_followthrough_plans (agenda_item_id,meeting_id,owner_user_id,owner_label,target_at,target_timezone,verification_criteria,readiness) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE owner_label=VALUES(owner_label),target_at=VALUES(target_at),target_timezone=VALUES(target_timezone),verification_criteria=VALUES(verification_criteria),readiness=VALUES(readiness),updated_at=NOW()")
        ->execute([$itemId,(int)$meeting['id'],$ownerUserId,$ownerLabel,$targetAt,$timezone,$verification,$readiness]);
    $plan=video_meeting_adaptive_planning_row_v18180($pdo,$ownerUserId,$itemId);if(!$plan)throw new RuntimeException('Follow-through plan could not be saved.');
    video_meeting_adaptive_planning_event_v18180($pdo,$plan,$existing?'updated':'created',$readiness==='ready'?'Follow-through plan is ready for review.':'Follow-through plan still needs definition.');
    return $plan;
}

function video_meeting_adaptive_planning_clear_v18180(PDO $pdo,array $meeting,array $user,int $itemId): void
{
    $ownerUserId=(int)($user['id']??0);video_meeting_adaptive_planning_item_v18180($pdo,$meeting,$ownerUserId,$itemId);
    $pdo->prepare('DELETE FROM video_meeting_followthrough_plans WHERE agenda_item_id=? AND owner_user_id=?')->execute([$itemId,$ownerUserId]);
}
