<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-lifecycle-v1100.php';

function profile_commerce_refund_requests_for_owner_v1100(PDO $pdo,int $ownerUserId,int $limit=100): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(200,$limit));$out=[];
    foreach(profile_commerce_orders_for_owner_v900($pdo,$ownerUserId,300) as $order){
        $request=profile_commerce_customer_refund_request_v1100($order);if(!$request)continue;
        $order['refund_request']=$request;$out[]=$order;if(count($out)>=$limit)break;
    }
    usort($out,static fn(array $a,array $b):int=>strcmp((string)($b['refund_request']['requested_at']??''),(string)($a['refund_request']['requested_at']??'')));
    return $out;
}

function profile_commerce_owner_approve_refund_request_v1100(PDO $pdo,int $ownerUserId,int $orderId,array $actor): array
{
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    $request=profile_commerce_customer_refund_request_v1100($order);if(!$request||$request['status']!=='pending')throw new RuntimeException('There is no pending customer refund request.');
    $remaining=max(0,(int)$order['amount_paid_cents']-(int)$order['amount_refunded_cents']);$amount=min($remaining,max(0,(int)$request['requested_amount_cents']));if($amount<1)throw new RuntimeException('This order has no refundable balance.');
    $reason=trim((string)$request['reason']);if($reason==='')$reason='Customer refund request';
    profile_commerce_refund_order_v900($pdo,$ownerUserId,$orderId,$amount,mb_strimwidth('Customer request: '.$reason,0,500,''),$actor,true);
    return profile_commerce_owner_refund_request_set_v1100($pdo,$ownerUserId,$orderId,'seller_refund_submitted');
}

function profile_commerce_owner_decline_refund_request_v1100(PDO $pdo,int $ownerUserId,int $orderId): array
{
    return profile_commerce_owner_refund_request_set_v1100($pdo,$ownerUserId,$orderId,'declined');
}
