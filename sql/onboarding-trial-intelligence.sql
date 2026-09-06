-- VP3 onboarding/trial intelligence migration
-- Safe to run once after the existing user_agent_preferences table is installed.

ALTER TABLE user_agent_preferences
  ADD COLUMN voice_preference VARCHAR(20) NULL AFTER onboarding_dismissed,
  ADD COLUMN onboarding_step VARCHAR(40) NOT NULL DEFAULT 'voice' AFTER voice_preference,
  ADD COLUMN onboarding_draft_json LONGTEXT NULL AFTER onboarding_step,
  ADD COLUMN feature_interest_json LONGTEXT NULL AFTER onboarding_draft_json,
  ADD COLUMN last_trial_notice_threshold TINYINT UNSIGNED NULL AFTER feature_interest_json,
  ADD COLUMN last_trial_notice_at DATETIME NULL AFTER last_trial_notice_threshold;
