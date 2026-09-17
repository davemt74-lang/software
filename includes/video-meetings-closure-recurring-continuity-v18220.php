<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.22 — Meeting Closure & Recurring Continuity.
 *
 * Freezes organizer-reviewed closure snapshots and lets the organizer explicitly
 * carry selected unresolved threads into a chosen future meeting. This layer is
 * continuity orchestration only and never creates or executes downstream work.
 */
const VP3_VIDEO_MEETINGS_CLOSURE_V18220='video-meetings-closure-recurring-continuity-v18220-20260917';
const VP3_VIDEO_MEETINGS_CLOSURE_ITEMS_V18220=80;
const VP3_VIDEO_MEETINGS_NEXT_MEETINGS_V18220=12;

require_once __DIR__.'/video-meetings-cross-meeting-continuity-guard-v18210.php';

function video_meeting_closure_schema_ready_v18220(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['video_meeting_closure_snapshots','video_meeting_closure_handoffs','video_meeting_closure_events'] as $table)if(!table_exists($table))return false;
    foreach(['meeting_id','revision','status','snapshot_hash','snapshot_json','closed_at'] as $column)if(!column_exists('video_meeting_closure_snapshots',$column))return false;
    return true;
}

function video_meeting_closure_ensure_schema_v18220(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_continuity_schema_ready_v18210($pdo))video_meeting_continuity_ensure_schema_v18210($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_closure_snapshots (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      revision INT UNSIGNED NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'closed',
      snapshot_hash CHAR(64) NOT NULL,
      snapshot_json MEDIUMTEXT NOT NULL,
      closed_at DATETIME NOT NULL,
      reopened_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_closure_revision (owner_user_id,meeting_id,revision),
      INDEX idx_video_meeting_closure_meeting (owner_user_id,meeting_id,id),
      CONSTRAINT fk_video_meeting_closure_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_closure_handoffs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      closure_snapshot_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      source_meeting_id BIGINT UNSIGNED NOT NULL,
      source_agenda_item_id BIGINT UNSIGNED NOT NULL,
      target_meeting_id BIGINT UNSIGNED NOT NULL,
      target_agenda_item_id BIGINT UNSIGNED NULL,
      continuity_link_id BIGINT UNSIGNED NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'carried_forward',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_closure_handoff (owner_user_id,closure_snapshot_id,source_agenda_item_id,target_meeting_id),
      INDEX idx_video_meeting_closure_handoff_target (owner_user_id,target_meeting_id,id),
      CONSTRAINT fk_video_meeting_closure_handoff_snapshot FOREIGN KEY (closure_snapshot_id) REFERENCES video_meeting_closure_snapshots(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_handoff_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_handoff_source FOREIGN KEY (source_meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_handoff_target FOREIGN KEY (target_meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_handoff_source_item FOREIGN KEY (source_agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE RESTRICT,
      CONSTRAINT fk_video_meeting_closure_handoff_target_item FOREIGN KEY (target_agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_closure_handoff_link FOREIGN KEY (continuity_link_id) REFERENCES video_meeting_continuity_links(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_closure_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      closure_snapshot_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(48) NOT NULL,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_closure_events_snapshot (closure_snapshot_id,id),
      CONSTRAINT fk_video_meeting_closure_event_snapshot FOREIGN KEY (closure_snapshot_id) REFERENCES video_meeting_closure_snapshots(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_closure_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_closure_text_v18220(mixed $value,int $limit=1500): string{return video_meeting_continuity_text_v18210($value,$limit);}

function video_meeting_closure_latest_v18220(PDO $pdo,int $ownerUserId,int $meetingId,bool $forUpdate=false): ?array
{
    if($ownerUserId<1||$meetingId<1||!video_meeting_closure_schema_ready_v18220($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_closure_snapshots WHERE owner_user_id=? AND meeting_id=? ORDER BY revision DESC,id DESC LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$ownerUserId,$meetingId]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function video_meeting_closure_snapshot_decode_v18220(?array $row): array
{
    if(!$row)return [];$data=json_decode((string)($row['snapshot_json']??''),true);return is_array($data)?$data:[];
}

function video_meeting_closure_event_v18220(PDO $pdo,array $snapshot,string $type,string $summary): void
{
    $stmt=$pdo->prepare('INSERT INTO video_meeting_closure_events (closure_snapshot_id,owner_user_id,meeting_id,event_type,summary) VALUES (?,?,?,?,?)');
    $stmt->execute([(int)$snapshot['id'],(int)$snapshot['owner_user_id'],(int)$snapshot['meeting_id'],video_meeting_closure_text_v18220($type,48),video_meeting_closure_text_v18220($summary,500)]);
}

function video_meeting_closure_collect_items_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_agenda_schema_ready_v18140($pdo))return [];
    $sql="SELECT a.id,a.item_text,a.item_type,a.status,a.priority,a.source_kind,a.source_meeting_id,
      e.id AS execution_id,e.status AS execution_status,c.id AS closure_id,c.status AS closure_status,
      il.id AS incoming_link_id,il.status AS incoming_status,ol.id AS outgoing_link_id,ol.status AS outgoing_status,ol.target_meeting_id AS outgoing_target_meeting_id
      FROM video_meeting_agenda_items a
      LEFT JOIN video_meeting_action_executions e ON e.id=(SELECT e2.id FROM video_meeting_action_executions e2 WHERE e2.owner_user_id=a.owner_user_id AND e2.agenda_item_id=a.id ORDER BY e2.id DESC LIMIT 1)
      LEFT JOIN video_meeting_followthrough_closures c ON c.execution_id=e.id AND c.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_continuity_links il ON il.target_agenda_item_id=a.id AND il.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_continuity_links ol ON ol.source_agenda_item_id=a.id AND ol.owner_user_id=a.owner_user_id AND ol.status='carried_forward'
      WHERE a.meeting_id=? AND a.owner_user_id=? AND a.status<>'skipped' AND (a.item_type IN ('follow_up','decision') OR a.status IN ('follow_up','decision'))
      ORDER BY a.sort_order,a.id LIMIT ".VP3_VIDEO_MEETINGS_CLOSURE_ITEMS_V18220;
    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$meeting['id'],$ownerUserId]);$out=[];$seen=[];
    foreach($stmt->fetchAll()?:[] as $row){
        if(!is_array($row))continue;$id=(int)$row['id'];if(isset($seen[$id]))continue;$seen[$id]=true;
        $kind=((string)$row['item_type']==='decision'||(string)$row['status']==='decision')?'decision':'commitment';
        $status='open';
        if((string)($row['closure_status']??'')==='verified')$status='verified';
        elseif(in_array((string)($row['incoming_status']??''),['resolved_elsewhere','superseded'],true))$status=(string)$row['incoming_status'];
        elseif((int)($row['execution_id']??0)>0)$status='action_active';
        elseif((int)($row['outgoing_link_id']??0)>0)$status='carried_forward';
        $eligible=$status==='open';
        $out[]=['agenda_item_id'=>$id,'kind'=>$kind,'text'=>video_meeting_closure_text_v18220($row['item_text']??'',1500),'priority'=>(string)($row['priority']??'normal'),'status'=>$status,'eligible_for_next'=>$eligible,
          'execution_id'=>(int)($row['execution_id']??0),'execution_status'=>(string)($row['execution_status']??''),'outcome_status'=>(string)($row['closure_status']??''),
          'incoming_link_id'=>(int)($row['incoming_link_id']??0),'incoming_status'=>(string)($row['incoming_status']??''),'outgoing_link_id'=>(int)($row['outgoing_link_id']??0),'outgoing_target_meeting_id'=>(int)($row['outgoing_target_meeting_id']??0)];
    }
    return $out;
}

function video_meeting_closure_build_snapshot_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $items=video_meeting_closure_collect_items_v18220($pdo,$meeting,$ownerUserId);$summary=['open'=>0,'verified'=>0,'action_active'=>0,'carried_forward'=>0,'resolved_elsewhere'=>0,'superseded'=>0];
    foreach($items as $item){$key=(string)$item['status'];if(isset($summary[$key]))$summary[$key]++;}
    return ['version'=>'v18.22','meeting_id'=>(int)$meeting['id'],'meeting_public_id'=>(string)$meeting['public_id'],'meeting_title'=>video_meeting_closure_text_v18220($meeting['title']??'Meeting',190),'meeting_status'=>(string)($meeting['status']??''),'items'=>$items,'summary'=>$summary,
      'policy'=>['organizer_owned'=>true,'snapshot_immutable'=>true,'explicit_next_meeting_selection'=>true,'automatic_carry_forward'=>false,'automatic_execution'=>false],
      'privacy'=>['participant_scoring'=>false,'participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false]];
}

function video_meeting_closure_close_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$meetingStatus=strtolower((string)($meeting['status']??''));
    if(!in_array($meetingStatus,['ended','processed'],true))throw new RuntimeException('End the meeting before closing its continuity review.');
    if(!video_meeting_closure_schema_ready_v18220($pdo))throw new RuntimeException('Meeting Closure is not ready. Run the current database upgrade first.');
    $snapshot=video_meeting_closure_build_snapshot_v18220($pdo,$meeting,$ownerUserId);$json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json))throw new RuntimeException('Meeting closure snapshot could not be encoded.');$hash=hash('sha256',$json);
    $pdo->beginTransaction();
    try{
        $latest=video_meeting_closure_latest_v18220($pdo,$ownerUserId,(int)$meeting['id'],true);
        if($latest&&(string)$latest['status']==='closed'){$pdo->commit();return video_meeting_closure_state_v18220($pdo,$meeting,$ownerUserId);}
        $revision=$latest?((int)$latest['revision']+1):1;
        $stmt=$pdo->prepare("INSERT INTO video_meeting_closure_snapshots (owner_user_id,meeting_id,revision,status,snapshot_hash,snapshot_json,closed_at) VALUES (?,?,?,'closed',?,?,UTC_TIMESTAMP())");
        $stmt->execute([$ownerUserId,(int)$meeting['id'],$revision,$hash,$json]);$id=(int)$pdo->lastInsertId();
        $row=$pdo->prepare('SELECT * FROM video_meeting_closure_snapshots WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$row->execute([$id,$ownerUserId]);$closed=$row->fetch();if(!is_array($closed))throw new RuntimeException('Meeting closure snapshot could not be verified.');
        video_meeting_closure_event_v18220($pdo,$closed,'closed','Organizer closed the meeting review and froze continuity snapshot revision '.$revision.'. No external action was executed.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return video_meeting_closure_state_v18220($pdo,$meeting,$ownerUserId);
}

function video_meeting_closure_reopen_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$pdo->beginTransaction();
    try{$latest=video_meeting_closure_latest_v18220($pdo,$ownerUserId,(int)$meeting['id'],true);if(!$latest)throw new RuntimeException('Close the meeting review before reopening it.');if((string)$latest['status']==='closed'){$stmt=$pdo->prepare("UPDATE video_meeting_closure_snapshots SET status='reopened',reopened_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='closed'");$stmt->execute([(int)$latest['id'],$ownerUserId]);$latest['status']='reopened';video_meeting_closure_event_v18220($pdo,$latest,'reopened','Organizer reopened the meeting review. The frozen closure snapshot was preserved.');}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return video_meeting_closure_state_v18220($pdo,$meeting,$ownerUserId);
}

function video_meeting_closure_title_tokens_v18220(string $title): array{return video_meeting_continuity_tokens_v18210($title);}

function video_meeting_closure_next_meetings_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $stmt=$pdo->prepare("SELECT id,public_id,title,start_at_utc,timezone,status FROM video_meetings WHERE owner_user_id=? AND id<>? AND status NOT IN ('cancelled','ended','processed') AND start_at_utc IS NOT NULL AND start_at_utc>=UTC_TIMESTAMP() AND start_at_utc<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 DAY) ORDER BY start_at_utc,id LIMIT 30");
    $stmt->execute([$ownerUserId,(int)$meeting['id']]);$sourceTitle=mb_strtolower(video_meeting_closure_text_v18220($meeting['title']??'',190));$sourceTokens=array_fill_keys(video_meeting_closure_title_tokens_v18220($sourceTitle),true);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){if(!is_array($row))continue;$title=video_meeting_closure_text_v18220($row['title']??'Meeting',190);$normalized=mb_strtolower($title);$score=$normalized===$sourceTitle&&$sourceTitle!==''?100:0;foreach(video_meeting_closure_title_tokens_v18220($normalized) as $token)if(isset($sourceTokens[$token]))$score+=5;$out[]=['meeting_id'=>(int)$row['id'],'meeting_public_id'=>(string)$row['public_id'],'title'=>$title,'start_at_utc'=>(string)$row['start_at_utc'],'timezone'=>(string)($row['timezone']??'UTC'),'recurring_match'=>$score>=100,'similarity_score'=>$score,'review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['public_id'])];}
    usort($out,static fn(array $a,array $b)=>($b['similarity_score']<=>$a['similarity_score'])?:strcmp((string)$a['start_at_utc'],(string)$b['start_at_utc']));return array_slice($out,0,VP3_VIDEO_MEETINGS_NEXT_MEETINGS_V18220);
}

function video_meeting_closure_thread_blocker_v18220(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId): ?string
{
    $ids=video_meeting_continuity_thread_item_ids_v18210($pdo,$ownerUserId,$sourceAgendaItemId);if(!$ids)return null;$marks=implode(',',array_fill(0,count($ids),'?'));
    $closure=$pdo->prepare("SELECT c.id FROM video_meeting_followthrough_closures c JOIN video_meeting_action_executions e ON e.id=c.execution_id AND e.owner_user_id=c.owner_user_id WHERE c.owner_user_id=? AND e.agenda_item_id IN ($marks) AND c.status='verified' LIMIT 1");$closure->execute(array_merge([$ownerUserId],$ids));if($closure->fetchColumn())return 'This continuity thread already has a verified intended outcome.';
    $action=$pdo->prepare("SELECT e.id FROM video_meeting_action_executions e WHERE e.owner_user_id=? AND e.agenda_item_id IN ($marks) LIMIT 1");$action->execute(array_merge([$ownerUserId],$ids));if($action->fetchColumn())return 'This continuity thread already has Meeting Action history. Review the existing action instead of creating a duplicate.';
    return null;
}

function video_meeting_closure_carry_to_next_v18220(PDO $pdo,array $meeting,int $ownerUserId,int $snapshotId,int $sourceAgendaItemId,int $targetMeetingId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);if($snapshotId<1||$sourceAgendaItemId<1||$targetMeetingId<1)throw new RuntimeException('Choose a closed item and a future meeting.');
    $snapshotStmt=$pdo->prepare("SELECT * FROM video_meeting_closure_snapshots WHERE id=? AND owner_user_id=? AND meeting_id=? AND status='closed' LIMIT 1");$snapshotStmt->execute([$snapshotId,$ownerUserId,(int)$meeting['id']]);$snapshot=$snapshotStmt->fetch();if(!is_array($snapshot))throw new RuntimeException('Use the current closed meeting snapshot before carrying anything forward.');
    $data=video_meeting_closure_snapshot_decode_v18220($snapshot);$selected=null;foreach((array)($data['items']??[]) as $item)if((int)($item['agenda_item_id']??0)===$sourceAgendaItemId){$selected=$item;break;}if(!is_array($selected)||empty($selected['eligible_for_next']))throw new RuntimeException('That snapshot item is not eligible to carry into another meeting.');
    $target=video_meeting_by_id_v1800($pdo,$targetMeetingId);if(!$target||(int)($target['owner_user_id']??0)!==$ownerUserId||in_array(strtolower((string)($target['status']??'')),['cancelled','ended','processed'],true))throw new RuntimeException('Choose an upcoming organizer-owned meeting.');
    $block=video_meeting_closure_thread_blocker_v18220($pdo,$ownerUserId,$sourceAgendaItemId);if($block)throw new RuntimeException($block);
    $existing=$pdo->prepare('SELECT * FROM video_meeting_closure_handoffs WHERE owner_user_id=? AND closure_snapshot_id=? AND source_agenda_item_id=? AND target_meeting_id=? LIMIT 1');$existing->execute([$ownerUserId,$snapshotId,$sourceAgendaItemId,$targetMeetingId]);if($existing->fetch())return video_meeting_closure_state_v18220($pdo,$meeting,$ownerUserId);
    video_meeting_continuity_carry_forward_v18210($pdo,$target,$ownerUserId,$sourceAgendaItemId);
    $linkStmt=$pdo->prepare('SELECT * FROM video_meeting_continuity_links WHERE owner_user_id=? AND source_agenda_item_id=? AND target_meeting_id=? ORDER BY id DESC LIMIT 1');$linkStmt->execute([$ownerUserId,$sourceAgendaItemId,$targetMeetingId]);$link=$linkStmt->fetch();if(!is_array($link))throw new RuntimeException('The next-meeting continuity link could not be verified.');
    $pdo->beginTransaction();
    try{
        $incoming=$pdo->prepare("UPDATE video_meeting_continuity_links SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND target_meeting_id=? AND target_agenda_item_id=? AND status='carried_forward' AND id<>?");$incoming->execute([$ownerUserId,(int)$meeting['id'],$sourceAgendaItemId,(int)$link['id']]);
        $insert=$pdo->prepare("INSERT INTO video_meeting_closure_handoffs (closure_snapshot_id,owner_user_id,source_meeting_id,source_agenda_item_id,target_meeting_id,target_agenda_item_id,continuity_link_id,status) VALUES (?,?,?,?,?,?,?,'carried_forward')");$insert->execute([$snapshotId,$ownerUserId,(int)$meeting['id'],$sourceAgendaItemId,$targetMeetingId,(int)($link['target_agenda_item_id']??0)?:null,(int)$link['id']]);
        video_meeting_closure_event_v18220($pdo,$snapshot,'carried_to_next','Organizer explicitly carried one unresolved closure item into the selected next meeting. No external action was created or executed.');$pdo->commit();
    }catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if((string)$e->getCode()!=='23000')throw $e;}
    return video_meeting_closure_state_v18220($pdo,$meeting,$ownerUserId);
}

function video_meeting_closure_state_v18220(PDO $pdo,array $meeting,int $ownerUserId): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);$latest=video_meeting_closure_latest_v18220($pdo,$ownerUserId,(int)$meeting['id']);$snapshot=video_meeting_closure_snapshot_decode_v18220($latest);$handoffs=[];
    if($latest){$stmt=$pdo->prepare("SELECT h.*,m.public_id AS target_public_id,m.title AS target_title,m.start_at_utc AS target_start_at_utc FROM video_meeting_closure_handoffs h JOIN video_meetings m ON m.id=h.target_meeting_id AND m.owner_user_id=h.owner_user_id WHERE h.closure_snapshot_id=? AND h.owner_user_id=? ORDER BY h.id");$stmt->execute([(int)$latest['id'],$ownerUserId]);foreach($stmt->fetchAll()?:[] as $row)if(is_array($row))$handoffs[]=['handoff_id'=>(int)$row['id'],'source_agenda_item_id'=>(int)$row['source_agenda_item_id'],'target_meeting_id'=>(int)$row['target_meeting_id'],'target_meeting_title'=>video_meeting_closure_text_v18220($row['target_title']??'Meeting',190),'target_start_at_utc'=>(string)$row['target_start_at_utc'],'target_review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['target_public_id']),'continuity_link_id'=>(int)($row['continuity_link_id']??0),'status'=>(string)$row['status']];}
    $isClosed=$latest&&(string)$latest['status']==='closed';return ['version'=>'v18.22','schema'=>'vp3.meeting.closure','meeting_status'=>(string)($meeting['status']??''),'closure'=>$latest?['id'=>(int)$latest['id'],'revision'=>(int)$latest['revision'],'status'=>(string)$latest['status'],'snapshot_hash'=>(string)$latest['snapshot_hash'],'closed_at'=>(string)$latest['closed_at'],'reopened_at'=>(string)($latest['reopened_at']??''),'snapshot'=>$snapshot]:null,'handoffs'=>$handoffs,'next_meetings'=>$isClosed?video_meeting_closure_next_meetings_v18220($pdo,$meeting,$ownerUserId):[],
      'policy'=>['organizer_owned'=>true,'immutable_revisions'=>true,'explicit_close'=>true,'explicit_next_meeting_selection'=>true,'automatic_carry_forward'=>false,'automatic_execution'=>false,'automatic_task_creation'=>false,'automatic_calendar_creation'=>false,'automatic_crm_write'=>false,'automatic_email_send'=>false],
      'privacy'=>['participant_scoring'=>false,'participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false]];
}
