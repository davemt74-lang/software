<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-chat-v1.php';

const VP3_YOUTUBE_MAX_WATCH_SECONDS_V1 = 14400;

function vp3_youtube_playback_install_v1(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_history_settings_v1 (
        owner_user_id INT UNSIGNED PRIMARY KEY, capture_key CHAR(32) NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES agent_connection_youtube_consent_v1(owner_user_id) ON DELETE CASCADE
    )'.$engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_playback_v1 (
        owner_user_id INT UNSIGNED NOT NULL, play_id CHAR(36) NOT NULL,
        message_id BIGINT UNSIGNED NOT NULL, video_id CHAR(11) NOT NULL, played_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (owner_user_id,play_id),
        FOREIGN KEY (owner_user_id) REFERENCES agent_connection_youtube_consent_v1(owner_user_id) ON DELETE CASCADE,
        FOREIGN KEY (message_id) REFERENCES agent_connection_youtube_results_v1(message_id) ON DELETE CASCADE'
        .($mysql ? ', INDEX idx_yt_playback_recent (owner_user_id,played_at)' : '').')'.$engine);
    if (!$mysql) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_yt_playback_recent ON vp3_youtube_playback_v1(owner_user_id,played_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_watch_v1 (
        owner_user_id INT UNSIGNED NOT NULL, play_id CHAR(36) NOT NULL,
        watch_seconds INTEGER UNSIGNED NOT NULL, last_watched_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (owner_user_id,play_id),
        FOREIGN KEY (owner_user_id,play_id) REFERENCES vp3_youtube_playback_v1(owner_user_id,play_id) ON DELETE CASCADE
    )'.$engine);
}

function vp3_youtube_playback_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT capture_key FROM vp3_youtube_history_settings_v1 WHERE 1=0') !== false
            && $pdo->query('SELECT play_id FROM vp3_youtube_playback_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_youtube_watch_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT watch_seconds,last_watched_at FROM vp3_youtube_watch_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_youtube_capture_key_v1(PDO $pdo, int $owner): string
{
    $stmt = $pdo->prepare('SELECT capture_key FROM vp3_youtube_history_settings_v1 WHERE owner_user_id=?');
    $stmt->execute([$owner]);
    return (string)$stmt->fetchColumn();
}

/** Remove watch-time data without removing starts. Caller owns the preference transaction. */
function vp3_youtube_watch_clear_v1(PDO $pdo, int $owner): void
{
    if (!vp3_youtube_watch_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM vp3_youtube_watch_v1 WHERE owner_user_id=?')->execute([$owner]);
    $pdo->prepare('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=?')
        ->execute([bin2hex(random_bytes(16)), $owner]);
}

function vp3_youtube_history_change_v1(PDO $pdo, int $owner, string $action, string $playId = ''): void
{
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $owner, $action, $playId): void {
        $member = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $member->execute([$owner]);
        if (!$member->fetchColumn()) throw new DomainException('Member unavailable.');
        $key = vp3_youtube_capture_key_v1($pdo, $owner);
        if ($action === 'enable_history' && $key === '') {
            if (!agent_connection_youtube_consent_v1($pdo, $owner)) throw new DomainException('Enable YouTube first.');
            $pdo->prepare('INSERT INTO vp3_youtube_history_settings_v1 (owner_user_id,capture_key) VALUES (?,?)')
                ->execute([$owner, bin2hex(random_bytes(16))]);
        } elseif ($action === 'pause_history') {
            $pdo->prepare('DELETE FROM vp3_youtube_history_settings_v1 WHERE owner_user_id=?')->execute([$owner]);
        } elseif ($action === 'clear_history') {
            $pdo->prepare('DELETE FROM vp3_youtube_playback_v1 WHERE owner_user_id=?')->execute([$owner]);
            // A late event from before clearing must never recreate deleted history.
            if ($key !== '') $pdo->prepare('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=?')
                ->execute([bin2hex(random_bytes(16)), $owner]);
        } elseif ($action === 'remove_history') {
            $pdo->prepare('DELETE FROM vp3_youtube_playback_v1 WHERE owner_user_id=? AND play_id=?')->execute([$owner, $playId]);
            if ($key !== '') $pdo->prepare('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=?')
                ->execute([bin2hex(random_bytes(16)), $owner]);
        }
    });
}

function vp3_youtube_history_video_v1(array|string $payload, string $id): ?array
{
    if (is_string($payload)) $payload = (array)json_decode($payload, true);
    foreach ($payload['videos'] ?? [] as $video) {
        if (($video['video_id'] ?? null) === $id && ($video['made_for_kids'] ?? null) === false) return $video;
    }
    return null;
}

function vp3_youtube_play_identity_valid_v1(int $messageId, string $id, string $playId, string $key): bool
{
    return $messageId > 0 && preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id)
        && preg_match('/\A[a-f0-9-]{36}\z/', $playId) && preg_match('/\A[a-f0-9]{32}\z/', $key);
}

/** Serialize capture with account consent and history controls. Caller owns the transaction. */
function vp3_youtube_play_capture_allowed_v1(PDO $pdo, int $owner, string $key): bool
{
    $member = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
    $member->execute([$owner]);
    return $member->fetchColumn() && agent_connection_youtube_enabled_v1()
        && agent_connection_youtube_consent_v1($pdo, $owner)
        && hash_equals(vp3_youtube_capture_key_v1($pdo, $owner), $key);
}

function vp3_youtube_history_record_v1(PDO $pdo, int $owner, int $messageId, string $id, string $playId, string $key): bool
{
    if (!vp3_youtube_play_identity_valid_v1($messageId, $id, $playId, $key)) return false;
    return agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $owner, $messageId, $id, $playId, $key): bool {
        if (!vp3_youtube_play_capture_allowed_v1($pdo, $owner, $key)) return false;
        $stmt = $pdo->prepare("SELECT r.payload_json FROM agent_connection_youtube_results_v1 r
            JOIN chat_messages m ON m.id=r.message_id JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE r.message_id=? AND c.user_id=? AND m.role='assistant' AND r.expires_at>?");
        $stmt->execute([$messageId, $owner, time()]);
        $payload = $stmt->fetchColumn();
        if (!is_string($payload) || !vp3_youtube_history_video_v1($payload, $id)) return false;
        $stmt = $pdo->prepare('SELECT message_id,video_id FROM vp3_youtube_playback_v1 WHERE owner_user_id=? AND play_id=?');
        $stmt->execute([$owner, $playId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int)$existing['message_id'] === $messageId && $existing['video_id'] === $id;
        $pdo->prepare('INSERT INTO vp3_youtube_playback_v1 (owner_user_id,play_id,message_id,video_id,played_at) VALUES (?,?,?,?,?)')
            ->execute([$owner, $playId, $messageId, $id, time()]);
        return true;
    });
}

/** Persist cumulative elapsed player time without treating repeated reports as extra viewing. */
function vp3_youtube_watch_record_v1(PDO $pdo, int $owner, int $messageId, string $id, string $playId, string $key, int $watchSeconds): bool
{
    if (!vp3_youtube_play_identity_valid_v1($messageId, $id, $playId, $key)
        || $watchSeconds < 1 || $watchSeconds > VP3_YOUTUBE_MAX_WATCH_SECONDS_V1) return false;
    return agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $owner, $messageId, $id, $playId, $key, $watchSeconds): bool {
        if (!vp3_youtube_play_capture_allowed_v1($pdo, $owner, $key)
            || !vp3_youtube_watch_research_enabled_v1($pdo, $owner)) return false;
        $now = time();
        $stmt = $pdo->prepare("SELECT p.played_at,r.payload_json FROM vp3_youtube_playback_v1 p
            JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
            JOIN chat_messages m ON m.id=r.message_id AND m.role='assistant'
            JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=p.owner_user_id
            WHERE p.owner_user_id=? AND p.play_id=? AND p.message_id=? AND p.video_id=? AND r.expires_at>?");
        $stmt->execute([$owner, $playId, $messageId, $id, $now]);
        $play = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$play || !vp3_youtube_history_video_v1($play['payload_json'], $id)
            || $watchSeconds > $now - (int)$play['played_at'] + 2) return false;
        $stmt = $pdo->prepare('SELECT watch_seconds FROM vp3_youtube_watch_v1 WHERE owner_user_id=? AND play_id=?');
        $stmt->execute([$owner, $playId]);
        $previous = $stmt->fetchColumn();
        if ($previous === false) {
            $pdo->prepare('INSERT INTO vp3_youtube_watch_v1 (owner_user_id,play_id,watch_seconds,last_watched_at) VALUES (?,?,?,?)')
                ->execute([$owner, $playId, $watchSeconds, $now]);
        } elseif ($watchSeconds > (int)$previous) {
            $pdo->prepare('UPDATE vp3_youtube_watch_v1 SET watch_seconds=?,last_watched_at=? WHERE owner_user_id=? AND play_id=?')
                ->execute([$watchSeconds, $now, $owner, $playId]);
        }
        return true;
    });
}

function vp3_youtube_history_recent_v1(PDO $pdo, int $owner, ?int $agentId = null, ?int $conversationId = null, int $limit = 12): array
{
    $scope = match (true) {
        $agentId === null => '',
        $agentId > 0 => ' AND c.user_agent_id=?',
        default => ' AND c.user_agent_id IS NULL',
    };
    $params = [$owner, time()];
    if ($agentId > 0) $params[] = $agentId;
    if ($conversationId !== null) {
        $scope .= ' AND c.id=?';
        $params[] = $conversationId;
    }
    $watchReady = vp3_youtube_watch_ready_v1($pdo) && vp3_youtube_watch_research_enabled_v1($pdo, $owner);
    $watchColumns = $watchReady ? 'w.watch_seconds,w.last_watched_at' : 'NULL AS watch_seconds,NULL AS last_watched_at';
    $watchJoin = $watchReady ? 'LEFT JOIN vp3_youtube_watch_v1 w ON w.owner_user_id=p.owner_user_id AND w.play_id=p.play_id' : '';
    $limit = max(1, min(120, $limit));
    $stmt = $pdo->prepare("SELECT p.play_id,p.message_id,p.video_id,p.played_at,r.payload_json,r.expires_at,c.id AS conversation_id,{$watchColumns}
        FROM vp3_youtube_playback_v1 p JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
        JOIN chat_messages m ON m.id=r.message_id AND m.role='assistant'
        JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=p.owner_user_id
        {$watchJoin}
        WHERE p.owner_user_id=? AND r.expires_at>?{$scope}
        ORDER BY p.played_at DESC,p.play_id DESC LIMIT {$limit}");
    $stmt->execute($params);
    $history = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = (array)json_decode($row['payload_json'], true);
        $video = vp3_youtube_history_video_v1($payload, $row['video_id']);
        if ($video) $history[] = $video + ['play_id'=>$row['play_id'], 'message_id'=>(int)$row['message_id'],
            'played_at'=>(int)$row['played_at'], 'expires_at'=>(int)$row['expires_at'],
            'watch_seconds'=>(int)$row['watch_seconds'], 'last_watched_at'=>(int)$row['last_watched_at'],
            'music_suggestion'=>in_array($payload['suggestion_kind']??null,['music','artists','similar'],true),
            'conversation_id'=>(int)$row['conversation_id']];
    }
    return $history;
}
