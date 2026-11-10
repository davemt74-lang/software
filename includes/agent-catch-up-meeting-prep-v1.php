<?php
declare(strict_types=1);

/** A persisted organizer carry-forward is the source relationship, regardless of title. */
function vp3_meeting_prep_lineage_rows_v1(PDO $pdo,int $owner,int $agent,DateTimeImmutable $now,?int $targetMeetingId=null): ?array
{
    if(!table_exists('video_meeting_continuity_links'))return null;
    require_once __DIR__.'/video-meetings-cross-meeting-continuity-guard-v18210.php';
    if(!video_meeting_schema_ready_v1800($pdo)||!video_meeting_agenda_schema_ready_v18140($pdo)
        ||!video_meeting_continuity_schema_ready_v18210($pdo)
        ||!table_exists('video_meeting_action_executions')||!table_exists('video_meeting_followthrough_closures'))return null;

    $stamp=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $scope=$agent>0?'m.organizer_agent_id=? AND p.organizer_agent_id=?':'m.organizer_agent_id IS NULL AND p.organizer_agent_id IS NULL';
    $calendar=$targetMeetingId===null?'JOIN user_calendar_events c ON c.id=m.calendar_event_id AND c.owner_user_id=m.owner_user_id':'';
    $target=$targetMeetingId===null
        ?"m.status='scheduled' AND m.start_at_utc>? AND m.start_at_utc<=?
            AND c.status='active' AND c.all_day=0 AND c.start_at_utc=m.start_at_utc AND c.end_at_utc=m.end_at_utc"
        :"m.id=? AND m.status IN ('scheduled','ready') AND m.start_at_utc>?";
    $q=$pdo->prepare("SELECT l.lineage_hash,l.updated_at AS link_updated_at,
            a.id AS source_agenda_id,a.updated_at AS source_updated_at,a.item_text AS source_text,
            t.id AS target_agenda_id,t.item_text,t.source_hash,t.updated_at AS target_updated_at,
            p.id AS source_meeting_id,p.public_id AS source_public_id,p.title AS source_title,p.end_at_utc AS source_end_at_utc,
            m.id AS meeting_id,m.public_id,m.title,m.start_at_utc,m.calendar_event_id
        FROM video_meetings m
        {$calendar}
        JOIN video_meeting_continuity_links l ON l.target_meeting_id=m.id AND l.owner_user_id=m.owner_user_id
        JOIN video_meetings p ON p.id=l.source_meeting_id AND p.owner_user_id=l.owner_user_id
        JOIN video_meeting_agenda_items a ON a.id=l.source_agenda_item_id AND a.meeting_id=p.id AND a.owner_user_id=p.owner_user_id
        JOIN video_meeting_agenda_items t ON t.id=l.target_agenda_item_id AND t.meeting_id=m.id AND t.owner_user_id=m.owner_user_id
        WHERE m.owner_user_id=? AND {$target} AND {$scope}
        AND p.id<>m.id AND p.status IN ('ended','processed') AND p.end_at_utc<=?
        AND EXISTS (SELECT 1 FROM video_meeting_participants mp WHERE mp.meeting_id=m.id
            AND mp.user_id=m.owner_user_id AND mp.`role`='organizer' AND mp.invitation_status NOT IN ('cancelled','revoked','declined'))
        AND EXISTS (SELECT 1 FROM video_meeting_participants pp WHERE pp.meeting_id=p.id
            AND pp.user_id=p.owner_user_id AND pp.`role`='organizer' AND pp.invitation_status NOT IN ('cancelled','revoked','declined'))
        AND l.status='carried_forward'
        AND a.status IN ('open','follow_up') AND (a.item_type='follow_up' OR a.status='follow_up')
        AND t.source_kind='continuity' AND t.source_meeting_id=p.id AND t.item_type='follow_up' AND t.status IN ('open','follow_up')
        ORDER BY m.start_at_utc,m.id,CASE WHEN t.priority='high' THEN 0 ELSE 1 END,t.sort_order,l.id LIMIT 9");
    $args=$targetMeetingId===null?[$stamp,gmdate('Y-m-d H:i:s',$now->getTimestamp()+1800)]:[$targetMeetingId,$stamp];
    if($agent>0)$args=[...$args,$agent,$agent];
    $q->execute([$owner,...$args,$stamp]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_meeting_prep_lineage_valid_v1(int $owner,array $row): bool
{
    $source=(int)$row['source_agenda_id'];
    return hash_equals(video_meeting_continuity_lineage_hash_v18210($owner,$source,(int)$row['meeting_id']),(string)$row['lineage_hash'])
        && hash_equals(hash('sha256','continuity-source|'.$owner.'|'.$source),(string)$row['source_hash'])
        && preg_match('/^[a-f0-9]{32}$/D',(string)$row['public_id'])
        && preg_match('/^[a-f0-9]{32}$/D',(string)$row['source_public_id']);
}

/** Only organizer-carried, still-open agenda lineage for an exact upcoming calendar event. */
function vp3_catch_up_meeting_prep_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    $owner=(int)($user['id']??0);
    $kind=$principal['kind']??'';
    $agent=(int)($principal['agent_id']??0);
    if($owner<1||!has_permission('account.access',$user)
        ||(int)($principal['viewer_user_id']??$owner)!==$owner
        ||!in_array($kind,['system','user_agent'],true)
        ||($kind==='system'&&$agent!==0)
        ||($kind==='user_agent'&&($agent<1||(int)($principal['owner_user_id']??0)!==$owner))
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return ['forbidden',[]];
    }
    if(!table_exists('video_meeting_continuity_links'))return ['unavailable',[]];
    require_once __DIR__.'/video-meetings-cross-meeting-continuity-guard-v18210.php';
    if(!user_calendar_schema_ready_v1300($pdo)
        ||!video_meeting_schema_ready_v1800($pdo)||!video_meeting_intelligence_schema_ready_v1820($pdo)
        ||!video_meeting_agenda_schema_ready_v18140($pdo)||!video_meeting_continuity_schema_ready_v18210($pdo)
        ||!table_exists('video_meeting_action_executions')||!table_exists('video_meeting_followthrough_closures')){
        return ['unavailable',[]];
    }

    $utc=new DateTimeZone('UTC');
    $now=$now->setTimezone($utc);
    $cutoff=$now->getTimestamp()+1800;
    $rows=vp3_meeting_prep_lineage_rows_v1($pdo,$owner,$agent,$now);
    if($rows===null)return ['unavailable',[]];

    $zone=new DateTimeZone(user_calendar_timezone_v1300(user_calendar_default_timezone_v1300($pdo,$user),'UTC'));
    $items=[];$meetings=[];
    foreach(array_slice($rows,0,8) as $row){
        $meeting=(int)$row['meeting_id'];
        if(isset($meetings[$meeting]))continue;
        $source=(int)$row['source_agenda_id'];
        $lineage=video_meeting_continuity_lineage_hash_v18210($owner,$source,$meeting);
        if(!vp3_meeting_prep_lineage_valid_v1($owner,$row))continue;
        $start=vp3_catch_up_timestamp_v1($row['start_at_utc'],$utc);
        $text=video_meeting_agenda_text_v18140($row['item_text'],140);
        if($start===null||$text===''||$start<=$now->getTimestamp()||$start>$cutoff)continue;

        // If the existing thread already owns an action, another active branch,
        // or verified closure, its own workspace should surface that state.
        if(video_meeting_continuity_thread_signal_v18210($pdo,$owner,$source,$meeting)!==null)continue;
        $local=(new DateTimeImmutable('@'.$start))->setTimezone($zone);
        $updated=max(
            vp3_catch_up_timestamp_v1($row['link_updated_at'],$utc)??0,
            vp3_catch_up_timestamp_v1($row['target_updated_at'],$utc)??0,
            vp3_catch_up_timestamp_v1($row['source_updated_at'],$utc)??0
        );
        $items[]=vp3_catch_up_item_v1('meeting-prep:'.$lineage,(string)$row['title'],
            'Starts at '.$local->format('g:i A T').'. Carried-forward follow-up still open: '.$text,
            'Meeting prep','/meeting.php?meeting='.$row['public_id'],
            $start<=$now->getTimestamp()+900?96:91,$start)
            +[
                'status'=>'open',
                'starts_at'=>gmdate(DATE_ATOM,$start),
                'source_updated_at'=>$updated>0?gmdate(DATE_ATOM,$updated):null,
                'source_review_url'=>url('/meeting.php?meeting='.$row['source_public_id']),
                '_calendar_event_id'=>(int)$row['calendar_event_id'],
            ];
        $meetings[$meeting]=true;
    }
    return [count($rows)>8?'limited':'ready',$items];
}
