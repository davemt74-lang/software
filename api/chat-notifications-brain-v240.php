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
    if ($userId < 1 || !table_exists('agent_activity_events')) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT id,surface,context_key,task_kind,task_title,previous_state,activity_state,reason,details_json,created_at
             FROM agent_activity_events
             WHERE user_id=?
             ORDER BY id DESC
             LIMIT {$limit}"
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
    if ($userId < 1 || !table_exists('agent_chat_archive')) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT id,conversation_id,source_message_id,role,input_mode,message_text,created_at,archived_at
             FROM agent_chat_archive
             WHERE user_id=?
             ORDER BY id DESC
             LIMIT {$limit}"
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
    $brain = agent_brain_schema_ready() ? agent_brain_summary($user) : [
        'archive_count'=>0,'memory_count'=>0,'themes'=>[],'dates'=>[],'files'=>[],'recent'=>[]
    ];
    $activity = agent_activity_v94_snapshot($user, 'chat', []);

    return [
        'ok'=>true,
        'notifications'=>[
            'unread'=>notification_unread_count($user),
            'items'=>notification_recent($user, 25),
        ],
        'brain'=>[
            'archive_count'=>(int)($brain['archive_count'] ?? 0),
            'memory_count'=>(int)($brain['memory_count'] ?? 0),
            'themes'=>array_values(is_array($brain['themes'] ?? null) ? $brain['themes'] : []),
            'dates'=>array_values(is_array($brain['dates'] ?? null) ? $brain['dates'] : []),
            'files'=>array_values(is_array($brain['files'] ?? null) ? $brain['files'] : []),
            'recent'=>array_values(is_array($brain['recent'] ?? null) ? $brain['recent'] : []),
            'activity'=>$activity,
            'events'=>chat_notifications_v240_activity_events($pdo, $userId, 50),
        ],
        'history'=>chat_notifications_v240_history($pdo, $userId, 60),
    ];
}

$user = current_user();
if (!$user) {
    chat_notifications_v240_json(['ok'=>false,'error'=>'login_required'], 401);
}
if (!has_permission('chat.access', $user)) {
    chat_notifications_v240_json(['ok'=>false,'error'=>'forbidden'], 403);
}
$pdo = db();
if (!$pdo) {
    chat_notifications_v240_json(['ok'=>false,'error'=>'database_unavailable'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? chat_notifications_v240_input() : $_GET;
$action = trim((string)($input['action'] ?? 'state'));

try {
    if ($method === 'GET' && $action === 'state') {
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }
    if ($method !== 'POST') {
        chat_notifications_v240_json(['ok'=>false,'error'=>'POST is required.'], 405);
    }
    chat_notifications_v240_require_csrf($input);

    if ($action === 'mark_read') {
        $id = max(0, (int)($input['notification_id'] ?? 0));
        if ($id < 1) {
            chat_notifications_v240_json(['ok'=>false,'error'=>'notification_required'], 400);
        }
        mark_notification_read($id, (int)$user['id']);
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }
    if ($action === 'mark_all_read') {
        mark_all_notifications_read((int)$user['id']);
        chat_notifications_v240_json(chat_notifications_v240_state($user, $pdo));
    }

    chat_notifications_v240_json(['ok'=>false,'error'=>'unknown_action'], 400);
} catch (Throwable $e) {
    error_log('Stonefellow notification brain drawer v240 error: ' . $e->getMessage());
    chat_notifications_v240_json(['ok'=>false,'error'=>'Unable to load notification activity.'], 500);
}
