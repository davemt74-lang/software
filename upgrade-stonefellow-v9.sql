-- Stonefellow v9
-- Adds the AI/API Settings permission to an existing permission catalog.
-- No database schema changes are required.

INSERT INTO permissions
(permission_key,label,description,category,sort_order)
VALUES
(
  'ai.manage',
  'Manage AI / API Settings',
  'Configure OpenAI and Claude API providers, models and encrypted API credentials.',
  'Security',
  85
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description),
  category=VALUES(category),
  sort_order=VALUES(sort_order);

INSERT IGNORE INTO role_permissions (role,permission_key)
VALUES ('admin','ai.manage');
