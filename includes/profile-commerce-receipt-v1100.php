<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';

const VP3_PROFILE_COMMERCE_RECEIPT_V1100='profile-commerce-receipt-v1100-20260912';

function profile_commerce_receipt_token_v1100(): string
{
    return bin2hex(random_bytes(32));
}

function profile_commerce_receipt_token_valid_v1100(string $token): bool
{
    return (bool)preg_match('/\A[a-f0-9]{64}\z/',strtolower(trim($token)));
}

function profile_commerce_receipt_token_hash_v1100(string $token): string
{
    $token=strtolower(trim($token));
    if(!profile_commerce_receipt_token_valid_v1100($token))throw new RuntimeException('Receipt link is invalid.');
    return hash('sha256',$token);
}

function profile_commerce_customer_order_url_v1100(string $username,string $orderNumber,string $token): string
{
    if(!profile_commerce_receipt_token_valid_v1100($token))throw new RuntimeException('Receipt link is invalid.');
    $orderNumber=trim($orderNumber);if($orderNumber===''||!preg_match('/\A[A-Za-z0-9._-]{1,80}\z/',$orderNumber))throw new RuntimeException('Order number is invalid.');
    return url('/'.rawurlencode(profile_username_normalize($username)).'/order/'.rawurlencode($orderNumber).'/'.rawurlencode(strtolower($token)));
}
