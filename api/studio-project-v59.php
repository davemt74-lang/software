<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('tracks.manage');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function studio_v47_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function studio_v47_error(Throwable|string $error, int $status = 400): never
{
    $message = $error instanceof Throwable
        ? $error->getMessage()
        : (string)$error;

    studio_v47_json([
        'ok'=>false,
        'error'=>$message !== '' ? $message : 'Request failed.',
    ], $status);
}

function studio_v53_cut(
    string $value,
    int $length
): string {
    $length = max(1,$length);

    if (function_exists('mb_substr')) {
        return mb_substr(
            $value,
            0,
            $length,
            'UTF-8'
        );
    }

    return substr(
        $value,
        0,
        $length
    );
}

function studio_v47_user_id(): int
{
    return (int)(current_user()['id'] ?? 0);
}

function studio_v47_track(int $trackId): array
{
    $pdo = db();

    if (!$pdo || $trackId < 1) {
        throw new RuntimeException('Track not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM tracks WHERE id=? LIMIT 1'
    );
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();

    if (!$track) {
        throw new RuntimeException('Track not found.');
    }

    return $track;
}

function studio_v47_refresh_track_duration(int $trackId): void
{
    $pdo = db();

    if (!$pdo || $trackId < 1) {
        return;
    }

    $projectTempo = 120.0;

    try {
        $tempoStmt = $pdo->prepare(
            'SELECT COALESCE(
                NULLIF(p.tempo_bpm,0),
                NULLIF(t.tempo_bpm,0),
                120
             )
             FROM tracks t
             LEFT JOIN track_projects p
               ON p.track_id=t.id
             WHERE t.id=?
             LIMIT 1'
        );
        $tempoStmt->execute([$trackId]);

        $projectTempo = max(
            40.0,
            min(
                300.0,
                (float)$tempoStmt->fetchColumn()
            )
        );
    } catch (Throwable $e) {
        $projectTempo = 120.0;
    }

    $stmt = $pdo->prepare(
        'SELECT
            duration_seconds,
            start_offset_seconds,
            rpp_fx_summary
         FROM track_stems
         WHERE track_id=?
           AND is_active=1'
    );
    $stmt->execute([$trackId]);

    $maxSeconds = 0.0;

    foreach ($stmt->fetchAll() as $stem) {
        $sourceTempo =
            $projectTempo;

        if (
            preg_match(
                '/(?:Library|Recorded) tempo:\s*([0-9.]+)\s*BPM/i',
                (string)($stem['rpp_fx_summary'] ?? ''),
                $match
            )
        ) {
            $sourceTempo = max(
                40.0,
                min(
                    300.0,
                    (float)$match[1]
                )
            );
        }

        $timelineDuration =
            max(
                0.0,
                (float)$stem['duration_seconds']
            )
            *
            (
                $sourceTempo /
                $projectTempo
            );

        $maxSeconds = max(
            $maxSeconds,
            max(
                0.0,
                (float)$stem['start_offset_seconds']
            )
            +
            $timelineDuration
        );
    }

    $duration = $maxSeconds > 0
        ? stem_format_duration($maxSeconds)
        : '';

    $update = $pdo->prepare(
        'UPDATE tracks
         SET duration=?
         WHERE id=?'
    );

    $update->execute([
        $duration,
        $trackId
    ]);
}


function studio_v47_import_root(
    int $trackId,
    string $requestId
): array {
    if (
        $trackId < 1 ||
        !preg_match(
            '/^[a-f0-9]{24}$/',
            $requestId
        )
    ) {
        throw new RuntimeException('Invalid import request.');
    }

    $relative = '/uploads/stems/track-'
        . $trackId
        . '/studio-'
        . $requestId;

    $absolute = STONEFELLOW_ROOT . $relative;

    return [$relative,$absolute];
}

function studio_v47_state_path(
    int $trackId,
    string $requestId
): string {
    [, $absolute] = studio_v47_import_root(
        $trackId,
        $requestId
    );

    return $absolute . '/.studio-import.json';
}

function studio_v47_load_state(
    int $trackId,
    string $requestId
): array {
    $path = studio_v47_state_path(
        $trackId,
        $requestId
    );

    if (!is_file($path)) {
        throw new RuntimeException(
            'This media import expired or could not be found.'
        );
    }

    $state = json_decode(
        (string)file_get_contents($path),
        true
    );

    if (
        !is_array($state) ||
        (int)($state['track_id'] ?? 0) !== $trackId ||
        (int)($state['user_id'] ?? 0) !== studio_v47_user_id()
    ) {
        throw new RuntimeException('Invalid media import state.');
    }

    return $state;
}

function studio_v47_save_state(
    int $trackId,
    string $requestId,
    array $state
): void {
    [, $absolute] = studio_v47_import_root(
        $trackId,
        $requestId
    );

    if (
        !is_dir($absolute) &&
        !mkdir($absolute,0700,true) &&
        !is_dir($absolute)
    ) {
        throw new RuntimeException(
            'Could not create the media import directory.'
        );
    }

    $json = json_encode(
        $state,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (
        !is_string($json) ||
        file_put_contents(
            $absolute . '/.studio-import.json',
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Could not save media import state.'
        );
    }
}

function studio_v47_mp3_header_ok(string $path): bool
{
    $handle = @fopen($path,'rb');
    if (!$handle) {
        return false;
    }

    $head = (string)fread($handle,10);
    fclose($handle);

    if (str_starts_with($head,'ID3')) {
        return true;
    }

    if (strlen($head) >= 2) {
        $a = ord($head[0]);
        $b = ord($head[1]);

        return $a === 0xFF && ($b & 0xE0) === 0xE0;
    }

    return false;
}

function studio_v47_validate_audio(
    string $path,
    string $extension
): array {
    if (!is_file($path)) {
        throw new RuntimeException('Uploaded media file was not found.');
    }

    if ($extension === 'wav') {
        $info = stem_wav_info($path);

        if (
            (int)($info['channels'] ?? 0) < 1 ||
            (int)($info['sample_rate'] ?? 0) < 1 ||
            (float)($info['duration_seconds'] ?? 0) <= 0
        ) {
            throw new RuntimeException(
                'A WAV file could not be read as valid PCM/WAVE media.'
            );
        }

        return $info;
    }

    if ($extension === 'mp3') {
        if (!studio_v47_mp3_header_ok($path)) {
            throw new RuntimeException(
                'An MP3 file did not contain a recognizable MP3 header.'
            );
        }

        return [
            'channels'=>0,
            'sample_rate'=>0,
            'bit_depth'=>0,
            'duration_seconds'=>0.0,
            'data_bytes'=>filesize($path) ?: 0,
        ];
    }

    throw new RuntimeException(
        'Only WAV and MP3 media can be imported into Stem Studio.'
    );
}

function studio_v47_ensure_project(
    int $trackId,
    int $userId
): array {
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }

    $project = stem_project_for_track($trackId);

    if ($project) {
        return $project;
    }

    $track = studio_v47_track($trackId);

    $stmt = $pdo->prepare(
        'INSERT INTO track_projects
         (
            track_id,
            project_name,
            tempo_bpm,
            time_signature,
            imported_by_user_id,
            imported_at
         )
         VALUES (?,?,?,?,?,NOW())'
    );

    $stmt->execute([
        $trackId,
        (string)$track['title'],
        !empty($track['tempo_bpm'])
            ? (float)$track['tempo_bpm']
            : 120.0,
        '4/4',
        $userId ?: null,
    ]);

    return stem_project_for_track($trackId)
        ?: throw new RuntimeException(
            'Could not create the Stem Studio project.'
        );
}

function studio_v53_recording_root(
    int $trackId,
    string $recordingId
): array {
    if (
        $trackId < 1 ||
        !preg_match(
            '/^[a-f0-9]{24}$/',
            $recordingId
        )
    ) {
        throw new RuntimeException(
            'Invalid recording request.'
        );
    }

    $relative =
        '/uploads/stems/track-'
        . $trackId
        . '/recording-'
        . $recordingId;

    return [
        $relative,
        STONEFELLOW_ROOT . $relative,
    ];
}

function studio_v53_recording_state_path(
    int $trackId,
    string $recordingId
): string {
    [, $absolute] =
        studio_v53_recording_root(
            $trackId,
            $recordingId
        );

    return $absolute
        . '/.recording.json';
}

function studio_v53_load_recording(
    int $trackId,
    string $recordingId
): array {
    $path =
        studio_v53_recording_state_path(
            $trackId,
            $recordingId
        );

    if (!is_file($path)) {
        throw new RuntimeException(
            'Recording session not found.'
        );
    }

    $state = json_decode(
        (string)file_get_contents($path),
        true
    );

    if (
        !is_array($state) ||
        (int)($state['track_id'] ?? 0)
            !== $trackId ||
        (int)($state['user_id'] ?? 0)
            !== studio_v47_user_id()
    ) {
        throw new RuntimeException(
            'Invalid recording session.'
        );
    }

    return $state;
}

function studio_v53_save_recording(
    int $trackId,
    string $recordingId,
    array $state
): void {
    [, $absolute] =
        studio_v53_recording_root(
            $trackId,
            $recordingId
        );

    if (
        !is_dir($absolute) &&
        !mkdir(
            $absolute,
            0700,
            true
        ) &&
        !is_dir($absolute)
    ) {
        throw new RuntimeException(
            'Could not create recording storage.'
        );
    }

    $json = json_encode(
        $state,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    if (
        !is_string($json) ||
        file_put_contents(
            $absolute
            . '/.recording.json',
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Could not save recording state.'
        );
    }
}

function studio_v53_wav_header(
    int $dataBytes,
    int $sampleRate,
    int $channels,
    int $bitsPerSample = 16
): string {
    $channels = max(
        1,
        min(2,$channels)
    );

    $sampleRate = max(
        8000,
        min(192000,$sampleRate)
    );

    $bitsPerSample = 16;

    $blockAlign =
        $channels *
        intdiv(
            $bitsPerSample,
            8
        );

    $byteRate =
        $sampleRate *
        $blockAlign;

    return
        'RIFF'
        . pack(
            'V',
            36 + $dataBytes
        )
        . 'WAVE'
        . 'fmt '
        . pack(
            'VvvVVvv',
            16,
            1,
            $channels,
            $sampleRate,
            $byteRate,
            $blockAlign,
            $bitsPerSample
        )
        . 'data'
        . pack(
            'V',
            $dataBytes
        );
}

function studio_v53_cleanup_recording(
    int $trackId,
    string $recordingId
): void {
    [, $absolute] =
        studio_v53_recording_root(
            $trackId,
            $recordingId
        );

    if (!is_dir($absolute)) {
        return;
    }

    $items = scandir($absolute);

    if (is_array($items)) {
        foreach ($items as $item) {
            if (
                $item === '.' ||
                $item === '..'
            ) {
                continue;
            }

            $path =
                $absolute
                . DIRECTORY_SEPARATOR
                . $item;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    @rmdir($absolute);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    studio_v47_error('POST required.',405);
}

if (!verify_csrf()) {
    studio_v47_error('Session expired. Refresh Stem Studio and try again.',403);
}

$pdo = db();

if (!$pdo) {
    studio_v47_error('Database unavailable.',503);
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create_project') {
        $userId = studio_v47_user_id();
        $name = trim(
            (string)($_POST['project_name'] ?? '')
        );
        $tempo = max(
            40.0,
            min(
                300.0,
                (float)($_POST['tempo_bpm'] ?? 120)
            )
        );
        $signature = trim(
            (string)($_POST['time_signature'] ?? '4/4')
        );

        if ($name === '') {
            $name = 'Untitled Project';
        }

        $name = studio_v53_cut($name,190);

        if (!preg_match('/^\d{1,2}\/\d{1,2}$/',$signature)) {
            $signature = '4/4';
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tracks
                 (
                    owner_user_id,
                    title,
                    album,
                    duration,
                    lyrics,
                    description,
                    genre,
                    mood,
                    energy,
                    tempo_bpm,
                    keywords,
                    audio_path,
                    cover_path,
                    sort_order,
                    is_published,
                    visibility
                 )
                 VALUES
                 (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            $stmt->execute([
                $userId ?: null,
                $name,
                'Stonefellow Studio',
                '',
                '',
                '',
                '',
                '',
                '',
                (int)round($tempo),
                '',
                '',
                '/images/stonefellow-studio.png',
                0,
                0,
                'admin',
            ]);

            $trackId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO track_projects
                 (
                    track_id,
                    project_name,
                    tempo_bpm,
                    time_signature,
                    imported_by_user_id,
                    imported_at
                 )
                 VALUES (?,?,?,?,?,NOW())'
            );

            $stmt->execute([
                $trackId,
                $name,
                $tempo,
                $signature,
                $userId ?: null,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        studio_v47_json([
            'ok'=>true,
            'track_id'=>$trackId,
            'redirect'=>url('/admin/stems.php?track=' . $trackId),
        ]);
    }

    if ($action === 'save_to_account') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        studio_v47_track($trackId);

        $userId = studio_v47_user_id();

        if ($userId < 1) {
            throw new RuntimeException('Sign in before saving this project.');
        }

        $stmt = $pdo->prepare(
            'UPDATE tracks
             SET owner_user_id=?
             WHERE id=?'
        );
        $stmt->execute([$userId,$trackId]);

        studio_v47_json([
            'ok'=>true,
            'owner_user_id'=>$userId,
            'message'=>'Project saved to your account.',
        ]);
    }

    if ($action === 'delete_project') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $track = studio_v47_track($trackId);

        stem_delete_track_package($trackId);
        delete_local_upload(
            (string)($track['audio_path'] ?? '')
        );

        $stmt = $pdo->prepare(
            'DELETE FROM tracks WHERE id=?'
        );
        $stmt->execute([$trackId]);

        studio_v47_json([
            'ok'=>true,
            'redirect'=>url('/admin/tracks.php'),
        ]);
    }

    if ($action === 'recording_start') {
        $trackId =
            (int)($_POST['track_id'] ?? 0);

        studio_v47_track($trackId);

        $userId =
            studio_v47_user_id();

        if ($userId < 1) {
            throw new RuntimeException(
                'Sign in before recording audio.'
            );
        }

        $sampleRate = max(
            8000,
            min(
                192000,
                (int)($_POST['sample_rate'] ?? 48000)
            )
        );

        $channels = max(
            1,
            min(
                2,
                (int)($_POST['channels'] ?? 2)
            )
        );

        $name = trim(
            (string)(
                $_POST['track_name'] ??
                'Audio Recording'
            )
        );

        if ($name === '') {
            $name = 'Audio Recording';
        }

        $name = studio_v53_cut(
            $name,
            190
        );

        $startOffset = max(
            0.0,
            min(
                86400.0,
                (float)(
                    $_POST['start_offset'] ??
                    0
                )
            )
        );

        $sessionTempo = max(
            40.0,
            min(
                300.0,
                (float)(
                    $_POST['session_tempo'] ??
                    120
                )
            )
        );

        $deviceLabel = studio_v53_cut(
            trim(
                (string)(
                    $_POST['device_label'] ??
                    'Audio Input'
                )
            ),
            190
        );

        $recordingId =
            bin2hex(
                random_bytes(12)
            );

        [, $absolute] =
            studio_v53_recording_root(
                $trackId,
                $recordingId
            );

        if (
            !is_dir($absolute) &&
            !mkdir(
                $absolute,
                0700,
                true
            ) &&
            !is_dir($absolute)
        ) {
            throw new RuntimeException(
                'Could not create recording storage.'
            );
        }

        $state = [
            'track_id'=>$trackId,
            'user_id'=>$userId,
            'recording_id'=>$recordingId,
            'track_name'=>$name,
            'device_label'=>$deviceLabel,
            'sample_rate'=>$sampleRate,
            'channels'=>$channels,
            'bits_per_sample'=>16,
            'start_offset'=>$startOffset,
            'session_tempo'=>$sessionTempo,
            'next_chunk'=>0,
            'pcm_bytes'=>0,
            'created_at'=>time(),
        ];

        studio_v53_save_recording(
            $trackId,
            $recordingId,
            $state
        );

        $rawPath =
            $absolute
            . '/capture.pcm';

        if (
            file_put_contents(
                $rawPath,
                ''
            ) === false
        ) {
            studio_v53_cleanup_recording(
                $trackId,
                $recordingId
            );

            throw new RuntimeException(
                'Could not initialize recording data.'
            );
        }

        studio_v47_json([
            'ok'=>true,
            'recording_id'=>$recordingId,
            'sample_rate'=>$sampleRate,
            'channels'=>$channels,
            'start_offset'=>$startOffset,
            'track_name'=>$name,
        ]);
    }

    if ($action === 'recording_chunk') {
        $trackId =
            (int)($_POST['track_id'] ?? 0);
        $recordingId = trim(
            (string)(
                $_POST['recording_id'] ??
                ''
            )
        );
        $chunkIndex =
            (int)($_POST['chunk_index'] ?? -1);

        studio_v47_track($trackId);

        $state =
            studio_v53_load_recording(
                $trackId,
                $recordingId
            );

        if (
            $chunkIndex < 0 ||
            $chunkIndex !==
                (int)$state['next_chunk']
        ) {
            throw new RuntimeException(
                'Recording chunks arrived out of order.'
            );
        }

        $upload =
            $_FILES['pcm'] ?? [];

        if (
            ($upload['error'] ??
                UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException(
                'A recording chunk failed to upload.'
            );
        }

        $chunkBytes =
            (int)($upload['size'] ?? 0);

        if (
            $chunkBytes < 1 ||
            $chunkBytes >
                (2 * 1024 * 1024)
        ) {
            throw new RuntimeException(
                'Invalid recording chunk size.'
            );
        }

        $nextTotal =
            (int)$state['pcm_bytes']
            + $chunkBytes;

        if (
            $nextTotal >
            stem_max_package_bytes()
        ) {
            throw new RuntimeException(
                'Recording exceeded the configured project-media limit.'
            );
        }

        [, $absolute] =
            studio_v53_recording_root(
                $trackId,
                $recordingId
            );

        $input = fopen(
            (string)$upload['tmp_name'],
            'rb'
        );
        $output = fopen(
            $absolute
            . '/capture.pcm',
            'ab'
        );

        if (
            !$input ||
            !$output
        ) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new RuntimeException(
                'Could not write recording audio.'
            );
        }

        try {
            $written =
                stream_copy_to_stream(
                    $input,
                    $output
                );
        } finally {
            fclose($input);
            fclose($output);
        }

        if (
            $written === false ||
            (int)$written !== $chunkBytes
        ) {
            throw new RuntimeException(
                'Could not save the complete recording chunk.'
            );
        }

        $state['pcm_bytes'] =
            $nextTotal;
        $state['next_chunk'] =
            $chunkIndex + 1;

        studio_v53_save_recording(
            $trackId,
            $recordingId,
            $state
        );

        studio_v47_json([
            'ok'=>true,
            'recording_id'=>$recordingId,
            'chunk_index'=>$chunkIndex,
            'pcm_bytes'=>$nextTotal,
        ]);
    }

    if ($action === 'recording_cancel') {
        $trackId =
            (int)($_POST['track_id'] ?? 0);
        $recordingId = trim(
            (string)(
                $_POST['recording_id'] ??
                ''
            )
        );

        if (
            $trackId > 0 &&
            preg_match(
                '/^[a-f0-9]{24}$/',
                $recordingId
            )
        ) {
            try {
                studio_v53_load_recording(
                    $trackId,
                    $recordingId
                );

                studio_v53_cleanup_recording(
                    $trackId,
                    $recordingId
                );
            } catch (Throwable $e) {
                // Cancel is intentionally idempotent.
            }
        }

        studio_v47_json([
            'ok'=>true,
            'cancelled'=>true,
        ]);
    }

    if ($action === 'recording_finish') {
        $trackId =
            (int)($_POST['track_id'] ?? 0);
        $recordingId = trim(
            (string)(
                $_POST['recording_id'] ??
                ''
            )
        );

        $track =
            studio_v47_track($trackId);

        $state =
            studio_v53_load_recording(
                $trackId,
                $recordingId
            );

        $pcmBytes =
            (int)($state['pcm_bytes'] ?? 0);
        $sampleRate =
            (int)$state['sample_rate'];
        $channels =
            (int)$state['channels'];

        if ($pcmBytes < 2) {
            studio_v53_cleanup_recording(
                $trackId,
                $recordingId
            );

            throw new RuntimeException(
                'No audio was captured.'
            );
        }

        $blockAlign =
            $channels * 2;

        $pcmBytes -=
            $pcmBytes %
            max(2,$blockAlign);

        if ($pcmBytes < $blockAlign) {
            throw new RuntimeException(
                'Recorded audio was too short.'
            );
        }

        $durationSeconds =
            $pcmBytes /
            (
                $sampleRate *
                $blockAlign
            );

        if ($durationSeconds < 0.03) {
            throw new RuntimeException(
                'Recorded audio was too short.'
            );
        }

        [, $absolute] =
            studio_v53_recording_root(
                $trackId,
                $recordingId
            );

        $rawPath =
            $absolute
            . '/capture.pcm';

        if (!is_file($rawPath)) {
            throw new RuntimeException(
                'Recording audio data was not found.'
            );
        }

        $base = stem_clean_filename(
            (string)$state['track_name']
        );

        if ($base === '') {
            $base = 'Audio Recording';
        }

        $fileName =
            $base
            . '-'
            . date('Ymd-His')
            . '.wav';

        $finalPath =
            $absolute
            . '/'
            . $fileName;

        $input = fopen(
            $rawPath,
            'rb'
        );
        $output = fopen(
            $finalPath,
            'wb'
        );

        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new RuntimeException(
                'Could not finalize recorded WAV.'
            );
        }

        try {
            $header =
                studio_v53_wav_header(
                    $pcmBytes,
                    $sampleRate,
                    $channels,
                    16
                );

            if (
                fwrite(
                    $output,
                    $header
                ) === false
            ) {
                throw new RuntimeException(
                    'Could not write WAV header.'
                );
            }

            $remaining =
                $pcmBytes;

            while ($remaining > 0) {
                $buffer = fread(
                    $input,
                    min(
                        1024 * 1024,
                        $remaining
                    )
                );

                if (
                    !is_string($buffer) ||
                    $buffer === ''
                ) {
                    throw new RuntimeException(
                        'Could not read recorded PCM.'
                    );
                }

                $length =
                    strlen($buffer);

                if (
                    fwrite(
                        $output,
                        $buffer
                    ) !== $length
                ) {
                    throw new RuntimeException(
                        'Could not write recorded WAV.'
                    );
                }

                $remaining -=
                    $length;
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        @chmod(
            $finalPath,
            0600
        );

        $project =
            studio_v47_ensure_project(
                $trackId,
                (int)$state['user_id']
            );
        $projectId =
            (int)$project['id'];

        $sortStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order),-1)
             FROM track_stems
             WHERE track_id=?'
        );
        $sortStmt->execute([
            $trackId
        ]);

        $sort =
            (int)$sortStmt->fetchColumn()
            + 1;

        $relativeDir =
            '/uploads/stems/track-'
            . $trackId
            . '/recording-'
            . $recordingId;
        $relativePath =
            $relativeDir
            . '/'
            . $fileName;

        $recordingTempo = max(
            40.0,
            min(
                300.0,
                (float)$state['session_tempo']
            )
        );

        $summary =
            'Recorded input: '
            . (
                trim(
                    (string)$state['device_label']
                ) !== ''
                    ? trim(
                        (string)$state['device_label']
                    )
                    : 'Audio Input'
            )
            . ' · Recorded tempo: '
            . rtrim(
                rtrim(
                    number_format(
                        $recordingTempo,
                        2,
                        '.',
                        ''
                    ),
                    '0'
                ),
                '.'
            )
            . ' BPM';

        $pdo->beginTransaction();

        try {
            $insert = $pdo->prepare(
                'INSERT INTO track_stems
                 (
                    track_id,
                    project_id,
                    stem_name,
                    stem_role,
                    source_track_name,
                    file_name,
                    file_path,
                    channels,
                    sample_rate,
                    bit_depth,
                    duration_seconds,
                    start_offset_seconds,
                    rpp_track_guid,
                    rpp_volume,
                    rpp_pan,
                    rpp_fx_summary,
                    sort_order,
                    is_active
                 )
                 VALUES
                 (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );

            $trackName =
                (string)$state['track_name'];

            $insert->execute([
                $trackId,
                $projectId,
                $trackName,
                stem_role_from_metadata(
                    $trackName,
                    $summary
                ),
                $trackName,
                $fileName,
                $relativePath,
                $channels,
                $sampleRate,
                16,
                $durationSeconds,
                (float)$state['start_offset'],
                '',
                1,
                0,
                $summary,
                $sort,
            ]);

            $stemId =
                (int)$pdo->lastInsertId();

            $updateProject = $pdo->prepare(
                'UPDATE track_projects
                 SET
                    media_sample_rate=?,
                    project_sample_rate=
                      COALESCE(
                        project_sample_rate,
                        ?
                      )
                 WHERE id=?'
            );
            $updateProject->execute([
                $sampleRate,
                $sampleRate,
                $projectId,
            ]);

            $owner = $pdo->prepare(
                'UPDATE tracks
                 SET owner_user_id=
                   COALESCE(
                     owner_user_id,
                     ?
                   )
                 WHERE id=?'
            );
            $owner->execute([
                (int)$state['user_id'],
                $trackId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            @unlink($finalPath);

            throw $e;
        }

        @unlink($rawPath);
        @unlink(
            studio_v53_recording_state_path(
                $trackId,
                $recordingId
            )
        );

        studio_v47_refresh_track_duration(
            $trackId
        );

        studio_v47_json([
            'ok'=>true,
            'stem_id'=>$stemId,
            'track_name'=>
                (string)$state['track_name'],
            'duration'=>$durationSeconds,
            'start_offset'=>
                (float)$state['start_offset'],
            'sample_rate'=>$sampleRate,
            'channels'=>$channels,
            'message'=>'Recording saved as a Studio track.',
        ]);
    }

    if ($action === 'add_library_stem') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $sourceStemId = (int)($_POST['source_stem_id'] ?? 0);

        studio_v47_track($trackId);

        if ($sourceStemId < 1) {
            throw new RuntimeException(
                'Choose a valid library stem.'
            );
        }

        $stmt = $pdo->prepare(
            "SELECT
                s.*,
                t.title AS source_song_title,
                COALESCE(
                    NULLIF(p.tempo_bpm,0),
                    NULLIF(t.tempo_bpm,0),
                    120
                ) AS source_tempo,
                COALESCE(
                    NULLIF(p.time_signature,''),
                    '4/4'
                ) AS source_signature
             FROM track_stems s
             INNER JOIN tracks t
               ON t.id=s.track_id
             LEFT JOIN track_projects p
               ON p.track_id=s.track_id
             WHERE s.id=?
               AND s.is_active=1
             LIMIT 1"
        );
        $stmt->execute([$sourceStemId]);
        $source = $stmt->fetch();

        if (!$source) {
            throw new RuntimeException(
                'The selected library stem is no longer available.'
            );
        }

        $sourceRelative = trim(
            (string)$source['file_path']
        );

        if (
            $sourceRelative === '' ||
            !str_starts_with(
                $sourceRelative,
                '/uploads/stems/'
            )
        ) {
            throw new RuntimeException(
                'The selected library stem does not have protected local media.'
            );
        }

        $uploadsRoot = realpath(
            STONEFELLOW_ROOT . '/uploads'
        );
        $sourceAbsolute = realpath(
            STONEFELLOW_ROOT . '/'
            . ltrim(
                $sourceRelative,
                '/'
            )
        );

        if (
            !$uploadsRoot ||
            !$sourceAbsolute ||
            !is_file($sourceAbsolute) ||
            !str_starts_with(
                $sourceAbsolute,
                rtrim(
                    $uploadsRoot,
                    DIRECTORY_SEPARATOR
                ) . DIRECTORY_SEPARATOR
            )
        ) {
            throw new RuntimeException(
                'The selected library media could not be read.'
            );
        }

        $requestedSourceStart = max(
            0.0,
            (float)($_POST['source_start'] ?? 0)
        );

        $sourceDuration = max(
            0.05,
            (float)$source['duration_seconds']
        );

        $signature = trim(
            (string)($source['source_signature'] ?? '4/4')
        );

        $quarterBeatsPerBar = 4.0;

        if (
            preg_match(
                '/^(\d{1,2})\/(\d{1,2})$/',
                $signature,
                $signatureMatch
            )
        ) {
            $numerator = max(
                1,
                (int)$signatureMatch[1]
            );
            $denominator = max(
                1,
                (int)$signatureMatch[2]
            );

            $quarterBeatsPerBar =
                $numerator
                * (
                    4.0 /
                    $denominator
                );
        }

        $sourceTempo = max(
            40.0,
            min(
                300.0,
                (float)($source['source_tempo'] ?? 120)
            )
        );

        $fourBarSourceSeconds =
            4.0
            * $quarterBeatsPerBar
            * 60.0
            / $sourceTempo;

        $libraryClipStart = min(
            max(
                0.0,
                $sourceDuration - 0.02
            ),
            $requestedSourceStart
        );

        $libraryClipEnd = min(
            $sourceDuration,
            $libraryClipStart +
                $fourBarSourceSeconds
        );

        if (
            $libraryClipEnd -
                $libraryClipStart <
            min(
                $fourBarSourceSeconds,
                $sourceDuration
            ) - 0.02
        ) {
            $libraryClipStart = max(
                0.0,
                $sourceDuration -
                    $fourBarSourceSeconds
            );
            $libraryClipEnd =
                $sourceDuration;
        }

        $userId = studio_v47_user_id();
        $project = studio_v47_ensure_project(
            $trackId,
            $userId
        );
        $projectId = (int)$project['id'];

        $token = bin2hex(
            random_bytes(8)
        );
        $relativeDir =
            '/uploads/stems/track-'
            . $trackId
            . '/library-'
            . $token;
        $absoluteDir =
            STONEFELLOW_ROOT
            . $relativeDir;

        if (
            !is_dir($absoluteDir) &&
            !mkdir(
                $absoluteDir,
                0700,
                true
            ) &&
            !is_dir($absoluteDir)
        ) {
            throw new RuntimeException(
                'Could not create the library-track media directory.'
            );
        }

        $extension = stem_lower(
            pathinfo(
                (string)$source['file_name'],
                PATHINFO_EXTENSION
            )
        );

        if (
            !in_array(
                $extension,
                ['wav','mp3'],
                true
            )
        ) {
            $extension = stem_lower(
                pathinfo(
                    $sourceAbsolute,
                    PATHINFO_EXTENSION
                )
            );
        }

        if (
            !in_array(
                $extension,
                ['wav','mp3'],
                true
            )
        ) {
            throw new RuntimeException(
                'Only WAV and MP3 library media can be inserted.'
            );
        }

        $baseName = stem_clean_filename(
            pathinfo(
                (string)$source['stem_name'],
                PATHINFO_FILENAME
            )
        );

        if ($baseName === '') {
            $baseName = 'library-track';
        }

        $storedName =
            $baseName
            . '-'
            . substr($token,0,6)
            . '.'
            . $extension;

        $destinationAbsolute =
            $absoluteDir
            . '/'
            . $storedName;
        $destinationRelative =
            $relativeDir
            . '/'
            . $storedName;

        if (
            !copy(
                $sourceAbsolute,
                $destinationAbsolute
            )
        ) {
            throw new RuntimeException(
                'Could not copy the library stem into this project.'
            );
        }

        @chmod(
            $destinationAbsolute,
            0600
        );

        $sortStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order),-1)
             FROM track_stems
             WHERE track_id=?'
        );
        $sortStmt->execute([$trackId]);
        $sort =
            (int)$sortStmt->fetchColumn()
            + 1;

        $pdo->beginTransaction();

        try {
            $insert = $pdo->prepare(
                'INSERT INTO track_stems
                 (
                    track_id,
                    project_id,
                    stem_name,
                    stem_role,
                    source_track_name,
                    file_name,
                    file_path,
                    channels,
                    sample_rate,
                    bit_depth,
                    duration_seconds,
                    start_offset_seconds,
                    rpp_track_guid,
                    rpp_volume,
                    rpp_pan,
                    rpp_fx_summary,
                    sort_order,
                    is_active
                 )
                 VALUES
                 (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );

            $insert->execute([
                $trackId,
                $projectId,
                (string)$source['stem_name'],
                (string)$source['stem_role'],
                (string)(
                    $source['source_track_name']
                    ?: $source['stem_name']
                ),
                $storedName,
                $destinationRelative,
                (int)$source['channels'],
                (int)$source['sample_rate'],
                (int)$source['bit_depth'],
                (float)$source['duration_seconds'],
                0,
                '',
                1,
                0,
                stem_cut(
                    trim(
                        (string)$source['rpp_fx_summary']
                        . ' · Library tempo: '
                        . rtrim(
                            rtrim(
                                number_format(
                                    max(
                                        40.0,
                                        min(
                                            300.0,
                                            (float)($source['source_tempo'] ?? 120)
                                        )
                                    ),
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                        . ' BPM'
                        . ' · Library clip start: '
                        . rtrim(
                            rtrim(
                                number_format(
                                    $libraryClipStart,
                                    3,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                        . ' · Library clip end: '
                        . rtrim(
                            rtrim(
                                number_format(
                                    $libraryClipEnd,
                                    3,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                        . ' · Library signature: '
                        . $signature
                        . (
                            !empty($source['source_song_title'])
                                ? ' · Library source: '
                                  . (string)$source['source_song_title']
                                : ''
                        )
                    ),
                    1000
                ),
                $sort,
            ]);

            $newStemId =
                (int)$pdo->lastInsertId();

            $owner = $pdo->prepare(
                'UPDATE tracks
                 SET owner_user_id=
                   COALESCE(owner_user_id,?)
                 WHERE id=?'
            );
            $owner->execute([
                $userId ?: null,
                $trackId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            @unlink(
                $destinationAbsolute
            );
            stem_cleanup_empty_parent(
                $destinationRelative
            );

            throw $e;
        }

        studio_v47_refresh_track_duration(
            $trackId
        );

        studio_v47_json([
            'ok'=>true,
            'stem_id'=>$newStemId,
            'source_stem_id'=>$sourceStemId,
            'name'=>(string)$source['stem_name'],
            'role'=>(string)$source['stem_role'],
            'duration'=>(float)$source['duration_seconds'],
            'source_tempo'=>$sourceTempo,
            'source_start'=>$libraryClipStart,
            'source_end'=>$libraryClipEnd,
            'source_signature'=>$signature,
            'message'=>'Library stem added as a standard Studio track.',
        ]);
    }

    if ($action === 'delete_stem') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $stemId = (int)($_POST['stem_id'] ?? 0);

        studio_v47_track($trackId);

        $stmt = $pdo->prepare(
            'SELECT *
             FROM track_stems
             WHERE id=? AND track_id=?
             LIMIT 1'
        );
        $stmt->execute([$stemId,$trackId]);
        $stem = $stmt->fetch();

        if (!$stem) {
            throw new RuntimeException('Track lane not found.');
        }

        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare(
                'DELETE FROM track_stems
                 WHERE id=? AND track_id=?'
            );
            $delete->execute([$stemId,$trackId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        stem_delete_path_if_local(
            (string)$stem['file_path']
        );
        stem_cleanup_empty_parent(
            (string)$stem['file_path']
        );

        studio_v47_refresh_track_duration($trackId);

        studio_v47_json([
            'ok'=>true,
            'stem_id'=>$stemId,
            'message'=>'Track deleted.',
        ]);
    }

    if ($action === 'import_init') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        studio_v47_track($trackId);

        $userId = studio_v47_user_id();
        $files = json_decode(
            (string)($_POST['files_json'] ?? ''),
            true
        );

        if (!is_array($files) || !$files) {
            throw new RuntimeException('Choose one or more WAV/MP3 files.');
        }

        if (count($files) > 96) {
            throw new RuntimeException(
                'Import up to 96 media files at a time.'
            );
        }

        $clean = [];
        $totalBytes = 0;

        foreach ($files as $index=>$file) {
            if (!is_array($file)) {
                throw new RuntimeException('Invalid media import list.');
            }

            $name = stem_clean_filename(
                (string)($file['name'] ?? '')
            );
            $extension = stem_lower(
                pathinfo($name,PATHINFO_EXTENSION)
            );
            $size = (int)($file['size'] ?? 0);
            $duration = max(
                0.0,
                min(
                    86400.0,
                    (float)($file['duration'] ?? 0)
                )
            );

            if (!in_array($extension,['wav','mp3'],true)) {
                throw new RuntimeException(
                    $name . ': only WAV and MP3 are supported.'
                );
            }

            if ($size < 1) {
                throw new RuntimeException(
                    $name . ': the selected file is empty.'
                );
            }

            $totalBytes += $size;

            if ($totalBytes > stem_max_package_bytes()) {
                throw new RuntimeException(
                    'The selected media is larger than the configured Stem Studio import limit.'
                );
            }

            $base = pathinfo($name,PATHINFO_FILENAME);
            $stored = sprintf(
                '%03d-%s.%s',
                $index + 1,
                stem_clean_filename($base),
                $extension
            );

            $clean[] = [
                'name'=>$name,
                'stored_name'=>$stored,
                'extension'=>$extension,
                'size'=>$size,
                'duration'=>$duration,
                'next_chunk'=>0,
                'total_chunks'=>0,
                'written'=>0,
                'complete'=>false,
            ];
        }

        $requestId = bin2hex(random_bytes(12));

        studio_v47_save_state(
            $trackId,
            $requestId,
            [
                'track_id'=>$trackId,
                'user_id'=>$userId,
                'files'=>$clean,
                'created_at'=>time(),
            ]
        );

        studio_v47_json([
            'ok'=>true,
            'request_id'=>$requestId,
            'chunk_bytes'=>stem_chunk_bytes(),
            'file_count'=>count($clean),
            'total_bytes'=>$totalBytes,
        ]);
    }

    if ($action === 'import_chunk') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $requestId = trim(
            (string)($_POST['request_id'] ?? '')
        );
        $fileIndex = (int)($_POST['file_index'] ?? -1);
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);

        studio_v47_track($trackId);
        $state = studio_v47_load_state(
            $trackId,
            $requestId
        );

        if (!isset($state['files'][$fileIndex])) {
            throw new RuntimeException('Unknown media file.');
        }

        $file = $state['files'][$fileIndex];

        if (
            $chunkIndex < 0 ||
            $totalChunks < 1 ||
            $chunkIndex >= $totalChunks ||
            (int)$file['next_chunk'] !== $chunkIndex
        ) {
            throw new RuntimeException('Media chunks arrived out of order.');
        }

        $upload = $_FILES['chunk'] ?? [];

        if (
            ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('A media chunk failed to upload.');
        }

        $chunkSize = (int)($upload['size'] ?? 0);

        if (
            $chunkSize < 1 ||
            $chunkSize > stem_chunk_bytes() + 2048
        ) {
            throw new RuntimeException('Invalid media chunk size.');
        }

        [, $absolute] = studio_v47_import_root(
            $trackId,
            $requestId
        );

        $target = $absolute . '/'
            . (string)$file['stored_name'];

        $input = fopen(
            (string)$upload['tmp_name'],
            'rb'
        );
        $output = fopen(
            $target,
            $chunkIndex === 0
                ? 'wb'
                : 'ab'
        );

        if (!$input || !$output) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Could not write imported media.');
        }

        try {
            $written = stream_copy_to_stream(
                $input,
                $output
            );
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($written === false) {
            throw new RuntimeException('Could not save imported media.');
        }

        $file['next_chunk'] =
            $chunkIndex + 1;
        $file['total_chunks'] =
            $totalChunks;
        $file['written'] =
            (int)$file['written'] +
            (int)$written;

        if ($file['next_chunk'] >= $totalChunks) {
            $actual = filesize($target) ?: 0;

            if ($actual !== (int)$file['size']) {
                throw new RuntimeException(
                    $file['name'] . ': uploaded size did not match the selected file.'
                );
            }

            $file['complete'] = true;
        }

        $state['files'][$fileIndex] = $file;

        studio_v47_save_state(
            $trackId,
            $requestId,
            $state
        );

        studio_v47_json([
            'ok'=>true,
            'file_index'=>$fileIndex,
            'chunk_index'=>$chunkIndex,
            'complete'=>(bool)$file['complete'],
        ]);
    }

    if ($action === 'import_commit') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $requestId = trim(
            (string)($_POST['request_id'] ?? '')
        );
        $track = studio_v47_track($trackId);
        $userId = studio_v47_user_id();

        $state = studio_v47_load_state(
            $trackId,
            $requestId
        );

        foreach ($state['files'] as $file) {
            if (empty($file['complete'])) {
                throw new RuntimeException(
                    'Finish uploading every selected media file before committing.'
                );
            }
        }

        $project = studio_v47_ensure_project(
            $trackId,
            $userId
        );
        $projectId = (int)$project['id'];

        [, $absolute] = studio_v47_import_root(
            $trackId,
            $requestId
        );
        [$relative] = studio_v47_import_root(
            $trackId,
            $requestId
        );

        $sortStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order),-1)
             FROM track_stems
             WHERE track_id=?'
        );
        $sortStmt->execute([$trackId]);
        $sort = (int)$sortStmt->fetchColumn() + 1;

        $inserted = [];
        $firstSampleRate = 0;

        $pdo->beginTransaction();

        try {
            $insert = $pdo->prepare(
                'INSERT INTO track_stems
                 (
                    track_id,
                    project_id,
                    stem_name,
                    stem_role,
                    source_track_name,
                    file_name,
                    file_path,
                    channels,
                    sample_rate,
                    bit_depth,
                    duration_seconds,
                    start_offset_seconds,
                    rpp_track_guid,
                    rpp_volume,
                    rpp_pan,
                    rpp_fx_summary,
                    sort_order,
                    is_active
                 )
                 VALUES
                 (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );

            foreach ($state['files'] as $file) {
                $path = $absolute . '/'
                    . (string)$file['stored_name'];

                $info = studio_v47_validate_audio(
                    $path,
                    (string)$file['extension']
                );

                $duration = (float)(
                    $info['duration_seconds'] ?? 0
                );

                if ($duration <= 0) {
                    $duration = max(
                        0.05,
                        (float)($file['duration'] ?? 0)
                    );
                }

                $channels = max(
                    0,
                    (int)($info['channels'] ?? 0)
                );
                $sampleRate = max(
                    0,
                    (int)($info['sample_rate'] ?? 0)
                );
                $bitDepth = max(
                    0,
                    (int)($info['bit_depth'] ?? 0)
                );

                if (
                    $firstSampleRate < 1 &&
                    $sampleRate > 0
                ) {
                    $firstSampleRate = $sampleRate;
                }

                $displayName = trim(
                    pathinfo(
                        (string)$file['name'],
                        PATHINFO_FILENAME
                    )
                );

                if ($displayName === '') {
                    $displayName = 'Imported Track ' . ($sort + 1);
                }

                $role = stem_role_from_metadata(
                    $displayName
                );

                $publicPath = $relative . '/'
                    . (string)$file['stored_name'];

                $insert->execute([
                    $trackId,
                    $projectId,
                    studio_v53_cut($displayName,190),
                    $role,
                    studio_v53_cut($displayName,190),
                    (string)$file['name'],
                    $publicPath,
                    $channels,
                    $sampleRate,
                    $bitDepth,
                    $duration,
                    0,
                    '',
                    1,
                    0,
                    '',
                    $sort,
                ]);

                $inserted[] = [
                    'id'=>(int)$pdo->lastInsertId(),
                    'name'=>$displayName,
                    'role'=>$role,
                    'duration'=>$duration,
                    'sample_rate'=>$sampleRate,
                    'channels'=>$channels,
                    'bit_depth'=>$bitDepth,
                ];

                $sort++;
            }

            if ($firstSampleRate > 0) {
                $updateProject = $pdo->prepare(
                    'UPDATE track_projects
                     SET
                       media_sample_rate=?,
                       project_sample_rate=
                         COALESCE(project_sample_rate,?)
                     WHERE id=?'
                );
                $updateProject->execute([
                    $firstSampleRate,
                    $firstSampleRate,
                    $projectId,
                ]);
            }

            $owner = $pdo->prepare(
                'UPDATE tracks
                 SET owner_user_id=COALESCE(owner_user_id,?)
                 WHERE id=?'
            );
            $owner->execute([
                $userId ?: null,
                $trackId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        @unlink(
            studio_v47_state_path(
                $trackId,
                $requestId
            )
        );

        studio_v47_refresh_track_duration($trackId);

        studio_v47_json([
            'ok'=>true,
            'track_id'=>$trackId,
            'imported'=>$inserted,
            'count'=>count($inserted),
            'message'=>count($inserted)
                . ' media track'
                . (count($inserted) === 1 ? '' : 's')
                . ' imported.',
        ]);
    }

    studio_v47_error('Unknown Studio project action.',404);
} catch (Throwable $e) {
    studio_v47_error($e,400);
}
