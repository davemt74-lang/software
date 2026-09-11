<?php
declare(strict_types=1);

function agent_commerce_product_v800(PDO $pdo,int $productId,int $ownerUserId=0): ?array
{
    if($productId<1)return null;$sql='SELECT * FROM agent_commerce_products_v800 WHERE id=?';$args=[$productId];if($ownerUserId>0){$sql.=' AND owner_user_id=?';$args[]=$ownerUserId;}$sql.=' LIMIT 1';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetch()?:null;
}
function agent_commerce_product_by_binding_v800(PDO $pdo,string $bindingType,int $bindingId): ?array
{
    $bindingType=trim($bindingType);if($bindingType===''||$bindingId<1)return null;$stmt=$pdo->prepare('SELECT p.* FROM agent_commerce_product_bindings_v800 b INNER JOIN agent_commerce_products_v800 p ON p.id=b.product_id WHERE b.binding_type=? AND b.binding_id=? LIMIT 1');$stmt->execute([$bindingType,$bindingId]);return $stmt->fetch()?:null;
}
function agent_commerce_upsert_bound_product_v800(PDO $pdo,int $ownerUserId,?int $workspaceOwnerId,string $productKey,string $bindingType,int $bindingId,array $input): array
{
    if($ownerUserId<1||$bindingId<1)throw new RuntimeException('Product ownership and binding are required.');$terms=agent_commerce_validate_terms_v800($input);$productKey=mb_strimwidth(trim($productKey),0,190,'');if($productKey==='')throw new RuntimeException('Product key is required.');$title=mb_strimwidth(trim((string)($input['title']??'Product')),0,190,'');if($title==='')$title='Product';$description=mb_strimwidth(trim((string)($input['description']??'')),0,1000,'');$productType=strtolower(trim((string)($input['product_type']??'service')));if(!in_array($productType,['service','digital','membership','event','gift','physical','other'],true))$productType='other';$fulfillmentType=strtolower(trim((string)($input['fulfillment_type']??'none')));if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/',$fulfillmentType))$fulfillmentType='none';$providerMode=strtolower(trim((string)($input['provider_mode']??'guest_choice')));if(!in_array($providerMode,['guest_choice','fixed','team_primary'],true))$providerMode='guest_choice';$fixed=max(0,(int)($input['fixed_connection_id']??0))?:null;if($providerMode==='fixed'){$connection=agent_commerce_connection_v800($pdo,(int)$fixed,$ownerUserId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Choose a connected provider for this product.');}else{$fixed=null;}$metadata=is_array($input['metadata']??null)?$input['metadata']:[];$existing=agent_commerce_product_by_binding_v800($pdo,$bindingType,$bindingId);$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();try{
      if($existing){if((int)$existing['owner_user_id']!==$ownerUserId)throw new RuntimeException('Product binding belongs to another account.');$pdo->prepare('UPDATE agent_commerce_products_v800 SET workspace_owner_user_id=?,product_key=?,title=?,description=?,product_type=?,fulfillment_type=?,payment_mode=?,price_cents=?,deposit_cents=?,currency=?,provider_mode=?,fixed_connection_id=?,hold_minutes=?,refund_before_hours=?,cancellation_fee_cents=?,cancellation_policy=?,is_active=1,metadata_json=?,updated_at=NOW() WHERE id=?')->execute([$workspaceOwnerId,$productKey,$title,$description,$productType,$fulfillmentType,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$providerMode,$fixed,$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy'],$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,(int)$existing['id']]);$productId=(int)$existing['id'];}
      else{$pdo->prepare('INSERT INTO agent_commerce_products_v800 (owner_user_id,workspace_owner_user_id,product_key,title,description,product_type,fulfillment_type,payment_mode,price_cents,deposit_cents,currency,provider_mode,fixed_connection_id,hold_minutes,refund_before_hours,cancellation_fee_cents,cancellation_policy,is_active,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')->execute([$ownerUserId,$workspaceOwnerId,$productKey,$title,$description,$productType,$fulfillmentType,$terms['payment_mode'],$terms['price_cents'],$terms['deposit_cents'],$terms['currency'],$providerMode,$fixed,$terms['hold_minutes'],$terms['refund_before_hours'],$terms['cancellation_fee_cents'],$terms['cancellation_policy'],$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);$productId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO agent_commerce_product_bindings_v800 (product_id,binding_type,binding_id) VALUES (?,?,?)')->execute([$productId,$bindingType,$bindingId]);}
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return agent_commerce_product_v800($pdo,$productId,$ownerUserId)?:throw new RuntimeException('Commerce product could not be saved.');
}
function agent_commerce_product_terms_v800(array $product): array
{
    return ['product_id'=>(int)($product['id']??0),'payment_mode'=>(string)($product['payment_mode']??'free'),'price_cents'=>(int)($product['price_cents']??0),'deposit_cents'=>(int)($product['deposit_cents']??0),'currency'=>(string)($product['currency']??'usd'),'provider_mode'=>(string)($product['provider_mode']??'guest_choice'),'fixed_connection_id'=>(int)($product['fixed_connection_id']??0)?:null,'hold_minutes'=>(int)($product['hold_minutes']??30),'refund_before_hours'=>(int)($product['refund_before_hours']??24),'cancellation_fee_cents'=>(int)($product['cancellation_fee_cents']??0),'cancellation_policy'=>(string)($product['cancellation_policy']??'')];
}
function agent_commerce_amount_due_v800(array $productOrTerms): int
{
    return match((string)($productOrTerms['payment_mode']??'free')){'full'=>(int)($productOrTerms['price_cents']??0),'deposit'=>(int)($productOrTerms['deposit_cents']??0),default=>0};
}
function agent_commerce_order_number_v800(): string
{
    return 'VP3-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
}
function agent_commerce_order_v800(PDO $pdo,int $orderId): ?array
{
    if($orderId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? LIMIT 1');$stmt->execute([$orderId]);return $stmt->fetch()?:null;
}
function agent_commerce_order_by_fulfillment_v800(PDO $pdo,string $type,int $refId): ?array
{
    if($type===''||$refId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE fulfillment_ref_type=? AND fulfillment_ref_id=? LIMIT 1');$stmt->execute([$type,$refId]);return $stmt->fetch()?:null;
}
function agent_commerce_order_by_group_v800(PDO $pdo,string $type,int $groupId): ?array
{
    if($type===''||$groupId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE fulfillment_group_type=? AND fulfillment_group_id=? LIMIT 1');$stmt->execute([$type,$groupId]);return $stmt->fetch()?:null;
}
function agent_commerce_order_items_v800(PDO $pdo,int $orderId): array
{
    if($orderId<1)return [];$stmt=$pdo->prepare('SELECT * FROM agent_commerce_order_items_v800 WHERE order_id=? ORDER BY id');$stmt->execute([$orderId]);return $stmt->fetchAll()?:[];
}
function agent_commerce_create_order_v800(PDO $pdo,array $product,array $input): array
{
    $productId=(int)($product['id']??0);$ownerId=(int)($product['owner_user_id']??0);if($productId<1||$ownerId<1||empty($product['is_active']))throw new RuntimeException('Commerce product is not available.');$due=agent_commerce_amount_due_v800($product);if($due<1)throw new RuntimeException('Free products do not require a commerce order.');$connectionId=max(0,(int)($input['connection_id']??0))?:null;$connection=null;if($connectionId){$connection=agent_commerce_connection_v800($pdo,$connectionId,$ownerId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Selected payment provider is unavailable.');}$holdMinutes=max(30,min(120,(int)($product['hold_minutes']??30)));$hold=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$holdMinutes.' minutes')->format('Y-m-d H:i:s');$fee=(int)floor((int)$product['price_cents']*(int)agent_commerce_config_v800()['platform_fee_bps']/10000);$terms=agent_commerce_product_terms_v800($product);$terms['product_key']=(string)$product['product_key'];$terms['product_type']=(string)$product['product_type'];$terms['fulfillment_type']=(string)$product['fulfillment_type'];$termsJson=json_encode($terms,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$refType=mb_strimwidth(trim((string)($input['fulfillment_ref_type']??'')),0,48,'');$refId=max(0,(int)($input['fulfillment_ref_id']??0))?:null;$groupType=mb_strimwidth(trim((string)($input['fulfillment_group_type']??'')),0,48,'');$groupId=max(0,(int)($input['fulfillment_group_id']??0))?:null;$payer=mb_strimwidth(strtolower(trim((string)($input['payer_email']??''))),0,190,'');$workspace=(int)($product['workspace_owner_user_id']??0)?:null;$metadata=is_array($input['metadata']??null)?$input['metadata']:[];$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();try{$pdo->prepare("INSERT INTO agent_commerce_orders_v800 (order_number,owner_user_id,workspace_owner_user_id,connection_id,provider_snapshot,external_account_snapshot,order_status,payment_status,payment_mode,currency,subtotal_cents,total_cents,amount_due_cents,platform_fee_cents,payer_email,fulfillment_type,fulfillment_ref_type,fulfillment_ref_id,fulfillment_group_type,fulfillment_group_id,hold_expires_at,terms_snapshot_json,metadata_json) VALUES (?,?,?,?,?,?,'pending_payment','awaiting_payment',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([agent_commerce_order_number_v800(),$ownerId,$workspace,$connectionId,$connection['provider']??'',$connection['external_account_id']??'',(string)$product['payment_mode'],(string)$product['currency'],(int)$product['price_cents'],(int)$product['price_cents'],$due,$fee,$payer,(string)$product['fulfillment_type'],$refType,$refId,$groupType,$groupId,$hold,$termsJson,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);$orderId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO agent_commerce_order_items_v800 (order_id,product_id,title_snapshot,product_type_snapshot,fulfillment_type,fulfillment_ref_type,fulfillment_ref_id,quantity,unit_price_cents,total_cents,fulfillment_status,terms_snapshot_json,metadata_json) VALUES (?,?,?,?,?,?,?,1,?,?,'pending',?,?)")->execute([$orderId,$productId,(string)$product['title'],(string)$product['product_type'],(string)$product['fulfillment_type'],$groupId?$groupType:$refType,$groupId?:$refId,(int)$product['price_cents'],(int)$product['price_cents'],$termsJson,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);agent_commerce_audit_v800($pdo,$orderId,$ownerId,$workspace,'system',null,null,'order_created','','awaiting_payment',$due,['product_id'=>$productId,'hold_expires_at'=>$hold]);if($owns)$pdo->commit();}catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}return agent_commerce_order_v800($pdo,$orderId)?:throw new RuntimeException('Commerce order could not be created.');
}
function agent_commerce_checkout_attempt_v800(PDO $pdo,int $attemptId): ?array
{
    if($attemptId<1)return null;$stmt=$pdo->prepare('SELECT a.*,o.owner_user_id,o.workspace_owner_user_id,o.payment_status order_payment_status FROM agent_commerce_checkout_attempts_v800 a JOIN agent_commerce_orders_v800 o ON o.id=a.order_id WHERE a.id=? LIMIT 1');$stmt->execute([$attemptId]);return $stmt->fetch()?:null;
}
function agent_commerce_attempt_by_external_v800(PDO $pdo,string $provider,string $externalId): ?array
{
    $provider=strtolower(trim($provider));$externalId=trim($externalId);if($externalId==='')return null;$stmt=$pdo->prepare('SELECT a.*,o.owner_user_id,o.workspace_owner_user_id,o.payment_status order_payment_status FROM agent_commerce_checkout_attempts_v800 a JOIN agent_commerce_orders_v800 o ON o.id=a.order_id WHERE a.provider=? AND (a.external_session_id=? OR a.external_payment_id=?) ORDER BY a.id DESC LIMIT 1');$stmt->execute([$provider,$externalId,$externalId]);return $stmt->fetch()?:null;
}
function agent_commerce_available_connections_v800(PDO $pdo,array $order): array
{
    $selected=(int)($order['connection_id']??0);$owner=(int)($order['workspace_owner_user_id']??0)>0?(int)$order['workspace_owner_user_id']:(int)$order['owner_user_id'];if($selected>0&&trim((string)($order['provider_snapshot']??''))!==''){$c=agent_commerce_connection_v800($pdo,$selected,$owner);return $c&&$c['status']==='connected'?[$c]:[];}$snapshot=json_decode((string)($order['terms_snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];if((string)($snapshot['provider_mode']??'guest_choice')==='fixed')return [];return agent_commerce_connections_v800($pdo,$owner,true);
}
function agent_commerce_order_title_v800(PDO $pdo,array $order): string
{
    $stmt=$pdo->prepare('SELECT title_snapshot FROM agent_commerce_order_items_v800 WHERE order_id=? ORDER BY id LIMIT 1');$stmt->execute([(int)$order['id']]);return trim((string)$stmt->fetchColumn())?:'VP3 purchase';
}

function agent_commerce_products_for_owner_v800(PDO $pdo,int $ownerUserId,int $limit=200): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(500,$limit));$stmt=$pdo->prepare("SELECT p.*,b.binding_type,b.binding_id FROM agent_commerce_products_v800 p LEFT JOIN agent_commerce_product_bindings_v800 b ON b.product_id=p.id WHERE p.owner_user_id=? OR p.workspace_owner_user_id=? ORDER BY p.is_active DESC,p.updated_at DESC,p.id DESC LIMIT {$limit}");$stmt->execute([$ownerUserId,$ownerUserId]);return $stmt->fetchAll()?:[];
}
function agent_commerce_orders_for_owner_v800(PDO $pdo,int $ownerUserId,int $limit=200): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(500,$limit));$stmt=$pdo->prepare("SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? OR workspace_owner_user_id=? ORDER BY created_at DESC,id DESC LIMIT {$limit}");$stmt->execute([$ownerUserId,$ownerUserId]);return $stmt->fetchAll()?:[];
}
function agent_commerce_refunds_for_order_v800(PDO $pdo,int $orderId): array
{
    if($orderId<1)return [];$stmt=$pdo->prepare('SELECT * FROM agent_commerce_refunds_v800 WHERE order_id=? ORDER BY id DESC');$stmt->execute([$orderId]);return $stmt->fetchAll()?:[];
}
function agent_commerce_balance_due_v800(array $order): int
{
    return max(0,(int)($order['total_cents']??0)-(int)($order['amount_paid_cents']??0));
}
