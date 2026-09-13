<?php
declare(strict_types=1);

const STONEFELLOW_ARTIST_RECORDING_PLAYBACK_V243 = 'artist-recording-playback-v243-20260913';

/**
 * Resolve one retained recording strictly inside the signed-in user's
 * transcription namespace. Native filesystem paths never leave this helper.
 *
 * @return array{path:string,size:int,mime:string,extension:string}
 */
function artist_recording_playback_v243_resolve(PDO $pdo, array $user, int $sessionId, string $clientKey): array
{
    $clientKey = artist_listening_v197_recording_key($clientKey);
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $recording = null;
    foreach (artist_listening_v197_recordings($session) as $candidate) {
        if ((string)($candidate['key'] ?? '') === $clientKey) {
            $recording = $candidate;
            break;
        }
    }
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
    }

    $fileName = basename((string)($recording['file_name'] ?? ''));
    if ($fileName === '') {
        throw new RuntimeException('Recording file is unavailable.');
    }
    $path = artist_listening_v197_private_dir($user, $sessionId) . '/' . $fileName;
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Recording file is unavailable.');
    }
    $size = (int)filesize($path);
    if ($size < 1) {
        throw new RuntimeException('Recording file is empty.');
    }

    $mime = strtolower(trim((string)($recording['mime_type'] ?? '')));
    $allowedMime = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav'];
    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Recording media type is unavailable.');
    }
    $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{2,5}$/', $extension)) {
        $extension = 'audio';
    }

    return [
        'path'=>$path,
        'size'=>$size,
        'mime'=>$mime,
        'extension'=>$extension,
    ];
}

/**
 * Parse a single RFC 7233 byte range. Multi-range responses are intentionally
 * rejected because the browser audio player only needs one contiguous range.
 *
 * @return array{status:int,start:int,end:int,length:int}
 */
function artist_recording_playback_v243_range(int $size, string $rangeHeader): array
{
    $size = max(0, $size);
    if ($size < 1) {
        throw new OutOfBoundsException('Empty recording.');
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $rangeHeader = trim($rangeHeader);
    if ($rangeHeader === '') {
        return ['status'=>$status, 'start'=>$start, 'end'=>$end, 'length'=>$size];
    }

    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $match)
        || ($match[1] === '' && $match[2] === '')) {
        throw new OutOfBoundsException('Unsupported byte range.');
    }

    if ($match[1] === '') {
        $suffix = (int)$match[2];
        if ($suffix < 1) {
            throw new OutOfBoundsException('Invalid suffix byte range.');
        }
        $suffix = min($size, $suffix);
        $start = $size - $suffix;
    } else {
        $start = (int)$match[1];
        if ($start < 0 || $start >= $size) {
            throw new OutOfBoundsException('Byte range starts outside the recording.');
        }
        if ($match[2] !== '') {
            $requestedEnd = (int)$match[2];
            if ($requestedEnd < $start) {
                throw new OutOfBoundsException('Byte range ends before it starts.');
            }
            $end = min($size - 1, $requestedEnd);
        }
    }

    $status = 206;
    return [
        'status'=>$status,
        'start'=>$start,
        'end'=>$end,
        'length'=>$end - $start + 1,
    ];
}

/**
 * Make the PHP response safe for binary media. In particular, release the PHP
 * session lock before the browser starts follow-up range requests and remove
 * application output buffers/compression that can invalidate Content-Length.
 */
function artist_recording_playback_v243_prepare_transport(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (function_exists('header_remove')) {
        @header_remove('Content-Encoding');
    }
}

function artist_recording_playback_v243_unsatisfied(int $size): never
{
    artist_recording_playback_v243_prepare_transport();
    http_response_code(416);
    header('Accept-Ranges: bytes');
    header('Content-Range: bytes */' . max(0, $size));
    header('Content-Length: 0');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    exit;
}

/**
 * Serve a private retained recording for native <audio> playback.
 * GET supports deterministic byte ranges; HEAD returns the same media headers
 * without opening or reading the private file body.
 */
function artist_recording_playback_v243_serve(
    PDO $pdo,
    array $user,
    int $sessionId,
    string $clientKey,
    string $method,
    string $rangeHeader
): never {
    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Content-Length: 0');
        exit;
    }

    $media = artist_recording_playback_v243_resolve($pdo, $user, $sessionId, $clientKey);
    try {
        $range = artist_recording_playback_v243_range((int)$media['size'], $rangeHeader);
    } catch (OutOfBoundsException $e) {
        artist_recording_playback_v243_unsatisfied((int)$media['size']);
    }

    artist_recording_playback_v243_prepare_transport();
    $status = (int)$range['status'];
    $start = (int)$range['start'];
    $end = (int)$range['end'];
    $length = (int)$range['length'];

    http_response_code($status);
    header('Content-Type: ' . (string)$media['mime']);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Content-Disposition: inline; filename="transcription-recording.' . (string)$media['extension'] . '"');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . (int)$media['size']);
    }

    if ($method === 'HEAD') {
        exit;
    }

    $handle = @fopen((string)$media['path'], 'rb');
    if (!$handle) {
        throw new RuntimeException('Recording file could not be opened.');
    }
    if ($start > 0 && fseek($handle, $start) !== 0) {
        fclose($handle);
        throw new RuntimeException('Recording range could not be opened.');
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}
