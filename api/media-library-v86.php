<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/media-collections-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = defined('VP3_PHONE_AGENT_NATIVE_V1')&&function_exists('vp3_phone_agent_native_user_v1')?vp3_phone_agent_native_user_v1():current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Media library access is not available for this account.']);
    exit;
}
if (!media_studio_schema_ready()) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Media Studio storage is not ready. Run the v86 database upgrade.']);
    exit;
}

function media_v86_json(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function media_v86_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        media_v86_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    }
}

function media_v86_collection_id(mixed $value, string $message): int
{
    $id = is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)
        ? filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
    if ($id === false) throw new InvalidArgumentException($message, 422);
    return $id;
}

/** Look up collection membership only for the current page of owned assets. */
function media_v86_collection_memberships(PDO $pdo, int $owner, array $ids): array
{
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare("SELECT asset_id,collection_id FROM vp3_media_collection_assets_v1
        WHERE owner_user_id=? AND asset_id IN ($marks) ORDER BY asset_id,collection_id");
    $query->execute([$owner,...$ids]);
    $out = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) $out[(int)$row['asset_id']][] = (int)$row['collection_id'];
    return $out;
}

function media_v86_temp_dir(int $userId): string
{
    $dir = media_studio_upload_root() . '/' . $userId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the upload workspace.');
    }
    @chmod($dir, 0700);
    return $dir;
}

function media_v86_meta_path(int $userId, string $token): string
{
    return media_v86_temp_dir($userId) . '/' . $token . '.json';
}

function media_v86_part_path(int $userId, string $token): string
{
    return media_v86_temp_dir($userId) . '/' . $token . '.part';
}

function media_v86_token(string $token): string
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Invalid upload token.');
    }
    return $token;
}

function media_v86_read_meta(int $userId, string $token): array
{
    $path = media_v86_meta_path($userId, $token);
    if (!is_file($path)) {
        throw new RuntimeException('Upload session not found.');
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Upload session is invalid.');
    }
    return $decoded;
}

function media_v86_write_meta(int $userId, string $token, array $meta): void
{
    $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents(media_v86_meta_path($userId,$token), $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not update the upload session.');
    }
    @chmod(media_v86_meta_path($userId,$token), 0600);
}

try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
    $userId = (int)$user['id'];
    $workspaceOwner = $_SERVER['HTTP_X_VP3_MEDIA_OWNER'] ?? null;
    $collectionAction = in_array($action, ['collection_create','collection_rename','collection_delete','collection_set_asset'], true);
    if (($action === 'browse' || $collectionAction || $workspaceOwner !== null) && $workspaceOwner !== (string)$userId) {
        media_v86_json(false, ['error'=>'Account changed. Reopen Media Library before continuing.'], 403);
    }

    if ($action === 'list') {
        media_v86_json(true, ['assets'=>media_studio_assets($user, 250)]);
    }

    if ($action === 'browse') {
        $pdo = db();
        $collectionsReady = $pdo instanceof PDO && vp3_media_collections_ready_v1($pdo);
        $collectionId = isset($_GET['collection_id']) ? media_v86_collection_id($_GET['collection_id'], 'Choose a media collection.') : null;
        $type = (string)($_GET['type'] ?? '');
        $source = (string)($_GET['source'] ?? '');
        $query = mb_strtolower(mb_substr(trim((string)($_GET['q'] ?? '')), 0, 190));
        $before = max(0, (int)($_GET['before'] ?? 0));
        $where = ['user_id=?'];
        $params = [$userId];
        if ($collectionId !== null) {
            if (!$collectionsReady) media_v86_json(false, ['error'=>'Run Database Upgrade to enable media collections.'], 503);
            vp3_media_collection_require_v1($pdo, $userId, $collectionId);
            $where[] = 'EXISTS (SELECT 1 FROM vp3_media_collection_assets_v1 membership
                WHERE membership.owner_user_id=user_media_assets.user_id AND membership.asset_id=user_media_assets.id
                AND membership.collection_id=?)';
            $params[] = $collectionId;
        }
        if (in_array($type, ['photo','video','audio'], true)) {
            $where[] = 'media_type=?';
            $params[] = $type;
        }
        $sourceFilter = match ($source) {
            'generated' => "source='openai_image'",
            'edited' => "source='image_editor'",
            'uploaded' => "COALESCE(source,'') NOT IN ('openai_image','image_editor')",
            default => '',
        };
        if ($sourceFilter !== '') $where[] = $sourceFilter;
        if ($query !== '') {
            $where[] = "LOWER(title) LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($query, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
        }
        if ($before > 0) {
            $where[] = 'id<?';
            $params[] = $before;
        }
        $stmt = $pdo->prepare('SELECT id,user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,created_at
            FROM user_media_assets WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 25');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nextBefore = count($rows) > 24 ? (int)$rows[23]['id'] : null;
        $assets = array_map('media_studio_asset_payload', array_slice($rows, 0, 24));
        if ($collectionsReady) {
            $memberships = media_v86_collection_memberships($pdo, $userId, array_column($assets, 'id'));
            foreach ($assets as &$asset) $asset['collection_ids'] = $memberships[$asset['id']] ?? [];
            unset($asset);
        }
        media_v86_json(true, [
            'assets'=>$assets,
            'next_before'=>$nextBefore,
            'collections_available'=>$collectionsReady,
            'collections'=>$collectionsReady ? vp3_media_collections_list_v1($pdo, $userId) : [],
        ]);
    }

    if ($collectionAction && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        media_v86_json(false, ['error'=>'Use POST to change a media collection.'], 405);
    }
    media_v86_csrf();

    if ($collectionAction) {
        $pdo = db();
        if (!$pdo instanceof PDO || !vp3_media_collections_ready_v1($pdo)) {
            media_v86_json(false, ['error'=>'Run Database Upgrade to enable media collections.'], 503);
        }
        $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        if ($action === 'collection_create') {
            media_v86_json(true, ['collection'=>vp3_media_collection_create_v1($pdo, $userId, $name)], 201);
        }
        $id = media_v86_collection_id($_POST['collection_id'] ?? null, 'Choose a media collection.');
        if ($action === 'collection_rename') {
            media_v86_json(true, ['collection'=>vp3_media_collection_rename_v1($pdo, $userId, $id, $name)]);
        }
        if ($action === 'collection_delete') {
            vp3_media_collection_delete_v1($pdo, $userId, $id);
            media_v86_json(true);
        }
        $assetId = media_v86_collection_id($_POST['asset_id'] ?? null, 'Choose a media item.');
        $member = $_POST['member'] ?? null;
        if (!in_array($member, ['0','1'], true)) throw new InvalidArgumentException('Choose whether the media item belongs in this collection.', 422);
        vp3_media_collection_set_asset_v1($pdo, $userId, $id, $assetId, $member === '1');
        media_v86_json(true);
    }

    if ($action === 'begin') {
        $type = strtolower(trim((string)($_POST['media_type'] ?? '')));
        $mime = media_studio_normalize_mime((string)($_POST['mime_type'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $source = trim((string)($_POST['source'] ?? 'capture'));
        $source = match (strtolower($source)) {
            'openai_image' => 'upload',
            'image_editor' => 'image_editor',
            default => $source,
        };
        $originalName = trim((string)($_POST['original_name'] ?? ''));

        if (!in_array($type, ['photo','video','audio'], true) || !media_studio_mime_allowed($type, $mime)) {
            throw new RuntimeException('Unsupported media format.');
        }

        $dir = media_v86_temp_dir($userId);
        foreach (glob($dir . '/*.json') ?: [] as $candidate) {
            if (@filemtime($candidate) && filemtime($candidate) < time() - 86400) {
                $oldToken = basename($candidate, '.json');
                @unlink($candidate);
                @unlink($dir . '/' . $oldToken . '.part');
            }
        }

        $token = bin2hex(random_bytes(16));
        $meta = [
            'media_type'=>$type,
            'mime_type'=>$mime,
            'title'=>mb_substr($title,0,190),
            'source'=>mb_substr($source,0,60),
            'original_name'=>mb_substr($originalName,0,255),
            'next_index'=>0,
            'total_chunks'=>0,
            'total_size'=>0,
            'started_at'=>date('c'),
        ];
        media_v86_write_meta($userId,$token,$meta);
        file_put_contents(media_v86_part_path($userId,$token), '');
        @chmod(media_v86_part_path($userId,$token), 0600);

        media_v86_json(true, ['token'=>$token,'chunk_size'=>8 * 1024 * 1024]);
    }

    if ($action === 'chunk') {
        $token = media_v86_token((string)($_POST['token'] ?? ''));
        $index = max(0,(int)($_POST['index'] ?? -1));
        $totalChunks = max(1,(int)($_POST['total_chunks'] ?? 1));
        $meta = media_v86_read_meta($userId,$token);

        if ($index !== (int)($meta['next_index'] ?? 0)) {
            throw new RuntimeException('Upload chunks arrived out of order.');
        }
        $lockedTotal = (int)($meta['total_chunks'] ?? 0);
        if ($lockedTotal > 0 && $lockedTotal !== $totalChunks) {
            throw new RuntimeException('Upload chunk count changed during transfer.');
        }
        if ($lockedTotal < 1) {
            $meta['total_chunks'] = $totalChunks;
        }
        if (!isset($_FILES['chunk']) || (int)($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Media chunk upload failed.');
        }

        $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
        $size = (int)($_FILES['chunk']['size'] ?? 0);
        if ($tmp === '' || $size < 1 || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Media chunk is empty.');
        }

        $nextSize = (int)($meta['total_size'] ?? 0) + $size;
        $limit = media_studio_max_bytes((string)$meta['media_type']);
        if ($limit < 1 || $nextSize > $limit) {
            throw new RuntimeException('This recording is larger than the configured media-library limit.');
        }

        $in = fopen($tmp,'rb');
        $out = fopen(media_v86_part_path($userId,$token),'ab');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            throw new RuntimeException('Could not store the media chunk.');
        }
        stream_copy_to_stream($in,$out);
        fclose($in);
        fclose($out);

        $meta['next_index'] = $index + 1;
        $meta['total_size'] = $nextSize;
        media_v86_write_meta($userId,$token,$meta);
        media_v86_json(true, ['received'=>$meta['next_index'],'total'=>$totalChunks]);
    }

    if ($action === 'finish') {
        $token = media_v86_token((string)($_POST['token'] ?? ''));
        $duration = max(0.0,(float)($_POST['duration_seconds'] ?? 0));
        $metadata = json_decode((string)($_POST['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $meta = media_v86_read_meta($userId,$token);

        $expected = (int)($meta['total_chunks'] ?? 0);
        $received = (int)($meta['next_index'] ?? 0);
        if ($expected < 1 || $received !== $expected) {
            throw new RuntimeException('The upload is incomplete.');
        }

        $part = media_v86_part_path($userId,$token);
        $size = is_file($part) ? (int)filesize($part) : 0;
        if ($size < 1 || $size !== (int)($meta['total_size'] ?? 0)) {
            throw new RuntimeException('The uploaded media file is incomplete.');
        }

        if ($meta['source'] === 'image_editor') {
            $sourceId = filter_var($metadata['source_asset_id'] ?? null, FILTER_VALIDATE_INT);
            $sourceAsset = $sourceId > 0 ? media_studio_asset($sourceId, $user) : null;
            if (!$sourceAsset || $sourceAsset['media_type'] !== 'photo' || !media_studio_asset_payload($sourceAsset)['available']) {
                throw new RuntimeException('The original image is unavailable in your Media Library.');
            }
            $image = @getimagesize($part);
            if ($meta['media_type'] !== 'photo' || $meta['mime_type'] !== 'image/png' || ($image['mime'] ?? '') !== 'image/png') {
                throw new RuntimeException('Edited copies must be saved as PNG images.');
            }
            $metadata = ['source_asset_id'=>$sourceId, 'width'=>$image[0], 'height'=>$image[1]];
        }
        if ($meta['source'] === 'promotion_design') {
            $creativeId = $metadata['creative_id'] ?? null;
            $image = @getimagesize($part);
            if (!is_string($creativeId) || !preg_match('/\A[a-f0-9]{32}\z/',$creativeId)
                || $meta['media_type'] !== 'photo' || $meta['mime_type'] !== 'image/png'
                || ($image['mime'] ?? '') !== 'image/png' || $size > 16*1024*1024
                || min($image[0],$image[1]) < 1 || max($image[0],$image[1]) > 4096
                || !table_exists('agent_commerce_promotions_v1')) {
                throw new RuntimeException('Choose an exact promotion design and save it as a PNG.');
            }
            $creative = db()->prepare("SELECT id FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND id=? AND status='designing'");
            $creative->execute([$userId,$creativeId]);
            if (!$creative->fetchColumn()) throw new RuntimeException('The promotion design is unavailable.');
            require_once dirname(__DIR__).'/includes/agent-image-provider-v1.php';
            $bytes = @file_get_contents($part,false,null,0,16*1024*1024+1);
            if (!is_string($bytes) || strlen($bytes) !== $size) throw new RuntimeException('The PNG upload is incomplete.');
            try {
                vp3_image_png_check_v1($bytes);
            } catch (RuntimeException) {
                throw new RuntimeException('The promotion artwork must be a complete PNG.');
            }
            unset($bytes);
            $metadata = ['creative_id'=>$creativeId,'width'=>$image[0],'height'=>$image[1]];
        }

        $extension = media_studio_extension_for_mime((string)$meta['mime_type']);
        if ($extension === 'bin') {
            throw new RuntimeException('Unsupported media format.');
        }

        $root = media_studio_ensure_user_root($userId);
        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $final = $root . '/' . $filename;
        if (!rename($part,$final)) {
            throw new RuntimeException('Could not finalize the media file.');
        }
        @chmod($final,0600);

        $relative = 'private/user-media/' . $userId . '/' . $filename;
        $assetId = media_studio_create_asset(
            $user,
            (string)$meta['media_type'],
            (string)$meta['mime_type'],
            $relative,
            $size,
            (string)$meta['title'],
            (string)$meta['source'],
            $duration,
            $metadata + ['original_name'=>(string)($meta['original_name'] ?? '')]
        );

        @unlink(media_v86_meta_path($userId,$token));
        $row = media_studio_asset($assetId,$user);

        if (function_exists('agent_tool_log')) {
            agent_tool_log(
                $user,
                'media_library.capture',
                (string)$meta['title'],
                'success',
                ['asset_id'=>$assetId,'media_type'=>$meta['media_type'],'source'=>$meta['source']],
                0
            );
        }

        media_v86_json(true, ['asset'=>$row ? media_studio_asset_payload($row) : ['id'=>$assetId]]);
    }

    if ($action === 'rename') {
        $assetId = max(0,(int)($_POST['asset_id'] ?? 0));
        $title = trim(mb_substr((string)($_POST['title'] ?? ''),0,190));
        if ($assetId < 1 || $title === '' || !media_studio_asset($assetId,$user)) {
            throw new RuntimeException('Media item not found.');
        }
        $stmt = db()?->prepare('UPDATE user_media_assets SET title=?,updated_at=NOW() WHERE id=? AND user_id=?');
        $stmt?->execute([$title,$assetId,$userId]);
        media_v86_json(true);
    }

    if ($action === 'delete') {
        $assetId = max(0,(int)($_POST['asset_id'] ?? 0));
        $row = media_studio_asset($assetId,$user);
        if (!$row) {
            throw new RuntimeException('Media item not found.');
        }

        $root = realpath(media_studio_user_root($userId));
        $path = realpath(STONEFELLOW_ROOT . '/' . ltrim((string)$row['file_path'],'/'));
        if ($root && $path && str_starts_with($path,$root . DIRECTORY_SEPARATOR)) {
            @unlink($path);
        }
        $stmt = db()?->prepare('DELETE FROM user_media_assets WHERE id=? AND user_id=?');
        $stmt?->execute([$assetId,$userId]);
        media_v86_json(true);
    }

    throw new RuntimeException('Unknown media-library action.');
} catch (Throwable $e) {
    if (($collectionAction ?? false) || ($action ?? '') === 'browse') {
        if ($e instanceof PDOException || !($e instanceof RuntimeException || $e instanceof DomainException || $e instanceof InvalidArgumentException)) {
            media_v86_json(false, ['error'=>'The Media Library could not complete this request. Try again.'], 503);
        }
        $status = (int)$e->getCode();
        media_v86_json(false, ['error'=>$e->getMessage()], in_array($status,[400,403,404,409,422,503],true) ? $status : 400);
    }
    media_v86_json(false, ['error'=>$e->getMessage()], 400);
}
