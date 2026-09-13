<?php
declare(strict_types=1);

/**
 * Canonical Artist Listening API route.
 *
 * Retained recording metadata emits this stable endpoint. Private recording
 * playback is intercepted here so native browser audio can rely on deterministic
 * GET/HEAD and byte-range semantics. All non-recording actions continue through
 * the versioned Artist Listening runtime.
 */
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'bootstrap'));

if ($action === 'recording' && in_array($method, ['GET', 'HEAD'], true)) {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    require_once dirname(__DIR__) . '/includes/artist-listening.php';
    require_once dirname(__DIR__) . '/includes/artist-recording-playback-v243.php';

    $user = current_user();
    if (!$user) {
        http_response_code(401);
        header('Content-Length: 0');
        header('Cache-Control: private, no-store, max-age=0');
        exit;
    }
    if (!has_permission('artist_listening.access', $user)) {
        http_response_code(403);
        header('Content-Length: 0');
        header('Cache-Control: private, no-store, max-age=0');
        exit;
    }
    if (!artist_listening_v172_schema_ready()) {
        http_response_code(503);
        header('Content-Length: 0');
        header('Cache-Control: private, no-store, max-age=0');
        exit;
    }
    $pdo = db();
    if (!$pdo) {
        http_response_code(503);
        header('Content-Length: 0');
        header('Cache-Control: private, no-store, max-age=0');
        exit;
    }

    try {
        artist_recording_playback_v243_serve(
            $pdo,
            $user,
            max(0, (int)($_GET['session_id'] ?? 0)),
            (string)($_GET['recording_key'] ?? ''),
            $method,
            (string)($_SERVER['HTTP_RANGE'] ?? '')
        );
    } catch (Throwable $e) {
        // Keep private recording errors intentionally opaque at the media edge.
        artist_recording_playback_v243_prepare_transport();
        http_response_code(404);
        header('Content-Length: 0');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

require __DIR__ . '/artist-listening-v172.php';
