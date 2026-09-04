<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@set_time_limit(55);
ob_start();

require dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('tracks.manage');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$requestId = date('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
const STONEFELLOW_STEM_IMPORTER_BUILD = 'v24';
$responded = false;

function stem_api_log(string $requestId, string $message): void
{
    $base = defined('STONEFELLOW_ROOT') ? STONEFELLOW_ROOT : dirname(__DIR__);
    $path = $base . '/private/stem-import.log';
    $line = '[' . date('c') . '] [' . $requestId . '] ' . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function stem_upload_json(array $payload, int $status = 200): never
{
    global $requestId, $responded;

    $responded = true;
    $payload['request_id'] = $requestId;
    $payload['importer_build'] = STONEFELLOW_STEM_IMPORTER_BUILD;

    $noise = '';
    if (ob_get_level() > 0) {
        $noise = trim((string)ob_get_clean());
    }

    if ($noise !== '') {
        stem_api_log($requestId, 'Suppressed output: ' . mb_substr(strip_tags($noise), 0, 2000));
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
    global $requestId, $responded;

    if ($responded) {
        return;
    }

    $error = error_get_last();
    if (!$error || !in_array((int)$error['type'], [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ], true)) {
        return;
    }

    $message = (string)($error['message'] ?? 'Fatal PHP error');
    stem_api_log(
        $requestId,
        'Fatal: ' . $message . ' in ' .
        (string)($error['file'] ?? '') . ':' .
        (string)($error['line'] ?? '')
    );

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'ok'=>false,
        'error'=>'The stem importer stopped because of a server-side PHP error.',
        'request_id'=>$requestId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

function stem_session_state_path(string $dir): string
{
    return $dir . '/import-state.json';
}

function stem_load_state(string $dir): array
{
    $path = stem_session_state_path($dir);
    if (!is_file($path)) {
        throw new RuntimeException('The prepared import session was not found.');
    }

    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) {
        throw new RuntimeException('The prepared import session is damaged.');
    }

    return $state;
}

function stem_save_state(string $dir, array $state): void
{
    $encoded = json_encode(
        $state,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (!is_string($encoded) || file_put_contents(
        stem_session_state_path($dir),
        $encoded,
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Could not save the stem import state.');
    }
}

function stem_remove_session_dir(string $dir, array $state = [], bool $removeStaged = false): void
{
    if ($removeStaged) {
        foreach (($state['staged_paths'] ?? []) as $relative) {
            stem_delete_path_if_local((string)$relative);
            stem_cleanup_empty_parent((string)$relative);
        }
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}

function stem_prepare_import(
    string $dir,
    int $trackId,
    int $userId,
    array $manifest
): array {
    global $requestId;

    $assembled = $dir . '/assembled.zip';

    if (!is_file($assembled)) {
        throw new RuntimeException('The assembled ZIP is missing.');
    }

    $backend = stem_zip_backend();

    if ($backend === '') {
        throw new RuntimeException(
            'This server does not provide a usable ZIP extraction method.'
        );
    }

    $entries = stem_zip_list_entries($assembled);

    if (!$entries) {
        throw new RuntimeException('The uploaded ZIP is empty.');
    }

    if (count($entries) > 2500) {
        throw new RuntimeException('The REAPER package contains too many files.');
    }

    $rppEntries = [];
    $mp3Entries = [];
    $wavEntries = [];
    $consolidatedEntries = [];

    foreach ($entries as $entry) {
        $entryName = (string)$entry['name'];
        $lower = mb_strtolower($entryName);

        if (
            str_ends_with($lower, '.rpp') ||
            str_ends_with($lower, '.rpp-bak')
        ) {
            $rppEntries[] = $entry;
        }

        if (str_ends_with($lower, '.mp3')) {
            $mp3Entries[] = $entry;
        }

        if (str_ends_with($lower, '.wav')) {
            $wavEntries[] = $entry;

            if (
                str_contains(
                    mb_strtolower(basename($entryName)),
                    'consolidated'
                )
            ) {
                $consolidatedEntries[] = $entry;
            }
        }
    }

    // Hosted listening preference:
    // 1) MP3 stems
    // 2) consolidated WAV stems
    // 3) ordinary WAV files
    $selected = $mp3Entries ?: ($consolidatedEntries ?: $wavEntries);

    if (!$selected) {
        throw new RuntimeException(
            'The ZIP was readable, but no MP3 or WAV stem files were found.'
        );
    }

    if (count($selected) > 96) {
        throw new RuntimeException(
            'This package has more than 96 candidate stems. '
            . 'Export a consolidated stem set before importing.'
        );
    }

    $originalName = stem_clean_filename(
        (string)($manifest['file_name'] ?? 'project.zip')
    );

    $rppName = '';
    $rppInfo = [
        'project_name'=>preg_replace('/\.zip$/i', '', $originalName) ?: 'REAPER Project',
        'tempo_bpm'=>null,
        'time_signature'=>'',
        'project_sample_rate'=>null,
        'tracks'=>[],
        'file_map'=>[],
    ];

    $importToken = date('Ymd-His') . '-'
        . substr(bin2hex(random_bytes(6)), 0, 12);

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
        throw new RuntimeException('Could not create the stem storage directory.');
    }

    if (
        !is_dir($projectDir) &&
        !mkdir($projectDir, 0755, true) &&
        !is_dir($projectDir)
    ) {
        throw new RuntimeException('Could not create the project storage directory.');
    }

    $stagedPaths = [];
    $projectPath = '';

    // Extract only the small REAPER project at prepare time.
    if ($rppEntries) {
        $rppEntry = (string)$rppEntries[0]['name'];
        $rppName = stem_clean_filename(basename($rppEntry));

        if (str_ends_with(mb_strtolower($rppName), '.rpp-bak')) {
            $rppName = preg_replace('/\.rpp-bak$/i', '.rpp', $rppName)
                ?: 'project.rpp';
        }

        $projectFile = $importToken . '-' . $rppName;
        $projectAbsolute = $projectDir . '/' . $projectFile;

        stem_zip_extract_entry(
            $assembled,
            $rppEntry,
            $projectAbsolute
        );

        $projectPath = '/uploads/projects/track-' . $trackId
            . '/' . $projectFile;

        $stagedPaths[] = $projectPath;

        $rppText = (string)@file_get_contents($projectAbsolute);

        if ($rppText !== '') {
            $rppInfo = stem_parse_rpp(
                $rppText,
                $rppName
            );
        }
    }

    $selectedMeta = [];
    $positions = [];
    $selectedKnownBytes = 0;

    foreach ($selected as $order=>$entry) {
        $entryName = (string)$entry['name'];
        $sourceBase = stem_clean_filename(basename($entryName));
        $map = stem_match_rpp_file(
            $rppInfo['file_map'] ?? [],
            $sourceBase
        );

        $position = (float)($map['position'] ?? 0.0);
        $positions[] = $position;
        $selectedKnownBytes += max(0, (int)($entry['size'] ?? 0));

        $selectedMeta[] = [
            'entry_name'=>$entryName,
            'source_base'=>$sourceBase,
            'position'=>$position,
            'sort_order'=>$order + 1,
        ];
    }

    $projectStart = $positions ? min($positions) : 0.0;

    // On CLI-unzip hosts the listing may not include entry sizes.
    // MP3 archives compress very little, so the ZIP size is a useful
    // conservative hosted-storage estimate. WAV fallback gets a larger factor.
    $archiveBytes = filesize($assembled) ?: 0;

    if ($selectedKnownBytes > 0) {
        $requiredBytes = $selectedKnownBytes + (48 * 1024 * 1024);
    } else {
        $factor = $mp3Entries ? 1.35 : 4.0;
        $requiredBytes = (int)($archiveBytes * $factor)
            + (48 * 1024 * 1024);
    }

    $free = @disk_free_space(STONEFELLOW_ROOT);

    if (is_numeric($free) && (float)$free < $requiredBytes) {
        throw new RuntimeException(
            'The ZIP is readable, but the server does not have enough free '
            . 'space for the selected stems. About '
            . number_format($requiredBytes / 1024 / 1024, 0)
            . ' MB is recommended for this import.'
        );
    }

    $state = [
        'track_id'=>$trackId,
        'user_id'=>$userId,
        'original_name'=>$originalName,
        'assembled'=>$assembled,
        'zip_backend'=>$backend,
        'import_token'=>$importToken,
        'stem_dir'=>$stemDir,
        'project_dir'=>$projectDir,
        'project_path'=>$projectPath,
        'rpp_name'=>$rppName,
        'rpp_info'=>$rppInfo,
        'project_start'=>$projectStart,
        'selected'=>$selectedMeta,
        'next_index'=>0,
        'rows'=>[],
        'staged_paths'=>$stagedPaths,
        'media_sample_rates'=>[],
        'max_end'=>0.0,
        'used_mp3'=>(bool)$mp3Entries,
        'used_consolidated'=>!$mp3Entries && (bool)$consolidatedEntries,
        'ignored_raw_wavs'=>$mp3Entries
            ? count($wavEntries)
            : max(0, count($wavEntries) - count($selected)),
    ];

    stem_save_state($dir, $state);

    stem_api_log(
        $requestId,
        'Prepared track=' . $trackId
        . ' backend=' . $backend
        . ' entries=' . count($entries)
        . ' stems=' . count($selectedMeta)
        . ' format=' . ($state['used_mp3'] ? 'MP3' : 'WAV')
    );

    return $state;
}


function stem_import_one(string $dir, array $state, array $track): array
{
    global $requestId;

    $index = (int)($state['next_index'] ?? 0);
    $selected = $state['selected'] ?? [];

    if ($index >= count($selected)) {
        return $state;
    }

    $item = $selected[$index];
    $assembled = (string)$state['assembled'];

    if (!is_file($assembled)) {
        throw new RuntimeException('The uploaded ZIP is no longer available.');
    }

    $sourceBase = stem_clean_filename(
        (string)$item['source_base']
    );

    $savedBase = str_pad(
        (string)($index + 1),
        2,
        '0',
        STR_PAD_LEFT
    ) . '-' . $sourceBase;

    $absolute = rtrim(
        (string)$state['stem_dir'],
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR . $savedBase;

    // Extract exactly one audio entry per request.
    stem_zip_extract_entry(
        $assembled,
        (string)$item['entry_name'],
        $absolute
    );

    $relative = '/uploads/stems/track-'
        . (int)$state['track_id']
        . '/' . (string)$state['import_token']
        . '/' . $savedBase;

    $state['staged_paths'][] = $relative;

    $map = stem_match_rpp_file(
        $state['rpp_info']['file_map'] ?? [],
        $sourceBase
    );

    $fallbackDuration = stem_parse_duration_string(
        (string)($track['duration'] ?? '')
    );

    $audioInfo = stem_audio_info(
        $absolute,
        $map,
        $fallbackDuration
    );

    $sourceTrackName = trim(
        (string)($map['track_name'] ?? '')
    );
    $fxSummary = trim(
        (string)($map['fx_summary'] ?? '')
    );

    $role = stem_role_from_metadata(
        $sourceTrackName . ' ' . $sourceBase,
        $fxSummary
    );

    $baseStemName = preg_replace(
        '/-consolidated(?=\.(wav|mp3)$)/i',
        '',
        $sourceBase
    ) ?: $sourceBase;

    $baseStemName = preg_replace(
        '/^\d{1,3}-/',
        '',
        $baseStemName
    ) ?: $baseStemName;

    $baseStemName = preg_replace(
        '/\.(wav|mp3)$/i',
        '',
        $baseStemName
    ) ?: $baseStemName;

    if ($sourceTrackName !== '') {
        $stemName = $sourceTrackName;
    } elseif ($role === 'Vocal') {
        preg_match('/^(\d{1,3})/', $sourceBase, $numberMatch);
        $stemName = 'Vocal '
            . ($numberMatch[1] ?? (string)($index + 1));
    } else {
        $stemName = trim($baseStemName) !== ''
            ? trim($baseStemName)
            : ('Stem ' . ($index + 1));
    }

    $position = (float)($item['position'] ?? 0.0);
    $offset = max(
        0.0,
        $position - (float)($state['project_start'] ?? 0.0)
    );

    $duration = (float)$audioInfo['duration_seconds'];

    $row = [
        'stem_name'=>mb_substr($stemName, 0, 190),
        'stem_role'=>mb_substr($role, 0, 80),
        'source_track_name'=>mb_substr($sourceTrackName, 0, 190),
        'file_name'=>$sourceBase,
        'file_path'=>$relative,
        'channels'=>(int)$audioInfo['channels'],
        'sample_rate'=>(int)$audioInfo['sample_rate'],
        'bit_depth'=>(int)$audioInfo['bit_depth'],
        'duration_seconds'=>$duration,
        'start_offset_seconds'=>$offset,
        'rpp_track_guid'=>mb_substr(
            (string)($map['track_guid'] ?? ''),
            0,
            80
        ),
        'rpp_volume'=>(float)($map['volume'] ?? 1.0),
        'rpp_pan'=>(float)($map['pan'] ?? 0.0),
        'rpp_fx_summary'=>mb_substr($fxSummary, 0, 1000),
        'sort_order'=>$index + 1,
    ];

    $state['rows'][] = $row;
    $state['next_index'] = $index + 1;

    if ((int)$audioInfo['sample_rate'] > 0) {
        $state['media_sample_rates'][] = (int)$audioInfo['sample_rate'];
        $state['media_sample_rates'] = array_values(
            array_unique($state['media_sample_rates'])
        );
        sort($state['media_sample_rates']);
    }

    $state['max_end'] = max(
        (float)($state['max_end'] ?? 0),
        $offset + $duration
    );

    stem_save_state($dir, $state);

    stem_api_log(
        $requestId,
        'Extracted stem ' . $state['next_index']
        . '/' . count($selected)
        . ' backend=' . (string)($state['zip_backend'] ?? 'unknown')
        . ' file=' . $sourceBase
    );

    return $state;
}


function stem_commit_import(
    string $dir,
    array $state,
    array $track
): array {
    global $requestId;

    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }

    $selected = $state['selected'] ?? [];
    $rows = $state['rows'] ?? [];

    if (
        count($rows) !== count($selected) ||
        (int)($state['next_index'] ?? 0) !== count($selected)
    ) {
        throw new RuntimeException('Not all stems have been imported yet.');
    }

    $trackId = (int)$state['track_id'];
    $userId = (int)$state['user_id'];
    $rppInfo = $state['rpp_info'] ?? [];
    $sampleRates = array_values(
        array_unique(
            array_map('intval', $state['media_sample_rates'] ?? [])
        )
    );
    sort($sampleRates);
    $primaryRate = $sampleRates[0] ?? 0;

    $oldProject = stem_project_for_track($trackId);
    $oldStems = stems_for_track($trackId);
    $oldPaths = array_filter(array_column($oldStems, 'file_path'));

    if ($oldProject && !empty($oldProject['rpp_file_path'])) {
        $oldPaths[] = (string)$oldProject['rpp_file_path'];
    }

    $pdo->beginTransaction();

    try {
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
                (string)($rppInfo['project_name'] ?? ''),
                (string)$state['original_name'],
                (string)$state['rpp_name'],
                (string)$state['project_path'],
                $rppInfo['tempo_bpm'] ?? null,
                (string)($rppInfo['time_signature'] ?? ''),
                $rppInfo['project_sample_rate'] ?? null,
                $primaryRate ?: null,
                (float)($state['project_start'] ?? 0),
                $userId ?: null,
                $projectId,
            ]);

            $pdo->prepare(
                'DELETE FROM track_stems WHERE track_id=?'
            )->execute([$trackId]);
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
                (string)($rppInfo['project_name'] ?? ''),
                (string)$state['original_name'],
                (string)$state['rpp_name'],
                (string)$state['project_path'],
                $rppInfo['tempo_bpm'] ?? null,
                (string)($rppInfo['time_signature'] ?? ''),
                $rppInfo['project_sample_rate'] ?? null,
                $primaryRate ?: null,
                (float)($state['project_start'] ?? 0),
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

        foreach ($rows as $row) {
            $insert->execute([
                $trackId,
                $projectId,
                $row['stem_name'],
                $row['stem_role'],
                $row['source_track_name'],
                $row['file_name'],
                $row['file_path'],
                (int)$row['channels'],
                (int)$row['sample_rate'],
                (int)$row['bit_depth'],
                round((float)$row['duration_seconds'], 4),
                round((float)$row['start_offset_seconds'], 4),
                $row['rpp_track_guid'],
                round((float)$row['rpp_volume'], 6),
                round((float)$row['rpp_pan'], 6),
                $row['rpp_fx_summary'],
                (int)$row['sort_order'],
            ]);
        }

        $updateFields = [];
        $updateValues = [];

        if (
            (int)($track['tempo_bpm'] ?? 0) < 1 &&
            !empty($rppInfo['tempo_bpm'])
        ) {
            $updateFields[] = 'tempo_bpm=?';
            $updateValues[] = (int)round((float)$rppInfo['tempo_bpm']);
        }

        if (
            trim((string)($track['duration'] ?? '')) === '' &&
            (float)($state['max_end'] ?? 0) > 0
        ) {
            $updateFields[] = 'duration=?';
            $updateValues[] = stem_format_duration(
                (float)$state['max_end']
            );
        }

        if ($updateFields) {
            $updateValues[] = $trackId;
            $pdo->prepare(
                'UPDATE tracks SET '
                . implode(',', $updateFields)
                . ' WHERE id=?'
            )->execute($updateValues);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    foreach ($oldPaths as $oldPath) {
        stem_delete_path_if_local((string)$oldPath);
        stem_cleanup_empty_parent((string)$oldPath);
    }

    @unlink((string)$state['assembled']);
    stem_remove_session_dir($dir, [], false);

    $summary = [
        'project_id'=>$projectId,
        'track_id'=>$trackId,
        'project_name'=>(string)($rppInfo['project_name'] ?? ''),
        'stem_count'=>count($rows),
        'tempo_bpm'=>$rppInfo['tempo_bpm'] ?? null,
        'time_signature'=>(string)($rppInfo['time_signature'] ?? ''),
        'project_sample_rate'=>$rppInfo['project_sample_rate'] ?? null,
        'media_sample_rates'=>$sampleRates,
        'duration_seconds'=>(float)($state['max_end'] ?? 0),
        'used_mp3'=>(bool)($state['used_mp3'] ?? false),
        'used_consolidated'=>(bool)($state['used_consolidated'] ?? false),
        'ignored_raw_wavs'=>(int)($state['ignored_raw_wavs'] ?? 0),
    ];

    stem_api_log(
        $requestId,
        'Committed track ' . $trackId
        . ': ' . count($rows) . ' stems'
    );

    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stem_upload_json(['ok'=>false,'error'=>'POST required.'], 405);
}

if (!verify_csrf()) {
    stem_upload_json(['ok'=>false,'error'=>'Session expired.'], 419);
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
    stem_upload_json(['ok'=>false,'error'=>'Invalid upload request.'], 400);
}

$pdo = db();
$stmt = $pdo?->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$stmt?->execute([$trackId]);
$track = $stmt ? $stmt->fetch() : false;

if (!$track) {
    stem_upload_json(['ok'=>false,'error'=>'Track not found.'], 404);
}

try {
    stem_cleanup_stale_uploads();
    $dir = stem_upload_root($userId, $uploadId);

    if ($action === 'probe') {
        stem_upload_json([
            'ok'=>true,
            'phase'=>'probe',
            'zip_backend'=>stem_zip_backend(),
            'native_zip'=>stem_native_zip_supported(),
            'php_version'=>PHP_VERSION,
            'memory_limit'=>(string)ini_get('memory_limit'),
            'max_execution_time'=>(string)ini_get('max_execution_time'),
        ]);
    }

    if ($action === 'chunk') {
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        $originalName = stem_clean_filename(
            (string)($_POST['file_name'] ?? 'project.zip')
        );
        $fileSize = (int)($_POST['file_size'] ?? 0);

        if (
            $chunkIndex < 0 ||
            $totalChunks < 1 ||
            $chunkIndex >= $totalChunks ||
            $totalChunks > 4096
        ) {
            throw new RuntimeException('Invalid chunk information.');
        }

        if (
            $fileSize < 1 ||
            $fileSize > stem_max_package_bytes() ||
            !str_ends_with(mb_strtolower($originalName), '.zip')
        ) {
            throw new RuntimeException(
                'Select a ZIP file within the configured project size limit.'
            );
        }

        $chunk = $_FILES['chunk'] ?? [];

        if (
            ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('A project upload chunk failed.');
        }

        $chunkSize = (int)($chunk['size'] ?? 0);

        if (
            $chunkSize < 1 ||
            $chunkSize > stem_chunk_bytes() + 1024
        ) {
            throw new RuntimeException('Invalid upload chunk size.');
        }

        if (
            !is_dir($dir) &&
            !mkdir($dir, 0700, true) &&
            !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Could not create the temporary upload directory.'
            );
        }

        file_put_contents(
            $dir . '/manifest.json',
            json_encode([
                'track_id'=>$trackId,
                'file_name'=>$originalName,
                'file_size'=>$fileSize,
                'total_chunks'=>$totalChunks,
                'created_at'=>time(),
            ]),
            LOCK_EX
        );

        $destination = $dir
            . '/chunk-'
            . str_pad(
                (string)$chunkIndex,
                6,
                '0',
                STR_PAD_LEFT
            );

        if (
            !move_uploaded_file(
                (string)$chunk['tmp_name'],
                $destination
            )
        ) {
            throw new RuntimeException('Could not save an upload chunk.');
        }

        stem_upload_json([
            'ok'=>true,
            'phase'=>'upload',
            'chunk_index'=>$chunkIndex,
            'total_chunks'=>$totalChunks,
        ]);
    }

    if ($action === 'assemble_start') {
        $manifestPath = $dir . '/manifest.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException('Upload session was not found.');
        }

        $manifest = json_decode(
            (string)file_get_contents($manifestPath),
            true
        );

        if (
            !is_array($manifest) ||
            (int)($manifest['track_id'] ?? 0) !== $trackId
        ) {
            throw new RuntimeException(
                'Upload session does not match this track.'
            );
        }

        $assembled = $dir . '/assembled.zip';
        $handle = fopen($assembled, 'wb');

        if (!$handle) {
            throw new RuntimeException(
                'Could not create the assembled project ZIP.'
            );
        }

        fclose($handle);

        $assembly = [
            'next_chunk'=>0,
            'total_chunks'=>(int)($manifest['total_chunks'] ?? 0),
            'expected_size'=>(int)($manifest['file_size'] ?? 0),
            'written'=>0,
        ];

        if (
            $assembly['total_chunks'] < 1 ||
            $assembly['expected_size'] < 1
        ) {
            throw new RuntimeException('The upload manifest is invalid.');
        }

        file_put_contents(
            $dir . '/assembly-state.json',
            json_encode($assembly),
            LOCK_EX
        );

        stem_upload_json([
            'ok'=>true,
            'phase'=>'assembly_started',
            'total_chunks'=>$assembly['total_chunks'],
        ]);
    }

    if ($action === 'assemble_step') {
        $assemblyPath = $dir . '/assembly-state.json';
        $manifestPath = $dir . '/manifest.json';

        if (!is_file($assemblyPath) || !is_file($manifestPath)) {
            throw new RuntimeException('The assembly session was not found.');
        }

        $assembly = json_decode(
            (string)file_get_contents($assemblyPath),
            true
        );

        if (!is_array($assembly)) {
            throw new RuntimeException('The assembly state is damaged.');
        }

        $index = (int)($assembly['next_chunk'] ?? -1);
        $totalChunks = (int)($assembly['total_chunks'] ?? 0);

        if ($index < 0 || $index >= $totalChunks) {
            throw new RuntimeException('The assembly cursor is invalid.');
        }

        $part = $dir
            . '/chunk-'
            . str_pad((string)$index, 6, '0', STR_PAD_LEFT);

        if (!is_file($part)) {
            throw new RuntimeException(
                'Upload chunk ' . ($index + 1) . ' is missing.'
            );
        }

        $assembled = $dir . '/assembled.zip';
        $input = fopen($part, 'rb');
        $output = fopen($assembled, 'ab');

        if (!$input || !$output) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException(
                'Could not append an upload chunk to the project ZIP.'
            );
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($copied === false) {
            throw new RuntimeException(
                'Could not assemble upload chunk ' . ($index + 1) . '.'
            );
        }

        // Once safely appended, remove this source chunk immediately.
        @unlink($part);

        $assembly['written'] = (int)($assembly['written'] ?? 0)
            + (int)$copied;
        $assembly['next_chunk'] = $index + 1;

        file_put_contents(
            $assemblyPath,
            json_encode($assembly),
            LOCK_EX
        );

        $done = $assembly['next_chunk'] >= $totalChunks;

        if ($done) {
            $expected = (int)($assembly['expected_size'] ?? 0);
            $actual = filesize($assembled) ?: 0;

            if ($actual !== $expected) {
                throw new RuntimeException(
                    'The assembled ZIP is incomplete. Expected '
                    . $expected . ' bytes but created ' . $actual . '.'
                );
            }
        }

        stem_upload_json([
            'ok'=>true,
            'phase'=>'assembling',
            'completed'=>(int)$assembly['next_chunk'],
            'total'=>$totalChunks,
            'done'=>$done,
        ]);
    }

    if ($action === 'prepare') {
        $manifestPath = $dir . '/manifest.json';
        $assemblyPath = $dir . '/assembly-state.json';
        $assembled = $dir . '/assembled.zip';

        if (
            !is_file($manifestPath) ||
            !is_file($assemblyPath) ||
            !is_file($assembled)
        ) {
            throw new RuntimeException(
                'The assembled project is not ready for inspection.'
            );
        }

        $manifest = json_decode(
            (string)file_get_contents($manifestPath),
            true
        );
        $assembly = json_decode(
            (string)file_get_contents($assemblyPath),
            true
        );

        if (
            !is_array($manifest) ||
            !is_array($assembly) ||
            (int)($manifest['track_id'] ?? 0) !== $trackId
        ) {
            throw new RuntimeException('The prepared upload state is invalid.');
        }

        if (
            (int)($assembly['next_chunk'] ?? 0) <
            (int)($assembly['total_chunks'] ?? 0)
        ) {
            throw new RuntimeException('The ZIP has not finished assembling.');
        }

        $state = stem_prepare_import(
            $dir,
            $trackId,
            $userId,
            $manifest
        );

        @unlink($manifestPath);
        @unlink($assemblyPath);

        stem_upload_json([
            'ok'=>true,
            'phase'=>'prepared',
            'total_stems'=>count($state['selected'] ?? []),
            'format'=>($state['used_mp3'] ?? false)
                ? 'MP3'
                : 'WAV',
        ]);
    }

    if ($action === 'import_step') {
        $state = stem_load_state($dir);
        $state = stem_import_one($dir, $state, $track);

        $completed = (int)$state['next_index'];
        $total = count($state['selected'] ?? []);

        stem_upload_json([
            'ok'=>true,
            'phase'=>'importing',
            'completed'=>$completed,
            'total'=>$total,
            'done'=>$completed >= $total,
            'last_stem'=>$state['rows'][$completed - 1]['stem_name']
                ?? '',
        ]);
    }

    if ($action === 'commit') {
        $state = stem_load_state($dir);
        $summary = stem_commit_import(
            $dir,
            $state,
            $track
        );

        stem_upload_json([
            'ok'=>true,
            'phase'=>'complete',
            'summary'=>$summary,
            'studio_url'=>url(
                '/admin/stems.php?track=' . $trackId
            ),
        ]);
    }

    if ($action === 'abort') {
        $state = [];
        if (is_file(stem_session_state_path($dir))) {
            try {
                $state = stem_load_state($dir);
            } catch (Throwable $ignored) {}
        }

        stem_remove_session_dir(
            $dir,
            $state,
            true
        );

        stem_upload_json([
            'ok'=>true,
            'phase'=>'aborted',
        ]);
    }

    throw new RuntimeException('Unknown stem upload action.');
} catch (Throwable $e) {
    stem_api_log(
        $requestId,
        'Error action=' . $action
        . ' track=' . $trackId
        . ': ' . $e->getMessage()
    );

    stem_upload_json([
        'ok'=>false,
        'error'=>$e->getMessage(),
    ], 400);
}
