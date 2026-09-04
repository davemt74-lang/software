-- Stonefellow v180 CRM
-- Admin-only demo lead workflow, activities and follow-up tasks.

CREATE TABLE IF NOT EXISTS crm_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  email_normalized VARCHAR(190) NOT NULL,
  phone VARCHAR(80) NOT NULL DEFAULT '',
  company VARCHAR(190) NOT NULL DEFAULT '',
  source VARCHAR(60) NOT NULL DEFAULT 'website',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_contacts_email_normalized (email_normalized),
  INDEX idx_crm_contacts_company (company),
  INDEX idx_crm_contacts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  source_contact_message_id INT UNSIGNED NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'book_demo',
  stage VARCHAR(40) NOT NULL DEFAULT 'new',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  role_interest VARCHAR(60) NOT NULL DEFAULT '',
  team_size VARCHAR(30) NOT NULL DEFAULT '',
  workflows_json LONGTEXT NULL,
  demo_focus TEXT NULL,
  internal_notes TEXT NULL,
  assigned_user_id INT UNSIGNED NULL,
  next_follow_up_at DATETIME NULL,
  demo_scheduled_at DATETIME NULL,
  last_contacted_at DATETIME NULL,
  stage_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_leads_source_message (source_contact_message_id),
  INDEX idx_crm_leads_stage_updated (stage, updated_at),
  INDEX idx_crm_leads_assigned_stage (assigned_user_id, stage),
  INDEX idx_crm_leads_followup (next_follow_up_at, stage),
  INDEX idx_crm_leads_demo (demo_scheduled_at, stage),
  INDEX idx_crm_leads_created (created_at),
  CONSTRAINT fk_crm_leads_contact FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_leads_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_activities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  activity_type VARCHAR(50) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_crm_activities_lead_created (lead_id, created_at, id),
  INDEX idx_crm_activities_user_created (user_id, created_at),
  CONSTRAINT fk_crm_activities_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lead_id BIGINT UNSIGNED NOT NULL,
  assigned_user_id INT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NULL,
  task_type VARCHAR(40) NOT NULL DEFAULT 'follow_up',
  title VARCHAR(190) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_crm_tasks_status_due (status, due_at),
  INDEX idx_crm_tasks_assigned_due (assigned_user_id, status, due_at),
  INDEX idx_crm_tasks_lead_status (lead_id, status, due_at),
  CONSTRAINT fk_crm_tasks_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_tasks_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
