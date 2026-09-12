<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-ops-v900.php';

/** VP3 Profile Commerce v11.00 — customer-safe order lifecycle projection. */
const VP3_PROFILE_COMMERCE_LIFECYCLE_V1100='profile-commerce-lifecycle-v1100-20260912';

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

function profile_commerce_customer_order_v1100(PDO $pdo,int $ownerUserId,string $orderNumber,string $token): ?array
{
    if($ownerUserId<1||!profile_commerce_receipt_token_valid_v1100($token))return null;
    $orderNumber=trim($orderNumber);if($orderNumber===''||!preg_match('/\A[A-Za-z0-9._-]{1,80}\z/',$orderNumber))return null;
    $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? AND order_number=? LIMIT 1');
    $stmt->execute([$ownerUserId,$orderNumber]);$order=$stmt->fetch()?:null;if(!$order||!profile_commerce_order_is_profile_v900($order))return null;
    $meta=profile_commerce_order_metadata_v900($order);$stored=strtolower(trim((string)($meta['receipt_token_sha256']??'')));
    if(!preg_match('/\A[a-f0-9]{64}\z/',$stored)||!hash_equals($stored,profile_commerce_receipt_token_hash_v1100($token)))return null;
    $order['items']=agent_commerce_order_items_v800($pdo,(int)$order['id']);
    return $order;
}

function profile_commerce_customer_status_v1100(array $order): array
{
    $payment=strtolower(trim((string)($order['payment_status']??'awaiting_payment')));
    $fulfillment=profile_commerce_fulfillment_state_v900($order);
    if($payment==='refunded')return ['key'=>'refunded','label'=>'Refunded','message'=>'This order has been refunded.'];
    if($payment==='partially_refunded')return ['key'=>'partially_refunded','label'=>'Partially refunded','message'=>'Part of this order has been refunded.'];
    if($payment!=='paid')return ['key'=>'payment_pending','label'=>'Payment pending','message'=>'Payment has not been fully verified yet.'];
    if($fulfillment==='fulfilled')return ['key'=>'fulfilled','label'=>'Fulfilled','message'=>'The seller has marked this order fulfilled.'];
    if($fulfillment==='processing')return ['key'=>'processing','label'=>'Processing','message'=>'Payment is verified and the seller is processing your order.'];
    return ['key'=>'paid','label'=>'Paid','message'=>'Payment is verified. Fulfillment has not started yet.'];
}

function profile_commerce_customer_projection_v1100(array $order): array
{
    $currency=strtolower((string)($order['currency']??'usd'));$status=profile_commerce_customer_status_v1100($order);$items=[];
    foreach((array)($order['items']??[]) as $item){
        if((string)($item['fulfillment_type']??'')==='appointment')continue;
        $items[]=[
            'title'=>(string)($item['title_snapshot']??'Item'),
            'quantity'=>max(1,(int)($item['quantity']??1)),
            'fulfillment_type'=>(string)($item['fulfillment_type']??'other'),
            'fulfillment_status'=>(string)($item['fulfillment_status']??'pending'),
        ];
    }
    return [
        'order_number'=>(string)($order['order_number']??''),
        'payment_status'=>(string)($order['payment_status']??'awaiting_payment'),
        'fulfillment_status'=>profile_commerce_fulfillment_state_v900($order),
        'status'=>$status,
        'currency'=>$currency,
        'total_label'=>agent_commerce_money_v800((int)($order['total_cents']??0),$currency),
        'paid_label'=>agent_commerce_money_v800((int)($order['amount_paid_cents']??0),$currency),
        'refunded_label'=>agent_commerce_money_v800((int)($order['amount_refunded_cents']??0),$currency),
        'created_at'=>(string)($order['created_at']??''),
        'updated_at'=>(string)($order['updated_at']??''),
        'items'=>$items,
    ];
}
