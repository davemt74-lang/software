<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-vp3.php';

const VP3_HOMESERVER_RELAY_BOOTSTRAP_V1210 = 'homeserver-relay-bootstrap-v1210-20260913';

function homeserver_relay_bootstrap_v1210_websocket_url(): string
{
    $base = homeserver_vp3_relay_base_url();
    if ($base === '') {
        throw new RuntimeException('HomeServer relay is not configured on VP3.');
    }

    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('HomeServer relay configuration is invalid.');
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = rtrim((string)($parts['path'] ?? ''), '/');

    if ($scheme === 'https') {
        $wsScheme = 'wss';
    } elseif ($scheme === 'http' && in_array(strtolower($host), ['localhost','127.0.0.1','::1'], true)) {
        $wsScheme = 'ws';
    } else {
        throw new RuntimeException('HomeServer relay bootstrap requires HTTPS in production.');
    }

    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = '[' . $host . ']';
    }
    return $wsScheme . '://' . $host . $port . $path . '/bridge';
}
