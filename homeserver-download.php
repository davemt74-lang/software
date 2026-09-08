<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$releaseId = max(0, (int)($_GET['id'] ?? 0));
$type = (string)($_GET['type'] ?? 'installer');
if ($releaseId < 1 || !in_array($type, ['installer','portable'], true)) {
    http_response_code(404);
    exit('Release not found.');
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    exit('Release service unavailable.');
}
homeserver_vp3_ensure_schema($pdo);
$stmt = $pdo->prepare('SELECT * FROM homeserver_releases WHERE id=? AND is_published=1 LIMIT 1');
$stmt->execute([$releaseId]);
$release = $stmt->fetch();
if (!$release) {
    http_response_code(404);
    exit('Release not found.');
}

$pathKey = $type . '_path';
$nameKey = $type . '_name';
$hashKey = $type . '_sha256';
$path = (string)($release[$pathKey] ?? '');
$name = (string)($release[$nameKey] ?? '');
$expectedHash = (string)($release[$hashKey] ?? '');
$base = realpath(homeserver_vp3_private_dir());
$real = $path !== '' && is_file($path) ? realpath($path) : false;
if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Release file not found.');
}
$size = filesize($real);
if (!is_int($size) || $size < 1) {
    http_response_code(404);
    exit('Release file not found.');
}

$start = 0;
$end = $size - 1;
$status = 200;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    if ($match[1] === '' && $match[2] === '') {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    if ($match[1] === '') {
        $suffix = min($size, max(1, (int)$match[2]));
        $start = $size - $suffix;
    } else {
        $start = (int)$match[1];
    }
    if ($match[2] !== '') {
        $end = min($end, (int)$match[2]);
    }
    if ($start < 0 || $start >= $size || $end < $start) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $status = 206;
}
$length = $end - $start + 1;

http_response_code($status);
header('Content-Type: application/vnd.microsoft.portable-executable');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300, immutable');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Content-Disposition: attachment; filename="' . str_replace(['"',"\r","\n"], '', basename($name !== '' ? $name : 'HomeServer.exe')) . '"');
if ($expectedHash !== '') {
    header('X-HomeServer-SHA256: ' . $expectedHash);
}
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

$handle = fopen($real, 'rb');
if ($handle === false || fseek($handle, $start) !== 0) {
    if (is_resource($handle)) fclose($handle);
    http_response_code(500);
    exit;
}
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1048576, $remaining));
    if (!is_string($chunk) || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
}
fclose($handle);
