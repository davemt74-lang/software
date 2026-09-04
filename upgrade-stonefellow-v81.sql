-- Stonefellow v81 — restricted staff presence + direct messaging
-- Rail/API access is limited in application code to manager, producer and supervisor roles.

CREATE TABLE IF NOT EXISTS team_user_presence (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    page_key VARCHAR(60) NOT NULL DEFAULT '',
    context_label VARCHAR(190) NOT NULL DEFAULT '',
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_team_presence_seen (last_seen_at),
    CONSTRAINT fk_team_presence_user
      FOREIGN KEY (user_id) REFERENCES users(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_direct_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_user_id INT UNSIGNED NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_team_dm_recipient_read (recipient_user_id, read_at, id),
    INDEX idx_team_dm_sender_recipient (sender_user_id, recipient_user_id, id),
    INDEX idx_team_dm_recipient_sender (recipient_user_id, sender_user_id, id),
    CONSTRAINT fk_team_dm_sender
      FOREIGN KEY (sender_user_id) REFERENCES users(id)
      ON DELETE CASCADE,
    CONSTRAINT fk_team_dm_recipient
      FOREIGN KEY (recipient_user_id) REFERENCES users(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
