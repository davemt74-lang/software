-- VP3 Phase 2 Stripe billing
-- Apply after the core subscription and self-service plan tables are installed.

CREATE TABLE IF NOT EXISTS package_billing_prices (
  package_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  billing_interval VARCHAR(20) NOT NULL,
  provider_product_id VARCHAR(120) NOT NULL,
  provider_price_id VARCHAR(120) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'usd',
  unit_amount_cents INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (package_id,provider,billing_interval),
  UNIQUE KEY uq_billing_provider_price (provider,provider_price_id),
  INDEX idx_billing_prices_provider (provider,is_active,package_id),
  CONSTRAINT fk_billing_price_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_customers (
  user_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  provider_customer_id VARCHAR(120) NOT NULL,
  email_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,provider),
  UNIQUE KEY uq_billing_provider_customer (provider,provider_customer_id),
  CONSTRAINT fk_billing_customer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  user_subscription_id BIGINT UNSIGNED NULL,
  package_id INT UNSIGNED NULL,
  plan_request_id BIGINT UNSIGNED NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  provider_customer_id VARCHAR(120) NOT NULL,
  provider_subscription_id VARCHAR(120) NOT NULL,
  provider_price_id VARCHAR(120) NOT NULL DEFAULT '',
  billing_interval VARCHAR(20) NOT NULL DEFAULT 'monthly',
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_provider_subscription (provider,provider_subscription_id),
  INDEX idx_billing_subscription_user (user_id,status,id),
  INDEX idx_billing_subscription_local (user_subscription_id,id),
  INDEX idx_billing_subscription_package (package_id,status,id),
  CONSTRAINT fk_billing_subscription_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_subscription_local FOREIGN KEY (user_subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL,
  CONSTRAINT fk_billing_subscription_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_billing_subscription_request FOREIGN KEY (plan_request_id) REFERENCES subscription_plan_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_checkout_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  plan_request_id BIGINT UNSIGNED NULL,
  package_id INT UNSIGNED NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  session_type VARCHAR(30) NOT NULL DEFAULT 'checkout',
  provider_session_id VARCHAR(160) NOT NULL,
  provider_customer_id VARCHAR(120) NOT NULL DEFAULT '',
  provider_subscription_id VARCHAR(120) NOT NULL DEFAULT '',
  billing_interval VARCHAR(20) NOT NULL DEFAULT 'monthly',
  amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'open',
  checkout_url TEXT NULL,
  expires_at DATETIME NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_provider_session (provider,provider_session_id),
  INDEX idx_billing_checkout_user (user_id,status,id),
  INDEX idx_billing_checkout_request (plan_request_id,status,id),
  CONSTRAINT fk_billing_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_checkout_request FOREIGN KEY (plan_request_id) REFERENCES subscription_plan_requests(id) ON DELETE SET NULL,
  CONSTRAINT fk_billing_checkout_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  event_id VARCHAR(160) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  payload_sha256 CHAR(64) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'processing',
  error_message VARCHAR(1000) NOT NULL DEFAULT '',
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_webhook_event (provider,event_id),
  INDEX idx_billing_webhook_status (provider,status,created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_portal_configs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  config_key CHAR(64) NOT NULL,
  provider_configuration_id VARCHAR(120) NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_portal_key (provider,config_key),
  UNIQUE KEY uq_billing_portal_provider_id (provider,provider_configuration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
