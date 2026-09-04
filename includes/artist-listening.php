<?php
declare(strict_types=1);

const STONEFELLOW_ARTIST_LISTENING_V172 = 'artist-listening-transcription-v172-20260830';

function artist_listening_v172_schema_ready(): bool
{
    return table_exists('artist_transcript_sessions_v172')
        && table_exists('artist_transcript_segments_v172')
        && column_exists('artist_transcript_sessions_v172', 'client_session_key')
        && column_exists('artist_transcript_sessions_v172', 'knowledge_id')
        && column_exists('artist_transcript_sessions_v172', 'agent_memory_id')
        && column_exists('artist_transcript_sessions_v172', 'project_note_id')
        && column_exists('artist_transcript_segments_v172', 'client_segment_key')
        && column_exists('artist_transcript_segments_v172', 'segment_type');
}

function artist_listening_v172_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_transcript_sessions_v172 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NULL,
            client_session_key CHAR(64) NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT 'Untitled transcript',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            language VARCHAR(20) NOT NULL DEFAULT 'en-US',
            duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            knowledge_id INT UNSIGNED NULL,
            agent_memory_id BIGINT UNSIGNED NULL,
            project_note_id BIGINT UNSIGNED NULL,
            project_track_id INT UNSIGNED NULL,
            metadata_json LONGTEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            stopped_at DATETIME NULL,
            discarded_at DATETIME NULL,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_artist_transcript_client (created_by_user_id, client_session_key),
            INDEX idx_artist_transcript_creator_status (created_by_user_id, status, last_activity_at, id),
            INDEX idx_artist_transcript_owner_updated (owner_user_id, updated_at, id),
            INDEX idx_artist_transcript_conversation (conversation_id, id),
            CONSTRAINT fk_artist_transcript_owner_v172
              FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_artist_transcript_creator_v172
              FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_artist_transcript_conversation_v172
              FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE SET NULL,
            CONSTRAINT fk_artist_transcript_knowledge_v172
              FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id) ON DELETE SET NULL,
            CONSTRAINT fk_artist_transcript_track_v172
              FOREIGN KEY (project_track_id) REFERENCES tracks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            client_segment_key CHAR(64) NOT NULL,
            segment_index INT UNSIGNED NOT NULL,
            segment_type VARCHAR(20) NOT NULL DEFAULT 'transcript',
            speaker_label VARCHAR(80) NOT NULL DEFAULT 'Speaker 1',
            transcript_text TEXT NOT NULL,
            started_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ended_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            confidence DECIMAL(5,4) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_artist_transcript_segment_client (session_id, client_segment_key),
            UNIQUE KEY uq_artist_transcript_segment_order (session_id, segment_index),
            INDEX idx_artist_transcript_segment_time (session_id, started_ms, id),
            CONSTRAINT fk_artist_transcript_segment_session_v172
              FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_transcript_folders_v177 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_by_user_id INT UNSIGNED NOT NULL,
            folder_name VARCHAR(80) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_artist_transcript_folder_name_v177 (created_by_user_id,folder_name),
            INDEX idx_artist_transcript_folder_user_v177 (created_by_user_id,sort_order,id),
            CONSTRAINT fk_artist_transcript_folder_user_v177
              FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function artist_listening_v172_owner_id(array $user): int
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return 0;
    }
    if (user_has_role('artist', $user)) {
        return $userId;
    }

    $pdo = db();
    if (!$pdo) {
        return $userId;
    }

    if (table_exists('artist_team_members')) {
        try {
            $stmt = $pdo->prepare('SELECT artist_user_id FROM artist_team_members WHERE member_user_id=? LIMIT 1');
            $stmt->execute([$userId]);
            $ownerId = (int)$stmt->fetchColumn();
            if ($ownerId > 0) {
                return $ownerId;
            }
        } catch (Throwable $e) {
        }
    }

    if (user_has_role('admin', $user)) {
        $configured = max(0, (int)setting('stonefellow_artist_user_id', '0'));
        if ($configured > 0 && public_platform_v159_user_has_type($configured, 'artist')) {
            return $configured;
        }
        try {
            $stmt = $pdo->query(
                "SELECT u.id FROM users u
                 WHERE u.is_active=1 AND (
                   u.role='artist' OR EXISTS (
                     SELECT 1 FROM user_account_types uat
                     WHERE uat.user_id=u.id AND uat.role='artist'
                   )
                 ) ORDER BY u.id ASC LIMIT 1"
            );
            $ownerId = (int)$stmt->fetchColumn();
            if ($ownerId > 0) {
                return $ownerId;
            }
        } catch (Throwable $e) {
        }
    }

    return $userId;
}

function artist_listening_v172_clean_key(string $key, string $label): string
{
    $key = strtolower(trim($key));
    if (!preg_match('/^[a-z0-9-]{16,64}$/', $key)) {
        throw new RuntimeException('A valid ' . $label . ' is required.');
    }
    return $key;
}

function artist_listening_v172_clean_title(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    return $title !== '' ? mb_strimwidth($title, 0, 190, '') : 'Untitled transcript';
}

function artist_listening_v172_conversation_id(PDO $pdo, array $user, int $conversationId): ?int
{
    if ($conversationId < 1 || !table_exists('chat_conversations')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, (int)$user['id']]);
    return $stmt->fetchColumn() ? $conversationId : null;
}

function artist_listening_v172_session(PDO $pdo, array $user, int $sessionId, bool $lock = false): array
{
    if ($sessionId < 1) {
        throw new RuntimeException('Choose a transcript session.');
    }
    $sql = 'SELECT * FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Transcript session not found.');
    }
    return $row;
}

function artist_listening_v172_segments(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence,created_at,updated_at
         FROM artist_transcript_segments_v172 WHERE session_id=? ORDER BY segment_index ASC,id ASC'
    );
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll() ?: [];
}

function artist_listening_v197_metadata(array $session): array
{
    $decoded = json_decode((string)($session['metadata_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function artist_listening_v197_recording_key(string $key): string
{
    $key = strtolower(trim($key));
    if (!preg_match('/^[a-z0-9-]{16,64}$/', $key)) {
        throw new RuntimeException('A valid recording key is required.');
    }
    return $key;
}

function artist_listening_v197_recordings(array $session): array
{
    $sessionId = max(0, (int)($session['id'] ?? 0));
    if ($sessionId < 1) {
        return [];
    }
    $metadata = artist_listening_v197_metadata($session);
    $rows = is_array($metadata['recordings_v197'] ?? null) ? $metadata['recordings_v197'] : [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $fileName = basename((string)($row['file_name'] ?? ''));
        if (!preg_match('/^[a-z0-9-]{16,64}$/', $key) || $fileName === '') {
            continue;
        }
        $out[] = [
            'key'=>$key,
            'mime_type'=>trim((string)($row['mime_type'] ?? 'audio/webm')) ?: 'audio/webm',
            'bytes'=>max(0, (int)($row['bytes'] ?? 0)),
            'duration_ms'=>max(0, (int)($row['duration_ms'] ?? 0)),
            'started_ms'=>max(0, (int)($row['started_ms'] ?? 0)),
            'ended_ms'=>max(0, (int)($row['ended_ms'] ?? 0)),
            'created_at'=>(string)($row['created_at'] ?? ''),
            'file_name'=>$fileName,
            'url'=>url('/api/artist-listening.php?action=recording&session_id=' . $sessionId . '&recording_key=' . rawurlencode($key)),
        ];
    }
    return $out;
}

function artist_listening_v197_private_dir(array $user, int $sessionId): string
{
    return STONEFELLOW_ROOT . '/private/artist-listening-audio-v197/'
        . max(0, (int)($user['id'] ?? 0)) . '/' . max(0, $sessionId);
}

function artist_listening_v197_store_recording(
    PDO $pdo,
    array $user,
    int $sessionId,
    string $clientKey,
    array $upload,
    int $startedMs,
    int $endedMs,
    int $durationMs
): array {
    if ($sessionId < 1) {
        throw new RuntimeException('Choose a transcription before saving audio.');
    }
    $clientKey = artist_listening_v197_recording_key($clientKey);
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE=>'The recording exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE=>'The recording exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL=>'The recording upload was incomplete.',
            UPLOAD_ERR_NO_FILE=>'No recording audio was received.',
        ];
        throw new RuntimeException($messages[$error] ?? 'The recording upload failed.');
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    $size = max(0, (int)($upload['size'] ?? 0));
    if ($tmp === '' || !is_file($tmp) || $size < 1) {
        throw new RuntimeException('The recording audio is empty.');
    }
    if ($size > 250 * 1024 * 1024) {
        throw new RuntimeException('A single retained recording is limited to 250 MB.');
    }

    $declared = strtolower(trim((string)($upload['type'] ?? '')));
    $detected = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = strtolower(trim((string)@finfo_file($finfo, $tmp)));
            @finfo_close($finfo);
        }
    }
    $candidates = array_values(array_unique(array_filter([$detected, $declared])));
    $format = null;
    $formats = [
        'audio/webm'=>['ext'=>'webm','mime'=>'audio/webm'],
        'video/webm'=>['ext'=>'webm','mime'=>'audio/webm'],
        'audio/ogg'=>['ext'=>'ogg','mime'=>'audio/ogg'],
        'application/ogg'=>['ext'=>'ogg','mime'=>'audio/ogg'],
        'audio/mp4'=>['ext'=>'m4a','mime'=>'audio/mp4'],
        'video/mp4'=>['ext'=>'m4a','mime'=>'audio/mp4'],
        'audio/mpeg'=>['ext'=>'mp3','mime'=>'audio/mpeg'],
        'audio/wav'=>['ext'=>'wav','mime'=>'audio/wav'],
        'audio/x-wav'=>['ext'=>'wav','mime'=>'audio/wav'],
    ];
    foreach ($candidates as $candidate) {
        if (isset($formats[$candidate])) {
            $format = $formats[$candidate];
            break;
        }
    }
    if (!$format) {
        if (str_contains($declared, 'webm')) {
            $format = ['ext'=>'webm','mime'=>'audio/webm'];
        } elseif (str_contains($declared, 'ogg')) {
            $format = ['ext'=>'ogg','mime'=>'audio/ogg'];
        } elseif (str_contains($declared, 'mp4')) {
            $format = ['ext'=>'m4a','mime'=>'audio/mp4'];
        }
    }
    if (!$format) {
        throw new RuntimeException('This browser recording format is not supported.');
    }

    $userId = max(0, (int)($user['id'] ?? 0));
    if ($userId < 1) {
        throw new RuntimeException('Sign in before saving a recording.');
    }
    $dir = artist_listening_v197_private_dir($user, $sessionId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create private recording storage.');
    }
    @chmod($dir, 0700);
    $fileName = $clientKey . '.' . $format['ext'];
    $finalPath = $dir . '/' . $fileName;
    $startedMs = max(0, $startedMs);
    $endedMs = max($startedMs, $endedMs);
    $durationMs = max(0, $durationMs ?: ($endedMs - $startedMs));
    $moved = false;

    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)($session['status'] ?? '') === 'discarded') {
            throw new RuntimeException('Restore this transcription before saving audio.');
        }
        $metadata = artist_listening_v197_metadata($session);
        $recordings = is_array($metadata['recordings_v197'] ?? null) ? $metadata['recordings_v197'] : [];
        foreach ($recordings as $existing) {
            if (is_array($existing) && strtolower((string)($existing['key'] ?? '')) === $clientKey) {
                $pdo->commit();
                $fresh = artist_listening_v172_session($pdo, $user, $sessionId);
                foreach (artist_listening_v197_recordings($fresh) as $recording) {
                    if ((string)$recording['key'] === $clientKey) {
                        return $recording;
                    }
                }
            }
        }
        if (count($recordings) >= 100) {
            throw new RuntimeException('This transcription already has the maximum 100 retained clips.');
        }
        if (!move_uploaded_file($tmp, $finalPath)) {
            throw new RuntimeException('Could not move the recording into private storage.');
        }
        $moved = true;
        @chmod($finalPath, 0600);

        $recordings[] = [
            'key'=>$clientKey,
            'file_name'=>$fileName,
            'mime_type'=>$format['mime'],
            'bytes'=>$size,
            'duration_ms'=>$durationMs,
            'started_ms'=>$startedMs,
            'ended_ms'=>$endedMs,
            'created_at'=>gmdate('c'),
        ];
        $metadata['recordings_v197'] = $recordings;
        $metadata['audio_retained'] = true;
        $metadata['capture_mode'] = 'transcription_with_optional_audio';
        $metadata['audio_updated_at'] = gmdate('c');
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode recording metadata.');
        }
        $stmt = $pdo->prepare(
            'UPDATE artist_transcript_sessions_v172
             SET metadata_json=?,last_activity_at=NOW()
             WHERE id=? AND created_by_user_id=?'
        );
        $stmt->execute([$json, $sessionId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($moved && is_file($finalPath)) {
            @unlink($finalPath);
        }
        throw $e;
    }

    $fresh = artist_listening_v172_session($pdo, $user, $sessionId);
    foreach (artist_listening_v197_recordings($fresh) as $recording) {
        if ((string)$recording['key'] === $clientKey) {
            return $recording;
        }
    }
    throw new RuntimeException('The recording metadata could not be reloaded.');
}

function artist_listening_v197_stream_recording(PDO $pdo, array $user, int $sessionId, string $clientKey): never
{
    $clientKey = artist_listening_v197_recording_key($clientKey);
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $recording = null;
    foreach (artist_listening_v197_recordings($session) as $candidate) {
        if ((string)$candidate['key'] === $clientKey) {
            $recording = $candidate;
            break;
        }
    }
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
    }
    $path = artist_listening_v197_private_dir($user, $sessionId) . '/' . basename((string)$recording['file_name']);
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Recording file is unavailable.');
    }
    $size = (int)filesize($path);
    if ($size < 1) {
        throw new RuntimeException('Recording file is empty.');
    }
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
        if ($match[1] === '' && $match[2] !== '') {
            $suffix = min($size, max(1, (int)$match[2]));
            $start = $size - $suffix;
        } else {
            $start = max(0, (int)($match[1] ?? 0));
            if ($match[2] !== '') {
                $end = min($end, (int)$match[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }
    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . (string)$recording['mime_type']);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="transcription-recording.' . pathinfo((string)$recording['file_name'], PATHINFO_EXTENSION) . '"');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Recording file could not be opened.');
    }
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

function artist_listening_v172_payload(PDO $pdo, array $user, int $sessionId): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $session['segments'] = artist_listening_v172_segments($pdo, $sessionId);
    $session['segment_count'] = count($session['segments']);
    $session['recordings'] = artist_listening_v197_recordings($session);
    $session['recording_count'] = count($session['recordings']);
    return $session;
}

function artist_listening_v172_list(array $user, int $limit = 20): array
{
    $pdo = db();
    if (!$pdo || !artist_listening_v172_schema_ready()) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare(
        "SELECT s.*,
           (SELECT COUNT(*) FROM artist_transcript_segments_v172 g WHERE g.session_id=s.id) AS segment_count,
           (SELECT COUNT(*) FROM artist_transcript_segments_v172 g WHERE g.session_id=s.id AND g.segment_type='transcript' AND TRIM(g.transcript_text)<>'') AS transcript_count
         FROM artist_transcript_sessions_v172 s
         WHERE s.created_by_user_id=? AND s.status<>'discarded'
         ORDER BY (s.status='active') DESC,s.last_activity_at DESC,s.id DESC LIMIT {$limit}"
    );
    $stmt->execute([(int)$user['id']]);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['recordings'] = artist_listening_v197_recordings($row);
        $row['recording_count'] = count($row['recordings']);
    }
    unset($row);
    return $rows;
}

function artist_listening_v172_start(array $user, string $clientKey, int $conversationId, string $language, string $speakerMode = 'auto'): array
{
    $pdo = db();
    if (!$pdo || !artist_listening_v172_schema_ready()) {
        throw new RuntimeException('Artist transcription is not ready. Run the v172 upgrade.');
    }
    $clientKey = artist_listening_v172_clean_key($clientKey, 'client session key');
    $language = preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z]{2,4})?$/', $language) ? $language : 'en-US';
    $speakerMode = in_array($speakerMode, ['auto','1','2','3','4'], true) ? $speakerMode : 'auto';
    $userId = (int)$user['id'];
    $ownerId = artist_listening_v172_owner_id($user);
    if ($ownerId < 1) {
        throw new RuntimeException('Artist workspace could not be resolved.');
    }

    $pdo->beginTransaction();
    try {
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userLock->execute([$userId]);
        if (!$userLock->fetchColumn()) {
            throw new RuntimeException('User account not found.');
        }
        $existing = $pdo->prepare(
            "SELECT id FROM artist_transcript_sessions_v172
             WHERE created_by_user_id=? AND status='active'
             ORDER BY last_activity_at DESC,id DESC LIMIT 1 FOR UPDATE"
        );
        $existing->execute([$userId]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId > 0) {
            $pdo->commit();
            $payload = artist_listening_v172_payload($pdo, $user, $existingId);
            $payload['recovered'] = true;
            return $payload;
        }

        $conversationId = artist_listening_v172_conversation_id($pdo, $user, $conversationId) ?? 0;
        $stmt = $pdo->prepare(
            'INSERT INTO artist_transcript_sessions_v172
             (owner_user_id,created_by_user_id,conversation_id,client_session_key,title,status,language,metadata_json)
             VALUES (?,?,?,?,?,\'active\',?,?)'
        );
        $stmt->execute([
            $ownerId,
            $userId,
            $conversationId > 0 ? $conversationId : null,
            $clientKey,
            'Transcription · ' . date('M j') . ' · ' . date('g:i A'),
            $language,
            json_encode(['audio_retained'=>false,'capture_mode'=>'passive_transcription','speaker_mode'=>$speakerMode], JSON_UNESCAPED_SLASHES),
        ]);
        $sessionId = (int)$pdo->lastInsertId();
        $pdo->commit();
        $payload = artist_listening_v172_payload($pdo, $user, $sessionId);
        $payload['recovered'] = false;
        return $payload;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_append(array $user, int $sessionId, array $segments): array
{
    $pdo = db();
    if (!$pdo || !$segments) {
        throw new RuntimeException('Transcript segments are required.');
    }
    if (count($segments) > 50) {
        throw new RuntimeException('Send no more than 50 transcript segments at once.');
    }

    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] !== 'active') {
            throw new RuntimeException('This transcript is no longer actively listening.');
        }
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO artist_transcript_segments_v172
             (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $nextIndexStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(segment_index),-1) FROM artist_transcript_segments_v172 WHERE session_id=? FOR UPDATE'
        );
        $nextIndexStmt->execute([$sessionId]);
        $nextIndex = max(0, (int)$nextIndexStmt->fetchColumn() + 1);
        $accepted = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $text = mb_strimwidth($text, 0, 8000, '');
            $type = strtolower(trim((string)($segment['type'] ?? 'transcript')));
            if (!in_array($type, ['transcript','marker','note'], true)) {
                $type = 'transcript';
            }
            $speaker = trim((string)($segment['speaker'] ?? 'Speaker 1'));
            $speaker = $speaker !== '' ? mb_strimwidth($speaker, 0, 80, '') : 'Speaker 1';
            $index = max($nextIndex, (int)($segment['index'] ?? 0));
            $nextIndex = $index + 1;
            $startMs = max(0, (int)($segment['started_ms'] ?? 0));
            $endMs = max($startMs, (int)($segment['ended_ms'] ?? $startMs));
            $key = trim((string)($segment['key'] ?? ''));
            if (!preg_match('/^[a-z0-9-]{16,64}$/i', $key)) {
                $key = sha1($sessionId . '|' . $index . '|' . $startMs . '|' . $type . '|' . $text);
            }
            $confidence = isset($segment['confidence']) && is_numeric($segment['confidence'])
                ? max(0.0, min(1.0, (float)$segment['confidence']))
                : null;
            $insert->execute([$sessionId, strtolower($key), $index, $type, $speaker, $text, $startMs, $endMs, $confidence]);
            $accepted += $insert->rowCount();
        }
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
        $pdo->commit();
        return ['accepted'=>$accepted,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_activate(array $user, int $sessionId, int $conversationId = 0): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userLock->execute([(int)$user['id']]);
        if (!$userLock->fetchColumn()) {
            throw new RuntimeException('User account not found.');
        }
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'discarded') {
            throw new RuntimeException('Restore this transcript before recording into it.');
        }
        if ((string)$session['status'] === 'active') {
            $pdo->commit();
            return artist_listening_v172_payload($pdo, $user, $sessionId);
        }
        if ((string)$session['status'] !== 'draft') {
            throw new RuntimeException('Create a new transcription document before starting another recording.');
        }
        $conversationId = artist_listening_v172_conversation_id($pdo, $user, $conversationId) ?? 0;
        $other = $pdo->prepare(
            "SELECT id FROM artist_transcript_sessions_v172
             WHERE created_by_user_id=? AND status='active' AND id<>?
             ORDER BY last_activity_at DESC,id DESC LIMIT 1 FOR UPDATE"
        );
        $other->execute([(int)$user['id'], $sessionId]);
        if ($other->fetchColumn()) {
            throw new RuntimeException('Stop the other active transcription before starting this one.');
        }
        $update = "UPDATE artist_transcript_sessions_v172
                   SET status='active',stopped_at=NULL,discarded_at=NULL,last_activity_at=NOW()";
        $params = [];
        if ($conversationId > 0) {
            $update .= ',conversation_id=?';
            $params[] = $conversationId;
        }
        $update .= ' WHERE id=? AND created_by_user_id=?';
        $params[] = $sessionId;
        $params[] = (int)$user['id'];
        $pdo->prepare($update)->execute($params);
        $pdo->commit();
        return artist_listening_v172_payload($pdo, $user, $sessionId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_stop(array $user, int $sessionId, int $durationMs): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'discarded') {
            throw new RuntimeException('This transcript has been discarded.');
        }
        if ((string)$session['status'] === 'active') {
            $stmt = $pdo->prepare(
                "UPDATE artist_transcript_sessions_v172
                 SET status='draft',duration_ms=GREATEST(duration_ms,?),stopped_at=NOW(),last_activity_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([max(0, $durationMs), $sessionId]);
        }
        $pdo->commit();
        return artist_listening_v172_payload($pdo, $user, $sessionId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_rename(array $user, int $sessionId, string $title): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    artist_listening_v172_session($pdo, $user, $sessionId);
    $pdo->prepare("UPDATE artist_transcript_sessions_v172 SET title=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=? AND status<>'discarded'")
        ->execute([artist_listening_v172_clean_title($title), $sessionId, (int)$user['id']]);
    return artist_listening_v172_payload($pdo, $user, $sessionId);
}

function artist_listening_v172_edit_segment(array $user, int $sessionId, int $segmentId, string $text): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    if ((string)$session['status'] === 'discarded') {
        throw new RuntimeException('Restore this transcript before editing it.');
    }
    $text = trim(mb_strimwidth($text, 0, 8000, ''));
    if ($text === '') {
        throw new RuntimeException('Transcript text cannot be empty.');
    }
    $stmt = $pdo->prepare(
        'UPDATE artist_transcript_segments_v172 SET transcript_text=? WHERE id=? AND session_id=?'
    );
    $stmt->execute([$text, $segmentId, $sessionId]);
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT id FROM artist_transcript_segments_v172 WHERE id=? AND session_id=?');
        $check->execute([$segmentId, $sessionId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Transcript segment not found.');
        }
    }
    return artist_listening_v172_payload($pdo, $user, $sessionId);
}

function artist_listening_v172_discard(array $user, int $sessionId): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    if ((string)$session['status'] === 'active') {
        throw new RuntimeException('Stop listening before discarding this transcript.');
    }
    $pdo->prepare(
        "UPDATE artist_transcript_sessions_v172
         SET status='discarded',discarded_at=NOW(),stopped_at=COALESCE(stopped_at,NOW()),last_activity_at=NOW()
         WHERE id=?"
    )->execute([$sessionId]);
    return ['id'=>$sessionId,'status'=>'discarded','recoverable'=>true];
}

function artist_listening_v172_restore(array $user, int $sessionId): array
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    artist_listening_v172_session($pdo, $user, $sessionId);
    $pdo->prepare(
        "UPDATE artist_transcript_sessions_v172
         SET status='draft',discarded_at=NULL,last_activity_at=NOW() WHERE id=? AND status='discarded'"
    )->execute([$sessionId]);
    return artist_listening_v172_payload($pdo, $user, $sessionId);
}

function artist_listening_v172_time(int $milliseconds): string
{
    $seconds = max(0, intdiv($milliseconds, 1000));
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function artist_listening_v172_text(PDO $pdo, int $sessionId): string
{
    $lines = [];
    foreach (artist_listening_v172_segments($pdo, $sessionId) as $segment) {
        $type = (string)$segment['segment_type'];
        $stamp = artist_listening_v172_time((int)$segment['started_ms']);
        $text = trim((string)$segment['transcript_text']);
        if ($type === 'marker') {
            $lines[] = '[' . $stamp . '] MARKER — ' . $text;
        } elseif ($type === 'note') {
            $lines[] = '[' . $stamp . '] NOTE — ' . $text;
        } else {
            $lines[] = '[' . $stamp . '] ' . trim((string)$segment['speaker_label']) . ': ' . $text;
        }
    }
    return trim(implode("\n\n", $lines));
}

function artist_listening_v172_promotable_text(PDO $pdo, int $sessionId, string $selectedText): string
{
    $text = trim($selectedText);
    if ($text === '') {
        $text = artist_listening_v172_text($pdo, $sessionId);
    }
    $text = trim(mb_strimwidth($text, 0, 100000, ''));
    if ($text === '') {
        throw new RuntimeException('This transcript does not contain any saved text yet.');
    }
    return $text;
}

function artist_listening_v172_promote_memory(array $user, int $sessionId, string $selectedText): array
{
    $pdo = db();
    if (!$pdo || !agent_brain_schema_ready()) {
        throw new RuntimeException('Agent Brain memory is unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'active') {
            throw new RuntimeException('Stop listening before saving transcript content to Agent Brain memory.');
        }
        $existingId = (int)($session['agent_memory_id'] ?? 0);
        if ($existingId > 0) {
            $pdo->commit();
            return ['memory_id'=>$existingId,'existing'=>true,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
        }
        $text = artist_listening_v172_promotable_text($pdo, $sessionId, $selectedText);
        $memoryId = agent_brain_store_memory(
            $user,
            'transcript_note',
            artist_listening_v172_clean_title((string)$session['title']),
            mb_strimwidth($text, 0, 2000, '…'),
            0,
            0.94,
            ['source'=>'artist_transcript_v172','session_id'=>$sessionId,'explicit_user_action'=>true]
        );
        if ($memoryId < 1) {
            throw new RuntimeException('Agent Brain could not save this transcript memory.');
        }
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET agent_memory_id=?,last_activity_at=NOW() WHERE id=?')
            ->execute([$memoryId, $sessionId]);
        $pdo->commit();
        return ['memory_id'=>$memoryId,'existing'=>false,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_track_allowed(PDO $pdo, array $user, int $trackId): bool
{
    if ($trackId < 1 || !table_exists('tracks')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) {
        return false;
    }
    if (user_has_role('admin', $user)) {
        return true;
    }
    $userId = (int)$user['id'];
    $ownerId = artist_listening_v172_owner_id($user);
    if ((int)($track['owner_user_id'] ?? 0) === $ownerId || (int)($track['producer_user_id'] ?? 0) === $userId) {
        return true;
    }
    return function_exists('can_manage_track_production') && can_manage_track_production($track, $user);
}

function artist_listening_v172_promote_project_note(array $user, int $sessionId, int $trackId, string $selectedText): array
{
    if (!has_permission('track_notes.manage', $user) && !has_permission('tracks.manage', $user) && !has_permission('producer.access', $user)) {
        throw new RuntimeException('You do not have permission to add project notes.');
    }
    $pdo = db();
    if (!$pdo || !table_exists('track_notes')) {
        throw new RuntimeException('Project notes are unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'active') {
            throw new RuntimeException('Stop listening before saving project notes.');
        }
        $existingId = (int)($session['project_note_id'] ?? 0);
        if ($existingId > 0) {
            $pdo->commit();
            return ['project_note_id'=>$existingId,'track_id'=>(int)($session['project_track_id'] ?? 0),'existing'=>true,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
        }
        if (!artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
            throw new RuntimeException('Choose a track you can manage.');
        }
        $text = artist_listening_v172_promotable_text($pdo, $sessionId, $selectedText);
        $stmt = $pdo->prepare('INSERT INTO track_notes (track_id,user_id,note) VALUES (?,?,?)');
        $stmt->execute([$trackId, (int)$user['id'], mb_strimwidth($text, 0, 65000, '…')]);
        $noteId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE artist_transcript_sessions_v172 SET project_note_id=?,project_track_id=?,last_activity_at=NOW() WHERE id=?'
        )->execute([$noteId, $trackId, $sessionId]);
        $pdo->commit();
        return ['project_note_id'=>$noteId,'track_id'=>$trackId,'existing'=>false,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v172_promote_knowledge(array $user, int $sessionId, int $trackId, string $selectedText): array
{
    if (!has_permission('knowledge.manage', $user)) {
        throw new RuntimeException('You do not have permission to add Knowledge Base drafts.');
    }
    $pdo = db();
    if (!$pdo || !table_exists('knowledge_items') || !column_exists('knowledge_items', 'owner_user_id')) {
        throw new RuntimeException('Artist Knowledge Base storage is unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'active') {
            throw new RuntimeException('Stop listening before sending a transcript to the Knowledge Base.');
        }
        $existingId = (int)($session['knowledge_id'] ?? 0);
        if ($existingId > 0) {
            $exists = $pdo->prepare('SELECT id FROM knowledge_items WHERE id=? LIMIT 1');
            $exists->execute([$existingId]);
            if ($exists->fetchColumn()) {
                $pdo->commit();
                return ['knowledge_id'=>$existingId,'existing'=>true,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
            }
        }
        if ($trackId > 0 && !artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
            throw new RuntimeException('Choose a track you can manage.');
        }
        $text = artist_listening_v172_promotable_text($pdo, $sessionId, $selectedText);
        $title = artist_listening_v172_clean_title((string)$session['title']);
        $ownerId = artist_listening_v172_owner_id($user);
        $stmt = $pdo->prepare(
            'INSERT INTO knowledge_items
             (owner_user_id,track_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,public_agent_enabled,is_published,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $ownerId,
            $trackId > 0 ? $trackId : null,
            $title,
            'Private transcript draft explicitly sent from Artist Listening.',
            '',
            '',
            'transcript',
            'text/plain',
            0,
            $text,
            valid_visibility('artist') ? 'artist' : 'members',
            0,
            0,
            (int)$user['id'],
        ]);
        $knowledgeId = (int)$pdo->lastInsertId();
        reindex_knowledge_item($knowledgeId, $text);
        $pdo->prepare(
            'UPDATE artist_transcript_sessions_v172 SET knowledge_id=?,project_track_id=?,last_activity_at=NOW() WHERE id=?'
        )->execute([$knowledgeId, $trackId > 0 ? $trackId : null, $sessionId]);
        $pdo->commit();
        return ['knowledge_id'=>$knowledgeId,'existing'=>false,'session'=>artist_listening_v172_payload($pdo, $user, $sessionId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
