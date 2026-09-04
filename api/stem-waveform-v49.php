<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

function waveform_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function waveform_error(string $message, int $status = 400): never
{
    waveform_json([
        'ok'=>false,
        'error'=>$message,
    ], $status);
}

function waveform_read_wav_layout(string $path): array
{
    $handle = @fopen($path, 'rb');

    if (!$handle) {
        throw new RuntimeException('Waveform media could not be opened.');
    }

    try {
        $header = fread($handle, 12);

        if (
            !is_string($header) ||
            strlen($header) < 12 ||
            substr($header,0,4) !== 'RIFF' ||
            substr($header,8,4) !== 'WAVE'
        ) {
            throw new RuntimeException('Not a supported RIFF/WAVE file.');
        }

        $format = 0;
        $channels = 0;
        $sampleRate = 0;
        $bits = 0;
        $blockAlign = 0;
        $dataOffset = 0;
        $dataBytes = 0;

        while (!feof($handle)) {
            $chunkHeader = fread($handle, 8);

            if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
                break;
            }

            $chunkId = substr($chunkHeader,0,4);
            $sizeData = unpack('Vsize',substr($chunkHeader,4,4));
            $chunkSize = (int)($sizeData['size'] ?? 0);

            if ($chunkSize < 0) {
                break;
            }

            if ($chunkId === 'fmt ') {
                $fmt = fread($handle,min($chunkSize,128));

                if (is_string($fmt) && strlen($fmt) >= 16) {
                    $formatData = unpack('vvalue',substr($fmt,0,2));
                    $channelData = unpack('vvalue',substr($fmt,2,2));
                    $rateData = unpack('Vvalue',substr($fmt,4,4));
                    $alignData = unpack('vvalue',substr($fmt,12,2));
                    $bitsData = unpack('vvalue',substr($fmt,14,2));

                    $format = (int)($formatData['value'] ?? 0);
                    $channels = (int)($channelData['value'] ?? 0);
                    $sampleRate = (int)($rateData['value'] ?? 0);
                    $blockAlign = (int)($alignData['value'] ?? 0);
                    $bits = (int)($bitsData['value'] ?? 0);
                }

                $consumed = is_string($fmt) ? strlen($fmt) : 0;

                if ($chunkSize > $consumed) {
                    fseek($handle,$chunkSize - $consumed,SEEK_CUR);
                }
            } elseif ($chunkId === 'data') {
                $dataOffset = ftell($handle);
                $dataBytes = $chunkSize;
                fseek($handle,$chunkSize,SEEK_CUR);
            } else {
                fseek($handle,$chunkSize,SEEK_CUR);
            }

            if ($chunkSize % 2 === 1) {
                fseek($handle,1,SEEK_CUR);
            }

            if (
                $format > 0 &&
                $channels > 0 &&
                $sampleRate > 0 &&
                $bits > 0 &&
                $blockAlign > 0 &&
                $dataOffset > 0 &&
                $dataBytes > 0
            ) {
                break;
            }
        }

        if (
            $channels < 1 ||
            $sampleRate < 1 ||
            $bits < 8 ||
            $blockAlign < 1 ||
            $dataOffset < 1 ||
            $dataBytes < 1
        ) {
            throw new RuntimeException('WAV layout is incomplete.');
        }

        return [
            'format'=>$format,
            'channels'=>$channels,
            'sample_rate'=>$sampleRate,
            'bits'=>$bits,
            'block_align'=>$blockAlign,
            'data_offset'=>$dataOffset,
            'data_bytes'=>$dataBytes,
            'frames'=>(int)floor($dataBytes / $blockAlign),
        ];
    } finally {
        fclose($handle);
    }
}

function waveform_sample_value(string $bytes, int $format, int $bits): float
{
    $length = strlen($bytes);

    if ($format === 3 && $bits === 32 && $length >= 4) {
        $value = unpack('gvalue',substr($bytes,0,4));
        return max(-1.0,min(1.0,(float)($value['value'] ?? 0.0)));
    }

    if ($format === 3 && $bits === 64 && $length >= 8) {
        $value = unpack('evalue',substr($bytes,0,8));
        return max(-1.0,min(1.0,(float)($value['value'] ?? 0.0)));
    }

    if ($bits === 8 && $length >= 1) {
        return (ord($bytes[0]) - 128) / 128;
    }

    if ($bits === 16 && $length >= 2) {
        $value = unpack('vvalue',substr($bytes,0,2));
        $raw = (int)($value['value'] ?? 0);

        if ($raw >= 0x8000) {
            $raw -= 0x10000;
        }

        return max(-1.0,min(1.0,$raw / 32768));
    }

    if ($bits === 24 && $length >= 3) {
        $raw = ord($bytes[0])
            | (ord($bytes[1]) << 8)
            | (ord($bytes[2]) << 16);

        if ($raw & 0x800000) {
            $raw -= 0x1000000;
        }

        return max(-1.0,min(1.0,$raw / 8388608));
    }

    if ($bits === 32 && $length >= 4) {
        $value = unpack('Vvalue',substr($bytes,0,4));
        $raw = (int)($value['value'] ?? 0);

        if ($raw >= 0x80000000) {
            $raw -= 0x100000000;
        }

        return max(-1.0,min(1.0,$raw / 2147483648));
    }

    return 0.0;
}

function waveform_wav_peaks(string $path, int $points): array
{
    $layout = waveform_read_wav_layout($path);
    $frames = max(1,(int)$layout['frames']);
    $channels = max(1,(int)$layout['channels']);
    $bits = (int)$layout['bits'];
    $format = (int)$layout['format'];
    $blockAlign = max(1,(int)$layout['block_align']);
    $bytesPerSample = max(1,(int)ceil($bits / 8));
    $dataOffset = (int)$layout['data_offset'];

    if (!in_array($format,[1,3],true)) {
        throw new RuntimeException('This WAV encoding is not supported for waveform extraction.');
    }

    $points = max(64,min(2400,$points));
    $handle = @fopen($path,'rb');

    if (!$handle) {
        throw new RuntimeException('Waveform media could not be opened.');
    }

    $mins = [];
    $maxs = [];

    try {
        for ($bucket = 0; $bucket < $points; $bucket++) {
            $frameStart = (int)floor(($bucket / $points) * $frames);
            $frameEnd = (int)floor((($bucket + 1) / $points) * $frames);
            $frameEnd = max($frameStart + 1,min($frames,$frameEnd));
            $frameCount = max(1,$frameEnd - $frameStart);

            // Sample enough frames for visible accuracy without streaming an entire
            // multi-hundred-megabyte production WAV on every page load.
            $samplesPerBucket = min(192,$frameCount);
            $step = max(1.0,$frameCount / $samplesPerBucket);
            $minValue = 0.0;
            $maxValue = 0.0;

            for ($sampleIndex = 0; $sampleIndex < $samplesPerBucket; $sampleIndex++) {
                $frame = min(
                    $frameEnd - 1,
                    $frameStart + (int)floor($sampleIndex * $step)
                );

                $offset = $dataOffset + ($frame * $blockAlign);
                fseek($handle,$offset,SEEK_SET);
                $frameBytes = fread($handle,$blockAlign);

                if (!is_string($frameBytes) || strlen($frameBytes) < $bytesPerSample) {
                    continue;
                }

                $frameMin = 0.0;
                $frameMax = 0.0;
                $channelCount = 0;

                for ($channel = 0; $channel < $channels; $channel++) {
                    $sampleOffset = $channel * $bytesPerSample;

                    if ($sampleOffset + $bytesPerSample > strlen($frameBytes)) {
                        break;
                    }

                    $value = waveform_sample_value(
                        substr($frameBytes,$sampleOffset,$bytesPerSample),
                        $format,
                        $bits
                    );

                    $frameMin = min($frameMin,$value);
                    $frameMax = max($frameMax,$value);
                    $channelCount++;
                }

                if ($channelCount < 1) {
                    continue;
                }

                $minValue = min($minValue,$frameMin);
                $maxValue = max($maxValue,$frameMax);
            }

            $mins[] = round(max(-1.0,min(1.0,$minValue)),4);
            $maxs[] = round(max(-1.0,min(1.0,$maxValue)),4);
        }
    } finally {
        fclose($handle);
    }

    return [
        'mins'=>$mins,
        'maxs'=>$maxs,
        'duration'=>$frames / max(1,(int)$layout['sample_rate']),
        'sample_rate'=>(int)$layout['sample_rate'],
        'channels'=>$channels,
        'bits'=>$bits,
    ];
}

$stemId = (int)($_GET['id'] ?? 0);
$points = max(64,min(2400,(int)($_GET['points'] ?? 1200)));

if ($stemId < 1) {
    waveform_error('Stem id is required.');
}

$pdo = db();

if (!$pdo) {
    waveform_error('Database unavailable.',503);
}

$stmt = $pdo->prepare(
    'SELECT id,track_id,file_path,file_name,duration_seconds
     FROM track_stems
     WHERE id=? AND is_active=1
     LIMIT 1'
);
$stmt->execute([$stemId]);
$stem = $stmt->fetch();

if (!$stem) {
    waveform_error('Stem not found.',404);
}

$trackStmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$trackStmt->execute([(int)$stem['track_id']]);
$track = $trackStmt->fetch();
if (!$track || (!can_manage_track_production($track) && !(user_has_role('fan') && can_view_track($track)))) {
    waveform_error(
        'This stem has not been shared with your account.',
        403
    );
}

$relative = trim((string)$stem['file_path']);

if (
    $relative === '' ||
    !str_starts_with($relative,'/uploads/stems/')
) {
    waveform_error('Stem waveform source is unavailable.',404);
}

$uploadsRoot = realpath(STONEFELLOW_ROOT . '/uploads');
$absolute = realpath(
    STONEFELLOW_ROOT . '/' . ltrim($relative,'/')
);

if (
    !$uploadsRoot ||
    !$absolute ||
    !is_file($absolute) ||
    !str_starts_with(
        $absolute,
        rtrim($uploadsRoot,DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    )
) {
    waveform_error('Stem waveform source is unavailable.',404);
}

$extension = stem_lower(
    pathinfo((string)$stem['file_name'],PATHINFO_EXTENSION)
);

if ($extension === '') {
    $extension = stem_lower(pathinfo($absolute,PATHINFO_EXTENSION));
}

if ($extension !== 'wav') {
    waveform_json([
        'ok'=>true,
        'supported'=>false,
        'format'=>strtoupper($extension ?: 'AUDIO'),
        'duration'=>(float)$stem['duration_seconds'],
        'mins'=>[],
        'maxs'=>[],
    ]);
}

try {
    $waveform = waveform_wav_peaks($absolute,$points);

    waveform_json([
        'ok'=>true,
        'supported'=>true,
        'format'=>'WAV',
        'stem_id'=>$stemId,
        'points'=>count($waveform['mins']),
        'duration'=>$waveform['duration'],
        'sample_rate'=>$waveform['sample_rate'],
        'channels'=>$waveform['channels'],
        'bits'=>$waveform['bits'],
        'mins'=>$waveform['mins'],
        'maxs'=>$waveform['maxs'],
    ]);
} catch (Throwable $e) {
    waveform_error($e->getMessage(),422);
}
