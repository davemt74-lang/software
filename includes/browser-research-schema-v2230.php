<?php
declare(strict_types=1);

const VP3_BROWSER_RESEARCH_V2230='browser-research-v2230-20260920';
const VP3_BROWSER_RESEARCH_MAX_SOURCES_V2230=5;
const VP3_BROWSER_RESEARCH_MAX_PAGES_V2230=12;
const VP3_BROWSER_RESEARCH_MAX_CLAIMS_V2230=60;
const VP3_BROWSER_RESEARCH_MAX_EVIDENCE_V2230=700;
const VP3_BROWSER_RESEARCH_MAX_DURATION_V2230=120;

require_once __DIR__.'/browser-multisite-v2220.php';
require_once __DIR__.'/research-projects-v2060.php';

function vp3_browser_research_schema_ready_v2230(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'browser_research_missions_v2230',
        'browser_research_pages_v2230',
        'browser_research_claims_v2230',
        'browser_research_evidence_v2230',
    ] as $table)if(!table_exists($table))return false;
    return vp3_browser_multisite_schema_ready_v2220($pdo)&&vp3_research_schema_ready_v2060($pdo);
}

function vp3_browser_research_ensure_schema_v2230(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_multisite_ensure_schema_v2220($pdo);
    if(!vp3_research_schema_ready_v2060($pdo))throw new RuntimeException('Research Projects are not ready. Run the current database upgrade.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_research_missions_v2230 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_id INT UNSIGNED NULL,
      conversation_id BIGINT UNSIGNED NULL,
      workflow_run_id BIGINT UNSIGNED NOT NULL,
      project_id BIGINT UNSIGNED NULL,
      question VARCHAR(2000) NOT NULL,
      approved_domains_json TEXT NULL,
      source_plan_json TEXT NULL,
      max_sources TINYINT UNSIGNED NOT NULL DEFAULT 5,
      max_pages TINYINT UNSIGNED NOT NULL DEFAULT 10,
      max_claims SMALLINT UNSIGNED NOT NULL DEFAULT 50,
      duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      gaps_json TEXT NULL,
      memo_text MEDIUMTEXT NULL,
      report_public_id CHAR(36) NULL,
      expires_at DATETIME NOT NULL,
      completed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      saved_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_research_mission_public_v2230 (public_id),
      INDEX idx_browser_research_mission_owner_v2230 (owner_user_id,status,updated_at),
      INDEX idx_browser_research_mission_runtime_v2230 (runtime_session_id,status),
      CONSTRAINT fk_browser_research_mission_runtime_v2230 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_mission_multisite_v2230 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_mission_owner_v2230 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_mission_project_v2230 FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_research_pages_v2230 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      mission_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      domain VARCHAR(190) NOT NULL,
      page_fingerprint CHAR(64) NOT NULL,
      content_hash CHAR(64) NOT NULL,
      duplicate_group_hash CHAR(64) NOT NULL,
      source_public_id CHAR(36) NOT NULL DEFAULT '',
      source_version_public_id CHAR(36) NOT NULL DEFAULT '',
      source_kind VARCHAR(24) NOT NULL DEFAULT 'unknown',
      freshness_date DATE NULL,
      extraction_status VARCHAR(32) NOT NULL DEFAULT 'processing',
      claim_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_research_page_public_v2230 (public_id),
      UNIQUE KEY uq_browser_research_page_fp_v2230 (mission_id,page_fingerprint),
      INDEX idx_browser_research_page_mission_v2230 (mission_id,domain,collected_at),
      INDEX idx_browser_research_page_dupe_v2230 (mission_id,duplicate_group_hash),
      CONSTRAINT fk_browser_research_page_mission_v2230 FOREIGN KEY (mission_id) REFERENCES browser_research_missions_v2230(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_page_owner_v2230 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_research_claims_v2230 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      mission_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      claim_key VARCHAR(120) NOT NULL,
      statement_text VARCHAR(1200) NOT NULL,
      value_text VARCHAR(1000) NOT NULL,
      value_hash CHAR(64) NOT NULL,
      evidence_state VARCHAR(24) NOT NULL DEFAULT 'single_source',
      support_sources SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      primary_sources SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      direct_sources SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      freshest_at DATE NULL,
      research_finding_public_id CHAR(36) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_research_claim_public_v2230 (public_id),
      UNIQUE KEY uq_browser_research_claim_value_v2230 (mission_id,claim_key,value_hash),
      INDEX idx_browser_research_claim_group_v2230 (mission_id,claim_key,evidence_state),
      CONSTRAINT fk_browser_research_claim_mission_v2230 FOREIGN KEY (mission_id) REFERENCES browser_research_missions_v2230(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_claim_owner_v2230 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_research_evidence_v2230 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      mission_id BIGINT UNSIGNED NOT NULL,
      claim_id BIGINT UNSIGNED NOT NULL,
      page_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      evidence_excerpt VARCHAR(700) NOT NULL,
      evidence_hash CHAR(64) NOT NULL,
      directness VARCHAR(20) NOT NULL DEFAULT 'direct',
      as_of_date DATE NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_research_evidence_public_v2230 (public_id),
      UNIQUE KEY uq_browser_research_evidence_v2230 (claim_id,page_id,evidence_hash),
      INDEX idx_browser_research_evidence_mission_v2230 (mission_id,claim_id,page_id),
      CONSTRAINT fk_browser_research_evidence_mission_v2230 FOREIGN KEY (mission_id) REFERENCES browser_research_missions_v2230(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_evidence_claim_v2230 FOREIGN KEY (claim_id) REFERENCES browser_research_claims_v2230(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_evidence_page_v2230 FOREIGN KEY (page_id) REFERENCES browser_research_pages_v2230(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_research_evidence_owner_v2230 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_research_uuid_v2230(): string { return vp3_extension_uuid_v2000(); }

function vp3_browser_research_text_v2230(mixed $value,int $max): string
{
    if(!is_scalar($value))return '';
    $text=trim(preg_replace('/\s+/u',' ',str_replace("\0",'',(string)$value))??'');
    return mb_strimwidth($text,0,$max,'');
}

function vp3_browser_research_date_v2230(mixed $value): string
{
    $value=trim((string)$value);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return '';
    [$y,$m,$d]=array_map('intval',explode('-',$value));
    return checkdate($m,$d,$y)?$value:'';
}

function vp3_browser_research_json_v2230(mixed $json): array
{
    if(is_array($json))return $json;
    $value=json_decode((string)$json,true);
    return is_array($value)?$value:[];
}

function vp3_browser_research_key_v2230(mixed $value,string $statement=''): string
{
    $key=mb_strtolower(vp3_browser_research_text_v2230($value,120));
    $key=preg_replace('/[^a-z0-9._-]+/','_',$key)??'';
    $key=trim($key,'_.-');
    if($key===''){
        $key=preg_replace('/[^a-z0-9]+/','_',mb_strtolower(vp3_browser_research_text_v2230($statement,100)))??'claim';
        $key=trim($key,'_');
    }
    return mb_strimwidth($key?:'claim',0,120,'');
}
