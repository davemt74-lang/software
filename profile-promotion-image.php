<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-product-promotion-v1.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$key = $_GET['key'] ?? null;
$pdo = db();
$bytes = null;
if ($pdo && profile_product_promotion_key_v1($key)) {
    try {
        $bytes = profile_product_promotion_image_v1($pdo, $key);
    } catch (Throwable $error) {
        error_log('VP3 public promotion image unavailable: '.$error->getMessage());
    }
}
if ($bytes === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="promotion.png"');
header('Content-Length: '.strlen($bytes));
if ($method === 'GET') echo $bytes;
