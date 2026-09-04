<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@set_time_limit(45);
ob_start();

require dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('tracks.manage');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$requestId = date('YmdHis') . '-' . substr(bin2hex(random_bytes(5)), 0, 10);
const STONEFELLOW_DIRECT_BUILD = 'v25';
$responded = false;

function direct_stem_log(string $requestId, string $message): void
{
    $path = STONEFELLOW_ROOT . '/private/stem-import.log';
    @file_put_contents(
        $path,
        '[' . date('c') . '] [' . $requestId . '] DIRECT ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function direct_stem_json(array $payload, int $status = 200): never
{
    global $requestId, $responded;

    $responded = true;
    $payload['request_id'] = $requestId;
    $payload['importer_build'] = STONEFELLOW_DIRECT_BUILD;

    $noise = '';
    while (ob_get_level() > 0) {
        $noise .= (string)ob_get_clean();
    }

    if (trim($noise) !== '') {
        direct_stem_log(
            $requestId,
            'Suppressed output: ' . mb_substr(strip_tags($noise), 0, 1500)
        );
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

register_shutdown_function(static function (): void {
    global $responded, $requestId;

    if ($responded) {
        return;
    }

    $error = error_get_last();
    if (!$error || !in_array((int)$error['type'], [
        E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR
    ], true)) {
        return;
    }

    direct_stem_log(
        $requestId,
        'Fatal: ' . (string)$error['message']
        . ' in ' . (string)$error['file']
        . ':' . (string)$error['line']
    );

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode([
        'ok'=>false,
        'error'=>'The direct MP3 importer stopped because of a server PHP error.',
        'request_id'=>$requestId,
    ]);
});

function direct_stem_session_dir(int $userId, string $uploadId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        throw new RuntimeException('Invalid upload session.');
    }

    return STONEFELLOW_ROOT
        . '/private/direct-stem-uploads/u' . $userId
        . '/' . $uploadId;
}

function direct_stem_state_path(string $dir): string
{
    return $dir . '/state.json';
}

function direct_stem_load_state(string $dir): array
{
    $path = direct_stem_state_path($dir);

    if (!is_file($path)) {
        throw new RuntimeException('Direct stem upload session was not found.');
    }

    $state = json_decode((string)file_get_contents($path), true);

    if (!is_array($state)) {
        throw new RuntimeException('Direct stem upload state is damaged.');
    }

    return $state;
}

function direct_stem_save_state(string $dir, array $state): void
{
    $json = json_encode(
        $state,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (
        !is_string($json) ||
        file_put_contents(direct_stem_state_path($dir), $json, LOCK_EX) === false
    ) {
        throw new RuntimeException('Could not save direct stem upload state.');
    }
}

function direct_stem_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}

function direct_stem_clean_sessions(): void
{
    $root = STONEFELLOW_ROOT . '/private/direct-stem-uploads';
    $cutoff = time() - 86400;

    foreach (glob($root . '/u*/*') ?: [] as $dir) {
        if (!is_dir($dir) || (filemtime($dir) ?: time()) >= $cutoff) {
            continue;
        }

        direct_stem_cleanup($dir);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    direct_stem_json(['ok'=>false,'error'=>'POST required.'], 405);
}

if (!verify_csrf()) {
    direct_stem_json(['ok'=>false,'error'=>'Session expired.'], 419);
}

$user = current_user();
$userId = (int)($user['id'] ?? 0);
$trackId = (int)($_POST['track_id'] ?? 0);
$uploadId = strtolower(trim((string)($_POST['upload_id'] ?? '')));
$action = trim((string)($_POST['action'] ?? ''));

if (
    $userId < 1 ||
    $trackId < 1 ||
    !preg_match('/^[a-f0-9]{32}$/', $uploadId)
) {
    direct_stem_json(['ok'=>false,'error'=>'Invalid direct stem request.'], 400);
}

$pdo = db();
$stmt = $pdo?->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$stmt?->execute([$trackId]);
$track = $stmt ? $stmt->fetch() : false;

if (!$track) {
    direct_stem_json(['ok'=>false,'error'=>'Track not found.'], 404);
}

try {
    direct_stem_clean_sessions();
    $dir = direct_stem_session_dir($userId, $uploadId);

    if ($action === 'probe') {
        direct_stem_json([
            'ok'=>true,
            'phase'=>'probe',
            'php_version'=>PHP_VERSION,
            'memory_limit'=>(string)ini_get('memory_limit'),
        ]);
    }

    if ($action === 'init') {
        $filesJson = (string)($_POST['files_json'] ?? '');
        $files = json_decode($filesJson, true);

        $hasRpp = (int)($_POST['has_rpp'] ?? 0) === 1;

        if (!is_array($files) || count($files) > 96) {
            throw new RuntimeException('Select up to 96 MP3 or WAV stem files.');
        }

        if (!$files && !$hasRpp) {
            throw new RuntimeException('Select at least one MP3/WAV stem or a REAPER .rpp project file.');
        }

        $totalBytes = 0;
        $cleanFiles = [];

        foreach ($files as $index=>$file) {
            $name = stem_clean_filename((string)($file['name'] ?? ''));
            $size = (int)($file['size'] ?? 0);
            $duration = max(0.0, (float)($file['duration'] ?? 0));

            $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (
                $name === '' ||
                !in_array($extension, ['mp3','wav'], true) ||
                $size < 1
            ) {
                throw new RuntimeException(
                    'Browser ZIP import accepts MP3 or WAV stems only.'
                );
            }

            $totalBytes += $size;

            $cleanFiles[] = [
                'index'=>(int)$index,
                'name'=>$name,
                'size'=>$size,
                'duration'=>$duration,
                'next_chunk'=>0,
                'total_chunks'=>0,
                'written'=>0,
                'complete'=>false,
            ];
        }

        if ($totalBytes > stem_max_package_bytes()) {
            throw new RuntimeException('The selected stems exceed the configured upload limit.');
        }

        $free = @disk_free_space(STONEFELLOW_ROOT);
        $required = $totalBytes + (48 * 1024 * 1024);

        if (is_numeric($free) && (float)$free < $required) {
            throw new RuntimeException(
                'Not enough server disk space. These stems need about '
                . number_format($required / 1024 / 1024, 0)
                . ' MB including a small safety reserve.'
            );
        }

        if (
            !is_dir($dir) &&
            !mkdir($dir, 0700, true) &&
            !is_dir($dir)
        ) {
            throw new RuntimeException('Could not create the direct upload directory.');
        }

        $state = [
            'track_id'=>$trackId,
            'user_id'=>$userId,
            'files'=>$cleanFiles,
            'total_bytes'=>$totalBytes,
            'expects_rpp'=>$hasRpp,
            'rpp_name'=>'',
            'rpp_path'=>'',
            'created_at'=>time(),
        ];

        direct_stem_save_state($dir, $state);

        direct_stem_log(
            $requestId,
            'Init track=' . $trackId
            . ' files=' . count($cleanFiles)
            . ' bytes=' . $totalBytes
        );

        direct_stem_json([
            'ok'=>true,
            'phase'=>'initialized',
            'file_count'=>count($cleanFiles),
            'total_bytes'=>$totalBytes,
            'expects_rpp'=>$hasRpp,
        ]);
    }

    if ($action === 'file_chunk') {
        $state = direct_stem_load_state($dir);
        $fileIndex = (int)($_POST['file_index'] ?? -1);
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);

        if (!isset($state['files'][$fileIndex])) {
            throw new RuntimeException('Unknown stem file.');
        }

        $fileState = $state['files'][$fileIndex];

        if (
            $chunkIndex < 0 ||
            $totalChunks < 1 ||
            $chunkIndex >= $totalChunks
        ) {
            throw new RuntimeException('Invalid direct stem chunk.');
        }

        if ((int)$fileState['next_chunk'] !== $chunkIndex) {
            throw new RuntimeException(
                'Stem chunks arrived out of order. Expected chunk '
                . ((int)$fileState['next_chunk'] + 1) . '.'
            );
        }

        $chunk = $_FILES['chunk'] ?? [];
        if (($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('A direct stem chunk failed to upload.');
        }

        $chunkSize = (int)($chunk['size'] ?? 0);
        if ($chunkSize < 1 || $chunkSize > stem_chunk_bytes() + 1024) {
            throw new RuntimeException('Invalid direct stem chunk size.');
        }

        $stemExtension = mb_strtolower(
            pathinfo((string)$fileState['name'], PATHINFO_EXTENSION)
        );
        $partPath = $dir
            . '/stem-'
            . str_pad((string)$fileIndex, 3, '0', STR_PAD_LEFT)
            . '.'
            . $stemExtension;
        $input = fopen((string)$chunk['tmp_name'], 'rb');
        $output = fopen($partPath, $chunkIndex === 0 ? 'wb' : 'ab');

        if (!$input || !$output) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Could not write the uploaded stem.');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($copied === false) {
            throw new RuntimeException('Could not save the uploaded stem.');
        }

        $fileState['next_chunk'] = $chunkIndex + 1;
        $fileState['total_chunks'] = $totalChunks;
        $fileState['written'] = (int)$fileState['written'] + (int)$copied;

        if ($fileState['next_chunk'] >= $totalChunks) {
            $actual = filesize($partPath) ?: 0;

            if ($actual !== (int)$fileState['size']) {
                throw new RuntimeException(
                    'Stem size mismatch for ' . $fileState['name'] . '.'
                );
            }

            $fileState['complete'] = true;
        }

        $state['files'][$fileIndex] = $fileState;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'uploading_stem',
            'file_index'=>$fileIndex,
            'chunk_index'=>$chunkIndex,
            'complete'=>(bool)$fileState['complete'],
        ]);
    }

    if ($action === 'rpp') {
        $state = direct_stem_load_state($dir);
        $rpp = $_FILES['rpp'] ?? [];

        if (($rpp['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            direct_stem_json([
                'ok'=>true,
                'phase'=>'rpp_skipped',
            ]);
        }

        if (($rpp['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The REAPER project file could not be uploaded.');
        }

        $name = stem_clean_filename((string)($rpp['name'] ?? 'project.rpp'));
        $size = (int)($rpp['size'] ?? 0);
        $tmpName = (string)($rpp['tmp_name'] ?? '');

        // REAPER projects are plain-text files. Validate the actual file
        // signature rather than relying only on the filename extension.
        // This also accepts .RPP, .rpp-bak, and renamed project files.
        $header = '';
        if ($tmpName !== '' && is_file($tmpName)) {
            $handle = @fopen($tmpName, 'rb');
            if ($handle) {
                $header = (string)fread($handle, 512);
                fclose($handle);
            }
        }

        $looksLikeReaper = (bool)preg_match(
            '/^\s*<REAPER_PROJECT\b/i',
            $header
        );

        if (
            $size < 1 ||
            $size > 64 * 1024 * 1024 ||
            !$looksLikeReaper
        ) {
            throw new RuntimeException(
                'The selected file is not a readable REAPER project. '
                . 'Received "' . $name . '" (' .
                number_format(max(0, $size) / 1024, 1) .
                ' KB). Select the actual REAPER .rpp project file, not a ZIP, '
                . 'audio file, or .reapeaks file.'
            );
        }

        // Keep the original filename when possible, but normalize backup or
        // extensionless names to .rpp for stored project metadata.
        $lowerName = mb_strtolower($name);
        if (!str_ends_with($lowerName, '.rpp')) {
            $base = preg_replace('/\.rpp-bak$/i', '', $name) ?: $name;
            $base = preg_replace('/\.[^.]+$/', '', $base) ?: $base;
            $name = stem_clean_filename($base . '.rpp');
        }

        $destination = $dir . '/project.rpp';

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not save the REAPER project file.');
        }

        $state['rpp_name'] = $name;
        $state['rpp_path'] = $destination;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'rpp_uploaded',
            'rpp_name'=>$name,
        ]);
    }

    if ($action === 'commit') {
        $state = direct_stem_load_state($dir);

        foreach ($state['files'] as $file) {
            if (empty($file['complete'])) {
                throw new RuntimeException(
                    'Stem upload is incomplete: ' . (string)$file['name']
                );
            }
        }

        $existingProject = stem_project_for_track($trackId);

        $rppInfo = [
            'project_name'=>$existingProject['project_name'] ?? (string)$track['title'],
            'tempo_bpm'=>$existingProject['tempo_bpm'] ?? null,
            'time_signature'=>$existingProject['time_signature'] ?? '',
            'project_sample_rate'=>$existingProject['project_sample_rate'] ?? null,
            'tracks'=>[],
            'file_map'=>[],
        ];

        if (
            !empty($state['rpp_path']) &&
            is_file((string)$state['rpp_path'])
        ) {
            $rppText = (string)file_get_contents((string)$state['rpp_path']);
            $rppInfo = stem_parse_rpp(
                $rppText,
                (string)$state['rpp_name']
            );
        }

        $positions = [];

        foreach ($state['files'] as $file) {
            $map = stem_match_rpp_file(
                $rppInfo['file_map'] ?? [],
                (string)$file['name']
            );

            $positions[] = (float)($map['position'] ?? 0.0);
        }

        $projectStart = $positions ? min($positions) : 0.0;
        $importToken = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);

        $stemDir = STONEFELLOW_ROOT
            . '/uploads/stems/track-' . $trackId
            . '/' . $importToken;

        $projectDir = STONEFELLOW_ROOT
            . '/uploads/projects/track-' . $trackId;

        if (
            !is_dir($stemDir) &&
            !mkdir($stemDir, 0755, true) &&
            !is_dir($stemDir)
        ) {
            throw new RuntimeException('Could not create final stem storage.');
        }

        if (
            !is_dir($projectDir) &&
            !mkdir($projectDir, 0755, true) &&
            !is_dir($projectDir)
        ) {
            throw new RuntimeException('Could not create final project storage.');
        }

        $rows = [];
        $newPaths = [];
        $maxEnd = 0.0;
        $projectPath = $existingProject['rpp_file_path'] ?? '';
        $projectFileName = $existingProject['rpp_file_name'] ?? '';

        try {
            foreach ($state['files'] as $index=>$file) {
                $stemExtension = mb_strtolower(
                    pathinfo((string)$file['name'], PATHINFO_EXTENSION)
                );
                $temp = $dir
                    . '/stem-'
                    . str_pad((string)$index, 3, '0', STR_PAD_LEFT)
                    . '.'
                    . $stemExtension;
                $savedName = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)
                    . '-' . stem_clean_filename((string)$file['name']);
                $final = $stemDir . '/' . $savedName;

                if (!@rename($temp, $final)) {
                    throw new RuntimeException(
                        'Could not move ' . (string)$file['name']
                        . ' into final stem storage.'
                    );
                }

                $relative = '/uploads/stems/track-' . $trackId
                    . '/' . $importToken
                    . '/' . $savedName;

                $newPaths[] = $relative;

                $map = stem_match_rpp_file(
                    $rppInfo['file_map'] ?? [],
                    (string)$file['name']
                );

                $sourceTrackName = trim((string)($map['track_name'] ?? ''));
                $fxSummary = trim((string)($map['fx_summary'] ?? ''));
                $role = stem_role_from_metadata(
                    $sourceTrackName . ' ' . (string)$file['name'],
                    $fxSummary
                );

                $baseName = preg_replace(
                    '/-consolidated(?=\.(mp3|wav)$)/i',
                    '',
                    (string)$file['name']
                ) ?: (string)$file['name'];

                $baseName = preg_replace('/\.(mp3|wav)$/i', '', $baseName) ?: $baseName;
                $baseName = preg_replace('/^\d{1,3}-/', '', $baseName) ?: $baseName;

                $stemName = $sourceTrackName !== ''
                    ? $sourceTrackName
                    : trim($baseName);

                if ($stemName === '') {
                    $stemName = 'Stem ' . ($index + 1);
                }

                $position = (float)($map['position'] ?? 0.0);
                $offset = max(0.0, $position - $projectStart);
                $duration = max(0.0, (float)($file['duration'] ?? 0));
                if ($duration <= 0 && is_array($map)) {
                    $duration = max(0.0, (float)($map['length'] ?? 0));
                }

                $maxEnd = max($maxEnd, $offset + $duration);

                $rows[] = [
                    'stem_name'=>mb_substr($stemName, 0, 190),
                    'stem_role'=>mb_substr($role, 0, 80),
                    'source_track_name'=>mb_substr($sourceTrackName, 0, 190),
                    'file_name'=>(string)$file['name'],
                    'file_path'=>$relative,
                    'channels'=>0,
                    'sample_rate'=>0,
                    'bit_depth'=>0,
                    'duration_seconds'=>$duration,
                    'start_offset_seconds'=>$offset,
                    'rpp_track_guid'=>mb_substr((string)($map['track_guid'] ?? ''), 0, 80),
                    'rpp_volume'=>(float)($map['volume'] ?? 1.0),
                    'rpp_pan'=>(float)($map['pan'] ?? 0.0),
                    'rpp_fx_summary'=>mb_substr($fxSummary, 0, 1000),
                    'sort_order'=>$index + 1,
                ];
            }

            if (
                !empty($state['rpp_path']) &&
                is_file((string)$state['rpp_path'])
            ) {
                $projectFile = $importToken . '-'
                    . stem_clean_filename((string)$state['rpp_name']);
                $projectFinal = $projectDir . '/' . $projectFile;

                if (!@rename((string)$state['rpp_path'], $projectFinal)) {
                    throw new RuntimeException('Could not move the REAPER project file.');
                }

                $projectPath = '/uploads/projects/track-' . $trackId
                    . '/' . $projectFile;
                $projectFileName = (string)$state['rpp_name'];

                $newPaths[] = $projectPath;
            }

            $oldProject = stem_project_for_track($trackId);
            $oldStems = stems_for_track($trackId);
            $oldPaths = array_filter(array_column($oldStems, 'file_path'));

            if (
                $oldProject &&
                !empty($oldProject['rpp_file_path']) &&
                !empty($state['rpp_path'])
            ) {
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
                    (string)($rppInfo['project_name'] ?? $track['title']),
                    'Browser ZIP stems',
                    $projectFileName,
                    $projectPath,
                    $rppInfo['tempo_bpm'] ?? null,
                    (string)($rppInfo['time_signature'] ?? ''),
                    $rppInfo['project_sample_rate'] ?? null,
                    null,
                    $projectStart,
                    $userId,
                    $projectId,
                ]);

                if (!empty($rows)) {
                    $pdo->prepare(
                        'DELETE FROM track_stems WHERE track_id=?'
                    )->execute([$trackId]);
                }
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
                    (string)($rppInfo['project_name'] ?? $track['title']),
                    'Browser ZIP stems',
                    $projectFileName,
                    $projectPath,
                    $rppInfo['tempo_bpm'] ?? null,
                    (string)($rppInfo['time_signature'] ?? ''),
                    $rppInfo['project_sample_rate'] ?? null,
                    null,
                    $projectStart,
                    $userId,
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

            foreach ($rows as $row) {
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

            if (
                (int)($track['tempo_bpm'] ?? 0) < 1 &&
                !empty($rppInfo['tempo_bpm'])
            ) {
                $pdo->prepare(
                    'UPDATE tracks SET tempo_bpm=? WHERE id=?'
                )->execute([
                    (int)round((float)$rppInfo['tempo_bpm']),
                    $trackId,
                ]);
            }

            if (
                trim((string)($track['duration'] ?? '')) === '' &&
                $maxEnd > 0
            ) {
                $pdo->prepare(
                    'UPDATE tracks SET duration=? WHERE id=?'
                )->execute([
                    stem_format_duration($maxEnd),
                    $trackId,
                ]);
            }

            $pdo->commit();

            foreach ($oldPaths as $oldPath) {
                stem_delete_path_if_local((string)$oldPath);
                stem_cleanup_empty_parent((string)$oldPath);
            }

            direct_stem_cleanup($dir);

            direct_stem_log(
                $requestId,
                'Committed track=' . $trackId
                . ' stems=' . count($rows)
            );

            direct_stem_json([
                'ok'=>true,
                'phase'=>'complete',
                'stem_count'=>count($rows),
                'studio_url'=>url('/admin/stems.php?track=' . $trackId),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($newPaths as $path) {
                stem_delete_path_if_local((string)$path);
                stem_cleanup_empty_parent((string)$path);
            }

            throw $e;
        }
    }

    if ($action === 'abort') {
        direct_stem_cleanup($dir);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'aborted',
        ]);
    }

    throw new RuntimeException('Unknown direct stem upload action.');
} catch (Throwable $e) {
    direct_stem_log(
        $requestId,
        'Error action=' . $action
        . ' track=' . $trackId
        . ': ' . $e->getMessage()
    );

    direct_stem_json([
        'ok'=>false,
        'error'=>$e->getMessage(),
    ], 400);
}
