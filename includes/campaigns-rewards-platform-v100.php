<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1 canonical platform schema.
 *
 * This module implements the frozen V1 database map. VP3 Core remains
 * authoritative for users, Team, CRM identity, Messaging, Commerce,
 * Scheduling, Knowledge and cognition. These tables add merchant context and
 * Campaigns & Rewards domain state only.
 */
const VP3_CAMPAIGNS_REWARDS_PLATFORM_V100='vp3-campaigns-rewards-platform-v100-20260923';

function campaigns_rewards_platform_required_tables_v100(): array
{
    return [
        'crm_merchant_relationships',
        'merchant_accounts','merchant_profiles','merchant_roles','merchant_role_capabilities','merchant_members','merchant_member_capability_overrides','merchant_locations','merchant_member_locations',
        'merchant_claim_codes','merchant_claim_code_campaigns',
        'campaign_types','campaigns','campaign_versions','campaign_audiences','campaign_audience_members','campaign_enrollments','campaign_cases',
        'campaign_landing_pages','campaign_profile_publications','campaign_public_sessions','campaign_public_events',
        'campaign_messages','campaign_deliveries',
        'reward_types','reward_products','reward_product_variants','campaign_reward_sets','campaign_reward_set_items',
        'reward_issuances','reward_claims','reward_claim_attempts','reward_claim_adjustments',
        'reward_inventory_balances','reward_inventory_ledger',
        'loyalty_programs','loyalty_tiers','loyalty_accounts','loyalty_ledger',
        'campaign_automation_rules','campaign_rule_executions','campaigns_rewards_object_bindings','campaign_activity_events',
        'merchant_ownership_events','campaign_simulation_runs','campaign_reviews','campaign_creative_assets',
        'vp3_custom_field_definitions','vp3_custom_field_values','integration_external_identities',
        'campaign_webhook_endpoints','campaign_webhook_deliveries','campaign_data_jobs',
        'campaign_funding_sources','reward_liability_ledger','reward_support_cases','campaign_governance_policies',
        'campaign_idempotency_keys','campaign_schema_versions','campaign_reconciliation_runs','campaign_reconciliation_findings',
        'campaign_cognitive_event_receipts','campaign_agent_recommendations',
    ];
}

function campaigns_rewards_platform_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    if(!table_exists('workspace_team_access_v1')||!table_exists('workspace_team_invitation_scopes_v1'))return false;
    foreach(campaigns_rewards_platform_required_tables_v100() as $table)if(!table_exists($table))return false;
    foreach(['public_id','owner_user_id','vp3_user_id','status','lifecycle_stage','marketing_status','metadata_json'] as $column){
        if(function_exists('column_exists')&&!column_exists('crm_contacts',$column))return false;
    }
    return true;
}

function campaigns_rewards_core_crm_ensure_v100(PDO $pdo): void
{
    if(!table_exists('crm_contacts'))return;
    $columns=[
        'public_id'=>"CHAR(36) NULL AFTER id",
        'owner_user_id'=>"INT UNSIGNED NULL AFTER public_id",
        'vp3_user_id'=>"INT UNSIGNED NULL AFTER owner_user_id",
        'status'=>"VARCHAR(30) NOT NULL DEFAULT 'active' AFTER source",
        'lifecycle_stage'=>"VARCHAR(60) NOT NULL DEFAULT '' AFTER status",
        'assigned_user_id'=>"INT UNSIGNED NULL AFTER lifecycle_stage",
        'marketing_status'=>"VARCHAR(30) NOT NULL DEFAULT 'unknown' AFTER assigned_user_id",
        'metadata_json'=>"LONGTEXT NULL AFTER marketing_status",
    ];
    foreach($columns as $name=>$ddl){
        if(function_exists('column_exists')&&!column_exists('crm_contacts',$name))$pdo->exec("ALTER TABLE crm_contacts ADD COLUMN {$name} {$ddl}");
    }
    $pdo->exec("UPDATE crm_contacts SET public_id=UUID() WHERE public_id IS NULL OR public_id=''");
    foreach([
        "ALTER TABLE crm_contacts DROP INDEX uq_crm_contacts_email_normalized",
        "ALTER TABLE crm_contacts ADD UNIQUE KEY uq_crm_contacts_public (public_id)",
        "ALTER TABLE crm_contacts ADD UNIQUE KEY uq_crm_contacts_owner_email (owner_user_id,email_normalized)",
        "ALTER TABLE crm_contacts ADD INDEX idx_crm_contacts_owner_user (owner_user_id,vp3_user_id)",
        "ALTER TABLE crm_contacts ADD INDEX idx_crm_contacts_owner_stage (owner_user_id,lifecycle_stage,updated_at)",
    ] as $sql){try{$pdo->exec($sql);}catch(Throwable $e){}}

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_emails (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,email VARCHAR(190) NOT NULL,email_normalized VARCHAR(190) NOT NULL,label VARCHAR(50) NOT NULL DEFAULT 'primary',is_primary TINYINT(1) NOT NULL DEFAULT 0,verified_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_crm_contact_email (contact_id,email_normalized),INDEX idx_crm_email_lookup (email_normalized,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_phones (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,phone VARCHAR(80) NOT NULL,phone_normalized VARCHAR(80) NOT NULL,label VARCHAR(50) NOT NULL DEFAULT 'primary',is_primary TINYINT(1) NOT NULL DEFAULT 0,verified_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_crm_contact_phone (contact_id,phone_normalized),INDEX idx_crm_phone_lookup (phone_normalized,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_identity_links (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,identity_type VARCHAR(40) NOT NULL,identity_key VARCHAR(255) NOT NULL,source VARCHAR(80) NOT NULL DEFAULT '',linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,
      UNIQUE KEY uq_crm_identity (contact_id,identity_type,identity_key),INDEX idx_crm_identity_lookup (identity_type,identity_key(190))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_preferences (
      contact_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,marketing_email TINYINT(1) NOT NULL DEFAULT 0,marketing_sms TINYINT(1) NOT NULL DEFAULT 0,marketing_push TINYINT(1) NOT NULL DEFAULT 0,transactional_allowed TINYINT(1) NOT NULL DEFAULT 1,preferred_channel VARCHAR(30) NOT NULL DEFAULT '',quiet_hours_json LONGTEXT NULL,consent_json LONGTEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_tags (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,slug VARCHAR(120) NOT NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_crm_tag_owner_slug (owner_user_id,slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_tags (
      contact_id BIGINT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,created_by_user_id INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(contact_id,tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_segments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,slug VARCHAR(120) NOT NULL,segment_mode VARCHAR(20) NOT NULL DEFAULT 'static',rules_json LONGTEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_crm_segment_owner_slug (owner_user_id,slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_segment_members (
      segment_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,source VARCHAR(50) NOT NULL DEFAULT 'manual',added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(segment_id,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_notes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,author_user_id INT UNSIGNED NULL,body TEXT NOT NULL,visibility VARCHAR(30) NOT NULL DEFAULT 'private',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_crm_contact_notes (contact_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_events_v1 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(120) NOT NULL,summary VARCHAR(500) NOT NULL DEFAULT '',source_kind VARCHAR(80) NOT NULL DEFAULT '',source_id VARCHAR(120) NOT NULL DEFAULT '',actor_type VARCHAR(30) NOT NULL DEFAULT 'user',actor_user_id INT UNSIGNED NULL,occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,
      INDEX idx_crm_contact_event (owner_user_id,contact_id,occurred_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO crm_contact_emails (contact_id,email,email_normalized,label,is_primary,created_at,updated_at)
      SELECT id,email,email_normalized,'primary',1,created_at,updated_at FROM crm_contacts WHERE email_normalized<>''");
    $pdo->exec("INSERT IGNORE INTO crm_contact_phones (contact_id,phone,phone_normalized,label,is_primary,created_at,updated_at)
      SELECT id,phone,LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','')),'primary',1,created_at,updated_at FROM crm_contacts WHERE phone<>''");
}

function campaigns_rewards_platform_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if($pdo->inTransaction()&&!campaigns_rewards_platform_schema_ready_v100($pdo))throw new RuntimeException('Run the VP3 database upgrade before Campaigns & Rewards mutations.');
    if(function_exists('workspace_team_v350_ensure_schema'))workspace_team_v350_ensure_schema($pdo);
    campaigns_rewards_core_crm_ensure_v100($pdo);
    $exec=static function(string $sql)use($pdo):void{$pdo->exec($sql);};

    $exec("CREATE TABLE IF NOT EXISTS crm_merchant_relationships (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,customer_status VARCHAR(40) NOT NULL DEFAULT 'prospect',loyalty_status VARCHAR(40) NOT NULL DEFAULT '',acquisition_source VARCHAR(120) NOT NULL DEFAULT '',assigned_member_id BIGINT UNSIGNED NULL,marketing_status VARCHAR(30) NOT NULL DEFAULT 'unknown',customer_since DATETIME NULL,last_purchase_at DATETIME NULL,merchant_notes TEXT NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_crm_merchant_contact (merchant_id,contact_id),INDEX idx_crm_merchant_status (merchant_id,customer_status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS merchant_accounts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,owner_user_id INT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,slug VARCHAR(120) NOT NULL,business_name VARCHAR(190) NOT NULL DEFAULT '',status VARCHAR(20) NOT NULL DEFAULT 'active',timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',currency CHAR(3) NOT NULL DEFAULT 'USD',sandbox_mode TINYINT(1) NOT NULL DEFAULT 0,settings_json LONGTEXT NULL,suspended_at DATETIME NULL,archived_at DATETIME NULL,closed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_merchant_public (public_id),UNIQUE KEY uq_merchant_owner_slug (owner_user_id,slug),INDEX idx_merchant_owner_status (owner_user_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_profiles (
      merchant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,display_name VARCHAR(190) NOT NULL DEFAULT '',description TEXT NULL,logo_path VARCHAR(500) NOT NULL DEFAULT '',cover_path VARCHAR(500) NOT NULL DEFAULT '',website_url VARCHAR(500) NOT NULL DEFAULT '',social_links_json LONGTEXT NULL,public_contact_json LONGTEXT NULL,branding_json LONGTEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_roles (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NULL,role_key VARCHAR(80) NOT NULL,name VARCHAR(120) NOT NULL,description VARCHAR(500) NOT NULL DEFAULT '',is_system TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_merchant_role (merchant_id,role_key),INDEX idx_merchant_role_active (merchant_id,is_active,role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_role_capabilities (
      role_id BIGINT UNSIGNED NOT NULL,capability_key VARCHAR(120) NOT NULL,effect VARCHAR(10) NOT NULL DEFAULT 'allow',PRIMARY KEY(role_id,capability_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_members (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',is_owner TINYINT(1) NOT NULL DEFAULT 0,joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,suspended_at DATETIME NULL,removed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_merchant_member (merchant_id,user_id),INDEX idx_merchant_member_user (user_id,status,merchant_id),INDEX idx_merchant_owner (merchant_id,is_owner,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_member_capability_overrides (
      merchant_member_id BIGINT UNSIGNED NOT NULL,capability_key VARCHAR(120) NOT NULL,effect VARCHAR(10) NOT NULL DEFAULT 'allow',reason VARCHAR(500) NOT NULL DEFAULT '',granted_by_user_id INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(merchant_member_id,capability_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_locations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,location_type VARCHAR(30) NOT NULL DEFAULT 'store',address1 VARCHAR(190) NOT NULL DEFAULT '',address2 VARCHAR(190) NOT NULL DEFAULT '',city VARCHAR(120) NOT NULL DEFAULT '',region VARCHAR(120) NOT NULL DEFAULT '',postal_code VARCHAR(40) NOT NULL DEFAULT '',country VARCHAR(80) NOT NULL DEFAULT 'US',phone VARCHAR(80) NOT NULL DEFAULT '',timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',is_active TINYINT(1) NOT NULL DEFAULT 1,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_merchant_location_public (public_id),INDEX idx_merchant_location (merchant_id,is_active,name,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_member_locations (
      merchant_member_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,role_key VARCHAR(80) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(merchant_member_id,location_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS merchant_claim_codes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,display_name VARCHAR(190) NOT NULL,code_hash CHAR(64) NOT NULL,code_last4 VARCHAR(8) NOT NULL,location_id BIGINT UNSIGNED NULL,merchant_member_id BIGINT UNSIGNED NULL,device_label VARCHAR(120) NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',active_from DATETIME NULL,active_until DATETIME NULL,daily_claim_limit INT UNSIGNED NULL,total_claim_limit INT UNSIGNED NULL,max_value_minor BIGINT UNSIGNED NULL,currency CHAR(3) NULL,rules_json LONGTEXT NULL,created_by_user_id INT UNSIGNED NOT NULL,last_used_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_claim_code_public (public_id),UNIQUE KEY uq_claim_code_hash (code_hash),INDEX idx_claim_code_merchant (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS merchant_claim_code_campaigns (
      claim_code_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(claim_code_id,campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_types (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NULL,type_key VARCHAR(80) NOT NULL,name VARCHAR(120) NOT NULL,description VARCHAR(500) NOT NULL DEFAULT '',base_handler_key VARCHAR(80) NOT NULL,field_schema_json LONGTEXT NULL,eligibility_schema_json LONGTEXT NULL,trigger_schema_json LONGTEXT NULL,reward_rules_schema_json LONGTEXT NULL,landing_schema_json LONGTEXT NULL,supports_public_signup TINYINT(1) NOT NULL DEFAULT 0,supports_existing_contacts TINYINT(1) NOT NULL DEFAULT 1,supports_cases TINYINT(1) NOT NULL DEFAULT 0,supports_automation TINYINT(1) NOT NULL DEFAULT 1,supports_agent TINYINT(1) NOT NULL DEFAULT 1,is_system TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_type (merchant_id,type_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaigns (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,public_code VARCHAR(24) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_type_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,slug VARCHAR(120) NOT NULL,description TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'draft',environment VARCHAR(12) NOT NULL DEFAULT 'production',objective VARCHAR(500) NOT NULL DEFAULT '',owner_user_id INT UNSIGNED NOT NULL,current_version_no INT UNSIGNED NOT NULL DEFAULT 0,starts_at DATETIME NULL,ends_at DATETIME NULL,audience_mode VARCHAR(20) NOT NULL DEFAULT 'static',budget_minor BIGINT UNSIGNED NULL,budget_currency CHAR(3) NULL,budget_quantity BIGINT UNSIGNED NULL,max_enrollments BIGINT UNSIGNED NULL,max_rewards BIGINT UNSIGNED NULL,per_contact_limit INT UNSIGNED NULL,settings_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,launched_at DATETIME NULL,paused_at DATETIME NULL,completed_at DATETIME NULL,archived_at DATETIME NULL,
      UNIQUE KEY uq_campaign_public (public_id),UNIQUE KEY uq_campaign_slug (merchant_id,slug),UNIQUE KEY uq_campaign_code (merchant_id,public_code),INDEX idx_campaign_status (merchant_id,environment,status,updated_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_versions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,version_no INT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'snapshot',campaign_snapshot_json LONGTEXT NOT NULL,audience_snapshot_json LONGTEXT NULL,eligibility_snapshot_json LONGTEXT NULL,trigger_snapshot_json LONGTEXT NULL,landing_snapshot_json LONGTEXT NULL,reward_snapshot_json LONGTEXT NULL,terms_snapshot_json LONGTEXT NULL,created_by_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_version (campaign_id,version_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_audiences (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,mode VARCHAR(20) NOT NULL DEFAULT 'static',rules_json LONGTEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_audience_members (
      audience_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,membership_source VARCHAR(60) NOT NULL DEFAULT 'manual',qualified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,snapshot_version_no INT UNSIGNED NULL,metadata_json LONGTEXT NULL,PRIMARY KEY(audience_id,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_enrollments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,campaign_version_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NULL,external_identity_ref VARCHAR(255) NULL,source VARCHAR(80) NOT NULL DEFAULT 'manual',status VARCHAR(30) NOT NULL DEFAULT 'enrolled',environment VARCHAR(12) NOT NULL DEFAULT 'production',qualified_at DATETIME NULL,enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,converted_to_contact_at DATETIME NULL,completed_at DATETIME NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_enrollment_public (public_id),INDEX idx_campaign_enrollment_contact (campaign_id,contact_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_cases (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,enrollment_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NOT NULL,case_type VARCHAR(60) NOT NULL,reason_code VARCHAR(80) NOT NULL DEFAULT '',summary VARCHAR(500) NOT NULL DEFAULT '',internal_notes TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'open',environment VARCHAR(12) NOT NULL DEFAULT 'production',created_by_user_id INT UNSIGNED NULL,created_by_actor_type VARCHAR(30) NOT NULL DEFAULT 'user',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolved_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_case_public (public_id),INDEX idx_campaign_case_contact (merchant_id,contact_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_landing_pages (
      campaign_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,slug VARCHAR(120) NOT NULL,visibility VARCHAR(30) NOT NULL DEFAULT 'profile_public',presentation_mode VARCHAR(20) NOT NULL DEFAULT 'profile',headline VARCHAR(255) NOT NULL DEFAULT '',subheadline VARCHAR(500) NOT NULL DEFAULT '',hero_media_ref VARCHAR(255) NOT NULL DEFAULT '',cta_label VARCHAR(80) NOT NULL DEFAULT 'Claim reward',content_json LONGTEXT NULL,terms_json LONGTEXT NULL,is_published TINYINT(1) NOT NULL DEFAULT 0,published_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_profile_publications (
      campaign_id BIGINT UNSIGNED NOT NULL,profile_user_id INT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',featured TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 100,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(campaign_id,profile_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_public_sessions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,profile_owner_user_id INT UNSIGNED NULL,session_hash CHAR(64) NOT NULL,visitor_user_id INT UNSIGNED NULL,contact_id BIGINT UNSIGNED NULL,source VARCHAR(120) NOT NULL DEFAULT '',medium VARCHAR(120) NOT NULL DEFAULT '',referral_ref VARCHAR(190) NOT NULL DEFAULT '',location_id BIGINT UNSIGNED NULL,first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,view_count INT UNSIGNED NOT NULL DEFAULT 1,metadata_json LONGTEXT NULL,
      UNIQUE KEY uq_campaign_public_session (campaign_id,session_hash),INDEX idx_campaign_public_contact (contact_id,campaign_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_public_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,session_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NULL,event_type VARCHAR(100) NOT NULL,occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,INDEX idx_campaign_public_event (campaign_id,event_type,occurred_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_messages (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,message_key VARCHAR(80) NOT NULL,channel VARCHAR(30) NOT NULL,subject VARCHAR(255) NOT NULL DEFAULT '',body TEXT NULL,template_json LONGTEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'draft',version_no INT UNSIGNED NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_campaign_message (campaign_id,message_key,version_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_deliveries (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,enrollment_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NOT NULL,reward_issuance_id BIGINT UNSIGNED NULL,message_id BIGINT UNSIGNED NULL,channel VARCHAR(30) NOT NULL,external_source_type VARCHAR(60) NOT NULL DEFAULT '',external_source_id VARCHAR(190) NOT NULL DEFAULT '',status VARCHAR(30) NOT NULL DEFAULT 'pending',sent_at DATETIME NULL,delivered_at DATETIME NULL,viewed_at DATETIME NULL,failed_at DATETIME NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_campaign_delivery (campaign_id,status,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS reward_types (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NULL,type_key VARCHAR(80) NOT NULL,name VARCHAR(120) NOT NULL,description VARCHAR(500) NOT NULL DEFAULT '',base_handler_key VARCHAR(80) NOT NULL,config_schema_json LONGTEXT NULL,claim_schema_json LONGTEXT NULL,fulfillment_schema_json LONGTEXT NULL,is_system TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_reward_type (merchant_id,type_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_products (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,reward_type_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,description TEXT NULL,sku VARCHAR(120) NOT NULL DEFAULT '',image_ref VARCHAR(255) NOT NULL DEFAULT '',retail_value_minor BIGINT UNSIGNED NULL,internal_cost_minor BIGINT UNSIGNED NULL,currency CHAR(3) NOT NULL DEFAULT 'USD',inventory_mode VARCHAR(20) NOT NULL DEFAULT 'none',fulfillment_type VARCHAR(40) NOT NULL DEFAULT 'merchant',pickup_enabled TINYINT(1) NOT NULL DEFAULT 1,shipping_enabled TINYINT(1) NOT NULL DEFAULT 0,digital_enabled TINYINT(1) NOT NULL DEFAULT 0,expiration_policy VARCHAR(30) NOT NULL DEFAULT 'campaign',expiration_days INT UNSIGNED NULL,claim_limit INT UNSIGNED NOT NULL DEFAULT 1,transferable TINYINT(1) NOT NULL DEFAULT 0,regiftable TINYINT(1) NOT NULL DEFAULT 0,terms TEXT NULL,settings_json LONGTEXT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_reward_product_public (public_id),INDEX idx_reward_product (merchant_id,is_active,name,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_product_variants (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,reward_product_id BIGINT UNSIGNED NOT NULL,variant_key VARCHAR(80) NOT NULL,name VARCHAR(120) NOT NULL,sku VARCHAR(120) NOT NULL DEFAULT '',attributes_json LONGTEXT NULL,retail_value_minor BIGINT UNSIGNED NULL,internal_cost_minor BIGINT UNSIGNED NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_reward_variant (reward_product_id,variant_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_reward_sets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,description VARCHAR(500) NOT NULL DEFAULT '',selection_mode VARCHAR(30) NOT NULL DEFAULT 'fixed',min_choices INT UNSIGNED NOT NULL DEFAULT 1,max_choices INT UNSIGNED NOT NULL DEFAULT 1,rules_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_reward_set_items (
      reward_set_id BIGINT UNSIGNED NOT NULL,reward_product_id BIGINT UNSIGNED NOT NULL,variant_id BIGINT UNSIGNED NULL,quantity INT UNSIGNED NOT NULL DEFAULT 1,priority INT NOT NULL DEFAULT 100,conditions_json LONGTEXT NULL,PRIMARY KEY(reward_set_id,reward_product_id,variant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS reward_issuances (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,campaign_version_id BIGINT UNSIGNED NOT NULL,campaign_enrollment_id BIGINT UNSIGNED NULL,campaign_case_id BIGINT UNSIGNED NULL,reward_product_id BIGINT UNSIGNED NOT NULL,reward_variant_id BIGINT UNSIGNED NULL,recipient_contact_id BIGINT UNSIGNED NOT NULL,recipient_user_id INT UNSIGNED NULL,issued_by_user_id INT UNSIGNED NULL,issued_by_actor_type VARCHAR(30) NOT NULL DEFAULT 'user',environment VARCHAR(12) NOT NULL DEFAULT 'production',status VARCHAR(20) NOT NULL DEFAULT 'issued',credential_hash CHAR(64) NOT NULL,credential_last4 VARCHAR(8) NOT NULL,quantity INT UNSIGNED NOT NULL DEFAULT 1,remaining_quantity INT UNSIGNED NOT NULL DEFAULT 1,face_value_minor BIGINT UNSIGNED NULL,currency CHAR(3) NOT NULL DEFAULT 'USD',terms_snapshot_json LONGTEXT NOT NULL,issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,viewed_at DATETIME NULL,claimed_at DATETIME NULL,expires_at DATETIME NULL,voided_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_reward_issuance_public (public_id),UNIQUE KEY uq_reward_credential_hash (credential_hash),INDEX idx_wallet_contact (recipient_contact_id,status,expires_at,id),INDEX idx_reward_issuance_campaign (campaign_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS reward_claims (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,reward_issuance_id BIGINT UNSIGNED NOT NULL,claim_code_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NULL,merchant_member_id BIGINT UNSIGNED NULL,processed_by_user_id INT UNSIGNED NULL,processed_by_actor_type VARCHAR(30) NOT NULL DEFAULT 'user',environment VARCHAR(12) NOT NULL DEFAULT 'production',quantity INT UNSIGNED NOT NULL DEFAULT 1,value_minor BIGINT UNSIGNED NULL,currency CHAR(3) NULL,status VARCHAR(20) NOT NULL DEFAULT 'claimed',order_ref VARCHAR(190) NULL,claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reversed_at DATETIME NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_reward_claim_public (public_id),UNIQUE KEY uq_reward_claim_once (reward_issuance_id,status),INDEX idx_reward_claim_merchant (merchant_id,claimed_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_claim_attempts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NULL,reward_issuance_id BIGINT UNSIGNED NULL,claim_code_id BIGINT UNSIGNED NULL,location_id BIGINT UNSIGNED NULL,merchant_member_id BIGINT UNSIGNED NULL,attempt_result VARCHAR(30) NOT NULL,reason_code VARCHAR(80) NOT NULL DEFAULT '',request_fingerprint_hash CHAR(64) NULL,occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,INDEX idx_claim_attempt (merchant_id,occurred_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_claim_adjustments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,claim_id BIGINT UNSIGNED NOT NULL,adjustment_type VARCHAR(40) NOT NULL,reason VARCHAR(500) NOT NULL,before_json LONGTEXT NULL,after_json LONGTEXT NULL,actor_user_id INT UNSIGNED NULL,actor_type VARCHAR(30) NOT NULL DEFAULT 'user',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_claim_adjustment (claim_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS reward_inventory_balances (
      reward_product_id BIGINT UNSIGNED NOT NULL,variant_id BIGINT UNSIGNED NULL,location_id BIGINT UNSIGNED NULL,on_hand BIGINT NOT NULL DEFAULT 0,reserved BIGINT NOT NULL DEFAULT 0,reorder_level BIGINT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_reward_inventory (reward_product_id,variant_id,location_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_inventory_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,reward_product_id BIGINT UNSIGNED NOT NULL,variant_id BIGINT UNSIGNED NULL,location_id BIGINT UNSIGNED NULL,movement_type VARCHAR(40) NOT NULL,quantity_delta BIGINT NOT NULL,source_type VARCHAR(60) NOT NULL,source_id VARCHAR(120) NOT NULL,actor_user_id INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,INDEX idx_inventory_ledger (reward_product_id,location_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS loyalty_programs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',earn_rules_json LONGTEXT NULL,spend_rules_json LONGTEXT NULL,settings_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_loyalty_program (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS loyalty_tiers (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,program_id BIGINT UNSIGNED NOT NULL,tier_key VARCHAR(80) NOT NULL,name VARCHAR(120) NOT NULL,rank INT NOT NULL DEFAULT 0,threshold_json LONGTEXT NULL,benefits_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_loyalty_tier (program_id,tier_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS loyalty_accounts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,program_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,current_tier_id BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_loyalty_account (program_id,contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS loyalty_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,loyalty_account_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(20) NOT NULL,points_delta BIGINT NOT NULL,source_type VARCHAR(60) NOT NULL,source_id VARCHAR(120) NOT NULL,campaign_id BIGINT UNSIGNED NULL,reward_issuance_id BIGINT UNSIGNED NULL,expires_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,INDEX idx_loyalty_ledger (loyalty_account_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_automation_rules (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,name VARCHAR(190) NOT NULL,trigger_event VARCHAR(120) NOT NULL,conditions_json LONGTEXT NULL,actions_json LONGTEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_by_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_rule (merchant_id,status,trigger_event,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_rule_executions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,rule_id BIGINT UNSIGNED NOT NULL,trigger_event_id VARCHAR(120) NOT NULL,contact_id BIGINT UNSIGNED NULL,campaign_id BIGINT UNSIGNED NULL,idempotency_key VARCHAR(190) NOT NULL,status VARCHAR(30) NOT NULL,environment VARCHAR(12) NOT NULL DEFAULT 'production',result_json LONGTEXT NULL,executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_rule_execution (rule_id,idempotency_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaigns_rewards_object_bindings (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,subject_type VARCHAR(60) NOT NULL,subject_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(80) NOT NULL DEFAULT '',target_type VARCHAR(80) NOT NULL,target_id VARCHAR(120) NOT NULL,target_public_id VARCHAR(120) NULL,settings_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_binding (merchant_id,subject_type,subject_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_activity_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NULL,enrollment_id BIGINT UNSIGNED NULL,campaign_case_id BIGINT UNSIGNED NULL,reward_issuance_id BIGINT UNSIGNED NULL,claim_id BIGINT UNSIGNED NULL,location_id BIGINT UNSIGNED NULL,merchant_member_id BIGINT UNSIGNED NULL,actor_type VARCHAR(30) NOT NULL DEFAULT 'user',actor_user_id INT UNSIGNED NULL,event_type VARCHAR(120) NOT NULL,summary VARCHAR(500) NOT NULL DEFAULT '',details_json LONGTEXT NULL,environment VARCHAR(12) NOT NULL DEFAULT 'production',occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_activity_public (public_id),INDEX idx_campaign_activity_stream (merchant_id,occurred_at,id),INDEX idx_campaign_activity_contact (contact_id,occurred_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS merchant_ownership_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(60) NOT NULL,from_user_id INT UNSIGNED NULL,to_user_id INT UNSIGNED NULL,actor_user_id INT UNSIGNED NOT NULL,reason VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_merchant_ownership (merchant_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_simulation_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,campaign_version_id BIGINT UNSIGNED NULL,requested_by_user_id INT UNSIGNED NOT NULL,input_snapshot_json LONGTEXT NOT NULL,result_summary_json LONGTEXT NULL,eligible_count BIGINT UNSIGNED NOT NULL DEFAULT 0,suppressed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,projected_issuance_count BIGINT UNSIGNED NOT NULL DEFAULT 0,projected_face_value_minor BIGINT UNSIGNED NULL,projected_cost_minor BIGINT UNSIGNED NULL,inventory_risk_count BIGINT UNSIGNED NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'complete',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_campaign_simulation_public (public_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_reviews (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,campaign_version_id BIGINT UNSIGNED NULL,review_status VARCHAR(30) NOT NULL DEFAULT 'ready',requested_by_user_id INT UNSIGNED NOT NULL,reviewed_by_user_id INT UNSIGNED NULL,reason VARCHAR(500) NOT NULL DEFAULT '',requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,INDEX idx_campaign_review (campaign_id,review_status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_creative_assets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,campaign_version_id BIGINT UNSIGNED NULL,asset_role VARCHAR(60) NOT NULL,media_ref VARCHAR(255) NOT NULL DEFAULT '',content_json LONGTEXT NULL,sort_order INT NOT NULL DEFAULT 100,is_active TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_asset (campaign_id,asset_role,is_active,sort_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS vp3_custom_field_definitions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,plugin_key VARCHAR(80) NULL,merchant_id BIGINT UNSIGNED NULL,entity_type VARCHAR(80) NOT NULL,field_key VARCHAR(100) NOT NULL,label VARCHAR(190) NOT NULL,field_type VARCHAR(40) NOT NULL,validation_json LONGTEXT NULL,choices_json LONGTEXT NULL,sensitivity VARCHAR(30) NOT NULL DEFAULT 'normal',is_searchable TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,schema_version INT UNSIGNED NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_custom_field (owner_user_id,plugin_key,merchant_id,entity_type,field_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS vp3_custom_field_values (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,definition_id BIGINT UNSIGNED NOT NULL,entity_type VARCHAR(80) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,value_text TEXT NULL,value_number DECIMAL(20,6) NULL,value_datetime DATETIME NULL,value_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_custom_field_value (definition_id,entity_type,entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS integration_external_identities (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,merchant_id BIGINT UNSIGNED NULL,provider_key VARCHAR(80) NOT NULL,entity_type VARCHAR(80) NOT NULL,internal_id BIGINT UNSIGNED NOT NULL,external_id VARCHAR(255) NOT NULL,metadata_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_external_identity (provider_key,entity_type,external_id(150),merchant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_webhook_endpoints (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,endpoint_url VARCHAR(500) NOT NULL,secret_enc TEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',event_filters_json LONGTEXT NULL,api_version VARCHAR(30) NOT NULL DEFAULT 'v1',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_webhook (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_webhook_deliveries (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,endpoint_id BIGINT UNSIGNED NOT NULL,event_id VARCHAR(120) NOT NULL,schema_version VARCHAR(30) NOT NULL,idempotency_key VARCHAR(190) NOT NULL,attempt_no INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(30) NOT NULL,http_status INT NULL,next_attempt_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME NULL,UNIQUE KEY uq_webhook_attempt (endpoint_id,idempotency_key,attempt_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_data_jobs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,job_type VARCHAR(20) NOT NULL,entity_type VARCHAR(80) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',requested_by_user_id INT UNSIGNED NOT NULL,source_file_ref VARCHAR(255) NULL,result_file_ref VARCHAR(255) NULL,preview_json LONGTEXT NULL,summary_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME NULL,INDEX idx_campaign_data_job (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_funding_sources (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,funding_type VARCHAR(30) NOT NULL DEFAULT 'merchant',sponsor_contact_id BIGINT UNSIGNED NULL,settings_json LONGTEXT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_funding (merchant_id,is_active,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_liability_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,reward_issuance_id BIGINT UNSIGNED NULL,entry_type VARCHAR(30) NOT NULL,face_value_delta_minor BIGINT NULL,estimated_cost_delta_minor BIGINT NULL,actual_cost_delta_minor BIGINT NULL,currency CHAR(3) NOT NULL DEFAULT 'USD',funding_source_id BIGINT UNSIGNED NULL,source_type VARCHAR(60) NOT NULL,source_id VARCHAR(120) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,metadata_json LONGTEXT NULL,INDEX idx_liability_ledger (merchant_id,campaign_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS reward_support_cases (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,reward_issuance_id BIGINT UNSIGNED NULL,claim_id BIGINT UNSIGNED NULL,case_type VARCHAR(60) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'open',summary VARCHAR(500) NOT NULL,resolution TEXT NULL,assigned_member_id BIGINT UNSIGNED NULL,created_by_user_id INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolved_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_reward_support_public (public_id),INDEX idx_reward_support (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_governance_policies (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,policy_type VARCHAR(40) NOT NULL,scope_kind VARCHAR(40) NOT NULL DEFAULT 'merchant',scope_id BIGINT UNSIGNED NULL,rules_json LONGTEXT NOT NULL,priority INT NOT NULL DEFAULT 100,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_campaign_policy (merchant_id,policy_type,is_active,priority,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exec("CREATE TABLE IF NOT EXISTS campaign_idempotency_keys (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,merchant_id BIGINT UNSIGNED NOT NULL,operation_key VARCHAR(100) NOT NULL,idempotency_key VARCHAR(190) NOT NULL,subject_type VARCHAR(80) NULL,subject_id BIGINT UNSIGNED NULL,request_hash CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'started',result_ref_type VARCHAR(80) NULL,result_ref_id VARCHAR(120) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME NULL,UNIQUE KEY uq_campaign_idempotency (merchant_id,operation_key,idempotency_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_schema_versions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,schema_family VARCHAR(40) NOT NULL,schema_key VARCHAR(100) NOT NULL,version INT UNSIGNED NOT NULL,schema_json LONGTEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_campaign_schema (schema_family,schema_key,version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_reconciliation_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NULL,scope_type VARCHAR(40) NOT NULL,scope_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'running',started_by VARCHAR(30) NOT NULL DEFAULT 'user',started_by_user_id INT UNSIGNED NULL,started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME NULL,summary_json LONGTEXT NULL,UNIQUE KEY uq_reconcile_public (public_id),INDEX idx_reconcile (merchant_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_reconciliation_findings (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,run_id BIGINT UNSIGNED NOT NULL,severity VARCHAR(20) NOT NULL DEFAULT 'warning',finding_type VARCHAR(100) NOT NULL,subject_type VARCHAR(80) NOT NULL,subject_id BIGINT UNSIGNED NOT NULL,expected_json LONGTEXT NULL,actual_json LONGTEXT NULL,repair_key VARCHAR(100) NULL,repair_status VARCHAR(20) NOT NULL DEFAULT 'none',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolved_at DATETIME NULL,INDEX idx_reconcile_finding (run_id,severity,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_cognitive_event_receipts (
      activity_event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,cognitive_event_external_id VARCHAR(190) NOT NULL,schema_version VARCHAR(30) NOT NULL,status VARCHAR(30) NOT NULL,emitted_at DATETIME NULL,last_checked_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exec("CREATE TABLE IF NOT EXISTS campaign_agent_recommendations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,public_id CHAR(36) NOT NULL,merchant_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NULL,recommendation_type VARCHAR(80) NOT NULL,summary VARCHAR(1000) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'proposed',evidence_refs_json LONGTEXT NOT NULL,impact_preview_json LONGTEXT NULL,created_by_agent_id BIGINT UNSIGNED NULL,created_for_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolved_at DATETIME NULL,UNIQUE KEY uq_campaign_recommendation_public (public_id),INDEX idx_campaign_recommendation (merchant_id,status,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    campaigns_rewards_platform_seed_v100($pdo);
}

function campaigns_rewards_platform_seed_v100(PDO $pdo): void
{
    $campaignTypes=[
        'signup'=>['Signup','public_signup',1,0],
        'make_good'=>['Make Good','make_good',0,1],
        'loyalty'=>['Loyalty','loyalty',0,0],
        'promotional_goods'=>['Promotional Goods','promotional_goods',1,0],
        'discount_voucher'=>['Discount Voucher','discount_voucher',1,0],
        'post_purchase'=>['Post Purchase','post_purchase',0,0],
        'referral'=>['Referral','referral',1,0],
        'win_back'=>['Win Back','win_back',0,0],
    ];
    $stmt=$pdo->prepare("INSERT IGNORE INTO campaign_types
      (merchant_id,type_key,name,description,base_handler_key,supports_public_signup,supports_existing_contacts,supports_cases,supports_automation,supports_agent,is_system,is_active)
      VALUES (NULL,?,?,?, ?,?,1,?,1,1,1,1)");
    foreach($campaignTypes as $key=>[$name,$handler,$public,$cases])$stmt->execute([$key,$name,$name.' campaign type.',$handler,$public,$cases]);

    $rewardTypes=[
        'percentage_discount'=>'Percentage Discount','fixed_value_discount'=>'Fixed-value Discount','free_product'=>'Free Product',
        'promotional_product'=>'Promotional Product','merchandise'=>'Merchandise','account_credit'=>'Account Credit',
        'loyalty_points'=>'Loyalty Points','free_service'=>'Free Service','upgrade'=>'Upgrade',
        'membership_access'=>'Membership / Access','custom'=>'Custom',
    ];
    $reward=$pdo->prepare("INSERT IGNORE INTO reward_types (merchant_id,type_key,name,description,base_handler_key,is_system,is_active) VALUES (NULL,?,?,?, ?,1,1)");
    foreach($rewardTypes as $key=>$name)$reward->execute([$key,$name,$name.' reward type.',$key]);

    $roleCaps=[
      'owner'=>['Owner','*'],
      'administrator'=>['Administrator','merchant.view,merchant.manage,merchant.settings.manage,merchant.team.view,merchant.team.manage,merchant.team.roles.manage,locations.view,locations.manage,crm.view,crm.edit,crm.history.view,crm.export,crm.actions.send,campaigns.view,campaigns.create,campaigns.edit,campaigns.publish,campaigns.launch,campaigns.pause,campaigns.complete,campaigns.archive,campaigns.audience.preview,campaigns.enrollment.manage,rewards.view,rewards.manage,rewards.issue,rewards.void,rewards.inventory.manage,claims.view,claims.process,claims.override,claims.reverse,claim_codes.manage,loyalty.view,loyalty.manage,loyalty.adjust,analytics.view,analytics.export'],
      'manager'=>['Manager','merchant.view,merchant.team.view,locations.view,locations.manage,crm.view,crm.edit,crm.history.view,crm.actions.send,campaigns.view,campaigns.create,campaigns.edit,campaigns.publish,campaigns.launch,campaigns.pause,campaigns.complete,campaigns.audience.preview,campaigns.enrollment.manage,rewards.view,rewards.manage,rewards.issue,rewards.inventory.manage,claims.view,claims.process,claim_codes.manage,analytics.view'],
      'marketing'=>['Marketing','merchant.view,crm.view,crm.edit,crm.history.view,campaigns.view,campaigns.create,campaigns.edit,campaigns.publish,campaigns.audience.preview,rewards.view,rewards.manage,analytics.view'],
      'customer_service'=>['Customer Service','merchant.view,crm.view,crm.edit,crm.history.view,crm.actions.send,campaigns.view,campaigns.enrollment.manage,rewards.view,rewards.issue,claims.view'],
      'claim_processor'=>['Claim Processor','merchant.view,crm.view,rewards.view,claims.view,claims.process'],
      'fulfillment'=>['Fulfillment','merchant.view,locations.view,rewards.view,rewards.inventory.manage,claims.view,claims.process'],
      'analyst'=>['Analyst','merchant.view,locations.view,crm.view,crm.history.view,campaigns.view,rewards.view,claims.view,loyalty.view,analytics.view,analytics.export'],
      'custom'=>['Custom',''],
    ];
    $role=$pdo->prepare("INSERT IGNORE INTO merchant_roles (merchant_id,role_key,name,description,is_system,is_active) VALUES (NULL,?,?,?,1,1)");
    foreach($roleCaps as $key=>[$name,$caps]){
        $role->execute([$key,$name,$name.' merchant role.']);
        $idStmt=$pdo->prepare("SELECT id FROM merchant_roles WHERE merchant_id IS NULL AND role_key=? LIMIT 1");$idStmt->execute([$key]);$roleId=(int)$idStmt->fetchColumn();
        if($roleId<1)continue;
        if($caps==='*')$caps='merchant.view,merchant.manage,merchant.settings.manage,merchant.team.view,merchant.team.manage,merchant.team.roles.manage,locations.view,locations.manage,crm.view,crm.edit,crm.history.view,crm.export,crm.actions.send,campaigns.view,campaigns.create,campaigns.edit,campaigns.publish,campaigns.launch,campaigns.pause,campaigns.complete,campaigns.archive,campaigns.audience.preview,campaigns.enrollment.manage,rewards.view,rewards.manage,rewards.issue,rewards.void,rewards.inventory.manage,claims.view,claims.process,claims.override,claims.reverse,claim_codes.manage,loyalty.view,loyalty.manage,loyalty.adjust,analytics.view,analytics.export';
        $capStmt=$pdo->prepare("INSERT IGNORE INTO merchant_role_capabilities (role_id,capability_key,effect) VALUES (?,?,'allow')");
        foreach(array_filter(array_map('trim',explode(',',$caps))) as $cap)$capStmt->execute([$roleId,$cap]);
    }

    foreach([
      ['campaign_type','signup',1],['campaign_type','make_good',1],['reward_type','free_product',1],['landing','default',1],['cognitive_event','campaigns_rewards',1]
    ] as [$family,$key,$version]){
        $pdo->prepare("INSERT IGNORE INTO campaign_schema_versions (schema_family,schema_key,version,schema_json,status) VALUES (?,?,?,'{}','active')")
            ->execute([$family,$key,$version]);
    }
}
