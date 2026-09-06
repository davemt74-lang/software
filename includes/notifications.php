<?php
declare(strict_types=1);

/**
 * Operational Agent activity uses the existing notifications table as a durable,
 * deduplicated carrier, but it belongs to the Agent Brain tab rather than the
 * user-facing Notifications surface or Agent Chat attention pipeline.
 */
function notification_is_agent_brain_activity(array $notification): bool
{
    $type = strtolower(trim((string)($notification['type'] ?? '')));
    $sourceType = strtolower(trim((string)($notification['source_type'] ?? '')));

    if (str_starts_with($type, 'agent_activity_')) return true;

    return in_array($sourceType, [
        'agent_tool_history',
        'agent_edit_event',
        'agent_memory_item',
        'personal_knowledge_item',
        'transcript_analysis',
        'agent_proactive_event',
    ], true);
}

function notification_agent_brain_sql_predicate(string $alias = ''): string
{
    $prefix = trim($alias);
    if ($prefix !== '' && !str_ends_with($prefix, '.')) $prefix .= '.';

    return "(\n"
        . "  {$prefix}type LIKE 'agent_activity_%'\n"
        . "  OR {$prefix}source_type IN ('agent_tool_history','agent_edit_event','agent_memory_item','personal_knowledge_item','transcript_analysis','agent_proactive_event')\n"
        . ')';
}

function notification_system_sql_predicate(string $alias = ''): string
{
    return 'NOT ' . notification_agent_brain_sql_predicate($alias);
}

function notification_unread_count(?array $user = null): int
{
    $user ??= current_user();
    $pdo = db();

    if (!$user || !$pdo || !table_exists('notifications')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND ' . notification_system_sql_predicate()
        );
        $stmt->execute([(int)$user['id']]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function notification_recent(?array $user = null, int $limit = 8): array
{
    $user ??= current_user();
    $pdo = db();

    if (!$user || !$pdo || !table_exists('notifications')) {
        return [];
    }

    if (function_exists('agent_chat_activity_reconcile')) {
        agent_chat_activity_reconcile($user);
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM notifications
             WHERE user_id=? AND ' . notification_system_sql_predicate() . '
             ORDER BY created_at DESC,id DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Return the durable operational feed for the existing Activity Center Agent
 * Brain tab. Authoritative subsystem ledgers are reconciled first; notification
 * rows are only the existing deduplicated delivery/index carrier.
 */
function notification_agent_brain_activity_after(?array $user, int $afterId = 0, int $limit = 50): array
{
    $user ??= current_user();
    $pdo = db();
    if (!$user || !$pdo || !table_exists('notifications')) return [];

    if (function_exists('agent_chat_activity_reconcile')) {
        agent_chat_activity_reconcile($user);
    }

    $limit = max(1, min(100, $limit));
    try {
        if ($afterId > 0) {
            $stmt = $pdo->prepare(
                "SELECT n.* FROM notifications n
                 WHERE n.user_id=? AND n.id>? AND " . notification_agent_brain_sql_predicate('n') . "
                 ORDER BY n.id ASC LIMIT {$limit}"
            );
            $stmt->execute([(int)$user['id'], $afterId]);
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $pdo->prepare(
            "SELECT n.* FROM notifications n
             WHERE n.user_id=?
               AND " . notification_agent_brain_sql_predicate('n') . "
               AND n.created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
             ORDER BY n.created_at DESC,n.id DESC LIMIT {$limit}"
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Deterministic attention gate for Agent Chat. Agent Brain activity is excluded
 * before any explicit or heuristic attention checks; only user-facing events
 * that genuinely need a response may enter the conversation canvas.
 */
function notification_requires_attention(array $notification): bool
{
    if (notification_is_agent_brain_activity($notification)) return false;

    if (array_key_exists('requires_attention', $notification)) {
        $explicit = (int)($notification['requires_attention'] ?? 0);
        if ($explicit === 1) return true;
    }

    $type = strtolower(trim((string)($notification['type'] ?? '')));
    $sourceType = strtolower(trim((string)($notification['source_type'] ?? '')));
    $title = strtolower(trim((string)($notification['title'] ?? '')));
    $body = strtolower(trim((string)($notification['body'] ?? '')));
    $text = trim($title . ' ' . $body);

    // Profile Agent events are owner-facing customer-service activity. Source
    // ownership is more reliable than matching a display type, and it also
    // keeps compatibility with the historical profile_profile_view type.
    if ($sourceType === 'profile_event' && str_starts_with($type, 'profile_')) return true;

    $profileAgentAttention = [
        'profile_view',
        'profile_profile_view',
        'profile_conversation_started',
    ];
    if (in_array($type, $profileAgentAttention, true)) return true;

    $informational = [
        'conversation_started',
        'new_track_release',
        'new_album_release',
        'artist_post',
        'show_reminder',
        'team_chat_message',
        'direct_message',
    ];
    if (in_array($type, $informational, true)) return false;

    $typePatterns = [
        'needs_owner', 'needs_input', 'needs_attention', 'action_required',
        'requires_action', 'approval_required', 'approval_request', 'review_request',
        'access_request', 'connection_request', 'collaboration_request',
        'collaborator_request', 'invite_request', 'invitation', 'assignment',
        'release_action', 'security_action', 'response_required',
    ];
    foreach ($typePatterns as $pattern) {
        if ($type === $pattern || str_contains($type, $pattern)) return true;
    }

    return (bool)preg_match(
        '/\b(?:needs? your (?:input|approval|attention|response)|requires? your (?:input|approval|attention|response)|action required|response required|approval (?:needed|required)|please (?:review|approve|respond)|awaiting your response|requested (?:access|approval)|invited you|wants to connect|needs? a decision|choose (?:an option|what to do))\b/u',
        $text
    );
}

function notification_attention_prompt(array $notification): string
{
    $prompt = trim((string)($notification['attention_prompt'] ?? ''));
    if ($prompt !== '') return mb_strimwidth($prompt, 0, 240, '…');
    return 'What do you want to do?';
}

function notification_attention_message(array $notification): string
{
    $parts = [];
    $title = trim((string)($notification['title'] ?? ''));
    $body = trim((string)($notification['body'] ?? ''));
    if ($title !== '') $parts[] = $title;
    if ($body !== '') $parts[] = $body;
    $parts[] = notification_attention_prompt($notification);
    return implode("\n\n", $parts);
}

function notification_attention_after(?array $user, int $afterId = 0, int $limit = 20): array
{
    $user ??= current_user();
    $pdo = db();
    if (!$user || !$pdo || !table_exists('notifications')) return [];

    if (function_exists('agent_chat_activity_reconcile')) {
        agent_chat_activity_reconcile($user);
    }

    $limit = max(1, min(50, $limit));
    try {
        if ($afterId > 0) {
            // Filter Brain rows in SQL, not after LIMIT, so high-volume agent
            // activity can never starve a real user-attention notification.
            $stmt = $pdo->prepare(
                "SELECT n.* FROM notifications n
                 WHERE n.user_id=? AND n.id>? AND " . notification_system_sql_predicate('n') . "
                 ORDER BY n.id ASC LIMIT {$limit}"
            );
            $stmt->execute([(int)$user['id'], $afterId]);
        } else {
            // Bootstrap only recent, unsurfaced user-facing attention. Agent
            // Brain has its own durable 14-day feed and never replays into Chat.
            if (table_exists('chat_messages') && table_exists('chat_conversations')) {
                $stmt = $pdo->prepare(
                    "SELECT n.*
                     FROM notifications n
                     WHERE n.user_id=?
                       AND " . notification_system_sql_predicate('n') . "
                       AND n.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)
                       AND NOT EXISTS (
                         SELECT 1
                         FROM chat_messages m
                         INNER JOIN chat_conversations c ON c.id=m.conversation_id
                         WHERE c.user_id=n.user_id
                           AND m.role='assistant'
                           AND JSON_VALID(m.context_json)=1
                           AND CAST(JSON_UNQUOTE(JSON_EXTRACT(m.context_json,'$.attention.notification_id')) AS UNSIGNED)=n.id
                       )
                     ORDER BY n.created_at DESC,n.id DESC
                     LIMIT {$limit}"
                );
                $stmt->execute([(int)$user['id']]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT n.* FROM notifications n
                     WHERE n.user_id=? AND n.is_read=0
                       AND " . notification_system_sql_predicate('n') . "
                       AND n.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)
                     ORDER BY n.created_at DESC,n.id DESC LIMIT {$limit}"
                );
                $stmt->execute([(int)$user['id']]);
            }
        }
        $rows = $stmt->fetchAll() ?: [];
        if ($afterId < 1) $rows = array_reverse($rows);
        return array_values(array_filter($rows, 'notification_requires_attention'));
    } catch (Throwable $e) {
        return [];
    }
}

function notification_latest_id(?array $user = null): int
{
    $user ??= current_user();
    $pdo = db();
    if (!$user || !$pdo || !table_exists('notifications')) return 0;
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id=?');
        $stmt->execute([(int)$user['id']]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function create_notification(
    int $userId,
    string $type,
    string $title,
    string $body = '',
    string $targetUrl = '',
    string $sourceType = '',
    ?int $sourceId = null,
    ?string $createdAt = null
): void {
    $pdo = db();

    if (!$pdo || $userId < 1 || !table_exists('notifications')) {
        return;
    }

    // profile_attention_from_event historically prefixes profile_ onto the
    // already-prefixed profile_view event. Normalize new writes while the read
    // path above remains backward-compatible with existing rows.
    if ($type === 'profile_profile_view') $type = 'profile_view';

    try {
        if ($sourceType !== '' && $sourceId !== null) {
            $check = $pdo->prepare(
                'SELECT id FROM notifications
                 WHERE user_id=? AND source_type=? AND source_id=? AND type=?
                 LIMIT 1'
            );
            $check->execute([$userId, $sourceType, $sourceId, $type]);
            if ($check->fetch()) {
                return;
            }
        }

        $created = null;
        if ($createdAt !== null && trim($createdAt) !== '') {
            $timestamp = strtotime($createdAt);
            if ($timestamp !== false) $created = date('Y-m-d H:i:s', $timestamp);
        }

        if ($created !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications
                 (user_id,type,title,body,target_url,source_type,source_id,created_at)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId,
                mb_substr($type, 0, 50),
                mb_substr($title, 0, 190),
                mb_substr($body, 0, 500),
                mb_substr($targetUrl, 0, 500),
                mb_substr($sourceType, 0, 80),
                $sourceId,
                $created,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications
                 (user_id,type,title,body,target_url,source_type,source_id)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId,
                mb_substr($type, 0, 50),
                mb_substr($title, 0, 190),
                mb_substr($body, 0, 500),
                mb_substr($targetUrl, 0, 500),
                mb_substr($sourceType, 0, 80),
                $sourceId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Notification create failed: ' . $e->getMessage());
    }
}

function create_notification_for_permission(
    string $permission,
    string $type,
    string $title,
    string $body = '',
    string $targetUrl = '',
    string $sourceType = '',
    ?int $sourceId = null
): void {
    $pdo = db();

    if (!$pdo || !table_exists('notifications')) {
        return;
    }

    try {
        $users = $pdo->query(
            'SELECT id,role FROM users WHERE is_active=1 ORDER BY id'
        )->fetchAll();

        foreach ($users as $user) {
            $user['roles'] = user_account_types_for_user_id(
                (int)$user['id'],
                (string)$user['role']
            );

            if (has_permission($permission, $user)) {
                create_notification(
                    (int)$user['id'],
                    $type,
                    $title,
                    $body,
                    $targetUrl,
                    $sourceType,
                    $sourceId
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Notification permission fanout failed: ' . $e->getMessage());
    }
}

function mark_notification_read(int $notificationId, int $userId): void
{
    $pdo = db();
    if (!$pdo || $notificationId < 1 || $userId < 1) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE notifications
         SET is_read=1,read_at=COALESCE(read_at,NOW())
         WHERE id=? AND user_id=? AND ' . notification_system_sql_predicate()
    );
    $stmt->execute([$notificationId, $userId]);
}

function mark_all_notifications_read(int $userId): void
{
    $pdo = db();
    if (!$pdo || $userId < 1) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE notifications
         SET is_read=1,read_at=COALESCE(read_at,NOW())
         WHERE user_id=? AND is_read=0 AND ' . notification_system_sql_predicate()
    );
    $stmt->execute([$userId]);
}
