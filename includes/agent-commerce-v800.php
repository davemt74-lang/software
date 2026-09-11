<?php
declare(strict_types=1);

/**
 * VP3 Commerce v8.00
 *
 * Canonical commerce domain:
 * Product / Offer -> Order / Order Item -> Payment -> Fulfillment.
 *
 * Scheduling remains canonical for appointment fulfillment. This layer stores
 * only the commerce relationship to a booking; it never replaces booking state.
 */
const VP3_AGENT_COMMERCE_V800='agent-commerce-v800-20260911';

function agent_commerce_schema_ready_v800(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach([
        'agent_commerce_products_v800','agent_commerce_offers_v800',
        'agent_commerce_orders_v800','agent_commerce_order_items_v800',
        'agent_commerce_payment_connections_v800','agent_commerce_payments_v800',
        'agent_commerce_checkout_attempts_v800','agent_commerce_refunds_v800',
        'agent_commerce_fulfillments_v800','agent_commerce_webhook_events_v800',
        'agent_commerce_audit_v800'
    ] as $table)if(!table_exists($table))return false;
    return column_exists('agent_commerce_orders_v800','payment_status')
        &&column_exists('agent_commerce_order_items_v800','fulfillment_type');
}

function agent_commerce_ensure_schema_v800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Commerce database is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_products_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      source_key VARCHAR(190) NOT NULL,
      product_type VARCHAR(32) NOT NULL DEFAULT 'service',
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_product_source (owner_user_id,source_key),
      INDEX idx_commerce_product_owner (owner_user_id,status,product_type,id),
      INDEX idx_commerce_product_workspace (workspace_owner_user_id,status,id),
      CONSTRAINT fk_commerce_product_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_product_workspace FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_payment_connections_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      authority VARCHAR(24) NOT NULL DEFAULT 'cloud',
      external_account_id VARCHAR(190) NOT NULL,
      account_label VARCHAR(190) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'connected',
      capabilities_json LONGTEXT NULL,
      legacy_connection_id BIGINT UNSIGNED NULL,
      last_verified_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_provider_account (owner_user_id,provider,authority,external_account_id),
      UNIQUE KEY uq_commerce_legacy_connection (legacy_connection_id),
      INDEX idx_commerce_provider_owner (owner_user_id,status,authority,provider,id),
      CONSTRAINT fk_commerce_provider_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_offers_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      offer_key VARCHAR(190) NOT NULL,
      title VARCHAR(190) NOT NULL DEFAULT '',
      payment_mode VARCHAR(24) NOT NULL DEFAULT 'free',
      price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      deposit_cents INT UNSIGNED NOT NULL DEFAULT 0,
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      provider_mode VARCHAR(24) NOT NULL DEFAULT 'guest_choice',
      fixed_connection_id BIGINT UNSIGNED NULL,
      platform_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      terms_json LONGTEXT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_offer_key (product_id,offer_key),
      INDEX idx_commerce_offer_product (product_id,status,id),
      CONSTRAINT fk_commerce_offer_product FOREIGN KEY (product_id) REFERENCES agent_commerce_products_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_offer_connection FOREIGN KEY (fixed_connection_id) REFERENCES agent_commerce_payment_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_orders_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      source_type VARCHAR(48) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      customer_email VARCHAR(190) NOT NULL DEFAULT '',
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_due_cents INT UNSIGNED NOT NULL DEFAULT 0,
      platform_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
      order_status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
      payment_status VARCHAR(32) NOT NULL DEFAULT 'awaiting_payment',
      payment_authority VARCHAR(24) NOT NULL DEFAULT '',
      provider_snapshot VARCHAR(24) NOT NULL DEFAULT '',
      external_account_snapshot VARCHAR(190) NOT NULL DEFAULT '',
      hold_expires_at DATETIME NULL,
      paid_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      refunded_at DATETIME NULL,
      terms_snapshot_json LONGTEXT NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_order_source (owner_user_id,source_type,source_id),
      INDEX idx_commerce_order_owner (owner_user_id,order_status,payment_status,id),
      INDEX idx_commerce_order_workspace (workspace_owner_user_id,order_status,id),
      INDEX idx_commerce_order_hold (payment_status,hold_expires_at,id),
      CONSTRAINT fk_commerce_order_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_order_workspace FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_order_items_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      offer_id BIGINT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      quantity INT UNSIGNED NOT NULL DEFAULT 1,
      unit_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
      total_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
      fulfillment_type VARCHAR(32) NOT NULL DEFAULT 'other',
      item_snapshot_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_commerce_item_order (order_id,id),
      INDEX idx_commerce_item_product (product_id,id),
      CONSTRAINT fk_commerce_item_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_item_product FOREIGN KEY (product_id) REFERENCES agent_commerce_products_v800(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_item_offer FOREIGN KEY (offer_id) REFERENCES agent_commerce_offers_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_fulfillments_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_item_id BIGINT UNSIGNED NOT NULL,
      fulfillment_type VARCHAR(32) NOT NULL,
      resource_type VARCHAR(48) NOT NULL DEFAULT '',
      resource_id BIGINT UNSIGNED NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      metadata_json LONGTEXT NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_fulfillment (order_item_id,fulfillment_type,resource_type,resource_id),
      INDEX idx_commerce_fulfillment_resource (resource_type,resource_id,status,id),
      CONSTRAINT fk_commerce_fulfillment_item FOREIGN KEY (order_item_id) REFERENCES agent_commerce_order_items_v800(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_payments_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      connection_id BIGINT UNSIGNED NULL,
      provider VARCHAR(24) NOT NULL,
      authority VARCHAR(24) NOT NULL DEFAULT 'cloud',
      external_account_snapshot VARCHAR(190) NOT NULL DEFAULT '',
      external_payment_id VARCHAR(190) NULL,
      amount_cents INT UNSIGNED NOT NULL,
      currency CHAR(3) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      paid_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_payment_external (provider,authority,external_payment_id),
      INDEX idx_commerce_payment_order (order_id,status,id),
      CONSTRAINT fk_commerce_payment_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_payment_connection FOREIGN KEY (connection_id) REFERENCES agent_commerce_payment_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_checkout_attempts_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      payment_id BIGINT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NULL,
      provider VARCHAR(24) NOT NULL,
      authority VARCHAR(24) NOT NULL DEFAULT 'cloud',
      external_session_id VARCHAR(190) NOT NULL,
      external_payment_id VARCHAR(190) NULL,
      checkout_url TEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'open',
      amount_cents INT UNSIGNED NOT NULL,
      currency CHAR(3) NOT NULL,
      idempotency_key VARCHAR(160) NOT NULL,
      expires_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_checkout_session (provider,authority,external_session_id),
      UNIQUE KEY uq_commerce_checkout_idempotency (idempotency_key),
      INDEX idx_commerce_checkout_order (order_id,status,id),
      CONSTRAINT fk_commerce_checkout_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_checkout_payment FOREIGN KEY (payment_id) REFERENCES agent_commerce_payments_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_checkout_connection FOREIGN KEY (connection_id) REFERENCES agent_commerce_payment_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_refunds_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      payment_id BIGINT UNSIGNED NULL,
      provider VARCHAR(24) NOT NULL,
      authority VARCHAR(24) NOT NULL DEFAULT 'cloud',
      external_refund_id VARCHAR(190) NOT NULL,
      amount_cents INT UNSIGNED NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      reason VARCHAR(500) NOT NULL DEFAULT '',
      approved_by_user_id INT UNSIGNED NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_refund_external (provider,authority,external_refund_id),
      INDEX idx_commerce_refund_order (order_id,status,id),
      CONSTRAINT fk_commerce_refund_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_refund_payment FOREIGN KEY (payment_id) REFERENCES agent_commerce_payments_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_refund_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_webhook_events_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      provider VARCHAR(24) NOT NULL,
      authority VARCHAR(24) NOT NULL DEFAULT 'cloud',
      external_event_id VARCHAR(190) NOT NULL,
      event_type VARCHAR(120) NOT NULL,
      payload_sha256 CHAR(64) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'processing',
      error_message VARCHAR(1000) NOT NULL DEFAULT '',
      processed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_webhook_event (provider,authority,external_event_id),
      INDEX idx_commerce_webhook_status (provider,authority,status,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_audit_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      actor_type VARCHAR(24) NOT NULL DEFAULT 'system',
      actor_user_id INT UNSIGNED NULL,
      actor_agent_id BIGINT UNSIGNED NULL,
      event_type VARCHAR(80) NOT NULL,
      from_status VARCHAR(32) NOT NULL DEFAULT '',
      to_status VARCHAR(32) NOT NULL DEFAULT '',
      amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_commerce_audit_order (order_id,created_at,id),
      INDEX idx_commerce_audit_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_commerce_audit_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_audit_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_audit_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_audit_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_audit_agent FOREIGN KEY (actor_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(table_exists('agent_paid_bookings_v800')&&!column_exists('agent_paid_bookings_v800','commerce_order_id')){
        $pdo->exec("ALTER TABLE agent_paid_bookings_v800 ADD COLUMN commerce_order_id BIGINT UNSIGNED NULL AFTER id, ADD UNIQUE KEY uq_paid_commerce_order (commerce_order_id), ADD CONSTRAINT fk_paid_commerce_order FOREIGN KEY (commerce_order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE RESTRICT");
    }
}

function agent_commerce_audit_v800(PDO $pdo,?int $orderId,int $ownerUserId,?int $workspaceOwnerId,string $actorType,string $eventType,string $from='',string $to='',int $amountCents=0,array $metadata=[],?int $actorUserId=null,?int $actorAgentId=null): void
{
    if($ownerUserId<1)return;
    $pdo->prepare('INSERT INTO agent_commerce_audit_v800 (order_id,owner_user_id,workspace_owner_user_id,actor_type,actor_user_id,actor_agent_id,event_type,from_status,to_status,amount_cents,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderId,$ownerUserId,$workspaceOwnerId,$actorType,$actorUserId,$actorAgentId,$eventType,$from,$to,max(0,$amountCents),$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function agent_commerce_sync_cloud_connection_v800(PDO $pdo,array $legacy): ?array
{
    if(!agent_commerce_schema_ready_v800($pdo)||empty($legacy['id'])||empty($legacy['owner_user_id'])||empty($legacy['provider'])||empty($legacy['external_account_id']))return null;
    $caps=json_decode((string)($legacy['capabilities_json']??''),true);if(!is_array($caps))$caps=[];
    $pdo->prepare("INSERT INTO agent_commerce_payment_connections_v800 (owner_user_id,provider,authority,external_account_id,account_label,status,capabilities_json,legacy_connection_id,last_verified_at,last_error) VALUES (?,?, 'cloud',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_label=VALUES(account_label),status=VALUES(status),capabilities_json=VALUES(capabilities_json),legacy_connection_id=VALUES(legacy_connection_id),last_verified_at=VALUES(last_verified_at),last_error=VALUES(last_error),updated_at=NOW()")
        ->execute([(int)$legacy['owner_user_id'],(string)$legacy['provider'],(string)$legacy['external_account_id'],(string)($legacy['account_label']??''),(string)($legacy['status']??'connected'),json_encode($caps,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$legacy['id'],$legacy['last_verified_at']??null,(string)($legacy['last_error']??'')]);
    $stmt=$pdo->prepare("SELECT * FROM agent_commerce_payment_connections_v800 WHERE owner_user_id=? AND provider=? AND authority='cloud' AND external_account_id=? LIMIT 1");$stmt->execute([(int)$legacy['owner_user_id'],(string)$legacy['provider'],(string)$legacy['external_account_id']]);return $stmt->fetch()?:null;
}

function agent_commerce_product_offer_for_appointment_v800(PDO $pdo,int $ownerUserId,string $sourceType,int $sourceId,string $title,array $terms,?int $workspaceOwnerId=null): array
{
    agent_commerce_ensure_schema_v800($pdo);
    if($ownerUserId<1||$sourceId<1)throw new RuntimeException('Commerce appointment source is invalid.');
    $sourceKey=$sourceType.':'.$sourceId;$productType='service';$title=mb_strimwidth(trim($title)?:'Appointment',0,190,'');
    $metadata=['fulfillment_type'=>'appointment','source_type'=>$sourceType,'source_id'=>$sourceId];
    $pdo->prepare("INSERT INTO agent_commerce_products_v800 (owner_user_id,workspace_owner_user_id,source_key,product_type,title,status,metadata_json) VALUES (?,?,?,?,?,'active',?) ON DUPLICATE KEY UPDATE workspace_owner_user_id=VALUES(workspace_owner_user_id),product_type=VALUES(product_type),title=VALUES(title),status='active',metadata_json=VALUES(metadata_json),updated_at=NOW()")
        ->execute([$ownerUserId,$workspaceOwnerId,$sourceKey,$productType,$title,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $stmt=$pdo->prepare('SELECT * FROM agent_commerce_products_v800 WHERE owner_user_id=? AND source_key=? LIMIT 1');$stmt->execute([$ownerUserId,$sourceKey]);$product=$stmt->fetch()?:throw new RuntimeException('Commerce product could not be loaded.');
    $mode=(string)($terms['payment_mode']??'free');$price=max(0,(int)($terms['price_cents']??0));$deposit=max(0,(int)($terms['deposit_cents']??0));$currency=strtolower((string)($terms['currency']??'usd'));$hold=max(1,(int)($terms['hold_minutes']??30));
    $offerKey='default';$termsJson=json_encode($terms,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO agent_commerce_offers_v800 (product_id,offer_key,title,payment_mode,price_cents,deposit_cents,currency,provider_mode,platform_fee_cents,hold_minutes,terms_json,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE title=VALUES(title),payment_mode=VALUES(payment_mode),price_cents=VALUES(price_cents),deposit_cents=VALUES(deposit_cents),currency=VALUES(currency),provider_mode=VALUES(provider_mode),platform_fee_cents=VALUES(platform_fee_cents),hold_minutes=VALUES(hold_minutes),terms_json=VALUES(terms_json),status='active',updated_at=NOW()")
        ->execute([(int)$product['id'],$offerKey,$title,$mode,$price,$deposit,$currency,(string)($terms['provider_mode']??'fixed'),max(0,(int)($terms['platform_fee_cents']??0)),$hold,$termsJson]);
    $stmt=$pdo->prepare('SELECT * FROM agent_commerce_offers_v800 WHERE product_id=? AND offer_key=? LIMIT 1');$stmt->execute([(int)$product['id'],$offerKey]);$offer=$stmt->fetch()?:throw new RuntimeException('Commerce offer could not be loaded.');
    return ['product'=>$product,'offer'=>$offer];
}

function agent_commerce_order_for_source_v800(PDO $pdo,int $ownerUserId,string $sourceType,int $sourceId): ?array
{
    if($ownerUserId<1||$sourceId<1)return null;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? AND source_type=? AND source_id=? LIMIT 1');$stmt->execute([$ownerUserId,$sourceType,$sourceId]);return $stmt->fetch()?:null;
}

function agent_commerce_create_appointment_order_v800(PDO $pdo,array $paid,array $booking,array $terms,bool $team=false): array
{
    agent_commerce_ensure_schema_v800($pdo);
    $ownerId=(int)($paid['owner_user_id']??0);$workspaceId=(int)($paid['workspace_owner_user_id']??0)?:null;
    $sourceType=$team?'team_appointment_booking':'appointment_booking';$sourceId=$team?(int)($paid['team_booking_id']??0):(int)($paid['booking_id']??0);
    if($ownerId<1||$sourceId<1)throw new RuntimeException('Commerce order source is invalid.');
    $sourceCatalogType=$team?'team_scheduling_pool':'scheduling_event';$sourceCatalogId=$team?(int)($terms['pool_id']??0):(int)($terms['event_type_id']??0);
    $title=trim((string)($booking['event_title']??$booking['pool_name']??$booking['name']??''))?:'Appointment';
    $catalog=agent_commerce_product_offer_for_appointment_v800($pdo,$ownerId,$sourceCatalogType,$sourceCatalogId,$title,$terms,$workspaceId);
    $existing=agent_commerce_order_for_source_v800($pdo,$ownerId,$sourceType,$sourceId);
    if($existing)return $existing;
    $metadata=['appointment_adapter_version'=>'v8.00','legacy_paid_booking_id'=>(int)($paid['id']??0)];
    $stmt=$pdo->prepare("INSERT INTO agent_commerce_orders_v800 (owner_user_id,workspace_owner_user_id,source_type,source_id,customer_email,currency,subtotal_cents,amount_due_cents,platform_fee_cents,order_status,payment_status,hold_expires_at,terms_snapshot_json,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,'pending_payment','awaiting_payment',?,?,?)");
    $stmt->execute([$ownerId,$workspaceId,$sourceType,$sourceId,mb_strimwidth(strtolower(trim((string)($paid['payer_email']??''))),0,190,''),(string)$paid['currency'],(int)$paid['amount_total_cents'],(int)$paid['amount_due_cents'],(int)$paid['platform_fee_cents'],$paid['hold_expires_at']??null,(string)($paid['terms_snapshot_json']??''),json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $orderId=(int)$pdo->lastInsertId();$product=$catalog['product'];$offer=$catalog['offer'];$snapshot=['product_id'=>(int)$product['id'],'offer_id'=>(int)$offer['id'],'source_type'=>$sourceCatalogType,'source_id'=>$sourceCatalogId,'terms'=>$terms];
    $pdo->prepare("INSERT INTO agent_commerce_order_items_v800 (order_id,product_id,offer_id,title,quantity,unit_amount_cents,total_amount_cents,fulfillment_type,item_snapshot_json) VALUES (?,?,?, ?,1,?,?,'appointment',?)")
        ->execute([$orderId,(int)$product['id'],(int)$offer['id'],$title,(int)$paid['amount_total_cents'],(int)$paid['amount_total_cents'],json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $itemId=(int)$pdo->lastInsertId();$resourceType=$team?'team_scheduling_booking':'scheduling_booking';
    $pdo->prepare("INSERT INTO agent_commerce_fulfillments_v800 (order_item_id,fulfillment_type,resource_type,resource_id,status,metadata_json) VALUES (?,'appointment',?,?, 'pending_payment',?)")
        ->execute([$itemId,$resourceType,$sourceId,json_encode(['canonical'=>true],JSON_UNESCAPED_SLASHES)]);
    if(!empty($paid['id'])&&table_exists('agent_paid_bookings_v800')&&column_exists('agent_paid_bookings_v800','commerce_order_id'))$pdo->prepare('UPDATE agent_paid_bookings_v800 SET commerce_order_id=? WHERE id=? AND commerce_order_id IS NULL')->execute([$orderId,(int)$paid['id']]);
    agent_commerce_audit_v800($pdo,$orderId,$ownerId,$workspaceId,'system','order_created','','pending_payment',(int)$paid['amount_due_cents'],['fulfillment_type'=>'appointment']);
    return agent_commerce_order_for_source_v800($pdo,$ownerId,$sourceType,$sourceId)?:throw new RuntimeException('Commerce order could not be reloaded.');
}

function agent_commerce_order_for_paid_v800(PDO $pdo,array $paid): ?array
{
    $orderId=(int)($paid['commerce_order_id']??0);if($orderId>0){$stmt=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=? LIMIT 1');$stmt->execute([$orderId]);$row=$stmt->fetch();if($row)return $row;}
    $team=(int)($paid['team_booking_id']??0)>0;$sourceType=$team?'team_appointment_booking':'appointment_booking';$sourceId=$team?(int)$paid['team_booking_id']:(int)$paid['booking_id'];return agent_commerce_order_for_source_v800($pdo,(int)$paid['owner_user_id'],$sourceType,$sourceId);
}

function agent_commerce_sync_checkout_v800(PDO $pdo,array $paid,array $legacyAttempt,string $authority='cloud'): ?array
{
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return null;$authority=in_array($authority,['cloud','homeserver'],true)?$authority:'cloud';
    $legacyConnectionId=(int)($legacyAttempt['connection_id']??0);$commerceConnectionId=null;if($legacyConnectionId>0&&table_exists('agent_appointment_payment_connections_v800')){$stmt=$pdo->prepare('SELECT * FROM agent_appointment_payment_connections_v800 WHERE id=? LIMIT 1');$stmt->execute([$legacyConnectionId]);$legacy=$stmt->fetch();if($legacy){$connection=agent_commerce_sync_cloud_connection_v800($pdo,$legacy);$commerceConnectionId=$connection['id']??null;}}
    $provider=(string)$legacyAttempt['provider'];$external=(string)$legacyAttempt['external_session_id'];$idem=(string)$legacyAttempt['idempotency_key'];
    $pdo->prepare("INSERT INTO agent_commerce_checkout_attempts_v800 (order_id,connection_id,provider,authority,external_session_id,external_payment_id,checkout_url,status,amount_cents,currency,idempotency_key,expires_at,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_payment_id=VALUES(external_payment_id),checkout_url=VALUES(checkout_url),status=VALUES(status),completed_at=VALUES(completed_at),updated_at=NOW()")
        ->execute([(int)$order['id'],$commerceConnectionId,$provider,$authority,$external,trim((string)($legacyAttempt['external_payment_id']??''))?:null,$legacyAttempt['checkout_url']??null,(string)$legacyAttempt['status'],(int)$legacyAttempt['amount_cents'],(string)$legacyAttempt['currency'],$idem,$legacyAttempt['expires_at']??null,$legacyAttempt['completed_at']??null]);
    $pdo->prepare('UPDATE agent_commerce_orders_v800 SET payment_authority=?,provider_snapshot=?,external_account_snapshot=?,updated_at=NOW() WHERE id=? AND payment_status=?')->execute([$authority,$provider,(string)($paid['external_account_snapshot']??''),(int)$order['id'],'awaiting_payment']);
    $stmt=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE idempotency_key=? LIMIT 1');$stmt->execute([$idem]);return $stmt->fetch()?:null;
}

function agent_commerce_mark_paid_v800(PDO $pdo,array $paid,string $provider,string $externalPaymentId,int $amountCents,string $currency,string $authority='cloud'): void
{
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return;$authority=in_array($authority,['cloud','homeserver'],true)?$authority:'cloud';
    $pdo->prepare("INSERT INTO agent_commerce_payments_v800 (order_id,provider,authority,external_account_snapshot,external_payment_id,amount_cents,currency,status,paid_at) VALUES (?,?,?,?,?,?,?,'paid',NOW()) ON DUPLICATE KEY UPDATE amount_cents=VALUES(amount_cents),currency=VALUES(currency),status='paid',paid_at=COALESCE(paid_at,NOW()),updated_at=NOW()")
        ->execute([(int)$order['id'],$provider,$authority,(string)($paid['external_account_snapshot']??''),trim($externalPaymentId)?:null,max(0,$amountCents),strtolower($currency)]);
    $pdo->prepare("UPDATE agent_commerce_orders_v800 SET amount_paid_cents=?,payment_status='paid',order_status='confirmed',payment_authority=?,provider_snapshot=?,paid_at=COALESCE(paid_at,NOW()),hold_expires_at=NULL,updated_at=NOW() WHERE id=? AND payment_status='awaiting_payment'")
        ->execute([(int)$paid['amount_due_cents'],$authority,$provider,(int)$order['id']]);
    $pdo->prepare("UPDATE agent_commerce_fulfillments_v800 f INNER JOIN agent_commerce_order_items_v800 i ON i.id=f.order_item_id SET f.status='ready',f.updated_at=NOW() WHERE i.order_id=? AND f.status='pending_payment'")->execute([(int)$order['id']]);
    agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'provider','payment_completed','awaiting_payment','paid',(int)$paid['amount_due_cents'],['provider'=>$provider,'authority'=>$authority]);
}

function agent_commerce_expire_order_v800(PDO $pdo,array $paid): void
{
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return;$pdo->prepare("UPDATE agent_commerce_orders_v800 SET order_status='expired',payment_status='expired',cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=? AND payment_status='awaiting_payment'")->execute([(int)$order['id']]);$pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET status='expired',updated_at=NOW() WHERE order_id=? AND status='open'")->execute([(int)$order['id']]);$pdo->prepare("UPDATE agent_commerce_fulfillments_v800 f INNER JOIN agent_commerce_order_items_v800 i ON i.id=f.order_item_id SET f.status='cancelled',f.updated_at=NOW() WHERE i.order_id=? AND f.status='pending_payment'")->execute([(int)$order['id']]);agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'system','payment_hold_expired','awaiting_payment','expired',(int)$order['amount_due_cents']);
}

function agent_commerce_cancel_fulfillment_v800(PDO $pdo,array $paid,string $reason=''): void
{
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return;$pdo->prepare("UPDATE agent_commerce_orders_v800 SET order_status='cancelled',cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=? AND order_status NOT IN ('cancelled','expired')")->execute([(int)$order['id']]);$pdo->prepare("UPDATE agent_commerce_fulfillments_v800 f INNER JOIN agent_commerce_order_items_v800 i ON i.id=f.order_item_id SET f.status='cancelled',f.updated_at=NOW() WHERE i.order_id=? AND f.status NOT IN ('completed','cancelled')")->execute([(int)$order['id']]);agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'system','fulfillment_cancelled',(string)$order['order_status'],'cancelled',0,['reason'=>mb_strimwidth($reason,0,190,'')]);
}

function agent_commerce_record_refund_v800(PDO $pdo,array $paid,string $provider,string $externalRefundId,int $amountCents,string $status,?int $approvedByUserId=null,string $authority='cloud'): void
{
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return;$authority=in_array($authority,['cloud','homeserver'],true)?$authority:'cloud';$paymentStmt=$pdo->prepare('SELECT id FROM agent_commerce_payments_v800 WHERE order_id=? AND provider=? AND authority=? ORDER BY id DESC LIMIT 1');$paymentStmt->execute([(int)$order['id'],$provider,$authority]);$paymentId=(int)($paymentStmt->fetchColumn()?:0)?:null;
    $pdo->prepare("INSERT INTO agent_commerce_refunds_v800 (order_id,payment_id,provider,authority,external_refund_id,amount_cents,status,approved_by_user_id,completed_at) VALUES (?,?,?,?,?,?,?,?,IF(? IN ('succeeded','completed'),NOW(),NULL)) ON DUPLICATE KEY UPDATE amount_cents=VALUES(amount_cents),status=VALUES(status),approved_by_user_id=COALESCE(VALUES(approved_by_user_id),approved_by_user_id),completed_at=COALESCE(completed_at,VALUES(completed_at)),updated_at=NOW()")
        ->execute([(int)$order['id'],$paymentId,$provider,$authority,$externalRefundId,max(0,$amountCents),$status,$approvedByUserId,$status]);
    $newRefunded=min((int)$order['amount_paid_cents'],(int)$order['amount_refunded_cents']+max(0,$amountCents));$paymentStatus=$newRefunded>0&&$newRefunded>=(int)$order['amount_paid_cents']?'refunded':'partially_refunded';
    $pdo->prepare('UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=?,payment_status=?,refunded_at=IF(?="refunded",COALESCE(refunded_at,NOW()),refunded_at),updated_at=NOW() WHERE id=?')->execute([$newRefunded,$paymentStatus,$paymentStatus,(int)$order['id']]);agent_commerce_audit_v800($pdo,(int)$order['id'],(int)$order['owner_user_id'],(int)($order['workspace_owner_user_id']??0)?:null,'provider','refund_completed',(string)$order['payment_status'],$paymentStatus,max(0,$amountCents),['provider'=>$provider,'authority'=>$authority]);
}
