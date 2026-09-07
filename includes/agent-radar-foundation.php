<?php
declare(strict_types=1);

/**
 * VP3 Agent Radar foundation.
 *
 * Shared sensing/identity layer for the owner's vp3.me profile and package-gated
 * external properties. It intentionally stores normalized activity, not raw
 * credentials or long-lived raw IP addresses. CRM/chat/notification surfaces
 * consume these records in later integration phases.
 */
const VP3_RADAR_SCHEMA_VERSION = 'agent-radar-20260906';
const VP3_RADAR_SITE_CAPABILITY = 'analytics.sites';
const VP3_AGENT_MESSAGING_CAPABILITY = 'agent.messaging';

function vp3_radar_schema_ready(?PDO $pdo=null): bool
{
    $pdo ??= db();
    if(!$pdo)return false;
    foreach(['vp3_radar_properties','vp3_agent_registry','vp3_agent_contacts','vp3_radar_sessions','vp3_radar_events','vp3_agent_policies'] as $table){
        if(!table_exists($table))return false;
    }
    return true;
}

function vp3_radar_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_radar_properties (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      property_type VARCHAR(20) NOT NULL DEFAULT 'external',
      label VARCHAR(190) NOT NULL DEFAULT '',
      domain VARCHAR(190) NOT NULL DEFAULT '',
      public_key CHAR(40) NOT NULL,
      secret_hash CHAR(64) NULL,
      verification_token CHAR(64) NULL,
      verified_at DATETIME NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_radar_public_key (public_key),
      UNIQUE KEY uq_vp3_radar_owner_domain (owner_user_id,domain),
      INDEX idx_vp3_radar_owner_active (owner_user_id,is_active,id),
      CONSTRAINT fk_vp3_radar_property_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_registry (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      operator_name VARCHAR(120) NOT NULL DEFAULT '',
      agent_name VARCHAR(120) NOT NULL,
      slug VARCHAR(160) NOT NULL,
      visitor_class VARCHAR(40) NOT NULL DEFAULT 'unknown',
      purpose VARCHAR(80) NOT NULL DEFAULT '',
      user_agent_pattern VARCHAR(500) NOT NULL DEFAULT '',
      verification_method VARCHAR(60) NOT NULL DEFAULT 'signature',
      metadata_json LONGTEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      first_verified_at DATETIME NULL,
      last_verified_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_agent_slug (slug),
      INDEX idx_vp3_agent_operator (operator_name,is_active,id),
      INDEX idx_vp3_agent_class (visitor_class,is_active,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_contacts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_registry_id BIGINT UNSIGNED NULL,
      identity_key CHAR(64) NOT NULL,
      display_name VARCHAR(190) NOT NULL,
      operator_name VARCHAR(120) NOT NULL DEFAULT '',
      contact_type VARCHAR(30) NOT NULL DEFAULT 'agent',
      visitor_class VARCHAR(40) NOT NULL DEFAULT 'unknown',
      verification_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
      confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      trust_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      engagement_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      value_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      cost_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      relationship_status VARCHAR(30) NOT NULL DEFAULT 'new',
      inferred_intent VARCHAR(120) NOT NULL DEFAULT '',
      intent_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
      session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      page_view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      referral_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      conversion_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_agent_contact_owner_identity (owner_user_id,identity_key),
      INDEX idx_vp3_agent_contact_owner_seen (owner_user_id,last_seen_at,id),
      INDEX idx_vp3_agent_contact_owner_risk (owner_user_id,risk_score,last_seen_at),
      CONSTRAINT fk_vp3_agent_contact_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_contact_registry FOREIGN KEY (agent_registry_id) REFERENCES vp3_agent_registry(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_radar_sessions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      property_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_contact_id BIGINT UNSIGNED NULL,
      session_key CHAR(64) NOT NULL,
      visitor_type VARCHAR(40) NOT NULL DEFAULT 'unknown',
      entry_path VARCHAR(500) NOT NULL DEFAULT '',
      exit_path VARCHAR(500) NOT NULL DEFAULT '',
      referrer_host VARCHAR(190) NOT NULL DEFAULT '',
      request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      page_view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ended_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      UNIQUE KEY uq_vp3_radar_property_session (property_id,session_key),
      INDEX idx_vp3_radar_owner_session_seen (owner_user_id,last_seen_at,id),
      INDEX idx_vp3_radar_contact_session (agent_contact_id,last_seen_at,id),
      CONSTRAINT fk_vp3_radar_session_property FOREIGN KEY (property_id) REFERENCES vp3_radar_properties(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_radar_session_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_radar_session_contact FOREIGN KEY (agent_contact_id) REFERENCES vp3_agent_contacts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_radar_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      property_id BIGINT UNSIGNED NOT NULL,
      session_id BIGINT UNSIGNED NULL,
      agent_contact_id BIGINT UNSIGNED NULL,
      event_type VARCHAR(80) NOT NULL,
      severity VARCHAR(20) NOT NULL DEFAULT 'low',
      path VARCHAR(500) NOT NULL DEFAULT '',
      method VARCHAR(12) NOT NULL DEFAULT 'GET',
      status_code SMALLINT UNSIGNED NULL,
      significance_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      details_json LONGTEXT NULL,
      occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_vp3_radar_owner_event (owner_user_id,occurred_at,id),
      INDEX idx_vp3_radar_owner_severity (owner_user_id,severity,occurred_at,id),
      INDEX idx_vp3_radar_contact_event (agent_contact_id,occurred_at,id),
      CONSTRAINT fk_vp3_radar_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_radar_event_property FOREIGN KEY (property_id) REFERENCES vp3_radar_properties(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_radar_event_session FOREIGN KEY (session_id) REFERENCES vp3_radar_sessions(id) ON DELETE SET NULL,
      CONSTRAINT fk_vp3_radar_event_contact FOREIGN KEY (agent_contact_id) REFERENCES vp3_agent_contacts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_policies (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      property_id BIGINT UNSIGNED NULL,
      agent_contact_id BIGINT UNSIGNED NULL,
      operator_name VARCHAR(120) NOT NULL DEFAULT '',
      visitor_class VARCHAR(40) NOT NULL DEFAULT '',
      path_pattern VARCHAR(500) NOT NULL DEFAULT '*',
      action VARCHAR(30) NOT NULL DEFAULT 'monitor',
      priority INT NOT NULL DEFAULT 100,
      expires_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_vp3_policy_owner_active (owner_user_id,is_active,priority,id),
      INDEX idx_vp3_policy_contact (agent_contact_id,is_active,priority,id),
      CONSTRAINT fk_vp3_policy_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_policy_property FOREIGN KEY (property_id) REFERENCES vp3_radar_properties(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_policy_contact FOREIGN KEY (agent_contact_id) REFERENCES vp3_agent_contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    vp3_radar_seed_registry($pdo);
}

function vp3_radar_seed_registry(PDO $pdo): void
{
    $rows=[
      ['OpenAI','ChatGPT-User','chatgpt-user','ai_user_agent','user-directed browsing','ChatGPT-User'],
      ['OpenAI','OAI-SearchBot','oai-searchbot','ai_search','AI search discovery','OAI-SearchBot'],
      ['OpenAI','GPTBot','gptbot','ai_crawler','automated crawling','GPTBot'],
      ['Anthropic','Claude-User','claude-user','ai_user_agent','user-directed browsing','Claude-User'],
      ['Anthropic','ClaudeBot','claudebot','ai_crawler','automated crawling','ClaudeBot'],
      ['Anthropic','Claude-SearchBot','claude-searchbot','ai_search','AI search discovery','Claude-SearchBot'],
      ['Perplexity','Perplexity-User','perplexity-user','ai_user_agent','user-directed browsing','Perplexity-User'],
      ['Perplexity','PerplexityBot','perplexitybot','ai_search','AI search discovery','PerplexityBot'],
    ];
    $stmt=$pdo->prepare("INSERT INTO vp3_agent_registry (operator_name,agent_name,slug,visitor_class,purpose,user_agent_pattern,verification_method,is_active)
      VALUES (?,?,?,?,?,?,'signature',1)
      ON DUPLICATE KEY UPDATE operator_name=VALUES(operator_name),agent_name=VALUES(agent_name),visitor_class=VALUES(visitor_class),purpose=VALUES(purpose),user_agent_pattern=VALUES(user_agent_pattern),is_active=1");
    foreach($rows as $row)$stmt->execute($row);
}

function vp3_radar_external_site_limit(?array $user=null): ?int
{
    $user ??= function_exists('current_user') ? current_user() : null;
    if(!$user)return 0;
    return subscription_entitlement_limit($user,VP3_RADAR_SITE_CAPABILITY,0);
}

function vp3_agent_messaging_allowed(?array $user=null): bool
{
    $user ??= function_exists('current_user') ? current_user() : null;
    return $user ? subscription_has_entitlement($user,VP3_AGENT_MESSAGING_CAPABILITY) : false;
}

function vp3_radar_external_site_count(int $ownerUserId,?PDO $pdo=null): int
{
    $pdo ??= db();
    if(!$pdo||$ownerUserId<1||!vp3_radar_schema_ready($pdo))return 0;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='external' AND is_active=1");
    $stmt->execute([$ownerUserId]);
    return (int)$stmt->fetchColumn();
}

function vp3_radar_can_add_external_site(?array $user=null,?PDO $pdo=null): bool
{
    $user ??= function_exists('current_user') ? current_user() : null;
    if(!$user)return false;
    $limit=vp3_radar_external_site_limit($user);
    if($limit===null)return true;
    return vp3_radar_external_site_count((int)$user['id'],$pdo)<max(0,$limit);
}

function vp3_radar_match_agent(string $userAgent,?PDO $pdo=null): ?array
{
    $pdo ??= db();
    $userAgent=trim($userAgent);
    if(!$pdo||$userAgent===''||!vp3_radar_schema_ready($pdo))return null;
    foreach($pdo->query("SELECT * FROM vp3_agent_registry WHERE is_active=1 ORDER BY id")->fetchAll()?:[] as $row){
        $pattern=trim((string)$row['user_agent_pattern']);
        if($pattern!==''&&stripos($userAgent,$pattern)!==false)return $row;
    }
    return null;
}

function vp3_radar_identity_key(int $ownerUserId,?array $registry,string $userAgent,string $networkHint=''): string
{
    $stable=$registry ? 'registry:'.(int)$registry['id'] : 'unknown:'.strtolower(trim($userAgent)).'|'.strtolower(trim($networkHint));
    return hash('sha256',$ownerUserId.'|'.$stable);
}

function vp3_radar_risk_score(array $signals): int
{
    $score=0;
    if(!empty($signals['verification_failed']))$score+=30;
    if(!empty($signals['restricted_probe']))$score+=35;
    if(!empty($signals['credential_probe']))$score+=30;
    if(!empty($signals['injection_pattern']))$score+=40;
    if(!empty($signals['failed_login_burst']))$score+=25;
    $rpm=max(0,(int)($signals['requests_per_minute']??0));
    if($rpm>=120)$score+=30;elseif($rpm>=60)$score+=20;elseif($rpm>=30)$score+=10;
    if(!empty($signals['repeat_denied']))$score+=20;
    return min(100,$score);
}

function vp3_radar_severity(int $riskScore): string
{
    if($riskScore>=90)return 'critical';
    if($riskScore>=70)return 'high';
    if($riskScore>=40)return 'medium';
    return 'low';
}

function vp3_radar_recent_high_risk(int $ownerUserId,int $limit=25,?PDO $pdo=null): array
{
    $pdo ??= db();
    if(!$pdo||$ownerUserId<1||!vp3_radar_schema_ready($pdo))return [];
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT e.*,c.display_name,c.operator_name,c.verification_status,c.trust_score,c.risk_score AS contact_risk_score
      FROM vp3_radar_events e LEFT JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id
      WHERE e.owner_user_id=? AND e.severity IN ('high','critical')
      ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId]);
    return $stmt->fetchAll()?:[];
}
