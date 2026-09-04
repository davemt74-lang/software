<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// Recovery compatibility only: PR82 predates the v159 public-platform helper,
// while the production database may already include the later multi-role schema.
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

function artist_listening_v172_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Persist timestamped Artist Listening note segments into the selected song's
 * track_notes stream. The metadata map makes retries idempotent while keeping
 * transcript storage authoritative and private.
 */
function artist_listening_v195_sync_song_notes(PDO $pdo, array $user, int $sessionId): array
{
    if ($sessionId < 1 || !table_exists('track_notes')) {
        return ['synced'=>0, 'track_id'=>0];
    }

    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        $metadata = json_decode((string)($session['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $association = is_array($metadata['association'] ?? null) ? $metadata['association'] : [];
        $type = strtolower(trim((string)($association['type'] ?? 'none')));
        $trackId = max(0, (int)($association['track_id'] ?? 0));

        if (!in_array($type, ['song', 'studio_project'], true) || $trackId < 1) {
            $pdo->commit();
            return ['synced'=>0, 'track_id'=>0];
        }
        if (!artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
            throw new RuntimeException('The selected song is no longer available to this account.');
        }
        if (!has_permission('track_notes.manage', $user)
            && !has_permission('tracks.manage', $user)
            && !has_permission('producer.access', $user)) {
            throw new RuntimeException('You do not have permission to save transcription notes to the selected song.');
        }

        $promotions = is_array($metadata['song_note_promotions_v195'] ?? null)
            ? $metadata['song_note_promotions_v195']
            : [];
        $notes = $pdo->prepare(
            "SELECT client_segment_key,transcript_text
             FROM artist_transcript_segments_v172
             WHERE session_id=? AND segment_type='note' AND TRIM(transcript_text)<>''
             ORDER BY segment_index ASC,id ASC"
        );
        $notes->execute([$sessionId]);
        $insert = $pdo->prepare('INSERT INTO track_notes (track_id,user_id,note) VALUES (?,?,?)');

        $synced = 0;
        $lastNoteId = 0;
        foreach ($notes->fetchAll() ?: [] as $note) {
            $clientKey = strtolower(trim((string)($note['client_segment_key'] ?? '')));
            if ($clientKey === '') {
                continue;
            }
            $promotionKey = $trackId . ':' . $clientKey;
            $existing = max(0, (int)($promotions[$promotionKey] ?? 0));
            if ($existing > 0) {
                $lastNoteId = $existing;
                continue;
            }
            $text = trim((string)($note['transcript_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $insert->execute([$trackId, (int)$user['id'], mb_strimwidth($text, 0, 65000, '…')]);
            $noteId = (int)$pdo->lastInsertId();
            if ($noteId < 1) {
                throw new RuntimeException('The transcription note could not be saved to the selected song.');
            }
            $promotions[$promotionKey] = $noteId;
            $lastNoteId = $noteId;
            $synced++;
        }

        if ($synced > 0) {
            $metadata['song_note_promotions_v195'] = $promotions;
            $metadata['song_notes_synced_at'] = gmdate('c');
            $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('The transcription note sync metadata could not be encoded.');
            }
            $pdo->prepare(
                'UPDATE artist_transcript_sessions_v172
                 SET metadata_json=?,project_note_id=?,project_track_id=?,last_activity_at=NOW()
                 WHERE id=? AND created_by_user_id=?'
            )->execute([$json, $lastNoteId > 0 ? $lastNoteId : null, $trackId, $sessionId, (int)$user['id']]);
        }

        $pdo->commit();
        return ['synced'=>$synced, 'track_id'=>$trackId, 'project_note_id'=>$lastNoteId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$user = current_user();
if (!$user) {
    artist_listening_v172_json(false, ['error'=>'Sign in to use Artist Listening.'], 401);
}
if (!has_permission('artist_listening.access', $user)) {
    artist_listening_v172_json(false, ['error'=>'Artist Listening permission is required.'], 403);
}
if (!artist_listening_v172_schema_ready()) {
    artist_listening_v172_json(false, ['error'=>'Artist Listening is not ready. Run the Stonefellow v172 upgrade.'], 503);
}

$pdo = db();
if (!$pdo) {
    artist_listening_v172_json(false, ['error'=>'Database unavailable.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'bootstrap'));

try {
    if ($method === 'GET') {
        if ($action === 'recording') {
            artist_listening_v197_stream_recording(
                $pdo,
                $user,
                max(0, (int)($_GET['session_id'] ?? 0)),
                (string)($_GET['recording_key'] ?? '')
            );
        }
        if ($action === 'bootstrap') {
            $sessions = artist_listening_v172_list($user, 20);
            $active = null;
            foreach ($sessions as $candidate) {
                if ((string)($candidate['status'] ?? '') === 'active') {
                    $active = artist_listening_v172_payload($pdo, $user, (int)$candidate['id']);
                    break;
                }
            }
            artist_listening_v172_json(true, [
                'build'=>STONEFELLOW_ARTIST_LISTENING_V172,
                'audio_retained'=>true,
                'sessions'=>$sessions,
                'active'=>$active,
            ]);
        }
        if ($action === 'session') {
            $sessionId = max(0, (int)($_GET['session_id'] ?? 0));
            artist_listening_v172_json(true, ['session'=>artist_listening_v172_payload($pdo, $user, $sessionId)]);
        }
        artist_listening_v172_json(false, ['error'=>'Unknown Artist Listening request.'], 404);
    }

    if ($method !== 'POST') {
        artist_listening_v172_json(false, ['error'=>'POST is required.'], 405);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = trim((string)($input['action'] ?? $action));
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        artist_listening_v172_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    }

    if ($action === 'upload_recording') {
        $sessionId = max(0, (int)($input['session_id'] ?? 0));
        $recording = artist_listening_v197_store_recording(
            $pdo,
            $user,
            $sessionId,
            (string)($input['client_recording_key'] ?? ''),
            is_array($_FILES['audio'] ?? null) ? $_FILES['audio'] : [],
            max(0, (int)($input['started_ms'] ?? 0)),
            max(0, (int)($input['ended_ms'] ?? 0)),
            max(0, (int)($input['duration_ms'] ?? 0))
        );
        artist_listening_v172_json(true, [
            'recording'=>$recording,
            'session'=>artist_listening_v172_payload($pdo, $user, $sessionId),
        ]);
    }

    if ($action === 'start') {
        $session = artist_listening_v172_start(
            $user,
            (string)($input['client_session_key'] ?? ''),
            max(0, (int)($input['conversation_id'] ?? 0)),
            trim((string)($input['language'] ?? 'en-US')),
            trim((string)($input['speaker_mode'] ?? 'auto'))
        );
        artist_listening_v172_json(true, ['session'=>$session]);
    }
    if ($action === 'append') {
        $segments = $input['segments'] ?? [];
        if (!is_array($segments)) {
            $segments = [];
        }
        $sessionId = max(0, (int)($input['session_id'] ?? 0));
        $result = artist_listening_v172_append($user, $sessionId, $segments);
        $songNotes = artist_listening_v195_sync_song_notes($pdo, $user, $sessionId);
        $result['song_notes_synced'] = (int)($songNotes['synced'] ?? 0);
        $result['song_track_id'] = (int)($songNotes['track_id'] ?? 0);
        if ((int)($songNotes['synced'] ?? 0) > 0) {
            $result['session'] = artist_listening_v172_payload($pdo, $user, $sessionId);
        }
        artist_listening_v172_json(true, $result);
    }
    if ($action === 'activate') {
        artist_listening_v172_json(true, ['session'=>artist_listening_v172_activate(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            max(0, (int)($input['conversation_id'] ?? 0))
        )]);
    }
    if ($action === 'stop') {
        $sessionId = max(0, (int)($input['session_id'] ?? 0));
        $session = artist_listening_v172_stop(
            $user,
            $sessionId,
            max(0, (int)($input['duration_ms'] ?? 0))
        );
        $songNotes = artist_listening_v195_sync_song_notes($pdo, $user, $sessionId);
        if ((int)($songNotes['synced'] ?? 0) > 0) {
            $session = artist_listening_v172_payload($pdo, $user, $sessionId);
        }
        artist_listening_v172_json(true, [
            'session'=>$session,
            'song_notes_synced'=>(int)($songNotes['synced'] ?? 0),
            'song_track_id'=>(int)($songNotes['track_id'] ?? 0),
        ]);
    }
    if ($action === 'rename') {
        artist_listening_v172_json(true, ['session'=>artist_listening_v172_rename(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            (string)($input['title'] ?? '')
        )]);
    }
    if ($action === 'edit_segment') {
        artist_listening_v172_json(true, ['session'=>artist_listening_v172_edit_segment(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            max(0, (int)($input['segment_id'] ?? 0)),
            (string)($input['text'] ?? '')
        )]);
    }
    if ($action === 'discard') {
        artist_listening_v172_json(true, artist_listening_v172_discard(
            $user,
            max(0, (int)($input['session_id'] ?? 0))
        ));
    }
    if ($action === 'restore') {
        artist_listening_v172_json(true, ['session'=>artist_listening_v172_restore(
            $user,
            max(0, (int)($input['session_id'] ?? 0))
        )]);
    }
    if ($action === 'promote_memory') {
        artist_listening_v172_json(true, artist_listening_v172_promote_memory(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            (string)($input['selected_text'] ?? '')
        ));
    }
    if ($action === 'promote_project_note') {
        artist_listening_v172_json(true, artist_listening_v172_promote_project_note(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            max(0, (int)($input['track_id'] ?? 0)),
            (string)($input['selected_text'] ?? '')
        ));
    }
    if ($action === 'promote_knowledge') {
        artist_listening_v172_json(true, artist_listening_v172_promote_knowledge(
            $user,
            max(0, (int)($input['session_id'] ?? 0)),
            max(0, (int)($input['track_id'] ?? 0)),
            (string)($input['selected_text'] ?? '')
        ));
    }
    artist_listening_v172_json(false, ['error'=>'Unsupported Artist Listening action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Artist Listening could not complete that request.';
    artist_listening_v172_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
