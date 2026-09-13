<?php
declare(strict_types=1);

/**
 * VP3 Profile Public Media v1.74
 *
 * Public profile uploads have existed in more than one stored-path form. Newer
 * rows use `/uploads/...`; some older rows may retain `uploads/...`. Keep the
 * browser-facing URL canonical while never accepting arbitrary filesystem paths.
 */
const VP3_PROFILE_PUBLIC_MEDIA_V174 = 'profile-public-media-v174-20260913';

function profile_public_media_path_v174(?string $storedPath, string $bucket): string
{
    $bucket = strtolower(trim($bucket));
    if (!in_array($bucket, ['avatars', 'profile-covers'], true)) return '';

    $path = trim((string)$storedPath);
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    $path = '/' . ltrim($path, '/');

    $prefix = '/uploads/' . $bucket . '/';
    if (!str_starts_with($path, $prefix)) return '';

    $file = substr($path, strlen($prefix));
    if ($file === '' || str_contains($file, '/')) return '';
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}\.(?:jpe?g|png|webp)$/i', $file)) return '';

    return $prefix . $file;
}

function profile_public_media_file_v174(?string $storedPath, string $bucket): string
{
    $path = profile_public_media_path_v174($storedPath, $bucket);
    if ($path === '') return '';

    $base = realpath(STONEFELLOW_ROOT . '/uploads/' . $bucket);
    $file = realpath(STONEFELLOW_ROOT . '/' . ltrim($path, '/'));
    if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) return '';
    return $file;
}

function profile_public_media_url_v174(?string $storedPath, string $bucket): string
{
    $path = profile_public_media_path_v174($storedPath, $bucket);
    if ($path === '' || profile_public_media_file_v174($path, $bucket) === '') return '';

    $file = basename($path);
    return url('/api/profile-media.php?bucket=' . rawurlencode($bucket) . '&file=' . rawurlencode($file));
}

/** @return array<int,string> */
function profile_public_media_db_candidates_v174(string $canonicalPath): array
{
    $canonicalPath = trim($canonicalPath);
    if ($canonicalPath === '') return [];
    $legacy = ltrim($canonicalPath, '/');
    return $legacy === $canonicalPath ? [$canonicalPath] : [$canonicalPath, $legacy];
}
