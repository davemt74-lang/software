<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

const VP3_YOUTUBE_SITE_SEARCH_LIMIT_V1 = 100;
const VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1 = 15;

function agent_connection_youtube_quota_schema_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_youtube_usage_v1 (
        quota_day DATE NOT NULL,
        owner_user_id INT UNSIGNED NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        successes INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (quota_day,owner_user_id)
    )".(agent_commerce_sqlite_pa2($pdo) ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
}

function agent_connection_youtube_quota_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT quota_day,owner_user_id,attempts,successes FROM agent_connection_youtube_usage_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_youtube_clock_v1(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
}

/** Commit both counters before the provider request. Owner zero is the shared quota row. */
function agent_connection_youtube_reserve_v1(PDO $pdo, int $ownerId): string
{
    if ($ownerId < 1) throw new AgentConnectionYoutubeExceptionV1('YouTube search requires a VP3 account.', 403);
    if ($pdo->inTransaction() || !agent_connection_youtube_quota_ready_v1($pdo)) {
        throw new AgentConnectionYoutubeExceptionV1('YouTube search quota storage is unavailable.', 503);
    }
    $day = agent_connection_youtube_clock_v1()->format('Y-m-d');
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo,$day,$ownerId): void {
        $insert = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_connection_youtube_usage_v1 (quota_day,owner_user_id) VALUES (?,?)');
        $increment = $pdo->prepare('UPDATE agent_connection_youtube_usage_v1 SET attempts=attempts+1 WHERE quota_day=? AND owner_user_id=? AND attempts<?');
        foreach ([0=>VP3_YOUTUBE_SITE_SEARCH_LIMIT_V1,$ownerId=>VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1] as $owner=>$limit) {
            $insert->execute([$day,$owner]);
            $increment->execute([$day,$owner,$limit]);
            if ($increment->rowCount() !== 1) {
                $message = $owner === 0 ? 'VP3 reached its shared daily YouTube search limit.' : 'You reached your daily YouTube search limit.';
                throw new AgentConnectionYoutubeExceptionV1($message.' The limit resets at midnight Pacific Time.',429);
            }
        }
    });
    return $day;
}

function agent_connection_youtube_record_success_v1(PDO $pdo, int $ownerId, string $day): void
{
    if ($ownerId < 1) return;
    $stmt = $pdo->prepare('UPDATE agent_connection_youtube_usage_v1 SET successes=successes+1 WHERE quota_day=? AND owner_user_id=? AND successes<attempts');
    $stmt->execute([$day,$ownerId]);
}

/** Counts measure VP3 skill use and exclude admin probes. No query or video data is collected here. */
function agent_connection_youtube_usage_v1(PDO $pdo): ?array
{
    if (!agent_connection_youtube_quota_ready_v1($pdo)) return null;
    $clock = agent_connection_youtube_clock_v1();
    $today = $clock->format('Y-m-d');
    $start = $clock->modify('-29 days')->format('Y-m-d');
    $query = $pdo->prepare('SELECT COUNT(DISTINCT CASE WHEN quota_day=? AND successes>0 THEN owner_user_id END) AS users_today,
        COALESCE(SUM(CASE WHEN quota_day=? THEN successes ELSE 0 END),0) AS searches_today,
        COUNT(DISTINCT CASE WHEN successes>0 THEN owner_user_id END) AS users_30d,
        COALESCE(SUM(successes),0) AS searches_30d
        FROM agent_connection_youtube_usage_v1 WHERE owner_user_id>0 AND quota_day>=? AND quota_day<=?');
    $query->execute([$today,$today,$start,$today]);
    $stats = array_map('intval',$query->fetch(PDO::FETCH_ASSOC));
    $shared = $pdo->prepare('SELECT attempts FROM agent_connection_youtube_usage_v1 WHERE owner_user_id=0 AND quota_day=?');
    $shared->execute([$today]);
    return $stats + [
        'attempts_today'=>(int)$shared->fetchColumn(),
        'daily_limit'=>VP3_YOUTUBE_SITE_SEARCH_LIMIT_V1,
        'member_limit'=>VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1,
    ];
}

function agent_connection_youtube_quota_cleanup_v1(PDO $pdo): int
{
    $stmt = $pdo->prepare('DELETE FROM agent_connection_youtube_usage_v1 WHERE quota_day<?');
    $stmt->execute([agent_connection_youtube_clock_v1()->modify('-29 days')->format('Y-m-d')]);
    return $stmt->rowCount();
}
