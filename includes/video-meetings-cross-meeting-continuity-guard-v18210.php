<?php
declare(strict_types=1);

/**
 * Phase 18.21 continuity lineage guards.
 *
 * Resolves the full organizer-owned carry-forward chain so A→B→C continuity
 * cannot spawn a second action path from any ancestor or descendant copy.
 */
require_once __DIR__.'/video-meetings-cross-meeting-continuity-v18210.php';

const VP3_VIDEO_MEETINGS_CONTINUITY_THREAD_DEPTH_V18210=16;
const VP3_VIDEO_MEETINGS_CONTINUITY_THREAD_ITEMS_V18210=64;

function video_meeting_continuity_existing_target_link_v18210(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId,int $targetMeetingId): ?array
{
    if($ownerUserId<1||$sourceAgendaItemId<1||$targetMeetingId<1||!video_meeting_continuity_schema_ready_v18210($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_continuity_links WHERE owner_user_id=? AND source_agenda_item_id=? AND target_meeting_id=? LIMIT 1');
    $stmt->execute([$ownerUserId,$sourceAgendaItemId,$targetMeetingId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_continuity_thread_item_ids_v18210(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId): array
{
    if($ownerUserId<1||$sourceAgendaItemId<1||!video_meeting_continuity_schema_ready_v18210($pdo))return [];
    $root=$sourceAgendaItemId;$seen=[$root=>true];
    $parent=$pdo->prepare('SELECT source_agenda_item_id FROM video_meeting_continuity_links WHERE owner_user_id=? AND target_agenda_item_id=? ORDER BY id DESC LIMIT 1');
    for($depth=0;$depth<VP3_VIDEO_MEETINGS_CONTINUITY_THREAD_DEPTH_V18210;$depth++){
        $parent->execute([$ownerUserId,$root]);$candidate=(int)$parent->fetchColumn();
        if($candidate<1||isset($seen[$candidate]))break;$root=$candidate;$seen[$root]=true;
    }
    $ids=[$root];$seen=[$root=>true];$frontier=[$root];
    $children=$pdo->prepare('SELECT target_agenda_item_id FROM video_meeting_continuity_links WHERE owner_user_id=? AND source_agenda_item_id=? AND target_agenda_item_id IS NOT NULL ORDER BY id');
    for($depth=0;$depth<VP3_VIDEO_MEETINGS_CONTINUITY_THREAD_DEPTH_V18210&&$frontier;$depth++){
        $next=[];
        foreach($frontier as $itemId){
            $children->execute([$ownerUserId,$itemId]);
            foreach($children->fetchAll(PDO::FETCH_COLUMN)?:[] as $raw){
                $child=(int)$raw;if($child<1||isset($seen[$child]))continue;$seen[$child]=true;$ids[]=$child;$next[]=$child;
                if(count($ids)>=VP3_VIDEO_MEETINGS_CONTINUITY_THREAD_ITEMS_V18210)break 3;
            }
        }
        $frontier=$next;
    }
    if(!isset($seen[$sourceAgendaItemId]))$ids[]=$sourceAgendaItemId;
    return array_values(array_unique(array_map('intval',$ids)));
}

function video_meeting_continuity_thread_signal_v18210(PDO $pdo,int $ownerUserId,int $sourceAgendaItemId,int $targetMeetingId): ?array
{
    $ids=video_meeting_continuity_thread_item_ids_v18210($pdo,$ownerUserId,$sourceAgendaItemId);if(!$ids)return null;
    $marks=implode(',',array_fill(0,count($ids),'?'));

    $closure=$pdo->prepare("SELECT c.id AS closure_id,c.status AS closure_status,e.id AS execution_id,e.status AS execution_status,m.id AS target_meeting_id,m.public_id AS meeting_public_id,m.title AS meeting_title
      FROM video_meeting_followthrough_closures c
      JOIN video_meeting_action_executions e ON e.id=c.execution_id AND e.owner_user_id=c.owner_user_id
      JOIN video_meetings m ON m.id=e.meeting_id AND m.owner_user_id=e.owner_user_id
      WHERE c.owner_user_id=? AND e.agenda_item_id IN ($marks) AND c.status='verified'
      ORDER BY c.verified_at DESC,c.id DESC LIMIT 1");
    $closure->execute(array_merge([$ownerUserId],$ids));$row=$closure->fetch();
    if(is_array($row))return ['kind'=>'verified']+$row+['thread_item_ids'=>$ids];

    $action=$pdo->prepare("SELECT e.id AS execution_id,e.status AS execution_status,m.id AS target_meeting_id,m.public_id AS meeting_public_id,m.title AS meeting_title
      FROM video_meeting_action_executions e
      JOIN video_meetings m ON m.id=e.meeting_id AND m.owner_user_id=e.owner_user_id
      WHERE e.owner_user_id=? AND e.agenda_item_id IN ($marks)
      ORDER BY e.updated_at DESC,e.id DESC LIMIT 1");
    $action->execute(array_merge([$ownerUserId],$ids));$row=$action->fetch();
    if(is_array($row))return ['kind'=>'action']+$row+['thread_item_ids'=>$ids];

    $active=$pdo->prepare("SELECT l.id AS continuity_link_id,l.status AS continuity_status,l.target_meeting_id,l.target_agenda_item_id,m.public_id AS meeting_public_id,m.title AS meeting_title
      FROM video_meeting_continuity_links l
      JOIN video_meetings m ON m.id=l.target_meeting_id AND m.owner_user_id=l.owner_user_id
      WHERE l.owner_user_id=? AND l.source_agenda_item_id IN ($marks) AND l.target_meeting_id<>? AND l.status='carried_forward'
      ORDER BY l.updated_at DESC,l.id DESC LIMIT 1");
    $active->execute(array_merge([$ownerUserId],$ids,[$targetMeetingId]));$row=$active->fetch();
    return is_array($row)?(['kind'=>'active']+$row+['thread_item_ids'=>$ids]):null;
}

function video_meeting_continuity_guarded_state_v18210(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $state=video_meeting_continuity_state_v18210($pdo,$meeting,$ownerUserId);$guarded=0;$resolved=0;
    foreach($state['candidates'] as &$row){
        if(!is_array($row)||(int)($row['continuity_link_id']??0)>0)continue;
        $signal=video_meeting_continuity_thread_signal_v18210($pdo,$ownerUserId,(int)$row['source_agenda_item_id'],(int)$meeting['id']);if(!$signal)continue;
        $title=video_meeting_continuity_text_v18210($signal['meeting_title']??'another meeting',190);
        $row['related_review_path']='/meeting.php?meeting='.rawurlencode((string)($signal['meeting_public_id']??''));
        $row['related_target_meeting_id']=(int)($signal['target_meeting_id']??0);
        $row['thread_item_count']=count((array)($signal['thread_item_ids']??[]));
        if((string)$signal['kind']==='verified'){
            $row['status']='resolved_elsewhere';$row['can_carry']=false;$row['source_closure_status']='verified';
            $row['action_guard']='This continuity thread already has a verified intended outcome in '.$title.'.';$resolved++;continue;
        }
        if((string)$signal['kind']==='action'){
            $row['can_carry']=false;$row['source_execution_status']=(string)($signal['execution_status']??'');
            $row['action_guard']='This continuity thread already has Meeting Action history in '.$title.'. Review that action instead of creating a duplicate.';$guarded++;continue;
        }
        $row['can_carry']=false;$row['related_continuity_link_id']=(int)($signal['continuity_link_id']??0);
        $row['action_guard']='This continuity thread is already active in '.$title.'. Resolve or supersede that continuity link before carrying it into another meeting.';$guarded++;
    }
    unset($row);
    $state['summary']['action_guarded']=max((int)($state['summary']['action_guarded']??0),$guarded);
    $state['summary']['resolved_elsewhere']=(int)($state['summary']['resolved_elsewhere']??0)+$resolved;
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
    $signal=video_meeting_continuity_thread_signal_v18210($pdo,$ownerUserId,$sourceAgendaItemId,(int)$meeting['id']);
    if($signal){
        $title=video_meeting_continuity_text_v18210($signal['meeting_title']??'another meeting',190);
        if((string)$signal['kind']==='verified')throw new RuntimeException('This continuity thread already has a verified intended outcome in '.$title.'.');
        if((string)$signal['kind']==='action')throw new RuntimeException('This continuity thread already has Meeting Action history in '.$title.'. Review that action instead of creating a duplicate.');
        throw new RuntimeException('This continuity thread is already active in '.$title.'. Resolve or supersede it before carrying it forward again.');
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
