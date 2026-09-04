-- Stonefellow one-time migration for an existing pre-roles installation.
-- Prefer /upgrade.php because it safely checks whether each column already exists.

ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER is_active;
ALTER TABLE tracks ADD COLUMN visibility VARCHAR(30) NOT NULL DEFAULT 'public' AFTER is_published;
ALTER TABLE tracks ADD INDEX idx_tracks_visibility (visibility);

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

INSERT INTO permissions (permission_key,label,description,category,sort_order) VALUES
('account.access','Account Dashboard','Access the signed-in user account dashboard.','Account',10),
('investor.access','Investor Area','Access private investor content.','Account',20),
('admin.access','Admin Dashboard','Enter the Stonefellow administration area.','Administration',30),
('tracks.manage','Manage Tracks','Add, edit, publish, upload and delete music tracks.','Content',40),
('shows.manage','Manage Shows','Add, edit, publish and delete show dates.','Content',50),
('messages.manage','Manage Messages','Read and delete contact-form messages.','Content',60),
('profile.manage','Manage Artist Profile','Edit artist bio, site copy, email and external links.','Content',70),
('users.manage','Manage Users','Create, edit, activate, deactivate and delete user accounts.','Security',80),
('permissions.manage','Manage Permissions','Change the permissions assigned to each account type.','Security',90)
ON DUPLICATE KEY UPDATE
label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order);

-- Fan
INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('fan','account.access');

-- Manager
INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('manager','account.access'),('manager','admin.access'),('manager','tracks.manage'),('manager','shows.manage'),('manager','profile.manage');

-- Supervisor
INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('supervisor','account.access'),('supervisor','admin.access'),('supervisor','tracks.manage'),('supervisor','shows.manage'),('supervisor','messages.manage'),('supervisor','profile.manage');

-- Investor
INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('investor','account.access'),('investor','investor.access');

-- Admin
INSERT IGNORE INTO role_permissions (role,permission_key) VALUES
('admin','account.access'),('admin','investor.access'),('admin','admin.access'),('admin','tracks.manage'),('admin','shows.manage'),('admin','messages.manage'),('admin','profile.manage'),('admin','users.manage'),('admin','permissions.manage');
