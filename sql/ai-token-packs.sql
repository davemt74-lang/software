-- VP3 one-time AI token pack commerce
-- Requires the existing subscription, AI token credit and Stripe billing schemas.
-- Safe for a fresh install. If these tables already exist, use upgrade.php so
-- the application can harden pre-release provider_session_id nullability.

CREATE TABLE IF NOT EXISTS ai_token_packs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  token_amount BIGINT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  expires_days SMALLINT UNSIGNED NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_token_pack_slug (slug),
  INDEX idx_ai_token_pack_public (is_active,is_public,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_token_pack_purchases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_pack_id INT UNSIGNED NULL,
  pack_name_snapshot VARCHAR(120) NOT NULL,
  token_amount BIGINT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'usd',
  expires_days SMALLINT UNSIGNED NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  provider_session_id VARCHAR(160) NULL DEFAULT NULL,
  provider_payment_intent_id VARCHAR(160) NULL DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  credit_id BIGINT UNSIGNED NULL,
  credited_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_token_purchase_session (provider,provider_session_id),
  INDEX idx_ai_token_purchase_user (user_id,status,created_at,id),
  INDEX idx_ai_token_purchase_pack (token_pack_id,status,id),
  CONSTRAINT fk_ai_token_purchase_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_token_purchase_pack FOREIGN KEY (token_pack_id) REFERENCES ai_token_packs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_token_purchase_credit FOREIGN KEY (credit_id) REFERENCES ai_token_credits(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-release hardening, harmless after the table exists with nullable columns.
ALTER TABLE ai_token_pack_purchases
  MODIFY provider_session_id VARCHAR(160) NULL DEFAULT NULL,
  MODIFY provider_payment_intent_id VARCHAR(160) NULL DEFAULT NULL;
UPDATE ai_token_pack_purchases SET provider_session_id=NULL WHERE provider_session_id='';
UPDATE ai_token_pack_purchases SET provider_payment_intent_id=NULL WHERE provider_payment_intent_id='';
