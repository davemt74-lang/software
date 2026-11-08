<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-youtube-quota-v1.php';

function youtube_quota_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function youtube_quota_blocked(callable $attempt, int $status): void
{
    try {
        $attempt();
    } catch (AgentConnectionYoutubeExceptionV1 $error) {
        youtube_quota_check($error->getCode() === $status, 'Unexpected YouTube quota error.');
        return;
    }
    throw new RuntimeException('YouTube quota should have blocked the request.');
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
$pdo = new PDO($dsn !== '' ? $dsn : 'sqlite::memory:', getenv('VP3_TEST_MYSQL_USER') ?: 'root',
    (string)getenv('VP3_TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = $dsn !== '' ? 'vp3_youtube_quota_'.bin2hex(random_bytes(8)) : '';
if ($database !== '') {
    $pdo->exec("CREATE DATABASE `$database`");
    $pdo->exec("USE `$database`");
}
try {
    youtube_quota_blocked(fn()=>agent_connection_youtube_reserve_v1($pdo,11),503);
    youtube_quota_check(agent_connection_youtube_usage_v1($pdo) === null,'Missing schema must be visible to admin.');

    agent_connection_youtube_quota_schema_v1($pdo);
    agent_connection_youtube_quota_schema_v1($pdo);
    youtube_quota_blocked(fn()=>agent_connection_youtube_reserve_v1($pdo,0),403);
    $day = agent_connection_youtube_reserve_v1($pdo,11);
    youtube_quota_check($day === agent_connection_youtube_clock_v1()->format('Y-m-d'),'Use Pacific quota dates.');
    agent_connection_youtube_record_success_v1($pdo,11,$day);
    agent_connection_youtube_record_success_v1($pdo,11,$day);
    for ($i=1;$i<VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1;$i++) agent_connection_youtube_reserve_v1($pdo,11);
    youtube_quota_blocked(fn()=>agent_connection_youtube_reserve_v1($pdo,11),429);
    $usage = agent_connection_youtube_usage_v1($pdo);
    youtube_quota_check($usage['attempts_today'] === VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1,'A denied owner reservation must roll back the global counter.');
    youtube_quota_check($usage['users_today'] === 1 && $usage['searches_today'] === 1,'Count validated provider responses only.');

    for ($i=VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1;$i<VP3_YOUTUBE_SITE_SEARCH_LIMIT_V1;$i++) {
        agent_connection_youtube_reserve_v1($pdo,100+$i);
    }
    youtube_quota_blocked(fn()=>agent_connection_youtube_reserve_v1($pdo,999),429);
    $usage = agent_connection_youtube_usage_v1($pdo);
    youtube_quota_check($usage['attempts_today'] === $usage['daily_limit'],'Shared cap must be enforced.');
    youtube_quota_check($usage['users_today'] === 1 && $usage['searches_today'] === 1,'Reservations do not inflate success analytics.');

    $clock = agent_connection_youtube_clock_v1();
    $insert = $pdo->prepare('INSERT INTO agent_connection_youtube_usage_v1 VALUES (?,?,?,?)');
    $insert->execute([$clock->modify('-29 days')->format('Y-m-d'),12,3,2]);
    $insert->execute([$clock->modify('-30 days')->format('Y-m-d'),13,9,9]);
    $insert->execute([$clock->modify('+1 day')->format('Y-m-d'),14,7,7]);
    $usage = agent_connection_youtube_usage_v1($pdo);
    youtube_quota_check($usage['users_30d'] === 2 && $usage['searches_30d'] === 3,'Rolling counts include 30 Pacific calendar dates.');
    youtube_quota_check(agent_connection_youtube_quota_cleanup_v1($pdo) === 1,'Remove only quota dates older than retention.');
    echo 'AGENT_CONNECTION_YOUTUBE_QUOTA_V1=PASS ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
} finally {
    if ($database !== '') $pdo->exec("DROP DATABASE `$database`");
}
