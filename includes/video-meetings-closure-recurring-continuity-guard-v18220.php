<?php
declare(strict_types=1);
require_once __DIR__.'/video-meetings-closure-recurring-continuity-v18220.php';

function video_meeting_closure_active_thread_target_v18220(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId,int $currentMeetingId): ?array
{
    $ids=video_meeting_continuity_thread_item_ids_v18210($pdo,$ownerUserId,$sourceAgendaItemId);if(!$ids)return null;$marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT l.id,l.source_agenda_item_id,l.target_meeting_id,l.target_agenda_item_id,m.public_id,m.title,m.start_at_utc
      FROM video_meeting_continuity_links l
      JOIN video_meetings m ON m.id=l.target_meeting_id AND m.owner_user_id=l.owner_user_id
      WHERE l.owner_user_id=? AND l.source_agenda_item_id IN ($marks) AND l.status='carried_forward' AND l.target_meeting_id<>?
      ORDER BY l.updated_at DESC,l.id DESC LIMIT 1");
    $stmt->execute(array_merge([$ownerUserId],$ids,[$currentMeetingId]));$row=$stmt->fetch();return is_array($row)?$row:null;
}

function video_meeting_closure_guarded_carry_to_next_v18220(PDO $pdo,array $meeting,int $ownerUserId,int $snapshotId,int $sourceAgendaItemId,int $targetMeetingId): array
{
    // If the exact source→target continuity link already exists, let the core
    // handoff path validate the frozen snapshot and repair any missing audit row.
    $exact=video_meeting_continuity_existing_target_link_v18210($pdo,$ownerUserId,$sourceAgendaItemId,$targetMeetingId);
    if($exact)return video_meeting_closure_carry_to_next_v18220($pdo,$meeting,$ownerUserId,$snapshotId,$sourceAgendaItemId,$targetMeetingId);
    $active=video_meeting_closure_active_thread_target_v18220($pdo,$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']);
    if($active){$title=video_meeting_closure_text_v18220($active['title']??'another meeting',190);throw new RuntimeException('This continuity thread is already active in '.$title.'. Resolve or supersede that link before carrying it to another next meeting.');}
    return video_meeting_closure_carry_to_next_v18220($pdo,$meeting,$ownerUserId,$snapshotId,$sourceAgendaItemId,$targetMeetingId);
}
