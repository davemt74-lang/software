<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/chrome-extension-releases.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

$pdo=db();
$managedRelease = ($pdo && function_exists('client_release_rollouts_schema_ready_v110') && client_release_rollouts_schema_ready_v110($pdo))
    ? client_release_public_release_v110($pdo,'browser_companion','stable')
    : chrome_extension_latest_release('stable');
if ($managedRelease) {
    $storedPath = (string)($managedRelease['package_path'] ?? '');
    $base = realpath(chrome_extension_release_private_dir());
    $real = $storedPath !== '' && is_file($storedPath) ? realpath($storedPath) : false;
    $size = $real ? filesize($real) : false;
    if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_int($size) || $size < 1) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "The current VP3 Chrome Extension package is temporarily unavailable.";
        exit;
    }

    $filename = basename((string)($managedRelease['package_name'] ?? ''));
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        $filename = 'vp3-browser-companion-v' . (string)$managedRelease['version'] . '.zip';
    }
    $filename = str_replace(['"', "\r", "\n"], '', $filename);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)$size);
    header('X-Chrome-Extension-Version: ' . (string)$managedRelease['version']);
    if (!empty($managedRelease['package_sha256'])) {
        header('X-Chrome-Extension-SHA256: ' . (string)$managedRelease['package_sha256']);
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        exit;
    }
    readfile($real);
    exit;
}

$root = __DIR__ . '/browser-companion';
$manifestPath = $root . '/manifest.json';

if (!is_file($manifestPath) || !class_exists('ZipArchive')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The VP3 Chrome Extension package is temporarily unavailable.";
    exit;
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
$version = preg_match('/^\d+(?:\.\d+){1,3}$/', (string)($manifest['version'] ?? ''))
    ? (string)$manifest['version']
    : 'current';

$files = [
    'manifest.json',
    'background.js',
    'notification-icon.png',
    'offscreen.html',
    'offscreen.js',
    'options.css',
    'options.html',
    'options.js',
    'sidepanel.css',
    'sidepanel.html',
    'sidepanel.js',
];

foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "The VP3 Chrome Extension package is incomplete.";
        exit;
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'vp3-browser-companion-');
if ($tmp === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The VP3 Chrome Extension package could not be prepared.";
    exit;
}

$zipPath = $tmp . '.zip';
@unlink($tmp);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The VP3 Chrome Extension package could not be prepared.";
    exit;
}

foreach ($files as $file) {
    $zip->addFile($root . '/' . $file, $file);
}
$zip->close();

$filename = 'vp3-browser-companion-v' . $version . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($zipPath));

readfile($zipPath);
@unlink($zipPath);
exit;
