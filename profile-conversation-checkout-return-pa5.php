<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-conversation-checkout-pa5.php';

header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$pdo=db();
$username=$_GET['username']??null;
if(!$pdo||!is_string($username)||strlen($username)>60){
    http_response_code(404);
    exit('Profile checkout is unavailable.');
}
$profile=profile_by_username($pdo,profile_username_normalize($username));
if(!$profile||empty($profile['is_active'])||empty($profile['is_public'])){
    http_response_code(404);
    exit('Profile checkout is unavailable.');
}

$destination=url('/'.rawurlencode((string)$profile['username']));
try{
    vp3_profile_checkout_require_pa5($pdo);
    $reviewId=vp3_profile_checkout_token_pa5($_GET['review']??null);
    $ctx=vp3_profile_agent_public_context_v110($pdo,$profile,current_user(),true);
    $ctx=vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,0);
    $q=$pdo->prepare('SELECT conversation_id FROM profile_conversation_checkouts_pa5
        WHERE owner_user_id=? AND credential_hash=? AND profile_agent_id=? AND profile_session_id=? AND visitor_user_id=? AND review_id=?');
    $q->execute([...vp3_profile_checkout_buyer_key_pa5($ctx),$reviewId]);
    $cid=(int)$q->fetchColumn();
    if($cid<1)redirect($destination);
    $ctx=vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid);
    $row=vp3_profile_checkout_review_row_pa5($pdo,$ctx,$cid,$reviewId);
    $destination.='?profile_checkout='.$cid;
    if((int)$row['order_id']<1)redirect($destination);
    $pdo->prepare('UPDATE profile_conversation_checkouts_pa5 SET returned_at_unix=? WHERE id=?')
        ->execute([time(),(int)$row['id']]);
    if(($_GET['cancel']??null)==='1')redirect($destination);

    $order=vp3_profile_checkout_order_pa5($pdo,$profile,$row,vp3_profile_checkout_secret_pa5($row));
    if(in_array($order['payment_status'],['paid','partially_refunded','refunded'],true))redirect($destination);
    $attempt=vp3_profile_checkout_attempt_pa5($pdo,$row);
    if(!$attempt)redirect($destination);
    $provider=(string)$order['provider_snapshot'];
    $connection=agent_commerce_connection_v800($pdo,(int)$row['connection_id'],(int)$profile['user_id']);
    if(!$connection||$connection['status']!=='connected'
        ||!hash_equals((string)$row['merchant_fingerprint'],agent_commerce_checkout_merchant_fingerprint_pa5($connection))){
        redirect($destination);
    }
    $selector=match($provider){
        'stripe'=>['session_id'=>$_GET['session_id']??null],
        'paypal'=>['token'=>$_GET['token']??null],
        'square'=>[],
        default=>null,
    };
    if($selector===null)redirect($destination);
    if($selector){
        $value=reset($selector);
        if(!is_string($value)||$value===''||!hash_equals((string)$attempt['external_session_id'],$value))redirect($destination);
    }
    // Provider verification may take time. Checkout was already authorized and
    // this endpoint never returns order data or writes a browser session.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    agent_commerce_return_verify_v800($pdo,$order,$provider,$selector);
}catch(VP3ProfileCheckoutException|VP3ProfileAgentPublicException $e){
    // A browser without this conversation's credentials gets the public Profile.
}catch(Throwable $e){
    error_log('Profile checkout return verification pending ('.get_class($e).').');
}
redirect($destination);
