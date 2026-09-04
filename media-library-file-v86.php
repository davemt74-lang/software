<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    exit('Forbidden');
}

$assetId = max(0,(int)($_GET['id'] ?? 0));
$row = media_studio_asset($assetId,$user);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$root = realpath(media_studio_user_root((int)$user['id']));
$path = realpath(STONEFELLOW_ROOT . '/' . ltrim((string)$row['file_path'],'/'));
if (!$root || !$path || !str_starts_with($path,$root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$size = (int)filesize($path);
$start = 0;
$end = max(0,$size - 1);
$status = 200;

header('Content-Type: ' . (string)$row['mime_type']);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="media-' . $assetId . '.' . media_studio_extension_for_mime((string)$row['mime_type']) . '"');

$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/i',$range,$m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $suffix = min($size,max(0,(int)$m[2]));
        $start = max(0,$size - $suffix);
    } else {
        $start = $m[1] !== '' ? max(0,(int)$m[1]) : 0;
        $end = $m[2] !== '' ? min($end,(int)$m[2]) : $end;
    }

    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $status = 206;
}

$length = max(0,$end - $start + 1);
http_response_code($status);
header('Content-Length: ' . $length);
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($path,'rb');
if (!$handle) {
    http_response_code(500);
    exit;
}
fseek($handle,$start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle,min(1024 * 1024,$remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);
