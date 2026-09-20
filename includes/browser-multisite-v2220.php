<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.20 — Multi-Site Workflow Automation.
 *
 * Cross-domain movement is a separate, short-lived handoff primitive layered
 * over v22.10. v21.90 remains the authority envelope and v22.00 remains the
 * canonical runtime. Raw URLs, cookies, credentials, page text and DOM are
 * never persisted by this layer.
 */
const VP3_BROWSER_MULTISITE_V2220='browser-multisite-v2220-20260920';
const VP3_BROWSER_MULTISITE_PERMIT_SECONDS_V2220=90;
const VP3_BROWSER_MULTISITE_MAX_DOMAINS_V2220=5;
const VP3_BROWSER_MULTISITE_MAX_TABS_V2220=12;
const VP3_BROWSER_MULTISITE_MAX_HANDOFFS_V2220=24;
const VP3_BROWSER_MULTISITE_MAX_FACTS_V2220=40;
const VP3_BROWSER_MULTISITE_MAX_ARTIFACTS_V2220=30;

require_once __DIR__.'/browser-web-interaction-v2210.php';

function vp3_browser_multisite_schema_ready_v2220(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'browser_multisite_sessions_v2220',
        'browser_multisite_domain_policies_v2220',
        'browser_multisite_handoffs_v2220',
        'browser_multisite_tabs_v2220',
        'browser_multisite_facts_v2220',
        'browser_multisite_artifacts_v2220',
    ] as $table)if(!table_exists($table))return false;
    return vp3_browser_web_schema_ready_v2210($pdo);
}

function vp3_browser_multisite_ensure_schema_v2220(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_web_ensure_schema_v2210($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_sessions_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      current_domain VARCHAR(190) NOT NULL DEFAULT '',
      max_domains TINYINT UNSIGNED NOT NULL DEFAULT 5,
      max_tabs TINYINT UNSIGNED NOT NULL DEFAULT 8,
      max_handoffs TINYINT UNSIGNED NOT NULL DEFAULT 12,
      max_facts TINYINT UNSIGNED NOT NULL DEFAULT 20,
      handoff_count INT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_public_v2220 (public_id),
      UNIQUE KEY uq_browser_multisite_runtime_v2220 (runtime_session_id),
      INDEX idx_browser_multisite_owner_v2220 (owner_user_id,status,updated_at),
      CONSTRAINT fk_browser_multisite_runtime_v2220 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_domain_policies_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      domain VARCHAR(190) NOT NULL,
      policy_mode VARCHAR(24) NOT NULL DEFAULT 'browse',
      allowed_actions_json TEXT NULL,
      visit_count INT UNSIGNED NOT NULL DEFAULT 0,
      last_visited_at DATETIME NULL,
      policy_updated_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_domain_v2220 (multisite_session_id,domain),
      INDEX idx_browser_multisite_domain_owner_v2220 (owner_user_id,domain),
      CONSTRAINT fk_browser_multisite_domain_session_v2220 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_domain_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_handoffs_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      source_domain VARCHAR(190) NOT NULL,
      target_domain VARCHAR(190) NOT NULL,
      source_page_fingerprint CHAR(64) NOT NULL,
      target_url_fingerprint CHAR(64) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'proposed',
      permit_hash CHAR(64) NULL,
      permit_expires_at DATETIME NULL,
      claimed_at DATETIME NULL,
      verified_at DATETIME NULL,
      failed_at DATETIME NULL,
      result_code VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_handoff_public_v2220 (public_id),
      INDEX idx_browser_multisite_handoff_session_v2220 (multisite_session_id,status,id),
      CONSTRAINT fk_browser_multisite_handoff_session_v2220 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_handoff_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_tabs_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      client_tab_hash CHAR(64) NOT NULL,
      domain VARCHAR(190) NOT NULL,
      tab_role VARCHAR(40) NOT NULL DEFAULT 'runtime',
      opened_by_runtime TINYINT(1) NOT NULL DEFAULT 0,
      status VARCHAR(24) NOT NULL DEFAULT 'open',
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_tab_v2220 (multisite_session_id,client_tab_hash),
      INDEX idx_browser_multisite_tab_owner_v2220 (owner_user_id,status,updated_at),
      CONSTRAINT fk_browser_multisite_tab_session_v2220 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_tab_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_facts_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      fact_key VARCHAR(120) NOT NULL,
      value_text VARCHAR(500) NOT NULL,
      value_hash CHAR(64) NOT NULL,
      source_domain VARCHAR(190) NOT NULL,
      page_fingerprint CHAR(64) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_fact_public_v2220 (public_id),
      INDEX idx_browser_multisite_fact_key_v2220 (multisite_session_id,fact_key,status,id),
      INDEX idx_browser_multisite_fact_owner_v2220 (owner_user_id,expires_at),
      CONSTRAINT fk_browser_multisite_fact_session_v2220 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_fact_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_multisite_artifacts_v2220 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      multisite_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      source_domain VARCHAR(190) NOT NULL,
      filename_hash CHAR(64) NOT NULL,
      file_ext VARCHAR(20) NOT NULL DEFAULT '',
      mime_type VARCHAR(120) NOT NULL DEFAULT '',
      byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(24) NOT NULL DEFAULT 'observed',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_multisite_artifact_public_v2220 (public_id),
      INDEX idx_browser_multisite_artifact_session_v2220 (multisite_session_id,id),
      CONSTRAINT fk_browser_multisite_artifact_session_v2220 FOREIGN KEY (multisite_session_id) REFERENCES browser_multisite_sessions_v2220(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_multisite_artifact_owner_v2220 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_multisite_sha_v2220(mixed $value): string
{
    $value=strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{64}$/',$value)?$value:'';
}

function vp3_browser_multisite_runtime_v2220(PDO $pdo,array $user,string $namespace,string $runtimePublicId): array
{
    return vp3_browser_web_runtime_v2210($pdo,$user,$namespace,$runtimePublicId);
}

function vp3_browser_multisite_row_v2220(PDO $pdo,array $runtime,bool $lock=false): ?array
{
    $sql="SELECT * FROM browser_multisite_sessions_v2220 WHERE runtime_session_id=? AND owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_multisite_browse_actions_v2220(array $delegationActions): array
{
    $allowed=['web_click','web_focus','web_scroll','web_open_link','multisite_handoff'];
    return array_values(array_intersect($allowed,$delegationActions));
}

function vp3_browser_multisite_web_actions_v2220(array $delegationActions): array
{
    $allowed=['web_click','web_focus','web_type','web_clear','web_select','web_toggle','web_scroll','web_open_link','web_submit','multisite_handoff'];
    return array_values(array_intersect($allowed,$delegationActions));
}

function vp3_browser_multisite_attach_v2220(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $currentDomain): array
{
    $runtime=vp3_browser_multisite_runtime_v2220($pdo,$user,$namespace,$runtimePublicId);
    $current=vp3_browser_delegation_domain_v2190($currentDomain);
    $domains=array_values(array_unique(array_filter(array_map('vp3_browser_delegation_domain_v2190',vp3_browser_delegation_json_array_v2190($runtime['allowed_domains_json']??'')))));
    if(!$domains)throw new RuntimeException('This Browser delegation has no approved domains.');
    if($current!==''&&!in_array($current,$domains,true))throw new RuntimeException('The active page is outside the approved multi-site domain scope.');
    if(count($domains)>VP3_BROWSER_MULTISITE_MAX_DOMAINS_V2220)throw new RuntimeException('This delegation exceeds the v22.20 approved-domain limit.');
    $actions=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if(!in_array('multisite_handoff',$actions,true))throw new RuntimeException('Multi-site handoff was not approved in this Browser delegation.');

    $row=vp3_browser_multisite_row_v2220($pdo,$runtime,true);
    if(!$row){
        $public=vp3_extension_uuid_v2000();
        $maxDomains=max(1,min(VP3_BROWSER_MULTISITE_MAX_DOMAINS_V2220,count($domains)));
        $maxTabs=max(2,min(VP3_BROWSER_MULTISITE_MAX_TABS_V2220,$maxDomains*2+2));
        $maxHandoffs=max(2,min(VP3_BROWSER_MULTISITE_MAX_HANDOFFS_V2220,max(1,(int)($runtime['max_steps']??1))*3));
        $maxFacts=max(5,min(VP3_BROWSER_MULTISITE_MAX_FACTS_V2220,max(1,(int)($runtime['max_steps']??1))*5));
        $stmt=$pdo->prepare("INSERT INTO browser_multisite_sessions_v2220
          (public_id,runtime_session_id,owner_user_id,current_domain,max_domains,max_tabs,max_handoffs,max_facts,status)
          VALUES (?,?,?,?,?,?,?,?,'active')");
        $stmt->execute([$public,(int)$runtime['id'],(int)$runtime['owner_user_id'],$current,$maxDomains,$maxTabs,$maxHandoffs,$maxFacts]);
        $row=vp3_browser_multisite_row_v2220($pdo,$runtime,true);
    }
    if(!$row)throw new RuntimeException('Multi-site runtime session could not be attached.');

    $existing=$pdo->prepare("SELECT COUNT(*) FROM browser_multisite_domain_policies_v2220 WHERE multisite_session_id=?");
    $existing->execute([(int)$row['id']]);
    if((int)$existing->fetchColumn()===0){
        $insert=$pdo->prepare("INSERT INTO browser_multisite_domain_policies_v2220
          (multisite_session_id,owner_user_id,domain,policy_mode,allowed_actions_json,visit_count,last_visited_at)
          VALUES (?,?,?,?,?,?,?)");
        foreach($domains as $domain){
            $primary=$domain===$current;
            $policy=$primary?'delegated':'browse';
            $policyActions=$primary?vp3_browser_multisite_web_actions_v2220($actions):vp3_browser_multisite_browse_actions_v2220($actions);
            $insert->execute([
                (int)$row['id'],(int)$runtime['owner_user_id'],$domain,$policy,
                json_encode($policyActions,JSON_UNESCAPED_SLASHES),
                $primary?1:0,$primary?gmdate('Y-m-d H:i:s'):null
            ]);
        }
        vp3_browser_runtime_event_v2200($pdo,$runtime,'multisite_attached','Multi-site runtime attached to the approved delegation domain envelope.','multisite.attach',null,'ready');
    }elseif($current!==''){
        $pdo->prepare("UPDATE browser_multisite_sessions_v2220 SET current_domain=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$current,(int)$row['id']]);
    }
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_cleanup_v2220(PDO $pdo,array $runtime,array $session): void
{
    $terminal=in_array((string)$runtime['status'],['completed','cancelled','expired'],true)||strtotime((string)$runtime['expires_at'])<time();
    if(!$terminal)return;
    $pdo->prepare("DELETE FROM browser_multisite_facts_v2220 WHERE multisite_session_id=?")->execute([(int)$session['id']]);
    $pdo->prepare("DELETE FROM browser_multisite_tabs_v2220 WHERE multisite_session_id=?")->execute([(int)$session['id']]);
    $pdo->prepare("UPDATE browser_multisite_sessions_v2220 SET status='closed',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$session['id']]);
}

function vp3_browser_multisite_policies_v2220(PDO $pdo,array $session): array
{
    $stmt=$pdo->prepare("SELECT domain,policy_mode,allowed_actions_json,visit_count,last_visited_at,policy_updated_at FROM browser_multisite_domain_policies_v2220 WHERE multisite_session_id=? ORDER BY id");
    $stmt->execute([(int)$session['id']]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=[
        'domain'=>(string)$row['domain'],'policy_mode'=>(string)$row['policy_mode'],
        'allowed_actions'=>vp3_browser_delegation_json_array_v2190($row['allowed_actions_json']),
        'visit_count'=>(int)$row['visit_count'],'last_visited_at'=>(string)($row['last_visited_at']??''),
        'policy_updated_at'=>(string)($row['policy_updated_at']??''),
    ];
    return $out;
}

function vp3_browser_multisite_policy_v2220(PDO $pdo,array $session,string $domain): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_multisite_domain_policies_v2220 WHERE multisite_session_id=? AND domain=? LIMIT 1");
    $stmt->execute([(int)$session['id'],$domain]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_multisite_set_policy_v2220(PDO $pdo,array $runtime,array $session,string $domain,string $mode): array
{
    $domain=vp3_browser_delegation_domain_v2190($domain);
    $policy=vp3_browser_multisite_policy_v2220($pdo,$session,$domain);
    if(!$policy)throw new RuntimeException('That domain is not in the approved delegation.');
    if(!in_array($mode,['browse','delegated','blocked'],true))throw new InvalidArgumentException('Unknown domain policy.');
    $delegationActions=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    $actions=$mode==='blocked'?[]:($mode==='browse'?vp3_browser_multisite_browse_actions_v2220($delegationActions):vp3_browser_multisite_web_actions_v2220($delegationActions));
    $pdo->prepare("UPDATE browser_multisite_domain_policies_v2220 SET policy_mode=?,allowed_actions_json=?,policy_updated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([$mode,json_encode($actions,JSON_UNESCAPED_SLASHES),(int)$policy['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'multisite_policy_updated','Domain interaction policy updated inside the existing delegation envelope.','multisite.policy',null,$mode);
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_expire_permits_v2220(PDO $pdo,array $session): void
{
    $pdo->prepare("UPDATE browser_multisite_handoffs_v2220
      SET status='failed',result_code='permit_expired',permit_hash=NULL,permit_expires_at=NULL,failed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE multisite_session_id=? AND status='executing' AND permit_expires_at IS NOT NULL AND permit_expires_at<UTC_TIMESTAMP()")
      ->execute([(int)$session['id']]);
}

function vp3_browser_multisite_handoff_count_v2220(PDO $pdo,array $session): int
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM browser_multisite_handoffs_v2220 WHERE multisite_session_id=? AND status NOT IN ('proposed','cancelled')");
    $stmt->execute([(int)$session['id']]);return (int)$stmt->fetchColumn();
}

function vp3_browser_multisite_handoff_row_v2220(PDO $pdo,array $session,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT * FROM browser_multisite_handoffs_v2220 WHERE multisite_session_id=? AND public_id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$session['id'],$publicId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_multisite_public_handoff_v2220(array $row): array
{
    return [
        'handoff_id'=>(string)$row['public_id'],'source_domain'=>(string)$row['source_domain'],'target_domain'=>(string)$row['target_domain'],
        'status'=>(string)$row['status'],'result_code'=>(string)$row['result_code'],
        'claimed_at'=>(string)($row['claimed_at']??''),'verified_at'=>(string)($row['verified_at']??''),
        'failed_at'=>(string)($row['failed_at']??''),'created_at'=>(string)$row['created_at'],
    ];
}

function vp3_browser_multisite_handoff_preview_v2220(PDO $pdo,array $runtime,array $session,array $input): array
{
    vp3_browser_multisite_expire_permits_v2220($pdo,$session);
    if(vp3_browser_multisite_handoff_count_v2220($pdo,$session)>=(int)$session['max_handoffs'])throw new RuntimeException('This runtime reached its bounded cross-domain handoff limit.');
    $source=vp3_browser_delegation_domain_v2190($input['source_domain']??'');
    $target=vp3_browser_delegation_domain_v2190($input['target_domain']??'');
    if($source===''||$target===''||$source===$target)throw new InvalidArgumentException('A cross-domain handoff requires two different approved domains.');
    $sourcePolicy=vp3_browser_multisite_policy_v2220($pdo,$session,$source);
    $targetPolicy=vp3_browser_multisite_policy_v2220($pdo,$session,$target);
    if(!$sourcePolicy||!$targetPolicy)throw new RuntimeException('One of these domains is outside the approved delegation.');
    if((string)$targetPolicy['policy_mode']==='blocked')throw new RuntimeException('The target domain is blocked by this runtime policy.');
    $sourceActions=vp3_browser_delegation_json_array_v2190($sourcePolicy['allowed_actions_json']);
    if(!in_array('multisite_handoff',$sourceActions,true))throw new RuntimeException('Cross-domain handoff is not allowed by the current domain policy.');
    $sourcePage=vp3_browser_multisite_sha_v2220($input['source_page_fingerprint']??'');
    $targetUrl=vp3_browser_multisite_sha_v2220($input['target_url_fingerprint']??'');
    if($sourcePage===''||$targetUrl==='')throw new InvalidArgumentException('Handoff fingerprints are required.');

    $public=vp3_extension_uuid_v2000();
    $stmt=$pdo->prepare("INSERT INTO browser_multisite_handoffs_v2220
      (public_id,multisite_session_id,owner_user_id,source_domain,target_domain,source_page_fingerprint,target_url_fingerprint,status)
      VALUES (?,?,?,?,?,?,?,'proposed')");
    $stmt->execute([$public,(int)$session['id'],(int)$runtime['owner_user_id'],$source,$target,$sourcePage,$targetUrl]);
    $row=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$public);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'domain_handoff_proposed','Cross-domain handoff proposed between two explicitly approved domains.','multisite.handoff',null,'proposed');
    return ['handoff'=>vp3_browser_multisite_public_handoff_v2220($row?:[]),'target_policy'=>(string)$targetPolicy['policy_mode']];
}

function vp3_browser_multisite_handoff_claim_v2220(PDO $pdo,array $runtime,array $session,string $handoffId,array $input): array
{
    $row=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$handoffId,true);
    if(!$row)throw new RuntimeException('Multi-site handoff was not found.');
    if((string)$row['status']!=='proposed')throw new RuntimeException('This multi-site handoff is not ready to execute.');
    $source=vp3_browser_delegation_domain_v2190($input['source_domain']??'');
    $sourcePage=vp3_browser_multisite_sha_v2220($input['source_page_fingerprint']??'');
    $targetUrl=vp3_browser_multisite_sha_v2220($input['target_url_fingerprint']??'');
    if($source!==(string)$row['source_domain']||$sourcePage===''||$targetUrl===''||
       !hash_equals((string)$row['source_page_fingerprint'],$sourcePage)||
       !hash_equals((string)$row['target_url_fingerprint'],$targetUrl)){
        throw new RuntimeException('The handoff changed after preview. Scan and preview it again.');
    }
    $targetPolicy=vp3_browser_multisite_policy_v2220($pdo,$session,(string)$row['target_domain']);
    if(!$targetPolicy||(string)$targetPolicy['policy_mode']==='blocked')throw new RuntimeException('The target domain is no longer available to this runtime.');

    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);
    $pdo->prepare("UPDATE browser_multisite_handoffs_v2220
      SET status='executing',permit_hash=?,permit_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_MULTISITE_PERMIT_SECONDS_V2220." SECOND),claimed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([$hash,(int)$row['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'domain_handoff_started','Chrome claimed a short-lived cross-domain handoff permit.','multisite.handoff',null,'executing');
    return [
        'permit_token'=>$token,
        'contract'=>[
            'handoff_id'=>(string)$row['public_id'],'source_domain'=>(string)$row['source_domain'],'target_domain'=>(string)$row['target_domain'],
            'source_page_fingerprint'=>(string)$row['source_page_fingerprint'],'target_url_fingerprint'=>(string)$row['target_url_fingerprint'],
            'permit_seconds'=>VP3_BROWSER_MULTISITE_PERMIT_SECONDS_V2220,
        ]
    ];
}

function vp3_browser_multisite_handoff_complete_v2220(PDO $pdo,array $runtime,array $session,string $handoffId,string $permitToken,array $input): array
{
    $row=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$handoffId,true);
    if(!$row)throw new RuntimeException('Multi-site handoff was not found.');
    if((string)$row['status']!=='executing')throw new RuntimeException('This multi-site handoff is not executing.');
    if(empty($row['permit_hash'])||empty($row['permit_expires_at'])||strtotime((string)$row['permit_expires_at'])<time())throw new RuntimeException('The multi-site handoff permit expired.');
    if(!hash_equals((string)$row['permit_hash'],hash('sha256',$permitToken)))throw new RuntimeException('The multi-site handoff permit is invalid.');
    $verified=!empty($input['verified']);
    $target=vp3_browser_delegation_domain_v2190($input['target_domain']??'');
    $targetUrl=vp3_browser_multisite_sha_v2220($input['target_url_fingerprint']??'');
    if($verified&&($target!==(string)$row['target_domain']||$targetUrl===''||!hash_equals((string)$row['target_url_fingerprint'],$targetUrl)))$verified=false;
    $code=preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)($input['result_code']??($verified?'verified':'unverified')))));
    $code=mb_strimwidth($code?:($verified?'verified':'unverified'),0,80,'');
    $status=$verified?'completed':'failed';
    $pdo->prepare("UPDATE browser_multisite_handoffs_v2220
      SET status=?,permit_hash=NULL,permit_expires_at=NULL,result_code=?,
          verified_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE verified_at END,
          failed_at=CASE WHEN ?=0 THEN UTC_TIMESTAMP() ELSE failed_at END,updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([$status,$code,$verified?1:0,$verified?1:0,(int)$row['id']]);
    if($verified){
        $pdo->prepare("UPDATE browser_multisite_sessions_v2220 SET current_domain=?,handoff_count=handoff_count+1,updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(string)$row['target_domain'],(int)$session['id']]);
        $pdo->prepare("UPDATE browser_multisite_domain_policies_v2220 SET visit_count=visit_count+1,last_visited_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE multisite_session_id=? AND domain=?")
            ->execute([(int)$session['id'],(string)$row['target_domain']]);
        $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    }
    vp3_browser_runtime_event_v2200($pdo,$runtime,$verified?'domain_handoff_verified':'domain_handoff_failed',$verified?'Cross-domain handoff reached the approved destination and was verified.':'Cross-domain handoff did not reach the approved destination.','multisite.handoff',null,$code);
    $fresh=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$handoffId);
    return ['handoff'=>vp3_browser_multisite_public_handoff_v2220($fresh?:$row),'state'=>vp3_browser_multisite_state_v2220($pdo,$runtime)];
}

function vp3_browser_multisite_handoff_cancel_v2220(PDO $pdo,array $runtime,array $session,string $handoffId): array
{
    $row=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$handoffId,true);
    if(!$row)throw new RuntimeException('Multi-site handoff was not found.');
    if((string)$row['status']!=='proposed')throw new RuntimeException('This handoff can no longer be cancelled.');
    $pdo->prepare("UPDATE browser_multisite_handoffs_v2220 SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'domain_handoff_cancelled','Cross-domain handoff proposal cancelled.','multisite.handoff',null,'cancelled');
    $fresh=vp3_browser_multisite_handoff_row_v2220($pdo,$session,$handoffId);
    return ['handoff'=>vp3_browser_multisite_public_handoff_v2220($fresh?:$row)];
}

function vp3_browser_multisite_tab_register_v2220(PDO $pdo,array $runtime,array $session,array $input): array
{
    $clientKey=trim((string)($input['client_tab_key']??''));
    if(strlen($clientKey)<12||strlen($clientKey)>180)throw new InvalidArgumentException('Runtime tab reference is invalid.');
    $domain=vp3_browser_delegation_domain_v2190($input['domain']??'');
    if(!vp3_browser_multisite_policy_v2220($pdo,$session,$domain))throw new RuntimeException('This tab is outside the approved domain scope.');
    $hash=hash('sha256',$clientKey);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM browser_multisite_tabs_v2220 WHERE multisite_session_id=? AND status='open'");
    $stmt->execute([(int)$session['id']]);
    $exists=$pdo->prepare("SELECT id FROM browser_multisite_tabs_v2220 WHERE multisite_session_id=? AND client_tab_hash=? LIMIT 1");
    $exists->execute([(int)$session['id'],$hash]);$id=(int)$exists->fetchColumn();
    if(!$id&&(int)$stmt->fetchColumn()>=(int)$session['max_tabs'])throw new RuntimeException('This runtime reached its bounded tab limit.');
    $role=preg_replace('/[^a-z0-9_\-]/','',strtolower((string)($input['tab_role']??'runtime')));$role=mb_strimwidth($role?:'runtime',0,40,'');
    if($id){
        $pdo->prepare("UPDATE browser_multisite_tabs_v2220 SET domain=?,tab_role=?,status='open',last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$domain,$role,$id]);
    }else{
        $pdo->prepare("INSERT INTO browser_multisite_tabs_v2220
          (multisite_session_id,owner_user_id,client_tab_hash,domain,tab_role,opened_by_runtime,status,last_seen_at)
          VALUES (?,?,?,?,?,?,'open',UTC_TIMESTAMP())")
          ->execute([(int)$session['id'],(int)$runtime['owner_user_id'],$hash,$domain,$role,!empty($input['opened_by_runtime'])?1:0]);
    }
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_tab_release_v2220(PDO $pdo,array $runtime,array $session,string $clientKey): array
{
    $hash=hash('sha256',trim($clientKey));
    $pdo->prepare("UPDATE browser_multisite_tabs_v2220 SET status='released',updated_at=UTC_TIMESTAMP() WHERE multisite_session_id=? AND client_tab_hash=?")
        ->execute([(int)$session['id'],$hash]);
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_fact_sensitive_v2220(string $key,string $value): bool
{
    $text=mb_strtolower($key.' '.$value);
    return (bool)preg_match('/\b(?:password|passcode|pin|otp|2fa|mfa|verification code|security code|credit card|card number|cvv|cvc|ssn|social security|access token|api key|secret key|private key|cookie|session token|bank account|routing number|medical record|diagnosis|prescription|health condition)\b/u',$text);
}

function vp3_browser_multisite_fact_add_v2220(PDO $pdo,array $runtime,array $session,array $input): array
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM browser_multisite_facts_v2220 WHERE multisite_session_id=? AND expires_at>UTC_TIMESTAMP()");
    $stmt->execute([(int)$session['id']]);
    if((int)$stmt->fetchColumn()>=(int)$session['max_facts'])throw new RuntimeException('This runtime reached its bounded structured-fact limit.');
    $key=preg_replace('/\s+/u',' ',trim((string)($input['fact_key']??'')))??'';
    $value=preg_replace('/\s+/u',' ',trim((string)($input['value']??'')))??'';
    $key=mb_strimwidth($key,0,120,'');$value=mb_strimwidth($value,0,500,'');
    if($key===''||$value==='')throw new InvalidArgumentException('Structured fact key and value are required.');
    if(vp3_browser_multisite_fact_sensitive_v2220($key,$value))throw new RuntimeException('Credentials, authentication secrets, financial account data and health/medical data cannot be stored as Browser Runtime facts.');
    $domain=vp3_browser_delegation_domain_v2190($input['source_domain']??'');
    if(!vp3_browser_multisite_policy_v2220($pdo,$session,$domain))throw new RuntimeException('Fact provenance domain is outside the approved delegation.');
    $page=vp3_browser_multisite_sha_v2220($input['page_fingerprint']??'');
    if($page==='')throw new InvalidArgumentException('Fact provenance fingerprint is required.');
    $hash=hash('sha256',mb_strtolower($value));
    $public=vp3_extension_uuid_v2000();
    $expires=(string)$runtime['expires_at'];
    $insert=$pdo->prepare("INSERT INTO browser_multisite_facts_v2220
      (public_id,multisite_session_id,owner_user_id,fact_key,value_text,value_hash,source_domain,page_fingerprint,status,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([$public,(int)$session['id'],(int)$runtime['owner_user_id'],$key,$value,$hash,$domain,$page,'active',$expires]);

    $conflict=$pdo->prepare("SELECT id,value_hash FROM browser_multisite_facts_v2220 WHERE multisite_session_id=? AND fact_key=? AND id<>LAST_INSERT_ID() AND status IN ('active','conflict') AND expires_at>UTC_TIMESTAMP()");
    $conflict->execute([(int)$session['id'],$key]);$conflictFound=false;
    foreach($conflict->fetchAll(PDO::FETCH_ASSOC)?:[] as $other){
        if(!hash_equals((string)$other['value_hash'],$hash)){
            $conflictFound=true;
            $pdo->prepare("UPDATE browser_multisite_facts_v2220 SET status='conflict',updated_at=UTC_TIMESTAMP() WHERE id IN (?,LAST_INSERT_ID())")
                ->execute([(int)$other['id']]);
        }
    }
    vp3_browser_runtime_event_v2200($pdo,$runtime,$conflictFound?'multisite_fact_conflict':'multisite_fact_added',$conflictFound?'Structured fact conflict detected across approved sources.':'Structured fact added with approved-source provenance.','multisite.fact',null,$conflictFound?'conflict':'active');
    if($conflictFound&&function_exists('create_notification'))create_notification(
        (int)$runtime['owner_user_id'],'browser_multisite_fact_conflict',
        'Browser Agent found conflicting source data',
        'Two approved sources disagree on a structured fact. Review the runtime before continuing.',
        '/agent-workflows.php?id='.(int)$runtime['workflow_run_id'],'browser_multisite_fact',(int)$pdo->lastInsertId()
    );
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_artifact_add_v2220(PDO $pdo,array $runtime,array $session,array $input): array
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM browser_multisite_artifacts_v2220 WHERE multisite_session_id=?");
    $stmt->execute([(int)$session['id']]);
    if((int)$stmt->fetchColumn()>=VP3_BROWSER_MULTISITE_MAX_ARTIFACTS_V2220)throw new RuntimeException('This runtime reached its bounded download-receipt limit.');
    $domain=vp3_browser_delegation_domain_v2190($input['source_domain']??'');
    if(!vp3_browser_multisite_policy_v2220($pdo,$session,$domain))throw new RuntimeException('Download source domain is outside the approved delegation.');
    $filenameHash=vp3_browser_multisite_sha_v2220($input['filename_hash']??'');
    if($filenameHash==='')throw new InvalidArgumentException('Download fingerprint is required.');
    $ext=preg_replace('/[^a-z0-9]/','',strtolower((string)($input['file_ext']??'')));$ext=mb_strimwidth($ext,0,20,'');
    $mime=preg_replace('/[^a-z0-9.+\-\/]/','',strtolower((string)($input['mime_type']??'')));$mime=mb_strimwidth($mime,0,120,'');
    $bytes=max(0,(int)($input['byte_size']??0));$public=vp3_extension_uuid_v2000();
    $pdo->prepare("INSERT INTO browser_multisite_artifacts_v2220
      (public_id,multisite_session_id,owner_user_id,source_domain,filename_hash,file_ext,mime_type,byte_size,status)
      VALUES (?,?,?,?,?,?,?,?, 'observed')")
      ->execute([$public,(int)$session['id'],(int)$runtime['owner_user_id'],$domain,$filenameHash,$ext,$mime,$bytes]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'download_observed','A download was observed from an approved runtime domain. The file was not executed.','multisite.download',null,'observed');
    return vp3_browser_multisite_state_v2220($pdo,$runtime);
}

function vp3_browser_multisite_state_v2220(PDO $pdo,array $runtime): array
{
    $session=vp3_browser_multisite_row_v2220($pdo,$runtime);
    if(!$session)return [
        'attached'=>false,'domains'=>[],'handoffs'=>[],'tabs'=>[],'facts'=>[],'artifacts'=>[]
    ];
    vp3_browser_multisite_cleanup_v2220($pdo,$runtime,$session);
    vp3_browser_multisite_expire_permits_v2220($pdo,$session);

    $handoffs=$pdo->prepare("SELECT * FROM browser_multisite_handoffs_v2220 WHERE multisite_session_id=? ORDER BY id DESC LIMIT 30");
    $handoffs->execute([(int)$session['id']]);
    $tabs=$pdo->prepare("SELECT domain,tab_role,opened_by_runtime,status,last_seen_at FROM browser_multisite_tabs_v2220 WHERE multisite_session_id=? ORDER BY id DESC LIMIT 30");
    $tabs->execute([(int)$session['id']]);
    $facts=$pdo->prepare("SELECT public_id,fact_key,value_text,source_domain,status,created_at FROM browser_multisite_facts_v2220 WHERE multisite_session_id=? AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 40");
    $facts->execute([(int)$session['id']]);
    $artifacts=$pdo->prepare("SELECT public_id,source_domain,file_ext,mime_type,byte_size,status,created_at FROM browser_multisite_artifacts_v2220 WHERE multisite_session_id=? ORDER BY id DESC LIMIT 30");
    $artifacts->execute([(int)$session['id']]);

    return [
        'attached'=>true,'session_id'=>(string)$session['public_id'],'status'=>(string)$session['status'],
        'current_domain'=>(string)$session['current_domain'],'max_domains'=>(int)$session['max_domains'],
        'max_tabs'=>(int)$session['max_tabs'],'max_handoffs'=>(int)$session['max_handoffs'],'max_facts'=>(int)$session['max_facts'],
        'handoff_count'=>vp3_browser_multisite_handoff_count_v2220($pdo,$session),
        'domains'=>vp3_browser_multisite_policies_v2220($pdo,$session),
        'handoffs'=>array_map('vp3_browser_multisite_public_handoff_v2220',$handoffs->fetchAll(PDO::FETCH_ASSOC)?:[]),
        'tabs'=>array_map(static fn(array $row): array=>[
            'domain'=>(string)$row['domain'],'tab_role'=>(string)$row['tab_role'],'opened_by_runtime'=>!empty($row['opened_by_runtime']),
            'status'=>(string)$row['status'],'last_seen_at'=>(string)$row['last_seen_at']
        ],$tabs->fetchAll(PDO::FETCH_ASSOC)?:[]),
        'facts'=>array_map(static fn(array $row): array=>[
            'fact_id'=>(string)$row['public_id'],'fact_key'=>(string)$row['fact_key'],'value'=>(string)$row['value_text'],
            'source_domain'=>(string)$row['source_domain'],'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at']
        ],$facts->fetchAll(PDO::FETCH_ASSOC)?:[]),
        'artifacts'=>array_map(static fn(array $row): array=>[
            'artifact_id'=>(string)$row['public_id'],'source_domain'=>(string)$row['source_domain'],'file_ext'=>(string)$row['file_ext'],
            'mime_type'=>(string)$row['mime_type'],'byte_size'=>(int)$row['byte_size'],'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at']
        ],$artifacts->fetchAll(PDO::FETCH_ASSOC)?:[]),
        'privacy'=>[
            'raw_urls_persisted'=>false,'cookies_persisted'=>false,'credentials_persisted'=>false,
            'browser_history_persisted'=>false,'structured_facts_task_scoped'=>true,
        ],
    ];
}

function vp3_browser_multisite_for_workflow_v2220(PDO $pdo,int $uid,int $workflowRunId): array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_multisite_schema_ready_v2220($pdo))return ['attached'=>false];
    $stmt=$pdo->prepare("SELECT r.* FROM browser_agent_runtime_sessions_v2200 r WHERE r.owner_user_id=? AND r.workflow_run_id=? LIMIT 1");
    $stmt->execute([$uid,$workflowRunId]);$runtime=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($runtime)?vp3_browser_multisite_state_v2220($pdo,$runtime):['attached'=>false];
}
