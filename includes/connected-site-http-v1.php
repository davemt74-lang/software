<?php
declare(strict_types=1);

function vp3_connected_site_origin_v1(string $input, string $platform): string
{
    require_once __DIR__.'/cloud-mission-web-v1.php';
    $input = trim($input);
    if (!preg_match('~^https://([a-z0-9.-]{1,253})/?$~iD', $input, $match)) {
        throw new InvalidArgumentException("Enter the public HTTPS origin of your {$platform} installation.");
    }
    return 'https://'.cloud_mission_web_host_v1($match[1]);
}

/** Build one client pinned to a checked DNS address for this action. */
function vp3_connected_site_transport_v1(
    string $origin, string $path, string $platform, ?callable $resolver = null, array $reportedStatuses = [],
    ?callable $responseError = null, ?callable $observeDiscovery = null
): Closure
{
    if (!preg_match('~^/api/[a-z0-9-]+\.php$~D', $path)) {
        throw new InvalidArgumentException('The connected site path is unsupported.');
    }
    $deadline = hrtime(true) + 12_000_000_000;
    $host = substr(vp3_connected_site_origin_v1($origin, $platform), 8);
    $records = $resolver ? $resolver($host) : @dns_get_record($host, DNS_A | DNS_AAAA);
    $addresses = [];
    foreach (is_array($records) ? $records : [] as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if ($address === null) continue; // A CNAME can accompany checked A/AAAA records.
        if (!is_string($address) || !cloud_mission_web_public_ip_v1($address)
            || (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))
            || (str_contains($address, ':') && str_starts_with(inet_pton($address), "\x20\x01\x00\x00"))) {
            throw new RuntimeException("The {$platform} hostname must resolve only to public internet addresses.", 422);
        }
        $addresses[] = $address;
    }
    if (!$addresses) {
        throw new RuntimeException("The {$platform} hostname could not be securely resolved.", 502);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException("{$platform} Cloud connections require the PHP cURL extension.", 503);
    }
    // Prefer IPv4 when available because some shared Cloud hosts lack IPv6 egress.
    $ipv4 = array_filter($addresses, static fn(string $ip): bool => !str_contains($ip, ':'));
    $ip = $ipv4 ? reset($ipv4) : $addresses[0];
    $resolve = $host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
    $url = 'https://'.$host.$path;
    return static function (?array $payload = null, ?string $token = null) use ($url, $resolve, $ip, $deadline, $platform, $reportedStatuses, $responseError, $observeDiscovery): array {
        $remainingMs = intdiv($deadline - hrtime(true), 1_000_000);
        if ($remainingMs < 1) throw new RuntimeException("The {$platform} request timed out.", 502);
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $body = '';
        $observe = $payload === null && $token === null ? $observeDiscovery : null;
        $responseHeaders = [];
        $curl = curl_init($url);
        try {
            $options = [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$resolve],
                CURLOPT_CONNECTTIMEOUT_MS => min(2500, $remainingMs),
                CURLOPT_TIMEOUT_MS => min(5000, $remainingMs),
                CURLOPT_ENCODING => '',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 256000) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($payload !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            if ($observe !== null) {
                $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
                    $fields = explode(':', $line, 2);
                    $name = strtolower($fields[0]);
                    if (isset($fields[1]) && in_array($name, ['server','cf-ray','cf-mitigated','x-request-id'],true)) {
                        $responseHeaders[$name] = substr(trim($fields[1]), 0, 128);
                    }
                    return strlen($line);
                };
            }
            $received = curl_setopt_array($curl, $options) && curl_exec($curl) === true;
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
            if ($observe !== null) {
                $observe($status, $contentType, $responseHeaders, $ip);
            }
            if (!$received) {
                throw new RuntimeException("The {$platform} site could not be reached securely.", 502);
            }
        } finally {
            curl_close($curl);
        }
        if ($responseError !== null && ($status < 200 || $status >= 300)) {
            $failure = $responseError($status, $contentType, $body, $payload['action'] ?? null);
            if ($failure !== null) throw $failure;
        }
        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException("{$platform} declined authorization. Check the connection in {$platform}.", 403);
        }
        if ($token !== null && $contentType === 'application/json' && in_array($status, $reportedStatuses, true)) {
            throw new RuntimeException("{$platform} could not resolve that request.", $status);
        }
        if ($status < 200 || $status >= 300 || $contentType !== 'application/json') {
            throw new RuntimeException("The {$platform} site did not return a valid connector response.", 502);
        }
        try {
            $response = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The {$platform} site did not return a valid connector response.", 502);
        }
        if (!is_array($response) || ($response['ok'] ?? null) !== true) {
            throw new RuntimeException("The {$platform} site did not confirm the request.", 502);
        }
        return $response;
    };
}
