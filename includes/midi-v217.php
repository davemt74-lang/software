<?php
declare(strict_types=1);

const STONEFELLOW_MIDI_V217 = 'stem-midi-foundation-v217-20260901';

function midi_v217_feature_enabled(): bool
{
    return (string)setting('midi_feature_enabled_v217','0') === '1';
}

function midi_v217_can_manage(?array $user = null): bool
{
    return permission_v105_has('midi.manage',$user);
}

function midi_v217_can_access(?array $user = null): bool
{
    $user ??= current_user();
    return midi_v217_feature_enabled() && permission_v105_has('midi.access',$user);
}

function midi_v217_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    return (bool)$pdo && table_exists('stem_midi_projects_v217');
}

function midi_v217_seed_permissions(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo || !permissions_schema_ready()) return;
    if ((string)setting('midi_permissions_seed_v217','') === '1') return;

    $catalog = permission_v105_catalog();
    $defaults = permission_v105_default_roles();
    $keys = ['midi.access','midi.manage'];

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO permissions (permission_key,label,description,category,sort_order)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)'
        );
        $grant = $pdo->prepare('INSERT IGNORE INTO role_permissions (role,permission_key) VALUES (?,?)');
        foreach ($keys as $key) {
            $permission = $catalog[$key] ?? null;
            if (!$permission) continue;
            $upsert->execute([$key,$permission['label'],$permission['description'],$permission['category'],$permission['sort_order']]);
            foreach ($defaults[$key] ?? [] as $role) $grant->execute([$role,$key]);
        }
        $pdo->commit();
        save_setting('midi_permissions_seed_v217','1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function midi_v217_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database unavailable.');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stem_midi_projects_v217 (
            track_id INT UNSIGNED NOT NULL,
            state_json LONGTEXT NOT NULL,
            state_version INT UNSIGNED NOT NULL DEFAULT 1,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (track_id),
            KEY idx_midi_v217_updated_by (updated_by_user_id),
            CONSTRAINT fk_midi_v217_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
            CONSTRAINT fk_midi_v217_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    midi_v217_seed_permissions($pdo);
}

function midi_v217_require_access(): void
{
    if (!is_logged_in()) {
        flash('error','Please sign in to continue.');
        redirect(url('/login.php'));
    }
    if (!midi_v217_feature_enabled()) {
        http_response_code(403);
        exit('MIDI is currently disabled.');
    }
    permission_v105_require('midi.access');
}

function midi_v217_require_manage(): void
{
    permission_v105_require('midi.manage');
}

function midi_v217_track(int $trackId): array
{
    $pdo = db();
    if (!$pdo || $trackId < 1) throw new RuntimeException('Track not found.');
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $track = $stmt->fetch();
    if (!$track) throw new RuntimeException('Track not found.');
    if (!can_manage_track_production($track)) throw new RuntimeException('This track is not available to your production account.');
    return $track;
}

function midi_v217_empty_state(): array
{
    return [
        'version'=>1,
        'ppq'=>960,
        'tracks'=>[],
        'selectedTrackId'=>'',
        'selectedClipId'=>'',
        'updatedAt'=>0,
    ];
}

function midi_v217_snapshot_text(mixed $value, int $max = 80): string
{
    $text = trim(preg_replace('/\s+/u',' ',(string)$value) ?? (string)$value);
    return function_exists('mb_substr') ? mb_substr($text,0,$max,'UTF-8') : substr($text,0,$max);
}

function midi_v217_snapshot_id(mixed $value): string
{
    return substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$value) ?? '',0,80);
}

function midi_v217_snapshot_number(mixed $value, float $min, float $max, float $fallback = 0): float
{
    $number = is_numeric($value) ? (float)$value : $fallback;
    if (!is_finite($number)) $number = $fallback;
    return max($min,min($max,$number));
}

/**
 * Validate a client MIDI state before it is embedded into a v216 A/B,
 * checkpoint, autosave, or named mix snapshot. This deliberately mirrors the
 * standalone MIDI API limits so a saved session cannot become a storage bypass.
 */
function midi_v217_clean_snapshot(mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $tracks = [];

    foreach (array_slice(is_array($raw['tracks'] ?? null) ? $raw['tracks'] : [],0,64) as $trackIndex=>$track) {
        if (!is_array($track)) continue;
        $instrument = is_array($track['instrument'] ?? null) ? $track['instrument'] : [];
        $type = strtolower(midi_v217_snapshot_text($instrument['type'] ?? 'poly',20));
        if (!in_array($type,['poly','drum'],true)) $type = 'poly';
        $waveform = strtolower(midi_v217_snapshot_text($instrument['waveform'] ?? ($type === 'drum' ? 'sine' : 'sawtooth'),20));
        if (!in_array($waveform,['sine','triangle','square','sawtooth'],true)) $waveform = 'sawtooth';

        $clips = [];
        foreach (array_slice(is_array($track['clips'] ?? null) ? $track['clips'] : [],0,256) as $clipIndex=>$clip) {
            if (!is_array($clip)) continue;
            $notes = [];
            foreach (array_slice(is_array($clip['notes'] ?? null) ? $clip['notes'] : [],0,12000) as $noteIndex=>$note) {
                if (!is_array($note)) continue;
                $noteId = midi_v217_snapshot_id($note['id'] ?? '');
                if ($noteId === '') $noteId = 'note-' . $trackIndex . '-' . $clipIndex . '-' . $noteIndex;
                $notes[] = [
                    'id'=>$noteId,
                    'pitch'=>(int)midi_v217_snapshot_number($note['pitch'] ?? 60,0,127,60),
                    'startTick'=>(int)midi_v217_snapshot_number($note['startTick'] ?? 0,0,100000000,0),
                    'durationTick'=>(int)midi_v217_snapshot_number($note['durationTick'] ?? 240,1,10000000,240),
                    'velocity'=>midi_v217_snapshot_number($note['velocity'] ?? .8,.01,1,.8),
                    'channel'=>(int)midi_v217_snapshot_number($note['channel'] ?? 1,1,16,1),
                ];
            }
            usort($notes,static fn(array $a,array $b): int => [$a['startTick'],$a['pitch']] <=> [$b['startTick'],$b['pitch']]);
            $clipId = midi_v217_snapshot_id($clip['id'] ?? '');
            if ($clipId === '') $clipId = 'clip-' . $trackIndex . '-' . $clipIndex;
            $clips[] = [
                'id'=>$clipId,
                'name'=>midi_v217_snapshot_text($clip['name'] ?? ('Clip ' . ($clipIndex+1)),80),
                'startTick'=>(int)midi_v217_snapshot_number($clip['startTick'] ?? 0,0,100000000,0),
                'lengthTick'=>(int)midi_v217_snapshot_number($clip['lengthTick'] ?? 15360,1,100000000,15360),
                'loop'=>!empty($clip['loop']),
                'notes'=>$notes,
            ];
        }

        $trackId = midi_v217_snapshot_id($track['id'] ?? '');
        if ($trackId === '') $trackId = 'midi-' . $trackIndex;
        $tracks[] = [
            'id'=>$trackId,
            'name'=>midi_v217_snapshot_text($track['name'] ?? ('MIDI ' . ($trackIndex+1)),80),
            'instrument'=>[
                'type'=>$type,
                'waveform'=>$waveform,
                'attack'=>midi_v217_snapshot_number($instrument['attack'] ?? .01,.001,2,.01),
                'release'=>midi_v217_snapshot_number($instrument['release'] ?? .18,.01,5,.18),
                'gain'=>midi_v217_snapshot_number($instrument['gain'] ?? .65,0,1,.65),
                'octave'=>(int)midi_v217_snapshot_number($instrument['octave'] ?? 0,-3,3,0),
            ],
            'volume'=>midi_v217_snapshot_number($track['volume'] ?? .8,0,1.5,.8),
            'pan'=>midi_v217_snapshot_number($track['pan'] ?? 0,-1,1,0),
            'muted'=>!empty($track['muted']),
            'solo'=>!empty($track['solo']),
            'armed'=>!empty($track['armed']),
            'clips'=>$clips,
        ];
    }

    return [
        'version'=>1,
        'ppq'=>960,
        'tracks'=>$tracks,
        'selectedTrackId'=>midi_v217_snapshot_id($raw['selectedTrackId'] ?? ''),
        'selectedClipId'=>midi_v217_snapshot_id($raw['selectedClipId'] ?? ''),
        'updatedAt'=>(int)midi_v217_snapshot_number($raw['updatedAt'] ?? 0,0,9999999999999,0),
    ];
}
