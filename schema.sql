CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL DEFAULT 'User',
  role VARCHAR(30) NOT NULL DEFAULT 'fan',
  avatar_path VARCHAR(500) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v104 keeps users.role as the primary/backward-compatible account type while
-- allowing each user to hold any number of additional account types here.
CREATE TABLE IF NOT EXISTS user_account_types (
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(30) NOT NULL,
  assigned_explicitly_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role),
  INDEX idx_user_account_types_role (role, user_id),
  CONSTRAINT fk_user_account_types_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v104 delegated Artist workspace seats. A delegated Manager/Producer account
-- belongs to one Artist until global Admin promotes it into broader access.
CREATE TABLE IF NOT EXISTS artist_team_members (
  artist_user_id INT UNSIGNED NOT NULL,
  member_user_id INT UNSIGNED NOT NULL,
  team_role VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (artist_user_id, member_user_id),
  UNIQUE KEY uq_artist_team_member (member_user_id),
  INDEX idx_artist_team_role (artist_user_id, team_role, member_user_id),
  CONSTRAINT fk_artist_team_artist
    FOREIGN KEY (artist_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_artist_team_member
    FOREIGN KEY (member_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  permission_key VARCHAR(100) PRIMARY KEY,
  label VARCHAR(190) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  category VARCHAR(100) NOT NULL DEFAULT 'General',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role VARCHAR(30) NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role, permission_key),
  CONSTRAINT fk_role_permissions_permission
    FOREIGN KEY (permission_key) REFERENCES permissions(permission_key)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS albums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  release_date DATE NULL,
  description TEXT NOT NULL,
  cover_path VARCHAR(500) NOT NULL DEFAULT '',
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_albums_published_sort (is_published, sort_order, id),
  INDEX idx_albums_visibility (visibility),
  INDEX idx_albums_creator (created_by_user_id),
  CONSTRAINT fk_albums_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,
  producer_user_id INT UNSIGNED NULL,
  album_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  album VARCHAR(190) NOT NULL DEFAULT 'Stonefellow',
  duration VARCHAR(20) NOT NULL DEFAULT '',
  lyrics LONGTEXT NOT NULL,
  credits TEXT NULL,
  description TEXT NOT NULL,
  genre VARCHAR(255) NOT NULL DEFAULT '',
  mood VARCHAR(255) NOT NULL DEFAULT '',
  energy VARCHAR(30) NOT NULL DEFAULT '',
  tempo_bpm SMALLINT UNSIGNED NULL,
  keywords VARCHAR(500) NOT NULL DEFAULT '',
  audio_path VARCHAR(500) NOT NULL,
  cover_path VARCHAR(500) NOT NULL DEFAULT '/images/stonefellow-studio.png',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  visibility VARCHAR(30) NOT NULL DEFAULT 'public',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tracks_published_order (is_published, sort_order, id),
  INDEX idx_tracks_visibility (visibility),
  INDEX idx_tracks_owner_updated (owner_user_id, updated_at),
  INDEX idx_tracks_producer_updated (producer_user_id, updated_at),
  INDEX idx_tracks_album_sort (album_id, sort_order, id),
  CONSTRAINT fk_tracks_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_tracks_producer
    FOREIGN KEY (producer_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_tracks_album
    FOREIGN KEY (album_id) REFERENCES albums(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_favorites (
  user_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, track_id),
  INDEX idx_track_favorites_track_created (track_id, created_at),
  INDEX idx_track_favorites_user_created (user_id, created_at),
  CONSTRAINT fk_track_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_track_favorites_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,
  show_date DATETIME NOT NULL,
  venue VARCHAR(190) NOT NULL,
  city VARCHAR(120) NOT NULL DEFAULT '',
  region VARCHAR(120) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  ticket_url VARCHAR(500) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_shows_date (show_date),
  INDEX idx_shows_published_date (is_published, show_date),
  INDEX idx_shows_owner_date (owner_user_id, show_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  caption TEXT NOT NULL,
  alt_text VARCHAR(255) NOT NULL DEFAULT '',
  image_path VARCHAR(500) NOT NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_photos_published_sort (is_published, sort_order, id),
  INDEX idx_photos_visibility (visibility),
  INDEX idx_photos_creator (created_by_user_id),
  CONSTRAINT fk_photos_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merch_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  product_url VARCHAR(500) NOT NULL DEFAULT '',
  image_path VARCHAR(500) NOT NULL DEFAULT '',
  album_id INT UNSIGNED NULL,
  track_id INT UNSIGNED NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_merch_published_sort (is_published, sort_order, id),
  INDEX idx_merch_visibility (visibility),
  INDEX idx_merch_creator (created_by_user_id),
  INDEX idx_merch_album (album_id),
  INDEX idx_merch_track (track_id),
  CONSTRAINT fk_merch_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_merch_album
    FOREIGN KEY (album_id) REFERENCES albums(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_merch_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_playlists_owner_updated (owner_user_id, updated_at),
  INDEX idx_playlists_visibility (visibility),
  CONSTRAINT fk_playlists_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_tracks (
  playlist_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (playlist_id, track_id),
  INDEX idx_playlist_tracks_sort (playlist_id, sort_order, track_id),
  CONSTRAINT fk_playlist_tracks_playlist
    FOREIGN KEY (playlist_id) REFERENCES playlists(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_playlist_tracks_track
    FOREIGN KEY (track_id) REFERENCES tracks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS album_favorites (
  user_id INT UNSIGNED NOT NULL,
  album_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, album_id),
  INDEX idx_album_favorites_album_created (album_id, created_at),
  CONSTRAINT fk_album_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_album_favorites_album
    FOREIGN KEY (album_id) REFERENCES albums(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_favorites (
  user_id INT UNSIGNED NOT NULL,
  playlist_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, playlist_id),
  INDEX idx_playlist_favorites_playlist_created (playlist_id, created_at),
  CONSTRAINT fk_playlist_favorites_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_playlist_favorites_playlist
    FOREIGN KEY (playlist_id) REFERENCES playlists(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS show_reminders (
  user_id INT UNSIGNED NOT NULL,
  show_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reminded_at DATETIME NULL,
  PRIMARY KEY (user_id, show_id),
  INDEX idx_show_reminders_show (show_id, created_at),
  CONSTRAINT fk_show_reminders_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_show_reminders_show
    FOREIGN KEY (show_id) REFERENCES shows(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  post_type VARCHAR(30) NOT NULL DEFAULT 'update',
  image_path VARCHAR(500) NOT NULL DEFAULT '',
  media_url VARCHAR(500) NOT NULL DEFAULT '',
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_artist_posts_published (is_published, published_at, id),
  INDEX idx_artist_posts_visibility (visibility),
  CONSTRAINT fk_artist_posts_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  topic VARCHAR(80) NOT NULL,
  message TEXT NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  assigned_user_id INT UNSIGNED NULL,
  admin_notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_messages_read_created (is_read, created_at),
  INDEX idx_messages_status_created (status, created_at),
  INDEX idx_messages_assigned (assigned_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  file_type VARCHAR(50) NOT NULL DEFAULT 'text',
  mime_type VARCHAR(120) NOT NULL DEFAULT '',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  content_text LONGTEXT NOT NULL,
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_kb_published_visibility (is_published, visibility),
  INDEX idx_kb_creator (created_by_user_id),
  INDEX idx_knowledge_track (track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  knowledge_id INT UNSIGNED NOT NULL,
  chunk_index INT NOT NULL DEFAULT 0,
  chunk_text TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kb_chunks_item (knowledge_id, chunk_index),
  FULLTEXT KEY ft_kb_chunk (chunk_text),
  CONSTRAINT fk_knowledge_chunks_item
    FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT 'New chat',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_chat_conversations_user (user_id, updated_at),
  CONSTRAINT fk_chat_conversations_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  role VARCHAR(20) NOT NULL,
  message LONGTEXT NOT NULL,
  context_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_messages_conversation (conversation_id, id),
  CONSTRAINT fk_chat_messages_conversation
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  region_start_seconds DECIMAL(12,4) NULL,
  region_end_seconds DECIMAL(12,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_notes_track (track_id, created_at),
  INDEX idx_track_notes_user (user_id),
  INDEX idx_track_notes_region (track_id, region_start_seconds, created_at),
  CONSTRAINT fk_track_notes_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_play_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_token CHAR(64) NOT NULL UNIQUE,
  track_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  listener_hash CHAR(64) NOT NULL,
  device_type VARCHAR(30) NOT NULL DEFAULT 'unknown',
  referrer_host VARCHAR(190) NOT NULL DEFAULT '',
  source_context VARCHAR(30) NOT NULL DEFAULT 'player',
  listener_city VARCHAR(120) NOT NULL DEFAULT '',
  listener_region VARCHAR(120) NOT NULL DEFAULT '',
  listener_country VARCHAR(80) NOT NULL DEFAULT '',
  listener_latitude DECIMAL(9,6) NULL,
  listener_longitude DECIMAL(9,6) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  listened_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  last_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  max_position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  completion_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
  qualified_play TINYINT(1) NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_play_track_started (track_id, started_at),
  INDEX idx_play_user_started (user_id, started_at),
  INDEX idx_play_listener_started (listener_hash, started_at),
  CONSTRAINT fk_play_session_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_play_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_play_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(30) NOT NULL,
  position_seconds DECIMAL(12,2) NOT NULL DEFAULT 0,
  listened_delta_seconds DECIMAL(8,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_play_events_session (session_id, id),
  INDEX idx_play_events_type_time (event_type, created_at),
  CONSTRAINT fk_play_events_session FOREIGN KEY (session_id) REFERENCES track_play_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS track_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL UNIQUE,
  project_name VARCHAR(190) NOT NULL DEFAULT '',
  source_zip_name VARCHAR(255) NOT NULL DEFAULT '',
  rpp_file_name VARCHAR(255) NOT NULL DEFAULT '',
  rpp_file_path VARCHAR(500) NOT NULL DEFAULT '',
  tempo_bpm DECIMAL(7,2) NULL,
  time_signature VARCHAR(20) NOT NULL DEFAULT '',
  duration_measures INT UNSIGNED NULL,
  project_sample_rate INT UNSIGNED NULL,
  media_sample_rate INT UNSIGNED NULL,
  project_start_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  master_plugin_chain_json LONGTEXT NULL,
  imported_by_user_id INT UNSIGNED NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_projects_imported (imported_at),
  CONSTRAINT fk_track_projects_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_projects_user FOREIGN KEY (imported_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_stems (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  stem_name VARCHAR(190) NOT NULL,
  stem_role VARCHAR(80) NOT NULL DEFAULT 'Other',
  source_track_name VARCHAR(190) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  channels TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sample_rate INT UNSIGNED NOT NULL DEFAULT 0,
  bit_depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  start_offset_seconds DECIMAL(12,4) NOT NULL DEFAULT 0,
  rpp_track_guid VARCHAR(80) NOT NULL DEFAULT '',
  rpp_volume DECIMAL(12,6) NOT NULL DEFAULT 1,
  rpp_pan DECIMAL(8,6) NOT NULL DEFAULT 0,
  rpp_fx_summary VARCHAR(1000) NOT NULL DEFAULT '',
  plugin_chain_json LONGTEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_track_stems_track_order (track_id,is_active,sort_order,id),
  INDEX idx_track_stems_role (track_id,stem_role),
  CONSTRAINT fk_track_stems_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_track_stems_project FOREIGN KEY (project_id) REFERENCES track_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stem_mix_saves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  mix_name VARCHAR(120) NOT NULL DEFAULT 'My Mix',
  mix_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stem_mix_user_track (user_id,track_id,updated_at),
  CONSTRAINT fk_stem_mix_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_stem_mix_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stonefellow v81 — restricted staff presence + direct messaging
-- Rail/API access is permission/role gated in application code.
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

CREATE TABLE IF NOT EXISTS agent_chat_archive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  source_message_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  input_mode VARCHAR(20) NOT NULL DEFAULT 'text',
  message_text LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_archive_source (user_id, source_message_id),
  INDEX idx_agent_archive_user_created (user_id, created_at, id),
  INDEX idx_agent_archive_conversation (user_id, conversation_id, id),
  CONSTRAINT fk_agent_archive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_memory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  memory_type VARCHAR(40) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  memory_text TEXT NOT NULL,
  memory_hash CHAR(40) NOT NULL,
  source_archive_id BIGINT UNSIGNED NULL,
  confidence DECIMAL(4,3) NOT NULL DEFAULT 0.750,
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json LONGTEXT NULL,
  UNIQUE KEY uq_agent_memory_hash (user_id, memory_hash),
  INDEX idx_agent_memory_type (user_id, memory_type, is_active, last_seen_at),
  INDEX idx_agent_memory_occurrence (user_id, occurrence_count, last_seen_at),
  CONSTRAINT fk_agent_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_memory_archive FOREIGN KEY (source_archive_id) REFERENCES agent_chat_archive(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_tool_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NULL,tool_key VARCHAR(80) NOT NULL,request_text TEXT NOT NULL,status VARCHAR(30) NOT NULL,result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_tool_user_created(user_id,created_at,id),INDEX idx_agent_tool_key(tool_key,created_at),CONSTRAINT fk_agent_tool_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agent_studio_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,track_id INT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_studio_user(user_id,last_activity_at),INDEX idx_agent_studio_track(track_id,last_activity_at),CONSTRAINT fk_agent_studio_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_track FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agent_studio_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,session_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,role VARCHAR(20) NOT NULL,message_text TEXT NOT NULL,command_json LONGTEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'complete',result_text TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_agent_studio_history(session_id,id),CONSTRAINT fk_agent_studio_history_session FOREIGN KEY(session_id) REFERENCES agent_studio_sessions(id) ON DELETE CASCADE,CONSTRAINT fk_agent_studio_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS booking_agent_research (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,query_text VARCHAR(500) NOT NULL,market_label VARCHAR(190) NOT NULL DEFAULT '',result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_booking_research_user(user_id,created_at,id),CONSTRAINT fk_booking_research_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS booking_agent_opportunities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(190) NOT NULL,venue VARCHAR(190) NOT NULL DEFAULT '',city VARCHAR(120) NOT NULL DEFAULT '',region VARCHAR(120) NOT NULL DEFAULT '',source_url VARCHAR(700) NOT NULL DEFAULT '',status VARCHAR(30) NOT NULL DEFAULT 'lead',notes TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_booking_opportunity_user_status(user_id,status,updated_at),CONSTRAINT fk_booking_opportunity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stonefellow v86 private user media + Video Editor
CREATE TABLE IF NOT EXISTS user_media_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  media_type VARCHAR(20) NOT NULL,
  title VARCHAR(190) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
  source VARCHAR(60) NOT NULL DEFAULT 'upload',
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_media_user_created (user_id,created_at,id),
  INDEX idx_user_media_type (user_id,media_type,created_at),
  CONSTRAINT fk_user_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_editor_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  settings_json LONGTEXT NULL,
  timeline_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_video_projects_user_updated (user_id,updated_at,id),
  CONSTRAINT fk_video_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_edit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  editor_kind VARCHAR(30) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  session_key VARCHAR(100) NOT NULL DEFAULT '',
  source_kind VARCHAR(20) NOT NULL DEFAULT 'manual',
  action_key VARCHAR(100) NOT NULL DEFAULT 'edit',
  request_text TEXT NULL,
  model_provider VARCHAR(30) NOT NULL DEFAULT '',
  model_name VARCHAR(100) NOT NULL DEFAULT '',
  playhead_seconds DECIMAL(12,4) NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  changes_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_edit_user_created (user_id, created_at, id),
  INDEX idx_agent_edit_project (editor_kind, project_id, created_at, id),
  INDEX idx_agent_edit_session (session_key, created_at, id),
  INDEX idx_agent_edit_source (source_kind, created_at),
  CONSTRAINT fk_agent_edit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stonefellow v93 — proactive Agent Brain opportunity feedback
CREATE TABLE IF NOT EXISTS agent_proactive_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  suggestion_hash CHAR(40) NOT NULL,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  event_type VARCHAR(20) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  prompt VARCHAR(1000) NOT NULL DEFAULT '',
  source_kind VARCHAR(60) NOT NULL DEFAULT '',
  context_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_proactive_user_created (user_id,created_at,id),
  INDEX idx_agent_proactive_hash_event (user_id,suggestion_hash,event_type,created_at),
  INDEX idx_agent_proactive_surface (user_id,surface,created_at),
  CONSTRAINT fk_agent_proactive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stonefellow v94 — Agent activity awareness
CREATE TABLE IF NOT EXISTS agent_activity_state (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  context_key VARCHAR(120) NOT NULL DEFAULT '',
  task_kind VARCHAR(40) NOT NULL DEFAULT '',
  task_title VARCHAR(190) NOT NULL DEFAULT '',
  activity_state VARCHAR(20) NOT NULL DEFAULT 'idle',
  last_input_at DATETIME NULL,
  last_task_at DATETIME NULL,
  last_heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  idle_since_at DATETIME NULL,
  details_json LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agent_activity_state (activity_state,last_heartbeat_at),
  CONSTRAINT fk_agent_activity_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_activity_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  surface VARCHAR(30) NOT NULL DEFAULT 'chat',
  context_key VARCHAR(120) NOT NULL DEFAULT '',
  task_kind VARCHAR(40) NOT NULL DEFAULT '',
  task_title VARCHAR(190) NOT NULL DEFAULT '',
  previous_state VARCHAR(20) NOT NULL DEFAULT '',
  activity_state VARCHAR(20) NOT NULL,
  reason VARCHAR(120) NOT NULL DEFAULT '',
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_agent_activity_user_created (user_id,created_at,id),
  INDEX idx_agent_activity_context (user_id,context_key,created_at,id),
  INDEX idx_agent_activity_transition (user_id,activity_state,created_at),
  CONSTRAINT fk_agent_activity_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
