-- VP3 self-service plan management
-- Apply after the core subscription tables from PR #43 are installed.

CREATE TABLE IF NOT EXISTS subscription_plan_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  from_package_id INT UNSIGNED NULL,
  target_package_id INT UNSIGNED NULL,
  action VARCHAR(30) NOT NULL,
  billing_interval VARCHAR(20) NOT NULL DEFAULT 'monthly',
  status VARCHAR(30) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  effective_at DATETIME NULL,
  resolved_at DATETIME NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_plan_requests_user_open (user_id,status,id),
  INDEX idx_plan_requests_due (status,effective_at,id),
  INDEX idx_plan_requests_subscription (subscription_id,id),
  CONSTRAINT fk_plan_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_requests_subscription FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL,
  CONSTRAINT fk_plan_requests_from_package FOREIGN KEY (from_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_plan_requests_target_package FOREIGN KEY (target_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
