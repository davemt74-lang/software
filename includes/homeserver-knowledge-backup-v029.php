<?php
declare(strict_types=1);

const VP3_HOMESERVER_KNOWLEDGE_BACKUP_V029 = 'homeserver-knowledge-backup-v029-20260908';
const VP3_HOMESERVER_KNOWLEDGE_BACKUP_FEATURE = 'knowledge.external_backup.v1';
const VP3_HOMESERVER_KNOWLEDGE_CHUNK_BYTES = 98304;

function homeserver_knowledge_v029_source_key(int $sessionId): string
{
    return 'vp3-transcript:session-' . max(1, $sessionId);
}

function homeserver_knowledge_v029_error_class(string $message): string
{
    $lower = mb_strtolower($message);
    if (str_contains($lower, 'knowledge.write') || str_contains($lower, 'permission') || str_contains($lower, '403')) return 'permission_required';
    if (str_contains($lower, '401') || str_contains($lower, 'bearer') || str_contains($lower, 'revoked') || str_contains($lower, 'authorization')) return 'authorization';
    if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) return 'timeout';
    if (str_contains($lower, 'relay') || str_contains($lower, 'offline') || str_contains($lower, 'connect') || str_contains($lower, 'curl')) return 'offline';
    if (str_contains($lower, 'scope') || str_contains($lower, 'kind')) return 'scope';
    return 'unavailable';
}

function homeserver_knowledge_v029_session_state(array $session): array
{
    $metadata = artist_listening_v197_metadata($session);
    $state = $metadata['homeserver_knowledge_backup_v029'] ?? [];
    return is_array($state) ? $state : [];
}

function homeserver_knowledge_v029_save_state(PDO $pdo, array $user, int $sessionId, array $state): array
{
    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        $metadata = artist_listening_v197_metadata($session);
        $state['version'] = 'v0.29';
        $state['source_key'] = homeserver_knowledge_v029_source_key($sessionId);
        $state['updated_at'] = gmdate('c');
        $metadata['homeserver_knowledge_backup_v029'] = $state;
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('HomeServer backup metadata could not be encoded.');
        $stmt = $pdo->prepare(
            'UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=?'
        );
        $stmt->execute([$json, $sessionId, (int)$user['id']]);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function homeserver_knowledge_v029_public(array $state, array $recordings = []): array
{
    $assets = is_array($state['assets'] ?? null) ? $state['assets'] : [];
    $total = count($recordings);
    $synced = 0;
    $bytesTotal = 0;
    $bytesSynced = 0;
    foreach ($recordings as $recording) {
        $key = (string)($recording['key'] ?? '');
        $size = max(0, (int)($recording['bytes'] ?? 0));
        $bytesTotal += $size;
        $asset = is_array($assets[$key] ?? null) ? $assets[$key] : [];
        if (($asset['state'] ?? '') === 'synced') {
            $synced++;
            $bytesSynced += $size;
        } else {
            $bytesSynced += min($size, max(0, (int)($asset['offset'] ?? 0)));
        }
    }
    return [
        'build'=>VP3_HOMESERVER_KNOWLEDGE_BACKUP_V029,
        'state'=>(string)($state['state'] ?? 'idle'),
        'source_key'=>(string)($state['source_key'] ?? ''),
        'mode'=>(string)($state['mode'] ?? ''),
        'text_synced'=>!empty($state['text_synced']),
        'recording_total'=>$total,
        'recording_synced'=>$synced,
        'bytes_total'=>$bytesTotal,
        'bytes_synced'=>$bytesSynced,
        'last_error'=>mb_strimwidth((string)($state['last_error'] ?? ''), 0, 500, '…'),
        'permission_required'=>(string)($state['state'] ?? '') === 'permission_required',
        'updated_at'=>(string)($state['updated_at'] ?? ''),
    ];
}

function homeserver_knowledge_v029_support(int $userId, bool $refresh=false): array
{
    $status = homeserver_vp3_status($userId, $refresh);
    $features = is_array($status['capabilities'] ?? null) ? $status['capabilities'] : [];
    return [
        'paired'=>!empty($status['paired']),
        'connected'=>!empty($status['connected']),
        'supported'=>in_array(VP3_HOMESERVER_KNOWLEDGE_BACKUP_FEATURE, $features, true),
        'state'=>(string)($status['state'] ?? 'unpaired'),
    ];
}

function homeserver_knowledge_v029_recordings(array $user, array $session): array
{
    $out = [];
    $dir = artist_listening_v197_private_dir($user, (int)$session['id']);
    foreach (artist_listening_v197_recordings($session) as $recording) {
        $fileName = basename((string)($recording['file_name'] ?? ''));
        $path = $dir . '/' . $fileName;
        if ($fileName === '' || !is_file($path) || !is_readable($path)) continue;
        $size = max(0, (int)filesize($path));
        if ($size < 1) continue;
        $recording['bytes'] = $size;
        $recording['_path'] = $path;
        $out[] = $recording;
    }
    return $out;
}

function homeserver_knowledge_v029_status(array $user, int $sessionId): array
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $state = homeserver_knowledge_v029_session_state($session);
    $recordings = homeserver_knowledge_v029_recordings($user, $session);
    return [
        'backup'=>homeserver_knowledge_v029_public($state, $recordings),
        'homeserver'=>homeserver_knowledge_v029_support((int)$user['id'], false),
    ];
}

function homeserver_knowledge_v029_mark_error(PDO $pdo, array $user, int $sessionId, array $state, Throwable $error): array
{
    $class = homeserver_knowledge_v029_error_class($error->getMessage());
    $state['state'] = $class === 'permission_required' ? 'permission_required' : 'pending';
    $state['last_error'] = match ($class) {
        'permission_required' => 'HomeServer needs the Knowledge write permission before VP3 can save this transcription.',
        'authorization' => 'HomeServer authorization expired. Re-pair HomeServer.',
        'timeout' => 'HomeServer did not respond in time. Backup can be retried.',
        'offline' => 'HomeServer is offline. Backup will remain pending.',
        'scope' => 'This transcription kind is outside the HomeServer scope granted to VP3.',
        default => 'HomeServer backup is temporarily unavailable.',
    };
    return homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
}

function homeserver_knowledge_v029_content(PDO $pdo, array $user, array $session, string $mode): array
{
    $mode = $mode === 'cloud' ? 'cloud' : 'direct';
    if ($mode === 'cloud') {
        $knowledgeId = max(0, (int)($session['knowledge_id'] ?? 0));
        if ($knowledgeId < 1) {
            throw new RuntimeException('Save this transcription to the cloud Knowledge Base first.');
        }
        $stmt = $pdo->prepare('SELECT id,title,content_text FROM knowledge_items WHERE id=? LIMIT 1');
        $stmt->execute([$knowledgeId]);
        $item = $stmt->fetch();
        if (!$item) throw new RuntimeException('The cloud Knowledge item could not be found.');
        $text = trim((string)($item['content_text'] ?? ''));
        if ($text === '') throw new RuntimeException('The cloud Knowledge item is empty.');
        return [
            'title'=>(string)($item['title'] ?? $session['title'] ?? 'Transcription'),
            'content'=>$text,
            'cloud_knowledge_id'=>$knowledgeId,
            'source'=>'vp3-cloud-knowledge',
        ];
    }
    return [
        'title'=>(string)($session['title'] ?? 'Transcription'),
        'content'=>artist_listening_v172_promotable_text($pdo, (int)$session['id'], ''),
        'cloud_knowledge_id'=>max(0, (int)($session['knowledge_id'] ?? 0)),
        'source'=>'vp3-transcription',
    ];
}

function homeserver_knowledge_v029_prepare(array $user, int $sessionId, string $mode='direct'): array
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    if ((string)($session['status'] ?? '') === 'active') {
        throw new RuntimeException('Stop listening before saving a transcription to HomeServer Knowledge.');
    }
    $state = homeserver_knowledge_v029_session_state($session);
    $state['mode'] = $mode === 'cloud' ? 'cloud' : 'direct';
    $state['source_key'] = homeserver_knowledge_v029_source_key($sessionId);
    $support = homeserver_knowledge_v029_support((int)$user['id'], false);
    if (!$support['paired']) {
        $state['state'] = 'pending';
        $state['last_error'] = 'Pair HomeServer before saving this transcription locally.';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
        return homeserver_knowledge_v029_status($user, $sessionId);
    }
    if (!$support['connected']) {
        $state['state'] = 'pending';
        $state['last_error'] = 'HomeServer is offline. Backup will remain pending.';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
        return homeserver_knowledge_v029_status($user, $sessionId);
    }
    if (!$support['supported']) {
        $state['state'] = 'unsupported';
        $state['last_error'] = 'Update HomeServer to a version that supports Knowledge and recording backup.';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
        return homeserver_knowledge_v029_status($user, $sessionId);
    }
    $credentials = homeserver_approvals_v028_credentials((int)$user['id']);
    if (!$credentials) throw new RuntimeException('HomeServer authorization is unavailable.');
    $content = homeserver_knowledge_v029_content($pdo, $user, $session, $state['mode']);
    $recordings = homeserver_knowledge_v029_recordings($user, $session);
    try {
        $result = homeserver_vp3_remote_operation($credentials['relay'], 'knowledge.upsert', [
            'source_key'=>$state['source_key'],
            'title'=>mb_strimwidth((string)$content['title'], 0, 240, '…'),
            'kind'=>'transcription',
            'content'=>(string)$content['content'],
            'metadata'=>[
                'cloud_knowledge_id'=>(int)$content['cloud_knowledge_id'],
                'session_id'=>$sessionId,
                'track_id'=>max(0, (int)($session['project_track_id'] ?? 0)),
                'duration_ms'=>max(0, (int)($session['duration_ms'] ?? 0)),
                'started_at'=>(string)($session['started_at'] ?? ''),
                'recording_count'=>count($recordings),
                'source'=>(string)$content['source'],
            ],
        ], $credentials['home']);
        $state['item_id'] = max(0, (int)($result['id'] ?? 0));
        $state['text_synced'] = true;
        $state['content_hash'] = (string)($result['content_hash'] ?? hash('sha256', (string)$content['content']));
        $state['last_error'] = '';
        $state['state'] = $recordings ? 'syncing' : 'synced';
        $state['assets'] = is_array($state['assets'] ?? null) ? $state['assets'] : [];
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
    } catch (Throwable $e) {
        homeserver_knowledge_v029_mark_error($pdo, $user, $sessionId, $state, $e);
    }
    return homeserver_knowledge_v029_status($user, $sessionId);
}

function homeserver_knowledge_v029_sync_step(array $user, int $sessionId): array
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $state = homeserver_knowledge_v029_session_state($session);
    if (empty($state['text_synced'])) {
        return homeserver_knowledge_v029_prepare($user, $sessionId, (string)($state['mode'] ?? 'direct'));
    }
    $support = homeserver_knowledge_v029_support((int)$user['id'], false);
    if (!$support['connected'] || !$support['supported']) {
        $state['state'] = !$support['supported'] ? 'unsupported' : 'pending';
        $state['last_error'] = !$support['supported']
            ? 'Update HomeServer to resume this backup.'
            : 'HomeServer is offline. Backup will remain pending.';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
        return homeserver_knowledge_v029_status($user, $sessionId);
    }
    $credentials = homeserver_approvals_v028_credentials((int)$user['id']);
    if (!$credentials) throw new RuntimeException('HomeServer authorization is unavailable.');
    $recordings = homeserver_knowledge_v029_recordings($user, $session);
    $state['assets'] = is_array($state['assets'] ?? null) ? $state['assets'] : [];

    foreach ($recordings as $recording) {
        $key = (string)$recording['key'];
        $path = (string)$recording['_path'];
        $size = max(0, (int)$recording['bytes']);
        $hash = (string)($state['assets'][$key]['sha256'] ?? '');
        if ($hash === '' || (int)($state['assets'][$key]['size_bytes'] ?? 0) !== $size) {
            $hash = hash_file('sha256', $path) ?: '';
            if ($hash === '') throw new RuntimeException('Could not hash a retained recording for HomeServer backup.');
            $state['assets'][$key] = [
                'state'=>'pending','offset'=>0,'upload_id'=>'','sha256'=>$hash,'size_bytes'=>$size,
                'mime_type'=>(string)$recording['mime_type'],'file_name'=>basename((string)$recording['file_name']),
            ];
        }
        $asset = $state['assets'][$key];
        if (($asset['state'] ?? '') === 'synced') continue;
        try {
            if (empty($asset['upload_id'])) {
                $begin = homeserver_vp3_remote_operation($credentials['relay'], 'knowledge.asset.begin', [
                    'source_key'=>$state['source_key'],
                    'asset_key'=>'recording:' . $key,
                    'original_name'=>basename((string)$recording['file_name']),
                    'media_type'=>(string)$recording['mime_type'],
                    'size_bytes'=>$size,
                    'sha256'=>$hash,
                ], $credentials['home']);
                if (!empty($begin['already_present'])) {
                    $asset['state'] = 'synced';
                    $asset['offset'] = $size;
                    $asset['upload_id'] = '';
                    $state['assets'][$key] = $asset;
                    homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
                    continue;
                }
                $asset['upload_id'] = (string)($begin['upload_id'] ?? '');
                $asset['offset'] = max(0, (int)($begin['received_bytes'] ?? 0));
                if ($asset['upload_id'] === '') throw new RuntimeException('HomeServer did not create a recording upload.');
            }
            $offset = max(0, (int)($asset['offset'] ?? 0));
            if ($offset < $size) {
                $handle = fopen($path, 'rb');
                if (!$handle) throw new RuntimeException('The retained recording could not be opened for backup.');
                try {
                    if ($offset > 0 && fseek($handle, $offset) !== 0) throw new RuntimeException('The retained recording could not resume at its saved position.');
                    $chunk = fread($handle, min(VP3_HOMESERVER_KNOWLEDGE_CHUNK_BYTES, $size - $offset));
                } finally {
                    fclose($handle);
                }
                if ($chunk === false || $chunk === '') throw new RuntimeException('The retained recording could not be read for backup.');
                $sent = homeserver_vp3_remote_operation($credentials['relay'], 'knowledge.asset.chunk', [
                    'upload_id'=>$asset['upload_id'],
                    'offset'=>$offset,
                    'data_base64'=>base64_encode($chunk),
                ], $credentials['home']);
                $asset['offset'] = max(0, (int)($sent['received_bytes'] ?? $offset));
            }
            if ((int)$asset['offset'] >= $size) {
                homeserver_vp3_remote_operation($credentials['relay'], 'knowledge.asset.commit', [
                    'upload_id'=>$asset['upload_id'],
                ], $credentials['home']);
                $asset['state'] = 'synced';
                $asset['offset'] = $size;
                $asset['upload_id'] = '';
            } else {
                $asset['state'] = 'syncing';
            }
            $asset['last_error'] = '';
            $state['assets'][$key] = $asset;
            $state['state'] = 'syncing';
            $state['last_error'] = '';
            homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
            break;
        } catch (Throwable $e) {
            $asset['last_error'] = mb_strimwidth($e->getMessage(), 0, 500, '…');
            $state['assets'][$key] = $asset;
            homeserver_knowledge_v029_mark_error($pdo, $user, $sessionId, $state, $e);
            return homeserver_knowledge_v029_status($user, $sessionId);
        }
    }

    $fresh = artist_listening_v172_session($pdo, $user, $sessionId);
    $state = homeserver_knowledge_v029_session_state($fresh);
    $recordings = homeserver_knowledge_v029_recordings($user, $fresh);
    $allSynced = !empty($state['text_synced']);
    foreach ($recordings as $recording) {
        $asset = $state['assets'][(string)$recording['key']] ?? [];
        if (!is_array($asset) || ($asset['state'] ?? '') !== 'synced') {
            $allSynced = false;
            break;
        }
    }
    if ($allSynced && ($state['state'] ?? '') !== 'synced') {
        $state['state'] = 'synced';
        $state['last_error'] = '';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
    }
    return homeserver_knowledge_v029_status($user, $sessionId);
}

function homeserver_knowledge_v029_sync(array $user, int $sessionId, int $steps=4): array
{
    $steps = max(1, min(8, $steps));
    $result = homeserver_knowledge_v029_status($user, $sessionId);
    for ($i = 0; $i < $steps; $i++) {
        $state = (string)($result['backup']['state'] ?? 'idle');
        if (in_array($state, ['synced','unsupported','permission_required'], true)) break;
        $result = homeserver_knowledge_v029_sync_step($user, $sessionId);
    }
    return $result;
}
