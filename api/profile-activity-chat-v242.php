<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function profile_activity_chat_v242_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function profile_activity_chat_v242_input(): array
{
    $raw = json_decode((string)file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function profile_activity_chat_v242_message(PDO $pdo, array $user, array $event): array
{
    $userId = (int)$user['id'];
    $descriptor = profile_runtime_visitor_descriptor($pdo, $userId, $event);
    $visitor = trim((string)($descriptor['visitor_label'] ?? 'Visitor')) ?: 'Visitor';
    $metadata = json_decode((string)($event['metadata_json'] ?? ''), true);
    if (!is_array($metadata)) $metadata = [];
    $profileConversationId = max(0, (int)($metadata['conversation_id'] ?? 0));
    $conversation = $profileConversationId > 0
        ? profile_runtime_conversation_owner($pdo, $profileConversationId, $userId)
        : null;
    $summary = trim((string)($conversation['last_summary'] ?? ''));
    $type = (string)($event['event_type'] ?? '');

    if ($type === 'needs_owner') {
        $text = 'Your Profile Agent needs your input. ' . $visitor . ' asked a question it could not answer accurately.';
        if ($summary !== '') $text .= ' Their latest message was: “' . mb_strimwidth($summary, 0, 700, '…') . '”';
    } else {
        $text = $visitor . ' started a new Profile Agent conversation.';
        if ($summary !== '') $text .= ' Their latest message was: “' . mb_strimwidth($summary, 0, 700, '…') . '”';
    }

    $actions = [];
    if ($profileConversationId > 0) {
        $actions[] = [
            'type'=>'open_url',
            'label'=>'Open Profile Agent conversation',
            'url'=>url('/profile-agent.php?tab=inbox&conversation=' . $profileConversationId),
        ];
    }

    return [
        'text'=>$text,
        'actions'=>$actions,
        'profile_conversation_id'=>$profileConversationId,
        'event_type'=>$type,
        'visitor_label'=>$visitor,
    ];
}

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>'Chat access is unavailable.'], 403);
}
if (!personal_capability_has_v242('profile_agent.access', $user)) {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>'Profile Agent access is unavailable.'], 403);
}

$pdo = db();
if (!$pdo || !profile_agent_schema_ready($pdo) || !table_exists('chat_conversations') || !table_exists('chat_messages')) {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>'Profile Agent chat storage is not ready.'], 503);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>'POST is required.'], 405);
}
$input = profile_activity_chat_v242_input();
$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'], 419);
}

try {
    $userId = (int)$user['id'];
    $eventId = max(0, (int)($input['event_id'] ?? 0));
    if ($eventId < 1) throw new RuntimeException('Profile activity event is required.');

    $eventStmt = $pdo->prepare(
        'SELECT e.*,s.identity_disclosed,s.visitor_user_id
         FROM profile_events e
         LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id
         WHERE e.id=? AND e.owner_user_id=? LIMIT 1'
    );
    $eventStmt->execute([$eventId, $userId]);
    $event = $eventStmt->fetch();
    if (!$event) throw new RuntimeException('Profile activity event was not found.');
    if (!in_array((string)$event['event_type'], ['conversation_started','needs_owner'], true)) {
        profile_activity_chat_v242_json(['ok'=>true,'handled'=>false]);
    }

    $fingerprint = '%"profile_event_id":' . $eventId . '%';
    $existingStmt = $pdo->prepare(
        "SELECT m.id,m.conversation_id,m.message,m.context_json
         FROM chat_messages m
         INNER JOIN chat_conversations c ON c.id=m.conversation_id
         WHERE c.user_id=? AND m.role='assistant' AND m.context_json LIKE ?
         ORDER BY m.id DESC LIMIT 1"
    );
    $existingStmt->execute([$userId, $fingerprint]);
    if ($existing = $existingStmt->fetch()) {
        $ctx = json_decode((string)($existing['context_json'] ?? ''), true);
        profile_activity_chat_v242_json([
            'ok'=>true,
            'handled'=>true,
            'duplicate'=>true,
            'conversation_id'=>(int)$existing['conversation_id'],
            'message_id'=>(int)$existing['id'],
            'message'=>(string)$existing['message'],
            'actions'=>is_array($ctx['actions'] ?? null) ? $ctx['actions'] : [],
        ]);
    }

    $agentId = max(0, (int)($input['agent_id'] ?? 0));
    $agent = $agentId > 0 ? user_agent_get_v236($pdo, $userId, $agentId) : null;
    if ($agentId > 0 && (!$agent || empty($agent['is_active']))) {
        throw new RuntimeException('The selected Agent Chat identity is unavailable.');
    }

    $conversationId = max(0, (int)($input['conversation_id'] ?? 0));
    if ($conversationId > 0) {
        if ($agent) {
            $check = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id=? LIMIT 1');
            $check->execute([$conversationId, $userId, (int)$agent['id']]);
        } else {
            $check = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id IS NULL LIMIT 1');
            $check->execute([$conversationId, $userId]);
        }
        if (!$check->fetchColumn()) $conversationId = 0;
    }

    if ($conversationId < 1) {
        $workspaceId = artist_workspace_v181_scope_id($user);
        $create = $pdo->prepare('INSERT INTO chat_conversations (user_id,user_agent_id,artist_workspace_id,title) VALUES (?,?,?,?)');
        $create->execute([$userId, $agent ? (int)$agent['id'] : null, $workspaceId ?: null, 'Profile Agent activity']);
        $conversationId = (int)$pdo->lastInsertId();
    }

    $message = profile_activity_chat_v242_message($pdo, $user, $event);
    $context = [
        'sources'=>[['source'=>'profile-agent:event:' . $eventId,'title'=>'Profile Agent activity']],
        'media'=>[],
        'stem_media'=>[],
        'actions'=>$message['actions'],
        'playlist_title'=>'',
        'profile_activity'=>[
            'profile_event_id'=>$eventId,
            'event_type'=>$message['event_type'],
            'profile_conversation_id'=>$message['profile_conversation_id'],
            'visitor_label'=>$message['visitor_label'],
        ],
    ];
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $insert = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant',?,?)");
    $insert->execute([$conversationId, $message['text'], is_string($contextJson) ? $contextJson : '{}']);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId, $userId]);

    if (personal_capability_has_v242('agent_brain.access', $user)) {
        agent_brain_archive_and_parse($user, $conversationId, $messageId, 'assistant', $message['text'], 'text');
    }

    profile_activity_chat_v242_json([
        'ok'=>true,
        'handled'=>true,
        'duplicate'=>false,
        'conversation_id'=>$conversationId,
        'message_id'=>$messageId,
        'message'=>$message['text'],
        'actions'=>$message['actions'],
        'profile_event_id'=>$eventId,
        'event_type'=>$message['event_type'],
    ]);
} catch (Throwable $e) {
    profile_activity_chat_v242_json(['ok'=>false,'error'=>$e->getMessage()], 400);
}
