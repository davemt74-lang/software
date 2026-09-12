<?php
declare(strict_types=1);

require_once __DIR__.'/profile-commerce-v900.php';
require_once __DIR__.'/profile-commerce-checkout-v900.php';
require_once __DIR__.'/profile-commerce-ops-v900.php';

const VP3_HOMESERVER_COMMERCE_AGENT_V1000='homeserver-commerce-agent-v1000-20260912';
const VP3_COMMERCE_AGENT_CONTRACT='commerce-agent-v1';
const VP3_COMMERCE_AGENT_SHA256='b61b1baea945286fb006ef78f7f798b4a604284145a74f71628d43779173bf77';

function homeserver_commerce_agent_v1000_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_commerce_agent_tokens (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        token_enc TEXT NOT NULL,
        token_prefix VARCHAR(16) NOT NULL DEFAULT '',
        status ENUM('active','revoked') NOT NULL DEFAULT 'active',
        provisioned_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_hs_commerce_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_commerce_agent_idempotency (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        operation VARCHAR(96) NOT NULL,
        idempotency_key VARCHAR(160) NOT NULL,
        response_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hs_commerce_agent_idem (user_id,operation,idempotency_key),
        CONSTRAINT fk_hs_commerce_agent_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_commerce_agent_v1000_issue_token(int $userId,bool $rotate=false): array
{
    $pdo=db();if(!$pdo||$userId<1)throw new RuntimeException('Agent Commerce connector is unavailable.');
    homeserver_commerce_agent_v1000_ensure_schema($pdo);
    if(!$rotate){$stmt=$pdo->prepare("SELECT token_enc,token_prefix,provisioned_at FROM homeserver_commerce_agent_tokens WHERE user_id=? AND status='active' LIMIT 1");$stmt->execute([$userId]);$row=$stmt->fetch();if($row){$token=homeserver_vp3_decrypt((string)$row['token_enc']);if($token!=='')return ['token'=>$token,'prefix'=>(string)$row['token_prefix'],'provisioned_at'=>$row['provisioned_at']??null,'rotated'=>false];}}
    $token='vpc_'.rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');$hash=hash('sha256',$token);$prefix=substr($token,0,12);
    $pdo->prepare("INSERT INTO homeserver_commerce_agent_tokens(user_id,token_hash,token_enc,token_prefix,status,provisioned_at) VALUES(?,?,?,?, 'active',NULL) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),token_enc=VALUES(token_enc),token_prefix=VALUES(token_prefix),status='active',provisioned_at=NULL,last_used_at=NULL,updated_at=NOW()")->execute([$userId,$hash,homeserver_vp3_encrypt($token),$prefix]);
    return ['token'=>$token,'prefix'=>$prefix,'provisioned_at'=>null,'rotated'=>true];
}

function homeserver_commerce_agent_v1000_revoke(int $userId): void
{
    $pdo=db();if(!$pdo||$userId<1)return;homeserver_commerce_agent_v1000_ensure_schema($pdo);$pdo->prepare("UPDATE homeserver_commerce_agent_tokens SET status='revoked',provisioned_at=NULL,updated_at=NOW() WHERE user_id=?")->execute([$userId]);
}

function homeserver_commerce_agent_v1000_authenticate(string $rawToken): ?int
{
    $rawToken=trim($rawToken);if($rawToken===''||strlen($rawToken)>256)return null;$pdo=db();if(!$pdo)return null;homeserver_commerce_agent_v1000_ensure_schema($pdo);$stmt=$pdo->prepare("SELECT user_id FROM homeserver_commerce_agent_tokens WHERE token_hash=? AND status='active' LIMIT 1");$stmt->execute([hash('sha256',$rawToken)]);$id=(int)($stmt->fetchColumn()?:0);if($id>0)$pdo->prepare('UPDATE homeserver_commerce_agent_tokens SET last_used_at=NOW() WHERE user_id=?')->execute([$id]);return $id>0?$id:null;
}

function homeserver_commerce_agent_v1000_status(int $userId): array
{
    $pdo=db();if(!$pdo||$userId<1)return ['configured'=>false,'provisioned'=>false,'contract'=>VP3_COMMERCE_AGENT_CONTRACT,'contract_sha256'=>VP3_COMMERCE_AGENT_SHA256];homeserver_commerce_agent_v1000_ensure_schema($pdo);$stmt=$pdo->prepare('SELECT token_prefix,status,provisioned_at,last_used_at,updated_at FROM homeserver_commerce_agent_tokens WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();return ['configured'=>is_array($row)&&($row['status']??'')==='active','provisioned'=>is_array($row)&&($row['status']??'')==='active'&&!empty($row['provisioned_at']),'token_prefix'=>(string)($row['token_prefix']??''),'last_used_at'=>$row['last_used_at']??null,'updated_at'=>$row['updated_at']??null,'contract'=>VP3_COMMERCE_AGENT_CONTRACT,'contract_sha256'=>VP3_COMMERCE_AGENT_SHA256,'version'=>'v10.00'];
}

function homeserver_commerce_agent_v1000_provision(int $userId,bool $rotate=false): array
{
    $status=homeserver_vp3_status($userId,false);if(empty($status['connected'])||empty($status['paired']))throw new RuntimeException('HomeServer must be online and paired first.');$row=homeserver_vp3_connection($userId);if(!$row)throw new RuntimeException('HomeServer connection was not found.');$relay=homeserver_vp3_decrypt((string)($row['relay_token_enc']??''));$hsToken=homeserver_vp3_decrypt((string)($row['homeserver_token_enc']??''));if($relay===''||$hsToken==='')throw new RuntimeException('HomeServer pairing credentials are unavailable.');
    $issued=homeserver_commerce_agent_v1000_issue_token($userId,$rotate);$endpoint=url('/api/homeserver-commerce-agent-v1000.php');if(!preg_match('#^https://#i',$endpoint)&&!preg_match('#^http://(?:127\.0\.0\.1|localhost)(?::\d+)?/#i',$endpoint))throw new RuntimeException('Agent Commerce connector requires HTTPS.');
    try{$response=homeserver_vp3_remote_operation($relay,'vp3.commerce.connector.configure',['endpoint'=>$endpoint,'token'=>(string)$issued['token'],'contract'=>VP3_COMMERCE_AGENT_CONTRACT,'contract_sha256'=>VP3_COMMERCE_AGENT_SHA256,'capabilities'=>array_keys(homeserver_commerce_agent_v1000_permissions())],$hsToken);}catch(Throwable $e){homeserver_commerce_agent_v1000_revoke($userId);throw $e;}
    $accepted=isset($response['payload'])&&is_array($response['payload'])?$response['payload']:$response;if(empty($accepted['configured'])||($accepted['contract_sha256']??'')!==VP3_COMMERCE_AGENT_SHA256){homeserver_commerce_agent_v1000_revoke($userId);throw new RuntimeException('HomeServer did not accept the Agent Commerce contract.');}
    $pdo=db();homeserver_commerce_agent_v1000_ensure_schema($pdo);$pdo->prepare("UPDATE homeserver_commerce_agent_tokens SET status='active',provisioned_at=NOW(),updated_at=NOW() WHERE user_id=?")->execute([$userId]);return homeserver_commerce_agent_v1000_status($userId);
}

function homeserver_commerce_agent_v1000_permissions(): array
{
    return ['vp3.commerce.agent.status'=>'commerce.read','vp3.commerce.catalog.search'=>'commerce.read','vp3.commerce.product.get'=>'commerce.read','vp3.commerce.orders.list'=>'commerce.read','vp3.commerce.order.get'=>'commerce.read','vp3.commerce.checkout.handoff'=>'commerce.order','vp3.commerce.fulfillment.update'=>'commerce.fulfill'];
}

function homeserver_commerce_agent_v1000_operation_fields(): array
{
    return ['vp3.commerce.agent.status'=>[],'vp3.commerce.catalog.search'=>['query','limit','public_only'],'vp3.commerce.product.get'=>['product_id'],'vp3.commerce.orders.list'=>['limit','payment_status','order_status'],'vp3.commerce.order.get'=>['order_id'],'vp3.commerce.checkout.handoff'=>['product_id'],'vp3.commerce.fulfillment.update'=>['order_id','status','idempotency_key']];
}

function homeserver_commerce_agent_v1000_owned_product(PDO $pdo,int $userId,string $opaqueId): ?array
{
    if(!ctype_digit($opaqueId)||strlen($opaqueId)>20)return null;$id=(int)$opaqueId;$stmt=$pdo->prepare('SELECT p.*,b.binding_type,b.binding_id FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id WHERE p.id=? AND (p.owner_user_id=? OR p.workspace_owner_user_id=?) LIMIT 1');$stmt->execute([$id,$userId,$userId]);return $stmt->fetch()?:null;
}
function homeserver_commerce_agent_v1000_owned_order(PDO $pdo,int $userId,string $opaqueId): ?array
{
    if(!ctype_digit($opaqueId)||strlen($opaqueId)>20)return null;$id=(int)$opaqueId;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? AND (owner_user_id=? OR workspace_owner_user_id=?) LIMIT 1');$stmt->execute([$id,$userId,$userId]);return $stmt->fetch()?:null;
}
function homeserver_commerce_agent_v1000_terms_digest(array $product): string
{
    return hash('sha256',(string)json_encode(agent_commerce_product_terms_v800($product),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function homeserver_commerce_agent_v1000_safe_product(PDO $pdo,int $userId,array $product): array
{
    $meta=profile_commerce_metadata_v900($product);$visibility=profile_commerce_visibility_v900($product);$profile=profile_for_user($pdo,$userId,false);$username=profile_username_normalize((string)($profile['username']??''));$isPersonal=(int)($product['owner_user_id']??0)===$userId;$public=$isPersonal&&!empty($product['is_active'])&&$visibility==='public'&&$username!==''&&!empty($profile['is_public']);$url=$public?profile_commerce_product_url_v900($username,$product):'';$checkout=$public?profile_commerce_checkout_url_v900($username,$product):'';
    return ['product_id'=>(string)$product['id'],'title'=>(string)$product['title'],'description'=>(string)$product['description'],'product_type'=>(string)$product['product_type'],'fulfillment_type'=>(string)$product['fulfillment_type'],'payment_mode'=>(string)$product['payment_mode'],'price_minor'=>(int)$product['price_cents'],'deposit_minor'=>(int)$product['deposit_cents'],'currency'=>(string)$product['currency'],'active'=>!empty($product['is_active']),'profile_visibility'=>$visibility,'binding_type'=>(string)($product['binding_type']??''),'terms_digest'=>homeserver_commerce_agent_v1000_terms_digest($product),'public_checkout_available'=>$public,'product_url'=>$url,'checkout_url'=>$checkout,'featured'=>!empty($meta['profile_featured'])];
}
function homeserver_commerce_agent_v1000_safe_order(PDO $pdo,array $order): array
{
    $items=[];foreach(agent_commerce_order_items_v800($pdo,(int)$order['id']) as $item)$items[]=['order_item_id'=>(string)$item['id'],'product_id'=>(string)$item['product_id'],'title'=>(string)$item['title_snapshot'],'product_type'=>(string)$item['product_type_snapshot'],'fulfillment_type'=>(string)$item['fulfillment_type'],'quantity'=>(int)$item['quantity'],'unit_price_minor'=>(int)$item['unit_price_cents'],'total_minor'=>(int)$item['total_cents'],'fulfillment_status'=>(string)$item['fulfillment_status']];
    return ['order_id'=>(string)$order['id'],'order_number'=>(string)$order['order_number'],'payment_status'=>(string)$order['payment_status'],'order_status'=>(string)$order['order_status'],'payment_mode'=>(string)$order['payment_mode'],'currency'=>(string)$order['currency'],'total_minor'=>(int)$order['total_cents'],'paid_minor'=>(int)$order['amount_paid_cents'],'refunded_minor'=>(int)$order['amount_refunded_cents'],'fulfillment_type'=>(string)$order['fulfillment_type'],'payer_email'=>(string)$order['payer_email'],'profile_commerce'=>profile_commerce_order_is_profile_v900($order),'created_at'=>(string)$order['created_at'],'updated_at'=>(string)$order['updated_at'],'items'=>$items];
}

function homeserver_commerce_agent_v1000_dispatch(int $userId,string $operation,array $args): array
{
    $pdo=db();if(!$pdo||!agent_commerce_schema_ready_v800($pdo))throw new RuntimeException('Commerce is unavailable.');$operation=trim($operation);$permissions=homeserver_commerce_agent_v1000_permissions();if(!isset($permissions[$operation]))throw new RuntimeException('Agent Commerce operation is not allowlisted.');$allowed=homeserver_commerce_agent_v1000_operation_fields()[$operation]??[];$unknown=array_diff(array_keys($args),$allowed);if($unknown)throw new InvalidArgumentException('Unsupported Agent Commerce field: '.array_values($unknown)[0]);
    if($operation==='vp3.commerce.agent.status')return ['operations'=>array_keys($permissions),'permissions'=>$permissions,'canonical_owner'=>'vp3-cloud'];
    if($operation==='vp3.commerce.catalog.search'){$query=mb_strtolower(mb_strimwidth(trim((string)($args['query']??'')),0,240,''));$limit=max(1,min(100,(int)($args['limit']??40)));$publicOnly=!empty($args['public_only']);$items=[];foreach(agent_commerce_products_for_owner_v800($pdo,$userId,300) as $row){$safe=homeserver_commerce_agent_v1000_safe_product($pdo,$userId,$row);if($publicOnly&&!$safe['public_checkout_available'])continue;if($query!==''&&!str_contains(mb_strtolower($safe['title'].' '.$safe['description'].' '.$safe['product_type'].' '.$safe['fulfillment_type']),$query))continue;$items[]=$safe;if(count($items)>=$limit)break;}return ['items'=>$items,'count'=>count($items)];}
    if($operation==='vp3.commerce.product.get'){$product=homeserver_commerce_agent_v1000_owned_product($pdo,$userId,trim((string)($args['product_id']??'')));if(!$product)throw new RuntimeException('Commerce product was not found.');return ['product'=>homeserver_commerce_agent_v1000_safe_product($pdo,$userId,$product)];}
    if($operation==='vp3.commerce.orders.list'){$limit=max(1,min(100,(int)($args['limit']??40)));$payment=trim((string)($args['payment_status']??''));$status=trim((string)($args['order_status']??''));$items=[];foreach(agent_commerce_orders_for_owner_v800($pdo,$userId,300) as $row){if($payment!==''&&(string)$row['payment_status']!==$payment)continue;if($status!==''&&(string)$row['order_status']!==$status)continue;$items[]=homeserver_commerce_agent_v1000_safe_order($pdo,$row);if(count($items)>=$limit)break;}return ['items'=>$items,'count'=>count($items)];}
    if($operation==='vp3.commerce.order.get'){$order=homeserver_commerce_agent_v1000_owned_order($pdo,$userId,trim((string)($args['order_id']??'')));if(!$order)throw new RuntimeException('Commerce order was not found.');return ['order'=>homeserver_commerce_agent_v1000_safe_order($pdo,$order)];}
    if($operation==='vp3.commerce.checkout.handoff'){$product=homeserver_commerce_agent_v1000_owned_product($pdo,$userId,trim((string)($args['product_id']??'')));if(!$product)throw new RuntimeException('Commerce product was not found.');$safe=homeserver_commerce_agent_v1000_safe_product($pdo,$userId,$product);if(empty($safe['public_checkout_available']))throw new RuntimeException('This product is not available for public Profile Commerce checkout.');return ['product_id'=>$safe['product_id'],'product_url'=>$safe['product_url'],'checkout_url'=>$safe['checkout_url'],'terms_digest'=>$safe['terms_digest'],'requires_buyer_acceptance'=>true,'creates_order'=>false,'moves_money'=>false];}
    if($operation==='vp3.commerce.fulfillment.update'){$key=trim((string)($args['idempotency_key']??''));if($key===''||strlen($key)>160)throw new InvalidArgumentException('A stable idempotency key is required.');$status=strtolower(trim((string)($args['status']??'')));if(!in_array($status,['processing','fulfilled'],true))throw new InvalidArgumentException('Fulfillment status must be processing or fulfilled.');$orderId=trim((string)($args['order_id']??''));$owned=homeserver_commerce_agent_v1000_owned_order($pdo,$userId,$orderId);if(!$owned||!profile_commerce_order_is_profile_v900($owned)||(int)$owned['owner_user_id']!==$userId)throw new RuntimeException('Eligible Profile Commerce order was not found.');$stmt=$pdo->prepare('SELECT response_json FROM homeserver_commerce_agent_idempotency WHERE user_id=? AND operation=? AND idempotency_key=? LIMIT 1');$stmt->execute([$userId,$operation,$key]);$prior=$stmt->fetchColumn();if(is_string($prior)&&$prior!==''){ $decoded=json_decode($prior,true);if(is_array($decoded))return $decoded;}$updated=profile_commerce_set_fulfillment_v900($pdo,$userId,(int)$owned['id'],$status);$result=['order'=>homeserver_commerce_agent_v1000_safe_order($pdo,$updated)];$pdo->prepare('INSERT INTO homeserver_commerce_agent_idempotency(user_id,operation,idempotency_key,response_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json),updated_at=NOW()')->execute([$userId,$operation,$key,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);return $result;}
    throw new RuntimeException('Unsupported Agent Commerce operation.');
}

function homeserver_commerce_agent_v1000_execute(int $userId,string $operation,array $args): array
{
    $result=homeserver_commerce_agent_v1000_dispatch($userId,$operation,$args);return ['contract'=>VP3_COMMERCE_AGENT_CONTRACT,'contract_sha256'=>VP3_COMMERCE_AGENT_SHA256,...$result];
}
