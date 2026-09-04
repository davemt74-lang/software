<?php
declare(strict_types=1);

const STONEFELLOW_STUDIO_PARTICIPANTS = 'studio-participants-20260903';
const STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD = 0.82;

function studio_participants_schema_ready(): bool
{
    return table_exists('studio_participants')
        && table_exists('studio_participant_voices')
        && table_exists('studio_session_participants')
        && column_exists('studio_participant_voices', 'recognition_verified')
        && column_exists('studio_participant_voices', 'clone_verified');
}

function studio_participants_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS studio_participants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            linked_user_id INT UNSIGNED NULL,
            profile_key CHAR(32) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            relationship_scope VARCHAR(30) NOT NULL DEFAULT 'guest',
            recognition_scope VARCHAR(30) NOT NULL DEFAULT 'private',
            recognition_consent TINYINT(1) NOT NULL DEFAULT 0,
            cloning_consent TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            consent_updated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_studio_participant_profile (owner_user_id, profile_key),
            UNIQUE KEY uq_studio_participant_link (owner_user_id, linked_user_id),
            INDEX idx_studio_participant_active (owner_user_id, is_active, updated_at),
            CONSTRAINT fk_studio_participant_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_studio_participant_linked FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS studio_participant_voices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'elevenlabs',
            recognition_provider_speaker_id VARCHAR(190) NOT NULL DEFAULT '',
            clone_provider_voice_id VARCHAR(190) NOT NULL DEFAULT '',
            source_session_id BIGINT UNSIGNED NULL,
            source_recording_key VARCHAR(64) NOT NULL DEFAULT '',
            recognition_enabled TINYINT(1) NOT NULL DEFAULT 0,
            clone_enabled TINYINT(1) NOT NULL DEFAULT 0,
            recognition_verified TINYINT(1) NOT NULL DEFAULT 0,
            clone_verified TINYINT(1) NOT NULL DEFAULT 0,
            consent_snapshot_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_studio_participant_voice (participant_id, provider),
            INDEX idx_studio_voice_recognition (owner_user_id, provider, recognition_provider_speaker_id),
            INDEX idx_studio_voice_clone (owner_user_id, provider, clone_provider_voice_id),
            CONSTRAINT fk_studio_voice_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_studio_voice_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!column_exists('studio_participant_voices', 'recognition_verified')) {
        $pdo->exec("ALTER TABLE studio_participant_voices ADD COLUMN recognition_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER clone_enabled");
    }
    if (!column_exists('studio_participant_voices', 'clone_verified')) {
        $pdo->exec("ALTER TABLE studio_participant_voices ADD COLUMN clone_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER recognition_verified");
    }
    if (column_exists('studio_participant_voices', 'provider_verified')) {
        $pdo->exec('UPDATE studio_participant_voices SET recognition_verified=GREATEST(recognition_verified,provider_verified),clone_verified=GREATEST(clone_verified,provider_verified) WHERE provider_verified=1');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS studio_session_participants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NULL,
            transcript_session_id BIGINT UNSIGNED NULL,
            participant_id BIGINT UNSIGNED NULL,
            speaker_label VARCHAR(80) NOT NULL DEFAULT '',
            recognition_method VARCHAR(40) NOT NULL DEFAULT 'unknown',
            recognition_confidence DECIMAL(5,4) NULL,
            provider VARCHAR(30) NOT NULL DEFAULT '',
            provider_speaker_id VARCHAR(190) NOT NULL DEFAULT '',
            presence_state VARCHAR(20) NOT NULL DEFAULT 'present',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_studio_session_conversation (owner_user_id, conversation_id, last_seen_at),
            INDEX idx_studio_session_transcript (owner_user_id, transcript_session_id, last_seen_at),
            INDEX idx_studio_session_participant (owner_user_id, participant_id, last_seen_at),
            CONSTRAINT fk_studio_session_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_studio_session_participant FOREIGN KEY (participant_id) REFERENCES studio_participants(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function studio_participants_clean_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') throw new RuntimeException('Participant name is required.');
    return mb_strimwidth($name, 0, 120, '');
}

function studio_participants_profile_key(string $key = ''): string
{
    $key = strtolower(trim($key));
    if ($key !== '' && preg_match('/^[a-z0-9-]{12,32}$/', $key)) return $key;
    return bin2hex(random_bytes(16));
}

function studio_participants_relationship(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['self','contact','collaborator','guest'], true) ? $value : 'guest';
}

function studio_participants_recognition_scope(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['private','contacts','collaborators'], true) ? $value : 'private';
}

function studio_participants_profile(PDO $pdo, array $user, int $participantId): array
{
    if ($participantId < 1) throw new RuntimeException('Choose a participant.');
    $stmt = $pdo->prepare('SELECT * FROM studio_participants WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$participantId, (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Participant not found.');
    return $row;
}

function studio_participants_ensure_self(PDO $pdo, array $user): array
{
    $userId = max(0, (int)($user['id'] ?? 0));
    if ($userId < 1) throw new RuntimeException('Sign in to create a voice profile.');
    $stmt = $pdo->prepare('SELECT * FROM studio_participants WHERE owner_user_id=? AND linked_user_id=? AND relationship_scope=\'self\' LIMIT 1');
    $stmt->execute([$userId, $userId]);
    $row = $stmt->fetch();
    if ($row) return $row;
    $name = studio_participants_clean_name((string)($user['display_name'] ?? 'User'));
    $key = studio_participants_profile_key();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO studio_participants (owner_user_id,linked_user_id,profile_key,display_name,relationship_scope,recognition_scope)
             VALUES (?,?,?,?,'self','private')"
        );
        $stmt->execute([$userId, $userId, $key, $name]);
        return studio_participants_profile($pdo, $user, (int)$pdo->lastInsertId());
    } catch (PDOException $e) {
        $stmt = $pdo->prepare('SELECT * FROM studio_participants WHERE owner_user_id=? AND linked_user_id=? LIMIT 1');
        $stmt->execute([$userId, $userId]);
        $row = $stmt->fetch();
        if ($row) return $row;
        throw $e;
    }
}

function studio_participants_list(PDO $pdo, array $user): array
{
    studio_participants_ensure_self($pdo, $user);
    $stmt = $pdo->prepare(
        "SELECT p.*,
                v.provider,
                v.recognition_enabled,
                v.clone_enabled,
                v.recognition_verified,
                v.clone_verified,
                CASE WHEN v.recognition_provider_speaker_id<>'' THEN 1 ELSE 0 END AS has_recognition_binding,
                CASE WHEN v.clone_provider_voice_id<>'' THEN 1 ELSE 0 END AS has_clone_binding
         FROM studio_participants p
         LEFT JOIN studio_participant_voices v ON v.participant_id=p.id AND v.provider='elevenlabs'
         WHERE p.owner_user_id=? AND p.is_active=1
         ORDER BY (p.relationship_scope='self') DESC,p.display_name ASC,p.id ASC"
    );
    $stmt->execute([(int)$user['id']]);
    return array_map(static function(array $row): array {
        $recognitionEnabled = !empty($row['recognition_enabled']);
        $cloneEnabled = !empty($row['clone_enabled']);
        $recognitionVerified = !empty($row['recognition_verified']);
        $cloneVerified = !empty($row['clone_verified']);
        return [
            'id'=>(int)$row['id'],
            'profile_key'=>(string)$row['profile_key'],
            'linked_user_id'=>max(0,(int)($row['linked_user_id'] ?? 0)),
            'display_name'=>(string)$row['display_name'],
            'relationship'=>(string)$row['relationship_scope'],
            'recognition_scope'=>(string)$row['recognition_scope'],
            'recognition_consent'=>!empty($row['recognition_consent']),
            'cloning_consent'=>!empty($row['cloning_consent']),
            'voice'=>[
                'provider'=>(string)($row['provider'] ?? 'elevenlabs'),
                'recognition_enabled'=>$recognitionEnabled,
                'clone_enabled'=>$cloneEnabled,
                'recognition_verified'=>$recognitionVerified,
                'clone_verified'=>$cloneVerified,
                'verified'=>(!$recognitionEnabled || $recognitionVerified) && (!$cloneEnabled || $cloneVerified),
                'has_recognition_binding'=>!empty($row['has_recognition_binding']),
                'has_clone_binding'=>!empty($row['has_clone_binding']),
            ],
        ];
    }, $stmt->fetchAll() ?: []);
}

function studio_participants_save_profile(PDO $pdo, array $user, array $input): array
{
    $id = max(0, (int)($input['participant_id'] ?? 0));
    $linkedUserId = max(0, (int)($input['linked_user_id'] ?? 0));
    $name = studio_participants_clean_name((string)($input['display_name'] ?? ''));
    $relationship = studio_participants_relationship((string)($input['relationship'] ?? 'guest'));
    if ($relationship === 'self') {
        $linkedUserId = (int)$user['id'];
    } elseif ($linkedUserId > 0) {
        $exists = $pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1 LIMIT 1');
        $exists->execute([$linkedUserId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('Linked Stonefellow account not found.');
    }
    if ($id > 0) {
        $profile = studio_participants_profile($pdo, $user, $id);
        if ((string)$profile['relationship_scope'] === 'self') {
            $relationship = 'self';
            $linkedUserId = (int)$user['id'];
        }
        $stmt = $pdo->prepare('UPDATE studio_participants SET linked_user_id=?,display_name=?,relationship_scope=? WHERE id=? AND owner_user_id=?');
        $stmt->execute([$linkedUserId ?: null, $name, $relationship, $id, (int)$user['id']]);
        return studio_participants_profile($pdo, $user, $id);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO studio_participants (owner_user_id,linked_user_id,profile_key,display_name,relationship_scope) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([(int)$user['id'], $linkedUserId ?: null, studio_participants_profile_key(), $name, $relationship]);
    return studio_participants_profile($pdo, $user, (int)$pdo->lastInsertId());
}

function studio_participants_set_consent(PDO $pdo, array $user, array $input): array
{
    $participantId = max(0, (int)($input['participant_id'] ?? 0));
    studio_participants_profile($pdo, $user, $participantId);
    $recognition = !empty($input['recognition_consent']);
    $cloning = !empty($input['cloning_consent']);
    $scope = studio_participants_recognition_scope((string)($input['recognition_scope'] ?? 'private'));
    $stmt = $pdo->prepare(
        'UPDATE studio_participants SET recognition_consent=?,cloning_consent=?,recognition_scope=?,consent_updated_at=NOW() WHERE id=? AND owner_user_id=?'
    );
    $stmt->execute([$recognition ? 1 : 0, $cloning ? 1 : 0, $scope, $participantId, (int)$user['id']]);
    if (!$recognition || !$cloning) {
        $sets = [];
        if (!$recognition) { $sets[] = 'recognition_enabled=0'; $sets[] = "recognition_provider_speaker_id=''"; $sets[] = 'recognition_verified=0'; }
        if (!$cloning) { $sets[] = 'clone_enabled=0'; $sets[] = "clone_provider_voice_id=''"; $sets[] = 'clone_verified=0'; }
        if ($sets) {
            $sets[] = (!$recognition && !$cloning) ? 'revoked_at=NOW()' : 'revoked_at=NULL';
            $pdo->prepare('UPDATE studio_participant_voices SET '.implode(',', $sets).' WHERE participant_id=? AND owner_user_id=?')
                ->execute([$participantId, (int)$user['id']]);
        }
    }
    return studio_participants_profile($pdo, $user, $participantId);
}

function studio_participants_bind_voice(PDO $pdo, array $user, int $participantId, array $binding): array
{
    $profile = studio_participants_profile($pdo, $user, $participantId);
    $provider = strtolower(trim((string)($binding['provider'] ?? 'elevenlabs')));
    if ($provider !== 'elevenlabs') throw new RuntimeException('Unsupported voice provider.');

    $stmt = $pdo->prepare('SELECT * FROM studio_participant_voices WHERE participant_id=? AND owner_user_id=? AND provider=? LIMIT 1');
    $stmt->execute([$participantId, (int)$user['id'], $provider]);
    $existing = $stmt->fetch() ?: [];

    $recognitionId = array_key_exists('recognition_provider_speaker_id', $binding)
        ? trim((string)$binding['recognition_provider_speaker_id'])
        : trim((string)($existing['recognition_provider_speaker_id'] ?? ''));
    $cloneId = array_key_exists('clone_provider_voice_id', $binding)
        ? trim((string)$binding['clone_provider_voice_id'])
        : trim((string)($existing['clone_provider_voice_id'] ?? ''));
    $sourceSessionId = array_key_exists('source_session_id', $binding)
        ? max(0, (int)$binding['source_session_id'])
        : max(0, (int)($existing['source_session_id'] ?? 0));
    $sourceRecordingKey = array_key_exists('source_recording_key', $binding)
        ? trim((string)$binding['source_recording_key'])
        : trim((string)($existing['source_recording_key'] ?? ''));
    $recognitionVerified = array_key_exists('recognition_verified', $binding)
        ? !empty($binding['recognition_verified'])
        : !empty($existing['recognition_verified']);
    $cloneVerified = array_key_exists('clone_verified', $binding)
        ? !empty($binding['clone_verified'])
        : !empty($existing['clone_verified']);

    if ($recognitionId !== '' && empty($profile['recognition_consent'])) throw new RuntimeException('Voice recognition consent is required first.');
    if ($cloneId !== '' && empty($profile['cloning_consent'])) throw new RuntimeException('Voice cloning consent is required first.');

    $stmt = $pdo->prepare(
        "INSERT INTO studio_participant_voices
         (owner_user_id,participant_id,provider,recognition_provider_speaker_id,clone_provider_voice_id,source_session_id,source_recording_key,recognition_enabled,clone_enabled,recognition_verified,clone_verified,consent_snapshot_at,revoked_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NULL)
         ON DUPLICATE KEY UPDATE
           recognition_provider_speaker_id=VALUES(recognition_provider_speaker_id),
           clone_provider_voice_id=VALUES(clone_provider_voice_id),
           source_session_id=VALUES(source_session_id),
           source_recording_key=VALUES(source_recording_key),
           recognition_enabled=VALUES(recognition_enabled),
           clone_enabled=VALUES(clone_enabled),
           recognition_verified=VALUES(recognition_verified),
           clone_verified=VALUES(clone_verified),
           consent_snapshot_at=NOW(),revoked_at=NULL"
    );
    $stmt->execute([
        (int)$user['id'],$participantId,$provider,$recognitionId,$cloneId,
        $sourceSessionId ?: null,$sourceRecordingKey,
        $recognitionId !== '' ? 1 : 0,$cloneId !== '' ? 1 : 0,$recognitionVerified ? 1 : 0,$cloneVerified ? 1 : 0,
    ]);
    return studio_participants_voice($pdo, $user, $participantId);
}

function studio_participants_voice(PDO $pdo, array $user, int $participantId): array
{
    studio_participants_profile($pdo, $user, $participantId);
    $stmt = $pdo->prepare('SELECT * FROM studio_participant_voices WHERE participant_id=? AND owner_user_id=? AND provider=\'elevenlabs\' LIMIT 1');
    $stmt->execute([$participantId, (int)$user['id']]);
    $row = $stmt->fetch() ?: [];
    $recognitionEnabled = !empty($row['recognition_enabled']);
    $cloneEnabled = !empty($row['clone_enabled']);
    $recognitionVerified = !empty($row['recognition_verified']);
    $cloneVerified = !empty($row['clone_verified']);
    return [
        'provider'=>'elevenlabs',
        'recognition_enabled'=>$recognitionEnabled,
        'clone_enabled'=>$cloneEnabled,
        'recognition_verified'=>$recognitionVerified,
        'clone_verified'=>$cloneVerified,
        'verified'=>(!$recognitionEnabled || $recognitionVerified) && (!$cloneEnabled || $cloneVerified),
        'has_recognition_binding'=>trim((string)($row['recognition_provider_speaker_id'] ?? '')) !== '',
        'has_clone_binding'=>trim((string)($row['clone_provider_voice_id'] ?? '')) !== '',
        'source_session_id'=>max(0,(int)($row['source_session_id'] ?? 0)),
        'source_recording_key'=>(string)($row['source_recording_key'] ?? ''),
    ];
}

function studio_participants_recognition_match(PDO $pdo, array $user, string $providerSpeakerId): ?array
{
    $providerSpeakerId = trim($providerSpeakerId);
    if ($providerSpeakerId === '') return null;
    $stmt = $pdo->prepare(
        "SELECT p.* FROM studio_participant_voices v
         JOIN studio_participants p ON p.id=v.participant_id
         WHERE v.owner_user_id=? AND v.provider='elevenlabs' AND v.recognition_enabled=1 AND v.recognition_verified=1
           AND v.recognition_provider_speaker_id=? AND p.recognition_consent=1 AND p.is_active=1
         LIMIT 1"
    );
    $stmt->execute([(int)$user['id'], $providerSpeakerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function studio_participants_record_presence(PDO $pdo, array $user, array $input): array
{
    $conversationId = max(0, (int)($input['conversation_id'] ?? 0));
    $transcriptSessionId = max(0, (int)($input['transcript_session_id'] ?? 0));
    if ($conversationId < 1 && $transcriptSessionId < 1) {
        throw new RuntimeException('Participant presence requires an active conversation or transcription session.');
    }

    $method = strtolower(trim((string)($input['recognition_method'] ?? 'manual')));
    if (!in_array($method, ['manual','voice','account','unknown'], true)) $method = 'unknown';
    $confidence = max(0.0, min(1.0, (float)($input['confidence'] ?? 0)));
    $providerSpeakerId = trim((string)($input['provider_speaker_id'] ?? ''));
    $participantId = max(0, (int)($input['participant_id'] ?? 0));

    if ($method === 'voice') {
        $match = studio_participants_recognition_match($pdo, $user, $providerSpeakerId);
        if (!$match || $confidence < STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD) {
            $participantId = 0;
            $method = 'unknown';
        } else {
            $participantId = (int)$match['id'];
        }
    } elseif ($participantId > 0) {
        studio_participants_profile($pdo, $user, $participantId);
    }

    $speakerLabel = mb_strimwidth(trim((string)($input['speaker_label'] ?? '')), 0, 80, '');
    if ($speakerLabel === '' && $participantId > 0) $speakerLabel = 'Participant ' . $participantId;
    if ($speakerLabel === '' && $providerSpeakerId !== '') $speakerLabel = 'Voice ' . substr(hash('sha256', $providerSpeakerId), 0, 12);
    if ($speakerLabel === '') throw new RuntimeException('A speaker label is required for an unidentified participant.');

    $stmt = $pdo->prepare(
        'SELECT id FROM studio_session_participants WHERE owner_user_id=? AND conversation_id<=>? AND transcript_session_id<=>? AND speaker_label=? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$user['id'], $conversationId ?: null, $transcriptSessionId ?: null, $speakerLabel]);
    $existing = (int)$stmt->fetchColumn();

    if ($existing > 0) {
        $stmt = $pdo->prepare(
            "UPDATE studio_session_participants SET participant_id=?,recognition_method=?,recognition_confidence=?,provider=?,provider_speaker_id=?,presence_state='present',last_seen_at=NOW() WHERE id=? AND owner_user_id=?"
        );
        $stmt->execute([$participantId ?: null,$method,$confidence ?: null,$method==='voice'?'elevenlabs':'',$providerSpeakerId,$existing,(int)$user['id']]);
        $id = $existing;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO studio_session_participants (owner_user_id,conversation_id,transcript_session_id,participant_id,speaker_label,recognition_method,recognition_confidence,provider,provider_speaker_id) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([(int)$user['id'],$conversationId ?: null,$transcriptSessionId ?: null,$participantId ?: null,$speakerLabel,$method,$confidence ?: null,$method==='voice'?'elevenlabs':'',$providerSpeakerId]);
        $id = (int)$pdo->lastInsertId();
    }
    return ['id'=>$id,'participant_id'=>$participantId,'recognized'=>$participantId>0,'method'=>$method,'confidence'=>$confidence,'speaker_label'=>$speakerLabel];
}

function studio_participants_context(PDO $pdo, array $user, int $conversationId = 0, int $transcriptSessionId = 0): array
{
    if ($conversationId < 1 && $transcriptSessionId < 1) {
        return ['build'=>STONEFELLOW_STUDIO_PARTICIPANTS,'count'=>0,'participants'=>[]];
    }
    $where = ['sp.owner_user_id=?', "sp.presence_state='present'", 'sp.last_seen_at>=DATE_SUB(NOW(), INTERVAL 8 HOUR)'];
    $params = [(int)$user['id']];
    if ($conversationId > 0) { $where[] = 'sp.conversation_id=?'; $params[] = $conversationId; }
    if ($transcriptSessionId > 0) { $where[] = 'sp.transcript_session_id=?'; $params[] = $transcriptSessionId; }
    $stmt = $pdo->prepare(
        "SELECT sp.id,sp.speaker_label,sp.recognition_method,sp.recognition_confidence,sp.last_seen_at,
                p.id participant_id,p.display_name,p.relationship_scope,p.linked_user_id,p.recognition_consent
         FROM studio_session_participants sp
         LEFT JOIN studio_participants p ON p.id=sp.participant_id AND p.owner_user_id=sp.owner_user_id
         WHERE ".implode(' AND ', $where)."
         ORDER BY sp.last_seen_at DESC,sp.id DESC LIMIT 12"
    );
    $stmt->execute($params);
    $rows = [];
    $seen = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $key = (string)($row['participant_id'] ?: ('speaker:'.$row['speaker_label']));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $identified = (int)($row['participant_id'] ?? 0) > 0;
        $voiceIdentified = (string)($row['recognition_method'] ?? '') === 'voice';
        $recognized = $identified && (!$voiceIdentified || !empty($row['recognition_consent']));
        $rows[] = [
            'participant_id'=>$recognized ? max(0,(int)($row['participant_id'] ?? 0)) : 0,
            'name'=>$recognized ? (string)$row['display_name'] : '',
            'speaker_label'=>(string)$row['speaker_label'],
            'relationship'=>$recognized ? (string)$row['relationship_scope'] : 'unknown',
            'recognized'=>$recognized,
            'method'=>(string)$row['recognition_method'],
            'confidence'=>round(max(0,min(1,(float)($row['recognition_confidence'] ?? 0))),4),
            'linked_user_id'=>$recognized ? max(0,(int)($row['linked_user_id'] ?? 0)) : 0,
            'last_seen_at'=>(string)$row['last_seen_at'],
        ];
    }
    return ['build'=>STONEFELLOW_STUDIO_PARTICIPANTS,'count'=>count($rows),'participants'=>$rows];
}
