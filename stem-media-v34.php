<?php
declare(strict_types=1);

// Stem media recovery v229.
// Keep all reusable inspection/range helpers above the runtime bootstrap so
// the protocol can be exercised byte-for-byte by CLI contract tests without a
// database or authenticated session.
const STONEFELLOW_STEM_MEDIA_BUILD = 'stem-media-byte-recovery-v229-20260902';

function stem_media_v229_mp3_frame_length(string $bytes, int $offset): int
{
    if ($offset < 0 || strlen($bytes) < $offset + 4) {
        return 0;
    }

    $b0 = ord($bytes[$offset]);
    $b1 = ord($bytes[$offset + 1]);
    $b2 = ord($bytes[$offset + 2]);

    if ($b0 !== 0xff || ($b1 & 0xe0) !== 0xe0) {
        return 0;
    }

    $version = ($b1 >> 3) & 0x03;
    $layer = ($b1 >> 1) & 0x03;
    $bitrateIndex = ($b2 >> 4) & 0x0f;
    $sampleRateIndex = ($b2 >> 2) & 0x03;
    $padding = ($b2 >> 1) & 0x01;

    // Stonefellow accepts .mp3 as MPEG Layer III only. Reserved MPEG version,
    // non-Layer-III frames, free/reserved bitrates and reserved sample rates
    // are not sufficient evidence that arbitrary bytes are playable MP3.
    if (
        $version === 0x01 ||
        $layer !== 0x01 ||
        $bitrateIndex < 1 ||
        $bitrateIndex > 14 ||
        $sampleRateIndex === 0x03
    ) {
        return 0;
    }

    $mpeg1Bitrates = [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0];
    $mpeg2Bitrates = [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160,0];
    $mpeg1Rates = [44100,48000,32000];
    $mpeg2Rates = [22050,24000,16000];
    $mpeg25Rates = [11025,12000,8000];

    $isMpeg1 = $version === 0x03;
    $bitrate = ($isMpeg1 ? $mpeg1Bitrates : $mpeg2Bitrates)[$bitrateIndex] ?? 0;
    $sampleRates = $isMpeg1
        ? $mpeg1Rates
        : ($version === 0x02 ? $mpeg2Rates : $mpeg25Rates);
    $sampleRate = $sampleRates[$sampleRateIndex] ?? 0;

    if ($bitrate < 1 || $sampleRate < 1) {
        return 0;
    }

    $coefficient = $isMpeg1 ? 144000 : 72000;
    $length = (int)floor(($coefficient * $bitrate) / $sampleRate) + $padding;
    return $length >= 24 ? $length : 0;
}

function stem_media_v229_scan_mp3_frames(string $bytes): bool
{
    $limit = strlen($bytes) - 4;
    for ($offset = 0; $offset <= $limit; $offset++) {
        $frameLength = stem_media_v229_mp3_frame_length($bytes, $offset);
        if ($frameLength < 1) {
            continue;
        }

        $next = $offset + $frameLength;
        if ($next <= $limit && stem_media_v229_mp3_frame_length($bytes, $next) > 0) {
            return true;
        }
    }

    return false;
}

function stem_media_v229_detect_mp3(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    try {
        $probe = (string)fread($handle, 131072);
        if (strlen($probe) < 4) {
            return false;
        }

        if (strlen($probe) >= 10 && substr($probe, 0, 3) === 'ID3') {
            $majorVersion = ord($probe[3]);
            $flags = ord($probe[5]);
            $sizeBytes = [ord($probe[6]), ord($probe[7]), ord($probe[8]), ord($probe[9])];

            if (
                $majorVersion < 2 ||
                $majorVersion > 4 ||
                array_filter($sizeBytes, static fn(int $value): bool => ($value & 0x80) !== 0)
            ) {
                return false;
            }

            $tagSize =
                (($sizeBytes[0] & 0x7f) << 21) |
                (($sizeBytes[1] & 0x7f) << 14) |
                (($sizeBytes[2] & 0x7f) << 7) |
                ($sizeBytes[3] & 0x7f);
            $footerBytes = ($majorVersion === 4 && ($flags & 0x10) !== 0) ? 10 : 0;
            $audioOffset = 10 + $tagSize + $footerBytes;

            if ($audioOffset < strlen($probe)) {
                return stem_media_v229_scan_mp3_frames(substr($probe, $audioOffset));
            }

            if (fseek($handle, $audioOffset, SEEK_SET) !== 0) {
                return false;
            }

            return stem_media_v229_scan_mp3_frames((string)fread($handle, 131072));
        }

        return stem_media_v229_scan_mp3_frames($probe);
    } finally {
        fclose($handle);
    }
}

function stem_media_v229_detect_wav(string $path): bool
{
    $size = @filesize($path);
    if ($size === false || $size < 44) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    try {
        $header = (string)fread($handle, 12);
        if (
            strlen($header) !== 12 ||
            substr($header, 0, 4) !== 'RIFF' ||
            substr($header, 8, 4) !== 'WAVE'
        ) {
            return false;
        }

        $fmt = false;
        $data = false;
        $chunks = 0;

        while (!feof($handle) && $chunks < 256) {
            $chunks++;
            $chunkHeader = (string)fread($handle, 8);
            if (strlen($chunkHeader) !== 8) {
                break;
            }

            $chunkId = substr($chunkHeader, 0, 4);
            $decoded = unpack('Vsize', substr($chunkHeader, 4, 4));
            $chunkSize = (int)($decoded['size'] ?? -1);
            if ($chunkSize < 0) {
                return false;
            }

            $payloadStart = ftell($handle);
            if ($payloadStart === false) {
                return false;
            }

            // Reject impossible chunk sizes instead of seeking outside the
            // source file and accepting a superficially valid RIFF header.
            if ($chunkSize > $size || $payloadStart + $chunkSize > $size) {
                return false;
            }

            if ($chunkId === 'fmt ') {
                if ($chunkSize < 16) {
                    return false;
                }
                $formatBytes = (string)fread($handle, min($chunkSize, 40));
                if (strlen($formatBytes) < 16) {
                    return false;
                }
                $format = unpack('vvalue', substr($formatBytes, 0, 2));
                $channels = unpack('vvalue', substr($formatBytes, 2, 2));
                $sampleRate = unpack('Vvalue', substr($formatBytes, 4, 4));
                $formatCode = (int)($format['value'] ?? 0);
                $channelCount = (int)($channels['value'] ?? 0);
                $rate = (int)($sampleRate['value'] ?? 0);
                if (
                    !in_array($formatCode, [1, 3, 0xfffe], true) ||
                    $channelCount < 1 ||
                    $channelCount > 64 ||
                    $rate < 8000 ||
                    $rate > 768000
                ) {
                    return false;
                }
                $fmt = true;
            } elseif ($chunkId === 'data') {
                if ($chunkSize < 1) {
                    return false;
                }
                $data = true;
            }

            $next = $payloadStart + $chunkSize + ($chunkSize % 2);
            if ($next > $size) {
                return false;
            }
            if (fseek($handle, $next, SEEK_SET) !== 0) {
                return false;
            }

            if ($fmt && $data) {
                return true;
            }
        }

        return $fmt && $data;
    } finally {
        fclose($handle);
    }
}

function stem_media_v229_inspect_file(string $path): array
{
    $result = [
        'ok' => false,
        'reason' => 'missing',
        'format' => '',
        'mime' => 'application/octet-stream',
        'size' => 0,
        'path' => $path,
    ];

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return $result;
    }

    $size = @filesize($path);
    if ($size === false || $size < 1) {
        $result['reason'] = 'empty';
        return $result;
    }

    $result['size'] = (int)$size;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (!in_array($extension, ['mp3', 'wav'], true)) {
        $result['reason'] = 'unsupported_extension';
        return $result;
    }

    $isWav = stem_media_v229_detect_wav($path);
    $isMp3 = !$isWav && stem_media_v229_detect_mp3($path);

    if ($isWav) {
        $result['format'] = 'wav';
        $result['mime'] = 'audio/wav';
    } elseif ($isMp3) {
        $result['format'] = 'mp3';
        $result['mime'] = 'audio/mpeg';
    } else {
        $result['reason'] = 'invalid_signature';
        return $result;
    }

    if ($result['format'] !== $extension) {
        $result['reason'] = 'signature_mismatch';
        return $result;
    }

    $result['ok'] = true;
    $result['reason'] = 'ok';
    return $result;
}

function stem_media_v229_resolve_path(string $root, string $relative): array
{
    $relative = trim($relative);
    $prefix = '/uploads/stems/';

    if ($relative === '' || !str_starts_with($relative, $prefix)) {
        return ['ok' => false, 'reason' => 'invalid_path', 'path' => ''];
    }

    $stemsRoot = realpath(rtrim($root, DIRECTORY_SEPARATOR) . '/uploads/stems');
    $absolute = realpath(rtrim($root, DIRECTORY_SEPARATOR) . '/' . ltrim($relative, '/'));

    if (!$stemsRoot || !$absolute || !is_file($absolute)) {
        return ['ok' => false, 'reason' => 'missing', 'path' => ''];
    }

    $boundary = rtrim($stemsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($absolute, $boundary)) {
        return ['ok' => false, 'reason' => 'outside_stem_root', 'path' => ''];
    }

    return ['ok' => true, 'reason' => 'ok', 'path' => $absolute];
}

function stem_media_v229_range(string $header, int $size): array
{
    $full = [
        'ok' => true,
        'status' => 200,
        'start' => 0,
        'end' => max(0, $size - 1),
        'length' => max(0, $size),
        'content_range' => '',
    ];

    if ($size < 1) {
        return [
            'ok' => false,
            'status' => 416,
            'start' => 0,
            'end' => -1,
            'length' => 0,
            'content_range' => 'bytes */0',
        ];
    }

    $header = trim($header);
    if ($header === '') {
        return $full;
    }

    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
        return [
            'ok' => false,
            'status' => 416,
            'start' => 0,
            'end' => -1,
            'length' => 0,
            'content_range' => 'bytes */' . $size,
        ];
    }

    $first = (string)$matches[1];
    $last = (string)$matches[2];
    if ($first === '' && $last === '') {
        return stem_media_v229_range('invalid', $size);
    }

    if ($first === '') {
        $suffixLength = (int)$last;
        if ($suffixLength < 1) {
            return stem_media_v229_range('invalid', $size);
        }
        $suffixLength = min($suffixLength, $size);
        $start = $size - $suffixLength;
        $end = $size - 1;
    } else {
        $start = (int)$first;
        $end = $last !== '' ? (int)$last : $size - 1;
        if ($start < 0 || $start >= $size || $end < $start) {
            return stem_media_v229_range('invalid', $size);
        }
        $end = min($end, $size - 1);
    }

    return [
        'ok' => true,
        'status' => 206,
        'start' => $start,
        'end' => $end,
        'length' => $end - $start + 1,
        'content_range' => 'bytes ' . $start . '-' . $end . '/' . $size,
    ];
}

function stem_media_v229_read_slice(string $path, int $start, int $length): string
{
    if ($start < 0 || $length < 0) {
        throw new InvalidArgumentException('Invalid media slice.');
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open media source.');
    }

    try {
        if ($start > 0 && fseek($handle, $start, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek media source.');
        }

        $remaining = $length;
        $body = '';
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $body;
    } finally {
        fclose($handle);
    }
}

function stem_media_v229_clear_output(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function stem_media_v229_harden_output(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    if (function_exists('header_remove')) {
        @header_remove('Content-Encoding');
    }
}

function stem_media_v229_error(int $status, string $message, string $reason): never
{
    stem_media_v229_clear_output();
    stem_media_v229_harden_output();
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store, no-transform');
    header('X-Content-Type-Options: nosniff');
    header('X-Stonefellow-Stem-Media: ' . STONEFELLOW_STEM_MEDIA_BUILD);
    header('X-Stonefellow-Media-Status: ' . $reason);
    $body = $message . "\n";
    header('Content-Length: ' . strlen($body));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
    exit;
}

if (defined('STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY') && STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY) {
    return;
}

// Start a buffer before bootstrap/auth so notices, BOMs or accidental template
// output cannot be prepended to an authorized binary response.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('zlib.output_compression', '0');
ob_start();

require __DIR__ . '/includes/bootstrap.php';
require_permission('chat.access');

$stemId = (int)($_GET['id'] ?? 0);
$pdo = db();

if (!$pdo || $stemId < 1 || !table_exists('track_stems')) {
    stem_media_v229_error(404, 'Stem not found.', 'not_found');
}

$stmt = $pdo->prepare(
    'SELECT s.* FROM track_stems s WHERE s.id=? AND s.is_active=1 LIMIT 1'
);
$stmt->execute([$stemId]);
$stem = $stmt->fetch();
if (!$stem) {
    stem_media_v229_error(404, 'Stem not found.', 'not_found');
}

$trackStmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$trackStmt->execute([(int)$stem['track_id']]);
$track = $trackStmt->fetch();
if (!$track || (!can_manage_track_production($track) && !(user_has_role('fan') && can_view_track($track)))) {
    stem_media_v229_error(403, 'This stem has not been shared with your account.', 'forbidden');
}

$resolved = stem_media_v229_resolve_path(STONEFELLOW_ROOT, (string)$stem['file_path']);
if (!$resolved['ok']) {
    stem_media_v229_error(404, 'Stem file is not available.', (string)$resolved['reason']);
}

$absolute = (string)$resolved['path'];
$inspection = stem_media_v229_inspect_file($absolute);
if (!$inspection['ok']) {
    $status = in_array($inspection['reason'], ['missing', 'empty'], true) ? 404 : 415;
    stem_media_v229_error(
        $status,
        'Stem media failed validation: ' . (string)$inspection['reason'] . '.',
        (string)$inspection['reason']
    );
}

$size = (int)$inspection['size'];
$range = stem_media_v229_range((string)($_SERVER['HTTP_RANGE'] ?? ''), $size);
if (!$range['ok']) {
    stem_media_v229_clear_output();
    stem_media_v229_harden_output();
    http_response_code(416);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Accept-Ranges: bytes');
    header('Content-Range: ' . (string)$range['content_range']);
    header('Cache-Control: private, no-store, no-transform');
    header('X-Stonefellow-Stem-Media: ' . STONEFELLOW_STEM_MEDIA_BUILD);
    header('X-Stonefellow-Media-Status: invalid_range');
    exit;
}

stem_media_v229_clear_output();
stem_media_v229_harden_output();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

http_response_code((int)$range['status']);
header('Content-Type: ' . (string)$inspection['mime']);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, no-transform, max-age=0');
header('X-Stonefellow-Stem-Media: ' . STONEFELLOW_STEM_MEDIA_BUILD);
header('X-Stonefellow-Media-Status: ok');
header('X-Stonefellow-Media-Format: ' . (string)$inspection['format']);
header('Content-Disposition: inline; filename="' . rawurlencode(basename($absolute)) . '"');
header('Content-Length: ' . (int)$range['length']);
if ((int)$range['status'] === 206) {
    header('Content-Range: ' . (string)$range['content_range']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = @fopen($absolute, 'rb');
if (!$handle) {
    stem_media_v229_error(500, 'Stem media could not be opened.', 'open_failed');
}

$start = (int)$range['start'];
$remaining = (int)$range['length'];
if ($start > 0 && fseek($handle, $start, SEEK_SET) !== 0) {
    fclose($handle);
    stem_media_v229_error(500, 'Stem media seek failed.', 'seek_failed');
}

while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
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
exit;
