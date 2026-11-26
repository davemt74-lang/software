<?php
declare(strict_types=1);

require_once __DIR__.'/media-studio-v86.php';
require_once __DIR__.'/agent-image-provider-v1.php';

const AGENT_EMAIL_ATTACHMENTS_MAX_COUNT_V1 = 5;
const AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1 = 10 * 1024 * 1024;

/** A frozen manifest contains no path, remote URL, or image bytes. */
function agent_email_attachments_manifest_v1(array $manifest): array
{
    if (!array_is_list($manifest) || count($manifest) > AGENT_EMAIL_ATTACHMENTS_MAX_COUNT_V1) {
        throw new DomainException('Attach up to five JPEG, PNG or WebP images.', 422);
    }
    $out = [];
    $seen = [];
    $total = 0;
    foreach ($manifest as $item) {
        if (!is_array($item) || count($item) !== 5
            || array_diff(array_keys($item), ['asset_id','name','mime','size','sha256'])
            || !is_int($item['asset_id'] ?? null) || $item['asset_id'] < 1
            || isset($seen[$item['asset_id']])
            || !is_string($item['name'] ?? null)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 _.-]{0,119}\.(?:jpg|png|webp)\z/D', $item['name'])
            || !in_array($item['mime'] ?? null, ['image/jpeg','image/png','image/webp'], true)
            || !is_int($item['size'] ?? null) || $item['size'] < 1
            || $item['size'] > AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1
            || !is_string($item['sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $item['sha256'])) {
            throw new DomainException('The saved image attachments are invalid. Select the images again.', 422);
        }
        $extension = media_studio_extension_for_mime($item['mime']);
        if (!str_ends_with($item['name'], '.'.$extension)) {
            throw new DomainException('The saved image attachment type changed. Select the image again.', 422);
        }
        $total += $item['size'];
        if ($total > AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1) {
            throw new DomainException('Keep the combined email images at 10 MB or less.', 422);
        }
        $seen[$item['asset_id']] = true;
        $out[] = ['asset_id'=>$item['asset_id'], 'name'=>$item['name'], 'mime'=>$item['mime'],
            'size'=>$item['size'], 'sha256'=>$item['sha256']];
    }
    return $out;
}

/** Safe ASCII filenames keep MIME headers unambiguous in every mail client. */
function agent_email_attachment_name_v1(array $asset): string
{
    $metadata = json_decode((string)($asset['metadata_json'] ?? ''), true);
    $original = is_array($metadata) && is_string($metadata['original_name'] ?? null)
        ? $metadata['original_name'] : (string)($asset['title'] ?? '');
    $base = basename(str_replace('\\', '/', $original));
    $base = (string)pathinfo($base, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9 _.-]+/', '-', $base) ?? '';
    $base = trim(substr($base, 0, 100), " .-_");
    if ($base === '') $base = 'image-'.(int)$asset['id'];
    return $base.'.'.media_studio_extension_for_mime((string)$asset['mime_type']);
}

/** Inspect an owned private raster using bounded reads and the canonical owner root. */
function agent_email_attachment_source_v1(PDO $pdo, int $owner, int $assetId): array
{
    if ($owner < 1 || $assetId < 1) {
        throw new DomainException('Choose an image from your private Media Library.', 422);
    }
    $query = $pdo->prepare('SELECT id,user_id,media_type,title,mime_type,file_path,file_size,metadata_json
        FROM user_media_assets WHERE id=? AND user_id=?');
    $query->execute([$assetId, $owner]);
    $asset = $query->fetch(PDO::FETCH_ASSOC);
    if (!$asset || $asset['media_type'] !== 'photo'
        || !in_array($asset['mime_type'], ['image/jpeg','image/png','image/webp'], true)) {
        throw new DomainException('An attached image is unavailable in your private Media Library.', 404);
    }
    $size = (int)$asset['file_size'];
    if ($size < 1 || $size > AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1) {
        throw new DomainException('Keep the combined email images at 10 MB or less.', 422);
    }
    $relative = (string)$asset['file_path'];
    $prefix = 'private/user-media/'.$owner.'/';
    $base = realpath(media_studio_private_root());
    $root = realpath(media_studio_user_root($owner));
    $candidate = STONEFELLOW_ROOT.'/'.$relative;
    $path = realpath($candidate);
    if (!str_starts_with($relative, $prefix) || str_contains($relative, '\\')
        || preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~', $relative)
        || !is_string($base) || !is_string($root) || $root !== $base.DIRECTORY_SEPARATOR.$owner
        || is_link(media_studio_user_root($owner)) || !is_string($path)
        || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || is_link($candidate) || !is_file($path)) {
        throw new DomainException('An attached image file is unavailable. Select the image again.', 409);
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new DomainException('An attached image could not be read. Select the image again.', 409);
    }
    try {
        $before = fstat($handle);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] !== $size) {
            throw new DomainException('An attached image changed. Select the image again.', 409);
        }
        $bytes = stream_get_contents($handle, AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1 + 1);
        $after = fstat($handle);
    } finally {
        fclose($handle);
    }
    clearstatcache(true, $path);
    $live = @lstat($path);
    if (!is_string($bytes) || strlen($bytes) !== $size || !is_array($after) || !is_array($live)
        || $before['size'] !== $after['size'] || $before['ino'] !== $live['ino'] || $before['dev'] !== $live['dev']
        || ($live['mode'] & 0170000) !== 0100000 || realpath($candidate) !== $path
        || realpath(media_studio_user_root($owner)) !== $root) {
        throw new DomainException('An attached image changed while it was being read. Select it again.', 409);
    }
    $image = @getimagesizefromstring($bytes);
    if (!$image || ($image['mime'] ?? '') !== $asset['mime_type'] || min($image[0], $image[1]) < 1
        || max($image[0], $image[1]) > 8192 || $image[0] * $image[1] > 40000000) {
        throw new DomainException('Attach a valid JPEG, PNG or WebP image up to 8192 pixels per side and 40 megapixels.', 422);
    }
    if ($asset['mime_type'] === 'image/png') {
        try { vp3_image_png_check_v1($bytes); }
        catch (RuntimeException $error) { throw new DomainException('An attached PNG is incomplete or damaged.', 422, $error); }
    } elseif ($asset['mime_type'] === 'image/jpeg') {
        if (!str_starts_with($bytes, "\xff\xd8") || !str_ends_with($bytes, "\xff\xd9")) {
            throw new DomainException('An attached JPEG is incomplete or damaged.', 422);
        }
    } elseif (strlen($bytes) < 20 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP'
        || (int)unpack('V', substr($bytes, 4, 4))[1] + 8 !== strlen($bytes)) {
        throw new DomainException('An attached WebP is incomplete or damaged.', 422);
    }
    return ['asset_id'=>$assetId, 'name'=>agent_email_attachment_name_v1($asset), 'mime'=>$asset['mime_type'],
        'size'=>$size, 'sha256'=>hash('sha256', $bytes), 'bytes'=>$bytes];
}

/** Only explicit asset selections become part of this draft's reviewed content. */
function agent_email_attachments_freeze_v1(PDO $pdo, int $owner, array $assetIds): array
{
    if (!array_is_list($assetIds) || count($assetIds) > AGENT_EMAIL_ATTACHMENTS_MAX_COUNT_V1) {
        throw new DomainException('Attach up to five JPEG, PNG or WebP images.', 422);
    }
    $manifest = [];
    $seen = [];
    $total = 0;
    foreach ($assetIds as $id) {
        if (!is_int($id) || $id < 1 || isset($seen[$id])) {
            throw new DomainException('Select each attached image once from your private Media Library.', 422);
        }
        $seen[$id] = true;
        $source = agent_email_attachment_source_v1($pdo, $owner, $id);
        $total += $source['size'];
        unset($source['bytes']);
        if ($total > AGENT_EMAIL_ATTACHMENTS_MAX_BYTES_V1) {
            throw new DomainException('Keep the combined email images at 10 MB or less.', 422);
        }
        $manifest[] = $source;
    }
    return agent_email_attachments_manifest_v1($manifest);
}

/** Projection is shape-only so deleted files cannot hide a saved draft or receipt. */
function agent_email_attachments_public_v1(array $manifest): array
{
    return array_map(static fn(array $item): array => [
        'asset_id'=>$item['asset_id'], 'name'=>$item['name'], 'mime'=>$item['mime'], 'size'=>$item['size'],
        'url'=>media_studio_asset_url($item['asset_id']),
    ], agent_email_attachments_manifest_v1($manifest));
}

/** Call only before the first provider POST, never during send reconciliation. */
function agent_email_attachments_parts_v1(PDO $pdo, int $owner, array $manifest): array
{
    $parts = [];
    foreach (agent_email_attachments_manifest_v1($manifest) as $item) {
        $source = agent_email_attachment_source_v1($pdo, $owner, $item['asset_id']);
        if ($source['mime'] !== $item['mime'] || $source['size'] !== $item['size']
            || !hash_equals($source['sha256'], $item['sha256'])) {
            throw new DomainException('An attached image changed after review. Save and review the draft again.', 409);
        }
        // A later library rename does not change the already-reviewed email filename.
        $parts[] = ['name'=>$item['name'], 'mime'=>$item['mime'], 'bytes'=>$source['bytes']];
    }
    return $parts;
}
