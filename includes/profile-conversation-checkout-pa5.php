<?php
declare(strict_types=1);

/** Buyer-reviewed checkout cards for native public Profile conversations. */
require_once __DIR__.'/agent-commerce-v800.php';
require_once __DIR__.'/profile-commerce-checkout-v900.php';
require_once __DIR__.'/profile-commerce-lifecycle-v1100.php';
require_once __DIR__.'/profile-commerce-delivery-file-v1130.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-agent-public-service-v110.php';

const VP3_PROFILE_CHECKOUT_TTL_PA5=600;
const VP3_PROFILE_CHECKOUT_MAX_QUANTITY_PA5=99;
const VP3_PROFILE_CHECKOUT_MAX_TOTAL_PA5=VP3_PROFILE_COMMERCE_CHECKOUT_MAX_TOTAL_V900;

final class VP3ProfileCheckoutException extends RuntimeException
{
    public function __construct(public readonly string $publicCode,string $message,public readonly int $httpStatus=409)
    {
        parent::__construct($message,$httpStatus);
    }
}

function vp3_profile_checkout_ensure_schema_pa5(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Profile checkout schema cannot be upgraded inside a transaction.');
    $sqlite=agent_commerce_sqlite_pa2($pdo);
    $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $engine=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_conversation_checkouts_pa5 (
        id $id,
        owner_user_id BIGINT NOT NULL,
        profile_agent_id BIGINT NOT NULL,
        profile_session_id BIGINT NOT NULL,
        visitor_user_id BIGINT NOT NULL,
        conversation_id BIGINT NOT NULL,
        credential_hash CHAR(64) NOT NULL,
        request_id CHAR(32) NOT NULL,
        request_hash CHAR(64) NOT NULL,
        review_id CHAR(32) NOT NULL UNIQUE,
        product_id BIGINT NOT NULL,
        product_snapshot_hash CHAR(64) NOT NULL,
        connection_id BIGINT NOT NULL,
        merchant_fingerprint CHAR(64) NOT NULL,
        review_json TEXT NOT NULL,
        buyer_ciphertext TEXT NOT NULL,
        expires_at_unix BIGINT NOT NULL,
        order_id BIGINT NULL UNIQUE,
        returned_at_unix BIGINT NOT NULL DEFAULT 0,
        created_at_unix BIGINT NOT NULL,
        UNIQUE (owner_user_id,credential_hash,request_id)
    )".$engine);
    $name='idx_profile_checkout_pa5_thread';
    $q=$pdo->prepare($sqlite?"SELECT name FROM sqlite_master WHERE type='index' AND name=?":
        "SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE()
         AND TABLE_NAME='profile_conversation_checkouts_pa5' AND INDEX_NAME=?");
    $q->execute([$name]);
    if(!$q->fetchColumn())$pdo->exec("CREATE INDEX $name ON profile_conversation_checkouts_pa5 (owner_user_id,conversation_id,id)");
}

function vp3_profile_checkout_schema_ready_pa5(PDO $pdo): bool
{
    try{
        return $pdo->query('SELECT id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,conversation_id,
            credential_hash,request_id,request_hash,review_id,product_id,product_snapshot_hash,connection_id,merchant_fingerprint,
            review_json,buyer_ciphertext,expires_at_unix,order_id,returned_at_unix,created_at_unix FROM profile_conversation_checkouts_pa5 WHERE 1=0')!==false;
    }catch(PDOException $e){
        return false;
    }
}

function vp3_profile_checkout_require_pa5(PDO $pdo): void
{
    if(!vp3_profile_checkout_schema_ready_pa5($pdo)||!agent_commerce_schema_ready_v800($pdo)){
        throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_NOT_READY','Profile checkout is unavailable on this deployment.',503);
    }
}

function vp3_profile_checkout_authorize_pa5(PDO $pdo,array $profile,array $ctx,int $cid,bool $lock=false): array
{
    if(($ctx['conversation_authority']['transport']??'')!=='native'
        ||(int)($ctx['owner_user_id']??0)!==(int)($profile['user_id']??0)
        ||(string)($ctx['profile']['username']??'')!==(string)($profile['username']??'')){
        vp3_profile_agent_public_unavailable_v1();
    }
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,$lock);
    if($cid>0)vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,$lock);
    return $ctx;
}

function vp3_profile_checkout_product_eligible_pa5(array $product): bool
{
    return profile_commerce_checkout_product_eligible_v900($product);
}

function vp3_profile_checkout_connection_eligible_pa5(array $connection,array $product): bool
{
    return profile_commerce_checkout_connection_eligible_v900($connection,$product);
}

function vp3_profile_checkout_provider_pa5(array $connection): array
{
    return ['connection_id'=>(int)$connection['id'],'provider'=>(string)$connection['provider'],
        'provider_label'=>agent_commerce_provider_label_v800((string)$connection['provider'])];
}

function vp3_profile_checkout_catalog_pa5(PDO $pdo,array $profile): array
{
    if(!vp3_profile_checkout_schema_ready_pa5($pdo))return ['products'=>[]];
    $products=profile_commerce_products_for_profile_v900($pdo,$profile,true,200);
    $slugCounts=array_count_values(array_column($products,'slug'));
    $out=[];
    foreach($products as $projected){
        if($slugCounts[$projected['slug']]!==1)continue;
        $product=profile_commerce_owner_product_v900($pdo,(int)$profile['user_id'],(int)$projected['id']);
        if(!$product||!vp3_profile_checkout_product_eligible_pa5($product)
            ||profile_commerce_public_slug_v900($product)!==$projected['slug'])continue;
        $connections=array_filter(profile_commerce_checkout_connections_v900($pdo,$product),
            static fn(array $connection):bool=>vp3_profile_checkout_connection_eligible_pa5($connection,$product));
        if(!$connections)continue;
        $projected=profile_commerce_product_projection_v900($product,(string)$profile['username']);
        $out[]=['product_id'=>(int)$projected['id'],'slug'=>$projected['slug'],'title'=>$projected['title'],
            'description'=>$projected['description'],'price_cents'=>$projected['price_cents'],
            'price_label'=>$projected['price_label'],'currency'=>$projected['currency'],
            'fulfillment_type'=>$projected['fulfillment_type'],'product_url'=>$projected['product_url'],
            'payment_providers'=>array_values(array_map('vp3_profile_checkout_provider_pa5',$connections))];
        if(count($out)>=80)break;
    }
    return ['products'=>$out];
}

function vp3_profile_checkout_product_pa5(PDO $pdo,array $profile,string $slug): array
{
    $owner=(int)$profile['user_id'];
    $matches=array_filter(profile_commerce_products_for_profile_v900($pdo,$profile,true,200),
        static fn(array $product):bool=>$product['slug']===$slug);
    $projection=count($matches)===1?reset($matches):null;
    $product=$projection?profile_commerce_owner_product_v900($pdo,$owner,(int)$projection['id'],true):null;
    if(!$product||!vp3_profile_checkout_product_eligible_pa5($product)||profile_commerce_public_slug_v900($product)!==$slug){
        throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_PRODUCT_UNAVAILABLE','This product is unavailable in Profile chat.',409);
    }
    return $product;
}

function vp3_profile_checkout_product_snapshot_pa5(array $product): string
{
    return hash('sha256',json_encode([
        (int)$product['owner_user_id'],(int)($product['workspace_owner_user_id']??0),
        (string)$product['title'],(string)$product['description'],(string)$product['product_key'],
        (string)$product['product_type'],(string)$product['fulfillment_type'],(string)($product['binding_type']??''),
        (int)($product['binding_id']??0),(string)($product['updated_at']??''),(string)($product['metadata_json']??''),
        (int)$product['is_active'],agent_commerce_product_terms_v800($product),
    ],JSON_THROW_ON_ERROR));
}

function vp3_profile_checkout_select_connection_pa5(PDO $pdo,array $product,int $wanted): array
{
    $connections=profile_commerce_checkout_connections_v900($pdo,$product);
    $connections=array_values(array_filter($connections,static fn(array $connection):bool=>vp3_profile_checkout_connection_eligible_pa5($connection,$product)));
    if($wanted===0&&count($connections)===1)$wanted=(int)$connections[0]['id'];
    foreach($connections as $connection){
        if((int)$connection['id']!==$wanted)continue;
        $q=$pdo->prepare('SELECT * FROM agent_commerce_provider_connections_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $q->execute([$wanted]);
        $locked=$q->fetch(PDO::FETCH_ASSOC);
        if($locked&&vp3_profile_checkout_connection_eligible_pa5($locked,$product))return $locked;
        break;
    }
    throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_PROVIDER_UNAVAILABLE','Choose an available payment provider and review the offer again.');
}

function vp3_profile_checkout_buyer_key_pa5(array $ctx): array
{
    return [(int)$ctx['owner_user_id'],(string)$ctx['conversation_authority']['credential_hash'],
        (int)$ctx['agent_id'],(int)$ctx['session_id'],(int)$ctx['conversation_authority']['visitor_user_id']];
}

function vp3_profile_checkout_review_row_pa5(PDO $pdo,array $ctx,int $cid,string $reviewId,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM profile_conversation_checkouts_pa5
        WHERE owner_user_id=? AND credential_hash=? AND profile_agent_id=? AND profile_session_id=? AND visitor_user_id=?
        AND conversation_id=? AND review_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $q->execute([...vp3_profile_checkout_buyer_key_pa5($ctx),$cid,$reviewId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_REVIEW_NOT_FOUND','This purchase review could not be found.',404);
    return $row;
}

function vp3_profile_checkout_token_pa5(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value)){
        throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_INVALID','The purchase review request is invalid.',422);
    }
    return $value;
}

function vp3_profile_checkout_prepare_pa5(PDO $pdo,array $profile,array $ctx,int $cid,array $input): array
{
    vp3_profile_checkout_require_pa5($pdo);
    $requestId=vp3_profile_checkout_token_pa5($input['request_id']??null);
    $slug=$input['product_slug']??null;
    $quantity=$input['quantity']??null;
    $wanted=$input['connection_id']??0;
    $email=is_string($input['payer_email']??null)?strtolower(trim($input['payer_email'])):'';
    if(!is_string($slug)||!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$slug)||!is_int($quantity)
        ||$quantity<1||$quantity>VP3_PROFILE_CHECKOUT_MAX_QUANTITY_PA5||!is_int($wanted)||$wanted<0
        ||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)){
        throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_INVALID','Choose a product, valid quantity and receipt email.',422);
    }
    $requestHash=hash_hmac('sha256',json_encode([$slug,$quantity,$wanted,$email],JSON_THROW_ON_ERROR),
        (string)agent_commerce_config_v800()['encryption_key']);
    $row=agent_commerce_atomic_pa2($pdo,function()use($pdo,$profile,$ctx,$cid,$slug,$quantity,$wanted,$email,$requestId,$requestHash):array{
        $ctx=vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid,true);
        $buyer=vp3_profile_checkout_buyer_key_pa5($ctx);
        $q=$pdo->prepare('SELECT * FROM profile_conversation_checkouts_pa5 WHERE owner_user_id=? AND credential_hash=? AND request_id=?'.agent_commerce_lock_pa2($pdo));
        $q->execute([$buyer[0],$buyer[1],$requestId]);
        $prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            if(($cid>0&&$cid!==(int)$prior['conversation_id'])||!hash_equals((string)$prior['request_hash'],$requestHash)){
                throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_REQUEST_CHANGED','That request already has a different purchase review.');
            }
            $cid=(int)$prior['conversation_id'];
            vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);
            return vp3_profile_checkout_review_row_pa5($pdo,$ctx,$cid,(string)$prior['review_id'],true);
        }

        $now=time();
        $q=$pdo->prepare('DELETE FROM profile_conversation_checkouts_pa5
            WHERE owner_user_id=? AND credential_hash=? AND order_id IS NULL AND expires_at_unix<?');
        $q->execute([$buyer[0],$buyer[1],$now-86400]);
        $q=$pdo->prepare('SELECT COUNT(*) FROM profile_conversation_checkouts_pa5 WHERE owner_user_id=? AND credential_hash=? AND created_at_unix>?');
        $q->execute([$buyer[0],$buyer[1],$now-VP3_PROFILE_CHECKOUT_TTL_PA5]);
        if((int)$q->fetchColumn()>=20)throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_RATE_LIMITED','Wait a few minutes before preparing another purchase.',429);
        $product=vp3_profile_checkout_product_pa5($pdo,$ctx['profile'],$slug);
        $price=(int)$product['price_cents'];
        if($price>intdiv(VP3_PROFILE_CHECKOUT_MAX_TOTAL_PA5,$quantity)){
            throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_INVALID','Choose a smaller purchase quantity.',422);
        }
        $connection=vp3_profile_checkout_select_connection_pa5($pdo,$product,$wanted);
        if($cid===0){
            $started=vp3_profile_agent_public_start_v1($pdo,$ctx);
            $cid=(int)$started['conversation']['conversation_id'];
        }
        $currency=strtolower((string)$product['currency']);
        $total=$price*$quantity;
        $terms=agent_commerce_product_terms_v800($product);
        $review=[
            'product_slug'=>$slug,'title'=>(string)$product['title'],'quantity'=>$quantity,'unit_price_cents'=>$price,
            'unit_price_label'=>agent_commerce_money_v800($price,$currency),'charges_cents'=>0,'charges_label'=>agent_commerce_money_v800(0,$currency),
            'total_cents'=>$total,'total_label'=>agent_commerce_money_v800($total,$currency),'currency'=>$currency,
            'fulfillment_type'=>(string)$product['fulfillment_type'],'payment_provider'=>vp3_profile_checkout_provider_pa5($connection),
            'terms'=>['hold_minutes'=>(int)$terms['hold_minutes'],'refund_before_hours'=>(int)$terms['refund_before_hours'],
                'cancellation_fee_cents'=>(int)$terms['cancellation_fee_cents'],
                'cancellation_fee_label'=>agent_commerce_money_v800((int)$terms['cancellation_fee_cents'],$currency),
                'cancellation_policy'=>(string)$terms['cancellation_policy']],
        ];
        $reviewId=bin2hex(random_bytes(16));
        $secret=['email'=>$email,'receipt'=>profile_commerce_receipt_token_v1100()];
        $promotionClick=profile_product_promotion_attribution_click_v1((int)$ctx['owner_user_id'],(int)$product['id'],null,$cid);
        if($promotionClick!==null)$secret['product_promotion_click_v1']=$promotionClick;
        $buyerSecret=agent_commerce_encrypt_v800(json_encode($secret,JSON_THROW_ON_ERROR));
        $pdo->prepare('INSERT INTO profile_conversation_checkouts_pa5
            (owner_user_id,credential_hash,profile_agent_id,profile_session_id,visitor_user_id,conversation_id,request_id,request_hash,
            review_id,product_id,product_snapshot_hash,connection_id,merchant_fingerprint,review_json,buyer_ciphertext,expires_at_unix,created_at_unix)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                ...$buyer,$cid,$requestId,$requestHash,$reviewId,(int)$product['id'],vp3_profile_checkout_product_snapshot_pa5($product),
                (int)$connection['id'],agent_commerce_checkout_merchant_fingerprint_pa5($connection),
                json_encode($review,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$buyerSecret,$now+VP3_PROFILE_CHECKOUT_TTL_PA5,$now,
            ]);
        return vp3_profile_checkout_review_row_pa5($pdo,$ctx,$cid,$reviewId);
    });
    return ['conversation_id'=>(int)$row['conversation_id'],'review'=>vp3_profile_checkout_review_pa5($pdo,$profile,$row)];
}

function vp3_profile_checkout_secret_pa5(array $row): array
{
    $secret=json_decode(agent_commerce_decrypt_v800((string)$row['buyer_ciphertext']),true,4,JSON_THROW_ON_ERROR);
    if(!is_array($secret)||!is_string($secret['email']??null)||!filter_var($secret['email'],FILTER_VALIDATE_EMAIL)
        ||!is_string($secret['receipt']??null)||!profile_commerce_receipt_token_valid_v1100($secret['receipt'])){
        throw new RuntimeException('Profile checkout buyer authority is unavailable.');
    }
    return $secret;
}

function vp3_profile_checkout_order_pa5(PDO $pdo,array $profile,array $row,array $secret): array
{
    $order=agent_commerce_order_v800($pdo,(int)$row['order_id']);
    $metadata=$order?profile_commerce_order_metadata_v900($order):[];
    if(!$order||(int)$order['owner_user_id']!==(int)$profile['user_id']
        ||(int)$order['connection_id']!==(int)$row['connection_id']
        ||!hash_equals((string)($metadata['profile_checkout_review_id']??''),(string)$row['review_id'])
        ||(int)($metadata['profile_conversation_id']??0)!==(int)$row['conversation_id']){
        throw new RuntimeException('Profile checkout order lineage is unavailable.');
    }
    $authorized=profile_commerce_customer_order_v1100($pdo,(int)$profile['user_id'],(string)$order['order_number'],$secret['receipt']);
    if(!$authorized)throw new RuntimeException('Profile checkout receipt authority is unavailable.');
    return $authorized;
}

function vp3_profile_checkout_attempt_pa5(PDO $pdo,array $row): ?array
{
    $q=$pdo->prepare('SELECT status,checkout_url,provider,provider_account_id,provider_location_id,external_session_id
        FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND connection_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([(int)$row['order_id'],(int)$row['connection_id']]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_profile_checkout_review_pa5(PDO $pdo,array $profile,array $row): array
{
    $secret=vp3_profile_checkout_secret_pa5($row);
    $review=json_decode((string)$row['review_json'],true,8,JSON_THROW_ON_ERROR);
    $review['review_id']=(string)$row['review_id'];
    $review['expires_at_unix']=(int)$row['expires_at_unix'];
    $review['payer_email']=$secret['email'];
    if((int)$row['order_id']<1){
        $review['state']='expired';
        if($review['expires_at_unix']<=time())return $review;
        $product=profile_commerce_owner_product_v900($pdo,(int)$profile['user_id'],(int)$row['product_id']);
        $connection=agent_commerce_connection_v800($pdo,(int)$row['connection_id'],(int)$profile['user_id']);
        if($product&&vp3_profile_checkout_product_eligible_pa5($product)
            &&hash_equals((string)$row['product_snapshot_hash'],vp3_profile_checkout_product_snapshot_pa5($product))
            &&$connection&&vp3_profile_checkout_connection_eligible_pa5($connection,$product)
            &&hash_equals((string)$row['merchant_fingerprint'],agent_commerce_checkout_merchant_fingerprint_pa5($connection))){
            $review['state']='prepared';
        }
        return $review;
    }
    $order=vp3_profile_checkout_order_pa5($pdo,$profile,$row,$secret);
    $view=profile_commerce_customer_projection_v1100($order);
    $review['order']=$view;
    $review['state']='order';
    if(in_array($view['payment_status'],['paid','partially_paid','partially_refunded','refunded'],true)){
        $review['receipt_url']=profile_commerce_customer_order_url_v1100((string)$profile['username'],$view['order_number'],$secret['receipt']);
        if(profile_commerce_delivery_for_customer_v1120($order)||profile_commerce_delivery_file_for_customer_v1130($order)){
            $review['delivery_url']=$review['receipt_url'].'#private-delivery';
        }
    }
    if($view['payment_status']==='awaiting_payment'&&agent_commerce_date_pa2($order['hold_expires_at'])>time()){
        $attempt=vp3_profile_checkout_attempt_pa5($pdo,$row);
        $connection=agent_commerce_connection_v800($pdo,(int)$row['connection_id'],(int)$profile['user_id']);
        if($connection&&$connection['status']==='connected'&&agent_commerce_provider_ready_v800((string)$connection['provider'])
            &&hash_equals((string)$row['merchant_fingerprint'],agent_commerce_checkout_merchant_fingerprint_pa5($connection))){
            if(!$attempt||$attempt['status']==='creating')$review['state']='checkout_pending';
            elseif($attempt['status']==='open'&&is_string($attempt['checkout_url'])&&$attempt['checkout_url']!==''
                &&hash_equals((string)$connection['provider'],(string)$attempt['provider'])
                &&hash_equals((string)$connection['external_account_id'],(string)$attempt['provider_account_id'])
                &&($attempt['provider']!=='square'||hash_equals((string)(agent_commerce_capabilities_v800($connection)['location_id']??''),
                    (string)$attempt['provider_location_id']))){
                $review['state']='checkout_ready';
                $review['checkout_url']=$attempt['checkout_url'];
            }
        }
    }
    return $review;
}

function vp3_profile_checkout_confirm_pa5(PDO $pdo,array $profile,array $ctx,int $cid,array $input): array
{
    vp3_profile_checkout_require_pa5($pdo);
    $reviewId=vp3_profile_checkout_token_pa5($input['review_id']??null);
    if($cid<1||($input['terms_accepted']??false)!==true){
        throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_CONFIRMATION_REQUIRED','Accept the seller terms on this purchase card before continuing.',422);
    }
    $callback='/profile-conversation-checkout-return-pa5.php?'.http_build_query(['username'=>$profile['username'],'review'=>$reviewId],'','&',PHP_QUERY_RFC3986);
    $return=agent_commerce_absolute_url_v800($callback);
    $cancel=$return.'&cancel=1';
    $row=agent_commerce_atomic_pa2($pdo,function()use($pdo,$profile,$ctx,$cid,$reviewId):array{
        $ctx=vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid,true);
        $row=vp3_profile_checkout_review_row_pa5($pdo,$ctx,$cid,$reviewId,true);
        if((int)$row['order_id']>0)return $row;
        if((int)$row['expires_at_unix']<=time()){
            throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_REVIEW_EXPIRED','The purchase review expired. Review the current offer again.');
        }
        $review=json_decode((string)$row['review_json'],true,8,JSON_THROW_ON_ERROR);
        $product=vp3_profile_checkout_product_pa5($pdo,$ctx['profile'],(string)$review['product_slug']);
        if((int)$product['id']!==(int)$row['product_id']
            ||!hash_equals((string)$row['product_snapshot_hash'],vp3_profile_checkout_product_snapshot_pa5($product))){
            throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_TERMS_CHANGED','Product price or seller terms changed. Review the current offer again.');
        }
        $connection=vp3_profile_checkout_select_connection_pa5($pdo,$product,(int)$row['connection_id']);
        if(!hash_equals((string)$row['merchant_fingerprint'],agent_commerce_checkout_merchant_fingerprint_pa5($connection))){
            throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_PROVIDER_CHANGED','The selected payment provider changed. Review the current offer again.');
        }
        $secret=vp3_profile_checkout_secret_pa5($row);
        $order=profile_commerce_create_promoted_order_v1($pdo,$product,[
            'connection_id'=>(int)$row['connection_id'],'payer_email'=>$secret['email'],'quantity'=>(int)$review['quantity'],
            'metadata'=>[
                'source'=>'profile_commerce_v900','profile_username'=>(string)$profile['username'],
                'profile_product_slug'=>(string)$review['product_slug'],'profile_checkout_review_id'=>$reviewId,
                'profile_conversation_id'=>$cid,'profile_session_id'=>(int)$ctx['session_id'],
                'profile_conversion_source'=>'profile_commerce_v900','profile_target_id'=>(int)$product['id'],
                'profile_target_slug'=>(string)$review['product_slug'],'profile_target_title'=>(string)$product['title'],
                'profile_target_url'=>profile_commerce_product_url_v900((string)$profile['username'],$product),
                'receipt_token_sha256'=>profile_commerce_receipt_token_hash_v1100($secret['receipt']),
                'terms_accepted_at'=>gmdate('c'),'terms_snapshot_sha256'=>profile_commerce_terms_digest_v900($product),
            ],
        ],$secret['product_promotion_click_v1']??null);
        $q=$pdo->prepare('UPDATE profile_conversation_checkouts_pa5 SET order_id=? WHERE id=? AND order_id IS NULL');
        $q->execute([(int)$order['id'],(int)$row['id']]);
        if($q->rowCount()!==1)throw new RuntimeException('Profile checkout confirmation lost its order claim.');
        $row['order_id']=(int)$order['id'];
        return $row;
    });

    $review=vp3_profile_checkout_review_pa5($pdo,$profile,$row);
    if($review['state']==='checkout_pending'){
        $order=vp3_profile_checkout_order_pa5($pdo,$profile,$row,vp3_profile_checkout_secret_pa5($row));
        try{
            agent_commerce_create_checkout_v800($pdo,$order,(int)$row['connection_id'],$return,$cancel,(string)$row['merchant_fingerprint']);
        }catch(RuntimeException $e){
            $review=vp3_profile_checkout_review_pa5($pdo,$profile,$row);
            if($review['state']==='checkout_pending'){
                if((int)$e->getCode()===409){
                    throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_PROVIDER_CHANGED','The existing checkout is unavailable. Check this order before trying another purchase.');
                }
                throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_RETRY','Checkout is still pending. Retry this purchase card to resume the same checkout.',503);
            }
        }
    }
    vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid);
    return ['conversation_id'=>$cid,'review'=>vp3_profile_checkout_review_pa5($pdo,$profile,$row)];
}

function vp3_profile_checkout_state_pa5(PDO $pdo,array $profile,array $ctx,int $cid): array
{
    vp3_profile_checkout_require_pa5($pdo);
    if($cid<1)throw new VP3ProfileCheckoutException('PROFILE_CHECKOUT_REVIEW_NOT_FOUND','This purchase conversation could not be found.',404);
    $ctx=vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid);
    $scope='SELECT * FROM profile_conversation_checkouts_pa5
        WHERE owner_user_id=? AND credential_hash=? AND profile_agent_id=? AND profile_session_id=? AND visitor_user_id=?
        AND conversation_id=?';
    $args=[...vp3_profile_checkout_buyer_key_pa5($ctx),$cid];
    $q=$pdo->prepare($scope.' AND order_id IS NULL ORDER BY id DESC LIMIT 20');
    $q->execute($args);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    // A buyer returning from a provider sees the matching order even after
    // newer drafts or orders were prepared in this same conversation.
    $q=$pdo->prepare($scope.' AND order_id IS NOT NULL ORDER BY returned_at_unix DESC,id DESC LIMIT 20');
    $q->execute($args);
    $rows=array_merge($rows,$q->fetchAll(PDO::FETCH_ASSOC));
    usort($rows,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
    $reviews=[];
    foreach($rows as $row)$reviews[]=vp3_profile_checkout_review_pa5($pdo,$profile,$row);
    vp3_profile_checkout_authorize_pa5($pdo,$profile,$ctx,$cid);
    return ['conversation_id'=>$cid,'reviews'=>$reviews];
}
