<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-vp3.php';

function homeserver_relay_v1210_release(string $relayToken): array
{
    $relayToken = trim($relayToken);
    if (strlen($relayToken) < 32 || strlen($relayToken) > 512) {
        throw new RuntimeException('HomeServer relay authorization is unavailable.');
    }
    $base = homeserver_vp3_relay_base_url();
    if ($base === '') throw new RuntimeException('HomeServer relay is not configured on VP3.');
    if (!function_exists('curl_init')) throw new RuntimeException('cURL is required for HomeServer relay access.');

    $ch = curl_init($base . '/v1/session/release');
    if ($ch === false) throw new RuntimeException('Could not initialize HomeServer relay release.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>8,
        CURLOPT_CUSTOMREQUEST=>'POST',
        CURLOPT_POSTFIELDS=>'{}',
        CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $relayToken,
        ],
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body)) throw new RuntimeException('HomeServer relay release failed.');
    $data = json_decode($body, true);
    if (!is_array($data) || $status < 200 || $status >= 300) {
        $detail = is_array($data) ? trim((string)($data['detail'] ?? '')) : '';
        throw new RuntimeException($detail !== '' ? $detail : 'HomeServer relay release failed.');
    }
    if (empty($data['released']) || trim((string)($data['device_id'] ?? '')) === '') {
        throw new RuntimeException('HomeServer relay returned an invalid release response.');
    }
    return $data;
}
