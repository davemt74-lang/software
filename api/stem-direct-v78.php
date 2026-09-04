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
const STONEFELLOW_DIRECT_BUILD = 'v78';
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
        'error'=>'Stem importer PHP fatal: '
            . (string)($error['message'] ?? 'unknown error')
            . ' at '
            . basename((string)($error['file'] ?? 'unknown'))
            . ':'
            . (string)($error['line'] ?? 0),
        'request_id'=>$requestId,
        'importer_build'=>STONEFELLOW_DIRECT_BUILD,
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


function direct_v78_plugins(mixed $value): array
{
    $values = is_array($value)
        ? $value
        : preg_split('/\s*[,|;]\s*/', (string)$value);
    $clean = [];

    foreach ($values ?: [] as $plugin) {
        $plugin = trim((string)$plugin);
        if ($plugin === '' || str_starts_with($plugin, 'Preset: ')) {
            continue;
        }
        $plugin = preg_replace('/^(?:VST3?|AU|AUi|CLAP|LV2|DX|JS)\s*:\s*/i', '', $plugin) ?: $plugin;
        $plugin = mb_substr($plugin, 0, 160);
        if (!in_array($plugin, $clean, true)) {
            $clean[] = $plugin;
        }
        if (count($clean) >= 30) {
            break;
        }
    }

    return $clean;
}

function direct_v78_instrument(string $value, string $role = 'Other'): string
{
    $source = ' ' . stem_lower($value) . ' ';
    $tests = [
        'Lead Vocal'=>['lead vocal','lead vox','main vocal','main vox'],
        'Backing Vocal'=>['backing vocal','background vocal','backing vox','bgv','harmony vocal'],
        'Kick Drum'=>['kick'],
        'Snare Drum'=>['snare'],
        'Hi-Hat'=>['hi-hat','hi hat','hihat'],
        'Cymbals'=>['cymbal','crash','ride'],
        'Toms'=>['tom'],
        'Drum Kit'=>['drum kit','drums','overhead','drum room'],
        'Percussion'=>['percussion','shaker','tambourine','conga','bongo','cowbell','clap'],
        'Electric Bass'=>['electric bass','bass guitar'],
        'Upright Bass'=>['upright bass','double bass'],
        'Acoustic Guitar'=>['acoustic guitar','acoustic gtr','ac gtr'],
        'Electric Guitar'=>['electric guitar','electric gtr','elec gtr','distorted guitar','clean guitar'],
        'Mandolin'=>['mandolin'],
        'Banjo'=>['banjo'],
        'Dobro'=>['dobro'],
        'Piano'=>['piano'],
        'Rhodes'=>['rhodes'],
        'Wurlitzer'=>['wurlitzer'],
        'Organ'=>['organ'],
        'Synth Pad'=>['synth pad','pad'],
        'Synth Lead'=>['synth lead','lead synth'],
        'Strings'=>['strings','violin','viola','cello'],
        'Brass'=>['brass','trumpet','trombone','horn'],
        'Saxophone'=>['saxophone','sax'],
        'Flute'=>['flute'],
    ];

    foreach ($tests as $label=>$needles) {
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                return $label;
            }
        }
    }

    return match ($role) {
        'Vocal'=>'Vocal',
        'Drums'=>'Drum Kit',
        'Percussion'=>'Percussion',
        'Bass'=>'Bass',
        'Guitar'=>'Guitar',
        'Keys'=>'Keys',
        'Synth'=>'Synth',
        default=>'',
    };
}

function direct_v78_stem_base_name(string $fileName): string
{
    $name = preg_replace('/-consolidated(?=\.(mp3|wav)$)/i', '', $fileName) ?: $fileName;
    $name = preg_replace('/\.(mp3|wav)$/i', '', $name) ?: $name;
    $name = preg_replace('/^\d{1,3}-/', '', $name) ?: $name;
    return trim($name);
}

function direct_v78_review_stem(array $stems, string $fileName): ?array
{
    $needle = stem_normalized_source_key($fileName);
    foreach ($stems as $stem) {
        if (!is_array($stem)) continue;
        if (stem_normalized_source_key((string)($stem['file_name'] ?? '')) === $needle) {
            return $stem;
        }
    }
    return null;
}

function direct_v78_make_keywords(array $values): string
{
    $parts = [];
    $keys = [];
    foreach ($values as $value) {
        foreach (preg_split('/\s*[,;|]\s*/', (string)$value) ?: [] as $part) {
            $part = trim($part);
            $key = stem_lower($part);
            if ($part !== '' && !isset($keys[$key])) {
                $parts[] = $part;
                $keys[$key] = true;
            }
        }
    }
    return mb_substr(implode(', ', $parts), 0, 500);
}

function direct_v78_prepare_review(array $state, array $track, string $dir): array
{
    $rpp = is_array($state['rpp_info'] ?? null) ? $state['rpp_info'] : [];
    $tempo = (float)($rpp['tempo_bpm'] ?? $track['tempo_bpm'] ?? 0);
    if ($tempo <= 0) $tempo = 120.0;

    $stems = [];
    $instruments = [];
    $allPlugins = direct_v78_plugins($rpp['plugins'] ?? []);
    $analysis = [];
    $detectedSampleRate = (int)($rpp['project_sample_rate'] ?? 0);

    foreach (($state['files'] ?? []) as $index=>$file) {
        if (empty($file['complete'])) {
            throw new RuntimeException('Stem upload is incomplete: ' . (string)($file['name'] ?? 'unknown'));
        }

        $fileName = (string)$file['name'];
        $extension = stem_lower(pathinfo($fileName, PATHINFO_EXTENSION));
        $temp = $dir . '/stem-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '.' . $extension;
        if (!is_file($temp)) {
            throw new RuntimeException('Uploaded stem is missing during metadata analysis: ' . $fileName);
        }

        $map = stem_match_rpp_file($rpp['file_map'] ?? [], $fileName) ?? [];
        $audio = stem_audio_info($temp, $map, (float)($file['duration'] ?? 0));
        $sourceName = trim((string)($map['track_name'] ?? ''));
        $fxSummary = trim((string)($map['fx_summary'] ?? ''));
        $plugins = direct_v78_plugins($map['plugins'] ?? $fxSummary);
        $allPlugins = array_values(array_unique(array_merge($allPlugins, $plugins)));

        $role = trim((string)($map['role'] ?? ''));
        if (!in_array($role, ['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'], true)) {
            $role = stem_role_from_metadata($sourceName . ' ' . $fileName, $fxSummary);
        }
        $instrument = trim((string)($map['instrument'] ?? ''));
        if ($instrument === '') {
            $instrument = direct_v78_instrument($sourceName . ' ' . $fileName . ' ' . implode(' ', $plugins), $role);
        }
        if ($instrument !== '' && !in_array($instrument, $instruments, true)) {
            $instruments[] = $instrument;
        }

        $stemName = $sourceName !== '' ? $sourceName : direct_v78_stem_base_name($fileName);
        if ($stemName === '') $stemName = 'Stem ' . ($index + 1);

        $description = $instrument !== '' ? $instrument . ' stem' : $role . ' stem';
        if ($sourceName !== '') $description .= ' from REAPER track “' . $sourceName . '”';
        if ($plugins) $description .= '. Plugins: ' . implode(', ', array_slice($plugins, 0, 8));
        $description .= '.';

        $position = (float)($map['position'] ?? 0.0);
        $duration = (float)($audio['duration_seconds'] ?? 0.0);
        if ($duration <= 0) $duration = (float)($file['duration'] ?? $map['length'] ?? 0.0);
        $sampleRate = (int)($audio['sample_rate'] ?? 0);
        if ($sampleRate > 0 && $detectedSampleRate <= 0) $detectedSampleRate = $sampleRate;

        $analysis[stem_normalized_source_key($fileName)] = [
            'channels'=>(int)($audio['channels'] ?? 0),
            'sample_rate'=>$sampleRate,
            'bit_depth'=>(int)($audio['bit_depth'] ?? 0),
            'duration_seconds'=>max(0.0, $duration),
            'format'=>(string)($audio['format'] ?? strtoupper($extension)),
        ];

        $stems[] = [
            'file_name'=>$fileName,
            'stem_name'=>mb_substr($stemName, 0, 190),
            'stem_role'=>$role,
            'instrument'=>mb_substr($instrument, 0, 190),
            'plugins'=>mb_substr(implode(', ', $plugins), 0, 700),
            'description'=>mb_substr($description, 0, 500),
            'source_track_name'=>mb_substr($sourceName, 0, 190),
            'track_guid'=>mb_substr((string)($map['track_guid'] ?? ''), 0, 80),
            'volume'=>(float)($map['volume'] ?? 1.0),
            'pan'=>max(-1.0, min(1.0, (float)($map['pan'] ?? 0.0))),
            'channels'=>(int)($audio['channels'] ?? 0),
            'sample_rate'=>$sampleRate,
            'bit_depth'=>(int)($audio['bit_depth'] ?? 0),
            'duration_seconds'=>max(0.0, $duration),
            'start_offset_seconds'=>max(0.0, $position),
            'format'=>(string)($audio['format'] ?? strtoupper($extension)),
            'detected_from'=>$sourceName !== '' ? 'RPP + audio' : 'Filename + audio',
        ];
    }

    $energy = trim((string)($track['energy'] ?? ''));
    if ($energy === '') $energy = $tempo >= 135 ? 'High' : ($tempo <= 82 ? 'Low' : 'Medium');

    $description = trim((string)($track['description'] ?? ''));
    if ($description === '') {
        $description = 'Production project at ' . rtrim(rtrim(number_format($tempo, 2), '0'), '.') . ' BPM';
        if ($instruments) $description .= ' featuring ' . implode(', ', array_slice($instruments, 0, 10));
        if ($allPlugins) $description .= '. Production plugins include ' . implode(', ', array_slice($allPlugins, 0, 10));
        $description .= '.';
    }

    return [
        'track'=>[
            'title'=>(string)($track['title'] ?? ''),
            'description'=>$description,
            'genre'=>(string)($track['genre'] ?? ''),
            'mood'=>(string)($track['mood'] ?? ''),
            'energy'=>$energy,
            'tempo_bpm'=>$tempo,
            'instruments'=>implode(', ', $instruments),
            'keywords'=>direct_v78_make_keywords([
                (string)($track['keywords'] ?? ''),
                implode(', ', $instruments),
                implode(', ', $allPlugins),
                rtrim(rtrim(number_format($tempo, 2), '0'), '.') . ' BPM',
                (string)($rpp['time_signature'] ?? ''),
            ]),
        ],
        'project'=>[
            'project_name'=>(string)($rpp['project_name'] ?? $track['title'] ?? ''),
            'tempo_bpm'=>$tempo,
            'time_signature'=>(string)($rpp['time_signature'] ?? ''),
            'project_sample_rate'=>(int)(($rpp['project_sample_rate'] ?? 0) ?: $detectedSampleRate),
        ],
        'plugins'=>$allPlugins,
        'stems'=>$stems,
        '_analysis'=>$analysis,
    ];
}

function direct_v78_sanitize_review(array $input, array $defaults, array $state): array
{
    $trackInput = is_array($input['track'] ?? null) ? $input['track'] : [];
    $projectInput = is_array($input['project'] ?? null) ? $input['project'] : [];
    $trackDefault = is_array($defaults['track'] ?? null) ? $defaults['track'] : [];
    $projectDefault = is_array($defaults['project'] ?? null) ? $defaults['project'] : [];

    $tempo = (float)($projectInput['tempo_bpm'] ?? $trackInput['tempo_bpm'] ?? $projectDefault['tempo_bpm'] ?? 0);
    $tempo = ($tempo >= 20 && $tempo <= 400) ? $tempo : null;
    $sampleRate = (int)($projectInput['project_sample_rate'] ?? $projectDefault['project_sample_rate'] ?? 0);
    $sampleRate = ($sampleRate >= 8000 && $sampleRate <= 768000) ? $sampleRate : null;

    $track = [
        'title'=>mb_substr(trim((string)($trackInput['title'] ?? $trackDefault['title'] ?? '')), 0, 190),
        'description'=>mb_substr(trim((string)($trackInput['description'] ?? $trackDefault['description'] ?? '')), 0, 5000),
        'genre'=>mb_substr(trim((string)($trackInput['genre'] ?? $trackDefault['genre'] ?? '')), 0, 255),
        'mood'=>mb_substr(trim((string)($trackInput['mood'] ?? $trackDefault['mood'] ?? '')), 0, 255),
        'energy'=>mb_substr(trim((string)($trackInput['energy'] ?? $trackDefault['energy'] ?? '')), 0, 30),
        'tempo_bpm'=>$tempo,
        'instruments'=>mb_substr(trim((string)($trackInput['instruments'] ?? $trackDefault['instruments'] ?? '')), 0, 500),
        'keywords'=>mb_substr(trim((string)($trackInput['keywords'] ?? $trackDefault['keywords'] ?? '')), 0, 500),
    ];
    if ($track['title'] === '') $track['title'] = (string)($trackDefault['title'] ?? 'Untitled Track');

    $project = [
        'project_name'=>mb_substr(trim((string)($projectInput['project_name'] ?? $projectDefault['project_name'] ?? $track['title'])), 0, 190),
        'tempo_bpm'=>$tempo,
        'time_signature'=>mb_substr(trim((string)($projectInput['time_signature'] ?? $projectDefault['time_signature'] ?? '')), 0, 20),
        'project_sample_rate'=>$sampleRate,
    ];

    $submittedByFile = [];
    foreach ((is_array($input['stems'] ?? null) ? $input['stems'] : []) as $stem) {
        if (!is_array($stem)) continue;
        $key = stem_normalized_source_key((string)($stem['file_name'] ?? ''));
        if ($key !== '') $submittedByFile[$key] = $stem;
    }
    $defaultsByFile = [];
    foreach (($defaults['stems'] ?? []) as $stem) {
        if (!is_array($stem)) continue;
        $key = stem_normalized_source_key((string)($stem['file_name'] ?? ''));
        if ($key !== '') $defaultsByFile[$key] = $stem;
    }

    $roles = ['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'];
    $stems = [];
    foreach (($state['files'] ?? []) as $file) {
        $fileName = (string)($file['name'] ?? '');
        $key = stem_normalized_source_key($fileName);
        $fallback = $defaultsByFile[$key] ?? [];
        $source = $submittedByFile[$key] ?? $fallback;
        $role = trim((string)($source['stem_role'] ?? $fallback['stem_role'] ?? 'Other'));
        if (!in_array($role, $roles, true)) $role = 'Other';
        $stemName = trim((string)($source['stem_name'] ?? $fallback['stem_name'] ?? direct_v78_stem_base_name($fileName)));
        if ($stemName === '') $stemName = direct_v78_stem_base_name($fileName) ?: 'Stem';

        $stems[] = [
            'file_name'=>$fileName,
            'stem_name'=>mb_substr($stemName, 0, 190),
            'stem_role'=>$role,
            'instrument'=>mb_substr(trim((string)($source['instrument'] ?? $fallback['instrument'] ?? '')), 0, 190),
            'plugins'=>mb_substr(trim((string)($source['plugins'] ?? $fallback['plugins'] ?? '')), 0, 700),
            'description'=>mb_substr(trim((string)($source['description'] ?? $fallback['description'] ?? '')), 0, 500),
            'source_track_name'=>mb_substr(trim((string)($source['source_track_name'] ?? $fallback['source_track_name'] ?? '')), 0, 190),
            'track_guid'=>mb_substr(trim((string)($source['track_guid'] ?? $fallback['track_guid'] ?? '')), 0, 80),
            'volume'=>max(0.0, min(8.0, (float)($source['volume'] ?? $fallback['volume'] ?? 1.0))),
            'pan'=>max(-1.0, min(1.0, (float)($source['pan'] ?? $fallback['pan'] ?? 0.0))),
        ];
    }

    return ['track'=>$track, 'project'=>$project, 'stems'=>$stems];
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

    if ($action === 'metadata_update') {
        $payload = json_decode((string)($_POST['review_json'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Reviewed metadata could not be decoded.');
        }

        $trackInput = is_array($payload['track'] ?? null) ? $payload['track'] : [];
        $projectInput = is_array($payload['project'] ?? null) ? $payload['project'] : [];
        $stemInput = is_array($payload['stems'] ?? null) ? $payload['stems'] : [];

        $tempo = (float)($projectInput['tempo_bpm'] ?? $trackInput['tempo_bpm'] ?? 0);
        $tempo = ($tempo >= 20 && $tempo <= 400) ? $tempo : null;
        $sampleRate = (int)($projectInput['project_sample_rate'] ?? 0);
        $sampleRate = ($sampleRate >= 8000 && $sampleRate <= 768000) ? $sampleRate : null;

        $title = mb_substr(trim((string)($trackInput['title'] ?? $track['title'] ?? '')), 0, 190);
        if ($title === '') $title = (string)$track['title'];
        $description = mb_substr(trim((string)($trackInput['description'] ?? $track['description'] ?? '')), 0, 5000);
        $genre = mb_substr(trim((string)($trackInput['genre'] ?? $track['genre'] ?? '')), 0, 255);
        $mood = mb_substr(trim((string)($trackInput['mood'] ?? $track['mood'] ?? '')), 0, 255);
        $energy = mb_substr(trim((string)($trackInput['energy'] ?? $track['energy'] ?? '')), 0, 30);
        $instruments = mb_substr(trim((string)($trackInput['instruments'] ?? '')), 0, 500);
        $keywords = direct_v78_make_keywords([
            (string)($trackInput['keywords'] ?? $track['keywords'] ?? ''),
            $instruments,
        ]);

        $projectStmt = $pdo->prepare('SELECT * FROM track_projects WHERE track_id=? LIMIT 1');
        $projectStmt->execute([$trackId]);
        $project = $projectStmt->fetch();
        if (!$project) {
            throw new RuntimeException('Saved stem project was not found for metadata update.');
        }
        $projectId = (int)$project['id'];
        $projectName = mb_substr(trim((string)($projectInput['project_name'] ?? $project['project_name'] ?? $title)), 0, 190);
        if ($projectName === '') $projectName = $title;
        $signature = mb_substr(trim((string)($projectInput['time_signature'] ?? $project['time_signature'] ?? '')), 0, 20);

        $roles = ['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'];
        $updatedStems = 0;

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE tracks
                 SET title=?,description=?,genre=?,mood=?,energy=?,tempo_bpm=?,keywords=?
                 WHERE id=?'
            )->execute([
                $title,
                $description,
                $genre,
                $mood,
                $energy,
                $tempo !== null ? (int)round($tempo) : null,
                $keywords,
                $trackId,
            ]);

            $pdo->prepare(
                'UPDATE track_projects
                 SET project_name=?,tempo_bpm=?,time_signature=?,project_sample_rate=?
                 WHERE id=? AND track_id=?'
            )->execute([
                $projectName,
                $tempo,
                $signature,
                $sampleRate,
                $projectId,
                $trackId,
            ]);

            $stemUpdate = $pdo->prepare(
                'UPDATE track_stems
                 SET stem_name=?,stem_role=?,source_track_name=?,rpp_track_guid=?,
                     rpp_volume=?,rpp_pan=?,rpp_fx_summary=?
                 WHERE track_id=? AND project_id=? AND file_name=? AND is_active=1'
            );

            foreach (array_slice($stemInput, 0, 96) as $stem) {
                if (!is_array($stem)) continue;
                $fileName = stem_clean_filename((string)($stem['file_name'] ?? ''));
                if ($fileName === '') continue;

                $stemName = mb_substr(trim((string)($stem['stem_name'] ?? '')), 0, 190);
                if ($stemName === '') $stemName = direct_v78_stem_base_name($fileName) ?: 'Stem';
                $role = trim((string)($stem['stem_role'] ?? 'Other'));
                if (!in_array($role, $roles, true)) $role = 'Other';
                $sourceName = mb_substr(trim((string)($stem['source_track_name'] ?? '')), 0, 190);
                $guid = mb_substr(trim((string)($stem['track_guid'] ?? '')), 0, 80);
                $volume = max(0.0, min(8.0, (float)($stem['volume'] ?? 1.0)));
                $pan = max(-1.0, min(1.0, (float)($stem['pan'] ?? 0.0)));
                $instrument = mb_substr(trim((string)($stem['instrument'] ?? '')), 0, 190);
                $plugins = mb_substr(trim((string)($stem['plugins'] ?? '')), 0, 700);
                $notes = mb_substr(trim((string)($stem['description'] ?? '')), 0, 500);
                $fxParts = [];
                if ($instrument !== '') $fxParts[] = 'Instrument: ' . $instrument;
                if ($plugins !== '') $fxParts[] = 'Plugins: ' . $plugins;
                if ($notes !== '') $fxParts[] = 'Notes: ' . $notes;
                $fxSummary = mb_substr(implode(' | ', $fxParts), 0, 1000);

                $stemUpdate->execute([
                    $stemName,
                    $role,
                    $sourceName,
                    $guid,
                    $volume,
                    $pan,
                    $fxSummary,
                    $trackId,
                    $projectId,
                    $fileName,
                ]);
                $updatedStems += $stemUpdate->rowCount() > 0 ? 1 : 0;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        direct_stem_log(
            $requestId,
            'Metadata resaved track=' . $trackId . ' stems=' . $updatedStems
        );

        direct_stem_json([
            'ok'=>true,
            'phase'=>'metadata_updated',
            'updated_stems'=>$updatedStems,
            'track_id'=>$trackId,
            'project_id'=>$projectId,
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

            $extension = stem_lower(pathinfo($name, PATHINFO_EXTENSION));

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

        $stemExtension = stem_lower(
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
        $lowerName = stem_lower($name);
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

    if ($action === 'meta') {
        $state = direct_stem_load_state($dir);
        $metaJson = (string)($_POST['rpp_meta_json'] ?? '');

        if ($metaJson === '') {
            $state['rpp_info'] = [
                'project_name'=>(string)$track['title'],
                'tempo_bpm'=>null,
                'time_signature'=>'',
                'project_sample_rate'=>null,
                'tracks'=>[],
                'file_map'=>[],
            ];
        } else {
            $rppInfo = json_decode($metaJson, true);

            if (!is_array($rppInfo)) {
                throw new RuntimeException(
                    'Browser REAPER metadata could not be decoded.'
                );
            }

            $rppInfo['project_name'] = mb_substr(
                trim((string)($rppInfo['project_name'] ?? $track['title'])),
                0,
                190
            );
            $rppInfo['tempo_bpm'] = isset($rppInfo['tempo_bpm'])
                ? (float)$rppInfo['tempo_bpm']
                : null;
            $rppInfo['time_signature'] = mb_substr(
                trim((string)($rppInfo['time_signature'] ?? '')),
                0,
                20
            );
            $rppInfo['project_sample_rate'] = isset($rppInfo['project_sample_rate'])
                ? (int)$rppInfo['project_sample_rate']
                : null;

            if (!isset($rppInfo['file_map']) || !is_array($rppInfo['file_map'])) {
                $rppInfo['file_map'] = [];
            }

            // The server only needs compact per-file placement metadata.
            // Do not persist the full browser-parsed REAPER track tree.
            $safeMap = [];

            foreach (
                array_slice(
                    $rppInfo['file_map'],
                    0,
                    256,
                    true
                ) as $key=>$map
            ) {
                if (!is_array($map)) {
                    continue;
                }

                $safeKey = stem_lower(
                    mb_substr((string)$key, 0, 255)
                );

                $safeMap[$safeKey] = [
                    'track_name'=>mb_substr(
                        (string)($map['track_name'] ?? ''),
                        0,
                        190
                    ),
                    'track_guid'=>mb_substr(
                        (string)($map['track_guid'] ?? ''),
                        0,
                        80
                    ),
                    'volume'=>(float)($map['volume'] ?? 1.0),
                    'pan'=>max(
                        -1.0,
                        min(1.0, (float)($map['pan'] ?? 0.0))
                    ),
                    'fx_summary'=>mb_substr(
                        (string)($map['fx_summary'] ?? ''),
                        0,
                        1000
                    ),
                    'plugins'=>array_slice(direct_v78_plugins($map['plugins'] ?? []), 0, 30),
                    'role'=>mb_substr(trim((string)($map['role'] ?? '')), 0, 80),
                    'instrument'=>mb_substr(trim((string)($map['instrument'] ?? '')), 0, 190),
                    'channels'=>max(0, min(64, (int)($map['channels'] ?? 0))),
                    'play_rate'=>(float)($map['play_rate'] ?? 1.0),
                    'take_name'=>mb_substr(trim((string)($map['take_name'] ?? '')), 0, 190),
                    'position'=>(float)($map['position'] ?? 0.0),
                    'length'=>(float)($map['length'] ?? 0.0),
                ];
            }

            $rppInfo['tracks'] = [];
            $rppInfo['plugins'] = array_slice(direct_v78_plugins($rppInfo['plugins'] ?? []), 0, 60);
            $rppInfo['file_map'] = $safeMap;
            $state['rpp_info'] = $rppInfo;
        }

        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'meta_saved',
            'mapped_files'=>count(
                $state['rpp_info']['file_map'] ?? []
            ),
        ]);
    }

    if ($action === 'review_prepare') {
        $state = direct_stem_load_state($dir);
        $review = direct_v78_prepare_review($state, $track, $dir);
        $state['review_defaults'] = [
            'track'=>$review['track'],
            'project'=>$review['project'],
            'plugins'=>$review['plugins'],
            'stems'=>$review['stems'],
        ];
        $state['analysis'] = $review['_analysis'] ?? [];
        // The automatic parser result is the initial saved mapping. User
        // corrections happen later through metadata_update after save_finish.
        $state['review'] = [
            'track'=>$review['track'],
            'project'=>$review['project'],
            'stems'=>$review['stems'],
        ];
        if (!isset($state['rpp_info']) || !is_array($state['rpp_info'])) {
            $state['rpp_info'] = [];
        }
        foreach (['project_name','tempo_bpm','time_signature','project_sample_rate'] as $key) {
            $state['rpp_info'][$key] = $review['project'][$key] ?? null;
        }
        direct_stem_save_state($dir, $state);
        unset($review['_analysis']);
        direct_stem_json(['ok'=>true, 'phase'=>'review_ready', 'review'=>$review]);
    }

    if ($action === 'review') {
        $state = direct_stem_load_state($dir);
        $defaults = is_array($state['review_defaults'] ?? null)
            ? $state['review_defaults']
            : direct_v78_prepare_review($state, $track, $dir);
        $payload = json_decode((string)($_POST['review_json'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Reviewed metadata could not be decoded.');
        }
        $review = direct_v78_sanitize_review($payload, $defaults, $state);
        $state['review'] = $review;
        if (!isset($state['rpp_info']) || !is_array($state['rpp_info'])) $state['rpp_info'] = [];
        foreach (['project_name','tempo_bpm','time_signature','project_sample_rate'] as $key) {
            $state['rpp_info'][$key] = $review['project'][$key] ?? null;
        }
        direct_stem_save_state($dir, $state);
        direct_stem_json(['ok'=>true, 'phase'=>'review_saved', 'stem_count'=>count($review['stems'])]);
    }

    if ($action === 'save_open') {
        $state = direct_stem_load_state($dir);

        foreach ($state['files'] as $file) {
            if (empty($file['complete'])) {
                throw new RuntimeException(
                    'Stem upload is incomplete: ' . (string)$file['name']
                );
            }
        }

        // Only filesystem setup here. No DB query and no RPP parsing.
        $importToken = date('Ymd-His')
            . '-' . substr(bin2hex(random_bytes(6)), 0, 12);

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
            throw new RuntimeException(
                'Could not create final stem storage.'
            );
        }

        if (
            !is_dir($projectDir) &&
            !mkdir($projectDir, 0755, true) &&
            !is_dir($projectDir)
        ) {
            throw new RuntimeException(
                'Could not create final project storage.'
            );
        }

        $state['save'] = [
            'import_token'=>$importToken,
            'stem_dir'=>$stemDir,
            'project_dir'=>$projectDir,
            'project_path'=>'',
            'project_file_name'=>'',
            'project_id'=>0,
            'created_project'=>false,
            'had_existing_project'=>false,
            'project_start'=>0.0,
            'next_index'=>0,
            'new_paths'=>[],
            'old_paths'=>[],
            'max_end'=>0.0,
        ];

        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'save_open',
        ]);
    }

    if ($action === 'save_rpp') {
        $state = direct_stem_load_state($dir);
        $save = $state['save'] ?? null;

        if (!is_array($save)) {
            throw new RuntimeException('Save session is not open.');
        }

        $rppInfo = $state['rpp_info'] ?? [
            'project_name'=>(string)$track['title'],
            'tempo_bpm'=>null,
            'time_signature'=>'',
            'project_sample_rate'=>null,
            'tracks'=>[],
            'file_map'=>[],
        ];

        $positions = [];
        foreach ($state['files'] as $file) {
            $map = stem_match_rpp_file(
                $rppInfo['file_map'] ?? [],
                (string)$file['name']
            );
            $positions[] = (float)($map['position'] ?? 0.0);
        }

        $save['project_start'] = $positions
            ? min($positions)
            : 0.0;

        if (
            !empty($state['rpp_path']) &&
            is_file((string)$state['rpp_path'])
        ) {
            $projectFile = (string)$save['import_token']
                . '-'
                . stem_clean_filename((string)$state['rpp_name']);

            $projectFinal = rtrim(
                (string)$save['project_dir'],
                DIRECTORY_SEPARATOR
            ) . DIRECTORY_SEPARATOR . $projectFile;

            if (!@rename(
                (string)$state['rpp_path'],
                $projectFinal
            )) {
                throw new RuntimeException(
                    'Could not move the REAPER project file.'
                );
            }

            $save['project_path'] =
                '/uploads/projects/track-' . $trackId
                . '/' . $projectFile;
            $save['project_file_name'] =
                (string)$state['rpp_name'];
            $save['new_paths'][] =
                (string)$save['project_path'];
            $state['rpp_path'] = '';
        }

        $state['save'] = $save;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'save_rpp',
            'project_start'=>$save['project_start'],
        ]);
    }

    if ($action === 'save_db') {
        $state = direct_stem_load_state($dir);
        $save = $state['save'] ?? null;

        if (!is_array($save)) {
            throw new RuntimeException('Save session is not open.');
        }

        // The only DB setup request. It is intentionally isolated so a host
        // failure here cannot be confused with parsing or file movement.
        $pdo->prepare(
            'DELETE FROM track_stems
             WHERE track_id=? AND is_active=0'
        )->execute([$trackId]);

        $existingProject = stem_project_for_track($trackId);
        $rppInfo = $state['rpp_info'] ?? [];

        if ($existingProject) {
            $save['project_id'] =
                (int)$existingProject['id'];
            $save['had_existing_project'] = true;

            if ($save['project_path'] === '') {
                $save['project_path'] =
                    (string)($existingProject['rpp_file_path'] ?? '');
                $save['project_file_name'] =
                    (string)($existingProject['rpp_file_name'] ?? '');
            } elseif (
                !empty($existingProject['rpp_file_path']) &&
                (string)$existingProject['rpp_file_path']
                    !== (string)$save['project_path']
            ) {
                $save['old_paths'][] =
                    (string)$existingProject['rpp_file_path'];
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO track_projects
                 (track_id,project_name,source_zip_name,
                  rpp_file_name,rpp_file_path,tempo_bpm,
                  time_signature,project_sample_rate,
                  media_sample_rate,project_start_seconds,
                  imported_by_user_id,imported_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())'
            );

            $stmt->execute([
                $trackId,
                (string)($rppInfo['project_name']
                    ?? $track['title']),
                'Browser ZIP stems',
                (string)$save['project_file_name'],
                (string)$save['project_path'],
                $rppInfo['tempo_bpm'] ?? null,
                (string)($rppInfo['time_signature'] ?? ''),
                $rppInfo['project_sample_rate'] ?? null,
                null,
                (float)$save['project_start'],
                $userId,
            ]);

            $save['project_id'] =
                (int)$pdo->lastInsertId();
            $save['created_project'] = true;
        }

        $oldStems = stems_for_track($trackId);
        foreach ($oldStems as $oldStem) {
            if (!empty($oldStem['file_path'])) {
                $save['old_paths'][] =
                    (string)$oldStem['file_path'];
            }
        }

        $save['old_paths'] = array_values(
            array_unique($save['old_paths'])
        );

        $state['save'] = $save;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'save_db',
            'project_id'=>(int)$save['project_id'],
            'total_stems'=>count($state['files']),
        ]);
    }

    if ($action === 'row_add') {
        $state = direct_stem_load_state($dir);
        $save = $state['save'] ?? null;

        if (!is_array($save)) {
            throw new RuntimeException(
                'Save session is not ready.'
            );
        }

        if (!empty($save['pending_row'])) {
            // A successful prior row_add response may have been lost.
            direct_stem_json([
                'ok'=>true,
                'phase'=>'row_add',
                'completed'=>(int)($save['next_index'] ?? 0),
                'pending'=>true,
                'stem_name'=>(string)($save['pending_row']['stem_name'] ?? ''),
            ]);
        }

        $index = (int)($save['next_index'] ?? 0);
        $files = $state['files'] ?? [];

        if ($index >= count($files)) {
            direct_stem_json([
                'ok'=>true,
                'phase'=>'row_add',
                'completed'=>$index,
                'total'=>count($files),
                'done'=>true,
            ]);
        }

        $file = $files[$index];
        $stemExtension = strtolower(
            pathinfo((string)$file['name'], PATHINFO_EXTENSION)
        );

        if (!in_array($stemExtension, ['mp3','wav'], true)) {
            throw new RuntimeException(
                'Unsupported stem type: ' . (string)$file['name']
            );
        }

        $temp = $dir
            . '/stem-'
            . str_pad((string)$index, 3, '0', STR_PAD_LEFT)
            . '.'
            . $stemExtension;

        if (!is_file($temp)) {
            throw new RuntimeException(
                'Uploaded stem is missing before save: '
                . (string)$file['name']
            );
        }

        $savedName = str_pad(
            (string)($index + 1),
            2,
            '0',
            STR_PAD_LEFT
        ) . '-' . stem_clean_filename((string)$file['name']);

        $relative = '/uploads/stems/track-'
            . $trackId
            . '/'
            . (string)$save['import_token']
            . '/'
            . $savedName;

        $map = stem_match_rpp_file(
            $state['rpp_info']['file_map'] ?? [],
            (string)$file['name']
        );

        $sourceTrackName = trim(
            (string)($map['track_name'] ?? '')
        );

        $role = stem_role_from_metadata(
            $sourceTrackName . ' ' . (string)$file['name'],
            ''
        );

        $baseName = preg_replace(
            '/-consolidated(?=\.(mp3|wav)$)/i',
            '',
            (string)$file['name']
        ) ?: (string)$file['name'];

        $baseName = preg_replace(
            '/\.(mp3|wav)$/i',
            '',
            $baseName
        ) ?: $baseName;

        $baseName = preg_replace(
            '/^\d{1,3}-/',
            '',
            $baseName
        ) ?: $baseName;

        $stemName = $sourceTrackName !== ''
            ? $sourceTrackName
            : trim($baseName);

        if ($stemName === '') {
            $stemName = 'Stem ' . ($index + 1);
        }

        $reviewStem = direct_v78_review_stem($state['review']['stems'] ?? [], (string)$file['name']);
        if (is_array($reviewStem)) {
            if (trim((string)($reviewStem['stem_name'] ?? '')) !== '') $stemName = trim((string)$reviewStem['stem_name']);
            $reviewRole = trim((string)($reviewStem['stem_role'] ?? ''));
            if (in_array($reviewRole, ['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'], true)) $role = $reviewRole;
            if (trim((string)($reviewStem['source_track_name'] ?? '')) !== '') $sourceTrackName = trim((string)$reviewStem['source_track_name']);
        }

        $position = (float)($map['position'] ?? 0.0);
        $offset = max(
            0.0,
            $position - (float)($save['project_start'] ?? 0.0)
        );

        $duration = max(
            0.0,
            (float)($file['duration'] ?? 0)
        );

        if ($duration <= 0 && is_array($map)) {
            $duration = max(0.0, (float)($map['length'] ?? 0));
        }

        $analysisKey = stem_normalized_source_key((string)$file['name']);
        $audioInfo = is_array($state['analysis'][$analysisKey] ?? null)
            ? $state['analysis'][$analysisKey]
            : stem_audio_info($temp, $map, $duration);
        if ((float)($audioInfo['duration_seconds'] ?? 0) > 0) {
            $duration = (float)$audioInfo['duration_seconds'];
        }

        // Intentionally minimal INSERT. All optional REAPER detail columns use
        // their schema defaults. This avoids the wide row that was the only
        // remaining operation returning a HostGator 500.
        $insert = $pdo->prepare(
            'INSERT INTO track_stems
             (track_id,project_id,stem_name,stem_role,file_name,file_path,
              duration_seconds,start_offset_seconds,sort_order,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,0)'
        );

        $insert->execute([
            $trackId,
            (int)$save['project_id'],
            substr($stemName, 0, 190),
            substr($role, 0, 80),
            substr((string)$file['name'], 0, 255),
            $relative,
            round($duration, 4),
            round($offset, 4),
            $index + 1,
        ]);

        $rowId = (int)$pdo->lastInsertId();

        if ($rowId < 1) {
            throw new RuntimeException(
                'Stem database row was not created.'
            );
        }

        // Preserve the HostGator-safe narrow INSERT, then enrich the row in a
        // separate narrow UPDATE with parsed and user-reviewed production data.
        $instrument = trim((string)($reviewStem['instrument'] ?? $map['instrument'] ?? ''));
        if ($instrument === '') {
            $instrument = direct_v78_instrument(
                $stemName . ' ' . $sourceTrackName . ' ' . (string)($map['fx_summary'] ?? ''),
                $role
            );
        }
        $plugins = trim((string)($reviewStem['plugins'] ?? ''));
        if ($plugins === '') {
            $plugins = implode(', ', direct_v78_plugins($map['plugins'] ?? $map['fx_summary'] ?? ''));
        }
        $notes = trim((string)($reviewStem['description'] ?? ''));
        $fxParts = [];
        if ($instrument !== '') $fxParts[] = 'Instrument: ' . $instrument;
        if ($plugins !== '') $fxParts[] = 'Plugins: ' . $plugins;
        if ($notes !== '') $fxParts[] = 'Notes: ' . $notes;
        $rawFx = trim((string)($map['fx_summary'] ?? ''));
        if ($rawFx !== '' && !str_contains($plugins, $rawFx)) $fxParts[] = 'RPP FX: ' . $rawFx;
        $fxSummary = mb_substr(implode(' | ', $fxParts), 0, 1000);
        $reviewVolume = is_array($reviewStem)
            ? max(0.0, min(8.0, (float)($reviewStem['volume'] ?? 1.0)))
            : (float)($map['volume'] ?? 1.0);
        $reviewPan = is_array($reviewStem)
            ? max(-1.0, min(1.0, (float)($reviewStem['pan'] ?? 0.0)))
            : max(-1.0, min(1.0, (float)($map['pan'] ?? 0.0)));

        $metadataUpdate = $pdo->prepare(
            'UPDATE track_stems
             SET source_track_name=?,channels=?,sample_rate=?,bit_depth=?,
                 rpp_track_guid=?,rpp_volume=?,rpp_pan=?,rpp_fx_summary=?
             WHERE id=?'
        );
        $metadataUpdate->execute([
            mb_substr($sourceTrackName, 0, 190),
            max(0, min(64, (int)($audioInfo['channels'] ?? 0))),
            max(0, (int)($audioInfo['sample_rate'] ?? 0)),
            max(0, (int)($audioInfo['bit_depth'] ?? 0)),
            mb_substr((string)($reviewStem['track_guid'] ?? $map['track_guid'] ?? ''), 0, 80),
            $reviewVolume,
            $reviewPan,
            $fxSummary,
            $rowId,
        ]);

        $save['pending_row'] = [
            'id'=>$rowId,
            'index'=>$index,
            'stem_name'=>substr($stemName, 0, 190),
            'relative'=>$relative,
            'saved_name'=>$savedName,
            'temp'=>$temp,
            'duration'=>$duration,
            'offset'=>$offset,
        ];

        $state['save'] = $save;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'row_add',
            'completed'=>$index,
            'total'=>count($files),
            'stem_name'=>$stemName,
        ]);
    }

    if ($action === 'file_place') {
        $state = direct_stem_load_state($dir);
        $save = $state['save'] ?? null;

        if (!is_array($save)) {
            throw new RuntimeException(
                'Save session is not ready.'
            );
        }

        $pending = $save['pending_row'] ?? null;

        if (!is_array($pending)) {
            throw new RuntimeException(
                'No pending stem row is ready for file placement.'
            );
        }

        $final = rtrim(
            (string)$save['stem_dir'],
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . (string)$pending['saved_name'];

        if (!is_file((string)$pending['temp'])) {
            // If the file already reached final storage, treat the request as
            // an idempotent retry.
            if (!is_file($final)) {
                throw new RuntimeException(
                    'Uploaded stem file disappeared before placement.'
                );
            }
        } elseif (!@rename((string)$pending['temp'], $final)) {
            throw new RuntimeException(
                'Could not move the uploaded stem into final storage.'
            );
        }

        $save['new_paths'][] = (string)$pending['relative'];
        $save['max_end'] = max(
            (float)($save['max_end'] ?? 0),
            (float)$pending['offset'] + (float)$pending['duration']
        );
        $save['next_index'] = (int)$pending['index'] + 1;
        unset($save['pending_row']);

        $state['save'] = $save;
        direct_stem_save_state($dir, $state);

        direct_stem_json([
            'ok'=>true,
            'phase'=>'file_place',
            'completed'=>(int)$save['next_index'],
            'total'=>count($state['files'] ?? []),
            'stem_name'=>(string)$pending['stem_name'],
        ]);
    }

    if ($action === 'save_finish') {
        $state = direct_stem_load_state($dir);
        $save = $state['save'] ?? null;

        if (!is_array($save)) {
            throw new RuntimeException(
                'Save session is not ready.'
            );
        }

        $total = count($state['files'] ?? []);

        if (!empty($save['pending_row'])) {
            throw new RuntimeException(
                'A stem database row is waiting for file placement.'
            );
        }

        if ((int)($save['next_index'] ?? 0) !== $total) {
            throw new RuntimeException(
                'Not all stems have been staged yet.'
            );
        }

        $rppInfo = $state['rpp_info'] ?? [];
        $projectId = (int)$save['project_id'];

        $pdo->beginTransaction();

        try {
            if (!empty($save['had_existing_project'])) {
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
                    (string)($save['project_file_name'] ?? ''),
                    (string)($save['project_path'] ?? ''),
                    $rppInfo['tempo_bpm'] ?? null,
                    (string)($rppInfo['time_signature'] ?? ''),
                    $rppInfo['project_sample_rate'] ?? null,
                    null,
                    (float)($save['project_start'] ?? 0),
                    $userId,
                    $projectId,
                ]);
            }

            // For a normal ZIP import there are new stems. Replace the active
            // set only now, after every new stem has safely staged.
            if ($total > 0) {
                $pdo->prepare(
                    'DELETE FROM track_stems
                     WHERE track_id=? AND is_active=1'
                )->execute([$trackId]);

                $pdo->prepare(
                    'UPDATE track_stems
                     SET is_active=1
                     WHERE track_id=? AND project_id=? AND is_active=0'
                )->execute([
                    $trackId,
                    $projectId,
                ]);
            }

            $reviewTrack = is_array($state['review']['track'] ?? null)
                ? $state['review']['track']
                : [];

            if ($reviewTrack) {
                $reviewKeywords = direct_v78_make_keywords([
                    (string)($reviewTrack['keywords'] ?? ''),
                    (string)($reviewTrack['instruments'] ?? ''),
                ]);
                $pdo->prepare(
                    'UPDATE tracks
                     SET title=?,description=?,genre=?,mood=?,energy=?,tempo_bpm=?,keywords=?
                     WHERE id=?'
                )->execute([
                    (string)($reviewTrack['title'] ?? $track['title']),
                    (string)($reviewTrack['description'] ?? ''),
                    (string)($reviewTrack['genre'] ?? ''),
                    (string)($reviewTrack['mood'] ?? ''),
                    (string)($reviewTrack['energy'] ?? ''),
                    !empty($reviewTrack['tempo_bpm']) ? (int)round((float)$reviewTrack['tempo_bpm']) : null,
                    $reviewKeywords,
                    $trackId,
                ]);
            } elseif (
                (int)($track['tempo_bpm'] ?? 0) < 1 &&
                !empty($rppInfo['tempo_bpm'])
            ) {
                $pdo->prepare('UPDATE tracks SET tempo_bpm=? WHERE id=?')->execute([
                    (int)round((float)$rppInfo['tempo_bpm']),
                    $trackId,
                ]);
            }

            if (
                trim((string)($track['duration'] ?? '')) === '' &&
                (float)($save['max_end'] ?? 0) > 0
            ) {
                $pdo->prepare(
                    'UPDATE tracks SET duration=? WHERE id=?'
                )->execute([
                    stem_format_duration(
                        (float)$save['max_end']
                    ),
                    $trackId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach (($save['old_paths'] ?? []) as $oldPath) {
            stem_delete_path_if_local((string)$oldPath);
            stem_cleanup_empty_parent((string)$oldPath);
        }

        direct_stem_cleanup($dir);

        direct_stem_log(
            $requestId,
            'Stage finished track=' . $trackId
            . ' stems=' . $total
        );

        direct_stem_json([
            'ok'=>true,
            'phase'=>'complete',
            'stem_count'=>$total,
            'studio_url'=>url(
                '/admin/stems.php?track=' . $trackId
            ),
        ]);
    }

    if ($action === 'abort') {
        $state = [];

        if (is_file(direct_stem_state_path($dir))) {
            try {
                $state = direct_stem_load_state($dir);
            } catch (Throwable $ignored) {
                $state = [];
            }
        }

        $save = $state['save'] ?? null;

        if (is_array($save)) {
            try {
                if (!empty($save['pending_row']['id'])) {
                    $pdo->prepare(
                        'DELETE FROM track_stems WHERE id=? AND is_active=0'
                    )->execute([
                        (int)$save['pending_row']['id'],
                    ]);
                }

                $pdo->prepare(
                    'DELETE FROM track_stems
                     WHERE track_id=? AND project_id=? AND is_active=0'
                )->execute([
                    $trackId,
                    (int)($save['project_id'] ?? 0),
                ]);

                if (!empty($save['created_project'])) {
                    $countStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM track_stems
                         WHERE project_id=? AND is_active=1'
                    );
                    $countStmt->execute([
                        (int)($save['project_id'] ?? 0),
                    ]);

                    if ((int)$countStmt->fetchColumn() === 0) {
                        $pdo->prepare(
                            'DELETE FROM track_projects WHERE id=?'
                        )->execute([
                            (int)($save['project_id'] ?? 0),
                        ]);
                    }
                }
            } catch (Throwable $ignored) {
                // The visible upload error is more important than cleanup.
            }

            foreach (($save['new_paths'] ?? []) as $path) {
                stem_delete_path_if_local((string)$path);
                stem_cleanup_empty_parent((string)$path);
            }
        }

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
