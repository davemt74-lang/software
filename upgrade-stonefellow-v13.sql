-- Stonefellow v13 upgrade
-- Adds rich track recommendation metadata, audited contact-message workflow,
-- and notifications used by the header icon/badge system.

-- ------------------------------------------------------------
-- Listening source: Player vs Agent Chat
-- ------------------------------------------------------------

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='track_play_sessions' AND COLUMN_NAME='source_context'
);
SET @q := IF(@c=0,
  "ALTER TABLE track_play_sessions ADD COLUMN source_context VARCHAR(30) NOT NULL DEFAULT 'player' AFTER referrer_host",
  "SELECT 'track_play_sessions.source_context exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- Tracks: recommendation / mood metadata
-- ------------------------------------------------------------

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='description'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN description TEXT NULL AFTER lyrics",
  "SELECT 'tracks.description exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='genre'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN genre VARCHAR(255) NOT NULL DEFAULT '' AFTER description",
  "SELECT 'tracks.genre exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='mood'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN mood VARCHAR(255) NOT NULL DEFAULT '' AFTER genre",
  "SELECT 'tracks.mood exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='energy'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN energy VARCHAR(30) NOT NULL DEFAULT '' AFTER mood",
  "SELECT 'tracks.energy exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='tempo_bpm'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN tempo_bpm SMALLINT UNSIGNED NULL AFTER energy",
  "SELECT 'tracks.tempo_bpm exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracks' AND COLUMN_NAME='keywords'
);
SET @q := IF(@c=0,
  "ALTER TABLE tracks ADD COLUMN keywords VARCHAR(500) NOT NULL DEFAULT '' AFTER tempo_bpm",
  "SELECT 'tracks.keywords exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE tracks SET description='' WHERE description IS NULL;

-- ------------------------------------------------------------
-- Contact-message workflow
-- ------------------------------------------------------------

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND COLUMN_NAME='status'
);
SET @q := IF(@c=0,
  "ALTER TABLE contact_messages ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'new' AFTER is_read",
  "SELECT 'contact_messages.status exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND COLUMN_NAME='assigned_user_id'
);
SET @q := IF(@c=0,
  "ALTER TABLE contact_messages ADD COLUMN assigned_user_id INT UNSIGNED NULL AFTER status",
  "SELECT 'contact_messages.assigned_user_id exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND COLUMN_NAME='admin_notes'
);
SET @q := IF(@c=0,
  "ALTER TABLE contact_messages ADD COLUMN admin_notes TEXT NULL AFTER assigned_user_id",
  "SELECT 'contact_messages.admin_notes exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND COLUMN_NAME='updated_at'
);
SET @q := IF(@c=0,
  "ALTER TABLE contact_messages ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
  "SELECT 'contact_messages.updated_at exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @i := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND INDEX_NAME='idx_messages_status_created'
);
SET @q := IF(@i=0,
  "ALTER TABLE contact_messages ADD INDEX idx_messages_status_created (status, created_at)",
  "SELECT 'idx_messages_status_created exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @i := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contact_messages' AND INDEX_NAME='idx_messages_assigned'
);
SET @q := IF(@i=0,
  "ALTER TABLE contact_messages ADD INDEX idx_messages_assigned (assigned_user_id)",
  "SELECT 'idx_messages_assigned exists'"
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing messages already read before v13 become Open rather than New.
UPDATE contact_messages
SET status='open'
WHERE is_read=1 AND status='new';

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(50) NOT NULL DEFAULT 'general',
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NOT NULL DEFAULT '',
  target_url VARCHAR(500) NOT NULL DEFAULT '',
  source_type VARCHAR(80) NOT NULL DEFAULT '',
  source_id BIGINT UNSIGNED NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
  INDEX idx_notifications_source (source_type, source_id),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill current unread contact submissions for active users whose role
-- can manage messages. Admin is included even if its permission row was
-- customized/missing because Admin is always privileged in Stonefellow.
INSERT INTO notifications
(user_id,type,title,body,target_url,source_type,source_id,is_read)
SELECT
  u.id,
  'contact_message',
  'New contact message',
  CONCAT(m.name,' — ',m.topic),
  CONCAT('/admin/messages.php?view=',m.id),
  'contact_message',
  m.id,
  0
FROM contact_messages m
JOIN users u
  ON u.is_active=1
WHERE m.is_read=0
  AND (
    u.role='admin'
    OR EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role=u.role
        AND rp.permission_key='messages.manage'
    )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM notifications n
    WHERE n.user_id=u.id
      AND n.type='contact_message'
      AND n.source_type='contact_message'
      AND n.source_id=m.id
  );
