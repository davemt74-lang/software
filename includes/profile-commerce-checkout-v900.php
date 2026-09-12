<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';

/**
 * Browser retry/idempotency guard for Profile Commerce checkout.
 *
 * The nonce is session-scoped and bound to one owner/product. Once the
 * canonical Phase 8 order is created, its ID is saved before provider checkout
 * begins. A retry therefore reuses that order and Phase 8's existing open
 * checkout attempt instead of creating a second purchase.
 */
function profile_commerce_checkout_nonce_v900(int $ownerUserId,int $productId): string
{
    if($ownerUserId<1||$productId<1)throw new RuntimeException('Checkout product is unavailable.');
    if(!isset($_SESSION['profile_commerce_checkout_intents'])||!is_array($_SESSION['profile_commerce_checkout_intents']))$_SESSION['profile_commerce_checkout_intents']=[];
    $now=time();
    foreach($_SESSION['profile_commerce_checkout_intents'] as $nonce=>$row){
        if(!is_array($row)||(int)($row['expires_at']??0)<$now)unset($_SESSION['profile_commerce_checkout_intents'][$nonce]);
    }
    if(count($_SESSION['profile_commerce_checkout_intents'])>40){
        uasort($_SESSION['profile_commerce_checkout_intents'],static fn($a,$b):int=>((int)($a['created_at']??0))<=>((int)($b['created_at']??0)));
        while(count($_SESSION['profile_commerce_checkout_intents'])>30)array_shift($_SESSION['profile_commerce_checkout_intents']);
    }
    $nonce=bin2hex(random_bytes(24));
    $_SESSION['profile_commerce_checkout_intents'][$nonce]=[
        'owner_user_id'=>$ownerUserId,
        'product_id'=>$productId,
        'order_id'=>0,
        'connection_id'=>0,
        'created_at'=>$now,
        'expires_at'=>$now+7200,
    ];
    return $nonce;
}

function profile_commerce_checkout_intent_v900(int $ownerUserId,int $productId,string $nonce): array
{
    $nonce=trim($nonce);$row=$_SESSION['profile_commerce_checkout_intents'][$nonce]??null;
    if($nonce===''||!is_array($row)||(int)($row['owner_user_id']??0)!==$ownerUserId||(int)($row['product_id']??0)!==$productId||(int)($row['expires_at']??0)<time())throw new RuntimeException('Checkout request expired. Refresh the product page and try again.');
    return $row;
}

function profile_commerce_checkout_intent_save_v900(string $nonce,array $row): void
{
    if(!isset($_SESSION['profile_commerce_checkout_intents'])||!is_array($_SESSION['profile_commerce_checkout_intents']))$_SESSION['profile_commerce_checkout_intents']=[];
    $_SESSION['profile_commerce_checkout_intents'][$nonce]=$row;
}

function profile_commerce_create_checkout_idempotent_v900(PDO $pdo,array $profile,array $projectedProduct,int $connectionId,string $payerEmail,string $nonce): array
{
    $owner=(int)($profile['user_id']??0);$productId=(int)($projectedProduct['id']??0);
    $product=profile_commerce_owner_product_v900($pdo,$owner,$productId);
    if(!$product||profile_commerce_visibility_v900($product)!=='public'||empty($product['is_active']))throw new RuntimeException('This product is not available.');
    $intent=profile_commerce_checkout_intent_v900($owner,$productId,$nonce);
    $payerEmail=strtolower(trim($payerEmail));if($payerEmail!==''&&!filter_var($payerEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address for the receipt.');

    $connections=profile_commerce_checkout_connections_v900($pdo,$product);
    $selected=null;
    $storedConnection=max(0,(int)($intent['connection_id']??0));
    $wanted=$storedConnection>0?$storedConnection:$connectionId;
    foreach($connections as $connection)if((int)$connection['id']===$wanted){$selected=$connection;break;}
    if(!$selected)throw new RuntimeException('Choose an available payment provider.');

    $order=null;$storedOrder=max(0,(int)($intent['order_id']??0));
    if($storedOrder>0){
        $candidate=agent_commerce_order_v800($pdo,$storedOrder);
        if(!$candidate||(int)($candidate['owner_user_id']??0)!==$owner)throw new RuntimeException('Checkout order could not be resumed safely.');
        $items=agent_commerce_order_items_v800($pdo,$storedOrder);
        if(count($items)!==1||(int)($items[0]['product_id']??0)!==$productId)throw new RuntimeException('Checkout order does not match this product.');
        if(!in_array((string)($candidate['payment_status']??''),['awaiting_payment','partially_paid'],true))throw new RuntimeException('This checkout is no longer awaiting payment. Refresh the product page to start another purchase.');
        $order=$candidate;
    }else{
        $order=agent_commerce_create_order_v800($pdo,$product,[
            'connection_id'=>(int)$selected['id'],
            'payer_email'=>$payerEmail,
            'metadata'=>[
                'source'=>'profile_commerce_v900',
                'profile_username'=>(string)$profile['username'],
                'profile_product_slug'=>(string)$projectedProduct['slug'],
                'checkout_nonce_sha256'=>hash('sha256',$nonce),
            ],
        ]);
        $intent['order_id']=(int)$order['id'];
        $intent['connection_id']=(int)$selected['id'];
        profile_commerce_checkout_intent_save_v900($nonce,$intent);
    }

    $return=agent_commerce_absolute_url_v800('/'.rawurlencode((string)$profile['username']).'?commerce=return&order='.(int)$order['id']);
    $cancel=agent_commerce_absolute_url_v800('/'.rawurlencode((string)$profile['username']).'/product/'.rawurlencode((string)$projectedProduct['slug']).'?commerce=cancelled');
    $attempt=agent_commerce_create_checkout_v800($pdo,$order,(int)$selected['id'],$return,$cancel);
    $intent['order_id']=(int)$order['id'];$intent['connection_id']=(int)$selected['id'];$intent['attempt_id']=(int)($attempt['id']??0);
    profile_commerce_checkout_intent_save_v900($nonce,$intent);
    return ['order'=>$order,'attempt'=>$attempt,'checkout_url'=>(string)($attempt['checkout_url']??'')];
}
