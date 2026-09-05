<?php
declare(strict_types=1);

/**
 * Canonical site-level branding settings.
 *
 * Site branding is stored in the existing settings table so every surface
 * reads the same value. Uploaded logos are intentionally limited to the
 * dedicated /uploads/branding directory.
 */

function site_brand_name(): string
{
    $name = trim((string)site_config('name', 'Stonefellow'));
    return $name !== '' ? $name : 'Stonefellow';
}

function site_logo_path(): string
{
    $path = trim((string)setting('site_logo_path', ''));

    if ($path === '' || !preg_match('#^/uploads/branding/[a-f0-9]{32}\.(?:jpe?g|png|webp)$#i', $path)) {
        return '';
    }

    $candidate = STONEFELLOW_ROOT . '/' . ltrim($path, '/');
    if (!is_file($candidate)) {
        return '';
    }

    return $path;
}

function site_logo_url(): string
{
    $path = site_logo_path();
    return $path !== '' ? url($path) : '';
}
