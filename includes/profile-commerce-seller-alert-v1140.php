<?php
declare(strict_types=1);

require_once __DIR__.'/notifications.php';
require_once __DIR__.'/profile-commerce-ops-v900.php';

/** VP3 Profile Commerce v11.40 — seller attention for customer refund requests. */
const VP3_PROFILE_COMMERCE_SELLER_ALERT_V1140='profile-commerce-seller-alert-v1140-20260912';

function profile_commerce_seller_refund_alert_v1140(array $order,array $request): void
{
    $ownerUserId=(int)($order['owner_user_id']??0);
    $orderId=(int)($order['id']??0);
    $orderNumber=trim((string)($order['order_number']??''));
    $requestId=strtolower(trim((string)($request['request_id']??'')));
    if($ownerUserId<1||$orderId<1||$orderNumber===''||!preg_match('/\A[a-f0-9]{32}\z/',$requestId))return;

    $amount=max(0,(int)($request['requested_amount_cents']??0));
    $currency=strtolower(trim((string)($order['currency']??'usd')));
    $amountLabel=function_exists('agent_commerce_money_v800')?agent_commerce_money_v800($amount,$currency):number_format($amount/100,2).' '.strtoupper($currency);

    create_notification(
        $ownerUserId,
        'profile_commerce_refund_approval_request',
        'Customer refund request',
        'Order '.$orderNumber.' has a customer refund request for '.$amountLabel.'. Review it before any money moves.',
        '/profile-commerce-refund-requests.php',
        'profile_commerce_refund_request:'.$requestId,
        $orderId
    );
}

/** The payment projection and this deduplicated owner alert commit together. */
function profile_commerce_seller_order_alert_pa5(PDO $pdo,array $order,array $item): void
{
    if(!$pdo->inTransaction()||db()!==$pdo||!table_exists('notifications')){
        throw new RuntimeException('Commerce notifications are temporarily unavailable.',503);
    }
    $owner=(int)$order['owner_user_id'];
    $orderId=(int)$order['id'];
    $number=(string)$order['order_number'];
    $currency=(string)$order['currency'];
    $paid=profile_commerce_verified_conversation_payment_pa5($pdo,$order,$item);
    $seller=$pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
    $seller->execute([$owner]);
    if(!$seller->fetchColumn())throw new RuntimeException('Profile order seller is unavailable.',409);

    $needsReview=(string)$order['order_status']==='payment_review';
    $title=$needsReview?'Profile order needs review':'New Profile order';
    $body='Order '.$number.' received '.agent_commerce_money_v800($paid,$currency).' in verified payment.';
    if($needsReview)$body.=' Review the payment before fulfillment.';
    if((string)$order['payment_status']==='refunded')$body.=' The payment has since been refunded.';
    if((string)$order['payment_status']==='partially_refunded')$body.=' Part of the payment has since been refunded.';
    $type='profile_commerce_order_paid';$source='profile_commerce_order';
    notification_create_locked($owner,$type,$title,$body,url('/profile-commerce-products.php#orders'),$source,$orderId);
    $saved=$pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND type=? AND source_type=? AND source_id=? LIMIT 1');
    $saved->execute([$owner,$type,$source,$orderId]);
    if(!$saved->fetchColumn())throw new RuntimeException('Commerce order notification was not saved.',503);
}
