<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chat access is unavailable for this account.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.']);
    exit;
}

$conversationId = max(0, (int)($input['conversation_id'] ?? 0));
$title = preg_replace('/\s+/u', ' ', trim((string)($input['title'] ?? ''))) ?? '';

if ($conversationId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A conversation is required.']);
    exit;
}
if ($title === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Enter a chat name.']);
    exit;
}
if (mb_strlen($title) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Keep the chat name under 120 characters.']);
    exit;
}

$pdo = db();
if (!$pdo || !table_exists('chat_conversations')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Chat storage is unavailable.']);
    exit;
}

$userId = (int)$user['id'];
$stmt = $pdo->prepare('UPDATE chat_conversations SET title=? WHERE id=? AND user_id=?');
$stmt->execute([$title, $conversationId, $userId]);

$check = $pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
$check->execute([$conversationId, $userId]);
$row = $check->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Conversation not found.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'conversation_id' => (int)$row['id'],
    'title' => (string)$row['title'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
