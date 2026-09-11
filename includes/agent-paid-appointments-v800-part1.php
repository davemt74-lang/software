<?php
declare(strict_types=1);

function agent_paid_appointments_schema_ready_v800(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach([
        'agent_appointment_payment_connections_v800','agent_team_payment_provider_v800',
        'agent_paid_event_types_v800','agent_paid_team_pools_v800','agent_paid_bookings_v800',
        'agent_paid_checkout_attempts_v800','agent_paid_refunds_v800','agent_paid_webhook_events_v800',
        'agent_paid_audit_v800'
    ] as $table)if(!table_exists($table))return false;
    return column_exists('agent_paid_bookings_v800','provider_snapshot')
        &&column_exists('agent_paid_bookings_v800','hold_expires_at')
        &&column_exists('agent_team_payment_provider_v800','workspace_owner_user_id');
}

function agent_paid_appointments_ensure_schema_v800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!agent_scheduling_schema_ready_v430($pdo))throw new RuntimeException('Install Agent Scheduling before Paid Appointments.');
    if(!agent_team_scheduling_schema_ready_v600($pdo))throw new RuntimeException('Install Team Scheduling before Paid Appointments.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_appointment_payment_connections_v800 (
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
      UNIQUE KEY uq_appt_provider_account (owner_user_id,provider,external_account_id),
      INDEX idx_appt_provider_owner (owner_user_id,status,is_default_personal,provider,id),
      CONSTRAINT fk_appt_provider_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_team_payment_provider_v800 (
      workspace_owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      connection_id BIGINT UNSIGNED NOT NULL,
      set_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_team_payment_connection (connection_id,workspace_owner_user_id),
      CONSTRAINT fk_team_payment_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_payment_connection FOREIGN KEY (connection_id) REFERENCES agent_appointment_payment_connections_v800(id) ON DELETE RESTRICT,
      CONSTRAINT fk_team_payment_setter FOREIGN KEY (set_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_event_types_v800 (
      event_type_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
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
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_paid_event_type FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE CASCADE,
      CONSTRAINT fk_paid_event_connection FOREIGN KEY (fixed_connection_id) REFERENCES agent_appointment_payment_connections_v800(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_team_pools_v800 (
      pool_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      payment_mode VARCHAR(24) NOT NULL DEFAULT 'free',
      price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      deposit_cents INT UNSIGNED NOT NULL DEFAULT 0,
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
      refund_before_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
      cancellation_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      cancellation_policy VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_paid_team_pool FOREIGN KEY (pool_id) REFERENCES agent_team_scheduling_pools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_bookings_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id BIGINT UNSIGNED NOT NULL,
      team_booking_id BIGINT UNSIGNED NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      workspace_owner_user_id INT UNSIGNED NULL,
      connection_id BIGINT UNSIGNED NULL,
      provider_snapshot VARCHAR(24) NOT NULL DEFAULT '',
      external_account_snapshot VARCHAR(190) NOT NULL DEFAULT '',
      payment_mode VARCHAR(24) NOT NULL DEFAULT 'free',
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      amount_total_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_due_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
      amount_refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
      platform_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
      payment_status VARCHAR(32) NOT NULL DEFAULT 'not_required',
      payer_email VARCHAR(190) NOT NULL DEFAULT '',
      hold_expires_at DATETIME NULL,
      paid_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      refunded_at DATETIME NULL,
      terms_snapshot_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_paid_booking (booking_id),
      UNIQUE KEY uq_paid_team_booking (team_booking_id),
      INDEX idx_paid_booking_owner (owner_user_id,payment_status,hold_expires_at,id),
      INDEX idx_paid_booking_team_owner (workspace_owner_user_id,payment_status,id),
      INDEX idx_paid_booking_provider (provider_snapshot,payment_status,id),
      CONSTRAINT fk_paid_booking_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_booking_team_booking FOREIGN KEY (team_booking_id) REFERENCES agent_team_scheduling_bookings(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_booking_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_booking_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_booking_connection FOREIGN KEY (connection_id) REFERENCES agent_appointment_payment_connections_v800(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_checkout_attempts_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      paid_booking_id BIGINT UNSIGNED NOT NULL,
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
      UNIQUE KEY uq_paid_checkout_provider_session (provider,external_session_id),
      UNIQUE KEY uq_paid_checkout_idempotency (idempotency_key),
      INDEX idx_paid_checkout_booking (paid_booking_id,status,id),
      CONSTRAINT fk_paid_checkout_booking FOREIGN KEY (paid_booking_id) REFERENCES agent_paid_bookings_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_paid_checkout_connection FOREIGN KEY (connection_id) REFERENCES agent_appointment_payment_connections_v800(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_refunds_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      paid_booking_id BIGINT UNSIGNED NOT NULL,
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
      UNIQUE KEY uq_paid_refund_provider (provider,external_refund_id),
      INDEX idx_paid_refund_booking (paid_booking_id,status,id),
      CONSTRAINT fk_paid_refund_booking FOREIGN KEY (paid_booking_id) REFERENCES agent_paid_bookings_v800(id) ON DELETE CASCADE,
      CONSTRAINT fk_paid_refund_checkout FOREIGN KEY (checkout_attempt_id) REFERENCES agent_paid_checkout_attempts_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_paid_refund_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_paid_refund_agent FOREIGN KEY (requested_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_paid_refund_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_webhook_events_v800 (
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
      UNIQUE KEY uq_paid_webhook_event (provider,external_event_id),
      INDEX idx_paid_webhook_status (provider,status,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_paid_audit_v800 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      paid_booking_id BIGINT UNSIGNED NULL,
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
      INDEX idx_paid_audit_booking (paid_booking_id,created_at,id),
      INDEX idx_paid_audit_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_paid_audit_booking FOREIGN KEY (paid_booking_id) REFERENCES agent_paid_bookings_v800(id) ON DELETE SET NULL,
      CONSTRAINT fk_paid_audit_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_audit_workspace_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_paid_audit_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_paid_audit_agent FOREIGN KEY (actor_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_paid_appointments_config_v800(): array
{
    global $config;$root=is_array($config['appointment_payments']??null)?$config['appointment_payments']:[];$providers=is_array($root['providers']??null)?$root['providers']:[];
    $stripe=is_array($providers['stripe']??null)?$providers['stripe']:[];$square=is_array($providers['square']??null)?$providers['square']:[];$paypal=is_array($providers['paypal']??null)?$providers['paypal']:[];
    return [
      'encryption_key'=>trim((string)(getenv('VP3_APPOINTMENT_PAYMENTS_ENCRYPTION_KEY')?:($root['encryption_key']??''))),
      'platform_fee_bps'=>max(0,min(10000,(int)(getenv('VP3_APPOINTMENT_PLATFORM_FEE_BPS')?:($root['platform_fee_bps']??0)))),
      'stripe'=>[
        'secret_key'=>trim((string)(getenv('VP3_APPOINTMENT_STRIPE_SECRET_KEY')?:($stripe['secret_key']??''))),
        'connect_client_id'=>trim((string)(getenv('VP3_APPOINTMENT_STRIPE_CONNECT_CLIENT_ID')?:($stripe['connect_client_id']??''))),
        'webhook_secret'=>trim((string)(getenv('VP3_APPOINTMENT_STRIPE_WEBHOOK_SECRET')?:($stripe['webhook_secret']??''))),
      ],
      'square'=>[
        'application_id'=>trim((string)(getenv('VP3_APPOINTMENT_SQUARE_APPLICATION_ID')?:($square['application_id']??''))),
        'client_secret'=>trim((string)(getenv('VP3_APPOINTMENT_SQUARE_CLIENT_SECRET')?:($square['client_secret']??''))),
        'webhook_signature_key'=>trim((string)(getenv('VP3_APPOINTMENT_SQUARE_WEBHOOK_SIGNATURE_KEY')?:($square['webhook_signature_key']??''))),
        'environment'=>strtolower(trim((string)(getenv('VP3_APPOINTMENT_SQUARE_ENVIRONMENT')?:($square['environment']??'sandbox'))))==='production'?'production':'sandbox',
      ],
      'paypal'=>[
        'client_id'=>trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_CLIENT_ID')?:($paypal['client_id']??''))),
        'client_secret'=>trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_CLIENT_SECRET')?:($paypal['client_secret']??''))),
        'partner_id'=>trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_PARTNER_ID')?:($paypal['partner_id']??''))),
        'bn_code'=>trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_BN_CODE')?:($paypal['bn_code']??''))),
        'webhook_id'=>trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_WEBHOOK_ID')?:($paypal['webhook_id']??''))),
        'environment'=>strtolower(trim((string)(getenv('VP3_APPOINTMENT_PAYPAL_ENVIRONMENT')?:($paypal['environment']??'sandbox'))))==='production'?'production':'sandbox',
      ],
    ];
}

function agent_paid_appointments_provider_registry_v800(): array
{
    return [
      'stripe'=>['label'=>'Stripe','oauth'=>true,'checkout'=>true,'refunds'=>true],
      'square'=>['label'=>'Square','oauth'=>true,'checkout'=>true,'refunds'=>true],
      'paypal'=>['label'=>'PayPal','oauth'=>false,'checkout'=>true,'refunds'=>true],
    ];
}

function agent_paid_appointments_provider_label_v800(string $provider): string
{
    $provider=strtolower(trim($provider));return (string)(agent_paid_appointments_provider_registry_v800()[$provider]['label']??'Payment provider');
}

function agent_paid_appointments_provider_ready_v800(string $provider): bool
{
    $cfg=agent_paid_appointments_config_v800();$provider=strtolower(trim($provider));
    if($cfg['encryption_key']==='')return false;
    return match($provider){
      'stripe'=>$cfg['stripe']['secret_key']!==''&&$cfg['stripe']['connect_client_id']!=='',
      'square'=>$cfg['square']['application_id']!==''&&$cfg['square']['client_secret']!=='',
      'paypal'=>$cfg['paypal']['client_id']!==''&&$cfg['paypal']['client_secret']!==''&&$cfg['paypal']['partner_id']!=='',
      default=>false,
    };
}

function agent_paid_appointments_encrypt_v800(string $plain): string
{
    if($plain==='')return '';$secret=(string)agent_paid_appointments_config_v800()['encryption_key'];
    if($secret==='')throw new RuntimeException('Appointment payment token encryption is not configured.');
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL is required for appointment payment token encryption.');
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,$iv,$tag,'vp3-appointment-payments-v800',16);
    if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Appointment payment token encryption failed.');
    return 'v1:'.base64_encode($iv.$tag.$cipher);
}

function agent_paid_appointments_decrypt_v800(string $ciphertext): string
{
    if($ciphertext==='')return '';$secret=(string)agent_paid_appointments_config_v800()['encryption_key'];
    if($secret==='')throw new RuntimeException('Appointment payment token encryption is not configured.');
    if(!str_starts_with($ciphertext,'v1:'))throw new RuntimeException('Unsupported appointment payment token format.');
    $raw=base64_decode(substr($ciphertext,3),true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Appointment payment token data is invalid.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$secret,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'vp3-appointment-payments-v800');
    if($plain===false)throw new RuntimeException('Appointment payment token decryption failed.');return $plain;
}

function agent_paid_appointments_absolute_url_v800(string $path): string
{
    global $config;$base=rtrim(trim((string)($config['site']['base_url']??'')),'/');
    if($base==='')throw new RuntimeException('Configure site.base_url before appointment payments can be used.');
    if(!preg_match('#^https?://[A-Za-z0-9.-]+(?::\d+)?$#',$base))throw new RuntimeException('site.base_url must be an origin before appointment payments can be used.');
    return $base.url($path);
}

function agent_paid_appointments_money_v800(int $cents,string $currency='usd'): string
{
    $currency=strtoupper(preg_match('/^[a-zA-Z]{3}$/',$currency)?$currency:'USD');return $currency.' '.number_format(max(0,$cents)/100,2);
}

function agent_paid_appointments_http_v800(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('cURL is required for appointment payment providers.');
    $method=strtoupper($method);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'VP3-Paid-Appointments/'.VP3_AGENT_PAID_APPOINTMENTS_V800];
    if($body!==null){$payload=$form?http_build_query((array)$body,'','&',PHP_QUERY_RFC3986):(is_string($body)?$body:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$opts[CURLOPT_POSTFIELDS]=$payload;$opts[CURLOPT_HTTPHEADER]=array_merge($headers,[$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json']);}
    $ch=curl_init($url);curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$errno!==0)throw new RuntimeException('Payment provider network request failed: '.$error);
    $json=$raw!==''?json_decode((string)$raw,true):[];if(!is_array($json))$json=[];
    if($status<200||$status>=300){$message=(string)($json['error_description']??$json['error']['message']??$json['message']??$json['details'][0]['detail']??'Payment provider returned HTTP '.$status.'.');throw new RuntimeException(mb_strimwidth(trim($message),0,800,'…'));}
    return ['status'=>$status,'json'=>$json,'raw'=>(string)$raw,'headers'=>[]];
}
