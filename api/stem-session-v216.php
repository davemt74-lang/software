<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const STEM_V216_AUTOSAVE_NAME = '__V216_AUTOSAVE__';
const STEM_V216_SLOT_A_NAME = '__V216_SLOT_A__';
const STEM_V216_SLOT_B_NAME = '__V216_SLOT_B__';
const STEM_V216_CHECKPOINT_PREFIX = '__V216_CHECKPOINT__:';
const STEM_V216_RESERVED_PREFIX = '__V216_';

function stem_v216_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stem_v216_error(Throwable|string $error, int $status = 400): never
{
    $message = $error instanceof Throwable ? $error->getMessage() : trim((string)$error);
    stem_v216_reply(['ok'=>false,'error'=>$message !== '' ? $message : 'Session request failed.'],$status);
}

function stem_v216_cut(string $value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/u',' ',$value) ?? $value);
    if (function_exists('mb_substr')) return mb_substr($value,0,$max,'UTF-8');
    return substr($value,0,$max);
}

function stem_v216_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1) throw new RuntimeException('Track not found.');
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) throw new RuntimeException('Track not found.');
    $user = current_user();
    $allowed = (user_has_role('fan',$user) && can_view_track($track,$user))
        || has_permission('track_notes.manage',$user)
        || can_manage_track_production($track,$user)
        || (int)($track['owner_user_id'] ?? 0) === (int)($user['id'] ?? 0);
    if (!$allowed) stem_v216_error('You do not have permission to save this Studio session.',403);
    return $track;
}

function stem_v216_mix_row(int $trackId, int $mixId): array
{
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0);
    if (!$pdo || $userId < 1 || $mixId < 1 || !table_exists('stem_mix_saves')) {
        throw new RuntimeException('Saved mix storage is unavailable.');
    }
    $stmt = $pdo->prepare('SELECT id,mix_name,mix_json,created_at,updated_at FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=? LIMIT 1');
    $stmt->execute([$mixId,$userId,$trackId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Saved mix not found.');
    return $row;
}

function stem_v216_clean_modes(mixed $value): array
{
    $out = [];
    if (!is_array($value)) return $out;
    foreach (array_slice($value,0,256,true) as $stemId=>$mode) {
        $id = (int)$stemId;
        $clean = strtolower(trim((string)$mode));
        if ($id < 1 || !in_array($clean,['read','touch','latch','write'],true)) continue;
        $out[(string)$id] = $clean;
    }
    return $out;
}

function stem_v216_clean_plugin_targets(mixed $value): array
{
    $out = [];
    if (!is_array($value)) return $out;
    foreach (array_slice($value,0,256,true) as $stemId=>$target) {
        $id = (int)$stemId;
        $target = substr(preg_replace('/[^a-zA-Z0-9:_-]/','',(string)$target) ?? '',0,160);
        if ($id < 1 || ($target !== '' && !preg_match('/^plugin:\d+:[a-z0-9_-]+:[a-zA-Z0-9_-]+$/',$target))) continue;
        $out[(string)$id] = $target;
    }
    return $out;
}

function stem_v216_clean_bool_map(mixed $value): array
{
    $out = [];
    if (!is_array($value)) return $out;
    foreach (array_slice($value,0,256,true) as $stemId=>$enabled) {
        $id = (int)$stemId;
        if ($id < 1) continue;
        $out[(string)$id] = !empty($enabled);
    }
    return $out;
}

function stem_v216_clean_plugin_automation(mixed $value): array
{
    $out = [];
    if (!is_array($value)) return $out;
    foreach (array_slice($value,0,256,true) as $stemId=>$targets) {
        $id = (int)$stemId;
        if ($id < 1 || !is_array($targets)) continue;
        $cleanTargets = [];
        foreach (array_slice($targets,0,64,true) as $target=>$points) {
            $cleanTarget = substr(preg_replace('/[^a-zA-Z0-9:_-]/','',(string)$target) ?? '',0,160);
            if (!preg_match('/^plugin:\d+:[a-z0-9_-]+:[a-zA-Z0-9_-]+$/',$cleanTarget) || !is_array($points)) continue;
            $cleanPoints = [];
            foreach (array_slice($points,0,1200) as $point) {
                if (!is_array($point)) continue;
                $time = max(0.0,min(86400.0,(float)($point['t'] ?? 0)));
                $number = (float)($point['v'] ?? 0);
                if (!is_finite($number)) $number = 0.0;
                $number = max(-1000000.0,min(1000000.0,$number));
                $cleanPoints[] = ['t'=>round($time,5),'v'=>round($number,7)];
            }
            usort($cleanPoints,static fn(array $a,array $b): int => $a['t'] <=> $b['t']);
            if ($cleanPoints) $cleanTargets[$cleanTarget] = $cleanPoints;
        }
        if ($cleanTargets) $out[(string)$id] = $cleanTargets;
    }
    return $out;
}

function stem_v216_clean_v211(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $density = strtolower(trim((string)($raw['density'] ?? 'normal')));
    if (!in_array($density,['compact','normal','wide'],true)) $density = 'normal';
    return [
        'density'=>$density,
        'followClips'=>!array_key_exists('followClips',$raw) || !empty($raw['followClips']),
        'modes'=>stem_v216_clean_modes($raw['modes'] ?? []),
        'pluginTargets'=>stem_v216_clean_plugin_targets($raw['pluginTargets'] ?? []),
        'pluginAutomation'=>stem_v216_clean_plugin_automation($raw['pluginAutomation'] ?? []),
        'draw'=>stem_v216_clean_bool_map($raw['draw'] ?? []),
        'peakHoldMs'=>max(250,min(10000,(int)($raw['peakHoldMs'] ?? 1500))),
    ];
}

function stem_v216_session_payload(array $raw): array
{
    $kind = strtolower(trim((string)($raw['kind'] ?? 'user_save')));
    if (!in_array($kind,['autosave','slot_a','slot_b','checkpoint','user_save'],true)) $kind = 'user_save';
    return [
        'build'=>'stem-session-safety-v216-20260901',
        'kind'=>$kind,
        'label'=>stem_v216_cut((string)($raw['label'] ?? ''),120),
        'signature'=>substr(preg_replace('/[^a-zA-Z0-9:_-]/','',(string)($raw['signature'] ?? '')) ?? '',0,160),
        'savedAt'=>max(0,(int)($raw['savedAt'] ?? round(microtime(true)*1000))),
        'automationV211'=>stem_v216_clean_v211($raw['automationV211'] ?? []),
    ];
}

function stem_v216_write_session(int $trackId, int $mixId, array $payload): array
{
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0);
    $row = stem_v216_mix_row($trackId,$mixId);
    $mix = json_decode((string)$row['mix_json'],true);
    if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
    $mix['sessionV216'] = stem_v216_session_payload($payload);
    $json = json_encode($mix,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 16777216) throw new RuntimeException('Session state is too large.');
    $stmt = $pdo->prepare('UPDATE stem_mix_saves SET mix_json=?,updated_at=NOW() WHERE id=? AND user_id=? AND track_id=?');
    $stmt->execute([$json,$mixId,$userId,$trackId]);
    return ['row'=>$row,'session'=>$mix['sessionV216']];
}

function stem_v216_read_session(array $row): ?array
{
    $mix = json_decode((string)($row['mix_json'] ?? ''),true);
    if (!is_array($mix) || !is_array($mix['sessionV216'] ?? null)) return null;
    return stem_v216_session_payload($mix['sessionV216']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') stem_v216_error('POST required.',405);
if (!verify_csrf()) stem_v216_error('Session expired. Refresh Stem Studio and try again.',403);

$pdo = db();
if (!$pdo) stem_v216_error('Database unavailable.',503);
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$trackId = max(0,(int)($_POST['track_id'] ?? 0));

try {
    stem_v216_track($trackId);

    if ($action === 'attach') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $automation = json_decode((string)($_POST['automation_json'] ?? ''),true);
        $payload = [
            'kind'=>(string)($_POST['kind'] ?? 'user_save'),
            'label'=>(string)($_POST['label'] ?? ''),
            'signature'=>(string)($_POST['signature'] ?? ''),
            'savedAt'=>max(0,(int)($_POST['saved_at'] ?? round(microtime(true)*1000))),
            'automationV211'=>is_array($automation) ? $automation : [],
        ];
        $written = stem_v216_write_session($trackId,$mixId,$payload);

        if (($written['session']['kind'] ?? '') === 'checkpoint') {
            $userId = (int)(current_user()['id'] ?? 0);
            $rows = $pdo->prepare('SELECT id FROM stem_mix_saves WHERE user_id=? AND track_id=? AND LEFT(mix_name,20)=? ORDER BY updated_at DESC,id DESC');
            $rows->execute([$userId,$trackId,STEM_V216_CHECKPOINT_PREFIX]);
            $ids = array_map('intval',$rows->fetchAll(PDO::FETCH_COLUMN));
            foreach (array_slice($ids,20) as $removeId) {
                $delete = $pdo->prepare('DELETE FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=?');
                $delete->execute([$removeId,$userId,$trackId]);
            }
        }

        stem_v216_reply(['ok'=>true,'mix_id'=>$mixId,'session'=>$written['session']]);
    }

    if ($action === 'load') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $row = stem_v216_mix_row($trackId,$mixId);
        $session = stem_v216_read_session($row);
        stem_v216_reply(['ok'=>true,'mix_id'=>$mixId,'has_session'=>$session !== null,'session'=>$session]);
    }

    if ($action === 'index') {
        $userId = (int)(current_user()['id'] ?? 0);
        if (!table_exists('stem_mix_saves')) throw new RuntimeException('Saved mix storage is unavailable.');
        $stmt = $pdo->prepare('SELECT id,mix_name,mix_json,created_at,updated_at FROM stem_mix_saves WHERE user_id=? AND track_id=? AND LEFT(mix_name,7)=? ORDER BY updated_at DESC,id DESC LIMIT 80');
        $stmt->execute([$userId,$trackId,STEM_V216_RESERVED_PREFIX]);
        $result = ['autosave'=>null,'slot_a'=>null,'slot_b'=>null,'checkpoints'=>[]];
        foreach ($stmt->fetchAll() as $row) {
            $name = (string)$row['mix_name'];
            $session = stem_v216_read_session($row);
            $item = [
                'id'=>(int)$row['id'],
                'name'=>$name,
                'label'=>(string)($session['label'] ?? ''),
                'signature'=>(string)($session['signature'] ?? ''),
                'saved_at'=>(int)($session['savedAt'] ?? 0),
                'updated_at'=>(string)($row['updated_at'] ?? ''),
                'created_at'=>(string)($row['created_at'] ?? ''),
            ];
            if ($name === STEM_V216_AUTOSAVE_NAME) {
                if ($result['autosave'] === null) $result['autosave'] = $item;
            } elseif ($name === STEM_V216_SLOT_A_NAME) {
                if ($result['slot_a'] === null) $result['slot_a'] = $item;
            } elseif ($name === STEM_V216_SLOT_B_NAME) {
                if ($result['slot_b'] === null) $result['slot_b'] = $item;
            } elseif (str_starts_with($name,STEM_V216_CHECKPOINT_PREFIX)) {
                $result['checkpoints'][] = $item;
            }
        }
        $result['checkpoints'] = array_slice($result['checkpoints'],0,20);
        stem_v216_reply(['ok'=>true,'records'=>$result]);
    }

    if ($action === 'delete_checkpoint') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $row = stem_v216_mix_row($trackId,$mixId);
        if (!str_starts_with((string)$row['mix_name'],STEM_V216_CHECKPOINT_PREFIX)) {
            throw new RuntimeException('Only a v216 checkpoint can be deleted here.');
        }
        $stmt = $pdo->prepare('DELETE FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=?');
        $stmt->execute([$mixId,(int)(current_user()['id'] ?? 0),$trackId]);
        stem_v216_reply(['ok'=>true,'deleted'=>$stmt->rowCount()>0,'mix_id'=>$mixId]);
    }

    stem_v216_error('Unknown session action.',400);
} catch (Throwable $e) {
    stem_v216_error($e,400);
}
