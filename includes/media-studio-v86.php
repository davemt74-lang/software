<?php
declare(strict_types=1);

function media_studio_schema_ready(): bool
{
    return table_exists('user_media_assets')
        && table_exists('video_editor_projects');
}

function media_studio_private_root(): string
{
    return STONEFELLOW_ROOT . '/private/user-media';
}

function media_studio_upload_root(): string
{
    return STONEFELLOW_ROOT . '/private/media-upload-v86';
}

function media_studio_user_root(int $userId): string
{
    return media_studio_private_root() . '/' . max(0, $userId);
}

function media_studio_ensure_user_root(int $userId): string
{
    if ($userId < 1) {
        throw new RuntimeException('A signed-in user is required.');
    }

    $root = media_studio_user_root($userId);
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create the private media library.');
    }
    @chmod($root, 0700);
    return $root;
}

function media_studio_normalize_mime(string $mime): string
{
    $mime = mb_strtolower(trim(explode(';', $mime, 2)[0] ?? ''));
    return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) ? $mime : '';
}

function media_studio_type_for_mime(string $mime): string
{
    $mime = media_studio_normalize_mime($mime);
    if (str_starts_with($mime, 'image/')) {
        return 'photo';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    return '';
}

function media_studio_extension_for_mime(string $mime): string
{
    return match (media_studio_normalize_mime($mime)) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/webm' => 'webm',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4', 'audio/x-m4a' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav', 'audio/x-wav' => 'wav',
        'audio/flac' => 'flac',
        default => 'bin',
    };
}

function media_studio_mime_allowed(string $type, string $mime): bool
{
    $type = strtolower(trim($type));
    $mime = media_studio_normalize_mime($mime);

    $allowed = [
        'photo' => ['image/jpeg','image/png','image/webp'],
        'video' => ['video/webm','video/mp4','video/quicktime'],
        'audio' => ['audio/webm','audio/ogg','audio/mp4','audio/x-m4a','audio/mpeg','audio/wav','audio/x-wav','audio/flac'],
    ];

    return isset($allowed[$type]) && in_array($mime, $allowed[$type], true);
}

function media_studio_max_bytes(string $type): int
{
    global $config;
    return match ($type) {
        'photo' => (int)($config['uploads']['max_user_photo_bytes'] ?? 25 * 1024 * 1024),
        'audio' => (int)($config['uploads']['max_user_recording_bytes'] ?? 512 * 1024 * 1024),
        'video' => (int)($config['uploads']['max_user_video_bytes'] ?? 4 * 1024 * 1024 * 1024),
        default => 0,
    };
}

function media_studio_asset_url(int $assetId): string
{
    return url('/media-library-file-v86.php?id=' . max(0, $assetId));
}

function media_studio_asset_payload(array $row): array
{
    $id = (int)($row['id'] ?? 0);
    return [
        'id' => $id,
        'media_type' => (string)($row['media_type'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'mime_type' => (string)($row['mime_type'] ?? ''),
        'file_size' => (int)($row['file_size'] ?? 0),
        'duration_seconds' => (float)($row['duration_seconds'] ?? 0),
        'source' => (string)($row['source'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'url' => media_studio_asset_url($id),
        'editor_url' => url('/video-editor.php?asset=' . $id),
        'available' => (static function(array $r): bool { $uid=(int)($r['user_id']??0);$root=$uid>0?realpath(media_studio_user_root($uid)):false;$path=realpath(STONEFELLOW_ROOT.'/'.ltrim((string)($r['file_path']??''),'/'));return is_string($root)&&is_string($path)&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path)&&filesize($path)>0; })($row),
    ];
}

function media_studio_assets(array $user, int $limit = 200): array
{
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1 || !table_exists('user_media_assets')) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare(
        'SELECT id,user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at
         FROM user_media_assets
         WHERE user_id=?
         ORDER BY created_at DESC,id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return array_map('media_studio_asset_payload', $stmt->fetchAll());
}

function media_studio_assets_by_ids(array $assetIds,array $user): array
{
    $pdo=db();$uid=(int)($user['id']??0);$ids=array_values(array_unique(array_filter(array_map('intval',$assetIds),static fn(int $id):bool=>$id>0)));if(!$pdo||$uid<1||!$ids||!table_exists('user_media_assets'))return [];$ids=array_slice($ids,0,500);$ph=implode(',',array_fill(0,count($ids),'?'));$stmt=$pdo->prepare('SELECT id,user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at FROM user_media_assets WHERE user_id=? AND id IN ('.$ph.')');$stmt->execute(array_merge([$uid],$ids));return array_map('media_studio_asset_payload',$stmt->fetchAll());
}

function media_studio_asset(int $assetId, array $user): ?array
{
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $assetId < 1 || $userId < 1 || !table_exists('user_media_assets')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id,user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at
         FROM user_media_assets
         WHERE id=? AND user_id=?
         LIMIT 1'
    );
    $stmt->execute([$assetId,$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function media_studio_create_asset(
    array $user,
    string $type,
    string $mime,
    string $relativePath,
    int $size,
    string $title,
    string $source = 'upload',
    float $durationSeconds = 0.0,
    array $metadata = []
): int {
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    $type = strtolower(trim($type));
    $mime = media_studio_normalize_mime($mime);
    $title = trim(mb_substr($title, 0, 190));
    $source = trim(mb_substr($source, 0, 60));

    if (!$pdo || $userId < 1 || !media_studio_schema_ready()) {
        throw new RuntimeException('Media Studio storage is not ready.');
    }
    if (!in_array($type, ['photo','video','audio'], true) || !media_studio_mime_allowed($type, $mime)) {
        throw new RuntimeException('Unsupported media format.');
    }
    if ($title === '') {
        $title = ucfirst($type) . ' ' . date('M j, Y g:i A');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO user_media_assets
         (user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([
        $userId,
        $type,
        $title,
        $mime,
        $relativePath,
        max(0,$size),
        max(0.0,$durationSeconds),
        $source ?: 'upload',
        $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);

    $assetId = (int)$pdo->lastInsertId();

    if (function_exists('agent_brain_store_memory')) {
        agent_brain_store_memory(
            $user,
            'file',
            'Media asset #' . $assetId,
            'Saved ' . $type . ' "' . $title . '" in the user media library.',
            0,
            0.95,
            ['asset_id'=>$assetId,'media_type'=>$type,'source'=>$source]
        );
    }

    return $assetId;
}

function media_studio_latest_asset(array $user, array $types = []): ?array
{
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1 || !table_exists('user_media_assets')) {
        return null;
    }

    $types = array_values(array_intersect(['photo','video','audio'], array_map('strtolower', $types)));
    $params = [$userId];
    $sql = 'SELECT * FROM user_media_assets WHERE user_id=?';
    if ($types) {
        $sql .= ' AND media_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    }
    $sql .= ' ORDER BY created_at DESC,id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function media_studio_visible_tracks(array $user): array
{
    $rows = [];
    foreach (get_tracks() as $track) {
        if (!can_view_track($track, $user)) {
            continue;
        }
        $id = (int)($track['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $rows[] = [
            'id'=>$id,
            'title'=>(string)($track['title'] ?? 'Track'),
            'album'=>(string)(($track['album'] ?? '') ?: 'Stonefellow'),
            'duration'=>(string)($track['duration'] ?? ''),
            'genre'=>(string)($track['genre'] ?? ''),
            'mood'=>(string)($track['mood'] ?? ''),
            'audio'=>url('/media.php?track='.$id.'&type=audio'),
            'cover'=>url('/media.php?track='.$id.'&type=cover'),
            'detail'=>url('/track.php?id='.$id),
        ];
    }
    return $rows;
}

function media_studio_track_visible(int $trackId, array $user): bool
{
    if ($trackId < 1) {
        return false;
    }
    foreach (get_tracks() as $track) {
        if ((int)($track['id'] ?? 0) === $trackId) {
            return can_view_track($track, $user);
        }
    }
    return false;
}
