<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

function transcription_knowledge_v314_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    transcription_knowledge_v314_json(false, ['error'=>'Sign in to save transcription knowledge.'], 401);
}
if (!has_permission('artist_listening.access', $user)) {
    transcription_knowledge_v314_json(false, ['error'=>'Artist Listening permission is required.'], 403);
}
if (!personal_capability_has_v242('personal_knowledge.access', $user)
    || !personal_capability_has_v242('personal_knowledge.manage', $user)) {
    transcription_knowledge_v314_json(false, ['error'=>'Personal Knowledge management is unavailable for this account.'], 403);
}
if (!artist_listening_v172_schema_ready()) {
    transcription_knowledge_v314_json(false, ['error'=>'Artist Listening is not ready. Run the current upgrade.'], 503);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    transcription_knowledge_v314_json(false, ['error'=>'POST is required.'], 405);
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
$pdo = db();
if (!$pdo) {
    transcription_knowledge_v314_json(false, ['error'=>'Database unavailable.'], 503);
}

try {
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
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
        $folderId > 0 ? $folderId : null
    );
    if ($knowledgeId < 1) {
        throw new RuntimeException('My Knowledge could not save this transcription.');
    }

    $pdo->prepare(
        'UPDATE artist_transcript_sessions_v172
         SET knowledge_id=?,last_activity_at=NOW()
         WHERE id=? AND created_by_user_id=?'
    )->execute([$knowledgeId, $sessionId, (int)$user['id']]);

    transcription_knowledge_v314_json(true, [
        'knowledge_id'=>$knowledgeId,
        'scope'=>'personal',
        'folder'=>[
            'id'=>$folderId,
            'name'=>$folder ? (string)($folder['folder_name'] ?? 'Folder') : 'Unfiled',
        ],
        'saved_at'=>gmdate('c'),
    ]);
} catch (Throwable $e) {
    transcription_knowledge_v314_json(false, ['error'=>$e->getMessage()], 400);
}
