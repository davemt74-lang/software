<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function chat_recordings_v242_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_recordings_v242_owned_conversation(PDO $pdo, int $conversationId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function chat_recordings_v242_valid_refs(PDO $pdo, array $user, array $refs): array
{
    $valid = [];
    $seen = [];

    foreach (array_slice($refs, 0, 8) as $ref) {
        if (!is_array($ref)) continue;
        $sessionId = max(0, (int)($ref['session_id'] ?? 0));
        $key = strtolower(trim((string)($ref['key'] ?? '')));
        if ($sessionId < 1 || !preg_match('/^[a-z0-9-]{16,64}$/', $key)) continue;
        $identity = $sessionId . ':' . $key;
        if (isset($seen[$identity])) continue;

        try {
            $session = artist_listening_v172_session($pdo, $user, $sessionId);
        } catch (Throwable $e) {
            continue;
        }

        $metadata = artist_listening_v197_metadata($session);
        $rows = is_array($metadata['recordings_v197'] ?? null)
            ? $metadata['recordings_v197']
            : [];
        $exists = false;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (strtolower(trim((string)($row['key'] ?? ''))) === $key) {
                $exists = true;
                break;
            }
        }
        if (!$exists) continue;

        $seen[$identity] = true;
        $valid[] = ['session_id'=>$sessionId, 'key'=>$key];
    }

    return $valid;
}

$user = current_user();
if (!$user) chat_recordings_v242_json(false, ['error'=>'Sign in to save recording results.'], 401);
if (!has_permission('chat.access', $user) || !has_permission('artist_listening.access', $user)) {
    chat_recordings_v242_json(false, ['error'=>'Agent Chat and Artist Listening access are required.'], 403);
}

$pdo = db();
if (!$pdo || !table_exists('chat_conversations') || !table_exists('chat_messages')) {
    chat_recordings_v242_json(false, ['error'=>'Chat storage is not ready.'], 503);
}
if (!artist_listening_v172_schema_ready()) {
    chat_recordings_v242_json(false, ['error'=>'Artist Listening is not ready.'], 503);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$csrf = trim((string)($input['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    chat_recordings_v242_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
}

$action = trim((string)($input['action'] ?? 'persist'));
if ($action !== 'persist') chat_recordings_v242_json(false, ['error'=>'Unknown recording chat action.'], 404);

$userId = (int)$user['id'];
$conversationId = max(0, (int)($input['conversation_id'] ?? 0));
$userMessage = trim((string)($input['user_message'] ?? ''));
$assistantMessage = trim((string)($input['assistant_message'] ?? ''));
$clientId = strtolower(trim((string)($input['client_id'] ?? '')));
$refs = chat_recordings_v242_valid_refs($pdo, $user, is_array($input['recording_refs'] ?? null) ? $input['recording_refs'] : []);

if (!$refs) chat_recordings_v242_json(false, ['error'=>'No available recordings were supplied.'], 422);
if ($assistantMessage === '') $assistantMessage = count($refs) === 1 ? 'I found 1 recording.' : ('I found ' . count($refs) . ' recordings.');
$assistantMessage = mb_strimwidth($assistantMessage, 0, 900, '…');
$userMessage = mb_strimwidth($userMessage, 0, 6000, '…');
if (!preg_match('/^[a-z0-9-]{10,80}$/', $clientId)) {
    $clientId = bin2hex(random_bytes(10));
}

try {
    $pdo->beginTransaction();

    if ($conversationId > 0) {
        if (!chat_recordings_v242_owned_conversation($pdo, $conversationId, $userId)) {
            throw new RuntimeException('Conversation not found.');
        }
    } else {
        $title = $userMessage !== ''
            ? mb_strimwidth($userMessage, 0, 70, '…')
            : 'Recording results';
        $workspaceId = artist_workspace_v181_scope_id($user);
        $stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id,artist_workspace_id,title) VALUES (?,?,?)');
        $stmt->execute([$userId, $workspaceId ?: null, $title]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    $userMessageId = 0;
    if ($userMessage !== '') {
        $stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (?,?,?,?)');
        $stmt->execute([$conversationId, $userId, 'user', $userMessage]);
        $userMessageId = (int)$pdo->lastInsertId();
    }

    $payload = ['client_id'=>$clientId, 'refs'=>$refs];
    $encoded = rtrim(strtr(base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $storedMessage = $assistantMessage . "\n[[STONEFELLOW_RECORDINGS_V242:" . $encoded . ']]';
    $contextJson = json_encode([
        'recording_refs'=>$refs,
        'recording_client_id'=>$clientId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,?,?,?)');
    $stmt->execute([$conversationId, 'assistant', $storedMessage, $contextJson]);
    $assistantMessageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);

    $pdo->commit();

    chat_recordings_v242_json(true, [
        'build'=>'chat-recordings-v242-20260902',
        'conversation_id'=>$conversationId,
        'user_message_id'=>$userMessageId,
        'assistant_message_id'=>$assistantMessageId,
        'client_id'=>$clientId,
        'recording_refs'=>$refs,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    chat_recordings_v242_json(false, ['error'=>$e->getMessage()], 400);
}
