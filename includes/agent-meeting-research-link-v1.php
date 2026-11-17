<?php
declare(strict_types=1);

require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/transcript-agent-v1.php';
require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/video-meetings-homeserver-v1801.php';

/** Check the meeting's live organizer role under the same private Chat principal. */
function vp3_meeting_research_owned_v1(PDO $pdo,array $scope,array $meeting): bool
{
    $owner=(int)$scope['user']['id'];
    if ((int)$meeting['owner_user_id']!==$owner || (int)($meeting['organizer_agent_id']??0)!==$scope['agent_id']
        || !in_array($meeting['status'],['scheduled','ready','live','ended','processed'],true)
        || !preg_match('/\A[a-f0-9]{32}\z/D',(string)$meeting['public_id'])) return false;
    $query=$pdo->prepare("SELECT 1 FROM video_meeting_participants WHERE meeting_id=? AND user_id=?
        AND `role`='organizer' AND invitation_status NOT IN ('cancelled','revoked','declined') LIMIT 1");
    $query->execute([(int)$meeting['id'],$owner]);
    return $query->fetchColumn()!==false;
}

/** An explicit agenda choice can follow the existing organizer-carried lineage. */
function vp3_meeting_research_agenda_v1(PDO $pdo,array $scope,int $sourceMeeting,int $agendaId): ?array
{
    require_once __DIR__.'/video-meetings-agenda-v18140.php';
    if (!video_meeting_agenda_schema_ready_v18140($pdo)) return null;
    $owner=(int)$scope['user']['id'];
    $query=$pdo->prepare('SELECT id,meeting_id,item_text,item_type,status,source_kind,source_meeting_id,source_hash,source_index_hash
        FROM video_meeting_agenda_items WHERE id=? AND owner_user_id=?');
    $query->execute([$agendaId,$owner]);
    $item=$query->fetch(PDO::FETCH_ASSOC);
    if (!$item || !in_array($item['status'],['open','follow_up'],true)) return null;
    $targetMeeting=(int)$item['meeting_id'];
    $lineage=null;
    if ($targetMeeting!==$sourceMeeting) {
        require_once __DIR__.'/agent-catch-up-meeting-prep-v1.php';
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        foreach (vp3_meeting_prep_lineage_rows_v1($pdo,$owner,$scope['agent_id'],$now,$targetMeeting)??[] as $row) {
            if ((int)$row['target_agenda_id']!==$agendaId || (int)$row['source_meeting_id']!==$sourceMeeting
                || !vp3_meeting_prep_lineage_valid_v1($owner,$row)) continue;
            $signal=video_meeting_continuity_thread_signal_v18210($pdo,$owner,(int)$row['source_agenda_id'],$targetMeeting);
            if (($signal['kind']??null)==='verified') return null;
            $lineage=[$row['lineage_hash'],$row['source_updated_at'],$row['source_text']];
            break;
        }
        if ($lineage===null) return null;
    }
    return ['item'=>$item,'lineage'=>$lineage];
}

/** Resolve an exact native recording and its existing meeting or selected open item. */
function vp3_meeting_research_snapshot_v1(PDO $pdo,array $scope,int $transcriptId,?int $agendaId): ?array
{
    if ($transcriptId<1 || ($agendaId!==null&&$agendaId<1) || !table_exists('video_meeting_transcription_links')
        || !table_exists('artist_transcript_sessions_v172')) return null;
    $scope=video_meeting_processing_scope_readonly_v1801($pdo,$scope['user']??[],$scope);
    $user=$scope['user'];$principal=$scope['principal'];
    if ($scope['agent_id']>0 && !function_exists('user_data_policy_can_use_v236')) return null;
    $session=vp3_transcript_session_v1($pdo,$user,$principal,$transcriptId);
    $source=video_meeting_transcription_readonly_binding_v1801($pdo,$user,$session);
    if ($source===null || !vp3_meeting_research_owned_v1($pdo,$scope,$source)) return null;
    $sourceId=(int)$source['id'];
    $query=$pdo->prepare('SELECT meeting_id FROM video_meeting_transcription_links WHERE transcript_session_id=? LIMIT 2');
    $query->execute([$transcriptId]);
    if (array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN))!==[$sourceId]) return null;

    $agenda=$agendaId===null?null:vp3_meeting_research_agenda_v1($pdo,$scope,$sourceId,$agendaId);
    if ($agendaId!==null && $agenda===null) return null;
    $meetingId=(int)($agenda['item']['meeting_id']??$sourceId);
    $meeting=$meetingId===$sourceId?$source:video_meeting_row_v1800($pdo,$meetingId);
    if ($meeting===null || !vp3_meeting_research_owned_v1($pdo,$scope,$meeting)) return null;
    $calendarId=(int)($meeting['calendar_event_id']??0);
    $target=['owner_user_id'=>(int)$user['id'],'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
        'transcript_session_id'=>$transcriptId,'source_meeting_id'=>$sourceId,'meeting_id'=>$meetingId,
        'calendar_event_id'=>$calendarId>0?$calendarId:null,'agenda_item_id'=>$agendaId];
    $target['revision']=hash('sha256',json_encode([$target,$source['public_id'],$meeting['public_id'],
        $meeting['start_at_utc'],$meeting['end_at_utc'],$agenda],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    $title=mb_strimwidth(trim(preg_replace('/[\s\x00-\x1f\x7f]+/u',' ',(string)$meeting['title'])??''),0,180,'…');
    $view=['title'=>$title===''?'Meeting':$title,'url'=>url('/meeting.php?meeting='.$meeting['public_id'].'&review=1')];
    if ($agendaId!==null) {
        $view['url'].='&view=agenda#agenda-item-'.$agendaId;
        $commitment=mb_strimwidth(trim(preg_replace('/[\s\x00-\x1f\x7f]+/u',' ',$agenda['item']['item_text'])??''),0,180,'…');
        if ($commitment!=='') $view['commitment']=$commitment;
    }
    return ['target'=>$target,'view'=>$view];
}

function vp3_meeting_research_target_v1(PDO $pdo,array $scope,int $transcriptId,?int $agendaId=null): ?array
{
    try {
        return vp3_meeting_research_snapshot_v1($pdo,$scope,$transcriptId,$agendaId)['target']??null;
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException || in_array($error->getCode(),[403,404,409,503],true)) return null;
        throw $error;
    }
}

/** Return an authorized link only while the original meeting attachment is current. */
function vp3_meeting_research_target_current_v1(PDO $pdo,array $scope,array $target): ?array
{
    $id=$target['transcript_session_id']??null;
    $agenda=$target['agenda_item_id']??null;
    if (!is_int($id) || ($agenda!==null&&!is_int($agenda))) return null;
    try {
        $current=vp3_meeting_research_snapshot_v1($pdo,$scope,$id,$agenda);
        return ($current['target']??null)===$target?$current['view']:null;
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException || in_array($error->getCode(),[403,404,409,503],true)) return null;
        throw $error;
    }
}

/** Resolve the requested recorded meeting before selecting an exact saved research item. */
function vp3_meeting_research_session_v1(PDO $pdo,array $scope,?int $meetingId=null): ?int
{
    if (($meetingId!==null&&$meetingId<1) || !table_exists('video_meeting_transcription_links')
        || !video_meeting_schema_ready_v1800($pdo)) return null;
    try {
        $scope=video_meeting_processing_scope_readonly_v1801($pdo,$scope['user']??[],$scope);
        $args=[(int)$scope['user']['id'],$scope['agent_id']];
        $selector="m.status IN ('ended','processed')";
        if ($meetingId!==null) {
            $selector='m.id=?';
            $args[]=$meetingId;
        }
        $ended=column_exists('video_meetings','ended_at')?'COALESCE(m.ended_at,m.end_at_utc)':'m.end_at_utc';
        $query=$pdo->prepare("SELECT l.transcript_session_id,m.id FROM video_meetings m
            LEFT JOIN video_meeting_transcription_links l ON l.meeting_id=m.id
            WHERE m.owner_user_id=? AND COALESCE(m.organizer_agent_id,0)=? AND $selector
            ORDER BY $ended DESC,m.id DESC LIMIT 1");
        foreach ($args as $index=>$value) $query->bindValue($index+1,$value,PDO::PARAM_INT);
        $query->execute();
        $selected=$query->fetch(PDO::FETCH_ASSOC);
        if (!$selected) return null;
        $id=(int)$selected['transcript_session_id'];
        $target=vp3_meeting_research_target_v1($pdo,$scope,$id);
        return ($target['source_meeting_id']??null)===(int)$selected['id']?$id:null;
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException || in_array($error->getCode(),[403,404,409,503],true)) return null;
        throw $error;
    }
}
