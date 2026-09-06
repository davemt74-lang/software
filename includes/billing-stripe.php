<?php
declare(strict_types=1);

function billing_config(string $key,mixed $default=null): mixed
{
    global $config;
    return $config['billing'][$key]??$default;
}

function billing_provider(): string
{
    return strtolower(trim((string)billing_config('provider','stripe')))?:'stripe';
}

function billing_currency(): string
{
    $currency=strtolower(trim((string)billing_config('currency','usd')));
    return preg_match('/^[a-z]{3}$/',$currency)?$currency:'usd';
}

function billing_stripe_config(string $key,mixed $default=null): mixed
{
    global $config;
    return $config['billing']['stripe'][$key]??$default;
}

function billing_stripe_secret_key(): string
{
    $env=trim((string)getenv('STRIPE_SECRET_KEY'));
    return $env!==''?$env:trim((string)billing_stripe_config('secret_key',''));
}

function billing_stripe_webhook_secret(): string
{
    $env=trim((string)getenv('STRIPE_WEBHOOK_SECRET'));
    return $env!==''?$env:trim((string)billing_stripe_config('webhook_secret',''));
}

function billing_stripe_configured(): bool
{
    return billing_provider()==='stripe'&&billing_stripe_secret_key()!=='';
}

function billing_public_origin(): string
{
    $configured=rtrim(trim((string)site_config('base_url','')),'/');
    if($configured!==''){
        if(!preg_match('#^https?://[A-Za-z0-9.-]+(?::\d+)?$#',$configured)){
            throw new RuntimeException('site.base_url must be an origin such as https://example.com before billing can be used.');
        }
        return $configured;
    }
    $host=trim((string)($_SERVER['HTTP_HOST']??''));
    if($host===''||!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/',$host)){
        throw new RuntimeException('Set site.base_url before billing can be used.');
    }
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    if(!$https&&$host!=='localhost'&&!str_starts_with($host,'127.0.0.1')){
        throw new RuntimeException('Set an HTTPS site.base_url before billing can be used.');
    }
    return ($https?'https':'http').'://'.$host;
}

function billing_absolute_url(string $path): string
{
    return billing_public_origin().url($path);
}

function billing_stripe_request(string $method,string $path,array $params=[],string $idempotencyKey=''): array
{
    $secret=billing_stripe_secret_key();
    if($secret==='')throw new RuntimeException('Stripe billing is not configured.');
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for Stripe billing.');
    $method=strtoupper($method);
    $url='https://api.stripe.com/v1/'.ltrim($path,'/');
    $encoded=http_build_query($params,'','&',PHP_QUERY_RFC3986);
    if($method==='GET'&&$encoded!=='')$url.='?'.$encoded;
    $headers=['Authorization: Bearer '.$secret,'Accept: application/json'];
    if($method!=='GET')$headers[]='Content-Type: application/x-www-form-urlencoded';
    if($idempotencyKey!=='')$headers[]='Idempotency-Key: '.substr($idempotencyKey,0,255);
    $curl=curl_init($url);
    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>35,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CUSTOMREQUEST=>$method,
    ]);
    if($method!=='GET')curl_setopt($curl,CURLOPT_POSTFIELDS,$encoded);
    $body=curl_exec($curl);
    $errno=curl_errno($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if($body===false||$errno!==0)throw new RuntimeException('Stripe network request failed: '.$error);
    $decoded=json_decode((string)$body,true);
    if(!is_array($decoded))throw new RuntimeException('Stripe returned an unreadable response.');
    if($status<200||$status>=300){
        $message=trim((string)($decoded['error']['message']??'Stripe rejected the billing request.'));
        throw new RuntimeException($message!==''?$message:'Stripe rejected the billing request.');
    }
    return $decoded;
}

function billing_stripe_verify_webhook(string $payload,string $signatureHeader,int $tolerance=300): bool
{
    $secret=billing_stripe_webhook_secret();
    if($secret===''||$payload===''||$signatureHeader==='')return false;
    $timestamp=0;$signatures=[];
    foreach(explode(',',$signatureHeader) as $part){
        [$key,$value]=array_pad(explode('=',trim($part),2),2,'');
        if($key==='t'&&ctype_digit($value))$timestamp=(int)$value;
        elseif($key==='v1'&&preg_match('/^[a-f0-9]{64}$/i',$value))$signatures[]=strtolower($value);
    }
    if($timestamp<1||!$signatures||abs(time()-$timestamp)>max(30,$tolerance))return false;
    $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
    foreach($signatures as $signature){if(hash_equals($expected,$signature))return true;}
    return false;
}

function billing_price_mapping(int $packageId,string $interval,?PDO $pdo=null): ?array
{
    $pdo??=db();$interval=subscription_self_service_interval($interval);
    if(!$pdo||$packageId<1||!billing_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM package_billing_prices WHERE package_id=? AND provider='stripe' AND billing_interval=? LIMIT 1");
    $stmt->execute([$packageId,$interval]);$row=$stmt->fetch();return $row?:null;
}

function billing_price_mapping_by_provider_price(string $priceId,?PDO $pdo=null): ?array
{
    $pdo??=db();$priceId=trim($priceId);
    if(!$pdo||$priceId===''||!billing_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT bp.*,p.name package_name,p.slug package_slug FROM package_billing_prices bp INNER JOIN subscription_packages p ON p.id=bp.package_id WHERE bp.provider='stripe' AND bp.provider_price_id=? LIMIT 1");
    $stmt->execute([$priceId]);$row=$stmt->fetch();return $row?:null;
}

function billing_customer_for_user(int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$userId<1||!billing_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM billing_customers WHERE user_id=? AND provider='stripe' LIMIT 1");$stmt->execute([$userId]);$row=$stmt->fetch();return $row?:null;
}

function billing_user_id_for_customer(string $customerId,?PDO $pdo=null): int
{
    $pdo??=db();if(!$pdo||$customerId===''||!billing_schema_ready($pdo))return 0;
    $stmt=$pdo->prepare("SELECT user_id FROM billing_customers WHERE provider='stripe' AND provider_customer_id=? LIMIT 1");$stmt->execute([$customerId]);return (int)$stmt->fetchColumn();
}

function billing_store_customer(int $userId,string $customerId,string $email='',?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo||$userId<1||$customerId==='')return;
    $stmt=$pdo->prepare("INSERT INTO billing_customers (user_id,provider,provider_customer_id,email_snapshot,metadata_json) VALUES (?,'stripe',?,?,?) ON DUPLICATE KEY UPDATE provider_customer_id=VALUES(provider_customer_id),email_snapshot=VALUES(email_snapshot),metadata_json=VALUES(metadata_json),updated_at=NOW()");
    $stmt->execute([$userId,$customerId,mb_strimwidth($email,0,255,''),json_encode(['schema'=>VP3_BILLING_SCHEMA_VERSION],JSON_UNESCAPED_SLASHES)]);
}

function billing_stripe_ensure_customer(array $user,?PDO $pdo=null): string
{
    $pdo??=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1)throw new RuntimeException('Billing account unavailable.');
    $known=billing_customer_for_user($userId,$pdo);if($known)return (string)$known['provider_customer_id'];
    $email=trim((string)($user['email']??''));$name=trim((string)($user['display_name']??''));
    $params=['metadata'=>['vp3_user_id'=>(string)$userId]];
    if($email!=='')$params['email']=$email;if($name!=='')$params['name']=$name;
    $customer=billing_stripe_request('POST','customers',$params,'vp3-customer-'.$userId);
    $customerId=trim((string)($customer['id']??''));if($customerId==='')throw new RuntimeException('Stripe did not create a customer record.');
    billing_store_customer($userId,$customerId,$email,$pdo);return $customerId;
}

function billing_stripe_package_amount(array $package,string $interval): ?int
{
    return subscription_self_service_price_cents($package,$interval);
}

function billing_stripe_ensure_package_price(array $package,string $interval,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!billing_schema_ready($pdo))throw new RuntimeException('Billing schema is unavailable.');
    $packageId=(int)($package['id']??0);$interval=subscription_self_service_interval($interval);$amount=billing_stripe_package_amount($package,$interval);
    if($packageId<1||$amount===null||$amount<1||(int)($package['is_trial']??0)===1)throw new RuntimeException('This package does not have a billable '.$interval.' price.');
    $currency=billing_currency();$existing=billing_price_mapping($packageId,$interval,$pdo);
    if($existing&&(int)$existing['is_active']===1&&(int)$existing['unit_amount_cents']===$amount&&(string)$existing['currency']===$currency)return $existing;

    $productId=trim((string)($existing['provider_product_id']??''));
    if($productId===''){
        $product=billing_stripe_request('POST','products',[
            'name'=>(string)$package['name'],
            'description'=>(string)($package['description']??''),
            'metadata'=>['vp3_package_id'=>(string)$packageId,'vp3_package_slug'=>(string)$package['slug']],
        ],'vp3-product-'.$packageId);
        $productId=trim((string)($product['id']??''));
        if($productId==='')throw new RuntimeException('Stripe did not return a Product ID.');
    }else{
        billing_stripe_request('POST','products/'.rawurlencode($productId),[
            'name'=>(string)$package['name'],
            'description'=>(string)($package['description']??''),
        ],'vp3-product-refresh-'.$packageId.'-'.substr(hash('sha256',(string)($package['updated_at']??'')),0,16));
    }

    $stripeInterval=$interval==='annual'?'year':'month';
    $termsHash=substr(hash('sha256',$packageId.'|'.$interval.'|'.$amount.'|'.$currency.'|'.$productId),0,28);
    $price=billing_stripe_request('POST','prices',[
        'product'=>$productId,
        'unit_amount'=>$amount,
        'currency'=>$currency,
        'recurring'=>['interval'=>$stripeInterval],
        'metadata'=>['vp3_package_id'=>(string)$packageId,'vp3_interval'=>$interval],
    ],'vp3-price-'.$termsHash);
    $priceId=trim((string)($price['id']??''));if($priceId==='')throw new RuntimeException('Stripe did not return a Price ID.');
    $stmt=$pdo->prepare("INSERT INTO package_billing_prices (package_id,provider,billing_interval,provider_product_id,provider_price_id,currency,unit_amount_cents,is_active,metadata_json) VALUES (?,'stripe',?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE provider_product_id=VALUES(provider_product_id),provider_price_id=VALUES(provider_price_id),currency=VALUES(currency),unit_amount_cents=VALUES(unit_amount_cents),is_active=1,metadata_json=VALUES(metadata_json),updated_at=NOW()");
    $stmt->execute([$packageId,$interval,$productId,$priceId,$currency,$amount,json_encode(['stripe_interval'=>$stripeInterval,'terms_hash'=>$termsHash],JSON_UNESCAPED_SLASHES)]);
    return billing_price_mapping($packageId,$interval,$pdo)??throw new RuntimeException('Billing price mapping could not be saved.');
}

function billing_stripe_subscription(string $subscriptionId): array
{
    return billing_stripe_request('GET','subscriptions/'.rawurlencode($subscriptionId),['expand'=>['items.data.price.product']]);
}

function billing_stripe_checkout_session(string $sessionId): array
{
    return billing_stripe_request('GET','checkout/sessions/'.rawurlencode($sessionId),[]);
}

function billing_stripe_subscription_item(array $subscription): ?array
{
    $items=$subscription['items']['data']??[];return is_array($items)&&isset($items[0])&&is_array($items[0])?$items[0]:null;
}

function billing_stripe_subscription_price_id(array $subscription): string
{
    $item=billing_stripe_subscription_item($subscription);return trim((string)($item['price']['id']??$item['plan']['id']??''));
}

function billing_stripe_subscription_item_id(array $subscription): string
{
    $item=billing_stripe_subscription_item($subscription);return trim((string)($item['id']??''));
}

function billing_stripe_subscription_product_id(array $subscription): string
{
    $item=billing_stripe_subscription_item($subscription);$product=$item['price']['product']??$item['plan']['product']??'';
    if(is_array($product))return trim((string)($product['id']??''));
    return trim((string)$product);
}

function billing_stripe_period(array $subscription): array
{
    $item=billing_stripe_subscription_item($subscription);
    $start=(int)($item['current_period_start']??$subscription['current_period_start']??0);
    $end=(int)($item['current_period_end']??$subscription['current_period_end']??0);
    return [
        'start'=>$start>0?gmdate('Y-m-d H:i:s',$start):null,
        'end'=>$end>0?gmdate('Y-m-d H:i:s',$end):null,
    ];
}

function billing_stripe_create_checkout(array $user,array $request,array $package,string $interval,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!billing_schema_ready($pdo))throw new RuntimeException('Billing storage is unavailable.');
    $userId=(int)($user['id']??0);$requestId=(int)($request['id']??0);if($userId<1||$requestId<1)throw new RuntimeException('Plan request is invalid.');
    $price=billing_stripe_ensure_package_price($package,$interval,$pdo);$customerId=billing_stripe_ensure_customer($user,$pdo);
    $returnBase='/subscription.php?billing='.rawurlencode(subscription_self_service_interval($interval));
    $params=[
        'mode'=>'subscription',
        'customer'=>$customerId,
        'client_reference_id'=>(string)$userId,
        'line_items'=>[['price'=>(string)$price['provider_price_id'],'quantity'=>1]],
        'success_url'=>billing_absolute_url($returnBase.'&checkout=success&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'=>billing_absolute_url($returnBase.'&checkout=cancelled'),
        'allow_promotion_codes'=>(bool)billing_stripe_config('allow_promotion_codes',true),
        'metadata'=>[
            'vp3_user_id'=>(string)$userId,'vp3_plan_request_id'=>(string)$requestId,'vp3_package_id'=>(string)$package['id'],'vp3_interval'=>subscription_self_service_interval($interval),
        ],
        'subscription_data'=>['metadata'=>[
            'vp3_user_id'=>(string)$userId,'vp3_plan_request_id'=>(string)$requestId,'vp3_package_id'=>(string)$package['id'],'vp3_interval'=>subscription_self_service_interval($interval),
        ]],
    ];
    if((bool)billing_stripe_config('automatic_tax',false))$params['automatic_tax']=['enabled'=>true];
    $session=billing_stripe_request('POST','checkout/sessions',$params,'vp3-checkout-'.$requestId.'-'.(string)$price['provider_price_id']);
    $sessionId=trim((string)($session['id']??''));$checkoutUrl=trim((string)($session['url']??''));
    if($sessionId===''||$checkoutUrl==='')throw new RuntimeException('Stripe did not return a checkout URL.');
    $expires=(int)($session['expires_at']??0);
    $stmt=$pdo->prepare("INSERT INTO billing_checkout_sessions (user_id,plan_request_id,package_id,provider,session_type,provider_session_id,provider_customer_id,billing_interval,amount_cents,status,checkout_url,expires_at,metadata_json) VALUES (?,?,?,'stripe','checkout',?,?,?,?,?,'open',?,?,?) ON DUPLICATE KEY UPDATE status='open',checkout_url=VALUES(checkout_url),expires_at=VALUES(expires_at),updated_at=NOW()");
    $stmt->execute([$userId,$requestId,(int)$package['id'],$sessionId,$customerId,subscription_self_service_interval($interval),(int)$price['unit_amount_cents'],$checkoutUrl,$expires>0?gmdate('Y-m-d H:i:s',$expires):null,json_encode(['provider_price_id'=>$price['provider_price_id']],JSON_UNESCAPED_SLASHES)]);
    return ['type'=>'checkout','url'=>$checkoutUrl,'session_id'=>$sessionId,'price'=>$price];
}

function billing_stripe_portal_config(array $products,?PDO $pdo=null): string
{
    $pdo??=db();if(!$pdo||!billing_schema_ready($pdo))throw new RuntimeException('Billing storage is unavailable.');
    $normalized=[];
    foreach($products as $productId=>$prices){
        $productId=trim((string)$productId);$prices=array_values(array_unique(array_filter(array_map('strval',(array)$prices))));
        sort($prices);if($productId!==''&&$prices)$normalized[$productId]=$prices;
    }
    ksort($normalized);if(!$normalized)throw new RuntimeException('No Stripe products are available for the billing portal.');
    $configKey=hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
    $stmt=$pdo->prepare("SELECT provider_configuration_id FROM billing_portal_configs WHERE provider='stripe' AND config_key=? LIMIT 1");$stmt->execute([$configKey]);$known=trim((string)$stmt->fetchColumn());if($known!=='')return $known;
    $params=[
        'name'=>'VP3 Plan Management',
        'default_return_url'=>billing_absolute_url('/subscription.php'),
        'features'=>[
            'payment_method_update'=>['enabled'=>true],
            'invoice_history'=>['enabled'=>true],
            'subscription_cancel'=>['enabled'=>true,'mode'=>'at_period_end','proration_behavior'=>'none'],
            'subscription_update'=>['enabled'=>true,'default_allowed_updates'=>['price'],'proration_behavior'=>'create_prorations','products'=>[]],
        ],
        'metadata'=>['vp3_config_key'=>$configKey],
    ];
    $i=0;foreach($normalized as $productId=>$prices){if($i>=10)break;$params['features']['subscription_update']['products'][]=['product'=>$productId,'prices'=>$prices];$i++;}
    $config=billing_stripe_request('POST','billing_portal/configurations',$params,'vp3-portal-'.$configKey);
    $configId=trim((string)($config['id']??''));if($configId==='')throw new RuntimeException('Stripe did not return a portal configuration.');
    $ins=$pdo->prepare("INSERT INTO billing_portal_configs (provider,config_key,provider_configuration_id,metadata_json) VALUES ('stripe',?,?,?) ON DUPLICATE KEY UPDATE provider_configuration_id=VALUES(provider_configuration_id),updated_at=NOW()");
    $ins->execute([$configKey,$configId,json_encode(['products'=>$normalized],JSON_UNESCAPED_SLASHES)]);return $configId;
}

function billing_stripe_create_plan_change_portal(array $user,array $request,array $package,string $interval,array $billingSubscription,?PDO $pdo=null): array
{
    $pdo??=db();$subscriptionId=trim((string)($billingSubscription['provider_subscription_id']??''));if($subscriptionId==='')throw new RuntimeException('Current Stripe subscription is unavailable.');
    $stripeSub=billing_stripe_subscription($subscriptionId);$itemId=billing_stripe_subscription_item_id($stripeSub);$currentProduct=billing_stripe_subscription_product_id($stripeSub);$currentPrice=billing_stripe_subscription_price_id($stripeSub);
    if($itemId===''||$currentProduct===''||$currentPrice==='')throw new RuntimeException('Current Stripe subscription item could not be resolved.');
    $target=billing_stripe_ensure_package_price($package,$interval,$pdo);$targetProduct=(string)$target['provider_product_id'];$targetPrice=(string)$target['provider_price_id'];
    $products=[$currentProduct=>[$currentPrice],$targetProduct=>[$targetPrice]];
    if($currentProduct===$targetProduct)$products[$currentProduct]=array_values(array_unique([$currentPrice,$targetPrice]));
    $configId=billing_stripe_portal_config($products,$pdo);$customerId=(string)$billingSubscription['provider_customer_id'];
    $returnUrl=billing_absolute_url('/subscription.php?billing='.rawurlencode(subscription_self_service_interval($interval)).'&billing_return=1');
    $params=[
        'customer'=>$customerId,'configuration'=>$configId,'return_url'=>$returnUrl,
        'flow_data'=>[
            'type'=>'subscription_update_confirm',
            'subscription_update_confirm'=>['subscription'=>$subscriptionId,'items'=>[['id'=>$itemId,'price'=>$targetPrice,'quantity'=>1]]],
            'after_completion'=>['type'=>'redirect','redirect'=>['return_url'=>$returnUrl]],
        ],
    ];
    $session=billing_stripe_request('POST','billing_portal/sessions',$params);
    $sessionId=trim((string)($session['id']??''));$portalUrl=trim((string)($session['url']??''));if($sessionId===''||$portalUrl==='')throw new RuntimeException('Stripe did not return a billing portal URL.');
    $stmt=$pdo->prepare("INSERT INTO billing_checkout_sessions (user_id,plan_request_id,package_id,provider,session_type,provider_session_id,provider_customer_id,provider_subscription_id,billing_interval,amount_cents,status,checkout_url,metadata_json) VALUES (?,?,?,'stripe','portal_update',?,?,?,?,?,'open',?,?) ON DUPLICATE KEY UPDATE status='open',checkout_url=VALUES(checkout_url),updated_at=NOW()");
    $stmt->execute([(int)$user['id'],(int)$request['id'],(int)$package['id'],$sessionId,$customerId,$subscriptionId,subscription_self_service_interval($interval),(int)$target['unit_amount_cents'],$portalUrl,json_encode(['target_price_id'=>$targetPrice,'portal_configuration_id'=>$configId],JSON_UNESCAPED_SLASHES)]);
    return ['type'=>'portal_update','url'=>$portalUrl,'session_id'=>$sessionId,'price'=>$target];
}

function billing_stripe_create_manage_portal(array $user,array $billingSubscription,?PDO $pdo=null): array
{
    $pdo??=db();$customerId=trim((string)($billingSubscription['provider_customer_id']??''));$subscriptionId=trim((string)($billingSubscription['provider_subscription_id']??''));
    if($customerId===''||$subscriptionId==='')throw new RuntimeException('Stripe billing account is unavailable.');
    $stripeSub=billing_stripe_subscription($subscriptionId);$product=billing_stripe_subscription_product_id($stripeSub);$price=billing_stripe_subscription_price_id($stripeSub);if($product===''||$price==='')throw new RuntimeException('Current Stripe plan could not be resolved.');
    $configId=billing_stripe_portal_config([$product=>[$price]],$pdo);$returnUrl=billing_absolute_url('/subscription.php?billing_return=1');
    $session=billing_stripe_request('POST','billing_portal/sessions',['customer'=>$customerId,'configuration'=>$configId,'return_url'=>$returnUrl]);
    $url=trim((string)($session['url']??''));if($url==='')throw new RuntimeException('Stripe did not return a billing portal URL.');return $session;
}
