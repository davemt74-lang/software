<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-product-photo-v1.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$username = $_GET['username'] ?? null;
$slug = $_GET['product'] ?? null;
$listing = $_GET['listing'] ?? null;
$pdo = db();
$image = null;
if ($pdo && is_string($username) && is_string($slug) && is_string($listing)
    && preg_match('/\A[1-9][0-9]{0,18}\z/', $listing) && (string)(int)$listing === $listing) {
    try {
        $image = profile_product_photo_image_v1($pdo, profile_username_normalize($username), profile_commerce_slug_v900($slug), (int)$listing);
    } catch (Throwable $error) {
        error_log('VP3 public product photo unavailable: '.$error->getMessage());
    }
}
if (!$image) {
    http_response_code(404);
    exit;
}
header('Content-Type: '.$image['mime']);
header('Content-Disposition: inline; filename="product-photo.'.media_studio_extension_for_mime($image['mime']).'"');
header('Content-Length: '.strlen($image['bytes']));
if ($method === 'GET') echo $image['bytes'];
