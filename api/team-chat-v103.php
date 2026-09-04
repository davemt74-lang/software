<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function team_chat_v103_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function team_chat_v103_role_allowed(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && (bool)array_intersect(
        user_roles_for_user($user),
        ['artist', 'manager', 'producer', 'supervisor', 'admin']
    );
}

function team_chat_v103_avatar_url(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return url($path);
}

function team_chat_v103_user_payload(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'name'=>(string)$row['display_name'],
        'role'=>(string)$row['role'],
        'role_label'=>role_label((string)$row['role']),
        'avatar'=>team_chat_v103_avatar_url((string)($row['avatar_path'] ?? '')),
        'page'=>(string)($row['page_key'] ?? ''),
        'context'=>(string)($row['context_label'] ?? ''),
        'unread'=>(int)($row['unread_count'] ?? 0),
        'online'=>isset($row['online']) ? (bool)$row['online'] : true,
    ];
}

function team_chat_v103_target(PDO $pdo, int $userId): ?array
{
    if ($userId < 1) return null;
    $stmt = $pdo->prepare(
        "SELECT u.id,u.display_name,u.role,u.avatar_path,u.is_active
         FROM users u
         WHERE u.id=?
           AND u.is_active=1
           AND (
             u.role IN ('artist','manager','producer','supervisor','admin')
             OR EXISTS (
               SELECT 1
               FROM user_account_types uat
               WHERE uat.user_id=u.id
                 AND uat.role IN ('artist','manager','producer','supervisor','admin')
             )
           )
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function team_chat_v103_touch(PDO $pdo, int $userId, string $page, string $context): void
{
    $page = preg_replace('/[^a-z0-9_-]/i', '', $page) ?: 'workspace';
    $page = substr($page, 0, 60);
    $context = trim($context);
    if (mb_strlen($context) > 190) $context = mb_substr($context, 0, 190);

    $stmt = $pdo->prepare(
        'INSERT INTO team_user_presence
         (user_id,page_key,context_label,last_seen_at)
         VALUES (?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           page_key=VALUES(page_key),
           context_label=VALUES(context_label),
           last_seen_at=NOW()'
    );
    $stmt->execute([$userId,$page,$context]);
}

function team_chat_v103_message_payload(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'sender_id'=>(int)$row['sender_user_id'],
        'recipient_id'=>(int)$row['recipient_user_id'],
        'text'=>(string)$row['message_text'],
        'created_at'=>(string)$row['created_at'],
        'read_at'=>$row['read_at'] !== null ? (string)$row['read_at'] : null,
        'sender'=>[
            'id'=>(int)$row['sender_user_id'],
            'name'=>(string)($row['sender_name'] ?? ''),
            'role'=>(string)($row['sender_role'] ?? ''),
            'role_label'=>role_label((string)($row['sender_role'] ?? '')),
            'avatar'=>team_chat_v103_avatar_url((string)($row['sender_avatar'] ?? '')),
        ],
        'recipient'=>[
            'id'=>(int)$row['recipient_user_id'],
            'name'=>(string)($row['recipient_name'] ?? ''),
            'role'=>(string)($row['recipient_role'] ?? ''),
            'role_label'=>role_label((string)($row['recipient_role'] ?? '')),
            'avatar'=>team_chat_v103_avatar_url((string)($row['recipient_avatar'] ?? '')),
        ],
    ];
}

$user = current_user();
if (!$user) team_chat_v103_json(['ok'=>false,'error'=>'login_required'], 401);
if (!team_chat_v103_role_allowed($user)) team_chat_v103_json(['ok'=>false,'error'=>'forbidden_role'], 403);
if (
    !table_exists('team_user_presence') ||
    !table_exists('team_direct_messages') ||
    !table_exists('user_account_types')
) {
    team_chat_v103_json(['ok'=>false,'error'=>'upgrade_required'], 503);
}

$pdo = db();
if (!$pdo) team_chat_v103_json(['ok'=>false,'error'=>'database_unavailable'], 503);

$currentId = (int)$user['id'];
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'poll');
$page = (string)($_POST['page'] ?? $_GET['page'] ?? 'workspace');
$context = (string)($_POST['context'] ?? $_GET['context'] ?? '');

try {
    team_chat_v103_touch($pdo, $currentId, $page, $context);

    if ($action === 'poll') {
        $since = max(0, (int)($_GET['since'] ?? 0));
        $onlineStmt = $pdo->prepare(
            "SELECT
                u.id,
                u.display_name,
                u.role,
                u.avatar_path,
                p.page_key,
                p.context_label,
                (
                    SELECT COUNT(*)
                    FROM team_direct_messages dm
                    WHERE dm.sender_user_id=u.id
                      AND dm.recipient_user_id=?
                      AND dm.read_at IS NULL
                ) AS unread_count
             FROM team_user_presence p
             INNER JOIN users u ON u.id=p.user_id
             WHERE u.id<>?
               AND u.is_active=1
               AND (
                 u.role IN ('artist','manager','producer','supervisor','admin')
                 OR EXISTS (
                   SELECT 1
                   FROM user_account_types uat
                   WHERE uat.user_id=u.id
                     AND uat.role IN ('artist','manager','producer','supervisor','admin')
                 )
               )
               AND p.last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
             ORDER BY unread_count DESC,u.display_name,u.id
             LIMIT 60"
        );
        $onlineStmt->execute([$currentId,$currentId]);
        $online = array_map('team_chat_v103_user_payload', $onlineStmt->fetchAll());

        if ($since < 1) {
            $cursorStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(id),0)
                 FROM team_direct_messages
                 WHERE sender_user_id=? OR recipient_user_id=?'
            );
            $cursorStmt->execute([$currentId,$currentId]);
            $cursor = (int)$cursorStmt->fetchColumn();
            $messages = [];
        } else {
            $messageStmt = $pdo->prepare(
                "SELECT
                    dm.*,
                    su.display_name AS sender_name,
                    su.role AS sender_role,
                    su.avatar_path AS sender_avatar,
                    ru.display_name AS recipient_name,
                    ru.role AS recipient_role,
                    ru.avatar_path AS recipient_avatar
                 FROM team_direct_messages dm
                 INNER JOIN users su ON su.id=dm.sender_user_id
                 INNER JOIN users ru ON ru.id=dm.recipient_user_id
                 WHERE dm.id>?
                   AND (dm.sender_user_id=? OR dm.recipient_user_id=?)
                 ORDER BY dm.id ASC
                 LIMIT 200"
            );
            $messageStmt->execute([$since,$currentId,$currentId]);
            $rows = $messageStmt->fetchAll();
            $messages = array_map('team_chat_v103_message_payload', $rows);
            $cursor = $since;
            foreach ($rows as $row) $cursor = max($cursor, (int)$row['id']);
        }

        team_chat_v103_json([
            'ok'=>true,
            'cursor'=>$cursor,
            'online'=>$online,
            'messages'=>$messages,
            'poll_ms'=>3000,
        ]);
    }

    if ($action === 'history') {
        $targetId = max(0, (int)($_GET['user_id'] ?? 0));
        $target = team_chat_v103_target($pdo, $targetId);
        if (!$target || $targetId === $currentId) {
            team_chat_v103_json(['ok'=>false,'error'=>'user_not_available'], 404);
        }

        $historyStmt = $pdo->prepare(
            "SELECT * FROM (
                SELECT
                    dm.*,
                    su.display_name AS sender_name,
                    su.role AS sender_role,
                    su.avatar_path AS sender_avatar,
                    ru.display_name AS recipient_name,
                    ru.role AS recipient_role,
                    ru.avatar_path AS recipient_avatar
                 FROM team_direct_messages dm
                 INNER JOIN users su ON su.id=dm.sender_user_id
                 INNER JOIN users ru ON ru.id=dm.recipient_user_id
                 WHERE
                    (dm.sender_user_id=? AND dm.recipient_user_id=?)
                    OR
                    (dm.sender_user_id=? AND dm.recipient_user_id=?)
                 ORDER BY dm.id DESC
                 LIMIT 80
            ) recent
            ORDER BY id ASC"
        );
        $historyStmt->execute([$currentId,$targetId,$targetId,$currentId]);
        $messages = array_map('team_chat_v103_message_payload', $historyStmt->fetchAll());

        $presenceStmt = $pdo->prepare(
            'SELECT page_key,context_label,last_seen_at
             FROM team_user_presence
             WHERE user_id=? LIMIT 1'
        );
        $presenceStmt->execute([$targetId]);
        $presence = $presenceStmt->fetch() ?: [];
        $target['page_key'] = (string)($presence['page_key'] ?? '');
        $target['context_label'] = (string)($presence['context_label'] ?? '');
        $target['online'] = !empty($presence['last_seen_at'])
            && strtotime((string)$presence['last_seen_at']) >= (time() - 15);

        team_chat_v103_json([
            'ok'=>true,
            'user'=>team_chat_v103_user_payload($target),
            'messages'=>$messages,
        ]);
    }

    if ($action === 'send') {
        if (!verify_csrf()) team_chat_v103_json(['ok'=>false,'error'=>'csrf'], 419);
        $targetId = max(0, (int)($_POST['user_id'] ?? 0));
        $message = trim((string)($_POST['message'] ?? ''));
        $target = team_chat_v103_target($pdo, $targetId);
        if (!$target || $targetId === $currentId) {
            team_chat_v103_json(['ok'=>false,'error'=>'user_not_available'], 404);
        }
        if ($message === '') team_chat_v103_json(['ok'=>false,'error'=>'empty_message'], 422);
        if (mb_strlen($message) > 2000) team_chat_v103_json(['ok'=>false,'error'=>'message_too_long'], 422);

        $stmt = $pdo->prepare(
            'INSERT INTO team_direct_messages
             (sender_user_id,recipient_user_id,message_text,created_at)
             VALUES (?,?,?,NOW())'
        );
        $stmt->execute([$currentId,$targetId,$message]);
        $messageId = (int)$pdo->lastInsertId();

        $rowStmt = $pdo->prepare(
            "SELECT
                dm.*,
                su.display_name AS sender_name,
                su.role AS sender_role,
                su.avatar_path AS sender_avatar,
                ru.display_name AS recipient_name,
                ru.role AS recipient_role,
                ru.avatar_path AS recipient_avatar
             FROM team_direct_messages dm
             INNER JOIN users su ON su.id=dm.sender_user_id
             INNER JOIN users ru ON ru.id=dm.recipient_user_id
             WHERE dm.id=? LIMIT 1"
        );
        $rowStmt->execute([$messageId]);
        $row = $rowStmt->fetch();

        team_chat_v103_json([
            'ok'=>true,
            'message'=>team_chat_v103_message_payload($row),
        ]);
    }

    if ($action === 'read') {
        if (!verify_csrf()) team_chat_v103_json(['ok'=>false,'error'=>'csrf'], 419);
        $targetId = max(0, (int)($_POST['user_id'] ?? 0));
        $target = team_chat_v103_target($pdo, $targetId);
        if (!$target || $targetId === $currentId) {
            team_chat_v103_json(['ok'=>false,'error'=>'user_not_available'], 404);
        }

        $stmt = $pdo->prepare(
            'UPDATE team_direct_messages
             SET read_at=COALESCE(read_at,NOW())
             WHERE sender_user_id=?
               AND recipient_user_id=?
               AND read_at IS NULL'
        );
        $stmt->execute([$targetId,$currentId]);
        team_chat_v103_json(['ok'=>true]);
    }

    team_chat_v103_json(['ok'=>false,'error'=>'unknown_action'], 400);
} catch (Throwable $e) {
    error_log('Stonefellow team chat v103 error: ' . $e->getMessage());
    team_chat_v103_json(['ok'=>false,'error'=>'team_chat_failed'], 500);
}
