<?php
declare(strict_types=1);

const STONEFELLOW_STUDIO_VOICE_PROFILE = 'studio-voice-profile-20260903';
const STONEFELLOW_STUDIO_VOICE_SAMPLE_MAX_BYTES = 26214400;

function studio_voice_profile_schema_ready(): bool
{
    return table_exists('studio_voice_samples');
}

function studio_voice_profile_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS studio_voice_samples (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            sample_key CHAR(32) NOT NULL,
            file_name VARCHAR(96) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            file_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_type VARCHAR(20) NOT NULL DEFAULT 'upload',
            sample_status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_studio_voice_sample_key (owner_user_id, sample_key),
            INDEX idx_studio_voice_sample_owner (owner_user_id, sample_status, created_at, id),
            INDEX idx_studio_voice_sample_participant (participant_id, sample_status, created_at, id),
            CONSTRAINT fk_studio_voice_sample_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_studio_voice_sample_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function studio_voice_profile_self(PDO $pdo, array $user): array
{
    return studio_participants_ensure_self($pdo, $user);
}

function studio_voice_profile_private_dir(array $user): string
{
    return STONEFELLOW_ROOT . '/private/studio-voice-samples/' . max(0, (int)($user['id'] ?? 0));
}

function studio_voice_profile_sample(PDO $pdo, array $user, int $sampleId, bool $activeOnly = true): array
{
    if ($sampleId < 1) throw new RuntimeException('Choose a voice sample.');
    $sql = 'SELECT * FROM studio_voice_samples WHERE id=? AND owner_user_id=?';
    if ($activeOnly) $sql .= " AND sample_status='active'";
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sampleId, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Voice sample not found.');
    return $row;
}

function studio_voice_profile_sample_path(array $user, array $sample): string
{
    $file = basename((string)($sample['file_name'] ?? ''));
    if ($file === '') throw new RuntimeException('Voice sample file is unavailable.');
    return studio_voice_profile_private_dir($user) . '/' . $file;
}

function studio_voice_profile_list_samples(PDO $pdo, array $user, int $participantId): array
{
    $stmt = $pdo->prepare(
        "SELECT id,sample_key,file_name,mime_type,file_bytes,duration_ms,source_type,created_at
         FROM studio_voice_samples
         WHERE owner_user_id=? AND participant_id=? AND sample_status='active'
         ORDER BY created_at DESC,id DESC LIMIT 20"
    );
    $stmt->execute([(int)$user['id'], $participantId]);
    $rows = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $rows[] = [
            'id'=>(int)$row['id'],
            'sample_key'=>(string)$row['sample_key'],
            'mime_type'=>(string)$row['mime_type'],
            'bytes'=>max(0,(int)$row['file_bytes']),
            'duration_ms'=>max(0,(int)$row['duration_ms']),
            'source_type'=>(string)$row['source_type'],
            'created_at'=>(string)$row['created_at'],
            'url'=>url('/api/studio-voice-profile.php?action=sample&sample_id='.(int)$row['id']),
        ];
    }
    return $rows;
}

function studio_voice_profile_format(array $upload): array
{
    $tmp = (string)($upload['tmp_name'] ?? '');
    $declared = strtolower(trim((string)($upload['type'] ?? '')));
    $detected = '';
    if ($tmp !== '' && is_file($tmp) && function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = strtolower(trim((string)@finfo_file($finfo, $tmp)));
            @finfo_close($finfo);
        }
    }
    $formats = [
        'audio/webm'=>['ext'=>'webm','mime'=>'audio/webm'],
        'video/webm'=>['ext'=>'webm','mime'=>'audio/webm'],
        'audio/ogg'=>['ext'=>'ogg','mime'=>'audio/ogg'],
        'application/ogg'=>['ext'=>'ogg','mime'=>'audio/ogg'],
        'audio/mp4'=>['ext'=>'m4a','mime'=>'audio/mp4'],
        'video/mp4'=>['ext'=>'m4a','mime'=>'audio/mp4'],
        'audio/mpeg'=>['ext'=>'mp3','mime'=>'audio/mpeg'],
        'audio/wav'=>['ext'=>'wav','mime'=>'audio/wav'],
        'audio/x-wav'=>['ext'=>'wav','mime'=>'audio/wav'],
    ];
    foreach (array_unique(array_filter([$detected, $declared])) as $candidate) {
        if (isset($formats[$candidate])) return $formats[$candidate];
    }
    if (str_contains($declared, 'webm')) return $formats['audio/webm'];
    if (str_contains($declared, 'ogg')) return $formats['audio/ogg'];
    if (str_contains($declared, 'mp4')) return $formats['audio/mp4'];
    throw new RuntimeException('Use WEBM, OGG, M4A/MP4, MP3 or WAV audio.');
}

function studio_voice_profile_store_sample(PDO $pdo, array $user, int $participantId, array $upload, int $durationMs, string $sourceType): array
{
    $profile = studio_participants_profile($pdo, $user, $participantId);
    if ((int)($profile['linked_user_id'] ?? 0) !== (int)$user['id'] || (string)($profile['relationship_scope'] ?? '') !== 'self') {
        throw new RuntimeException('Voice samples may only be saved to your own Voice Profile.');
    }
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE=>'The voice sample exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE=>'The voice sample exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL=>'The voice sample upload was incomplete.',
            UPLOAD_ERR_NO_FILE=>'Choose or record a voice sample first.',
        ];
        throw new RuntimeException($messages[$error] ?? 'The voice sample upload failed.');
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    $bytes = max(0, (int)($upload['size'] ?? 0));
    if ($tmp === '' || !is_file($tmp) || $bytes < 1) throw new RuntimeException('The voice sample is empty.');
    if ($bytes > STONEFELLOW_STUDIO_VOICE_SAMPLE_MAX_BYTES) throw new RuntimeException('Voice samples are limited to 25 MB.');
    $format = studio_voice_profile_format($upload);
    $sourceType = in_array($sourceType, ['recorded','upload'], true) ? $sourceType : 'upload';
    $durationMs = max(0, min(600000, $durationMs));

    $dir = studio_voice_profile_private_dir($user);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not create private voice storage.');
    @chmod($dir, 0700);
    $key = bin2hex(random_bytes(16));
    $fileName = $key . '.' . $format['ext'];
    $path = $dir . '/' . $fileName;
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $path) : rename($tmp, $path);
    if (!$moved || !is_file($path)) throw new RuntimeException('Could not save the private voice sample.');
    @chmod($path, 0600);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO studio_voice_samples
             (owner_user_id,participant_id,sample_key,file_name,mime_type,file_bytes,duration_ms,source_type)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([(int)$user['id'],$participantId,$key,$fileName,$format['mime'],$bytes,$durationMs,$sourceType]);
        return studio_voice_profile_sample($pdo, $user, (int)$pdo->lastInsertId());
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
}

function studio_voice_profile_delete_sample(PDO $pdo, array $user, int $sampleId): void
{
    $sample = studio_voice_profile_sample($pdo, $user, $sampleId);
    $path = studio_voice_profile_sample_path($user, $sample);
    $stmt = $pdo->prepare("UPDATE studio_voice_samples SET sample_status='deleted' WHERE id=? AND owner_user_id=?");
    $stmt->execute([$sampleId, (int)$user['id']]);
    if (is_file($path)) @unlink($path);
}
