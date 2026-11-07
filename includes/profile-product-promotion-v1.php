<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';

/** The public key belongs to one approval and is rotated on republication. */
function profile_product_promotion_key_v1(mixed $key): bool
{
    return is_string($key) && preg_match('/\A[a-f0-9]{32}\z/D', $key) === 1;
}

/** Internal rows with a verified current destination. Never serialize the file descriptor. */
function profile_product_promotion_records_v1(PDO $pdo, array $profile, ?array $keys = null): array
{
    $owner = (int)($profile['user_id'] ?? 0);
    if ($owner < 1 || empty($profile['is_active']) || empty($profile['is_public'])
        || !table_exists('agent_commerce_promotions_v1')) return [];
    $fresh = profile_for_user($pdo, $owner, false);
    if (!$fresh || empty($fresh['is_active']) || empty($fresh['is_public'])
        || (string)$fresh['username'] !== (string)($profile['username'] ?? '')) return [];

    $params = [$owner];
    $where = "owner_user_id=? AND status='published' AND public_key IS NOT NULL";
    if ($keys !== null) {
        $keys = array_values(array_unique(array_filter($keys, 'profile_product_promotion_key_v1')));
        if (!$keys) return [];
        $keys = array_slice($keys, 0, 120);
        $where .= ' AND public_key IN ('.implode(',', array_fill(0, count($keys), '?')).')';
        array_push($params, ...$keys);
    }
    $query = $pdo->prepare("SELECT public_key,product_id,source_asset_id,asset_id,asset_sha256,settings_json,public_product_url,public_cta
        FROM agent_commerce_promotions_v1 WHERE $where ORDER BY updated_at DESC,id DESC LIMIT ".($keys === null ? '40' : '120'));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // This is the same sorted catalog as the public destination resolver. When
    // two legacy rows share a slug, only the product reached by that route wins.
    $products = [];
    $slugs = [];
    foreach (profile_commerce_products_for_profile_v900($pdo, $fresh, true, 200) as $product) {
        if (isset($slugs[$product['slug']])) continue;
        $slugs[$product['slug']] = true;
        $products[(int)$product['id']] = $product;
    }
    $root = realpath(media_studio_user_root($owner));
    if ($root === false) return [];

    $records = [];
    foreach ($rows as $row) {
        $key = $row['public_key'];
        $productId = (int)$row['product_id'];
        $assetId = (int)$row['asset_id'];
        $product = $products[$productId] ?? null;
        if (!profile_product_promotion_key_v1($key) || !$product
            || !hash_equals((string)$product['product_url'], (string)$row['public_product_url'])
            || $assetId < 1 || $assetId === (int)$row['source_asset_id']
            || !preg_match('/\A[a-f0-9]{64}\z/D', (string)$row['asset_sha256'])) continue;
        $currentProduct = profile_commerce_owner_product_v900($pdo, $owner, $productId);
        if (!$currentProduct || empty($currentProduct['is_active']) || profile_commerce_visibility_v900($currentProduct) !== 'public'
            || profile_commerce_private_listing_draft_v900($currentProduct)
            || profile_commerce_product_url_v900((string)$fresh['username'], $currentProduct) !== $product['product_url']) continue;

        $asset = media_studio_asset($assetId, ['id'=>$owner]);
        if (!$asset || $asset['media_type'] !== 'photo' || $asset['mime_type'] !== 'image/png') continue;
        $path = realpath(STONEFELLOW_ROOT.'/'.ltrim((string)$asset['file_path'], '/'));
        if ($path === false || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) continue;
        $size = @filesize($path);
        if ($size === false || $size < 8 || $size > 16*1024*1024 || $size !== (int)$asset['file_size']) continue;

        $settings = json_decode((string)$row['settings_json'], true, 16);
        $headline = $settings['headline'] ?? null;
        $copy = $settings['copy'] ?? null;
        $cta = (string)$row['public_cta'];
        if (!is_string($headline) || trim($headline) === '' || mb_strlen($headline) > 120
            || !is_string($copy) || mb_strlen($copy) > 600 || trim($cta) === '' || mb_strlen($cta) > 40) continue;
        $records[$key] = [
            'card'=>['key'=>$key, 'product_id'=>$productId, 'headline'=>$headline, 'copy'=>$copy, 'cta'=>$cta,
                'product_url'=>$product['product_url'], 'image_url'=>url('/profile-promotion-image.php?key='.$key)],
            'product'=>$product, 'path'=>$path, 'size'=>$size, 'sha256'=>(string)$row['asset_sha256'],
        ];
    }
    return $records;
}

function profile_product_promotions_public_v1(PDO $pdo, array $profile): array
{
    return array_values(array_column(profile_product_promotion_records_v1($pdo, $profile), 'card'));
}

function profile_product_promotion_public_v1(PDO $pdo, array $profile, string $key): ?array
{
    return profile_product_promotion_records_v1($pdo, $profile, [$key])[$key]['card'] ?? null;
}

/** Resolve only the reviewed artwork, never the source product photo or other private assets. */
function profile_product_promotion_image_v1(PDO $pdo, string $key): ?string
{
    if (!profile_product_promotion_key_v1($key) || !table_exists('agent_commerce_promotions_v1')) return null;
    $query = $pdo->prepare("SELECT owner_user_id FROM agent_commerce_promotions_v1 WHERE public_key=? AND status='published' LIMIT 1");
    $query->execute([$key]);
    $owner = (int)$query->fetchColumn();
    $profile = $owner > 0 ? profile_for_user($pdo, $owner, false) : null;
    if (!$profile) return null;
    $record = profile_product_promotion_records_v1($pdo, $profile, [$key])[$key] ?? null;
    if (!$record) return null;
    $bytes = @file_get_contents($record['path'], false, null, 0, $record['size']+1);
    if (!is_string($bytes) || strlen($bytes) !== $record['size'] || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
        || !hash_equals($record['sha256'], hash('sha256', $bytes))) return null;
    // Recheck authority after the file read so a simultaneous pause, replacement
    // or profile/product visibility change does not disclose the earlier bytes.
    $current = profile_product_promotion_records_v1($pdo, $profile, [$key])[$key] ?? null;
    return $current && hash_equals($current['sha256'], $record['sha256']) ? $bytes : null;
}

/** Select one published card grounded in the public catalog and visitor question. */
function profile_product_promotion_match_v1(PDO $pdo, array $profile, string $query, array $context): ?string
{
    $productIds = [];
    foreach ($context as $item) {
        if (preg_match('/\Aprofile:commerce:([1-9][0-9]*)\z/D', (string)($item['source'] ?? ''), $matches)) {
            $productIds[(int)$matches[1]] = true;
        }
    }
    if (!$productIds) return null;
    $terms = static function(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($words, static fn(string $word): bool =>
            mb_strlen($word) >= 2 && !in_array($word, ['a','an','and','the','your','from','for','of','in','to','with','by','is','this','that','on','or'], true))));
    };
    $queryTerms = $terms($query);
    $browse = preg_match('/\b(?:what (?:do|are) you (?:sell|selling)|(?:show|browse|list)\b.{0,40}\b(?:products|offers|promotions|catalog)|featured products)\b/iu', $query) === 1;
    try {
        $records = profile_product_promotion_records_v1($pdo, $profile);
    } catch (Throwable $error) {
        error_log('VP3 public promotion catalog unavailable: '.$error->getMessage());
        return null;
    }
    $match = null;
    $best = 0;
    foreach ($records as $key=>$record) {
        $product = $record['product'];
        if (!isset($productIds[(int)$product['id']])) continue;
        $titleTerms = $terms($product['title']);
        $overlap = count(array_intersect($queryTerms, $titleTerms));
        $score = $overlap > 0 ? ($overlap === count($titleTerms) ? 100 : 1) + $overlap : (int)$browse;
        if ($score <= $best) continue;
        $best = $score;
        $match = $key;
    }
    return $match;
}

/** Read only public card keys from internal message context. Never return context_json. */
function profile_product_promotion_chat_cards_v1(PDO $pdo, array $profile, int $conversationId, array $messages): array
{
    $bindings = $records = [];
    try {
        if ($conversationId > 0 && table_exists('agent_commerce_promotions_v1')) {
            $query = $pdo->prepare("SELECT id,SUBSTR(context_json,1,65537) context_json FROM (
                SELECT id,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='agent' ORDER BY id DESC LIMIT 120
            ) recent WHERE context_json LIKE ?");
            $query->execute([$conversationId, '%"product_promotion_key":%']);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strlen((string)$row['context_json']) > 65536) continue;
                $key = json_decode((string)$row['context_json'], true, 32)['product_promotion_key'] ?? null;
                if (profile_product_promotion_key_v1($key)) $bindings[(int)$row['id']] = $key;
            }
            if ($bindings) $records = profile_product_promotion_records_v1($pdo, $profile, array_values($bindings));
        }
    } catch (Throwable $error) {
        error_log('VP3 public promotion message cards unavailable: '.$error->getMessage());
    }
    foreach ($messages as &$message) {
        $key = $bindings[(int)($message['id'] ?? 0)] ?? null;
        if ($key !== null && isset($records[$key])) $message['promotion'] = $records[$key]['card'];
    }
    unset($message);
    return ['messages'=>$messages,'active_promotion_keys'=>array_keys($records)];
}
