<?php
declare(strict_types=1);

require_once __DIR__.'/notifications.php';

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
