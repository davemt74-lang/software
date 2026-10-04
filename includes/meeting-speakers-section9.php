<?php
declare(strict_types=1);
require_once __DIR__.'/speaker-attribution-v1.php';

// Keep capture immutable. The canonical transcript is the review projection.
function meeting_speaker_projection_section9(array $segment,array $capture): array
{
    $raw=json_decode((string)($capture['correction_json']??$capture['attribution_json']??''),true);
    $attribution=is_array($raw)?$raw:vp3_speaker_fuse_v1([['source'=>'unknown','speaker_label'=>$segment['speaker_label']??'Participant']]);
    $label=(string)($segment['speaker_label']??'Participant');
    if($label!==(string)($attribution['speaker_label']??'')){
        $attribution=vp3_speaker_fuse_v1([['source'=>'manual_correction','speaker_label'=>$label,'confidence'=>1.0]]);
    }
    $attribution['overlap']=!empty($attribution['overlap'])||!empty($capture['temporal_overlap']);
    $attribution['authentication_authority']=false;
    $segment['speaker_attribution']=$attribution;
    $segment['overlap']=$attribution['overlap'];
    $segment['correction_revision']=max(0,(int)($capture['revision']??0));
    return $segment;
}

function meeting_speaker_segments_section9(PDO $pdo,int $sessionId,array $segments): array
{
    if(!table_exists('video_meeting_speaker_evidence')||!table_exists('video_meeting_transcription_links'))return $segments;
    $stmt=$pdo->prepare("SELECT s.source_key,e.attribution_json,e.correction_json,e.revision,
        EXISTS(SELECT 1 FROM video_meeting_transcript_segments other
          WHERE other.meeting_id=s.meeting_id AND other.id<>s.id AND other.is_final=1
          AND other.start_ms<s.end_ms AND other.end_ms>s.start_ms
          AND COALESCE(other.speaker_key,'')<>COALESCE(s.speaker_key,'')) AS temporal_overlap
        FROM video_meeting_transcription_links l JOIN video_meeting_transcript_segments s ON s.meeting_id=l.meeting_id
        LEFT JOIN video_meeting_speaker_evidence e ON e.segment_id=s.id WHERE l.transcript_session_id=?");
    $stmt->execute([$sessionId]);$byKey=[];
    foreach($stmt->fetchAll()?:[] as $row)$byKey[(string)$row['source_key']]=$row;
    foreach($segments as &$segment){
        $capture=$byKey[(string)($segment['client_segment_key']??'')]??null;
        if($capture)$segment=meeting_speaker_projection_section9($segment,$capture);
    }
    unset($segment);return $segments;
}

function meeting_speaker_correct_section9(PDO $pdo,array $meeting,array $user,int $segmentId,string $label,int $revision): array
{
    $label=vp3_speaker_text_v1($label,80);
    if($segmentId<1||$label===''||$revision<0)throw new RuntimeException('Choose a segment and speaker label.');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT owner_user_id FROM video_meetings WHERE id=? FOR UPDATE');$lock->execute([(int)$meeting['id']]);
        if((int)$lock->fetchColumn()!==(int)($user['id']??0))throw new RuntimeException('Only the meeting owner can correct speaker labels.');
        $session=video_meeting_transcription_session_v1800($pdo,$meeting);
        if(!$session)throw new RuntimeException('Meeting transcript is unavailable.');
        $row=$pdo->prepare('SELECT s.*,e.revision FROM video_meeting_transcript_segments s LEFT JOIN video_meeting_speaker_evidence e ON e.segment_id=s.id WHERE s.id=? AND s.meeting_id=? FOR UPDATE');
        $row->execute([$segmentId,(int)$meeting['id']]);$capture=$row->fetch();
        if(!$capture)throw new RuntimeException('Meeting segment not found.');
        if((int)($capture['revision']??0)!==$revision)throw new RuntimeException('This speaker was already corrected. Refresh and try again.');
        $canonical=$pdo->prepare("SELECT id,speaker_label FROM artist_transcript_segments_v172 WHERE session_id=? AND client_segment_key=? AND segment_type='transcript' FOR UPDATE");
        $canonical->execute([(int)$session['id'],(string)$capture['source_key']]);$review=$canonical->fetch();
        if(!$review)throw new RuntimeException('Restore this transcript segment before correcting it.');
        $correction=vp3_speaker_fuse_v1([['source'=>'manual_correction','speaker_label'=>$label,'confidence'=>1.0]]);
        // A human label is a review annotation, never a biometric or account claim.
        $json=json_encode($correction,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO video_meeting_speaker_evidence (segment_id,attribution_json,correction_json,revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE correction_json=VALUES(correction_json),revision=VALUES(revision)')
            ->execute([$segmentId,json_encode(vp3_speaker_fuse_v1([['source'=>'unknown','speaker_label'=>$capture['speaker_name']]])),$json,$revision+1]);
        $pdo->prepare('UPDATE artist_transcript_segments_v172 SET speaker_label=?,updated_at=NOW() WHERE id=?')->execute([$label,(int)$review['id']]);
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=?')->execute([(int)$session['id']]);
        $pdo->commit();return ['segment_id'=>$segmentId,'correction_revision'=>$revision+1,'speaker_attribution'=>$correction];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
