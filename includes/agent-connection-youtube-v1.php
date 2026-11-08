<?php
declare(strict_types=1);

const VP3_YOUTUBE_SEARCH_ENDPOINT_V1 = 'https://www.googleapis.com/youtube/v3/search';
const VP3_YOUTUBE_HEALTH_ENDPOINT_V1 = 'https://www.googleapis.com/youtube/v3/i18nRegions';
const VP3_YOUTUBE_MAX_RESULTS_V1 = 8;

final class AgentConnectionYoutubeExceptionV1 extends RuntimeException {}

function agent_connection_youtube_valid_api_key_v1(string $key): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{20,128}\z/', $key) === 1;
}

function agent_connection_youtube_enabled_v1(): bool
{
    if (!function_exists('db')) return true;
    $pdo = db();
    if (!$pdo) return false;
    try {
        return $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'connections_youtube_enabled'")->fetchColumn() !== '0';
    } catch (PDOException $e) {
        return false;
    }
}

/** Server-only configuration. Send only readiness/source to browser or model clients. */
function agent_connection_youtube_config_v1(): array
{
    global $config;
    if (function_exists('db')) {
        $pdo = db();
        if (!$pdo) return ['api_key' => '', 'source' => 'none'];
        try {
            $saved = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'connections_youtube_api_key'")->fetchColumn();
        } catch (PDOException $e) {
            return ['api_key' => '', 'source' => 'none'];
        }
        if ($saved !== false) {
            $key = function_exists('ai_decrypt_secret') ? ai_decrypt_secret((string)$saved) : '';
            return ['api_key' => agent_connection_youtube_valid_api_key_v1($key) ? $key : '', 'source' => 'saved'];
        }
    }
    $key = trim((string)(getenv('VP3_YOUTUBE_API_KEY') ?: ($config['connections']['youtube']['api_key'] ?? '')));
    return [
        'api_key' => agent_connection_youtube_valid_api_key_v1($key) ? $key : '',
        'source' => $key !== '' ? 'config' : 'none',
    ];
}

function agent_connection_youtube_ready_v1(): bool
{
    return function_exists('curl_init') && agent_connection_youtube_enabled_v1()
        && agent_connection_youtube_config_v1()['api_key'] !== '';
}

function agent_connection_youtube_query_v1(string $query): string
{
    if (strlen($query) > 1200) {
        throw new AgentConnectionYoutubeExceptionV1('Use a YouTube search of up to 200 characters.', 400);
    }
    $query = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $query) ?? '');
    if ($query === '' || mb_strlen($query, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/', $query)) {
        throw new AgentConnectionYoutubeExceptionV1('Use a YouTube search of 1 to 200 characters.', 400);
    }
    return $query;
}

/** Both requests use fixed Google endpoints. Never follow provider redirects. */
function agent_connection_youtube_http_v1(string $url, array $headers): array
{
    if (!function_exists('curl_init')) {
        throw new AgentConnectionYoutubeExceptionV1('YouTube requires PHP cURL on the server.', 503);
    }
    $curl = curl_init($url);
    if ($curl === false) throw new AgentConnectionYoutubeExceptionV1('YouTube search is temporarily unavailable.', 502);
    $raw = '';
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_NETRC => CURL_NETRC_IGNORED,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'VP3-Connections/1',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$raw): int {
            if (strlen($raw) + strlen($chunk) > 131072) return 0;
            $raw .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'HTTPS';
    } else {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    try {
        if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
            throw new AgentConnectionYoutubeExceptionV1('YouTube search is temporarily unavailable.', 502);
        }
        return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'json' => json_decode($raw, true, 32)];
    } finally {
        curl_close($curl);
    }
}

function agent_connection_youtube_assert_response_v1(int $status, mixed $json): void
{
    if ($status === 200 && is_array($json) && !isset($json['error'])
        && is_array($json['items'] ?? null) && array_is_list($json['items'])) {
        return;
    }

    $error = is_array($json['error'] ?? null) ? $json['error'] : [];
    $reasons = [$error['status'] ?? ''];
    foreach (array_merge((array)($error['errors'] ?? []), (array)($error['details'] ?? [])) as $item) {
        if (is_array($item)) $reasons[] = $item['reason'] ?? '';
    }
    if ($status === 429 || array_intersect(['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded',
        'userRateLimitExceeded', 'RESOURCE_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'QUOTA_EXCEEDED'], array_filter($reasons, 'is_string'))) {
        throw new AgentConnectionYoutubeExceptionV1('YouTube search quota is unavailable right now. Try again later.', 429);
    }
    if (in_array($status, [401, 403], true) || in_array('API_KEY_INVALID', $reasons, true) || in_array('keyInvalid', $reasons, true)) {
        throw new AgentConnectionYoutubeExceptionV1('YouTube refused the server credential. Ask your VP3 administrator to check the YouTube Data API settings.', 503);
    }
    throw new AgentConnectionYoutubeExceptionV1('YouTube search is temporarily unavailable.', 502);
}

function agent_connection_youtube_text_v1(mixed $value, int $limit): string
{
    if (!is_string($value)) return '';
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $decoded) ?? '');
    return mb_substr($text, 0, $limit, 'UTF-8');
}

function agent_connection_youtube_request_v1(string $endpoint, array $params, string $kind, ?callable $http): array
{
    if (!in_array($endpoint, [VP3_YOUTUBE_SEARCH_ENDPOINT_V1, VP3_YOUTUBE_HEALTH_ENDPOINT_V1], true)) {
        throw new LogicException('Unexpected YouTube endpoint.');
    }
    $key = agent_connection_youtube_config_v1()['api_key'];
    if ($key === '') {
        throw new AgentConnectionYoutubeExceptionV1('Ask your VP3 administrator to set up the YouTube Data API.', 503);
    }
    $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $response = ($http ?? 'agent_connection_youtube_http_v1')($url, ['Accept: application/json', 'X-Goog-Api-Key: ' . $key]);
    $json = $response['json'] ?? null;
    // A partial response may omit an empty repeated field.
    if (is_array($json) && ($json['kind'] ?? null) === $kind && !array_key_exists('items', $json)) $json['items'] = [];
    agent_connection_youtube_assert_response_v1((int)($response['status'] ?? 0), $json);
    return $json['items'];
}

/** Verify the credential with a non-search API call, including during an admin pause. */
function agent_connection_youtube_test_v1(?callable $http = null): void
{
    $regions = agent_connection_youtube_request_v1(VP3_YOUTUBE_HEALTH_ENDPOINT_V1, [
        'part' => 'snippet', 'fields' => 'kind,items(snippet(gl))',
    ], 'youtube#i18nRegionListResponse', $http);
    foreach ($regions as $region) {
        $code = $region['snippet']['gl'] ?? null;
        if (is_string($code) && preg_match('/\A[A-Z]{2}\z/', $code) === 1) return;
    }
    throw new AgentConnectionYoutubeExceptionV1('YouTube did not return a valid credential check response.', 502);
}

/** One bounded public search. The server credential is carried in the header only. */
function agent_connection_youtube_search_v1(string $query, int $limit = 6, ?callable $http = null): array
{
    $query = agent_connection_youtube_query_v1($query);
    $limit = max(1, min($limit, VP3_YOUTUBE_MAX_RESULTS_V1));
    if (!agent_connection_youtube_enabled_v1()) {
        throw new AgentConnectionYoutubeExceptionV1('YouTube search is paused for this installation.', 403);
    }
    $items = agent_connection_youtube_request_v1(VP3_YOUTUBE_SEARCH_ENDPOINT_V1, [
        'part' => 'snippet',
        'type' => 'video',
        'videoEmbeddable' => 'true',
        'videoSyndicated' => 'true',
        'safeSearch' => 'moderate',
        'q' => $query,
        'maxResults' => $limit,
        'fields' => 'kind,items(id(kind,videoId),snippet(title,channelTitle,thumbnails(medium(url),default(url))))',
    ], 'youtube#searchListResponse', $http);

    $results = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        $id = $item['id']['videoId'] ?? null;
        if (($item['id']['kind'] ?? null) !== 'youtube#video' || !is_string($id)
            || preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id) !== 1 || isset($results[$id])) {
            continue;
        }
        $title = agent_connection_youtube_text_v1($item['snippet']['title'] ?? null, 200);
        if ($title === '') continue;
        $thumbnail = '';
        foreach (['medium', 'default'] as $size) {
            $candidate = $item['snippet']['thumbnails'][$size]['url'] ?? null;
            if (is_string($candidate) && preg_match('~\Ahttps://(?:i\.ytimg\.com|img\.youtube\.com)/(?:vi|vi_webp)/'
                . $id . '/[A-Za-z0-9_]{1,32}\.(?:jpg|webp)\z~', $candidate) === 1) {
                $thumbnail = $candidate;
                break;
            }
        }
        $results[$id] = [
            'video_id' => $id,
            'title' => $title,
            'channel_title' => agent_connection_youtube_text_v1($item['snippet']['channelTitle'] ?? null, 120),
            'thumbnail_url' => $thumbnail,
        ];
    }
    return array_values($results);
}
