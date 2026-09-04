<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function midi_v217_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function midi_v217_fail(string $message, int $status = 400): never
{
    midi_v217_json(['ok'=>false,'error'=>$message],$status);
}

function midi_v217_string(mixed $value, int $max = 80): string
{
    $text = trim(preg_replace('/\s+/u',' ',(string)$value) ?? (string)$value);
    return function_exists('mb_substr') ? mb_substr($text,0,$max,'UTF-8') : substr($text,0,$max);
}

function midi_v217_number(mixed $value, float $min, float $max, float $fallback = 0): float
{
    $number = is_numeric($value) ? (float)$value : $fallback;
    if (!is_finite($number)) $number = $fallback;
    return max($min,min($max,$number));
}

function midi_v217_id(mixed $value, string $prefix): string
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/','',(string)$value) ?? '';
    if ($id === '') $id = $prefix . '-' . bin2hex(random_bytes(6));
    return substr($id,0,80);
}

function midi_v217_optional_id(mixed $value): string
{
    return substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$value) ?? '',0,80);
}

function midi_v217_note(array $row, int $index): array
{
    return [
        'id'=>midi_v217_id($row['id'] ?? '', 'note' . $index),
        'pitch'=>(int)midi_v217_number($row['pitch'] ?? 60,0,127,60),
        'startTick'=>(int)midi_v217_number($row['startTick'] ?? 0,0,100000000,0),
        'durationTick'=>(int)midi_v217_number($row['durationTick'] ?? 240,1,10000000,240),
        'velocity'=>midi_v217_number($row['velocity'] ?? 0.8,0.01,1,0.8),
        'channel'=>(int)midi_v217_number($row['channel'] ?? 1,1,16,1),
    ];
}

function midi_v217_clip(array $row, int $index): array
{
    $notes = [];
    foreach (array_slice(is_array($row['notes'] ?? null) ? $row['notes'] : [],0,12000) as $noteIndex=>$note) {
        if (!is_array($note)) continue;
        $notes[] = midi_v217_note($note,(int)$noteIndex);
    }
    usort($notes,static fn(array $a,array $b): int => [$a['startTick'],$a['pitch']] <=> [$b['startTick'],$b['pitch']]);
    return [
        'id'=>midi_v217_id($row['id'] ?? '', 'clip' . $index),
        'name'=>midi_v217_string($row['name'] ?? ('MIDI Clip ' . ($index+1)),80),
        'startTick'=>(int)midi_v217_number($row['startTick'] ?? 0,0,100000000,0),
        'lengthTick'=>(int)midi_v217_number($row['lengthTick'] ?? 15360,1,100000000,15360),
        'loop'=>!empty($row['loop']),
        'notes'=>$notes,
    ];
}

function midi_v217_instrument(array $row): array
{
    $type = strtolower(midi_v217_string($row['type'] ?? 'poly',20));
    if (!in_array($type,['poly','drum'],true)) $type = 'poly';
    $wave = strtolower(midi_v217_string($row['waveform'] ?? 'sawtooth',20));
    if (!in_array($wave,['sine','triangle','square','sawtooth'],true)) $wave = 'sawtooth';
    return [
        'type'=>$type,
        'waveform'=>$wave,
        'attack'=>midi_v217_number($row['attack'] ?? 0.01,0.001,2,0.01),
        'release'=>midi_v217_number($row['release'] ?? 0.18,0.01,5,0.18),
        'gain'=>midi_v217_number($row['gain'] ?? 0.65,0,1,0.65),
        'octave'=>(int)midi_v217_number($row['octave'] ?? 0,-3,3,0),
    ];
}

function midi_v217_track_state(array $row, int $index): array
{
    $clips = [];
    foreach (array_slice(is_array($row['clips'] ?? null) ? $row['clips'] : [],0,256) as $clipIndex=>$clip) {
        if (!is_array($clip)) continue;
        $clips[] = midi_v217_clip($clip,(int)$clipIndex);
    }
    return [
        'id'=>midi_v217_id($row['id'] ?? '', 'midi' . $index),
        'name'=>midi_v217_string($row['name'] ?? ('MIDI ' . ($index+1)),80),
        'instrument'=>midi_v217_instrument(is_array($row['instrument'] ?? null) ? $row['instrument'] : []),
        'volume'=>midi_v217_number($row['volume'] ?? 0.8,0,1.5,0.8),
        'pan'=>midi_v217_number($row['pan'] ?? 0,-1,1,0),
        'muted'=>!empty($row['muted']),
        'solo'=>!empty($row['solo']),
        'armed'=>!empty($row['armed']),
        'clips'=>$clips,
    ];
}

function midi_v218_clean_step(mixed $value): array
{
    $row = is_array($value) ? $value : [];
    return [
        'on'=>!empty($row['on']),
        'velocity'=>midi_v217_number($row['velocity'] ?? .82,.01,1,.82),
        'probability'=>midi_v217_number($row['probability'] ?? 1,0,1,1),
        'lengthSteps'=>midi_v217_number($row['lengthSteps'] ?? .9,.05,4,.9),
    ];
}

function midi_v218_clean_composition(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $scale = is_array($raw['scale'] ?? null) ? $raw['scale'] : [];
    $mode = strtolower(midi_v217_string($scale['mode'] ?? 'major',24));
    if (!in_array($mode,['major','minor','dorian','mixolydian','pentatonic','minor_pentatonic'],true)) $mode = 'major';
    $patterns = [];
    foreach (array_slice(is_array($raw['patterns'] ?? null) ? $raw['patterns'] : [],0,128) as $patternIndex=>$pattern) {
        if (!is_array($pattern)) continue;
        $steps = (int)midi_v217_number($pattern['steps'] ?? 16,8,64,16);
        if (!in_array($steps,[8,16,32,64],true)) $steps = 16;
        $division = midi_v217_string($pattern['division'] ?? '1/16',8);
        if (!in_array($division,['1/4','1/8','1/16','1/32','1/8T','1/16T'],true)) $division = '1/16';
        $lanes = [];
        foreach (array_slice(is_array($pattern['lanes'] ?? null) ? $pattern['lanes'] : [],0,32) as $laneIndex=>$lane) {
            if (!is_array($lane)) continue;
            $cleanSteps = [];
            for ($stepIndex=0;$stepIndex<$steps;$stepIndex++) {
                $cleanSteps[] = midi_v218_clean_step(is_array($lane['steps'][$stepIndex] ?? null) ? $lane['steps'][$stepIndex] : []);
            }
            $lanes[] = [
                'pitch'=>(int)midi_v217_number($lane['pitch'] ?? (36+$laneIndex),0,127,36+$laneIndex),
                'name'=>midi_v217_string($lane['name'] ?? ('Lane '.($laneIndex+1)),40),
                'steps'=>$cleanSteps,
            ];
        }
        $patterns[] = [
            'id'=>midi_v217_optional_id($pattern['id'] ?? '') ?: 'pattern-'.($patternIndex+1),
            'name'=>midi_v217_string($pattern['name'] ?? ('Pattern '.($patternIndex+1)),80),
            'trackId'=>midi_v217_optional_id($pattern['trackId'] ?? ''),
            'clipId'=>midi_v217_optional_id($pattern['clipId'] ?? ''),
            'division'=>$division,
            'steps'=>$steps,
            'startTick'=>(int)midi_v217_number($pattern['startTick'] ?? 0,0,100000000,0),
            'seed'=>(int)midi_v217_number($pattern['seed'] ?? 1,0,2147483647,1),
            'lanes'=>$lanes,
        ];
    }
    $ccLanes = [];
    foreach (array_slice(is_array($raw['ccLanes'] ?? null) ? $raw['ccLanes'] : [],0,128) as $laneIndex=>$lane) {
        if (!is_array($lane)) continue;
        $controller = midi_v217_string($lane['controller'] ?? '1',8);
        if ($controller !== 'pitch' && (!ctype_digit($controller) || (int)$controller > 127)) $controller = '1';
        $maxValue = $controller === 'pitch' ? 16383 : 127;
        $points = [];
        foreach (array_slice(is_array($lane['points'] ?? null) ? $lane['points'] : [],0,2048) as $point) {
            if (!is_array($point)) continue;
            $points[] = [
                'tick'=>(int)midi_v217_number($point['tick'] ?? 0,0,100000000,0),
                'value'=>midi_v217_number($point['value'] ?? 0,0,$maxValue,0),
            ];
        }
        usort($points,static fn(array $a,array $b): int => $a['tick'] <=> $b['tick']);
        $ccLanes[] = [
            'id'=>midi_v217_optional_id($lane['id'] ?? '') ?: 'cc-'.($laneIndex+1),
            'trackId'=>midi_v217_optional_id($lane['trackId'] ?? ''),
            'clipId'=>midi_v217_optional_id($lane['clipId'] ?? ''),
            'controller'=>$controller,
            'points'=>$points,
        ];
    }
    $ghosts = [];
    foreach (array_slice(is_array($raw['ghostTrackIds'] ?? null) ? $raw['ghostTrackIds'] : [],0,16) as $trackId) {
        $clean = midi_v217_optional_id($trackId);
        if ($clean !== '' && !in_array($clean,$ghosts,true)) $ghosts[] = $clean;
    }
    $humanize = is_array($raw['humanize'] ?? null) ? $raw['humanize'] : [];
    return [
        'version'=>1,
        'updatedAt'=>(int)midi_v217_number($raw['updatedAt'] ?? 0,0,9999999999999,0),
        'scale'=>[
            'root'=>(int)midi_v217_number($scale['root'] ?? 0,0,11,0),
            'mode'=>$mode,
            'lock'=>!empty($scale['lock']),
        ],
        'swing'=>midi_v217_number($raw['swing'] ?? 0,0,75,0),
        'humanize'=>[
            'timingTicks'=>(int)midi_v217_number($humanize['timingTicks'] ?? 0,0,240,0),
            'velocityPercent'=>midi_v217_number($humanize['velocityPercent'] ?? 0,0,60,0),
        ],
        'patterns'=>$patterns,
        'ccLanes'=>$ccLanes,
        'ghostTrackIds'=>$ghosts,
    ];
}

function midi_v217_clean_state(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $tracks = [];
    foreach (array_slice(is_array($raw['tracks'] ?? null) ? $raw['tracks'] : [],0,64) as $index=>$track) {
        if (!is_array($track)) continue;
        $tracks[] = midi_v217_track_state($track,(int)$index);
    }
    return [
        'version'=>1,
        'ppq'=>960,
        'tracks'=>$tracks,
        'selectedTrackId'=>midi_v217_optional_id($raw['selectedTrackId'] ?? ''),
        'selectedClipId'=>midi_v217_optional_id($raw['selectedClipId'] ?? ''),
        'updatedAt'=>(int)midi_v217_number($raw['updatedAt'] ?? 0,0,9999999999999,0),
        'compositionV218'=>midi_v218_clean_composition($raw['compositionV218'] ?? []),
    ];
}

function midi_v217_mix_row(int $trackId, int $mixId): array
{
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0);
    if (!$pdo || $mixId < 1 || $userId < 1 || !table_exists('stem_mix_saves')) {
        throw new RuntimeException('Saved mix storage is unavailable.');
    }
    $stmt = $pdo->prepare('SELECT id,mix_json FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=? LIMIT 1');
    $stmt->execute([$mixId,$userId,$trackId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Saved mix not found.');
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') midi_v217_fail('POST required.',405);
if (!is_logged_in()) midi_v217_fail('Sign in required.',401);
if (!midi_v217_feature_enabled()) midi_v217_fail('MIDI Studio is disabled.',403);
if (!permission_v105_has('midi.access')) midi_v217_fail('Your account does not have MIDI Studio permission.',403);
if (!verify_csrf()) midi_v217_fail('Session expired. Refresh Stem Studio and try again.',403);
if (!midi_v217_schema_ready()) midi_v217_fail('MIDI storage requires a database upgrade.',503);

$pdo = db();
if (!$pdo) midi_v217_fail('Database unavailable.',503);
$trackId = max(0,(int)($_POST['track_id'] ?? 0));
$action = trim((string)($_POST['action'] ?? 'load'));

try {
    midi_v217_track($trackId);

    if ($action === 'snapshot_attach') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $decoded = json_decode((string)($_POST['state_json'] ?? ''),true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid MIDI snapshot.');
        $row = midi_v217_mix_row($trackId,$mixId);
        $mix = json_decode((string)$row['mix_json'],true);
        if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
        $mix['midiV217'] = midi_v217_clean_state($decoded);
        $json = json_encode($mix,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 16777216) throw new RuntimeException('Saved session is too large to include MIDI.');
        $stmt = $pdo->prepare('UPDATE stem_mix_saves SET mix_json=?,updated_at=NOW() WHERE id=? AND user_id=? AND track_id=?');
        $stmt->execute([$json,$mixId,(int)(current_user()['id'] ?? 0),$trackId]);
        midi_v217_json(['ok'=>true,'build'=>STONEFELLOW_MIDI_V217,'mix_id'=>$mixId,'state'=>$mix['midiV217']]);
    }

    if ($action === 'snapshot_load') {
        $mixId = max(0,(int)($_POST['mix_id'] ?? 0));
        $row = midi_v217_mix_row($trackId,$mixId);
        $mix = json_decode((string)$row['mix_json'],true);
        if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
        $hasMidi = is_array($mix['midiV217'] ?? null);
        midi_v217_json([
            'ok'=>true,
            'build'=>STONEFELLOW_MIDI_V217,
            'mix_id'=>$mixId,
            'has_midi'=>$hasMidi,
            'state'=>$hasMidi ? midi_v217_clean_state($mix['midiV217']) : null,
        ]);
    }

    if ($action === 'load') {
        $stmt = $pdo->prepare('SELECT state_json,state_version,updated_at FROM stem_midi_projects_v217 WHERE track_id=? LIMIT 1');
        $stmt->execute([$trackId]);
        $row = $stmt->fetch();
        $state = midi_v217_empty_state();
        if ($row) {
            $decoded = json_decode((string)$row['state_json'],true);
            if (is_array($decoded)) $state = midi_v217_clean_state($decoded);
        }
        if (!isset($state['compositionV218'])) $state['compositionV218'] = midi_v218_clean_composition([]);
        midi_v217_json(['ok'=>true,'build'=>STONEFELLOW_MIDI_V217,'state'=>$state,'updated_at'=>(string)($row['updated_at'] ?? '')]);
    }

    if ($action === 'save') {
        $decoded = json_decode((string)($_POST['state_json'] ?? ''),true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid MIDI project state.');
        $state = midi_v217_clean_state($decoded);
        $json = json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 16777216) throw new RuntimeException('MIDI project is too large.');
        $stmt = $pdo->prepare(
            'INSERT INTO stem_midi_projects_v217 (track_id,state_json,state_version,updated_by_user_id)
             VALUES (?,?,1,?)
             ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),state_version=1,updated_by_user_id=VALUES(updated_by_user_id),updated_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute([$trackId,$json,(int)(current_user()['id'] ?? 0)]);
        midi_v217_json(['ok'=>true,'build'=>STONEFELLOW_MIDI_V217,'state'=>$state]);
    }

    if ($action === 'reset') {
        $pdo->prepare('DELETE FROM stem_midi_projects_v217 WHERE track_id=?')->execute([$trackId]);
        $empty = midi_v217_empty_state();
        $empty['compositionV218'] = midi_v218_clean_composition([]);
        midi_v217_json(['ok'=>true,'build'=>STONEFELLOW_MIDI_V217,'state'=>$empty]);
    }

    midi_v217_fail('Unknown MIDI action.');
} catch (Throwable $e) {
    midi_v217_fail($e->getMessage(),400);
}
