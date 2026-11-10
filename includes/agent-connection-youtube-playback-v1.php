<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-chat-v1.php';

const VP3_YOUTUBE_MAX_WATCH_SECONDS_V1 = 14400;
const VP3_YOUTUBE_MAX_POSITION_MS_V1 = 604800000;
const VP3_YOUTUBE_PLAYLIST_LIMIT_V1 = 25;
const VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1 = 7*86400;

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
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_playhead_v1 (
        owner_user_id INT UNSIGNED NOT NULL, play_id CHAR(36) NOT NULL,
        position_ms INT UNSIGNED NOT NULL, position_seq INT UNSIGNED NOT NULL,
        position_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (owner_user_id,play_id),
        FOREIGN KEY (owner_user_id,play_id) REFERENCES vp3_youtube_playback_v1(owner_user_id,play_id) ON DELETE CASCADE
    )'.$engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_saved_playlists_v1 (
        owner_user_id INT UNSIGNED NOT NULL, playlist_id CHAR(32) NOT NULL, source_message_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL, ordered_ids_json TEXT NOT NULL, videos_json TEXT NOT NULL,
        saved_at BIGINT UNSIGNED NOT NULL, cached_until BIGINT UNSIGNED NOT NULL,
        refresh_at BIGINT UNSIGNED NOT NULL, references_until BIGINT UNSIGNED NOT NULL, retry_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (owner_user_id,playlist_id), UNIQUE (owner_user_id,source_message_id),
        FOREIGN KEY (owner_user_id) REFERENCES agent_connection_youtube_consent_v1(owner_user_id) ON DELETE CASCADE
    )'.$engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_youtube_playlist_plays_v1 (
        owner_user_id INT UNSIGNED NOT NULL, play_id CHAR(36) NOT NULL, playlist_id CHAR(32) NOT NULL,
        video_id CHAR(11) NOT NULL, played_at BIGINT UNSIGNED NOT NULL,
        position_ms INT UNSIGNED NULL, position_seq INT UNSIGNED NOT NULL DEFAULT 0,
        position_at BIGINT UNSIGNED NOT NULL DEFAULT 0, watch_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        last_watched_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (owner_user_id,play_id),
        FOREIGN KEY (owner_user_id,playlist_id) REFERENCES vp3_youtube_saved_playlists_v1(owner_user_id,playlist_id) ON DELETE CASCADE'
        .($mysql ? ', INDEX idx_yt_playlist_plays_recent (owner_user_id,played_at)' : '').')'.$engine);
    if (!$mysql) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_yt_playlist_plays_recent ON vp3_youtube_playlist_plays_v1(owner_user_id,played_at)');
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

function vp3_youtube_playhead_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT position_ms,position_seq,position_at FROM vp3_youtube_playhead_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_youtube_playlists_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT ordered_ids_json,videos_json,refresh_at FROM vp3_youtube_saved_playlists_v1 WHERE 1=0') !== false
            && $pdo->query('SELECT position_ms,watch_seconds,last_watched_at FROM vp3_youtube_playlist_plays_v1 WHERE 1=0') !== false;
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
    if (vp3_youtube_playlists_ready_v1($pdo)) {
        $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET watch_seconds=0,last_watched_at=0 WHERE owner_user_id=?')
            ->execute([$owner]);
    }
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
            if (vp3_youtube_playlists_ready_v1($pdo)) {
                $pdo->prepare('DELETE FROM vp3_youtube_playlist_plays_v1 WHERE owner_user_id=?')->execute([$owner]);
            }
            // A late event from before clearing must never recreate deleted history.
            if ($key !== '') $pdo->prepare('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=?')
                ->execute([bin2hex(random_bytes(16)), $owner]);
        } elseif ($action === 'remove_history') {
            $pdo->prepare('DELETE FROM vp3_youtube_playback_v1 WHERE owner_user_id=? AND play_id=?')->execute([$owner, $playId]);
            if (vp3_youtube_playlists_ready_v1($pdo)) {
                $pdo->prepare('DELETE FROM vp3_youtube_playlist_plays_v1 WHERE owner_user_id=? AND play_id=?')->execute([$owner, $playId]);
            }
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

/** The cached provider payload is usable only inside VP3's existing seven-day freshness window. */
function vp3_youtube_playlist_row_v1(PDO $pdo, int $owner, string $playlist): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $playlist) || !agent_connection_youtube_enabled_v1()
        || !agent_connection_youtube_consent_v1($pdo, $owner)) return null;
    $query = $pdo->prepare('SELECT * FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=? AND playlist_id=?');
    $query->execute([$owner,$playlist]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_youtube_playlist_save_v1(PDO $pdo, int $owner, int $messageId): ?string
{
    if ($owner < 1 || $messageId < 1) return null;
    return agent_commerce_atomic_pa2($pdo, static function () use ($pdo,$owner,$messageId): ?string {
        $member = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $member->execute([$owner]);
        if (!$member->fetchColumn() || !agent_connection_youtube_enabled_v1()
            || !agent_connection_youtube_consent_v1($pdo,$owner)) return null;
        $query = $pdo->prepare('SELECT playlist_id FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=? AND source_message_id=?');
        $query->execute([$owner,$messageId]);
        if ($saved = $query->fetchColumn()) return (string)$saved;
        $now = time();
        $query = $pdo->prepare("SELECT r.payload_json,r.expires_at FROM agent_connection_youtube_results_v1 r
            JOIN chat_messages m ON m.id=r.message_id AND m.role='assistant'
            JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE m.id=? AND c.user_id=? AND r.expires_at>?");
        $query->execute([$messageId,$owner,$now]);
        $source = $query->fetch(PDO::FETCH_ASSOC);
        $payload = $source ? json_decode($source['payload_json'],true) : null;
        if (($payload['suggestion_kind'] ?? '') !== 'playlist') return null;
        $videos = [];
        foreach (array_slice((array)($payload['videos'] ?? []),0,VP3_YOUTUBE_MAX_RESULTS_V1) as $video) {
            $id = $video['video_id'] ?? '';
            if (is_string($id) && preg_match('/\A[A-Za-z0-9_-]{11}\z/',$id)) $videos[$id] = $video;
        }
        if (!$videos) return null;
        $query = $pdo->prepare('SELECT COUNT(*) FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=?');
        $query->execute([$owner]);
        if ((int)$query->fetchColumn() >= VP3_YOUTUBE_PLAYLIST_LIMIT_V1) return null;
        $playlist = bin2hex(random_bytes(16));
        $title = agent_connection_youtube_text_v1($payload['owner_topic'] ?? $payload['taste_topic'] ?? $payload['query'] ?? '',200)
            ?: 'Saved YouTube playlist';
        // Age the references from the original search, not from the date they were saved.
        $cachedUntil = (int)$source['expires_at'];
        $pdo->prepare('INSERT INTO vp3_youtube_saved_playlists_v1
            (owner_user_id,playlist_id,source_message_id,title,ordered_ids_json,videos_json,saved_at,cached_until,refresh_at,references_until)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$owner,$playlist,$messageId,$title,json_encode(array_keys($videos),JSON_THROW_ON_ERROR),
                json_encode(array_values($videos),JSON_THROW_ON_ERROR),$now,$cachedUntil,$cachedUntil+14*86400,$cachedUntil+23*86400]);
        return $playlist;
    });
}

function vp3_youtube_playlists_list_v1(PDO $pdo, int $owner): array
{
    $query = $pdo->prepare('SELECT playlist_id,title,saved_at,references_until FROM vp3_youtube_saved_playlists_v1
        WHERE owner_user_id=? ORDER BY saved_at DESC,playlist_id DESC LIMIT '.VP3_YOUTUBE_PLAYLIST_LIMIT_V1);
    $query->execute([$owner]);
    return array_map(static fn(array $row): array => ['playlist_id'=>$row['playlist_id'],'title'=>$row['title'],
        'saved_at'=>(int)$row['saved_at'],'available'=>(int)$row['references_until']>time()],$query->fetchAll(PDO::FETCH_ASSOC));
}

/** Revalidate the selected IDs directly and preserve the owner's ordering. */
function vp3_youtube_playlist_provider_v1(array $ids, ?callable $http): array
{
    $ids = array_values(array_unique(array_filter($ids,static fn($id): bool => is_string($id)
        && preg_match('/\A[A-Za-z0-9_-]{11}\z/',$id))));
    if (!$ids) return [];
    $items = agent_connection_youtube_request_v1(VP3_YOUTUBE_STATUS_ENDPOINT_V1,[
        'part'=>'snippet,status','id'=>implode(',',$ids),
        'fields'=>'kind,items(id,snippet(title,channelTitle,thumbnails(medium(url),default(url)),status(embeddable,madeForKids,privacyStatus))',
    ],'youtube#videoListResponse',$http);
    $videos = [];
    foreach ($items as $item) {
        $id = $item['id'] ?? '';
        if (!in_array($id,$ids,true) || ($item['status']['privacyStatus'] ?? '') !== 'public') continue;
        $title = agent_connection_youtube_text_v1($item['snippet']['title'] ?? '',200);
        if ($title === '') continue;
        $video = ['video_id'=>$id,'title'=>$title,
            'channel_title'=>agent_connection_youtube_text_v1($item['snippet']['channelTitle'] ?? '',120),'thumbnail_url'=>''];
        foreach (['medium','default'] as $size) {
            $url = $item['snippet']['thumbnails'][$size]['url'] ?? null;
            if (is_string($url) && preg_match('~\Ahttps://(?:i\.ytimg\.com|img\.youtube\.com)/(?:vi|vi_webp)/'
                .$id.'/[A-Za-z0-9_]{1,32}\.(?:jpg|webp)\z~',$url)) {
                $video['thumbnail_url'] = $url;
                break;
            }
        }
        if (($item['status']['embeddable'] ?? null) === true && is_bool($item['status']['madeForKids'] ?? null)) {
            $video['made_for_kids'] = $item['status']['madeForKids'];
        }
        $videos[$id] = $video;
    }
    $ordered = [];
    foreach ($ids as $id) if (isset($videos[$id])) $ordered[] = $videos[$id];
    return $ordered;
}

function vp3_youtube_playlist_open_v1(PDO $pdo, int $owner, string $playlist, ?callable $http=null, bool $refresh=false): ?array
{
    $row = vp3_youtube_playlist_row_v1($pdo,$owner,$playlist);
    if (!$row) return null;
    $now = time();
    $view = static fn(array $videos,int $until): array => ['playlist_id'=>$playlist,'query'=>$row['title'],
        'suggestion_kind'=>'playlist','expires_at'=>$until,'videos'=>$videos];
    if ((int)$row['references_until'] <= $now) return $view([],0);
    if (!$refresh && (int)$row['cached_until'] > $now) return $view((array)json_decode($row['videos_json'],true),(int)$row['cached_until']);

    $lease = $now+60;
    $claim = $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET retry_after=?
        WHERE owner_user_id=? AND playlist_id=? AND references_until>? AND retry_after<=?');
    $claim->execute([$lease,$owner,$playlist,$now,$now]);
    if ($claim->rowCount() !== 1) throw new AgentConnectionYoutubeExceptionV1('Playlist refresh is in progress. Try again shortly.',409);
    $videos = vp3_youtube_playlist_provider_v1(array_slice((array)json_decode($row['ordered_ids_json'],true),0,VP3_YOUTUBE_MAX_RESULTS_V1),$http);
    if (!vp3_youtube_playlist_row_v1($pdo,$owner,$playlist)) return null;
    $now = time();
    $until = $now+VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1;
    $update = $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET ordered_ids_json=?,videos_json=?,cached_until=?,
        refresh_at=?,references_until=?,retry_after=0 WHERE owner_user_id=? AND playlist_id=? AND retry_after=?');
    $update->execute([json_encode(array_column($videos,'video_id'),JSON_THROW_ON_ERROR),json_encode($videos,JSON_THROW_ON_ERROR),
        $until,$now+21*86400,$now+30*86400,$owner,$playlist,$lease]);
    return $update->rowCount() === 1 ? $view($videos,$until) : null;
}

/** Refresh inactive selections on the Cloud worker and remove stale provider fields on schedule. */
function vp3_youtube_playlists_maintain_v1(PDO $pdo, ?callable $http=null): int
{
    if (!vp3_youtube_playlists_ready_v1($pdo)) return 0;
    $now = time();
    $pdo->prepare("UPDATE vp3_youtube_saved_playlists_v1 SET videos_json='[]' WHERE cached_until<=? AND videos_json<>'[]'")->execute([$now]);
    $pdo->prepare("UPDATE vp3_youtube_saved_playlists_v1 SET ordered_ids_json='[]'
        WHERE references_until<=? AND ordered_ids_json<>'[]'")->execute([$now]);
    $pdo->prepare('DELETE FROM vp3_youtube_playlist_plays_v1 WHERE played_at<=?')
        ->execute([$now-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1]);
    $due = $pdo->prepare("SELECT owner_user_id,playlist_id FROM vp3_youtube_saved_playlists_v1
        WHERE references_until>? AND refresh_at<=? AND retry_after<=? AND ordered_ids_json<>'[]' ORDER BY references_until LIMIT 2");
    $due->execute([$now,$now,$now]);
    $refreshed = 0;
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            if (vp3_youtube_playlist_open_v1($pdo,(int)$row['owner_user_id'],$row['playlist_id'],$http,true)) ++$refreshed;
        } catch (AgentConnectionYoutubeExceptionV1) { /* Bounded retries precede expiry. */ }
    }
    return $refreshed;
}

function vp3_youtube_playlist_remove_v1(PDO $pdo, int $owner, string $playlist): void
{
    $pdo->prepare('DELETE FROM vp3_youtube_saved_playlists_v1 WHERE owner_user_id=? AND playlist_id=?')->execute([$owner,$playlist]);
}

function vp3_youtube_position_record_v1(PDO $pdo, int $owner, int $messageId, string $id, string $playId, string $key, int $position, int $sequence): bool
{
    if (!vp3_youtube_play_identity_valid_v1($messageId,$id,$playId,$key) || $position < 0
        || $position > VP3_YOUTUBE_MAX_POSITION_MS_V1 || $sequence < 1 || $sequence > 1000000) return false;
    return agent_commerce_atomic_pa2($pdo, static function () use ($pdo,$owner,$messageId,$id,$playId,$key,$position,$sequence): bool {
        if (!vp3_youtube_play_capture_allowed_v1($pdo,$owner,$key)) return false;
        $query = $pdo->prepare('SELECT 1 FROM vp3_youtube_playback_v1 p
            JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
            WHERE p.owner_user_id=? AND p.play_id=? AND p.message_id=? AND p.video_id=? AND r.expires_at>?');
        $query->execute([$owner,$playId,$messageId,$id,time()]);
        if (!$query->fetchColumn()) return false;
        $query = $pdo->prepare('SELECT position_seq FROM vp3_youtube_playhead_v1 WHERE owner_user_id=? AND play_id=?');
        $query->execute([$owner,$playId]);
        $prior = $query->fetchColumn();
        $at = (int)(microtime(true)*1000);
        if ($prior === false) {
            $pdo->prepare('INSERT INTO vp3_youtube_playhead_v1 (owner_user_id,play_id,position_ms,position_seq,position_at)
                VALUES (?,?,?,?,?)')->execute([$owner,$playId,$position,$sequence,$at]);
        } elseif ($sequence > (int)$prior) {
            $pdo->prepare('UPDATE vp3_youtube_playhead_v1 SET position_ms=?,position_seq=?,position_at=?
                WHERE owner_user_id=? AND play_id=?')->execute([$position,$sequence,$at,$owner,$playId]);
        }
        return true;
    });
}

/** Saved playlist starts use the same owner capture settings and expiry as Chat results. */
function vp3_youtube_playlist_play_v1(PDO $pdo, int $owner, string $playlist, string $id, string $playId, string $key,
    string $action, int $value=0, int $sequence=0): bool
{
    if (!vp3_youtube_play_identity_valid_v1(1,$id,$playId,$key) || !in_array($action,['record','watch','position_save'],true)) return false;
    if ($action === 'watch' && ($value < 1 || $value > VP3_YOUTUBE_MAX_WATCH_SECONDS_V1)) return false;
    if ($action === 'position_save' && ($value < 0 || $value > VP3_YOUTUBE_MAX_POSITION_MS_V1 || $sequence < 1 || $sequence > 1000000)) return false;
    return agent_commerce_atomic_pa2($pdo, static function () use ($pdo,$owner,$playlist,$id,$playId,$key,$action,$value,$sequence): bool {
        if (!vp3_youtube_play_capture_allowed_v1($pdo,$owner,$key)) return false;
        $row = vp3_youtube_playlist_row_v1($pdo,$owner,$playlist);
        $now = time();
        if (!$row || (int)$row['cached_until'] <= $now
            || !vp3_youtube_history_video_v1(['videos'=>(array)json_decode($row['videos_json'],true)],$id)) return false;
        $query = $pdo->prepare('SELECT playlist_id,video_id,played_at FROM vp3_youtube_playlist_plays_v1 WHERE owner_user_id=? AND play_id=?');
        $query->execute([$owner,$playId]);
        $prior = $query->fetch(PDO::FETCH_ASSOC);
        if ($action === 'record' && !$prior) {
            $pdo->prepare('INSERT INTO vp3_youtube_playlist_plays_v1 (owner_user_id,play_id,playlist_id,video_id,played_at)
                VALUES (?,?,?,?,?)')->execute([$owner,$playId,$playlist,$id,$now]);
            return true;
        }
        if (!$prior || $prior['playlist_id'] !== $playlist || $prior['video_id'] !== $id
            || (int)$prior['played_at'] <= $now-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1) return false;
        if ($action === 'watch') {
            if (!vp3_youtube_watch_research_enabled_v1($pdo,$owner) || $value > $now-(int)$prior['played_at']+2) return false;
            $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET watch_seconds=?,last_watched_at=?
                WHERE owner_user_id=? AND play_id=? AND watch_seconds<?')->execute([$value,$now,$owner,$playId,$value]);
        } elseif ($action === 'position_save') {
            $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET position_ms=?,position_seq=?,position_at=?
                WHERE owner_user_id=? AND play_id=? AND position_seq<?')->execute([$value,$sequence,(int)(microtime(true)*1000),$owner,$playId,$sequence]);
        }
        return true;
    });
}

function vp3_youtube_position_read_v1(PDO $pdo, int $owner, string $id, string $key): ?int
{
    if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/',$id) || !preg_match('/\A[a-f0-9]{32}\z/',$key)
        || !agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo,$owner)
        || !hash_equals(vp3_youtube_capture_key_v1($pdo,$owner),$key)) return null;
    $candidates = [];
    if (vp3_youtube_playhead_ready_v1($pdo)) {
        $query = $pdo->prepare('SELECT h.position_ms,h.position_at,p.played_at FROM vp3_youtube_playhead_v1 h
            JOIN vp3_youtube_playback_v1 p ON p.owner_user_id=h.owner_user_id AND p.play_id=h.play_id
            JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
            WHERE p.owner_user_id=? AND p.video_id=? AND r.expires_at>? ORDER BY p.played_at DESC,h.position_at DESC LIMIT 1');
        $query->execute([$owner,$id,time()]);
        if ($row = $query->fetch(PDO::FETCH_ASSOC)) $candidates[] = $row;
    }
    if (vp3_youtube_playlists_ready_v1($pdo)) {
        $query = $pdo->prepare('SELECT p.position_ms,p.position_at,p.played_at FROM vp3_youtube_playlist_plays_v1 p
            JOIN vp3_youtube_saved_playlists_v1 l ON l.owner_user_id=p.owner_user_id AND l.playlist_id=p.playlist_id
            WHERE p.owner_user_id=? AND p.video_id=? AND p.position_ms IS NOT NULL AND l.references_until>? AND p.played_at>?
            ORDER BY p.played_at DESC,p.position_at DESC LIMIT 1');
        $query->execute([$owner,$id,time(),time()-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1]);
        if ($row = $query->fetch(PDO::FETCH_ASSOC)) $candidates[] = $row;
    }
    usort($candidates,static fn(array $a,array $b): int => $b['played_at']<=>$a['played_at'] ?: $b['position_at']<=>$a['position_at']);
    return isset($candidates[0]) && agent_connection_youtube_enabled_v1() && agent_connection_youtube_consent_v1($pdo,$owner)
        && hash_equals(vp3_youtube_capture_key_v1($pdo,$owner),$key)
        ? (int)$candidates[0]['position_ms'] : null;
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
    $positionReady = vp3_youtube_playhead_ready_v1($pdo) && vp3_youtube_capture_key_v1($pdo,$owner) !== '';
    $positionColumn = $positionReady ? 'h.position_ms' : 'NULL';
    $positionJoin = $positionReady ? 'LEFT JOIN vp3_youtube_playhead_v1 h ON h.owner_user_id=p.owner_user_id AND h.play_id=p.play_id' : '';
    $limit = max(1, min(120, $limit));
    $stmt = $pdo->prepare("SELECT p.play_id,p.message_id,p.video_id,p.played_at,r.payload_json,r.expires_at,c.id AS conversation_id,
        {$watchColumns},{$positionColumn} AS position_ms
        FROM vp3_youtube_playback_v1 p JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
        JOIN chat_messages m ON m.id=r.message_id AND m.role='assistant'
        JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=p.owner_user_id
        {$watchJoin} {$positionJoin}
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
            'music_suggestion'=>is_bool($payload['music_suggestion'] ?? null)
                ? $payload['music_suggestion'] : in_array($payload['suggestion_kind']??null,['music','artists','similar'],true),
            'conversation_id'=>(int)$row['conversation_id']]
            + ($row['position_ms'] === null ? [] : ['resume_ms'=>(int)$row['position_ms']]);
    }
    if (vp3_youtube_playlists_ready_v1($pdo)) {
        // Use the original conversation only when it still belongs to the same owner.
        $playlistScope = ($agentId === null && $conversationId === null) ? '' : ' AND c.id IS NOT NULL'.$scope;
        $query = $pdo->prepare("SELECT p.play_id,p.playlist_id,p.video_id,p.played_at,p.position_ms,p.watch_seconds,p.last_watched_at,
            l.videos_json,l.cached_until,m.id AS message_id,c.id AS conversation_id
            FROM vp3_youtube_playlist_plays_v1 p JOIN vp3_youtube_saved_playlists_v1 l
              ON l.owner_user_id=p.owner_user_id AND l.playlist_id=p.playlist_id
            LEFT JOIN chat_messages m ON m.id=l.source_message_id AND m.role='assistant'
            LEFT JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=p.owner_user_id
            WHERE p.owner_user_id=? AND l.cached_until>?{$playlistScope} AND p.played_at>?
            ORDER BY p.played_at DESC,p.play_id DESC LIMIT {$limit}");
        $query->execute([...$params,time()-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $video = vp3_youtube_history_video_v1(['videos'=>(array)json_decode($row['videos_json'],true)],$row['video_id']);
            if (!$video) continue;
            $history[] = $video+['play_id'=>$row['play_id'],'playlist_id'=>$row['playlist_id'],
                'message_id'=>$row['conversation_id'] ? (int)$row['message_id'] : 0,'conversation_id'=>(int)$row['conversation_id'],
                'played_at'=>(int)$row['played_at'],
                'expires_at'=>min((int)$row['cached_until'],(int)$row['played_at']+VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1),'music_suggestion'=>false,
                'watch_seconds'=>$watchReady?(int)$row['watch_seconds']:0,'last_watched_at'=>$watchReady?(int)$row['last_watched_at']:0]
                + ($positionReady && $row['position_ms'] !== null ? ['resume_ms'=>(int)$row['position_ms']] : []);
        }
        usort($history,static fn(array $a,array $b): int => $b['played_at']<=>$a['played_at'] ?: strcmp($b['play_id'],$a['play_id']));
    }
    return array_slice($history,0,$limit);
}
