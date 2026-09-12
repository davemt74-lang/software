<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-ops-v900.php';
require_once __DIR__.'/profile-commerce-receipt-v1100.php';

/** VP3 Profile Commerce v11.00 — customer-safe order lifecycle projection. */
const VP3_PROFILE_COMMERCE_LIFECYCLE_V1100='profile-commerce-lifecycle-v1100-20260912';

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

function profile_commerce_customer_refund_request_v1100(array $order): ?array
{
    $meta=profile_commerce_order_metadata_v900($order);$request=$meta['customer_refund_request']??null;
    if(!is_array($request))return null;
    $status=strtolower(trim((string)($request['status']??'')));if(!in_array($status,['pending','seller_refund_submitted','declined'],true))return null;
    return [
        'request_id'=>(string)($request['request_id']??''),
        'status'=>$status,
        'reason'=>(string)($request['reason']??''),
        'requested_amount_cents'=>max(0,(int)($request['requested_amount_cents']??0)),
        'requested_at'=>(string)($request['requested_at']??''),
        'resolved_at'=>(string)($request['resolved_at']??''),
    ];
}

function profile_commerce_customer_refund_request_create_v1100(PDO $pdo,int $ownerUserId,string $orderNumber,string $token,string $reason): array
{
    $order=profile_commerce_customer_order_v1100($pdo,$ownerUserId,$orderNumber,$token);if(!$order)throw new RuntimeException('Order not found.');
    if((string)($order['fulfillment_type']??'')==='appointment')throw new RuntimeException('Appointment refunds are managed through the appointment lifecycle.');
    $reason=mb_strimwidth(trim($reason),0,500,'');if(mb_strlen($reason)<3)throw new RuntimeException('Tell the seller why you are requesting a refund.');
    $remaining=max(0,(int)($order['amount_paid_cents']??0)-(int)($order['amount_refunded_cents']??0));if($remaining<1)throw new RuntimeException('This order has no refundable balance.');
    if(!in_array((string)($order['payment_status']??''),['paid','partially_refunded'],true))throw new RuntimeException('A refund can be requested only after payment is verified.');

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([(int)$order['id'],$ownerUserId]);$locked=$stmt->fetch();if(!$locked||!profile_commerce_order_is_profile_v900($locked))throw new RuntimeException('Order not found.');
        $meta=profile_commerce_order_metadata_v900($locked);$stored=strtolower(trim((string)($meta['receipt_token_sha256']??'')));if(!preg_match('/\A[a-f0-9]{64}\z/',$stored)||!hash_equals($stored,profile_commerce_receipt_token_hash_v1100($token)))throw new RuntimeException('Order not found.');
        $remaining=max(0,(int)$locked['amount_paid_cents']-(int)$locked['amount_refunded_cents']);if($remaining<1)throw new RuntimeException('This order has no refundable balance.');
        $existing=profile_commerce_customer_refund_request_v1100($locked);if($existing&&$existing['status']==='pending'){$pdo->commit();return profile_commerce_customer_order_v1100($pdo,$ownerUserId,$orderNumber,$token)?:$order;}if($existing&&$existing['status']==='seller_refund_submitted')throw new RuntimeException('A seller-approved refund is still being processed.');
        $request=['request_id'=>bin2hex(random_bytes(16)),'status'=>'pending','reason'=>$reason,'requested_amount_cents'=>$remaining,'requested_at'=>gmdate('c'),'resolved_at'=>''];$meta['customer_refund_request']=$request;
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$locked['id'],$ownerUserId]);
        agent_commerce_audit_v800($pdo,(int)$locked['id'],$ownerUserId,(int)($locked['workspace_owner_user_id']??0)?:null,'customer',null,null,'customer_refund_requested',(string)$locked['payment_status'],(string)$locked['payment_status'],$remaining,['request_id'=>$request['request_id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return profile_commerce_customer_order_v1100($pdo,$ownerUserId,$orderNumber,$token)?:throw new RuntimeException('Order could not be reloaded.');
}

function profile_commerce_owner_refund_request_set_v1100(PDO $pdo,int $ownerUserId,int $orderId,string $status): array
{
    if(!in_array($status,['seller_refund_submitted','declined'],true))throw new RuntimeException('Invalid refund request state.');
    $order=profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId);if(!$order)throw new RuntimeException('Profile Commerce order not found.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$orderId,$ownerUserId]);$locked=$stmt->fetch();if(!$locked||!profile_commerce_order_is_profile_v900($locked))throw new RuntimeException('Profile Commerce order not found.');
        $meta=profile_commerce_order_metadata_v900($locked);$request=$meta['customer_refund_request']??null;if(!is_array($request)||(string)($request['status']??'')!=='pending')throw new RuntimeException('There is no pending customer refund request.');
        $request['status']=$status;$request['resolved_at']=gmdate('c');$meta['customer_refund_request']=$request;
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$orderId,$ownerUserId]);
        agent_commerce_audit_v800($pdo,$orderId,$ownerUserId,(int)($locked['workspace_owner_user_id']??0)?:null,'user',$ownerUserId,null,$status==='declined'?'customer_refund_declined':'customer_refund_actioned',(string)$locked['payment_status'],(string)$locked['payment_status'],max(0,(int)($request['requested_amount_cents']??0)),['request_id'=>(string)($request['request_id']??'')]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return profile_commerce_order_for_owner_v900($pdo,$ownerUserId,$orderId)?:throw new RuntimeException('Profile Commerce order could not be reloaded.');
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
        'refund_request'=>profile_commerce_customer_refund_request_v1100($order),
        'refundable_cents'=>max(0,(int)($order['amount_paid_cents']??0)-(int)($order['amount_refunded_cents']??0)),
        'currency'=>$currency,
        'total_label'=>agent_commerce_money_v800((int)($order['total_cents']??0),$currency),
        'paid_label'=>agent_commerce_money_v800((int)($order['amount_paid_cents']??0),$currency),
        'refunded_label'=>agent_commerce_money_v800((int)($order['amount_refunded_cents']??0),$currency),
        'created_at'=>(string)($order['created_at']??''),
        'updated_at'=>(string)($order['updated_at']??''),
        'items'=>$items,
    ];
}
