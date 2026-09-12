<?php
declare(strict_types=1);

/** VP3 Profile Commerce v9.00 — public profile projection over canonical Commerce. */
const VP3_PROFILE_COMMERCE_V900='profile-commerce-v900-20260912';

function profile_commerce_metadata_v900(array $product): array
{
    $decoded=json_decode((string)($product['metadata_json']??''),true);
    return is_array($decoded)?$decoded:[];
}

function profile_commerce_slug_v900(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    $value=trim($value,'-');
    return substr($value,0,80);
}

function profile_commerce_visibility_v900(array $product): string
{
    $meta=profile_commerce_metadata_v900($product);
    $visibility=strtolower(trim((string)($meta['profile_visibility']??'hidden')));
    return in_array($visibility,['public','hidden'],true)?$visibility:'hidden';
}

function profile_commerce_public_slug_v900(array $product): string
{
    $meta=profile_commerce_metadata_v900($product);
    $slug=profile_commerce_slug_v900((string)($meta['profile_slug']??''));
    if($slug!=='')return $slug;
    $slug=profile_commerce_slug_v900((string)($product['product_key']??''));
    return $slug!==''?$slug:'product-'.(int)($product['id']??0);
}

function profile_commerce_product_url_v900(string $username,array $product): string
{
    return url('/'.rawurlencode(profile_username_normalize($username)).'/product/'.rawurlencode(profile_commerce_public_slug_v900($product)));
}

function profile_commerce_checkout_url_v900(string $username,array $product): string
{
    return url('/'.rawurlencode(profile_username_normalize($username)).'/product/'.rawurlencode(profile_commerce_public_slug_v900($product)).'/checkout');
}

function profile_commerce_product_projection_v900(array $product,string $username): array
{
    $meta=profile_commerce_metadata_v900($product);
    $bindingType=trim((string)($product['binding_type']??''));
    $price=(int)($product['price_cents']??0);
    $deposit=(int)($product['deposit_cents']??0);
    $currency=(string)($product['currency']??'usd');
    $paymentMode=(string)($product['payment_mode']??'free');
    $priceLabel=$paymentMode==='free'?'Free':agent_commerce_money_v800($price,$currency);
    if($paymentMode==='deposit'&&$deposit>0)$priceLabel=agent_commerce_money_v800($deposit,$currency).' deposit · '.agent_commerce_money_v800($price,$currency).' total';
    $cta=trim((string)($meta['profile_cta']??''));
    if($cta==='')$cta=$bindingType==='appointment_event_type'?'Book':($paymentMode==='free'?'Learn more':'Buy');
    return [
        'id'=>(int)($product['id']??0),
        'slug'=>profile_commerce_public_slug_v900($product),
        'title'=>(string)($product['title']??'Product'),
        'description'=>(string)($product['description']??''),
        'product_type'=>(string)($product['product_type']??'other'),
        'fulfillment_type'=>(string)($product['fulfillment_type']??'other'),
        'payment_mode'=>$paymentMode,
        'price_cents'=>$price,
        'deposit_cents'=>$deposit,
        'currency'=>$currency,
        'price_label'=>$priceLabel,
        'featured'=>!empty($meta['profile_featured']),
        'sort'=>(int)($meta['profile_sort']??100),
        'cta'=>$cta,
        'binding_type'=>$bindingType,
        'binding_id'=>(int)($product['binding_id']??0),
        'product_url'=>profile_commerce_product_url_v900($username,$product),
        'checkout_url'=>profile_commerce_checkout_url_v900($username,$product),
    ];
}

function profile_commerce_products_for_profile_v900(PDO $pdo,array $profile,bool $publicOnly=true,int $limit=80): array
{
    if(!agent_commerce_schema_ready_v800($pdo))return [];
    $owner=max(0,(int)($profile['user_id']??0));
    $username=profile_username_normalize((string)($profile['username']??''));
    if($owner<1||$username==='')return [];
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT p.*,b.binding_type,b.binding_id FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id WHERE p.owner_user_id=? AND p.is_active=1 ORDER BY p.updated_at DESC,p.id DESC LIMIT {$limit}");
    $stmt->execute([$owner]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $product){
        if($publicOnly&&profile_commerce_visibility_v900($product)!=='public')continue;
        $out[]=profile_commerce_product_projection_v900($product,$username);
    }
    usort($out,static fn(array $a,array $b):int=>(($b['featured']?1:0)<=>($a['featured']?1:0))?:($a['sort']<=>$b['sort'])?:strcasecmp($a['title'],$b['title'])?:($a['id']<=>$b['id']));
    return $out;
}

function profile_commerce_public_product_v900(PDO $pdo,array $profile,string $slug): ?array
{
    $slug=profile_commerce_slug_v900($slug);if($slug==='')return null;
    foreach(profile_commerce_products_for_profile_v900($pdo,$profile,true,200) as $product)if(hash_equals((string)$product['slug'],$slug))return $product;
    return null;
}

function profile_commerce_public_source_v900(array $product): array
{
    $text='Offer: '.$product['title'].'. ';
    if(trim((string)$product['description'])!=='')$text.=trim((string)$product['description']).' ';
    $text.='Price: '.$product['price_label'].'. Product type: '.$product['product_type'].'. Fulfillment: '.$product['fulfillment_type'].'. ';
    if($product['binding_type']==='appointment_event_type')$text.='This offer is booked through the profile scheduling flow. ';
    elseif($product['payment_mode']!=='free')$text.='Secure checkout: '.$product['checkout_url'].'. ';
    $text.='Product details: '.$product['product_url'].'.';
    return ['source'=>'profile:commerce:'.$product['id'],'title'=>$product['title'],'text'=>mb_strimwidth($text,0,4000,'…')];
}

function profile_commerce_agent_context_v900(PDO $pdo,array $profile,string $query): array
{
    $products=profile_commerce_products_for_profile_v900($pdo,$profile,true,40);if(!$products)return [];
    $terms=function_exists('chat_policy_terms_v236')?chat_policy_terms_v236($query):[];
    $forced=(bool)preg_match('/\b(buy|book|price|pricing|product|service|offer|shop|store|merch|ticket|membership|gift|checkout|pay)\b/i',$query);
    $out=[];
    foreach($products as $product){
        $hay=mb_strtolower($product['title'].' '.$product['description'].' '.$product['product_type'].' '.$product['fulfillment_type']);
        $matched=$forced||!$terms;
        if(!$matched)foreach($terms as $term)if(str_contains($hay,mb_strtolower((string)$term))){$matched=true;break;}
        if(!$matched)continue;
        $out[]=profile_commerce_public_source_v900($product);
        if(count($out)>=8)break;
    }
    return $out;
}

function profile_commerce_owner_product_v900(PDO $pdo,int $ownerUserId,int $productId): ?array
{
    if($ownerUserId<1||$productId<1)return null;
    $stmt=$pdo->prepare('SELECT p.*,b.binding_type,b.binding_id FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id WHERE p.id=? AND p.owner_user_id=? LIMIT 1');
    $stmt->execute([$productId,$ownerUserId]);return $stmt->fetch()?:null;
}

function profile_commerce_publish_v900(PDO $pdo,int $ownerUserId,int $productId,array $input): array
{
    $product=profile_commerce_owner_product_v900($pdo,$ownerUserId,$productId);if(!$product)throw new RuntimeException('Commerce product not found.');
    $meta=profile_commerce_metadata_v900($product);
    $visibility=strtolower(trim((string)($input['profile_visibility']??'hidden')));if(!in_array($visibility,['public','hidden'],true))$visibility='hidden';
    $bindingType=trim((string)($product['binding_type']??''));
    if($visibility==='public'&&$bindingType===''&&(string)($product['payment_mode']??'')!=='full')throw new RuntimeException('Generic Profile Commerce products must use full payment.');
    if($visibility==='public'&&(string)($product['fulfillment_type']??'')==='physical')throw new RuntimeException('Shipped physical products cannot be published until the shipping-address fulfillment adapter is installed.');
    $slug=profile_commerce_slug_v900((string)($input['profile_slug']??profile_commerce_public_slug_v900($product)));if($slug==='')throw new RuntimeException('Choose a valid profile product slug.');
    $check=$pdo->prepare('SELECT id,metadata_json,product_key FROM agent_commerce_products_v800 WHERE owner_user_id=? AND id<>? AND is_active=1');$check->execute([$ownerUserId,$productId]);
    foreach($check->fetchAll()?:[] as $other)if(profile_commerce_public_slug_v900($other)===$slug)throw new RuntimeException('That profile product URL is already in use.');
    $meta['profile_visibility']=$visibility;$meta['profile_slug']=$slug;$meta['profile_featured']=!empty($input['profile_featured']);$meta['profile_sort']=max(0,min(9999,(int)($input['profile_sort']??100)));$meta['profile_cta']=mb_strimwidth(trim((string)($input['profile_cta']??'')),0,40,'');
    $pdo->prepare('UPDATE agent_commerce_products_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$productId,$ownerUserId]);
    return profile_commerce_owner_product_v900($pdo,$ownerUserId,$productId)?:throw new RuntimeException('Product profile settings could not be saved.');
}

function profile_commerce_save_generic_product_v900(PDO $pdo,int $ownerUserId,array $input): array
{
    if($ownerUserId<1)throw new RuntimeException('Sign in to create a Commerce product.');
    $productId=max(0,(int)($input['product_id']??0));$existing=$productId?profile_commerce_owner_product_v900($pdo,$ownerUserId,$productId):null;
    if($productId&&(!$existing||trim((string)($existing['binding_type']??''))!==''))throw new RuntimeException('Bound fulfillment products are managed by their fulfillment workspace.');
    $title=mb_strimwidth(trim((string)($input['title']??'')),0,190,'');if($title==='')throw new RuntimeException('Product title is required.');
    $description=mb_strimwidth(trim((string)($input['description']??'')),0,1000,'');
    $productType=strtolower(trim((string)($input['product_type']??'service')));if(!in_array($productType,['service','digital','membership','event','gift','physical','other'],true))$productType='other';
    $fulfillmentType=strtolower(trim((string)($input['fulfillment_type']??'other')));if(!in_array($fulfillmentType,['physical','local_pickup','digital','virtual','event','membership','gift','other'],true))$fulfillmentType='other';
    $terms=agent_commerce_validate_terms_v800(['payment_mode'=>(string)($input['payment_mode']??'full'),'price_cents'=>agent_commerce_decimal_to_minor_v800((string)($input['price']??'0')),'deposit_cents'=>agent_commerce_decimal_to_minor_v800((string)($input['deposit']??'0')),'currency'=>(string)($input['currency']??'usd'),'hold_minutes'=>(int)($input['hold_minutes']??30),'refund_before_hours'=>(int)($input['refund_before_hours']??24),'cancellation_fee_cents'=>agent_commerce_decimal_to_minor_v800((string)($input['cancellation_fee']??'0')),'cancellation_policy'=>(string)($input['cancellation_policy']??'')]);
    if($terms['payment_mode']!=='full')throw new RuntimeException('Generic Profile Commerce products must use full payment.');
    $terms['deposit_cents']=0;
    $providerMode=strtolower(trim((string)($input['provider_mode']??'guest_choice')));if(!in_array($providerMode,['guest_choice','fixed'],true))$providerMode='guest_choice';
    $fixed=max(0,(int)($input['fixed_connection_id']??0))?:null;
    if($providerMode==='fixed'){$connection=agent_commerce_connection_v800($pdo,(int)$fixed,$ownerUserId);if(!$connection||(string)$connection['status']!=='connected')throw new RuntimeException('Choose a connected fixed provider.');}
    else{$fixed=null;if(!agent_commerce_connections_v800($pdo,$ownerUserId,true))throw new RuntimeException('Connect at least one Commerce provider before creating a paid public product.');}
    $key=$existing?(string)$existing['product_key']:'profile:'.profile_commerce_slug_v900($title).':'.bin2hex(random_bytes(4));
    $meta=$existing?profile_commerce_metadata_v900($existing):[];
    $meta['profile_visibility']=strtolower(trim((string)($input['profile_visibility']??($meta['profile_visibility']??'hidden')))==='public'?'public':'hidden');
    $meta['profile_slug']=profile_commerce_slug_v900((string)($input['profile_slug']??($meta['profile_slug']??$title)));if($meta['profile_slug']==='')throw new RuntimeException('Choose a valid profile product slug.');
    $meta['profile_featured']=!empty($input['profile_featured']);$meta['profile_sort']=max(0,min(9999,(int)($input['profile_sort']??100)));$meta['profile_cta']=mb_strimwidth(trim((string)($input['profile_cta']??'Buy')),0,40,'');
    if($existing){$pdo->prepare('UPDATE agent_commerce_products_v800 SET title=?,description=?,product_type=?,fulfillment_type=?,payment_mode=?,price_cents=?,deposit_cents=?,currency=?,provider_mode=?,fixed_connection_id=?,hold_minutes=?,refund_before_hours=?,cancellation_fee_cents=?,cancellation_policy=?,metadata_json=?,is_active=1,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$title,$description,$productType,$fulfillmentType,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$providerMode,$fixed,$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy'],json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$productId,$ownerUserId]);}
    else{$pdo->prepare('INSERT INTO agent_commerce_products_v800 (owner_user_id,workspace_owner_user_id,product_key,title,description,product_type,fulfillment_type,payment_mode,price_cents,deposit_cents,currency,provider_mode,fixed_connection_id,hold_minutes,refund_before_hours,cancellation_fee_cents,cancellation_policy,is_active,metadata_json) VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')->execute([$ownerUserId,$key,$title,$description,$productType,$fulfillmentType,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$providerMode,$fixed,$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy'],json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$productId=(int)$pdo->lastInsertId();}
    return profile_commerce_owner_product_v900($pdo,$ownerUserId,$productId)?:throw new RuntimeException('Commerce product could not be saved.');
}

function profile_commerce_token_v900(int $ownerUserId): string
{
    if(!isset($_SESSION['profile_commerce_tokens'])||!is_array($_SESSION['profile_commerce_tokens']))$_SESSION['profile_commerce_tokens']=[];
    $key=(string)$ownerUserId;if(empty($_SESSION['profile_commerce_tokens'][$key]))$_SESSION['profile_commerce_tokens'][$key]=bin2hex(random_bytes(24));
    return (string)$_SESSION['profile_commerce_tokens'][$key];
}

function profile_commerce_token_valid_v900(int $ownerUserId,string $token): bool
{
    $expected=(string)($_SESSION['profile_commerce_tokens'][(string)$ownerUserId]??'');
    return $expected!==''&&$token!==''&&hash_equals($expected,$token);
}

function profile_commerce_checkout_connections_v900(PDO $pdo,array $productRow): array
{
    $owner=(int)$productRow['owner_user_id'];$mode=(string)$productRow['provider_mode'];
    if($mode==='fixed'){$c=agent_commerce_connection_v800($pdo,(int)($productRow['fixed_connection_id']??0),$owner);return $c&&(string)$c['status']==='connected'?[$c]:[];}
    return agent_commerce_connections_v800($pdo,$owner,true);
}
