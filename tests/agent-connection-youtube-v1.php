<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-connection-youtube-v1.php';

const YOUTUBE_FALLBACK_KEY = 'fixture-youtube-server-key-1234567890';
const YOUTUBE_SAVED_KEY = 'fixture-youtube-saved-key-1234567890';

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    }
    return $pdo;
}

function ai_decrypt_secret(string $value): string
{
    return $value === 'encrypted-fixture' ? YOUTUBE_SAVED_KEY : '';
}

function youtube_assert(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

function youtube_expect_error(int $code, callable $run): void
{
    try {
        $run();
    } catch (AgentConnectionYoutubeExceptionV1 $e) {
        youtube_assert($e->getCode() === $code, 'Unexpected YouTube error status.');
        youtube_assert(!str_contains($e->getMessage(), 'secret-fixture')
            && !str_contains($e->getMessage(), YOUTUBE_FALLBACK_KEY), 'Raw provider data escaped through an error.');
        return;
    }
    throw new RuntimeException('Expected a YouTube error.');
}

putenv('VP3_YOUTUBE_API_KEY');
$config = ['connections' => ['youtube' => ['api_key' => YOUTUBE_FALLBACK_KEY]]];
youtube_assert(agent_connection_youtube_config_v1() === ['api_key' => YOUTUBE_FALLBACK_KEY, 'source' => 'config'],
    'Server-configured key was not resolved.');
putenv('VP3_YOUTUBE_API_KEY=fixture-youtube-environment-key-123456');
youtube_assert(agent_connection_youtube_config_v1()['api_key'] === 'fixture-youtube-environment-key-123456',
    'Environment did not override the config-file fallback.');
putenv('VP3_YOUTUBE_API_KEY');

$calls = 0;
$row = static fn(string $id, string $title, string $thumbnail = '', string $kind = 'youtube#video'): array => [
    'id' => ['kind' => $kind, 'videoId' => $id],
    'snippet' => ['title' => $title, 'channelTitle' => 'Fresh &amp; Local', 'description' => 'secret-fixture',
        'thumbnails' => ['medium' => ['url' => $thumbnail]]],
];
$results = agent_connection_youtube_search_v1(" Pizza   dough\n café & key=injection ", 100,
    static function (string $url, array $headers) use ($row, &$calls): array {
        $calls++;
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        youtube_assert(str_starts_with($url, VP3_YOUTUBE_SEARCH_ENDPOINT_V1 . '?') && !str_contains($url, YOUTUBE_FALLBACK_KEY)
            && $headers === ['Accept: application/json', 'X-Goog-Api-Key: ' . YOUTUBE_FALLBACK_KEY] && !isset($query['key']),
            'Provider destination or private key placement is incorrect.');
        youtube_assert($query['q'] === 'Pizza dough café & key=injection' && $query['type'] === 'video'
            && $query['videoEmbeddable'] === 'true' && $query['videoSyndicated'] === 'true'
            && $query['safeSearch'] === 'moderate' && $query['maxResults'] === '8',
            'YouTube query lost the owner text or bounded embed filters.');
        return ['status' => 200, 'json' => ['items' => [
            $row('abcDEF123-_', 'Dough &amp; Flour', 'https://i.ytimg.com/vi/abcDEF123-_/mqdefault.jpg'),
            $row('abcDEF123-_', 'Duplicate'),
            $row('xyzABC456-_', 'Channel', '', 'youtube#channel'),
            $row('malformed-id!', 'Invalid ID'),
            $row('z1234567890', str_repeat('é', 250), 'https://i.ytimg.com.evil.test/vi/z1234567890/mqdefault.jpg'),
            $row('y1234567890', 'Other thumbnail', 'https://i.ytimg.com/vi/abcDEF123-_/mqdefault.jpg'),
            $row('x1234567890', ''),
            $row('v1234567890', 'Bounded final result'),
            $row('u1234567890', 'Unrequested result'),
        ]]];
    });
youtube_assert($calls === 1 && array_column($results, 'video_id') === ['abcDEF123-_','z1234567890','y1234567890','v1234567890'],
    'Search must make one call and retain only the bounded unique videos in provider order.');
youtube_assert($results[0] === ['video_id' => 'abcDEF123-_', 'title' => 'Dough & Flour', 'channel_title' => 'Fresh & Local',
    'thumbnail_url' => 'https://i.ytimg.com/vi/abcDEF123-_/mqdefault.jpg'], 'Minimal public video metadata was not projected.');
youtube_assert(mb_strlen($results[1]['title'], 'UTF-8') === 200 && $results[1]['thumbnail_url'] === '' && $results[2]['thumbnail_url'] === '',
    'Untrusted thumbnails and oversized titles must not escape the provider boundary.');

$never = static fn() => throw new RuntimeException('Invalid input or disabled credential contacted YouTube.');
foreach (['', '   ', str_repeat('é', 201), "invalid UTF-8\xff", "in\0valid"] as $query) {
    youtube_expect_error(400, static fn() => agent_connection_youtube_search_v1($query, 6, $never));
}
youtube_assert(agent_connection_youtube_search_v1('empty', 6,
    static fn() => ['status' => 200, 'json' => ['kind' => 'youtube#searchListResponse']]) === [],
    'A valid empty partial response should yield no results.');
foreach ([
    [403, 429, ['error' => ['errors' => [['reason' => 'quotaExceeded']], 'message' => 'secret-fixture']]],
    [429, 429, null],
    [400, 503, ['error' => ['details' => [['reason' => 'API_KEY_INVALID']]]]],
    [403, 503, ['error' => ['message' => 'secret-fixture']]],
    [500, 502, ['error' => 'secret-fixture']],
    [302, 502, null],
    [200, 502, ['items' => ['unknown' => []]]],
    [200, 502, null],
] as [$status, $code, $body]) {
    youtube_expect_error($code, static fn() => agent_connection_youtube_search_v1('dough', 6,
        static fn() => ['status' => $status, 'json' => $body]));
}

db()->exec("INSERT INTO settings VALUES ('connections_youtube_api_key', 'encrypted-fixture'), ('connections_youtube_enabled', '0')");
youtube_assert(agent_connection_youtube_config_v1() === ['api_key' => YOUTUBE_SAVED_KEY, 'source' => 'saved']
    && !agent_connection_youtube_ready_v1(), 'The encrypted admin key or pause state was not resolved.');
youtube_expect_error(403, static fn() => agent_connection_youtube_search_v1('dough', 6, $never));
agent_connection_youtube_test_v1(static function (string $url, array $headers): array {
    youtube_assert(str_starts_with($url, VP3_YOUTUBE_HEALTH_ENDPOINT_V1 . '?')
        && in_array('X-Goog-Api-Key: ' . YOUTUBE_SAVED_KEY, $headers, true), 'Admin credential check used the search quota or wrong key.');
    return ['status' => 200, 'json' => ['items' => [['snippet' => ['gl' => 'US']]]]];
});
youtube_expect_error(502, static fn() => agent_connection_youtube_test_v1(
    static fn() => ['status' => 200, 'json' => ['items' => []]]));
db()->exec("UPDATE settings SET setting_value = 'bad-ciphertext' WHERE setting_key = 'connections_youtube_api_key';
    DELETE FROM settings WHERE setting_key = 'connections_youtube_enabled'");
youtube_assert(agent_connection_youtube_config_v1() === ['api_key' => '', 'source' => 'saved'],
    'A corrupt saved key silently fell back to another credential.');
youtube_expect_error(503, static fn() => agent_connection_youtube_search_v1('dough', 6, $never));
db()->exec("DELETE FROM settings WHERE setting_key = 'connections_youtube_api_key'");
youtube_assert(agent_connection_youtube_config_v1()['api_key'] === YOUTUBE_FALLBACK_KEY,
    'Removing the saved key should restore the server fallback.');
db()->exec('DROP TABLE settings');
youtube_assert(!agent_connection_youtube_enabled_v1() && agent_connection_youtube_config_v1()['api_key'] === '',
    'Unavailable settings did not fail closed.');

echo "AGENT_CONNECTION_YOUTUBE_V1=PASS\n";
