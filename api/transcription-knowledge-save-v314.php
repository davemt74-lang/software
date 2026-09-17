<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

const VP3_TRANSCRIPTION_KNOWLEDGE_ROUNDTRIP_V315 = 'transcription-knowledge-roundtrip-v315-20260917';

function transcription_knowledge_v314_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>VP3_TRANSCRIPTION_KNOWLEDGE_ROUNDTRIP_V315] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function transcription_knowledge_v315_marker(int $sessionId): string
{
    return 'personal-' . sha1('artist-listening-transcript:' . max(0, $sessionId)) . '.txt';
}

function transcription_knowledge_v315_item(PDO $pdo, array $user, array $session): ?array
{
    $userId = max(0, (int)($user['id'] ?? 0));
    $sessionId = max(0, (int)($session['id'] ?? 0));
    if ($userId < 1 || $sessionId < 1) return null;

    $marker = transcription_knowledge_v315_marker($sessionId);
    $linkedId = max(0, (int)($session['knowledge_id'] ?? 0));
    $sql = "SELECT id,folder_id,title,updated_at
            FROM knowledge_items
            WHERE created_by_user_id=? AND knowledge_scope='personal'
              AND file_type='personal_note' AND file_name=?";
    $params = [$userId, $marker];
    if ($linkedId > 0) {
        $sql .= ' ORDER BY (id=?) DESC,id DESC LIMIT 1';
        $params[] = $linkedId;
    } else {
        $sql .= ' ORDER BY id DESC LIMIT 1';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $item = $stmt->fetch();
    return is_array($item) ? $item : null;
}

function transcription_knowledge_v315_state(PDO $pdo, array $user, array $session): array
{
    $item = transcription_knowledge_v315_item($pdo, $user, $session);
    if (!$item) {
        return [
            'saved'=>false,
            'knowledge_id'=>0,
            'scope'=>'personal',
            'folder'=>['id'=>0,'name'=>''],
            'title'=>'',
            'updated_at'=>'',
            'view_url'=>'',
        ];
    }

    $knowledgeId = max(0, (int)($item['id'] ?? 0));
    $folderId = max(0, (int)($item['folder_id'] ?? 0));
    $folder = $folderId > 0 ? personal_knowledge_folder($pdo, $user, $folderId) : null;
    $folderName = $folder ? (string)($folder['folder_name'] ?? 'Folder') : 'Unfiled';
    $folderQuery = $folderId > 0 ? (string)$folderId : 'unfiled';
    $viewUrl = url('/knowledge.php?' . http_build_query([
        'folder'=>$folderQuery,
        'edit'=>$knowledgeId,
    ]) . '#knowledge-form');

    return [
        'saved'=>true,
        'knowledge_id'=>$knowledgeId,
        'scope'=>'personal',
        'folder'=>['id'=>$folderId,'name'=>$folderName],
        'title'=>(string)($item['title'] ?? ''),
        'updated_at'=>(string)($item['updated_at'] ?? ''),
        'view_url'=>$viewUrl,
    ];
}

$user = current_user();
if (!$user) {
    transcription_knowledge_v314_json(false, ['error'=>'Sign in to use transcription knowledge.'], 401);
}
if (!has_permission('artist_listening.access', $user)) {
    transcription_knowledge_v314_json(false, ['error'=>'Artist Listening permission is required.'], 403);
}
if (!personal_capability_has_v242('personal_knowledge.access', $user)) {
    transcription_knowledge_v314_json(false, ['error'=>'Personal Knowledge access is unavailable for this account.'], 403);
}
if (!artist_listening_v172_schema_ready()) {
    transcription_knowledge_v314_json(false, ['error'=>'Artist Listening is not ready. Run the current upgrade.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','POST'], true)) {
    transcription_knowledge_v314_json(false, ['error'=>'GET or POST is required.'], 405);
}

$pdo = db();
if (!$pdo) {
    transcription_knowledge_v314_json(false, ['error'=>'Database unavailable.'], 503);
}

try {
    if ($method === 'GET') {
        $sessionId = max(0, (int)($_GET['session_id'] ?? 0));
        $session = artist_listening_v172_session($pdo, $user, $sessionId);
        transcription_knowledge_v314_json(true, [
            'state'=>transcription_knowledge_v315_state($pdo, $user, $session),
        ]);
    }

    if (!personal_capability_has_v242('personal_knowledge.manage', $user)) {
        transcription_knowledge_v314_json(false, ['error'=>'Personal Knowledge management is unavailable for this account.'], 403);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        transcription_knowledge_v314_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    }

    $sessionId = max(0, (int)($input['session_id'] ?? 0));
    $folderId = max(0, (int)($input['folder_id'] ?? 0));

    $pdo->beginTransaction();
    try {
        // Lock the owner-scoped transcription row so simultaneous saves of the
        // same transcript cannot race the deterministic Personal Knowledge upsert.
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)($session['status'] ?? '') === 'discarded') {
            throw new RuntimeException('Restore this transcription before saving it to My Knowledge.');
        }

        $folder = null;
        if ($folderId > 0) {
            $folder = personal_knowledge_folder($pdo, $user, $folderId);
            if (!$folder) {
                throw new RuntimeException('The selected Knowledge folder is no longer available.');
            }
        }

        $text = artist_listening_v172_promotable_text($pdo, $sessionId, '');
        $title = artist_listening_v172_clean_title((string)($session['title'] ?? 'Untitled transcription'));
        $knowledgeId = personal_knowledge_store(
            $user,
            'artist-listening-transcript:' . $sessionId,
            $title,
            $text,
            'Source: Artist Listening · transcription #' . $sessionId,
            $folderId
        );
        if ($knowledgeId < 1) {
            throw new RuntimeException('My Knowledge could not save this transcription.');
        }

        // Keep an explicit owner/scope correction here so an Unfiled save is
        // deterministic even if the canonical store implementation changes.
        $pdo->prepare(
            "UPDATE knowledge_items SET folder_id=?
             WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'"
        )->execute([$folderId > 0 ? $folderId : null, $knowledgeId, (int)$user['id']]);

        $pdo->prepare(
            'UPDATE artist_transcript_sessions_v172
             SET knowledge_id=?,last_activity_at=NOW()
             WHERE id=? AND created_by_user_id=?'
        )->execute([$knowledgeId, $sessionId, (int)$user['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $savedSession = artist_listening_v172_session($pdo, $user, $sessionId);
    $state = transcription_knowledge_v315_state($pdo, $user, $savedSession);
    transcription_knowledge_v314_json(true, [
        'knowledge_id'=>(int)$state['knowledge_id'],
        'scope'=>'personal',
        'folder'=>$state['folder'],
        'view_url'=>(string)$state['view_url'],
        'saved_at'=>gmdate('c'),
        'state'=>$state,
    ]);
} catch (Throwable $e) {
    transcription_knowledge_v314_json(false, ['error'=>$e->getMessage()], 400);
}
