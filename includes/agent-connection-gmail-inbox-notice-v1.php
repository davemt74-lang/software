<?php
declare(strict_types=1);

function agent_connection_gmail_inbox_notice_generation_v1(array $policy): ?int
{
    $connection = (int)($policy['connection_id'] ?? 0);
    $grant = (string)($policy['authorization_key'] ?? '');
    $enabledAt = (int)($policy['enabled_at_ms'] ?? 0);
    if ($connection < 1 || $grant === '' || $enabledAt < 1) return null;

    // This opaque 52-bit ID stays exact when sent as a JSON number.
    return 1 + (int)hexdec(substr(hash('sha256', $connection.':'.$grant.':'.$enabledAt), 0, 13));
}

function agent_connection_gmail_inbox_notice_current_v1(PDO $pdo, array $user): ?int
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1) return null;

    try {
        $enabled = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_v1 WHERE owner_user_id=? AND enabled=1');
        $enabled->execute([$owner]);
        if (!$enabled->fetchColumn()) return null;
        require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
        $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner);
        if (empty($policy['enabled'])) return null;
        $connection = agent_connections_find_v1($pdo,$owner,(int)$policy['connection_id']);
        if (!agent_connection_gmail_inbox_authority_v1($user,$connection)
            || !agent_connection_gmail_inbox_bound_policy_v1($policy,$connection)
            || !agent_connection_gmail_ready_v1()
            || !hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) return null;
        return agent_connection_gmail_inbox_notice_generation_v1($policy);
    } catch (Throwable) {
        return null;
    }
}

/** The verified inbox cursor transaction also owns this notification write. */
function agent_connection_gmail_inbox_notify_v1(PDO $pdo, int $owner, array $review, ?array $policy = null): void
{
    $total = (int)($review['total'] ?? 0);
    if ($owner < 1 || $total < 1 || !table_exists('notifications')) return;
    if (!$pdo->inTransaction()) throw new LogicException('Inbox review notices require the cursor transaction.');
    if ($policy === null) {
        require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
        $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner,true);
    }
    $generation = empty($policy['enabled']) ? null : agent_connection_gmail_inbox_notice_generation_v1($policy);
    if ($generation === null) throw new LogicException('Inbox review notice has no active grant.');

    $important = max(0,min($total,(int)($review['important'] ?? 0)));
    $replies = max(0,min($total,(int)($review['replies'] ?? 0)));
    $body = 'Reviewed '.$total.' new Gmail message'.($total === 1 ? '' : 's').'. '
        .$important.' marked important, '.$replies.' '.($replies === 1 ? 'reply' : 'replies').'.';
    $utc = new DateTimeZone('UTC');
    $at = (new DateTimeImmutable($review['at'] ?? 'now',$utc))->setTimezone($utc)->format('Y-m-d H:i:s');
    // Each committed review is a separate bell event. Sharing its generation
    // ID lets the away digest coalesce batches and reject a replaced grant.
    $insert = $pdo->prepare('INSERT INTO notifications
        (user_id,type,title,body,target_url,source_type,source_id,created_at) VALUES (?,?,?,?,?,?,?,?)');
    $insert->execute([$owner,'agent_email_inbox_review','Gmail inbox review',$body,
        url('/connections.php?app=gmail#gmail-inbox-review'),'agent_connection_gmail_inbox',$generation,$at]);
}

function agent_connection_gmail_inbox_notice_purge_v1(PDO $pdo, int $owner): void
{
    if ($owner < 1 || !table_exists('notifications')) return;
    $delete = $pdo->prepare("DELETE FROM notifications WHERE user_id=?
        AND source_type='agent_connection_gmail_inbox' AND type='agent_email_inbox_review'");
    $delete->execute([$owner]);
}
