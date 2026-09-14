-- VP3 Phase 19.0 — Durable Agent Job Engine
-- Extends the canonical Phase 14 workflow ledger. The Agent Brain remains the
-- decision/prioritization layer; this migration only adds durable execution state.

ALTER TABLE agent_workflow_runs
  ADD COLUMN IF NOT EXISTS next_attempt_at DATETIME NULL AFTER last_error_class,
  ADD COLUMN IF NOT EXISTS lease_owner VARCHAR(120) NOT NULL DEFAULT '' AFTER next_attempt_at,
  ADD COLUMN IF NOT EXISTS lease_token CHAR(64) NOT NULL DEFAULT '' AFTER lease_owner,
  ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER lease_token,
  ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER lease_expires_at,
  ADD COLUMN IF NOT EXISTS progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER heartbeat_at,
  ADD COLUMN IF NOT EXISTS progress_message VARCHAR(500) NOT NULL DEFAULT '' AFTER progress_percent,
  ADD COLUMN IF NOT EXISTS max_attempts INT UNSIGNED NOT NULL DEFAULT 3 AFTER progress_message,
  ADD COLUMN IF NOT EXISTS retry_backoff_seconds INT UNSIGNED NOT NULL DEFAULT 60 AFTER max_attempts,
  ADD COLUMN IF NOT EXISTS timeout_seconds INT UNSIGNED NOT NULL DEFAULT 900 AFTER retry_backoff_seconds;

ALTER TABLE agent_workflow_actions
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL AFTER error_class,
  ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER available_at,
  ADD COLUMN IF NOT EXISTS progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER heartbeat_at,
  ADD COLUMN IF NOT EXISTS progress_message VARCHAR(500) NOT NULL DEFAULT '' AFTER progress_percent,
  ADD COLUMN IF NOT EXISTS max_attempts INT UNSIGNED NOT NULL DEFAULT 3 AFTER progress_message,
  ADD COLUMN IF NOT EXISTS timeout_seconds INT UNSIGNED NOT NULL DEFAULT 900 AFTER max_attempts;

CREATE TABLE IF NOT EXISTS agent_workflow_action_dependencies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  depends_on_action_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_job_dependency (action_id,depends_on_action_id),
  INDEX idx_agent_job_dependency_run (run_id,action_id),
  CONSTRAINT fk_agent_job_dependency_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_job_dependency_action FOREIGN KEY (action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_job_dependency_on_action FOREIGN KEY (depends_on_action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_job_dependency_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_workflow_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  receipt_key CHAR(64) NOT NULL,
  executor VARCHAR(24) NOT NULL DEFAULT 'cloud',
  worker_id VARCHAR(120) NOT NULL DEFAULT '',
  attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(24) NOT NULL DEFAULT 'completed',
  summary VARCHAR(500) NOT NULL DEFAULT '',
  result_json MEDIUMTEXT NULL,
  error_class VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_job_owner_receipt (owner_user_id,receipt_key),
  INDEX idx_agent_job_receipt_run (run_id,action_id,id),
  CONSTRAINT fk_agent_job_receipt_run FOREIGN KEY (run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_job_receipt_action FOREIGN KEY (action_id) REFERENCES agent_workflow_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_job_receipt_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
