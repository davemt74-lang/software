-- Stonefellow v70 — Photos + Merch content types
-- Safe to run once. /upgrade.php performs the same schema work.

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
(
  'photos.manage',
  'Manage Photos',
  'Upload, edit, publish and delete Stonefellow photo-library images.',
  'Content',
  52
),
(
  'merch.manage',
  'Manage Merch',
  'Add, edit, publish and delete Stonefellow merchandise items.',
  'Content',
  54
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key)
VALUES
  ('manager','photos.manage'),
  ('manager','merch.manage'),
  ('supervisor','photos.manage'),
  ('supervisor','merch.manage');

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
  visibility VARCHAR(30) NOT NULL DEFAULT 'members',
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_merch_published_sort (is_published, sort_order, id),
  INDEX idx_merch_visibility (visibility),
  INDEX idx_merch_creator (created_by_user_id),
  CONSTRAINT fk_merch_creator
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
