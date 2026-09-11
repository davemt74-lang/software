<?php
declare(strict_types=1);

function agent_commerce_schema_ready_v800(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach([
        'agent_commerce_provider_connections_v800','agent_commerce_team_provider_v800',
        'agent_commerce_products_v800','agent_commerce_product_bindings_v800',
        'agent_commerce_orders_v800','agent_commerce_order_items_v800',
        'agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800',
        'agent_commerce_refunds_v800','agent_commerce_webhook_events_v800','agent_commerce_audit_v800'
    ] as $table)if(!table_exists($table))return false;
    return column_exists('agent_commerce_products_v800','fulfillment_type')
        &&column_exists('agent_commerce_orders_v800','fulfillment_ref_type')
        &&column_exists('agent_commerce_order_items_v800','fulfillment_ref_type')
        &&column_exists('agent_commerce_payments_v800','external_payment_id');
}

function agent_commerce_ensure_schema_v800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_provider_connections_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      external_account_id VARCHAR(190) NOT NULL,
      account_label VARCHAR(190) NOT NULL DEFAULT '',
      account_email VARCHAR(190) NOT NULL DEFAULT '',
      access_token_ciphertext MEDIUMTEXT NULL,
      refresh_token_ciphertext MEDIUMTEXT NULL,
      token_expires_at DATETIME NULL,
      scopes TEXT NULL,
      capabilities_json LONGTEXT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'connected',
      is_default_personal TINYINT(1) NOT NULL DEFAULT 0,
      connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_verified_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_provider_account (owner_user_id,provider,external_account_id),
      INDEX idx_commerce_provider_owner (owner_user_id,status,is_default_personal,provider,id),
      CONSTRAINT fk_commerce_provider_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_team_provider_v800 (
      workspace_owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      connection_id BIGINT UNSIGNED NOT NULL,
      set_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_commerce_team_provider_connection (connection_id,workspace_owner_user_id),
      CONSTRAINT fk_commerce_team_provider_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_team_provider_connection FOREIGN KEY (connection_id) REFERENCES agent_commerce_provider_connections_v800(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_team_provider_setter FOREIGN KEY (set_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_products_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      product_key VARCHAR(190) NOT NULL,
      title VARCHAR(190) NOT NULL,
      description VARCHAR(1000) NOT NULL DEFAULT '',
      product_type VARCHAR(32) NOT NULL DEFAULT 'service',
      fulfillment_type VARCHAR(32) NOT NULL DEFAULT 'none',
      payment_mode VARCHAR(24) NOT NULL DEFAULT 'free',
      price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      deposit_cents INT UNSIGNED NOT NULL DEFAULT 0,
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      provider_mode VARCHAR(24) NOT NULL DEFAULT 'guest_choice',
      fixed_connection_id BIGINT UNSIGNED NULL,
      hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      refund_before_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
      cancellation_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      cancellation_policy VARCHAR(1000) NOT NULL DEFAULT '',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_product_key (owner_user_id,product_key),
      INDEX idx_commerce_products_owner (owner_user_id,is_active,product_type,fulfillment_type,id),
      INDEX idx_commerce_products_workspace (workspace_owner_user_id,is_active,id),
      CONSTRAINT fk_commerce_product_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_product_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_product_connection FOREIGN KEY (fixed_connection_id) REFERENCES agent_commerce_provider_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_product_bindings_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id BIGINT UNSIGNED NOT NULL,
      binding_type VARCHAR(48) NOT NULL,
      binding_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_product_binding (binding_type,binding_id),
      INDEX idx_commerce_product_binding_product (product_id,binding_type,binding_id),
      CONSTRAINT fk_commerce_product_binding_product FOREIGN KEY (product_id) REFERENCES agent_commerce_products_v800(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_orders_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_number VARCHAR(40) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NULL,
      provider_snapshot VARCHAR(24) NOT NULL DEFAULT '',
      external_account_snapshot VARCHAR(190) NOT NULL DEFAULT '',
      order_status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
      payment_status VARCHAR(32) NOT NULL DEFAULT 'awaiting_payment',
      payment_mode VARCHAR(24) NOT NULL DEFAULT 'full',
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
      total_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_due_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
      platform_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      payer_email VARCHAR(190) NOT NULL DEFAULT '',
      fulfillment_type VARCHAR(32) NOT NULL DEFAULT 'none',
      fulfillment_ref_type VARCHAR(48) NOT NULL DEFAULT '',
      fulfillment_ref_id BIGINT UNSIGNED NULL,
      fulfillment_group_type VARCHAR(48) NOT NULL DEFAULT '',
      fulfillment_group_id BIGINT UNSIGNED NULL,
      hold_expires_at DATETIME NULL,
      paid_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      refunded_at DATETIME NULL,
      terms_snapshot_json LONGTEXT NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_order_number (order_number),
      UNIQUE KEY uq_commerce_fulfillment_ref (fulfillment_ref_type,fulfillment_ref_id),
      UNIQUE KEY uq_commerce_fulfillment_group (fulfillment_group_type,fulfillment_group_id),
      INDEX idx_commerce_order_owner (owner_user_id,payment_status,hold_expires_at,id),
      INDEX idx_commerce_order_workspace (workspace_owner_user_id,payment_status,id),
      INDEX idx_commerce_order_provider (provider_snapshot,payment_status,id),
      CONSTRAINT fk_commerce_order_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_order_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_commerce_order_connection FOREIGN KEY (connection_id) REFERENCES agent_commerce_provider_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_order_items_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NULL,
      title_snapshot VARCHAR(190) NOT NULL,
      product_type_snapshot VARCHAR(32) NOT NULL,
      fulfillment_type VARCHAR(32) NOT NULL,
      fulfillment_ref_type VARCHAR(48) NOT NULL DEFAULT '',
      fulfillment_ref_id BIGINT UNSIGNED NULL,
      quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      total_cents INT UNSIGNED NOT NULL DEFAULT 0,
      fulfillment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
      terms_snapshot_json LONGTEXT NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_commerce_order_items_order (order_id,id),
      INDEX idx_commerce_order_items_product (product_id,id),
      INDEX idx_commerce_order_items_fulfillment (fulfillment_type,fulfillment_ref_type,fulfillment_ref_id),
      CONSTRAINT fk_commerce_order_item_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_order_item_product FOREIGN KEY (product_id) REFERENCES agent_commerce_products_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_checkout_attempts_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      connection_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(24) NOT NULL,
      external_session_id VARCHAR(190) NOT NULL,
      external_payment_id VARCHAR(190) NOT NULL DEFAULT '',
      checkout_url TEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'open',
      amount_cents INT UNSIGNED NOT NULL,
      currency CHAR(3) NOT NULL,
      idempotency_key CHAR(64) NOT NULL,
      expires_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_checkout_provider_session (provider,external_session_id),
      UNIQUE KEY uq_commerce_checkout_idempotency (idempotency_key),
      INDEX idx_commerce_checkout_order (order_id,status,id),
      CONSTRAINT fk_commerce_checkout_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_checkout_connection FOREIGN KEY (connection_id) REFERENCES agent_commerce_provider_connections_v800(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_payments_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      checkout_attempt_id BIGINT UNSIGNED NULL,
      provider VARCHAR(24) NOT NULL,
      external_payment_id VARCHAR(190) NOT NULL,
      amount_cents INT UNSIGNED NOT NULL,
      currency CHAR(3) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'completed',
      received_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_payment_provider (provider,external_payment_id),
      INDEX idx_commerce_payment_order (order_id,status,id),
      CONSTRAINT fk_commerce_payment_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_payment_checkout FOREIGN KEY (checkout_attempt_id) REFERENCES agent_commerce_checkout_attempts_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_refunds_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      payment_id BIGINT UNSIGNED NULL,
      checkout_attempt_id BIGINT UNSIGNED NULL,
      provider VARCHAR(24) NOT NULL,
      external_refund_id VARCHAR(190) NOT NULL,
      amount_cents INT UNSIGNED NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      reason VARCHAR(500) NOT NULL DEFAULT '',
      requested_by_user_id INT UNSIGNED NULL,
      requested_by_agent_id BIGINT UNSIGNED NULL,
      approved_by_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_refund_provider (provider,external_refund_id),
      INDEX idx_commerce_refund_order (order_id,status,id),
      CONSTRAINT fk_commerce_refund_order FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_commerce_refund_payment FOREIGN KEY (payment_id) REFERENCES agent_commerce_payments_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_refund_checkout FOREIGN KEY (checkout_attempt_id) REFERENCES agent_commerce_checkout_attempts_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_refund_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_refund_agent FOREIGN KEY (requested_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_commerce_refund_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_commerce_webhook_events_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      provider VARCHAR(24) NOT NULL,
      external_event_id VARCHAR(190) NOT NULL,
      event_type VARCHAR(120) NOT NULL,
      payload_sha256 CHAR(64) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'processing',
      error_message VARCHAR(1000) NOT NULL DEFAULT '',
      processed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_commerce_webhook_event (provider,external_event_id),
      INDEX idx_commerce_webhook_status (provider,status,created_at,id)
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
}

function agent_commerce_config_v800(): array
{
    global $config;$root=is_array($config['commerce']??null)?$config['commerce']:[];$providers=is_array($root['providers']??null)?$root['providers']:[];
    $stripe=is_array($providers['stripe']??null)?$providers['stripe']:[];$square=is_array($providers['square']??null)?$providers['square']:[];$paypal=is_array($providers['paypal']??null)?$providers['paypal']:[];
    return [
      'encryption_key'=>trim((string)(getenv('VP3_COMMERCE_ENCRYPTION_KEY')?:($root['encryption_key']??''))),
      'platform_fee_bps'=>max(0,min(10000,(int)(getenv('VP3_COMMERCE_PLATFORM_FEE_BPS')?:($root['platform_fee_bps']??0)))),
      'stripe'=>[
        'secret_key'=>trim((string)(getenv('VP3_COMMERCE_STRIPE_SECRET_KEY')?:($stripe['secret_key']??''))),
        'connect_client_id'=>trim((string)(getenv('VP3_COMMERCE_STRIPE_CONNECT_CLIENT_ID')?:($stripe['connect_client_id']??''))),
        'webhook_secret'=>trim((string)(getenv('VP3_COMMERCE_STRIPE_WEBHOOK_SECRET')?:($stripe['webhook_secret']??''))),
      ],
      'square'=>[
        'application_id'=>trim((string)(getenv('VP3_COMMERCE_SQUARE_APPLICATION_ID')?:($square['application_id']??''))),
        'client_secret'=>trim((string)(getenv('VP3_COMMERCE_SQUARE_CLIENT_SECRET')?:($square['client_secret']??''))),
        'webhook_signature_key'=>trim((string)(getenv('VP3_COMMERCE_SQUARE_WEBHOOK_SIGNATURE_KEY')?:($square['webhook_signature_key']??''))),
        'environment'=>strtolower(trim((string)(getenv('VP3_COMMERCE_SQUARE_ENVIRONMENT')?:($square['environment']??'sandbox'))))==='production'?'production':'sandbox',
      ],
      'paypal'=>[
        'client_id'=>trim((string)(getenv('VP3_COMMERCE_PAYPAL_CLIENT_ID')?:($paypal['client_id']??''))),
        'client_secret'=>trim((string)(getenv('VP3_COMMERCE_PAYPAL_CLIENT_SECRET')?:($paypal['client_secret']??''))),
        'partner_id'=>trim((string)(getenv('VP3_COMMERCE_PAYPAL_PARTNER_ID')?:($paypal['partner_id']??''))),
        'bn_code'=>trim((string)(getenv('VP3_COMMERCE_PAYPAL_BN_CODE')?:($paypal['bn_code']??''))),
        'webhook_id'=>trim((string)(getenv('VP3_COMMERCE_PAYPAL_WEBHOOK_ID')?:($paypal['webhook_id']??''))),
        'environment'=>strtolower(trim((string)(getenv('VP3_COMMERCE_PAYPAL_ENVIRONMENT')?:($paypal['environment']??'sandbox'))))==='production'?'production':'sandbox',
      ],
    ];
}

function agent_commerce_provider_registry_v800(): array
{
    return [
      'stripe'=>['label'=>'Stripe','oauth'=>true,'checkout'=>true,'refunds'=>true],
      'square'=>['label'=>'Square','oauth'=>true,'checkout'=>true,'refunds'=>true],
      'paypal'=>['label'=>'PayPal','oauth'=>false,'checkout'=>true,'refunds'=>true],
    ];
}
function agent_commerce_provider_label_v800(string $provider): string
{
    $provider=strtolower(trim($provider));return (string)(agent_commerce_provider_registry_v800()[$provider]['label']??'Payment provider');
}
function agent_commerce_provider_ready_v800(string $provider): bool
{
    $cfg=agent_commerce_config_v800();$provider=strtolower(trim($provider));if($cfg['encryption_key']==='')return false;
    return match($provider){
      'stripe'=>$cfg['stripe']['secret_key']!==''&&$cfg['stripe']['connect_client_id']!=='',
      'square'=>$cfg['square']['application_id']!==''&&$cfg['square']['client_secret']!=='',
      'paypal'=>$cfg['paypal']['client_id']!==''&&$cfg['paypal']['client_secret']!==''&&$cfg['paypal']['partner_id']!=='',
      default=>false,
    };
}
function agent_commerce_encrypt_v800(string $plain): string
{
    if($plain==='')return '';$secret=(string)agent_commerce_config_v800()['encryption_key'];if($secret==='')throw new RuntimeException('Commerce token encryption is not configured.');if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL is required for commerce token encryption.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,$iv,$tag,'vp3-commerce-v800',16);if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Commerce token encryption failed.');return 'v1:'.base64_encode($iv.$tag.$cipher);
}
function agent_commerce_decrypt_v800(string $ciphertext): string
{
    if($ciphertext==='')return '';$secret=(string)agent_commerce_config_v800()['encryption_key'];if($secret==='')throw new RuntimeException('Commerce token encryption is not configured.');if(!str_starts_with($ciphertext,'v1:'))throw new RuntimeException('Unsupported commerce token format.');$raw=base64_decode(substr($ciphertext,3),true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Commerce token data is invalid.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'vp3-commerce-v800');if($plain===false)throw new RuntimeException('Commerce token decryption failed.');return $plain;
}
function agent_commerce_absolute_url_v800(string $path): string
{
    global $config;$base=rtrim(trim((string)($config['site']['base_url']??'')),'/');if($base==='')throw new RuntimeException('Configure site.base_url before commerce can be used.');if(!preg_match('#^https?://[A-Za-z0-9.-]+(?::\d+)?$#',$base))throw new RuntimeException('site.base_url must be an origin before commerce can be used.');return $base.url($path);
}
function agent_commerce_money_v800(int $cents,string $currency='usd'): string
{
    $currency=strtoupper(preg_match('/^[a-zA-Z]{3}$/',$currency)?$currency:'USD');return $currency.' '.number_format(max(0,$cents)/100,2);
}
function agent_commerce_decimal_to_minor_v800(string $value): int
{
    $value=trim($value);if($value==='')return 0;if(!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/',$value,$m))throw new RuntimeException('Enter a valid money amount with no more than two decimal places.');$whole=(int)$m[1];$fraction=str_pad((string)($m[2]??''),2,'0');if($whole>21474836)throw new RuntimeException('Money amount is too large.');return ($whole*100)+(int)$fraction;
}
function agent_commerce_http_v800(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('cURL is required for commerce payment providers.');$method=strtoupper($method);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'VP3-Commerce/'.VP3_AGENT_COMMERCE_V800];if($body!==null){$payload=$form?http_build_query((array)$body,'','&',PHP_QUERY_RFC3986):(is_string($body)?$body:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$opts[CURLOPT_POSTFIELDS]=$payload;$opts[CURLOPT_HTTPHEADER]=array_merge($headers,[$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json']);}$ch=curl_init($url);curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($raw===false||$errno!==0)throw new RuntimeException('Payment provider network request failed: '.$error);$json=$raw!==''?json_decode((string)$raw,true):[];if(!is_array($json))$json=[];if($status<200||$status>=300){$message=(string)($json['error_description']??$json['error']['message']??$json['message']??$json['details'][0]['detail']??'Payment provider returned HTTP '.$status.'.');throw new RuntimeException(mb_strimwidth(trim($message),0,800,'…'));}return ['status'=>$status,'json'=>$json,'raw'=>(string)$raw,'headers'=>[]];
}

function agent_commerce_is_team_super_admin_v800(int $workspaceOwnerId,?array $actor=null): bool
{
    $actor??=current_user();return $workspaceOwnerId>0&&(int)($actor['id']??0)===$workspaceOwnerId;
}
function agent_commerce_connection_v800(PDO $pdo,int $connectionId,int $ownerUserId=0): ?array
{
    if($connectionId<1)return null;$sql='SELECT * FROM agent_commerce_provider_connections_v800 WHERE id=?';$args=[$connectionId];if($ownerUserId>0){$sql.=' AND owner_user_id=?';$args[]=$ownerUserId;}$sql.=' LIMIT 1';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetch()?:null;
}
function agent_commerce_connections_v800(PDO $pdo,int $ownerUserId,bool $connectedOnly=false): array
{
    if($ownerUserId<1)return [];$sql='SELECT * FROM agent_commerce_provider_connections_v800 WHERE owner_user_id=?';$args=[$ownerUserId];if($connectedOnly)$sql.=" AND status='connected'";$sql.=' ORDER BY is_default_personal DESC,provider,account_label,id';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll()?:[];
}
function agent_commerce_store_connection_v800(PDO $pdo,int $ownerUserId,string $provider,string $externalAccountId,array $data=[]): array
{
    $provider=strtolower(trim($provider));$externalAccountId=mb_strimwidth(trim($externalAccountId),0,190,'');if($ownerUserId<1||$externalAccountId==='')throw new RuntimeException('Payment provider account identity is required.');if(!isset(agent_commerce_provider_registry_v800()[$provider]))throw new RuntimeException('Unsupported commerce payment provider.');$access=trim((string)($data['access_token']??''));$refresh=trim((string)($data['refresh_token']??''));$expires=$data['token_expires_at']??null;$stmt=$pdo->prepare('SELECT * FROM agent_commerce_provider_connections_v800 WHERE owner_user_id=? AND provider=? AND external_account_id=? LIMIT 1');$stmt->execute([$ownerUserId,$provider,$externalAccountId]);$existing=$stmt->fetch()?:null;$accessCipher=$access!==''?agent_commerce_encrypt_v800($access):(string)($existing['access_token_ciphertext']??'');$refreshCipher=$refresh!==''?agent_commerce_encrypt_v800($refresh):(string)($existing['refresh_token_ciphertext']??'');$capabilities=json_encode(is_array($data['capabilities']??null)?$data['capabilities']:[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$label=mb_strimwidth(trim((string)($data['account_label']??$existing['account_label']??agent_commerce_provider_label_v800($provider))),0,190,'');$email=mb_strimwidth(strtolower(trim((string)($data['account_email']??$existing['account_email']??''))),0,190,'');$scopes=mb_strimwidth(trim((string)($data['scopes']??$existing['scopes']??'')),0,4000,'');$pdo->prepare("INSERT INTO agent_commerce_provider_connections_v800 (owner_user_id,provider,external_account_id,account_label,account_email,access_token_ciphertext,refresh_token_ciphertext,token_expires_at,scopes,capabilities_json,status,last_verified_at,last_error) VALUES (?,?,?,?,?,?,?,?,?,?,'connected',NOW(),'') ON DUPLICATE KEY UPDATE account_label=VALUES(account_label),account_email=VALUES(account_email),access_token_ciphertext=VALUES(access_token_ciphertext),refresh_token_ciphertext=VALUES(refresh_token_ciphertext),token_expires_at=VALUES(token_expires_at),scopes=VALUES(scopes),capabilities_json=VALUES(capabilities_json),status='connected',last_verified_at=NOW(),last_error='',updated_at=NOW()")->execute([$ownerUserId,$provider,$externalAccountId,$label,$email,$accessCipher?:null,$refreshCipher?:null,$expires?:null,$scopes?:null,$capabilities]);$stmt->execute([$ownerUserId,$provider,$externalAccountId]);return $stmt->fetch()?:throw new RuntimeException('Payment provider connection could not be saved.');
}
function agent_commerce_set_personal_default_v800(PDO $pdo,int $ownerUserId,int $connectionId): array
{
    $connection=agent_commerce_connection_v800($pdo,$connectionId,$ownerUserId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('Choose one of your connected payment providers.');$pdo->beginTransaction();try{$pdo->prepare('UPDATE agent_commerce_provider_connections_v800 SET is_default_personal=0 WHERE owner_user_id=?')->execute([$ownerUserId]);$pdo->prepare('UPDATE agent_commerce_provider_connections_v800 SET is_default_personal=1 WHERE id=? AND owner_user_id=?')->execute([$connectionId,$ownerUserId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return agent_commerce_connection_v800($pdo,$connectionId,$ownerUserId)?:throw new RuntimeException('Default provider could not be saved.');
}
function agent_commerce_disconnect_v800(PDO $pdo,int $ownerUserId,int $connectionId): void
{
    $connection=agent_commerce_connection_v800($pdo,$connectionId,$ownerUserId);if(!$connection)throw new RuntimeException('Payment provider connection not found.');$check=$pdo->prepare("SELECT 1 FROM agent_commerce_team_provider_v800 WHERE connection_id=? UNION SELECT 1 FROM agent_commerce_products_v800 WHERE fixed_connection_id=? AND is_active=1 UNION SELECT 1 FROM agent_commerce_orders_v800 WHERE connection_id=? AND payment_status IN ('awaiting_payment','paid','partially_refunded') LIMIT 1");$check->execute([$connectionId,$connectionId,$connectionId]);if($check->fetchColumn())throw new RuntimeException('This provider is still assigned to a Team, active product, or active order. Reassign it before disconnecting.');$pdo->prepare("UPDATE agent_commerce_provider_connections_v800 SET status='disconnected',is_default_personal=0,access_token_ciphertext=NULL,refresh_token_ciphertext=NULL,token_expires_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$connectionId,$ownerUserId]);
}
function agent_commerce_team_primary_v800(PDO $pdo,int $workspaceOwnerId): ?array
{
    if($workspaceOwnerId<1)return null;$stmt=$pdo->prepare("SELECT tp.*,c.provider,c.external_account_id,c.account_label,c.account_email,c.status connection_status,c.owner_user_id connection_owner_user_id FROM agent_commerce_team_provider_v800 tp INNER JOIN agent_commerce_provider_connections_v800 c ON c.id=tp.connection_id WHERE tp.workspace_owner_user_id=? LIMIT 1");$stmt->execute([$workspaceOwnerId]);return $stmt->fetch()?:null;
}
function agent_commerce_set_team_primary_v800(PDO $pdo,int $workspaceOwnerId,int $connectionId,?array $actor=null): array
{
    $actor??=current_user();$actorId=(int)($actor['id']??0);if(!agent_commerce_is_team_super_admin_v800($workspaceOwnerId,$actor))throw new RuntimeException('Only the Team Super Admin can change the Team payment provider.');$connection=agent_commerce_connection_v800($pdo,$connectionId,$workspaceOwnerId);if(!$connection||$connection['status']!=='connected')throw new RuntimeException('The Team primary provider must be a connected provider owned by the Team Super Admin.');$pdo->prepare('INSERT INTO agent_commerce_team_provider_v800 (workspace_owner_user_id,connection_id,set_by_user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),set_by_user_id=VALUES(set_by_user_id),updated_at=NOW()')->execute([$workspaceOwnerId,$connectionId,$actorId]);agent_commerce_audit_v800($pdo,null,$workspaceOwnerId,$workspaceOwnerId,'user',$actorId,null,'team_primary_provider_changed','','',0,['connection_id'=>$connectionId,'provider'=>$connection['provider']]);return agent_commerce_team_primary_v800($pdo,$workspaceOwnerId)?:throw new RuntimeException('Team primary provider could not be saved.');
}
function agent_commerce_currency_v800(string $currency): string
{
    $currency=strtolower(trim($currency));if(!preg_match('/^[a-z]{3}$/',$currency))throw new RuntimeException('Use a three-letter ISO currency code.');return $currency;
}
function agent_commerce_payment_mode_v800(string $mode): string
{
    $mode=strtolower(trim($mode));if(!in_array($mode,['free','full','deposit'],true))throw new RuntimeException('Choose free, full payment, or deposit.');return $mode;
}
function agent_commerce_validate_terms_v800(array $input): array
{
    $mode=agent_commerce_payment_mode_v800((string)($input['payment_mode']??'free'));$price=max(0,(int)($input['price_cents']??0));$deposit=max(0,(int)($input['deposit_cents']??0));$currency=agent_commerce_currency_v800((string)($input['currency']??'usd'));if($mode!=='free'&&$price<1)throw new RuntimeException('Paid products require a price greater than zero.');if($mode==='deposit'&&($deposit<1||$deposit>$price))throw new RuntimeException('Deposit must be greater than zero and no more than the product price.');if($mode==='full')$deposit=$price;if($mode==='free'){$price=0;$deposit=0;}$hold=max(30,min(120,(int)($input['hold_minutes']??30)));$refundHours=max(0,min(2160,(int)($input['refund_before_hours']??24)));$fee=max(0,(int)($input['cancellation_fee_cents']??0));if($fee>$price)$fee=$price;return ['payment_mode'=>$mode,'price_cents'=>$price,'deposit_cents'=>$deposit,'currency'=>$currency,'hold_minutes'=>$hold,'refund_before_hours'=>$refundHours,'cancellation_fee_cents'=>$fee,'cancellation_policy'=>mb_strimwidth(trim((string)($input['cancellation_policy']??'')),0,1000,'')];
}
function agent_commerce_audit_v800(PDO $pdo,?int $orderId,int $ownerUserId,?int $workspaceOwnerId,string $actorType,?int $actorUserId,?int $actorAgentId,string $eventType,string $fromStatus='',string $toStatus='',int $amountCents=0,array $metadata=[]): void
{
    if($ownerUserId<1)return;$pdo->prepare('INSERT INTO agent_commerce_audit_v800 (order_id,owner_user_id,workspace_owner_user_id,actor_type,actor_user_id,actor_agent_id,event_type,from_status,to_status,amount_cents,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$orderId,$ownerUserId,$workspaceOwnerId,$actorType,$actorUserId,$actorAgentId,$eventType,$fromStatus,$toStatus,max(0,$amountCents),$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
