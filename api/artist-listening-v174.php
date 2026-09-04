<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!function_exists('public_platform_v159_user_has_type')) {
    function public_platform_v159_user_has_type(int $userId, string $role): bool
    {
        $userId = max(0, $userId);
        $role = strtolower(trim($role));
        if ($userId < 1 || $role === '') {
            return false;
        }
        $pdo = db();
        if (!$pdo) {
            return false;
        }
        try {
            if (table_exists('user_account_types')) {
                $stmt = $pdo->prepare('SELECT 1 FROM user_account_types WHERE user_id=? AND role=? LIMIT 1');
                $stmt->execute([$userId, $role]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$userId]);
            return strtolower(trim((string)$stmt->fetchColumn())) === $role;
        } catch (Throwable $e) {
            return false;
        }
    }
}

require_once dirname(__DIR__) . '/includes/artist-listening.php';

const STONEFELLOW_ARTIST_LISTENING_V174 = 'artist-listening-basics-v174-20260831';

function artist_listening_v174_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_listening_v174_metadata(array $session): array
{
    $decoded = json_decode((string)($session['metadata_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function artist_listening_v174_tags(mixed $value): array
{
    $raw = is_array($value) ? $value : preg_split('/[,\n]+/u', (string)$value);
    $tags = [];
    foreach ($raw ?: [] as $tag) {
        $tag = trim(preg_replace('/\s+/u', ' ', (string)$tag) ?? '');
        if ($tag === '') {
            continue;
        }
        $tag = mb_strimwidth($tag, 0, 30, '');
        $key = mb_strtolower($tag);
        if (!isset($tags[$key])) {
            $tags[$key] = $tag;
        }
        if (count($tags) >= 12) {
            break;
        }
    }
    return array_values($tags);
}

function artist_listening_v174_folder_schema_ready(): bool
{
    return table_exists('artist_transcript_folders_v177');
}

function artist_listening_v174_folders(PDO $pdo, array $user): array
{
    if (!artist_listening_v174_folder_schema_ready()) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id,folder_name,sort_order,created_at,updated_at
         FROM artist_transcript_folders_v177
         WHERE created_by_user_id=? ORDER BY sort_order ASC,folder_name ASC,id ASC'
    );
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll() ?: [];
}

function artist_listening_v174_folder(PDO $pdo, array $user, int $folderId): ?array
{
    if ($folderId < 1 || !artist_listening_v174_folder_schema_ready()) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id,folder_name,sort_order FROM artist_transcript_folders_v177
         WHERE id=? AND created_by_user_id=? LIMIT 1'
    );
    $stmt->execute([$folderId, (int)$user['id']]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function artist_listening_v174_clean_folder_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) {
        throw new RuntimeException('Folder names must contain 1 to 80 characters.');
    }
    return $name;
}

function artist_listening_v174_merge_text(string $left, string $right): string
{
    $left = trim(preg_replace('/\s+/u', ' ', $left) ?? '');
    $right = trim(preg_replace('/\s+/u', ' ', $right) ?? '');
    if ($left === '') {
        return $right;
    }
    if ($right === '') {
        return $left;
    }
    if (str_ends_with(mb_strtolower($left), mb_strtolower($right))) {
        return $left;
    }
    $a = preg_split('/\s+/u', $left) ?: [];
    $b = preg_split('/\s+/u', $right) ?: [];
    $limit = min(14, count($a), count($b));
    for ($count = $limit; $count >= 2; $count--) {
        $tail = mb_strtolower(implode(' ', array_slice($a, -$count)));
        $head = mb_strtolower(implode(' ', array_slice($b, 0, $count)));
        if ($tail === $head) {
            return trim($left . ' ' . implode(' ', array_slice($b, $count)));
        }
    }
    return trim($left . ' ' . $right);
}

function artist_listening_v174_continuous(array $segments): string
{
    $text = '';
    foreach ($segments as $segment) {
        if ((string)($segment['segment_type'] ?? '') !== 'transcript') {
            continue;
        }
        $text = artist_listening_v174_merge_text($text, (string)($segment['transcript_text'] ?? ''));
    }
    return $text;
}

function artist_listening_v174_words(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return 0;
    }
    return count(preg_split('/\s+/u', $text) ?: []);
}

function artist_listening_v174_association(PDO $pdo, array $user, array $metadata): array
{
    $association = is_array($metadata['association'] ?? null) ? $metadata['association'] : [];
    $type = strtolower(trim((string)($association['type'] ?? 'none')));
    $trackId = max(0, (int)($association['track_id'] ?? 0));
    if (!in_array($type, ['song','studio_project'], true) || $trackId < 1) {
        return ['type'=>'none','track_id'=>0,'label'=>'Unassigned'];
    }
    if (!artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
        return ['type'=>'none','track_id'=>0,'label'=>'Unassigned'];
    }
    $stmt = $pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $title = trim((string)$stmt->fetchColumn()) ?: ('Track #' . $trackId);
    if ($type === 'studio_project') {
        if (!table_exists('track_projects')) {
            return ['type'=>'song','track_id'=>$trackId,'label'=>$title];
        }
        $check = $pdo->prepare('SELECT 1 FROM track_projects WHERE track_id=? LIMIT 1');
        $check->execute([$trackId]);
        if (!$check->fetchColumn()) {
            return ['type'=>'song','track_id'=>$trackId,'label'=>$title];
        }
    }
    return [
        'type'=>$type,
        'track_id'=>$trackId,
        'label'=>($type === 'studio_project' ? 'Studio · ' : 'Song · ') . $title,
    ];
}

function artist_listening_v174_options(PDO $pdo, array $user): array
{
    if (!table_exists('tracks')) {
        return [];
    }
    $orderBy = column_exists('tracks', 'updated_at') ? 'updated_at DESC,id DESC' : 'id DESC';
    $rows = $pdo->query('SELECT id,title FROM tracks ORDER BY ' . $orderBy . ' LIMIT 300')->fetchAll() ?: [];
    $projectCheck = table_exists('track_projects')
        ? $pdo->prepare('SELECT 1 FROM track_projects WHERE track_id=? LIMIT 1')
        : null;
    $out = [];
    foreach ($rows as $row) {
        $trackId = (int)($row['id'] ?? 0);
        if ($trackId < 1 || !artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
            continue;
        }
        $hasProject = false;
        if ($projectCheck) {
            $projectCheck->execute([$trackId]);
            $hasProject = (bool)$projectCheck->fetchColumn();
        }
        $out[] = [
            'track_id'=>$trackId,
            'title'=>trim((string)($row['title'] ?? '')) ?: ('Track #' . $trackId),
            'has_studio_project'=>$hasProject,
        ];
    }
    return $out;
}

function artist_listening_v174_chat_options(PDO $pdo, array $user): array
{
    if (!table_exists('chat_conversations')) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id,title,updated_at FROM chat_conversations
         WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 100'
    );
    $stmt->execute([(int)$user['id']]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = max(0, (int)($row['id'] ?? 0));
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'conversation_id'=>$id,
            'title'=>trim((string)($row['title'] ?? '')) ?: ('Chat #' . $id),
            'updated_at'=>(string)($row['updated_at'] ?? ''),
        ];
    }
    return $out;
}

function artist_listening_v174_chat(PDO $pdo, array $user, int $conversationId): ?array
{
    $conversationId = max(0, $conversationId);
    if ($conversationId < 1 || !table_exists('chat_conversations')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id'=>(int)$row['id'],
        'title'=>trim((string)($row['title'] ?? '')) ?: ('Chat #' . (int)$row['id']),
    ];
}

function artist_listening_v174_enrich(PDO $pdo, array $user, array $session, bool $withSegments = false): array
{
    if ($withSegments && !isset($session['segments'])) {
        $session['segments'] = artist_listening_v172_segments($pdo, (int)$session['id']);
    }
    $segments = is_array($session['segments'] ?? null) ? $session['segments'] : [];
    $metadata = artist_listening_v174_metadata($session);
    $corrections = is_array($metadata['speaker_corrections'] ?? null) ? $metadata['speaker_corrections'] : [];
    if ($withSegments) {
        foreach ($segments as &$segment) {
            if ((string)($segment['segment_type'] ?? '') !== 'transcript') {
                continue;
            }
            preg_match('/^Speaker ([1-4])$/', (string)($segment['speaker_label'] ?? ''), $speakerMatch);
            $segment['inferred_speaker_index'] = isset($speakerMatch[1]) ? (int)$speakerMatch[1] : 1;
            $segment['speaker_inferred'] = empty($corrections[(string)($segment['id'] ?? 0)]);
        }
        unset($segment);
        $session['segments'] = $segments;
    }
    $session['metadata'] = $metadata;
    $session['recordings'] = artist_listening_v197_recordings($session);
    $session['recording_count'] = count($session['recordings']);
    $session['tags'] = artist_listening_v174_tags($metadata['tags'] ?? []);
    $session['association'] = artist_listening_v174_association($pdo, $user, $metadata);
    $session['chat'] = artist_listening_v174_chat($pdo, $user, (int)($session['conversation_id'] ?? 0));
    $folderId = max(0, (int)($metadata['folder_id'] ?? 0));
    $folder = artist_listening_v174_folder($pdo, $user, $folderId);
    $session['folder'] = $folder
        ? ['id'=>(int)$folder['id'],'name'=>(string)$folder['folder_name']]
        : ['id'=>0,'name'=>'Unfiled'];
    if ($withSegments) {
        $continuous = artist_listening_v174_continuous($segments);
        $session['continuous_text'] = $continuous;
        $session['word_count'] = artist_listening_v174_words($continuous);
    }
    unset($session['metadata_json']);
    return $session;
}

function artist_listening_v174_library(PDO $pdo, array $user, string $query, int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    $query = trim(mb_strimwidth($query, 0, 100, ''));
    $params = [(int)$user['id']];
    $where = "s.created_by_user_id=? AND s.status<>'discarded'";
    if ($query !== '') {
        $like = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query) . '%';
        $where .= " AND (s.title LIKE ? ESCAPE '\\\\' OR s.metadata_json LIKE ? ESCAPE '\\\\' OR EXISTS (
            SELECT 1 FROM artist_transcript_segments_v172 sx
            WHERE sx.session_id=s.id AND sx.transcript_text LIKE ? ESCAPE '\\\\'
        ))";
        array_push($params, $like, $like, $like);
    }
    $stmt = $pdo->prepare(
        "SELECT s.*,
           (SELECT COUNT(*) FROM artist_transcript_segments_v172 g WHERE g.session_id=s.id) AS segment_count
         FROM artist_transcript_sessions_v172 s
         WHERE {$where}
         ORDER BY (s.status='active') DESC,s.last_activity_at DESC,s.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $segments = artist_listening_v172_segments($pdo, (int)$row['id']);
        $continuous = artist_listening_v174_continuous($segments);
        $row['segments'] = $segments;
        $row = artist_listening_v174_enrich($pdo, $user, $row, true);
        $row['preview'] = mb_strimwidth($continuous, 0, 260, '…');
        unset($row['segments']);
        $out[] = $row;
    }
    return $out;
}

function artist_listening_v174_update_metadata(PDO $pdo, array $user, int $sessionId, array $input): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    if ((string)$session['status'] === 'discarded') {
        throw new RuntimeException('Restore this transcript before organizing it.');
    }
    $metadata = artist_listening_v174_metadata($session);
    $metadata['tags'] = artist_listening_v174_tags($input['tags'] ?? []);
    $folderId = array_key_exists('folder_id', $input)
        ? max(0, (int)$input['folder_id'])
        : max(0, (int)($metadata['folder_id'] ?? 0));
    if ($folderId > 0 && !artist_listening_v174_folder($pdo, $user, $folderId)) {
        throw new RuntimeException('Choose a folder from your recording library.');
    }
    $metadata['folder_id'] = $folderId;

    $conversationId = array_key_exists('conversation_id', $input)
        ? max(0, (int)$input['conversation_id'])
        : max(0, (int)($session['conversation_id'] ?? 0));
    if ($conversationId > 0 && artist_listening_v172_conversation_id($pdo, $user, $conversationId) === null) {
        throw new RuntimeException('Choose one of your Agent Chat conversations.');
    }

    $type = strtolower(trim((string)($input['association_type'] ?? 'none')));
    $trackId = max(0, (int)($input['track_id'] ?? 0));
    if (!in_array($type, ['none','song','studio_project'], true)) {
        $type = 'none';
    }
    if ($type === 'none') {
        $trackId = 0;
    } else {
        if ($trackId < 1 || !artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
            throw new RuntimeException('Choose a song you can manage.');
        }
        if ($type === 'studio_project') {
            if (!table_exists('track_projects')) {
                throw new RuntimeException('Studio projects are unavailable on this installation.');
            }
            $check = $pdo->prepare('SELECT 1 FROM track_projects WHERE track_id=? LIMIT 1');
            $check->execute([$trackId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('That song does not have a Studio project yet.');
            }
        }
    }
    $metadata['association'] = ['type'=>$type,'track_id'=>$trackId];
    $metadata['organization_updated_at'] = gmdate('c');
    $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Transcript organization metadata could not be encoded.');
    }
    $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,conversation_id=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=?')
        ->execute([$json, $conversationId > 0 ? $conversationId : null, $sessionId, (int)$user['id']]);
    return artist_listening_v174_enrich($pdo, $user, artist_listening_v172_payload($pdo, $user, $sessionId), true);
}

function artist_listening_v174_split_text(string $text, array $segments): array
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (!$words) {
        return [];
    }
    $count = max(1, count($segments));
    $weights = [];
    $weightTotal = 0;
    foreach ($segments as $segment) {
        $weight = max(1, mb_strlen((string)($segment['transcript_text'] ?? '')));
        $weights[] = $weight;
        $weightTotal += $weight;
    }
    if (!$weights) {
        $weights = [1];
        $weightTotal = 1;
    }
    $chunks = [];
    $offset = 0;
    $wordCount = count($words);
    for ($i = 0; $i < $count; $i++) {
        $remainingSlots = $count - $i - 1;
        $remainingWords = $wordCount - $offset;
        if ($remainingWords <= 0) {
            $chunks[] = '';
            continue;
        }
        if ($i === $count - 1) {
            $take = $remainingWords;
        } else {
            $target = (int)round($wordCount * (($weights[$i] ?? 1) / max(1, $weightTotal)));
            $take = max(1, min($remainingWords - min($remainingSlots, $remainingWords - 1), $target));
        }
        $chunks[] = trim(implode(' ', array_slice($words, $offset, $take)));
        $offset += $take;
    }
    if ($offset < $wordCount) {
        $chunks[count($chunks)-1] = trim($chunks[count($chunks)-1] . ' ' . implode(' ', array_slice($words, $offset)));
    }
    return $chunks;
}

function artist_listening_v174_replace_transcript(PDO $pdo, array $user, int $sessionId, string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        throw new RuntimeException('Transcript text cannot be empty.');
    }
    if (mb_strlen($text) > 120000) {
        throw new RuntimeException('Edit transcripts in sections when they exceed 120,000 characters.');
    }

    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)$session['status'] === 'active') {
            throw new RuntimeException('Stop listening before editing the continuous transcript.');
        }
        if ((string)$session['status'] === 'discarded') {
            throw new RuntimeException('Restore this transcript before editing it.');
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM artist_transcript_segments_v172
             WHERE session_id=? AND segment_type='transcript'
             ORDER BY segment_index ASC,id ASC FOR UPDATE"
        );
        $stmt->execute([$sessionId]);
        $segments = $stmt->fetchAll() ?: [];

        if (!$segments) {
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(segment_index),-1) FROM artist_transcript_segments_v172 WHERE session_id=? FOR UPDATE');
            $maxStmt->execute([$sessionId]);
            $index = (int)$maxStmt->fetchColumn() + 1;
            $key = sha1($sessionId . '|v174|' . microtime(true) . '|' . $text);
            $insert = $pdo->prepare(
                "INSERT INTO artist_transcript_segments_v172
                 (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence)
                 VALUES (?,?,?,'transcript','Speaker 1',?,0,0,NULL)"
            );
            $insert->execute([$sessionId, $key, $index, mb_strimwidth($text, 0, 60000, '')]);
        } else {
            $chunks = artist_listening_v174_split_text($text, $segments);
            $update = $pdo->prepare('UPDATE artist_transcript_segments_v172 SET transcript_text=?,updated_at=NOW() WHERE id=? AND session_id=?');
            $delete = $pdo->prepare("DELETE FROM artist_transcript_segments_v172 WHERE id=? AND session_id=? AND segment_type='transcript'");
            foreach ($segments as $index => $segment) {
                $chunk = trim((string)($chunks[$index] ?? ''));
                if ($chunk === '') {
                    $delete->execute([(int)$segment['id'], $sessionId]);
                    continue;
                }
                if (mb_strlen($chunk) > 60000) {
                    throw new RuntimeException('One edited transcript section became too large. Make a smaller edit and try again.');
                }
                $update->execute([$chunk, (int)$segment['id'], $sessionId]);
            }
        }
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
        $pdo->commit();
        return artist_listening_v174_enrich($pdo, $user, artist_listening_v172_payload($pdo, $user, $sessionId), true);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v174_update_turn(PDO $pdo, array $user, int $sessionId, int $segmentId, string $speakerLabel, string $text): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    if ((string)$session['status'] === 'active') {
        throw new RuntimeException('Stop listening before correcting speaker turns.');
    }
    if ((string)$session['status'] === 'discarded') {
        throw new RuntimeException('Restore this transcript before editing it.');
    }
    $speakerLabel = trim($speakerLabel);
    if (!preg_match('/^Speaker [1-4]$/', $speakerLabel)) {
        throw new RuntimeException('Choose Speaker 1, Speaker 2, Speaker 3, or Speaker 4.');
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '' || mb_strlen($text) > 8000) {
        throw new RuntimeException('Speaker turn text must contain 1 to 8,000 characters.');
    }
    $stmt = $pdo->prepare(
        "UPDATE artist_transcript_segments_v172
         SET speaker_label=?,transcript_text=?,updated_at=NOW()
         WHERE id=? AND session_id=? AND segment_type='transcript'"
    );
    $stmt->execute([$speakerLabel, $text, $segmentId, $sessionId]);
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare("SELECT id FROM artist_transcript_segments_v172 WHERE id=? AND session_id=? AND segment_type='transcript'");
        $check->execute([$segmentId, $sessionId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Speaker turn not found.');
        }
    }
    $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
    $metadata = artist_listening_v174_metadata($session);
    $corrections = is_array($metadata['speaker_corrections'] ?? null) ? $metadata['speaker_corrections'] : [];
    $corrections[(string)$segmentId] = true;
    $metadata['speaker_corrections'] = $corrections;
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metadataJson !== false) {
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=?')->execute([$metadataJson, $sessionId]);
    }
    return artist_listening_v174_enrich($pdo, $user, artist_listening_v172_payload($pdo, $user, $sessionId), true);
}

function artist_listening_v174_create_draft(PDO $pdo, array $user, int $folderId): array
{
    if ($folderId > 0 && !artist_listening_v174_folder($pdo, $user, $folderId)) {
        throw new RuntimeException('Choose a folder from your recording library.');
    }
    $ownerId = artist_listening_v172_owner_id($user);
    if ($ownerId < 1) {
        throw new RuntimeException('Artist workspace could not be resolved.');
    }
    try {
        $clientKey = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        $clientKey = sha1((string)$user['id'] . '|' . microtime(true) . '|' . mt_rand());
    }
    $metadata = json_encode([
        'audio_retained'=>false,
        'capture_mode'=>'passive_transcription',
        'speaker_mode'=>'auto',
        'folder_id'=>$folderId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try {
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userLock->execute([(int)$user['id']]);
        if (!$userLock->fetchColumn()) {
            throw new RuntimeException('User account not found.');
        }
        $active = $pdo->prepare("SELECT id FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND status='active' LIMIT 1 FOR UPDATE");
        $active->execute([(int)$user['id']]);
        if ($active->fetchColumn()) {
            throw new RuntimeException('Stop the active transcription before creating another transcription.');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO artist_transcript_sessions_v172
             (owner_user_id,created_by_user_id,conversation_id,client_session_key,title,status,language,metadata_json)
             VALUES (?,?,?,?,?,'draft','en-US',?)"
        );
        $stmt->execute([
            $ownerId,
            (int)$user['id'],
            null,
            $clientKey,
            'Transcription · ' . date('M j') . ' · ' . date('g:i A'),
            $metadata ?: '{}',
        ]);
        $sessionId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return artist_listening_v174_enrich($pdo, $user, artist_listening_v172_payload($pdo, $user, $sessionId), true);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function artist_listening_v174_create_folder(PDO $pdo, array $user, string $name): array
{
    if (!artist_listening_v174_folder_schema_ready()) {
        throw new RuntimeException('Run the latest Artist Listening database upgrade before creating folders.');
    }
    $name = artist_listening_v174_clean_folder_name($name);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO artist_transcript_folders_v177 (created_by_user_id,folder_name,sort_order)
             VALUES (?,?,0)'
        );
        $stmt->execute([(int)$user['id'], $name]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new RuntimeException('A folder with that name already exists.');
        }
        throw $e;
    }
    return artist_listening_v174_folder($pdo, $user, (int)$pdo->lastInsertId()) ?? [];
}

function artist_listening_v174_delete_folder(PDO $pdo, array $user, int $folderId): array
{
    $folder = artist_listening_v174_folder($pdo, $user, $folderId);
    if (!$folder) {
        throw new RuntimeException('Folder not found.');
    }
    $pdo->beginTransaction();
    try {
        $sessions = $pdo->prepare(
            'SELECT id,metadata_json FROM artist_transcript_sessions_v172
             WHERE created_by_user_id=? FOR UPDATE'
        );
        $sessions->execute([(int)$user['id']]);
        $update = $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=?');
        foreach ($sessions->fetchAll() ?: [] as $session) {
            $metadata = json_decode((string)($session['metadata_json'] ?? ''), true);
            if (!is_array($metadata) || (int)($metadata['folder_id'] ?? 0) !== $folderId) {
                continue;
            }
            $metadata['folder_id'] = 0;
            $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $update->execute([$json, (int)$session['id']]);
            }
        }
        $pdo->prepare('DELETE FROM artist_transcript_folders_v177 WHERE id=? AND created_by_user_id=?')
            ->execute([$folderId, (int)$user['id']]);
        $pdo->commit();
        return ['id'=>$folderId,'deleted'=>true,'recordings_moved_to_unfiled'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$user = current_user();
if (!$user) {
    artist_listening_v174_json(false, ['error'=>'Sign in to use My Recordings.'], 401);
}
if (!has_permission('artist_listening.access', $user)) {
    artist_listening_v174_json(false, ['error'=>'Artist Listening permission is required.'], 403);
}
if (!artist_listening_v172_schema_ready()) {
    artist_listening_v174_json(false, ['error'=>'Run the Stonefellow v172 transcript upgrade first.'], 503);
}
$pdo = db();
if (!$pdo) {
    artist_listening_v174_json(false, ['error'=>'Database unavailable.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'library'));

try {
    if ($method === 'GET') {
        if ($action === 'library') {
            $query = trim((string)($_GET['q'] ?? ''));
            artist_listening_v174_json(true, [
                'build'=>STONEFELLOW_ARTIST_LISTENING_V174,
                'audio_retained'=>false,
                'sessions'=>artist_listening_v174_library($pdo, $user, $query, 100),
                'folders'=>artist_listening_v174_folders($pdo, $user),
                'association_options'=>artist_listening_v174_options($pdo, $user),
                'chat_options'=>artist_listening_v174_chat_options($pdo, $user),
            ]);
        }
        if ($action === 'session') {
            $sessionId = max(0, (int)($_GET['session_id'] ?? 0));
            $session = artist_listening_v172_payload($pdo, $user, $sessionId);
            artist_listening_v174_json(true, ['session'=>artist_listening_v174_enrich($pdo, $user, $session, true)]);
        }
        artist_listening_v174_json(false, ['error'=>'Unknown My Recordings request.'], 404);
    }

    if ($method !== 'POST') {
        artist_listening_v174_json(false, ['error'=>'POST is required.'], 405);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = trim((string)($input['action'] ?? $action));
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        artist_listening_v174_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    }
    $sessionId = max(0, (int)($input['session_id'] ?? 0));

    if ($action === 'update_metadata') {
        artist_listening_v174_json(true, ['session'=>artist_listening_v174_update_metadata($pdo, $user, $sessionId, $input)]);
    }
    if ($action === 'create_draft') {
        artist_listening_v174_json(true, ['session'=>artist_listening_v174_create_draft(
            $pdo,
            $user,
            max(0, (int)($input['folder_id'] ?? 0))
        )]);
    }
    if ($action === 'create_folder') {
        artist_listening_v174_json(true, ['folder'=>artist_listening_v174_create_folder(
            $pdo,
            $user,
            (string)($input['name'] ?? '')
        )]);
    }
    if ($action === 'delete_folder') {
        artist_listening_v174_json(true, artist_listening_v174_delete_folder(
            $pdo,
            $user,
            max(0, (int)($input['folder_id'] ?? 0))
        ));
    }
    if ($action === 'replace_transcript') {
        artist_listening_v174_json(true, ['session'=>artist_listening_v174_replace_transcript($pdo, $user, $sessionId, (string)($input['text'] ?? ''))]);
    }
    if ($action === 'update_turn') {
        artist_listening_v174_json(true, ['session'=>artist_listening_v174_update_turn(
            $pdo,
            $user,
            $sessionId,
            max(0, (int)($input['segment_id'] ?? 0)),
            (string)($input['speaker_label'] ?? ''),
            (string)($input['text'] ?? '')
        )]);
    }
    artist_listening_v174_json(false, ['error'=>'Unsupported My Recordings action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'My Recordings could not complete that request.';
    artist_listening_v174_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
