<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!function_exists('public_platform_v159_user_has_type')) {
    function public_platform_v159_user_has_type(int $userId, string $role): bool
    {
        $userId = max(0, $userId);
        $role = strtolower(trim($role));
        if ($userId < 1 || $role === '') return false;
        $pdo = db();
        if (!$pdo) return false;
        try {
            if (table_exists('user_account_types')) {
                $stmt = $pdo->prepare('SELECT 1 FROM user_account_types WHERE user_id=? AND role=? LIMIT 1');
                $stmt->execute([$userId, $role]);
                if ($stmt->fetchColumn()) return true;
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

const STONEFELLOW_ARTIST_RECORDINGS_V198 = 'artist-recordings-v198-20260901';

function artist_recordings_v198_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_recordings_v198_clean_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') throw new RuntimeException('Recording name cannot be empty.');
    return mb_strimwidth($name, 0, 120, '');
}

function artist_recordings_v198_raw_rows(array $session): array
{
    $metadata = artist_listening_v197_metadata($session);
    return is_array($metadata['recordings_v197'] ?? null) ? $metadata['recordings_v197'] : [];
}

function artist_recordings_v198_save_rows(PDO $pdo, array $user, array $session, array $rows): void
{
    $metadata = artist_listening_v197_metadata($session);
    $metadata['recordings_v197'] = array_values($rows);
    $metadata['audio_retained'] = count($rows) > 0;
    $metadata['capture_mode'] = 'transcription_with_optional_audio';
    $metadata['audio_updated_at'] = gmdate('c');
    $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Recording metadata could not be encoded.');
    $stmt = $pdo->prepare(
        'UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=?'
    );
    $stmt->execute([$json, (int)$session['id'], (int)$user['id']]);
}

function artist_recordings_v198_association(PDO $pdo, array $user, array $session): array
{
    $metadata = artist_listening_v197_metadata($session);
    $association = is_array($metadata['association'] ?? null) ? $metadata['association'] : [];
    $type = strtolower(trim((string)($association['type'] ?? 'none')));
    $trackId = max(0, (int)($association['track_id'] ?? 0));
    if (!in_array($type, ['song','studio_project'], true) || $trackId < 1 || !artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
        return ['type'=>'none','track_id'=>0,'label'=>'Unassigned'];
    }
    $stmt = $pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $title = trim((string)$stmt->fetchColumn()) ?: ('Track #' . $trackId);
    return [
        'type'=>$type,
        'track_id'=>$trackId,
        'label'=>($type === 'studio_project' ? 'Studio · ' : 'Song · ') . $title,
    ];
}

function artist_recordings_v198_transcript_map(PDO $pdo, array $sessionIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $sessionIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT session_id,transcript_text FROM artist_transcript_segments_v172
         WHERE session_id IN ({$placeholders}) AND segment_type='transcript' AND TRIM(transcript_text)<>''
         ORDER BY session_id ASC,segment_index ASC,id ASC"
    );
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (int)($row['session_id'] ?? 0);
        if ($id < 1) continue;
        $existing = (string)($map[$id] ?? '');
        if (mb_strlen($existing) >= 5000) continue;
        $text = trim((string)($row['transcript_text'] ?? ''));
        if ($text === '') continue;
        $map[$id] = mb_strimwidth(trim($existing . ' ' . $text), 0, 5000, '…');
    }
    return $map;
}

function artist_recordings_v198_normalize_row(array $session, array $row, int $index, array $association, string $transcript): ?array
{
    $sessionId = max(0, (int)($session['id'] ?? 0));
    $key = strtolower(trim((string)($row['key'] ?? '')));
    $fileName = basename((string)($row['file_name'] ?? ''));
    if ($sessionId < 1 || !preg_match('/^[a-z0-9-]{16,64}$/', $key) || $fileName === '') return null;
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') $name = 'Recording ' . ($index + 1);
    $started = max(0, (int)($row['started_ms'] ?? 0));
    $ended = max($started, (int)($row['ended_ms'] ?? $started));
    $duration = max(0, (int)($row['duration_ms'] ?? ($ended - $started)));
    return [
        'key'=>$key,
        'name'=>mb_strimwidth($name, 0, 120, ''),
        'favorite'=>!empty($row['favorite']),
        'mime_type'=>trim((string)($row['mime_type'] ?? 'audio/webm')) ?: 'audio/webm',
        'bytes'=>max(0, (int)($row['bytes'] ?? 0)),
        'duration_ms'=>$duration,
        'started_ms'=>$started,
        'ended_ms'=>$ended,
        'created_at'=>(string)($row['created_at'] ?? $session['started_at'] ?? ''),
        'session_id'=>$sessionId,
        'session_title'=>trim((string)($session['title'] ?? '')) ?: ('Transcription #' . $sessionId),
        'session_started_at'=>(string)($session['started_at'] ?? ''),
        'conversation_id'=>max(0, (int)($session['conversation_id'] ?? 0)),
        'association'=>$association,
        'transcript_excerpt'=>mb_strimwidth(trim($transcript), 0, 1600, '…'),
        'url'=>url('/api/artist-listening.php?action=recording&session_id=' . $sessionId . '&recording_key=' . rawurlencode($key)),
        'open_url'=>url('/artist-listening.php?session=' . $sessionId),
    ];
}

function artist_recordings_v198_library(PDO $pdo, array $user, int $limit = 120, string $query = '', int $sessionId = 0): array
{
    $limit = max(1, min(200, $limit));
    $sessions = artist_listening_v172_list($user, 50);
    if ($sessionId > 0) {
        $sessions = array_values(array_filter($sessions, static fn(array $session): bool => (int)($session['id'] ?? 0) === $sessionId));
        if (!$sessions) {
            try { $sessions = [artist_listening_v172_session($pdo, $user, $sessionId)]; } catch (Throwable $e) { $sessions = []; }
        }
    }
    $transcripts = artist_recordings_v198_transcript_map($pdo, array_map(static fn(array $session): int => (int)($session['id'] ?? 0), $sessions));
    $items = [];
    foreach ($sessions as $session) {
        $sid = (int)($session['id'] ?? 0);
        $association = artist_recordings_v198_association($pdo, $user, $session);
        $rows = artist_recordings_v198_raw_rows($session);
        foreach ($rows as $index => $row) {
            if (!is_array($row)) continue;
            $item = artist_recordings_v198_normalize_row($session, $row, (int)$index, $association, (string)($transcripts[$sid] ?? ''));
            if ($item) $items[] = $item;
        }
    }
    usort($items, static function(array $a, array $b): int {
        $at = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $bt = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($at !== $bt) return $bt <=> $at;
        if ((int)$a['session_id'] !== (int)$b['session_id']) return (int)$b['session_id'] <=> (int)$a['session_id'];
        return (int)$b['started_ms'] <=> (int)$a['started_ms'];
    });
    $query = mb_strtolower(trim($query));
    if ($query !== '') {
        $items = array_values(array_filter($items, static function(array $item) use ($query): bool {
            $association = is_array($item['association'] ?? null) ? (string)($item['association']['label'] ?? '') : '';
            $haystack = mb_strtolower(implode(' ', [
                (string)($item['name'] ?? ''),
                (string)($item['session_title'] ?? ''),
                $association,
                (string)($item['transcript_excerpt'] ?? ''),
            ]));
            return str_contains($haystack, $query);
        }));
    }
    return array_slice($items, 0, $limit);
}

function artist_recordings_v198_mutate(PDO $pdo, array $user, int $sessionId, string $key, callable $mutator): array
{
    $key = artist_listening_v197_recording_key($key);
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)($session['status'] ?? '') === 'discarded') throw new RuntimeException('Restore this transcription before editing its recordings.');
        $rows = artist_recordings_v198_raw_rows($session);
        $found = false;
        foreach ($rows as $index => &$row) {
            if (!is_array($row) || strtolower(trim((string)($row['key'] ?? ''))) !== $key) continue;
            $row = $mutator($row, (int)$index);
            $found = true;
            break;
        }
        unset($row);
        if (!$found) throw new RuntimeException('Recording not found.');
        artist_recordings_v198_save_rows($pdo, $user, $session, $rows);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $library = artist_recordings_v198_library($pdo, $user, 200, '', $sessionId);
    foreach ($library as $item) if ((string)$item['key'] === $key) return $item;
    throw new RuntimeException('Recording metadata could not be reloaded.');
}

function artist_recordings_v198_delete(PDO $pdo, array $user, int $sessionId, string $key): array
{
    $key = artist_listening_v197_recording_key($key);
    $fileName = '';
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)($session['status'] ?? '') === 'discarded') throw new RuntimeException('Restore this transcription before deleting its recordings.');
        $rows = artist_recordings_v198_raw_rows($session);
        $next = [];
        $found = false;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (strtolower(trim((string)($row['key'] ?? ''))) === $key) {
                $fileName = basename((string)($row['file_name'] ?? ''));
                $found = true;
                continue;
            }
            $next[] = $row;
        }
        if (!$found) throw new RuntimeException('Recording not found.');
        artist_recordings_v198_save_rows($pdo, $user, $session, $next);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($fileName !== '') {
        $path = artist_listening_v197_private_dir($user, $sessionId) . '/' . $fileName;
        if (is_file($path)) @unlink($path);
    }
    return ['deleted'=>true,'session_id'=>$sessionId,'key'=>$key];
}

$user = current_user();
if (!$user) artist_recordings_v198_json(false, ['error'=>'Sign in to use recording library.'], 401);
if (!has_permission('artist_listening.access', $user)) artist_recordings_v198_json(false, ['error'=>'Artist Listening permission is required.'], 403);
if (!artist_listening_v172_schema_ready()) artist_recordings_v198_json(false, ['error'=>'Artist Listening is not ready.'], 503);
$pdo = db();
if (!$pdo) artist_recordings_v198_json(false, ['error'=>'Database unavailable.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'library'));

try {
    if ($method === 'GET') {
        if ($action === 'library') {
            $items = artist_recordings_v198_library(
                $pdo,
                $user,
                max(1, (int)($_GET['limit'] ?? 120)),
                (string)($_GET['q'] ?? ''),
                max(0, (int)($_GET['session_id'] ?? 0))
            );
            artist_recordings_v198_json(true, ['build'=>STONEFELLOW_ARTIST_RECORDINGS_V198,'recordings'=>$items,'count'=>count($items)]);
        }
        artist_recordings_v198_json(false, ['error'=>'Unknown recording library request.'], 404);
    }
    if ($method !== 'POST') artist_recordings_v198_json(false, ['error'=>'POST is required.'], 405);
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $action = trim((string)($input['action'] ?? ''));
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) artist_recordings_v198_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    $sessionId = max(0, (int)($input['session_id'] ?? 0));
    $key = (string)($input['recording_key'] ?? '');
    if ($action === 'rename') {
        $name = artist_recordings_v198_clean_name((string)($input['name'] ?? ''));
        $recording = artist_recordings_v198_mutate($pdo, $user, $sessionId, $key, static function(array $row) use ($name): array {
            $row['name'] = $name;
            $row['updated_at'] = gmdate('c');
            return $row;
        });
        artist_recordings_v198_json(true, ['recording'=>$recording]);
    }
    if ($action === 'favorite') {
        $favorite = filter_var($input['favorite'] ?? false, FILTER_VALIDATE_BOOL);
        $recording = artist_recordings_v198_mutate($pdo, $user, $sessionId, $key, static function(array $row) use ($favorite): array {
            $row['favorite'] = $favorite;
            $row['updated_at'] = gmdate('c');
            return $row;
        });
        artist_recordings_v198_json(true, ['recording'=>$recording]);
    }
    if ($action === 'delete') {
        artist_recordings_v198_json(true, artist_recordings_v198_delete($pdo, $user, $sessionId, $key));
    }
    artist_recordings_v198_json(false, ['error'=>'Unsupported recording library action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Recording library could not complete that request.';
    artist_recordings_v198_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
