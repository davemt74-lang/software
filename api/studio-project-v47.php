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

    $stmt = $pdo->prepare(
        'SELECT COALESCE(
            MAX(start_offset_seconds + duration_seconds),
            0
         )
         FROM track_stems
         WHERE track_id=? AND is_active=1'
    );
    $stmt->execute([$trackId]);

    $seconds = max(
        0.0,
        (float)$stmt->fetchColumn()
    );

    $duration = $seconds > 0
        ? stem_format_duration($seconds)
        : '';

    $update = $pdo->prepare(
        'UPDATE tracks
         SET duration=?
         WHERE id=?'
    );
    $update->execute([$duration,$trackId]);
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

        $name = mb_substr($name,0,190);

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
                    mb_substr($displayName,0,190),
                    $role,
                    mb_substr($displayName,0,190),
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
