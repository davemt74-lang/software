<?php
declare(strict_types=1);

function notification_unread_count(?array $user = null): int
{
    $user ??= current_user();
    $pdo = db();

    if (!$user || !$pdo || !table_exists('notifications')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'
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

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM notifications
             WHERE user_id=?
             ORDER BY created_at DESC
             LIMIT ' . max(1, min(25, $limit))
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function create_notification(
    int $userId,
    string $type,
    string $title,
    string $body = '',
    string $targetUrl = '',
    string $sourceType = '',
    ?int $sourceId = null
): void {
    $pdo = db();

    if (!$pdo || $userId < 1 || !table_exists('notifications')) {
        return;
    }

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
         WHERE id=? AND user_id=?'
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
         WHERE user_id=? AND is_read=0'
    );
    $stmt->execute([$userId]);
}
