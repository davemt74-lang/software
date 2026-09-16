<?php
declare(strict_types=1);

function video_meeting_outcome_learning_schema_ready_v18170(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo||!table_exists('video_meeting_outcome_patterns'))return false;
    foreach(['action_kind','source_kind','priority','observed_count','verified_count','blocked_count','overdue_count','dismissed_count','verified_rate_bps','friction_rate_bps','last_rebuilt_at'] as $column){if(!column_exists('video_meeting_outcome_patterns',$column))return false;}
    return true;
}

function video_meeting_outcome_learning_ensure_schema_v18170(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo))video_meeting_followthrough_intelligence_ensure_schema_v18160($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_outcome_patterns (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      action_kind VARCHAR(24) NOT NULL,
      source_kind VARCHAR(40) NOT NULL DEFAULT 'manual',
      priority VARCHAR(16) NOT NULL DEFAULT 'normal',
      observed_count INT UNSIGNED NOT NULL DEFAULT 0,
      verified_count INT UNSIGNED NOT NULL DEFAULT 0,
      blocked_count INT UNSIGNED NOT NULL DEFAULT 0,
      overdue_count INT UNSIGNED NOT NULL DEFAULT 0,
      dismissed_count INT UNSIGNED NOT NULL DEFAULT 0,
      verified_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      friction_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      first_observed_at DATETIME NULL,
      last_observed_at DATETIME NULL,
      last_rebuilt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_outcome_pattern (owner_user_id,action_kind,source_kind,priority),
      INDEX idx_video_meeting_outcome_owner (owner_user_id,observed_count,verified_rate_bps,id),
      CONSTRAINT fk_video_meeting_outcome_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_outcome_learning_rebuild_v18170(PDO $pdo,int $ownerUserId): int
{
    if($ownerUserId<1||!video_meeting_outcome_learning_schema_ready_v18170($pdo))return 0;
    video_meeting_followthrough_reconcile_owner_v18160($pdo,$ownerUserId,200);
    $s=$pdo->prepare("SELECT e.action_kind,a.source_kind,a.priority,m.status,MIN(m.created_at) AS first_seen,MAX(m.updated_at) AS last_seen,COUNT(*) AS c
      FROM video_meeting_followthrough_monitors m
      JOIN video_meeting_action_executions e ON e.id=m.execution_id
      JOIN video_meeting_agenda_items a ON a.id=e.agenda_item_id
      WHERE m.owner_user_id=? AND m.status IN ('verified','blocked','overdue','dismissed')
      GROUP BY e.action_kind,a.source_kind,a.priority,m.status");
    $s->execute([$ownerUserId]);$patterns=[];
    foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$kind=video_meeting_agenda_normalize_action_v18140((string)$row['action_kind']);if($kind==='')continue;$source=video_meeting_action_text_v18150($row['source_kind']??'manual',40)?:'manual';$priority=video_meeting_agenda_normalize_priority_v18140((string)($row['priority']??'normal'));$key=$kind.'|'.$source.'|'.$priority;if(!isset($patterns[$key]))$patterns[$key]=['action_kind'=>$kind,'source_kind'=>$source,'priority'=>$priority,'verified'=>0,'blocked'=>0,'overdue'=>0,'dismissed'=>0,'first'=>'','last'=>''];$status=(string)$row['status'];$count=max(0,(int)$row['c']);if(isset($patterns[$key][$status]))$patterns[$key][$status]+=$count;$first=(string)($row['first_seen']??'');$last=(string)($row['last_seen']??'');if($first!==''&&($patterns[$key]['first']===''||$first<$patterns[$key]['first']))$patterns[$key]['first']=$first;if($last!==''&&($patterns[$key]['last']===''||$last>$patterns[$key]['last']))$patterns[$key]['last']=$last;}
    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM video_meeting_outcome_patterns WHERE owner_user_id=?')->execute([$ownerUserId]);
        $insert=$pdo->prepare('INSERT INTO video_meeting_outcome_patterns (owner_user_id,action_kind,source_kind,priority,observed_count,verified_count,blocked_count,overdue_count,dismissed_count,verified_rate_bps,friction_rate_bps,first_observed_at,last_observed_at,last_rebuilt_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        foreach($patterns as $row){$observed=(int)$row['verified']+(int)$row['blocked']+(int)$row['overdue']+(int)$row['dismissed'];if($observed<1)continue;$verifiedRate=(int)round(((int)$row['verified']/$observed)*10000);$frictionRate=(int)round((((int)$row['blocked']+(int)$row['overdue'])/$observed)*10000);$insert->execute([$ownerUserId,$row['action_kind'],$row['source_kind'],$row['priority'],$observed,(int)$row['verified'],(int)$row['blocked'],(int)$row['overdue'],(int)$row['dismissed'],max(0,min(10000,$verifiedRate)),max(0,min(10000,$frictionRate)),$row['first']!==''?$row['first']:null,$row['last']!==''?$row['last']:null]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return count($patterns);
}

function video_meeting_outcome_learning_rows_v18170(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!video_meeting_outcome_learning_schema_ready_v18170($pdo))return [];
    video_meeting_outcome_learning_rebuild_v18170($pdo,$ownerUserId);
    $s=$pdo->prepare('SELECT * FROM video_meeting_outcome_patterns WHERE owner_user_id=? ORDER BY observed_count DESC,verified_rate_bps DESC,id ASC LIMIT 40');$s->execute([$ownerUserId]);return $s->fetchAll()?:[];
}

function video_meeting_outcome_learning_guidance_v18170(array $row): ?array
{
    $observed=max(0,(int)($row['observed_count']??0));if($observed<VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170)return null;
    $verified=max(0,min(10000,(int)($row['verified_rate_bps']??0)));$friction=max(0,min(10000,(int)($row['friction_rate_bps']??0)));$kind=(string)($row['action_kind']??'');$source=(string)($row['source_kind']??'manual');$priority=(string)($row['priority']??'normal');
    $tone='mixed';$guidance='This follow-through pattern has mixed historical outcomes. Keep the owner and completion target explicit.';
    if($verified>=7500){$tone='reliable';$guidance='This follow-through pattern has verified reliably. Reuse the structure, but still confirm the owner and completion target.';}
    elseif($friction>=5000){$tone='friction';$guidance='This follow-through pattern has often become blocked or overdue. Tighten the owner, deadline, and verification step before execution.';}
    return ['action_kind'=>$kind,'source_kind'=>$source,'priority'=>$priority,'observed_count'=>$observed,'verified_count'=>(int)$row['verified_count'],'blocked_count'=>(int)$row['blocked_count'],'overdue_count'=>(int)$row['overdue_count'],'dismissed_count'=>(int)$row['dismissed_count'],'verified_rate'=>round($verified/100,1),'friction_rate'=>round($friction/100,1),'tone'=>$tone,'guidance'=>$guidance,'last_observed_at'=>(string)($row['last_observed_at']??'')];
}
