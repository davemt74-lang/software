<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';

/** The seller approves this private source only for the canonical listing. */
function profile_product_photo_binding_v1(array $product): ?array
{
    if (empty($product['is_active']) || profile_commerce_visibility_v900($product) !== 'public'
        || profile_commerce_private_listing_draft_v900($product)) return null;

    $metadata = profile_commerce_metadata_v900($product);
    $sourceId = $metadata['source_asset_id'] ?? null;
    $digest = $metadata['source_content_sha256'] ?? null;
    if (($metadata['public_listing_photo'] ?? false) !== true
        || (!is_string($sourceId) && !is_int($sourceId))
        || !preg_match('/\A[1-9][0-9]{0,18}\z/', (string)$sourceId)
        || (string)(int)$sourceId !== (string)$sourceId
        || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) return null;
    return ['asset_id'=>(int)$sourceId, 'sha256'=>$digest];
}

function profile_product_photo_url_v1(string $username, array $product): ?string
{
    if (!profile_product_photo_binding_v1($product)) return null;
    return url('/profile-product-photo.php?'.http_build_query([
        'username'=>$username, 'product'=>profile_commerce_public_slug_v900($product), 'listing'=>(int)$product['id'],
    ]));
}

/** Resolve the same active public listing used by the product page. */
function profile_product_photo_record_v1(PDO $pdo, string $username, string $slug, int $listingId): ?array
{
    $profile = profile_by_username($pdo, $username);
    if (!$profile || empty($profile['is_active']) || empty($profile['is_public'])) return null;
    $product = profile_commerce_public_product_v900($pdo, $profile, $slug);
    if (!$product || $product['id'] !== $listingId) return null;
    $owner = (int)$profile['user_id'];
    $row = profile_commerce_owner_product_v900($pdo, $owner, $listingId);
    $binding = $row ? profile_product_photo_binding_v1($row) : null;
    if (!$binding || profile_commerce_public_slug_v900($row) !== $product['slug']) return null;

    require_once __DIR__.'/initiative-work-product-v1.php';
    $asset = media_studio_asset($binding['asset_id'], ['id'=>$owner]);
    if (!$asset || $asset['media_type'] !== 'photo' || !in_array($asset['mime_type'], VP3_PRODUCT_PHOTO_MIMES_V1, true)) return null;
    $path = initiative_work_product_file_v1($owner, (string)$asset['file_path']);
    return $path ? $binding + ['owner'=>$owner, 'mime'=>$asset['mime_type'], 'path'=>$path] : null;
}

/** Read the approved bytes and recheck visibility and ownership before returning. */
function profile_product_photo_image_v1(PDO $pdo, string $username, string $slug, int $listingId): ?array
{
    $record = profile_product_photo_record_v1($pdo, $username, $slug, $listingId);
    if (!$record) return null;
    $bytes = @file_get_contents($record['path'], false, null, 0, VP3_PRODUCT_PHOTO_BYTES_V1+1);
    if (!is_string($bytes) || strlen($bytes) > VP3_PRODUCT_PHOTO_BYTES_V1
        || !hash_equals($record['sha256'], hash('sha256', $bytes))) return null;
    $dimensions = @getimagesizefromstring($bytes);
    if (!$dimensions || $dimensions['mime'] !== $record['mime']
        || min($dimensions[0], $dimensions[1]) < 16 || max($dimensions[0], $dimensions[1]) > 8192
        || $dimensions[0]*$dimensions[1] > 24000000) return null;
    if (profile_product_photo_record_v1($pdo, $username, $slug, $listingId) !== $record) return null;
    return ['bytes'=>$bytes, 'mime'=>$record['mime']];
}
