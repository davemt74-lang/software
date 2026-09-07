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

function chat_notifications_v240_brain_operations(array $user, int $limit = 60): array
{
    return array_map(static fn(array $row): array => [
        'id'=>(int)($row['id'] ?? 0),
        'type'=>(string)($row['type'] ?? ''),
        'title'=>(string)($row['title'] ?? ''),
        'body'=>(string)($row['body'] ?? ''),
        'target_url'=>(string)($row['target_url'] ?? ''),
        'source_type'=>(string)($row['source_type'] ?? ''),
        'source_id'=>max(0, (int)($row['source_id'] ?? 0)),
        'created_at'=>(string)($row['created_at'] ?? ''),
    ], notification_agent_brain_activity_after($user, 0, $limit));
}

/**
 * Resolve exact hashes for the current v123 proactive candidate set. This is
 * intentionally server-side: Brain display titles are bounded/truncated, so
 * reconstructing identity from presentation text would be unsafe.
 */
function chat_notifications_v313_base_priority_hash_map(array $user): array
{
    if (!function_exists('agent_cognitive_loop_v310_base_candidates')) return [];
    $context = function_exists('agent_brain_v122_activity_context')
        ? agent_brain_v122_activity_context($user)
        : [];
    try {
        $map = [];
        foreach (agent_cognitive_loop_v310_base_candidates($user, $context) as $candidate) {
            if (!is_array($candidate)) continue;
            $key = trim((string)($candidate['key'] ?? $candidate['hash'] ?? ''));
            $hash = strtolower(trim((string)($candidate['hash'] ?? '')));
            if ($key !== '' && preg_match('/^[a-f0-9]{40}$/', $hash)) $map[$key] = $hash;
        }
        return $map;
    } catch (Throwable $e) {
        error_log('Activity Center Brain priority hash map failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Map the current cognitive priority identity back to the canonical proactive
 * suggestion hash. Proactive priorities come from the exact current candidate
 * map; notification and Analytics identities are deterministic. Unknown or
 * stale future key families are deliberately non-actionable rather than guessed.
 */
function chat_notifications_v313_priority_outcome_hash(array $priority, array $baseHashes = []): string
{
    $key = trim((string)($priority['key'] ?? ''));
    $source = trim((string)($priority['source'] ?? ''));
    $title = trim((string)($priority['title'] ?? ''));
    if ($key === '') return '';

    $mapped = strtolower(trim((string)($baseHashes[$key] ?? '')));
    if (preg_match('/^[a-f0-9]{40}$/', $mapped)) return $mapped;

    if (preg_match('/^notification:(\d+)$/', $key, $match)) {
        return sha1('notification|' . (int)$match[1]);
    }
    if ($source === 'analytics' && $title !== '' && !str_ends_with($title, '…')) {
        return sha1('analytics|' . $key . '|' . $title);
    }
    if (preg_match('/^[a-f0-9]{40}$/', $key)) return $key;
    return '';
}

function chat_notifications_v313_priority_outcome_map(PDO $pdo, int $userId, array $hashes): array
{
    $hashes = array_values(array_unique(array_filter(array_map(
        static fn(mixed $hash): string => preg_match('/^[a-f0-9]{40}$/', (string)$hash) ? (string)$hash : '',
        $hashes
    ))));
    if ($userId < 1 || !$hashes || !table_exists('agent_proactive_events')) return [];

    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT suggestion_hash,event_type,context_json,created_at
             FROM agent_proactive_events
             WHERE user_id=? AND suggestion_hash IN ({$placeholders})
               AND event_type IN ('acted','dismissed')
             ORDER BY id DESC LIMIT 300"
        );
        $stmt->execute(array_merge([$userId], $hashes));
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $hash = (string)($row['suggestion_hash'] ?? '');
            if ($hash === '' || isset($out[$hash])) continue;
            $context = function_exists('agent_action_v313_feedback_context')
                ? agent_action_v313_feedback_context((string)($row['context_json'] ?? ''))
                : (json_decode((string)($row['context_json'] ?? ''), true) ?: []);
            $outcome = function_exists('agent_action_v313_normalize_outcome')
                ? agent_action_v313_normalize_outcome((string)($context['outcome'] ?? ''), (string)($row['event_type'] ?? ''))
                : ((string)($row['event_type'] ?? '') === 'dismissed' ? 'ignored' : 'acted');
            $out[$hash] = [
                'outcome'=>$outcome,
                'created_at'=>(string)($row['created_at'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('Activity Center Brain outcome lookup failed: ' . $e->getMessage());
        return [];
    }
}

function chat_notifications_v313_brain_priorities(array $user, PDO $pdo): array
{
    if (!function_exists('agent_cognitive_loop_v310_state') || !function_exists('agent_cognitive_loop_v310_state_fresh')) return [];
    $state = agent_cognitive_loop_v310_state($user);
    if (!agent_cognitive_loop_v310_state_fresh($state)) return [];

    $baseHashes = chat_notifications_v313_base_priority_hash_map($user);
    $rows = [];
    $hashes = [];
    foreach (array_slice((array)($state['priorities'] ?? []), 0, 6) as $priority) {
        if (!is_array($priority)) continue;
        $hash = chat_notifications_v313_priority_outcome_hash($priority, $baseHashes);
        if ($hash !== '') $hashes[] = $hash;
        $rows[] = [
            'key'=>(string)($priority['key'] ?? ''),
            'title'=>(string)($priority['title'] ?? 'Agent Brain priority'),
            'reason'=>(string)($priority['reason'] ?? ''),
            'source'=>(string)($priority['source'] ?? 'agent_brain'),
            'url'=>(string)($priority['url'] ?? ''),
            'rank'=>max(1, (int)($priority['rank'] ?? count($rows) + 1)),
            'score'=>round((float)($priority['score'] ?? 0), 4),
            'score_delta'=>round((float)($priority['score_delta'] ?? 0), 4),
            'movement'=>(string)($priority['movement'] ?? 'same'),
            'outcome_factor'=>round((float)($priority['outcome_factor'] ?? 1), 4),
            'risk_level'=>(string)($priority['risk_level'] ?? 'low'),
            'requires_approval'=>!empty($priority['requires_approval']),
            'outcome_hash'=>$hash,
            'outcome'=>'',
            'outcome_at'=>'',
        ];
    }
    if (!$rows) return [];

    $outcomes = chat_notifications_v313_priority_outcome_map($pdo, (int)($user['id'] ?? 0), $hashes);
    foreach ($rows as &$row) {
        $hash = (string)$row['outcome_hash'];
        if ($hash !== '' && isset($outcomes[$hash])) {
            $row['outcome'] = (string)($outcomes[$hash]['outcome'] ?? '');
            $row['outcome_at'] = (string)($outcomes[$hash]['created_at'] ?? '');
        }
    }
    unset($row);
    return $rows;
}

function chat_notifications_v313_record_brain_outcome(PDO $pdo, array $user, array $input): array
{
    if (!personal_capability_has_v242('agent_brain.access', $user)) {
        throw new DomainException('Agent Brain is not enabled for this account type.');
    }
    if (!function_exists('agent_action_v124_record_outcome')) {
        throw new DomainException('Agent Brain outcome learning is unavailable.');
    }

    $hash = strtolower(trim((string)($input['hash'] ?? '')));
    $outcome = strtolower(trim((string)($input['outcome'] ?? '')));
    if (!preg_match('/^[a-f0-9]{40}$/', $hash)) throw new DomainException('Priority identity is invalid.');
    if (!in_array($outcome, ['successful','resolved','unsuccessful','ignored'], true)) {
        throw new DomainException('Outcome is invalid.');
    }

    $priority = null;
    foreach (chat_notifications_v313_brain_priorities($user, $pdo) as $candidate) {
        $candidateHash = (string)($candidate['outcome_hash'] ?? '');
        if ($candidateHash !== '' && hash_equals($candidateHash, $hash)) {
            $priority = $candidate;
            break;
        }
    }
    if (!$priority) throw new DomainException('That priority is no longer active. Refresh Agent Brain and try again.');

    $current = (string)($priority['outcome'] ?? '');
    $final = ['successful','resolved','unsuccessful','ignored'];
    if (in_array($current, $final, true)) {
        if ($current !== $outcome) throw new DomainException('This priority already has a final outcome.');
        $state = chat_notifications_v240_state($user, $pdo);
        $state['outcome_result'] = ['recorded'=>false,'duplicate'=>true,'outcome'=>$current];
        return $state;
    }

    $result = agent_action_v124_record_outcome($user, $hash, $outcome, 'brain', [
        'outcome'=>$outcome,
        'title'=>(string)($priority['title'] ?? ''),
        'source'=>(string)($priority['source'] ?? ''),
        'context'=>[
            'surface'=>'activity_center',
            'priority_key'=>(string)($priority['key'] ?? ''),
            'rank'=>(int)($priority['rank'] ?? 0),
            'score'=>(float)($priority['score'] ?? 0),
        ],
    ]);
    if (empty($result['recorded']) && empty($result['duplicate'])) {
        throw new DomainException('The outcome could not be recorded.');
    }

    $state = chat_notifications_v240_state($user, $pdo);
    $state['outcome_result'] = $result;
    return $state;
}

function chat_notifications_v240_state(array $user, PDO $pdo): array
{
    if (function_exists('agent_chat_activity_reconcile')) {
        agent_chat_activity_reconcile($user);
    }

    $userId = (int)($user['id'] ?? 0);
    $brainAllowed = personal_capability_has_v242('agent_brain.access', $user);
    $brain = $brainAllowed && agent_brain_schema_ready() ? agent_brain_summary($user) : [
        'archive_count'=>0,'memory_count'=>0,'themes'=>[],'dates'=>[],'files'=>[],'recent'=>[]
    ];
    $activity = $brainAllowed ? agent_activity_v94_snapshot($user, 'chat', []) : [];

    return [
        'ok'=>true,
        'agent_voice_enabled'=>member_agent_voice_enabled($user),
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
            'priorities'=>$brainAllowed ? chat_notifications_v313_brain_priorities($user, $pdo) : [],
            'operations'=>$brainAllowed ? chat_notifications_v240_brain_operations($user, 60) : [],
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
    if (!$agent || empty($agent['is_active'])) {
        throw new DomainException('The selected Agent Chat identity is unavailable.');
    }
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

function chat_notifications_v240_contextual_decision(PDO $pdo, int $userId, array $notification): ?array
{
    if (!function_exists('profile_visitor_attention_decision_v243')) return null;
    try {
        $decision = profile_visitor_attention_decision_v243($pdo, $userId, $notification);
        return is_array($decision) ? $decision : null;
    } catch (Throwable $e) {
        error_log('Profile visitor attention enrichment failed: ' . $e->getMessage());
        return null;
    }
}

function chat_notifications_v240_action_label(array $notification): string
{
    $type = strtolower(trim((string)($notification['type'] ?? '')));
    if (preg_match('/approval|review|request|needs_|required/', $type)) return 'Review';
    return 'Open';
}

function chat_notifications_v240_activity_context(array $notification): array
{
    return [
        'kind'=>'user_attention',
        'status'=>'needs_response',
        'source_type'=>(string)($notification['source_type'] ?? ''),
        'source_id'=>max(0, (int)($notification['source_id'] ?? 0)),
        'occurred_at'=>(string)($notification['created_at'] ?? ''),
    ];
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

    $agent = chat_notifications_v240_agent($pdo, $userId, max(0, (int)($input['agent_id'] ?? 0)));
    if ($agent) {
        $existing = $pdo->prepare(
            "SELECT m.id,m.conversation_id,m.message,m.context_json
             FROM chat_messages m
             INNER JOIN chat_conversations c ON c.id=m.conversation_id
             WHERE c.user_id=? AND c.user_agent_id=?
               AND m.role='assistant'
               AND JSON_VALID(m.context_json)=1
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(m.context_json,'$.attention.notification_id')) AS UNSIGNED)=?
             ORDER BY m.id DESC LIMIT 1"
        );
        $existing->execute([$userId, (int)$agent['id'], $notificationId]);
    } else {
        $existing = $pdo->prepare(
            "SELECT m.id,m.conversation_id,m.message,m.context_json
             FROM chat_messages m
             INNER JOIN chat_conversations c ON c.id=m.conversation_id
             WHERE c.user_id=? AND c.user_agent_id IS NULL
               AND m.role='assistant'
               AND JSON_VALID(m.context_json)=1
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(m.context_json,'$.attention.notification_id')) AS UNSIGNED)=?
             ORDER BY m.id DESC LIMIT 1"
        );
        $existing->execute([$userId, $notificationId]);
    }

    if ($row = $existing->fetch()) {
        $ctx = json_decode((string)($row['context_json'] ?? ''), true);
        return [
            'ok'=>true,'handled'=>true,'duplicate'=>true,
            'notification_id'=>$notificationId,
            'conversation_id'=>(int)$row['conversation_id'],
            'message_id'=>(int)$row['id'],
            'message'=>(string)$row['message'],
            'actions'=>is_array($ctx['actions'] ?? null) ? $ctx['actions'] : [],
            'activity'=>is_array($ctx['activity'] ?? null) ? $ctx['activity'] : [],
        ];
    }

    $conversationId = chat_notifications_v240_conversation(
        $pdo,
        $user,
        $agent,
        max(0, (int)($input['conversation_id'] ?? 0))
    );
    $decision = chat_notifications_v240_contextual_decision($pdo, $userId, $notification);
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
        'type'=>'open_url',
        'label'=>chat_notifications_v240_action_label($notification),
        'url'=>$target,
    ]] : [];
    $activity = chat_notifications_v240_activity_context($notification);
    $context = [
        'sources'=>[[
            'source'=>'notification:' . $notificationId,
            'title'=>$contact ? 'Profile visitor attention' : 'User attention notification',
        ]],
        'media'=>[],
        'stem_media'=>[],
        'playlist_title'=>'',
        'actions'=>$actions,
        'activity'=>$activity,
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
    $attentionCreatedAt = trim((string)($notification['created_at'] ?? ''));
    if ($attentionCreatedAt === '' || strtotime($attentionCreatedAt) === false) {
        $attentionCreatedAt = date('Y-m-d H:i:s');
    }

    $insert = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,NULL,'assistant',?,?,?)");
    $insert->execute([
        $conversationId,
        $message,
        is_string($contextJson) ? $contextJson : '{}',
        $attentionCreatedAt,
    ]);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')
        ->execute([$conversationId, $userId]);

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
        'activity'=>$activity,
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
    if ($method !== 'POST') {
        chat_notifications_v240_json(['ok'=>false,'error'=>'POST is required.'], 405);
    }
    chat_notifications_v240_require_csrf($input);

    if ($action === 'present_attention') {
        chat_notifications_v240_json(chat_notifications_v240_present_attention($pdo, $user, $input));
    }
    if ($action === 'brain_outcome') {
        chat_notifications_v240_json(chat_notifications_v313_record_brain_outcome($pdo, $user, $input));
    }
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
} catch (DomainException $e) {
    chat_notifications_v240_json(['ok'=>false,'error'=>$e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Stonefellow notification brain drawer v240 error: ' . $e->getMessage());
    chat_notifications_v240_json(['ok'=>false,'error'=>'Activity Center is temporarily unavailable.'], 500);
}
