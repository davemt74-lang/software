<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function chat_notifications_v240_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_notifications_v240_input(): array
{
    $raw = json_decode((string)file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function chat_notifications_v240_require_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        chat_notifications_v240_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'], 419);
    }
}

function chat_notifications_v240_activity_events(PDO $pdo, int $userId, int $limit = 40): array
{
    if ($userId < 1 || !table_exists('agent_activity_events')) return [];
    $limit = max(1, min(80, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT id,surface,context_key,task_kind,task_title,previous_state,activity_state,reason,details_json,created_at
             FROM agent_activity_events
             WHERE user_id=? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return array_map(static function(array $row): array {
            $details = json_decode((string)($row['details_json'] ?? ''), true);
            return [
                'id'=>(int)$row['id'],
                'surface'=>(string)$row['surface'],
                'context_key'=>(string)$row['context_key'],
                'task_kind'=>(string)$row['task_kind'],
                'task_title'=>(string)$row['task_title'],
                'previous_state'=>(string)$row['previous_state'],
                'activity_state'=>(string)$row['activity_state'],
                'reason'=>(string)$row['reason'],
                'details'=>is_array($details) ? $details : [],
                'created_at'=>(string)$row['created_at'],
            ];
        }, $stmt->fetchAll() ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function chat_notifications_v240_history(PDO $pdo, int $userId, int $limit = 50): array
{
    if ($userId < 1 || !table_exists('agent_chat_archive')) return [];
    $limit = max(1, min(100, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT id,conversation_id,source_message_id,role,input_mode,message_text,created_at,archived_at
             FROM agent_chat_archive
             WHERE user_id=? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],
            'conversation_id'=>(int)$row['conversation_id'],
            'source_message_id'=>(int)$row['source_message_id'],
            'role'=>(string)$row['role'],
            'input_mode'=>(string)$row['input_mode'],
            'message'=>(string)$row['message_text'],
            'created_at'=>(string)$row['created_at'],
            'archived_at'=>(string)$row['archived_at'],
        ], $stmt->fetchAll() ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function chat_notifications_v240_state(array $user, PDO $pdo): array
{
    $userId = (int)($user['id'] ?? 0);
    $brainAllowed = personal_capability_has_v242('agent_brain.access', $user);
    $brain = $brainAllowed && agent_brain_schema_ready() ? agent_brain_summary($user) : [
        'archive_count'=>0,'memory_count'=>0,'themes'=>[],'dates'=>[],'files'=>[],'recent'=>[]
    ];
    $activity = $brainAllowed ? agent_activity_v94_snapshot($user, 'chat', []) : [];

    return [
        'ok'=>true,
        'notifications'=>[
            'unread'=>notification_unread_count($user),
            'items'=>notification_recent($user, 25),
        ],
        'attention_cursor'=>notification_latest_id($user),
        'brain'=>[
            'enabled'=>$brainAllowed,
            'archive_count'=>(int)($brain['archive_count'] ?? 0),
            'memory_count'=>(int)($brain['memory_count'] ?? 0),
            'themes'=>array_values(is_array($brain['themes'] ?? null) ? $brain['themes'] : []),
            'dates'=>array_values(is_array($brain['dates'] ?? null) ? $brain['dates'] : []),
            'files'=>array_values(is_array($brain['files'] ?? null) ? $brain['files'] : []),
            'recent'=>array_values(is_array($brain['recent'] ?? null) ? $brain['recent'] : []),
            'activity'=>$activity,
            'events'=>$brainAllowed ? chat_notifications_v240_activity_events($pdo, $userId, 50) : [],
        ],
        'history'=>$brainAllowed ? chat_notifications_v240_history($pdo, $userId, 60) : [],
    ];
}

function chat_notifications_v240_attention_rows(array $user, int $afterId): array
{
    $rows = notification_attention_after($user, $afterId, 25);
    return array_map(static fn(array $row): array => [
        'id'=>(int)($row['id'] ?? 0),
        'type'=>(string)($row['type'] ?? ''),
        'title'=>(string)($row['title'] ?? ''),
        'body'=>(string)($row['body'] ?? ''),
        'target_url'=>(string)($row['target_url'] ?? ''),
        'created_at'=>(string)($row['created_at'] ?? ''),
        'prompt'=>notification_attention_prompt($row),
    ], $rows);
}

function chat_notifications_v240_agent(PDO $pdo, int $userId, int $agentId): ?array
{
    if ($agentId < 1) return null;
    $agent = user_agent_get_v236($pdo, $userId, $agentId);
    if (!$agent || empty($agent['is_active'])) throw new DomainException('The selected Agent Chat identity is unavailable.');
    return $agent;
}

function chat_notifications_v240_conversation(PDO $pdo, array $user, ?array $agent, int $conversationId): int
{
    $userId = (int)$user['id'];
    if ($conversationId > 0) {
        if ($agent) {
            $check = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id=? LIMIT 1');
            $check->execute([$conversationId, $userId, (int)$agent['id']]);
        } else {
            $check = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id IS NULL LIMIT 1');
            $check->execute([$conversationId, $userId]);
        }
        if ($check->fetchColumn()) return $conversationId;
    }

    $workspaceId = artist_workspace_v181_scope_id($user);
    $create = $pdo->prepare('INSERT INTO chat_conversations (user_id,user_agent_id,artist_workspace_id,title) VALUES (?,?,?,?)');
    $create->execute([$userId, $agent ? (int)$agent['id'] : null, $workspaceId ?: null, 'Attention required']);
    return (int)$pdo->lastInsertId();
}

function chat_notifications_v240_present_attention(PDO $pdo, array $user, array $input): array
{
    $userId = (int)$user['id'];
    $notificationId = max(0, (int)($input['notification_id'] ?? 0));
    if ($notificationId < 1) throw new DomainException('Notification is required.');

    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$notificationId, $userId]);
    $notification = $stmt->fetch();
    if (!$notification) throw new DomainException('Notification was not found.');
    if (!notification_requires_attention($notification)) {
        return ['ok'=>true,'handled'=>false,'notification_id'=>$notificationId];
    }

    $existing = $pdo->prepare(
        "SELECT m.id,m.conversation_id,m.message,m.context_json
         FROM chat_messages m
         INNER JOIN chat_conversations c ON c.id=m.conversation_id
         WHERE c.user_id=?
           AND m.role='assistant'
           AND JSON_VALID(m.context_json)=1
           AND CAST(JSON_UNQUOTE(JSON_EXTRACT(m.context_json,'$.attention.notification_id')) AS UNSIGNED)=?
         ORDER BY m.id DESC LIMIT 1"
    );
    $existing->execute([$userId, $notificationId]);
    if ($row = $existing->fetch()) {
        $ctx = json_decode((string)($row['context_json'] ?? ''), true);
        return [
            'ok'=>true,'handled'=>true,'duplicate'=>true,
            'notification_id'=>$notificationId,
            'conversation_id'=>(int)$row['conversation_id'],
            'message_id'=>(int)$row['id'],
            'message'=>(string)$row['message'],
            'actions'=>is_array($ctx['actions'] ?? null) ? $ctx['actions'] : [],
        ];
    }

    $agent = chat_notifications_v240_agent($pdo, $userId, max(0, (int)($input['agent_id'] ?? 0)));
    $conversationId = chat_notifications_v240_conversation($pdo, $user, $agent, max(0, (int)($input['conversation_id'] ?? 0)));
    $decision = function_exists('profile_visitor_attention_decision_v243')
        ? profile_visitor_attention_decision_v243($pdo, $userId, $notification)
        : null;
    $message = is_array($decision) && trim((string)($decision['message'] ?? '')) !== ''
        ? trim((string)$decision['message'])
        : notification_attention_message($notification);
    $prompt = is_array($decision) && trim((string)($decision['prompt'] ?? '')) !== ''
        ? trim((string)$decision['prompt'])
        : notification_attention_prompt($notification);
    $contact = is_array($decision) && is_array($decision['contact'] ?? null)
        ? $decision['contact']
        : [];

    $target = trim((string)($notification['target_url'] ?? ''));
    $actions = $target !== '' ? [[
        'type'=>'open_url','label'=>'Open','url'=>$target,
    ]] : [];
    $context = [
        'sources'=>[[
            'source'=>'notification:' . $notificationId,
            'title'=>$contact ? 'Profile visitor attention' : 'User attention notification',
        ]],
        'media'=>[],
        'stem_media'=>[],
        'playlist_title'=>'',
        'actions'=>$actions,
        'attention'=>[
            'required'=>true,
            'notification_id'=>$notificationId,
            'notification_type'=>(string)($notification['type'] ?? ''),
            'prompt'=>$prompt,
            'response_timeout_ms'=>10000,
            'target_url'=>$target,
            'profile_contact'=>$contact,
        ],
    ];
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $insert = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant',?,?)");
    $insert->execute([$conversationId, $message, is_string($contextJson) ? $contextJson : '{}']);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId, $userId]);

    if (personal_capability_has_v242('agent_brain.access', $user)) {
        agent_brain_archive_and_parse($user, $conversationId, $messageId, 'assistant', $message, 'text');
    }

    return [
        'ok'=>true,'handled'=>true,'duplicate'=>false,
        'notification_id'=>$notificationId,
        'conversation_id'=>$conversationId,
        'message_id'=>$messageId,
        'message'=>$message,
        'prompt'=>$prompt,
        'actions'=>$actions,
        'profile_contact'=>$contact,
        'response_timeout_ms'=>10000,
    ];
}

$user = current_user();
if (!$user) chat_notifications_v240_json(['ok'=>false,'error'=>'login_required'], 401);
if (!has_permission('chat.access', $user)) chat_notifications_v240_json(['ok'=>false,'error'=>'forbidden'], 403);
$pdo = db();
if (!$pdo) chat_notifications_v240_json(['ok'=>false,'error'=>'database_unavailable'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? chat_notifications_v240_input() : $_GET;
$action = trim((string)($input['action'] ?? 'state'));

try {
    if ($method === 'GET' && $action === 'state') {
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }
    if ($method === 'GET' && $action === 'attention') {
        $afterId = max(0, (int)($input['after_id'] ?? 0));
        chat_notifications_v240_json([
            'ok'=>true,
            'latest_id'=>notification_latest_id($user),
            'items'=>chat_notifications_v240_attention_rows($user, $afterId),
        ]);
    }
    if ($method !== 'POST') chat_notifications_v240_json(['ok'=>false,'error'=>'POST is required.'], 405);
    chat_notifications_v240_require_csrf($input);

    if ($action === 'present_attention') {
        chat_notifications_v240_json(chat_notifications_v240_present_attention($pdo, $user, $input));
    }
    if ($action === 'mark_read') {
        $id = max(0, (int)($input['notification_id'] ?? 0));
        if ($id < 1) chat_notifications_v240_json(['ok'=>false,'error'=>'notification_required'], 400);
        mark_notification_read($id, (int)$user['id']);
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }
    if ($action === 'mark_all_read') {
        mark_all_notifications_read((int)$user['id']);
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }

    chat_notifications_v240_json(['ok'=>false,'error'=>'unknown_action'], 400);
} catch (DomainException $e) {
    chat_notifications_v240_json(['ok'=>false,'error'=>$e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Stonefellow notification brain drawer v240 error: ' . $e->getMessage());
    chat_notifications_v240_json(['ok'=>false,'error'=>'Activity Center is temporarily unavailable.'], 500);
}