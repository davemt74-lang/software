<?php
declare(strict_types=1);

/**
 * VP3 subscription, entitlement and AI quota runtime.
 *
 * Commercial access is intentionally separate from identity/team relationships.
 * Admin remains an internal authority and is never represented as a public plan.
 */

const VP3_SUBSCRIPTION_SCHEMA_VERSION = 'subscription-packages-20260906';

function subscription_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;
    foreach ([
        'subscription_packages',
        'package_entitlements',
        'user_subscriptions',
        'ai_token_credits',
        'ai_usage_ledger',
        'ai_token_reservations',
        'subscription_audit_log',
    ] as $table) {
        if (!table_exists($table)) return false;
    }
    return true;
}

function subscription_capability_catalog(): array
{
    return [
        'main_ai.access' => ['label'=>'Main AI / Agent Chat','type'=>'boolean','category'=>'AI'],
        'agent_brain.access' => ['label'=>'Agent Brain','type'=>'boolean','category'=>'AI'],
        'agent_memory.access' => ['label'=>'Agent Memory','type'=>'boolean','category'=>'AI'],
        'knowledge.access' => ['label'=>'Knowledge','type'=>'boolean','category'=>'AI'],
        'profile_agent.access' => ['label'=>'Profile Agent','type'=>'boolean','category'=>'AI'],
        'voice.access' => ['label'=>'Voice','type'=>'boolean','category'=>'AI'],
        'voice_clone.access' => ['label'=>'Voice Clone','type'=>'boolean','category'=>'AI'],
        'transcription.access' => ['label'=>'Transcription','type'=>'boolean','category'=>'AI'],
        'stem_editor.access' => ['label'=>'Stem Editor','type'=>'boolean','category'=>'Studio'],
        'video_editor.access' => ['label'=>'Video Editor','type'=>'boolean','category'=>'Studio'],
        'team_seats' => ['label'=>'Team Seats','type'=>'limit','category'=>'Collaboration'],
        'projects.limit' => ['label'=>'Project Limit','type'=>'limit','category'=>'Limits'],
        'storage_mb' => ['label'=>'Storage (MB)','type'=>'limit','category'=>'Limits'],
        'ai.unlimited' => ['label'=>'Unlimited AI Tokens','type'=>'boolean','category'=>'AI'],
        'legacy.permissions' => ['label'=>'Legacy Permission Compatibility','type'=>'boolean','category'=>'Internal'],
    ];
}

function subscription_permission_key(string $permission): string
{
    return 'permission.' . trim($permission);
}

function subscription_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_packages (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(80) NOT NULL,
      name VARCHAR(120) NOT NULL,
      description TEXT NULL,
      monthly_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      annual_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
      ai_tokens_monthly BIGINT UNSIGNED NOT NULL DEFAULT 0,
      trial_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      trial_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      is_trial TINYINT(1) NOT NULL DEFAULT 0,
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      is_public TINYINT(1) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 100,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_subscription_package_slug (slug),
      INDEX idx_subscription_packages_public (is_active,is_public,sort_order,id),
      INDEX idx_subscription_packages_default (is_default,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS package_entitlements (
      package_id INT UNSIGNED NOT NULL,
      capability_key VARCHAR(120) NOT NULL,
      is_enabled TINYINT(1) NOT NULL DEFAULT 0,
      limit_value BIGINT NULL,
      metadata_json LONGTEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (package_id,capability_key),
      INDEX idx_package_entitlements_key (capability_key,is_enabled,package_id),
      CONSTRAINT fk_package_entitlements_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      package_id INT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      assignment_source VARCHAR(40) NOT NULL DEFAULT 'admin_assigned',
      billing_required TINYINT(1) NOT NULL DEFAULT 0,
      starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ends_at DATETIME NULL,
      current_period_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      current_period_end DATETIME NULL,
      ai_token_override BIGINT UNSIGNED NULL,
      assigned_by INT UNSIGNED NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_user_subscriptions_current (user_id,status,starts_at,ends_at,id),
      INDEX idx_user_subscriptions_package (package_id,status,id),
      CONSTRAINT fk_user_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_subscriptions_package FOREIGN KEY (package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT,
      CONSTRAINT fk_user_subscriptions_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_credits (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      amount BIGINT UNSIGNED NOT NULL,
      remaining_amount BIGINT UNSIGNED NOT NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'admin_topup',
      reason VARCHAR(500) NOT NULL DEFAULT '',
      expires_at DATETIME NULL,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_ai_token_credits_user (user_id,expires_at,remaining_amount,id),
      CONSTRAINT fk_ai_token_credits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_token_credits_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      subscription_id BIGINT UNSIGNED NULL,
      scope VARCHAR(60) NOT NULL DEFAULT 'chat',
      provider VARCHAR(40) NOT NULL DEFAULT '',
      model VARCHAR(120) NOT NULL DEFAULT '',
      input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
      credit_tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
      package_tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
      trace_id VARCHAR(120) NOT NULL DEFAULT '',
      request_key VARCHAR(160) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_ai_usage_request_key (request_key),
      INDEX idx_ai_usage_user_time (user_id,created_at,id),
      INDEX idx_ai_usage_subscription_time (subscription_id,created_at,id),
      INDEX idx_ai_usage_scope_time (user_id,scope,created_at,id),
      CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_usage_subscription FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_reservations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      subscription_id BIGINT UNSIGNED NULL,
      scope VARCHAR(60) NOT NULL DEFAULT 'chat',
      reserved_tokens BIGINT UNSIGNED NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_reservations_user (user_id,expires_at,id),
      CONSTRAINT fk_ai_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_reservations_subscription FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_audit_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      actor_user_id INT UNSIGNED NULL,
      target_user_id INT UNSIGNED NOT NULL,
      action VARCHAR(60) NOT NULL,
      old_package_id INT UNSIGNED NULL,
      new_package_id INT UNSIGNED NULL,
      reason VARCHAR(500) NOT NULL DEFAULT '',
      details_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_subscription_audit_target (target_user_id,created_at,id),
      INDEX idx_subscription_audit_actor (actor_user_id,created_at,id),
      CONSTRAINT fk_subscription_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_subscription_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_subscription_audit_old_package FOREIGN KEY (old_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,
      CONSTRAINT fk_subscription_audit_new_package FOREIGN KEY (new_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    subscription_seed_defaults($pdo);
    subscription_migrate_existing_users($pdo);
}

function subscription_seed_defaults(PDO $pdo): void
{
    $trial = $pdo->prepare("INSERT INTO subscription_packages
      (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=1");
    $trial->execute([
        'free-trial','Free Trial','Default trial assigned to new accounts.',0,0,0,14,50000,1,1,1,1,10,
    ]);

    $legacy = $pdo->prepare("INSERT INTO subscription_packages
      (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE name=VALUES(name),is_active=1");
    $legacy->execute([
        'legacy-access','Legacy Access','Compatibility package for accounts that existed before package monetization.',0,0,0,0,0,0,0,0,1,999,
    ]);

    $trialId = subscription_package_id_by_slug($pdo, 'free-trial');
    $legacyId = subscription_package_id_by_slug($pdo, 'legacy-access');

    $trialEntitlements = [
        'main_ai.access'=>[1,null],
        'agent_brain.access'=>[1,null],
        'agent_memory.access'=>[1,null],
        'knowledge.access'=>[1,null],
        'profile_agent.access'=>[1,null],
        'voice.access'=>[1,null],
        'voice_clone.access'=>[0,null],
        'transcription.access'=>[1,null],
        'stem_editor.access'=>[0,null],
        'video_editor.access'=>[0,null],
        'team_seats'=>[1,0],
        'permission.account.access'=>[1,null],
        'permission.chat.access'=>[1,null],
        'permission.artist_listening.access'=>[1,null],
        'permission.knowledge.access'=>[1,null],
    ];
    foreach ($trialEntitlements as $key => [$enabled,$limit]) {
        subscription_seed_entitlement($pdo,$trialId,$key,(bool)$enabled,$limit);
    }

    $legacyEntitlements = [
        'main_ai.access'=>[1,null],
        'agent_brain.access'=>[1,null],
        'agent_memory.access'=>[1,null],
        'knowledge.access'=>[1,null],
        'profile_agent.access'=>[1,null],
        'voice.access'=>[1,null],
        'voice_clone.access'=>[1,null],
        'transcription.access'=>[1,null],
        'stem_editor.access'=>[1,null],
        'video_editor.access'=>[1,null],
        'team_seats'=>[1,2],
        'ai.unlimited'=>[1,null],
        'legacy.permissions'=>[1,null],
    ];
    foreach ($legacyEntitlements as $key => [$enabled,$limit]) {
        subscription_seed_entitlement($pdo,$legacyId,$key,(bool)$enabled,$limit);
    }
}

function subscription_seed_entitlement(PDO $pdo,int $packageId,string $key,bool $enabled,?int $limit): void
{
    if ($packageId < 1) return;
    $stmt=$pdo->prepare("INSERT IGNORE INTO package_entitlements (package_id,capability_key,is_enabled,limit_value) VALUES (?,?,?,?)");
    $stmt->execute([$packageId,$key,$enabled?1:0,$limit]);
}

function subscription_package_id_by_slug(PDO $pdo,string $slug): int
{
    $stmt=$pdo->prepare('SELECT id FROM subscription_packages WHERE slug=? LIMIT 1');
    $stmt->execute([$slug]);
    return (int)$stmt->fetchColumn();
}

function subscription_migrate_existing_users(PDO $pdo): void
{
    $legacyId=subscription_package_id_by_slug($pdo,'legacy-access');
    if($legacyId<1)return;
    $stmt=$pdo->query("SELECT u.id FROM users u WHERE NOT EXISTS (SELECT 1 FROM user_subscriptions s WHERE s.user_id=u.id)");
    $insert=$pdo->prepare("INSERT INTO user_subscriptions
      (user_id,package_id,status,assignment_source,billing_required,starts_at,current_period_start,current_period_end,metadata_json)
      VALUES (?,?,'active','migration',0,NOW(),NOW(),NULL,?)");
    foreach($stmt->fetchAll()?:[] as $row){
        $uid=(int)$row['id'];
        if($uid<1)continue;
        $insert->execute([$uid,$legacyId,json_encode(['schema'=>VP3_SUBSCRIPTION_SCHEMA_VERSION],JSON_UNESCAPED_SLASHES)]);
    }
}
