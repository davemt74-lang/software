-- VP3 v0.20 — account-scoped Agent compute preference.
-- Idempotent: safe to run more than once.

CREATE TABLE IF NOT EXISTS agent_compute_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    preference VARCHAR(24) NOT NULL DEFAULT 'auto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_compute_preference_user
      FOREIGN KEY (user_id) REFERENCES users(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
