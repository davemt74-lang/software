<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$viewer = current_user();
$userId = (int)($_GET['user'] ?? 0);

if ($userId < 1 || !$viewer) {
    http_response_code(404);
    exit;
}

if ($userId !== (int)$viewer['id'] && !has_permission('users.manage', $viewer)) {
    http_response_code(403);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id=? LIMIT 1');
$stmt->execute([$userId]);
$path = trim((string)$stmt->fetchColumn());

if ($path === '' || !str_starts_with($path, '/uploads/avatars/')) {
    http_response_code(404);
    exit;
}

$base = realpath(STONEFELLOW_ROOT . '/uploads/avatars');
$file = realpath(STONEFELLOW_ROOT . '/' . ltrim($path, '/'));

if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit;
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $file);
        if (is_string($detected) && str_starts_with($detected, 'image/')) {
            $mime = $detected;
        }
        finfo_close($finfo);
    }
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$fallback = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
$mime = $fallback[$ext] ?? $mime;

if (!str_starts_with($mime, 'image/')) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
