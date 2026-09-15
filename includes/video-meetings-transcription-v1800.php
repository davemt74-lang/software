<?php
declare(strict_types=1);

const VP3_VIDEO_MEETING_TRANSCRIPTION_V1800='video-meeting-transcription-v1800-20260915';

function video_meeting_transcription_load_stack_v1800(): void
{
    require_once __DIR__.'/artist-listening.php';
    require_once __DIR__.'/artist-listening-transcript.php';
    require_once __DIR__.'/transcription-app-registry.php';
    require_once __DIR__.'/transcription-apps-wave2.php';
    require_once __DIR__.'/transcription-intelligence-outputs.php';
    require_once __DIR__.'/transcription-deeper-intelligence.php';
}

function video_meeting_transcription_schema_ready_v1800(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    video_meeting_transcription_load_stack_v1800();
    return video_meeting_schema_ready_v1800($pdo)
        && artist_listening_v172_schema_ready()
        && artist_listening_v237_schema_ready()
        && table_exists('video_meeting_transcription_links')
        && column_exists('video_meeting_transcript_segments','source_key');
}

function video_meeting_transcription_ensure_schema_v1800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    video_meeting_transcription_load_stack_v1800();
    if(!artist_listening_v172_schema_ready())artist_listening_v172_ensure_schema();
    if(!artist_listening_v237_schema_ready())artist_listening_v237_ensure_schema();
    if(!video_meeting_schema_ready_v1800($pdo))video_meeting_ensure_schema_v1800($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_transcription_links (
      meeting_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      transcript_session_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_transcript_session (transcript_session_id),
      CONSTRAINT fk_video_meeting_transcription_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_transcription_session FOREIGN KEY (transcript_session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(!column_exists('video_meeting_transcript_segments','source_key')){
        $pdo->exec('ALTER TABLE video_meeting_transcript_segments ADD COLUMN source_key CHAR(64) NULL AFTER source');
    }
    try{
        $index=$pdo->query("SHOW INDEX FROM video_meeting_transcript_segments WHERE Key_name='uq_video_meeting_transcript_source'");
        if(!$index||!$index->fetch())$pdo->exec('ALTER TABLE video_meeting_transcript_segments ADD UNIQUE KEY uq_video_meeting_transcript_source (meeting_id,source_key)');
    }catch(Throwable $e){
        // Application-level duplicate detection still protects ingest if an
        // older MySQL deployment cannot add the helper index immediately.
    }
}

function video_meeting_worker_secret_v1800(): string
{
    global $config;$livekit=is_array($config['livekit']??null)?$config['livekit']:[];
    return trim((string)(getenv('VP3_MEETING_WORKER_SECRET')?:($livekit['worker_secret']??'')));
}

function video_meeting_transcription_registry_v1800(): array
{
    video_meeting_transcription_load_stack_v1800();
    if(function_exists('transcription_app_registry_public_v307'))return transcription_app_registry_public_v307();
    if(function_exists('transcription_app_registry_public_v306'))return transcription_app_registry_public_v306();
    if(function_exists('transcription_app_registry_public_v301'))return transcription_app_registry_public_v301();
    return function_exists('transcription_app_registry_public_v300')?transcription_app_registry_public_v300():[];
}

function video_meeting_transcription_owner_v1800(PDO $pdo,array $meeting): ?array
{
    return video_meeting_user_v1800($pdo,(int)($meeting['owner_user_id']??0));
}

function video_meeting_transcription_session_v1800(PDO $pdo,array $meeting): ?array
{
    if(!table_exists('video_meeting_transcription_links'))return null;
    $stmt=$pdo->prepare('SELECT s.* FROM video_meeting_transcription_links l JOIN artist_transcript_sessions_v172 s ON s.id=l.transcript_session_id WHERE l.meeting_id=? LIMIT 1');
    $stmt->execute([(int)$meeting['id']]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function video_meeting_transcription_ensure_session_v1800(PDO $pdo,array $meeting): ?array
{
    if(empty($meeting['transcription_enabled']))return null;
    if(!video_meeting_transcription_schema_ready_v1800($pdo))return null;
    if($existing=video_meeting_transcription_session_v1800($pdo,$meeting))return $existing;
    $owner=video_meeting_transcription_owner_v1800($pdo,$meeting);if(!$owner)return null;

    $clientKey='meeting-'.strtolower((string)$meeting['public_id']);
    $find=$pdo->prepare('SELECT * FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND client_session_key=? LIMIT 1');
    $find->execute([(int)$owner['id'],$clientKey]);$session=$find->fetch()?:null;
    if(!$session){
        $metadata=json_encode([
            'audio_retained'=>false,
            'capture_mode'=>'vp3_video_meeting',
            'source'=>'video_meetings_v1800',
            'video_meeting_id'=>(int)$meeting['id'],
            'video_meeting_public_id'=>(string)$meeting['public_id'],
            'room_name'=>(string)$meeting['room_name'],
            'agent_mode'=>(string)$meeting['agent_mode'],
            'recording_enabled'=>!empty($meeting['recording_enabled']),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $ownerId=function_exists('artist_listening_v172_owner_id')?artist_listening_v172_owner_id($owner):(int)$owner['id'];
        try{
            $insert=$pdo->prepare("INSERT INTO artist_transcript_sessions_v172 (owner_user_id,created_by_user_id,conversation_id,client_session_key,title,status,language,metadata_json,started_at,last_activity_at) VALUES (?,?,NULL,?,?,'draft','en-US',?,COALESCE(?,NOW()),NOW())");
            $insert->execute([$ownerId,(int)$owner['id'],$clientKey,artist_listening_v172_clean_title('Meeting · '.(string)$meeting['title']),$metadata,(string)($meeting['started_at']??'')?:null]);
            $sessionId=(int)$pdo->lastInsertId();
        }catch(Throwable $e){
            $find->execute([(int)$owner['id'],$clientKey]);$row=$find->fetch();if(!$row)throw $e;$sessionId=(int)$row['id'];
        }
        $stmt=$pdo->prepare('SELECT * FROM artist_transcript_sessions_v172 WHERE id=? LIMIT 1');$stmt->execute([$sessionId]);$session=$stmt->fetch()?:null;
    }
    if(!$session)return null;
    $pdo->prepare('INSERT INTO video_meeting_transcription_links (meeting_id,transcript_session_id) VALUES (?,?) ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),updated_at=NOW()')
        ->execute([(int)$meeting['id'],(int)$session['id']]);
    return $session;
}

function video_meeting_transcription_participant_v1800(PDO $pdo,array $meeting,string $identity): ?array
{
    $identity=trim($identity);if($identity==='')return null;
    foreach(video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant){
        if(hash_equals(video_meeting_participant_identity_v1800($meeting,$participant),$identity))return $participant;
    }
    return null;
}

function video_meeting_transcription_source_key_v1800(array $meeting,array $input): string
{
    $key=strtolower(trim((string)($input['source_key']??'')));
    if(preg_match('/^[a-f0-9]{64}$/',$key))return $key;
    return hash('sha256',implode('|',[
        (string)$meeting['public_id'],(string)($input['participant_identity']??''),(string)($input['speaker_name']??''),
        (string)max(0,(int)($input['start_ms']??0)),(string)max(0,(int)($input['end_ms']??0)),trim((string)($input['text']??'')),
    ]));
}

function video_meeting_transcription_mirror_v1800(PDO $pdo,array $meeting): ?array
{
    $session=video_meeting_transcription_ensure_session_v1800($pdo,$meeting);if(!$session)return null;
    $stmt=$pdo->prepare("SELECT * FROM video_meeting_transcript_segments WHERE meeting_id=? AND is_final=1 AND TRIM(transcript_text)<>'' ORDER BY start_ms,id");
    $stmt->execute([(int)$meeting['id']]);$rows=$stmt->fetchAll()?:[];
    $insert=$pdo->prepare('INSERT IGNORE INTO artist_transcript_segments_v172 (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence) VALUES (?,?,?,?,?,?,?,?,?)');
    $maxEnd=0;$accepted=0;
    foreach($rows as $row){
        $key=strtolower(trim((string)($row['source_key']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$key))$key=hash('sha256','meeting-segment|'.(int)$row['id']);
        $start=max(0,(int)$row['start_ms']);$end=max($start,(int)$row['end_ms']);$maxEnd=max($maxEnd,$end);
        $speaker=trim((string)$row['speaker_name'])?:trim((string)$row['speaker_key'])?:'Speaker';
        $insert->execute([(int)$session['id'],$key,(int)$row['id'],'transcript',mb_strimwidth($speaker,0,80,''),mb_strimwidth(trim((string)$row['transcript_text']),0,8000,''),$start,$end,$row['confidence']!==null?(float)$row['confidence']:null]);
        $accepted+=$insert->rowCount();
    }
    $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET duration_ms=GREATEST(duration_ms,?),last_activity_at=NOW() WHERE id=?')->execute([$maxEnd,(int)$session['id']]);
    return ['session'=>$session,'accepted'=>$accepted,'segments'=>count($rows)];
}

function video_meeting_transcription_append_v1800(PDO $pdo,array $meeting,array $input): array
{
    if(!video_meeting_schema_ready_v1800($pdo))throw new RuntimeException('Video Meetings are not ready.');
    if(empty($meeting['transcription_enabled']))return ['accepted'=>0,'ignored'=>'transcription_disabled'];
    $text=trim(preg_replace('/\s+/u',' ',(string)($input['text']??''))??'');if($text==='')return ['accepted'=>0,'ignored'=>'empty'];
    $text=mb_strimwidth($text,0,8000,'');$identity=trim((string)($input['participant_identity']??''));
    $participant=video_meeting_transcription_participant_v1800($pdo,$meeting,$identity);
    $speaker=trim(preg_replace('/\s+/u',' ',(string)($input['speaker_name']??$participant['display_name']??''))??'')?:'Participant';
    $start=max(0,(int)($input['start_ms']??0));$end=max($start,(int)($input['end_ms']??$start));
    $confidence=isset($input['confidence'])&&is_numeric($input['confidence'])?max(0.0,min(1.0,(float)$input['confidence'])):null;
    $source=trim((string)($input['source']??'livekit-agent'))?:'livekit-agent';$sourceKey=video_meeting_transcription_source_key_v1800($meeting,$input);

    $existing=$pdo->prepare('SELECT id FROM video_meeting_transcript_segments WHERE meeting_id=? AND source_key=? LIMIT 1');$existing->execute([(int)$meeting['id'],$sourceKey]);
    if($existing->fetchColumn()){
        $mirror=video_meeting_transcription_mirror_v1800($pdo,$meeting);
        return ['accepted'=>0,'duplicate'=>true,'source_key'=>$sourceKey,'transcript_session_id'=>(int)($mirror['session']['id']??0)];
    }
    try{
        $stmt=$pdo->prepare('INSERT INTO video_meeting_transcript_segments (meeting_id,participant_id,speaker_key,speaker_name,start_ms,end_ms,transcript_text,confidence,source,source_key,is_final) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
        $stmt->execute([(int)$meeting['id'],$participant?(int)$participant['id']:null,mb_strimwidth($identity,0,100,''),mb_strimwidth($speaker,0,190,''),$start,$end,$text,$confidence,mb_strimwidth($source,0,40,''),$sourceKey]);
        $segmentId=(int)$pdo->lastInsertId();
    }catch(Throwable $e){
        $existing->execute([(int)$meeting['id'],$sourceKey]);if(!$existing->fetchColumn())throw $e;
        return ['accepted'=>0,'duplicate'=>true,'source_key'=>$sourceKey];
    }
    $mirror=video_meeting_transcription_mirror_v1800($pdo,$meeting);
    return ['accepted'=>1,'segment_id'=>$segmentId,'source_key'=>$sourceKey,'transcript_session_id'=>(int)($mirror['session']['id']??0)];
}

function video_meeting_transcription_segments_v1800(PDO $pdo,array $meeting,int $afterId=0,int $limit=100): array
{
    $limit=max(1,min(200,$limit));$stmt=$pdo->prepare("SELECT id,participant_id,speaker_key,speaker_name,start_ms,end_ms,transcript_text,confidence,source,is_final,created_at FROM video_meeting_transcript_segments WHERE meeting_id=? AND id>? AND is_final=1 ORDER BY id ASC LIMIT {$limit}");
    $stmt->execute([(int)$meeting['id'],max(0,$afterId)]);return $stmt->fetchAll()?:[];
}

function video_meeting_transcription_finalize_v1800(PDO $pdo,array $meeting): void
{
    if(!video_meeting_transcription_schema_ready_v1800($pdo))return;
    $mirror=video_meeting_transcription_mirror_v1800($pdo,$meeting);$session=$mirror['session']??null;if(!is_array($session))return;
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(end_ms),0) FROM video_meeting_transcript_segments WHERE meeting_id=?');$stmt->execute([(int)$meeting['id']);$duration=max(0,(int)$stmt->fetchColumn());
    $pdo->prepare("UPDATE artist_transcript_sessions_v172 SET status='draft',duration_ms=GREATEST(duration_ms,?),stopped_at=COALESCE(stopped_at,NOW()),last_activity_at=NOW() WHERE id=? AND status<>'discarded'")
        ->execute([$duration,(int)$session['id']]);
}

function video_meeting_transcription_state_v1800(PDO $pdo,array $meeting): array
{
    $ready=video_meeting_transcription_schema_ready_v1800($pdo);
    $session=$ready?video_meeting_transcription_session_v1800($pdo,$meeting):null;
    $stmt=$pdo->prepare('SELECT COUNT(*),COALESCE(MAX(id),0) FROM video_meeting_transcript_segments WHERE meeting_id=? AND is_final=1');$stmt->execute([(int)$meeting['id']]);$stats=$stmt->fetch(PDO::FETCH_NUM)?:[0,0];
    return ['ready'=>$ready,'session_id'=>(int)($session['id']??0),'segment_count'=>(int)$stats[0],'last_segment_id'=>(int)$stats[1]];
}
