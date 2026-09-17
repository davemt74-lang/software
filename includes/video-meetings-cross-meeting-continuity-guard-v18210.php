<?php
declare(strict_types=1);

/**
 * Phase 18.21 continuity lineage guards.
 *
 * Keeps one active cross-meeting thread from spawning a second action path in a
 * later meeting. The core lineage store remains append-only; these helpers
 * enrich state and guard the public carry-forward path across descendant links.
 */
require_once __DIR__.'/video-meetings-cross-meeting-continuity-v18210.php';

function video_meeting_continuity_existing_target_link_v18210(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId,int $targetMeetingId): ?array
{
    if($ownerUserId<1||$sourceAgendaItemId<1||$targetMeetingId<1||!video_meeting_continuity_schema_ready_v18210($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_continuity_links WHERE owner_user_id=? AND source_agenda_item_id=? AND target_meeting_id=? LIMIT 1');
    $stmt->execute([$ownerUserId,$sourceAgendaItemId,$targetMeetingId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_continuity_related_thread_v18210(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId,int $targetMeetingId): ?array
{
    if($ownerUserId<1||$sourceAgendaItemId<1||!video_meeting_continuity_schema_ready_v18210($pdo))return null;
    $stmt=$pdo->prepare("SELECT l.id AS continuity_link_id,l.status AS continuity_status,l.target_meeting_id,l.target_agenda_item_id,
          m.public_id AS meeting_public_id,m.title AS meeting_title,
          e.id AS execution_id,e.status AS execution_status,
          c.id AS closure_id,c.status AS closure_status
        FROM video_meeting_continuity_links l
        JOIN video_meetings m ON m.id=l.target_meeting_id AND m.owner_user_id=l.owner_user_id
        LEFT JOIN video_meeting_action_executions e ON e.agenda_item_id=l.target_agenda_item_id AND e.owner_user_id=l.owner_user_id
        LEFT JOIN video_meeting_followthrough_monitors fm ON fm.execution_id=e.id AND fm.owner_user_id=l.owner_user_id
        LEFT JOIN video_meeting_followthrough_closures c ON c.monitor_id=fm.id AND c.owner_user_id=l.owner_user_id
        WHERE l.owner_user_id=? AND l.source_agenda_item_id=? AND l.target_meeting_id<>?
          AND l.status IN ('carried_forward','resolved_elsewhere')
        ORDER BY (c.status='verified') DESC,(e.id IS NOT NULL) DESC,l.updated_at DESC,l.id DESC LIMIT 1");
    $stmt->execute([$ownerUserId,$sourceAgendaItemId,$targetMeetingId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_continuity_guarded_state_v18210(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $state=video_meeting_continuity_state_v18210($pdo,$meeting,$ownerUserId);$guarded=0;$resolved=0;
    foreach($state['candidates'] as &$row){
        if(!is_array($row)||(int)($row['continuity_link_id']??0)>0)continue;
        $related=video_meeting_continuity_related_thread_v18210($pdo,$ownerUserId,(int)$row['source_agenda_item_id'],(int)$meeting['id']);
        if(!$related)continue;
        $title=video_meeting_continuity_text_v18210($related['meeting_title']??'another meeting',190);
        $href='/meeting.php?meeting='.rawurlencode((string)($related['meeting_public_id']??''));
        $row['related_continuity_link_id']=(int)$related['continuity_link_id'];
        $row['related_target_meeting_id']=(int)$related['target_meeting_id'];
        $row['related_review_path']=$href;
        if((string)($related['closure_status']??'')==='verified'){
            $row['status']='resolved_elsewhere';$row['can_carry']=false;$row['source_closure_status']='verified';
            $row['action_guard']='This continuity thread already has a verified intended outcome in '.$title.'.';$resolved++;continue;
        }
        if((int)($related['execution_id']??0)>0){
            $row['can_carry']=false;$row['source_execution_status']=(string)($related['execution_status']??'');
            $row['action_guard']='This continuity thread already has Meeting Action history in '.$title.'. Review that action instead of creating a duplicate.';$guarded++;continue;
        }
        if((string)($related['continuity_status']??'')==='carried_forward'){
            $row['can_carry']=false;$row['action_guard']='This thread is already active in '.$title.'. Resolve or supersede that continuity link before carrying it into another meeting.';$guarded++;
        }
    }
    unset($row);
    $state['summary']['action_guarded']=max((int)($state['summary']['action_guarded']??0),$guarded);
    $state['summary']['resolved_elsewhere']=($state['summary']['resolved_elsewhere']??0)+$resolved;
    $state['agent_context']=[];
    foreach(array_slice((array)$state['candidates'],0,8) as $row)$state['agent_context'][]=[
        'kind'=>(string)($row['thread_kind']??''),'status'=>(string)($row['status']??''),'text'=>(string)($row['text']??''),
        'source_meeting'=>(string)($row['meeting_title']??''),'action_guarded'=>!empty($row['action_guard']),
    ];
    return $state;
}

function video_meeting_continuity_guarded_carry_forward_v18210(PDO $pdo,array $meeting,int $ownerUserId,int $sourceAgendaItemId): array
{
    $existing=video_meeting_continuity_existing_target_link_v18210($pdo,$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']);
    if($existing)return video_meeting_continuity_guarded_state_v18210($pdo,$meeting,$ownerUserId);
    $related=video_meeting_continuity_related_thread_v18210($pdo,$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']);
    if($related){
        $title=video_meeting_continuity_text_v18210($related['meeting_title']??'another meeting',190);
        if((string)($related['closure_status']??'')==='verified')throw new RuntimeException('This continuity thread already has a verified intended outcome in '.$title.'.');
        if((int)($related['execution_id']??0)>0)throw new RuntimeException('This continuity thread already has Meeting Action history in '.$title.'. Review that action instead of creating a duplicate.');
        if((string)($related['continuity_status']??'')==='carried_forward')throw new RuntimeException('This continuity thread is already active in '.$title.'. Resolve or supersede it before carrying it forward again.');
    }
    try{video_meeting_continuity_carry_forward_v18210($pdo,$meeting,$ownerUserId,$sourceAgendaItemId);}
    catch(PDOException $e){
        if((string)$e->getCode()==='23000'&&video_meeting_continuity_existing_target_link_v18210($pdo,$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']))return video_meeting_continuity_guarded_state_v18210($pdo,$meeting,$ownerUserId);
        throw $e;
    }
    return video_meeting_continuity_guarded_state_v18210($pdo,$meeting,$ownerUserId);
}

function video_meeting_continuity_guarded_set_status_v18210(PDO $pdo,array $meeting,int $ownerUserId,int $linkId,string $status): array
{
    video_meeting_continuity_set_status_v18210($pdo,$meeting,$ownerUserId,$linkId,$status);
    return video_meeting_continuity_guarded_state_v18210($pdo,$meeting,$ownerUserId);
}
