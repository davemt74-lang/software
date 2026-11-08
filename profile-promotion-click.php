<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-product-promotion-v1.php';
require_once __DIR__.'/includes/profile-promotion-click-v1.php';
require_once __DIR__.'/includes/profile-promotion-attribution-v1.php';

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','HEAD'],true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$key = $_GET['key'] ?? null;
$pdo = db();
$record = null;
if ($pdo && profile_product_promotion_key_v1($key)) {
    try {
        $record = profile_product_promotion_key_record_v1($pdo,$key);
    } catch (Throwable $error) {
        error_log('VP3 promotion link lookup failed ('.get_class($error).').');
    }
}
if (!$record) {
    http_response_code(404);
    exit;
}

$destination = (string)$record['card']['product_url'];
// The canonical resolver owns the destination. This last check keeps the
// redirect local even if the installation's base path is misconfigured.
if (!preg_match('~\A/(?!/)[^\\\\\x00-\x20\x7f?#]+\z~D',$destination)) {
    http_response_code(404);
    exit;
}
$claim = profile_product_promotion_click_claim_v1($key,$_GET);
$purpose = strtolower(implode(' ',[(string)($_SERVER['HTTP_PURPOSE']??''),(string)($_SERVER['HTTP_SEC_PURPOSE']??''),(string)($_SERVER['HTTP_X_MOZ']??'')]));
if ($method==='GET' && $claim && !str_contains($purpose,'prefetch') && !str_contains($purpose,'prerender')) {
    try {
        $owner = (int)$record['profile']['user_id'];
        $viewer = current_user();
        $digest = @hash_file('sha256',$record['path']);
        if ((int)($viewer['id']??0)!==$owner && is_string($digest) && hash_equals($record['sha256'],$digest)
            && profile_promotion_attribution_record_click_v1($pdo,$owner,$key,$claim['placement'],$claim['click_id'])) {
            profile_product_promotion_click_remember_v1($claim['click_id'],$owner,(int)$record['card']['product_id'],$claim['conversation_id']);
            $destination .= '?promotion_click='.$claim['click_id'];
        }
    } catch (Throwable $error) {
        error_log('VP3 promotion click recording failed ('.get_class($error).').');
    }
}
header('Location: '.$destination,true,303);
