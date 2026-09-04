<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_permission('admin.access');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

function stonefellow_export_name(string $value, string $fallback): string
{
    $value = trim($value);
    $value = preg_replace('/[^\pL\pN._()\- ]+/u', '-', $value) ?: '';
    $value = trim($value, " .-\t\n\r\0\x0B");

    return $value !== ''
        ? substr($value, 0, 180)
        : $fallback;
}

function stonefellow_export_file(
    string $absolute,
    string $downloadName
): never {
    if (!is_file($absolute)) {
        http_response_code(404);
        exit('Audio file is not available.');
    }

    $extension = strtolower(
        pathinfo($absolute, PATHINFO_EXTENSION)
    );

    $mime = match ($extension) {
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        default => '',
    };

    if ($mime === '') {
        http_response_code(415);
        exit('Only MP3 and WAV files can be exported.');
    }

    $size = filesize($absolute);

    if ($size === false || $size < 1) {
        http_response_code(404);
        exit('Audio file is empty.');
    }

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header_remove('Content-Encoding');
    header('Content-Encoding: identity');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($downloadName)
        . '"'
    );

    $handle = fopen($absolute, 'rb');

    if (!$handle) {
        http_response_code(500);
        exit;
    }

    while (!feof($handle)) {
        $chunk = fread($handle, 65536);

        if ($chunk === false || $chunk === '') {
            break;
        }

        echo $chunk;

        if (function_exists('flush')) {
            @flush();
        }

        if (connection_aborted()) {
            break;
        }
    }

    fclose($handle);
    exit;
}

function stonefellow_export_stem_path(array $stem): ?string
{
    $relative = trim(
        (string)($stem['file_path'] ?? '')
    );

    if ($relative === '') {
        return null;
    }

    $stemsRoot = realpath(
        STONEFELLOW_ROOT . '/uploads/stems'
    );
    $absolute = realpath(
        STONEFELLOW_ROOT
        . '/'
        . ltrim($relative, '/')
    );

    if (
        !$stemsRoot ||
        !$absolute ||
        !is_file($absolute)
    ) {
        return null;
    }

    $prefix =
        rtrim(
            $stemsRoot,
            DIRECTORY_SEPARATOR
        )
        . DIRECTORY_SEPARATOR;

    if (!str_starts_with($absolute, $prefix)) {
        return null;
    }

    $extension = strtolower(
        pathinfo(
            $absolute,
            PATHINFO_EXTENSION
        )
    );

    return in_array(
        $extension,
        ['mp3', 'wav'],
        true
    )
        ? $absolute
        : null;
}

function stonefellow_export_master_path(array $track): ?string
{
    $relative = trim(
        (string)($track['audio_path'] ?? '')
    );

    if (
        $relative === '' ||
        preg_match('#^https?://#i', $relative)
    ) {
        return null;
    }

    $root = realpath(STONEFELLOW_ROOT);
    $absolute = realpath(
        STONEFELLOW_ROOT
        . '/'
        . ltrim($relative, '/')
    );

    if (
        !$root ||
        !$absolute ||
        !is_file($absolute)
    ) {
        return null;
    }

    $prefix =
        rtrim(
            $root,
            DIRECTORY_SEPARATOR
        )
        . DIRECTORY_SEPARATOR;

    if (!str_starts_with($absolute, $prefix)) {
        return null;
    }

    $extension = strtolower(
        pathinfo(
            $absolute,
            PATHINFO_EXTENSION
        )
    );

    return in_array(
        $extension,
        ['mp3', 'wav'],
        true
    )
        ? $absolute
        : null;
}

$pdo = db();

if (!$pdo) {
    http_response_code(503);
    exit('Database unavailable.');
}

$stemId = (int)($_GET['stem'] ?? 0);
$trackId = (int)($_GET['track'] ?? 0);
$bundle = !empty($_GET['bundle']);

if ($stemId > 0) {
    $stmt = $pdo->prepare(
        'SELECT *
         FROM track_stems
         WHERE id=?
           AND is_active=1
         LIMIT 1'
    );
    $stmt->execute([$stemId]);
    $stem = $stmt->fetch();

    if (!$stem) {
        http_response_code(404);
        exit('Stem not found.');
    }

    if (
        !can_manage_track_production_id(
            (int)$stem['track_id']
        )
    ) {
        http_response_code(403);
        exit('This stem has not been shared with your account.');
    }

    $absolute =
        stonefellow_export_stem_path(
            $stem
        );

    if (!$absolute) {
        http_response_code(404);
        exit('Exportable stem media is not available.');
    }

    $extension = strtolower(
        pathinfo(
            $absolute,
            PATHINFO_EXTENSION
        )
    );

    $name =
        stonefellow_export_name(
            (string)(
                $stem['stem_name'] ??
                $stem['file_name'] ??
                'track'
            ),
            'track'
        )
        . '.'
        . $extension;

    stonefellow_export_file(
        $absolute,
        $name
    );
}

if ($trackId < 1) {
    http_response_code(400);
    exit('Track is required.');
}

$track = get_track_by_id($trackId);

if (!$track) {
    http_response_code(404);
    exit('Track not found.');
}

if (!can_manage_track_production($track)) {
    http_response_code(403);
    exit('This track has not been shared with your account.');
}

if ($bundle) {
    if (!class_exists('ZipArchive')) {
        http_response_code(501);
        exit(
            'ZIP export is unavailable on this server. '
            . 'Use the individual MP3/WAV download buttons.'
        );
    }

    $files = [];
    $master =
        stonefellow_export_master_path(
            $track
        );

    if ($master) {
        $extension = strtolower(
            pathinfo(
                $master,
                PATHINFO_EXTENSION
            )
        );

        $files[] = [
            'path' => $master,
            'name' =>
                'Master - '
                . stonefellow_export_name(
                    (string)$track['title'],
                    'Stonefellow Track'
                )
                . '.'
                . $extension,
        ];
    }

    $stemStmt = $pdo->prepare(
        'SELECT *
         FROM track_stems
         WHERE track_id=?
           AND is_active=1
         ORDER BY sort_order,id'
    );
    $stemStmt->execute([$trackId]);

    foreach ($stemStmt->fetchAll() as $stem) {
        $absolute =
            stonefellow_export_stem_path(
                $stem
            );

        if (!$absolute) {
            continue;
        }

        $extension = strtolower(
            pathinfo(
                $absolute,
                PATHINFO_EXTENSION
            )
        );

        $files[] = [
            'path' => $absolute,
            'name' =>
                stonefellow_export_name(
                    (string)(
                        $stem['stem_name'] ??
                        'track'
                    ),
                    'track'
                )
                . '.'
                . $extension,
        ];
    }

    if (!$files) {
        http_response_code(404);
        exit('No MP3/WAV files are available for export.');
    }

    $temp = tempnam(
        sys_get_temp_dir(),
        'stonefellow-export-'
    );

    if ($temp === false) {
        http_response_code(500);
        exit('Could not prepare the export.');
    }

    $zipPath = $temp . '.zip';
    @unlink($temp);

    $zip = new ZipArchive();

    if (
        $zip->open(
            $zipPath,
            ZipArchive::CREATE |
            ZipArchive::OVERWRITE
        ) !== true
    ) {
        @unlink($zipPath);
        http_response_code(500);
        exit('Could not create the export package.');
    }

    $used = [];

    foreach ($files as $index => $file) {
        $name = (string)$file['name'];

        if (isset($used[strtolower($name)])) {
            $extension = strtolower(
                pathinfo(
                    $name,
                    PATHINFO_EXTENSION
                )
            );
            $base = pathinfo(
                $name,
                PATHINFO_FILENAME
            );
            $name =
                $base
                . ' '
                . ($index + 1)
                . '.'
                . $extension;
        }

        $used[strtolower($name)] = true;

        $zip->addFile(
            (string)$file['path'],
            $name
        );
    }

    $zip->close();

    $size = filesize($zipPath);

    if ($size === false || $size < 1) {
        @unlink($zipPath);
        http_response_code(500);
        exit('The export package is empty.');
    }

    $downloadName =
        stonefellow_export_name(
            (string)$track['title'],
            'Stonefellow Track'
        )
        . ' - Audio Files.zip';

    @ini_set('zlib.output_compression', '0');

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Content-Type: application/zip');
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($downloadName)
        . '"'
    );

    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

$master =
    stonefellow_export_master_path(
        $track
    );

if (!$master) {
    http_response_code(404);
    exit('Exportable master MP3/WAV media is not available.');
}

$extension = strtolower(
    pathinfo(
        $master,
        PATHINFO_EXTENSION
    )
);

$name =
    stonefellow_export_name(
        (string)$track['title'],
        'Stonefellow Track'
    )
    . ' - Master.'
    . $extension;

stonefellow_export_file(
    $master,
    $name
);
