-- Stonefellow v189 — explicit multi-role integrity.
-- Historical secondary rows are intentionally left unverified and therefore
-- no longer grant access. Re-save a user in Admin > Users to explicitly
-- authorize every selected secondary account type.

ALTER TABLE user_account_types
  ADD COLUMN IF NOT EXISTS assigned_explicitly_at DATETIME NULL AFTER role;

UPDATE user_account_types uat
INNER JOIN users u ON u.id=uat.user_id AND u.role=uat.role
SET uat.assigned_explicitly_at=COALESCE(uat.assigned_explicitly_at,NOW());
