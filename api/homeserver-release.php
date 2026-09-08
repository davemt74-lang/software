<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

try {
    $channel = strtolower(trim((string)($_GET['channel'] ?? 'stable')));
    if (!homeserver_vp3_channel_valid($channel)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Invalid HomeServer release channel.']);
        exit;
    }
    $release = homeserver_vp3_latest_release($channel);
    echo json_encode([
        'ok'=>true,
        'service'=>'VP3 HomeServer Releases',
        'channel'=>$channel,
        'release'=>$release ? homeserver_vp3_release_public($release) : null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'HomeServer release metadata is temporarily unavailable.']);
}
