<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-commerce-checkout-v900.php';
require_once __DIR__.'/includes/profile-commerce-ops-v900.php';
require_once __DIR__.'/includes/profile-commerce-lifecycle-v1100.php';

$pdo=db();if(!$pdo||!profile_agent_schema_ready($pdo)||!agent_commerce_schema_ready_v800($pdo)){http_response_code(503);exit('Profile Commerce is not ready.');}
$username=profile_username_normalize((string)($_GET['username']??''));$orderId=max(0,(int)($_GET['order']??0));$nonce=trim((string)($_GET['intent']??''));
$profile=profile_by_username($pdo,$username);if(!$profile||empty($profile['is_active'])){http_response_code(404);exit('Profile not found.');}
$owner=(int)$profile['user_id'];$order=profile_commerce_order_for_owner_v900($pdo,$owner,$orderId);if(!$order){http_response_code(404);exit('Checkout order not found.');}
$items=(array)($order['items']??[]);$productId=(int)($items[0]['product_id']??0);if($productId<1){http_response_code(400);exit('Checkout product lineage is unavailable.');}
$receiptToken='';
try{
    $intent=profile_commerce_checkout_intent_v900($owner,$productId,$nonce);
    if((int)($intent['order_id']??0)!==$orderId)throw new RuntimeException('Checkout return does not match this order.');
    $receiptToken=strtolower(trim((string)($intent['receipt_token']??'')));if(!profile_commerce_receipt_token_valid_v1100($receiptToken))$receiptToken='';
    $provider=strtolower(trim((string)($order['provider_snapshot']??'')));if($provider==='')throw new RuntimeException('Checkout provider lineage is unavailable.');
    $verified=agent_commerce_return_verify_v800($pdo,$order,$provider,$_GET);
    $status=(string)($verified['payment_status']??'awaiting_payment');
    if($status==='paid'){$notice=['kind'=>'verified','headline'=>'Payment verified.','message'=>'Your payment is confirmed in the canonical Commerce order.'];}
    elseif($status==='partially_paid'){$notice=['kind'=>'verified','headline'=>'Deposit verified.','message'=>'Your deposit is confirmed in the canonical Commerce order.'];}
    else{$notice=['kind'=>'pending','headline'=>'Payment return received.','message'=>'The provider has not confirmed payment yet. VP3 will only mark the order paid after verification.'];}
}catch(Throwable $e){
    error_log('VP3 Profile Commerce return verification failed: '.$e->getMessage());
    $notice=['kind'=>'pending','headline'=>'Payment verification is still pending.','message'=>'VP3 did not mark this order paid because provider verification was not complete.'];
}
if($receiptToken!==''&&profile_commerce_customer_order_v1100($pdo,$owner,(string)$order['order_number'],$receiptToken)){
    if(($notice['kind']??'')==='verified')profile_commerce_checkout_intent_forget_v900($nonce);
    redirect(profile_commerce_customer_order_url_v1100($username,(string)$order['order_number'],$receiptToken));
}
if(!isset($_SESSION['profile_commerce_return_notices'])||!is_array($_SESSION['profile_commerce_return_notices']))$_SESSION['profile_commerce_return_notices']=[];
$_SESSION['profile_commerce_return_notices'][(string)$owner]=array_merge($notice,['expires_at'=>time()+300]);
if(!empty($profile['is_public']))redirect(profile_public_url($username).'?commerce=return');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Commerce payment return</title><style>body{margin:0;background:#f6f5f2;color:#171816;font:15px/1.5 system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}.box{max-width:560px;margin:24px;background:#fff;border:1px solid #e0e2dc;border-radius:22px;padding:28px}.box h1{margin:0 0 8px;font-size:24px}.box p{color:#5f625c}.box a{color:#171816}</style></head><body><main class="box"><h1><?=e((string)$notice['headline'])?></h1><p><?=e((string)$notice['message'])?></p><a href="<?=e(url('/'))?>">Return home</a></main></body></html>