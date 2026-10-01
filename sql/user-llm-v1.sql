-- VP3 Cloud account-owned provider keys.
-- Reuses private/ai-key.php encryption. Never replicate ciphertext to HomeServer.
CREATE TABLE IF NOT EXISTS user_llm_credentials (
  user_id INT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  encrypted_key TEXT NOT NULL,
  model VARCHAR(160) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,provider),
  CONSTRAINT fk_user_llm_credentials_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_llm_preferences (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  route VARCHAR(24) NOT NULL DEFAULT 'system',
  provider VARCHAR(32) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_llm_preferences_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
