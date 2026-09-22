<?php
declare(strict_types=1);

const VP3_CHROME_EXTENSION_MAX_ZIP_BYTES = 67108864; // 64 MB

function chrome_extension_releases_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chrome_extension_releases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(64) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'stable',
            release_notes TEXT NOT NULL,
            package_name VARCHAR(255) NOT NULL DEFAULT '',
            package_path VARCHAR(500) NOT NULL DEFAULT '',
            package_sha256 CHAR(64) NOT NULL DEFAULT '',
            package_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            manifest_version TINYINT UNSIGNED NOT NULL DEFAULT 3,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_latest TINYINT(1) NOT NULL DEFAULT 0,
            created_by_user_id INT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_chrome_extension_release_version_channel (version, channel),
            INDEX idx_chrome_extension_release_current (channel, is_published, is_latest, id),
            CONSTRAINT fk_chrome_extension_release_creator
              FOREIGN KEY (created_by_user_id) REFERENCES users(id)
              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function chrome_extension_release_channel_valid(string $channel): bool
{
    return in_array($channel, ['stable', 'beta', 'dev'], true);
}

function chrome_extension_release_version_valid(string $version): bool
{
    return (bool)preg_match('/^\\d+(?:\\.\\d+){0,3}$/', trim($version));
}

function chrome_extension_release_private_dir(): string
{
    $dir = STONEFELLOW_ROOT . '/private/chrome-extension-releases';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create private Chrome Extension release storage.');
    }
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n", LOCK_EX);
    }
    return $dir;
}

function chrome_extension_release_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) {
        return false;
    }
    try {
        return table_exists('chrome_extension_releases');
    } catch (Throwable $e) {
        return false;
    }
}

function chrome_extension_release_validate_zip(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required to verify Chrome Extension packages.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Chrome Extension package was not received.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Chrome Extension package is not a valid ZIP archive.');
    }

    try {
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!is_string($entry) || $entry === '' || str_contains($entry, "\0")
                || str_starts_with($entry, '/') || str_contains($entry, '\\')
                || preg_match('#(^|/)\\.\\.(/|$)#', $entry)) {
                throw new RuntimeException('Chrome Extension package contains an unsafe ZIP path.');
            }
            $stat = $zip->statIndex($i);
            $entrySize = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
            if ($entrySize < 0 || $entrySize > 67108864) {
                throw new RuntimeException('Chrome Extension package contains an oversized file.');
            }
            $totalUncompressed += $entrySize;
            if ($totalUncompressed > 268435456) {
                throw new RuntimeException('Chrome Extension package expands beyond the 256 MB safety limit.');
            }
        }

        $required = [
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
        foreach ($required as $file) {
            if ($zip->locateName($file) === false) {
                throw new RuntimeException('Chrome Extension package is missing required root file: ' . $file);
            }
        }

        $manifestIndex = $zip->locateName('manifest.json');
        $manifestStat = $manifestIndex !== false ? $zip->statIndex($manifestIndex) : false;
        if (!is_array($manifestStat) || (int)($manifestStat['size'] ?? 0) < 2 || (int)($manifestStat['size'] ?? 0) > 1048576) {
            throw new RuntimeException('Chrome Extension manifest.json has an invalid size.');
        }
        $manifestRaw = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('Chrome Extension manifest.json is invalid.');
        }
        $manifestVersion = (int)($manifest['manifest_version'] ?? 0);
        if ($manifestVersion !== 3) {
            throw new RuntimeException('Chrome Extension package must use Chrome Manifest V3.');
        }
        $version = trim((string)($manifest['version'] ?? ''));
        if (!chrome_extension_release_version_valid($version)) {
            throw new RuntimeException('Chrome Extension manifest version is invalid.');
        }
        $name = trim((string)($manifest['name'] ?? ''));
        if ($name !== 'VP3 Browser Companion') {
            throw new RuntimeException('Chrome Extension package is not the VP3 Browser Companion.');
        }

        return [
            'version' => $version,
            'manifest_version' => $manifestVersion,
            'name' => $name,
        ];
    } finally {
        $zip->close();
    }
}

function chrome_extension_release_store_zip(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a Chrome Extension ZIP package.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Chrome Extension package upload failed.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > VP3_CHROME_EXTENSION_MAX_ZIP_BYTES) {
        throw new RuntimeException('Chrome Extension package must be 64 MB or smaller.');
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Only .zip Chrome Extension packages are accepted.');
    }
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Chrome Extension package was not received as a valid HTTP upload.');
    }

    $metadata = chrome_extension_release_validate_zip($tmp);
    $dir = chrome_extension_release_private_dir();
    $safeVersion = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$metadata['version']) ?: 'release';
    $stored = bin2hex(random_bytes(16)) . '-browser-companion-v' . $safeVersion . '.zip';
    $destination = $dir . '/' . $stored;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save Chrome Extension package.');
    }
    @chmod($destination, 0640);

    $hash = hash_file('sha256', $destination);
    $actualSize = filesize($destination);
    if (!is_string($hash) || strlen($hash) !== 64 || !is_int($actualSize) || $actualSize < 1) {
        @unlink($destination);
        throw new RuntimeException('Could not verify saved Chrome Extension package.');
    }

    return [
        'version' => (string)$metadata['version'],
        'manifest_version' => (int)$metadata['manifest_version'],
        'name' => 'vp3-browser-companion-v' . (string)$metadata['version'] . '.zip',
        'path' => $destination,
        'sha256' => $hash,
        'size' => $actualSize,
    ];
}

function chrome_extension_release_create(array $input, array $files, int $createdBy): int
{
    $channel = strtolower(trim((string)($input['channel'] ?? 'stable')));
    if (!chrome_extension_release_channel_valid($channel)) {
        throw new RuntimeException('Choose a valid Chrome Extension release channel.');
    }

    $stored = chrome_extension_release_store_zip($files['chrome_zip'] ?? []);
    $pdo = db();
    if (!$pdo) {
        @unlink((string)$stored['path']);
        throw new RuntimeException('Database connection is unavailable.');
    }
    chrome_extension_releases_ensure_schema($pdo);

    $published = !empty($input['is_published']) ? 1 : 0;
    $latest = $published && !empty($input['is_latest']) ? 1 : 0;

    try {
        $pdo->beginTransaction();
        if ($latest) {
            $pdo->prepare('UPDATE chrome_extension_releases SET is_latest=0 WHERE channel=?')->execute([$channel]);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO chrome_extension_releases (
                version,channel,release_notes,package_name,package_path,package_sha256,package_size,
                manifest_version,is_published,is_latest,created_by_user_id,published_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL))"
        );
        $stmt->execute([
            (string)$stored['version'],
            $channel,
            trim((string)($input['release_notes'] ?? '')),
            (string)$stored['name'],
            (string)$stored['path'],
            (string)$stored['sha256'],
            (int)$stored['size'],
            (int)$stored['manifest_version'],
            $published,
            $latest,
            $createdBy,
            $published,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink((string)$stored['path']);
        throw $e;
    }
}

function chrome_extension_release_set_state(int $releaseId, string $action): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    chrome_extension_releases_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM chrome_extension_releases WHERE id=? LIMIT 1');
    $stmt->execute([$releaseId]);
    $release = $stmt->fetch();
    if (!$release) {
        throw new RuntimeException('Chrome Extension release was not found.');
    }

    if ($action === 'publish') {
        $pdo->prepare('UPDATE chrome_extension_releases SET is_published=1,published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([$releaseId]);
        return;
    }
    if ($action === 'unpublish') {
        $pdo->prepare('UPDATE chrome_extension_releases SET is_published=0,is_latest=0 WHERE id=?')->execute([$releaseId]);
        return;
    }
    if ($action === 'latest') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE chrome_extension_releases SET is_latest=0 WHERE channel=?')->execute([(string)$release['channel']]);
            $pdo->prepare('UPDATE chrome_extension_releases SET is_published=1,is_latest=1,published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([$releaseId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }
    throw new RuntimeException('Unsupported Chrome Extension release action.');
}

function chrome_extension_release_delete(int $releaseId): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    chrome_extension_releases_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT package_path FROM chrome_extension_releases WHERE id=? LIMIT 1');
    $stmt->execute([$releaseId]);
    $release = $stmt->fetch();
    if (!$release) {
        return;
    }

    $pdo->prepare('DELETE FROM chrome_extension_releases WHERE id=?')->execute([$releaseId]);
    $base = realpath(chrome_extension_release_private_dir());
    $path = (string)($release['package_path'] ?? '');
    $real = $path !== '' && is_file($path) ? realpath($path) : false;
    if ($base && $real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        @unlink($real);
    }
}

function chrome_extension_releases(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    chrome_extension_releases_ensure_schema($pdo);
    return $pdo->query('SELECT * FROM chrome_extension_releases ORDER BY created_at DESC,id DESC')->fetchAll();
}

function chrome_extension_latest_release(string $channel = 'stable'): ?array
{
    if (!chrome_extension_release_channel_valid($channel)) {
        $channel = 'stable';
    }
    $pdo = db();
    if (!$pdo || !chrome_extension_release_schema_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM chrome_extension_releases WHERE channel=? AND is_published=1 ORDER BY is_latest DESC,published_at DESC,id DESC LIMIT 1'
    );
    $stmt->execute([$channel]);
    $row = $stmt->fetch();
    return $row ?: null;
}
