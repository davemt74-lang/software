<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$trackId = (int)($_GET['track'] ?? 0);
$type = (string)($_GET['type'] ?? 'audio');

if ($trackId < 1 || !in_array($type, ['audio', 'cover'], true)) {
    http_response_code(400);
    exit('Invalid media request.');
}

$track = get_track_by_id($trackId);
if (!$track) {
    http_response_code(404);
    exit('Media not found.');
}

if (!can_view_track($track)) {
    http_response_code(is_logged_in() ? 403 : 401);
    header('Cache-Control: no-store');
    exit('You do not have access to this media.');
}

$relative = $type === 'cover'
    ? (string)($track['cover_path'] ?? '')
    : (string)($track['audio_path'] ?? '');

if ($relative === '' || preg_match('#^https?://#i', $relative)) {
    http_response_code(404);
    exit('Media file is not available.');
}

$rootReal = realpath(STONEFELLOW_ROOT);
$absolute = realpath(STONEFELLOW_ROOT . '/' . ltrim($relative, '/'));

if (!$rootReal || !$absolute || !is_file($absolute)) {
    http_response_code(404);
    exit('Media file is not available.');
}

$rootPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!str_starts_with($absolute, $rootPrefix)) {
    http_response_code(403);
    exit('Invalid media path.');
}

$size = filesize($absolute);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Media file is empty.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $absolute);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
        finfo_close($finfo);
    }
}

$allowedPrefix = $type === 'cover' ? 'image/' : 'audio/';
if (!str_starts_with($mime, $allowedPrefix)) {
    $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
    $fallbackMimes = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    $mime = $fallbackMimes[$extension] ?? $mime;
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

$start = 0;
$end = $size - 1;
$status = 200;

$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
    if ($matches[1] === '' && $matches[2] !== '') {
        $suffixLength = (int)$matches[2];
        $start = max(0, $size - $suffixLength);
    } else {
        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end = $matches[2] !== '' ? (int)$matches[2] : $end;
    }

    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }

    $end = min($end, $size - 1);
    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
header('Content-Length: ' . $length);
if ($status === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$handle = fopen($absolute, 'rb');
if (!$handle) {
    http_response_code(500);
    exit;
}

fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_status() !== CONNECTION_NORMAL) {
        break;
    }
}
fclose($handle);
exit;
