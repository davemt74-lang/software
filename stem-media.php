<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('tracks.manage');

$stemId = (int)($_GET['id'] ?? 0);
$pdo = db();

if (!$pdo || $stemId < 1 || !table_exists('track_stems')) {
    http_response_code(404);
    exit('Stem not found.');
}

$stmt = $pdo->prepare(
    'SELECT s.* FROM track_stems s
     WHERE s.id=? AND s.is_active=1 LIMIT 1'
);
$stmt->execute([$stemId]);
$stem = $stmt->fetch();

if (!$stem) {
    http_response_code(404);
    exit('Stem not found.');
}

$relative = (string)$stem['file_path'];
$stemsRoot = realpath(STONEFELLOW_ROOT . '/uploads/stems');
$absolute = realpath(STONEFELLOW_ROOT . '/' . ltrim($relative, '/'));

if (
    !$stemsRoot ||
    !$absolute ||
    !is_file($absolute) ||
    !str_starts_with(
        $absolute,
        rtrim($stemsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    )
) {
    http_response_code(404);
    exit('Stem file is not available.');
}

$size = filesize($absolute);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Stem file is empty.');
}

$extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
$mime = $extension === 'mp3' ? 'audio/mpeg' : 'audio/wav';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $absolute);
        if (is_string($detected) && str_starts_with($detected, 'audio/')) {
            $mime = $detected;
        }
        finfo_close($finfo);
    }
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
        $suffix = max(0, (int)$matches[2]);
        $start = max(0, $size - $suffix);
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
    $chunk = fread($handle, min(16384, $remaining));
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
