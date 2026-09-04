<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stem_v215_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stem_v215_error(Throwable|string $error, int $status = 400): never
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    stem_v215_json(['ok'=>false,'error'=>$message !== '' ? $message : 'Audio engine request failed.'],$status);
}

function stem_v215_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1) throw new RuntimeException('Track not found.');
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) throw new RuntimeException('Track not found.');
    if (!can_manage_track_production($track)) stem_v215_error('This track has not been shared with your account.',403);
    return $track;
}

function stem_v215_source(int $trackId, int $stemId): array
{
    $pdo = db();
    if (!$pdo || $stemId < 1) throw new RuntimeException('Select a Studio track first.');
    $stmt = $pdo->prepare('SELECT * FROM track_stems WHERE id=? AND track_id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$stemId,$trackId]);
    $stem = $stmt->fetch();
    if (!$stem) throw new RuntimeException('The selected Studio track no longer exists.');
    return $stem;
}

function stem_v215_mode(string $value): string
{
    $mode = strtolower(trim($value));
    if (!in_array($mode,['freeze','commit','bounce'],true)) throw new RuntimeException('Invalid render mode.');
    return $mode;
}

function stem_v215_name(string $value, string $fallback): string
{
    $name = trim(preg_replace('/\s+/u',' ',$value) ?? $value);
    if ($name === '') $name = $fallback;
    if (function_exists('mb_substr')) return mb_substr($name,0,120,'UTF-8');
    return substr($name,0,120);
}

function stem_v215_dir(int $trackId): array
{
    $token = bin2hex(random_bytes(8));
    $relative = '/uploads/stems/track-' . $trackId . '/v215-' . $token;
    $absolute = STONEFELLOW_ROOT . $relative;
    if (!is_dir($absolute) && !mkdir($absolute,0700,true) && !is_dir($absolute)) {
        throw new RuntimeException('Could not create rendered-audio storage.');
    }
    return [$relative,$absolute];
}

function stem_v215_render_path(int $trackId, string $relative): string
{
    $relative = trim($relative);
    $prefix = '/uploads/stems/track-' . $trackId . '/v215-';
    if ($relative === '' || !str_starts_with($relative,$prefix) || str_contains($relative,'..') || str_contains($relative,"\0")) {
        throw new RuntimeException('Rendered media path is outside the v215 storage area.');
    }
    $absolute = STONEFELLOW_ROOT . '/' . ltrim($relative,'/');
    $root = realpath(STONEFELLOW_ROOT . '/uploads/stems');
    if (!$root) throw new RuntimeException('Rendered media storage is unavailable.');
    if (is_file($absolute)) {
        $resolved = realpath($absolute);
        if (!$resolved || !str_starts_with($resolved,rtrim($root,DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Rendered media path is invalid.');
        }
        return $resolved;
    }
    return $absolute;
}

function stem_v215_engine_state(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $tracks = [];
    foreach (array_slice(is_array($raw['tracks'] ?? null) ? $raw['tracks'] : [],0,256,true) as $stemId=>$row) {
        $id = (int)$stemId;
        if ($id < 1 || !is_array($row)) continue;
        $tracks[(string)$id] = [
            'manualDelayMs'=>max(-500.0,min(500.0,(float)($row['manualDelayMs'] ?? 0))),
            'polarity'=>!empty($row['polarity']),
        ];
    }

    $freezes = [];
    foreach (array_slice(is_array($raw['freezes'] ?? null) ? $raw['freezes'] : [],0,128,true) as $stemId=>$row) {
        $id = (int)$stemId;
        if ($id < 1 || !is_array($row)) continue;
        $states = array_slice(array_map(static fn($item): bool => !empty($item),is_array($row['pluginStates'] ?? null) ? $row['pluginStates'] : []),0,12);
        $freezes[(string)$id] = [
            'renderStemId'=>max(0,(int)($row['renderStemId'] ?? 0)),
            'pluginStates'=>$states,
            'originalMuted'=>!empty($row['originalMuted']),
            'createdAt'=>max(0,(int)($row['createdAt'] ?? 0)),
        ];
    }

    $calibration = null;
    if (is_array($raw['calibration'] ?? null)) {
        $calibration = [
            'reportedInputMs'=>max(0.0,min(5000.0,(float)($raw['calibration']['reportedInputMs'] ?? 0))),
            'roundTripMs'=>max(0.0,min(5000.0,(float)($raw['calibration']['roundTripMs'] ?? 0))),
            'peak'=>max(0.0,min(1.0,(float)($raw['calibration']['peak'] ?? 0))),
            'probedAt'=>substr((string)($raw['calibration']['probedAt'] ?? ''),0,40),
            'calibratedAt'=>substr((string)($raw['calibration']['calibratedAt'] ?? ''),0,40),
        ];
    }

    return [
        'pdc'=>!array_key_exists('pdc',$raw) || !empty($raw['pdc']),
        'recordOffsetMs'=>max(-1000.0,min(1000.0,(float)($raw['recordOffsetMs'] ?? 0))),
        'preRollSeconds'=>max(0.0,min(10.0,(float)($raw['preRollSeconds'] ?? 1))),
        'postRollSeconds'=>max(0.0,min(15.0,(float)($raw['postRollSeconds'] ?? 2))),
        'tracks'=>$tracks,
        'freezes'=>$freezes,
        'calibration'=>$calibration,
    ];
}

function stem_v215_mix_row(int $trackId, int $mixId): array
{
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0);
    if (!$pdo || $userId < 1 || $mixId < 1 || !table_exists('stem_mix_saves')) {
        throw new RuntimeException('Saved mix storage is unavailable.');
    }
    $stmt = $pdo->prepare('SELECT id,mix_json FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=? LIMIT 1');
    $stmt->execute([$mixId,$userId,$trackId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Saved mix not found.');
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') stem_v215_error('POST required.',405);
if (!verify_csrf()) stem_v215_error('Session expired. Refresh Stem Studio and try again.',403);

$pdo = db();
if (!$pdo) stem_v215_error('Database unavailable.',503);
$action = trim((string)($_POST['action'] ?? ''));
$trackId = max(0,(int)($_POST['track_id'] ?? 0));

try {
    stem_v215_track($trackId);

    if ($action === 'save_mix_engine') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $row = stem_v215_mix_row($trackId,$mixId);
        $mix = json_decode((string)$row['mix_json'],true);
        if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
        $incoming = json_decode((string)($_POST['engine_json'] ?? ''),true);
        $mix['engineV215'] = stem_v215_engine_state($incoming);
        $json = json_encode($mix,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 16777216) throw new RuntimeException('Saved mix state is too large.');
        $stmt = $pdo->prepare('UPDATE stem_mix_saves SET mix_json=?,updated_at=NOW() WHERE id=? AND user_id=? AND track_id=?');
        $stmt->execute([$json,$mixId,(int)(current_user()['id'] ?? 0),$trackId]);
        stem_v215_json(['ok'=>true,'mix_id'=>$mixId,'has_engine'=>true,'engine'=>$mix['engineV215']]);
    }

    if ($action === 'load_mix_engine') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $row = stem_v215_mix_row($trackId,$mixId);
        $mix = json_decode((string)$row['mix_json'],true);
        if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
        $hasEngine = array_key_exists('engineV215',$mix) && is_array($mix['engineV215']);
        stem_v215_json([
            'ok'=>true,
            'mix_id'=>$mixId,
            'has_engine'=>$hasEngine,
            'engine'=>$hasEngine ? stem_v215_engine_state($mix['engineV215']) : null,
        ]);
    }

    if ($action === 'import_render') {
        $sourceStemId = max(0,(int)($_POST['source_stem_id'] ?? 0));
        $source = stem_v215_source($trackId,$sourceStemId);
        $mode = stem_v215_mode((string)($_POST['mode'] ?? 'bounce'));
        $startOffset = max(0.0,min(86400.0,(float)($_POST['start_offset'] ?? 0)));
        $baseName = trim((string)($source['stem_name'] ?? 'Audio')) ?: 'Audio';
        $name = stem_v215_name((string)($_POST['name'] ?? ''),$baseName . ' ' . ucfirst($mode));

        $upload = $_FILES['audio'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Rendered WAV upload was not received.');
        }
        if (!is_uploaded_file((string)$upload['tmp_name'])) throw new RuntimeException('Invalid rendered-audio upload.');
        $size = (int)($upload['size'] ?? 0);
        if ($size < 44 || $size > 536870912) throw new RuntimeException('Rendered WAV must be between 44 bytes and 512 MB.');

        $info = stem_wav_info((string)$upload['tmp_name']);
        $channels = max(1,min(2,(int)($info['channels'] ?? 0)));
        $sampleRate = max(8000,min(192000,(int)($info['sample_rate'] ?? 0)));
        $bitDepth = (int)($info['bit_depth'] ?? 0);
        $duration = max(0.0,(float)($info['duration_seconds'] ?? 0));
        if ($channels < 1 || $sampleRate < 8000 || !in_array($bitDepth,[16,24,32],true) || $duration <= 0) {
            throw new RuntimeException('Rendered file is not a supported PCM WAV.');
        }

        [$relativeDir,$absoluteDir] = stem_v215_dir($trackId);
        $base = stem_clean_filename($name);
        if ($base === '') $base = 'Stem-v215-' . ucfirst($mode);
        $fileName = $base . '.wav';
        $absolutePath = $absoluteDir . '/' . $fileName;
        if (!move_uploaded_file((string)$upload['tmp_name'],$absolutePath)) {
            @rmdir($absoluteDir);
            throw new RuntimeException('Could not store the rendered WAV.');
        }
        @chmod($absolutePath,0600);

        $pdo->beginTransaction();
        try {
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM track_stems WHERE track_id=?');
            $sortStmt->execute([$trackId]);
            $sort = (int)$sortStmt->fetchColumn() + 1;
            $summary = 'V215 ' . ucfirst($mode) . ' of stem: ' . $sourceStemId
                . ' · Printed track processing'
                . ' · Rendered sample rate: ' . $sampleRate . ' Hz'
                . ' · Rendered bit depth: ' . $bitDepth;
            $insert = $pdo->prepare(
                'INSERT INTO track_stems
                 (track_id,project_id,stem_name,stem_role,source_track_name,file_name,file_path,channels,sample_rate,bit_depth,duration_seconds,start_offset_seconds,rpp_track_guid,rpp_volume,rpp_pan,rpp_fx_summary,plugin_chain_json,sort_order,is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );
            $insert->execute([
                $trackId,(int)$source['project_id'],$name,(string)$source['stem_role'],(string)$source['source_track_name'],
                $fileName,$relativeDir . '/' . $fileName,$channels,$sampleRate,$bitDepth,$duration,$startOffset,'',1.0,0.0,
                substr($summary,0,1000),null,$sort,
            ]);
            $stemId = (int)$pdo->lastInsertId();
            $pdo->commit();
            stem_v215_json([
                'ok'=>true,'stem_id'=>$stemId,'source_stem_id'=>$sourceStemId,'mode'=>$mode,'name'=>$name,
                'duration_seconds'=>$duration,'start_offset'=>$startOffset,'sample_rate'=>$sampleRate,'bit_depth'=>$bitDepth,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_file($absolutePath)) @unlink($absolutePath);
            if (is_dir($absoluteDir)) @rmdir($absoluteDir);
            throw $e;
        }
    }

    if ($action === 'remove_render') {
        $stemId = max(0,(int)($_POST['stem_id'] ?? 0));
        $stmt = $pdo->prepare('SELECT * FROM track_stems WHERE id=? AND track_id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$stemId,$trackId]);
        $stem = $stmt->fetch();
        if (!$stem) stem_v215_json(['ok'=>true,'removed'=>false,'stem_id'=>$stemId]);
        $summary = (string)($stem['rpp_fx_summary'] ?? '');
        if (!preg_match('/^V215 (?:Freeze|Commit|Bounce) of stem:\s*\d+/i',$summary)) {
            throw new RuntimeException('Only a v215 rendered stem can be removed by this action.');
        }
        $absolute = stem_v215_render_path($trackId,(string)$stem['file_path']);
        $update = $pdo->prepare('UPDATE track_stems SET is_active=0,updated_at=NOW() WHERE id=? AND track_id=? AND is_active=1');
        $update->execute([$stemId,$trackId]);
        if ($update->rowCount() > 0 && is_file($absolute)) @unlink($absolute);
        if ($update->rowCount() > 0 && is_dir(dirname($absolute))) @rmdir(dirname($absolute));
        stem_v215_json(['ok'=>true,'removed'=>$update->rowCount()>0,'stem_id'=>$stemId]);
    }

    stem_v215_error('Unknown audio engine action.',400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    stem_v215_error($e,400);
}
