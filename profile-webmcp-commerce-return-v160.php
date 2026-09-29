<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-commerce-v900.php';
require_once __DIR__.'/includes/profile-commerce-ops-v900.php';
require_once __DIR__.'/includes/profile-commerce-lifecycle-v1100.php';
require_once __DIR__.'/includes/profile-webmcp-commerce-v160.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

$pdo=db();
if(!$pdo||!agent_commerce_schema_ready_v800($pdo)){http_response_code(503);exit('Commerce is unavailable.');}

$grant=trim((string)($_GET['grant']??''));
try{
    if($grant==='')throw new RuntimeException('Commerce return grant is missing.');
    $decoded=agent_commerce_decrypt_v800($grant);
    $payload=json_decode($decoded,true);
    if(!is_array($payload)||($payload['v']??null)!==1||(int)($payload['exp']??0)<time())throw new RuntimeException('Commerce return grant expired.');
    $owner=(int)($payload['owner_user_id']??0);
    $username=profile_username_normalize((string)($payload['profile_username']??''));
    $orderId=(int)($payload['order_id']??0);
    $receipt=strtolower(trim((string)($payload['receipt_token']??'')));
    if($owner<1||$username===''||$orderId<1||!profile_commerce_receipt_token_valid_v1100($receipt))throw new RuntimeException('Commerce return grant is invalid.');

    $profile=profile_for_user($pdo,$owner,false);
    if(!$profile||profile_username_normalize((string)($profile['username']??''))!==$username)throw new RuntimeException('Commerce return profile is invalid.');
    $order=profile_commerce_order_for_owner_v900($pdo,$owner,$orderId);
    if(!$order)throw new RuntimeException('Commerce order was not found.');
    $meta=profile_commerce_order_metadata_v900($order);
    $stored=strtolower(trim((string)($meta['receipt_token_sha256']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$stored)||!hash_equals($stored,profile_commerce_receipt_token_hash_v1100($receipt)))throw new RuntimeException('Commerce receipt authority does not match.');

    $provider=strtolower(trim((string)($order['provider_snapshot']??'')));
    if($provider!==''){
        try{$order=agent_commerce_return_verify_v800($pdo,$order,$provider,$_GET);}
        catch(Throwable $verifyError){error_log('WebMCP commerce return verification pending: '.$verifyError->getMessage());}
    }
    if(function_exists('profile_conversion_commerce_order_v179'))profile_conversion_commerce_order_v179($pdo,$order);
    redirect(profile_commerce_customer_order_url_v1100($username,(string)$order['order_number'],$receipt));
}catch(Throwable $e){
    error_log('WebMCP commerce return failed: '.$e->getMessage());
    http_response_code(400);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Commerce return</title></head><body><main><h1>Payment verification is pending</h1><p>VP3 did not mark this order paid unless the payment provider verified it. Return to the seller profile or your secure receipt link.</p></main></body></html><?php
}
