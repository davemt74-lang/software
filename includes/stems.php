<?php
declare(strict_types=1);

function stem_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function stem_cut(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function stem_max_package_bytes(): int
{
    global $config;
    return max(
        64 * 1024 * 1024,
        (int)($config['uploads']['max_stem_package_bytes'] ?? (2 * 1024 * 1024 * 1024))
    );
}

function stem_chunk_bytes(): int
{
    global $config;
    return max(
        1024 * 1024,
        min(
            16 * 1024 * 1024,
            (int)($config['uploads']['stem_chunk_bytes'] ?? (8 * 1024 * 1024))
        )
    );
}

function stem_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\pL\pN._()\- &]+/u', '-', $name) ?: 'file';
    $name = trim($name, " .-\t\n\r\0\x0B");
    return stem_cut($name !== '' ? $name : 'file', 220);
}

function stem_project_for_track(int $trackId): ?array
{
    $pdo = db();
    if (!$pdo || $trackId < 1 || !table_exists('track_projects')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM track_stems s WHERE s.track_id=p.track_id AND s.is_active=1) AS stem_count
             FROM track_projects p
             WHERE p.track_id=? LIMIT 1'
        );
        $stmt->execute([$trackId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function stems_for_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1 || !table_exists('track_stems')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM track_stems
             WHERE track_id=? AND is_active=1
             ORDER BY sort_order,id'
        );
        $stmt->execute([$trackId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function stem_format_duration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $minutes = intdiv($seconds, 60);
    return $minutes . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function stem_role_from_metadata(string $name, string $fxSummary = ''): string
{
    $text = stem_lower($name . ' ' . $fxSummary);

    if (str_contains($text, 'vocal') || str_contains($text, 'voice') || str_contains($text, 'vox')) {
        return 'Vocal';
    }
    if (
        str_contains($text, 'drum') ||
        str_contains($text, 'kick') ||
        str_contains($text, 'snare') ||
        str_contains($text, 'clap')
    ) {
        return 'Drums';
    }
    if (
        str_contains($text, 'shaker') ||
        str_contains($text, 'percussion') ||
        str_contains($text, 'tambourine')
    ) {
        return 'Percussion';
    }
    if (str_contains($text, 'bass')) {
        return 'Bass';
    }
    if (str_contains($text, 'guitar') || str_contains($text, 'powerchord')) {
        return 'Guitar';
    }
    if (
        str_contains($text, 'piano') ||
        str_contains($text, 'keys') ||
        str_contains($text, 'keyboard')
    ) {
        return 'Keys';
    }
    if (str_contains($text, 'synth') || str_contains($text, 'pad')) {
        return 'Synth';
    }

    return 'Other';
}

function stem_parse_rpp(string $text, string $fileName = ''): array
{
    $result = [
        'project_name' => preg_replace('/\.rpp$/i', '', basename($fileName)) ?: 'REAPER Project',
        'tempo_bpm' => null,
        'time_signature' => '',
        'project_sample_rate' => null,
        'tracks' => [],
        'file_map' => [],
    ];

    if (preg_match('/^\s*TEMPO\s+([0-9.]+)\s+(\d+)\s+(\d+)/m', $text, $match)) {
        $result['tempo_bpm'] = (float)$match[1];
        $result['time_signature'] = (int)$match[2] . '/' . (int)$match[3];
    }

    if (preg_match('/^\s*SAMPLERATE\s+(\d+)/m', $text, $match)) {
        $result['project_sample_rate'] = (int)$match[1];
    }

    $parts = preg_split('/(?=^  <TRACK\s)/m', $text) ?: [];

    foreach ($parts as $part) {
        if (!str_starts_with($part, '  <TRACK ')) {
            continue;
        }

        $trackName = '';
        if (preg_match('/^\s{4}NAME\s+"([^"]*)"/m', $part, $match)) {
            $trackName = trim($match[1]);
        } elseif (preg_match('/^\s{4}NAME\s+([^\r\n]*)/m', $part, $match)) {
            $trackName = trim(trim($match[1]), '"');
        }

        $trackGuid = '';
        if (preg_match('/^\s{4}TRACKID\s+(\{[^}]+\})/m', $part, $match)) {
            $trackGuid = $match[1];
        }

        $volume = 1.0;
        $pan = 0.0;
        if (preg_match('/^\s{4}VOLPAN\s+([-0-9.eE]+)\s+([-0-9.eE]+)/m', $part, $match)) {
            $volume = (float)$match[1];
            $pan = max(-1.0, min(1.0, (float)$match[2]));
        }

        $presets = [];
        if (preg_match_all('/^\s+PRESETNAME\s+"([^"]+)"/m', $part, $matches)) {
            $presets = array_values(array_unique(array_map('trim', $matches[1])));
        }
        $fxSummary = implode(', ', $presets);

        $files = [];
        if (preg_match_all('/<ITEM\b(.*?)^\s{4}>/ms', $part, $itemMatches)) {
            foreach ($itemMatches[1] as $itemBlock) {
                if (!preg_match('/^\s+FILE\s+"([^"]+)"/m', $itemBlock, $fileMatch)) {
                    continue;
                }

                $sourceFile = basename(str_replace('\\', '/', $fileMatch[1]));
                if ($sourceFile === '') {
                    continue;
                }

                $position = 0.0;
                $length = 0.0;

                if (preg_match('/^\s+POSITION\s+([-0-9.eE]+)/m', $itemBlock, $m)) {
                    $position = (float)$m[1];
                }
                if (preg_match('/^\s+LENGTH\s+([-0-9.eE]+)/m', $itemBlock, $m)) {
                    $length = (float)$m[1];
                }

                $files[$sourceFile] = [
                    'position' => $position,
                    'length' => $length,
                ];
            }
        }

        if (!$files && preg_match_all('/^\s+FILE\s+"([^"]+)"/m', $part, $fileMatches)) {
            foreach ($fileMatches[1] as $source) {
                $sourceFile = basename(str_replace('\\', '/', $source));
                if ($sourceFile !== '') {
                    $files[$sourceFile] = ['position' => 0.0, 'length' => 0.0];
                }
            }
        }

        $track = [
            'name' => $trackName,
            'guid' => $trackGuid,
            'volume' => $volume,
            'pan' => $pan,
            'fx_summary' => $fxSummary,
            'files' => $files,
        ];

        $result['tracks'][] = $track;

        foreach ($files as $sourceFile => $item) {
            $result['file_map'][stem_lower($sourceFile)] = [
                'track_name' => $trackName,
                'track_guid' => $trackGuid,
                'volume' => $volume,
                'pan' => $pan,
                'fx_summary' => $fxSummary,
                'position' => (float)$item['position'],
                'length' => (float)$item['length'],
            ];
        }
    }

    return $result;
}

function stem_wav_info(string $path): array
{
    $result = [
        'channels' => 0,
        'sample_rate' => 0,
        'bit_depth' => 0,
        'duration_seconds' => 0.0,
        'data_bytes' => 0,
    ];

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return $result;
    }

    $header = fread($handle, 12);
    if (!is_string($header) || strlen($header) < 12 || substr($header, 8, 4) !== 'WAVE') {
        fclose($handle);
        return $result;
    }

    $fmtFound = false;
    $dataFound = false;
    $byteRate = 0;

    while (!feof($handle)) {
        $chunkHeader = fread($handle, 8);
        if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
            break;
        }

        $chunkId = substr($chunkHeader, 0, 4);
        $sizeData = unpack('Vsize', substr($chunkHeader, 4, 4));
        $chunkSize = (int)($sizeData['size'] ?? 0);

        if ($chunkId === 'fmt ') {
            $fmt = fread($handle, min($chunkSize, 128));
            if (is_string($fmt) && strlen($fmt) >= 16) {
                $channels = unpack('vvalue', substr($fmt, 2, 2));
                $sampleRate = unpack('Vvalue', substr($fmt, 4, 4));
                $byteRateData = unpack('Vvalue', substr($fmt, 8, 4));
                $bits = unpack('vvalue', substr($fmt, 14, 2));

                $result['channels'] = (int)($channels['value'] ?? 0);
                $result['sample_rate'] = (int)($sampleRate['value'] ?? 0);
                $result['bit_depth'] = (int)($bits['value'] ?? 0);
                $byteRate = (int)($byteRateData['value'] ?? 0);
                $fmtFound = true;
            }

            $consumed = is_string($fmt) ? strlen($fmt) : 0;
            if ($chunkSize > $consumed) {
                fseek($handle, $chunkSize - $consumed, SEEK_CUR);
            }
        } elseif ($chunkId === 'data') {
            $result['data_bytes'] = $chunkSize;
            $dataFound = true;
            fseek($handle, $chunkSize, SEEK_CUR);
        } else {
            fseek($handle, $chunkSize, SEEK_CUR);
        }

        if ($chunkSize % 2 === 1) {
            fseek($handle, 1, SEEK_CUR);
        }

        if ($fmtFound && $dataFound) {
            break;
        }
    }

    fclose($handle);

    if ($byteRate > 0 && $result['data_bytes'] > 0) {
        $result['duration_seconds'] = $result['data_bytes'] / $byteRate;
    }

    return $result;
}


function stem_parse_duration_string(string $value): float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }

    if (preg_match('/^(\d+):(\d{1,2})(?:\.(\d+))?$/', $value, $m)) {
        $seconds = ((int)$m[1] * 60) + (int)$m[2];
        if (!empty($m[3])) {
            $seconds += (float)('0.' . $m[3]);
        }
        return (float)$seconds;
    }

    return is_numeric($value) ? max(0.0, (float)$value) : 0.0;
}

function stem_normalized_source_key(string $fileName): string
{
    $base = stem_lower(basename(str_replace('\\', '/', $fileName)));
    $base = preg_replace('/\.(wav|mp3)$/i', '', $base) ?: $base;
    $base = preg_replace('/-consolidated$/i', '', $base) ?: $base;
    return trim($base);
}

function stem_match_rpp_file(array $fileMap, string $sourceBase): ?array
{
    $exact = $fileMap[stem_lower($sourceBase)] ?? null;
    if (is_array($exact)) {
        return $exact;
    }

    $needle = stem_normalized_source_key($sourceBase);

    foreach ($fileMap as $fileName => $meta) {
        if (stem_normalized_source_key((string)$fileName) === $needle && is_array($meta)) {
            return $meta;
        }
    }

    return null;
}

function stem_audio_info(string $path, ?array $rppMap = null, float $fallbackDuration = 0.0): array
{
    $extension = stem_lower(pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === 'wav') {
        $info = stem_wav_info($path);
        $info['format'] = 'WAV';
        return $info;
    }

    $result = [
        'channels' => 0,
        'sample_rate' => 0,
        'bit_depth' => 0,
        'duration_seconds' => 0.0,
        'data_bytes' => filesize($path) ?: 0,
        'format' => strtoupper($extension ?: 'AUDIO'),
    ];

    // Prefer ffprobe when a host provides it, but do not require it.
    $ffprobe = '';
    if (function_exists('shell_exec')) {
        $candidate = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
        if ($candidate !== '' && is_executable($candidate)) {
            $ffprobe = $candidate;
        }
    }

    if ($ffprobe !== '') {
        $command = escapeshellarg($ffprobe)
            . ' -v error -select_streams a:0'
            . ' -show_entries stream=channels,sample_rate'
            . ' -show_entries format=duration'
            . ' -of json '
            . escapeshellarg($path)
            . ' 2>/dev/null';

        $decoded = json_decode((string)@shell_exec($command), true);

        if (is_array($decoded)) {
            $stream = $decoded['streams'][0] ?? [];
            $format = $decoded['format'] ?? [];

            $result['channels'] = (int)($stream['channels'] ?? 0);
            $result['sample_rate'] = (int)($stream['sample_rate'] ?? 0);
            $result['duration_seconds'] = (float)($format['duration'] ?? 0);
        }
    }

    if ($result['duration_seconds'] <= 0 && is_array($rppMap)) {
        $result['duration_seconds'] = max(0.0, (float)($rppMap['length'] ?? 0));
    }

    if ($result['duration_seconds'] <= 0) {
        $result['duration_seconds'] = max(0.0, $fallbackDuration);
    }

    return $result;
}


function stem_php_function_enabled(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }

    $disabled = array_filter(array_map(
        'trim',
        explode(',', (string)ini_get('disable_functions'))
    ));

    return !in_array($name, $disabled, true);
}


function stem_native_zip_supported(): bool
{
    return function_exists('inflate_init')
        && function_exists('inflate_add')
        && defined('ZLIB_ENCODING_RAW');
}

function stem_native_zip_eocd(string $zipPath): array
{
    $size = filesize($zipPath);

    if ($size === false || $size < 22) {
        throw new RuntimeException('The uploaded ZIP is too small to be valid.');
    }

    $handle = fopen($zipPath, 'rb');

    if (!$handle) {
        throw new RuntimeException('Could not open the uploaded ZIP.');
    }

    try {
        // EOCD must be within the final 65,557 bytes for a standard ZIP.
        $window = min($size, 65557);
        fseek($handle, $size - $window);
        $tail = (string)fread($handle, $window);

        $position = strrpos($tail, "\x50\x4b\x05\x06");

        if ($position === false || strlen($tail) - $position < 22) {
            throw new RuntimeException(
                'Could not locate the ZIP central directory.'
            );
        }

        $record = substr($tail, $position, 22);
        $eocd = unpack(
            'Vsignature/'
            . 'vdisk_number/'
            . 'vcentral_disk/'
            . 'ventries_disk/'
            . 'ventries_total/'
            . 'Vcentral_size/'
            . 'Vcentral_offset/'
            . 'vcomment_length',
            $record
        );

        if (
            !$eocd ||
            (int)$eocd['signature'] !== 0x06054b50
        ) {
            throw new RuntimeException('The ZIP end record is invalid.');
        }

        if (
            (int)$eocd['disk_number'] !== 0 ||
            (int)$eocd['central_disk'] !== 0
        ) {
            throw new RuntimeException(
                'Multi-volume ZIP archives are not supported.'
            );
        }

        if (
            (int)$eocd['entries_total'] === 0xffff ||
            (int)$eocd['central_offset'] === 0xffffffff ||
            (int)$eocd['central_size'] === 0xffffffff
        ) {
            throw new RuntimeException(
                'ZIP64 archives are not supported by the native shared-host reader.'
            );
        }

        return $eocd;
    } finally {
        fclose($handle);
    }
}

function stem_native_zip_entries(string $zipPath): array
{
    $eocd = stem_native_zip_eocd($zipPath);
    $handle = fopen($zipPath, 'rb');

    if (!$handle) {
        throw new RuntimeException('Could not open the ZIP central directory.');
    }

    try {
        fseek($handle, (int)$eocd['central_offset']);

        $entries = [];
        $total = (int)$eocd['entries_total'];

        if ($total > 2500) {
            throw new RuntimeException(
                'The REAPER package contains too many files.'
            );
        }

        for ($index = 0; $index < $total; $index++) {
            $header = (string)fread($handle, 46);

            if (strlen($header) !== 46) {
                throw new RuntimeException(
                    'The ZIP central directory ended unexpectedly.'
                );
            }

            $meta = unpack(
                'Vsignature/'
                . 'vversion_made/'
                . 'vversion_needed/'
                . 'vflags/'
                . 'vmethod/'
                . 'vmod_time/'
                . 'vmod_date/'
                . 'Vcrc/'
                . 'Vcompressed_size/'
                . 'Vuncompressed_size/'
                . 'vname_length/'
                . 'vextra_length/'
                . 'vcomment_length/'
                . 'vdisk_start/'
                . 'vinternal_attributes/'
                . 'Vexternal_attributes/'
                . 'Vlocal_offset',
                $header
            );

            if (
                !$meta ||
                (int)$meta['signature'] !== 0x02014b50
            ) {
                throw new RuntimeException(
                    'The ZIP contains an invalid central-directory entry.'
                );
            }

            $nameLength = (int)$meta['name_length'];
            $extraLength = (int)$meta['extra_length'];
            $commentLength = (int)$meta['comment_length'];

            $name = $nameLength > 0
                ? (string)fread($handle, $nameLength)
                : '';

            if ($extraLength > 0) {
                fseek($handle, $extraLength, SEEK_CUR);
            }

            if ($commentLength > 0) {
                fseek($handle, $commentLength, SEEK_CUR);
            }

            if (
                $name === '' ||
                str_ends_with($name, '/')
            ) {
                continue;
            }

            if (!stem_zip_entry_is_safe($name)) {
                throw new RuntimeException(
                    'The ZIP contains an unsafe file path.'
                );
            }

            $method = (int)$meta['method'];

            if (!in_array($method, [0, 8], true)) {
                // Keep listing the file, but extraction will explain the
                // unsupported compression method if that file is selected.
            }

            $entries[] = [
                'name'=>$name,
                'size'=>(int)$meta['uncompressed_size'],
                'compressed_size'=>(int)$meta['compressed_size'],
                'method'=>$method,
                'flags'=>(int)$meta['flags'],
                'crc'=>(int)$meta['crc'],
                'local_offset'=>(int)$meta['local_offset'],
            ];
        }

        return $entries;
    } finally {
        fclose($handle);
    }
}

function stem_native_zip_find_entry(
    string $zipPath,
    string $entryName
): ?array {
    foreach (stem_native_zip_entries($zipPath) as $entry) {
        if ((string)$entry['name'] === $entryName) {
            return $entry;
        }
    }

    return null;
}

function stem_native_zip_extract(
    string $zipPath,
    array $entry,
    string $destination
): void {
    $method = (int)($entry['method'] ?? -1);

    if (!in_array($method, [0, 8], true)) {
        throw new RuntimeException(
            'ZIP compression method ' . $method
            . ' is not supported for "' . basename((string)$entry['name']) . '".'
        );
    }

    $handle = fopen($zipPath, 'rb');

    if (!$handle) {
        throw new RuntimeException('Could not open the ZIP for extraction.');
    }

    $output = null;

    try {
        fseek($handle, (int)$entry['local_offset']);
        $localHeader = (string)fread($handle, 30);

        if (strlen($localHeader) !== 30) {
            throw new RuntimeException(
                'The ZIP local file header is incomplete.'
            );
        }

        $local = unpack(
            'Vsignature/'
            . 'vversion/'
            . 'vflags/'
            . 'vmethod/'
            . 'vmod_time/'
            . 'vmod_date/'
            . 'Vcrc/'
            . 'Vcompressed_size/'
            . 'Vuncompressed_size/'
            . 'vname_length/'
            . 'vextra_length',
            $localHeader
        );

        if (
            !$local ||
            (int)$local['signature'] !== 0x04034b50
        ) {
            throw new RuntimeException(
                'The ZIP local file header is invalid.'
            );
        }

        $nameLength = (int)$local['name_length'];
        $extraLength = (int)$local['extra_length'];

        if ($nameLength > 0) {
            fseek($handle, $nameLength, SEEK_CUR);
        }

        if ($extraLength > 0) {
            fseek($handle, $extraLength, SEEK_CUR);
        }

        $parent = dirname($destination);

        if (
            !is_dir($parent) &&
            !mkdir($parent, 0755, true) &&
            !is_dir($parent)
        ) {
            throw new RuntimeException(
                'Could not create the extraction directory.'
            );
        }

        $output = fopen($destination, 'wb');

        if (!$output) {
            throw new RuntimeException(
                'Could not create the extracted file.'
            );
        }

        $compressedRemaining = (int)$entry['compressed_size'];
        $written = 0;
        $crcContext = hash_init('crc32b');

        if ($method === 0) {
            while ($compressedRemaining > 0) {
                $chunk = fread(
                    $handle,
                    min(65536, $compressedRemaining)
                );

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException(
                        'The stored ZIP entry ended unexpectedly.'
                    );
                }

                $compressedRemaining -= strlen($chunk);
                $written += strlen($chunk);
                hash_update($crcContext, $chunk);

                if (fwrite($output, $chunk) === false) {
                    throw new RuntimeException(
                        'Could not write the extracted file.'
                    );
                }
            }
        } else {
            $inflate = inflate_init(ZLIB_ENCODING_RAW);

            if ($inflate === false) {
                throw new RuntimeException(
                    'PHP could not initialize the native ZIP inflater.'
                );
            }

            while ($compressedRemaining > 0) {
                $readLength = min(65536, $compressedRemaining);
                $chunk = fread($handle, $readLength);

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException(
                        'The deflated ZIP entry ended unexpectedly.'
                    );
                }

                $compressedRemaining -= strlen($chunk);
                $flush = $compressedRemaining === 0
                    ? ZLIB_FINISH
                    : ZLIB_SYNC_FLUSH;

                $decoded = inflate_add(
                    $inflate,
                    $chunk,
                    $flush
                );

                if ($decoded === false) {
                    throw new RuntimeException(
                        'PHP could not decompress "' .
                        basename((string)$entry['name']) . '".'
                    );
                }

                if ($decoded !== '') {
                    $written += strlen($decoded);
                    hash_update($crcContext, $decoded);

                    if (fwrite($output, $decoded) === false) {
                        throw new RuntimeException(
                            'Could not write the extracted stem.'
                        );
                    }
                }
            }
        }

        fflush($output);

        $expectedSize = (int)$entry['size'];

        if ($expectedSize > 0 && $written !== $expectedSize) {
            throw new RuntimeException(
                'Extracted size mismatch for "' .
                basename((string)$entry['name']) . '".'
            );
        }

        $actualCrc = strtolower(hash_final($crcContext));
        $expectedCrc = strtolower(
            str_pad(
                dechex((int)$entry['crc']),
                8,
                '0',
                STR_PAD_LEFT
            )
        );

        if (
            $expectedCrc !== '00000000' &&
            $actualCrc !== $expectedCrc
        ) {
            throw new RuntimeException(
                'CRC verification failed for "' .
                basename((string)$entry['name']) . '".'
            );
        }
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }

        fclose($handle);
    }
}

function stem_cli_unzip_path(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    foreach (['/usr/bin/unzip', '/bin/unzip', '/usr/local/bin/unzip'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $resolved = $candidate;
        }
    }

    if (stem_php_function_enabled('shell_exec')) {
        $candidate = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $resolved = $candidate;
        }
    }

    return $resolved = '';
}

function stem_zip_backend(): string
{
    // v23 default: no exec(), PharData or ZipArchive dependency.
    // The native reader parses the standard ZIP central directory and
    // streams Store/Deflate entries with PHP's zlib extension.
    if (stem_native_zip_supported()) {
        return 'php-native';
    }

    if (stem_cli_unzip_path() !== '' && stem_php_function_enabled('exec')) {
        return 'cli-unzip';
    }

    if (class_exists('PharData')) {
        return 'phardata';
    }

    if (class_exists('ZipArchive')) {
        return 'ziparchive';
    }

    return '';
}

function stem_zip_entry_is_safe(string $entry): bool
{
    return $entry !== ''
        && !str_contains($entry, "\0")
        && !str_starts_with($entry, '/')
        && !str_starts_with($entry, '\\')
        && !preg_match('#(^|[\\/])\.\.([\\/]|$)#', $entry);
}

function stem_zip_list_entries(string $zipPath): array
{
    if (!is_file($zipPath)) {
        throw new RuntimeException('The uploaded ZIP file is missing.');
    }

    $backend = stem_zip_backend();

    if ($backend === 'php-native') {
        return stem_native_zip_entries($zipPath);
    }

    if ($backend === 'cli-unzip') {
        $output = [];
        $status = 0;
        $command = escapeshellarg(stem_cli_unzip_path())
            . ' -Z1 '
            . escapeshellarg($zipPath)
            . ' 2>/dev/null';

        @exec($command, $output, $status);

        if ($status !== 0) {
            throw new RuntimeException(
                'The server unzip utility could not inspect this ZIP package.'
            );
        }

        $entries = [];

        foreach ($output as $line) {
            $entry = rtrim((string)$line, "\r\n");

            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }

            if (!stem_zip_entry_is_safe($entry)) {
                throw new RuntimeException('The ZIP contains an unsafe file path.');
            }

            $entries[] = [
                'name'=>$entry,
                'size'=>0,
            ];
        }

        return $entries;
    }

    if ($backend === 'ziparchive') {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a readable ZIP archive.');
        }

        try {
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if (!$stat) {
                    continue;
                }

                $entry = (string)$stat['name'];

                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }

                if (!stem_zip_entry_is_safe($entry)) {
                    throw new RuntimeException('The ZIP contains an unsafe file path.');
                }

                $entries[] = [
                    'name'=>$entry,
                    'size'=>(int)($stat['size'] ?? 0),
                ];
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    if ($backend === 'phardata') {
        try {
            $phar = new PharData($zipPath);
            $entries = [];
            $prefix = 'phar://' . str_replace('\\', '/', $zipPath) . '/';

            $iterator = new RecursiveIteratorIterator($phar);

            foreach ($iterator as $path=>$file) {
                if ($file->isDir()) {
                    continue;
                }

                $normalized = str_replace('\\', '/', (string)$path);
                $entry = str_starts_with($normalized, $prefix)
                    ? substr($normalized, strlen($prefix))
                    : basename($normalized);

                if (!stem_zip_entry_is_safe($entry)) {
                    throw new RuntimeException('The ZIP contains an unsafe file path.');
                }

                $entries[] = [
                    'name'=>$entry,
                    'size'=>(int)$file->getSize(),
                ];
            }

            return $entries;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'The server could not inspect the ZIP package: ' . $e->getMessage()
            );
        }
    }

    throw new RuntimeException(
        'No supported ZIP extraction backend is available on this server.'
    );
}

function stem_zip_extract_entry(
    string $zipPath,
    string $entry,
    string $destination
): void {
    if (!stem_zip_entry_is_safe($entry)) {
        throw new RuntimeException('Unsafe ZIP entry path.');
    }

    $parent = dirname($destination);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        throw new RuntimeException('Could not create an extraction directory.');
    }

    $backend = stem_zip_backend();

    if ($backend === 'php-native') {
        $meta = stem_native_zip_find_entry(
            $zipPath,
            $entry
        );

        if (!$meta) {
            throw new RuntimeException(
                'Could not find "' . basename($entry) . '" in the uploaded ZIP.'
            );
        }

        stem_native_zip_extract(
            $zipPath,
            $meta,
            $destination
        );

        return;
    }

    if ($backend === 'cli-unzip') {
        $output = [];
        $status = 0;

        $command = escapeshellarg(stem_cli_unzip_path())
            . ' -p '
            . escapeshellarg($zipPath)
            . ' '
            . escapeshellarg($entry)
            . ' > '
            . escapeshellarg($destination)
            . ' 2>/dev/null';

        @exec($command, $output, $status);

        if ($status !== 0 || !is_file($destination)) {
            @unlink($destination);
            throw new RuntimeException(
                'The server unzip utility could not extract "' . basename($entry) . '".'
            );
        }

        return;
    }

    if ($backend === 'ziparchive') {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not reopen the uploaded ZIP.');
        }

        try {
            $input = $zip->getStream($entry);

            if (!$input) {
                throw new RuntimeException(
                    'Could not read "' . basename($entry) . '" from the ZIP.'
                );
            }

            $output = fopen($destination, 'wb');

            if (!$output) {
                fclose($input);
                throw new RuntimeException('Could not create an extracted file.');
            }

            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Could not extract the ZIP entry.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            return;
        } finally {
            $zip->close();
        }
    }

    if ($backend === 'phardata') {
        $source = 'phar://' . $zipPath . '/' . $entry;
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'wb');

        if (!$input || !$output) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($destination);
            throw new RuntimeException(
                'Could not extract "' . basename($entry) . '" with PharData.'
            );
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Could not extract the ZIP entry.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return;
    }

    throw new RuntimeException(
        'No supported ZIP extraction backend is available on this server.'
    );
}

function stem_copy_zip_entry(ZipArchive $zip, int $index, string $destination): void
{
    $entry = (string)$zip->getNameIndex($index);
    $input = $zip->getStream($entry);
    if (!$input) {
        throw new RuntimeException('Could not read an item from the REAPER ZIP.');
    }

    $output = fopen($destination, 'wb');
    if (!$output) {
        fclose($input);
        throw new RuntimeException('Could not create a stem file.');
    }

    stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
}

function stem_delete_path_if_local(?string $relative): void
{
    $relative = trim((string)$relative);
    if (
        $relative === '' ||
        (!str_starts_with($relative, '/uploads/stems/') &&
         !str_starts_with($relative, '/uploads/projects/'))
    ) {
        return;
    }

    $base = realpath(STONEFELLOW_ROOT . '/uploads');
    $candidate = realpath(STONEFELLOW_ROOT . '/' . ltrim($relative, '/'));

    if (
        $base &&
        $candidate &&
        is_file($candidate) &&
        str_starts_with($candidate, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        @unlink($candidate);
    }
}

function stem_cleanup_empty_parent(string $relative): void
{
    $absolute = STONEFELLOW_ROOT . '/' . ltrim($relative, '/');
    $dir = dirname($absolute);

    for ($i = 0; $i < 3; $i++) {
        if (!is_dir($dir)) {
            break;
        }

        $items = array_values(array_diff(scandir($dir) ?: [], ['.','..']));
        if ($items) {
            break;
        }

        @rmdir($dir);
        $dir = dirname($dir);
    }
}

function stem_import_reaper_zip(
    int $trackId,
    string $zipPath,
    string $originalName,
    int $userId
): array {
    $pdo = db();

    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    if ($trackId < 1) {
        throw new RuntimeException('Select a valid track.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required for REAPER project imports.');
    }
    if (!is_file($zipPath)) {
        throw new RuntimeException('Uploaded REAPER package was not found.');
    }

    $packageSize = filesize($zipPath) ?: 0;
    if ($packageSize < 1 || $packageSize > stem_max_package_bytes()) {
        throw new RuntimeException('The REAPER package is empty or larger than the configured limit.');
    }

    $trackStmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $trackStmt->execute([$trackId]);
    $track = $trackStmt->fetch();
    if (!$track) {
        throw new RuntimeException('Track not found.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('The uploaded file is not a readable ZIP archive.');
    }

    if ($zip->numFiles > 2500) {
        $zip->close();
        throw new RuntimeException('The REAPER package contains too many files.');
    }

    $rppEntries = [];
    $wavEntries = [];
    $mp3Entries = [];
    $consolidatedEntries = [];
    $totalUncompressed = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) {
            continue;
        }

        $entryName = (string)$stat['name'];
        if (
            str_contains($entryName, "\0") ||
            str_starts_with($entryName, '/') ||
            str_starts_with($entryName, '\\') ||
            preg_match('#(^|[\\/])\.\.([\\/]|$)#', $entryName)
        ) {
            $zip->close();
            throw new RuntimeException('The ZIP contains an unsafe file path.');
        }

        $totalUncompressed += max(0, (int)($stat['size'] ?? 0));
        if ($totalUncompressed > stem_max_package_bytes() * 3) {
            $zip->close();
            throw new RuntimeException('The expanded REAPER package is larger than the configured safety limit.');
        }

        $lower = stem_lower($entryName);

        if (str_ends_with($lower, '.rpp')) {
            $rppEntries[] = $i;
        }
        if (str_ends_with($lower, '.wav')) {
            $wavEntries[] = $i;
            if (str_contains(stem_lower(basename($entryName)), 'consolidated')) {
                $consolidatedEntries[] = $i;
            }
        }
    }

    $selectedWavs = $consolidatedEntries ?: $wavEntries;

    if (!$selectedWavs) {
        $zip->close();
        throw new RuntimeException('No WAV stem files were found in the REAPER package.');
    }
    if (count($selectedWavs) > 96) {
        $zip->close();
        throw new RuntimeException('This package has more than 96 candidate stems. Consolidate the project before importing.');
    }

    $rppName = '';
    $rppInfo = [
        'project_name' => preg_replace('/\.zip$/i', '', stem_clean_filename($originalName)),
        'tempo_bpm' => null,
        'time_signature' => '',
        'project_sample_rate' => null,
        'tracks' => [],
        'file_map' => [],
    ];

    if ($rppEntries) {
        $rppName = stem_clean_filename((string)$zip->getNameIndex($rppEntries[0]));
        $rppText = (string)$zip->getFromIndex($rppEntries[0], 12 * 1024 * 1024);
        if ($rppText !== '') {
            $rppInfo = stem_parse_rpp($rppText, $rppName);
        }
    }

    $importToken = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
    $stemDir = STONEFELLOW_ROOT . '/uploads/stems/track-' . $trackId . '/' . $importToken;
    $projectDir = STONEFELLOW_ROOT . '/uploads/projects/track-' . $trackId;

    if (!is_dir($stemDir) && !mkdir($stemDir, 0755, true) && !is_dir($stemDir)) {
        $zip->close();
        throw new RuntimeException('Could not create the stem storage directory.');
    }
    if (!is_dir($projectDir) && !mkdir($projectDir, 0755, true) && !is_dir($projectDir)) {
        $zip->close();
        throw new RuntimeException('Could not create the project storage directory.');
    }

    $newPaths = [];
    $stemRows = [];
    $mediaSampleRates = [];
    $positions = [];
    $maxEnd = 0.0;
    $projectPath = '';

    try {
        if ($rppEntries && $rppName !== '') {
            $projectFile = $importToken . '-' . $rppName;
            $projectAbsolute = $projectDir . '/' . $projectFile;
            stem_copy_zip_entry($zip, $rppEntries[0], $projectAbsolute);
            $projectPath = '/uploads/projects/track-' . $trackId . '/' . $projectFile;
            $newPaths[] = $projectPath;
        }

        $sortOrder = 0;

        foreach ($selectedAudio as $index) {
            $sortOrder++;
            $entryName = (string)$zip->getNameIndex($index);
            $sourceBase = stem_clean_filename($entryName);
            $savedBase = str_pad((string)$sortOrder, 2, '0', STR_PAD_LEFT) . '-' . $sourceBase;
            $absolute = $stemDir . '/' . $savedBase;

            stem_copy_zip_entry($zip, $index, $absolute);

            $relative = '/uploads/stems/track-' . $trackId . '/' . $importToken . '/' . $savedBase;
            $newPaths[] = $relative;

            $wav = stem_wav_info($absolute);
            if ((int)$audioInfo['sample_rate'] > 0) {
                $mediaSampleRates[] = (int)$audioInfo['sample_rate'];
            }

            $map = $rppInfo['file_map'][stem_lower($sourceBase)] ?? null;
            $sourceTrackName = trim((string)($map['track_name'] ?? ''));
            $fxSummary = trim((string)($map['fx_summary'] ?? ''));
            $role = stem_role_from_metadata($sourceTrackName . ' ' . $sourceBase, $fxSummary);

            $baseStemName = preg_replace('/-consolidated(?=\.wav$)/i', '', $sourceBase) ?: $sourceBase;
            $baseStemName = preg_replace('/^\d{1,3}-/', '', $baseStemName) ?: $baseStemName;
            $baseStemName = preg_replace('/\.wav$/i', '', $baseStemName) ?: $baseStemName;

            if ($sourceTrackName !== '') {
                $stemName = $sourceTrackName;
            } elseif ($role === 'Vocal') {
                preg_match('/^(\d{1,3})/', $sourceBase, $numberMatch);
                $stemName = 'Vocal ' . ($numberMatch[1] ?? (string)$sortOrder);
            } else {
                $stemName = trim($baseStemName) !== '' ? trim($baseStemName) : ('Stem ' . $sortOrder);
            }

            $position = (float)($map['position'] ?? 0.0);
            $positions[] = $position;

            $stemRows[] = [
                'stem_name' => stem_cut($stemName, 190),
                'stem_role' => stem_cut($role, 80),
                'source_track_name' => stem_cut($sourceTrackName, 190),
                'file_name' => $sourceBase,
                'file_path' => $relative,
                'channels' => (int)$audioInfo['channels'],
                'sample_rate' => (int)$audioInfo['sample_rate'],
                'bit_depth' => (int)$audioInfo['bit_depth'],
                'duration_seconds' => (float)$audioInfo['duration_seconds'],
                'position' => $position,
                'rpp_track_guid' => stem_cut((string)($map['track_guid'] ?? ''), 80),
                'rpp_volume' => (float)($map['volume'] ?? 1.0),
                'rpp_pan' => (float)($map['pan'] ?? 0.0),
                'rpp_fx_summary' => stem_cut($fxSummary, 1000),
                'sort_order' => $sortOrder,
            ];
        }

        $zip->close();

        $projectStart = $positions ? min($positions) : 0.0;

        foreach ($stemRows as &$row) {
            $row['start_offset_seconds'] = max(0.0, (float)$row['position'] - $projectStart);
            $maxEnd = max(
                $maxEnd,
                $row['start_offset_seconds'] + (float)$row['duration_seconds']
            );
        }
        unset($row);

        $mediaSampleRates = array_values(array_unique($mediaSampleRates));
        sort($mediaSampleRates);
        $primaryMediaRate = $mediaSampleRates[0] ?? 0;

        $oldProject = stem_project_for_track($trackId);
        $oldStems = stems_for_track($trackId);
        $oldPaths = array_filter(array_column($oldStems, 'file_path'));
        if ($oldProject && !empty($oldProject['rpp_file_path'])) {
            $oldPaths[] = (string)$oldProject['rpp_file_path'];
        }

        $pdo->beginTransaction();

        if ($oldProject) {
            $projectId = (int)$oldProject['id'];
            $stmt = $pdo->prepare(
                'UPDATE track_projects
                 SET project_name=?,source_zip_name=?,rpp_file_name=?,rpp_file_path=?,
                     tempo_bpm=?,time_signature=?,project_sample_rate=?,media_sample_rate=?,
                     project_start_seconds=?,imported_by_user_id=?,imported_at=NOW()
                 WHERE id=?'
            );
            $stmt->execute([
                (string)$rppInfo['project_name'],
                stem_clean_filename($originalName),
                $rppName,
                $projectPath,
                $rppInfo['tempo_bpm'],
                (string)$rppInfo['time_signature'],
                $rppInfo['project_sample_rate'],
                $primaryMediaRate ?: null,
                $projectStart,
                $userId ?: null,
                $projectId,
            ]);

            $pdo->prepare('DELETE FROM track_stems WHERE track_id=?')->execute([$trackId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO track_projects
                 (track_id,project_name,source_zip_name,rpp_file_name,rpp_file_path,
                  tempo_bpm,time_signature,project_sample_rate,media_sample_rate,
                  project_start_seconds,imported_by_user_id,imported_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())'
            );
            $stmt->execute([
                $trackId,
                (string)$rppInfo['project_name'],
                stem_clean_filename($originalName),
                $rppName,
                $projectPath,
                $rppInfo['tempo_bpm'],
                (string)$rppInfo['time_signature'],
                $rppInfo['project_sample_rate'],
                $primaryMediaRate ?: null,
                $projectStart,
                $userId ?: null,
            ]);
            $projectId = (int)$pdo->lastInsertId();
        }

        $insert = $pdo->prepare(
            'INSERT INTO track_stems
             (track_id,project_id,stem_name,stem_role,source_track_name,file_name,file_path,
              channels,sample_rate,bit_depth,duration_seconds,start_offset_seconds,
              rpp_track_guid,rpp_volume,rpp_pan,rpp_fx_summary,sort_order,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
        );

        foreach ($stemRows as $row) {
            $insert->execute([
                $trackId,
                $projectId,
                $row['stem_name'],
                $row['stem_role'],
                $row['source_track_name'],
                $row['file_name'],
                $row['file_path'],
                $row['channels'],
                $row['sample_rate'],
                $row['bit_depth'],
                round($row['duration_seconds'], 4),
                round($row['start_offset_seconds'], 4),
                $row['rpp_track_guid'],
                round($row['rpp_volume'], 6),
                round($row['rpp_pan'], 6),
                $row['rpp_fx_summary'],
                $row['sort_order'],
            ]);
        }

        $updateFields = [];
        $updateValues = [];

        if ((int)($track['tempo_bpm'] ?? 0) < 1 && !empty($rppInfo['tempo_bpm'])) {
            $updateFields[] = 'tempo_bpm=?';
            $updateValues[] = (int)round((float)$rppInfo['tempo_bpm']);
        }

        if (trim((string)($track['duration'] ?? '')) === '' && $maxEnd > 0) {
            $updateFields[] = 'duration=?';
            $updateValues[] = stem_format_duration($maxEnd);
        }

        if ($updateFields) {
            $updateValues[] = $trackId;
            $pdo->prepare(
                'UPDATE tracks SET ' . implode(',', $updateFields) . ' WHERE id=?'
            )->execute($updateValues);
        }

        $pdo->commit();

        foreach ($oldPaths as $oldPath) {
            stem_delete_path_if_local((string)$oldPath);
            stem_cleanup_empty_parent((string)$oldPath);
        }

        return [
            'project_id' => $projectId,
            'track_id' => $trackId,
            'project_name' => (string)$rppInfo['project_name'],
            'stem_count' => count($stemRows),
            'tempo_bpm' => $rppInfo['tempo_bpm'],
            'time_signature' => (string)$rppInfo['time_signature'],
            'project_sample_rate' => $rppInfo['project_sample_rate'],
            'media_sample_rates' => $mediaSampleRates,
            'duration_seconds' => $maxEnd,
            'used_mp3' => (bool)$mp3Entries,
            'used_consolidated' => !$mp3Entries && (bool)$consolidatedEntries,
            'ignored_raw_wavs' => $mp3Entries ? count($wavEntries) : max(0, count($wavEntries) - count($selectedAudio)),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        try {
            $zip->close();
        } catch (Throwable $ignored) {}

        foreach ($newPaths as $newPath) {
            stem_delete_path_if_local((string)$newPath);
            stem_cleanup_empty_parent((string)$newPath);
        }

        throw $e;
    }
}

function stem_upload_root(int $userId, string $uploadId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        throw new RuntimeException('Invalid upload session.');
    }

    return STONEFELLOW_ROOT . '/private/stem-upload-chunks/u' . $userId . '/' . $uploadId;
}

function stem_cleanup_stale_uploads(): void
{
    $root = STONEFELLOW_ROOT . '/private/stem-upload-chunks';
    if (!is_dir($root)) {
        return;
    }

    $cutoff = time() - 86400;

    foreach (glob($root . '/u*/*') ?: [] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        if ((filemtime($dir) ?: time()) >= $cutoff) {
            continue;
        }

        $statePath = $dir . '/import-state.json';
        if (is_file($statePath)) {
            $state = json_decode((string)@file_get_contents($statePath), true);
            if (is_array($state)) {
                foreach (($state['staged_paths'] ?? []) as $relative) {
                    stem_delete_path_if_local((string)$relative);
                    stem_cleanup_empty_parent((string)$relative);
                }
            }
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}


function stem_delete_track_package(int $trackId): void
{
    $pdo = db();

    if (!$pdo || $trackId < 1) {
        return;
    }

    $project = stem_project_for_track($trackId);
    $stems = stems_for_track($trackId);
    $paths = array_filter(array_column($stems, 'file_path'));

    if ($project && !empty($project['rpp_file_path'])) {
        $paths[] = (string)$project['rpp_file_path'];
    }

    $pdo->beginTransaction();

    try {
        $pdo->prepare('DELETE FROM track_projects WHERE track_id=?')->execute([$trackId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    foreach ($paths as $path) {
        stem_delete_path_if_local((string)$path);
        stem_cleanup_empty_parent((string)$path);
    }
}
