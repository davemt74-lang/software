<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stem_v213_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stem_v213_error(Throwable|string $error, int $status = 400): never
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    stem_v213_json(['ok'=>false,'error'=>$message !== '' ? $message : 'Recording take request failed.'],$status);
}

function stem_v213_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1) throw new RuntimeException('Track not found.');
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) throw new RuntimeException('Track not found.');
    if (!can_manage_track_production($track)) stem_v213_error('This track has not been shared with your account.',403);
    return $track;
}

function stem_v213_recording_id(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[a-f0-9]{24}$/',$value)) throw new RuntimeException('Invalid recording session.');
    return $value;
}

function stem_v213_take_parent_id(array $stem): int
{
    $summary = (string)($stem['rpp_fx_summary'] ?? '');
    if (preg_match('/Take of stem:\s*(\d+)/i',$summary,$match)) return max(0,(int)$match[1]);
    return max(0,(int)($stem['id'] ?? 0));
}

function stem_v213_base_name(string $name): string
{
    $clean = trim($name);
    $clean = preg_replace('/\s+(?:·\s*)?Take\s+\d+\s*$/i','',$clean) ?? $clean;
    return trim($clean) !== '' ? trim($clean) : 'Audio';
}

function stem_v213_clean_summary(string $summary): string
{
    $clean = preg_replace('/\s*·?\s*Take of stem:\s*\d+/i','',$summary) ?? $summary;
    $clean = preg_replace('/\s*·?\s*V213 take archive:\s*[a-f0-9]{24}/i','',$clean) ?? $clean;
    return trim($clean," \t\n\r\0\x0B·");
}

function stem_v213_source_path(string $relative): string
{
    $relative = trim($relative);
    if ($relative === '' || !str_starts_with($relative,'/uploads/stems/')) throw new RuntimeException('The armed track media cannot be archived safely.');
    $root = realpath(STONEFELLOW_ROOT . '/uploads/stems');
    $source = realpath(STONEFELLOW_ROOT . '/' . ltrim($relative,'/'));
    if (!$root || !$source || !is_file($source)) throw new RuntimeException('The armed track media file was not found.');
    $prefix = rtrim($root,DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($source,$prefix)) throw new RuntimeException('The armed track media path is invalid.');
    return $source;
}

function stem_v213_take_directory(int $trackId, string $recordingId): array
{
    $token = bin2hex(random_bytes(5));
    $relative = '/uploads/stems/track-' . $trackId . '/take-v213-' . $recordingId . '-' . $token;
    $absolute = STONEFELLOW_ROOT . $relative;
    if (!is_dir($absolute) && !mkdir($absolute,0700,true) && !is_dir($absolute)) throw new RuntimeException('Could not create take-lane storage.');
    return [$relative,$absolute];
}

function stem_v213_remove_take_file(array $stem): void
{
    $relative = trim((string)($stem['file_path'] ?? ''));
    if ($relative === '' || !str_starts_with($relative,'/uploads/stems/')) return;
    $absolute = STONEFELLOW_ROOT . '/' . ltrim($relative,'/');
    if (is_file($absolute)) @unlink($absolute);
    $dir = dirname($absolute);
    if (str_contains(basename($dir),'take-v213-')) @rmdir($dir);
}

function stem_v213_recording_completed(int $trackId, string $recordingId): bool
{
    $dir = STONEFELLOW_ROOT . '/uploads/stems/track-' . $trackId . '/recording-' . $recordingId;
    if (!is_dir($dir) || is_file($dir . '/.recording.json')) return false;
    $wav = glob($dir . '/*.wav');
    return is_array($wav) && count($wav) > 0;
}

function stem_v213_finalize_summary(string $summary, string $recordingId): string
{
    $summary = preg_replace('/\s*·?\s*V213 take archive:\s*' . preg_quote($recordingId,'/') . '/i','',$summary) ?? $summary;
    return trim($summary," \t\n\r\0\x0B·");
}

function stem_v213_reconcile(PDO $pdo, int $trackId, int $hours = 168): array
{
    $hours = max(24,min(720,$hours));
    $threshold = date('Y-m-d H:i:s',time() - ($hours * 3600));
    $stmt = $pdo->prepare(
        "SELECT * FROM track_stems
         WHERE track_id=? AND is_active=1
           AND rpp_fx_summary LIKE '%V213 take archive:%'
         ORDER BY id ASC LIMIT 100"
    );
    $stmt->execute([$trackId]);
    $finalized = 0;
    $cleaned = 0;

    foreach ($stmt->fetchAll() as $row) {
        $summary = (string)($row['rpp_fx_summary'] ?? '');
        if (!preg_match('/V213 take archive:\s*([a-f0-9]{24})/i',$summary,$match)) continue;
        $recordingId = strtolower((string)$match[1]);

        if (stem_v213_recording_completed($trackId,$recordingId)) {
            $update = $pdo->prepare('UPDATE track_stems SET rpp_fx_summary=?,updated_at=NOW() WHERE id=? AND track_id=?');
            $update->execute([
                substr(stem_v213_finalize_summary($summary,$recordingId),0,1000),
                (int)$row['id'],
                $trackId,
            ]);
            $finalized += $update->rowCount() > 0 ? 1 : 0;
            continue;
        }

        if ((string)($row['created_at'] ?? '') >= $threshold) continue;
        $delete = $pdo->prepare('DELETE FROM track_stems WHERE id=? AND track_id=? AND rpp_fx_summary LIKE ?');
        $delete->execute([(int)$row['id'],$trackId,'%V213 take archive:%']);
        if ($delete->rowCount() > 0) {
            stem_v213_remove_take_file($row);
            $cleaned++;
        }
    }

    return ['finalized'=>$finalized,'cleaned'=>$cleaned,'max_age_hours'=>$hours];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') stem_v213_error('POST required.',405);
if (!verify_csrf()) stem_v213_error('Session expired. Refresh Stem Studio and try again.',403);

$pdo = db();
if (!$pdo) stem_v213_error('Database unavailable.',503);
$action = trim((string)($_POST['action'] ?? ''));
$trackId = max(0,(int)($_POST['track_id'] ?? 0));

try {
    stem_v213_track($trackId);

    if ($action === 'reconcile' || $action === 'cleanup_stale') {
        stem_v213_json(['ok'=>true] + stem_v213_reconcile($pdo,$trackId,168));
    }

    if ($action === 'prepare_take') {
        stem_v213_reconcile($pdo,$trackId,168);
        $recordingId = stem_v213_recording_id((string)($_POST['recording_id'] ?? ''));
        $targetStemId = max(0,(int)($_POST['target_stem_id'] ?? 0));
        if ($targetStemId < 1) throw new RuntimeException('Arm a Studio track before recording a take.');

        $createdFile = '';
        $createdDir = '';
        $pdo->beginTransaction();
        try {
            $targetStmt = $pdo->prepare('SELECT * FROM track_stems WHERE id=? AND track_id=? AND is_active=1 LIMIT 1 FOR UPDATE');
            $targetStmt->execute([$targetStemId,$trackId]);
            $target = $targetStmt->fetch();
            if (!$target) throw new RuntimeException('The armed Studio track no longer exists.');

            $parentId = stem_v213_take_parent_id($target);
            if ($parentId !== (int)$target['id']) {
                $targetStmt->execute([$parentId,$trackId]);
                $parent = $targetStmt->fetch();
                if (!$parent) throw new RuntimeException('The take-lane parent track no longer exists.');
            } else {
                $parent = $target;
            }

            $parentSummary = (string)($parent['rpp_fx_summary'] ?? '');
            if (stripos($parentSummary,'Empty recording track') !== false) {
                $pdo->commit();
                stem_v213_json([
                    'ok'=>true,'archive_required'=>false,'parent_stem_id'=>(int)$parent['id'],
                    'message'=>'The empty armed track can accept its first recording without archiving a take.',
                ]);
            }

            $source = stem_v213_source_path((string)$parent['file_path']);
            $baseName = stem_v213_base_name((string)$parent['stem_name']);
            $familyStmt = $pdo->prepare('SELECT stem_name,rpp_fx_summary FROM track_stems WHERE track_id=? AND is_active=1');
            $familyStmt->execute([$trackId]);
            $maxTake = 0;
            foreach ($familyStmt->fetchAll() as $row) {
                $summary = (string)($row['rpp_fx_summary'] ?? '');
                if (!preg_match('/Take of stem:\s*' . preg_quote((string)$parentId,'/') . '(?:\D|$)/i',$summary)) continue;
                if (preg_match('/\bTake\s+(\d+)\s*$/i',(string)$row['stem_name'],$match)) $maxTake = max($maxTake,(int)$match[1]);
            }

            $takeNumber = $maxTake + 1;
            $takeName = $baseName . ' Take ' . $takeNumber;
            [$relativeDir,$absoluteDir] = stem_v213_take_directory($trackId,$recordingId);
            $createdDir = $absoluteDir;
            $extension = strtolower((string)pathinfo((string)$parent['file_name'],PATHINFO_EXTENSION));
            $extension = preg_match('/^[a-z0-9]{1,8}$/',$extension) ? $extension : 'wav';
            $fileBase = stem_clean_filename($takeName);
            if ($fileBase === '') $fileBase = 'Audio-Take-' . $takeNumber;
            $fileName = $fileBase . '.' . $extension;
            $createdFile = $absoluteDir . '/' . $fileName;
            if (!copy($source,$createdFile)) throw new RuntimeException('Could not archive the current performance before recording.');
            @chmod($createdFile,0600);

            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM track_stems WHERE track_id=?');
            $sortStmt->execute([$trackId]);
            $sort = (int)$sortStmt->fetchColumn() + 1;
            $summaryBase = stem_v213_clean_summary($parentSummary);
            $takeSummary = $summaryBase . ($summaryBase !== '' ? ' · ' : '') . 'Take of stem: ' . $parentId . ' · V213 take archive: ' . $recordingId;

            $insert = $pdo->prepare(
                'INSERT INTO track_stems
                 (track_id,project_id,stem_name,stem_role,source_track_name,file_name,file_path,channels,sample_rate,bit_depth,duration_seconds,start_offset_seconds,rpp_track_guid,rpp_volume,rpp_pan,rpp_fx_summary,plugin_chain_json,sort_order,is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
            );
            $insert->execute([
                $trackId,(int)$parent['project_id'],$takeName,(string)$parent['stem_role'],(string)$parent['source_track_name'],
                $fileName,$relativeDir . '/' . $fileName,(int)$parent['channels'],(int)$parent['sample_rate'],(int)$parent['bit_depth'],
                (float)$parent['duration_seconds'],(float)$parent['start_offset_seconds'],'',(float)$parent['rpp_volume'],(float)$parent['rpp_pan'],
                substr($takeSummary,0,1000),$parent['plugin_chain_json'] ?? null,$sort,
            ]);
            $takeId = (int)$pdo->lastInsertId();
            $pdo->commit();

            stem_v213_json([
                'ok'=>true,'archive_required'=>true,'take_stem_id'=>$takeId,'take_name'=>$takeName,'take_number'=>$takeNumber,
                'parent_stem_id'=>$parentId,'recording_id'=>$recordingId,'message'=>'Current performance archived as a non-destructive take.',
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($createdFile !== '' && is_file($createdFile)) @unlink($createdFile);
            if ($createdDir !== '' && is_dir($createdDir)) @rmdir($createdDir);
            throw $e;
        }
    }

    if ($action === 'commit_take') {
        $recordingId = stem_v213_recording_id((string)($_POST['recording_id'] ?? ''));
        $takeStemId = max(0,(int)($_POST['take_stem_id'] ?? 0));
        $stmt = $pdo->prepare('SELECT id,rpp_fx_summary FROM track_stems WHERE id=? AND track_id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$takeStemId,$trackId]);
        $take = $stmt->fetch();
        if (!$take) throw new RuntimeException('Archived take not found.');
        $marker = 'V213 take archive: ' . $recordingId;
        $summary = (string)$take['rpp_fx_summary'];
        if (stripos($summary,$marker) === false) {
            if (preg_match('/Take of stem:\s*\d+/i',$summary)) {
                stem_v213_json(['ok'=>true,'committed'=>true,'already_committed'=>true,'take_stem_id'=>$takeStemId,'recording_id'=>$recordingId]);
            }
            throw new RuntimeException('Archived take does not belong to this recording session.');
        }
        $summary = stem_v213_finalize_summary($summary,$recordingId);
        $update = $pdo->prepare('UPDATE track_stems SET rpp_fx_summary=?,updated_at=NOW() WHERE id=? AND track_id=?');
        $update->execute([substr($summary,0,1000),$takeStemId,$trackId]);
        stem_v213_json(['ok'=>true,'committed'=>true,'already_committed'=>false,'take_stem_id'=>$takeStemId,'recording_id'=>$recordingId]);
    }

    if ($action === 'cleanup_take') {
        $recordingId = stem_v213_recording_id((string)($_POST['recording_id'] ?? ''));
        $takeStemId = max(0,(int)($_POST['take_stem_id'] ?? 0));
        $stmt = $pdo->prepare('SELECT * FROM track_stems WHERE id=? AND track_id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$takeStemId,$trackId]);
        $take = $stmt->fetch();
        if (!$take) stem_v213_json(['ok'=>true,'cleaned'=>false,'take_stem_id'=>$takeStemId]);
        $marker = 'V213 take archive: ' . $recordingId;
        if (stripos((string)$take['rpp_fx_summary'],$marker) === false) throw new RuntimeException('Committed takes cannot be removed by recording cleanup.');
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM track_stems WHERE id=? AND track_id=?');
            $delete->execute([$takeStemId,$trackId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        stem_v213_remove_take_file($take);
        stem_v213_json(['ok'=>true,'cleaned'=>true,'take_stem_id'=>$takeStemId,'recording_id'=>$recordingId]);
    }

    stem_v213_error('Unknown recording take action.',404);
} catch (Throwable $e) {
    stem_v213_error($e,400);
}
