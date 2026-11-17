<?php
declare(strict_types=1);

const VP3_CODING_CANVAS_ARTWORK_MAX_BYTES_V1 = 73728;
const VP3_CODING_CANVAS_ICON_FILE_V1 = 'vp3-canvas-icon.image';
const VP3_CODING_CANVAS_THUMBNAIL_PREFIX_V1 = 'vp3-canvas-thumbnail-';
const VP3_CODING_CANVAS_ARTWORK_DIGEST_V1 = 'Canvas artwork SHA-256 ';

function vp3_coding_canvas_artwork_file_v1(string $kind, ?int $revision): string
{
    return match (true) {
        $kind === 'icon' => VP3_CODING_CANVAS_ICON_FILE_V1,
        $kind === 'thumbnail' && $revision !== null && $revision > 0 =>
            VP3_CODING_CANVAS_THUMBNAIL_PREFIX_V1.$revision.'.image',
        default => throw new InvalidArgumentException('Choose an exact canvas artwork item.', 422),
    };
}

/** Only passive, bounded raster images can be saved or served from this endpoint. */
function vp3_coding_canvas_artwork_mime_v1(string $kind, string $bytes): string
{
    $length = strlen($bytes);
    if ($length > VP3_CODING_CANVAS_ARTWORK_MAX_BYTES_V1) {
        throw new InvalidArgumentException('Canvas artwork is limited to 72 KiB.', 422);
    }
    if ($length < 32) throw new InvalidArgumentException('Select a complete PNG, JPEG or WebP image.', 422);
    $image = @getimagesizefromstring($bytes);
    $mime = $image['mime'] ?? '';
    $width = $image[0] ?? 0;
    $height = $image[1] ?? 0;
    $dimensions = $width >= 32 && $height >= 32 && $width <= 1024 && $height <= 1024
        && ($kind !== 'icon' || $width === $height);
    $complete = match ($mime) {
        'image/png' => str_starts_with($bytes, "\x89PNG\x0d\x0a\x1a\x0a")
            && str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82"),
        'image/jpeg' => str_starts_with($bytes, "\xff\xd8") && str_ends_with($bytes, "\xff\xd9"),
        'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP'
            && (unpack('Vlength', substr($bytes, 4, 4))['length'] ?? -1) === $length-8,
        default => false,
    };
    if (!$dimensions || !$complete) {
        throw new InvalidArgumentException('Select a complete PNG, JPEG or WebP image within the canvas artwork dimensions.', 422);
    }
    return $mime;
}

/** Metadata only. Do not fetch or disclose private bytes when listing canvases. */
function vp3_coding_canvas_artwork_projection_v1(PDO $pdo, int $owner, int $agentId, ?string $canvasId = null,
    ?int $revision = null): array
{
    $where = $canvasId === null ? '' : ' AND c.id=?';
    $query = $pdo->prepare("SELECT c.id,c.active_revision,c.draft_revision,k.file_name,k.description
        FROM vp3_coding_canvases_v1 c
        JOIN knowledge_items k ON k.folder_id=c.folder_id AND k.created_by_user_id=c.owner_user_id
        WHERE c.owner_user_id=? AND c.agent_id=?$where AND k.file_type='coding_canvas'
          AND k.knowledge_scope='personal' AND k.visibility='private' AND k.is_published=0
          AND k.mime_type IN ('image/png','image/jpeg','image/webp') AND k.file_size BETWEEN 32 AND ?
          AND (k.file_name=? OR k.file_name LIKE ?) ORDER BY k.id ASC");
    $args = $canvasId === null ? [$owner, $agentId] : [$owner, $agentId, $canvasId];
    $query->execute([...$args, VP3_CODING_CANVAS_ARTWORK_MAX_BYTES_V1, VP3_CODING_CANVAS_ICON_FILE_V1,
        VP3_CODING_CANVAS_THUMBNAIL_PREFIX_V1.'%']);
    $out = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $description = $row['description'] ?? '';
        if (!is_string($description) || !str_starts_with($description, VP3_CODING_CANVAS_ARTWORK_DIGEST_V1)) continue;
        $digest = substr($description, strlen(VP3_CODING_CANVAS_ARTWORK_DIGEST_V1));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest)) continue;
        $version = $revision ?? (int)($row['active_revision'] ?? $row['draft_revision'] ?? 0);
        $kind = match (true) {
            $row['file_name'] === VP3_CODING_CANVAS_ICON_FILE_V1 => 'icon',
            $version > 0 && $row['file_name'] === vp3_coding_canvas_artwork_file_v1('thumbnail', $version) => 'thumbnail',
            default => null,
        };
        if ($kind === null || isset($out[$row['id']][$kind.'_url'])) continue;
        $route = ['agent_id'=>$agentId,'canvas_id'=>$row['id'],'kind'=>$kind];
        if ($kind === 'thumbnail') {
            $route['revision'] = $version;
            $out[$row['id']]['thumbnail_revision'] = $version;
        }
        $route['v'] = $digest;
        $out[$row['id']][$kind.'_url'] = url('/api/coding-canvas-artwork-v1.php?'.http_build_query($route,'','&',PHP_QUERY_RFC3986));
        $out[$row['id']][$kind.'_hash'] = $digest;
    }
    return $out;
}

function vp3_coding_canvas_artwork_urls_v1(PDO $pdo, int $owner, int $agentId, string $canvasId, ?int $revision = null): array
{
    return vp3_coding_canvas_artwork_projection_v1($pdo, $owner, $agentId, $canvasId, $revision)[$canvasId] ?? [];
}

function vp3_coding_canvas_artwork_row_v1(PDO $pdo, array $canvas, string $fileName): ?array
{
    $query = $pdo->prepare("SELECT id,description,mime_type,file_size,content_text FROM knowledge_items
        WHERE folder_id=? AND created_by_user_id=? AND file_name=? AND file_type='coding_canvas'
          AND knowledge_scope='personal' AND visibility='private' AND is_published=0 ORDER BY id ASC LIMIT 1");
    $query->execute([(int)$canvas['folder_id'], (int)$canvas['owner_user_id'], $fileName]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Owner-scoped upsert. Automatic saves are create-only, replacements compare the current digest. */
function vp3_coding_canvas_artwork_save_v1(PDO $pdo, array $user, int $agentId, string $canvasId, string $kind,
    int $revision, string $bytes, ?string $replaceHash = null): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $owner = (int)$user['id'];
    $id = vp3_coding_canvas_id_v1($canvasId);
    $fileName = vp3_coding_canvas_artwork_file_v1($kind, $revision);
    $mime = vp3_coding_canvas_artwork_mime_v1($kind, $bytes);
    $encoded = base64_encode($bytes);
    $digest = hash('sha256', $bytes);
    $description = VP3_CODING_CANVAS_ARTWORK_DIGEST_V1.$digest;
    if ($replaceHash !== null && !preg_match('/\A[a-f0-9]{64}\z/D', $replaceHash)) {
        throw new InvalidArgumentException('Open the current canvas artwork again.', 422);
    }
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use (
        $pdo, $owner, $agentId, $id, $kind, $revision, $bytes, $encoded, $replaceHash, $fileName, $mime, $description
    ): array {
        $row = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $details = vp3_coding_canvas_details_v1($pdo, $row, $revision);
        if (!in_array($details['revision']['status'], ['active','draft'], true) || !$details['revision']['test_passed']) {
            throw new RuntimeException('Test the current canvas version before saving its artwork.', 409);
        }
        $previous = vp3_coding_canvas_artwork_row_v1($pdo, $row, $fileName);
        if ($previous !== null && $previous['description'] === $description
            && $previous['content_text'] === $encoded) return $details['canvas'];
        if ($previous !== null && ($replaceHash === null
            || !hash_equals(VP3_CODING_CANVAS_ARTWORK_DIGEST_V1.$replaceHash, (string)$previous['description']))) {
            throw new RuntimeException('The canvas artwork changed. Open it again before replacing it.', 409);
        }
        if ($previous === null && $replaceHash !== null) {
            throw new RuntimeException('The original canvas artwork is no longer available.', 409);
        }
        if ($previous === null) {
            $query = $pdo->prepare("INSERT INTO knowledge_items
                (title, description, file_name, file_type, mime_type, file_size, content_text,
                 visibility, is_published, created_by_user_id, knowledge_scope, folder_id)
                VALUES (?, ?, ?, 'coding_canvas', ?, ?, ?, 'private', 0, ?, 'personal', ?)");
            $query->execute([$kind === 'icon' ? 'App icon' : 'App thumbnail', $description, $fileName,
                $mime, strlen($bytes), $encoded, $owner, (int)$row['folder_id']]);
        } else {
            $query = $pdo->prepare('UPDATE knowledge_items SET description=?,mime_type=?,file_size=?,content_text=?,
                updated_at=CURRENT_TIMESTAMP WHERE id=? AND created_by_user_id=? AND folder_id=?');
            $query->execute([$description, $mime, strlen($bytes), $encoded, (int)$previous['id'], $owner, (int)$row['folder_id']]);
        }
        return vp3_coding_canvas_public_v1($row)
            + vp3_coding_canvas_artwork_urls_v1($pdo, $owner, $agentId, $id, $revision);
    });
}

function vp3_coding_canvas_artwork_read_v1(PDO $pdo, array $user, int $agentId, string $canvasId, string $kind,
    ?int $revision, string $digest): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId);
    $row = vp3_coding_canvas_require_row_v1($pdo, (int)$user['id'], $agentId, vp3_coding_canvas_id_v1($canvasId));
    $item = vp3_coding_canvas_artwork_row_v1($pdo, $row, vp3_coding_canvas_artwork_file_v1($kind, $revision));
    $encoded = $item['content_text'] ?? null;
    $bytes = is_string($encoded) && strlen($encoded) <= 98304 ? base64_decode($encoded, true) : false;
    if (!is_string($bytes) || !preg_match('/\A[a-f0-9]{64}\z/D', $digest)
        || !hash_equals($digest, hash('sha256', $bytes))
        || !hash_equals(VP3_CODING_CANVAS_ARTWORK_DIGEST_V1.$digest, (string)$item['description'])) {
        throw new RuntimeException('Canvas artwork not found.', 404);
    }
    $mime = vp3_coding_canvas_artwork_mime_v1($kind, $bytes);
    if ($mime !== $item['mime_type'] || strlen($bytes) !== (int)$item['file_size']) {
        throw new RuntimeException('Canvas artwork not found.', 404);
    }
    return ['mime'=>$mime,'bytes'=>$bytes];
}
