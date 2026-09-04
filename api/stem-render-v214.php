<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

header('Cache-Control: no-store');

function stem_v214_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stem_v214_error(Throwable|string $error, int $status = 400): never
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    stem_v214_json([
        'ok'=>false,
        'error'=>$message !== '' ? $message : 'Render export request failed.',
    ],$status);
}

function stem_v214_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1) {
        throw new RuntimeException('Track not found.');
    }

    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) {
        throw new RuntimeException('Track not found.');
    }

    $user = current_user();
    $canProduce = can_manage_track_production($track,$user);
    $fanPrivateMix = user_has_role('fan',$user) && !$canProduce;
    if (!$canProduce && !$fanPrivateMix) {
        stem_v214_error('This track has not been shared with your account.',403);
    }

    return $track;
}

function stem_v214_ffmpeg(): string
{
    if (!function_exists('exec')) {
        return '';
    }

    $candidates = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/homebrew/bin/ffmpeg',
        '/opt/local/bin/ffmpeg',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function stem_v214_safe_name(string $value, string $fallback = 'Stonefellow Render'): string
{
    $value = trim($value);
    $value = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u',' ',$value) ?? $value;
    $value = preg_replace('/\s+/u',' ',$value) ?? $value;
    $value = trim($value," .\t\n\r\0\x0B");
    if ($value === '') {
        $value = $fallback;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value,0,140,'UTF-8');
    }
    return substr($value,0,140);
}

function stem_v214_assert_wav(string $path): void
{
    if (!is_file($path) || filesize($path) < 44) {
        throw new RuntimeException('Rendered WAV upload is empty or invalid.');
    }

    $handle = @fopen($path,'rb');
    if (!$handle) {
        throw new RuntimeException('Rendered WAV upload could not be read.');
    }
    $header = (string)fread($handle,12);
    fclose($handle);

    if (strlen($header) < 12 || substr($header,0,4) !== 'RIFF' || substr($header,8,4) !== 'WAVE') {
        throw new RuntimeException('Only RIFF/WAVE render files can be transcoded.');
    }
}

function stem_v214_temp_root(): string
{
    $root = rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'stonefellow-render-v214-'
        . bin2hex(random_bytes(8));

    if (!mkdir($root,0700,true) && !is_dir($root)) {
        throw new RuntimeException('Could not create temporary render storage.');
    }

    return $root;
}

function stem_v214_cleanup(string $root): void
{
    if ($root === '' || !is_dir($root)) {
        return;
    }

    $items = scandir($root);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    @rmdir($root);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stem_v214_error('POST required.',405);
}

if (!verify_csrf()) {
    stem_v214_error('Session expired. Refresh Stem Studio and try again.',403);
}

$action = trim((string)($_POST['action'] ?? ''));
$trackId = max(0,(int)($_POST['track_id'] ?? 0));

try {
    $track = stem_v214_track($trackId);

    if ($action === 'capabilities') {
        $ffmpeg = stem_v214_ffmpeg();
        stem_v214_json([
            'ok'=>true,
            'wav'=>true,
            'mp3'=>$ffmpeg !== '',
            'mp3_encoder'=>$ffmpeg !== '' ? 'ffmpeg' : '',
            'max_upload_bytes'=>536870912,
        ]);
    }

    if ($action === 'transcode_mp3') {
        $ffmpeg = stem_v214_ffmpeg();
        if ($ffmpeg === '') {
            stem_v214_error('MP3 encoding is not installed on this server. Export WAV instead.',501);
        }

        if (empty($_FILES['wav']) || !is_array($_FILES['wav'])) {
            throw new RuntimeException('Rendered WAV file is required.');
        }

        $upload = $_FILES['wav'];
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Rendered WAV upload failed.');
        }

        $size = max(0,(int)($upload['size'] ?? 0));
        if ($size < 44 || $size > 536870912) {
            throw new RuntimeException('Rendered WAV size is outside the supported export range.');
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Rendered WAV upload could not be verified.');
        }
        stem_v214_assert_wav($tmp);

        $root = stem_v214_temp_root();
        $input = $root . DIRECTORY_SEPARATOR . 'input.wav';
        $output = $root . DIRECTORY_SEPARATOR . 'render.mp3';

        try {
            if (!move_uploaded_file($tmp,$input)) {
                throw new RuntimeException('Rendered WAV could not be staged for encoding.');
            }
            @chmod($input,0600);

            $commands = [
                escapeshellarg($ffmpeg)
                . ' -hide_banner -loglevel error -nostdin -y -i '
                . escapeshellarg($input)
                . ' -map_metadata -1 -codec:a libmp3lame -b:a 320k '
                . escapeshellarg($output)
                . ' 2>&1',
                escapeshellarg($ffmpeg)
                . ' -hide_banner -loglevel error -nostdin -y -i '
                . escapeshellarg($input)
                . ' -map_metadata -1 -codec:a mp3 -b:a 320k '
                . escapeshellarg($output)
                . ' 2>&1',
            ];

            $encoded = false;
            $lastOutput = [];
            foreach ($commands as $command) {
                $commandOutput = [];
                $status = 1;
                exec($command,$commandOutput,$status);
                $lastOutput = $commandOutput;
                if ($status === 0 && is_file($output) && filesize($output) > 0) {
                    $encoded = true;
                    break;
                }
                @unlink($output);
            }

            if (!$encoded) {
                $detail = trim(implode("\n",array_slice($lastOutput,-5)));
                throw new RuntimeException(
                    'MP3 encoding failed on this server.'
                    . ($detail !== '' ? ' ' . $detail : '')
                );
            }

            $requested = stem_v214_safe_name((string)($_POST['filename'] ?? ''),(string)($track['title'] ?? 'Stonefellow Render'));
            $requested = preg_replace('/\.wav$/i','',$requested) ?? $requested;
            $download = stem_v214_safe_name($requested) . '.mp3';
            $length = (int)filesize($output);

            header('Content-Type: audio/mpeg');
            header('Content-Length: ' . $length);
            header('Content-Disposition: attachment; filename="' . addcslashes($download,"\\\"") . '"');
            header('X-Stonefellow-Render: stem-render-export-v214-20260901');
            readfile($output);
            stem_v214_cleanup($root);
            exit;
        } catch (Throwable $e) {
            stem_v214_cleanup($root);
            throw $e;
        }
    }

    stem_v214_error('Unknown render export action.',404);
} catch (Throwable $e) {
    stem_v214_error($e,400);
}
