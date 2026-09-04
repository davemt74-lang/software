<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

const STONEFELLOW_ARTIST_LISTENING_EDIT_V249 = 'artist-listening-edit-v255-csrf-recovery-20260903';

function artist_listening_edit_v249_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>STONEFELLOW_ARTIST_LISTENING_EDIT_V249] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_listening_edit_v249_text(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function artist_listening_edit_v249_segment(PDO $pdo, int $sessionId, int $segmentId, bool $forUpdate = false): array
{
    if ($sessionId < 1 || $segmentId < 1) {
        throw new RuntimeException('Choose a transcript section first.');
    }
    $sql = "SELECT id,session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence,updated_at
            FROM artist_transcript_segments_v172
            WHERE id=? AND session_id=? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$segmentId, $sessionId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Transcript section not found.');
    }
    return $row;
}

function artist_listening_edit_v249_session_for_write(PDO $pdo, array $user, int $sessionId): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
    $status = strtolower(trim((string)($session['status'] ?? '')));
    if ($status === 'active') {
        throw new RuntimeException('Stop Listening before editing transcript sections.');
    }
    if ($status === 'discarded') {
        throw new RuntimeException('Restore this transcript before editing it.');
    }
    return $session;
}

function artist_listening_edit_v249_touch(PDO $pdo, int $sessionId): void
{
    $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
}

function artist_listening_edit_v249_update(PDO $pdo, array $user, int $sessionId, int $segmentId, string $speaker, string $text): array
{
    $speaker = trim($speaker);
    if (!preg_match('/^Speaker [1-4]$/', $speaker)) {
        throw new RuntimeException('Choose Speaker 1, Speaker 2, Speaker 3, or Speaker 4.');
    }
    $text = artist_listening_edit_v249_text($text);
    if ($text === '' || mb_strlen($text) > 8000) {
        throw new RuntimeException('A transcript section must contain 1 to 8,000 characters.');
    }

    $pdo->beginTransaction();
    try {
        artist_listening_edit_v249_session_for_write($pdo, $user, $sessionId);
        $segment = artist_listening_edit_v249_segment($pdo, $sessionId, $segmentId, true);
        if ((string)$segment['segment_type'] !== 'transcript') {
            throw new RuntimeException('Restore this section before editing it.');
        }
        $stmt = $pdo->prepare(
            "UPDATE artist_transcript_segments_v172
             SET speaker_label=?,transcript_text=?,updated_at=NOW()
             WHERE id=? AND session_id=? AND segment_type='transcript'"
        );
        $stmt->execute([$speaker, $text, $segmentId, $sessionId]);
        artist_listening_edit_v249_touch($pdo, $sessionId);
        $fresh = artist_listening_edit_v249_segment($pdo, $sessionId, $segmentId, false);
        $pdo->commit();
        return $fresh;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function artist_listening_edit_v249_delete(PDO $pdo, array $user, int $sessionId, int $segmentId): array
{
    $pdo->beginTransaction();
    try {
        artist_listening_edit_v249_session_for_write($pdo, $user, $sessionId);
        $segment = artist_listening_edit_v249_segment($pdo, $sessionId, $segmentId, true);
        if ((string)$segment['segment_type'] === 'deleted') {
            $pdo->commit();
            return ['segment_id'=>$segmentId,'deleted'=>true];
        }
        if ((string)$segment['segment_type'] !== 'transcript') {
            throw new RuntimeException('Only transcript sections can be deleted here.');
        }
        $stmt = $pdo->prepare(
            "UPDATE artist_transcript_segments_v172
             SET segment_type='deleted',updated_at=NOW()
             WHERE id=? AND session_id=? AND segment_type='transcript'"
        );
        $stmt->execute([$segmentId, $sessionId]);
        artist_listening_edit_v249_touch($pdo, $sessionId);
        $pdo->commit();
        return ['segment_id'=>$segmentId,'deleted'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function artist_listening_edit_v249_restore(PDO $pdo, array $user, int $sessionId, int $segmentId): array
{
    $pdo->beginTransaction();
    try {
        artist_listening_edit_v249_session_for_write($pdo, $user, $sessionId);
        $segment = artist_listening_edit_v249_segment($pdo, $sessionId, $segmentId, true);
        if ((string)$segment['segment_type'] === 'transcript') {
            $pdo->commit();
            return ['segment'=>$segment,'restored'=>true];
        }
        if ((string)$segment['segment_type'] !== 'deleted') {
            throw new RuntimeException('This section cannot be restored from Edit mode.');
        }
        $stmt = $pdo->prepare(
            "UPDATE artist_transcript_segments_v172
             SET segment_type='transcript',updated_at=NOW()
             WHERE id=? AND session_id=? AND segment_type='deleted'"
        );
        $stmt->execute([$segmentId, $sessionId]);
        artist_listening_edit_v249_touch($pdo, $sessionId);
        $fresh = artist_listening_edit_v249_segment($pdo, $sessionId, $segmentId, false);
        $pdo->commit();
        return ['segment'=>$fresh,'restored'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$user = current_user();
if (!$user) artist_listening_edit_v249_json(false, ['error'=>'Sign in to edit transcripts.'], 401);
if (!has_permission('artist_listening.access', $user)) artist_listening_edit_v249_json(false, ['error'=>'Artist Listening permission is required.'], 403);
if (!artist_listening_v172_schema_ready()) artist_listening_edit_v249_json(false, ['error'=>'Run the Stonefellow transcript upgrade first.'], 503);
$pdo = db();
if (!$pdo) artist_listening_edit_v249_json(false, ['error'=>'Database unavailable.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$getAction = trim((string)($_GET['action'] ?? ''));
if ($method === 'GET' && $getAction === 'csrf') {
    artist_listening_edit_v249_json(true, ['csrf'=>csrf_token()]);
}
if ($method !== 'POST') artist_listening_edit_v249_json(false, ['error'=>'POST is required.'], 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$csrf = trim((string)($input['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) artist_listening_edit_v249_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);

$action = trim((string)($input['action'] ?? ''));
$sessionId = max(0, (int)($input['session_id'] ?? 0));
$segmentId = max(0, (int)($input['segment_id'] ?? 0));

try {
    if ($action === 'update_segment') {
        artist_listening_edit_v249_json(true, ['segment'=>artist_listening_edit_v249_update(
            $pdo,
            $user,
            $sessionId,
            $segmentId,
            (string)($input['speaker_label'] ?? 'Speaker 1'),
            (string)($input['text'] ?? '')
        )]);
    }
    if ($action === 'delete_segment') {
        artist_listening_edit_v249_json(true, artist_listening_edit_v249_delete($pdo, $user, $sessionId, $segmentId));
    }
    if ($action === 'restore_segment') {
        artist_listening_edit_v249_json(true, artist_listening_edit_v249_restore($pdo, $user, $sessionId, $segmentId));
    }
    artist_listening_edit_v249_json(false, ['error'=>'Unknown transcript edit action.'], 404);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Transcript edit could not be saved.';
    artist_listening_edit_v249_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
